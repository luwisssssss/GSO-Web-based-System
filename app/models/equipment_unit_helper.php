<?php

/**
 * Phase 3.5B — individually tracked equipment units.
 *
 * A Serialized resource's aggregate counters are DERIVED from its units, never
 * incremented or decremented. Every state change recomputes them from the unit
 * rows inside the same transaction, which makes a double deduction structurally
 * impossible rather than merely unlikely.
 *
 * Units are only ever created by authorised GSO staff registering a real asset
 * tag. Nothing in this file fabricates a unit from an aggregate quantity.
 */

require_once __DIR__ . "/request_helper.php";
require_once __DIR__ . "/request_line_helper.php";

if (!function_exists("gsoEquipmentUnitStatuses")) {
    function gsoEquipmentUnitStatuses(): array
    {
        return ["Available", "Released", "Under Repair", "Lost", "Retired"];
    }
}

if (!function_exists("gsoUnitConditionOptions")) {
    function gsoUnitConditionOptions(): array
    {
        return ["Good", "Damaged", "Missing Parts", "Needs Repair", "Lost"];
    }
}

if (!function_exists("gsoNormalizeAssetTag")) {
    /** Asset tags are compared case-insensitively and stored trimmed. */
    function gsoNormalizeAssetTag(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/u', " ", $value)));
    }
}

if (!function_exists("gsoRecalculateSerializedStock")) {
    /**
     * Rebuild a Serialized resource's counters from its physical units.
     *
     * total_stock counts every unit still owned and serviceable, so a Lost or
     * Retired unit leaves the borrowable pool exactly as a lost aggregate item
     * reduces total_stock. available_stock and under_repair_stock keep their
     * existing meaning. Aggregate and Consumable resources are left untouched.
     */
    function gsoRecalculateSerializedStock(PDO $pdo, int $resourceId): bool
    {
        $stmt = $pdo->prepare("SELECT tracking_mode FROM resources WHERE resource_id = :id LIMIT 1");
        $stmt->execute([":id" => $resourceId]);

        if ((string) $stmt->fetchColumn() !== "Serialized") {
            return false;
        }

        $update = $pdo->prepare("
            UPDATE resources r
            SET
                r.total_stock = (
                    SELECT COUNT(*) FROM equipment_units u
                    WHERE u.resource_id = r.resource_id AND u.status NOT IN ('Lost','Retired')
                ),
                r.available_stock = (
                    SELECT COUNT(*) FROM equipment_units u
                    WHERE u.resource_id = r.resource_id AND u.status = 'Available'
                ),
                r.under_repair_stock = (
                    SELECT COUNT(*) FROM equipment_units u
                    WHERE u.resource_id = r.resource_id AND u.status = 'Under Repair'
                )
            WHERE r.resource_id = :id
        ");

        return $update->execute([":id" => $resourceId]);
    }
}

if (!function_exists("gsoRegisterEquipmentUnit")) {
    /**
     * Register one real physical unit.
     *
     * @return array{ok: bool, message: string, unit_id?: int}
     */
    function gsoRegisterEquipmentUnit(
        PDO $pdo,
        int $resourceId,
        string $assetTag,
        ?string $serialNumber,
        int $registrarId,
        ?string $acquiredAt = null,
        string $notes = ""
    ): array {
        $assetTag = gsoNormalizeAssetTag($assetTag);
        $serialNumber = $serialNumber !== null ? trim($serialNumber) : null;
        $serialNumber = ($serialNumber === null || $serialNumber === "") ? null : $serialNumber;

        if ($assetTag === "" || mb_strlen($assetTag) > 60) {
            return ["ok" => false, "message" => "Enter an institutional asset tag of 1 to 60 characters."];
        }

        if ($serialNumber !== null && mb_strlen($serialNumber) > 120) {
            return ["ok" => false, "message" => "The serial number must be 120 characters or fewer."];
        }

        $resource = gsoFetchRequestableResource($pdo, $resourceId);

        if ($resource === null || (string) $resource["resource_type"] !== "Item") {
            return ["ok" => false, "message" => "Physical units can only be registered for equipment items."];
        }

        if ((string) $resource["tracking_mode"] === "Consumable") {
            return ["ok" => false, "message" => "Consumable materials are not individually tracked."];
        }

        try {
            $pdo->beginTransaction();

            $insert = $pdo->prepare("
                INSERT INTO equipment_units
                    (resource_id, asset_tag, serial_number, status, condition_status,
                     unit_notes, acquired_at, registered_by)
                VALUES
                    (:resource_id, :asset_tag, :serial_number, 'Available', 'Good',
                     :notes, :acquired_at, :registrar)
            ");
            $insert->execute([
                ":resource_id" => $resourceId,
                ":asset_tag" => $assetTag,
                ":serial_number" => $serialNumber,
                ":notes" => $notes !== "" ? mb_substr($notes, 0, 500) : null,
                ":acquired_at" => $acquiredAt !== null && $acquiredAt !== "" ? $acquiredAt : null,
                ":registrar" => $registrarId,
            ]);
            $unitId = (int) $pdo->lastInsertId();

            gsoRecalculateSerializedStock($pdo, $resourceId);
            $pdo->commit();

            return ["ok" => true, "message" => "Unit {$assetTag} registered.", "unit_id" => $unitId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // The unique indexes are the authority; the message stays generic
            // enough not to leak which other resource already holds the value.
            gsoLogApplicationException($e, "equipment unit registration");
            if (str_contains($e->getMessage(), "uq_equipment_units_asset_tag")) {
                return ["ok" => false, "message" => "That asset tag is already registered."];
            }
            if (str_contains($e->getMessage(), "uq_equipment_units_serial")) {
                return ["ok" => false, "message" => "That serial number is already registered."];
            }

            return ["ok" => false, "message" => "The unit could not be registered."];
        }
    }
}

if (!function_exists("gsoUpdateEquipmentUnitStatus")) {
    /**
     * Change a unit's operational state.
     *
     * A unit that is currently released cannot be edited into another state
     * from the inventory screen: it must come back through the return
     * inspection, which is the only path that closes its allocation.
     */
    function gsoUpdateEquipmentUnitStatus(
        PDO $pdo,
        int $unitId,
        string $status,
        string $condition,
        string $notes,
        int $actorId
    ): array {
        if (!in_array($status, gsoEquipmentUnitStatuses(), true)
            || !in_array($condition, gsoUnitConditionOptions(), true)
        ) {
            return ["ok" => false, "message" => "Select a valid unit status and condition."];
        }

        if ($status === "Released") {
            return ["ok" => false, "message" => "A unit becomes Released only through an authorised request release."];
        }

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT unit_id, resource_id, status, condition_status FROM equipment_units WHERE unit_id = :id FOR UPDATE");
            $lock->execute([":id" => $unitId]);
            $unit = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$unit) {
                throw new DomainException("That unit no longer exists.");
            }

            if ((string) $unit["status"] === "Released") {
                throw new DomainException("This unit is issued to a borrower. Record its return first.");
            }

            if($unit['status']==='Under Repair'&&$status==='Available')throw new DomainException('Complete the authorized repair work order to restore this unit.');
            if(($unit['status']==='Lost'||$unit['condition_status']==='Lost')&&$status!=='Lost'&&$status!=='Retired')throw new DomainException('A Lost unit cannot be restored or moved into repair through a status edit.');
            if(($status==='Lost')!==($condition==='Lost')||($status==='Available'&&$condition!=='Good')||($status==='Under Repair'&&!in_array($condition,['Damaged','Missing Parts','Needs Repair'],true)))throw new DomainException('Unit status must match its Good, repairable, or Lost condition.');
            $update = $pdo->prepare("
                UPDATE equipment_units
                SET status = :status, condition_status = :condition,
                    unit_notes = :notes, updated_at = NOW()
                WHERE unit_id = :id AND status <> 'Released'
            ");
            $update->execute([
                ":status" => $status,
                ":condition" => $condition,
                ":notes" => $notes !== "" ? mb_substr($notes, 0, 500) : null,
                ":id" => $unitId,
            ]);

            if ($update->rowCount() !== 1) {
                throw new DomainException("The unit changed before it could be updated.");
            }

            gsoRecalculateSerializedStock($pdo, (int) $unit["resource_id"]);
            $pdo->commit();

            return ["ok" => true, "message" => "Unit updated."];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                "ok" => false,
                "message" => gsoSafeApplicationError($e, "app/models/equipment_unit_helper.php", "The unit could not be updated."),
            ];
        }
    }
}

if (!function_exists("gsoResourceUnits")) {
    /** Every registered unit of a resource, with its open allocation if any. */
    function gsoResourceUnits(PDO $pdo, int $resourceId): array
    {
        $stmt = $pdo->prepare("
            SELECT u.*,
                   ru.request_item_id AS open_request_item_id,
                   ri.request_id AS open_request_id
            FROM equipment_units u
            LEFT JOIN request_item_units ru
                ON ru.unit_id = u.unit_id AND ru.returned_at IS NULL
            LEFT JOIN request_items ri ON ri.request_item_id = ru.request_item_id
            WHERE u.resource_id = :resource_id
            ORDER BY u.asset_tag ASC
        ");
        $stmt->execute([":resource_id" => $resourceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoAvailableUnitsForResource")) {
    /** Units that may legitimately be issued right now. */
    function gsoAvailableUnitsForResource(PDO $pdo, int $resourceId): array
    {
        $stmt = $pdo->prepare("
            SELECT u.unit_id, u.asset_tag, u.serial_number, u.condition_status
            FROM equipment_units u
            WHERE u.resource_id = :resource_id
              AND u.status = 'Available'
              AND u.condition_status = 'Good'
              AND NOT EXISTS (
                  SELECT 1 FROM request_item_units ru
                  WHERE ru.unit_id = u.unit_id AND ru.returned_at IS NULL
              )
            ORDER BY u.asset_tag ASC
        ");
        $stmt->execute([":resource_id" => $resourceId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoUnitBorrowingHistory")) {
    /** Allocation history of one unit, newest first. Never deleted. */
    function gsoUnitBorrowingHistory(PDO $pdo, int $unitId): array
    {
        $stmt = $pdo->prepare("
            SELECT ru.*, ri.request_id, rr.borrower_id, rr.requester_type, rr.walkin_organization, rr.encoded_by, " . gsoRequesterSql("full_name","rr","b") . " AS borrower_name,
                   a.full_name AS allocated_by_name
            FROM request_item_units ru
            INNER JOIN request_items ri ON ri.request_item_id = ru.request_item_id
            INNER JOIN resource_requests rr ON rr.request_id = ri.request_id
            LEFT JOIN users b ON b.user_id = rr.borrower_id
            LEFT JOIN users a ON a.user_id = ru.allocated_by
            WHERE ru.unit_id = :unit_id
            ORDER BY ru.allocated_at DESC, ru.allocation_id DESC
        ");
        $stmt->execute([":unit_id" => $unitId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoCurrentReleasedQuantity")) {
    /** How much of a resource is physically out on loan right now. */
    function gsoCurrentReleasedQuantity(PDO $pdo, int $resourceId): int
    {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(ri.released_quantity - ri.returned_quantity), 0)
            FROM request_items ri
            INNER JOIN resource_requests rr ON rr.request_id = ri.request_id
            WHERE ri.resource_id = :resource_id
              AND rr.status IN ('Released', 'Return')
        ");
        $stmt->execute([":resource_id" => $resourceId]);

        return max(0, (int) $stmt->fetchColumn());
    }
}

if (!function_exists("gsoSerializedActivationReadiness")) {
    /**
     * Can this aggregate resource safely become Serialized?
     *
     * Activation is refused unless the registered physical units account for
     * every unit the catalogue claims to own, including whatever is currently
     * on loan or held for repair. That prevents borrowed stock from silently
     * becoming "Available" the moment the classification flips.
     *
     * @return array{ready: bool, blockers: array<int,string>, ...}
     */
    function gsoSerializedActivationReadiness(PDO $pdo, int $resourceId): array
    {
        $stmt = $pdo->prepare("
            SELECT resource_id, resource_name, resource_type, tracking_mode,
                   total_stock, available_stock, under_repair_stock
            FROM resources WHERE resource_id = :id LIMIT 1
        ");
        $stmt->execute([":id" => $resourceId]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$resource) {
            return ["ready" => false, "blockers" => ["Resource not found."], "resource" => null];
        }

        $counts = $pdo->prepare("
            SELECT
                COUNT(*) AS registered,
                SUM(status = 'Available') AS available,
                SUM(status = 'Released') AS released,
                SUM(status = 'Under Repair') AS under_repair,
                SUM(status IN ('Lost','Retired')) AS gone
            FROM equipment_units WHERE resource_id = :id
        ");
        $counts->execute([":id" => $resourceId]);
        $unitCounts = $counts->fetch(PDO::FETCH_ASSOC) ?: [];

        $registered = (int) ($unitCounts["registered"] ?? 0);
        $serviceable = $registered - (int) ($unitCounts["gone"] ?? 0);
        $totalStock = (int) ($resource["total_stock"] ?? 0);
        $underRepair = (int) ($resource["under_repair_stock"] ?? 0);
        $onLoan = gsoCurrentReleasedQuantity($pdo, $resourceId);

        $blockers = [];

        if ((string) $resource["resource_type"] !== "Item") {
            $blockers[] = "Only equipment items can be individually tracked.";
        }

        if ((string) $resource["tracking_mode"] === "Consumable") {
            $blockers[] = "Consumable materials are not individually tracked.";
        }

        if ($registered === 0) {
            $blockers[] = "No physical units are registered yet. Register each unit's real asset tag first.";
        }

        if ($registered > 0 && $serviceable !== $totalStock) {
            $blockers[] = "Registered serviceable units ({$serviceable}) do not match the catalogue total stock ({$totalStock}).";
        }

        if ($registered > 0 && (int) ($unitCounts["released"] ?? 0) !== $onLoan) {
            $blockers[] = "Units marked Released ({$unitCounts['released']}) do not match the quantity currently on loan ({$onLoan}).";
        }

        if ($registered > 0 && (int) ($unitCounts["under_repair"] ?? 0) !== $underRepair) {
            $blockers[] = "Units Under Repair ({$unitCounts['under_repair']}) do not match the catalogue under-repair stock ({$underRepair}).";
        }

        return [
            "ready" => $blockers === [] && (string) $resource["tracking_mode"] !== "Serialized",
            "already_serialized" => (string) $resource["tracking_mode"] === "Serialized",
            "blockers" => $blockers,
            "resource" => $resource,
            "registered_units" => $registered,
            "serviceable_units" => $serviceable,
            "on_loan_quantity" => $onLoan,
        ];
    }
}

if (!function_exists("gsoActivateSerializedTracking")) {
    /** Flip an aggregate resource to Serialized, transactionally and only when reconciled. */
    function gsoActivateSerializedTracking(PDO $pdo, int $resourceId, int $actorId): array
    {
        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare("SELECT resource_id FROM resources WHERE resource_id = :id FOR UPDATE");
            $lock->execute([":id" => $resourceId]);

            if (!$lock->fetchColumn()) {
                throw new DomainException("Resource not found.");
            }

            $readiness = gsoSerializedActivationReadiness($pdo, $resourceId);

            if (!empty($readiness["already_serialized"])) {
                throw new DomainException("This resource is already individually tracked.");
            }

            if (!$readiness["ready"]) {
                throw new DomainException(implode(" ", $readiness["blockers"]));
            }

            $update = $pdo->prepare("
                UPDATE resources SET tracking_mode = 'Serialized'
                WHERE resource_id = :id AND tracking_mode = 'Aggregate' AND resource_type = 'Item'
            ");
            $update->execute([":id" => $resourceId]);

            if ($update->rowCount() !== 1) {
                throw new DomainException("The resource changed before it could be reclassified.");
            }

            gsoRecalculateSerializedStock($pdo, $resourceId);
            $pdo->commit();

            return ["ok" => true, "message" => "Individual unit tracking activated."];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return [
                "ok" => false,
                "message" => gsoSafeApplicationError($e, "app/models/equipment_unit_helper.php", "Tracking could not be activated."),
            ];
        }
    }
}

if (!function_exists("gsoSerializedStockMismatches")) {
    /**
     * Reconciliation report: Serialized resources whose counters disagree with
     * their physical units. Used by the inventory screen and by the test suite.
     */
    function gsoSerializedStockMismatches(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT r.resource_id, r.resource_name,
                   r.total_stock, r.available_stock, r.under_repair_stock,
                   (SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id = r.resource_id AND u.status NOT IN ('Lost','Retired')) AS unit_total,
                   (SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id = r.resource_id AND u.status = 'Available') AS unit_available,
                   (SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id = r.resource_id AND u.status = 'Under Repair') AS unit_repair
            FROM resources r
            WHERE r.tracking_mode = 'Serialized'
            HAVING total_stock <> unit_total
                OR available_stock <> unit_available
                OR under_repair_stock <> unit_repair
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}
