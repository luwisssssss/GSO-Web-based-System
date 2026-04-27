<?php

require_once __DIR__ . "/../PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../PHPMailer/src/SMTP.php";
require_once __DIR__ . "/../PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists("gsoBuildAppUrl")) {
    function gsoBuildAppUrl(string $path): string
    {
        $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $hostName = $_SERVER["HTTP_HOST"] ?? "localhost";
        $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/GSO_WebSystem/index.php")), "/");
        $appBase = preg_replace("#/(auth|admin|borrower|pages)$#", "", $scriptDir);

        return $scheme . "://" . $hostName . rtrim((string) $appBase, "/") . "/" . ltrim($path, "/");
    }
}

if (!function_exists("gsoIsSmtpConfigured")) {
    function gsoIsSmtpConfigured(array $mailConfig): bool
    {
        return !empty($mailConfig["host"])
            && !empty($mailConfig["username"])
            && !empty($mailConfig["password"]);
    }
}

if (!function_exists("gsoIsLocalRequest")) {
    function gsoIsLocalRequest(): bool
    {
        $host = strtolower((string) ($_SERVER["HTTP_HOST"] ?? "localhost"));

        return $host === ""
            || str_contains($host, "localhost")
            || str_contains($host, "127.0.0.1")
            || str_contains($host, "::1");
    }
}

if (!function_exists("gsoAllowLocalMailFallback")) {
    function gsoAllowLocalMailFallback(): bool
    {
        $explicit = getenv("GSO_ALLOW_LOCAL_MAIL_FALLBACK");

        if ($explicit !== false && $explicit !== "") {
            return $explicit === "1";
        }

        return gsoIsLocalRequest();
    }
}

if (!function_exists("gsoWriteLocalMailOutbox")) {
    function gsoWriteLocalMailOutbox(string $toEmail, string $subject, string $htmlBody, string $altBody): array
    {
        try {
            $outboxDir = __DIR__ . "/../uploads_temp/email_outbox";

            if (!is_dir($outboxDir) && !mkdir($outboxDir, 0775, true) && !is_dir($outboxDir)) {
                return [
                    "success" => false,
                    "message" => "Local email outbox could not be created."
                ];
            }

            $safeEmail = preg_replace('/[^a-z0-9._-]+/i', "_", $toEmail);
            $filename = "mail_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "_" . $safeEmail . ".html";
            $path = $outboxDir . "/" . $filename;
            $content = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>"
                . htmlspecialchars($subject, ENT_QUOTES, "UTF-8")
                . "</title></head><body>"
                . "<p><strong>To:</strong> " . htmlspecialchars($toEmail, ENT_QUOTES, "UTF-8") . "</p>"
                . "<p><strong>Subject:</strong> " . htmlspecialchars($subject, ENT_QUOTES, "UTF-8") . "</p>"
                . "<hr>"
                . $htmlBody
                . "<hr><pre>" . htmlspecialchars($altBody, ENT_QUOTES, "UTF-8") . "</pre>"
                . "</body></html>";

            if (file_put_contents($path, $content) === false) {
                return [
                    "success" => false,
                    "message" => "Local email outbox file could not be written."
                ];
            }

            return [
                "success" => true,
                "message" => "Local verification email was created.",
                "path" => $path
            ];
        } catch (Throwable $e) {
            return [
                "success" => false,
                "message" => "Local email outbox failed."
            ];
        }
    }
}

if (!function_exists("gsoSendEmail")) {
    function gsoSendEmail(
        array $mailConfig,
        string $toEmail,
        string $toName,
        string $subject,
        string $htmlBody,
        string $altBody
    ): array {
        if (!gsoIsSmtpConfigured($mailConfig)) {
            if (gsoAllowLocalMailFallback()) {
                $outbox = gsoWriteLocalMailOutbox($toEmail, $subject, $htmlBody, $altBody);
                $outbox["channel"] = "local_outbox";

                return $outbox;
            }

            return [
                "success" => false,
                "channel" => "none",
                "message" => "SMTP is not configured."
            ];
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = (string) $mailConfig["host"];
            $mail->SMTPAuth = true;
            $mail->Username = (string) $mailConfig["username"];
            $smtpPassword = (string) $mailConfig["password"];
            if (stripos((string) $mailConfig["host"], "gmail.com") !== false) {
                $smtpPassword = preg_replace('/\s+/', '', $smtpPassword);
            }
            $mail->Password = $smtpPassword;
            $mail->CharSet = "UTF-8";
            $mail->Timeout = 20;

            $secure = strtolower((string) ($mailConfig["secure"] ?? "tls"));
            if ($secure === "ssl" || $secure === "smtps") {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === "tls" || $secure === "starttls") {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->Port = (int) ($mailConfig["port"] ?? 587);
            $fromEmail = filter_var((string) ($mailConfig["from_email"] ?? ""), FILTER_VALIDATE_EMAIL)
                ? (string) $mailConfig["from_email"]
                : (string) $mailConfig["username"];

            $mail->setFrom($fromEmail, (string) ($mailConfig["from_name"] ?? "GSO System"));
            $mail->addAddress($toEmail, $toName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $altBody;
            $mail->send();

            return [
                "success" => true,
                "channel" => "smtp",
                "message" => "Email sent."
            ];
        } catch (Throwable $e) {
            $errorInfo = (string) $mail->ErrorInfo;
            $message = "Email could not be sent.";

            if (stripos($errorInfo, "authenticate") !== false) {
                $message = "SMTP authentication failed. Check the Gmail address and Gmail App Password.";
            } elseif (stripos($errorInfo, "connect") !== false) {
                $message = "SMTP connection failed. Check the SMTP host, port, secure setting, or internet access.";
            }

            return [
                "success" => false,
                "channel" => "smtp",
                "message" => $message
            ];
        }
    }
}
