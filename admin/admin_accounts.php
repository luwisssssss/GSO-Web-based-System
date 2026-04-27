<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Admin Accounts";
$activePage = "admin_accounts";
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
        header("Location: admin_accounts.php");
        exit();
    }

    if ($action === "create") {
        $fullName = trim((string) ($_POST["full_name"] ?? ""));
        $department = trim((string) ($_POST["department"] ?? ""));
        $universityId = trim((string) ($_POST["university_id"] ?? ""));
        $email = trim((string) ($_POST["email"] ?? ""));
        $username = trim((string) ($_POST["username"] ?? ""));
        $password = (string) ($_POST["password"] ?? "");

        if ($fullName === "" || $email === "" || $username === "" || $password === "") {
            $_SESSION["flash_message"] = "Full name, email, username, and password are required.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_accounts.php");
            exit();
        }

        if (strlen($password) < 8) {
            $_SESSION["flash_message"] = "Admin password must be at least 8 characters long.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_accounts.php");
            exit();
        }

        $duplicateStmt = $pdo->prepare("
            SELECT user_id
            FROM users
            WHERE email = :email
               OR username = :username
               OR (:has_university_id = 1 AND university_id = :university_id)
            LIMIT 1
        ");
        $duplicateStmt->execute([
            ":email" => $email,
            ":username" => $username,
            ":has_university_id" => $universityId !== "" ? 1 : 0,
            ":university_id" => $universityId
        ]);

        if ($duplicateStmt->fetch()) {
            $_SESSION["flash_message"] = "Email, username, or university ID already exists.";
            $_SESSION["flash_type"] = "error";
            header("Location: admin_accounts.php");
            exit();
        }

        $insertStmt = $pdo->prepare("
            INSERT INTO users
            (
                full_name,
                department,
                university_id,
                email,
                role,
                username,
                password_hash,
                account_status,
                approved_by,
                approved_at,
                email_verified
            )
            VALUES
            (
                :full_name,
                :department,
                :university_id,
                :email,
                'Admin',
                :username,
                :password_hash,
                'Approved',
                :approved_by,
                NOW(),
                1
            )
        ");

        $success = $insertStmt->execute([
            ":full_name" => $fullName,
            ":department" => $department !== "" ? $department : null,
            ":university_id" => $universityId !== "" ? $universityId : null,
            ":email" => $email,
            ":username" => $username,
            ":password_hash" => password_hash($password, PASSWORD_DEFAULT),
            ":approved_by" => $adminUserId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Admin Account Created",
                "Admin created admin account: " . $fullName
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Admin account created successfully."
            : "Failed to create admin account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        header("Location: admin_accounts.php");
        exit();
    }

    if ($targetUserId <= 0) {
        $_SESSION["flash_message"] = "Invalid admin account action.";
        $_SESSION["flash_type"] = "error";
        header("Location: admin_accounts.php");
        exit();
    }

    if ($targetUserId === $adminUserId) {
        $_SESSION["flash_message"] = "You cannot change your own admin account status here.";
        $_SESSION["flash_type"] = "error";
        header("Location: admin_accounts.php");
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

    if (!$targetUser || $targetUser["role"] !== "Admin") {
        $_SESSION["flash_message"] = "Admin account not found.";
        $_SESSION["flash_type"] = "error";
        header("Location: admin_accounts.php");
        exit();
    }

    if ($action === "enable") {
        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                account_status = 'Approved',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE user_id = :user_id
              AND role = 'Admin'
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
                "Admin Account Enabled",
                "Admin enabled admin account: " . ($targetUser["full_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Admin account enabled successfully."
            : "Failed to enable admin account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: admin_accounts.php");
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
              AND role = 'Admin'
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
                "Admin Account Disabled",
                "Admin disabled admin account: " . ($targetUser["full_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Admin account disabled successfully."
            : "Failed to disable admin account.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: admin_accounts.php");
        exit();
    }

    $_SESSION["flash_message"] = "Unknown action.";
    $_SESSION["flash_type"] = "error";
    header("Location: admin_accounts.php");
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
    WHERE u.role = 'Admin'
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
            WHEN u.account_status = 'Approved' THEN 1
            WHEN u.account_status = 'Pending' THEN 2
            WHEN u.account_status = 'Rejected' THEN 3
            WHEN u.account_status = 'Disabled' THEN 4
            ELSE 5
        END,
        u.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            <h2>Admin Accounts</h2>
            <p>View, create, enable, disable, and monitor administrator access.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="page-header-row">
                <div>
                    <h3>Add Admin Account</h3>
                    <p>Create an approved administrator account for system management.</p>
                </div>
            </div>

            <form method="POST" class="request-form admin-account-create-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="create">

                <div class="form-row">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required>
                </div>

                <div class="form-row">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>

                <div class="form-row">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required>
                </div>

                <div class="form-row">
                    <label for="password">Temporary Password</label>
                    <input type="password" id="password" name="password" minlength="8" required>
                    <small class="form-help">Minimum 8 characters. Admin can change this after login.</small>
                </div>

                <div class="form-row">
                    <label for="department">Department</label>
                    <input type="text" id="department" name="department">
                </div>

                <div class="form-row">
                    <label for="university_id">University ID</label>
                    <input type="text" id="university_id" name="university_id">
                </div>

                <div class="profile-actions">
                    <button type="submit" class="admin-btn primary-btn">Create Admin</button>
                </div>
            </form>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search admin..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Approved" <?php echo $statusFilter === "Approved" ? "selected" : ""; ?>>Approved</option>
                    <option value="Pending" <?php echo $statusFilter === "Pending" ? "selected" : ""; ?>>Pending</option>
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
                        <?php if (count($admins) > 0): ?>
                            <?php foreach ($admins as $admin): ?>
                                <?php $statusClass = strtolower($admin["account_status"]); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin["user_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($admin["full_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($admin["university_id"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars($admin["email"]); ?></td>
                                    <td><?php echo htmlspecialchars($admin["username"]); ?></td>
                                    <td><?php echo htmlspecialchars($admin["department"] ?? "N/A"); ?></td>
                                    <td><?php echo htmlspecialchars($admin["uploaded_id_type"] ?? "N/A"); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars($admin["account_status"]); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo !empty($admin["reviewed_by_name"]) ? htmlspecialchars($admin["reviewed_by_name"]) : "N/A"; ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($admin["approved_at"] ?? "")); ?>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($admin["created_at"] ?? "")); ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for admin <?php echo htmlspecialchars($admin["full_name"]); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <a
                                                    href="../borrower/view_id.php?user_id=<?php echo $admin["user_id"]; ?>"
                                                    target="_blank"
                                                    class="row-action-item"
                                                    role="menuitem"
                                                >
                                                    <i class="fa-solid fa-id-card" aria-hidden="true"></i>
                                                    <span>View ID</span>
                                                </a>

                                                <?php if ((int)$admin["user_id"] === (int)$_SESSION["user_id"]): ?>
                                                    <span class="row-action-note">Current account</span>
                                                <?php else: ?>
                                                    <?php if ($admin["account_status"] !== "Approved"): ?>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Enable this admin account?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($admin["user_id"]); ?>">
                                                            <input type="hidden" name="action" value="enable">
                                                            <button type="submit" class="success-action" role="menuitem">
                                                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                                <span>Enable</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>

                                                    <?php if ($admin["account_status"] !== "Disabled"): ?>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Disable this admin account?');">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($admin["user_id"]); ?>">
                                                            <input type="hidden" name="action" value="disable">
                                                            <button type="submit" class="warning-action" role="menuitem">
                                                                <i class="fa-solid fa-user-slash" aria-hidden="true"></i>
                                                                <span>Disable</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="empty-note">No admin accounts found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($admins); ?> of <?php echo $totalRows; ?> admin account(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "admin_accounts.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "admin_accounts.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="admin_accounts.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
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

