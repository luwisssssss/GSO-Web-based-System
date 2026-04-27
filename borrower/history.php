<?php
require_once "../includes/borrower_check.php";
require_once "../config/db.php";
require_once "../includes/request_helper.php";

$pageTitle = "History";
$activePage = "history";

$borrower_id = (int) $_SESSION["user_id"];
$statusFilter = $_GET["status"] ?? "All";
$typeFilter = $_GET["type"] ?? "All";
$dateFrom = trim((string) ($_GET["date_from"] ?? ""));
$dateTo = trim((string) ($_GET["date_to"] ?? ""));
$search = trim($_GET["search"] ?? "");
$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$baseWhereSql = "
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN return_submissions rs ON rs.request_id = rr.request_id
    WHERE rr.borrower_id = :borrower_id
      AND rr.status IN ('Rejected', 'Cancelled', 'Returned')
";

$params = [
    ":borrower_id" => $borrower_id
];

if ($statusFilter !== "All") {
    $baseWhereSql .= " AND rr.status = :status";
    $params[":status"] = $statusFilter;
}

if ($typeFilter !== "All") {
    $baseWhereSql .= " AND r.resource_type = :resource_type";
    $params[":resource_type"] = $typeFilter;
}

if ($dateFrom !== "") {
    $baseWhereSql .= " AND DATE(rr.request_date) >= :date_from";
    $params[":date_from"] = $dateFrom;
}

if ($dateTo !== "") {
    $baseWhereSql .= " AND DATE(rr.request_date) <= :date_to";
    $params[":date_to"] = $dateTo;
}

if ($search !== "") {
    $baseWhereSql .= " AND (
        rr.request_id LIKE :search
        OR r.resource_name LIKE :search
        OR r.resource_type LIKE :search
        OR r.category LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}

$countSql = "SELECT COUNT(*) " . $baseWhereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = "
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
        rr.approved_by,
        rr.approved_at,
        rr.due_date,
        rr.return_date,
        rr.notes,
        rs.inspection_condition,
        r.resource_name,
        r.resource_type,
        r.category,
        approver.full_name AS reviewed_by_name
    " . $baseWhereSql . "
    ORDER BY rr.request_date DESC, rr.request_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$historyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter,
    "type" => $typeFilter,
    "date_from" => $dateFrom,
    "date_to" => $dateTo
];

require_once "../includes/header.php";
require_once "../includes/borrower_sidebar.php";
?>

<style>
.pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.pagination-info {
    color: #64748b;
    font-size: 14px;
}

.pagination-links {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    padding: 9px 12px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
}

.pagination-link:hover {
    background: #f8fafc;
}

.pagination-link.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.pagination-link.disabled {
    pointer-events: none;
    opacity: 0.5;
}
</style>

<div class="main-content">
    <?php require_once "../includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>History</h2>
            <p>Review completed requests, returns, cancellations, and rejected records with filters.</p>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search history or resource..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Records</option>
                    <option value="Rejected" <?php echo $statusFilter === "Rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="Cancelled" <?php echo $statusFilter === "Cancelled" ? "selected" : ""; ?>>Cancelled</option>
                    <option value="Returned" <?php echo $statusFilter === "Returned" ? "selected" : ""; ?>>Returned</option>
                </select>

                <select name="type">
                    <option value="All" <?php echo $typeFilter === "All" ? "selected" : ""; ?>>All Types</option>
                    <option value="Item" <?php echo $typeFilter === "Item" ? "selected" : ""; ?>>Items</option>
                    <option value="Facility" <?php echo $typeFilter === "Facility" ? "selected" : ""; ?>>Facilities</option>
                </select>

                <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" aria-label="Date from">
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" aria-label="Date to">

                <button type="submit" class="request-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Reference No.</th>
                            <th>Resource</th>
                            <th>Quantity</th>
                            <th>Date Requested</th>
                            <th>Date Needed</th>
                            <th>Final Status</th>
                            <th>Reviewed By</th>
                            <th>Reviewed At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($historyRows) > 0): ?>
                            <?php foreach ($historyRows as $row): ?>
                                <?php
                                $displayStatus = getRequestLifecycleStatus($row);
                                $statusClass = getStatusCssClass($displayStatus);
                                ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad($row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>

                                    <td>
                                        <strong><?php echo htmlspecialchars($row["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars($row["resource_type"]); ?>
                                            <?php if (!empty($row["category"])): ?>
                                                - <?php echo htmlspecialchars($row["category"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td><?php echo htmlspecialchars($row["quantity"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($row["request_date"] ?? "")); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($row["date_needed"] ?? "")); ?></td>

                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars($displayStatus); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo !empty($row["reviewed_by_name"]) ? htmlspecialchars($row["reviewed_by_name"]) : "N/A"; ?>
                                    </td>

                                    <td>
                                        <?php echo !empty($row["approved_at"]) ? htmlspecialchars(formatDateTimeDisplay($row["approved_at"])) : "N/A"; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-note">No history records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($historyRows); ?> of <?php echo $totalRows; ?> history record(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "history.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "history.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="history.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
                                class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>"
                            >
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i === 2 && $page > 4): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php elseif ($i === $totalPages - 1 && $page < $totalPages - 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <a href="<?php echo $nextUrl; ?>" class="pagination-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next</a>
                </div>
            </div>
        </div>
    </main>
</div>

