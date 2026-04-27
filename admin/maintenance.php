<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/request_helper.php";
require_once "../includes/maintenance_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Maintenance";
$activePage = "maintenance";
$cssFile = "../assets/css/admin.css";

$adminUserId = (int) ($_SESSION["user_id"] ?? 0);

function redirectMaintenancePage(): void
{
    header("Location: maintenance.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $action = trim((string) ($_POST["action"] ?? ""));

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        redirectMaintenancePage();
    }

    if ($action === "add_schedule") {
        $resourceId = (int) ($_POST["resource_id"] ?? 0);
        $startDate = trim((string) ($_POST["start_date"] ?? ""));
        $durationDays = max(1, (int) ($_POST["duration_days"] ?? 1));
        $reason = trim((string) ($_POST["reason"] ?? ""));
        $remarks = trim((string) ($_POST["remarks"] ?? ""));

        $resourceStmt = $pdo->prepare("
            SELECT resource_id, resource_name, resource_type, is_archived
            FROM resources
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $resourceStmt->execute([
            ":resource_id" => $resourceId
        ]);
        $resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);

        $errors = [];

        if (!$resource) {
            $errors[] = "Selected resource was not found.";
        } elseif ((int) ($resource["is_archived"] ?? 0) === 1) {
            $errors[] = "Archived resources cannot receive maintenance schedules.";
        }

        if ($startDate === "") {
            $errors[] = "Maintenance start date is required.";
        } elseif ($startDate < date("Y-m-d")) {
            $errors[] = "Past maintenance dates are not allowed.";
        }

        if ($reason === "") {
            $errors[] = "Maintenance reason is required.";
        }

        $endDate = calculateMaintenanceEndDate($startDate, $durationDays);

        if ($endDate === null) {
            $errors[] = "Invalid maintenance date range.";
        }

        if (empty($errors)) {
            $maintenanceConflict = getMaintenanceConflict(
                $pdo,
                $resourceId,
                normalizeDateTimeInput($startDate . " 00:00:00"),
                normalizeDateTimeInput($endDate . " 23:59:59")
            );

            if ($maintenanceConflict) {
                $errors[] = "This resource already has a maintenance schedule that overlaps the selected dates.";
            }
        }

        if (empty($errors)) {
            $scheduleRangeStart = normalizeDateTimeInput($startDate . " 00:00:00");
            $scheduleRangeEnd = normalizeDateTimeInput($endDate . " 23:59:59");
            $activeRequests = fetchActiveScheduleRequests($pdo, $resourceId);

            foreach ($activeRequests as $activeRequest) {
                $requestRange = buildRequestDateRange(
                    (string) ($resource["resource_type"] ?? "Item"),
                    $activeRequest["date_needed"] ?? null,
                    $activeRequest["start_time"] ?? null,
                    $activeRequest["end_time"] ?? null,
                    $activeRequest["due_date"] ?? null,
                    $activeRequest["status"] ?? null
                );

                if ($requestRange === null || $scheduleRangeStart === null || $scheduleRangeEnd === null) {
                    continue;
                }

                if (intervalsOverlap($scheduleRangeStart, $scheduleRangeEnd, $requestRange["start"], $requestRange["end"])) {
                    $errors[] = "Cannot schedule maintenance because there is an active request overlapping that date range.";
                    break;
                }
            }
        }

        if (!empty($errors)) {
            $_SESSION["flash_message"] = implode(" ", $errors);
            $_SESSION["flash_type"] = "error";
            redirectMaintenancePage();
        }

        $status = ($startDate === date("Y-m-d")) ? "In Progress" : "Scheduled";

        $insertStmt = $pdo->prepare("
            INSERT INTO maintenance_schedules
            (
                resource_id,
                start_date,
                end_date,
                duration_days,
                reason,
                remarks,
                status,
                created_by,
                updated_by,
                updated_at
            )
            VALUES
            (
                :resource_id,
                :start_date,
                :end_date,
                :duration_days,
                :reason,
                :remarks,
                :status,
                :created_by,
                :updated_by,
                NOW()
            )
        ");
        $success = $insertStmt->execute([
            ":resource_id" => $resourceId,
            ":start_date" => $startDate,
            ":end_date" => $endDate,
            ":duration_days" => $durationDays,
            ":reason" => $reason,
            ":remarks" => ($remarks === "" ? null : $remarks),
            ":status" => $status,
            ":created_by" => $adminUserId,
            ":updated_by" => $adminUserId
        ]);

        if ($success) {
            syncMaintenanceSchedules($pdo);
            refreshResourceOperationalStatus($pdo, $resourceId);

            addActivityLog(
                $pdo,
                $adminUserId,
                "Maintenance Scheduled",
                "Scheduled maintenance for " . ($resource["resource_name"] ?? "resource")
                    . " from {$startDate} to {$endDate}."
            );
        }

        $_SESSION["flash_message"] = $success ? "Maintenance schedule saved successfully." : "Failed to save maintenance schedule.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectMaintenancePage();
    }

    if (in_array($action, ["complete_schedule", "cancel_schedule"], true)) {
        $maintenanceId = (int) ($_POST["maintenance_id"] ?? 0);

        $scheduleStmt = $pdo->prepare("
            SELECT ms.maintenance_id, ms.resource_id, ms.reason, r.resource_name
            FROM maintenance_schedules ms
            INNER JOIN resources r ON ms.resource_id = r.resource_id
            WHERE ms.maintenance_id = :maintenance_id
            LIMIT 1
        ");
        $scheduleStmt->execute([
            ":maintenance_id" => $maintenanceId
        ]);
        $schedule = $scheduleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$schedule) {
            $_SESSION["flash_message"] = "Maintenance schedule not found.";
            $_SESSION["flash_type"] = "error";
            redirectMaintenancePage();
        }

        $nextStatus = $action === "complete_schedule" ? "Completed" : "Cancelled";
        $updateStmt = $pdo->prepare("
            UPDATE maintenance_schedules
            SET
                status = :status,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE maintenance_id = :maintenance_id
            LIMIT 1
        ");
        $success = $updateStmt->execute([
            ":status" => $nextStatus,
            ":updated_by" => $adminUserId,
            ":maintenance_id" => $maintenanceId
        ]);

        if ($success) {
            refreshResourceOperationalStatus($pdo, (int) $schedule["resource_id"]);

            addActivityLog(
                $pdo,
                $adminUserId,
                $nextStatus === "Completed" ? "Maintenance Completed" : "Maintenance Cancelled",
                ($nextStatus === "Completed" ? "Completed" : "Cancelled")
                    . " maintenance for " . ($schedule["resource_name"] ?? "resource")
                    . "."
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Maintenance schedule updated successfully."
            : "Failed to update maintenance schedule.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectMaintenancePage();
    }
}

$selectedResourceId = isset($_GET["resource_id"]) ? (int) $_GET["resource_id"] : 0;
$search = trim((string) ($_GET["search"] ?? ""));
$statusFilter = trim((string) ($_GET["status"] ?? "All"));

$resourceOptionsStmt = $pdo->query("
    SELECT resource_id, resource_name, resource_type, status, condition_status
    FROM resources
    WHERE is_archived = 0
    ORDER BY resource_name ASC
");
$resourceOptions = $resourceOptionsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_schedules,
        SUM(CASE WHEN status = 'Scheduled' THEN 1 ELSE 0 END) AS scheduled_count,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) AS in_progress_count,
        SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count
    FROM maintenance_schedules
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$scheduleStmt = $pdo->query("
    SELECT
        ms.maintenance_id,
        ms.resource_id,
        ms.start_date,
        ms.end_date,
        ms.duration_days,
        ms.reason,
        ms.remarks,
        ms.status,
        ms.created_at,
        ms.updated_at,
        r.resource_name,
        r.resource_type,
        r.status AS resource_status,
        r.condition_status,
        creator.full_name AS created_by_name,
        updater.full_name AS updated_by_name
    FROM maintenance_schedules ms
    INNER JOIN resources r ON ms.resource_id = r.resource_id
    LEFT JOIN users creator ON ms.created_by = creator.user_id
    LEFT JOIN users updater ON ms.updated_by = updater.user_id
    ORDER BY
        CASE
            WHEN ms.status = 'In Progress' THEN 1
            WHEN ms.status = 'Scheduled' THEN 2
            WHEN ms.status = 'Completed' THEN 3
            WHEN ms.status = 'Cancelled' THEN 4
            ELSE 5
        END,
        ms.start_date ASC,
        ms.maintenance_id DESC
");
$scheduleRows = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filteredSchedules = [];

foreach ($scheduleRows as $row) {
    if ($statusFilter !== "All" && strcasecmp((string) $row["status"], $statusFilter) !== 0) {
        continue;
    }

    if ($search !== "") {
        $haystack = implode(" ", [
            (string) ($row["resource_name"] ?? ""),
            (string) ($row["resource_type"] ?? ""),
            (string) ($row["reason"] ?? ""),
            (string) ($row["remarks"] ?? "")
        ]);

        if (stripos($haystack, $search) === false) {
            continue;
        }
    }

    $filteredSchedules[] = $row;
}

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Maintenance</h2>
            <p>Schedule preventive or corrective maintenance and automatically block resources from borrowing while maintenance is active.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="admin-grid">
            <div class="summary-card">
                <h3>Total Schedules</h3>
                <p class="summary-number"><?php echo (int) ($summary["total_schedules"] ?? 0); ?></p>
            </div>
            <div class="summary-card">
                <h3>Scheduled</h3>
                <p class="summary-number"><?php echo (int) ($summary["scheduled_count"] ?? 0); ?></p>
            </div>
            <div class="summary-card">
                <h3>In Progress</h3>
                <p class="summary-number"><?php echo (int) ($summary["in_progress_count"] ?? 0); ?></p>
            </div>
            <div class="summary-card">
                <h3>Completed</h3>
                <p class="summary-number"><?php echo (int) ($summary["completed_count"] ?? 0); ?></p>
            </div>
        </div>

        <div class="card">
            <h3>Schedule Maintenance</h3>
            <form method="POST" class="request-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="add_schedule">

                <div class="form-row">
                    <label for="resource_id">Resource</label>
                    <select id="resource_id" name="resource_id" required>
                        <option value="">Select Resource</option>
                        <?php foreach ($resourceOptions as $resourceOption): ?>
                            <?php $optionValue = (int) ($resourceOption["resource_id"] ?? 0); ?>
                            <option
                                value="<?php echo $optionValue; ?>"
                                <?php echo $selectedResourceId === $optionValue ? "selected" : ""; ?>
                            >
                                <?php echo htmlspecialchars((string) $resourceOption["resource_name"]); ?>
                                (<?php echo htmlspecialchars((string) $resourceOption["resource_type"]); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <label for="start_date">Maintenance Start Date</label>
                    <input type="date" id="start_date" name="start_date" min="<?php echo htmlspecialchars(date("Y-m-d")); ?>" required>
                </div>

                <div class="form-row">
                    <label for="duration_days">Number of Days</label>
                    <input type="number" id="duration_days" name="duration_days" min="1" value="1" required>
                </div>

                <div class="form-row">
                    <label for="reason">Reason</label>
                    <input type="text" id="reason" name="reason" placeholder="Preventive check, repair, inspection..." required>
                </div>

                <div class="form-row">
                    <label for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="3" placeholder="Optional maintenance remarks..."></textarea>
                </div>

                <div class="profile-actions">
                    <button type="submit" class="admin-btn primary-btn">Save Schedule</button>
                    <a href="inventory.php" class="admin-btn info-btn">Back to Inventory</a>
                </div>
            </form>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search resource, reason, or remarks..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Scheduled" <?php echo $statusFilter === "Scheduled" ? "selected" : ""; ?>>Scheduled</option>
                    <option value="In Progress" <?php echo $statusFilter === "In Progress" ? "selected" : ""; ?>>In Progress</option>
                    <option value="Completed" <?php echo $statusFilter === "Completed" ? "selected" : ""; ?>>Completed</option>
                    <option value="Cancelled" <?php echo $statusFilter === "Cancelled" ? "selected" : ""; ?>>Cancelled</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resource</th>
                            <th>Maintenance Period</th>
                            <th>Days</th>
                            <th>Reason</th>
                            <th>Remarks</th>
                            <th>Status</th>
                            <th>Updated By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($filteredSchedules) > 0): ?>
                            <?php foreach ($filteredSchedules as $schedule): ?>
                                <?php $statusClass = getStatusCssClass((string) ($schedule["status"] ?? "")); ?>
                                <tr>
                                    <td>MTN-<?php echo str_pad((string) $schedule["maintenance_id"], 3, "0", STR_PAD_LEFT); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $schedule["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars((string) $schedule["resource_type"]); ?>
                                            | Resource Status:
                                            <?php echo htmlspecialchars((string) ($schedule["resource_status"] ?? "N/A")); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($schedule["start_date"] ?? "")); ?>
                                        -
                                        <?php echo htmlspecialchars(formatDateTimeDisplay($schedule["end_date"] ?? "")); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($schedule["duration_days"] ?? "1")); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($schedule["reason"] ?? "N/A")); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($schedule["remarks"] ?? "N/A")); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                            <?php echo htmlspecialchars((string) ($schedule["status"] ?? "N/A")); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo !empty($schedule["updated_by_name"]) ? htmlspecialchars((string) $schedule["updated_by_name"]) : "N/A"; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for maintenance MTN-<?php echo str_pad((string) $schedule["maintenance_id"], 3, "0", STR_PAD_LEFT); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <?php if (in_array((string) ($schedule["status"] ?? ""), ["Scheduled", "In Progress"], true)): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Mark this maintenance as completed?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="action" value="complete_schedule">
                                                        <input type="hidden" name="maintenance_id" value="<?php echo htmlspecialchars((string) $schedule["maintenance_id"]); ?>">
                                                        <button type="submit" class="success-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                            <span>Complete</span>
                                                        </button>
                                                    </form>

                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Cancel this maintenance schedule?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="action" value="cancel_schedule">
                                                        <input type="hidden" name="maintenance_id" value="<?php echo htmlspecialchars((string) $schedule["maintenance_id"]); ?>">
                                                        <button type="submit" class="danger-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                                            <span>Cancel</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="row-action-note">No action available</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="empty-note">No maintenance schedules found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>
