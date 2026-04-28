<?php
require_once __DIR__ . "/bootstrap.php";

$host = (string) gsoEnv("GSO_DB_HOST", "localhost");
$port = (string) gsoEnv("GSO_DB_PORT", "3306");
$dbname = (string) gsoEnv("GSO_DB_NAME", "gso_database");
$username = (string) gsoEnv("GSO_DB_USERNAME", "root");
$password = (string) gsoEnv("GSO_DB_PASSWORD", "");
$dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

if ($port !== "") {
    $dsn .= ";port={$port}";
}

try {
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $e) {
    error_log("GSO database connection failed: " . $e->getMessage());

    if (gsoEnvBool("GSO_APP_DEBUG", false)) {
        die("Database connection failed: " . $e->getMessage());
    }

    die("Database connection failed. Check your environment configuration.");
}

require_once __DIR__ . "/../includes/schema_helper.php";
require_once __DIR__ . "/../includes/maintenance_helper.php";

$runMigrations = gsoEnv("GSO_RUN_MIGRATIONS");

try {
    if ($runMigrations !== "0") {
        ensureSystemSchema($pdo);
    }

    syncMaintenanceSchedules($pdo);
} catch (Throwable $e) {
    error_log("GSO database initialization failed: " . $e->getMessage());

    if (gsoEnvBool("GSO_APP_DEBUG", false)) {
        die("Database initialization failed: " . $e->getMessage());
    }

    die("System initialization failed. Please check the database schema and configuration.");
}
?>
