<?php

if (!function_exists("validateIncidentAnalyticsFilters")) {
    function validateIncidentAnalyticsFilters(array $input): array
    {
        $errors = [];
        $dateFrom = trim((string) ($input["date_from"] ?? ""));
        $dateTo = trim((string) ($input["date_to"] ?? ""));
        $status = trim((string) ($input["incident_status"] ?? "All"));
        $category = trim((string) ($input["incident_category"] ?? "All"));
        $priority = trim((string) ($input["incident_priority"] ?? "All"));
        $location = normalizeIncidentSingleLine($input["incident_location"] ?? "");

        $validDate = static function (string $value): bool {
            if ($value === "") {
                return true;
            }

            $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
            return $date instanceof DateTimeImmutable && $date->format("Y-m-d") === $value;
        };

        if (!$validDate($dateFrom) || !$validDate($dateTo)) {
            $errors[] = "Use valid start and end dates in YYYY-MM-DD format.";
            $dateFrom = "";
            $dateTo = "";
        } elseif ($dateFrom !== "" && $dateTo !== "" && $dateFrom > $dateTo) {
            $errors[] = "The report start date cannot be later than the end date.";
            $dateFrom = "";
            $dateTo = "";
        }

        if ($status !== "All" && !in_array($status, getIncidentStatusOptions(), true)) {
            $errors[] = "The selected incident status is invalid.";
            $status = "All";
        }
        if ($category !== "All" && !in_array($category, getIncidentTypeOptions(), true)) {
            $errors[] = "The selected incident category is invalid.";
            $category = "All";
        }
        if ($priority !== "All" && !in_array($priority, getIncidentPriorityOptions(), true)) {
            $errors[] = "The selected incident priority is invalid.";
            $priority = "All";
        }
        if (incidentTextLength($location) > 100) {
            $errors[] = "The incident location filter must not exceed 100 characters.";
            $location = truncateIncidentText($location, 100);
        }

        return [
            "filters" => [
                "date_from" => $dateFrom,
                "date_to" => $dateTo,
                "status" => $status,
                "category" => $category,
                "priority" => $priority,
                "location" => $location,
            ],
            "errors" => $errors,
        ];
    }
}

if (!function_exists("buildIncidentAnalyticsWhere")) {
    function buildIncidentAnalyticsWhere(array $filters): array
    {
        $where = ["1 = 1"];
        $params = [];

        if (($filters["date_from"] ?? "") !== "") {
            $where[] = "ir.reported_at >= :incident_date_from";
            $params[":incident_date_from"] = $filters["date_from"] . " 00:00:00";
        }
        if (($filters["date_to"] ?? "") !== "") {
            $exclusiveEnd = (new DateTimeImmutable($filters["date_to"]))->modify("+1 day");
            $where[] = "ir.reported_at < :incident_date_to";
            $params[":incident_date_to"] = $exclusiveEnd->format("Y-m-d 00:00:00");
        }
        if (($filters["status"] ?? "All") !== "All") {
            $where[] = "ir.status = :incident_status";
            $params[":incident_status"] = $filters["status"];
        }
        if (($filters["category"] ?? "All") !== "All") {
            $where[] = "ir.incident_type = :incident_category";
            $params[":incident_category"] = $filters["category"];
        }
        if (($filters["priority"] ?? "All") !== "All") {
            $where[] = "ir.priority = :incident_priority";
            $params[":incident_priority"] = $filters["priority"];
        }
        if (($filters["location"] ?? "") !== "") {
            $escapedLocation = str_replace(
                ["=", "%", "_"],
                ["==", "=%", "=_"],
                $filters["location"]
            );
            $where[] = "ir.location LIKE :incident_location ESCAPE '='";
            $params[":incident_location"] = "%" . $escapedLocation . "%";
        }

        return ["sql" => implode(" AND ", $where), "params" => $params];
    }
}

if (!function_exists("incidentAnalyticsFetchAll")) {
    function incidentAnalyticsFetchAll(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("incidentAnalyticsFetchOne")) {
    function incidentAnalyticsFetchOne(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}

if (!function_exists("getIncidentReportAnalytics")) {
    function getIncidentReportAnalytics(PDO $pdo, array $filters, int $unresolvedLimit = 100): array
    {
        $query = buildIncidentAnalyticsWhere($filters);
        $whereSql = $query["sql"];
        $params = $query["params"];
        $statusCounts = array_fill_keys(getIncidentStatusOptions(), 0);

        $statusRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT ir.status, COUNT(*) AS total
             FROM incident_reports ir
             WHERE {$whereSql}
             GROUP BY ir.status",
            $params
        );
        foreach ($statusRows as $row) {
            if (array_key_exists((string) $row["status"], $statusCounts)) {
                $statusCounts[(string) $row["status"]] = (int) $row["total"];
            }
        }

        $total = array_sum($statusCounts);
        $open = $statusCounts["Submitted"] + $statusCounts["Under Review"] + $statusCounts["In Progress"];
        $closed = $statusCounts["Resolved"] + $statusCounts["Rejected"];

        $categoryRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT ir.incident_type AS label, COUNT(*) AS total,
                    SUM(ir.status IN ('Submitted', 'Under Review', 'In Progress')) AS open_total
             FROM incident_reports ir
             WHERE {$whereSql}
             GROUP BY ir.incident_type
             ORDER BY total DESC, ir.incident_type ASC",
            $params
        );

        $priorityRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT ir.priority AS label, COUNT(*) AS total,
                    SUM(ir.status IN ('Submitted', 'Under Review', 'In Progress')) AS open_total
             FROM incident_reports ir
             WHERE {$whereSql}
             GROUP BY ir.priority
             ORDER BY FIELD(ir.priority, 'Urgent', 'High', 'Normal', 'Low')",
            $params
        );

        $locationRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT COALESCE(NULLIF(TRIM(ir.location), ''), 'Unspecified') AS label,
                    COUNT(*) AS total,
                    SUM(ir.status IN ('Submitted', 'Under Review', 'In Progress')) AS open_total
             FROM incident_reports ir
             WHERE {$whereSql}
             GROUP BY COALESCE(NULLIF(TRIM(ir.location), ''), 'Unspecified')
             ORDER BY total DESC, label ASC",
            $params
        );

        $span = incidentAnalyticsFetchOne(
            $pdo,
            "SELECT MIN(ir.reported_at) AS first_reported_at, MAX(ir.reported_at) AS last_reported_at
             FROM incident_reports ir
             WHERE {$whereSql}",
            $params
        );
        $spanDays = 0;
        if (!empty($span["first_reported_at"]) && !empty($span["last_reported_at"])) {
            $first = new DateTimeImmutable((string) $span["first_reported_at"]);
            $last = new DateTimeImmutable((string) $span["last_reported_at"]);
            $spanDays = max(0, (int) $first->diff($last)->format("%a"));
        }

        if ($spanDays <= 31) {
            $granularity = "Daily";
            $periodExpression = "DATE(ir.reported_at)";
        } elseif ($spanDays <= 180) {
            $granularity = "Weekly";
            $periodExpression = "DATE_SUB(DATE(ir.reported_at), INTERVAL WEEKDAY(ir.reported_at) DAY)";
        } else {
            $granularity = "Monthly";
            $periodExpression = "DATE_FORMAT(ir.reported_at, '%Y-%m-01')";
        }

        $trendRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT {$periodExpression} AS period_start, COUNT(*) AS total
             FROM incident_reports ir
             WHERE {$whereSql}
             GROUP BY period_start
             ORDER BY period_start ASC",
            $params
        );

        $resolution = incidentAnalyticsFetchOne(
            $pdo,
            "SELECT COUNT(*) AS valid_count,
                    AVG(TIMESTAMPDIFF(SECOND, ir.reported_at, ir.resolved_at)) AS average_seconds,
                    MIN(TIMESTAMPDIFF(SECOND, ir.reported_at, ir.resolved_at)) AS minimum_seconds,
                    MAX(TIMESTAMPDIFF(SECOND, ir.reported_at, ir.resolved_at)) AS maximum_seconds
             FROM incident_reports ir
             WHERE {$whereSql}
               AND ir.status = 'Resolved'
               AND ir.resolved_at IS NOT NULL
               AND ir.resolved_at >= ir.reported_at",
            $params
        );

        $unresolvedQuery = "{$whereSql} AND ir.status IN ('Submitted', 'Under Review', 'In Progress')";
        $unresolvedCount = incidentAnalyticsFetchOne(
            $pdo,
            "SELECT COUNT(*) AS total FROM incident_reports ir WHERE {$unresolvedQuery}",
            $params
        );
        $unresolvedLimit = max(1, min(500, $unresolvedLimit));
        $unresolvedRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT ir.incident_id, ir.incident_title, ir.incident_type, ir.location,
                    ir.priority, ir.status, ir.reported_at,
                    GREATEST(TIMESTAMPDIFF(SECOND, ir.reported_at, NOW()), 0) AS age_seconds
             FROM incident_reports ir
             WHERE {$unresolvedQuery}
             ORDER BY
                FIELD(ir.priority, 'Urgent', 'High', 'Normal', 'Low'),
                ir.reported_at ASC,
                ir.incident_id ASC
             LIMIT {$unresolvedLimit}",
            $params
        );

        $exportRows = incidentAnalyticsFetchAll(
            $pdo,
            "SELECT ir.incident_id, ir.incident_title, ir.incident_type, ir.location,
                    r.resource_name, ir.priority, ir.status, ir.reported_at, ir.resolved_at
             FROM incident_reports ir
             LEFT JOIN resources r ON r.resource_id = ir.resource_id
             WHERE {$whereSql}
             ORDER BY ir.reported_at DESC, ir.incident_id DESC",
            $params
        );

        return [
            "total" => $total,
            "status_counts" => $statusCounts,
            "open" => $open,
            "closed" => $closed,
            "category_rows" => $categoryRows,
            "priority_rows" => $priorityRows,
            "location_rows" => $locationRows,
            "trend_granularity" => $granularity,
            "trend_rows" => $trendRows,
            "resolution" => [
                "valid_count" => (int) ($resolution["valid_count"] ?? 0),
                "average_seconds" => $resolution["average_seconds"] !== null ? (float) $resolution["average_seconds"] : null,
                "minimum_seconds" => $resolution["minimum_seconds"] !== null ? (int) $resolution["minimum_seconds"] : null,
                "maximum_seconds" => $resolution["maximum_seconds"] !== null ? (int) $resolution["maximum_seconds"] : null,
            ],
            "unresolved_total" => (int) ($unresolvedCount["total"] ?? 0),
            "unresolved_rows" => $unresolvedRows,
            "export_rows" => $exportRows,
        ];
    }
}

if (!function_exists("formatIncidentAnalyticsDuration")) {
    function formatIncidentAnalyticsDuration($seconds): string
    {
        if ($seconds === null || !is_numeric($seconds) || (float) $seconds < 0) {
            return "N/A";
        }

        $totalMinutes = (int) round((float) $seconds / 60);
        $days = intdiv($totalMinutes, 1440);
        $hours = intdiv($totalMinutes % 1440, 60);
        $minutes = $totalMinutes % 60;
        $parts = [];

        if ($days > 0) {
            $parts[] = $days . "d";
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours . "h";
        }
        $parts[] = $minutes . "m";

        return implode(" ", $parts);
    }
}

if (!function_exists("formatIncidentTrendPeriod")) {
    function formatIncidentTrendPeriod(?string $value, string $granularity): string
    {
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return "N/A";
        }

        if ($granularity === "Monthly") {
            return date("M Y", $timestamp);
        }
        if ($granularity === "Weekly") {
            return "Week of " . date("M d, Y", $timestamp);
        }

        return date("M d, Y", $timestamp);
    }
}
