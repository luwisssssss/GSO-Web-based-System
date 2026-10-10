<?php
/**
 * Explicit schema migration command.
 *
 *   C:\xampp\php\php.exe scripts\migrate.php
 *
 * Run it with the deployment database account after deploying new code. The web
 * application never migrates the schema itself: it only checks that the recorded
 * version matches the version this code requires.
 */
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

// Tells config/db.php to connect without the request-time version check.
define("GSO_SCHEMA_TOOL", true);

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../app/models/schema_migrator.php";

try {
    foreach (gsoRunSchemaMigrations($pdo, dirname(__DIR__)) as $line) {
        echo $line, PHP_EOL;
    }
    echo "Migrations completed.", PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "Migration stopped: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
