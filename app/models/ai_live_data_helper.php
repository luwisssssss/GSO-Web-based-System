<?php

/**
 * Controlled live-data adapters for the chatbot (Step 10).
 *
 * These are thin wrappers around the application's existing business logic.
 * They do not reimplement availability, conflict, lifecycle, incident or
 * maintenance rules - they call the same services the web UI calls, so the
 * chatbot can never disagree with the rest of the system.
 *
 * Contract for every adapter:
 *   - identity comes from the authenticated session, never from the message;
 *   - a caller may only reach records the normal UI would show them;
 *   - "not found" and "not yours" return the same text, so the chatbot cannot
 *     be used to probe whether someone else's record exists;
 *   - the return value is a narrow structured result, never a database row.
 *
 * The chatbot is READ-ONLY. Nothing here writes to a GSO transactional table.
 */

require_once __DIR__ . "/request_helper.php";
require_once __DIR__ . "/incident_helper.php";
require_once __DIR__ . "/maintenance_helper.php";
require_once __DIR__ . "/ai_param_helper.php";

if (!defined("GSO_CHAT_PENDING_TTL_SECONDS")) {
    define("GSO_CHAT_PENDING_TTL_SECONDS", 900);
}

if (!function_exists("gsoChatLiveResult")) {
    /**
     * @param array $needs  Missing parameter names that should be asked for.
     * @param array $facts  Narrow sanitized values safe to hand to a provider.
     */
    function gsoChatLiveResult(
        string $type,
        bool $success,
        string $message,
        array $facts = [],
        array $needs = [],
        array $resolvedParams = []
    ): array {
        return [
            "type" => $type,
            "success" => $success,
            "needs" => $needs,
            "message" => $message,
            "facts" => $facts,
            // Values already established this turn, carried into the next turn
            // when we have to ask for something that is still missing.
            "params" => array_filter(
                $resolvedParams,
                static fn ($value): bool => $value !== null && $value !== ""
            ),
        ];
    }
}

if (!function_exists("gsoChatIsAdmin")) {
    /** Role comes from the verified session user, never from message text. */
    function gsoChatIsAdmin(array $authUser): bool
    {
        return (string) ($authUser["role"] ?? "") === "Admin";
    }
}

if (!function_exists("gsoChatDescribeResourceList")) {
    /** Names only - internal ids are never shown to the user or the model. */
    function gsoChatDescribeResourceList(array $resources, int $limit = 5): string
    {
        $names = [];

        foreach (array_slice($resources, 0, $limit) as $resource) {
            $names[] = (string) ($resource["resource_name"] ?? "");
        }

        return implode(", ", array_filter($names));
    }
}

/* ------------------------------------------------------------------ *
 * Resource availability
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatCheckResourceAvailability")) {
    function gsoChatCheckResourceAvailability(PDO $pdo, array $authUser, string $message, array $params = []): array
    {
        $type = "resource_availability";
        $searchText = trim((string) ($params["resource_name"] ?? "")) !== ""
            ? (string) $params["resource_name"]
            : $message;

        $matches = gsoChatMatchResources($pdo, $searchText);

        if ($matches === []) {
            return gsoChatLiveResult(
                $type,
                false,
                "I couldn't find a matching resource in the GSO inventory. "
                    . "Try the exact name as it appears in Browse Resources.",
                ["matched" => 0]
            );
        }

        if (count($matches) > 1) {
            return gsoChatLiveResult(
                $type,
                false,
                "I found more than one match: " . gsoChatDescribeResourceList($matches)
                    . ". Which one do you mean?",
                ["matched" => count($matches), "options" => gsoChatDescribeResourceList($matches)],
                ["resource_name"]
            );
        }

        $resource = $matches[0];

        // A facility question needs a schedule, so hand it to that adapter.
        if ((string) ($resource["resource_type"] ?? "") === "Facility") {
            return gsoChatCheckFacilityAvailability($pdo, $authUser, $message, [
                "facility_name" => (string) $resource["resource_name"],
            ] + $params);
        }

        $resourceName = (string) $resource["resource_name"];
        $availableStock = max(0, (int) ($resource["available_stock"] ?? 0));
        $underRepair = max(0, (int) ($resource["under_repair_stock"] ?? 0));
        $status = (string) ($resource["status"] ?? "");

        // The authoritative borrowability check - same call the request form makes.
        $today = date("Y-m-d");
        $availability = checkResourceAvailability($pdo, $resource, 1, $today, null, null, null);
        $borrowable = (bool) ($availability["ok"] ?? false);

        $facts = [
            "resource_name" => $resourceName,
            "available_quantity" => $availableStock,
            "under_repair_quantity" => $underRepair,
            "status" => $status,
            "borrowable_today" => $borrowable,
        ];

        if ($borrowable) {
            $text = "{$resourceName}: {$availableStock} available right now.";

            if ($underRepair > 0) {
                $text .= " {$underRepair} unit(s) are held for repair and cannot be borrowed until they are fixed.";
            }

            $text .= " You can submit a request from Browse Resources.";

            return gsoChatLiveResult($type, true, $text, $facts);
        }

        // Not borrowable: the service already explains why, in user-safe wording.
        $reason = trim((string) ($availability["message"] ?? ""));
        $text = "{$resourceName} is not available to borrow right now"
            . ($reason !== "" ? ": " . $reason : ".")
            . " Current on-hand quantity: {$availableStock}.";

        return gsoChatLiveResult($type, true, $text, $facts + ["reason" => $reason]);
    }
}

/* ------------------------------------------------------------------ *
 * Facility availability
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatCheckFacilityAvailability")) {
    function gsoChatCheckFacilityAvailability(PDO $pdo, array $authUser, string $message, array $params = []): array
    {
        $type = "facility_availability";
        $searchText = trim((string) ($params["facility_name"] ?? "")) !== ""
            ? (string) $params["facility_name"]
            : $message;

        $matches = gsoChatMatchResources($pdo, $searchText, "Facility");

        if ($matches === []) {
            return gsoChatLiveResult(
                $type,
                false,
                "I couldn't find a matching facility. Try the exact name as it appears in Browse Resources.",
                ["matched" => 0]
            );
        }

        if (count($matches) > 1) {
            return gsoChatLiveResult(
                $type,
                false,
                "I found more than one matching facility: " . gsoChatDescribeResourceList($matches)
                    . ". Which one do you mean?",
                ["matched" => count($matches), "options" => gsoChatDescribeResourceList($matches)],
                ["facility_name"]
            );
        }

        $facility = $matches[0];
        $facilityName = (string) $facility["resource_name"];

        $date = $params["date"] ?? gsoChatExtractDate($message);
        $startTime = $params["start_time"] ?? null;
        $endTime = $params["end_time"] ?? null;

        if ($startTime === null || $endTime === null) {
            $times = gsoChatExtractTimeRange($message);
            $startTime = $startTime ?? $times["start"];
            $endTime = $endTime ?? $times["end"];
        }

        // Ask only for what is actually missing; never invent a date or time.
        $needs = [];
        if ($date === null) {
            $needs[] = "date";
        }
        if ($startTime === null) {
            $needs[] = "start_time";
        }
        if ($endTime === null) {
            $needs[] = "end_time";
        }

        if ($needs !== []) {
            $prompts = [
                "date" => "date",
                "start_time" => "start time",
                "end_time" => "end time",
            ];
            $wanted = array_map(static fn (string $key): string => $prompts[$key], $needs);
            $last = array_pop($wanted);
            $phrase = $wanted === [] ? $last : implode(", ", $wanted) . " and " . $last;

            return gsoChatLiveResult(
                $type,
                false,
                "What {$phrase} would you like for the {$facilityName}?",
                ["facility" => $facilityName],
                $needs,
                [
                    "facility_name" => $facilityName,
                    "date" => $date,
                    "start_time" => $startTime,
                    "end_time" => $endTime,
                ]
            );
        }

        if (!gsoChatIsRealDate((string) $date)) {
            return gsoChatLiveResult($type, false, "That date doesn't look valid. Please give a date like 2026-09-20.", [], ["date"]);
        }

        if (strtotime((string) $startTime) >= strtotime((string) $endTime)) {
            return gsoChatLiveResult(
                $type,
                false,
                "The end time has to be after the start time. Please give a valid time range.",
                ["facility" => $facilityName],
                ["start_time", "end_time"],
                ["facility_name" => $facilityName, "date" => $date]
            );
        }

        if ($date < date("Y-m-d")) {
            return gsoChatLiveResult(
                $type,
                false,
                "That date has already passed. Please choose a current or future date.",
                ["facility" => $facilityName],
                ["date"],
                ["facility_name" => $facilityName, "start_time" => $startTime, "end_time" => $endTime]
            );
        }

        // Authoritative check: same service the reservation form uses, which
        // internally applies findScheduleConflict() and maintenance conflicts.
        $availability = checkResourceAvailability($pdo, $facility, 1, $date, $startTime, $endTime, null);
        $isAvailable = (bool) ($availability["ok"] ?? false);
        $reason = trim((string) ($availability["message"] ?? ""));

        $facts = [
            "facility" => $facilityName,
            "date" => (string) $date,
            "start_time" => substr((string) $startTime, 0, 5),
            "end_time" => substr((string) $endTime, 0, 5),
            "available" => $isAvailable,
            "conflict_summary" => $isAvailable ? "" : $reason,
        ];

        $window = $facts["start_time"] . " to " . $facts["end_time"] . " on " . $facts["date"];

        $text = $isAvailable
            ? "{$facilityName} is free {$window}. You can submit the reservation from Browse Resources; GSO still has to approve it."
            : "{$facilityName} is not available {$window}" . ($reason !== "" ? ": " . $reason : ".");

        return gsoChatLiveResult($type, true, $text, $facts);
    }
}

/* ------------------------------------------------------------------ *
 * Request status
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatGetRequestStatus")) {
    function gsoChatGetRequestStatus(PDO $pdo, array $authUser, string $message, array $params = []): array
    {
        $type = "request_status";
        $userId = (int) ($authUser["user_id"] ?? 0);
        $isAdmin = gsoChatIsAdmin($authUser);
        $requestId = $params["request_id"] ?? gsoChatExtractCode($message, ["REQ", "BRW"]);

        // Same wording for "does not exist" and "not yours", so the chatbot
        // cannot be used to discover that another user's request exists.
        $notFound = "I couldn't find a request with that reference under your account.";

        if ($requestId === null) {
            return gsoChatListOwnRequests($pdo, $authUser);
        }

        $sql = "SELECT rr.request_id, rr.status, rr.quantity, rr.date_needed, rr.due_date,
                       rr.return_date, rr.request_date, rr.borrower_id,
                       COALESCE((SELECT GROUP_CONCAT(CONCAT(lr.resource_name, CASE WHEN li.line_kind = 'Facility' THEN '' ELSE CONCAT(' x', li.requested_quantity) END) ORDER BY FIELD(li.line_kind,'Facility','Equipment','Consumable'), lr.resource_name SEPARATOR ', ') FROM request_items li INNER JOIN resources lr ON lr.resource_id = li.resource_id WHERE li.request_id = rr.request_id), r.resource_name) AS resource_name, r.resource_type,
                       rs.inspection_condition
                FROM resource_requests rr
                INNER JOIN resources r ON r.resource_id = rr.resource_id
                LEFT JOIN return_submissions rs
                    ON rs.request_id = rr.request_id AND rs.status = 'Approved'
                WHERE rr.request_id = :request_id";
        $queryParams = [":request_id" => (int) $requestId];

        // Ownership is part of the query for a normal user. An Admin sees the
        // same breadth the admin Requests page already gives them.
        if (!$isAdmin) {
            $sql .= " AND rr.borrower_id = :borrower_id";
            $queryParams[":borrower_id"] = $userId;
        }

        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($queryParams);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return gsoChatLiveResult($type, false, $notFound, ["found" => false]);
        }

        $lifecycle = getRequestLifecycleStatus($row);
        $code = formatRequestCode((int) $row["request_id"]);

        $facts = [
            "request_code" => $code,
            "resource_name" => (string) $row["resource_name"],
            "quantity" => (int) ($row["quantity"] ?? 0),
            "status" => (string) $row["status"],
            "lifecycle_stage" => $lifecycle,
            "date_needed" => (string) ($row["date_needed"] ?? ""),
            "due_date" => (string) ($row["due_date"] ?? ""),
        ];

        $text = "{$code} ({$facts["resource_name"]}) is currently {$lifecycle}.";

        if ($lifecycle === "Overdue") {
            $text .= " It was due " . formatDateTimeDisplay($row["due_date"]) . ". Please submit the return from My Borrowed.";
        } elseif ((string) $row["status"] === "Released") {
            $text .= " Return it by " . formatDateTimeDisplay($row["due_date"]) . ".";
        } elseif ((string) $row["status"] === "Approved") {
            $text .= " It is approved and waiting to be released by GSO.";
        } elseif (in_array((string) $row["status"], ["Pending", "Under Review"], true)) {
            $text .= " GSO has not completed the review yet.";
        }

        return gsoChatLiveResult($type, true, $text, $facts);
    }
}

if (!function_exists("gsoChatListOwnRequests")) {
    /**
     * No reference given: summarise the caller's own open requests. Admins are
     * asked for a reference instead, because "all requests" is a report, not a
     * chat answer - and dumping it is exactly what we must not do.
     */
    function gsoChatListOwnRequests(PDO $pdo, array $authUser): array
    {
        $type = "request_status";
        $userId = (int) ($authUser["user_id"] ?? 0);

        if (gsoChatIsAdmin($authUser)) {
            return gsoChatLiveResult(
                $type,
                false,
                "Which request should I check? Give me a reference like REQ-123, or open the Requests page for the full pipeline.",
                [],
                ["request_code"]
            );
        }

        $stmt = $pdo->prepare("
            SELECT rr.request_id, rr.status, rr.due_date,
                   COALESCE((SELECT GROUP_CONCAT(CONCAT(lr.resource_name, CASE WHEN li.line_kind = 'Facility' THEN '' ELSE CONCAT(' x', li.requested_quantity) END) ORDER BY FIELD(li.line_kind,'Facility','Equipment','Consumable'), lr.resource_name SEPARATOR ', ') FROM request_items li INNER JOIN resources lr ON lr.resource_id = li.resource_id WHERE li.request_id = rr.request_id), r.resource_name) AS resource_name
            FROM resource_requests rr
            INNER JOIN resources r ON r.resource_id = rr.resource_id
            WHERE rr.borrower_id = :borrower_id
              AND rr.status IN ('Pending', 'Under Review', 'Approved', 'Released')
            ORDER BY rr.request_id DESC
            LIMIT 5
        ");
        $stmt->execute([":borrower_id" => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return gsoChatLiveResult(
                $type,
                true,
                "You have no open requests right now. Submitted requests appear under My Requests.",
                ["open_requests" => 0]
            );
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = formatRequestCode((int) $row["request_id"])
                . " (" . (string) $row["resource_name"] . "): "
                . getRequestLifecycleStatus($row);
        }

        return gsoChatLiveResult(
            $type,
            true,
            "Your open requests:\n" . implode("\n", $lines),
            ["open_requests" => count($rows), "summary" => implode("; ", $lines)]
        );
    }
}

/* ------------------------------------------------------------------ *
 * Incident status
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatGetIncidentStatus")) {
    function gsoChatGetIncidentStatus(PDO $pdo, array $authUser, string $message, array $params = []): array
    {
        $type = "incident_status";
        $userId = (int) ($authUser["user_id"] ?? 0);
        $isAdmin = gsoChatIsAdmin($authUser);
        $incidentId = $params["incident_id"] ?? gsoChatExtractCode($message, ["INC"]);
        $notFound = "I couldn't find an incident report with that reference under your account.";

        if ($incidentId === null) {
            return gsoChatListOwnIncidents($pdo, $authUser);
        }

        // Reuse the existing ownership-scoped lookups rather than writing new SQL.
        $incident = $isAdmin
            ? findAdminIncidentReport($pdo, (int) $incidentId)
            : findBorrowerIncidentReport($pdo, (int) $incidentId, $userId);

        if ($incident === null) {
            return gsoChatLiveResult($type, false, $notFound, ["found" => false]);
        }

        // Deliberately narrower than the incident page: internal remarks,
        // assignee and attachments are left out of chatbot output.
        $facts = [
            "incident_code" => formatIncidentNumber((int) $incident["incident_id"]),
            "title" => (string) ($incident["incident_title"] ?? ""),
            "incident_type" => (string) ($incident["incident_type"] ?? ""),
            "status" => (string) ($incident["status"] ?? ""),
            "priority" => (string) ($incident["priority"] ?? ""),
            "reported_at" => formatIncidentDateTime($incident["reported_at"] ?? null),
        ];

        $text = "{$facts["incident_code"]} ({$facts["title"]}) is currently {$facts["status"]}"
            . ($facts["priority"] !== "" ? ", priority {$facts["priority"]}" : "") . ".";

        if ((string) ($incident["status"] ?? "") === "Resolved") {
            $facts["resolved_at"] = formatIncidentDateTime($incident["resolved_at"] ?? null);
            $summary = trim((string) ($incident["resolution_notes"] ?? ""));

            if ($summary !== "") {
                $facts["resolution_summary"] = truncateIncidentText($summary, 300);
                $text .= " Resolution: " . $facts["resolution_summary"];
            }
        } else {
            $text .= " You'll be notified as the status changes; details are under My Reports.";
        }

        return gsoChatLiveResult($type, true, $text, $facts);
    }
}

if (!function_exists("gsoChatListOwnIncidents")) {
    function gsoChatListOwnIncidents(PDO $pdo, array $authUser): array
    {
        $type = "incident_status";
        $userId = (int) ($authUser["user_id"] ?? 0);

        if (gsoChatIsAdmin($authUser)) {
            return gsoChatLiveResult(
                $type,
                false,
                "Which incident should I check? Give me a reference like INC-000123, or open Incident Reports for the full list.",
                [],
                ["incident_code"]
            );
        }

        $stmt = $pdo->prepare("
            SELECT incident_id, incident_title, status
            FROM incident_reports
            WHERE reporter_id = :reporter_id
            ORDER BY incident_id DESC
            LIMIT 5
        ");
        $stmt->execute([":reporter_id" => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            return gsoChatLiveResult(
                $type,
                true,
                "You haven't submitted any incident reports yet. You can file one from Report an Issue.",
                ["incident_count" => 0]
            );
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = formatIncidentNumber((int) $row["incident_id"])
                . " (" . (string) $row["incident_title"] . "): " . (string) $row["status"];
        }

        return gsoChatLiveResult(
            $type,
            true,
            "Your recent incident reports:\n" . implode("\n", $lines),
            ["incident_count" => count($rows), "summary" => implode("; ", $lines)]
        );
    }
}

/* ------------------------------------------------------------------ *
 * Maintenance status
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatGetMaintenanceStatus")) {
    function gsoChatGetMaintenanceStatus(PDO $pdo, array $authUser, string $message, array $params = []): array
    {
        $type = "maintenance_status";
        $isAdmin = gsoChatIsAdmin($authUser);
        $searchText = trim((string) ($params["resource_name"] ?? "")) !== ""
            ? (string) $params["resource_name"]
            : $message;

        $matches = gsoChatMatchResources($pdo, $searchText);

        if ($matches === []) {
            return gsoChatLiveResult(
                $type,
                false,
                "I couldn't tell which resource you mean. Try the exact name as it appears in Browse Resources.",
                ["matched" => 0]
            );
        }

        if (count($matches) > 1) {
            return gsoChatLiveResult(
                $type,
                false,
                "I found more than one match: " . gsoChatDescribeResourceList($matches) . ". Which one do you mean?",
                ["matched" => count($matches)],
                ["resource_name"]
            );
        }

        $resource = $matches[0];
        $resourceId = (int) $resource["resource_id"];
        $resourceName = (string) $resource["resource_name"];
        $underRepair = max(0, (int) ($resource["under_repair_stock"] ?? 0));
        $resourceStatus = (string) ($resource["status"] ?? "");

        // Existing maintenance service, over a window wide enough to catch an
        // active or imminent schedule.
        $schedule = getMaintenanceConflict(
            $pdo,
            $resourceId,
            date("Y-m-d 00:00:00"),
            date("Y-m-d 23:59:59", strtotime("+90 days"))
        );

        $facts = [
            "resource_name" => $resourceName,
            "resource_status" => $resourceStatus,
            "under_repair_quantity" => $underRepair,
            "under_maintenance" => $schedule !== null,
        ];

        if ($schedule === null) {
            $text = $underRepair > 0
                ? "{$resourceName} has no active maintenance schedule, but {$underRepair} unit(s) are still held for repair and are not borrowable yet."
                : "{$resourceName} has no active or upcoming maintenance. Its current status is {$resourceStatus}.";

            return gsoChatLiveResult($type, true, $text, $facts);
        }

        $facts["scheduled_from"] = formatDateTimeDisplay($schedule["start_date"]);
        $facts["scheduled_to"] = formatDateTimeDisplay($schedule["end_date"]);
        $facts["schedule_status"] = (string) ($schedule["status"] ?? "");

        $text = "{$resourceName} is under maintenance ({$facts["schedule_status"]}) from "
            . "{$facts["scheduled_from"]} to {$facts["scheduled_to"]}. It can't be borrowed during that period.";

        // Internal scheduling notes stay with staff, mirroring the admin pages.
        if ($isAdmin) {
            $reason = trim((string) ($schedule["reason"] ?? ""));

            if ($reason !== "") {
                $facts["reason"] = $reason;
                $text .= " Reason: {$reason}.";
            }
        }

        return gsoChatLiveResult($type, true, $text, $facts);
    }
}

/* ------------------------------------------------------------------ *
 * Pending multi-turn state
 *
 * Stored as a 'system' message on the conversation, which already exists in
 * ai_messages and is filtered out of the user-visible transcript. That keeps
 * multi-turn follow-ups working with no schema change, and inherits the
 * conversation's ownership checks for free.
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatSavePendingIntent")) {
    function gsoChatSavePendingIntent(PDO $pdo, int $conversationId, string $intent, array $params): void
    {
        $payload = json_encode([
            "intent" => $intent,
            "params" => $params,
            "saved_at" => time(),
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($payload)) {
            return;
        }

        try {
            $pdo->prepare("
                INSERT INTO ai_messages (conversation_id, sender_type, message, intent)
                VALUES (:conversation_id, 'system', :message, 'pending_intent')
            ")->execute([
                ":conversation_id" => $conversationId,
                ":message" => $payload,
            ]);
        } catch (Throwable $e) {
            error_log("Pending intent save failed: " . $e->getMessage());
        }
    }
}

if (!function_exists("gsoChatClearPendingIntent")) {
    function gsoChatClearPendingIntent(PDO $pdo, int $conversationId): void
    {
        try {
            $pdo->prepare("
                INSERT INTO ai_messages (conversation_id, sender_type, message, intent)
                VALUES (:conversation_id, 'system', '{}', 'pending_cleared')
            ")->execute([":conversation_id" => $conversationId]);
        } catch (Throwable $e) {
            error_log("Pending intent clear failed: " . $e->getMessage());
        }
    }
}

if (!function_exists("gsoChatLoadPendingIntent")) {
    /**
     * The still-open pending intent for this conversation, if any.
     *
     * Only the most recent system marker counts, so a cleared or expired one
     * can never resurrect an old question.
     */
    function gsoChatLoadPendingIntent(PDO $pdo, int $conversationId): ?array
    {
        if ($conversationId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT message, intent, created_at
                FROM ai_messages
                WHERE conversation_id = :conversation_id
                  AND sender_type = 'system'
                ORDER BY message_id DESC
                LIMIT 1
            ");
            $stmt->execute([":conversation_id" => $conversationId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log("Pending intent lookup failed: " . $e->getMessage());
            return null;
        }

        if (!$row || (string) $row["intent"] !== "pending_intent") {
            return null;
        }

        $decoded = json_decode((string) $row["message"], true);

        if (!is_array($decoded) || empty($decoded["intent"])) {
            return null;
        }

        if ((time() - (int) ($decoded["saved_at"] ?? 0)) > GSO_CHAT_PENDING_TTL_SECONDS) {
            return null;
        }

        return [
            "intent" => (string) $decoded["intent"],
            "params" => is_array($decoded["params"] ?? null) ? $decoded["params"] : [],
        ];
    }
}

/* ------------------------------------------------------------------ *
 * Dispatch
 * ------------------------------------------------------------------ */

if (!function_exists("gsoChatRefineLiveIntent")) {
    /**
     * Catch availability questions the fixed pattern list cannot anticipate.
     *
     * The Step 9 router cannot enumerate every resource name, so "Is the
     * laboratory available?" would fall through to the FAQ branch. This adds a
     * second deterministic pass: an availability cue plus a name that matches
     * the real catalogue routes to the live adapter for that resource type.
     *
     * Still entirely rule-based - the model has no say in routing.
     *
     * Procedure questions are excluded, so "How do I borrow equipment?" stays
     * a knowledge-base answer rather than becoming a stock lookup.
     */
    function gsoChatRefineLiveIntent(PDO $pdo, string $message, array $classification): array
    {
        if (!empty($classification["live"])) {
            return $classification;
        }

        $text = gsoChatNormalizeText($message);

        if ($text === "") {
            return $classification;
        }

        // An explicit reference is unambiguous: route straight to the record
        // lookup, which then applies its own ownership scoping.
        if (preg_match('/\binc[-#:\s]*\d/i', $message) === 1
            || preg_match('/\bincident\s*(?:number|no\.?|#)?\s*\d/i', $message) === 1
        ) {
            return ["intent" => "incident_status", "live" => true, "matched" => "reference"];
        }

        if (preg_match('/\b(?:req|brw)[-#:\s]*\d/i', $message) === 1
            || preg_match('/\brequest\s*(?:number|no\.?|#)?\s*\d/i', $message) === 1
        ) {
            return ["intent" => "request_status", "live" => true, "matched" => "reference"];
        }

        $procedural = [
            "how do i", "how do we", "how to", "how can i", "what should i do",
            "what are the requirement", "what is the process", "what is the procedure",
            "steps to", "procedure for", "process for", "where do i", "where can i",
        ];

        foreach ($procedural as $marker) {
            if (strpos($text, $marker) !== false) {
                return $classification;
            }
        }

        // Word-boundary matching so "free" does not fire on "freedom".
        $cuePattern = '/\b(available|availability|vacant|free|unoccupied|booked|occupied|'
            . 'can i (?:borrow|reserve|use|get)|may i (?:borrow|use)|how many|in stock|any left|still have)\b/';

        if (preg_match($cuePattern, $text) !== 1) {
            return $classification;
        }

        // An availability question goes to the live adapter even when nothing
        // matches, so the user gets an honest "I couldn't find that resource"
        // instead of an unrelated procedure article.
        $matches = gsoChatMatchResources($pdo, $message);
        $isFacility = $matches !== [] && (string) ($matches[0]["resource_type"] ?? "") === "Facility";

        return [
            "intent" => $isFacility ? "facility_availability" : "resource_availability",
            "live" => true,
            "matched" => $matches === [] ? "cue" : "catalogue",
        ];
    }
}

if (!function_exists("gsoChatMergePendingParams")) {
    /**
     * Fold a follow-up message into the values already gathered.
     *
     * Anything the user states now wins over the stored value, so a correction
     * ("make that Friday instead") is respected rather than overridden by the
     * earlier answer.
     */
    function gsoChatMergePendingParams(array $pendingParams, string $message): array
    {
        $merged = $pendingParams;
        $date = gsoChatExtractDate($message);

        if ($date !== null) {
            $merged["date"] = $date;
        }

        $times = gsoChatExtractTimeRange($message);

        if ($times["start"] !== null) {
            $merged["start_time"] = $times["start"];
        }

        if ($times["end"] !== null) {
            $merged["end_time"] = $times["end"];
        }

        return $merged;
    }
}

if (!function_exists("gsoChatHandleLiveIntent")) {
    /**
     * Route a live intent to its adapter.
     *
     * The intent string comes from the deterministic router, never from the
     * model, so this switch cannot be steered by message content.
     */
    function gsoChatHandleLiveIntent(
        PDO $pdo,
        array $authUser,
        string $intent,
        string $message,
        array $params = []
    ): ?array {
        switch ($intent) {
            case "resource_availability":
                return gsoChatCheckResourceAvailability($pdo, $authUser, $message, $params);
            case "facility_availability":
                return gsoChatCheckFacilityAvailability($pdo, $authUser, $message, $params);
            case "request_status":
                return gsoChatGetRequestStatus($pdo, $authUser, $message, $params);
            case "incident_status":
                return gsoChatGetIncidentStatus($pdo, $authUser, $message, $params);
            case "maintenance_status":
                return gsoChatGetMaintenanceStatus($pdo, $authUser, $message, $params);
            default:
                return null;
        }
    }
}
