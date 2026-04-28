<?php
require_once "../config/bootstrap.php";
gsoSecureSessionStart();
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";

if (!empty($_SESSION["user_id"])) {
    $userId = (int) $_SESSION["user_id"];
    $role = $_SESSION["role"] ?? "";

    addActivityLog(
        $pdo,
        $userId,
        "Logout Success",
        "User logged out successfully" . ($role !== "" ? " as {$role}" : "") . "."
    );
}

gsoDestroySession();

header("Location: ../auth/login.php");
exit();
