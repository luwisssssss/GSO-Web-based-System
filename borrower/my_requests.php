<?php
require_once "../includes/borrower_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/request_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "My Requests";
$activePage = "requests";

$borrower_id = (int) $_SESSION["user_id"];
$cancelableRequestStatuses = ["Pending", "Under Review"];

/*
|--------------------------------------------------------------------------
| Handle cancel request
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $requestId = isset($_POST["request_id"]) ? (int) $_POST["request_id"] : 0;
    $action = $_POST["action"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        header("Location: my_requests.php");
        exit();
    }

    if ($requestId <= 0 || $action !== "cancel") {
        $_SESSION["flash_message"] = "Invalid cancel request.";
        $_SESSION["flash_type"] = "error";
        header("Location: my_requests.php");
        exit();
    }

    $checkStmt = $pdo->prepare("
        SELECT
            rr.request_id,
            rr.borrower_id,
            rr.status,
            r.resource_name
        FROM resource_requests rr
        INNER JOIN resources r ON rr.resource_id = r.resource_id
        WHERE rr.request_id = :request_id
        LIMIT 1
    ");
    $checkStmt->execute([
        ":request_id" => $requestId
    ]);

    $requestRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requestRow) {
        $_SESSION["flash_message"] = "Request not found.";
        $_SESSION["flash_type"] = "error";
        header("Location: my_requests.php");
        exit();
    }

    if ((int) $requestRow["borrower_id"] !== $borrower_id) {
        $_SESSION["flash_message"] = "Access denied.";
        $_SESSION["flash_type"] = "error";
        header("Location: my_requests.php");
        exit();
    }

    if (!in_array((string) $requestRow["status"], $cancelableRequestStatuses, true)) {
        $_SESSION["flash_message"] = "Only pending or under review requests can be cancelled.";
        $_SESSION["flash_type"] = "error";
        header("Location: my_requests.php");
        exit();
    }

    $updateStmt = $pdo->prepare("
        UPDATE resource_requests
        SET status = 'Cancelled'
        WHERE request_id = :request_id
          AND borrower_id = :borrower_id
          AND status IN (:pending_status, :under_review_status)
        LIMIT 1
    ");

    $success = $updateStmt->execute([
        ":request_id" => $requestId,
        ":borrower_id" => $borrower_id,
        ":pending_status" => "Pending",
        ":under_review_status" => "Under Review"
    ]) && $updateStmt->rowCount() > 0;

    if ($success) {
        addActivityLog(
            $pdo,
            $borrower_id,
            "Borrower Request Cancelled",
            "Borrower cancelled request #{$requestId} for resource: " . ($requestRow["resource_name"] ?? "Unknown")
        );

        $_SESSION["flash_message"] = "Request cancelled successfully.";
        $_SESSION["flash_type"] = "success";
    } else {
        $_SESSION["flash_message"] = "Failed to cancel request.";
        $_SESSION["flash_type"] = "error";
    }

    header("Location: my_requests.php");
    exit();
}

$statusFilter = $_GET["status"] ?? "All";
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
";

$params = [
    ":borrower_id" => $borrower_id
];

if ($statusFilter !== "All") {
    $baseWhereSql .= " AND rr.status = :status";
    $params[":status"] = $statusFilter;
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
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter
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
            <h2>My Requests</h2>
            <p>Track the real status of your submitted requests.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="status-tabs" aria-label="Request status filters">
            <?php foreach (["All", "Pending", "Under Review", "Approved", "Released", "Returned", "Rejected", "Cancelled"] as $statusTab): ?>
                <a
                    href="my_requests.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["status" => $statusTab, "page" => 1]))); ?>"
                    class="<?php echo strcasecmp($statusFilter, $statusTab) === 0 ? "active" : ""; ?>"
                >
                    <?php echo htmlspecialchars($statusTab); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search request or resource..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Pending" <?php echo $statusFilter === "Pending" ? "selected" : ""; ?>>Pending</option>
                    <option value="Under Review" <?php echo $statusFilter === "Under Review" ? "selected" : ""; ?>>Under Review</option>
                    <option value="Approved" <?php echo $statusFilter === "Approved" ? "selected" : ""; ?>>Approved</option>
                    <option value="Released" <?php echo $statusFilter === "Released" ? "selected" : ""; ?>>Released</option>
                    <option value="Returned" <?php echo $statusFilter === "Returned" ? "selected" : ""; ?>>Returned</option>
                    <option value="Rejected" <?php echo $statusFilter === "Rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="Cancelled" <?php echo $statusFilter === "Cancelled" ? "selected" : ""; ?>>Cancelled</option>
                </select>

                <button type="submit" class="request-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>Request No.</th>
                            <th>Resource</th>
                            <th>Quantity</th>
                            <th>Date Requested</th>
                            <th>Date Needed</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Reviewed By</th>
                            <th>Reviewed At</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($requests) > 0): ?>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                $displayStatus = getRequestLifecycleStatus($request);
                                $statusClass = getStatusCssClass($displayStatus);
                                $canCancelRequest = in_array((string) ($request["status"] ?? ""), $cancelableRequestStatuses, true);
                                ?>
                                <tr>
                                    <td>REQ-<?php echo str_pad($request["request_id"], 3, "0", STR_PAD_LEFT); ?></td>

                                    <td>
                                        <strong><?php echo htmlspecialchars($request["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars($request["resource_type"]); ?>
                                            <?php if (!empty($request["category"])): ?>
                                                - <?php echo htmlspecialchars($request["category"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td><?php echo htmlspecialchars($request["quantity"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($request["request_date"] ?? "")); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($request["date_needed"] ?? "")); ?></td>

                                    <td>
                                        <?php echo htmlspecialchars(buildTimeRangeDisplay($request["start_time"] ?? null, $request["end_time"] ?? null)); ?>
                                    </td>

                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars($displayStatus); ?>
                                        </span>
                                        <div class="ui-timeline" aria-label="Request progress">
                                            <span class="ui-timeline-step done">Submitted</span>
                                            <span class="ui-timeline-step <?php echo in_array((string) $request["status"], ["Approved", "Released", "Returned", "Rejected"], true) ? "done" : ""; ?>">Reviewed</span>
                                            <span class="ui-timeline-step <?php echo in_array((string) $request["status"], ["Released", "Returned"], true) ? "done" : ""; ?>">Released</span>
                                            <span class="ui-timeline-step <?php echo (string) $request["status"] === "Returned" ? "done" : ""; ?>">Closed</span>
                                        </div>
                                    </td>

                                    <td>
                                        <?php echo !empty($request["reviewed_by_name"]) ? htmlspecialchars($request["reviewed_by_name"]) : "N/A"; ?>
                                    </td>

                                    <td>
                                        <?php echo !empty($request["approved_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["approved_at"])) : "N/A"; ?>
                                    </td>

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
                                                <?php if ($canCancelRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Cancel this request?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="cancel">
                                                        <button type="submit" class="danger-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                                            <span>Cancel request</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="row-action-note">No action available</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="empty-note">No request records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($requests); ?> of <?php echo $totalRows; ?> request(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "my_requests.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "my_requests.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="my_requests.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
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

