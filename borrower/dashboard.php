<?php
require_once "../includes/borrower_check.php";
header("Location: browse.php");
exit();

require_once "../config/db.php";
require_once "../includes/overdue_helper.php";
require_once "../includes/request_helper.php";

$pageTitle = "Dashboard";
$activePage = "dashboard";
$cssFile = "../assets/css/borrower.css";

$borrowerId = (int) $_SESSION["user_id"];

ensureBorrowerOverdueNotifications($pdo, $borrowerId);

function borrowerDashboardPct(int $value, int $total): float
{
    if ($total <= 0) {
        return 0;
    }

    return round(($value / $total) * 100, 2);
}

function borrowerDashboardWidth(int $value, int $maxValue): string
{
    if ($maxValue <= 0) {
        return "0%";
    }

    return round(($value / $maxValue) * 100, 2) . "%";
}

$requestStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) AS released_count,
        SUM(CASE WHEN status = 'Returned' THEN 1 ELSE 0 END) AS returned_count,
        SUM(CASE WHEN status = 'Rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count
    FROM resource_requests
    WHERE borrower_id = :borrower_id
");
$requestStmt->execute([":borrower_id" => $borrowerId]);
$requestPipeline = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$loanStmt = $pdo->prepare("
    SELECT
        COUNT(DISTINCT CASE WHEN rr.status = 'Released' THEN rr.request_id END) AS active_count,
        COUNT(DISTINCT CASE WHEN rr.status = 'Released' AND rr.due_date IS NOT NULL AND rr.due_date < NOW() THEN rr.request_id END) AS overdue_count,
        COUNT(DISTINCT CASE WHEN rr.status = 'Released' AND rr.due_date IS NOT NULL AND rr.due_date BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 3 DAY) THEN rr.request_id END) AS due_soon_count,
        COUNT(DISTINCT CASE WHEN rs.status = 'Pending' THEN rr.request_id END) AS return_pending_count
    FROM resource_requests rr
    LEFT JOIN return_submissions rs ON rs.request_id = rr.request_id
    WHERE rr.borrower_id = :borrower_id
");
$loanStmt->execute([":borrower_id" => $borrowerId]);
$loanState = $loanStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$availability = $pdo->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_count,
        SUM(CASE WHEN resource_type = 'Item' AND status = 'Available' THEN 1 ELSE 0 END) AS available_items,
        SUM(CASE WHEN resource_type = 'Facility' AND status = 'Available' THEN 1 ELSE 0 END) AS available_facilities,
        SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) AS maintenance_count,
        SUM(CASE WHEN status <> 'Available' THEN 1 ELSE 0 END) AS unavailable_count
    FROM resources
    WHERE is_archived = 0
")->fetch(PDO::FETCH_ASSOC) ?: [];

$trendStmt = $pdo->prepare("
    SELECT
        DATE_FORMAT(request_date, '%b') AS month_label,
        DATE_FORMAT(request_date, '%Y-%m') AS month_key,
        COUNT(*) AS total
    FROM resource_requests
    WHERE borrower_id = :borrower_id
      AND request_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
");
$trendStmt->execute([":borrower_id" => $borrowerId]);
$trendRows = $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentStmt = $pdo->prepare("
    SELECT
        rr.request_id,
        rr.quantity,
        rr.request_date,
        rr.date_needed,
        rr.start_time,
        rr.end_time,
        rr.status,
        rr.due_date,
        r.resource_name,
        r.resource_type,
        r.category,
        rs.inspection_condition
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN return_submissions rs ON rs.request_id = rr.request_id
    WHERE rr.borrower_id = :borrower_id
    ORDER BY rr.request_date DESC, rr.request_id DESC
    LIMIT 5
");
$recentStmt->execute([":borrower_id" => $borrowerId]);
$recentRequests = $recentStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dueStmt = $pdo->prepare("
    SELECT
        rr.request_id,
        rr.due_date,
        rr.status,
        r.resource_name,
        r.resource_type,
        r.category
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    WHERE rr.borrower_id = :borrower_id
      AND rr.status = 'Released'
      AND rr.due_date IS NOT NULL
    ORDER BY
        CASE WHEN rr.due_date < NOW() THEN 0 ELSE 1 END,
        rr.due_date ASC,
        rr.request_id DESC
    LIMIT 5
");
$dueStmt->execute([":borrower_id" => $borrowerId]);
$dueItems = $dueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$requestTotal = (int) ($requestPipeline["total_count"] ?? 0);
$pendingPct = borrowerDashboardPct((int) ($requestPipeline["pending_count"] ?? 0), $requestTotal);
$approvedPct = borrowerDashboardPct((int) ($requestPipeline["approved_count"] ?? 0), $requestTotal);
$releasedPct = borrowerDashboardPct((int) ($requestPipeline["released_count"] ?? 0), $requestTotal);
$returnedPct = borrowerDashboardPct((int) ($requestPipeline["returned_count"] ?? 0), $requestTotal);
$rejectedPct = borrowerDashboardPct((int) ($requestPipeline["rejected_count"] ?? 0), $requestTotal);

$loanTotal = max(1, (int) ($loanState["active_count"] ?? 0) + (int) ($loanState["return_pending_count"] ?? 0));
$activePct = borrowerDashboardPct((int) ($loanState["active_count"] ?? 0), $loanTotal);
$dueSoonPct = borrowerDashboardPct((int) ($loanState["due_soon_count"] ?? 0), $loanTotal);
$overduePct = borrowerDashboardPct((int) ($loanState["overdue_count"] ?? 0), $loanTotal);
$returnPendingPct = borrowerDashboardPct((int) ($loanState["return_pending_count"] ?? 0), $loanTotal);

$trendMax = max(array_merge([1], array_map(function (array $row): int {
    return (int) ($row["total"] ?? 0);
}, $trendRows)));

$availabilityRows = [
    ["Available Items", (int) ($availability["available_items"] ?? 0), "green"],
    ["Available Facilities", (int) ($availability["available_facilities"] ?? 0), "blue"],
    ["Maintenance", (int) ($availability["maintenance_count"] ?? 0), "orange"],
    ["Unavailable", (int) ($availability["unavailable_count"] ?? 0), "red"]
];
$availabilityMax = max(array_merge([1], array_column($availabilityRows, 1)));

require_once "../includes/header.php";
require_once "../includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/borrower_topbar.php"; ?>

    <main class="page-content dashboard-page">
        <div class="card">
            <div>
                <h2>Dashboard</h2>
                <p>Your request progress, active borrowings, due dates, and available resources in one view.</p>
            </div>
            <div class="action-group">
                <a href="browse.php" class="request-btn">Browse</a>
                <a href="my_requests.php" class="small-btn">My Requests</a>
                <a href="my_borrowed.php" class="small-btn">Return Items</a>
            </div>
        </div>

        <section class="dashboard-chart-grid">
            <div class="dashboard-chart-panel chart-main">
                <div class="chart-heading">
                    <div>
                        <h3>Request Progress</h3>
                        <p><?php echo $requestTotal; ?> total submitted requests</p>
                    </div>
                    <a href="my_requests.php" class="chart-link">Track</a>
                </div>

                <div class="donut-row">
                    <div
                        class="donut-chart"
                        style="background: conic-gradient(
                            #f59e0b 0 <?php echo $pendingPct; ?>%,
                            #2563eb <?php echo $pendingPct; ?>% <?php echo $pendingPct + $approvedPct; ?>%,
                            #1d4ed8 <?php echo $pendingPct + $approvedPct; ?>% <?php echo $pendingPct + $approvedPct + $releasedPct; ?>%,
                            #16a34a <?php echo $pendingPct + $approvedPct + $releasedPct; ?>% <?php echo $pendingPct + $approvedPct + $releasedPct + $returnedPct; ?>%,
                            #dc2626 <?php echo $pendingPct + $approvedPct + $releasedPct + $returnedPct; ?>% <?php echo $pendingPct + $approvedPct + $releasedPct + $returnedPct + $rejectedPct; ?>%,
                            #64748b <?php echo $pendingPct + $approvedPct + $releasedPct + $returnedPct + $rejectedPct; ?>% 100%
                        );"
                    >
                        <div>
                            <strong><?php echo $requestTotal; ?></strong>
                            <span>Total</span>
                        </div>
                    </div>

                    <div class="chart-legend">
                        <span><i style="background:#f59e0b"></i> Pending: <?php echo (int) ($requestPipeline["pending_count"] ?? 0); ?></span>
                        <span><i style="background:#2563eb"></i> Approved: <?php echo (int) ($requestPipeline["approved_count"] ?? 0); ?></span>
                        <span><i style="background:#1d4ed8"></i> Borrowed: <?php echo (int) ($requestPipeline["released_count"] ?? 0); ?></span>
                        <span><i style="background:#16a34a"></i> Returned: <?php echo (int) ($requestPipeline["returned_count"] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Borrowing Health</h3>
                        <p>Current loans and return status</p>
                    </div>
                    <a href="my_borrowed.php" class="chart-link">Open</a>
                </div>

                <div
                    class="donut-chart donut-small"
                    style="background: conic-gradient(
                        #1d4ed8 0 <?php echo $activePct; ?>%,
                        #f59e0b <?php echo $activePct; ?>% <?php echo $activePct + $dueSoonPct; ?>%,
                        #dc2626 <?php echo $activePct + $dueSoonPct; ?>% <?php echo $activePct + $dueSoonPct + $overduePct; ?>%,
                        #38bdf8 <?php echo $activePct + $dueSoonPct + $overduePct; ?>% <?php echo $activePct + $dueSoonPct + $overduePct + $returnPendingPct; ?>%,
                        #e2e8f0 <?php echo $activePct + $dueSoonPct + $overduePct + $returnPendingPct; ?>% 100%
                    );"
                >
                    <div>
                        <strong><?php echo (int) ($loanState["active_count"] ?? 0); ?></strong>
                        <span>Active</span>
                    </div>
                </div>

                <div class="compact-legend">
                    <span><i style="background:#1d4ed8"></i> Active <?php echo (int) ($loanState["active_count"] ?? 0); ?></span>
                    <span><i style="background:#f59e0b"></i> Due Soon <?php echo (int) ($loanState["due_soon_count"] ?? 0); ?></span>
                    <span><i style="background:#dc2626"></i> Overdue <?php echo (int) ($loanState["overdue_count"] ?? 0); ?></span>
                    <span><i style="background:#38bdf8"></i> Return Pending <?php echo (int) ($loanState["return_pending_count"] ?? 0); ?></span>
                </div>
            </div>

            <div class="dashboard-chart-panel urgent-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Needs Action</h3>
                        <p>Fast links for the next task</p>
                    </div>
                </div>

                <div class="urgent-grid">
                    <a href="my_requests.php?status=Pending"><strong><?php echo (int) ($requestPipeline["pending_count"] ?? 0); ?></strong><span>Waiting requests</span></a>
                    <a href="my_requests.php?status=Approved"><strong><?php echo (int) ($requestPipeline["approved_count"] ?? 0); ?></strong><span>Approved requests</span></a>
                    <a href="my_borrowed.php?loan_state=Overdue"><strong><?php echo (int) ($loanState["overdue_count"] ?? 0); ?></strong><span>Overdue items</span></a>
                    <a href="my_borrowed.php"><strong><?php echo (int) ($loanState["return_pending_count"] ?? 0); ?></strong><span>Returns pending</span></a>
                </div>
            </div>
        </section>

        <section class="dashboard-chart-grid secondary">
            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Monthly Requests</h3>
                        <p>Your activity over the last months</p>
                    </div>
                </div>

                <div class="vertical-chart">
                    <?php if (count($trendRows) > 0): ?>
                        <?php foreach ($trendRows as $row): ?>
                            <?php $height = max(8, (int) round((((int) $row["total"]) / $trendMax) * 100)); ?>
                            <div class="vertical-bar">
                                <strong><?php echo (int) $row["total"]; ?></strong>
                                <span style="height: <?php echo $height; ?>%;"></span>
                                <small><?php echo htmlspecialchars((string) $row["month_label"]); ?></small>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-chart">No request trend yet.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Resource Availability</h3>
                        <p><?php echo (int) ($availability["total_count"] ?? 0); ?> resources in inventory</p>
                    </div>
                    <a href="browse.php?status=Available" class="chart-link">Browse</a>
                </div>

                <div class="chart-list compact">
                    <?php foreach ($availabilityRows as $row): ?>
                        <div class="chart-row">
                            <div class="chart-label-row">
                                <span><?php echo htmlspecialchars($row[0]); ?></span>
                                <strong><?php echo (int) $row[1]; ?></strong>
                            </div>
                            <div class="chart-track"><div class="chart-fill <?php echo htmlspecialchars($row[2]); ?>" style="width: <?php echo borrowerDashboardWidth((int) $row[1], $availabilityMax); ?>;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Due Dates</h3>
                        <p>Borrowed resources that need attention</p>
                    </div>
                    <a href="my_borrowed.php" class="chart-link">Manage</a>
                </div>

                <div class="rank-list">
                    <?php if (count($dueItems) > 0): ?>
                        <?php foreach ($dueItems as $index => $item): ?>
                            <?php $isOverdue = strtotime((string) $item["due_date"]) < time(); ?>
                            <div class="rank-item">
                                <span><?php echo $index + 1; ?></span>
                                <div>
                                    <strong><?php echo htmlspecialchars((string) $item["resource_name"]); ?></strong>
                                    <small><?php echo htmlspecialchars(formatDateTimeDisplay($item["due_date"] ?? "")); ?></small>
                                </div>
                                <b class="<?php echo $isOverdue ? "text-danger" : ""; ?>"><?php echo $isOverdue ? "Due" : "Soon"; ?></b>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-chart">No active due dates.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="dashboard-table-grid">
            <div class="card">
                <div class="chart-heading">
                    <div>
                        <h3 class="dashboard-card-title">Recent Requests</h3>
                        <p>Latest items and facilities you submitted</p>
                    </div>
                    <a href="my_requests.php" class="chart-link">View All</a>
                </div>

                <div class="table-wrapper">
                    <table class="request-table table-has-actions table-actions-menu-enhanced">
                        <thead>
                            <tr>
                                <th>Request No.</th>
                                <th>Resource</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentRequests) > 0): ?>
                                <?php foreach ($recentRequests as $request): ?>
                                    <?php
                                    $displayStatus = getRequestLifecycleStatus($request);
                                    $statusClass = getStatusCssClass($displayStatus);
                                    ?>
                                    <tr>
                                        <td>REQ-<?php echo str_pad((string) $request["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) $request["resource_name"]); ?></strong><br>
                                            <small><?php echo htmlspecialchars((string) $request["resource_type"]); ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                                <?php echo htmlspecialchars($displayStatus); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateTimeDisplay($request["request_date"] ?? "")); ?></td>
                                        <td>
                                            <div class="row-actions">
                                                <button
                                                    type="button"
                                                    class="row-actions-trigger"
                                                    data-row-actions-toggle
                                                    aria-expanded="false"
                                                    aria-label="Open actions for request REQ-<?php echo str_pad((string) $request["request_id"], 3, "0", STR_PAD_LEFT); ?>"
                                                >
                                                    <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                                </button>

                                                <div class="row-actions-menu" role="menu">
                                                    <a href="my_requests.php?focus_request_id=<?php echo (int) $request["request_id"]; ?>" class="row-action-item" role="menuitem">
                                                        <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                        <span>View details</span>
                                                    </a>
                                                    <?php if ($displayStatus === "Released"): ?>
                                                        <a href="return_submit.php?request_id=<?php echo (int) $request["request_id"]; ?>" class="row-action-item" role="menuitem">
                                                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                            <span>Return resource</span>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-note">No requests yet. Browse resources to start.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Fast Navigation</h3>
                        <p>Go directly to common tasks</p>
                    </div>
                </div>

                <div class="quick-actions-dropdown dashboard-quick-actions show">
                    <a href="browse.php"><i class="fa-solid fa-magnifying-glass"></i><span>Browse available resources</span></a>
                    <a href="my_requests.php"><i class="fa-solid fa-clipboard-list"></i><span>Check request progress</span></a>
                    <a href="my_borrowed.php"><i class="fa-solid fa-rotate-left"></i><span>Return borrowed resources</span></a>
                    <a href="history.php"><i class="fa-solid fa-clock-rotate-left"></i><span>Review borrowing history</span></a>
                    <a href="profile.php"><i class="fa-solid fa-user-gear"></i><span>Update profile</span></a>
                </div>
            </div>
        </section>
    </main>
</div>
