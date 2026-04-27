<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Change Password";
$activePage = "profile";
$cssFile = "../assets/css/admin.css";

$user_id = (int) $_SESSION["user_id"];
$message = "";
$messageType = "";

$stmt = $pdo->prepare("
    SELECT user_id, password_hash, full_name
    FROM users
    WHERE user_id = :user_id
      AND role = 'Admin'
    LIMIT 1
");
$stmt->execute([
    ":user_id" => $user_id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    redirectWithFlash("profile.php", "Admin account not found.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token.";
        $messageType = "error";
    } elseif ($current_password === "" || $new_password === "" || $confirm_password === "") {
        $message = "All password fields are required.";
        $messageType = "error";
    } elseif (!password_verify($current_password, $user["password_hash"])) {
        $message = "Current password is incorrect.";
        $messageType = "error";
    } elseif (strlen($new_password) < 8) {
        $message = "New password must be at least 8 characters long.";
        $messageType = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New password and confirm password do not match.";
        $messageType = "error";
    } elseif (password_verify($new_password, $user["password_hash"])) {
        $message = "New password must be different from your current password.";
        $messageType = "error";
    } else {
        $newPasswordHash = password_hash($new_password, PASSWORD_DEFAULT);

        $updateStmt = $pdo->prepare("
            UPDATE users
            SET password_hash = :password_hash,
                password_updated_at = NOW()
            WHERE user_id = :user_id
              AND role = 'Admin'
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":password_hash" => $newPasswordHash,
            ":user_id" => $user_id
        ]);

        if ($success) {
            $message = "Password changed successfully.";
            $messageType = "success";

            $stmt->execute([
                ":user_id" => $user_id
            ]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $message = "Failed to change password.";
            $messageType = "error";
        }
    }
}

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Change Password</h2>
            <p>Update your admin account password securely.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="flash-message <?php echo $messageType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" class="request-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <div class="form-row">
                    <label for="current_password">Current Password</label>
                    <input
                        type="password"
                        id="current_password"
                        name="current_password"
                        required
                    >
                </div>

                <div class="form-row">
                    <label for="new_password">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        required
                    >
                </div>

                <div class="form-row">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        required
                    >
                </div>

                <div class="profile-actions">
                    <button type="submit" class="admin-btn primary-btn">Change Password</button>
                    <a href="profile.php" class="admin-btn info-btn">Back to Profile</a>
                </div>
            </form>
        </div>
    </main>
</div>

