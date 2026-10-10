<?php

if (!function_exists("getSchemaColumnMetadata")) {
    function getSchemaColumnMetadata(PDO $pdo, string $tableName, string $columnName): ?array
    {
        $stmt = $pdo->prepare("
            SELECT
                COLUMN_NAME,
                COLUMN_TYPE,
                DATA_TYPE,
                IS_NULLABLE,
                COLUMN_DEFAULT
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND COLUMN_NAME = :column_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":column_name" => $columnName,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("tableExists")) {
    function tableExists(PDO $pdo, string $tableName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("columnExists")) {
    function columnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        return getSchemaColumnMetadata($pdo, $tableName, $columnName) !== null;
    }
}

if (!function_exists("foreignKeyExists")) {
    function foreignKeyExists(PDO $pdo, string $tableName, string $constraintName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND CONSTRAINT_NAME = :constraint_name
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":constraint_name" => $constraintName,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("constraintExists")) {
    /** CHECK constraints are not foreign keys, so they need their own lookup. */
    function constraintExists(PDO $pdo, string $tableName, string $constraintName): bool
    {
        $stmt = $pdo->prepare("
            SELECT 1
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE()
              AND TABLE_NAME = :table_name
              AND CONSTRAINT_NAME = :constraint_name
            LIMIT 1
        ");
        $stmt->execute([
            ":table_name" => $tableName,
            ":constraint_name" => $constraintName,
        ]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("enumColumnHasValue")) {
    function enumColumnHasValue(PDO $pdo, string $tableName, string $columnName, string $value): bool
    {
        $column = getSchemaColumnMetadata($pdo, $tableName, $columnName);

        if ($column === null) {
            return false;
        }

        $type = (string) ($column["COLUMN_TYPE"] ?? "");

        return strpos($type, "'" . str_replace("'", "\\'", $value) . "'") !== false;
    }
}

if (!defined("GSO_SCHEMA_VERSION")) {
    /**
     * Schema version this code requires: the identifier of the newest file in
     * database/migrations. It is recorded in system_settings.schema_version by
     * scripts/migrate.php or by a fresh database/schema.sql import.
     */
    define("GSO_SCHEMA_VERSION", "20261010_007");
}

if (!function_exists("gsoRecordedSchemaVersion")) {
    /** Recorded schema version, or null when the database is unversioned. */
    function gsoRecordedSchemaVersion(PDO $pdo): ?string
    {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version' LIMIT 1");
            $value = $stmt->fetchColumn();
        } catch (Throwable $e) {
            return null;
        }

        return is_string($value) && preg_match('/^\d{8}_\d{3}$/', $value) ? $value : null;
    }
}

if (!function_exists("gsoAssertSchemaCurrent")) {
    /**
     * Read-only compatibility check for ordinary requests. The application never
     * alters, backfills or migrates the schema while serving a request: when the
     * database is not at the required version it stops and asks for the explicit
     * migration command instead.
     */
    function gsoAssertSchemaCurrent(PDO $pdo): void
    {
        $recorded = gsoRecordedSchemaVersion($pdo);

        if ($recorded !== GSO_SCHEMA_VERSION) {
            throw new RuntimeException(
                "Database schema version " . ($recorded ?? "unversioned")
                . " does not match required version " . GSO_SCHEMA_VERSION
                . ". Run scripts/migrate.php with the deployment database account."
            );
        }
    }
}
