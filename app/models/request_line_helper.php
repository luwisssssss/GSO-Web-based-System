<?php

/**
 * Phase 3.5A — combined borrowing request lines.
 *
 * resource_requests remains the authoritative request header: one reference
 * number, one borrower, one approval workflow, one adviser workflow and one
 * director workflow. request_items carries the resources that request covers.
 *
 * resource_requests.resource_id and .quantity survive only as a compatibility
 * mirror of the request's primary line so that display paths and test fixtures
 * that have not yet switched over keep working. The mirror is NOT the complete
 * request and must never be used for availability, conflict decisions, stock
 * deduction, release, return or reporting.
 */

require_once __DIR__ . "/request_helper.php";

if (!function_exists("gsoLineKindForTrackingMode")) {
    /** Map the authoritative resource classification onto a request line kind. */
    function gsoLineKindForTrackingMode(string $trackingMode, string $resourceType): string
    {
        if ($resourceType === "Facility" || $trackingMode === "Facility") {
            return "Facility";
        }

        return $trackingMode === "Consumable" ? "Consumable" : "Equipment";
    }
}

if (!function_exists("gsoFetchRequestableResource")) {
    /**
     * Re-read a resource by id for request building. The caller never supplies
     * the classification: it is always taken from this row.
     */
    function gsoFetchRequestableResource(PDO $pdo, int $resourceId): ?array
    {
        if ($resourceId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT resource_id, resource_name, resource_type, tracking_mode, category,
                   location, total_stock, available_stock, under_repair_stock, capacity,
                   status, condition_status, is_archived
            FROM resources
            WHERE resource_id = :resource_id AND is_archived = 0
            LIMIT 1
        ");
        $stmt->execute([":resource_id" => $resourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("gsoRequestLines")) {
    /** Every line of one request, with its resource joined for display. */
    function gsoRequestLines(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare("
            SELECT ri.*, r.resource_name, r.resource_type, r.tracking_mode,
                   r.category, r.location, r.capacity
            FROM request_items ri
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE ri.request_id = :request_id
            ORDER BY FIELD(ri.line_kind, 'Facility', 'Equipment', 'Consumable'),
                     r.resource_name ASC, ri.request_item_id ASC
        ");
        $stmt->execute([":request_id" => $requestId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoRequestLineSummarySql")) {
    /**
     * A correlated subquery that renders a request's resources as one string.
     *
     * List screens use this instead of joining request_items, because joining
     * would emit one row per line and silently multiply every request-level
     * count on the page.
     *
     * @param string $alias the resource_requests alias in the outer query
     */
    function gsoRequestLineSummarySql(string $alias = "rr"): string
    {
        return "(
            SELECT GROUP_CONCAT(
                CONCAT(lr.resource_name,
                       CASE WHEN li.line_kind = 'Facility' THEN '' ELSE CONCAT(' x', li.requested_quantity) END)
                ORDER BY FIELD(li.line_kind,'Facility','Equipment','Consumable'), lr.resource_name
                SEPARATOR ', '
            )
            FROM request_items li
            INNER JOIN resources lr ON lr.resource_id = li.resource_id
            WHERE li.request_id = {$alias}.request_id
        )";
    }
}

if (!function_exists("gsoRequestLineCountSql")) {
    /** Correlated line count; same no-fan-out rationale as the summary. */
    function gsoRequestLineCountSql(string $alias = "rr"): string
    {
        return "(SELECT COUNT(*) FROM request_items lc WHERE lc.request_id = {$alias}.request_id)";
    }
}

if (!function_exists("gsoRequestFacilityLine")) {
    /** The single facility line of a request, or null for an equipment-only request. */
    function gsoRequestFacilityLine(PDO $pdo, int $requestId): ?array
    {
        $stmt = $pdo->prepare("
            SELECT ri.*, r.resource_name, r.resource_type, r.tracking_mode
            FROM request_items ri
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE ri.request_id = :request_id AND ri.line_kind = 'Facility'
            LIMIT 1
        ");
        $stmt->execute([":request_id" => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("gsoNormalizeRequestedLines")) {
    /**
     * Validate and normalize submitted lines into insertable rows.
     *
     * The submitted payload supplies only resource ids and quantities. Every
     * classification is re-read from the database, so a forged line_kind cannot
     * turn a facility into equipment or smuggle a consumable past its gate.
     * Repeated resources are merged rather than rejected, which is why the
     * UNIQUE (request_id, resource_id) key can act purely as a backstop.
     *
     * @return array{lines: array<int, array>, errors: array<int, string>}
     */
    function gsoNormalizeRequestedLines(PDO $pdo, array $submitted): array
    {
        $merged = [];
        $errors = [];

        foreach ($submitted as $entry) {
            $resourceId = (int) ($entry["resource_id"] ?? 0);
            $quantity = (int) ($entry["quantity"] ?? 0);

            if ($resourceId <= 0) {
                continue;
            }

            $resource = gsoFetchRequestableResource($pdo, $resourceId);

            if ($resource === null) {
                $errors[] = "One of the selected resources is unavailable.";
                continue;
            }

            $lineKind = gsoLineKindForTrackingMode(
                (string) $resource["tracking_mode"],
                (string) $resource["resource_type"]
            );

            // A facility is a single reservation, never a quantity.
            $quantity = $lineKind === "Facility" ? 1 : $quantity;

            if ($quantity < 1 || $quantity > 1000) {
                $errors[] = "Enter a quantity between 1 and 1000 for "
                    . $resource["resource_name"] . ".";
                continue;
            }

            if (isset($merged[$resourceId])) {
                // Same resource twice in one submission: merge the quantities
                // instead of creating a duplicate line.
                $merged[$resourceId]["quantity"] = $lineKind === "Facility"
                    ? 1
                    : min(1000, $merged[$resourceId]["quantity"] + $quantity);
                continue;
            }

            $merged[$resourceId] = [
                "resource_id" => $resourceId,
                "resource" => $resource,
                "line_kind" => $lineKind,
                "quantity" => $quantity,
            ];
        }

        $lines = array_values($merged);
        $facilityCount = 0;

        foreach ($lines as $line) {
            if ($line["line_kind"] === "Facility") {
                $facilityCount++;
            }
        }

        if ($lines === []) {
            $errors[] = "Add at least one resource to the request.";
        }

        if ($facilityCount > 1) {
            $errors[] = "A request may include only one facility.";
        }

        return ["lines" => $lines, "errors" => $errors];
    }
}

if (!function_exists("gsoPrimaryMirrorLine")) {
    /**
     * Pick the line mirrored into the legacy resource_requests columns.
     *
     * The facility wins when present, otherwise the largest equipment line.
     * This exists purely so paths that have not yet migrated keep rendering
     * something truthful; it is never the complete request.
     */
    function gsoPrimaryMirrorLine(array $lines): ?array
    {
        $primary = null;

        foreach ($lines as $line) {
            if ($line["line_kind"] === "Facility") {
                return $line;
            }

            if ($primary === null || $line["quantity"] > $primary["quantity"]) {
                $primary = $line;
            }
        }

        return $primary;
    }
}

if (!function_exists("gsoRequestFulfilmentBlocker")) {
    /**
     * Why a request cannot be released or returned yet, or null when it can.
     *
     * Phase 3.5A delivers combined borrowing end to end for request creation,
     * approval, adviser routing and director routing. Physical-unit allocation
     * (serialized), permanent consumable issuance and per-line return
     * inspection are Phase 3.5B. Rather than let those shapes through the old
     * single-resource release and return paths — which would deduct stock for
     * one line and silently mis-handle the rest — they are blocked with an
     * operational message and nothing is mutated.
     */
    function gsoRequestFulfilmentBlocker(PDO $pdo, int $requestId): ?string
    {
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE WHEN ri.line_kind = 'Consumable' THEN 1 ELSE 0 END) AS consumable_lines,
                SUM(CASE WHEN r.tracking_mode = 'Serialized' THEN 1 ELSE 0 END) AS serialized_lines,
                SUM(CASE WHEN ri.line_kind <> 'Consumable' THEN 1 ELSE 0 END) AS returnable_lines,
                COUNT(*) AS total_lines
            FROM request_items ri
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE ri.request_id = :request_id
        ");
        $stmt->execute([":request_id" => $requestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ((int) ($row["total_lines"] ?? 0) === 0) {
            return "This request has no resource lines and cannot be fulfilled.";
        }

        if ((int) ($row["serialized_lines"] ?? 0) > 0) {
            return "This request includes individually tracked equipment. "
                . "Physical unit allocation is not available yet, so it cannot be released.";
        }

        if ((int) ($row["consumable_lines"] ?? 0) > 0) {
            return "This request includes consumable materials. "
                . "Consumable issuance is not available yet, so it cannot be released.";
        }

        if ((int) ($row["returnable_lines"] ?? 0) > 1) {
            return "This request covers several returnable resources. "
                . "Per-resource return inspection is not available yet, so it cannot be released.";
        }

        return null;
    }
}
