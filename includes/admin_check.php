<?php
require_once "auth_check.php";
require_once "role_helper.php";
require_once __DIR__ . "/../config/db.php";

$requiredPermission = defined("GSO_REQUIRED_PERMISSION") ? GSO_REQUIRED_PERMISSION : "gso.admin_workspace";
ensureAuthenticatedPermission($requiredPermission);
ensureApprovedPermissionAccount($pdo, $requiredPermission);
?>
