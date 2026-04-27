<?php

require_once __DIR__ . "/schema_helper.php";
require_once __DIR__ . "/request_helper.php";

if (!function_exists("facilityCalendarEscape")) {
    function facilityCalendarEscape($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
    }
}

if (!function_exists("normalizeFacilityCalendarMonth")) {
    function normalizeFacilityCalendarMonth(?string $month): string
    {
        $month = trim((string) $month);

        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return date("Y-m");
        }

        $timestamp = strtotime($month . "-01");

        if ($timestamp === false) {
            return date("Y-m");
        }

        return date("Y-m", $timestamp);
    }
}

if (!function_exists("buildFacilityCalendarUrl")) {
    function buildFacilityCalendarUrl(string $baseUrl, array $queryParams): string
    {
        $query = http_build_query($queryParams);

        return $query === "" ? $baseUrl : $baseUrl . "?" . $query;
    }
}

if (!function_exists("fetchFacilityCalendarResources")) {
    function fetchFacilityCalendarResources(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT
                resource_id,
                resource_name,
                location,
                status
            FROM resources
            WHERE resource_type = 'Facility'
              AND is_archived = 0
            ORDER BY resource_name ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("fetchFacilityCalendarEvents")) {
    function fetchFacilityCalendarEvents(
        PDO $pdo,
        ?int $resourceId,
        string $monthStart,
        string $monthEnd,
        bool $includeBorrowerName = false
    ): array {
        $sql = "
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
                borrower.full_name AS borrower_name,
                r.resource_name,
                r.location
            FROM resource_requests rr
            INNER JOIN resources r ON rr.resource_id = r.resource_id
            LEFT JOIN users borrower ON rr.borrower_id = borrower.user_id
            WHERE r.resource_type = 'Facility'
              AND r.is_archived = 0
              AND rr.status IN ('Pending', 'Under Review', 'Approved', 'Released')
              AND DATE(rr.date_needed) BETWEEN :month_start AND :month_end
        ";

        $params = [
            ":month_start" => $monthStart,
            ":month_end" => $monthEnd
        ];

        if ($resourceId !== null && $resourceId > 0) {
            $sql .= " AND rr.resource_id = :resource_id";
            $params[":resource_id"] = $resourceId;
        }

        $sql .= "
            ORDER BY
                rr.date_needed ASC,
                rr.start_time ASC,
                rr.request_date ASC,
                rr.request_id ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $events = [];

        foreach ($rows as $row) {
            $dateNeeded = (string) ($row["date_needed"] ?? "");
            $dateTimestamp = strtotime($dateNeeded);

            if ($dateTimestamp === false) {
                continue;
            }

            $eventDate = date("Y-m-d", $dateTimestamp);
            $status = (string) ($row["status"] ?? "Pending");
            $timeRange = buildTimeRangeDisplay($row["start_time"] ?? null, $row["end_time"] ?? null);
            $details = [];

            if ($resourceId === null || $resourceId <= 0) {
                $details[] = (string) ($row["resource_name"] ?? "Facility");
            }

            if ($includeBorrowerName && !empty($row["borrower_name"])) {
                $details[] = (string) $row["borrower_name"];
            }

            $events[] = [
                "date" => $eventDate,
                "type" => "reservation",
                "status" => $status,
                "status_class" => getStatusCssClass($status),
                "request_code" => formatRequestCode((int) ($row["request_id"] ?? 0)),
                "time" => $timeRange,
                "title" => implode(" | ", $details),
                "resource_name" => (string) ($row["resource_name"] ?? "Facility"),
                "borrower_name" => $includeBorrowerName ? (string) ($row["borrower_name"] ?? "") : "",
                "request_date" => (string) ($row["request_date"] ?? "")
            ];
        }

        if (tableExists($pdo, "maintenance_schedules")) {
            $maintenanceSql = "
                SELECT
                    ms.maintenance_id,
                    ms.resource_id,
                    ms.start_date,
                    ms.end_date,
                    ms.reason,
                    ms.status,
                    r.resource_name,
                    r.location
                FROM maintenance_schedules ms
                INNER JOIN resources r ON ms.resource_id = r.resource_id
                WHERE r.resource_type = 'Facility'
                  AND r.is_archived = 0
                  AND ms.status IN ('Scheduled', 'In Progress')
                  AND NOT (
                        :month_end < ms.start_date
                        OR :month_start > ms.end_date
                  )
            ";

            $maintenanceParams = [
                ":month_start" => $monthStart,
                ":month_end" => $monthEnd
            ];

            if ($resourceId !== null && $resourceId > 0) {
                $maintenanceSql .= " AND ms.resource_id = :resource_id";
                $maintenanceParams[":resource_id"] = $resourceId;
            }

            $maintenanceSql .= " ORDER BY ms.start_date ASC, ms.maintenance_id ASC";

            $maintenanceStmt = $pdo->prepare($maintenanceSql);
            $maintenanceStmt->execute($maintenanceParams);
            $maintenanceRows = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($maintenanceRows as $row) {
                $startTimestamp = max(strtotime((string) $row["start_date"]), strtotime($monthStart));
                $endTimestamp = min(strtotime((string) $row["end_date"]), strtotime($monthEnd));

                if ($startTimestamp === false || $endTimestamp === false) {
                    continue;
                }

                for ($dayTimestamp = $startTimestamp; $dayTimestamp <= $endTimestamp; $dayTimestamp = strtotime("+1 day", $dayTimestamp)) {
                    $details = [];

                    if ($resourceId === null || $resourceId <= 0) {
                        $details[] = (string) ($row["resource_name"] ?? "Facility");
                    }

                    if (!empty($row["reason"])) {
                        $details[] = (string) $row["reason"];
                    }

                    $events[] = [
                        "date" => date("Y-m-d", $dayTimestamp),
                        "type" => "maintenance",
                        "status" => (string) ($row["status"] ?? "Scheduled"),
                        "status_class" => "maintenance",
                        "request_code" => "MNT-" . str_pad((string) ($row["maintenance_id"] ?? 0), 3, "0", STR_PAD_LEFT),
                        "time" => "Maintenance",
                        "title" => implode(" | ", $details),
                        "resource_name" => (string) ($row["resource_name"] ?? "Facility"),
                        "borrower_name" => "",
                        "request_date" => ""
                    ];
                }
            }
        }

        usort($events, function (array $left, array $right): int {
            $dateCompare = strcmp((string) $left["date"], (string) $right["date"]);

            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            $timeCompare = strcmp((string) $left["time"], (string) $right["time"]);

            if ($timeCompare !== 0) {
                return $timeCompare;
            }

            return strcmp((string) $left["request_code"], (string) $right["request_code"]);
        });

        return $events;
    }
}

if (!function_exists("groupFacilityCalendarEventsByDate")) {
    function groupFacilityCalendarEventsByDate(array $events): array
    {
        $grouped = [];

        foreach ($events as $event) {
            $date = (string) ($event["date"] ?? "");

            if ($date === "") {
                continue;
            }

            if (!isset($grouped[$date])) {
                $grouped[$date] = [];
            }

            $grouped[$date][] = $event;
        }

        return $grouped;
    }
}

if (!function_exists("renderFacilityAvailabilityCalendar")) {
    function renderFacilityAvailabilityCalendar(PDO $pdo, array $options = []): string
    {
        $monthParam = (string) ($options["month_param"] ?? "facility_calendar_month");
        $resourceParam = (string) ($options["resource_param"] ?? "facility_calendar_resource_id");
        $month = normalizeFacilityCalendarMonth($options["month"] ?? null);
        $resourceId = isset($options["resource_id"]) ? (int) $options["resource_id"] : null;
        $showBorrower = (bool) ($options["show_borrower"] ?? false);
        $showResourceFilter = (bool) ($options["show_resource_filter"] ?? false);
        $showResourceName = (bool) ($options["show_resource_name"] ?? ($resourceId === null || $resourceId <= 0));
        $baseUrl = (string) ($options["base_url"] ?? basename($_SERVER["PHP_SELF"] ?? ""));
        $queryParams = $options["query_params"] ?? [];

        if (!is_array($queryParams)) {
            $queryParams = [];
        }

        $resources = $options["resources"] ?? fetchFacilityCalendarResources($pdo);

        if (!is_array($resources)) {
            $resources = [];
        }

        $monthStart = date("Y-m-01", strtotime($month . "-01"));
        $monthEnd = date("Y-m-t", strtotime($month . "-01"));
        $monthTitle = date("F Y", strtotime($month . "-01"));
        $prevMonth = date("Y-m", strtotime($month . "-01 -1 month"));
        $nextMonth = date("Y-m", strtotime($month . "-01 +1 month"));

        $events = fetchFacilityCalendarEvents($pdo, $resourceId, $monthStart, $monthEnd, $showBorrower);
        $eventsByDate = groupFacilityCalendarEventsByDate($events);

        $firstDayTimestamp = strtotime($monthStart);
        $lastDayTimestamp = strtotime($monthEnd);
        $firstWeekday = (int) date("N", $firstDayTimestamp);
        $lastWeekday = (int) date("N", $lastDayTimestamp);
        $calendarStartTimestamp = strtotime("-" . ($firstWeekday - 1) . " days", $firstDayTimestamp);
        $calendarEndTimestamp = strtotime("+" . (7 - $lastWeekday) . " days", $lastDayTimestamp);

        $baseQueryParams = $queryParams;
        unset($baseQueryParams[$monthParam], $baseQueryParams[$resourceParam]);

        if ($resourceId !== null && $resourceId > 0) {
            $baseQueryParams[$resourceParam] = $resourceId;
        }

        $prevQueryParams = array_merge($baseQueryParams, [$monthParam => $prevMonth]);
        $nextQueryParams = array_merge($baseQueryParams, [$monthParam => $nextMonth]);

        $title = (string) ($options["title"] ?? "Facility Availability");
        $subtitle = (string) ($options["subtitle"] ?? "Reserved dates and maintenance blocks are loaded from active facility requests.");
        $emptyMessage = (string) ($options["empty_message"] ?? "Available");
        $today = date("Y-m-d");
        $activeReservationCount = 0;
        $blockedDayCount = count($eventsByDate);

        foreach ($eventsByDate as $eventList) {
            foreach ($eventList as $event) {
                if (($event["type"] ?? "") === "reservation") {
                    $activeReservationCount++;
                }
            }
        }

        ob_start();
        ?>
        <div class="facility-calendar" data-facility-calendar>
            <div class="facility-calendar-heading">
                <div>
                    <h3><?php echo facilityCalendarEscape($title); ?></h3>
                    <p><?php echo facilityCalendarEscape($subtitle); ?></p>
                </div>

                <form method="GET" action="<?php echo facilityCalendarEscape($baseUrl); ?>" class="facility-calendar-controls">
                    <?php foreach ($baseQueryParams as $name => $value): ?>
                        <?php if (is_scalar($value) && $name !== $monthParam && $name !== $resourceParam): ?>
                            <input type="hidden" name="<?php echo facilityCalendarEscape($name); ?>" value="<?php echo facilityCalendarEscape($value); ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if ($showResourceFilter): ?>
                        <select name="<?php echo facilityCalendarEscape($resourceParam); ?>" aria-label="Facility">
                            <option value="0" <?php echo empty($resourceId) ? "selected" : ""; ?>>All facilities</option>
                            <?php foreach ($resources as $facility): ?>
                                <?php $facilityId = (int) ($facility["resource_id"] ?? 0); ?>
                                <option value="<?php echo $facilityId; ?>" <?php echo $facilityId === (int) $resourceId ? "selected" : ""; ?>>
                                    <?php echo facilityCalendarEscape($facility["resource_name"] ?? "Facility"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($resourceId !== null && $resourceId > 0): ?>
                        <input type="hidden" name="<?php echo facilityCalendarEscape($resourceParam); ?>" value="<?php echo (int) $resourceId; ?>">
                    <?php endif; ?>

                    <input type="month" name="<?php echo facilityCalendarEscape($monthParam); ?>" value="<?php echo facilityCalendarEscape($month); ?>">
                    <button type="submit" class="admin-btn primary-btn">View</button>
                </form>
            </div>

            <div class="facility-calendar-summary" aria-label="Facility availability summary">
                <span><strong><?php echo (int) $activeReservationCount; ?></strong> active reservation<?php echo $activeReservationCount === 1 ? "" : "s"; ?></span>
                <span><strong><?php echo (int) $blockedDayCount; ?></strong> blocked day<?php echo $blockedDayCount === 1 ? "" : "s"; ?></span>
                <span><strong><?php echo facilityCalendarEscape($monthTitle); ?></strong></span>
            </div>

            <div class="facility-calendar-nav" aria-label="Calendar month navigation">
                <a href="<?php echo facilityCalendarEscape(buildFacilityCalendarUrl($baseUrl, $prevQueryParams)); ?>">Previous month</a>
                <strong><?php echo facilityCalendarEscape($monthTitle); ?></strong>
                <a href="<?php echo facilityCalendarEscape(buildFacilityCalendarUrl($baseUrl, $nextQueryParams)); ?>">Next month</a>
            </div>

            <div class="facility-calendar-legend" aria-label="Calendar legend">
                <span><i class="legend-dot available"></i> Available</span>
                <span><i class="legend-dot pending"></i> Pending review</span>
                <span><i class="legend-dot approved"></i> Approved or released</span>
                <span><i class="legend-dot maintenance"></i> Maintenance</span>
            </div>

            <?php if (empty($resources) && $showResourceFilter): ?>
                <div class="facility-calendar-empty">No active facility records found.</div>
            <?php else: ?>
                <div class="facility-calendar-grid" role="grid" aria-label="<?php echo facilityCalendarEscape($title . " for " . $monthTitle); ?>">
                    <?php foreach (["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"] as $weekday): ?>
                        <div class="facility-calendar-weekday" role="columnheader"><?php echo facilityCalendarEscape($weekday); ?></div>
                    <?php endforeach; ?>

                    <?php for ($dayTimestamp = $calendarStartTimestamp; $dayTimestamp <= $calendarEndTimestamp; $dayTimestamp = strtotime("+1 day", $dayTimestamp)): ?>
                        <?php
                        $date = date("Y-m-d", $dayTimestamp);
                        $isOutside = $date < $monthStart || $date > $monthEnd;
                        $dayEvents = $eventsByDate[$date] ?? [];
                        $hasReservation = false;
                        $hasMaintenance = false;

                        foreach ($dayEvents as $event) {
                            if (($event["type"] ?? "") === "maintenance") {
                                $hasMaintenance = true;
                            } else {
                                $hasReservation = true;
                            }
                        }

                        $dayClasses = ["facility-calendar-day"];

                        if ($isOutside) {
                            $dayClasses[] = "is-outside";
                        }

                        if ($date === $today) {
                            $dayClasses[] = "is-today";
                        }

                        if ($hasMaintenance) {
                            $dayClasses[] = "has-maintenance";
                        }

                        if ($hasReservation) {
                            $dayClasses[] = "has-reservation";
                        }

                        if (!$isOutside && !$hasMaintenance && !$hasReservation) {
                            $dayClasses[] = "is-available";
                        }
                        ?>
                        <div class="<?php echo facilityCalendarEscape(implode(" ", $dayClasses)); ?>" role="gridcell" data-calendar-day="<?php echo facilityCalendarEscape($date); ?>">
                            <button type="button" class="facility-calendar-day-button" data-calendar-date="<?php echo facilityCalendarEscape($date); ?>">
                                <span class="facility-calendar-day-number"><?php echo facilityCalendarEscape(date("j", $dayTimestamp)); ?></span>

                                <span class="facility-calendar-day-events">
                                    <?php if (!$isOutside && empty($dayEvents)): ?>
                                        <span class="facility-calendar-available"><?php echo facilityCalendarEscape($emptyMessage); ?></span>
                                    <?php else: ?>
                                        <?php foreach (array_slice($dayEvents, 0, 4) as $event): ?>
                                            <?php
                                            $eventClasses = [
                                                "facility-calendar-event",
                                                "facility-calendar-event-" . (string) ($event["type"] ?? "reservation"),
                                                "status-" . (string) ($event["status_class"] ?? "pending")
                                            ];
                                            ?>
                                            <span class="<?php echo facilityCalendarEscape(implode(" ", $eventClasses)); ?>">
                                                <strong><?php echo facilityCalendarEscape($event["time"] ?? "Reserved"); ?></strong>
                                                <?php if (($event["type"] ?? "") === "maintenance" && !empty($event["title"])): ?>
                                                    <span><?php echo facilityCalendarEscape($event["title"]); ?></span>
                                                <?php elseif ($showResourceName && !empty($event["title"])): ?>
                                                    <span><?php echo facilityCalendarEscape($event["title"]); ?></span>
                                                <?php elseif ($showBorrower && !empty($event["borrower_name"])): ?>
                                                    <span><?php echo facilityCalendarEscape($event["borrower_name"]); ?></span>
                                                <?php else: ?>
                                                    <span><?php echo facilityCalendarEscape($event["status"] ?? "Reserved"); ?></span>
                                                <?php endif; ?>
                                                <small><?php echo facilityCalendarEscape($event["request_code"] ?? ""); ?></small>
                                            </span>
                                        <?php endforeach; ?>

                                        <?php if (count($dayEvents) > 4): ?>
                                            <span class="facility-calendar-more">+<?php echo count($dayEvents) - 4; ?> more</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </span>
                            </button>
                        </div>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php

        return (string) ob_get_clean();
    }
}
