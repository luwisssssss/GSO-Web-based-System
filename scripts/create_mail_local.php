<?php

$configPath = __DIR__ . "/../config/mail.local.php";

$config = [
    "host" => getenv("GSO_SMTP_HOST") ?: "smtp.gmail.com",
    "username" => getenv("GSO_SMTP_USERNAME") ?: "",
    "password" => getenv("GSO_SMTP_PASSWORD") ?: "",
    "port" => (int) (getenv("GSO_SMTP_PORT") ?: 587),
    "secure" => getenv("GSO_SMTP_SECURE") ?: "tls",
    "from_email" => getenv("GSO_SMTP_FROM") ?: (getenv("GSO_SMTP_USERNAME") ?: ""),
    "from_name" => getenv("GSO_SMTP_FROM_NAME") ?: "GSO Management System"
];

if (stripos((string) $config["host"], "gmail.com") !== false) {
    $config["password"] = preg_replace('/\s+/', '', (string) $config["password"]);
}

if ($config["username"] === "" || $config["password"] === "") {
    fwrite(STDERR, "GSO_SMTP_USERNAME and GSO_SMTP_PASSWORD must be set before creating mail.local.php." . PHP_EOL);
    exit(1);
}

$content = "<?php\n\nreturn " . var_export($config, true) . ";\n";

if (file_put_contents($configPath, $content) === false) {
    fwrite(STDERR, "Unable to write config/mail.local.php." . PHP_EOL);
    exit(1);
}

echo "Created config/mail.local.php for " . $config["username"] . PHP_EOL;
