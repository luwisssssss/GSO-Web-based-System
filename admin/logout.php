<?php
session_start();
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

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        "",
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

session_destroy();

header("Location: ../auth/login.php");
exit();