<?php

require_once __DIR__ . "/maintenance_helper.php";

if (!function_exists("normalizeDateTimeInput")) {
    function normalizeDateTimeInput(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === "") {
            return null;
        }

        $value = str_replace("T", " ", $value);

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ":00";
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return date("Y-m-d H:i:s", $timestamp);
    }
}

if (!function_exists("formatDateTimeDisplay")) {
    function formatDateTimeDisplay($value): string
    {
        if (empty($value)) {
            return "N/A";
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return "N/A";
        }

        if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', (string) $value)) {
            return date("h:i A", $timestamp);
        }

        if (strpos((string) $value, ":") !== false) {
            return date("M d, Y h:i A", $timestamp);
        }

        return date("M d, Y", $timestamp);
    }
}

if (!function_exists("formatTimeDisplay")) {
    function formatTimeDisplay($value): string
    {
        if (empty($value)) {
            return "N/A";
        }

        $timestamp = strtotime((string) $value);

        if ($timestamp === false) {
            return "N/A";
        }

        return date("h:i A", $timestamp);
    }
}

if (!function_exists("buildTimeRangeDisplay")) {
    function buildTimeRangeDisplay(?string $startTime, ?string $endTime): string
    {
        if (!empty($startTime) && !empty($endTime)) {
            return formatTimeDisplay($startTime) . " - " . formatTimeDisplay($endTime);
        }

        if (!empty($startTime)) {
            return formatTimeDisplay($startTime);
        }

        return "N/A";
    }
}

if (!function_exists("buildRequestDateRange")) {
    function buildRequestDateRange(
        string $resourceType,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate,
        ?string $requestStatus = null
    ): ?array {
        if (empty($dateNeeded)) {
            return null;
        }

        $rangeStart = $dateNeeded . " " . (!empty($startTime) ? $startTime : "00:00:00");
        $rangeStart = normalizeDateTimeInput($rangeStart);

        if ($rangeStart === null) {
            return null;
        }

        if ($resourceType === "Facility") {
            $rangeEnd = $dateNeeded . " " . (!empty($endTime) ? $endTime : "23:59:59");
            $rangeEnd = normalizeDateTimeInput($rangeEnd);

            return $rangeEnd === null ? null : [
                "start" => $rangeStart,
                "end" => $rangeEnd
            ];
        }

        $shouldUseLoanDueDate = !empty($dueDate)
            && ($requestStatus === "Released" || $requestStatus === null);

        $rangeEnd = $shouldUseLoanDueDate ? normalizeDateTimeInput($dueDate) : null;

        if ($rangeEnd === null) {
            $rangeEnd = $dateNeeded . " " . (!empty($endTime) ? $endTime : "23:59:59");
            $rangeEnd = normalizeDateTimeInput($rangeEnd);
        }

        if ($rangeEnd === null) {
            $rangeEnd = $rangeStart;
        }

        return [
            "start" => $rangeStart,
            "end" => $rangeEnd
        ];
    }
}

if (!function_exists("intervalsOverlap")) {
    function intervalsOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return strtotime($startA) < strtotime($endB) && strtotime($endA) > strtotime($startB);
    }
}

if (!function_exists("validateBorrowScheduleInputs")) {
    function validateBorrowScheduleInputs(
        string $resourceType,
        int $quantity,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate
    ): array {
        $errors = [];
        $today = date("Y-m-d");
        $currentTime = date("H:i:s");
        $normalizedDueDate = !empty($dueDate) ? normalizeDateTimeInput($dueDate) : null;

        if ($quantity <= 0) {
            $errors[] = "Quantity must be greater than 0.";
        }

        if (empty($dateNeeded)) {
            $errors[] = "Date needed is required.";
        } elseif ($dateNeeded < $today) {
            $errors[] = "Past dates are not allowed.";
        }

        $hasStartTime = !empty($startTime);
        $hasEndTime = !empty($endTime);

        if ($resourceType === "Facility") {
            if (!$hasStartTime || !$hasEndTime) {
                $errors[] = "Start time and end time are required for facility reservations.";
            }
        } elseif ($hasStartTime xor $hasEndTime) {
            $errors[] = "Provide both start time and end time or leave both blank.";
        }

        if ($hasStartTime && $hasEndTime) {
            if ($startTime === $endTime) {
                $errors[] = "Start time and end time cannot be the same.";
            } elseif (strtotime($startTime) >= strtotime($endTime)) {
                $errors[] = "End time must be later than the start time.";
            }
        }

        if (!empty($dateNeeded) && $dateNeeded === $today) {
            if ($hasStartTime && strtotime($startTime) <= strtotime($currentTime)) {
                $errors[] = "Past start times are not allowed for today.";
            }

            if ($hasEndTime && strtotime($endTime) <= strtotime($currentTime)) {
                $errors[] = "Past end times are not allowed for today.";
            }
        }

        if (!empty($dueDate) && $normalizedDueDate === null) {
            $errors[] = "A valid due date and time is required.";
        }

        if (empty($errors) && !empty($dateNeeded) && $normalizedDueDate !== null) {
            $minimumReferenceTime = "00:00:00";

            if ($resourceType === "Facility" && $hasEndTime) {
                $minimumReferenceTime = $endTime;
            } elseif ($hasStartTime) {
                $minimumReferenceTime = $startTime;
            }

            $minimumReference = normalizeDateTimeInput($dateNeeded . " " . $minimumReferenceTime);

            if ($minimumReference !== null && strtotime($normalizedDueDate) < strtotime($minimumReference)) {
                $errors[] = "Due date and time must be on or after the selected borrowing schedule.";
            }
        }

        return $errors;
    }
}

if (!function_exists("getDefaultLoanDurationHours")) {
    function getDefaultLoanDurationHours(): int
    {
        $configuredHours = (int) (getenv("GSO_DEFAULT_LOAN_HOURS") ?: 72);

        return max(1, $configuredHours);
    }
}

if (!function_exists("generateLoanDueDate")) {
    function generateLoanDueDate(
        string $resourceType,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $issuedAt = null
    ): string {
        $issuedAt = normalizeDateTimeInput($issuedAt ?: date("Y-m-d H:i:s")) ?: date("Y-m-d H:i:s");

        if ($resourceType === "Facility") {
            $facilityRange = buildRequestDateRange(
                "Facility",
                $dateNeeded,
                $startTime,
                $endTime,
                null,
                null
            );

            if ($facilityRange !== null && strtotime($facilityRange["end"]) > strtotime($issuedAt)) {
                return $facilityRange["end"];
            }
        }

        return date("Y-m-d H:i:s", strtotime($issuedAt) + (getDefaultLoanDurationHours() * 3600));
    }
}

if (!function_exists("fetchActiveScheduleRequests")) {
    function fetchActiveScheduleRequests(
        PDO $pdo,
        int $resourceId,
        ?int $excludeRequestId = null,
        ?int $borrowerId = null
    ): array {
        $sql = "
            SELECT
                request_id,
                borrower_id,
                resource_id,
                quantity,
                date_needed,
                start_time,
                end_time,
                request_date,
                due_date,
                status
            FROM resource_requests
            WHERE resource_id = :resource_id
              AND status IN ('Pending', 'Under Review', 'Approved', 'Released')
        ";

        $params = [
            ":resource_id" => $resourceId
        ];

        if ($excludeRequestId !== null && $excludeRequestId > 0) {
            $sql .= " AND request_id <> :exclude_request_id";
            $params[":exclude_request_id"] = $excludeRequestId;
        }

        if ($borrowerId !== null && $borrowerId > 0) {
            $sql .= " AND borrower_id = :borrower_id";
            $params[":borrower_id"] = $borrowerId;
        }

        $sql .= " ORDER BY request_date ASC, request_id ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("findScheduleConflict")) {
    function findScheduleConflict(
        PDO $pdo,
        int $resourceId,
        string $resourceType,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate,
        ?int $excludeRequestId = null,
        ?int $borrowerId = null
    ): ?array {
        $targetRange = buildRequestDateRange($resourceType, $dateNeeded, $startTime, $endTime, $dueDate);

        if ($targetRange === null) {
            return null;
        }

        $requests = fetchActiveScheduleRequests($pdo, $resourceId, $excludeRequestId, $borrowerId);

        foreach ($requests as $row) {
            $rowRange = buildRequestDateRange(
                $resourceType,
                $row["date_needed"] ?? null,
                $row["start_time"] ?? null,
                $row["end_time"] ?? null,
                $row["due_date"] ?? null,
                $row["status"] ?? null
            );

            if ($rowRange === null) {
                continue;
            }

            if (intervalsOverlap($targetRange["start"], $targetRange["end"], $rowRange["start"], $rowRange["end"])) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists("calculateReservedItemQuantity")) {
    function calculateReservedItemQuantity(
        PDO $pdo,
        int $resourceId,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate,
        ?int $excludeRequestId = null
    ): int {
        $targetRange = buildRequestDateRange("Item", $dateNeeded, $startTime, $endTime, $dueDate);

        if ($targetRange === null) {
            return 0;
        }

        $reservedQuantity = 0;
        $requests = fetchActiveScheduleRequests($pdo, $resourceId, $excludeRequestId);

        foreach ($requests as $row) {
            $rowRange = buildRequestDateRange(
                "Item",
                $row["date_needed"] ?? null,
                $row["start_time"] ?? null,
                $row["end_time"] ?? null,
                $row["due_date"] ?? null,
                $row["status"] ?? null
            );

            if ($rowRange === null) {
                continue;
            }

            if (intervalsOverlap($targetRange["start"], $targetRange["end"], $rowRange["start"], $rowRange["end"])) {
                $reservedQuantity += (int) ($row["quantity"] ?? 0);
            }
        }

        return $reservedQuantity;
    }
}

if (!function_exists("checkResourceAvailability")) {
    function checkResourceAvailability(
        PDO $pdo,
        array $resource,
        int $quantity,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate,
        ?int $excludeRequestId = null
    ): array {
        if ((int) ($resource["is_archived"] ?? 0) === 1) {
            return [
                "ok" => false,
                "message" => "This resource is archived and unavailable."
            ];
        }

        $resourceType = (string) ($resource["resource_type"] ?? "");
        $conditionStatus = (string) ($resource["condition_status"] ?? "Good");
        $resourceStatus = (string) ($resource["status"] ?? "Available");

        if ($resourceStatus === "Maintenance") {
            return [
                "ok" => false,
                "message" => "This resource is currently under maintenance."
            ];
        }

        if ($resourceStatus === "Unavailable" && $conditionStatus === "Good") {
            return [
                "ok" => false,
                "message" => "This resource is currently unavailable."
            ];
        }

        if (in_array($conditionStatus, ["Damaged", "Missing Parts", "Needs Repair", "Lost"], true)) {
            return [
                "ok" => false,
                "message" => "This resource is currently marked as {$conditionStatus} and cannot be selected."
            ];
        }

        $range = buildRequestDateRange($resourceType, $dateNeeded, $startTime, $endTime, $dueDate);
        $maintenanceConflict = getMaintenanceConflict(
            $pdo,
            (int) ($resource["resource_id"] ?? 0),
            $range["start"] ?? null,
            $range["end"] ?? null
        );

        if ($maintenanceConflict) {
            return [
                "ok" => false,
                "message" => "This resource is scheduled for maintenance from "
                    . formatDateTimeDisplay($maintenanceConflict["start_date"])
                    . " to "
                    . formatDateTimeDisplay($maintenanceConflict["end_date"])
                    . "."
            ];
        }

        if ($resourceType === "Facility") {
            $conflict = findScheduleConflict(
                $pdo,
                (int) ($resource["resource_id"] ?? 0),
                $resourceType,
                $dateNeeded,
                $startTime,
                $endTime,
                $dueDate,
                $excludeRequestId
            );

            if ($conflict) {
                return [
                    "ok" => false,
                    "message" => "Another reservation already occupies the selected date and time."
                ];
            }

            return [
                "ok" => true,
                "message" => ""
            ];
        }

        $totalStock = isset($resource["total_stock"]) ? (int) $resource["total_stock"] : 0;

        if ($totalStock <= 0) {
            return [
                "ok" => false,
                "message" => "This item has no stock available for scheduling."
            ];
        }

        $reservedQuantity = calculateReservedItemQuantity(
            $pdo,
            (int) ($resource["resource_id"] ?? 0),
            $dateNeeded,
            $startTime,
            $endTime,
            $dueDate,
            $excludeRequestId
        );

        if ($reservedQuantity + $quantity > $totalStock) {
            return [
                "ok" => false,
                "message" => "Insufficient stock for the selected borrowing schedule. "
                    . ($totalStock - $reservedQuantity) . " item(s) remain available in that time window."
            ];
        }

        return [
            "ok" => true,
            "message" => ""
        ];
    }
}

if (!function_exists("findBorrowerDuplicateConflict")) {
    function findBorrowerDuplicateConflict(
        PDO $pdo,
        int $borrowerId,
        int $resourceId,
        string $resourceType,
        ?string $dateNeeded,
        ?string $startTime,
        ?string $endTime,
        ?string $dueDate,
        ?int $excludeRequestId = null
    ): ?array {
        return findScheduleConflict(
            $pdo,
            $resourceId,
            $resourceType,
            $dateNeeded,
            $startTime,
            $endTime,
            $dueDate,
            $excludeRequestId,
            $borrowerId
        );
    }
}

if (!function_exists("formatRequestCode")) {
    function formatRequestCode(int $requestId, string $prefix = "REQ"): string
    {
        return $prefix . "-" . str_pad((string) $requestId, 3, "0", STR_PAD_LEFT);
    }
}

if (!function_exists("normalizePhoneNumberForTel")) {
    function normalizePhoneNumberForTel(?string $phoneNumber): string
    {
        $phoneNumber = trim((string) $phoneNumber);

        if ($phoneNumber === "") {
            return "";
        }

        $hasLeadingPlus = substr($phoneNumber, 0, 1) === "+";
        $digits = preg_replace('/\D+/', '', $phoneNumber);

        if ($digits === null || $digits === "") {
            return "";
        }

        return ($hasLeadingPlus ? "+" : "") . $digits;
    }
}

if (!function_exists("buildPhoneCallHref")) {
    function buildPhoneCallHref(?string $phoneNumber): string
    {
        $normalizedPhoneNumber = normalizePhoneNumberForTel($phoneNumber);

        return $normalizedPhoneNumber !== "" ? "tel:" . $normalizedPhoneNumber : "";
    }
}

if (!function_exists("getFirstComeFirstServedStatuses")) {
    function getFirstComeFirstServedStatuses(): array
    {
        return ["Pending", "Under Review", "Approved", "Released"];
    }
}

if (!function_exists("getEarlierFirstComeFirstServedRequests")) {
    function getEarlierFirstComeFirstServedRequests(
        PDO $pdo,
        array $requestRow,
        array $statuses
    ): array {
        $resourceId = (int) ($requestRow["resource_id"] ?? 0);
        $requestId = (int) ($requestRow["request_id"] ?? 0);
        $requestDate = (string) ($requestRow["request_date"] ?? "");
        $resourceType = (string) ($requestRow["resource_type"] ?? "Item");

        if ($resourceId <= 0 || $requestId <= 0 || $requestDate === "" || empty($statuses)) {
            return [];
        }

        $targetRange = buildRequestDateRange(
            $resourceType,
            $requestRow["date_needed"] ?? null,
            $requestRow["start_time"] ?? null,
            $requestRow["end_time"] ?? null,
            $requestRow["due_date"] ?? null,
            $requestRow["status"] ?? null
        );

        if ($targetRange === null) {
            return [];
        }

        $statusPlaceholders = [];
        $params = [
            ":resource_id" => $resourceId,
            ":request_id" => $requestId,
            ":request_date_before" => $requestDate,
            ":request_date_same" => $requestDate,
            ":request_id_before" => $requestId
        ];

        foreach (array_values($statuses) as $index => $status) {
            $placeholder = ":status_" . $index;
            $statusPlaceholders[] = $placeholder;
            $params[$placeholder] = $status;
        }

        $stmt = $pdo->prepare("
            SELECT
                rr.request_id,
                rr.borrower_id,
                rr.resource_id,
                rr.quantity,
                rr.date_needed,
                rr.start_time,
                rr.end_time,
                rr.request_date,
                rr.status,
                rr.due_date,
                borrower.full_name AS borrower_name,
                borrower.username AS borrower_username
            FROM resource_requests rr
            LEFT JOIN users borrower ON rr.borrower_id = borrower.user_id
            WHERE rr.resource_id = :resource_id
              AND rr.request_id <> :request_id
              AND rr.status IN (" . implode(", ", $statusPlaceholders) . ")
              AND (
                    rr.request_date < :request_date_before
                    OR (rr.request_date = :request_date_same AND rr.request_id < :request_id_before)
              )
            ORDER BY rr.request_date ASC, rr.request_id ASC
        ");
        $stmt->execute($params);

        $earlierRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $competingRows = [];

        foreach ($earlierRows as $row) {
            $rowRange = buildRequestDateRange(
                $resourceType,
                $row["date_needed"] ?? null,
                $row["start_time"] ?? null,
                $row["end_time"] ?? null,
                $row["due_date"] ?? null,
                $row["status"] ?? null
            );

            if ($rowRange === null) {
                continue;
            }

            if (intervalsOverlap($targetRange["start"], $targetRange["end"], $rowRange["start"], $rowRange["end"])) {
                $competingRows[] = $row;
            }
        }

        return $competingRows;
    }
}

if (!function_exists("findEarlierFirstComeFirstServedRequest")) {
    function findEarlierFirstComeFirstServedRequest(
        PDO $pdo,
        array $requestRow,
        array $statuses
    ): ?array {
        $earlierRows = getEarlierFirstComeFirstServedRequests($pdo, $requestRow, $statuses);

        return $earlierRows[0] ?? null;
    }
}

if (!function_exists("getFirstComeFirstServedQueueRank")) {
    function getFirstComeFirstServedQueueRank(PDO $pdo, array $requestRow): ?int
    {
        $status = (string) ($requestRow["status"] ?? "");

        if (!in_array($status, getFirstComeFirstServedStatuses(), true)) {
            return null;
        }

        $earlierRows = getEarlierFirstComeFirstServedRequests(
            $pdo,
            $requestRow,
            getFirstComeFirstServedStatuses()
        );

        return count($earlierRows) + 1;
    }
}

if (!function_exists("buildFirstComeFirstServedMessage")) {
    function buildFirstComeFirstServedMessage(array $blocker): string
    {
        $requestCode = formatRequestCode((int) ($blocker["request_id"] ?? 0));
        $borrowerName = trim((string) ($blocker["borrower_name"] ?? ""));
        $submittedAt = formatDateTimeDisplay($blocker["request_date"] ?? "");

        if ($borrowerName === "") {
            $borrowerName = "the earlier borrower";
        }

        return "First-come-first-served queue rule: process {$requestCode} from {$borrowerName} first. "
            . "It was submitted on {$submittedAt}.";
    }
}

if (!function_exists("getRequestLifecycleStatus")) {
    function getRequestLifecycleStatus(array $row): string
    {
        $status = (string) ($row["status"] ?? "");
        $dueDate = (string) ($row["due_date"] ?? "");
        $inspectionCondition = (string) ($row["inspection_condition"] ?? "");

        if ($status === "Released" && $dueDate !== "" && strtotime($dueDate) < time()) {
            return "Overdue";
        }

        if ($status === "Returned" && in_array($inspectionCondition, ["Damaged", "Missing Parts", "Needs Repair", "Lost"], true)) {
            return "Damaged";
        }

        return $status;
    }
}

if (!function_exists("getStatusCssClass")) {
    function getStatusCssClass(string $label): string
    {
        return strtolower(str_replace([" ", "/"], "", $label));
    }
}
