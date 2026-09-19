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
        return match ($role) {
            "Admin" => $prefix . "admin/dashboard.php",
            "GSO Staff" => $prefix . "gso_staff/dashboard.php",
            "Director" => $prefix . "director/dashboard.php",
            default => $prefix . "borrower/browse.php",
        };
    }
}

if (!function_exists("gsoSupportedRoles")) {
    function gsoSupportedRoles(): array
    {
        return ["Borrower", "GSO Staff", "Admin", "Director"];
    }
}

if (!function_exists("gsoRolePermissions")) {
    /** Central Phase 1 authorization policy. Admin is deliberately explicit
     * through the wildcard; other roles receive only listed capabilities. */
    function gsoRolePermissions(): array
    {
        return [
            "Borrower" => [
                "borrower.portal", "request.create_own", "request.view_own",
                "return.submit_own", "incident.submit_own", "profile.manage_own",
                "chatbot.use",
            ],
            "GSO Staff" => [
                "staff.dashboard", "request.view_queue", "request.approve_ordinary",
                "release.manage", "return.inspect", "inventory.view",
                "inventory.manage_units",
                "work_order.manage", "walk_in.create", "profile.manage_own",
                "chatbot.use",
            ],
            "Admin" => ["*"],
            "Director" => [
                "director.dashboard", "director.request.view", "director.request.decide",
                "profile.manage_own", "chatbot.use",
            ],
        ];
    }
}

if (!function_exists("gsoUserHasPermission")) {
    function gsoUserHasPermission(?string $role, string $permission): bool
    {
        $permissions = gsoRolePermissions()[$role ?? ""] ?? [];
        return in_array("*", $permissions, true) || in_array($permission, $permissions, true);
    }
}

if (!function_exists("ensureAuthenticatedPermission")) {
    function ensureAuthenticatedPermission(
        string $permission,
        string $loginPath = "../auth/login.php",
        string $roleHomePrefix = "../"
    ): void {
        gsoSecureSessionStart();
        if (empty($_SESSION["user_id"])) {
            header("Location: " . $loginPath);
            exit();
        }
        if (!gsoUserHasPermission((string) ($_SESSION["role"] ?? ""), $permission)) {
            setFlashMessage("Access denied for this page.");
            header("Location: " . getRoleHomePath($_SESSION["role"] ?? null, $roleHomePrefix));
            exit();
        }
    }
}

if (!function_exists("ensureApprovedPermissionAccount")) {
    function ensureApprovedPermissionAccount(PDO $pdo, string $permission, string $loginPath = "../auth/login.php"): void
    {
        gsoSecureSessionStart();
        $userId = (int) ($_SESSION["user_id"] ?? 0);
        $stmt = $pdo->prepare("SELECT role FROM users WHERE user_id=:user_id AND account_status='Approved' AND email_verified=1 LIMIT 1");
        $stmt->execute([":user_id" => $userId]);
        $role = $stmt->fetchColumn();
        if (is_string($role) && $role === (string) ($_SESSION["role"] ?? "") && gsoUserHasPermission($role, $permission)) {
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
