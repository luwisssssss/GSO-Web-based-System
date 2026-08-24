<?php
require_once dirname(__DIR__, 3) . "/config/bootstrap.php";
gsoSecureSessionStart();
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";

$message = "";
$messageType = "";
$tokenValid = false;
$user = null;
$csrfValid = true;

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfValid = hash_equals((string) $_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""));
}

$token = trim((string) ($_GET["token"] ?? ""));

if ($token === "" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    $message = "Invalid or missing reset token.";
    $messageType = "error";
} else {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        $token = trim((string) ($_POST["token"] ?? ""));
    }

    if ($token !== "") {
        $tokenHash = hash("sha256", $token);

        $stmt = $pdo->prepare("
            SELECT
                user_id,
                full_name,
                email,
                username,
                password_hash,
                reset_token_hash,
                reset_token_expires_at
            FROM users
            WHERE reset_token_hash = :reset_token_hash
            LIMIT 1
        ");
        $stmt->execute([
            ":reset_token_hash" => $tokenHash
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $message = "This reset link is invalid.";
            $messageType = "error";
        } elseif (empty($user["reset_token_expires_at"])) {
            $message = "This reset link is invalid.";
            $messageType = "error";
        } elseif (strtotime((string) $user["reset_token_expires_at"]) < time()) {
            $message = "This reset link has already expired.";
            $messageType = "error";
        } else {
            $tokenValid = true;
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $newPassword = (string) ($_POST["new_password"] ?? "");
    $confirmPassword = (string) ($_POST["confirm_password"] ?? "");

    if (!$csrfValid) {
        $message = "Invalid request token. Please refresh the page and try again.";
        $messageType = "error";
    } elseif (!$tokenValid || !$user) {
        $message = "This reset link is invalid or already expired.";
        $messageType = "error";
    } elseif ($newPassword === "" || $confirmPassword === "") {
        $message = "Please complete both password fields.";
        $messageType = "error";
    } elseif (strlen($newPassword) < 8) {
        $message = "New password must be at least 8 characters long.";
        $messageType = "error";
    } elseif ($newPassword !== $confirmPassword) {
        $message = "New password and confirm password do not match.";
        $messageType = "error";
    } elseif (password_verify($newPassword, (string) $user["password_hash"])) {
        $message = "New password must be different from your current password.";
        $messageType = "error";
    } else {
        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET
                password_hash = :password_hash,
                reset_token_hash = NULL,
                reset_token_expires_at = NULL
            WHERE user_id = :user_id
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":password_hash" => $newPasswordHash,
            ":user_id" => $user["user_id"]
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                (int) $user["user_id"],
                "Password Reset Success",
                "Password reset completed successfully for account: " . ($user["username"] ?? "Unknown")
            );

            $message = "Password reset completed successfully. You may now sign in using your new password.";
            $messageType = "success";
            $tokenValid = false;
        } else {
            $message = "Failed to reset password.";
            $messageType = "error";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - GSO WebSystem</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../public/assets/css/pages/auth-pages.css?v=20260419-reset-password">
</head>
<body class="auth-page">
    <div class="auth-shell auth-shell--reset">
        <section class="auth-card auth-card--reset">
            <div class="auth-brand">
                <span class="auth-badge">
                    <i class="fa-solid fa-key" aria-hidden="true"></i>
                    Password Reset
                </span>

                <div class="auth-brand-row">
                    <img src="../public/assets/images/login_logo.png" alt="GSO logo" class="auth-logo">
                    <div>
                        <h1 class="auth-title">Create a New Password</h1>
                        <p class="auth-subtitle">
                            Use a strong password that only you know. For security, reset links expire automatically and can only be used once.
                        </p>
                    </div>
                </div>
            </div>

            <?php if ($message !== ""): ?>
                <div class="auth-message <?php echo htmlspecialchars($messageType !== "" ? $messageType : "info"); ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <?php if ($tokenValid && !empty($user)): ?>
                <div class="auth-user-box">
                    Reset request confirmed for <strong><?php echo htmlspecialchars((string) ($user["email"] ?? "")); ?></strong>. Enter your new password below.
                </div>

                <div class="auth-note auth-reset-note">
                    <strong>Password tips</strong>
                    <div class="auth-password-tips">
                        <span><i class="fa-solid fa-check" aria-hidden="true"></i> Use at least 8 characters</span>
                        <span><i class="fa-solid fa-check" aria-hidden="true"></i> Mix letters, numbers, and symbols when possible</span>
                        <span><i class="fa-solid fa-check" aria-hidden="true"></i> Avoid reusing an old password</span>
                    </div>
                </div>

                <form method="POST" class="auth-form" id="resetPasswordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, "UTF-8"); ?>">

                    <div class="auth-field auth-field--icon">
                        <label for="new_password">New Password</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">
                                <i class="fa-solid fa-lock" aria-hidden="true"></i>
                            </span>
                            <input
                                type="password"
                                id="new_password"
                                name="new_password"
                                class="auth-input"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                        <span class="auth-inline-help">Use at least 8 characters.</span>
                    </div>

                    <div class="auth-field auth-field--icon">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">
                                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                            </span>
                            <input
                                type="password"
                                id="confirm_password"
                                name="confirm_password"
                                class="auth-input"
                                autocomplete="new-password"
                                required
                            >
                        </div>
                        <span class="auth-inline-help">Re-enter the same password to confirm the update.</span>
                    </div>

                    <div class="auth-actions">
                        <button type="submit" class="auth-button">
                            <i class="fa-solid fa-shield-heart" aria-hidden="true"></i>
                            Update Password
                        </button>
                        <a href="login.php" class="auth-link-button">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                            Back to Login
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <div class="auth-link-row">
                    <a href="forgot_password.php">Request a new password reset link</a>
                </div>
                <div class="auth-link-row">
                    <a href="login.php">Return to Login</a>
                </div>
            <?php endif; ?>
        </section>

        <aside class="auth-support-card auth-support-card--reset">
            <h3>Security reminders</h3>
            <ul class="auth-support-list">
                <li>Use a password that is not shared with any other account.</li>
                <li>Keep your reset link private and complete the process on a trusted device.</li>
                <li>After changing your password, return to the login page and sign in normally.</li>
            </ul>

            <div class="auth-support-tags">
                <span><i class="fa-solid fa-link-slash" aria-hidden="true"></i> One-time reset link</span>
                <span><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i> Time-limited access</span>
                <span><i class="fa-solid fa-user-shield" aria-hidden="true"></i> Account-safe update</span>
            </div>
        </aside>
    </div>
</body>
</html>
