<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/facility_calendar_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_line_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_attachment_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Request Resource";
$activePage = "browse";
$cssFile = "../public/assets/css/pages/borrower.css";

$message = "";
$messageType = "";

if (!isset($_GET["resource_id"]) || empty($_GET["resource_id"])) {
    redirectWithFlash("browse.php", "Resource ID is required.");
}

$resourceId = (int) $_GET["resource_id"];
$borrowerId = (int) $_SESSION["user_id"];

$resourceStmt = $pdo->prepare("
    SELECT
        resource_id,
        resource_name,
        resource_type,
        tracking_mode,
        category,
        description,
        location,
        total_stock,
        available_stock,
        under_repair_stock,
        capacity,
        status,
        condition_status,
        condition_notes,
        is_archived
    FROM resources
    WHERE resource_id = :resource_id
      AND is_archived = 0
    LIMIT 1
");
$resourceStmt->execute([
    ":resource_id" => $resourceId
]);

$resource = $resourceStmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    redirectWithFlash("browse.php", "Resource not found or already archived.");
}

$resourceType = (string) ($resource["resource_type"] ?? "Item");
$isFacility = $resourceType === "Facility";
$defaultQuantity = $isFacility ? "1" : "";

$formQuantity = $_POST["quantity"] ?? $defaultQuantity;
$formContactNumber = $_POST["contact_number"] ?? "";
$formDateNeeded = $_POST["date_needed"] ?? "";
$formStartTime = $_POST["start_time"] ?? "";
$formEndTime = $_POST["end_time"] ?? "";
$formNotes = $_POST["notes"] ?? "";
$resourceBaseStatus = (string) ($resource["status"] ?? "Unavailable");
$resourceBaseStatusClass = getStatusCssClass($resourceBaseStatus);
$initialPastFacilitySchedule = $isFacility && isPastFacilitySchedule(
    $resourceType,
    $formDateNeeded !== "" ? $formDateNeeded : null,
    $formStartTime !== "" ? $formStartTime : null
);
$resourceDisplayedStatus = $initialPastFacilitySchedule ? "Unavailable" : $resourceBaseStatus;
$resourceDisplayedStatusClass = getStatusCssClass($resourceDisplayedStatus);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $quantity = $isFacility ? 1 : (int) ($_POST["quantity"] ?? 0);
    $contactNumber = preg_replace('/\s+/', ' ', trim((string) ($_POST["contact_number"] ?? "")));
    $dateNeeded = trim((string) ($_POST["date_needed"] ?? ""));
    $startTime = trim((string) ($_POST["start_time"] ?? ""));
    $endTime = trim((string) ($_POST["end_time"] ?? ""));
    $notes = trim((string) ($_POST["notes"] ?? ""));

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $message = "Invalid request token.";
        $messageType = "error";
    } else {
        $errors = [];

        if ($contactNumber === "") {
            $errors[] = "Contact number is required for urgent return reminders.";
        } elseif (!preg_match('/^[0-9+\-\s()]{7,30}$/', $contactNumber)) {
            $errors[] = "Please enter a valid contact number.";
        }

        // Phase 3.5A: one request carries many resource lines. The page's
        // primary resource is always line one; extra_lines[] adds the rest.
        // Only resource ids and quantities are accepted from the browser - the
        // classification of every line is re-read from the database below.
        $submittedLines = [["resource_id" => $resourceId, "quantity" => $quantity]];

        foreach ((array) ($_POST["extra_lines"] ?? []) as $extraLine) {
            if (!is_array($extraLine)) {
                continue;
            }

            $submittedLines[] = [
                "resource_id" => (int) ($extraLine["resource_id"] ?? 0),
                "quantity" => (int) ($extraLine["quantity"] ?? 0),
            ];
        }

        $normalized = gsoNormalizeRequestedLines($pdo, $submittedLines);
        $requestLines = $normalized["lines"];
        $errors = array_merge($errors, $normalized["errors"]);

        // The schedule is request-level, so it is validated once against the
        // strictest line: a facility line drives facility schedule rules.
        $scheduleType = "Item";

        foreach ($requestLines as $line) {
            if ($line["line_kind"] === "Facility") {
                $scheduleType = "Facility";
                break;
            }
        }

        $errors = array_merge($errors, validateBorrowScheduleInputs(
            $scheduleType,
            $scheduleType === "Facility" ? 1 : max(1, $quantity),
            $dateNeeded !== "" ? $dateNeeded : null,
            $startTime !== "" ? $startTime : null,
            $endTime !== "" ? $endTime : null,
            null
        ));

        $validatedLetter = null;
        if ($scheduleType === "Facility") {
            try { $validatedLetter=gsoValidateRequestLetter($_FILES["request_letter"]??[]); }
            catch (DomainException $e) { $errors[]=$e->getMessage(); }
        }

        // Per-line duplicate and availability checks. Availability runs through
        // the unchanged shared service, which now reads request lines.
        if (empty($errors)) {
            foreach ($requestLines as $line) {
                $lineResource = $line["resource"];
                $lineType = (string) $lineResource["resource_type"];

                if (findBorrowerDuplicateConflict(
                    $pdo,
                    $borrowerId,
                    (int) $line["resource_id"],
                    $lineType,
                    $dateNeeded,
                    $startTime !== "" ? $startTime : null,
                    $endTime !== "" ? $endTime : null,
                    null
                )) {
                    $errors[] = "You already have a request for "
                        . $lineResource["resource_name"] . " on the same schedule.";
                    continue;
                }

                $availability = checkResourceAvailability(
                    $pdo,
                    $lineResource,
                    (int) $line["quantity"],
                    $dateNeeded,
                    $startTime !== "" ? $startTime : null,
                    $endTime !== "" ? $endTime : null,
                    null
                );

                if (!($availability["ok"] ?? false)) {
                    $errors[] = $lineResource["resource_name"] . ": "
                        . (string) ($availability["message"] ?? "unavailable for the selected schedule.");
                }
            }
        }

        if (!empty($errors)) {
            $message = implode(" ", $errors);
            $messageType = "error";
        } else {
            $insertStmt = $pdo->prepare("
                INSERT INTO resource_requests
                (
                    borrower_id,
                    resource_id,
                    quantity,
                    contact_number,
                    date_needed,
                    start_time,
                    end_time,
                    request_date,
                    status,
                    approved_by,
                    approved_at,
                    due_date,
                    return_date,
                    notes
                )
                VALUES
                (
                    :borrower_id,
                    :resource_id,
                    :quantity,
                    :contact_number,
                    :date_needed,
                    :start_time,
                    :end_time,
                    NOW(),
                    'Pending',
                    NULL,
                    NULL,
                    NULL,
                    NULL,
                    :notes
                )
            ");

            // Legacy compatibility mirror only: the primary line is written to
            // resource_requests.resource_id/quantity so paths that have not yet
            // migrated still render something truthful. It is NOT the complete
            // request - request_items is authoritative for every decision.
            $mirrorLine = gsoPrimaryMirrorLine($requestLines);

            $storedLetter=null;
            try {
            $pdo->beginTransaction();

            $success = $insertStmt->execute([
                ":borrower_id" => $borrowerId,
                ":resource_id" => (int) ($mirrorLine["resource_id"] ?? $resourceId),
                ":quantity" => (int) ($mirrorLine["quantity"] ?? $quantity),
                ":contact_number" => $contactNumber,
                ":date_needed" => $dateNeeded,
                ":start_time" => $startTime !== "" ? $startTime : null,
                ":end_time" => $endTime !== "" ? $endTime : null,
                ":notes" => $notes !== "" ? $notes : null
            ]);

            if ($success) {
                $newRequestId = (int) $pdo->lastInsertId();

                // One header, all lines, one commit. If any line fails the whole
                // request is rolled back, so a partially-built request with a
                // live reference number can never exist.
                $lineStmt = $pdo->prepare("
                    INSERT INTO request_items
                        (request_id, resource_id, line_kind, requested_quantity)
                    VALUES
                        (:request_id, :resource_id, :line_kind, :requested_quantity)
                ");

                foreach ($requestLines as $line) {
                    $lineStmt->execute([
                        ":request_id" => $newRequestId,
                        ":resource_id" => (int) $line["resource_id"],
                        ":line_kind" => $line["line_kind"],
                        ":requested_quantity" => (int) $line["quantity"],
                    ]);
                }

                if ($validatedLetter !== null) {
                    $storedLetter=gsoStoreRequestLetter($validatedLetter);
                    gsoInsertRequestLetter($pdo,$newRequestId,$borrowerId,$storedLetter);
                }

                // One request, one reference, one notification per recipient -
                // never one per resource line.
                $lineLabels = [];

                foreach ($requestLines as $line) {
                    $lineLabels[] = $line["resource"]["resource_name"]
                        . ($line["line_kind"] === "Facility" ? "" : " x" . $line["quantity"]);
                }

                $lineSummary = implode(", ", $lineLabels);

                addActivityLog(
                    $pdo,
                    $borrowerId,
                    "Borrower Request Submitted",
                    "Borrower submitted request #{$newRequestId} covering " . count($requestLines)
                        . " resource line(s): " . substr($lineSummary, 0, 180)
                );

                $adminRows = $pdo->query("
                    SELECT user_id
                    FROM users
                    WHERE role = 'Admin'
                      AND account_status = 'Approved'
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                foreach ($adminRows as $adminRow) {
                    createNotification(
                        $pdo,
                        (int) $adminRow["user_id"],
                        "request",
                        "New Resource Request",
                        substr(formatRequestCode($newRequestId) . " requires review: " . $lineSummary, 0, 255),
                        "requests.php"
                    );
                }

                $pdo->commit();

                $message = "Request submitted successfully. "
                    . formatRequestCode($newRequestId) . " covers " . count($requestLines)
                    . " resource line(s). Please wait for admin review.";
                $messageType = "success";

                $formQuantity = $defaultQuantity;
                $formContactNumber = "";
                $formDateNeeded = "";
                $formStartTime = "";
                $formEndTime = "";
                $formNotes = "";
            } else {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $message = "Failed to submit request.";
                $messageType = "error";
            }
            } catch (Throwable $submissionError) {
                // A duplicate line, a second facility or any other constraint
                // breach discards the whole request rather than leaving a
                // half-built one behind.
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ($storedLetter) @unlink($storedLetter["path"]);
                error_log("Combined request submission failed: " . $submissionError->getMessage());
                $message = "The request could not be submitted. Please review your selected resources and try again.";
                $messageType = "error";
            }
        }
    }
}

// Resources the borrower may add as extra lines. The page's primary resource
// is excluded because it is always line one, and the classification shown here
// is only a hint: every submitted line is re-classified server-side.
$selectableStmt = $pdo->prepare("
    SELECT resource_id, resource_name, resource_type, tracking_mode
    FROM resources
    WHERE is_archived = 0
      AND status <> 'Unavailable'
      AND resource_id <> :resource_id
    ORDER BY resource_type ASC, resource_name ASC
");
$selectableStmt->execute([":resource_id" => $resourceId]);
$selectableResources = [];

foreach ($selectableStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $selectableRow) {
    $selectableRow["line_kind"] = gsoLineKindForTrackingMode(
        (string) $selectableRow["tracking_mode"],
        (string) $selectableRow["resource_type"]
    );
    $selectableResources[] = $selectableRow;
}

$primaryLineKind = gsoLineKindForTrackingMode(
    (string) ($resource["tracking_mode"] ?? "Aggregate"),
    $resourceType
);

$todayDate = date("Y-m-d");
$minDateTime = date("Y-m-d\TH:i");
$facilityCalendarHtml = "";

if ($isFacility) {
    $facilityCalendarMonth = normalizeFacilityCalendarMonth($_GET["facility_calendar_month"] ?? null);
    $facilityCalendarHtml = renderFacilityAvailabilityCalendar($pdo, [
        "title" => "Facility Availability",
        "subtitle" => "Submitted reservations appear here as soon as they are recorded.",
        "month" => $facilityCalendarMonth,
        "resource_id" => $resourceId,
        "show_borrower" => false,
        "show_resource_name" => false,
        "show_resource_filter" => false,
        "base_url" => "request_resource.php",
        "query_params" => [
            "resource_id" => $resourceId
        ],
        "mark_past_days_unavailable" => true,
        "disable_past_date_selection" => true,
        "month_param" => "facility_calendar_month",
        "resource_param" => "facility_calendar_resource_id"
    ]);
}

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2><?php echo $isFacility ? "Reserve Facility" : "Request Resource"; ?></h2>
            <p>Review the schedule carefully before submitting. Invalid or overlapping schedules are blocked automatically.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="flash-message <?php echo $messageType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($isFacility): ?>
            <div class="card">
                <?php echo $facilityCalendarHtml; ?>
            </div>
        <?php endif; ?>

        <div class="request-layout">
        <div class="card request-summary-card <?php echo $initialPastFacilitySchedule ? "schedule-unavailable" : ""; ?>">
            <h3><?php echo htmlspecialchars($resource["resource_name"]); ?></h3>
            <p><strong>Type:</strong> <?php echo htmlspecialchars($resourceType); ?></p>
            <p><strong>Category:</strong> <?php echo htmlspecialchars($resource["category"] ?? "N/A"); ?></p>
            <p><strong>Location:</strong> <?php echo htmlspecialchars($resource["location"] ?? "N/A"); ?></p>
            <p>
                <strong>Status:</strong>
                <span
                    id="resourceStatusBadge"
                    class="status-badge <?php echo htmlspecialchars($resourceDisplayedStatusClass); ?>"
                    data-base-status="<?php echo htmlspecialchars($resourceBaseStatus); ?>"
                    data-base-status-class="<?php echo htmlspecialchars($resourceBaseStatusClass); ?>"
                >
                    <?php echo htmlspecialchars($resourceDisplayedStatus); ?>
                </span>
            </p>
            <p>
                <strong>Condition:</strong>
                <span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) ($resource["condition_status"] ?? "Good"))); ?>">
                    <?php echo htmlspecialchars((string) ($resource["condition_status"] ?? "Good")); ?>
                </span>
            </p>
            <?php if ($resourceType === "Item"): ?>
                <p><strong>Available Stock:</strong> <?php echo htmlspecialchars((string) ($resource["available_stock"] ?? "0")); ?></p>
                <p><strong>Total Stock:</strong> <?php echo htmlspecialchars((string) ($resource["total_stock"] ?? "0")); ?></p>
            <?php else: ?>
                <p><strong>Capacity:</strong> <?php echo htmlspecialchars((string) ($resource["capacity"] ?? "N/A")); ?></p>
            <?php endif; ?>
            <?php if (!empty($resource["condition_notes"])): ?>
                <p><strong>Condition Notes:</strong> <?php echo htmlspecialchars((string) $resource["condition_notes"]); ?></p>
            <?php endif; ?>
        </div>

        <div class="card request-form-card <?php echo $initialPastFacilitySchedule ? "schedule-unavailable" : ""; ?>">
            <form
                method="POST"
                enctype="multipart/form-data"
                class="request-form"
                id="requestForm"
                data-resource-type="<?php echo htmlspecialchars($resourceType); ?>"
            >
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">

                <?php if ($isFacility): ?>
                    <input type="hidden" id="quantity" name="quantity" value="1">
                <?php else: ?>
                    <div class="form-row">
                        <label for="quantity">Quantity</label>
                        <input
                            type="number"
                            id="quantity"
                            name="quantity"
                            min="1"
                            max="<?php echo max(1, (int) ($resource["total_stock"] ?? 1)); ?>"
                            value="<?php echo htmlspecialchars((string) $formQuantity); ?>"
                            required
                        >
                        <small class="form-help">Stock is validated against overlapping requests, not only the current available count.</small>
                    </div>
                <?php endif; ?>

                <div class="form-row">
                    <label for="contact_number">Contact Number</label>
                    <input
                        type="tel"
                        id="contact_number"
                        name="contact_number"
                        maxlength="30"
                        pattern="[0-9+\-\s()]{7,30}"
                        placeholder="e.g. 0917 123 4567"
                        value="<?php echo htmlspecialchars((string) $formContactNumber); ?>"
                        required
                    >
                    <small class="form-help">Used by GSO only for urgent release or overdue return reminders.</small>
                </div>

                <div class="form-row">
                    <label for="date_needed">Date Needed</label>
                    <input
                        type="date"
                        id="date_needed"
                        name="date_needed"
                        min="<?php echo htmlspecialchars($todayDate); ?>"
                        value="<?php echo htmlspecialchars($formDateNeeded); ?>"
                        required
                    >
                </div>

                <div class="form-row">
                    <label for="start_time">Start Time</label>
                    <input
                        type="time"
                        id="start_time"
                        name="start_time"
                        value="<?php echo htmlspecialchars($formStartTime); ?>"
                        <?php echo $isFacility ? "required" : ""; ?>
                    >
                </div>

                <div class="form-row">
                    <label for="end_time">End Time</label>
                    <input
                        type="time"
                        id="end_time"
                        name="end_time"
                        value="<?php echo htmlspecialchars($formEndTime); ?>"
                        <?php echo $isFacility ? "required" : ""; ?>
                    >
                </div>

                <div class="form-row request-policy-note">
                    <label>Return Due Date</label>
                    <div class="form-help">
                        The due date is generated automatically when GSO releases the resource.
                        <?php if (!$isFacility): ?>
                            Current item policy: <?php echo (int) getDefaultLoanDurationHours(); ?> hours after release.
                        <?php else: ?>
                            Facility reservations close at the approved end time. Valid requests follow first-come-first-served priority.
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-row">
                    <label>Additional resources for this activity</label>
                    <p class="mini-muted">
                        Everything you add here stays on this one request, under a single
                        reference number and a single approval. A request may include at most
                        one facility.
                    </p>
                    <div id="extraLineRows"></div>
                    <div class="request-line-picker">
                        <select id="extraLineResource" aria-label="Resource to add">
                            <option value="">Select a resource to add</option>
                            <?php foreach ($selectableResources as $selectable): ?>
                                <option
                                    value="<?php echo (int) $selectable["resource_id"]; ?>"
                                    data-kind="<?php echo htmlspecialchars((string) $selectable["line_kind"]); ?>"
                                    data-name="<?php echo htmlspecialchars((string) $selectable["resource_name"]); ?>"
                                >
                                    <?php
                                    echo htmlspecialchars(
                                        $selectable["resource_name"]
                                        . " (" . $selectable["line_kind"] . ")"
                                    );
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="extraLineQuantity" min="1" max="1000" value="1" aria-label="Quantity to add">
                        <button type="button" id="addExtraLine" class="admin-btn info-btn">Add resource</button>
                    </div>
                    <p id="extraLineError" class="flash-message flash-error" hidden></p>
                </div>
                <script>
                (function () {
                    const rows = document.getElementById("extraLineRows");
                    const picker = document.getElementById("extraLineResource");
                    const qtyInput = document.getElementById("extraLineQuantity");
                    const addButton = document.getElementById("addExtraLine");
                    const errorBox = document.getElementById("extraLineError");
                    if (!rows || !picker || !qtyInput || !addButton) { return; }

                    // The primary resource is line one, so a facility here means
                    // no further facility may be added. The server re-checks both
                    // rules and the database enforces them regardless.
                    const primaryKind = <?php echo json_encode($primaryLineKind); ?>;
                    let index = 0;

                    const showError = function (text) {
                        errorBox.textContent = text;
                        errorBox.hidden = text === "";
                    };

                    const hasFacility = function () {
                        if (primaryKind === "Facility") { return true; }
                        return rows.querySelector('[data-kind="Facility"]') !== null;
                    };

                    addButton.addEventListener("click", function () {
                        const option = picker.options[picker.selectedIndex];
                        const resourceId = picker.value;
                        if (!resourceId) { showError("Choose a resource to add."); return; }

                        const kind = option.dataset.kind || "Equipment";
                        if (kind === "Facility" && hasFacility()) {
                            showError("A request may include only one facility.");
                            return;
                        }
                        if (rows.querySelector('[data-resource-id="' + resourceId + '"]')) {
                            showError("That resource is already on this request. Edit its quantity instead.");
                            return;
                        }

                        const quantity = kind === "Facility" ? 1 : Math.max(1, Math.min(1000, parseInt(qtyInput.value, 10) || 1));
                        const row = document.createElement("div");
                        row.className = "request-line-row";
                        row.dataset.kind = kind;
                        row.dataset.resourceId = resourceId;

                        const label = document.createElement("span");
                        label.textContent = (option.dataset.name || "Resource")
                            + (kind === "Facility" ? " (Facility)" : " x" + quantity);

                        const idField = document.createElement("input");
                        idField.type = "hidden";
                        idField.name = "extra_lines[" + index + "][resource_id]";
                        idField.value = resourceId;

                        const qtyField = document.createElement("input");
                        qtyField.type = "hidden";
                        qtyField.name = "extra_lines[" + index + "][quantity]";
                        qtyField.value = String(quantity);

                        const remove = document.createElement("button");
                        remove.type = "button";
                        remove.className = "admin-btn";
                        remove.textContent = "Remove";
                        remove.addEventListener("click", function () { row.remove(); showError(""); });

                        row.appendChild(label);
                        row.appendChild(idField);
                        row.appendChild(qtyField);
                        row.appendChild(remove);
                        rows.appendChild(row);

                        index += 1;
                        picker.selectedIndex = 0;
                        qtyInput.value = "1";
                        showError("");
                    });
                })();
                </script>

                <div class="form-row">
                    <label for="notes">Notes</label>
                    <textarea
                        id="notes"
                        name="notes"
                        rows="4"
                        placeholder="Optional notes for this request..."
                    ><?php echo htmlspecialchars($formNotes); ?></textarea>
                </div>

                <div id="scheduleErrorBox" class="flash-message flash-error is-hidden"></div>
                <?php if ($isFacility): ?>
                    <div id="pastFacilityScheduleNote" class="facility-schedule-note" <?php echo $initialPastFacilitySchedule ? "" : "hidden"; ?>>
                        This selected facility schedule is already in the past and is unavailable. Choose a future date and time to continue.
                    </div>
                <?php endif; ?>

                <div class="form-row" id="requestLetterRow">
                    <label for="requestLetter">Signed facility request letter</label>
                    <input type="file" id="requestLetter" name="request_letter" accept=".pdf,.jpg,.jpeg,.png" <?php echo $isFacility ? "required" : ""; ?>>
                    <small class="form-help">Required when any selected resource is a facility. Prepare the letter and obtain required manual signatures outside the system, then upload one PDF, JPG or PNG for the whole request (up to <?php echo htmlspecialchars(gsoFormatBytesLabel(gsoEffectiveUploadLimitBytes(5*1024*1024))); ?>). GSO verifies the document before processing; the system does not sign the letter.</small>
                </div>
                <script>
                document.addEventListener('DOMContentLoaded',function(){
                    const form=document.getElementById('requestForm'), input=document.getElementById('requestLetter'), row=document.getElementById('requestLetterRow');
                    const sync=function(){const required=form.dataset.resourceType==='Facility'||form.querySelector('[data-kind="Facility"]')!==null;input.required=required;row.hidden=!required;};
                    new MutationObserver(sync).observe(form,{childList:true,subtree:true});sync();
                });
                </script>
                <div class="profile-actions">
                    <button
                        type="submit"
                        class="request-btn <?php echo $initialPastFacilitySchedule ? "disabled-btn" : ""; ?>"
                        id="submitRequestButton"
                        data-default-label="Submit Request"
                        <?php echo $initialPastFacilitySchedule ? "disabled" : ""; ?>
                    >
                        <?php echo $initialPastFacilitySchedule ? "Unavailable" : "Submit Request"; ?>
                    </button>
                    <a href="browse.php" class="profile-link-btn">Back to Browse</a>
                </div>
            </form>
        </div>
        </div>
    </main>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const form = document.getElementById("requestForm");
    const resourceType = form.dataset.resourceType || "Item";
    const dateNeeded = document.getElementById("date_needed");
    const startTime = document.getElementById("start_time");
    const endTime = document.getElementById("end_time");
    const errorBox = document.getElementById("scheduleErrorBox");
    const calendarDays = document.querySelectorAll("[data-calendar-date]");
    const statusBadge = document.getElementById("resourceStatusBadge");
    const submitButton = document.getElementById("submitRequestButton");
    const summaryCard = document.querySelector(".request-summary-card");
    const formCard = document.querySelector(".request-form-card");
    const pastFacilityScheduleNote = document.getElementById("pastFacilityScheduleNote");
    const baseStatus = statusBadge ? (statusBadge.dataset.baseStatus || "Unavailable") : "Unavailable";
    const baseStatusClass = statusBadge ? (statusBadge.dataset.baseStatusClass || "unavailable") : "unavailable";
    const defaultSubmitLabel = submitButton ? (submitButton.dataset.defaultLabel || "Submit Request") : "Submit Request";

    function pad(value) {
        return String(value).padStart(2, "0");
    }

    function getTodayDate() {
        const now = new Date();
        return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    }

    function getCurrentTime() {
        const now = new Date();
        return `${pad(now.getHours())}:${pad(now.getMinutes())}`;
    }

    function showErrors(errors) {
        if (errors.length === 0) {
            errorBox.classList.add("is-hidden");
            errorBox.textContent = "";
            return;
        }

        errorBox.classList.remove("is-hidden");
        errorBox.textContent = errors.join(" ");
    }

    function syncSelectedCalendarDay() {
        calendarDays.forEach(function (dayButton) {
            const day = dayButton.closest("[data-calendar-day]");
            if (!day) {
                return;
            }

            day.classList.toggle("is-selected", dayButton.dataset.calendarDate === dateNeeded.value);
        });
    }

    function syncMinTimes() {
        const today = getTodayDate();
        const currentTime = getCurrentTime();

        if (dateNeeded.value === today) {
            startTime.min = currentTime;
            endTime.min = currentTime;
        } else {
            startTime.removeAttribute("min");
            endTime.removeAttribute("min");
        }
    }

    function selectedFacilityScheduleIsPast() {
        if (resourceType !== "Facility" || !dateNeeded.value || !startTime.value) {
            return false;
        }

        const scheduleStart = new Date(`${dateNeeded.value}T${startTime.value}`);

        if (Number.isNaN(scheduleStart.getTime())) {
            return false;
        }

        return scheduleStart.getTime() <= Date.now();
    }

    function syncFacilityAvailabilityState() {
        if (resourceType !== "Facility") {
            return;
        }

        const scheduleIsPast = selectedFacilityScheduleIsPast();

        if (statusBadge) {
            statusBadge.className = `status-badge ${scheduleIsPast ? "unavailable" : baseStatusClass}`;
            statusBadge.textContent = scheduleIsPast ? "Unavailable" : baseStatus;
        }

        [summaryCard, formCard].forEach(function (card) {
            if (!card) {
                return;
            }

            card.classList.toggle("schedule-unavailable", scheduleIsPast);
        });

        if (pastFacilityScheduleNote) {
            pastFacilityScheduleNote.hidden = !scheduleIsPast;
        }

        if (submitButton) {
            submitButton.disabled = scheduleIsPast;
            submitButton.classList.toggle("disabled-btn", scheduleIsPast);
            submitButton.textContent = scheduleIsPast ? "Unavailable" : defaultSubmitLabel;
        }
    }

    function validateSchedule() {
        const errors = [];
        const today = getTodayDate();
        const currentTime = getCurrentTime();

        if (!dateNeeded.value) {
            errors.push("Date needed is required.");
        } else if (dateNeeded.value < today) {
            errors.push("Past dates are not allowed.");
        }

        const hasStart = startTime.value !== "";
        const hasEnd = endTime.value !== "";

        if (resourceType === "Facility" && (!hasStart || !hasEnd)) {
            errors.push("Start time and end time are required for facility reservations.");
        }

        if (resourceType !== "Facility" && hasStart !== hasEnd) {
            errors.push("Provide both start and end time or leave both blank.");
        }

        if (hasStart && hasEnd) {
            if (startTime.value === endTime.value) {
                errors.push("Start time and end time cannot be the same.");
            } else if (startTime.value >= endTime.value) {
                errors.push("End time must be later than the start time.");
            }
        }

        if (dateNeeded.value === today) {
            if (hasStart && startTime.value <= currentTime) {
                errors.push("Past start times are not allowed for today.");
            }

            if (hasEnd && endTime.value <= currentTime) {
                errors.push("Past end times are not allowed for today.");
            }
        }

        showErrors(errors);
        syncFacilityAvailabilityState();
        return errors.length === 0;
    }

    dateNeeded.addEventListener("change", function () {
        syncMinTimes();
        validateSchedule();
        syncSelectedCalendarDay();
    });

    calendarDays.forEach(function (dayButton) {
        dayButton.addEventListener("click", function () {
            const selectedDate = dayButton.dataset.calendarDate || "";

            if (selectedDate === "" || selectedDate < dateNeeded.min) {
                return;
            }

            dateNeeded.value = selectedDate;
            syncMinTimes();
            validateSchedule();
            syncSelectedCalendarDay();
        });
    });

    [startTime, endTime].forEach(function (field) {
        field.addEventListener("input", validateSchedule);
        field.addEventListener("change", validateSchedule);
    });

    form.addEventListener("submit", function (event) {
        syncMinTimes();
        syncFacilityAvailabilityState();

        if (!validateSchedule()) {
            event.preventDefault();
        }
    });

    syncMinTimes();
    validateSchedule();
    syncFacilityAvailabilityState();
    syncSelectedCalendarDay();
});
</script>
