<?php
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
require_once dirname(__DIR__, 3) . "/config/db.php";

ensureAuthenticatedPermission("director.dashboard");
ensureApprovedPermissionAccount($pdo, "director.dashboard");

$pageTitle = "Campus Director Dashboard";
$activePage = "director-dashboard";
$cssFile = "../public/assets/css/pages/admin.css";
$showGlobalBackButton = false;
require_once dirname(__DIR__, 3) . "/includes/header.php";
?>
<div class="main-content">
    <main class="page-content">
        <div class="card">
            <h1>Campus Director</h1>
            <p>Your authenticated Director workspace is ready. Prioritisation, approval decisions, and decision history will be added in a later phase.</p>
            <a class="admin-btn primary-btn" href="../auth/logout.php">Sign out</a>
        </div>
    </main>
</div>
<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
