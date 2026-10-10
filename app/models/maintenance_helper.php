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
                under_repair_stock,
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
              AND ((SELECT resource_type FROM resources WHERE resource_id=maintenance_schedules.resource_id)='Facility' OR(quantity=0 AND equipment_unit_id IS NULL AND source_return_item_id IS NULL))
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
        } elseif (in_array($conditionStatus, ["Damaged", "Missing Parts", "Needs Repair", "Lost"], true) && !($resourceType==='Item' && $conditionStatus!=='Lost' && (int)$resource['under_repair_stock']>0 && $availableStock>0)) {
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

if (!function_exists("getUnderRepairQuantity")) {
    /**
     * Units currently held out of service for repair.
     *
     * Not every caller selects the column, so this reads the authoritative
     * value when it is absent from an already-loaded resource row.
     */
    function getUnderRepairQuantity(PDO $pdo, int $resourceId, array $resource = []): int
    {
        if (array_key_exists("under_repair_stock", $resource)) {
            return max(0, (int) $resource["under_repair_stock"]);
        }

        if ($resourceId <= 0) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT under_repair_stock FROM resources WHERE resource_id = :id LIMIT 1");
            $stmt->execute([":id" => $resourceId]);

            return max(0, (int) $stmt->fetchColumn());
        } catch (Throwable $e) {
            error_log("Under-repair lookup failed: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists("restoreRepairedStock")) {
    /**
     * Put units that were held out of service for repair back into circulation.
     *
     * A return inspected as Damaged, Missing Parts or Needs Repair parks only
     * the affected units in `under_repair_stock`, so the remaining good units
     * stay borrowable. Completing the repair releases the parked units for the
     * resource and clears the damaged condition flag.
     *
     * $requestedQuantity restores only that many units, which is how a work
     * order covering 2 of 10 damaged chairs leaves the other 8 held. Omitting
     * it falls back to draining the whole repair pool; that legacy default is
     * kept for backward compatibility but no caller uses it - every completion
     * path passes the work order's own recorded quantity. New callers should
     * always be explicit.
     *
     * Returns the number of units restored.
     */
    function restoreRepairedStock(PDO $pdo, int $resourceId, ?int $requestedQuantity = null): int
    {
        $stmt = $pdo->prepare("
            SELECT resource_type, total_stock, available_stock, under_repair_stock
            FROM resources
            WHERE resource_id = :resource_id
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([
            ":resource_id" => $resourceId
        ]);

        $resource = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resource || (string) ($resource["resource_type"] ?? "") !== "Item") {
            return 0;
        }

        $underRepair = max(0, (int) ($resource["under_repair_stock"] ?? 0));

        if ($underRepair === 0) {
            return 0;
        }

        $totalStock = max(0, (int) ($resource["total_stock"] ?? 0));
        $availableStock = max(0, (int) ($resource["available_stock"] ?? 0));

        // Units still out on loan keep their slot, so never push availability
        // past what the total stock can back.
        $requestedQuantity = $requestedQuantity === null ? $underRepair : max(0,$requestedQuantity);
        $restored = min($requestedQuantity,$underRepair,max(0,$totalStock-$availableStock));

        $pdo->prepare("
            UPDATE resources
            SET
                available_stock = :available_stock,
                under_repair_stock = GREATEST(0,under_repair_stock-:restored),
                condition_status = CASE WHEN under_repair_stock<=0 THEN 'Good' ELSE condition_status END
            WHERE resource_id = :resource_id
            LIMIT 1
        ")->execute([
            ":available_stock" => $availableStock + $restored,
            ":restored" => $restored,
            ":resource_id" => $resourceId
        ]);

        return $restored;
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
              AND (maintenance_type IS NULL OR maintenance_type='Corrective')
              AND CURDATE() BETWEEN start_date AND end_date
        ");

        $pdo->exec("
            UPDATE maintenance_schedules
            SET
                status = 'Scheduled',
                updated_at = NOW()
            WHERE status = 'In Progress'
              AND (maintenance_type IS NULL OR maintenance_type='Corrective')
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
        ?string $rangeEnd,
        bool $wholeResourceOnly=false,
        ?int $excludeMaintenanceId=null
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
              " . ($wholeResourceOnly ? "AND quantity=0 AND equipment_unit_id IS NULL AND source_return_item_id IS NULL" : "") . "
              AND (:excluded_maintenance IS NULL OR maintenance_id<>:excluded_maintenance_id)
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
            ":range_end" => $rangeEnd,
            ":excluded_maintenance" => $excludeMaintenanceId,
            ":excluded_maintenance_id" => $excludeMaintenanceId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

/** Scheduled preventive holds affect future capacity, not current inventory counters. */
function gsoPreventiveUnavailableQuantity(PDO $pdo,int $resourceId,?string $start,?string $end,?int $exclude=null): int
{
    if(!$start||!$end)return 0;
    $s=$pdo->prepare("SELECT m.quantity,m.equipment_unit_id,m.start_date,m.end_date FROM maintenance_schedules m LEFT JOIN equipment_units u ON u.unit_id=m.equipment_unit_id WHERE m.resource_id=? AND m.maintenance_type='Preventive' AND m.status='Scheduled' AND(m.maintenance_id<>? OR ? IS NULL) AND NOT(?<CONCAT(m.start_date,' 00:00:00') OR ?>CONCAT(m.end_date,' 23:59:59')) AND(m.equipment_unit_id IS NULL OR(u.status IN('Available','Released') AND u.condition_status='Good'))");$s->execute([$resourceId,$exclude,$exclude,$end,$start]);$units=[];$events=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC) as $job){if($job['equipment_unit_id']!==null){$units[(int)$job['equipment_unit_id']]=true;continue;}$a=max(strtotime($start),strtotime($job['start_date'].' 00:00:00'));$b=min(strtotime($end),strtotime($job['end_date'].' 23:59:59'))+1;$events[$a]=($events[$a]??0)+(int)$job['quantity'];$events[$b]=($events[$b]??0)-(int)$job['quantity'];}
    ksort($events);$current=0;$peak=0;foreach($events as $change){$current+=$change;$peak=max($peak,$current);}return count($units)+$peak;
}
function gsoPreventiveUnitConflict(PDO $pdo,int $unitId,string $start,string $end,?int $exclude=null): bool
{
    $s=$pdo->prepare("SELECT 1 FROM maintenance_schedules WHERE equipment_unit_id=? AND maintenance_type='Preventive' AND status IN('Scheduled','In Progress') AND(maintenance_id<>? OR ? IS NULL) AND NOT(?<CONCAT(start_date,' 00:00:00') OR ?>CONCAT(end_date,' 23:59:59')) LIMIT 1");$s->execute([$unitId,$exclude,$exclude,$end,$start]);return (bool)$s->fetchColumn();
}
