<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/avatar_helper.php";
require_once "../includes/upload_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$supportsAvatarColumns = avatarColumnsAvailable($pdo);
$user_id = (int) ($_SESSION['user_id'] ?? 0);
$avatarSelect = $supportsAvatarColumns ? ",
        u.profile_image,
        u.profile_image_type" : "";

/* ================= USER INFO ================= */
$userSql = "
    SELECT 
        u.user_id,
        u.full_name,
        u.department,
        u.university_id,
        u.email,
        u.role,
        u.username,
        u.uploaded_id,
        u.uploaded_id_type" . $avatarSelect . ",
        u.account_status,
        u.approved_by,
        u.approved_at,
        u.created_at,
        u.password_updated_at,
        admin.full_name AS approved_by_name
    FROM users u
    LEFT JOIN users admin ON u.approved_by = admin.user_id
    WHERE u.user_id = ?
      AND u.role = 'Admin'
";
$stmt = $pdo->prepare($userSql);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    redirectWithFlash("dashboard.php", "Admin profile not found.");
}

/* ================= PROFILE UPLOAD ================= */
if(isset($_POST['upload_profile'])){
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        redirectWithFlash("profile.php", "Invalid request token.");
    }

    if (!$supportsAvatarColumns) {
        redirectWithFlash("profile.php", "Profile image storage is not ready yet. Please run the latest migration first.");
    }

    $uploadResult = gsoStoreUserProfileImage(
        $pdo,
        $user_id,
        "Admin",
        $_FILES["profile_image"] ?? [],
        5 * 1024 * 1024
    );

    redirectWithFlash(
        "profile.php",
        (string) ($uploadResult["message"] ?? "Profile image update failed."),
        ($uploadResult["success"] ?? false) ? "success" : "error"
    );
}

/* ================= PROFILE IMAGE ================= */
$profileImage = buildAvatarSource($user, "../assets/css/img/default.png");

/* ================= ADMIN SUMMARY (ADDED ONLY) ================= */

// TOTAL USERS
$stmtUsers = $pdo->query("SELECT COUNT(*) FROM users");
$totalUsers = $stmtUsers->fetchColumn();

// TOTAL RESOURCES
$stmtResources = $pdo->query("SELECT COUNT(*) FROM resources");
$totalResources = $stmtResources->fetchColumn();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Profile</title>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="stylesheet" href="../assets/css/ui-redesign.css?v=20260415-sidebar-motion">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>

<body>

<?php include("../includes/admin_topbar.php"); ?>
<?php include("../includes/admin_sidebar.php"); ?>

<div class="content-wrapper">
<div class="profile-container">

    <?php if (!empty($flashMessage)): ?>
        <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
            <?php echo htmlspecialchars($flashMessage); ?>
        </div>
    <?php endif; ?>

    <!-- HEADER -->
    <div class="profile-header-wrapper">

        <div class="profile-header-top"></div>

        <div class="profile-header-bottom">
            <div class="profile-info-section">
                <h3><?= htmlspecialchars((string) ($user['full_name'] ?? '')); ?></h3>
                <p>
                    <i class="fa-solid fa-briefcase"></i> <?= htmlspecialchars((string) ($user['role'] ?? '')); ?>
                    &nbsp;&nbsp;
                    <i class="fa-solid fa-building"></i> <?= htmlspecialchars((string) ($user['department'] ?? 'N/A')); ?>
                </p>
            </div>

            <!-- ✅ CONNECTED EDIT PROFILE -->
            <a href="edit_profile.php" class="btn-primary">
                <i class="fa-solid fa-pen"></i> Edit Profile
            </a>
        </div>

        <div class="profile-avatar">
    <img src="<?= $profileImage; ?>" alt="Profile">

    <!-- CAMERA UPLOAD -->
    <form method="POST" enctype="multipart/form-data" class="avatar-upload">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION["csrf_token"]); ?>">
        <label>
            <i class="fa-solid fa-camera"></i>
            <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp,.gif" onchange="this.form.submit()">
            <input type="hidden" name="upload_profile">
        </label>
    </form>
</div>

    </div>

    <!-- BODY -->
    <div class="profile-body">

        <!-- LEFT -->
        <div class="left">

            <!-- PERSONAL INFO -->
            <div class="card">
                <h3><i class="fa-solid fa-id-card"></i> Personal Information</h3>

                <div class="grid">
                    <div><label>FULL NAME</label><input value="<?= htmlspecialchars((string) ($user['full_name'] ?? '')); ?>" disabled></div>
                    <div><label>UNIVERSITY ID</label><input value="<?= htmlspecialchars((string) ($user['university_id'] ?? '')); ?>" disabled></div>
                    <div><label>EMAIL</label><input value="<?= htmlspecialchars((string) ($user['email'] ?? '')); ?>" disabled></div>
                    <div><label>USERNAME</label><input value="<?= htmlspecialchars((string) ($user['username'] ?? '')); ?>" disabled></div>
                    <div><label>DEPARTMENT</label><input value="<?= htmlspecialchars((string) ($user['department'] ?? 'N/A')); ?>" disabled></div>
                    <div><label>ROLE</label><input value="<?= htmlspecialchars((string) ($user['role'] ?? '')); ?>" disabled></div>
                    <div><label>ACCOUNT STATUS</label><input value="<?= htmlspecialchars((string) ($user['account_status'] ?? 'N/A')); ?>" disabled></div>
                    <div><label>APPROVED BY</label><input value="<?= htmlspecialchars((string) ($user['approved_by_name'] ?? 'N/A')); ?>" disabled></div>
                    <div><label>APPROVED AT</label><input value="<?= htmlspecialchars((string) ($user['approved_at'] ?? 'N/A')); ?>" disabled></div>
                    <div><label>DATE REGISTERED</label><input value="<?= htmlspecialchars((string) ($user['created_at'] ?? 'N/A')); ?>" disabled></div>
                </div>
            </div>

            <!-- ACCOUNT SETTINGS -->
            <div class="card">
                <h3><i class="fa-solid fa-gear"></i> Account Settings</h3>

                <div class="setting">
                    <div>
                        <strong>Security</strong>
                        <p>
                        Last password change: 
                        <?php
                        if($user['password_updated_at']){
                            $time = strtotime($user['password_updated_at']);
                            $now = time();
                            $diff = $now - $time;

                            if($diff < 60){
                                echo "Just now";
                            } elseif($diff < 3600){
                                echo floor($diff / 60) . " minutes ago";
                            } elseif($diff < 86400){
                                echo floor($diff / 3600) . " hours ago";
                            } elseif($diff < 172800){
                                echo "Yesterday";
                            } elseif($diff < 604800){
                                echo floor($diff / 86400) . " days ago";
                            } else {
                                echo date("M d, Y", $time);
                            }
                        } else {
                            echo "Never";
                        }
                        ?>
                        </p>
                    </div>

                    <!-- ✅ CONNECTED RESET PASSWORD -->
                    <a href="change_password.php" class="btn-light">
                        Reset Password
                    </a>
                </div>

            </div>

        </div>

        <!-- RIGHT -->
        <div class="right">

            <div class="card combined">

                <h4>Systen Summary</h4>

                    <div class="summary blue">
                        <div class="summary-icon"><i class="fa-solid fa-users"></i></div>
                        <div>
                            <h2><?= $totalUsers; ?></h2>
                            <p>Total Users</p>
                        </div>
                    </div>

                    <div class="summary blue">
                        <div class="summary-icon"><i class="fa-solid fa-box"></i></div>
                        <div>
                            <h2><?= $totalResources; ?></h2>
                            <p>Total Resources</p>
                        </div>
                    </div>

                <div class="divider"></div>

                <!-- QUICK LINKS -->
                <h4 class="ql-title">Quick Links</h4>

                <a href="requests.php" class="ql-item">
                     <span>Manage Requests</span>
                     <i class="fa-solid fa-arrow-right"></i>
                </a>

                <!-- ✅ SECOND QUICK LINK CONNECTED -->
                <a href="inventory.php" class="ql-item">
                    <span>Manage Resources</span>
                    <i class="fa-solid fa-box"></i>
                </a>

            </div>

            <!-- VERIFIED IMAGE -->
            <div class="verified-img">
                <img src="../assets/css/img/verified.png" alt="Verified">
            </div>

        </div>

    </div>

</div>
</div>

<script src="../assets/js/ui-redesign.js?v=20260415-sidebar-motion" defer></script>
</body>
</html>
