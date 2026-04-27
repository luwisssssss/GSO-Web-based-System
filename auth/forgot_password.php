<?php
session_start();
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/mail_helper.php";

date_default_timezone_set("Asia/Manila");

$message = "";
$messageType = "";
$mailConfig = require "../config/mail.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if (!empty($_SESSION["forgot_password_message"])) {
    $message = (string) $_SESSION["forgot_password_message"];
    $messageType = (string) ($_SESSION["forgot_password_message_type"] ?? "info");
    unset($_SESSION["forgot_password_message"], $_SESSION["forgot_password_message_type"]);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string) ($_POST["csrf_token"] ?? "");
    $loginInput = trim((string) ($_POST["login_input"] ?? ""));
    $now = time();
    $windowSeconds = 15 * 60;
    $maxAttempts = 5;
    $rateBucket = $_SESSION["forgot_password_rate_limit"] ?? [
        "count" => 0,
        "first_attempt" => $now
    ];

    if (!is_array($rateBucket) || $now - (int) ($rateBucket["first_attempt"] ?? 0) > $windowSeconds) {
        $rateBucket = [
            "count" => 0,
            "first_attempt" => $now
        ];
    }

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token. Please refresh the page and try again.";
        $messageType = "error";
    } elseif ($loginInput === "") {
        $message = "Please enter your email address or username.";
        $messageType = "error";
    } elseif ((int) ($rateBucket["count"] ?? 0) >= $maxAttempts) {
        $message = "Too many reset requests were submitted. Please try again later.";
        $messageType = "error";
    } else {
        $rateBucket["count"] = (int) ($rateBucket["count"] ?? 0) + 1;
        $_SESSION["forgot_password_rate_limit"] = $rateBucket;

        $stmt = $pdo->prepare("
            SELECT user_id, full_name, email, username, role, account_status
            FROM users
            WHERE email = :login_input OR username = :login_input
            LIMIT 1
        ");
        $stmt->execute([
            ":login_input" => $loginInput
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $eligibleUser = $user
            && !(($user["role"] ?? "") === "Borrower" && ($user["account_status"] ?? "") !== "Approved")
            && (($user["account_status"] ?? "") !== "Disabled");

        if ($eligibleUser) {
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = hash("sha256", $plainToken);
            $expiresAt = date("Y-m-d H:i:s", time() + 3600);

            $updateStmt = $pdo->prepare("
                UPDATE users
                SET
                    reset_token_hash = :reset_token_hash,
                    reset_token_expires_at = :reset_token_expires_at
                WHERE user_id = :user_id
                LIMIT 1
            ");

            $success = $updateStmt->execute([
                ":reset_token_hash" => $tokenHash,
                ":reset_token_expires_at" => $expiresAt,
                ":user_id" => $user["user_id"]
            ]);

            if ($success) {
                $resetLink = gsoBuildAppUrl("auth/reset_password.php?token=" . urlencode($plainToken));
                $safeName = htmlspecialchars((string) ($user["full_name"] ?? "User"), ENT_QUOTES, "UTF-8");
                $safeResetLink = htmlspecialchars($resetLink, ENT_QUOTES, "UTF-8");
                $subject = "Reset your GSO account password";
                $htmlBody = "
                    <div style='font-family: Arial, sans-serif; background:#f4f7fb; padding:30px;'>
                        <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:10px; overflow:hidden; border:1px solid #d8e3ef;'>
                            <div style='background:#061a2f; padding:22px; color:#ffffff;'>
                                <h2 style='margin:0;'>GSO Management System</h2>
                                <p style='margin:6px 0 0;'>Password reset request</p>
                            </div>
                            <div style='padding:28px; color:#142235;'>
                                <p>Hi <strong>{$safeName}</strong>,</p>
                                <p>Use the button below to reset your password. This link is valid for 1 hour.</p>
                                <p style='text-align:center; margin:28px 0;'>
                                    <a href='{$safeResetLink}' style='background:#1d4ed8; color:#ffffff; padding:12px 22px; border-radius:6px; text-decoration:none; font-weight:bold;'>Reset Password</a>
                                </p>
                                <p style='font-size:12px;'>If the button does not work, open this link:</p>
                                <p style='word-break:break-all; color:#1d4ed8;'>{$safeResetLink}</p>
                                <p>If you did not request this, you can ignore this email.</p>
                            </div>
                        </div>
                    </div>
                ";

                $mailResult = gsoSendEmail(
                    $mailConfig,
                    (string) $user["email"],
                    (string) $user["full_name"],
                    $subject,
                    $htmlBody,
                    "Reset your GSO password: " . $resetLink
                );

                if ($mailResult["success"] ?? false) {
                    addActivityLog(
                        $pdo,
                        (int) $user["user_id"],
                        "Password Reset Requested",
                        "Password reset instructions sent for account: " . ($user["username"] ?? "Unknown")
                    );
                } else {
                    $clearStmt = $pdo->prepare("
                        UPDATE users
                        SET reset_token_hash = NULL,
                            reset_token_expires_at = NULL
                        WHERE user_id = :user_id
                        LIMIT 1
                    ");
                    $clearStmt->execute([
                        ":user_id" => $user["user_id"]
                    ]);

                    addActivityLog(
                        $pdo,
                        (int) $user["user_id"],
                        "Password Reset Email Failed",
                        "Password reset email could not be sent for account: " . ($user["username"] ?? "Unknown")
                    );
                }
            }
        }

        $message = "If an eligible account exists, password reset instructions will be sent to its registered email address.";
        $messageType = "success";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - GSO WebSystem</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/auth-pages.css?v=20260419-forgot-password">
</head>
<body class="auth-page">
    <div class="auth-shell">
        <section class="auth-card">
            <div class="auth-brand">
                <span class="auth-badge">
                    <i class="fa-solid fa-envelope-circle-check" aria-hidden="true"></i>
                    Account Recovery
                </span>

                <div class="auth-brand-row">
                    <img src="../assets/css/img/login_logo.png" alt="GSO logo" class="auth-logo">
                    <div>
                        <h1 class="auth-title">Forgot Password</h1>
                        <p class="auth-subtitle">
                            Enter your registered email address or username. If the account is eligible, the system will send a secure password reset link.
                        </p>
                    </div>
                </div>
            </div>

            <div class="auth-note">
                <strong>Instructions:</strong> Use the same email address or username associated with your GSO Borrowing System account. Reset links remain valid for one hour.
            </div>

            <?php if ($message !== ""): ?>
                <div class="auth-message <?php echo htmlspecialchars($messageType !== "" ? $messageType : "info"); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="auth-form" id="forgotPasswordForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">

                <div class="auth-field">
                    <label for="login_input">Email Address or Username</label>
                    <input
                        type="text"
                        id="login_input"
                        name="login_input"
                        class="auth-input"
                        placeholder="Enter your email address or username"
                        value="<?php echo htmlspecialchars((string) ($_POST["login_input"] ?? ""), ENT_QUOTES, "UTF-8"); ?>"
                        autocomplete="username"
                        required
                    >
                    <span class="auth-inline-help">Recovery instructions will only be sent to eligible and active accounts.</span>
                </div>

                <div class="auth-actions">
                    <button type="submit" class="auth-button" id="forgotPasswordButton">
                        <span class="button-spinner" aria-hidden="true"></span>
                        <span class="button-text">Send Reset Instructions</span>
                    </button>
                    <a href="login.php" class="auth-link-button">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        Back to Login
                    </a>
                </div>
            </form>
        </section>

        <aside class="auth-support-card">
            <h3>What happens next?</h3>
            <ol class="auth-steps">
                <li>
                    <span class="auth-steps-number">1</span>
                    <span>The system verifies whether the submitted account can receive a reset link.</span>
                </li>
                <li>
                    <span class="auth-steps-number">2</span>
                    <span>A secure reset email is sent to the registered address when the account is eligible.</span>
                </li>
                <li>
                    <span class="auth-steps-number">3</span>
                    <span>Open the link, create a new password, and then return to the login page.</span>
                </li>
            </ol>
        </aside>
    </div>

    <script>
    const forgotPasswordForm = document.getElementById("forgotPasswordForm");
    const forgotPasswordButton = document.getElementById("forgotPasswordButton");

    if (forgotPasswordForm && forgotPasswordButton) {
        forgotPasswordForm.addEventListener("submit", function () {
            forgotPasswordButton.disabled = true;
            forgotPasswordButton.classList.add("is-loading");
        });
    }
    </script>
</body>
</html>
