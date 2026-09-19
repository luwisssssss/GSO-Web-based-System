<?php
declare(strict_types=1);

require_once __DIR__ . "/request_helper.php";
require_once __DIR__ . "/adviser_endorsement_helper.php";
require_once __DIR__ . "/request_line_helper.php";
require_once __DIR__ . "/notification_helper.php";
require_once __DIR__ . "/activity_log_helper.php";

if (!function_exists("gsoIsApprovedDirector")) {
    /**
     * Authoritative Director check. The Admin role holds the "*" permission
     * wildcard, so permission checks alone would let a GSO Head record a
     * Director decision. Only the database role decides who may act here.
     */
    function gsoIsApprovedDirector(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT 1 FROM users
            WHERE user_id = :user_id
              AND role = 'Director'
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([":user_id" => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("gsoDirectorStateBlocksGso")) {
    function gsoDirectorStateBlocksGso(array $request): bool
    {
        return (string) ($request["director_state"] ?? "Not Required") === "Pending";
    }
}

if (!function_exists("gsoDirectorGateAllowsRelease")) {
    function gsoDirectorGateAllowsRelease(array $request): bool
    {
        return in_array(
            (string) ($request["director_state"] ?? "Not Required"),
            ["Not Required", "Approved"],
            true
        );
    }
}

if (!function_exists("gsoCanRouteRequestToDirector")) {
    function gsoCanRouteRequestToDirector(array $request): bool
    {
        return in_array((string) ($request["status"] ?? ""), ["Pending", "Under Review"], true)
            && in_array((string) ($request["adviser_state"] ?? "Not Required"), ["Not Required", "Endorsed"], true)
            && (string) ($request["director_state"] ?? "Not Required") === "Not Required";
    }
}

if (!function_exists("gsoDirectorHistory")) {
    function gsoDirectorHistory(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare("
            SELECT h.*, d.full_name AS director_name
            FROM request_director_history h
            INNER JOIN users d ON d.user_id = h.director_user_id
            WHERE h.request_id = :request_id
            ORDER BY h.decided_at ASC, h.director_history_id ASC
        ");
        $stmt->execute([":request_id" => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoFacilityConflictsForRequest")) {
    function gsoFacilityConflictsForRequest(PDO $pdo, array $request): array
    {
        // Phase 3.5A: the facility is a request line, not the request header.
        // An equipment-only request has no facility line and therefore never
        // enters facility conflict prioritisation.
        $facilityLine = gsoRequestFacilityLine($pdo, (int) ($request["request_id"] ?? 0));

        if ($facilityLine === null) {
            return [];
        }

        return findScheduleConflicts(
            $pdo,
            (int) $facilityLine["resource_id"],
            "Facility",
            $request["date_needed"] ?? null,
            $request["start_time"] ?? null,
            $request["end_time"] ?? null,
            $request["due_date"] ?? null,
            (int) ($request["request_id"] ?? 0)
        );
    }
}

if (!function_exists("gsoNotifyApprovedRoles")) {
    function gsoNotifyApprovedRoles(
        PDO $pdo,
        array $roles,
        string $type,
        string $title,
        string $message,
        string $link
    ): void {
        $roles = array_values(array_intersect(["Admin", "GSO Staff", "Director"], array_unique($roles)));
        if ($roles === []) {
            return;
        }
        $marks = implode(",", array_fill(0, count($roles), "?"));
        $stmt = $pdo->prepare("
            SELECT user_id FROM users
            WHERE role IN ({$marks}) AND account_status='Approved' AND email_verified=1
        ");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $userId) {
            createNotification($pdo, (int) $userId, $type, $title, $message, $link);
        }
    }
}

if (!function_exists("gsoNewDirectorDecisionBatch")) {
    function gsoNewDirectorDecisionBatch(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . "-" . substr($hex, 8, 4) . "-" . substr($hex, 12, 4)
            . "-" . substr($hex, 16, 4) . "-" . substr($hex, 20, 12);
    }
}

if (!function_exists("gsoSendDirectorAssignmentEmails")) {
    /** Notification only: links always require the Director to authenticate. */
    function gsoSendDirectorAssignmentEmails(PDO $pdo, int $requestId, int $actorId): bool
    {
        require_once dirname(__DIR__, 2) . "/includes/mail_helper.php";
        $stmt = $pdo->prepare("
            SELECT d.full_name,d.email,r.resource_name,b.full_name AS borrower_name
            FROM users d
            JOIN resource_requests rr ON rr.request_id=:request_id
            JOIN " . gsoRequestResourceRollupSql() . " r ON r.request_id=rr.request_id
            JOIN users b ON b.user_id=rr.borrower_id
            WHERE d.role='Director' AND d.account_status='Approved' AND d.email_verified=1
        ");
        $stmt->execute([":request_id" => $requestId]);
        $directors = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($directors === []) return false;
        $config = require dirname(__DIR__, 2) . "/config/mail.php";
        $ref = formatRequestCode($requestId);
        $url = gsoBuildAppUrl("director/request.php?request_id={$requestId}");
        $allSent = true;
        foreach ($directors as $director) {
            if (str_ends_with(strtolower((string)$director["email"]), "@example.invalid")) {
                $allSent = false;
                continue;
            }
            $result = gsoSendEmail(
                $config,
                (string)$director["email"],
                (string)$director["full_name"],
                "Campus Director decision required: {$ref}",
                "<p>A GSO request requires your authenticated decision.</p><p><a href=\"".htmlspecialchars($url,ENT_QUOTES,"UTF-8")."\">Sign in to review {$ref}</a></p><p>This email cannot authorize or record a decision.</p>",
                "A GSO request requires your decision. Sign in to review {$ref}: {$url}"
            );
            $allSent = $allSent && !empty($result["success"]);
        }
        addActivityLog($pdo,$actorId,$allSent?"Director Email Notification Sent":"Director Email Notification Failed","Director assignment email for {$ref} ".($allSent?"was sent.":"was unavailable; in-app routing remains active."));
        if (!$allSent) error_log("Director assignment email notification failed for request {$requestId}.");
        return $allSent;
    }
}
