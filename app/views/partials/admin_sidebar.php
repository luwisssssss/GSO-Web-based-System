<?php
$current_page = basename($_SERVER["PHP_SELF"]);
$currentRole = (string) ($_SESSION["role"] ?? "");
$isStaff = $currentRole === "GSO Staff";
$adminBase = $isStaff ? "../admin/" : "";
$dashboardPath = $isStaff ? "../gso_staff/dashboard.php" : "dashboard.php";
?>

<aside class="sidebar" aria-label="<?php echo $isStaff ? "GSO Staff" : "Admin"; ?> navigation">
    <div>
        <div class="sidebar-brand">
            <img src="../public/assets/images/logo.png" alt="GSO logo">
            <div>
                <strong><?php echo $isStaff ? "GSO Staff" : "GSO Head"; ?></strong>
                <span><?php echo $isStaff ? "Operational workspace" : "Full system access"; ?></span>
            </div>
        </div>

        <p class="menu-label">Workspace</p>
        <ul class="menu">
            <li class="<?php echo $current_page === "dashboard.php" ? "active" : ""; ?>">
                <a href="<?php echo $dashboardPath; ?>"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
            </li>
            <li class="<?php echo in_array($current_page, ["requests.php", "view_request.php"], true) ? "active" : ""; ?>">
                <a href="<?php echo $adminBase; ?>requests.php"><i class="fa-solid fa-clipboard-check"></i> Requests</a>
            </li>
            <li class="<?php echo in_array($current_page, ["on_loan.php", "review_return.php"], true) ? "active" : ""; ?>">
                <a href="<?php echo $adminBase; ?>on_loan.php"><i class="fa-solid fa-box-open"></i> Release / Return</a>
            </li>
            <?php if (!$isStaff): ?>
            <li class="<?php echo in_array($current_page, ["incidents.php", "view_incident.php"], true) ? "active" : ""; ?>">
                <a href="incidents.php"><i class="fa-solid fa-triangle-exclamation"></i> Incident Reports</a>
            </li>
            <?php endif; ?>
            <li class="<?php echo in_array($current_page, ["inventory.php", "add_resource.php", "edit_resource.php", "view_resource.php"], true) ? "active" : ""; ?>">
                <a href="<?php echo $adminBase; ?>inventory.php"><i class="fa-solid fa-boxes-stacked"></i> Inventory<?php echo $isStaff ? " (View)" : ""; ?></a>
            </li>
            <li class="<?php echo $current_page === "equipment_units.php" ? "active" : ""; ?>">
                <a href="<?php echo $adminBase; ?>equipment_units.php"><i class="fa-solid fa-barcode"></i> Equipment Units</a>
            </li>
            <li class="<?php echo $current_page === "maintenance.php" ? "active" : ""; ?>">
                <a href="<?php echo $adminBase; ?>maintenance.php"><i class="fa-solid fa-screwdriver-wrench"></i> Work Orders</a>
            </li>
        </ul>

        <?php if (!$isStaff): ?>
        <p class="menu-label">Management</p>
        <ul class="menu">
            <li class="<?php echo $current_page === "ai_knowledge.php" ? "active" : ""; ?>">
                <a href="ai_knowledge.php"><i class="fa-solid fa-robot"></i> AI Knowledge Base</a>
            </li>
            <li class="<?php echo $current_page === "notifications.php" ? "active" : ""; ?>">
                <a href="notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a>
            </li>
            <li class="<?php echo $current_page === "borrowers_accounts.php" ? "active" : ""; ?>">
                <a href="borrowers_accounts.php"><i class="fa-solid fa-users"></i> Borrower Accounts</a>
            </li>
            <li class="<?php echo $current_page === "admin_accounts.php" ? "active" : ""; ?>">
                <a href="admin_accounts.php"><i class="fa-solid fa-user-shield"></i> Admin Accounts</a>
            </li>
            <li class="<?php echo $current_page === "reports.php" ? "active" : ""; ?>">
                <a href="reports.php"><i class="fa-solid fa-chart-pie"></i> Reports</a>
            </li>
            <li class="<?php echo $current_page === "activity_logs.php" ? "active" : ""; ?>">
                <a href="activity_logs.php"><i class="fa-solid fa-list-check"></i> Activity Logs</a>
            </li>
        </ul>
        <?php endif; ?>
    </div>

    <div class="sidebar-bottom"></div>
</aside>
