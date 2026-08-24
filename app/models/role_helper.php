<?php

require_once dirname(__DIR__, 2) . "/config/bootstrap.php";

if (!function_exists("setFlashMessage")) {
    function setFlashMessage(string $message, string $type = "error"): void
    {
        gsoSecureSessionStart();

        $_SESSION["flash_message"] = $message;
        $_SESSION["flash_type"] = $type;
    }
}

if (!function_exists("redirectWithFlash")) {
    function redirectWithFlash(string $location, string $message, string $type = "error"): void
    {
        setFlashMessage($message, $type);
        header("Location: " . $location);
        exit();
    }
}

if (!function_exists("getRoleHomePath")) {
    function getRoleHomePath(?string $role, string $prefix = "../"): string
    {
        if ($role === "Admin") {
            return $prefix . "admin/dashboard.php";
        }

        return $prefix . "borrower/browse.php";
    }
}

if (!function_exists("redirectToRoleHome")) {
    function redirectToRoleHome(?string $role, string $prefix = "../"): void
    {
        header("Location: " . getRoleHomePath($role, $prefix));
        exit();
    }
}

if (!function_exists("ensureAuthenticatedRole")) {
    function ensureAuthenticatedRole(
        string $requiredRole,
        string $loginPath = "../auth/login.php",
        string $roleHomePrefix = "../"
    ): void {
        gsoSecureSessionStart();

        if (!isset($_SESSION["user_id"])) {
            header("Location: " . $loginPath);
            exit();
        }

        $currentRole = $_SESSION["role"] ?? null;

        if ($currentRole !== $requiredRole) {
            setFlashMessage("Access denied for this page.");
            header("Location: " . getRoleHomePath($currentRole, $roleHomePrefix));
            exit();
        }
    }
}

if (!function_exists("ensureApprovedRoleAccount")) {
    function ensureApprovedRoleAccount(
        PDO $pdo,
        string $requiredRole,
        string $loginPath = "../auth/login.php"
    ): void {
        gsoSecureSessionStart();
        $userId = (int) ($_SESSION["user_id"] ?? 0);

        if ($userId <= 0) {
            header("Location: " . $loginPath);
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM users
            WHERE user_id = :user_id
              AND role = :role
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([
            ":user_id" => $userId,
            ":role" => $requiredRole,
        ]);

        if ($stmt->fetchColumn()) {
            return;
        }

        gsoDestroySession();
        gsoSecureSessionStart();
        $_SESSION["flash_message"] = "Your account is no longer authorized. Please sign in again.";
        $_SESSION["flash_type"] = "error";
        header("Location: " . $loginPath);
        exit();
    }
}
