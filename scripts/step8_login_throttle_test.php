<?php
declare(strict_types=1);
require_once __DIR__ . '/test_environment_helper.php';

/**
 * Step 8 login throttling unit tests.
 *
 * Run with local MySQL:
 *   C:\xampp\php\php.exe scripts\step8_login_throttle_test.php
 *
 * Covers the attempt threshold, lock expiration, successful-login reset, and
 * the privacy properties of the stored key. Lock expiration is exercised by
 * backdating `attempted_at` rather than sleeping for the real lock window.
 *
 * The fresh-session bypass regression (Test B) and the valid-login regression
 * (Test E) run over real HTTP in scripts/step8_core_e2e.php.
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";
require_once $root . "/includes/login_throttle_helper.php";

$checks = 0;
$failures = [];

function throttleAssert(bool $condition, string $message): void
{
    global $checks, $failures;
    $checks++;

    if (!$condition) {
        $failures[] = $message;
    }
}

/** Move every row for a key back in time, simulating a lock that has aged. */
function ageAttempts(PDO $pdo, string $key, int $seconds): void
{
    $pdo->prepare(sprintf(
        "UPDATE login_attempts SET attempted_at = DATE_SUB(attempted_at, INTERVAL %d SECOND) WHERE attempt_key = :k",
        $seconds
    ))->execute([":k" => $key]);
}

$marker = "step8-throttle-" . bin2hex(random_bytes(8));
$login = $marker . "@example.test";
$baseline = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
$key = gsoLoginAttemptKey($login, "203.0.113.10");
$otherIpKey = gsoLoginAttemptKey($login, "203.0.113.11");
$createdKeys = [$key, $otherIpKey];

try {
    // --- Key privacy and normalization -----------------------------------
    throttleAssert(
        preg_match('/^[0-9a-f]{64}$/', $key) === 1,
        "Attempt key is not a 64-character hex digest."
    );
    throttleAssert(
        stripos($key, $marker) === false && strpos($key, "203.0.113.10") === false,
        "Attempt key leaks the raw login identifier or IP address."
    );
    throttleAssert(
        $key === gsoLoginAttemptKey("  " . strtoupper($login) . "  ", "203.0.113.10"),
        "Case or surrounding whitespace produces a different key (throttle bypass)."
    );
    throttleAssert(
        $key !== $otherIpKey,
        "Different client addresses collapse onto the same throttle counter."
    );
    throttleAssert(
        $key !== hash("sha256", strtolower($login) . "|203.0.113.10"),
        "Attempt key is an unkeyed digest rather than a keyed HMAC."
    );

    // --- TEST A: attempts below the threshold do not lock -----------------
    throttleAssert(gsoLoginLockRemaining($pdo, $key) === 0, "A clean key started out locked.");

    gsoRecordLoginFailure($pdo, $key);
    throttleAssert(gsoLoginLockRemaining($pdo, $key) === 0, "TEST A: 1 failed attempt locked the account.");

    gsoRecordLoginFailure($pdo, $key);
    throttleAssert(gsoLoginLockRemaining($pdo, $key) === 0, "TEST A: 2 failed attempts locked the account.");

    gsoRecordLoginFailure($pdo, $key);
    $lockRemaining = gsoLoginLockRemaining($pdo, $key);
    throttleAssert(
        $lockRemaining > 0 && $lockRemaining <= GSO_LOGIN_LOCK_SECONDS,
        "TEST A: 3 failed attempts did not produce a lock of at most "
            . GSO_LOGIN_LOCK_SECONDS . "s (observed {$lockRemaining})."
    );

    // The lock is scoped to this key only.
    throttleAssert(
        gsoLoginLockRemaining($pdo, $otherIpKey) === 0,
        "The lock leaked onto the same login from a different client address."
    );

    // --- Privacy of what actually landed in the table ---------------------
    $stored = $pdo->prepare("SELECT attempt_key FROM login_attempts WHERE attempt_key = :k");
    $stored->execute([":k" => $key]);
    $storedKeys = $stored->fetchAll(PDO::FETCH_COLUMN) ?: [];
    throttleAssert(count($storedKeys) === 3, "Expected exactly 3 recorded failures, found " . count($storedKeys) . ".");
    throttleAssert(
        count(array_filter($storedKeys, static fn ($k) => preg_match('/^[0-9a-f]{64}$/', (string) $k) !== 1)) === 0,
        "A stored attempt key is not a hashed value."
    );
    $columns = $pdo->query("SHOW COLUMNS FROM login_attempts")->fetchAll(PDO::FETCH_COLUMN);
    throttleAssert(
        count(array_intersect(array_map("strtolower", $columns), ["password", "password_hash", "ip_address", "username", "email"])) === 0,
        "login_attempts carries a column that would store credentials or a raw identifier."
    );

    // --- TEST C: the lock expires -----------------------------------------
    ageAttempts($pdo, $key, GSO_LOGIN_LOCK_SECONDS - 2);
    throttleAssert(
        gsoLoginLockRemaining($pdo, $key) > 0,
        "TEST C: the lock released early, before the window elapsed."
    );

    ageAttempts($pdo, $key, 3);
    throttleAssert(
        gsoLoginLockRemaining($pdo, $key) === 0,
        "TEST C: the lock did not expire after " . GSO_LOGIN_LOCK_SECONDS . " seconds."
    );

    // A failure after expiry starts a fresh run instead of re-locking at once.
    gsoRecordLoginFailure($pdo, $key);
    throttleAssert(
        gsoLoginLockRemaining($pdo, $key) === 0,
        "TEST C: a single failure after expiry immediately re-locked (stale attempts were counted)."
    );
    throttleAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempt_key = " . $pdo->quote($key))->fetchColumn() === 1,
        "TEST C: expired rows were not pruned when a new failure was recorded."
    );

    // --- TEST D: a successful login clears the failures -------------------
    gsoRecordLoginFailure($pdo, $key);
    gsoRecordLoginFailure($pdo, $key);
    throttleAssert(
        gsoLoginLockRemaining($pdo, $key) > 0,
        "TEST D: could not reach a locked state to verify the reset."
    );

    gsoClearLoginFailures($pdo, $key);
    throttleAssert(gsoLoginLockRemaining($pdo, $key) === 0, "TEST D: a successful login did not clear the lock.");
    throttleAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempt_key = " . $pdo->quote($key))->fetchColumn() === 0,
        "TEST D: failed-attempt rows survived a successful login."
    );

    // --- Retention sweep ---------------------------------------------------
    gsoRecordLoginFailure($pdo, $otherIpKey);
    ageAttempts($pdo, $otherIpKey, GSO_LOGIN_LOCK_SECONDS + 5);
    gsoPruneLoginAttempts($pdo, true);
    throttleAssert(
        (int) $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE attempt_key = " . $pdo->quote($otherIpKey))->fetchColumn() === 0,
        "Retention sweep left throttle rows that can no longer affect a lock.",
    );
} finally {
    $cleanup = $pdo->prepare("DELETE FROM login_attempts WHERE attempt_key = :k");
    foreach ($createdKeys as $createdKey) {
        $cleanup->execute([":k" => $createdKey]);
    }
}

$restored = (int) $pdo->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
throttleAssert($restored === $baseline, "Test rows leaked: baseline {$baseline}, now {$restored}.");

if ($failures !== []) {
    echo "FAIL: login throttling ({$checks} checks)\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo "PASS: login throttling ({$checks} checks; threshold " . GSO_LOGIN_ATTEMPT_THRESHOLD
    . ", lock " . GSO_LOGIN_LOCK_SECONDS . "s).\n";
