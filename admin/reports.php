<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/request_helper.php";

$pageTitle = "Reports";
$activePage = "reports";
$cssFile = "../assets/css/admin.css";

$adminUserId = (int) ($_SESSION["user_id"] ?? 0);

$dateFrom = trim((string) ($_GET["date_from"] ?? ""));
$dateTo = trim((string) ($_GET["date_to"] ?? ""));
$statusFilter = trim((string) ($_GET["status"] ?? "All"));
$typeFilter = trim((string) ($_GET["resource_type"] ?? "All"));
$export = trim((string) ($_GET["export"] ?? ""));

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

$summary = [
    "borrowing_transactions" => count($borrowingRows),
    "returned_items" => count($returnedRows),
    "overdue_items" => count($overdueRows),
    "reservation_records" => count($reservationRows),
    "maintenance_records" => count($maintenanceRows),
    "inventory_status" => count($inventoryRows),
    "damaged_lost_items" => count($damagedRows)
];

function h($value): string
{
    return htmlspecialchars((string) $value);
}

function reportFilterText(string $dateFrom, string $dateTo, string $statusFilter, string $typeFilter): string
{
    return "Filters | Date From: " . ($dateFrom !== "" ? $dateFrom : "All")
        . " | Date To: " . ($dateTo !== "" ? $dateTo : "All")
        . " | Status: " . ($statusFilter !== "" ? $statusFilter : "All")
        . " | Resource Type: " . ($typeFilter !== "" ? $typeFilter : "All");
}

if ($export === "excel") {
    addActivityLog(
        $pdo,
        $adminUserId,
        "Report Export Excel",
        reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter)
    );

    $filename = "gso_reports_" . date("Ymd_His") . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "<html><head><meta charset='UTF-8'><title>GSO Reports</title></head><body>";
    echo "<h2>GSO Reports</h2>";
    echo "<p>" . h(reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter)) . "</p>";

    echo "<table border='1' cellpadding='6' cellspacing='0'>";
    echo "<tr><th>Report</th><th>Total</th></tr>";
    foreach ($summary as $label => $count) {
        echo "<tr><td>" . h(ucwords(str_replace("_", " ", $label))) . "</td><td>" . h($count) . "</td></tr>";
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
        reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter)
    );
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>GSO Reports PDF</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #111827; }
            h1, h2, h3 { margin: 0 0 10px 0; }
            .meta { margin-bottom: 20px; color: #475569; font-size: 14px; }
            .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 20px; }
            .summary-box { border: 1px solid #cbd5e1; padding: 10px; border-radius: 8px; }
            .summary-box strong { display: block; font-size: 22px; margin-top: 6px; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 18px; }
            th, td { border: 1px solid #cbd5e1; padding: 6px; text-align: left; vertical-align: top; }
            th { background: #f8fafc; }
            .no-print { margin-bottom: 16px; }
            @media print { .no-print { display: none; } }
        </style>
    </head>
    <body>
        <div class="no-print">
            <button onclick="window.print()">Print / Save as PDF</button>
        </div>

        <h1>GSO Reports</h1>
        <div class="meta">
            <?php echo h(reportFilterText($dateFrom, $dateTo, $statusFilter, $typeFilter)); ?><br>
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

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";

$exportBase = [
    "date_from" => $dateFrom,
    "date_to" => $dateTo,
    "status" => $statusFilter,
    "resource_type" => $typeFilter
];
?>

<style>
.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.report-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
}

.report-stat-card h3 {
    margin: 0 0 10px 0;
    font-size: 15px;
    color: #64748b;
    font-weight: 700;
}

.report-stat-card .stat-number {
    font-size: 28px;
    font-weight: 800;
    color: #0f172a;
    margin: 0;
}

.report-action-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 14px;
}
</style>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Reports</h2>
            <p>Monitor borrowing transactions, returns, overdue items, reservations, maintenance records, inventory status, and damaged or lost resources.</p>

            <div class="report-action-bar">
                <a href="reports.php?<?php echo h(http_build_query(array_merge($exportBase, ["export" => "excel"]))); ?>" class="admin-btn success-btn">
                    Export Excel
                </a>
                <a href="reports.php?<?php echo h(http_build_query(array_merge($exportBase, ["export" => "pdf"]))); ?>" target="_blank" class="admin-btn danger-btn">
                    Export PDF
                </a>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input type="date" name="date_from" value="<?php echo h($dateFrom); ?>">
                <input type="date" name="date_to" value="<?php echo h($dateTo); ?>">

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Pending" <?php echo $statusFilter === "Pending" ? "selected" : ""; ?>>Pending</option>
                    <option value="Approved" <?php echo $statusFilter === "Approved" ? "selected" : ""; ?>>Approved</option>
                    <option value="Released" <?php echo $statusFilter === "Released" ? "selected" : ""; ?>>Released</option>
                    <option value="Overdue" <?php echo $statusFilter === "Overdue" ? "selected" : ""; ?>>Overdue</option>
                    <option value="Returned" <?php echo $statusFilter === "Returned" ? "selected" : ""; ?>>Returned</option>
                    <option value="Damaged" <?php echo $statusFilter === "Damaged" ? "selected" : ""; ?>>Damaged</option>
                    <option value="Rejected" <?php echo $statusFilter === "Rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="Cancelled" <?php echo $statusFilter === "Cancelled" ? "selected" : ""; ?>>Cancelled</option>
                </select>

                <select name="resource_type">
                    <option value="All" <?php echo $typeFilter === "All" ? "selected" : ""; ?>>All Resource Types</option>
                    <option value="Item" <?php echo $typeFilter === "Item" ? "selected" : ""; ?>>Item</option>
                    <option value="Facility" <?php echo $typeFilter === "Facility" ? "selected" : ""; ?>>Facility</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Generate Report</button>
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
