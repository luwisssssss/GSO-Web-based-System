<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_page_helper.php";

$borrowerId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedBorrowerAccount($pdo, $borrowerId)) {
    gsoDestroySession();
    redirectWithFlash("../auth/login.php", "Your account is not authorized.");
}
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Notifications";
$activePage = "notifications";
$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/notifications.css"]);
$notificationPageTitle = "My Notifications";
$notificationPageData = getNotificationPageData($pdo, $borrowerId, $_GET);
$notificationPageData["global_unread_count"] = getUnreadNotificationCount($pdo, $borrowerId);

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>
<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>
    <?php require dirname(__DIR__, 2) . "/views/notifications_page.php"; ?>
</div>
