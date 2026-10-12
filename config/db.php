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
    $message = gsoSafeApplicationError($e, "database connection", "Database connection failed. Please contact the administrator.");
    if (PHP_SAPI !== "cli") http_response_code(503);
    exit($message);
}

require_once __DIR__ . "/../includes/schema_helper.php";
require_once __DIR__ . "/../includes/maintenance_helper.php";

// Ordinary requests never alter, backfill or migrate the schema. They only
// confirm that the explicit migration command (scripts/migrate.php) has brought
// the database to the version this code requires. The migration tool itself
// connects without this check.
if (!defined("GSO_SCHEMA_TOOL")) {
    try {
        gsoAssertSchemaCurrent($pdo);

        if (!defined("GSO_CHAT_READ_ONLY")) {
            syncMaintenanceSchedules($pdo);
        }
    } catch (Throwable $e) {
        $message = gsoSafeApplicationError($e, "database initialization", "The system is temporarily unavailable while its database is being updated. Please contact the administrator.");

        if (PHP_SAPI !== "cli") {
            http_response_code(503);
        }

        exit($message . (PHP_SAPI === "cli" ? PHP_EOL : ""));
    }
}
?>
