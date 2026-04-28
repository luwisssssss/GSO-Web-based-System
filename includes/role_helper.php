<?php

require_once __DIR__ . "/../config/bootstrap.php";

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
