<?php

/**
 * Database-backed login throttling.
 *
 * The session-only guard on the login page can be defeated by clearing cookies,
 * so the authoritative attempt counter lives in the `login_attempts` table.
 *
 * Privacy: no username, email, password or raw IP address is ever stored. Rows
 * are keyed by a keyed hash (HMAC-SHA256) of the normalized login identifier
 * and the client address. The HMAC secret is held outside the database, so a
 * leaked dump cannot be brute-forced back into the identifiers it protects.
 */

require_once __DIR__ . "/schema_helper.php";

if (!defined("GSO_LOGIN_ATTEMPT_THRESHOLD")) {
    define("GSO_LOGIN_ATTEMPT_THRESHOLD", 3);
}

if (!defined("GSO_LOGIN_LOCK_SECONDS")) {
    define("GSO_LOGIN_LOCK_SECONDS", 30);
}

if (!function_exists("gsoLoginAttemptKey")) {
    /**
     * Build the one-way lookup key for a login identifier and client address.
     *
     * Normalizing case and surrounding whitespace keeps "Admin", "admin" and
     * " admin " on a single counter, which matches how the credential lookup
     * itself resolves them.
     */
    function gsoLoginAttemptKey(string $loginInput, ?string $remoteAddress = null): string
    {
        $normalizedLogin = strtolower(trim($loginInput));

        if ($remoteAddress === null) {
            $remoteAddress = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
        }

        // REMOTE_ADDR only: a forwarded-for header is client supplied and would
        // let an attacker rotate their way out of the lock.
        $normalizedAddress = strtolower(trim($remoteAddress));

        if ($normalizedAddress === "") {
            $normalizedAddress = "unknown";
        }

        return hash_hmac(
            "sha256",
            "login-attempt:v1|" . $normalizedLogin . "|" . $normalizedAddress,
            gsoAppSecret()
        );
    }
}

if (!function_exists("gsoLoginThrottleAvailable")) {
    function gsoLoginThrottleAvailable(PDO $pdo): bool
    {
        static $available = null;

        if ($available === null) {
            try {
                $available = tableExists($pdo, "login_attempts");
            } catch (Throwable $e) {
                error_log("Login throttle availability check failed: " . $e->getMessage());
                $available = false;
            }
        }

        return $available;
    }
}

if (!function_exists("gsoLoginLockRemaining")) {
    /**
     * Seconds left on an active lock for this key, or 0 when it may proceed.
     */
    function gsoLoginLockRemaining(PDO $pdo, string $attemptKey): int
    {
        if ($attemptKey === "" || !gsoLoginThrottleAvailable($pdo)) {
            return 0;
        }

        $lockSeconds = (int) GSO_LOGIN_LOCK_SECONDS;

        try {
            // Every comparison is evaluated on the database clock, so a PHP and
            // MySQL timezone mismatch cannot shorten or extend the lock.
            $stmt = $pdo->prepare(sprintf(
                "SELECT
                    COUNT(*) AS failure_count,
                    COALESCE(
                        TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(MAX(attempted_at), INTERVAL %d SECOND)),
                        0
                    ) AS lock_remaining
                FROM login_attempts
                WHERE attempt_key = :attempt_key
                  AND attempted_at > DATE_SUB(NOW(), INTERVAL %d SECOND)",
                $lockSeconds,
                $lockSeconds
            ));
            $stmt->execute([":attempt_key" => $attemptKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            if ((int) ($row["failure_count"] ?? 0) < (int) GSO_LOGIN_ATTEMPT_THRESHOLD) {
                return 0;
            }

            return max(0, (int) ($row["lock_remaining"] ?? 0));
        } catch (Throwable $e) {
            error_log("Login throttle lookup failed: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists("gsoRecordLoginFailure")) {
    function gsoRecordLoginFailure(PDO $pdo, string $attemptKey): void
    {
        if ($attemptKey === "" || !gsoLoginThrottleAvailable($pdo)) {
            return;
        }

        try {
            // Expired rows for this key go first, so a new lock always needs a
            // fresh run of failures instead of inheriting stale ones.
            $stmt = $pdo->prepare(sprintf(
                "DELETE FROM login_attempts
                 WHERE attempt_key = :attempt_key
                   AND attempted_at <= DATE_SUB(NOW(), INTERVAL %d SECOND)",
                (int) GSO_LOGIN_LOCK_SECONDS
            ));
            $stmt->execute([":attempt_key" => $attemptKey]);

            $pdo->prepare("INSERT INTO login_attempts (attempt_key, attempted_at) VALUES (:attempt_key, NOW())")
                ->execute([":attempt_key" => $attemptKey]);
        } catch (Throwable $e) {
            error_log("Login throttle write failed: " . $e->getMessage());
        }

        gsoPruneLoginAttempts($pdo);
    }
}

if (!function_exists("gsoClearLoginFailures")) {
    function gsoClearLoginFailures(PDO $pdo, string $attemptKey): void
    {
        if ($attemptKey === "" || !gsoLoginThrottleAvailable($pdo)) {
            return;
        }

        try {
            $pdo->prepare("DELETE FROM login_attempts WHERE attempt_key = :attempt_key")
                ->execute([":attempt_key" => $attemptKey]);
        } catch (Throwable $e) {
            error_log("Login throttle cleanup failed: " . $e->getMessage());
        }
    }
}

if (!function_exists("gsoPruneLoginAttempts")) {
    /**
     * Sweep rows that can no longer affect a lock. Keys abandoned mid-lockout
     * are only reachable from here, so the sweep runs occasionally rather than
     * on every failed login.
     */
    function gsoPruneLoginAttempts(PDO $pdo, bool $force = false): void
    {
        if (!gsoLoginThrottleAvailable($pdo)) {
            return;
        }

        if (!$force && random_int(1, 20) !== 1) {
            return;
        }

        try {
            $pdo->exec(sprintf(
                "DELETE FROM login_attempts WHERE attempted_at <= DATE_SUB(NOW(), INTERVAL %d SECOND)",
                (int) GSO_LOGIN_LOCK_SECONDS
            ));
        } catch (Throwable $e) {
            error_log("Login throttle prune failed: " . $e->getMessage());
        }
    }
}
