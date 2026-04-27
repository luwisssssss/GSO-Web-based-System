<?php
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../includes/schema_helper.php";
require_once __DIR__ . "/../includes/maintenance_helper.php";

ensureSystemSchema($pdo);
syncMaintenanceSchedules($pdo);

echo "Migrations completed.\n";
