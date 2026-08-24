<?php

return [
    "public" => [
        "/" => "index.php",
        "/pages/landing.php" => "app/controllers/pages/landing.php",
    ],
    "auth" => [
        "/auth/login.php" => "app/controllers/auth/login.php",
        "/auth/signup.php" => "app/controllers/auth/signup.php",
        "/auth/forgot_password.php" => "app/controllers/auth/forgot_password.php",
        "/auth/reset_password.php" => "app/controllers/auth/reset_password.php",
        "/auth/verify.php" => "app/controllers/auth/verify.php",
        "/auth/logout.php" => "app/controllers/auth/logout.php",
    ],
    "admin" => [
        "/admin/dashboard.php" => "app/controllers/admin/dashboard.php",
        "/admin/inventory.php" => "app/controllers/admin/inventory.php",
        "/admin/requests.php" => "app/controllers/admin/requests.php",
        "/admin/on_loan.php" => "app/controllers/admin/on_loan.php",
        "/admin/reports.php" => "app/controllers/admin/reports.php",
        "/admin/maintenance.php" => "app/controllers/admin/maintenance.php",
        "/admin/activity_logs.php" => "app/controllers/admin/activity_logs.php",
        "/admin/incidents.php" => "app/controllers/admin/incidents.php",
        "/admin/view_incident.php" => "app/controllers/admin/view_incident.php",
        "/admin/incident_photo.php" => "app/controllers/admin/incident_photo.php",
        "/admin/return_photo.php" => "app/controllers/admin/return_photo.php",
        "/admin/notifications.php" => "app/controllers/admin/notifications.php",
    ],
    "borrower" => [
        "/borrower/browse.php" => "app/controllers/borrower/browse.php",
        "/borrower/my_requests.php" => "app/controllers/borrower/my_requests.php",
        "/borrower/my_borrowed.php" => "app/controllers/borrower/my_borrowed.php",
        "/borrower/history.php" => "app/controllers/borrower/history.php",
        "/borrower/report_issue.php" => "app/controllers/borrower/report_issue.php",
        "/borrower/my_incidents.php" => "app/controllers/borrower/my_incidents.php",
        "/borrower/view_incident.php" => "app/controllers/borrower/view_incident.php",
        "/borrower/incident_photo.php" => "app/controllers/borrower/incident_photo.php",
        "/borrower/notifications.php" => "app/controllers/borrower/notifications.php",
    ],
];
