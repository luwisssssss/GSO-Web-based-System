<?php

if (!function_exists("getConditionStatusOptions")) {
    function getConditionStatusOptions(): array
    {
        return [
            "Good",
            "Damaged",
            "Missing Parts",
            "Needs Repair",
            "Lost"
        ];
    }
}

if (!function_exists("calculateMaintenanceEndDate")) {
    function calculateMaintenanceEndDate(string $startDate, int $durationDays): ?string
    {
        $durationDays = max(1, $durationDays);
        $timestamp = strtotime($startDate);

        if ($timestamp === false) {
            return null;
        }

        return date("Y-m-d", strtotime("+". ($durationDays - 1) . " days", $timestamp));
    }
}

if (!function_exists("refreshResourceOperationalStatus")) {
    function refreshResourceOperationalStatus(PDO $pdo, int $resourceId): void
    {
        $stmt = $pdo->prepare("
            SELECT
                resource_id,
                resource_type,
                total_stock,
                available_stock,
                status,
                condition_status,
                is_archived
            FROM resources
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $stmt->execute([
            ":resource_id" => $resourceId
        ]);

        $resource = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resource) {
            return;
        }

        if ((int) ($resource["is_archived"] ?? 0) === 1) {
            return;
        }

        $activeMaintenanceStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM maintenance_schedules
            WHERE resource_id = :resource_id
              AND status = 'In Progress'
        ");
        $activeMaintenanceStmt->execute([
            ":resource_id" => $resourceId
        ]);
        $hasActiveMaintenance = (int) $activeMaintenanceStmt->fetchColumn() > 0;

        $conditionStatus = (string) ($resource["condition_status"] ?? "Good");
        $resourceType = (string) ($resource["resource_type"] ?? "");
        $availableStock = isset($resource["available_stock"]) ? (int) $resource["available_stock"] : 0;

        if ($hasActiveMaintenance) {
            $nextStatus = "Maintenance";
        } elseif (in_array($conditionStatus, ["Damaged", "Missing Parts", "Needs Repair", "Lost"], true)) {
            $nextStatus = "Unavailable";
        } elseif ($resourceType === "Item") {
            $nextStatus = $availableStock > 0 ? "Available" : "Unavailable";
        } else {
            $nextStatus = "Available";
        }

        $updateStmt = $pdo->prepare("
            UPDATE resources
            SET status = :status
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $updateStmt->execute([
            ":status" => $nextStatus,
            ":resource_id" => $resourceId
        ]);
    }
}

if (!function_exists("syncMaintenanceSchedules")) {
    function syncMaintenanceSchedules(PDO $pdo): void
    {
        static $hasRun = false;

        if ($hasRun) {
            return;
        }

        $hasRun = true;

        if (!tableExists($pdo, "maintenance_schedules")) {
            return;
        }

        $pdo->exec("
            UPDATE maintenance_schedules
            SET
                status = 'In Progress',
                updated_at = NOW()
            WHERE status = 'Scheduled'
              AND CURDATE() BETWEEN start_date AND end_date
        ");

        $pdo->exec("
            UPDATE maintenance_schedules
            SET
                status = 'Completed',
                updated_at = NOW()
            WHERE status IN ('Scheduled', 'In Progress')
              AND end_date < CURDATE()
        ");

        $pdo->exec("
            UPDATE maintenance_schedules
            SET
                status = 'Scheduled',
                updated_at = NOW()
            WHERE status = 'In Progress'
              AND start_date > CURDATE()
        ");

        $resourceIds = $pdo->query("
            SELECT DISTINCT resource_id
            FROM maintenance_schedules
        ")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($resourceIds as $resourceId) {
            refreshResourceOperationalStatus($pdo, (int) $resourceId);
        }
    }
}

if (!function_exists("getMaintenanceConflict")) {
    function getMaintenanceConflict(
        PDO $pdo,
        int $resourceId,
        ?string $rangeStart,
        ?string $rangeEnd
    ): ?array {
        if ($resourceId <= 0) {
            return null;
        }

        if (empty($rangeStart) || empty($rangeEnd) || !tableExists($pdo, "maintenance_schedules")) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                maintenance_id,
                resource_id,
                start_date,
                end_date,
                duration_days,
                reason,
                remarks,
                status
            FROM maintenance_schedules
            WHERE resource_id = :resource_id
              AND status IN ('Scheduled', 'In Progress')
              AND NOT (
                    :range_end < CONCAT(start_date, ' 00:00:00')
                    OR :range_start > CONCAT(end_date, ' 23:59:59')
              )
            ORDER BY start_date ASC, maintenance_id ASC
            LIMIT 1
        ");
        $stmt->execute([
            ":resource_id" => $resourceId,
            ":range_start" => $rangeStart,
            ":range_end" => $rangeEnd
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
