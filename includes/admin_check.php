<?php
require_once "auth_check.php";
require_once "role_helper.php";
require_once __DIR__ . "/../config/db.php";

ensureAuthenticatedRole("Admin");
ensureApprovedRoleAccount($pdo, "Admin");
?>
