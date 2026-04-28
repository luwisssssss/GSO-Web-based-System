<?php
require_once __DIR__ . "/bootstrap.php";

$mailConfig = [
    "host" => gsoEnv("GSO_SMTP_HOST", "smtp.gmail.com"),
    "username" => gsoEnv("GSO_SMTP_USERNAME", ""),
    "password" => gsoEnv("GSO_SMTP_PASSWORD", ""),
    "port" => (int) gsoEnv("GSO_SMTP_PORT", "587"),
    "secure" => gsoEnv("GSO_SMTP_SECURE", "tls"),
    "from_email" => gsoEnv("GSO_SMTP_FROM", gsoEnv("GSO_SMTP_USERNAME", "no-reply@gso.local")),
    "from_name" => gsoEnv("GSO_SMTP_FROM_NAME", "GSO System")
];

$localMailConfig = __DIR__ . "/mail.local.php";
if (is_file($localMailConfig)) {
    $localOverrides = require $localMailConfig;

    if (is_array($localOverrides)) {
        $mailConfig = array_merge($mailConfig, array_filter($localOverrides, static function ($value): bool {
            return $value !== null && $value !== "";
        }));
    }
}

return $mailConfig;
