<?php

/**
 * Phase 3.5B — per-resource and per-unit return inspection.
 *
 * return_submissions stays the request-level header (one per request, keeping
 * its unique key, photos, ownership and history). return_submission_items
 * carries the outcome for each returnable resource line: one row per condition
 * group for aggregate equipment, one row per physical unit for serialized
 * equipment. A damaged line therefore never drags unrelated resources of the
 * same request down with it.
 *
 * Issued consumables are excluded everywhere: they are gone, not returnable.
 */

require_once __DIR__ . "/request_helper.php";
require_once __DIR__ . "/request_line_helper.php";
require_once __DIR__ . "/equipment_unit_helper.php";
require_once __DIR__ . "/maintenance_helper.php";

if (!function_exists("gsoReturnableLines")) {
    /**
     * The lines of a request that must come back, with their allocated units.
     *
     * Serialized lines expose exactly the units issued to THIS request, so the
     * borrower can never be shown, or claim, a unit from general inventory.
     */
    function gsoReturnableLines(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare("
            SELECT ri.request_item_id, ri.resource_id, ri.line_kind,
                   ri.requested_quantity, ri.released_quantity, ri.returned_quantity,
                   r.resource_name, r.resource_type, r.tracking_mode
            FROM request_items ri
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE ri.request_id = :request_id
              AND ri.line_kind <> 'Consumable'
            ORDER BY FIELD(ri.line_kind,'Facility','Equipment'), r.resource_name ASC
        ");
        $stmt->execute([":request_id" => $requestId]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $unitStmt = $pdo->prepare("
            SELECT ru.allocation_id, ru.unit_id, ru.returned_at,
                   u.asset_tag, u.serial_number, u.status, u.condition_status
            FROM request_item_units ru
            INNER JOIN equipment_units u ON u.unit_id = ru.unit_id
            WHERE ru.request_item_id = :line_id
            ORDER BY u.asset_tag ASC
        ");

        foreach ($lines as $index => $line) {
            $lines[$index]["units"] = [];

            if ((string) $line["tracking_mode"] === "Serialized") {
                $unitStmt->execute([":line_id" => (int) $line["request_item_id"]]);
                $lines[$index]["units"] = $unitStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }
        }

        return $lines;
    }
}

if (!function_exists("gsoRequestHasReturnableLines")) {
    function gsoRequestHasReturnableLines(PDO $pdo, int $requestId): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM request_items
            WHERE request_id = :request_id AND line_kind <> 'Consumable'
        ");
        $stmt->execute([":request_id" => $requestId]);

        return (int) $stmt->fetchColumn() > 0;
    }
}

if (!function_exists("gsoValidateBorrowerReturnRows")) {
    /**
     * Turn a borrower's submission into detail rows, or explain what is wrong.
     *
     * Nothing is defaulted to Good: every returnable line, and every allocated
     * unit, must be explicitly accounted for. Quantities can never exceed what
     * was actually released, and a lost item is recorded as lost rather than
     * counted as physically received.
     *
     * @return array{rows: array<int,array>, errors: array<int,string>}
     */
    function gsoValidateBorrowerReturnRows(PDO $pdo, int $requestId, array $submitted): array
    {
        $conditions = getConditionStatusOptions();
        $lines = gsoReturnableLines($pdo, $requestId);
        $rows = [];
        $errors = [];

        foreach ($lines as $line) {
            $lineId = (int) $line["request_item_id"];
            $name = (string) $line["resource_name"];
            $released = (int) $line["released_quantity"];
            if ($released < 1 || (int) $line["returned_quantity"] !== 0) {
                $errors[] = "The released quantities for {$name} are not eligible for submission.";
                continue;
            }
            $payload = (array) ($submitted[$lineId] ?? []);

            if ((string) $line["tracking_mode"] === "Serialized") {
                $openUnits = array_values(array_filter(
                    $line["units"],
                    static fn(array $u): bool => $u["returned_at"] === null
                ));

                if (count($openUnits) !== $released) {
                    $errors[] = "The allocated units for {$name} do not match the released quantity.";
                    continue;
                }

                $unitPayload = (array) ($payload["units"] ?? []);
                if (array_diff(array_map("intval", array_keys($unitPayload)), array_column($openUnits, "unit_id")) !== []) {
                    $errors[] = "Only the exact allocated units of {$name} may be returned.";
                }

                foreach ($openUnits as $unit) {
                    $unitId = (int) $unit["unit_id"];
                    $condition = trim((string) ($unitPayload[$unitId]["condition"] ?? ""));

                    if (!in_array($condition, $conditions, true)) {
                        $errors[] = "Select a condition for unit " . $unit["asset_tag"] . ".";
                        continue;
                    }

                    $rows[] = [
                        "request_item_id" => $lineId,
                        "unit_id" => $unitId,
                        "reported_condition" => $condition,
                        "returned_quantity" => $condition === "Lost" ? 0 : 1,
                        "lost_quantity" => $condition === "Lost" ? 1 : 0,
                    ];
                }

                continue;
            }

            // Facility and aggregate equipment are reported by quantity.
            $condition = trim((string) ($payload["condition"] ?? ""));
            $isFacility = (string) $line["line_kind"] === "Facility";
            $returnedQty = $isFacility ? 1 : (int) ($payload["returned_quantity"] ?? -1);
            $lostQty = $isFacility ? 0 : (int) ($payload["lost_quantity"] ?? 0);

            if (!in_array($condition, $conditions, true)) {
                $errors[] = "Select a condition for {$name}.";
                continue;
            }

            if ($returnedQty < 0 || $lostQty < 0) {
                $errors[] = "Quantities for {$name} cannot be negative.";
                continue;
            }

            if (($returnedQty + $lostQty) !== $released) {
                $errors[] = "Account for all {$released} of {$name}: "
                    . "returned plus lost must equal the quantity released.";
                continue;
            }

            if ($returnedQty > 0) {
                $rows[] = [
                    "request_item_id" => $lineId,
                    "unit_id" => null,
                    "reported_condition" => $condition === "Lost" ? "Good" : $condition,
                    "returned_quantity" => $returnedQty,
                    "lost_quantity" => 0,
                ];
            }

            if ($lostQty > 0) {
                $rows[] = [
                    "request_item_id" => $lineId,
                    "unit_id" => null,
                    "reported_condition" => "Lost",
                    "returned_quantity" => 0,
                    "lost_quantity" => $lostQty,
                ];
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = "This request has nothing that requires a return.";
        }

        return ["rows" => $rows, "errors" => $errors];
    }
}

if (!function_exists("gsoReturnDetailRows")) {
    /** Detail rows of one return, with resource and unit labels for display. */
    function gsoReturnDetailRows(PDO $pdo, int $returnId): array
    {
        $stmt = $pdo->prepare("
            SELECT rsi.*, r.resource_name, r.tracking_mode, ri.line_kind,
                   ri.resource_id, u.asset_tag
            FROM return_submission_items rsi
            INNER JOIN request_items ri ON ri.request_item_id = rsi.request_item_id
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            LEFT JOIN equipment_units u ON u.unit_id = rsi.unit_id
            WHERE rsi.return_id = :return_id
            ORDER BY FIELD(ri.line_kind,'Facility','Equipment'), r.resource_name, rsi.return_item_id
        ");
        $stmt->execute([":return_id" => $returnId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoApplyReturnInspection")) {
    /**
     * Record the GSO inspection outcome and move stock accordingly.
     *
     * Each detail row is applied independently, so a damaged laptop parks only
     * that laptop and a lost chair reduces only chair stock. stock_applied is
     * flipped inside the same transaction, which is what stops a second
     * inspection from restoring the same stock twice.
     *
     * @return array{ok: bool, message: string}
     */
    function gsoApplyReturnInspection(
        PDO $pdo,
        int $returnId,
        array $inspection,
        int $inspectorId
    ): array {
        $conditions = getConditionStatusOptions();
        $touchedResources = [];

        $detailStmt = $pdo->prepare("
            SELECT rsi.*, ri.resource_id, ri.line_kind, r.tracking_mode
            FROM return_submission_items rsi
            INNER JOIN request_items ri ON ri.request_item_id = rsi.request_item_id
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE rsi.return_id = :return_id
            FOR UPDATE
        ");
        $detailStmt->execute([":return_id" => $returnId]);
        $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($details === []) {
            return ["ok" => false, "message" => "This return has no resource details to inspect."];
        }

        // Validate the complete submission before touching any stock. The
        // caller holds the request lock and the inspection transaction.
        $requestStmt = $pdo->prepare("SELECT request_id FROM return_submissions WHERE return_id = ?");
        $requestStmt->execute([$returnId]);
        $lines = gsoReturnableLines($pdo, (int) $requestStmt->fetchColumn());
        $byLine = [];
        foreach ($details as $detail) {
            $byLine[(int) $detail["request_item_id"]][] = $detail;
            if ((int) $detail["stock_applied"] !== 0 || !in_array((string) ($inspection[(int) $detail["return_item_id"]]["condition"] ?? ""), $conditions, true)) {
                return ["ok" => false, "message" => "Every return detail must be unprocessed and have an inspection condition."];
            }
        }
        if ($lines === [] || array_diff(array_keys($byLine), array_column($lines, "request_item_id")) !== []) {
            return ["ok" => false, "message" => "The return details do not match this request's returnable lines."];
        }
        foreach ($lines as $line) {
            $rows = $byLine[(int) $line["request_item_id"]] ?? [];
            $accounted = array_sum(array_column($rows, "returned_quantity")) + array_sum(array_column($rows, "lost_quantity"));
            if ($rows === [] || $accounted !== (int) $line["released_quantity"] || (int) $line["returned_quantity"] !== 0) {
                return ["ok" => false, "message" => "Inspection must account for every released quantity exactly once."];
            }
            if ((string) $line["tracking_mode"] === "Serialized") {
                $expected = array_map("intval", array_column(array_filter($line["units"], static fn(array $u): bool => $u["returned_at"] === null), "unit_id"));
                $actual = array_map("intval", array_column($rows, "unit_id"));
                sort($expected); sort($actual);
                if ($expected !== $actual || count($actual) !== (int) $line["released_quantity"]) {
                    return ["ok" => false, "message" => "Inspection must cover the exact allocated units."];
                }
            } elseif (array_filter($rows, static fn(array $r): bool => $r["unit_id"] !== null) !== []) {
                return ["ok" => false, "message" => "Aggregate and facility returns cannot contain serialized units."];
            }
        }

        foreach ($details as $detail) {
            $detailId = (int) $detail["return_item_id"];
            $resourceId = (int) $detail["resource_id"];
            $lineKind = (string) $detail["line_kind"];
            $serialized = (string) $detail["tracking_mode"] === "Serialized";
            $touchedResources[$resourceId] = true;

            if ((int) $detail["stock_applied"] === 1) {
                // Already settled - a second inspection must not move stock again.
                continue;
            }

            $chosen = trim((string) ($inspection[$detailId]["condition"] ?? ""));
            $remarks = trim((string) ($inspection[$detailId]["remarks"] ?? ""));

            if (!in_array($chosen, $conditions, true)) {
                return ["ok" => false, "message" => "Select an inspection condition for every returned resource."];
            }

            $lostQty = (int) $detail["lost_quantity"];
            $returnedQty = (int) $detail["returned_quantity"];

            if ($chosen === "Lost" && $lineKind !== "Facility") {
                $lostQty += $returnedQty;
                $returnedQty = 0;
            } elseif ($lostQty > 0) {
                return ["ok" => false, "message" => "A lost quantity must be inspected as Lost."];
            }

            if ($lineKind === "Facility") {
                // A room has no stock to move; the inspection is the record.
                $chosen = $chosen === "Lost" ? "Good" : $chosen;
            } elseif ($serialized) {
                $unitId = (int) $detail["unit_id"];

                if ($unitId <= 0) {
                    return ["ok" => false, "message" => "A serialized return row is missing its unit."];
                }

                // Good units come back into service only here, after the
                // authorised inspection - never on borrower submission alone.
                $unitStatus = match ($chosen) {
                    "Good" => "Available",
                    "Lost" => "Lost",
                    default => "Under Repair",
                };

                $pdo->prepare("
                    UPDATE equipment_units
                    SET status = :status, condition_status = :condition, updated_at = NOW()
                    WHERE unit_id = :unit_id
                ")->execute([
                    ":status" => $unitStatus,
                    ":condition" => $chosen,
                    ":unit_id" => $unitId,
                ]);

                // Closing the allocation frees the open-unit guard so the unit
                // can be issued again, while the history row is kept forever.
                $pdo->prepare("
                    UPDATE request_item_units
                    SET returned_at = NOW()
                    WHERE unit_id = :unit_id AND returned_at IS NULL
                ")->execute([":unit_id" => $unitId]);
            } else {
                // Aggregate equipment: only the quantities in THIS group move.
                if ($chosen === "Lost" || $lostQty > 0) {
                    $lostAmount = $lostQty > 0 ? $lostQty : $returnedQty;
                    // MariaDB applies these assignments left to right, so the
                    // second expression already sees the reduced total_stock.
                    // It must therefore clamp against that new total rather
                    // than subtract the lost quantity a second time.
                    $pdo->prepare("
                        UPDATE resources
                        SET total_stock = GREATEST(0, total_stock - :qty),
                            available_stock = LEAST(available_stock, GREATEST(0, total_stock - under_repair_stock))
                        WHERE resource_id = :resource_id
                    ")->execute([
                        ":qty" => $lostAmount,
                        ":resource_id" => $resourceId,
                    ]);
                } elseif ($chosen === "Good") {
                    $pdo->prepare("
                        UPDATE resources
                        SET available_stock = LEAST(
                                total_stock - under_repair_stock,
                                available_stock + :qty
                            )
                        WHERE resource_id = :resource_id
                    ")->execute([":qty" => $returnedQty, ":resource_id" => $resourceId]);
                } else {
                    // Damaged, Missing Parts or Needs Repair: park exactly these
                    // units out of service and leave the rest borrowable.
                    $pdo->prepare("
                        UPDATE resources
                        SET under_repair_stock = LEAST(
                                GREATEST(0, total_stock - available_stock),
                                under_repair_stock + :qty
                            )
                        WHERE resource_id = :resource_id
                    ")->execute([":qty" => $returnedQty, ":resource_id" => $resourceId]);
                }
            }

            $pdo->prepare("
                UPDATE return_submission_items
                SET inspection_condition = :condition,
                    inspection_remarks = :remarks,
                    inspected_by = :inspector,
                    inspected_at = NOW(),
                    stock_applied = 1,
                    returned_quantity = :returned_qty,
                    lost_quantity = :lost_qty
                WHERE return_item_id = :id AND stock_applied = 0
            ")->execute([
                ":condition" => $chosen,
                ":remarks" => $remarks !== "" ? mb_substr($remarks, 0, 500) : null,
                ":inspector" => $inspectorId,
                ":id" => $detailId,
                ":returned_qty" => $returnedQty,
                ":lost_qty" => $lostQty,
            ]);

            $pdo->prepare("
                UPDATE request_items
                SET returned_quantity = LEAST(released_quantity, returned_quantity + :qty)
                WHERE request_item_id = :line_id
            ")->execute([
                ":qty" => $returnedQty + $lostQty,
                ":line_id" => (int) $detail["request_item_id"],
            ]);
        }

        foreach (array_keys($touchedResources) as $resourceId) {
            gsoRecalculateSerializedStock($pdo, (int) $resourceId);
            refreshResourceOperationalStatus($pdo, (int) $resourceId);
        }

        return ["ok" => true, "message" => "Inspection recorded."];
    }
}

if (!function_exists("gsoWorstReturnCondition")) {
    /**
     * Roll the detail conditions up for the header, so existing displays and
     * reports that read return_submissions keep working unchanged.
     */
    function gsoWorstReturnCondition(array $conditions): string
    {
        $severity = ["Good" => 0, "Needs Repair" => 1, "Missing Parts" => 2, "Damaged" => 3, "Lost" => 4];
        $worst = "Good";

        foreach ($conditions as $condition) {
            if (($severity[$condition] ?? 0) > ($severity[$worst] ?? 0)) {
                $worst = $condition;
            }
        }

        return $worst;
    }
}
