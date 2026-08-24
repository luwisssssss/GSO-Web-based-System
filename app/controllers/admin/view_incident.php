<?php
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/maintenance_helper.php";

if (!class_exists("IncidentAdminActionException")) {
    class IncidentAdminActionException extends RuntimeException
    {
    }
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Incident Report Details";
$activePage = "incidents";
$cssFile = "../public/assets/css/pages/admin.css";
$cssFiles = array_merge($cssFiles ?? [], [
    "../public/assets/css/pages/borrower-incidents.css",
    "../public/assets/css/pages/admin-incidents.css",
]);
$backButtonFallback = "incidents.php";

$adminId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedAdminAccount($pdo, $adminId)) {
    redirectWithFlash("dashboard.php", "Your account is not authorized to manage incident reports.");
}

$incidentId = filter_input(INPUT_GET, "incident_id", FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);
if (!is_int($incidentId) || $incidentId <= 0) {
    redirectWithFlash("incidents.php", "Incident report not found.");
}

$redirectToIncident = static function (string $message, string $type = "error") use ($incidentId): void {
    redirectWithFlash("view_incident.php?incident_id=" . $incidentId, $message, $type);
};

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string) ($_POST["csrf_token"] ?? "");
    $action = trim((string) ($_POST["action"] ?? ""));
    $postedIncidentId = filter_var($_POST["incident_id"] ?? null, FILTER_VALIDATE_INT, [
        "options" => ["min_range" => 1],
    ]);

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
        $redirectToIncident("Invalid request token. Please refresh the page and try again.");
    }
    if (!is_int($postedIncidentId) || $postedIncidentId !== $incidentId) {
        $redirectToIncident("Invalid incident action target.");
    }
    if (!in_array($action, ["change_status", "change_priority", "update_remarks", "start_maintenance"], true)) {
        $redirectToIncident("Unsupported incident action.");
    }

    $correlationId = bin2hex(random_bytes(8));

    try {
        $pdo->beginTransaction();

        $adminLockStmt = $pdo->prepare("
            SELECT 1
            FROM users
            WHERE user_id = :user_id
              AND role = 'Admin'
              AND account_status = 'Approved'
              AND email_verified = 1
            FOR UPDATE
        ");
        $adminLockStmt->execute([":user_id" => $adminId]);
        if (!$adminLockStmt->fetchColumn()) {
            throw new IncidentAdminActionException("Your Admin account is no longer authorized.");
        }

        $incidentLockStmt = $pdo->prepare("
            SELECT
                incident_id,
                reporter_id,
                resource_id,
                incident_title,
                priority,
                status,
                admin_remarks,
                resolution_notes,
                resolved_at,
                assigned_to
            FROM incident_reports
            WHERE incident_id = :incident_id
            FOR UPDATE
        ");
        $incidentLockStmt->execute([":incident_id" => $incidentId]);
        $lockedIncident = $incidentLockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$lockedIncident) {
            throw new IncidentAdminActionException("Incident report not found.");
        }

        $incidentNumber = formatIncidentNumber($incidentId);
        $successMessage = "Incident report updated successfully.";

        if ($action === "change_status") {
            $expectedStatus = trim((string) ($_POST["expected_status"] ?? ""));
            $nextStatus = trim((string) ($_POST["next_status"] ?? ""));
            $statusRemarks = normalizeIncidentDescription($_POST["status_remarks"] ?? "");
            $resolutionNotes = normalizeIncidentDescription($_POST["resolution_notes"] ?? "");
            $expectedRemarksHash = trim((string) ($_POST["expected_remarks_hash"] ?? ""));
            $currentStatus = (string) $lockedIncident["status"];
            $currentRemarks = (string) ($lockedIncident["admin_remarks"] ?? "");

            if (!hash_equals($currentStatus, $expectedStatus)) {
                throw new IncidentAdminActionException("This incident changed in another session. Refresh and review its current status.");
            }
            if (!in_array($nextStatus, getIncidentStatusOptions(), true)
                || !isValidIncidentStatusTransition($currentStatus, $nextStatus)
            ) {
                throw new IncidentAdminActionException("The selected status transition is not allowed.");
            }
            if (str_contains($statusRemarks, "\0") || incidentTextLength($statusRemarks) > 5000) {
                throw new IncidentAdminActionException("Admin remarks must not exceed 5,000 characters.");
            }
            if (str_contains($resolutionNotes, "\0") || incidentTextLength($resolutionNotes) > 5000) {
                throw new IncidentAdminActionException("Resolution notes must not exceed 5,000 characters.");
            }
            if ($nextStatus === "Rejected" && incidentTextLength($statusRemarks) < 5) {
                throw new IncidentAdminActionException("A rejection reason of at least 5 characters is required.");
            }
            if ($nextStatus === "Resolved" && incidentTextLength($resolutionNotes) < 5) {
                throw new IncidentAdminActionException("Resolution notes of at least 5 characters are required.");
            }
            if (($statusRemarks !== "" || $nextStatus === "Rejected")
                && (!preg_match('/\A[a-f0-9]{64}\z/', $expectedRemarksHash)
                    || !hash_equals(hash("sha256", $currentRemarks), $expectedRemarksHash))
            ) {
                throw new IncidentAdminActionException("Admin remarks changed in another session. Refresh before updating status with remarks.");
            }

            $setClauses = [
                "status = :next_status",
                "assigned_to = COALESCE(assigned_to, :assigned_to)",
                "updated_at = NOW()",
            ];
            $updateParams = [
                ":next_status" => $nextStatus,
                ":assigned_to" => $adminId,
                ":incident_id" => $incidentId,
                ":expected_status" => $currentStatus,
            ];
            if ($statusRemarks !== "" || $nextStatus === "Rejected") {
                $setClauses[] = "admin_remarks = :admin_remarks";
                $updateParams[":admin_remarks"] = $statusRemarks;
            }
            if ($nextStatus === "Resolved") {
                $setClauses[] = "resolution_notes = :resolution_notes";
                $setClauses[] = "resolved_at = NOW()";
                $updateParams[":resolution_notes"] = $resolutionNotes;
            }

            $statusUpdateStmt = $pdo->prepare("
                UPDATE incident_reports
                SET " . implode(", ", $setClauses) . "
                WHERE incident_id = :incident_id
                  AND status = :expected_status
                LIMIT 1
            ");
            $statusUpdateStmt->execute($updateParams);
            if ($statusUpdateStmt->rowCount() !== 1) {
                throw new IncidentAdminActionException("The incident status changed before this action completed. Refresh and try again.");
            }

            $notification = getIncidentStatusNotification($nextStatus, $incidentId);
            if ($notification === null || !createNotification(
                $pdo,
                (int) $lockedIncident["reporter_id"],
                (string) $notification["type"],
                (string) $notification["title"],
                (string) $notification["message"],
                (string) $notification["link"]
            )) {
                throw new RuntimeException("Reporter notification creation failed.");
            }

            $logAction = $nextStatus === "Resolved"
                ? "Incident Resolved"
                : ($nextStatus === "Rejected" ? "Incident Rejected" : "Incident Status Changed");
            $logDetails = truncateIncidentText(
                "Updated {$incidentNumber} from {$currentStatus} to {$nextStatus}.",
                255
            );
            if (!addActivityLog($pdo, $adminId, $logAction, $logDetails)) {
                throw new RuntimeException("Incident status activity logging failed.");
            }

            $successMessage = "{$incidentNumber} is now {$nextStatus}.";
        } elseif ($action === "change_priority") {
            $expectedPriority = trim((string) ($_POST["expected_priority"] ?? ""));
            $nextPriority = trim((string) ($_POST["priority"] ?? ""));
            $currentPriority = (string) $lockedIncident["priority"];

            if (!hash_equals($currentPriority, $expectedPriority)) {
                throw new IncidentAdminActionException("This incident priority changed in another session. Refresh and try again.");
            }
            if (!in_array($nextPriority, getIncidentPriorityOptions(), true)) {
                throw new IncidentAdminActionException("Select a valid incident priority.");
            }
            if ($nextPriority === $currentPriority) {
                throw new IncidentAdminActionException("The incident already has that priority.");
            }

            $priorityStmt = $pdo->prepare("
                UPDATE incident_reports
                SET priority = :priority, updated_at = NOW()
                WHERE incident_id = :incident_id
                  AND priority = :expected_priority
                LIMIT 1
            ");
            $priorityStmt->execute([
                ":priority" => $nextPriority,
                ":incident_id" => $incidentId,
                ":expected_priority" => $currentPriority,
            ]);
            if ($priorityStmt->rowCount() !== 1) {
                throw new IncidentAdminActionException("The incident priority changed before this action completed.");
            }

            if (!addActivityLog(
                $pdo,
                $adminId,
                "Incident Priority Changed",
                "Changed {$incidentNumber} priority from {$currentPriority} to {$nextPriority}."
            )) {
                throw new RuntimeException("Incident priority activity logging failed.");
            }
            $successMessage = "{$incidentNumber} priority was changed to {$nextPriority}.";
        } elseif ($action === "update_remarks") {
            $remarks = normalizeIncidentDescription($_POST["admin_remarks"] ?? "");
            $expectedHash = trim((string) ($_POST["expected_remarks_hash"] ?? ""));
            $currentRemarks = (string) ($lockedIncident["admin_remarks"] ?? "");
            $currentHash = hash("sha256", $currentRemarks);

            if (!preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) || !hash_equals($currentHash, $expectedHash)) {
                throw new IncidentAdminActionException("Admin remarks changed in another session. Refresh before saving.");
            }
            if (str_contains($remarks, "\0") || incidentTextLength($remarks) > 5000) {
                throw new IncidentAdminActionException("Admin remarks must not exceed 5,000 characters.");
            }
            if ($remarks === $currentRemarks) {
                throw new IncidentAdminActionException("No changes were made to the Admin remarks.");
            }
            if ((string) $lockedIncident["status"] === "Rejected" && incidentTextLength($remarks) < 5) {
                throw new IncidentAdminActionException("Rejected incidents must keep a reason of at least 5 characters.");
            }

            $remarksStmt = $pdo->prepare("
                UPDATE incident_reports
                SET admin_remarks = :admin_remarks, updated_at = NOW()
                WHERE incident_id = :incident_id
                LIMIT 1
            ");
            $remarksStmt->execute([
                ":admin_remarks" => $remarks === "" ? null : $remarks,
                ":incident_id" => $incidentId,
            ]);
            if ($remarksStmt->rowCount() !== 1) {
                throw new IncidentAdminActionException("Admin remarks could not be updated.");
            }

            if (!addActivityLog(
                $pdo,
                $adminId,
                "Incident Remarks Updated",
                "Updated reporter-visible Admin remarks for {$incidentNumber}."
            )) {
                throw new RuntimeException("Incident remarks activity logging failed.");
            }
            $successMessage = "Admin remarks for {$incidentNumber} were updated.";
        } else {
            $currentStatus = (string) $lockedIncident["status"];
            $resourceId = (int) ($lockedIncident["resource_id"] ?? 0);
            $durationInput = trim((string) ($_POST["duration_days"] ?? ""));
            $maintenanceRemarks = normalizeIncidentSingleLine($_POST["maintenance_remarks"] ?? "");

            if (!in_array($currentStatus, ["Under Review", "In Progress"], true)) {
                throw new IncidentAdminActionException("Review the incident before starting maintenance.");
            }
            if ($resourceId <= 0) {
                throw new IncidentAdminActionException("This incident is not linked to an inventory resource.");
            }
            if (!ctype_digit($durationInput) || (int) $durationInput < 1 || (int) $durationInput > 365) {
                throw new IncidentAdminActionException("Maintenance duration must be between 1 and 365 days.");
            }
            if (str_contains($maintenanceRemarks, "\0") || incidentTextLength($maintenanceRemarks) > 500) {
                throw new IncidentAdminActionException("Maintenance remarks must not exceed 500 characters.");
            }

            $resourceStmt = $pdo->prepare("
                SELECT resource_id, resource_name, resource_type, status, is_archived
                FROM resources
                WHERE resource_id = :resource_id
                FOR UPDATE
            ");
            $resourceStmt->execute([":resource_id" => $resourceId]);
            $resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$resource || (int) ($resource["is_archived"] ?? 0) === 1) {
                throw new IncidentAdminActionException("The linked resource is unavailable or archived.");
            }
            $startDate = date("Y-m-d");
            $durationDays = (int) $durationInput;
            $endDate = calculateMaintenanceEndDate($startDate, $durationDays);
            if ($endDate === null) {
                throw new IncidentAdminActionException("The maintenance date range is invalid.");
            }

            $maintenanceLockStmt = $pdo->prepare("
                SELECT maintenance_id, start_date, end_date, status
                FROM maintenance_schedules
                WHERE resource_id = :resource_id
                  AND status IN ('Scheduled', 'In Progress')
                  AND NOT (
                        :range_end < CONCAT(start_date, ' 00:00:00')
                        OR :range_start > CONCAT(end_date, ' 23:59:59')
                  )
                ORDER BY maintenance_id ASC
                LIMIT 1
                FOR UPDATE
            ");
            $maintenanceLockStmt->execute([
                ":resource_id" => $resourceId,
                ":range_start" => $startDate . " 00:00:00",
                ":range_end" => $endDate . " 23:59:59",
            ]);
            if ($maintenanceLockStmt->fetch(PDO::FETCH_ASSOC)) {
                throw new IncidentAdminActionException("This resource already has an overlapping active maintenance schedule.");
            }

            $activeRequestLockStmt = $pdo->prepare("
                SELECT request_id, status, return_date
                FROM resource_requests
                WHERE resource_id = :resource_id
                  AND status IN ('Pending', 'Under Review', 'Approved', 'Released')
                  AND (status <> 'Released' OR return_date IS NULL)
                FOR UPDATE
            ");
            $activeRequestLockStmt->execute([":resource_id" => $resourceId]);
            $lockedActiveRequests = $activeRequestLockStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($lockedActiveRequests as $lockedActiveRequest) {
                if ((string) ($lockedActiveRequest["status"] ?? "") === "Released"
                    && empty($lockedActiveRequest["return_date"])
                ) {
                    throw new IncidentAdminActionException(
                        "Maintenance cannot start while request REQ-"
                        . str_pad((string) ($lockedActiveRequest["request_id"] ?? 0), 3, "0", STR_PAD_LEFT)
                        . " is still released and unreturned."
                    );
                }
            }

            $scheduleStart = normalizeDateTimeInput($startDate . " 00:00:00");
            $scheduleEnd = normalizeDateTimeInput($endDate . " 23:59:59");
            foreach (fetchActiveScheduleRequests($pdo, $resourceId) as $activeRequest) {
                $requestRange = buildRequestDateRange(
                    (string) ($resource["resource_type"] ?? "Item"),
                    $activeRequest["date_needed"] ?? null,
                    $activeRequest["start_time"] ?? null,
                    $activeRequest["end_time"] ?? null,
                    $activeRequest["due_date"] ?? null,
                    $activeRequest["status"] ?? null
                );
                if ($requestRange !== null
                    && $scheduleStart !== null
                    && $scheduleEnd !== null
                    && intervalsOverlap($scheduleStart, $scheduleEnd, $requestRange["start"], $requestRange["end"])
                ) {
                    throw new IncidentAdminActionException(
                        "Maintenance cannot start because request REQ-"
                        . str_pad((string) ($activeRequest["request_id"] ?? 0), 3, "0", STR_PAD_LEFT)
                        . " overlaps the selected period."
                    );
                }
            }

            $reason = truncateIncidentText(
                "Incident {$incidentNumber}: " . (string) $lockedIncident["incident_title"],
                255
            );
            $maintenanceStmt = $pdo->prepare("
                INSERT INTO maintenance_schedules
                (
                    resource_id, start_date, end_date, duration_days,
                    reason, remarks, status, created_by, updated_by, updated_at
                )
                VALUES
                (
                    :resource_id, :start_date, :end_date, :duration_days,
                    :reason, :remarks, 'In Progress', :created_by, :updated_by, NOW()
                )
            ");
            $maintenanceStmt->execute([
                ":resource_id" => $resourceId,
                ":start_date" => $startDate,
                ":end_date" => $endDate,
                ":duration_days" => $durationDays,
                ":reason" => $reason,
                ":remarks" => $maintenanceRemarks === "" ? null : $maintenanceRemarks,
                ":created_by" => $adminId,
                ":updated_by" => $adminId,
            ]);
            $maintenanceId = (int) $pdo->lastInsertId();

            refreshResourceOperationalStatus($pdo, $resourceId);

            $resourceStatusStmt = $pdo->prepare("SELECT status FROM resources WHERE resource_id = :resource_id");
            $resourceStatusStmt->execute([":resource_id" => $resourceId]);
            if ((string) $resourceStatusStmt->fetchColumn() !== "Maintenance") {
                throw new RuntimeException("The resource maintenance state could not be verified.");
            }

            if (!addActivityLog(
                $pdo,
                $adminId,
                "Resource Marked as Maintenance",
                truncateIncidentText(
                    "Started MTN-{$maintenanceId} for " . (string) $resource["resource_name"] . " from {$incidentNumber}.",
                    255
                )
            )) {
                throw new RuntimeException("Incident maintenance activity logging failed.");
            }
            $successMessage = "Maintenance started for " . (string) $resource["resource_name"] . ". The incident status was not changed.";
        }

        $pdo->commit();
        $redirectToIncident($successMessage, "success");
    } catch (IncidentAdminActionException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $redirectToIncident($exception->getMessage());
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Admin incident action failed [{$correlationId}]: " . $exception->getMessage());
        $redirectToIncident("The incident action could not be completed. Reference: {$correlationId}.");
    }
}

$incident = findAdminIncidentReport($pdo, $incidentId);
if ($incident === null) {
    redirectWithFlash("incidents.php", "Incident report not found.");
}

$maintenanceRows = [];
if (!empty($incident["resource_id"])) {
    $maintenanceStmt = $pdo->prepare("
        SELECT
            ms.maintenance_id,
            ms.start_date,
            ms.end_date,
            ms.reason,
            ms.remarks,
            ms.status,
            ms.created_at,
            creator.full_name AS created_by_name
        FROM maintenance_schedules ms
        LEFT JOIN users creator ON creator.user_id = ms.created_by
        WHERE ms.resource_id = :resource_id
        ORDER BY
            CASE ms.status
                WHEN 'In Progress' THEN 0
                WHEN 'Scheduled' THEN 1
                ELSE 2
            END,
            ms.created_at DESC,
            ms.maintenance_id DESC
        LIMIT 5
    ");
    $maintenanceStmt->execute([":resource_id" => (int) $incident["resource_id"]]);
    $maintenanceRows = $maintenanceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$hasActiveMaintenance = false;
foreach ($maintenanceRows as $maintenanceRow) {
    if ((string) $maintenanceRow["status"] === "In Progress") {
        $hasActiveMaintenance = true;
        break;
    }
}

$incidentNumber = formatIncidentNumber($incidentId);
$status = (string) $incident["status"];
$priority = (string) $incident["priority"];
$allowedTransitions = getIncidentAllowedTransitions($status);
$remarksHash = hash("sha256", (string) ($incident["admin_remarks"] ?? ""));
$hasPhoto = !empty($incident["photo_path"]);
$flashMessage = (string) ($_SESSION["flash_message"] ?? "");
$flashType = (string) ($_SESSION["flash_type"] ?? "");
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content incident-page admin-incident-page">
        <div class="card incident-page-hero incident-detail-hero">
            <div>
                <span class="incident-reference"><?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?></span>
                <h2><?php echo htmlspecialchars((string) $incident["incident_title"], ENT_QUOTES, "UTF-8"); ?></h2>
                <p>Reported <?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?> by <?php echo htmlspecialchars((string) $incident["reporter_name"], ENT_QUOTES, "UTF-8"); ?></p>
            </div>
            <div class="incident-hero-actions">
                <div class="incident-hero-badges">
                    <span class="incident-priority <?php echo htmlspecialchars(getIncidentPriorityCssClass($priority), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($priority, ENT_QUOTES, "UTF-8"); ?> Priority</span>
                    <span class="status-badge incident-status <?php echo htmlspecialchars(getIncidentStatusCssClass($status), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?></span>
                </div>
                <a href="incidents.php" class="admin-btn info-btn"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Incidents</a>
            </div>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>" role="<?php echo $flashType === "success" ? "status" : "alert"; ?>"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <div class="admin-incident-detail-grid">
            <div class="admin-incident-evidence">
                <section class="card incident-detail-card">
                    <div class="incident-section-heading"><div><span>Submitted report</span><h3>Incident Details</h3></div><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i></div>
                    <dl class="incident-detail-list">
                        <div><dt>Incident ID</dt><dd><?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Category</dt><dd><?php echo htmlspecialchars((string) $incident["incident_type"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Location Reported</dt><dd><?php echo htmlspecialchars((string) $incident["location"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Reported Date</dt><dd><?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Last Updated</dt><dd><?php echo htmlspecialchars(formatIncidentDateTime($incident["updated_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Assigned Admin</dt><dd><?php echo !empty($incident["assigned_to_name"]) ? htmlspecialchars((string) $incident["assigned_to_name"], ENT_QUOTES, "UTF-8") : "Unassigned"; ?></dd></div>
                    </dl>
                    <div class="incident-description-block"><h3>Description</h3><p><?php echo nl2br(htmlspecialchars((string) $incident["description"], ENT_QUOTES, "UTF-8")); ?></p></div>
                </section>

                <section class="card incident-detail-card">
                    <div class="incident-section-heading"><div><span>Reporter</span><h3>Reporter Information</h3></div><i class="fa-solid fa-user" aria-hidden="true"></i></div>
                    <dl class="incident-detail-list">
                        <div><dt>Name</dt><dd><?php echo htmlspecialchars((string) $incident["reporter_name"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>University ID</dt><dd><?php echo htmlspecialchars((string) ($incident["reporter_university_id"] ?: "N/A"), ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Department</dt><dd><?php echo htmlspecialchars((string) ($incident["reporter_department"] ?: "N/A"), ENT_QUOTES, "UTF-8"); ?></dd></div>
                        <div><dt>Account Status</dt><dd><?php echo htmlspecialchars((string) $incident["reporter_account_status"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                    </dl>
                </section>

                <section class="card incident-detail-card">
                    <div class="incident-section-heading"><div><span>Evidence</span><h3>Submitted Photo</h3></div><i class="fa-solid fa-camera" aria-hidden="true"></i></div>
                    <?php if ($hasPhoto): ?>
                        <a href="incident_photo.php?incident_id=<?php echo $incidentId; ?>" class="incident-photo-link admin-incident-photo" target="_blank" rel="noopener">
                            <img src="incident_photo.php?incident_id=<?php echo $incidentId; ?>" alt="Evidence submitted for <?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?>">
                            <span><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Open full image</span>
                        </a>
                    <?php else: ?>
                        <div class="incident-no-photo"><i class="fa-regular fa-image" aria-hidden="true"></i><strong>No photo submitted</strong><span>The reporter did not attach evidence.</span></div>
                    <?php endif; ?>
                </section>

                <section class="card incident-detail-card">
                    <div class="incident-section-heading"><div><span>Resource</span><h3>Related Resource and Maintenance</h3></div><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i></div>
                    <?php if (!empty($incident["resource_id"]) && !empty($incident["resource_name"])): ?>
                        <dl class="incident-detail-list">
                            <div><dt>Resource</dt><dd><?php echo htmlspecialchars((string) $incident["resource_name"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                            <div><dt>Type / Category</dt><dd><?php echo htmlspecialchars((string) $incident["resource_type"] . " / " . (string) ($incident["resource_category"] ?: "N/A"), ENT_QUOTES, "UTF-8"); ?></dd></div>
                            <div><dt>Current Location</dt><dd><?php echo htmlspecialchars((string) ($incident["resource_location"] ?: "N/A"), ENT_QUOTES, "UTF-8"); ?></dd></div>
                            <div><dt>Operational State</dt><dd><?php echo htmlspecialchars((string) $incident["resource_status"] . " / " . (string) $incident["resource_condition"], ENT_QUOTES, "UTF-8"); ?></dd></div>
                            <div><dt>Archive State</dt><dd><?php echo (int) ($incident["resource_is_archived"] ?? 0) === 1 ? "Archived" : "Active"; ?></dd></div>
                            <div><dt>Inventory</dt><dd><?php echo (string) $incident["resource_type"] === "Item" ? (int) ($incident["available_stock"] ?? 0) . " available / " . (int) ($incident["total_stock"] ?? 0) . " total" : "Capacity " . (int) ($incident["capacity"] ?? 0); ?></dd></div>
                        </dl>
                        <div class="incident-resource-actions"><a href="view_resource.php?resource_id=<?php echo (int) $incident["resource_id"]; ?>" class="admin-btn info-btn">View Resource</a><a href="maintenance.php?resource_id=<?php echo (int) $incident["resource_id"]; ?>" class="admin-btn info-btn">Open Maintenance</a></div>

                        <?php if (!empty($maintenanceRows)): ?>
                            <div class="incident-maintenance-list">
                                <?php foreach ($maintenanceRows as $maintenanceRow): ?>
                                    <div><strong>MTN-<?php echo (int) $maintenanceRow["maintenance_id"]; ?> &middot; <?php echo htmlspecialchars((string) $maintenanceRow["status"], ENT_QUOTES, "UTF-8"); ?></strong><span><?php echo htmlspecialchars((string) $maintenanceRow["start_date"] . " to " . (string) $maintenanceRow["end_date"], ENT_QUOTES, "UTF-8"); ?> &middot; <?php echo htmlspecialchars((string) $maintenanceRow["reason"], ENT_QUOTES, "UTF-8"); ?></span></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($hasActiveMaintenance): ?>
                            <div class="incident-action-note warning"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> This resource already has maintenance in progress. Duplicate maintenance is disabled.</div>
                        <?php elseif (!in_array($status, ["Under Review", "In Progress"], true)): ?>
                            <div class="incident-action-note"><i class="fa-solid fa-shield" aria-hidden="true"></i> Move the incident to Under Review before starting resource maintenance.</div>
                        <?php elseif ((int) ($incident["resource_is_archived"] ?? 0) === 1): ?>
                            <div class="incident-action-note warning">Archived resources cannot be marked for maintenance.</div>
                        <?php else: ?>
                            <form method="POST" class="incident-admin-form incident-maintenance-form" data-confirm="Start maintenance for this resource? The incident itself will remain <?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>.">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                                <input type="hidden" name="action" value="start_maintenance">
                                <input type="hidden" name="incident_id" value="<?php echo $incidentId; ?>">
                                <label>Duration (days)<input type="number" name="duration_days" min="1" max="365" value="1" required></label>
                                <label>Maintenance Remarks <span>Optional</span><textarea name="maintenance_remarks" rows="3" maxlength="500" placeholder="Inspection or repair instructions..."></textarea></label>
                                <button type="submit" class="admin-btn warning-btn"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i> Mark Resource as Maintenance</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="incident-no-resource"><i class="fa-solid fa-location-dot" aria-hidden="true"></i><strong>No inventory resource linked</strong><p>This facility/property concern can still be reviewed, prioritized, resolved, or rejected normally. Resource maintenance actions are unavailable.</p></div>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="admin-incident-actions">
                <section class="card incident-action-card">
                    <h3>Status Management</h3>
                    <?php if (!empty($allowedTransitions)): ?>
                        <form method="POST" class="incident-admin-form" id="incidentStatusForm" data-confirm="Apply this incident status change?">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                            <input type="hidden" name="action" value="change_status">
                            <input type="hidden" name="incident_id" value="<?php echo $incidentId; ?>">
                            <input type="hidden" name="expected_status" value="<?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>">
                            <input type="hidden" name="expected_remarks_hash" value="<?php echo htmlspecialchars($remarksHash, ENT_QUOTES, "UTF-8"); ?>">
                            <label>Next Status<select name="next_status" id="incidentNextStatus" required><option value="">Select next status</option><?php foreach ($allowedTransitions as $transition): ?><option value="<?php echo htmlspecialchars($transition, ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($transition, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></label>
                            <label>Admin Remarks <span id="incidentRemarksRequirement">Optional</span><textarea name="status_remarks" id="incidentStatusRemarks" rows="4" maxlength="5000" placeholder="Reporter-visible inspection update or rejection reason..."></textarea></label>
                            <?php if (in_array("Resolved", $allowedTransitions, true)): ?>
                                <label id="incidentResolutionField">Resolution Notes <span id="incidentResolutionRequirement">Required when resolved</span><textarea name="resolution_notes" id="incidentResolutionNotes" rows="4" maxlength="5000" placeholder="Describe the completed corrective action..."></textarea></label>
                            <?php endif; ?>
                            <button type="submit" class="admin-btn primary-btn">Update Status</button>
                        </form>
                    <?php else: ?>
                        <div class="incident-action-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> This incident is in a terminal status. Its record remains available for accountability.</div>
                    <?php endif; ?>
                </section>

                <section class="card incident-action-card">
                    <h3>Priority</h3>
                    <form method="POST" class="incident-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="change_priority">
                        <input type="hidden" name="incident_id" value="<?php echo $incidentId; ?>">
                        <input type="hidden" name="expected_priority" value="<?php echo htmlspecialchars($priority, ENT_QUOTES, "UTF-8"); ?>">
                        <label>Incident Priority<select name="priority" required><?php foreach (getIncidentPriorityOptions() as $option): ?><option value="<?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?>" <?php echo $priority === $option ? "selected" : ""; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?></option><?php endforeach; ?></select></label>
                        <button type="submit" class="admin-btn primary-btn">Save Priority</button>
                    </form>
                </section>

                <section class="card incident-action-card">
                    <h3>Reporter-visible Remarks</h3>
                    <p class="mini-muted">These remarks appear on the reporter's incident detail page.</p>
                    <form method="POST" class="incident-admin-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>">
                        <input type="hidden" name="action" value="update_remarks">
                        <input type="hidden" name="incident_id" value="<?php echo $incidentId; ?>">
                        <input type="hidden" name="expected_remarks_hash" value="<?php echo htmlspecialchars($remarksHash, ENT_QUOTES, "UTF-8"); ?>">
                        <label>Admin Remarks<textarea name="admin_remarks" rows="6" maxlength="5000" placeholder="Add an inspection or processing update..."><?php echo htmlspecialchars((string) ($incident["admin_remarks"] ?? ""), ENT_QUOTES, "UTF-8"); ?></textarea></label>
                        <button type="submit" class="admin-btn primary-btn">Save Remarks</button>
                    </form>
                </section>

                <section class="card incident-action-card incident-review-summary">
                    <h3>Current Review</h3>
                    <div><span>Admin Remarks</span><p><?php echo !empty($incident["admin_remarks"]) ? nl2br(htmlspecialchars((string) $incident["admin_remarks"], ENT_QUOTES, "UTF-8")) : "No remarks recorded."; ?></p></div>
                    <div><span>Resolution</span><p><?php echo !empty($incident["resolution_notes"]) ? nl2br(htmlspecialchars((string) $incident["resolution_notes"], ENT_QUOTES, "UTF-8")) : "No resolution recorded."; ?></p><?php if (!empty($incident["resolved_at"])): ?><small><?php echo htmlspecialchars(formatIncidentDateTime($incident["resolved_at"]), ENT_QUOTES, "UTF-8"); ?></small><?php endif; ?></div>
                </section>
            </aside>
        </div>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const statusSelect = document.getElementById("incidentNextStatus");
    const remarks = document.getElementById("incidentStatusRemarks");
    const remarksRequirement = document.getElementById("incidentRemarksRequirement");
    const resolutionNotes = document.getElementById("incidentResolutionNotes");
    const resolutionRequirement = document.getElementById("incidentResolutionRequirement");

    const updateRequirements = function () {
        if (!statusSelect) return;
        const status = statusSelect.value;
        if (remarks) remarks.required = status === "Rejected";
        if (remarksRequirement) remarksRequirement.textContent = status === "Rejected" ? "Required when rejected" : "Optional";
        if (resolutionNotes) resolutionNotes.required = status === "Resolved";
        if (resolutionRequirement) resolutionRequirement.textContent = status === "Resolved" ? "Required when resolved" : "Complete before choosing Resolved";
    };
    if (statusSelect) {
        statusSelect.addEventListener("change", updateRequirements);
        updateRequirements();
    }

    document.querySelectorAll("form[data-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            if (!form.checkValidity()) return;
            const message = form.getAttribute("data-confirm") || "Continue with this action?";
            if (!window.confirm(message)) event.preventDefault();
        });
    });
});
</script>
