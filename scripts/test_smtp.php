<?php

require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer/src/SMTP.php";
require_once __DIR__ . "/../PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;

$cfg = require __DIR__ . "/../config/mail.php";

$recipient = $argv[1] ?? ($cfg["username"] ?? "");
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "A valid recipient email is required." . PHP_EOL);
    exit(1);
}

$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = (string) $cfg["host"];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $cfg["username"];
    $smtpPassword = (string) $cfg["password"];
    if (stripos((string) $cfg["host"], "gmail.com") !== false) {
        $smtpPassword = preg_replace('/\s+/', '', $smtpPassword);
    }
    $mail->Password = $smtpPassword;
    $mail->CharSet = "UTF-8";
    $mail->Timeout = 20;

    $secure = strtolower((string) ($cfg["secure"] ?? "tls"));
    if ($secure === "ssl" || $secure === "smtps") {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } elseif ($secure === "tls" || $secure === "starttls") {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }

    $mail->Port = (int) ($cfg["port"] ?? 587);
    $fromEmail = filter_var((string) ($cfg["from_email"] ?? ""), FILTER_VALIDATE_EMAIL)
        ? (string) $cfg["from_email"]
        : (string) $cfg["username"];

    $mail->setFrom($fromEmail, (string) ($cfg["from_name"] ?? "GSO System"));
    $mail->addAddress($recipient, "GSO SMTP Test");
    $mail->isHTML(true);
    $mail->Subject = "GSO SMTP Test";
    $mail->Body = "<p>This is a GSO SMTP test message.</p>";
    $mail->AltBody = "This is a GSO SMTP test message.";
    $mail->send();

    echo "SMTP test sent to {$recipient}." . PHP_EOL;
} catch (Throwable $e) {
    echo "SMTP test failed." . PHP_EOL;
    echo "Username: " . ($cfg["username"] ?? "") . PHP_EOL;
    echo "From: " . ($cfg["from_email"] ?? "") . PHP_EOL;
    echo "Host: " . ($cfg["host"] ?? "") . PHP_EOL;
    echo "Port: " . ($cfg["port"] ?? "") . PHP_EOL;
    echo "Secure: " . ($cfg["secure"] ?? "") . PHP_EOL;
    echo "Error: " . $mail->ErrorInfo . PHP_EOL;
    exit(1);
}
