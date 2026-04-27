<?php
session_start();
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/role_helper.php";

date_default_timezone_set("Asia/Manila");

$message = "";
$messageType = "info";
$loginInputValue = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function gsoDefaultLoginGuard(): array
{
    return [
        "failed_attempts" => 0,
        "lock_until" => 0,
        "post_lock_mode" => false,
        "post_lock_remaining" => 0,
        "show_reset_notice" => false,
    ];
}

function gsoSaveLoginGuard(array $guard): void
{
    $_SESSION["login_guard"] = array_merge(gsoDefaultLoginGuard(), $guard);
}

function gsoClearLoginGuard(): void
{
    unset($_SESSION["login_guard"]);
}

function gsoGetLoginGuard(): array
{
    $guard = $_SESSION["login_guard"] ?? gsoDefaultLoginGuard();

    if (!is_array($guard)) {
        $guard = gsoDefaultLoginGuard();
    }

    $guard = array_merge(gsoDefaultLoginGuard(), $guard);
    $now = time();
    $lockUntil = (int) ($guard["lock_until"] ?? 0);

    if ($lockUntil > 0 && $lockUntil <= $now) {
        $guard = gsoDefaultLoginGuard();
        $guard["post_lock_mode"] = true;
        $guard["post_lock_remaining"] = 1;
        $guard["show_reset_notice"] = true;
    }

    if (empty($guard["post_lock_mode"])) {
        $guard["post_lock_remaining"] = 0;
    }

    gsoSaveLoginGuard($guard);

    return $guard;
}

function gsoGetLoginLockRemaining(array $guard): int
{
    return max(0, (int) ($guard["lock_until"] ?? 0) - time());
}

function gsoRegisterLoginFailure(string $reason): array
{
    $guard = gsoGetLoginGuard();

    if (!empty($guard["post_lock_mode"])) {
        $guard["post_lock_remaining"] = max(0, (int) ($guard["post_lock_remaining"] ?? 0) - 1);

        if ((int) $guard["post_lock_remaining"] <= 0) {
            gsoClearLoginGuard();
            $_SESSION["forgot_password_message"] = "For security, the final login attempt was unsuccessful. Please reset your password before signing in again.";
            $_SESSION["forgot_password_message_type"] = "info";

            return [
                "status" => "redirect_forgot_password",
                "message" => $reason,
            ];
        }

        gsoSaveLoginGuard($guard);

        return [
            "status" => "failed",
            "message" => $reason,
            "guard" => $guard,
        ];
    }

    $guard["failed_attempts"] = max(0, (int) ($guard["failed_attempts"] ?? 0)) + 1;

    if ((int) $guard["failed_attempts"] >= 3) {
        $guard["failed_attempts"] = 3;
        $guard["lock_until"] = time() + 30;
        $guard["post_lock_mode"] = false;
        $guard["post_lock_remaining"] = 0;
        $guard["show_reset_notice"] = false;
        gsoSaveLoginGuard($guard);

        return [
            "status" => "locked",
            "message" => "Too many failed login attempts. Please wait 30 seconds before trying again.",
            "guard" => $guard,
        ];
    }

    gsoSaveLoginGuard($guard);

    return [
        "status" => "failed",
        "message" => $reason,
        "guard" => $guard,
    ];
}

if (!empty($_SESSION["user_id"])) {
    redirectToRoleHome($_SESSION["role"] ?? null, "../");
}

$guard = gsoGetLoginGuard();
$lockRemaining = gsoGetLoginLockRemaining($guard);
$postLockRemaining = (int) ($guard["post_lock_remaining"] ?? 0);

if (!empty($guard["show_reset_notice"])) {
    $message = "Security reset applied. You now have one login attempt remaining before password recovery is required.";
    $messageType = "warning";
    $guard["show_reset_notice"] = false;
    gsoSaveLoginGuard($guard);
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string) ($_POST["csrf_token"] ?? "");
    $loginInputValue = trim((string) ($_POST["login_input"] ?? ""));
    $password = (string) ($_POST["password"] ?? "");
    $guard = gsoGetLoginGuard();
    $lockRemaining = gsoGetLoginLockRemaining($guard);
    $postLockRemaining = (int) ($guard["post_lock_remaining"] ?? 0);

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token. Please refresh the page and try again.";
        $messageType = "error";
    } elseif ($lockRemaining > 0) {
        $message = "Too many failed login attempts. Please wait for the countdown to finish.";
        $messageType = "warning";
    } elseif ($loginInputValue === "" || $password === "") {
        $message = "Please fill in all fields.";
        $messageType = "error";
    } else {
        $stmt = $pdo->prepare("
            SELECT
                user_id,
                full_name,
                email,
                username,
                password_hash,
                role,
                account_status,
                email_verified
            FROM users
            WHERE email = :login_input OR username = :login_input
            LIMIT 1
        ");
        $stmt->execute([
            ":login_input" => $loginInputValue,
        ]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $failure = gsoRegisterLoginFailure("Invalid username or password.");

            if (($failure["status"] ?? "") === "redirect_forgot_password") {
                header("Location: forgot_password.php");
                exit();
            }

            $message = (string) ($failure["message"] ?? "Invalid username or password.");
            $messageType = ($failure["status"] ?? "") === "locked" ? "warning" : "error";
        } elseif (!password_verify($password, (string) ($user["password_hash"] ?? ""))) {
            $failure = gsoRegisterLoginFailure("Incorrect password.");

            if (($failure["status"] ?? "") === "redirect_forgot_password") {
                header("Location: forgot_password.php");
                exit();
            }

            $message = (string) ($failure["message"] ?? "Incorrect password.");
            $messageType = ($failure["status"] ?? "") === "locked" ? "warning" : "error";
        } elseif (!(bool) ($user["email_verified"] ?? false)) {
            $message = "Please verify your email first.";
            $messageType = "warning";
        } elseif (($user["account_status"] ?? "") === "Disabled") {
            $message = "Your account has been disabled.";
            $messageType = "error";
        } elseif (($user["account_status"] ?? "") === "Rejected") {
            $message = "Your account has been rejected.";
            $messageType = "error";
        } elseif (($user["role"] ?? "") === "Borrower" && ($user["account_status"] ?? "") !== "Approved") {
            $message = "Your borrower account is still pending admin approval.";
            $messageType = "warning";
        } elseif (($user["role"] ?? "") === "Borrower" || ($user["role"] ?? "") === "Admin") {
            session_regenerate_id(true);
            gsoClearLoginGuard();

            $_SESSION["user_id"] = (int) $user["user_id"];
            $_SESSION["full_name"] = (string) $user["full_name"];
            $_SESSION["username"] = (string) $user["username"];
            $_SESSION["email"] = (string) $user["email"];
            $_SESSION["role"] = (string) $user["role"];

            addActivityLog(
                $pdo,
                (int) $user["user_id"],
                "Login Success",
                "User logged in successfully as " . (string) $user["role"] . "."
            );

            if (($user["role"] ?? "") === "Borrower") {
                header("Location: ../borrower/browse.php");
                exit();
            }

            header("Location: ../admin/dashboard.php");
            exit();
        } else {
            $message = "Invalid user role.";
            $messageType = "error";
        }

        $guard = gsoGetLoginGuard();
        $lockRemaining = gsoGetLoginLockRemaining($guard);
        $postLockRemaining = (int) ($guard["post_lock_remaining"] ?? 0);
    }
}

$remainingAttempts = max(0, 3 - (int) ($guard["failed_attempts"] ?? 0));
$isLocked = $lockRemaining > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/auth.css?v=20260419-login-guard">

    <title>Login</title>
</head>

<body>

<div class="page-wrapper">
    <div class="login-container">
        <img src="../assets/css/img/login_logo.png" alt="Logo" class="logo">

        <div class="top">
            <h2>General Services Office</h2>
            <p>Borrowing System</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo htmlspecialchars($messageType); ?>" id="loginMessage">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($isLocked): ?>
            <div class="security-state security-state-lock" id="lockState">
                <div class="security-state-icon">
                    <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                </div>
                <div class="security-state-copy">
                    <strong>Temporary login lock enabled</strong>
                    <span>Login will refresh automatically when the countdown ends.</span>
                    <span class="countdown-text">Time remaining: <span id="lockCountdown" data-seconds="<?php echo (int) $lockRemaining; ?>">00:00</span></span>
                </div>
            </div>
        <?php elseif ($postLockRemaining > 0): ?>
            <div class="security-state security-state-warning">
                <div class="security-state-icon">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                </div>
                <div class="security-state-copy">
                    <strong>One protected retry remains</strong>
                    <span>Another failed login will send you directly to password recovery.</span>
                </div>
            </div>
        <?php elseif ((int) ($guard["failed_attempts"] ?? 0) > 0): ?>
            <div class="security-state">
                <div class="security-state-icon">
                    <i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>
                </div>
                <div class="security-state-copy">
                    <strong>Login protection active</strong>
                    <span>Remaining attempts before the 30-second lock: <?php echo $remainingAttempts; ?></span>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">

            <label for="login_input">Email or Username:</label>
            <input
                type="text"
                id="login_input"
                name="login_input"
                placeholder="Enter your username or email"
                value="<?php echo htmlspecialchars($loginInputValue, ENT_QUOTES, "UTF-8"); ?>"
                autocomplete="username"
                <?php echo $isLocked ? "disabled" : ""; ?>
                required
            >

            <label for="password">Password:</label>
            <div class="password-wrapper">
                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    <?php echo $isLocked ? "disabled" : ""; ?>
                    required
                >
                <i class="fa-solid fa-eye toggle-password" id="togglePassword" aria-hidden="true"></i>
            </div>

            <button type="submit" id="loginButton" <?php echo $isLocked ? "disabled" : ""; ?>>Login</button>

            <div class="forgot">
                <a href="forgot_password.php">Forgot password?</a>
            </div>
        </form>

        <p class="signup-text">No account yet? <a href="signup.php">Sign up here</a></p>
    </div>

    <div class="access-note">
        Access is restricted to authorized university personnel and students. <br>
        All borrowing activities are monitored and recorded.
    </div>
</div>

<script>
const togglePassword = document.getElementById("togglePassword");
const password = document.getElementById("password");
const loginForm = document.getElementById("loginForm");
const loginButton = document.getElementById("loginButton");
const countdownElement = document.getElementById("lockCountdown");

if (togglePassword && password) {
    togglePassword.addEventListener("click", function () {
        const type = password.getAttribute("type") === "password" ? "text" : "password";
        password.setAttribute("type", type);
        this.classList.toggle("fa-eye");
        this.classList.toggle("fa-eye-slash");
    });
}

function formatCountdown(totalSeconds) {
    const safeSeconds = Math.max(0, totalSeconds);
    const minutes = String(Math.floor(safeSeconds / 60)).padStart(2, "0");
    const seconds = String(safeSeconds % 60).padStart(2, "0");
    return minutes + ":" + seconds;
}

function setLoginButtonState(locked, secondsRemaining) {
    if (!loginButton) {
        return;
    }

    loginButton.disabled = locked;
    loginButton.textContent = locked
        ? "Locked (" + formatCountdown(secondsRemaining) + ")"
        : "Login";
}

if (countdownElement && loginForm) {
    let secondsRemaining = parseInt(countdownElement.dataset.seconds || "0", 10);
    const formFields = loginForm.querySelectorAll("input[type='text'], input[type='password']");

    const syncLockState = function () {
        const isLocked = secondsRemaining > 0;
        countdownElement.textContent = formatCountdown(secondsRemaining);
        setLoginButtonState(isLocked, secondsRemaining);

        formFields.forEach(function (field) {
            field.disabled = isLocked;
        });
    };

    syncLockState();

    if (secondsRemaining > 0) {
        const timer = window.setInterval(function () {
            secondsRemaining -= 1;

            if (secondsRemaining <= 0) {
                window.clearInterval(timer);
                countdownElement.textContent = "00:00";
                window.location.replace("login.php");
                return;
            }

            syncLockState();
        }, 1000);
    }
} else {
    setLoginButtonState(false, 0);
}
</script>

</body>
</html>
