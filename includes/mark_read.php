<?php
session_start();
require_once "../config/db.php";
require_once "../includes/notification_helper.php";

if (empty($_SESSION["user_id"])) {
    http_response_code(401);
    exit("Unauthorized");
}

$user_id = (int) $_SESSION['user_id'];
$notificationId = isset($_POST["notification_id"]) ? (int) $_POST["notification_id"] : 0;
$action = trim((string) ($_POST["action"] ?? ""));
$csrfToken = (string) ($_POST["csrf_token"] ?? "");

if (empty($_SESSION["csrf_token"]) || !hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
    http_response_code(403);
    exit("Invalid request token");
}

if ($action === "mark_all") {
    if (!markAllNotificationsRead($pdo, $user_id)) {
        http_response_code(400);
        exit("Failed");
    }

    echo "done";
    exit();
}

if ($notificationId <= 0) {
    http_response_code(400);
    exit("Invalid notification");
}

if (!markNotificationRead($pdo, $user_id, $notificationId)) {
    http_response_code(400);
    exit("Failed");
}

echo "done";
