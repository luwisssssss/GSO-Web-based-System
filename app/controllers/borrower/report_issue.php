<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_upload_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Report an Issue";
$activePage = "incidents";
$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/borrower-incidents.css"]);
$backButtonFallback = "my_incidents.php";

$borrowerId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedBorrowerAccount($pdo, $borrowerId)) {
    redirectWithFlash("browse.php", "Your account is not authorized to submit incident reports.");
}

$incidentTypes = getIncidentTypeOptions();
$resources = getIncidentReportableResources($pdo);
$errors = [];

$formTitle = (string) ($_POST["incident_title"] ?? "");
$formType = (string) ($_POST["incident_type"] ?? "");
$formResourceId = (string) ($_POST["resource_id"] ?? ($_GET["resource_id"] ?? ""));
$formLocation = (string) ($_POST["location"] ?? "");
$formDescription = (string) ($_POST["description"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = (string) ($_POST["csrf_token"] ?? "");
    $formTitle = normalizeIncidentSingleLine($_POST["incident_title"] ?? "");
    $formType = trim((string) ($_POST["incident_type"] ?? ""));
    $formResourceId = trim((string) ($_POST["resource_id"] ?? ""));
    $formLocation = normalizeIncidentSingleLine($_POST["location"] ?? "");
    $formDescription = normalizeIncidentDescription($_POST["description"] ?? "");

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrfToken)) {
        $errors[] = "Invalid request token. Please refresh the page and try again.";
    }

    $contentLength = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);
    if ($contentLength > incidentPhotoMaxBytes() + (1024 * 1024)) {
        $errors[] = "The submitted form exceeds the allowed upload size.";
    }

    if (str_contains($formTitle, "\0") || str_contains($formLocation, "\0") || str_contains($formDescription, "\0")) {
        $errors[] = "The report contains invalid control characters.";
    }

    $titleLength = incidentTextLength($formTitle);
    if ($titleLength < 5 || $titleLength > 150) {
        $errors[] = "Incident Title must be between 5 and 150 characters.";
    }

    if (!in_array($formType, $incidentTypes, true)) {
        $errors[] = "Select a valid incident category.";
    }

    $locationLength = incidentTextLength($formLocation);
    if ($locationLength < 1 || $locationLength > 255) {
        $errors[] = "Location is required and must not exceed 255 characters.";
    }

    $descriptionLength = incidentTextLength($formDescription);
    if ($descriptionLength < 10 || $descriptionLength > 5000) {
        $errors[] = "Description must be between 10 and 5,000 characters.";
    }

    $resourceId = null;
    if ($formResourceId !== "") {
        if (!ctype_digit($formResourceId) || (int) $formResourceId <= 0) {
            $errors[] = "Select a valid related resource or leave it blank.";
        } else {
            $resourceId = (int) $formResourceId;
            if (findIncidentReportableResource($pdo, $resourceId) === null) {
                $errors[] = "The selected resource is unavailable or archived.";
            }
        }
    }

    $photoFilename = null;
    $incidentId = null;
    $correlationId = bin2hex(random_bytes(8));
    $photoFile = $_FILES["incident_photo"] ?? null;
    $hasPhoto = is_array($photoFile)
        && (int) ($photoFile["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (empty($errors) && $hasPhoto) {
        $uploadResult = storeIncidentPhoto($photoFile);
        if (!($uploadResult["success"] ?? false)) {
            $errors[] = (string) ($uploadResult["message"] ?? "The incident photo could not be uploaded.");
        } else {
            $photoFilename = (string) $uploadResult["filename"];
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $borrowerLockStmt = $pdo->prepare("
                SELECT 1
                FROM users
                WHERE user_id = :user_id
                  AND role = 'Borrower'
                  AND account_status = 'Approved'
                  AND email_verified = 1
                FOR UPDATE
            ");
            $borrowerLockStmt->execute([":user_id" => $borrowerId]);

            if (!$borrowerLockStmt->fetchColumn()) {
                throw new RuntimeException("The borrower account is no longer authorized.");
            }

            if ($resourceId !== null) {
                $resourceLockStmt = $pdo->prepare("
                    SELECT 1
                    FROM resources
                    WHERE resource_id = :resource_id
                      AND is_archived = 0
                    FOR UPDATE
                ");
                $resourceLockStmt->execute([":resource_id" => $resourceId]);

                if (!$resourceLockStmt->fetchColumn()) {
                    throw new RuntimeException("The related resource is no longer reportable.");
                }
            }

            $insertStmt = $pdo->prepare("
                INSERT INTO incident_reports
                (
                    reporter_id,
                    resource_id,
                    incident_title,
                    incident_type,
                    location,
                    description,
                    photo_path
                )
                VALUES
                (
                    :reporter_id,
                    :resource_id,
                    :incident_title,
                    :incident_type,
                    :location,
                    :description,
                    :photo_path
                )
            ");
            $insertStmt->execute([
                ":reporter_id" => $borrowerId,
                ":resource_id" => $resourceId,
                ":incident_title" => $formTitle,
                ":incident_type" => $formType,
                ":location" => $formLocation,
                ":description" => $formDescription,
                ":photo_path" => $photoFilename,
            ]);

            $incidentId = (int) $pdo->lastInsertId();
            $incidentNumber = formatIncidentNumber($incidentId);
            $activityDetails = truncateIncidentText(
                "Submitted {$incidentNumber}: {$formTitle}",
                255
            );

            if (!addActivityLog(
                $pdo,
                $borrowerId,
                "Incident Report Submitted",
                $activityDetails
            )) {
                throw new RuntimeException("The incident activity could not be recorded.");
            }

            $adminStmt = $pdo->query("
                SELECT user_id
                FROM users
                WHERE role = 'Admin'
                  AND account_status = 'Approved'
                  AND email_verified = 1
                ORDER BY user_id ASC
            ");
            $adminRows = $adminStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($adminRows)) {
                throw new RuntimeException("No eligible administrator is available for notification.");
            }

            $notificationMessage = "{$incidentNumber} was submitted and requires review at {$formLocation}.";

            foreach ($adminRows as $adminRow) {
                if (!createNotification(
                    $pdo,
                    (int) $adminRow["user_id"],
                    "INCIDENT_SUBMITTED",
                    "New Incident Report",
                    $notificationMessage,
                    "view_incident.php?incident_id=" . $incidentId
                )) {
                    throw new RuntimeException("An administrator notification could not be created.");
                }
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($photoFilename !== null && !deleteIncidentPhoto($photoFilename)) {
                error_log("Incident photo rollback cleanup failed. Correlation: {$correlationId}");
            }

            error_log("Incident submission failed [{$correlationId}]: " . $e->getMessage());
            $errors[] = "The incident could not be submitted. Reference: {$correlationId}.";
        }

        if ($incidentId !== null && empty($errors)) {
            unset($_SESSION["csrf_token"]);
            setFlashMessage("Incident {$incidentNumber} was submitted successfully.", "success");
            header("Location: view_incident.php?incident_id=" . $incidentId);
            exit();
        }
    }
}

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>

    <main class="page-content incident-page">
        <div class="card incident-page-hero">
            <div>
                <h2>Report an Issue</h2>
                <p>Tell GSO about damaged property, facility problems, or safety concerns that need inspection.</p>
            </div>
            <a href="my_incidents.php" class="request-btn neutral-action">
                <i class="fa-solid fa-list-check" aria-hidden="true"></i>
                My Incident Reports
            </a>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error" role="alert">
                <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
                <div>
                    <strong>Please correct the following:</strong>
                    <ul class="incident-error-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="incident-form-layout">
            <aside class="card incident-guidance-card">
                <div class="incident-guidance-icon">
                    <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
                </div>
                <h3>Before you submit</h3>
                <ul>
                    <li>Describe what is broken and how it affects the area.</li>
                    <li>Select a resource only when it exists in the inventory.</li>
                    <li>Do not upload IDs, faces, passwords, or unrelated personal information.</li>
                    <li>Reports are reviewed before any resource is marked for maintenance.</li>
                </ul>

                <div class="incident-reporter-card">
                    <span>Reporter</span>
                    <strong><?php echo htmlspecialchars((string) ($_SESSION["full_name"] ?? "Borrower"), ENT_QUOTES, "UTF-8"); ?></strong>
                    <small>Date Reported: <?php echo htmlspecialchars(date("M d, Y h:i A"), ENT_QUOTES, "UTF-8"); ?></small>
                </div>

                <div class="incident-safety-note" id="incidentSafetyNote" hidden>
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    <span>For immediate danger, contact the designated campus security or emergency channel as well.</span>
                </div>
            </aside>

            <section class="card incident-form-card">
                <form method="POST" enctype="multipart/form-data" class="incident-report-form" id="incidentReportForm">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>"
                    >

                    <div class="incident-form-grid">
                        <div class="form-row incident-field-full">
                            <label for="incident_title">Incident Title <span aria-hidden="true">*</span></label>
                            <input
                                type="text"
                                id="incident_title"
                                name="incident_title"
                                minlength="5"
                                maxlength="150"
                                value="<?php echo htmlspecialchars($formTitle, ENT_QUOTES, "UTF-8"); ?>"
                                placeholder="e.g. Broken classroom door"
                                required
                            >
                            <small class="form-help">Use a short, specific title between 5 and 150 characters.</small>
                        </div>

                        <div class="form-row">
                            <label for="incident_type">Incident Category <span aria-hidden="true">*</span></label>
                            <select id="incident_type" name="incident_type" required>
                                <option value="">Select category</option>
                                <?php foreach ($incidentTypes as $incidentType): ?>
                                    <option
                                        value="<?php echo htmlspecialchars($incidentType, ENT_QUOTES, "UTF-8"); ?>"
                                        <?php echo $formType === $incidentType ? "selected" : ""; ?>
                                    >
                                        <?php echo htmlspecialchars($incidentType, ENT_QUOTES, "UTF-8"); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">For Other, explain the concern clearly in the description.</small>
                        </div>

                        <div class="form-row">
                            <label for="resource_id">Related Resource <span class="optional-label">Optional</span></label>
                            <select id="resource_id" name="resource_id">
                                <option value="">Not listed / not applicable</option>
                                <?php foreach ($resources as $resource): ?>
                                    <?php $resourceOptionId = (string) (int) $resource["resource_id"]; ?>
                                    <option
                                        value="<?php echo htmlspecialchars($resourceOptionId, ENT_QUOTES, "UTF-8"); ?>"
                                        data-location="<?php echo htmlspecialchars((string) ($resource["location"] ?? ""), ENT_QUOTES, "UTF-8"); ?>"
                                        <?php echo $formResourceId === $resourceOptionId ? "selected" : ""; ?>
                                    >
                                        <?php
                                        $resourceLabel = (string) $resource["resource_name"]
                                            . " (" . (string) $resource["resource_type"] . ")";
                                        if (!empty($resource["location"])) {
                                            $resourceLabel .= " - " . (string) $resource["location"];
                                        }
                                        echo htmlspecialchars($resourceLabel, ENT_QUOTES, "UTF-8");
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-help">Doors, windows, walls, ceilings, and fixtures may be reported without a resource.</small>
                        </div>

                        <div class="form-row incident-field-full">
                            <label for="location">Location <span aria-hidden="true">*</span></label>
                            <input
                                type="text"
                                id="location"
                                name="location"
                                maxlength="255"
                                value="<?php echo htmlspecialchars($formLocation, ENT_QUOTES, "UTF-8"); ?>"
                                placeholder="Building, room, floor, or nearby landmark"
                                required
                            >
                        </div>

                        <div class="form-row incident-field-full">
                            <label for="description">Description <span aria-hidden="true">*</span></label>
                            <textarea
                                id="description"
                                name="description"
                                rows="7"
                                minlength="10"
                                maxlength="5000"
                                placeholder="Describe the damage, symptoms, affected area, and any immediate risk."
                                required
                            ><?php echo htmlspecialchars($formDescription, ENT_QUOTES, "UTF-8"); ?></textarea>
                            <div class="incident-field-meta">
                                <small class="form-help">Plain text only. Do not include sensitive personal information.</small>
                                <small id="descriptionCounter">0 / 5000</small>
                            </div>
                        </div>

                        <div class="form-row incident-field-full">
                            <label for="incident_photo">Photo <span class="optional-label">Optional</span></label>
                            <div class="incident-photo-picker">
                                <input
                                    type="file"
                                    id="incident_photo"
                                    name="incident_photo"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                >
                                <div class="incident-photo-copy">
                                    <strong>Attach one evidence photo</strong>
                                    <span>JPG or PNG, maximum 5 MB and 4,096 px per side.</span>
                                </div>
                            </div>
                            <div class="incident-photo-preview" id="incidentPhotoPreview" hidden>
                                <img src="" alt="Selected incident photo preview">
                                <button type="button" class="incident-remove-photo" id="removeIncidentPhoto">Remove photo</button>
                            </div>
                        </div>
                    </div>

                    <div class="incident-submit-note">
                        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        <span>Submitting this report does not automatically change a resource to Maintenance. GSO must review it first.</span>
                    </div>

                    <div class="incident-form-actions">
                        <button type="submit" class="request-btn" id="submitIncidentButton">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                            Submit Report
                        </button>
                        <a href="my_incidents.php" class="request-btn neutral-action">Cancel</a>
                    </div>
                </form>
            </section>
        </div>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("incidentReportForm");
    const category = document.getElementById("incident_type");
    const resource = document.getElementById("resource_id");
    const location = document.getElementById("location");
    const description = document.getElementById("description");
    const descriptionCounter = document.getElementById("descriptionCounter");
    const safetyNote = document.getElementById("incidentSafetyNote");
    const photoInput = document.getElementById("incident_photo");
    const preview = document.getElementById("incidentPhotoPreview");
    const previewImage = preview ? preview.querySelector("img") : null;
    const removePhoto = document.getElementById("removeIncidentPhoto");
    const submitButton = document.getElementById("submitIncidentButton");

    function updateSafetyNote() {
        if (safetyNote && category) {
            safetyNote.hidden = category.value !== "Safety";
        }
    }

    function updateDescriptionCounter() {
        if (description && descriptionCounter) {
            descriptionCounter.textContent = description.value.length + " / 5000";
        }
    }

    if (resource && location) {
        resource.addEventListener("change", function () {
            const selected = resource.options[resource.selectedIndex];
            const suggestedLocation = selected ? (selected.dataset.location || "") : "";

            if (suggestedLocation !== "" && location.value.trim() === "") {
                location.value = suggestedLocation;
            }
        });
    }

    if (category) {
        category.addEventListener("change", updateSafetyNote);
        updateSafetyNote();
    }

    if (description) {
        description.addEventListener("input", updateDescriptionCounter);
        updateDescriptionCounter();
    }

    if (photoInput && preview && previewImage) {
        photoInput.addEventListener("change", function () {
            const file = photoInput.files && photoInput.files[0] ? photoInput.files[0] : null;

            if (!file) {
                preview.hidden = true;
                previewImage.removeAttribute("src");
                return;
            }

            if (!["image/jpeg", "image/png"].includes(file.type) || file.size > 5 * 1024 * 1024) {
                photoInput.value = "";
                preview.hidden = true;
                previewImage.removeAttribute("src");
                window.alert("Choose a JPG or PNG image no larger than 5 MB.");
                return;
            }

            const reader = new FileReader();
            reader.addEventListener("load", function () {
                previewImage.src = String(reader.result || "");
                preview.hidden = false;
            });
            reader.readAsDataURL(file);
        });
    }

    if (removePhoto && photoInput && preview && previewImage) {
        removePhoto.addEventListener("click", function () {
            photoInput.value = "";
            previewImage.removeAttribute("src");
            preview.hidden = true;
        });
    }

    if (form && submitButton) {
        form.addEventListener("submit", function () {
            if (!form.checkValidity()) {
                return;
            }

            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Submitting...';
        });
    }
});
</script>
