<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";

$pageTitle = "Activity Logs";
$activePage = "activity_logs";
$cssFile = "../assets/css/admin.css";

/**
 * Format date/time like: Oct 23, 2026 08:28 PM
 * If the value only contains a date, it will show: Oct 23, 2026
 */
function formatDateTimeDisplay($value)
{
    if (empty($value)) {
        return "N/A";
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return "N/A";
    }

    if (strpos($value, ":") !== false) {
        return date("M d, Y h:i A", $timestamp);
    }

    return date("M d, Y", $timestamp);
}

$search = trim($_GET["search"] ?? "");
$actionFilter = $_GET["action"] ?? "All";
$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 15;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Load action filter values
|--------------------------------------------------------------------------
*/
$actionStmt = $pdo->query("
    SELECT DISTINCT action
    FROM activity_logs
    WHERE action IS NOT NULL AND action <> ''
    ORDER BY action ASC
");
$actionOptions = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

$logSummary = [
    "today" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM activity_logs
        WHERE DATE(log_date) = CURDATE()
    ")->fetchColumn(),
    "week" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM activity_logs
        WHERE log_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ")->fetchColumn(),
    "account" => (int) $pdo->query("
        SELECT COUNT(*)
        FROM activity_logs
        WHERE action LIKE '%Account%'
    ")->fetchColumn()
];

/*
|--------------------------------------------------------------------------
| Base WHERE for count + list
|--------------------------------------------------------------------------
*/
$baseWhereSql = "
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    WHERE 1=1
";

$params = [];

if ($actionFilter !== "All") {
    $baseWhereSql .= " AND al.action = :action";
    $params[":action"] = $actionFilter;
}

if ($search !== "") {
    $baseWhereSql .= " AND (
        al.action LIKE :search
        OR al.details LIKE :search
        OR u.full_name LIKE :search
        OR u.username LIKE :search
        OR u.role LIKE :search
        OR al.log_id LIKE :search
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
        al.log_id,
        al.user_id,
        al.action,
        al.details,
        al.log_date,
        u.full_name,
        u.username,
        u.role
    " . $baseWhereSql . "
    ORDER BY al.log_date DESC, al.log_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$queryBase = [
    "search" => $search,
    "action" => $actionFilter
];

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
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
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Activity Logs</h2>
            <p>Search and audit account, resource, request, return, maintenance, and report activity.</p>
        </div>

        <div class="ui-metric-strip">
            <div class="ui-metric">
                <span>Today</span>
                <strong><?php echo (int) $logSummary["today"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Last 7 Days</span>
                <strong><?php echo (int) $logSummary["week"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Account Actions</span>
                <strong><?php echo (int) $logSummary["account"]; ?></strong>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search log, action, details, or actor..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="action">
                    <option value="All" <?php echo $actionFilter === "All" ? "selected" : ""; ?>>All Actions</option>
                    <?php foreach ($actionOptions as $action): ?>
                        <option value="<?php echo htmlspecialchars($action); ?>" <?php echo $actionFilter === $action ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($action); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="admin-btn primary-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Log ID</th>
                            <th>Date / Time</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th>Actor</th>
                            <th>Role</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($logs) > 0): ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log["log_id"]); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($log["log_date"] ?? "")); ?></td>
                                    <td>
                                        <span class="activity-badge">
                                            <?php echo htmlspecialchars($log["action"]); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log["details"] ?? "N/A"); ?></td>
                                    <td>
                                        <?php if (!empty($log["full_name"])): ?>
                                            <strong><?php echo htmlspecialchars($log["full_name"]); ?></strong><br>
                                            <small><?php echo htmlspecialchars($log["username"] ?? ""); ?></small>
                                        <?php else: ?>
                                            <span class="empty-note">System / Unknown</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo !empty($log["role"]) ? htmlspecialchars($log["role"]) : "N/A"; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="empty-note">No activity logs found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($logs); ?> of <?php echo $totalRows; ?> log record(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "activity_logs.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "activity_logs.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="activity_logs.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
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

