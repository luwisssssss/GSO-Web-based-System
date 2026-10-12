<?php
// Maintenance History is a read-only view of terminal maintenance_schedules
// rows (Completed / Cancelled). There is no separate history table.
define("GSO_REQUIRED_PERMISSION", "work_order.manage");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";

$pageTitle = "Maintenance History";
$activePage = "maintenance";
$cssFile = "../public/assets/css/pages/admin.css";

$resourceFilter = max(0, (int) ($_GET["resource_id"] ?? 0));
$unitFilter = max(0, (int) ($_GET["unit_id"] ?? 0));
$planFilter = max(0, (int) ($_GET["plan_id"] ?? 0));
$typeFilter = (string) ($_GET["type"] ?? "All");
$statusFilter = (string) ($_GET["status"] ?? "All");

$where = ["m.status IN ('Completed', 'Cancelled')"];
$args = [];

if ($resourceFilter > 0) {
    $where[] = "m.resource_id = ?";
    $args[] = $resourceFilter;
}
if ($unitFilter > 0) {
    $where[] = "m.equipment_unit_id = ?";
    $args[] = $unitFilter;
}
if ($planFilter > 0) {
    $where[] = "m.preventive_plan_id = ?";
    $args[] = $planFilter;
}
if (in_array($typeFilter, ["Corrective", "Preventive"], true)) {
    $where[] = "m.maintenance_type = ?";
    $args[] = $typeFilter;
} elseif ($typeFilter === "Unclassified") {
    $where[] = "m.maintenance_type IS NULL";
} else {
    $typeFilter = "All";
}
if (in_array($statusFilter, ["Completed", "Cancelled"], true)) {
    $where[] = "m.status = ?";
    $args[] = $statusFilter;
} else {
    $statusFilter = "All";
}

// Source relationships come from the real foreign keys. No borrower identity
// is selected here: history is about the asset, not the client.
$historyStmt = $pdo->prepare("
    SELECT m.maintenance_id, m.maintenance_type, m.preventive_plan_id, m.plan_occurrence_date,
           m.resource_id, m.equipment_unit_id, m.quantity, m.source_return_item_id, m.incident_id,
           m.start_date, m.end_date, m.duration_days, m.reason, m.status, m.completion_notes,
           m.started_at, m.completed_at, m.created_at, m.updated_at,
           r.resource_name, r.resource_type, r.tracking_mode,
           u.asset_tag, u.serial_number,
           p.title AS plan_title,
           a.full_name AS assignee_name, c.full_name AS creator_name, v.full_name AS updater_name
    FROM maintenance_schedules m
    INNER JOIN resources r ON r.resource_id = m.resource_id
    LEFT JOIN equipment_units u ON u.unit_id = m.equipment_unit_id
    LEFT JOIN preventive_maintenance_plans p ON p.plan_id = m.preventive_plan_id
    LEFT JOIN users a ON a.user_id = m.assigned_to
    LEFT JOIN users c ON c.user_id = m.created_by
    LEFT JOIN users v ON v.user_id = m.updated_by
    WHERE " . implode(" AND ", $where) . "
    ORDER BY COALESCE(m.completed_at, m.updated_at, m.created_at) DESC, m.maintenance_id DESC
    LIMIT 300
");
$historyStmt->execute($args);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$resourceOptions = $pdo->query("
    SELECT resource_id, resource_name FROM resources ORDER BY resource_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$unitLabel = "";
if ($unitFilter > 0) {
    $unitStmt = $pdo->prepare("SELECT asset_tag FROM equipment_units WHERE unit_id = ?");
    $unitStmt->execute([$unitFilter]);
    $unitLabel = (string) ($unitStmt->fetchColumn() ?: "");
}

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <div class="workspace-heading">
            <h2>Maintenance History</h2>
            <p>Completed and cancelled work orders. Records created before maintenance types existed stay Unclassified unless a return or incident link proves they were corrective.</p>
            </div>
            <div class="profile-actions">
                <a href="maintenance.php" class="admin-btn neutral-btn">Active / Upcoming</a>
                <a href="preventive_plans.php" class="admin-btn neutral-btn">Preventive Plans</a>
                <a href="maintenance_history.php" class="admin-btn primary-btn" aria-current="page">Maintenance History</a>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <select name="resource_id" aria-label="Resource">
                    <option value="0">All Resources</option>
                    <?php foreach ($resourceOptions as $option): ?>
                        <option value="<?php echo (int) $option["resource_id"]; ?>" <?php echo $resourceFilter === (int) $option["resource_id"] ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars((string) $option["resource_name"]); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="type" aria-label="Maintenance type">
                    <?php foreach (["All" => "All Types", "Corrective" => "Corrective", "Preventive" => "Preventive", "Unclassified" => "Unclassified"] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $typeFilter === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status" aria-label="Status">
                    <?php foreach (["All" => "Completed and Cancelled", "Completed" => "Completed", "Cancelled" => "Cancelled"] as $value => $label): ?>
                        <option value="<?php echo $value; ?>" <?php echo $statusFilter === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($unitFilter > 0): ?><input type="hidden" name="unit_id" value="<?php echo $unitFilter; ?>"><?php endif; ?>
                <?php if ($planFilter > 0): ?><input type="hidden" name="plan_id" value="<?php echo $planFilter; ?>"><?php endif; ?>
                <button type="submit" class="admin-btn primary-btn">Filter</button>
                <?php if ($unitFilter > 0 || $planFilter > 0 || $resourceFilter > 0): ?><a href="maintenance_history.php" class="admin-btn neutral-btn">Clear</a><?php endif; ?>
            </form>
            <?php if ($unitFilter > 0): ?><p><small>Showing history for unit <?php echo htmlspecialchars($unitLabel !== "" ? $unitLabel : "#" . $unitFilter); ?>.</small></p><?php endif; ?>
            <?php if ($planFilter > 0): ?><p><small>Showing history for preventive plan #<?php echo $planFilter; ?>.</small></p><?php endif; ?>

            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Work Order</th>
                            <th>Type / Origin</th>
                            <th>Resource</th>
                            <th>Period</th>
                            <th>Task</th>
                            <th>Status</th>
                            <th>Completion</th>
                            <th>People</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$history): ?>
                        <tr><td colspan="8" class="empty-note">No completed or cancelled maintenance matches these filters.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($history as $job): ?>
                        <tr>
                            <td>MTN-<?php echo str_pad((string) $job["maintenance_id"], 3, "0", STR_PAD_LEFT); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars((string) ($job["maintenance_type"] ?? "Unclassified")); ?></strong><br>
                                <small>
                                <?php if (!empty($job["preventive_plan_id"])): ?>
                                    <a href="preventive_plans.php?plan_id=<?php echo (int) $job["preventive_plan_id"]; ?>">Preventive Plan #<?php echo (int) $job["preventive_plan_id"]; ?></a>
                                    (due <?php echo htmlspecialchars((string) $job["plan_occurrence_date"]); ?>)
                                <?php elseif (!empty($job["source_return_item_id"])): ?>
                                    Return Detail #<?php echo (int) $job["source_return_item_id"]; ?>
                                <?php elseif (!empty($job["incident_id"])): ?>
                                    Incident #<?php echo (int) $job["incident_id"]; ?>
                                <?php else: ?>
                                    Manual schedule
                                <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars((string) $job["resource_name"]); ?></strong><br>
                                <small>
                                <?php if (!empty($job["asset_tag"])): ?>
                                    Unit <?php echo htmlspecialchars($job["asset_tag"] . ($job["serial_number"] ? " | S/N " . $job["serial_number"] : "")); ?>
                                <?php elseif ((int) $job["quantity"] > 0): ?>
                                    Quantity <?php echo (int) $job["quantity"]; ?>
                                <?php else: ?>
                                    <?php echo (string) $job["resource_type"] === "Facility" ? "Whole facility" : "Whole resource"; ?>
                                <?php endif; ?>
                                </small>
                            </td>
                            <td>
                                <?php echo htmlspecialchars(formatDateTimeDisplay((string) $job["start_date"]) . " - " . formatDateTimeDisplay((string) $job["end_date"])); ?><br>
                                <small><?php echo (int) $job["duration_days"]; ?> day(s) planned<?php echo !empty($job["started_at"]) ? "; started " . htmlspecialchars(formatDateTimeDisplay((string) $job["started_at"])) : ""; ?></small>
                            </td>
                            <td><?php echo htmlspecialchars((string) $job["reason"]); ?></td>
                            <td><span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) $job["status"])); ?>"><?php echo htmlspecialchars((string) $job["status"]); ?></span></td>
                            <td>
                                <?php if ((string) $job["status"] === "Completed"): ?>
                                    <?php echo !empty($job["completed_at"]) ? htmlspecialchars(formatDateTimeDisplay((string) $job["completed_at"])) : "No completion timestamp recorded"; ?>
                                <?php else: ?>
                                    Cancelled <?php echo htmlspecialchars(formatDateTimeDisplay((string) ($job["updated_at"] ?? ""))); ?>
                                <?php endif; ?>
                                <?php if (!empty($job["completion_notes"])): ?><br><small><?php echo nl2br(htmlspecialchars((string) $job["completion_notes"])); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <small>
                                    Assignee: <?php echo htmlspecialchars((string) ($job["assignee_name"] ?? "Unassigned")); ?><br>
                                    Created by: <?php echo htmlspecialchars((string) ($job["creator_name"] ?? "N/A")); ?><br>
                                    Updated by: <?php echo htmlspecialchars((string) ($job["updater_name"] ?? "N/A")); ?>
                                </small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
