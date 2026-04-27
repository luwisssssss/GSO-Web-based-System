<?php
$host = "localhost";
$dbname = "gso_database";
$username = "root";
$password = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

require_once __DIR__ . "/../includes/schema_helper.php";
require_once __DIR__ . "/../includes/maintenance_helper.php";

$runMigrations = getenv("GSO_RUN_MIGRATIONS");
if ($runMigrations !== "0") {
    ensureSystemSchema($pdo);
}

syncMaintenanceSchedules($pdo);
?>
