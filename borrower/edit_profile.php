<?php
require_once "../includes/borrower_check.php";
require_once "../config/db.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Edit Profile";
$activePage = "profile";

$user_id = (int) $_SESSION["user_id"];
$message = "";
$messageType = "";

/*
|--------------------------------------------------------------------------
| Load current borrower data
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT
        user_id,
        full_name,
        department,
        university_id,
        email,
        username,
        role,
        account_status
    FROM users
    WHERE user_id = :user_id
      AND role = 'Borrower'
    LIMIT 1
");
$stmt->execute([
    ":user_id" => $user_id
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    redirectWithFlash("profile.php", "Borrower profile not found.");
}

/*
|--------------------------------------------------------------------------
| Handle update
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token.";
        $messageType = "error";
    } else {
        $full_name = trim($_POST["full_name"] ?? "");
        $department = trim($_POST["department"] ?? "");
        $university_id = trim($_POST["university_id"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $username = trim($_POST["username"] ?? "");

        if ($full_name === "" || $email === "" || $username === "") {
            $message = "Full name, email, and username are required.";
            $messageType = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = "Please enter a valid email address.";
            $messageType = "error";
        } else {
            $checkStmt = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE (email = :email OR username = :username)
                  AND user_id <> :user_id
                LIMIT 1
            ");
            $checkStmt->execute([
                ":email" => $email,
                ":username" => $username,
                ":user_id" => $user_id
            ]);

            if ($checkStmt->fetch()) {
                $message = "Email or username is already being used by another account.";
                $messageType = "error";
            } else {
                $updateStmt = $pdo->prepare("
                    UPDATE users
                    SET
                        full_name = :full_name,
                        department = :department,
                        university_id = :university_id,
                        email = :email,
                        username = :username
                    WHERE user_id = :user_id
                      AND role = 'Borrower'
                    LIMIT 1
                ");

                $success = $updateStmt->execute([
                    ":full_name" => $full_name,
                    ":department" => $department !== "" ? $department : null,
                    ":university_id" => $university_id !== "" ? $university_id : null,
                    ":email" => $email,
                    ":username" => $username,
                    ":user_id" => $user_id
                ]);

                if ($success) {
                    $_SESSION["full_name"] = $full_name;
                    $_SESSION["email"] = $email;
                    $_SESSION["username"] = $username;

                    $message = "Profile updated successfully.";
                    $messageType = "success";

                    $stmt->execute([
                        ":user_id" => $user_id
                    ]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $message = "Failed to update profile.";
                    $messageType = "error";
                }
            }
        }
    }
}

require_once "../includes/header.php";
require_once "../includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/borrower_topbar.php"; ?>

    <main class="page-content settings-page">
        <section class="form-page-hero">
            <div>
                <span class="form-page-kicker">Account Settings</span>
                <h2>Edit Profile</h2>
                <p>Review and update your personal account information in a cleaner, more focused workspace.</p>
            </div>

            <div class="form-hero-badge">
                <span class="form-hero-badge-label">Current account</span>
                <strong><?php echo htmlspecialchars($user["role"] ?? "Borrower"); ?></strong>
                <small><?php echo htmlspecialchars($user["account_status"] ?? "N/A"); ?></small>
            </div>
        </section>

        <?php if (!empty($message)): ?>
            <div class="flash-message <?php echo $messageType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="form-page-layout profile-edit-layout">
            <aside class="card form-intro-card">
                <div class="form-intro-icon">
                    <i class="fa-solid fa-user-pen" aria-hidden="true"></i>
                </div>
                <h3>Keep your borrower profile ready</h3>
                <p>Updated details help GSO contact you quickly for approvals, releases, and return reminders when needed.</p>
                <div class="form-intro-points">
                    <span><i class="fa-solid fa-user" aria-hidden="true"></i> Personal profile details</span>
                    <span><i class="fa-solid fa-building-columns" aria-hidden="true"></i> Department and ID reference</span>
                    <span><i class="fa-solid fa-envelope" aria-hidden="true"></i> Reliable account contact info</span>
                </div>
            </aside>

            <div class="card request-form-card form-panel-card">
                <form method="POST" class="request-form settings-form-grid">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                    <div class="form-section-heading">
                        <h3>Personal Information</h3>
                        <p>These details are used in your borrower profile, request records, and account notifications.</p>
                    </div>

                    <div class="form-row form-row--full">
                        <label for="full_name">Full Name</label>
                        <input
                            type="text"
                            id="full_name"
                            name="full_name"
                            placeholder="Enter your full name"
                            value="<?php echo htmlspecialchars($user["full_name"] ?? ""); ?>"
                            required
                        >
                    </div>

                    <div class="form-row">
                        <label for="department">Department</label>
                        <input
                            type="text"
                            id="department"
                            name="department"
                            placeholder="Enter your department"
                            value="<?php echo htmlspecialchars($user["department"] ?? ""); ?>"
                        >
                    </div>

                    <div class="form-row">
                        <label for="university_id">University ID</label>
                        <input
                            type="text"
                            id="university_id"
                            name="university_id"
                            placeholder="Enter your university ID"
                            value="<?php echo htmlspecialchars($user["university_id"] ?? ""); ?>"
                        >
                    </div>

                    <div class="form-row form-row--full">
                        <label for="email">Email</label>
                        <input
                            type="email"
                            id="email"
                            name="email"
                            placeholder="Enter your email address"
                            value="<?php echo htmlspecialchars($user["email"] ?? ""); ?>"
                            required
                        >
                    </div>

                    <div class="form-row">
                        <label for="username">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="Choose your username"
                            value="<?php echo htmlspecialchars($user["username"] ?? ""); ?>"
                            required
                        >
                    </div>

                    <div class="form-section-heading">
                        <h3>Account Overview</h3>
                        <p>These read-only fields help confirm the account currently linked to your borrower access.</p>
                    </div>

                    <div class="form-row">
                        <label>Role</label>
                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($user["role"] ?? "Borrower"); ?>"
                            disabled
                        >
                        <small class="form-help">Your role is assigned by the system.</small>
                    </div>

                    <div class="form-row">
                        <label>Account Status</label>
                        <input
                            type="text"
                            value="<?php echo htmlspecialchars($user["account_status"] ?? "N/A"); ?>"
                            disabled
                        >
                        <small class="form-help">Status updates are managed by GSO account approval controls.</small>
                    </div>

                    <div class="profile-actions form-actions-bar">
                        <button type="submit" class="request-btn">Save Changes</button>
                        <a href="profile.php" class="profile-link-btn">Back to Profile</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

