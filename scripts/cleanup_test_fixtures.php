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
 * Fixtures are identified strictly by the naming patterns the harnesses use.
 * Nothing outside those patterns is touched.
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

$resourcePatterns = ["Step8 %", "Step9 %", "Step10 %", "VRFY %", "PROBE %"];
$userPatterns = [
    "Step 6 %", "Step 7 %", "Step 8 %", "Step 9 %", "Step 10 %", "VRFY %",
    "MultiTurn Probe", "Demo User",
    // Exact, previously documented verification fixtures. These strings have
    // no wildcard, so similarly named legitimate accounts are not selected.
    "Verify Admin", "Verify Borrower", "Verify Other",
];

$findIds = static function (PDO $pdo, string $table, string $idColumn, string $nameColumn, array $patterns): array {
    $ids = [];

    foreach ($patterns as $pattern) {
        $stmt = $pdo->prepare("SELECT {$idColumn}, {$nameColumn} FROM `{$table}` WHERE {$nameColumn} LIKE :pattern");
        $stmt->execute([":pattern" => $pattern]);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[(int) $row[$idColumn]] = (string) $row[$nameColumn];
        }
    }

    return $ids;
};

$resources = $findIds($pdo, "resources", "resource_id", "resource_name", $resourcePatterns);
$users = $findIds($pdo, "users", "user_id", "full_name", $userPatterns);
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
                       INNER JOIN resource_requests rr ON rr.request_id = rs.request_id
                       WHERE rr.resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE rs FROM return_submissions rs
                       INNER JOIN resource_requests rr ON rr.request_id = rs.request_id
                       WHERE rr.resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM maintenance_schedules WHERE resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM incident_reports WHERE resource_id IN ({$placeholders})")->execute($ids);
        $pdo->prepare("DELETE FROM resource_requests WHERE resource_id IN ({$placeholders})")->execute($ids);
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
