<?php
$current_page = basename($_SERVER["PHP_SELF"]);
?>

<aside class="sidebar" aria-label="Admin navigation">
    <div>
        <div class="sidebar-brand">
            <img src="../assets/css/img/logo.png" alt="GSO logo">
            <div>
                <strong>GSO Admin</strong>
                <span>Resource operations</span>
            </div>
        </div>

        <p class="menu-label">Workspace</p>
        <ul class="menu">
            <li class="<?php echo $current_page === "dashboard.php" ? "active" : ""; ?>">
                <a href="dashboard.php"><i class="fa-solid fa-chart-line"></i> Dashboard</a>
            </li>
            <li class="<?php echo in_array($current_page, ["requests.php", "view_request.php"], true) ? "active" : ""; ?>">
                <a href="requests.php"><i class="fa-solid fa-clipboard-check"></i> Request Management</a>
            </li>
            <li class="<?php echo in_array($current_page, ["on_loan.php", "review_return.php"], true) ? "active" : ""; ?>">
                <a href="on_loan.php"><i class="fa-solid fa-box-open"></i> Active Borrowings</a>
            </li>
            <li class="<?php echo in_array($current_page, ["inventory.php", "add_resource.php", "edit_resource.php", "view_resource.php"], true) ? "active" : ""; ?>">
                <a href="inventory.php"><i class="fa-solid fa-boxes-stacked"></i> Inventory</a>
            </li>
            <li class="<?php echo $current_page === "maintenance.php" ? "active" : ""; ?>">
                <a href="maintenance.php"><i class="fa-solid fa-screwdriver-wrench"></i> Maintenance</a>
            </li>
        </ul>

        <p class="menu-label">Management</p>
        <ul class="menu">
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
    </div>

    <div class="sidebar-bottom"></div>
</aside>
