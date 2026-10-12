<?php
/**
 * Explicit schema migration runner (command line only).
 *
 * Deployment model:
 *   - Fresh install:        an empty database receives database/schema.sql, which
 *                           is the complete current schema and records its version.
 *   - Existing installation: ordered files in database/migrations newer than the
 *                           recorded version are applied one by one, and the
 *                           version is recorded after each file.
 *   - Ordinary web request: never migrates. config/db.php only compares the
 *                           recorded version with GSO_SCHEMA_VERSION.
 *
 * The version lives in system_settings.schema_version as the identifier of the
 * last applied migration file (for example 20261010_007).
 */
require_once __DIR__ . "/schema_helper.php";

if (PHP_SAPI !== "cli") {
    throw new RuntimeException("Schema migration is a command-line deployment tool.");
}

/** Last migration that the pre-R10 guarded upgrader represents. */
const GSO_LEGACY_BASELINE_VERSION = "20261010_006";

/** Split a reviewed SQL file into statements. Files end each statement with ";\n". */
function gsoSplitSqlStatements(string $sql): array
{
    $lines = [];
    foreach (preg_split('/\r\n|\r|\n/', $sql) as $line) {
        if (preg_match('/^\s*--/', $line)) {
            continue;
        }
        $lines[] = $line;
    }

    $statements = [];
    foreach (preg_split('/;[ \t]*(?:\n|$)/', implode("\n", $lines)) as $statement) {
        $statement = trim($statement);
        if ($statement !== "") {
            $statements[] = $statement;
        }
    }

    return $statements;
}

/** Run every statement of a SQL file separately so no failure is skipped. */
function gsoExecuteSqlFile(PDO $pdo, string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Cannot read " . basename($path) . ".");
    }

    $count = 0;
    foreach (gsoSplitSqlStatements($sql) as $statement) {
        try {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->closeCursor();
            }
        } catch (Throwable $e) {
            $reference = gsoLogApplicationException($e, "schema migration statement");
            throw new RuntimeException(
                basename($path) . " failed at statement " . ($count + 1) . ". Reference: " . $reference,
                0,
                $e
            );
        }
        $count++;
    }

    return $count;
}

/** Ordered migration files newer than the given version: [identifier => path]. */
function gsoPendingMigrations(string $directory, string $afterVersion): array
{
    $pending = [];
    foreach (glob($directory . "/*.sql") ?: [] as $path) {
        if (preg_match('/^(\d{8}_\d{3})_/', basename($path), $match) && strcmp($match[1], $afterVersion) > 0) {
            $pending[$match[1]] = $path;
        }
    }
    ksort($pending, SORT_STRING);

    return $pending;
}

function gsoRecordSchemaVersion(PDO $pdo, string $version): void
{
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value, updated_at)
        VALUES ('schema_version', :version, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
    ");
    $stmt->execute([":version" => $version]);
}

/**
 * Data gate for 20261010_007_final_cleanup.
 *
 * Returns one human-readable line per reason the destructive cleanup must not
 * run. An empty list means every retired structure is provably empty, or holds
 * only values that request_items already reproduces.
 */
function gsoFinalCleanupBlockers(PDO $pdo): array
{
    $blockers = [];
    $count = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    foreach (["request_adviser_history", "request_director_history"] as $table) {
        if (tableExists($pdo, $table)) {
            $rows = $count("SELECT COUNT(*) FROM `{$table}`");
            if ($rows > 0) {
                $blockers[] = "{$table} holds {$rows} historical row(s).";
            }
        }
    }

    $routing = [
        "adviser_state" => "adviser_state <> 'Not Required'",
        "adviser_user_id" => "adviser_user_id IS NOT NULL",
        "adviser_required_by" => "adviser_required_by IS NOT NULL",
        "adviser_required_at" => "adviser_required_at IS NOT NULL",
        "director_state" => "director_state <> 'Not Required'",
        "director_required_by" => "director_required_by IS NOT NULL",
        "director_required_at" => "director_required_at IS NOT NULL",
    ];
    foreach ($routing as $column => $condition) {
        if (columnExists($pdo, "resource_requests", $column)) {
            $rows = $count("SELECT COUNT(*) FROM resource_requests WHERE {$condition}");
            if ($rows > 0) {
                $blockers[] = "resource_requests.{$column} holds {$rows} non-default value(s).";
            }
        }
    }

    $hasResource = columnExists($pdo, "resource_requests", "resource_id");
    $hasQuantity = columnExists($pdo, "resource_requests", "quantity");
    if ($hasResource || $hasQuantity) {
        $rows = $count("
            SELECT COUNT(*) FROM resource_requests rr
            WHERE NOT EXISTS (SELECT 1 FROM request_items ri WHERE ri.request_id = rr.request_id)
        ");
        if ($rows > 0) {
            $blockers[] = "{$rows} request(s) have no request_items line, so the header is their only resource record.";
        }

        if ($hasResource && $hasQuantity) {
            $rows = $count("
                SELECT COUNT(*) FROM resource_requests rr
                WHERE EXISTS (SELECT 1 FROM request_items x WHERE x.request_id = rr.request_id)
                  AND NOT EXISTS (
                        SELECT 1 FROM request_items ri
                        WHERE ri.request_id = rr.request_id
                          AND ri.resource_id = rr.resource_id
                          AND ri.requested_quantity <=> rr.quantity)
            ");
            if ($rows > 0) {
                $blockers[] = "{$rows} request(s) have a header resource/quantity that no request_items line reproduces.";
            }
        }
    }

    return $blockers;
}

/**
 * Gate for 20261010_008_claim_slip_code.
 *
 * An Approved request must hold a Claim Slip Code once the migration is in
 * place. Codes are issued only by an approval, never fabricated by a migration,
 * so any request that is already Approved is reported for an explicit decision
 * instead of being changed silently.
 */
function gsoClaimSlipBlockers(PDO $pdo): array
{
    if (columnExists($pdo, "resource_requests", "claim_slip_code")) {
        $sql = "SELECT request_id FROM resource_requests WHERE status = 'Approved' AND claim_slip_code IS NULL ORDER BY request_id";
    } else {
        $sql = "SELECT request_id FROM resource_requests WHERE status = 'Approved' ORDER BY request_id";
    }
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);

    if ($ids === []) {
        return [];
    }

    return [
        count($ids) . " request(s) are currently Approved and unreleased without a Claim Slip Code: #"
        . implode(", #", array_map("intval", $ids))
        . ". Release, cancel or re-approve them under the previous version first, or decide explicitly how each should receive a code.",
    ];
}

/**
 * Bring the connected database to GSO_SCHEMA_VERSION.
 *
 * @return string[] progress lines for the operator
 */
function gsoRunSchemaMigrations(PDO $pdo, string $projectRoot): array
{
    $log = [];
    $tableCount = (int) $pdo->query("
        SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
    ")->fetchColumn();

    if ($tableCount === 0) {
        $statements = gsoExecuteSqlFile($pdo, $projectRoot . "/database/schema.sql");
        $version = gsoRecordedSchemaVersion($pdo);
        if ($version !== GSO_SCHEMA_VERSION) {
            throw new RuntimeException("database/schema.sql did not record schema version " . GSO_SCHEMA_VERSION . ".");
        }
        $log[] = "Fresh install: applied database/schema.sql ({$statements} statements) at version {$version}.";

        return $log;
    }

    $version = gsoRecordedSchemaVersion($pdo);

    if ($version === null) {
        if (!tableExists($pdo, "resource_requests") || !columnExists($pdo, "resource_requests", "resource_id")) {
            throw new RuntimeException(
                "This database has no recorded schema version and does not look like a pre-R10 installation. "
                . "Restore a known database or import database/schema.sql into an empty database."
            );
        }

        require_once __DIR__ . "/legacy_schema_upgrader.php";
        gsoLegacyBaselineUpgrade($pdo);
        gsoRecordSchemaVersion($pdo, GSO_LEGACY_BASELINE_VERSION);
        $version = GSO_LEGACY_BASELINE_VERSION;
        $log[] = "Unversioned pre-R10 database: legacy baseline verified and recorded as {$version}.";
    }

    if (strcmp($version, GSO_SCHEMA_VERSION) > 0) {
        throw new RuntimeException("Database version {$version} is newer than this code ({" . GSO_SCHEMA_VERSION . "}).");
    }

    $gates = ["20261010_007" => "gsoFinalCleanupBlockers", "20261010_008" => "gsoClaimSlipBlockers"];

    foreach (gsoPendingMigrations($projectRoot . "/database/migrations", $version) as $identifier => $path) {
        if (isset($gates[$identifier])) {
            $blockers = $gates[$identifier]($pdo);
            if ($blockers !== []) {
                throw new RuntimeException(
                    basename($path) . " was NOT applied and the schema is unchanged:\n  - "
                    . implode("\n  - ", $blockers)
                );
            }
            $log[] = basename($path) . ": data gate passed.";
        }

        $statements = gsoExecuteSqlFile($pdo, $path);
        gsoRecordSchemaVersion($pdo, $identifier);
        $log[] = "Applied " . basename($path) . " ({$statements} statements). Version is now {$identifier}.";
    }

    $final = gsoRecordedSchemaVersion($pdo);
    if ($final !== GSO_SCHEMA_VERSION) {
        throw new RuntimeException("Migration finished at {$final}, but this code requires " . GSO_SCHEMA_VERSION . ".");
    }
    $log[] = "Schema is current at version {$final}.";

    return $log;
}
