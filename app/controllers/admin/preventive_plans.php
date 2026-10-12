<?php
// Preventive plans describe WHEN/WHAT should be maintained. Actual work is a
// maintenance_schedules occurrence generated explicitly from a plan.
define("GSO_REQUIRED_PERMISSION", "work_order.manage");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/preventive_maintenance_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Preventive Maintenance Plans";
$activePage = "maintenance";
$cssFile = "../public/assets/css/pages/admin.css";

$actorId = (int) ($_SESSION["user_id"] ?? 0);
$scope = max(0, (int) ($_GET["plan_id"] ?? 0));
// Navigation visibility only. Every mutation re-checks the capability against
// the locked database role inside the helper.
$canManage = gsoUserHasPermission((string) ($_SESSION["role"] ?? ""), "maintenance.plan.manage");
$error = "";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    try {
        if (!hash_equals((string) $_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))) {
            throw new DomainException("Invalid request token.");
        }

        $action = (string) ($_POST["action"] ?? "");
        $planId = (int) ($_POST["plan_id"] ?? 0);

        if ($scope && $planId !== $scope) {
            throw new DomainException("Plan identifier does not match this page.");
        }

        if (in_array($action, ["start_job", "complete_job", "cancel_job"], true)) {
            $jobId = (int) ($_POST["maintenance_id"] ?? 0);
            $jobPlanStmt = $pdo->prepare("SELECT preventive_plan_id FROM maintenance_schedules WHERE maintenance_id = ?");
            $jobPlanStmt->execute([$jobId]);
            if ($planId < 1 || (int) $jobPlanStmt->fetchColumn() !== $planId) {
                throw new DomainException("Work-order and plan identifiers do not match.");
            }
            $result = gsoPreventiveJobAction(
                $pdo,
                $jobId,
                $actorId,
                str_replace("_job", "", $action),
                trim((string) ($_POST["completion_notes"] ?? ""))
            );
            if (!$result["ok"]) {
                throw new DomainException($result["message"]);
            }
        } elseif ($action === "create_plan") {
            $planId = gsoSavePreventivePlan($pdo, $actorId, $_POST);
        } elseif ($action === "edit_plan") {
            gsoSavePreventivePlan($pdo, $actorId, $_POST, $planId, (int) ($_POST["revision"] ?? 0));
        } elseif ($action === "disable_plan") {
            gsoDisablePreventivePlan($pdo, $actorId, $planId, (int) ($_POST["revision"] ?? 0));
        } elseif ($action === "generate") {
            gsoGeneratePreventiveOccurrence($pdo, $actorId, $planId);
        } else {
            throw new DomainException("Invalid preventive maintenance action.");
        }

        redirectWithFlash(
            $scope ? "preventive_plans.php?plan_id=" . $planId : "preventive_plans.php",
            "Maintenance action recorded.",
            "success"
        );
    } catch (Throwable $e) {
        $error = gsoSafeApplicationError($e, "app/controllers/admin/preventive_plans.php", "The maintenance action could not be saved. Refresh and retry.");
    }
}

$planSql = "
    SELECT p.*, r.resource_name, r.resource_type, r.tracking_mode, u.asset_tag, u.serial_number,
           a.full_name AS assignee_name, c.full_name AS creator_name, v.full_name AS updater_name
    FROM preventive_maintenance_plans p
    INNER JOIN resources r ON r.resource_id = p.resource_id
    LEFT JOIN equipment_units u ON u.unit_id = p.equipment_unit_id
    LEFT JOIN users a ON a.user_id = p.assigned_to
    LEFT JOIN users c ON c.user_id = p.created_by
    LEFT JOIN users v ON v.user_id = p.updated_by
    " . ($scope ? "WHERE p.plan_id = ?" : "") . "
    ORDER BY p.is_active DESC, p.next_due_date ASC, p.plan_id ASC
    LIMIT 200
";
$planStmt = $pdo->prepare($planSql);
$planStmt->execute($scope ? [$scope] : []);
$plans = $planStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($scope && !$plans) {
    http_response_code(404);
    exit("Preventive plan not found.");
}

$resources = $pdo->query("
    SELECT resource_id, resource_name, resource_type, tracking_mode, total_stock
    FROM resources
    WHERE is_archived = 0 AND tracking_mode <> 'Consumable' AND condition_status <> 'Lost'
    ORDER BY resource_name
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$units = $pdo->query("
    SELECT u.unit_id, u.asset_tag, u.serial_number, r.resource_name
    FROM equipment_units u
    INNER JOIN resources r ON r.resource_id = u.resource_id
    WHERE u.status NOT IN ('Lost', 'Retired', 'Under Repair') AND u.condition_status = 'Good'
    ORDER BY r.resource_name, u.asset_tag
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$people = [];
foreach ($pdo->query("
    SELECT user_id, full_name, role FROM users
    WHERE account_status = 'Approved' AND email_verified = 1
    ORDER BY full_name
")->fetchAll(PDO::FETCH_ASSOC) as $person) {
    if (gsoUserHasPermission((string) $person["role"], "work_order.manage")) {
        $people[] = $person;
    }
}

$jobStmt = $pdo->prepare("
    SELECT m.maintenance_id, m.start_date, m.end_date, m.status, m.assigned_to, m.started_at,
           m.completed_at, m.plan_occurrence_date, a.full_name AS assignee_name
    FROM maintenance_schedules m
    LEFT JOIN users a ON a.user_id = m.assigned_to
    WHERE m.preventive_plan_id = ?
    ORDER BY m.start_date DESC, m.maintenance_id DESC
    LIMIT 20
");

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);
if ($error !== "") {
    $flashMessage = $error;
    $flashType = "error";
}

$token = htmlspecialchars((string) $_SESSION["csrf_token"]);
$planForm = $scope ? $plans[0] : [];
$today = date("Y-m-d");

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <div class="workspace-heading">
            <h2>Preventive Maintenance Plans</h2>
            <p>A plan describes future work and its next due date. Generate the due occurrence to create a real work order, then Start and Complete it separately. Passing a date never proves that work was performed.</p>
            </div>
            <div class="profile-actions">
                <a href="maintenance.php" class="admin-btn neutral-btn">Active / Upcoming</a>
                <a href="preventive_plans.php" class="admin-btn primary-btn" aria-current="page">Preventive Plans</a>
                <a href="maintenance_history.php" class="admin-btn neutral-btn">Maintenance History</a>
            </div>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>" role="<?php echo $flashType === "success" ? "status" : "alert"; ?>">
                <?php echo htmlspecialchars((string) $flashMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($canManage): ?>
        <div class="card">
            <h3><?php echo $scope ? "Edit Future Plan Settings (Plan #" . (int) $scope . ")" : "Create Plan"; ?></h3>
            <p>Facility plans cover the whole facility with quantity 0. Serialized equipment needs one real unit and quantity 1. Aggregate equipment needs a quantity and no unit. Consumables cannot be planned.</p>
            <form method="POST" class="request-form">
                <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                <input type="hidden" name="plan_id" value="<?php echo (int) $scope; ?>">
                <input type="hidden" name="revision" value="<?php echo (int) ($planForm["revision"] ?? 0); ?>">

                <div class="form-row">
                    <label for="plan_resource_id">Resource</label>
                    <select id="plan_resource_id" name="resource_id" required>
                        <option value="">Select Resource</option>
                        <?php foreach ($resources as $resource): ?>
                            <option value="<?php echo (int) $resource["resource_id"]; ?>" <?php echo (int) ($planForm["resource_id"] ?? 0) === (int) $resource["resource_id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($resource["resource_name"] . " (" . $resource["tracking_mode"] . ($resource["tracking_mode"] === "Aggregate" ? ", stock " . (int) $resource["total_stock"] : "") . ")"); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="plan_unit_id">Physical unit (serialized equipment only)</label>
                    <select id="plan_unit_id" name="equipment_unit_id">
                        <option value="0">No unit (facility or aggregate equipment)</option>
                        <?php foreach ($units as $unit): ?>
                            <option value="<?php echo (int) $unit["unit_id"]; ?>" <?php echo (int) ($planForm["equipment_unit_id"] ?? 0) === (int) $unit["unit_id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars($unit["resource_name"] . " | " . $unit["asset_tag"] . ($unit["serial_number"] ? " | S/N " . $unit["serial_number"] : "")); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="plan_quantity">Target quantity</label>
                    <input type="number" id="plan_quantity" name="quantity" min="0" required value="<?php echo (int) ($planForm["quantity"] ?? 0); ?>">
                </div>

                <div class="form-row">
                    <label for="plan_title">Maintenance task</label>
                    <input type="text" id="plan_title" name="title" maxlength="255" required placeholder="Aircon cleaning, projector lamp check..." value="<?php echo htmlspecialchars((string) ($planForm["title"] ?? "")); ?>">
                </div>

                <div class="form-row">
                    <label for="plan_recurrence">Recurrence</label>
                    <select id="plan_recurrence" name="recurrence" required>
                        <?php foreach (["Once" => "One time", "Monthly" => "Monthly", "Quarterly" => "Quarterly (every 3 months)", "Semiannual" => "Semiannual (every 6 months)", "Annual" => "Annual"] as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo ($planForm["recurrence"] ?? "") === $value ? "selected" : ""; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="plan_due">Next due date</label>
                    <input type="date" id="plan_due" name="next_due_date" required value="<?php echo htmlspecialchars((string) ($planForm["next_due_date"] ?? "")); ?>">
                </div>

                <div class="form-row">
                    <label for="plan_duration">Expected duration (days)</label>
                    <input type="number" id="plan_duration" name="duration_days" min="1" max="365" required value="<?php echo (int) ($planForm["duration_days"] ?? 1); ?>">
                </div>

                <div class="form-row">
                    <label for="plan_assignee">Assignee</label>
                    <select id="plan_assignee" name="assigned_to">
                        <option value="0">Unassigned</option>
                        <?php foreach ($people as $person): ?>
                            <option value="<?php echo (int) $person["user_id"]; ?>" <?php echo (int) ($planForm["assigned_to"] ?? 0) === (int) $person["user_id"] ? "selected" : ""; ?>>
                                <?php echo htmlspecialchars((string) $person["full_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="profile-actions">
                    <button type="submit" name="action" value="<?php echo $scope ? "edit_plan" : "create_plan"; ?>" class="admin-btn primary-btn">
                        <?php echo $scope ? "Save Future Settings" : "Create Plan"; ?>
                    </button>
                    <?php if ($scope): ?><a href="preventive_plans.php" class="admin-btn info-btn">Back to All Plans</a><?php endif; ?>
                </div>
                <p><small>Editing or disabling a plan never changes work orders that were already generated, completed, or cancelled.</small></p>
            </form>
        </div>
        <?php endif; ?>

        <?php if (!$plans): ?>
            <div class="card"><p class="empty-note">No preventive plans yet.</p></div>
        <?php endif; ?>

        <?php foreach ($plans as $plan): ?>
            <?php
            $planId = (int) $plan["plan_id"];
            $isActive = (int) $plan["is_active"] === 1;
            $isOverdue = $isActive && (string) $plan["next_due_date"] < $today;
            $jobStmt->execute([$planId]);
            $jobs = $jobStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            ?>
            <div class="card">
                <h3>Plan #<?php echo $planId; ?> | <?php echo htmlspecialchars((string) $plan["title"]); ?></h3>
                <p>
                    <strong><?php echo htmlspecialchars((string) $plan["resource_name"]); ?></strong>
                    (<?php echo htmlspecialchars((string) $plan["tracking_mode"]); ?>)
                    <?php if (!empty($plan["asset_tag"])): ?>
                        | Unit <?php echo htmlspecialchars($plan["asset_tag"] . ($plan["serial_number"] ? " | S/N " . $plan["serial_number"] : "")); ?>
                    <?php elseif ((int) $plan["quantity"] > 0): ?>
                        | Quantity <?php echo (int) $plan["quantity"]; ?>
                    <?php endif; ?>
                </p>
                <p>
                    <?php echo htmlspecialchars((string) $plan["recurrence"]); ?>
                    | Next due <?php echo htmlspecialchars(formatDateTimeDisplay((string) $plan["next_due_date"])); ?>
                    <?php if ($isOverdue): ?><span class="status-badge cancelled">Overdue</span><?php endif; ?>
                    | <?php echo (int) $plan["duration_days"]; ?> day(s)
                    | Assignee: <?php echo htmlspecialchars((string) ($plan["assignee_name"] ?? "Unassigned")); ?>
                    | <span class="status-badge <?php echo $isActive ? "completed" : "cancelled"; ?>"><?php echo $isActive ? "Active" : "Inactive"; ?></span>
                </p>
                <p><small>Created by <?php echo htmlspecialchars((string) ($plan["creator_name"] ?? "N/A")); ?>; last updated by <?php echo htmlspecialchars((string) ($plan["updater_name"] ?? "N/A")); ?> on <?php echo htmlspecialchars(formatDateTimeDisplay((string) $plan["updated_at"])); ?>.</small></p>

                <?php if ($canManage): ?>
                    <form method="POST" class="profile-actions">
                        <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                        <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
                        <input type="hidden" name="revision" value="<?php echo (int) $plan["revision"]; ?>">
                        <?php if (!$scope): ?><a href="preventive_plans.php?plan_id=<?php echo $planId; ?>" class="admin-btn info-btn">Edit Future Settings</a><?php endif; ?>
                        <?php if ($isActive): ?>
                            <button type="submit" name="action" value="generate" class="admin-btn primary-btn">Generate Due Occurrence</button>
                            <button type="submit" name="action" value="disable_plan" class="admin-btn neutral-btn" onclick="return confirm('Disable this plan? Existing work orders and history are kept.');">Disable Plan</button>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>

                <?php if ($jobs): ?>
                <div class="table-wrapper">
                    <table class="request-table">
                        <thead><tr><th>Work Order</th><th>Period</th><th>Status</th><th>Assignee</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($jobs as $job): ?>
                            <?php
                            $jobActive = in_array((string) $job["status"], ["Scheduled", "In Progress"], true);
                            $mayWork = $jobActive && ($canManage || (int) $job["assigned_to"] === $actorId);
                            ?>
                            <tr>
                                <td>MTN-<?php echo str_pad((string) $job["maintenance_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                <td><?php echo htmlspecialchars(formatDateTimeDisplay((string) $job["start_date"]) . " - " . formatDateTimeDisplay((string) $job["end_date"])); ?></td>
                                <td><span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) $job["status"])); ?>"><?php echo htmlspecialchars((string) $job["status"]); ?></span></td>
                                <td><?php echo htmlspecialchars((string) ($job["assignee_name"] ?? "Unassigned")); ?></td>
                                <td>
                                    <?php if ($mayWork): ?>
                                        <form method="POST" class="inline-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo $token; ?>">
                                            <input type="hidden" name="plan_id" value="<?php echo $planId; ?>">
                                            <input type="hidden" name="maintenance_id" value="<?php echo (int) $job["maintenance_id"]; ?>">
                                            <input class="form-control-block" name="completion_notes" maxlength="1000" placeholder="Completion or cancellation notes" aria-label="Notes for MTN-<?php echo (int) $job["maintenance_id"]; ?>">
                                            <?php if ((string) $job["status"] === "Scheduled"): ?>
                                                <button type="submit" name="action" value="start_job" class="admin-btn primary-btn">Start</button>
                                            <?php else: ?>
                                                <button type="submit" name="action" value="complete_job" class="admin-btn primary-btn">Complete</button>
                                            <?php endif; ?>
                                            <?php if ($canManage): ?>
                                                <button type="submit" name="action" value="cancel_job" class="admin-btn neutral-btn" onclick="return confirm('Cancel this occurrence? It stays in Maintenance History.');">Cancel</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php elseif (!$jobActive): ?>
                                        <a class="admin-btn" href="maintenance_history.php?plan_id=<?php echo $planId; ?>">View in history</a>
                                    <?php else: ?>
                                        <span class="row-action-note">Assigned personnel only</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                    <p class="empty-note">No occurrence has been generated from this plan yet.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </main>
</div>

<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
