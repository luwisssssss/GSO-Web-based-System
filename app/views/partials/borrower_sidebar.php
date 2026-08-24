<?php
$current_page = basename($_SERVER["PHP_SELF"]);
?>

<aside class="sidebar" aria-label="Borrower navigation">
    <div>
        <div class="sidebar-brand">
            <img src="../public/assets/images/logo.png" alt="GSO logo">
            <div>
                <strong>GSO Borrower</strong>
                <span>Requests, returns, and issues</span>
            </div>
        </div>

        <p class="menu-label">Borrowing</p>
        <ul class="menu">
            <li class="<?php echo $current_page === "browse.php" ? "active" : ""; ?>">
                <a href="browse.php"><i class="fa-solid fa-magnifying-glass"></i> Browse Resources</a>
            </li>
            <li class="<?php echo in_array($current_page, ["my_requests.php", "request_resource.php"], true) ? "active" : ""; ?>">
                <a href="my_requests.php"><i class="fa-solid fa-clipboard-list"></i> My Requests</a>
            </li>
            <li class="<?php echo in_array($current_page, ["my_borrowed.php", "return_submit.php"], true) ? "active" : ""; ?>">
                <a href="my_borrowed.php"><i class="fa-solid fa-box-open"></i> Active Borrowings</a>
            </li>
            <li class="<?php echo $current_page === "history.php" ? "active" : ""; ?>">
                <a href="history.php"><i class="fa-solid fa-clock-rotate-left"></i> History</a>
            </li>
        </ul>

        <p class="menu-label">Issues</p>
        <ul class="menu">
            <li class="<?php echo $current_page === "report_issue.php" ? "active" : ""; ?>">
                <a href="report_issue.php"><i class="fa-solid fa-triangle-exclamation"></i> Report an Issue</a>
            </li>
            <li class="<?php echo in_array($current_page, ["my_incidents.php", "view_incident.php"], true) ? "active" : ""; ?>">
                <a href="my_incidents.php"><i class="fa-solid fa-list-check"></i> My Incident Reports</a>
            </li>
            <li class="<?php echo $current_page === "notifications.php" ? "active" : ""; ?>">
                <a href="notifications.php"><i class="fa-solid fa-bell"></i> Notifications</a>
            </li>
        </ul>
    </div>

    <div class="sidebar-bottom"></div>
</aside>
