<?php
// Retired decision endpoint. Historical records remain in the database.
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
ensureAuthenticatedPermission("facility.schedule.view");
ensureApprovedPermissionAccount($pdo, "facility.schedule.view");
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
    http_response_code(410);
    header("Content-Type: text/plain; charset=UTF-8");
    header("Cache-Control: no-store");
    exit("Director decision actions have been retired. Facility schedules are read-only.");
}
header("Location: dashboard.php");
exit();
