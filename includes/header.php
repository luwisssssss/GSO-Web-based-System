<?php
require_once __DIR__ . "/role_helper.php";

if (!isset($pageTitle)) {
    $pageTitle = "GSO Web System";
}

if (!isset($cssFile)) {
    $cssFile = "../assets/css/borrower.css";
}

$showGlobalBackButton = $showGlobalBackButton ?? true;
$backButtonLabel = $backButtonLabel ?? "Back";
$backButtonFallback = $backButtonFallback ?? getRoleHomePath($_SESSION["role"] ?? null, "../");
$roleClass = strtolower((string) ($_SESSION["role"] ?? "guest"));
$pageClass = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) ($activePage ?? $pageTitle)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile); ?>">
    <link rel="stylesheet" href="../assets/css/ui-redesign.css?v=20260415-sidebar-motion">
    <script src="../assets/js/ui-redesign.js?v=20260415-sidebar-motion" defer></script>
</head>
<body class="app-body role-<?php echo htmlspecialchars($roleClass); ?> page-<?php echo htmlspecialchars($pageClass); ?>">
<div class="app-wrapper">
<?php if ($showGlobalBackButton && !empty($_SESSION["user_id"])): ?>
    <button
        type="button"
        class="global-back-btn"
        data-fallback="<?php echo htmlspecialchars($backButtonFallback); ?>"
        aria-label="<?php echo htmlspecialchars($backButtonLabel); ?>"
    >
        <i class="fa-solid fa-arrow-left"></i>
        <span><?php echo htmlspecialchars($backButtonLabel); ?></span>
    </button>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const backButton = document.querySelector(".global-back-btn");

        if (!backButton) {
            return;
        }

        backButton.addEventListener("click", function () {
            const fallback = backButton.getAttribute("data-fallback") || "";

            if (window.history.length > 1) {
                window.history.back();
                return;
            }

            if (fallback !== "") {
                window.location.href = fallback;
            }
        });
    });
    </script>
<?php endif; ?>
