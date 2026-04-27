<?php

$mailConfig = [
    "host" => getenv("GSO_SMTP_HOST") ?: "smtp.gmail.com",
    "username" => getenv("GSO_SMTP_USERNAME") ?: "",
    "password" => getenv("GSO_SMTP_PASSWORD") ?: "",
    "port" => (int) (getenv("GSO_SMTP_PORT") ?: 587),
    "secure" => getenv("GSO_SMTP_SECURE") ?: "tls",
    "from_email" => getenv("GSO_SMTP_FROM") ?: (getenv("GSO_SMTP_USERNAME") ?: "no-reply@gso.local"),
    "from_name" => getenv("GSO_SMTP_FROM_NAME") ?: "GSO System"
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
