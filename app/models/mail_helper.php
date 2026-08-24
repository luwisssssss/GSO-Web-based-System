<?php

require_once dirname(__DIR__, 2) . "/config/bootstrap.php";

require_once dirname(__DIR__, 2) . "/PHPMailer/src/PHPMailer.php";
require_once dirname(__DIR__, 2) . "/PHPMailer/src/SMTP.php";
require_once dirname(__DIR__, 2) . "/PHPMailer/src/Exception.php";

use PHPMailer\PHPMailer\PHPMailer;

if (!function_exists("gsoBuildAppUrl")) {
    function gsoBuildAppUrl(string $path): string
    {
        $configuredBaseUrl = trim((string) gsoEnv("GSO_APP_URL", ""));

        if ($configuredBaseUrl !== "") {
            return rtrim($configuredBaseUrl, "/") . "/" . ltrim($path, "/");
        }

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

if (!function_exists("gsoGetMailConfigurationIssue")) {
    function gsoGetMailConfigurationIssue(array $mailConfig): string
    {
        $host = strtolower(trim((string) ($mailConfig["host"] ?? "")));
        $username = trim((string) ($mailConfig["username"] ?? ""));
        $password = preg_replace('/\s+/', '', (string) ($mailConfig["password"] ?? ""));
        $fromEmail = trim((string) ($mailConfig["from_email"] ?? ""));

        if ($host === "" || $username === "" || $password === "") {
            return "SMTP is not configured. Set the Gmail address and Gmail App Password in the .env file.";
        }

        if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
            return "SMTP username must be a valid Gmail address.";
        }

        if (str_contains($host, "gmail.com")) {
            if (!str_ends_with(strtolower($username), "@gmail.com")) {
                return "Gmail SMTP requires a Gmail address as the SMTP username.";
            }

            if (strlen($password) !== 16) {
                return "Gmail SMTP requires a 16-character App Password. Use an App Password, not your normal Gmail password.";
            }
        }

        if ($fromEmail !== "" && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            return "SMTP from email is invalid. Check GSO_SMTP_FROM in the .env file.";
        }

        return "";
    }
}

if (!function_exists("gsoIsLoopbackAddress")) {
    function gsoIsLoopbackAddress(string $address): bool
    {
        $address = strtolower(trim($address));

        if ($address === "::1") {
            return true;
        }

        if (str_starts_with($address, "::ffff:")) {
            $address = substr($address, 7);
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && preg_match('/\A127(?:\.[0-9]{1,3}){3}\z/', $address) === 1;
    }
}

if (!function_exists("gsoIsLoopbackRequest")) {
    function gsoIsLoopbackRequest(): bool
    {
        $remoteAddress = trim((string) ($_SERVER["REMOTE_ADDR"] ?? ""));

        if ($remoteAddress === "") {
            return PHP_SAPI === "cli";
        }

        return gsoIsLoopbackAddress($remoteAddress);
    }
}

if (!function_exists("gsoAllowLocalMailFallback")) {
    function gsoAllowLocalMailFallback(): bool
    {
        if (!gsoEnvBool("GSO_ALLOW_LOCAL_MAIL_FALLBACK", false)) {
            return false;
        }

        $environment = strtolower(trim((string) gsoEnv("GSO_APP_ENV", "production")));
        if ($environment === "" || in_array($environment, ["production", "prod"], true)) {
            return false;
        }

        return gsoIsLoopbackRequest();
    }
}

if (!function_exists("gsoMailPathIsAbsolute")) {
    function gsoMailPathIsAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, "/");
    }
}

if (!function_exists("gsoNormalizeMailPath")) {
    function gsoNormalizeMailPath(string $path): string
    {
        return rtrim(str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists("gsoMailPathIsWithin")) {
    function gsoMailPathIsWithin(string $path, string $root): bool
    {
        $path = gsoNormalizeMailPath($path);
        $root = gsoNormalizeMailPath($root);

        if (DIRECTORY_SEPARATOR === "\\") {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}

if (!function_exists("gsoDefaultMailOutboxDirectory")) {
    function gsoDefaultMailOutboxDirectory(): string
    {
        $projectRoot = dirname(__DIR__, 2);

        return dirname($projectRoot, 2)
            . DIRECTORY_SEPARATOR . "private"
            . DIRECTORY_SEPARATOR . "GSO_WebSystem"
            . DIRECTORY_SEPARATOR . "mail_outbox";
    }
}

if (!function_exists("gsoMailOutboxDirectory")) {
    function gsoMailOutboxDirectory(): string
    {
        $configured = trim((string) gsoEnv("GSO_MAIL_OUTBOX_DIR", ""));
        $directory = $configured !== "" ? $configured : gsoDefaultMailOutboxDirectory();

        if (!gsoMailPathIsAbsolute($directory)) {
            throw new RuntimeException("Local mail outbox storage must use an absolute path.");
        }

        $directory = gsoNormalizeMailPath($directory);
        if (is_link($directory)) {
            throw new RuntimeException("Local mail outbox storage cannot be a symbolic link.");
        }

        if (!is_dir($directory)
            && !mkdir($directory, 0750, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException("Local mail outbox storage is unavailable.");
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || !is_dir($realDirectory)
            || is_link($directory)
            || is_link($realDirectory)
        ) {
            throw new RuntimeException("Local mail outbox storage is invalid.");
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot !== false && gsoMailPathIsWithin($realDirectory, $projectRoot)) {
            throw new RuntimeException("Local mail outbox storage cannot be inside the application directory.");
        }

        $documentRootValue = trim((string) ($_SERVER["DOCUMENT_ROOT"] ?? ""));
        $documentRoot = $documentRootValue !== "" ? realpath($documentRootValue) : false;
        if ($documentRoot !== false && gsoMailPathIsWithin($realDirectory, $documentRoot)) {
            throw new RuntimeException("Local mail outbox storage cannot be inside the public web root.");
        }

        if (!is_readable($realDirectory) || !is_writable($realDirectory)) {
            throw new RuntimeException("Local mail outbox storage permissions are invalid.");
        }

        return gsoNormalizeMailPath($realDirectory);
    }
}

if (!function_exists("gsoWriteLocalMailOutbox")) {
    function gsoWriteLocalMailOutbox(string $toEmail, string $subject, string $htmlBody, string $altBody): array
    {
        try {
            $outboxDir = gsoMailOutboxDirectory();
            $filename = "mail_" . date("Ymd_His") . "_" . bin2hex(random_bytes(16)) . ".html";
            $path = $outboxDir . DIRECTORY_SEPARATOR . $filename;
            $content = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>"
                . htmlspecialchars($subject, ENT_QUOTES, "UTF-8")
                . "</title></head><body>"
                . "<p><strong>To:</strong> " . htmlspecialchars($toEmail, ENT_QUOTES, "UTF-8") . "</p>"
                . "<p><strong>Subject:</strong> " . htmlspecialchars($subject, ENT_QUOTES, "UTF-8") . "</p>"
                . "<hr>"
                . $htmlBody
                . "<hr><pre>" . htmlspecialchars($altBody, ENT_QUOTES, "UTF-8") . "</pre>"
                . "</body></html>";

            if (file_put_contents($path, $content, LOCK_EX) === false) {
                return [
                    "success" => false,
                    "message" => "Local email outbox file could not be written."
                ];
            }

            if (DIRECTORY_SEPARATOR !== "\\" && !chmod($path, 0640)) {
                if (!unlink($path)) {
                    error_log("Local mail outbox permission cleanup failed for a newly stored message.");
                }

                return [
                    "success" => false,
                    "message" => "Local email outbox file permissions could not be secured."
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
        $configurationIssue = gsoGetMailConfigurationIssue($mailConfig);

        if ($configurationIssue !== "") {
            if (gsoAllowLocalMailFallback()) {
                $outbox = gsoWriteLocalMailOutbox($toEmail, $subject, $htmlBody, $altBody);
                $outbox["channel"] = "local_outbox";
                $outbox["message"] = ($outbox["message"] ?? "Local email outbox created.") . " " . $configurationIssue;

                return $outbox;
            }

            return [
                "success" => false,
                "channel" => "none",
                "message" => $configurationIssue
            ];
        }

        $mail = null;

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
            $errorInfo = $mail instanceof PHPMailer ? (string) $mail->ErrorInfo : "";
            $message = "Email could not be sent.";

            if (stripos($errorInfo, "authenticate") !== false) {
                $message = "SMTP authentication failed. Confirm the Gmail address and 16-character Gmail App Password in the .env file.";
            } elseif (stripos($errorInfo, "connect") !== false) {
                $message = "SMTP connection failed. Check the SMTP host, port, secure setting, or internet access.";
            } elseif (stripos($errorInfo, "from") !== false) {
                $message = "SMTP sender details are invalid. Check GSO_SMTP_FROM and GSO_SMTP_FROM_NAME in the .env file.";
            }

            if (gsoEnvBool("GSO_APP_DEBUG", false) && $errorInfo !== "") {
                $message .= " Details: " . $errorInfo;
            }

            return [
                "success" => false,
                "channel" => "smtp",
                "message" => $message
            ];
        }
    }
}
