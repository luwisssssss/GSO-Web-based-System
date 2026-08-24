<?php
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_report_analytics_helper.php";

$pageTitle = "Reports";
$activePage = "reports";
$cssFile = "../public/assets/css/pages/admin.css";

$adminUserId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedAdminAccount($pdo, $adminUserId)) {
    redirectWithFlash("dashboard.php", "Your account is not authorized to view reports.");
}

$statusFilter = trim((string) ($_GET["status"] ?? "All"));
$typeFilter = trim((string) ($_GET["resource_type"] ?? "All"));
$export = trim((string) ($_GET["export"] ?? ""));
$requestStatusOptions = ["Pending", "Approved", "Released", "Overdue", "Returned", "Damaged", "Rejected", "Cancelled"];
$resourceTypeOptions = ["Item", "Facility"];
$filterValidation = validateIncidentAnalyticsFilters($_GET);
$incidentFilters = $filterValidation["filters"];
$filterErrors = $filterValidation["errors"];
$dateFrom = $incidentFilters["date_from"];
$dateTo = $incidentFilters["date_to"];
$incidentStatusFilter = $incidentFilters["status"];
$incidentCategoryFilter = $incidentFilters["category"];
$incidentPriorityFilter = $incidentFilters["priority"];
$incidentLocationFilter = $incidentFilters["location"];

if ($statusFilter !== "All" && !in_array($statusFilter, $requestStatusOptions, true)) {
    $filterErrors[] = "The selected borrowing status is invalid.";
    $statusFilter = "All";
}
if ($typeFilter !== "All" && !in_array($typeFilter, $resourceTypeOptions, true)) {
    $filterErrors[] = "The selected resource type is invalid.";
    $typeFilter = "All";
}
if ($export !== "" && !in_array($export, ["excel", "pdf"], true)) {
    $filterErrors[] = "The selected export format is invalid.";
    $export = "";
}
if ($export !== "" && $filterErrors !== []) {
    $export = "";
}

$requestStmt = $pdo->query("
    SELECT
        rr.request_id,
        rr.quantity,
        rr.request_date,
        rr.date_needed,
        rr.start_time,
        rr.end_time,
        rr.status,
        rr.approved_at,
        rr.due_date,
        rr.return_date,
        rr.contact_number,
        rr.notes,
        borrower.full_name AS borrower_name,
        borrower.username AS borrower_username,
        borrower.email AS borrower_email,
        approver.full_name AS reviewed_by_name,
        r.resource_name,
        r.resource_type,
        r.category,
        r.location,
        rs.inspection_condition
    FROM resource_requests rr
    INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN return_submissions rs ON rr.request_id = rs.request_id
    ORDER BY rr.request_date DESC, rr.request_id DESC
");
$requestRows = $requestStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$borrowingRows = [];

foreach ($requestRows as $row) {
    $requestDateOnly = !empty($row["request_date"]) ? date("Y-m-d", strtotime((string) $row["request_date"])) : "";
    $displayStatus = getRequestLifecycleStatus($row);

    if ($dateFrom !== "" && $requestDateOnly !== "" && $requestDateOnly < $dateFrom) {
        continue;
    }

    if ($dateTo !== "" && $requestDateOnly !== "" && $requestDateOnly > $dateTo) {
        continue;
    }

    if ($statusFilter !== "All" && strcasecmp($displayStatus, $statusFilter) !== 0) {
        continue;
    }

    if ($typeFilter !== "All" && strcasecmp((string) ($row["resource_type"] ?? ""), $typeFilter) !== 0) {
        continue;
    }

    $row["display_status"] = $displayStatus;
    $borrowingRows[] = $row;
}

$returnedRows = array_values(array_filter($borrowingRows, function (array $row): bool {
    return ($row["status"] ?? "") === "Returned";
}));

$overdueRows = array_values(array_filter($borrowingRows, function (array $row): bool {
    return ($row["display_status"] ?? "") === "Overdue";
}));

$reservationRows = array_values(array_filter($borrowingRows, function (array $row): bool {
    return ($row["resource_type"] ?? "") === "Facility";
}));

$damagedRows = array_values(array_filter($borrowingRows, function (array $row): bool {
    return in_array((string) ($row["inspection_condition"] ?? ""), ["Damaged", "Missing Parts", "Needs Repair", "Lost"], true);
}));

$maintenanceSql = "
    SELECT
        ms.maintenance_id,
        ms.start_date,
        ms.end_date,
        ms.duration_days,
        ms.reason,
        ms.remarks,
        ms.status,
        r.resource_name,
        r.resource_type
    FROM maintenance_schedules ms
    INNER JOIN resources r ON ms.resource_id = r.resource_id
    WHERE 1=1
";

$maintenanceParams = [];

if ($dateFrom !== "") {
    $maintenanceSql .= " AND ms.end_date >= :date_from";
    $maintenanceParams[":date_from"] = $dateFrom;
}

if ($dateTo !== "") {
    $maintenanceSql .= " AND ms.start_date <= :date_to";
    $maintenanceParams[":date_to"] = $dateTo;
}

if ($typeFilter !== "All") {
    $maintenanceSql .= " AND r.resource_type = :maintenance_resource_type";
    $maintenanceParams[":maintenance_resource_type"] = $typeFilter;
}

$maintenanceSql .= " ORDER BY ms.start_date DESC, ms.maintenance_id DESC";
$maintenanceStmt = $pdo->prepare($maintenanceSql);
$maintenanceStmt->execute($maintenanceParams);
$maintenanceRows = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$inventorySql = "
    SELECT
        resource_id,
        resource_name,
        resource_type,
        category,
        location,
        total_stock,
        available_stock,
        status,
        condition_status
    FROM resources
    WHERE is_archived = 0
";
$inventoryParams = [];

if ($typeFilter !== "All") {
    $inventorySql .= " AND resource_type = :resource_type";
    $inventoryParams[":resource_type"] = $typeFilter;
}

$inventorySql .= " ORDER BY resource_name ASC";
$inventoryStmt = $pdo->prepare($inventorySql);
$inventoryStmt->execute($inventoryParams);
$inventoryRows = $inventoryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$incidentAnalytics = getIncidentReportAnalytics($pdo, $incidentFilters);

$summary = [
    "borrowing_transactions" => count($borrowingRows),
    "returned_items" => count($returnedRows),
    "overdue_items" => count($overdueRows),
    "reservation_records" => count($reservationRows),
    "maintenance_records" => count($maintenanceRows),
    "inventory_status" => count($inventoryRows),
    "damaged_lost_items" => count($damagedRows),
    "incident_reports" => $incidentAnalytics["total"]
];

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function reportFilterText(
    string $dateFrom,
    string $dateTo,
    string $statusFilter,
    string $typeFilter,
    array $incidentFilters
): string
{
    return "Filters | Date From: " . ($dateFrom !== "" ? $dateFrom : "All")
        . " | Date To: " . ($dateTo !== "" ? $dateTo : "All")
        . " | Borrowing Status: " . ($statusFilter !== "" ? $statusFilter : "All")
        . " | Resource Type: " . ($typeFilter !== "" ? $typeFilter : "All")
        . " | Incident Status: " . ($incidentFilters["status"] ?? "All")
        . " | Incident Category: " . ($incidentFilters["category"] ?? "All")
        . " | Incident Priority: " . ($incidentFilters["priority"] ?? "All")
        . " | Incident Location: " . (($incidentFilters["location"] ?? "") !== "" ? $incidentFilters["location"] : "All");
}

if ($export === "excel") {
    addActivityLog(
        $pdo,
        $adminUserId,
        "Report Export Excel",
        reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter, $incidentFilters)
    );

    $filename = "gso_reports_" . date("Ymd_His") . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html><head><meta charset='UTF-8'><title>GSO Reports</title></head><body>";
    echo "<h2>GSO Reports</h2>";
    echo "<p>" . h(reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter, $incidentFilters)) . "</p>";

    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><th>Report</th><th>Total</th></tr>";
    foreach ($summary as $label => $count) {
        echo "<tr><td>" . h(ucwords(str_replace("_", " ", $label))) . "</td><td>" . h($count) . "</td></tr>";
    }
    echo "</table><br><br>";

    echo "<h3>Incident Reporting Summary</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><th>Metric</th><th>Total</th></tr>";
    echo "<tr><td>All filtered incidents</td><td>" . h($incidentAnalytics["total"]) . "</td></tr>";
    foreach ($incidentAnalytics["status_counts"] as $incidentStatus => $count) {
        echo "<tr><td>" . h($incidentStatus) . "</td><td>" . h($count) . "</td></tr>";
    }
    echo "<tr><td>Open (Submitted, Under Review, In Progress)</td><td>" . h($incidentAnalytics["open"]) . "</td></tr>";
    echo "<tr><td>Closed (Resolved, Rejected)</td><td>" . h($incidentAnalytics["closed"]) . "</td></tr>";
    echo "</table><br>";

    echo "<h3>Incident Category Breakdown</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Category</th><th>Total</th><th>Open</th><th>Share</th></tr>";
    foreach ($incidentAnalytics["category_rows"] as $row) {
        $share = $incidentAnalytics["total"] > 0 ? round(((int) $row["total"] / $incidentAnalytics["total"]) * 100, 1) : 0;
        echo "<tr><td>" . h($row["label"]) . "</td><td>" . h($row["total"]) . "</td><td>" . h($row["open_total"]) . "</td><td>" . h($share) . "%</td></tr>";
    }
    if ($incidentAnalytics["category_rows"] === []) {
        echo "<tr><td colspan='4'>No incident category records found.</td></tr>";
    }
    echo "</table><br>";

    echo "<h3>Incident Location Breakdown</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Location</th><th>Total</th><th>Open</th></tr>";
    foreach ($incidentAnalytics["location_rows"] as $row) {
        echo "<tr><td>" . h($row["label"]) . "</td><td>" . h($row["total"]) . "</td><td>" . h($row["open_total"]) . "</td></tr>";
    }
    if ($incidentAnalytics["location_rows"] === []) {
        echo "<tr><td colspan='3'>No incident location records found.</td></tr>";
    }
    echo "</table><br>";

    echo "<h3>Incident Trend (" . h($incidentAnalytics["trend_granularity"]) . ")</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Period</th><th>Reported</th></tr>";
    foreach ($incidentAnalytics["trend_rows"] as $row) {
        echo "<tr><td>" . h(formatIncidentTrendPeriod($row["period_start"] ?? null, $incidentAnalytics["trend_granularity"])) . "</td><td>" . h($row["total"]) . "</td></tr>";
    }
    if ($incidentAnalytics["trend_rows"] === []) {
        echo "<tr><td colspan='2'>No incident trend records found.</td></tr>";
    }
    echo "</table><br>";

    echo "<h3>Incident Resolution Time</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Valid Resolved Incidents</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr>";
    echo "<tr><td>" . h($incidentAnalytics["resolution"]["valid_count"]) . "</td><td>" . h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["average_seconds"])) . "</td><td>" . h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["minimum_seconds"])) . "</td><td>" . h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["maximum_seconds"])) . "</td></tr>";
    echo "</table><br>";

    echo "<h3>Filtered Incident Reports</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'><tr><th>Incident</th><th>Title</th><th>Category</th><th>Location</th><th>Related Resource</th><th>Priority</th><th>Status</th><th>Reported</th><th>Resolved</th></tr>";
    foreach ($incidentAnalytics["export_rows"] as $row) {
        echo "<tr><td>" . h(formatIncidentNumber((int) $row["incident_id"])) . "</td><td>" . h($row["incident_title"]) . "</td><td>" . h($row["incident_type"]) . "</td><td>" . h($row["location"]) . "</td><td>" . h($row["resource_name"] ?? "N/A") . "</td><td>" . h($row["priority"]) . "</td><td>" . h($row["status"]) . "</td><td>" . h(formatIncidentDateTime($row["reported_at"] ?? null)) . "</td><td>" . h(formatIncidentDateTime($row["resolved_at"] ?? null)) . "</td></tr>";
    }
    if ($incidentAnalytics["export_rows"] === []) {
        echo "<tr><td colspan='9'>No incident reports found.</td></tr>";
    }
    echo "</table><br><br>";

    $sections = [
        "Borrowing Transactions" => $borrowingRows,
        "Overdue Items" => $overdueRows,
        "Reservation Records" => $reservationRows,
        "Damaged or Lost Items" => $damagedRows
    ];

    foreach ($sections as $title => $rows) {
        echo "<h3>" . h($title) . "</h3>";
        echo "<table border='1' cellpadding='6' cellspacing='0'>";
        echo "<tr>
                <th>Reference</th>
                <th>Borrower</th>
                <th>Contact</th>
                <th>Resource</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Date Requested</th>
                <th>Date Needed</th>
                <th>Time</th>
                <th>Status</th>
                <th>Due Date</th>
                <th>Return Date</th>
              </tr>";

        foreach ($rows as $row) {
            echo "<tr>
                    <td>REQ-" . str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT) . "</td>
                    <td>" . h($row["borrower_name"] ?? "N/A") . "</td>
                    <td>" . h($row["contact_number"] ?? "N/A") . "</td>
                    <td>" . h($row["resource_name"] ?? "N/A") . "</td>
                    <td>" . h($row["resource_type"] ?? "N/A") . "</td>
                    <td>" . h($row["quantity"] ?? "N/A") . "</td>
                    <td>" . h(formatDateTimeDisplay($row["request_date"] ?? "")) . "</td>
                    <td>" . h(formatDateTimeDisplay($row["date_needed"] ?? "")) . "</td>
                    <td>" . h(buildTimeRangeDisplay($row["start_time"] ?? null, $row["end_time"] ?? null)) . "</td>
                    <td>" . h($row["display_status"] ?? ($row["status"] ?? "N/A")) . "</td>
                    <td>" . h(formatDateTimeDisplay($row["due_date"] ?? "")) . "</td>
                    <td>" . h(formatDateTimeDisplay($row["return_date"] ?? "")) . "</td>
                  </tr>";
        }

        if (count($rows) === 0) {
            echo "<tr><td colspan='12'>No records found.</td></tr>";
        }

        echo "</table><br><br>";
    }

    echo "<h3>Maintenance Records</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Resource</th><th>Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th><th>Reason</th></tr>";
    foreach ($maintenanceRows as $row) {
        echo "<tr>
                <td>MTN-" . str_pad((string) $row["maintenance_id"], 3, "0", STR_PAD_LEFT) . "</td>
                <td>" . h($row["resource_name"] ?? "N/A") . "</td>
                <td>" . h($row["resource_type"] ?? "N/A") . "</td>
                <td>" . h(formatDateTimeDisplay($row["start_date"] ?? "")) . "</td>
                <td>" . h(formatDateTimeDisplay($row["end_date"] ?? "")) . "</td>
                <td>" . h($row["duration_days"] ?? "N/A") . "</td>
                <td>" . h($row["status"] ?? "N/A") . "</td>
                <td>" . h($row["reason"] ?? "N/A") . "</td>
              </tr>";
    }
    if (count($maintenanceRows) === 0) {
        echo "<tr><td colspan='8'>No maintenance records found.</td></tr>";
    }
    echo "</table><br><br>";

    echo "<h3>Inventory Status</h3>";
    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><th>ID</th><th>Resource</th><th>Type</th><th>Category</th><th>Location</th><th>Total Stock</th><th>Available Stock</th><th>Status</th><th>Condition</th></tr>";
    foreach ($inventoryRows as $row) {
        echo "<tr>
                <td>" . h($row["resource_id"] ?? "N/A") . "</td>
                <td>" . h($row["resource_name"] ?? "N/A") . "</td>
                <td>" . h($row["resource_type"] ?? "N/A") . "</td>
                <td>" . h($row["category"] ?? "N/A") . "</td>
                <td>" . h($row["location"] ?? "N/A") . "</td>
                <td>" . h($row["total_stock"] ?? "N/A") . "</td>
                <td>" . h($row["available_stock"] ?? "N/A") . "</td>
                <td>" . h($row["status"] ?? "N/A") . "</td>
                <td>" . h($row["condition_status"] ?? "N/A") . "</td>
              </tr>";
    }
    if (count($inventoryRows) === 0) {
        echo "<tr><td colspan='9'>No inventory records found.</td></tr>";
    }
    echo "</table></body></html>";
    exit();
}

if ($export === "pdf") {
    addActivityLog(
        $pdo,
        $adminUserId,
        "Report Export PDF",
        reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter, $incidentFilters)
    );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>GSO Reports PDF</title>
        <link rel="stylesheet" href="../public/assets/css/pages/admin-reports.css">
    </head>
    <body>
        <div class="no-print">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>

        <h1>GSO Reports</h1>
        <div class="meta">
            <?php echo h(reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter, $incidentFilters)); ?><br>
            Generated at: <?php echo h(date("M d, Y h:i A")); ?>
        </div>

        <div class="summary-grid">
            <?php foreach ($summary as $label => $count): ?>
                <div class="summary-box">
                    <?php echo h(ucwords(str_replace("_", " ", $label))); ?>
                    <strong><?php echo h($count); ?></strong>
                </div>
            <?php endforeach; ?>
        </div>

        <h2>Incident Reporting Analytics</h2>
        <div class="summary-grid">
            <div class="summary-box">Total incidents<strong><?php echo h($incidentAnalytics["total"]); ?></strong></div>
            <?php foreach ($incidentAnalytics["status_counts"] as $incidentStatus => $count): ?>
                <div class="summary-box"><?php echo h($incidentStatus); ?><strong><?php echo h($count); ?></strong></div>
            <?php endforeach; ?>
            <div class="summary-box">Open<strong><?php echo h($incidentAnalytics["open"]); ?></strong></div>
            <div class="summary-box">Closed<strong><?php echo h($incidentAnalytics["closed"]); ?></strong></div>
        </div>

        <h3>Category and Priority Breakdown</h3>
        <table>
            <thead><tr><th>Dimension</th><th>Value</th><th>Total</th><th>Open</th></tr></thead>
            <tbody>
                <?php foreach ($incidentAnalytics["category_rows"] as $row): ?>
                    <tr><td>Category</td><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ($incidentAnalytics["priority_rows"] as $row): ?>
                    <tr><td>Priority</td><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td></tr>
                <?php endforeach; ?>
                <?php if ($incidentAnalytics["category_rows"] === [] && $incidentAnalytics["priority_rows"] === []): ?>
                    <tr><td colspan="4">No incident breakdown records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h3>Locations</h3>
        <table>
            <thead><tr><th>Location</th><th>Total</th><th>Open</th></tr></thead>
            <tbody>
                <?php foreach ($incidentAnalytics["location_rows"] as $row): ?>
                    <tr><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td></tr>
                <?php endforeach; ?>
                <?php if ($incidentAnalytics["location_rows"] === []): ?><tr><td colspan="3">No incident location records found.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h3>Incident Trend (<?php echo h($incidentAnalytics["trend_granularity"]); ?>)</h3>
        <table>
            <thead><tr><th>Period</th><th>Reported</th></tr></thead>
            <tbody>
                <?php foreach ($incidentAnalytics["trend_rows"] as $row): ?>
                    <tr><td><?php echo h(formatIncidentTrendPeriod($row["period_start"] ?? null, $incidentAnalytics["trend_granularity"])); ?></td><td><?php echo h($row["total"]); ?></td></tr>
                <?php endforeach; ?>
                <?php if ($incidentAnalytics["trend_rows"] === []): ?><tr><td colspan="2">No incident trend records found.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h3>Resolution Time</h3>
        <table>
            <thead><tr><th>Valid Resolved Incidents</th><th>Average</th><th>Minimum</th><th>Maximum</th></tr></thead>
            <tbody><tr>
                <td><?php echo h($incidentAnalytics["resolution"]["valid_count"]); ?></td>
                <td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["average_seconds"])); ?></td>
                <td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["minimum_seconds"])); ?></td>
                <td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["maximum_seconds"])); ?></td>
            </tr></tbody>
        </table>

        <h3>Filtered Incident Reports</h3>
        <table>
            <thead><tr><th>Incident</th><th>Title</th><th>Category</th><th>Location</th><th>Priority</th><th>Status</th><th>Reported</th><th>Resolved</th></tr></thead>
            <tbody>
                <?php foreach ($incidentAnalytics["export_rows"] as $row): ?>
                    <tr>
                        <td><?php echo h(formatIncidentNumber((int) $row["incident_id"])); ?></td>
                        <td><?php echo h($row["incident_title"]); ?></td>
                        <td><?php echo h($row["incident_type"]); ?></td>
                        <td><?php echo h($row["location"]); ?></td>
                        <td><?php echo h($row["priority"]); ?></td>
                        <td><?php echo h($row["status"]); ?></td>
                        <td><?php echo h(formatIncidentDateTime($row["reported_at"] ?? null)); ?></td>
                        <td><?php echo h(formatIncidentDateTime($row["resolved_at"] ?? null)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($incidentAnalytics["export_rows"] === []): ?><tr><td colspan="8">No incident reports found.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Borrowing Transactions</h2>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Borrower</th>
                    <th>Resource</th>
                    <th>Type</th>
                    <th>Qty</th>
                    <th>Date Requested</th>
                    <th>Status</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($borrowingRows as $row): ?>
                    <tr>
                        <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["quantity"] ?? "N/A"); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["request_date"] ?? "")); ?></td>
                        <td><?php echo h($row["display_status"] ?? ($row["status"] ?? "N/A")); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["due_date"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($borrowingRows) === 0): ?>
                    <tr><td colspan="8">No borrowing records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Maintenance Records</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Resource</th>
                    <th>Type</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Status</th>
                    <th>Reason</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($maintenanceRows as $row): ?>
                    <tr>
                        <td>MTN-<?php echo str_pad((string) $row["maintenance_id"], 3, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["start_date"] ?? "")); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["end_date"] ?? "")); ?></td>
                        <td><?php echo h($row["status"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["reason"] ?? "N/A"); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($maintenanceRows) === 0): ?>
                    <tr><td colspan="7">No maintenance records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Overdue Items</h2>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Borrower</th>
                    <th>Contact</th>
                    <th>Resource</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overdueRows as $row): ?>
                    <tr>
                        <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["contact_number"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["due_date"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($overdueRows) === 0): ?>
                    <tr><td colspan="5">No overdue items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Reservation Records</h2>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Borrower</th>
                    <th>Facility</th>
                    <th>Date Needed</th>
                    <th>Time</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservationRows as $row): ?>
                    <tr>
                        <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["date_needed"] ?? "")); ?></td>
                        <td><?php echo h(buildTimeRangeDisplay($row["start_time"] ?? null, $row["end_time"] ?? null)); ?></td>
                        <td><?php echo h($row["display_status"] ?? ($row["status"] ?? "N/A")); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($reservationRows) === 0): ?>
                    <tr><td colspan="6">No reservation records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Damaged / Lost Items</h2>
        <table>
            <thead>
                <tr>
                    <th>Reference</th>
                    <th>Borrower</th>
                    <th>Resource</th>
                    <th>Condition</th>
                    <th>Return Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($damagedRows as $row): ?>
                    <tr>
                        <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                        <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["inspection_condition"] ?? "N/A"); ?></td>
                        <td><?php echo h(formatDateTimeDisplay($row["return_date"] ?? "")); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($damagedRows) === 0): ?>
                    <tr><td colspan="5">No damaged or lost items found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Inventory Status</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Resource</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Condition</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventoryRows as $row): ?>
                    <tr>
                        <td><?php echo h($row["resource_id"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["status"] ?? "N/A"); ?></td>
                        <td><?php echo h($row["condition_status"] ?? "N/A"); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (count($inventoryRows) === 0): ?>
                    <tr><td colspan="5">No inventory records found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit();
}

$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/admin-reports.css"]);
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";

$exportBase = [
    "date_from" => $dateFrom,
    "date_to" => $dateTo,
    "status" => $statusFilter,
    "resource_type" => $typeFilter,
    "incident_status" => $incidentStatusFilter,
    "incident_category" => $incidentCategoryFilter,
    "incident_priority" => $incidentPriorityFilter,
    "incident_location" => $incidentLocationFilter
];
?>



<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Reports</h2>
            <p>Monitor borrowing, inventory, maintenance, and incident-reporting performance from one Admin report.</p>

            <div class="report-action-bar">
                <a href="reports.php?<?php echo h(http_build_query(array_merge($exportBase, ["export" => "excel"]))); ?>" class="admin-btn success-btn">
                    Export Excel
                </a>
                <a href="reports.php?<?php echo h(http_build_query(array_merge($exportBase, ["export" => "pdf"]))); ?>" target="_blank" class="admin-btn danger-btn">
                    Export PDF
                </a>
            </div>
        </div>

        <?php if ($filterErrors !== []): ?>
            <div class="card report-filter-error" role="alert">
                <strong>The report filters need attention.</strong>
                <ul>
                    <?php foreach ($filterErrors as $filterError): ?><li><?php echo h($filterError); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="table-toolbar report-filter-form">
                <label>From date<input type="date" name="date_from" value="<?php echo h($dateFrom); ?>"></label>
                <label>To date<input type="date" name="date_to" value="<?php echo h($dateTo); ?>"></label>

                <label>Borrowing status<select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <?php foreach ($requestStatusOptions as $option): ?><option value="<?php echo h($option); ?>" <?php echo $statusFilter === $option ? "selected" : ""; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                </select></label>

                <label>Resource type<select name="resource_type">
                    <option value="All" <?php echo $typeFilter === "All" ? "selected" : ""; ?>>All Resource Types</option>
                    <?php foreach ($resourceTypeOptions as $option): ?><option value="<?php echo h($option); ?>" <?php echo $typeFilter === $option ? "selected" : ""; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                </select></label>

                <span class="report-filter-divider" aria-hidden="true"></span>

                <label>Incident status<select name="incident_status">
                    <option value="All">All Incident Statuses</option>
                    <?php foreach (getIncidentStatusOptions() as $option): ?><option value="<?php echo h($option); ?>" <?php echo $incidentStatusFilter === $option ? "selected" : ""; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                </select></label>

                <label>Incident category<select name="incident_category">
                    <option value="All">All Categories</option>
                    <?php foreach (getIncidentTypeOptions() as $option): ?><option value="<?php echo h($option); ?>" <?php echo $incidentCategoryFilter === $option ? "selected" : ""; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                </select></label>

                <label>Incident priority<select name="incident_priority">
                    <option value="All">All Priorities</option>
                    <?php foreach (getIncidentPriorityOptions() as $option): ?><option value="<?php echo h($option); ?>" <?php echo $incidentPriorityFilter === $option ? "selected" : ""; ?>><?php echo h($option); ?></option><?php endforeach; ?>
                </select></label>

                <label>Incident location<input type="search" name="incident_location" maxlength="100" placeholder="Location contains..." value="<?php echo h($incidentLocationFilter); ?>"></label>

                <button type="submit" class="admin-btn primary-btn">Generate Report</button>
                <a href="reports.php" class="admin-btn info-btn">Reset</a>
            </form>
        </div>

        <div class="report-grid">
            <?php foreach ($summary as $label => $count): ?>
                <div class="report-stat-card">
                    <h3><?php echo h(ucwords(str_replace("_", " ", $label))); ?></h3>
                    <p class="stat-number"><?php echo h($count); ?></p>
                </div>
            <?php endforeach; ?>
        </div>

        <section class="incident-report-section" aria-labelledby="incident-analytics-title">
            <div class="card incident-report-heading">
                <div>
                    <h2 id="incident-analytics-title">Incident Reporting Analytics</h2>
                    <p>Counts use the incident's reported date and the incident filters above. The shared date range also applies to the existing report sections.</p>
                </div>
                <a class="admin-btn primary-btn" href="incidents.php">Manage Incidents</a>
            </div>

            <div class="report-grid incident-report-stat-grid">
                <div class="report-stat-card"><h3>Total Incidents</h3><p class="stat-number"><?php echo h($incidentAnalytics["total"]); ?></p></div>
                <?php foreach ($incidentAnalytics["status_counts"] as $incidentStatus => $count): ?>
                    <div class="report-stat-card">
                        <h3><?php echo h($incidentStatus); ?></h3>
                        <p class="stat-number"><?php echo h($count); ?></p>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="report-analytics-grid">
                <div class="card report-analytics-card">
                    <h3>Open vs Closed</h3>
                    <table class="request-table compact-report-table">
                        <thead><tr><th>Group</th><th>Total</th><th>Share</th></tr></thead>
                        <tbody>
                            <?php foreach (["Open" => $incidentAnalytics["open"], "Closed" => $incidentAnalytics["closed"]] as $label => $count): ?>
                                <tr><td><?php echo h($label); ?></td><td><?php echo h($count); ?></td><td><?php echo h($incidentAnalytics["total"] > 0 ? round(($count / $incidentAnalytics["total"]) * 100, 1) : 0); ?>%</td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p class="report-metric-note">Open = Submitted, Under Review, or In Progress. Closed = Resolved or Rejected.</p>
                </div>

                <div class="card report-analytics-card">
                    <h3>Resolution Time</h3>
                    <table class="request-table compact-report-table">
                        <tbody>
                            <tr><th>Valid resolved records</th><td><?php echo h($incidentAnalytics["resolution"]["valid_count"]); ?></td></tr>
                            <tr><th>Average</th><td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["average_seconds"])); ?></td></tr>
                            <tr><th>Minimum</th><td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["minimum_seconds"])); ?></td></tr>
                            <tr><th>Maximum</th><td><?php echo h(formatIncidentAnalyticsDuration($incidentAnalytics["resolution"]["maximum_seconds"])); ?></td></tr>
                        </tbody>
                    </table>
                    <p class="report-metric-note">Measured from reported_at to resolved_at. Only Resolved records with a present, nonnegative timestamp pair are included.</p>
                </div>

                <div class="card report-analytics-card">
                    <h3>Incidents by Category</h3>
                    <div class="table-wrapper"><table class="request-table compact-report-table">
                        <thead><tr><th>Category</th><th>Total</th><th>Open</th><th>Share</th></tr></thead>
                        <tbody>
                            <?php foreach ($incidentAnalytics["category_rows"] as $row): ?>
                                <tr><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td><td><?php echo h($incidentAnalytics["total"] > 0 ? round(((int) $row["total"] / $incidentAnalytics["total"]) * 100, 1) : 0); ?>%</td></tr>
                            <?php endforeach; ?>
                            <?php if ($incidentAnalytics["category_rows"] === []): ?><tr><td colspan="4" class="empty-note">No incident records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>

                <div class="card report-analytics-card">
                    <h3>Incidents by Priority</h3>
                    <div class="table-wrapper"><table class="request-table compact-report-table">
                        <thead><tr><th>Priority</th><th>Total</th><th>Open</th></tr></thead>
                        <tbody>
                            <?php foreach ($incidentAnalytics["priority_rows"] as $row): ?>
                                <tr><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($incidentAnalytics["priority_rows"] === []): ?><tr><td colspan="3" class="empty-note">No incident records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                </div>

                <div class="card report-analytics-card">
                    <h3>Incident Trend — <?php echo h($incidentAnalytics["trend_granularity"]); ?></h3>
                    <div class="table-wrapper"><table class="request-table compact-report-table">
                        <thead><tr><th>Period</th><th>Reported</th></tr></thead>
                        <tbody>
                            <?php foreach ($incidentAnalytics["trend_rows"] as $row): ?>
                                <tr><td><?php echo h(formatIncidentTrendPeriod($row["period_start"] ?? null, $incidentAnalytics["trend_granularity"])); ?></td><td><?php echo h($row["total"]); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($incidentAnalytics["trend_rows"] === []): ?><tr><td colspan="2" class="empty-note">No incident records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                    <p class="report-metric-note">Daily for spans up to 31 days, weekly up to 180 days, and monthly for longer spans.</p>
                </div>

                <div class="card report-analytics-card">
                    <h3>Incidents by Location</h3>
                    <div class="table-wrapper report-location-table"><table class="request-table compact-report-table">
                        <thead><tr><th>Location</th><th>Total</th><th>Open</th></tr></thead>
                        <tbody>
                            <?php foreach ($incidentAnalytics["location_rows"] as $row): ?>
                                <tr><td><?php echo h($row["label"]); ?></td><td><?php echo h($row["total"]); ?></td><td><?php echo h($row["open_total"]); ?></td></tr>
                            <?php endforeach; ?>
                            <?php if ($incidentAnalytics["location_rows"] === []): ?><tr><td colspan="3" class="empty-note">No incident records found.</td></tr><?php endif; ?>
                        </tbody>
                    </table></div>
                    <p class="report-metric-note">Locations are free text. Spelling, abbreviations, and capitalization can create separate groups.</p>
                </div>
            </div>

            <div class="card report-unresolved-card">
                <div class="report-card-heading">
                    <div><h3>Open Incident Worklist</h3><p><?php echo h($incidentAnalytics["unresolved_total"]); ?> matching open incident(s), ordered by priority and oldest report first.</p></div>
                    <a href="incidents.php?open=1" class="admin-btn info-btn">View Open Queue</a>
                </div>
                <div class="table-wrapper"><table class="request-table">
                    <thead><tr><th>Incident</th><th>Title</th><th>Category</th><th>Location</th><th>Priority</th><th>Status</th><th>Reported</th><th>Age</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($incidentAnalytics["unresolved_rows"] as $row): ?>
                            <tr>
                                <td><strong><?php echo h(formatIncidentNumber((int) $row["incident_id"])); ?></strong></td>
                                <td><?php echo h($row["incident_title"]); ?></td>
                                <td><?php echo h($row["incident_type"]); ?></td>
                                <td><?php echo h($row["location"]); ?></td>
                                <td><?php echo h($row["priority"]); ?></td>
                                <td><span class="status-badge <?php echo h(getIncidentStatusCssClass((string) $row["status"])); ?>"><?php echo h($row["status"]); ?></span></td>
                                <td><?php echo h(formatIncidentDateTime($row["reported_at"] ?? null)); ?></td>
                                <td><?php echo h(formatIncidentAnalyticsDuration($row["age_seconds"] ?? null)); ?></td>
                                <td><a class="table-action-btn" href="view_incident.php?incident_id=<?php echo (int) $row["incident_id"]; ?>">Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($incidentAnalytics["unresolved_rows"] === []): ?><tr><td colspan="9" class="empty-note">No open incidents match the selected filters.</td></tr><?php endif; ?>
                    </tbody>
                </table></div>
                <?php if ($incidentAnalytics["unresolved_total"] > count($incidentAnalytics["unresolved_rows"])): ?><p class="report-metric-note">Showing the first <?php echo count($incidentAnalytics["unresolved_rows"]); ?> records. Use Incident Management for the complete queue.</p><?php endif; ?>
            </div>
        </section>

        <div class="card">
            <h3>Borrowing Transactions</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Borrower</th>
                            <th>Resource</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Date Requested</th>
                            <th>Date Needed</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Due Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($borrowingRows) > 0): ?>
                            <?php foreach ($borrowingRows as $row): ?>
                                <?php $statusClass = getStatusCssClass((string) ($row["display_status"] ?? $row["status"] ?? "")); ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["quantity"] ?? "N/A"); ?></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["request_date"] ?? "")); ?></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["date_needed"] ?? "")); ?></td>
                                    <td><?php echo h(buildTimeRangeDisplay($row["start_time"] ?? null, $row["end_time"] ?? null)); ?></td>
                                    <td><span class="status-badge <?php echo h($statusClass); ?>"><?php echo h($row["display_status"] ?? ($row["status"] ?? "N/A")); ?></span></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["due_date"] ?? "")); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10" class="empty-note">No borrowing records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Maintenance Records</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resource</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Days</th>
                            <th>Status</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($maintenanceRows) > 0): ?>
                            <?php foreach ($maintenanceRows as $row): ?>
                                <tr>
                                    <td>MTN-<?php echo str_pad((string) $row["maintenance_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["start_date"] ?? "")); ?> - <?php echo h(formatDateTimeDisplay($row["end_date"] ?? "")); ?></td>
                                    <td><?php echo h($row["duration_days"] ?? "N/A"); ?></td>
                                    <td><span class="status-badge <?php echo h(getStatusCssClass((string) ($row["status"] ?? ""))); ?>"><?php echo h($row["status"] ?? "N/A"); ?></span></td>
                                    <td><?php echo h($row["reason"] ?? "N/A"); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="7" class="empty-note">No maintenance records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Inventory Status</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resource</th>
                            <th>Type</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Total Stock</th>
                            <th>Available Stock</th>
                            <th>Status</th>
                            <th>Condition</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($inventoryRows) > 0): ?>
                            <?php foreach ($inventoryRows as $row): ?>
                                <tr>
                                    <td><?php echo h($row["resource_id"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_type"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["category"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["location"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["total_stock"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["available_stock"] ?? "N/A"); ?></td>
                                    <td><span class="status-badge <?php echo h(getStatusCssClass((string) ($row["status"] ?? ""))); ?>"><?php echo h($row["status"] ?? "N/A"); ?></span></td>
                                    <td><span class="status-badge <?php echo h(getStatusCssClass((string) ($row["condition_status"] ?? ""))); ?>"><?php echo h($row["condition_status"] ?? "N/A"); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="empty-note">No inventory records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Overdue Items</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Borrower</th>
                            <th>Contact</th>
                            <th>Resource</th>
                            <th>Due Date</th>
                            <th>Reviewed By</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($overdueRows) > 0): ?>
                            <?php foreach ($overdueRows as $row): ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["contact_number"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["due_date"] ?? "")); ?></td>
                                    <td><?php echo h($row["reviewed_by_name"] ?? "N/A"); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-note">No overdue items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Reservation Records</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Borrower</th>
                            <th>Facility</th>
                            <th>Date Needed</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($reservationRows) > 0): ?>
                            <?php foreach ($reservationRows as $row): ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["date_needed"] ?? "")); ?></td>
                                    <td><?php echo h(buildTimeRangeDisplay($row["start_time"] ?? null, $row["end_time"] ?? null)); ?></td>
                                    <td><span class="status-badge <?php echo h(getStatusCssClass((string) ($row["display_status"] ?? $row["status"] ?? ""))); ?>"><?php echo h($row["display_status"] ?? ($row["status"] ?? "N/A")); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="empty-note">No reservation records found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <h3>Damaged / Lost Items</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Borrower</th>
                            <th>Resource</th>
                            <th>Condition</th>
                            <th>Return Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($damagedRows) > 0): ?>
                            <?php foreach ($damagedRows as $row): ?>
                                <?php $condition = (string) ($row["inspection_condition"] ?? "N/A"); ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td><?php echo h($row["borrower_name"] ?? "N/A"); ?></td>
                                    <td><?php echo h($row["resource_name"] ?? "N/A"); ?></td>
                                    <td><span class="status-badge <?php echo h(getStatusCssClass($condition)); ?>"><?php echo h($condition); ?></span></td>
                                    <td><?php echo h(formatDateTimeDisplay($row["return_date"] ?? "")); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="empty-note">No damaged or lost items found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
