<?php
session_start();
require_once "../config/db.php";


require_once "../includes/mail_helper.php";
require_once "../includes/upload_helper.php";
$message = "";
$localVerificationLink = "";
$localVerificationPath = "";
$mailConfig = require "../config/mail.php";

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
        "lock_until" => 0
    ];

    if (!is_array($bucket) || $now - (int) ($bucket["first_attempt"] ?? 0) > $windowSeconds) {
        $bucket = [
            "count" => 0,
            "first_attempt" => $now,
            "lock_until" => 0
        ];
    }

    $bucket["count"] = (int) ($bucket["count"] ?? 0) + 1;

    if ($bucket["count"] >= $maxAttempts) {
        $bucket["lock_until"] = $now + $windowSeconds;
    }

    $_SESSION["signup_rate_limit"] = $bucket;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !hash_equals((string) $_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))) {
    $message = "Invalid request token. Please refresh the page and try again.";
} elseif ($_SERVER["REQUEST_METHOD"] === "POST" && gsoSignupRateLimitMessage() !== "") {
    $message = gsoSignupRateLimitMessage();
} elseif ($_SERVER["REQUEST_METHOD"] === "POST") {
    gsoRecordSignupAttempt();

    $full_name = trim($_POST["full_name"]);
    $department = trim($_POST["department"]);
    $university_id = trim($_POST["university_id"]);
    $email = strtolower(trim($_POST["email"]));
    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    $confirm_password = $_POST["confirm_password"] ?? "";
    $role = "Borrower";

    $account_status = "Pending";

    if (
        empty($full_name) ||
        empty($email) ||
        empty($username) ||
        empty($password)
    ) {
        $message = "Please fill in all required fields.";
    } 
    elseif ($password !== $confirm_password) {
        $message = "Passwords do not match.";
    }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    }
    elseif (!isset($_FILES["uploaded_id"]) || (int) ($_FILES["uploaded_id"]["error"] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $message = "Please upload your ID.";
    } elseif ((int) ($_FILES["uploaded_id"]["error"] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $message = gsoUploadErrorMessage((int) $_FILES["uploaded_id"]["error"], "ID file", 5 * 1024 * 1024);
    } else {
        $tmpFile = $_FILES["uploaded_id"]["tmp_name"];
        $fileSize = $_FILES["uploaded_id"]["size"];
        $fileType = gsoDetectUploadedMime($tmpFile);

        $allowedTypes = ["image/jpeg", "image/png", "application/pdf"];

        if (!in_array($fileType, $allowedTypes)) {
            $message = "Only JPG, PNG, and PDF files are allowed.";
        } elseif ($fileSize > 5 * 1024 * 1024) {
            $message = "File size must not exceed 5MB.";
        } else {
            $checkSql = "SELECT user_id
                         FROM users
                         WHERE email = :email
                            OR username = :username";
            $checkParams = [
                ":email" => $email,
                ":username" => $username
            ];

            if ($university_id !== "") {
                $checkSql .= " OR university_id = :university_id";
                $checkParams[":university_id"] = $university_id;
            }

            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute($checkParams);

            $existing = $checkStmt->fetch();

                if ($existing) {

                    // CHECK EACH FIELD
                    $checkEmail = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                    $checkEmail->execute([$email]);

                    $checkUsername = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
                    $checkUsername->execute([$username]);

                    $hasDuplicateId = false;
                    if ($university_id !== "") {
                        $checkID = $pdo->prepare("SELECT user_id FROM users WHERE university_id = ?");
                        $checkID->execute([$university_id]);
                        $hasDuplicateId = (bool) $checkID->fetch();
                    }

                    if ($hasDuplicateId) {
                        $message = "University ID already exists.";
                    } elseif ($checkEmail->fetch()) {
                        $message = "Email already exists.";
                    } elseif ($checkUsername->fetch()) {
                        $message = "Username already exists.";
                    }

                } else {

                $mailDeliveryAvailable = gsoIsSmtpConfigured($mailConfig) || gsoAllowLocalMailFallback();

                if (!$mailDeliveryAvailable) {
                    $message = "Email verification is not configured. Please contact the administrator.";
                } else {
                    try {
                $uploadedIdData = file_get_contents($tmpFile);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);

                /* 🔥 ADD TOKEN */
                $token = bin2hex(random_bytes(32));

                $sql = "INSERT INTO users
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
                            :token,
                            0
                        )";

                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(":full_name", $full_name);
                $stmt->bindValue(":department", $department !== "" ? $department : null);
                $stmt->bindValue(":university_id", $university_id !== "" ? $university_id : null);
                $stmt->bindValue(":email", $email);
                $stmt->bindValue(":uploaded_id", $uploadedIdData, PDO::PARAM_LOB);
                $stmt->bindValue(":uploaded_id_type", $fileType);
                $stmt->bindValue(":role", $role);
                $stmt->bindValue(":username", $username);
                $stmt->bindValue(":password_hash", $passwordHash);
                $stmt->bindValue(":account_status", $account_status);
                $stmt->bindValue(":token", $token);

                $pdo->beginTransaction();

                if ($stmt->execute()) {
                    $verifyLink = gsoBuildAppUrl("auth/verify.php?token=" . urlencode($token));
                    $safeName = htmlspecialchars($full_name, ENT_QUOTES, "UTF-8");
                    $safeVerifyLink = htmlspecialchars($verifyLink, ENT_QUOTES, "UTF-8");
                    $logoUrl = htmlspecialchars(gsoBuildAppUrl("assets/css/img/logo.png"), ENT_QUOTES, "UTF-8");
                    $subject = "Verify your GSO account";
                    $htmlBody = "
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
                    ";
                    $mailResult = gsoSendEmail(
                        $mailConfig,
                        $email,
                        $full_name,
                        $subject,
                        $htmlBody,
                        "Verify your GSO account: " . $verifyLink
                    );

                    if (!($mailResult["success"] ?? false)) {
                        throw new Exception((string) ($mailResult["message"] ?? "Verification email was not sent."));
                    }

                    $pdo->commit();
                    unset($_SESSION["signup_rate_limit"]);

                    if (($mailResult["channel"] ?? "") === "local_outbox") {
                        $localVerificationLink = $verifyLink;
                        $localVerificationPath = (string) ($mailResult["path"] ?? "");
                        $message = "Signup successful. SMTP is not configured, so a local verification message was created for this development setup.";
                    } else {
                        $message = "Signup successful! Please check your email to verify your account.";
                    }

                    if (false) {

                    /* 🔥 SEND EMAIL */
                    $smtpConfigured = !empty($mailConfig["username"]) && !empty($mailConfig["password"]);

                    if ($smtpConfigured) {
                        $mail = new PHPMailer(true);

                        try {
                            $mail->isSMTP();
                            $mail->Host = $mailConfig["host"];
                            $mail->SMTPAuth = true;
                            $mail->Username = $mailConfig["username"];
                            $mail->Password = $mailConfig["password"];
                            $mail->CharSet = "UTF-8";
                            $mail->Timeout = 20;
                            $secure = strtolower((string) $mailConfig["secure"]);
                            if ($secure === "ssl" || $secure === "smtps") {
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                            } else {
                                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            }
                            $mail->Port = (int) $mailConfig["port"];

                            $fromEmail = filter_var((string) ($mailConfig["from_email"] ?? ""), FILTER_VALIDATE_EMAIL)
                                ? (string) $mailConfig["from_email"]
                                : (string) $mailConfig["username"];
                            $mail->setFrom($fromEmail, $mailConfig["from_name"]);
                            $mail->addAddress($email, $full_name);

                            $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
                            $hostName = $_SERVER["HTTP_HOST"] ?? "localhost";
                            $scriptDir = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/GSO_WebSystem/auth/signup.php")), "/");
                            $verifyLink = $scheme . "://" . $hostName . $scriptDir . "/verify.php?token=" . urlencode($token);
                            $safeName = htmlspecialchars($full_name, ENT_QUOTES, "UTF-8");
                            $safeVerifyLink = htmlspecialchars($verifyLink, ENT_QUOTES, "UTF-8");

                            $mail->isHTML(true);
                            $mail->Subject = 'Verify your account';
                            $mail->Body = "
                        <div style='font-family: Arial, sans-serif; background:#f4f6f9; padding:30px;'>

                            <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 10px rgba(0,0,0,0.1);'>
                                
                                <!-- HEADER -->
                                <div style='background:#061a2f; padding:25px; text-align:center; color:white;'>

                                    <img src='http://localhost/GSO_WebSystem/assets/css/img/logo.png' 
                                        style='width:70px; margin-bottom:10px;'>

                                    <h2 style='margin:0;'>General Services Office</h2>
                                    <p style='margin:0; font-size:14px;'>Borrowing System</p>
                                </div>

                                <!-- CONTENT -->
                                <div style='padding:30px; color:#333;'>
                                    <h3>Verify Your Email</h3>

                                    <p>Hi <strong>{$safeName}</strong>,</p>

                                    <p>Please verify your email to activate your account.</p>

                                    <div style='text-align:center; margin:30px 0;'>
                                        <a href='{$safeVerifyLink}' 
                                        style='background:#2563eb; color:white; padding:12px 25px; 
                                        text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>
                                        Verify Account
                                        </a>
                                    </div>

                                    <p style='font-size:12px;'>If button doesn't work:</p>
                                    <p style='word-break:break-all; color:#2563eb;'>{$safeVerifyLink}</p>
                                </div>

                                <!-- FOOTER -->
                                <div style='background:#f1f5f9; padding:15px; text-align:center; font-size:12px; color:#64748b;'>
                                    (c) " . date('Y') . " GSO System
                                </div>

                            </div>

                        </div>
                        ";

                        $mail->send();
                        $pdo->commit();

                        $message = "Signup successful! Please check your email to verify your account.";

                        } catch (Exception $e) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $message = "Signup could not be completed because the verification email was not sent. Please try again or contact the administrator.";
                        }
                    } else {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }

                        $message = "Email verification is not configured. Please contact the administrator.";
                    }
                }
                }
                if (false) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $message = "Signup failed.";
                }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $detail = trim($e->getMessage());
                    $message = "Signup could not be completed because the verification email was not sent."
                        . ($detail !== "" ? " {$detail}" : " Please try again or contact the administrator.");
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
    <link rel="stylesheet" href="../assets/css/signup.css?v=20260415-logo-clean">
    <title>Signup</title>

    <!-- ✅ ADDED -->
    <style>
        .password-msg {
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="page-wrapper">
        <div class="signup-container">
            <div class="form-header">
                <img src="../assets/css/img/login_logo.png" alt="Logo" class="logo">
                <div class="header-text">
                    <h2>Signup</h2>
                    <p>Create your institutional account to access university equipment and resources.</p>
                </div>
            </div>

            <?php if (!empty($message)): ?>
                <p class="message"><?php echo htmlspecialchars($message); ?></p>
            <?php endif; ?>

            <?php if (!empty($localVerificationLink)): ?>
                <div class="message" style="text-align:left; line-height:1.6;">
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
                        <input type="text" name="full_name" placeholder="Enter your full name" required>
                    </div>

                    <div class="form-group">
                        <label>Department:</label>
                        <select name="department" required> 
                            <option value="" disabled selected hidden>Select Department</option>
                            <option value="College of Accountancy">College of Accountancy</option>
                            <option value="College of Computer Studies">College of Computer Studies</option>
                            <option value="College of Business">College of Business</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>University ID:</label>
                        <input type="text" name="university_id" placeholder="Enter your university ID">
                    </div>

                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" placeholder="Enter your email" required>
                    </div>

                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" placeholder="Enter your username" required>
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
            passwordMsg.textContent = "Strong password ✔";
            passwordMsg.style.color = "#1d4ed8";
        }
    });

    confirmPassword.addEventListener("input", () => {
        if (confirmPassword.value !== password.value) {
            confirmMsg.textContent = "Passwords do not match";
            confirmMsg.style.color = "red";
        } else {
            confirmMsg.textContent = "Passwords match ✔";
            confirmMsg.style.color = "#1d4ed8";
        }
    });
    </script>

</body>
</html>
