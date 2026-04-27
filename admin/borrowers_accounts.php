<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Borrowers Accounts";
$activePage = "borrowers_accounts";
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

/*
|--------------------------------------------------------------------------
| Handle account actions
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $targetUserId = isset($_POST["user_id"]) ? (int) $_POST["user_id"] : 0;
    $action = $_POST["action"] ?? "";
    $adminUserId = (int) $_SESSION["user_id"];

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        header("Location: borrowers_accounts.php");
        exit();
    }

    if ($targetUserId <= 0) {
        $_SESSION["flash_message"] = "Invalid borrower account action.";
        $_SESSION["flash_type"] = "error";
        header("Location: borrowers_accounts.php");
        exit();
    }

    $checkStmt = $pdo->prepare("
        SELECT user_id, full_name, role, account_status
        FROM users
        WHERE user_id = :user_id
        LIMIT 1
    ");
    $checkStmt->execute([
        ":user_id" => $targetUserId
    ]);

    $targetUser = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser || $targetUser["role"] !== "Borrower") {
        $_SESSION["flash_message"] = "Borrower account not found.";
        $_SESSION["flash_type"] = "error";
        header("Location: borrowers_accounts.php");
        exit();
    }

    if ($action === "approve") {
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                account_status = 'Approved',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE user_id = :user_id
              AND role = 'Borrower'
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":approved_by" => $adminUserId,
            ":user_id" => $targetUserId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Borrower Account Approved",
                "Admin approved borrower account: " . ($targetUser["full_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Borrower account approved successfully."
            : "Failed to approve borrower account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: borrowers_accounts.php");
        exit();
    }

    if ($action === "reject") {
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                account_status = 'Rejected',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE user_id = :user_id
              AND role = 'Borrower'
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":approved_by" => $adminUserId,
            ":user_id" => $targetUserId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Borrower Account Rejected",
                "Admin rejected borrower account: " . ($targetUser["full_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Borrower account rejected successfully."
            : "Failed to reject borrower account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: borrowers_accounts.php");
        exit();
    }

    if ($action === "disable") {
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                account_status = 'Disabled',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE user_id = :user_id
              AND role = 'Borrower'
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":approved_by" => $adminUserId,
            ":user_id" => $targetUserId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Borrower Account Disabled",
                "Admin disabled borrower account: " . ($targetUser["full_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Borrower account disabled successfully."
            : "Failed to disable borrower account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: borrowers_accounts.php");
        exit();
    }

    $_SESSION["flash_message"] = "Unknown action.";
    $_SESSION["flash_type"] = "error";
    header("Location: borrowers_accounts.php");
    exit();
}

$search = trim($_GET["search"] ?? "");
$statusFilter = $_GET["status"] ?? "All";
$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$baseWhereSql = "
    FROM users u
    LEFT JOIN users approver ON u.approved_by = approver.user_id
    WHERE u.role = 'Borrower'
";

$params = [];

if ($statusFilter !== "All") {
    $baseWhereSql .= " AND u.account_status = :status";
    $params[":status"] = $statusFilter;
}

if ($search !== "") {
    $baseWhereSql .= " AND (
        u.full_name LIKE :search
        OR u.email LIKE :search
        OR u.username LIKE :search
        OR u.university_id LIKE :search
        OR u.department LIKE :search
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
        u.user_id,
        u.full_name,
        u.department,
        u.university_id,
        u.email,
        u.uploaded_id_type,
        u.role,
        u.username,
        u.account_status,
        u.approved_by,
        u.approved_at,
        u.created_at,
        approver.full_name AS reviewed_by_name
    " . $baseWhereSql . "
    ORDER BY
        CASE
            WHEN u.account_status = 'Pending' THEN 1
            WHEN u.account_status = 'Approved' THEN 2
            WHEN u.account_status = 'Rejected' THEN 3
            WHEN u.account_status = 'Disabled' THEN 4
            ELSE 5
        END,
        u.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$borrowers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter
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
            <h2>Borrowers Accounts</h2>
            <p>Manage borrower accounts from the real <code>users</code> table.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search borrower..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Pending" <?php echo $statusFilter === "Pending" ? "selected" : ""; ?>>Pending</option>
                    <option value="Approved" <?php echo $statusFilter === "Approved" ? "selected" : ""; ?>>Approved</option>
                    <option value="Rejected" <?php echo $statusFilter === "Rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="Disabled" <?php echo $statusFilter === "Disabled" ? "selected" : ""; ?>>Disabled</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>User ID</th>
                            <th>Full Name</th>
                            <th>University ID</th>
                            <th>Email</th>
                            <th>Username</th>
                            <th>Department</th>
                            <th>ID Type</th>
                            <th>Status</th>
                            <th>Reviewed By</th>
                            <th>Reviewed At</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($borrowers) > 0): ?>
                            <?php foreach ($borrowers as $borrower): ?>
                                <?php $statusClass = strtolower($borrower["account_status"]); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($borrower["user_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["full_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["university_id"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["email"]); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["username"]); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["department"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars($borrower["uploaded_id_type"] ?? "N/A"); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars($borrower["account_status"]); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo !empty($borrower["reviewed_by_name"]) ? htmlspecialchars($borrower["reviewed_by_name"]) : "N/A"; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($borrower["approved_at"] ?? "")); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($borrower["created_at"] ?? "")); ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for borrower <?php echo htmlspecialchars($borrower["full_name"]); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <a
                                                    href="../borrower/view_id.php?user_id=<?php echo $borrower["user_id"]; ?>"
                                                    target="_blank"
                                                    class="row-action-item"
                                                    role="menuitem"
                                                >
                                                    <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                                                    <span>View ID</span>
                                                </a>

                                                <?php if ($borrower["account_status"] !== "Approved"): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Approve this borrower account?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($borrower["user_id"]); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="success-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                            <span>Approve</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($borrower["account_status"] !== "Rejected"): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Reject this borrower account?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($borrower["user_id"]); ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="danger-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                                            <span>Reject</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($borrower["account_status"] !== "Disabled"): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Disable this borrower account?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($borrower["user_id"]); ?>">
                                                        <input type="hidden" name="action" value="disable">
                                                        <button type="submit" class="warning-action" role="menuitem">
                                                            <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                                                            <span>Disable</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="empty-note">No borrower accounts found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($borrowers); ?> of <?php echo $totalRows; ?> borrower account(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "borrowers_accounts.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "borrowers_accounts.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="borrowers_accounts.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
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

