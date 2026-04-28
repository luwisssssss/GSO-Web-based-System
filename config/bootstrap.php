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

            if (array_key_exists($name, $_ENV) || getenv($name) !== false) {
                continue;
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

        if (strtolower((string) ($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "")) === "https") {
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

        session_set_cookie_params([
            "lifetime" => $cookieLifetime,
            "path" => $cookiePath,
            "domain" => $cookieDomain,
            "secure" => gsoEnvBool("GSO_SESSION_SECURE", gsoIsHttpsRequest()),
            "httponly" => true,
            "samesite" => $sameSite,
        ]);

        $sessionName = trim((string) gsoEnv("GSO_SESSION_NAME", "GSOSESSID"));

        if ($sessionName !== "") {
            session_name($sessionName);
        }

        session_start();
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

$rootPath = dirname(__DIR__);
gsoLoadEnvFile($rootPath . DIRECTORY_SEPARATOR . ".env");
date_default_timezone_set((string) gsoEnv("GSO_APP_TIMEZONE", "Asia/Manila"));
