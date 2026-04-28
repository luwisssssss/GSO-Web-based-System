<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/request_helper.php";

$pageTitle = "Dashboard";
$activePage = "dashboard";
$cssFile = "../assets/css/admin.css";

function pct(int $value, int $total): float
{
    if ($total <= 0) {
        return 0;
    }

    return round(($value / $total) * 100, 2);
}

function chartWidth(int $value, int $maxValue): string
{
    if ($maxValue <= 0) {
        return "0%";
    }

    return round(($value / $maxValue) * 100, 2) . "%";
}

$excludePastFacilityWhere = "
    NOT EXISTS (
        SELECT 1
        FROM resources active_facility_filter
        WHERE active_facility_filter.resource_id = resource_requests.resource_id
          AND active_facility_filter.resource_type = 'Facility'
          AND resource_requests.date_needed IS NOT NULL
          AND resource_requests.start_time IS NOT NULL
          AND TIMESTAMP(resource_requests.date_needed, resource_requests.start_time) <= NOW()
    )
";
$excludePastFacilityWhereForAlias = "
    NOT EXISTS (
        SELECT 1
        FROM resources active_facility_filter
        WHERE active_facility_filter.resource_id = rr.resource_id
          AND active_facility_filter.resource_type = 'Facility'
          AND rr.date_needed IS NOT NULL
          AND rr.start_time IS NOT NULL
          AND TIMESTAMP(rr.date_needed, rr.start_time) <= NOW()
    )
";

$activeRequestWhere = "
    status IN ('Pending', 'Under Review', 'Approved', 'Released')
    AND (status <> 'Released' OR return_date IS NULL)
    AND {$excludePastFacilityWhere}
";
$activeRequestWhereForAlias = "
    rr.status IN ('Pending', 'Under Review', 'Approved', 'Released')
    AND (rr.status <> 'Released' OR rr.return_date IS NULL)
    AND {$excludePastFacilityWhereForAlias}
";

$inventoryState = $pdo->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Available' THEN 1 ELSE 0 END) AS available_count,
        SUM(CASE WHEN status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_count,
        SUM(CASE WHEN status = 'Maintenance' THEN 1 ELSE 0 END) AS maintenance_count,
        SUM(CASE WHEN condition_status IN ('Damaged', 'Missing Parts', 'Needs Repair', 'Lost') THEN 1 ELSE 0 END) AS issue_count
    FROM resources
    WHERE is_archived = 0
")->fetch(PDO::FETCH_ASSOC) ?: [];

$requestPipeline = $pdo->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'Under Review' THEN 1 ELSE 0 END) AS under_review_count,
        SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE WHEN status = 'Released' THEN 1 ELSE 0 END) AS released_count
    FROM resource_requests
    WHERE {$activeRequestWhere}
")->fetch(PDO::FETCH_ASSOC) ?: [];

$urgent = [
    "pending" => (int) ($requestPipeline["pending_count"] ?? 0),
    "approved" => (int) ($requestPipeline["approved_count"] ?? 0),
    "overdue" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM resource_requests
        WHERE status = 'Released'
          AND return_date IS NULL
          AND due_date IS NOT NULL
          AND due_date < NOW()
    ")->fetchColumn(),
    "return_proofs" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM return_submissions rs
        INNER JOIN resource_requests rr ON rs.request_id = rr.request_id
        WHERE rs.status = 'Pending'
          AND rr.status = 'Released'
          AND rr.return_date IS NULL
    ")->fetchColumn(),
    "maintenance" => (int) ($inventoryState["maintenance_count"] ?? 0),
    "low_stock" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM resources
        WHERE is_archived = 0
          AND resource_type = 'Item'
          AND available_stock <= 2
    ")->fetchColumn()
];

$people = [
    "borrowers" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'Borrower'
    ")->fetchColumn(),
    "active_users" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE account_status = 'Approved'
    ")->fetchColumn()
];

$trendRows = $pdo->query("
    SELECT
        DATE_FORMAT(request_date, '%h %p') AS month_label,
        DATE_FORMAT(request_date, '%Y-%m-%d %H') AS month_key,
        COUNT(*) AS total
    FROM resource_requests
    WHERE {$activeRequestWhere}
      AND request_date >= CURDATE()
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$trendMax = max(array_merge([1], array_map(function (array $row): int {
    return (int) ($row["total"] ?? 0);
}, $trendRows)));

$mostBorrowed = $pdo->query("
    SELECT
        r.resource_name,
        r.resource_type,
        COUNT(*) AS borrow_count
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    WHERE rr.status = 'Released'
      AND rr.return_date IS NULL
      AND NOT (
            r.resource_type = 'Facility'
            AND rr.date_needed IS NOT NULL
            AND rr.start_time IS NOT NULL
            AND TIMESTAMP(rr.date_needed, rr.start_time) <= NOW()
      )
    GROUP BY r.resource_id, r.resource_name, r.resource_type
    ORDER BY borrow_count DESC, r.resource_name ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentRequestsStmt = $pdo->query("
    SELECT
        rr.request_id,
        rr.request_date,
        rr.status,
        rr.due_date,
        borrower.full_name AS borrower_name,
        borrower.username AS borrower_username,
        r.resource_name,
        r.resource_type,
        rs.inspection_condition
    FROM resource_requests rr
    INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN return_submissions rs ON rr.request_id = rs.request_id
    WHERE {$activeRequestWhereForAlias}
    ORDER BY rr.request_date DESC, rr.request_id DESC
    LIMIT 6
");
$recentRequests = $recentRequestsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentLogsStmt = $pdo->query("
    SELECT
        al.log_id,
        al.action,
        al.details,
        al.log_date,
        u.full_name,
        u.role
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.log_date DESC, al.log_id DESC
    LIMIT 6
");
$recentLogs = $recentLogsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$inventoryTotal = (int) ($inventoryState["total_count"] ?? 0);
$availablePct = pct((int) ($inventoryState["available_count"] ?? 0), $inventoryTotal);
$maintenancePct = pct((int) ($inventoryState["maintenance_count"] ?? 0), $inventoryTotal);
$issuePct = pct((int) ($inventoryState["issue_count"] ?? 0), $inventoryTotal);
$unavailablePct = max(0, 100 - $availablePct - $maintenancePct - $issuePct);

$requestTotal = (int) ($requestPipeline["total_count"] ?? 0);
$pendingPct = pct((int) ($requestPipeline["pending_count"] ?? 0), $requestTotal);
$underReviewPct = pct((int) ($requestPipeline["under_review_count"] ?? 0), $requestTotal);
$approvedPct = pct((int) ($requestPipeline["approved_count"] ?? 0), $requestTotal);
$releasedPct = pct((int) ($requestPipeline["released_count"] ?? 0), $requestTotal);

$pipelineMax = max(
    1,
    (int) ($requestPipeline["pending_count"] ?? 0),
    (int) ($requestPipeline["under_review_count"] ?? 0),
    (int) ($requestPipeline["approved_count"] ?? 0),
    (int) ($requestPipeline["released_count"] ?? 0)
);

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>
    <script>
    window.setTimeout(function () {
        if (!document.hidden) {
            window.location.reload();
        }
    }, 30000);
    </script>

    <main class="page-content dashboard-page">
        <div class="card">
            <div>
                <h2>Dashboard</h2>
                <p>Monitor live requests, availability, urgent work, and recent system activity.</p>
            </div>
        </div>

        <section class="dashboard-chart-grid">
            <div class="dashboard-chart-panel chart-main">
                <div class="chart-heading">
                    <div>
                        <h3>Resource Availability</h3>
                        <p><?php echo $inventoryTotal; ?> active resources</p>
                    </div>
                    <a href="inventory.php" class="chart-link">Inventory</a>
                </div>

                <div class="donut-row">
                    <div
                        class="donut-chart"
                        style="background: conic-gradient(
                            #16a34a 0 <?php echo $availablePct; ?>%,
                            #f97316 <?php echo $availablePct; ?>% <?php echo $availablePct + $maintenancePct; ?>%,
                            #dc2626 <?php echo $availablePct + $maintenancePct; ?>% <?php echo $availablePct + $maintenancePct + $issuePct; ?>%,
                            #64748b <?php echo $availablePct + $maintenancePct + $issuePct; ?>% 100%
                        );"
                    >
                        <div>
                            <strong><?php echo $inventoryTotal; ?></strong>
                            <span>Total</span>
                        </div>
                    </div>

                    <div class="chart-legend">
                        <span><i style="background:#16a34a"></i> Available: <?php echo (int) ($inventoryState["available_count"] ?? 0); ?></span>
                        <span><i style="background:#f97316"></i> Maintenance: <?php echo (int) ($inventoryState["maintenance_count"] ?? 0); ?></span>
                        <span><i style="background:#dc2626"></i> Damaged/Lost: <?php echo (int) ($inventoryState["issue_count"] ?? 0); ?></span>
                        <span><i style="background:#64748b"></i> Other Unavailable: <?php echo (int) ($inventoryState["unavailable_count"] ?? 0); ?></span>
                    </div>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Request Status</h3>
                        <p><?php echo $requestTotal; ?> current requests</p>
                    </div>
                    <a href="requests.php" class="chart-link">Requests</a>
                </div>

                <div
                    class="donut-chart donut-small"
                    style="background: conic-gradient(
                        #f59e0b 0 <?php echo $pendingPct; ?>%,
                        #0ea5e9 <?php echo $pendingPct; ?>% <?php echo $pendingPct + $underReviewPct; ?>%,
                        #2563eb <?php echo $pendingPct + $underReviewPct; ?>% <?php echo $pendingPct + $underReviewPct + $approvedPct; ?>%,
                        #1d4ed8 <?php echo $pendingPct + $underReviewPct + $approvedPct; ?>% <?php echo $pendingPct + $underReviewPct + $approvedPct + $releasedPct; ?>%,
                        #e2e8f0 <?php echo $pendingPct + $underReviewPct + $approvedPct + $releasedPct; ?>% 100%
                    );"
                >
                    <div>
                        <strong><?php echo $requestTotal; ?></strong>
                        <span>Total</span>
                    </div>
                </div>

                <div class="compact-legend">
                    <span><i style="background:#f59e0b"></i> Pending <?php echo (int) ($requestPipeline["pending_count"] ?? 0); ?></span>
                    <span><i style="background:#0ea5e9"></i> Under Review <?php echo (int) ($requestPipeline["under_review_count"] ?? 0); ?></span>
                    <span><i style="background:#2563eb"></i> Approved <?php echo (int) ($requestPipeline["approved_count"] ?? 0); ?></span>
                    <span><i style="background:#1d4ed8"></i> Released <?php echo (int) ($requestPipeline["released_count"] ?? 0); ?></span>
                </div>
            </div>

            <div class="dashboard-chart-panel urgent-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Needs Attention</h3>
                        <p>Pending reviews, overdue resources, returns, and maintenance</p>
                    </div>
                </div>

                <div class="urgent-grid">
                    <a href="requests.php?status=Pending"><strong><?php echo $urgent["pending"]; ?></strong><span>Pending requests</span></a>
                    <a href="requests.php?status=Approved"><strong><?php echo $urgent["approved"]; ?></strong><span>Ready to release</span></a>
                    <a href="on_loan.php?loan_state=Overdue"><strong><?php echo $urgent["overdue"]; ?></strong><span>Overdue</span></a>
                    <a href="on_loan.php"><strong><?php echo $urgent["return_proofs"]; ?></strong><span>Return proofs</span></a>
                    <a href="maintenance.php?status=In+Progress"><strong><?php echo $urgent["maintenance"]; ?></strong><span>Maintenance</span></a>
                    <a href="inventory.php"><strong><?php echo $urgent["low_stock"]; ?></strong><span>Low stock</span></a>
                </div>
            </div>
        </section>

        <section class="dashboard-chart-grid secondary">
            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Today's Requests</h3>
                        <p>Active requests submitted today</p>
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
                        <div class="empty-chart">No active requests today.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Request Pipeline</h3>
                        <p>Volume by current status</p>
                    </div>
                </div>

                <div class="chart-list compact">
                    <?php
                    $pipelineRows = [
                        ["Pending", (int) ($requestPipeline["pending_count"] ?? 0), "orange"],
                        ["Under Review", (int) ($requestPipeline["under_review_count"] ?? 0), "cyan"],
                        ["Approved", (int) ($requestPipeline["approved_count"] ?? 0), "blue"],
                        ["Released", (int) ($requestPipeline["released_count"] ?? 0), "light"]
                    ];
                    ?>
                    <?php foreach ($pipelineRows as $row): ?>
                        <div class="chart-row">
                            <div class="chart-label-row"><span><?php echo htmlspecialchars($row[0]); ?></span><strong><?php echo $row[1]; ?></strong></div>
                            <div class="chart-track"><div class="chart-fill <?php echo htmlspecialchars($row[2]); ?>" style="width: <?php echo chartWidth($row[1], $pipelineMax); ?>;"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="dashboard-chart-panel">
                <div class="chart-heading">
                    <div>
                        <h3>Currently Borrowed</h3>
                        <p>Resources still out with borrowers</p>
                    </div>
                </div>

                <div class="rank-list">
                    <?php if (count($mostBorrowed) > 0): ?>
                        <?php foreach ($mostBorrowed as $index => $row): ?>
                            <div class="rank-item">
                                <span><?php echo $index + 1; ?></span>
                                <div>
                                    <strong><?php echo htmlspecialchars((string) $row["resource_name"]); ?></strong>
                                    <small><?php echo htmlspecialchars((string) $row["resource_type"]); ?></small>
                                </div>
                                <b><?php echo (int) $row["borrow_count"]; ?></b>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-chart">No active borrowings.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="dashboard-table-grid">
            <div class="card">
                <div class="chart-heading">
                    <div>
                        <h3 class="dashboard-card-title">Recent Requests</h3>
                        <p>Latest active borrower submissions and transactions</p>
                    </div>
                    <a href="requests.php" class="chart-link">Open</a>
                </div>

                <div class="table-wrapper">
                    <table class="request-table">
                        <thead>
                            <tr>
                                <th>Request No.</th>
                                <th>Borrower</th>
                                <th>Resource</th>
                                <th>Status</th>
                                <th>Date Requested</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($recentRequests) > 0): ?>
                                <?php foreach ($recentRequests as $row): ?>
                                    <?php
                                    $displayStatus = getRequestLifecycleStatus($row);
                                    $statusClass = getStatusCssClass($displayStatus);
                                    ?>
                                    <tr>
                                        <td>REQ-<?php echo str_pad((string) $row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) $row["borrower_name"]); ?></strong><br>
                                            <small><?php echo htmlspecialchars((string) $row["borrower_username"]); ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars((string) $row["resource_name"]); ?></strong><br>
                                            <small><?php echo htmlspecialchars((string) $row["resource_type"]); ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                                <?php echo htmlspecialchars($displayStatus); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars(formatDateTimeDisplay($row["request_date"] ?? "")); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="empty-note">No active request records found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="chart-heading">
                    <div>
                        <h3 class="dashboard-card-title">Recent Activity</h3>
                        <p>Latest administrative and borrower actions</p>
                    </div>
                    <a href="activity_logs.php" class="chart-link">Logs</a>
                </div>

                <div class="activity-feed">
                    <?php if (count($recentLogs) > 0): ?>
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="activity-feed-item">
                                <span><i class="fa-solid fa-bolt"></i></span>
                                <div>
                                    <strong><?php echo htmlspecialchars((string) $log["action"]); ?></strong>
                                    <p><?php echo htmlspecialchars((string) ($log["details"] ?? "No details.")); ?></p>
                                    <small>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($log["log_date"] ?? "")); ?>
                                        <?php if (!empty($log["full_name"])): ?>
                                            - <?php echo htmlspecialchars((string) $log["full_name"]); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-chart">No activity logs found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </main>
</div>
