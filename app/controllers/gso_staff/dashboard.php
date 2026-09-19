<?php
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
require_once dirname(__DIR__, 3) . "/config/db.php";

ensureAuthenticatedPermission("staff.dashboard");
ensureApprovedPermissionAccount($pdo, "staff.dashboard");

$pageTitle = "GSO Staff Dashboard";
$activePage = "dashboard";
$cssFile = "../public/assets/css/pages/admin.css";
$showGlobalBackButton = false;

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>
<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>
    <main class="page-content">
        <div class="card">
            <h2>Operational Dashboard</h2>
            <p>Process ordinary requests, release resources, inspect returns, view inventory, and manage work orders.</p>
        </div>
        <div class="admin-grid">
            <a class="summary-card" href="../admin/requests.php"><h3>Requests</h3><p>Review the operational queue</p></a>
            <a class="summary-card" href="../admin/on_loan.php"><h3>Release / Return</h3><p>Manage active transactions</p></a>
            <a class="summary-card" href="../admin/inventory.php"><h3>Inventory</h3><p>View operational stock</p></a>
            <a class="summary-card" href="../admin/maintenance.php"><h3>Work Orders</h3><p>Manage maintenance work</p></a>
        </div>
    </main>
</div>
<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
