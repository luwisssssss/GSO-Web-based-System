<?php
require_once dirname(__DIR__, 3) . "/config/bootstrap.php";
gsoSecureSessionStart();
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/mail_helper.php";
require_once dirname(__DIR__, 3) . "/includes/upload_helper.php";

$message = "";
$localVerificationLink = "";
$localVerificationPath = "";
$mailConfig = require "../config/mail.php";
$formData = [
    "full_name" => "",
    "department" => "",
    "university_id" => "",
    "email" => "",
    "username" => "",
];

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function gsoSignupRateLimitMessage(): string
{
    $now = time();
    $windowSeconds = 15 * 60;
    $bucket = $_SESSION["signup_rate_limit"] ?? null;

    if (!is_array($bucket)) {
        return "";
    }

    if (!empty($bucket["lock_until"]) && (int) $bucket["lock_until"] > $now) {
        $remainingMinutes = max(1, (int) ceil(((int) $bucket["lock_until"] - $now) / 60));
        return "Too many signup attempts. Please try again in {$remainingMinutes} minute(s).";
    }

    if (!empty($bucket["first_attempt"]) && $now - (int) $bucket["first_attempt"] > $windowSeconds) {
        unset($_SESSION["signup_rate_limit"]);
    }

    return "";
}

function gsoRecordSignupAttempt(): void
{
    $now = time();
    $windowSeconds = 15 * 60;
    $maxAttempts = 5;
    $bucket = $_SESSION["signup_rate_limit"] ?? [
        "count" => 0,
        "first_attempt" => $now,
        "lock_until" => 0,
    ];

    if (!is_array($bucket) || $now - (int) ($bucket["first_attempt"] ?? 0) > $windowSeconds) {
        $bucket = [
            "count" => 0,
            "first_attempt" => $now,
            "lock_until" => 0,
        ];
    }

    $bucket["count"] = (int) ($bucket["count"] ?? 0) + 1;

    if ($bucket["count"] >= $maxAttempts) {
        $bucket["lock_until"] = $now + $windowSeconds;
    }

    $_SESSION["signup_rate_limit"] = $bucket;
}

function gsoBuildSignupVerificationEmail(string $fullName, string $verifyLink): array
{
    $safeName = htmlspecialchars($fullName, ENT_QUOTES, "UTF-8");
    $safeVerifyLink = htmlspecialchars($verifyLink, ENT_QUOTES, "UTF-8");
    $logoUrl = htmlspecialchars(gsoBuildAppUrl("public/assets/images/logo.png"), ENT_QUOTES, "UTF-8");

    return [
        "subject" => "Verify your GSO account",
        "html" => "
            <div style='font-family: Arial, sans-serif; background:#f4f6f9; padding:30px;'>
                <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 10px rgba(0,0,0,0.1);'>
                    <div style='background:#061a2f; padding:25px; text-align:center; color:white;'>
                        <img src='{$logoUrl}' style='width:70px; margin-bottom:10px;' alt='GSO Logo'>
                        <h2 style='margin:0;'>General Services Office</h2>
                        <p style='margin:0; font-size:14px;'>Borrowing System</p>
                    </div>
                    <div style='padding:30px; color:#333;'>
                        <h3>Verify Your Email</h3>
                        <p>Hi <strong>{$safeName}</strong>,</p>
                        <p>Please verify your email to activate your account.</p>
                        <div style='text-align:center; margin:30px 0;'>
                            <a href='{$safeVerifyLink}' style='background:#2563eb; color:white; padding:12px 25px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>Verify Account</a>
                        </div>
                        <p style='font-size:12px;'>If the button does not work, open this link:</p>
                        <p style='word-break:break-all; color:#2563eb;'>{$safeVerifyLink}</p>
                    </div>
                    <div style='background:#f1f5f9; padding:15px; text-align:center; font-size:12px; color:#64748b;'>
                        &copy; " . date("Y") . " GSO System
                    </div>
                </div>
            </div>
        ",
        "alt" => "Verify your GSO account: " . $verifyLink,
    ];
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $formData["full_name"] = trim((string) ($_POST["full_name"] ?? ""));
    $formData["department"] = trim((string) ($_POST["department"] ?? ""));
    $formData["university_id"] = trim((string) ($_POST["university_id"] ?? ""));
    $formData["email"] = strtolower(trim((string) ($_POST["email"] ?? "")));
    $formData["username"] = trim((string) ($_POST["username"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $confirmPassword = (string) ($_POST["confirm_password"] ?? "");
    $uploadedId = $_FILES["uploaded_id"] ?? null;

    if (!hash_equals((string) $_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))) {
        $message = "Invalid request token. Please refresh the page and try again.";
    } elseif (gsoSignupRateLimitMessage() !== "") {
        $message = gsoSignupRateLimitMessage();
    } else {
        gsoRecordSignupAttempt();

        if (
            $formData["full_name"] === ""
            || $formData["department"] === ""
            || $formData["email"] === ""
            || $formData["username"] === ""
            || $password === ""
            || $confirmPassword === ""
        ) {
            $message = "Please fill in all required fields.";
        } elseif (!filter_var($formData["email"], FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
        } elseif (strlen($password) < 8) {
            $message = "Password must be at least 8 characters long.";
        } elseif ($password !== $confirmPassword) {
            $message = "Passwords do not match.";
        } elseif (!is_array($uploadedId) || (int) ($uploadedId["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $message = "Please upload your ID.";
        } elseif ((int) ($uploadedId["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            $message = gsoUploadErrorMessage((int) $uploadedId["error"], "ID file", 5 * 1024 * 1024);
        } else {
            $tmpFile = (string) ($uploadedId["tmp_name"] ?? "");
            $fileSize = (int) ($uploadedId["size"] ?? 0);
            $fileType = gsoDetectUploadedMime($tmpFile);
            $allowedTypes = ["image/jpeg", "image/png", "application/pdf"];

            if (!is_uploaded_file($tmpFile)) {
                $message = "The uploaded ID file is invalid. Please try again.";
            } elseif (!in_array($fileType, $allowedTypes, true)) {
                $message = "Only JPG, PNG, and PDF files are allowed.";
            } elseif ($fileSize <= 0) {
                $message = "The uploaded ID file is empty.";
            } elseif ($fileSize > 5 * 1024 * 1024) {
                $message = "File size must not exceed 5MB.";
            } else {
                $checkStmt = $pdo->prepare("
                    SELECT email, username, university_id
                    FROM users
                    WHERE email = :email
                       OR username = :username
                       OR (:has_university_id = 1 AND university_id = :university_id)
                ");
                $checkStmt->execute([
                    ":email" => $formData["email"],
                    ":username" => $formData["username"],
                    ":has_university_id" => $formData["university_id"] !== "" ? 1 : 0,
                    ":university_id" => $formData["university_id"],
                ]);

                $duplicates = $checkStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($duplicates as $duplicate) {
                    if (
                        $formData["university_id"] !== ""
                        && ($duplicate["university_id"] ?? "") === $formData["university_id"]
                    ) {
                        $message = "University ID already exists.";
                        break;
                    }

                    if (($duplicate["email"] ?? "") === $formData["email"]) {
                        $message = "Email already exists.";
                        break;
                    }

                    if (($duplicate["username"] ?? "") === $formData["username"]) {
                        $message = "Username already exists.";
                        break;
                    }
                }

                if ($message === "") {
                    $configurationIssue = gsoGetMailConfigurationIssue($mailConfig);

                    if ($configurationIssue !== "" && !gsoAllowLocalMailFallback()) {
                        $message = $configurationIssue;
                    } else {
                        try {
                            $uploadedIdData = file_get_contents($tmpFile);

                            if ($uploadedIdData === false) {
                                throw new RuntimeException("The uploaded ID file could not be processed.");
                            }

                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $verificationToken = bin2hex(random_bytes(32));

                            $stmt = $pdo->prepare("
                                INSERT INTO users
                                (
                                    full_name,
                                    department,
                                    university_id,
                                    email,
                                    uploaded_id,
                                    uploaded_id_type,
                                    role,
                                    username,
                                    password_hash,
                                    account_status,
                                    approved_by,
                                    approved_at,
                                    verification_token,
                                    email_verified
                                )
                                VALUES
                                (
                                    :full_name,
                                    :department,
                                    :university_id,
                                    :email,
                                    :uploaded_id,
                                    :uploaded_id_type,
                                    :role,
                                    :username,
                                    :password_hash,
                                    :account_status,
                                    NULL,
                                    NULL,
                                    :verification_token,
                                    0
                                )
                            ");
                            $stmt->bindValue(":full_name", $formData["full_name"]);
                            $stmt->bindValue(":department", $formData["department"] !== "" ? $formData["department"] : null);
                            $stmt->bindValue(":university_id", $formData["university_id"] !== "" ? $formData["university_id"] : null);
                            $stmt->bindValue(":email", $formData["email"]);
                            $stmt->bindValue(":uploaded_id", $uploadedIdData, PDO::PARAM_LOB);
                            $stmt->bindValue(":uploaded_id_type", $fileType);
                            $stmt->bindValue(":role", "Borrower");
                            $stmt->bindValue(":username", $formData["username"]);
                            $stmt->bindValue(":password_hash", $passwordHash);
                            $stmt->bindValue(":account_status", "Pending");
                            $stmt->bindValue(":verification_token", $verificationToken);

                            $pdo->beginTransaction();
                            $stmt->execute();

                            $verifyLink = gsoBuildAppUrl("auth/verify.php?token=" . urlencode($verificationToken));
                            $emailContent = gsoBuildSignupVerificationEmail($formData["full_name"], $verifyLink);
                            $mailResult = gsoSendEmail(
                                $mailConfig,
                                $formData["email"],
                                $formData["full_name"],
                                (string) $emailContent["subject"],
                                (string) $emailContent["html"],
                                (string) $emailContent["alt"]
                            );

                            if (!($mailResult["success"] ?? false)) {
                                throw new RuntimeException((string) ($mailResult["message"] ?? "Verification email was not sent."));
                            }

                            $pdo->commit();
                            unset($_SESSION["signup_rate_limit"]);

                            if (($mailResult["channel"] ?? "") === "local_outbox") {
                                $localVerificationLink = $verifyLink;
                                $localVerificationPath = (string) ($mailResult["path"] ?? "");
                                $message = "Signup successful. A local verification message was created because SMTP is not available in this development setup.";
                            } else {
                                $message = "Signup successful! Please check your email to verify your account.";
                            }

                            $formData = [
                                "full_name" => "",
                                "department" => "",
                                "university_id" => "",
                                "email" => "",
                                "username" => "",
                            ];
                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $detail = trim($e->getMessage());
                            $message = "Signup could not be completed because the verification email was not sent.";

                            if ($detail !== "") {
                                $message .= " " . $detail;
                            }
                        }
                    }
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../public/assets/css/pages/signup.css?v=20260415-logo-clean">
    <title>Signup</title>

    <link rel="stylesheet" href="../public/assets/css/pages/auth-signup.css">
</head>
<body>
    <div class="page-wrapper">
        <div class="signup-container">
            <div class="form-header">
                <img src="../public/assets/images/login_logo.png" alt="Logo" class="logo">
                <div class="header-text">
                    <h2>Signup</h2>
                    <p>Create your institutional account to access university equipment and resources.</p>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (!empty($localVerificationLink)): ?>
                <div class="message message-readable">
                    <strong>Local verification:</strong>
                    <a href="<?php echo htmlspecialchars($localVerificationLink); ?>">Open verification link</a>
                    <?php if (!empty($localVerificationPath)): ?>
                        <br><small>Saved email preview: <?php echo htmlspecialchars($localVerificationPath); ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">

                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name:</label>
                        <input type="text" name="full_name" placeholder="Enter your full name" value="<?php echo htmlspecialchars($formData["full_name"], ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Department:</label>
                        <select name="department" required>
                            <option value="" disabled <?php echo $formData["department"] === "" ? "selected" : ""; ?> hidden>Select Department</option>
                            <option value="College of Accountancy" <?php echo $formData["department"] === "College of Accountancy" ? "selected" : ""; ?>>College of Accountancy</option>
                            <option value="College of Computer Studies" <?php echo $formData["department"] === "College of Computer Studies" ? "selected" : ""; ?>>College of Computer Studies</option>
                            <option value="College of Business" <?php echo $formData["department"] === "College of Business" ? "selected" : ""; ?>>College of Business</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>University ID:</label>
                        <input type="text" name="university_id" placeholder="Enter your university ID" value="<?php echo htmlspecialchars($formData["university_id"], ENT_QUOTES, "UTF-8"); ?>">
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" placeholder="Enter your email" value="<?php echo htmlspecialchars($formData["email"], ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" placeholder="Enter your username" value="<?php echo htmlspecialchars($formData["username"], ENT_QUOTES, "UTF-8"); ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" id="password" placeholder="Enter your password" required>
                        <div id="passwordMsg" class="password-msg"></div>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password:</label>
                        <input type="password" name="confirm_password" id="confirmPassword" placeholder="Confirm your password" required>
                        <div id="confirmMsg" class="password-msg"></div>
                    </div>

                    <div class="upload-group form-group full-width">
                        <label>Upload ID:</label>
                        <div class="upload-box">
                            <input type="file" name="uploaded_id" accept=".jpg,.jpeg,.png,.pdf" required>
                            <div class="upload-hint">
                                Accepted formats: JPG, JPEG, PNG, PDF
                            </div>
                        </div>
                    </div>

                    <div class="full-width">
                        <button type="submit" class="signup-btn">Create Account</button>
                    </div>
                </div>
            </form>

            <p class="login-link">Already have an account? <a href="login.php">Back to Login</a></p>

            <div class="info-row">
                <div class="info-card">
                    <strong>ID REQUIREMENT</strong>
                    Use your official format ID for validation.
                </div>
                <div class="info-card">
                    <strong>VERIFICATION</strong>
                    Your account details may be checked before approval.
                </div>
            </div>
        </div>
    </div>

    <script>
    const password = document.getElementById("password");
    const confirmPassword = document.getElementById("confirmPassword");
    const passwordMsg = document.getElementById("passwordMsg");
    const confirmMsg = document.getElementById("confirmMsg");

    password.addEventListener("input", () => {
        if (password.value.length < 8) {
            passwordMsg.textContent = "Weak (minimum 8 characters)";
            passwordMsg.style.color = "red";
        } else {
            passwordMsg.textContent = "Strong password";
            passwordMsg.style.color = "#1d4ed8";
        }
    });

    confirmPassword.addEventListener("input", () => {
        if (confirmPassword.value !== password.value) {
            confirmMsg.textContent = "Passwords do not match";
            confirmMsg.style.color = "red";
        } else {
            confirmMsg.textContent = "Passwords match";
            confirmMsg.style.color = "#1d4ed8";
        }
    });
    </script>
</body>
</html>
