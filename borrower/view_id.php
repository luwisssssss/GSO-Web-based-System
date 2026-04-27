<?php
require_once "../includes/auth_check.php";
require_once "../config/db.php";

if (!isset($_GET["user_id"]) || empty($_GET["user_id"])) {
    die("User ID is required.");
}

$requested_user_id = (int) $_GET["user_id"];
$logged_in_user_id = (int) $_SESSION["user_id"];
$logged_in_role = $_SESSION["role"] ?? "";

// Allow access only to:
// 1. the owner of the file
// 2. admin
if ($requested_user_id !== $logged_in_user_id && $logged_in_role !== "Admin") {
    die("Access denied.");
}

$stmt = $pdo->prepare("
    SELECT uploaded_id, uploaded_id_type
    FROM users
    WHERE user_id = :user_id
    LIMIT 1
");
$stmt->execute([
    ":user_id" => $requested_user_id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("User not found.");
}

if (empty($user["uploaded_id"]) || empty($user["uploaded_id_type"])) {
    die("No uploaded ID found.");
}

$allowedTypes = ["image/jpeg", "image/png", "application/pdf"];

if (!in_array($user["uploaded_id_type"], $allowedTypes)) {
    die("Invalid file type.");
}

header("Content-Type: " . $user["uploaded_id_type"]);
echo $user["uploaded_id"];
exit();
?>