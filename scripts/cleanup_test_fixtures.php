<?php
declare(strict_types=1);

/**
 * Remove leftover automated-test fixtures from the database.
 *
 * Earlier harness runs that failed mid-way left marked fixtures behind,
 * because the cleanup block aborted on the first foreign-key error. The
 * harnesses now sweep by marker, so this only has to clear the historical
 * leftovers once.
 *
 * Fixtures are identified by their audited primary keys and exact names.
 * A fixture name found under a different key, or a known key carrying a
 * different name, is treated as unsafe and aborts the run.
 *
 * Dry run (default - shows what would be removed, changes nothing):
 *   C:\xampp\php\php.exe scripts\cleanup_test_fixtures.php
 *
 * Apply:
 *   C:\xampp\php\php.exe scripts\cleanup_test_fixtures.php --apply
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";

$apply = in_array("--apply", $argv ?? [], true);

$expectedResources = [
    74 => "VRFY Item",
    75 => "VRFY Room",
    76 => "Step8 Item S8-B46DFEA6B98D",
    77 => "Step8 Facility S8-B46DFEA6B98D",
    78 => "Step8 Maintenance S8-B46DFEA6B98D",
    79 => "Step8 Item S8-A6F44A65A93E",
    80 => "Step8 Facility S8-A6F44A65A93E",
    81 => "Step8 Maintenance S8-A6F44A65A93E",
];

$expectedUsers = [
    108 => "Verify Admin",
    109 => "Verify Borrower",
    110 => "Verify Other",
    111 => "Step 8 Borrower A S8-B46DFEA6B98D",
    112 => "Step 8 Borrower B S8-B46DFEA6B98D",
    113 => "Step 8 Lifecycle S8-B46DFEA6B98D",
    114 => "Step 8 Pending Admin S8-B46DFEA6B98D",
    115 => "Step 8 Borrower A S8-A6F44A65A93E",
    116 => "Step 8 Borrower B S8-A6F44A65A93E",
    117 => "Step 8 Lifecycle S8-A6F44A65A93E",
    118 => "Step 8 Pending Admin S8-A6F44A65A93E",
];

$findExactFixtures = static function (
    PDO $pdo,
    string $table,
    string $idColumn,
    string $nameColumn,
    array $expected
): array {
    $idPlaceholders = implode(",", array_fill(0, count($expected), "?"));
    $stmt = $pdo->prepare(
        "SELECT {$idColumn}, {$nameColumn} FROM `{$table}` WHERE {$idColumn} IN ({$idPlaceholders})"
    );
    $stmt->execute(array_keys($expected));

    $found = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row[$idColumn];
        $name = (string) $row[$nameColumn];
        if (!isset($expected[$id]) || !hash_equals($expected[$id], $name)) {
            throw new RuntimeException(
                "Safety check failed: {$table}.{$idColumn} {$id} is '{$name}', expected audited fixture '{$expected[$id]}'."
            );
        }
        $found[$id] = $name;
    }

    $namePlaceholders = implode(",", array_fill(0, count($expected), "?"));
    $stmt = $pdo->prepare(
        "SELECT {$idColumn}, {$nameColumn} FROM `{$table}` WHERE {$nameColumn} IN ({$namePlaceholders})"
    );
    $stmt->execute(array_values($expected));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row[$idColumn];
        $name = (string) $row[$nameColumn];
        if (!isset($expected[$id]) || !hash_equals($expected[$id], $name)) {
            throw new RuntimeException(
                "Safety check failed: audited fixture name '{$name}' exists at unexpected {$table}.{$idColumn} {$id}."
            );
        }
    }

    ksort($found);
    return $found;
};

try {
    $resources = $findExactFixtures(
        $pdo,
        "resources",
        "resource_id",
        "resource_name",
        $expectedResources
    );
    $users = $findExactFixtures($pdo, "users", "user_id", "full_name", $expectedUsers);
} catch (Throwable $e) {
    fwrite(STDERR, "Cleanup safety check failed; no changes were made: " . $e->getMessage() . "\n");
    exit(2);
}
$fixtureRequestIds = [];
if ($resources !== [] || $users !== []) {
    $conditions = [];
    $params = [];
    if ($resources !== []) {
        $conditions[] = "resource_id IN (" . implode(",", array_fill(0, count($resources), "?")) . ")";
        $params = array_merge($params, array_keys($resources));
    }
    if ($users !== []) {
        $conditions[] = "borrower_id IN (" . implode(",", array_fill(0, count($users), "?")) . ")";
        $params = array_merge($params, array_keys($users));
    }
    $stmt = $pdo->prepare("SELECT request_id FROM resource_requests WHERE " . implode(" OR ", $conditions));
    $stmt->execute($params);
    $fixtureRequestIds = array_map("intval", $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

echo ($apply ? "APPLYING" : "DRY RUN") . " - fixture cleanup\n\n";
echo "Resources to remove: " . count($resources) . "\n";
foreach ($resources as $id => $name) {
    echo "  [{$id}] {$name}\n";
}
echo "\nUsers to remove: " . count($users) . "\n";
foreach ($users as $id => $name) {
    echo "  [{$id}] {$name}\n";
}
echo "\nRelated requests: " . count($fixtureRequestIds) . "\n";
echo $fixtureRequestIds === [] ? "  none\n" : "  " . implode(", ", $fixtureRequestIds) . "\n";

if ($resources === [] && $users === []) {
    echo "\nNothing to clean up.\n";
    exit(0);
}

if (!$apply) {
    echo "\nRe-run with --apply to delete these rows and their dependent records.\n";
    exit(0);
}

$inList = static fn (array $ids): string => implode(",", array_fill(0, count($ids), "?"));
$removed = [];

$pdo->beginTransaction();

try {
    // Notification links are not foreign keys. Remove only links ending in an
    // exact fixture request id so other users do not retain dead test links.
    foreach ($fixtureRequestIds as $fixtureRequestId) {
        $pdo->prepare("DELETE FROM notifications WHERE link LIKE :suffix")
            ->execute([":suffix" => "%request_id={$fixtureRequestId}"]);
    }
    if ($resources !== []) {
        $ids = array_keys($resources);
        $placeholders = $inList($ids);

        // Dependency order: photos -> returns -> requests, then the rest.
        $pdo->prepare("DELETE p FROM return_submission_photos p
                       INNER JOIN return_submissions rs ON rs.return_id = p.return_id
                       INNER JOIN request_items ri ON ri.request_id = rs.request_id
                       WHERE ri.resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE rs FROM return_submissions rs
                       INNER JOIN request_items ri ON ri.request_id = rs.request_id
                       WHERE ri.resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM maintenance_schedules WHERE resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM incident_reports WHERE resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE rr FROM resource_requests rr INNER JOIN request_items ri ON ri.request_id=rr.request_id WHERE ri.resource_id IN ({$placeholders})")->execute($ids);
        $stmt = $pdo->prepare("DELETE FROM resources WHERE resource_id IN ({$placeholders})");
        $stmt->execute($ids);
        $removed["resources"] = $stmt->rowCount();
    }

    if ($users !== []) {
        $ids = array_keys($users);
        $placeholders = $inList($ids);

        $pdo->prepare("DELETE p FROM return_submission_photos p
                       INNER JOIN return_submissions rs ON rs.return_id = p.return_id
                       WHERE rs.borrower_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM return_submissions WHERE borrower_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM incident_reports WHERE reporter_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM resource_requests WHERE borrower_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM notifications WHERE user_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM activity_logs WHERE user_id IN ({$placeholders})")->execute($ids);
        $stmt = $pdo->prepare("DELETE FROM users WHERE user_id IN ({$placeholders})");
        $stmt->execute($ids);
        $removed["users"] = $stmt->rowCount();
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "\nCleanup failed and was rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "\nRemoved:\n";
foreach ($removed as $table => $count) {
    echo "  {$table}: {$count}\n";
}
echo "Done.\n";
