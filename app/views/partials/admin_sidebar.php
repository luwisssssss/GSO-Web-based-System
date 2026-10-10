<?php
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
$current_page = basename($_SERVER["PHP_SELF"]);
$currentRole = (string) ($_SESSION["role"] ?? "");
$isStaff = $currentRole === "GSO Staff";
$adminBase = $isStaff ? "../admin/" : "";
$workspaceLabel = match ($currentRole) { "Super Admin" => "Super Admin / MIS", "GSO Staff" => "GSO Staff", "Director" => "Campus Director", default => "GSO Head" };
$workspaceNote = match (true) { gsoUserHasPermission($currentRole, "accounts.manage") => "Account Management", $currentRole === "Director" => "Read-only facility schedule", default => "Operational workspace" };
// Pages that belong to a sidebar entry without being that entry's own file.
$sectionPages = ["maintenance.php" => ["preventive_plans.php", "maintenance_history.php"]];
$links = [
    [getRoleHomePath($currentRole), "Dashboard", "chart-line", ""],
    [$adminBase."requests.php", "Requests", "clipboard-check", "request.view_queue"],
    [$adminBase."create_walkin.php", "Create Walk-in", "user-plus", "walk_in.create"],
    [$adminBase."reschedules.php", "Reschedule Review", "calendar", "request.reschedule.manage"],
    [$adminBase."on_loan.php", "Release / Return", "box-open", "release.manage"],
    ["incidents.php", "Incident Reports", "triangle-exclamation", "gso.admin_workspace"],
    [$adminBase."inventory.php", "Inventory", "boxes-stacked", "inventory.view"],
    [$adminBase."equipment_units.php", "Equipment Units", "barcode", "inventory.manage_units"],
    [$adminBase."maintenance.php", "Work Orders", "screwdriver-wrench", "work_order.manage"],
    ["borrowers_accounts.php", "Borrower Accounts", "users", "accounts.manage"],
    ["admin_accounts.php", "System Accounts", "user-shield", "accounts.manage"],
    ["ai_knowledge.php", "AI Knowledge Base", "robot", "gso.admin_workspace"],
    ["reports.php", "Reports", "chart-pie", "gso.admin_workspace"],
    ["activity_logs.php", "Activity Logs", "list-check", "gso.admin_workspace"],
    ["notifications.php", "Notifications", "bell", "notifications.view_own"],
    ["../auth/logout.php", "Sign Out", "right-from-bracket", "director.dashboard"],
];
?>
<aside class="sidebar" aria-label="<?php echo htmlspecialchars($workspaceLabel); ?> navigation">
    <div>
        <div class="sidebar-brand"><img src="../public/assets/images/logo.png" alt="GSO logo"><div>
            <strong><?php echo htmlspecialchars($workspaceLabel); ?></strong>
            <span><?php echo htmlspecialchars($workspaceNote); ?></span>
        </div></div>
        <p class="menu-label">Workspace</p><ul class="menu">
        <?php foreach ($links as [$href, $label, $icon, $permission]): ?>
            <?php if ($permission !== "" && !gsoUserHasPermission($currentRole, $permission)) continue; ?>
            <li class="<?php echo ($current_page === basename($href) || in_array($current_page, $sectionPages[basename($href)] ?? [], true)) ? "active" : ""; ?>">
                <a href="<?php echo htmlspecialchars($href); ?>"><i class="fa-solid fa-<?php echo htmlspecialchars($icon); ?>"></i> <?php echo htmlspecialchars($label); ?></a>
            </li>
        <?php endforeach; ?>
        </ul>
    </div><div class="sidebar-bottom"></div>
</aside>
