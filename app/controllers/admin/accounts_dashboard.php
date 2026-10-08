<?php
define("GSO_REQUIRED_PERMISSION", "accounts.manage");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
$pageTitle = "MIS Account Management";
$activePage = "accounts_dashboard";
$cssFile = "../public/assets/css/pages/admin.css";
$showGlobalBackButton = false;
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>
<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>
    <main class="page-content">
        <section class="card"><h1>MIS Account Management</h1><p>Manage system access for the GSO system.</p></section>
        <div class="admin-grid">
            <a class="summary-card" href="admin_accounts.php"><h2>System Accounts</h2><p>MIS, GSO Head, GSO Staff and Director accounts</p></a>
            <a class="summary-card" href="borrowers_accounts.php"><h2>Borrower Accounts</h2><p>Review and manage borrower access</p></a>
        </div>
    </main>
</div>
<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
