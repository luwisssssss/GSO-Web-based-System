<?php
require_once __DIR__ . "/../config/bootstrap.php";

gsoSecureSessionStart();

if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit();
}
?>
