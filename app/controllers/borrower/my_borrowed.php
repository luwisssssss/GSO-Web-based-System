<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/overdue_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";

$pageTitle = "Active Borrowings";
$activePage = "borrowed";

$borrower_id = (int) $_SESSION["user_id"];
$search = trim($_GET["search"] ?? "");
$loanStateFilter = trim((string) ($_GET["loan_state"] ?? "All"));
if ($loanStateFilter === "On Loan") {
    $loanStateFilter = "Borrowed";
}
$focusRequestId = isset($_GET["focus_request_id"]) ? (int) $_GET["focus_request_id"] : 0;

$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

ensureBorrowerOverdueNotifications($pdo, $borrower_id);

$baseWhereSql = "
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN return_submissions rs ON rs.request_id = rr.request_id
    WHERE rr.borrower_id = :borrower_id
      AND rr.status = 'Released'
      AND NOT (
            r.resource_type = 'Facility'
            AND rr.date_needed IS NOT NULL
            AND rr.start_time IS NOT NULL
            AND TIMESTAMP(rr.date_needed, rr.start_time) <= NOW()
      )
";

$params = [
    ":borrower_id" => $borrower_id
];

if ($search !== "") {
    $baseWhereSql .= " AND (
        rr.request_id LIKE :search
        OR r.resource_name LIKE :search
        OR r.resource_type LIKE :search
        OR r.category LIKE :search
        OR r.location LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}

if ($loanStateFilter === "Overdue") {
    $baseWhereSql .= " AND rr.due_date IS NOT NULL AND rr.due_date < NOW()";
} elseif ($loanStateFilter === "Borrowed") {
    $baseWhereSql .= " AND (rr.due_date IS NULL OR rr.due_date >= NOW())";
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
        rr.quantity,
        rr.request_date,
        rr.date_needed,
        rr.start_time,
        rr.end_time,
        rr.status,
        rr.approved_at,
        rr.due_date,
        rr.notes,
        r.resource_name,
        r.resource_type,
        r.category,
        r.location,
        approver.full_name AS reviewed_by_name,
        rs.status AS return_status
    " . $baseWhereSql . "
    ORDER BY
        CASE
            WHEN rr.due_date IS NOT NULL AND rr.due_date < NOW() THEN 1
            ELSE 2
        END,
        rr.due_date ASC,
        rr.request_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$borrowedRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$loanStats = [
    "active" => count($borrowedRows),
    "overdue" => 0,
    "submitted" => 0
];

foreach ($borrowedRows as $loanStatRow) {
    if (getRequestLifecycleStatus($loanStatRow) === "Overdue") {
        $loanStats["overdue"]++;
    }

    if (($loanStatRow["return_status"] ?? "") === "Pending") {
        $loanStats["submitted"]++;
    }
}

$queryBase = [
    "search" => $search,
    "loan_state" => $loanStateFilter
];

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/borrower-my-borrowed.css"]);
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>



<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Active Borrowings</h2>
            <p>Track borrowed resources, due dates, overdue items, and return progress in one place.</p>
        </div>

        <div class="ui-metric-strip">
            <div class="ui-metric">
                <span>Active</span>
                <strong><?php echo (int) $loanStats["active"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Overdue</span>
                <strong><?php echo (int) $loanStats["overdue"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Returns Submitted</span>
                <strong><?php echo (int) $loanStats["submitted"]; ?></strong>
            </div>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search borrowed resource..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="loan_state">
                    <option value="All" <?php echo $loanStateFilter === "All" ? "selected" : ""; ?>>All Loan States</option>
                    <option value="Borrowed" <?php echo $loanStateFilter === "Borrowed" ? "selected" : ""; ?>>Borrowed</option>
                    <option value="Overdue" <?php echo $loanStateFilter === "Overdue" ? "selected" : ""; ?>>Overdue</option>
                </select>

                <button type="submit" class="request-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>Borrow No.</th>
                            <th>Resource</th>
                            <th>Quantity</th>
                            <th>Released At</th>
                            <th>Due Date</th>
                            <th>Loan State</th>
                            <th>Reviewed By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($borrowedRows) > 0): ?>
                            <?php foreach ($borrowedRows as $row): ?>
                                <?php
                                $rid = (int) $row["request_id"];
                                $displayStatus = getRequestLifecycleStatus($row);
                                $loanStateLabel = $displayStatus === "Overdue" ? "Overdue" : "Borrowed";
                                $loanStateClass = $displayStatus === "Overdue" ? "overdue" : "borrowed";

                                $returnStatus = (string) ($row["return_status"] ?? "");
                                $rowClass = ($focusRequestId === $rid) ? "row-focus" : "";
                                ?>
                                <tr class="<?php echo $rowClass; ?>">
                                    <td>BRW-<?php echo str_pad((string) $rid, 3, "0", STR_PAD_LEFT); ?></td>

                                    <td>
                                        <strong><?php echo htmlspecialchars($row["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars($row["resource_type"]); ?>
                                            <?php if (!empty($row["category"])): ?>
                                                - <?php echo htmlspecialchars($row["category"]); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($row["location"])): ?>
                                                - <?php echo htmlspecialchars($row["location"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td><?php echo htmlspecialchars($row["quantity"] ?? "N/A"); ?></td>
                                    <td><?php echo !empty($row["approved_at"]) ? htmlspecialchars(formatDateTimeDisplay($row["approved_at"])) : "N/A"; ?></td>
                                    <td><?php echo !empty($row["due_date"]) ? date("M d, Y h:i A", strtotime($row["due_date"])) : "N/A"; ?></td>

                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($loanStateClass); ?>">
                                            <?php echo htmlspecialchars($loanStateLabel); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <?php echo !empty($row["reviewed_by_name"]) ? htmlspecialchars($row["reviewed_by_name"]) : "N/A"; ?>
                                    </td>

                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for borrow record BRW-<?php echo str_pad((string) $rid, 3, "0", STR_PAD_LEFT); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <?php if ($returnStatus === "Pending"): ?>
                                                    <span class="row-action-note">Return proof submitted</span>
                                                <?php else: ?>
                                                    <a class="row-action-item" href="return_submit.php?request_id=<?php echo $rid; ?>" role="menuitem">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                        <span><?php echo $returnStatus === "Rejected" ? "Resubmit return" : "Return resource"; ?></span>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-note">No borrowed records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($borrowedRows); ?> of <?php echo $totalRows; ?> borrowed record(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "my_borrowed.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "my_borrowed.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? "disabled" : ""; ?>">
                        Previous
                    </a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="my_borrowed.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
                                class="pagination-link <?php echo $i === $page ? "active" : ""; ?>"
                            >
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i === 2 && $page > 4): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php elseif ($i === $totalPages - 1 && $page < $totalPages - 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <a href="<?php echo $nextUrl; ?>" class="pagination-link <?php echo $page >= $totalPages ? "disabled" : ""; ?>">
                        Next
                    </a>
                </div>
            </div>
        </div>
    </main>
</div>
