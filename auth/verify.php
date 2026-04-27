<?php
require_once "../config/db.php";

$success = false;
$message = "The verification link is invalid or has already expired.";
$messageType = "error";

if (isset($_GET["token"])) {
    $token = trim((string) $_GET["token"]);

    if ($token !== "") {
        $stmt = $pdo->prepare("SELECT user_id, email_verified FROM users WHERE verification_token = ? LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $update = $pdo->prepare("
                UPDATE users
                SET email_verified = 1, verification_token = NULL
                WHERE user_id = ?
                LIMIT 1
            ");
            $update->execute([(int) $user["user_id"]]);

            $success = true;
            $message = "Your email address has been verified successfully. You may now continue to the login page and access the system once your account has been approved.";
            $messageType = "success";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/auth-pages.css?v=20260419-verify-page">
</head>
<body class="auth-page">
    <div class="auth-shell auth-shell--single">
        <section class="auth-card">
            <div class="auth-status">
                <img src="../assets/css/img/login_logo.png" alt="GSO logo" class="auth-logo">

                <div class="auth-status-icon <?php echo $success ? "" : "error"; ?>">
                    <i class="fa-solid <?php echo $success ? "fa-circle-check" : "fa-circle-xmark"; ?>" aria-hidden="true"></i>
                </div>

                <span class="auth-badge">
                    <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                    Email Verification
                </span>

                <div>
                    <h2><?php echo $success ? "Verification Successful" : "Verification Unavailable"; ?></h2>
                    <p><?php echo htmlspecialchars($message); ?></p>
                </div>

                <?php if ($success): ?>
                    <ol class="auth-steps">
                        <li>
                            <span class="auth-steps-number">1</span>
                            <span>Return to the login page using the button below.</span>
                        </li>
                        <li>
                            <span class="auth-steps-number">2</span>
                            <span>Sign in after your borrower account has been approved by the administrator.</span>
                        </li>
                        <li>
                            <span class="auth-steps-number">3</span>
                            <span>If access is still pending, wait for the official approval notice from the General Services Office.</span>
                        </li>
                    </ol>
                <?php else: ?>
                    <div class="auth-note">
                        <strong>Next step:</strong> Request a new verification link or contact the administrator if the email has already been used.
                    </div>
                <?php endif; ?>

                <div class="auth-inline-links">
                    <a href="login.php" class="auth-link-button">
                        <i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i>
                        Go to Login
                    </a>
                    <?php if (!$success): ?>
                        <a href="signup.php" class="auth-link-button">
                            <i class="fa-solid fa-user-plus" aria-hidden="true"></i>
                            Back to Signup
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
</body>
</html>
