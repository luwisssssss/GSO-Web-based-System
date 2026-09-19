<?php

if (!function_exists("gsoLoadEnvFile")) {
    function gsoLoadEnvFile(string $path): void
    {
        static $loaded = [];

        if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
            return;
        }

        $loaded[$path] = true;
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);

            if ($trimmed === "" || str_starts_with($trimmed, "#")) {
                continue;
            }

            $separatorPosition = strpos($trimmed, "=");

            if ($separatorPosition === false) {
                continue;
            }

            $name = trim(substr($trimmed, 0, $separatorPosition));
            $value = trim(substr($trimmed, $separatorPosition + 1));

            if ($name === "") {
                continue;
            }

            if (
                (str_starts_with($value, "\"") && str_ends_with($value, "\""))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if (array_key_exists($name, $_ENV) || getenv($name) !== false) {
                continue;
            }

            putenv($name . "=" . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

if (!function_exists("gsoEnv")) {
    function gsoEnv(string $name, ?string $default = null): ?string
    {
        if (array_key_exists($name, $_ENV)) {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);

        if ($value === false) {
            return $default;
        }

        return (string) $value;
    }
}

if (!function_exists("gsoEnvBool")) {
    function gsoEnvBool(string $name, bool $default = false): bool
    {
        $value = gsoEnv($name);

        if ($value === null) {
            return $default;
        }

        $normalized = strtolower(trim($value));

        if (in_array($normalized, ["1", "true", "yes", "on"], true)) {
            return true;
        }

        if (in_array($normalized, ["0", "false", "no", "off"], true)) {
            return false;
        }

        return $default;
    }
}

if (!function_exists("gsoIsHttpsRequest")) {
    function gsoIsHttpsRequest(): bool
    {
        if (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off") {
            return true;
        }

        if ((string) ($_SERVER["SERVER_PORT"] ?? "") === "443") {
            return true;
        }

        if (
            gsoEnvBool("GSO_TRUST_PROXY_HEADERS", false)
            && strtolower((string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "")) === "https"
        ) {
            return true;
        }

        return false;
    }
}

if (!function_exists("gsoSecureSessionStart")) {
    function gsoSecureSessionStart(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set("session.use_strict_mode", "1");
        ini_set("session.use_only_cookies", "1");
        ini_set("session.use_trans_sid", "0");
        ini_set("session.cookie_httponly", "1");

        $defaultCookieParams = session_get_cookie_params();
        $cookiePath = (string) ($defaultCookieParams["path"] ?? "/");
        $cookieDomain = (string) ($defaultCookieParams["domain"] ?? "");
        $cookieLifetime = (int) ($defaultCookieParams["lifetime"] ?? 0);
        $sameSite = trim((string) gsoEnv("GSO_SESSION_SAMESITE", "Lax"));

        if ($sameSite === "") {
            $sameSite = "Lax";
        }

        if (!in_array($sameSite, ["Lax", "Strict", "None"], true)) {
            $sameSite = "Lax";
        }

        $secureCookie = gsoEnvBool("GSO_SESSION_SECURE", gsoIsHttpsRequest());
        if ($sameSite === "None" && !$secureCookie) {
            $sameSite = "Lax";
        }

        session_set_cookie_params([
            "lifetime" => $cookieLifetime,
            "path" => $cookiePath,
            "domain" => $cookieDomain,
            "secure" => $secureCookie,
            "httponly" => true,
            "samesite" => $sameSite,
        ]);

        $sessionName = trim((string) gsoEnv("GSO_SESSION_NAME", "GSOSESSID"));

        if ($sessionName !== "") {
            session_name($sessionName);
        }

        session_start();

        if (!empty($_SESSION["user_id"])) {
            $now = time();
            $idleTimeout = max(0, (int) gsoEnv("GSO_SESSION_IDLE_TIMEOUT", "1800"));
            $absoluteTimeout = max(0, (int) gsoEnv("GSO_SESSION_ABSOLUTE_TIMEOUT", "28800"));
            $startedAt = (int) ($_SESSION["auth_started_at"] ?? $now);
            $lastActivityAt = (int) ($_SESSION["last_activity_at"] ?? $now);
            $idleExpired = $idleTimeout > 0 && ($now - $lastActivityAt) > $idleTimeout;
            $absoluteExpired = $absoluteTimeout > 0 && ($now - $startedAt) > $absoluteTimeout;

            if ($idleExpired || $absoluteExpired) {
                gsoDestroySession();
                return;
            }

            $_SESSION["auth_started_at"] = $startedAt;
            $_SESSION["last_activity_at"] = $now;
        }
    }
}

if (!function_exists("gsoDestroySession")) {
    function gsoDestroySession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            gsoSecureSessionStart();
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                "",
                [
                    "expires" => time() - 42000,
                    "path" => (string) ($params["path"] ?? "/"),
                    "domain" => (string) ($params["domain"] ?? ""),
                    "secure" => (bool) ($params["secure"] ?? false),
                    "httponly" => (bool) ($params["httponly"] ?? true),
                    "samesite" => (string) ($params["samesite"] ?? "Lax"),
                ]
            );
        }

        session_destroy();
    }
}

if (!function_exists("gsoAppSecret")) {
    /**
     * Server-side secret used to key one-way identifiers such as login
     * throttling keys.
     *
     * The secret deliberately lives outside the database so that a leaked
     * database dump cannot be used to recover the identifiers it protects.
     * GSO_APP_KEY in .env takes precedence; otherwise a random key is
     * generated once and cached in a gitignored config file.
     */
    function gsoAppSecret(): string
    {
        static $secret = null;

        if ($secret !== null) {
            return $secret;
        }

        $configured = trim((string) gsoEnv("GSO_APP_KEY", ""));

        if ($configured !== "") {
            return $secret = $configured;
        }

        $keyFile = __DIR__ . DIRECTORY_SEPARATOR . "app_key.local.php";
        $readKeyFile = static function (string $path): ?string {
            if (!is_file($path) || !is_readable($path)) {
                return null;
            }

            if (function_exists("opcache_invalidate")) {
                @opcache_invalidate($path, true);
            }

            $stored = @include $path;

            return is_string($stored) && trim($stored) !== "" ? trim($stored) : null;
        };

        $stored = $readKeyFile($keyFile);

        if ($stored !== null) {
            return $secret = $stored;
        }

        $generated = bin2hex(random_bytes(32));
        @file_put_contents(
            $keyFile,
            "<?php\n\n// Auto-generated local secret. Never commit this file.\n"
                . "// Set GSO_APP_KEY in .env to manage the secret explicitly instead.\n\n"
                . "return \"" . $generated . "\";\n",
            LOCK_EX
        );

        // Re-read so concurrent first requests converge on the key that won the race.
        return $secret = $readKeyFile($keyFile) ?? $generated;
    }
}

$rootPath = dirname(__DIR__);
gsoLoadEnvFile($rootPath . DIRECTORY_SEPARATOR . ".env");
date_default_timezone_set((string) gsoEnv("GSO_APP_TIMEZONE", "Asia/Manila"));

$appEnvironment = strtolower(trim((string) gsoEnv("GSO_APP_ENV", "development")));
$appDebug = gsoEnvBool("GSO_APP_DEBUG", false);
if ($appEnvironment === "production" && !$appDebug) {
    ini_set("display_errors", "0");
    ini_set("display_startup_errors", "0");
    ini_set("log_errors", "1");
}
