<?php
/**
 * File: /borrower/return_submit.php
 */

$walkinReturn = defined('GSO_WALKIN_RETURN') && GSO_WALKIN_RETURN === true;
if ($walkinReturn) { define('GSO_REQUIRED_PERMISSION','walk_in.create'); require_once dirname(__DIR__,3).'/includes/admin_check.php'; }
else { require_once dirname(__DIR__,3).'/includes/borrower_check.php'; }
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/return_upload_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/return_detail_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Return Submission";
$activePage = "borrowed";

$submitterId = (int)($_SESSION["user_id"] ?? 0);
$borrowerId = $walkinReturn ? null : $submitterId;
$returnHome = $walkinReturn ? 'on_loan.php' : 'my_borrowed.php';
if($walkinReturn)gsoWalkinActor($pdo,$submitterId);
$requestId = isset($_GET["request_id"]) ? (int)$_GET["request_id"] : 0;

if ($submitterId <= 0 || $requestId <= 0) {
    redirectWithFlash($returnHome, "Invalid return request.");
}

$reqStmt = $pdo->prepare("
    SELECT
        rr.request_id,
        r.quantity,
        rr.status,
        rr.date_needed,
        rr.start_time,
        (SELECT MAX(sp.facility_slot) FROM request_items sp WHERE sp.request_id=rr.request_id) AS facility_slot,
        rr.end_time,
        rr.due_date,
        rr.return_date,
        r.resource_name,
        r.resource_type,
        r.category,
        r.location
    FROM resource_requests rr
    INNER JOIN " . gsoRequestResourceRollupSql() . " r ON r.request_id = rr.request_id
    WHERE rr.request_id = :request_id
      AND " . ($walkinReturn ? "rr.requester_type='Walk-in' AND rr.borrower_id IS NULL" : "rr.requester_type='Registered' AND rr.borrower_id = :borrower_id") . "
      AND rr.status = 'Released'
      AND rr.return_date IS NULL
    LIMIT 1
");
$reqStmt->execute([
    ":request_id" => $requestId,
    ...($walkinReturn ? [] : [":borrower_id" => $borrowerId]),
]);
$requestRow = $reqStmt->fetch(PDO::FETCH_ASSOC);

if (!$requestRow) {
    redirectWithFlash($returnHome, "Borrowed request not found or not eligible for return.");
}

// Phase 3.5B: the borrower accounts for every returnable resource of the
// request individually. Issued consumables are excluded - they are gone, not
// returnable - so a consumable-only request never reaches this form at all.
$returnableLines = gsoReturnableLines($pdo, $requestId);

if ($returnableLines === []) {
    redirectWithFlash(
        $returnHome,
        "This request contains only issued materials, so there is nothing to return."
    );
}

// A facility line keeps its post-schedule rule wherever it sits in the request.
$facilityReturnLine = null;
foreach ($returnableLines as $candidateLine) {
    if ((string) $candidateLine["line_kind"] === "Facility") { $facilityReturnLine = $candidateLine; break; }
}

if ($facilityReturnLine !== null) {
    $facilityRange = buildRequestDateRange(
        "Facility",
        $requestRow["date_needed"] ?? null,
        $requestRow["start_time"] ?? null,
        $requestRow["end_time"] ?? null,
        $requestRow["due_date"] ?? null,
        "Released"
    );

    if ($facilityRange !== null && strtotime($facilityRange["end"]) > time()) {
        redirectWithFlash(
            $returnHome,
            "Facility completion can be submitted after the scheduled end time."
        );
    }
}

$subStmt = $pdo->prepare("
    SELECT return_id, status, condition_notes, reported_condition, admin_notes
    FROM return_submissions
    WHERE request_id = :request_id
    LIMIT 1
");
$subStmt->execute([":request_id" => $requestId]);
$existing = $subStmt->fetch(PDO::FETCH_ASSOC);

if (($existing["status"] ?? "") === "Pending") {
    redirectWithFlash(
        $returnHome,
        "Your return is already awaiting GSO review.",
        "error"
    );
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals((string)$_SESSION["csrf_token"], $csrf)) {
        $message = "Invalid request token.";
    } else {
        $notes = trim((string)($_POST["condition_notes"] ?? ""));
        // Per-line and per-unit outcomes. Nothing defaults to Good: an
        // unreported resource or unit is rejected rather than assumed intact.
        $detailResult = gsoValidateBorrowerReturnRows($pdo, $requestId, (array) ($_POST["lines"] ?? []));
        $detailRows = $detailResult["rows"];

        if ($detailResult["errors"] !== []) {
            $message = implode(" ", $detailResult["errors"]);
        }

        // The header condition is the worst outcome across the detail rows, so
        // existing displays and reports that read return_submissions keep
        // working without knowing about the per-line structure.
        $reportedCondition = gsoWorstReturnCondition(array_column($detailRows, "reported_condition"));
        $files = $_FILES["proof_photos"] ?? null;
        $allowedConditions = getConditionStatusOptions();

        $photos = [];
        if (is_array($files)) {
            $photos = normalizeUploadedFiles($files);
            $photos = array_values(array_filter($photos, function (array $f): bool {
                return (($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
            }));
        }

        if ($message === "" && count($photos) > 3) {
            $message = "Max 3 photos only.";
        }

        if ($message === "" && $facilityReturnLine !== null && $photos === []) {
            $message = "A proof photo is required for facility post-use submission.";
        }

        if ($message === "" && !in_array($reportedCondition, $allowedConditions, true)) {
            $message = "Invalid return condition selected.";
        }

        if ($message === "") {
            $oldPhotos = [];
            $newUploadedPhotos = [];

            try {
                $pdo->beginTransaction();

                // Recheck ownership and eligibility under the request lock.
                // A concurrent submission must not overwrite pending evidence.
                $lock = $pdo->prepare("SELECT status, return_date FROM resource_requests WHERE request_id = ? AND " . ($walkinReturn ? "requester_type='Walk-in' AND borrower_id IS NULL" : "requester_type='Registered' AND borrower_id = ?") . " FOR UPDATE");
                $lock->execute($walkinReturn?[$requestId]:[$requestId,$borrowerId]);
                if($walkinReturn)gsoWalkinActor($pdo,$submitterId);
                $locked = $lock->fetch(PDO::FETCH_ASSOC);
                if (!$locked || $locked["status"] !== "Released" || $locked["return_date"] !== null) {
                    throw new DomainException("This request is no longer eligible for return submission.");
                }
                $subStmt->execute([":request_id" => $requestId]);
                $existing = $subStmt->fetch(PDO::FETCH_ASSOC);
                if ($existing && $existing["status"] !== "Rejected") {
                    throw new DomainException("Your return is already submitted.");
                }
                $detailResult = gsoValidateBorrowerReturnRows($pdo, $requestId, (array) ($_POST["lines"] ?? []));
                if ($detailResult["errors"] !== []) {
                    throw new DomainException(implode(" ", $detailResult["errors"]));
                }
                $detailRows = $detailResult["rows"];

                // The single submission header is reused. Preserve the previous
                // rejection remarks in the existing append-only activity log.
                if ($existing) {
                    addActivityLog($pdo, $submitterId, "Rejected Return Resubmitted",
                        "Request #{$requestId}; previous rejection: " . mb_substr((string) ($existing["admin_notes"] ?? ""), 0, 170));
                }

                if ($existing) {
                    $returnId = (int)$existing["return_id"];

                    $pdo->prepare("
                        UPDATE return_submissions
                        SET
                            condition_notes = :notes,
                            reported_condition = :reported_condition,
                            status = 'Pending',
                            admin_id = NULL,
                            admin_notes = NULL,
                            inspection_condition = NULL,
                            inspection_remarks = NULL,
                            reviewed_at = NULL,
                            submitted_by = :submitted_by,
                            submitted_at = NOW()
                        WHERE return_id = :return_id
                        LIMIT 1
                    ")->execute([
                        ":notes" => ($notes === "" ? null : $notes),
                        ":submitted_by" => $walkinReturn ? $submitterId : null,
                        ":reported_condition" => $reportedCondition,
                        ":return_id" => $returnId,
                    ]);

                    $photoRowsStmt = $pdo->prepare("
                        SELECT filename
                        FROM return_submission_photos
                        WHERE return_id = :return_id
                    ");
                    $photoRowsStmt->execute([":return_id" => $returnId]);
                    $oldPhotos = $photoRowsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

                    $pdo->prepare("
                        DELETE FROM return_submission_photos
                        WHERE return_id = :return_id
                    ")->execute([":return_id" => $returnId]);
                } else {
                    $insStmt = $pdo->prepare("
                        INSERT INTO return_submissions
                            (request_id, borrower_id, submitted_by, condition_notes, reported_condition, status)
                        VALUES
                            (:request_id, :borrower_id, :submitted_by, :notes, :reported_condition, 'Pending')
                    ");
                    $insStmt->execute([
                        ":request_id" => $requestId,
                        ":borrower_id" => $borrowerId,
                        ":notes" => ($notes === "" ? null : $notes),
                        ":submitted_by" => $walkinReturn ? $submitterId : null,
                        ":reported_condition" => $reportedCondition,
                    ]);
                    $returnId = (int)$pdo->lastInsertId();
                }

                // Rewrite this submission's detail rows. Rows the GSO already
                // inspected and settled are preserved, so a resubmission can
                // never undo a completed stock movement.
                $pdo->prepare("DELETE FROM return_submission_items WHERE return_id = :return_id AND stock_applied = 0")
                    ->execute([":return_id" => $returnId]);

                $detailStmt = $pdo->prepare("
                    INSERT INTO return_submission_items
                        (return_id, request_item_id, unit_id, reported_condition, returned_quantity, lost_quantity)
                    VALUES
                        (:return_id, :request_item_id, :unit_id, :reported_condition, :returned_quantity, :lost_quantity)
                ");

                foreach ($detailRows as $detailRow) {
                    $detailStmt->execute([
                        ":return_id" => $returnId,
                        ":request_item_id" => (int) $detailRow["request_item_id"],
                        ":unit_id" => $detailRow["unit_id"] !== null ? (int) $detailRow["unit_id"] : null,
                        ":reported_condition" => (string) $detailRow["reported_condition"],
                        ":returned_quantity" => (int) $detailRow["returned_quantity"],
                        ":lost_quantity" => (int) $detailRow["lost_quantity"],
                    ]);
                }

                foreach ($photos as $file) {
                    $up = uploadReturnPhoto($file);
                    if (!($up["success"] ?? false)) {
                        throw new Exception((string)($up["message"] ?? "Photo upload failed."));
                    }

                    $newUploadedPhotos[] = (string)$up["filename"];

                    $pdo->prepare("
                        INSERT INTO return_submission_photos (return_id, filename, mime_type)
                        VALUES (:return_id, :filename, :mime_type)
                    ")->execute([
                        ":return_id" => $returnId,
                        ":filename" => (string)$up["filename"],
                        ":mime_type" => (string)$up["mime"],
                    ]);
                }

                $transition = $pdo->prepare("UPDATE resource_requests SET status = 'Return' WHERE request_id = ? AND " . ($walkinReturn ? "requester_type='Walk-in' AND borrower_id IS NULL" : "requester_type='Registered' AND borrower_id = ?") . " AND status = 'Released'  AND return_date IS NULL");
                $transition->execute($walkinReturn?[$requestId]:[$requestId,$borrowerId]);
                if ($transition->rowCount() !== 1) {
                    throw new DomainException("The request changed. Refresh and try again.");
                }

                $admins = $pdo->query("
                    SELECT user_id
                    FROM users
                    WHERE role = 'Admin'
                      AND account_status = 'Approved'
                      AND email_verified = 1
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $borrowNo = "BRW-" . str_pad((string)$requestId, 3, "0", STR_PAD_LEFT);

                foreach ($admins as $a) {
                    createNotification(
                        $pdo,
                        (int)$a["user_id"],
                        "RETURN_SUBMITTED",
                        "Return Submitted",
                        ($walkinReturn ? "GSO encoded external client return proof for {$borrowNo}" : "Borrower submitted return proof for {$borrowNo}") . ".",
                        "review_return.php?request_id=" . $requestId
                    );
                }

                addActivityLog(
                    $pdo,
                    $submitterId,
                    "Return Submitted",
                    ($walkinReturn ? "GSO encoded external client return proof for {$borrowNo}" : "Borrower submitted return proof for {$borrowNo}") . " with reported condition {$reportedCondition}."
                );

                $pdo->commit();

                foreach ($oldPhotos as $p) {
                    if (!empty($p["filename"])) {
                        deleteReturnPhotoFile((string)$p["filename"]);
                    }
                }

                $_SESSION["flash_message"] = "Return submitted successfully. Wait for admin confirmation.";
                $_SESSION["flash_type"] = "success";
                header("Location: ".$returnHome);
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                foreach ($newUploadedPhotos as $filename) {
                    deleteReturnPhotoFile($filename);
                }

                $message = $e->getMessage();
            }
        }
    }
}

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__,3).($walkinReturn?"/includes/admin_sidebar.php":"/includes/borrower_sidebar.php");
?>
<div class="main-content">
    <?php if(!$walkinReturn)require_once dirname(__DIR__,3)."/includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Return Submission</h2>
            <?php if($facilityReturnLine!==null):?><p><?php echo htmlspecialchars(formatDateTimeDisplay($requestRow["date_needed"])." | ".gsoRequestScheduleDisplay($requestRow));?></p><?php endif;?>
            <p>Submit notes and up to 3 photos. Admin will confirm and mark as returned.</p>
        </div>

        <?php if ($message !== ""): ?>
            <div class="flash-message flash-error">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($existing && (($existing["status"] ?? "") === "Rejected")): ?>
            <div class="flash-message flash-error">
                Rejected by admin:
                <?php echo htmlspecialchars((string)($existing["admin_notes"] ?? "No notes.")); ?>
                <br>
                Resubmit your proof below.
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>
                BRW-<?php echo str_pad((string)$requestId, 3, "0", STR_PAD_LEFT); ?>
                - <?php echo htmlspecialchars((string)$requestRow["resource_name"]); ?>
            </h3>

            <p>
                Type: <?php echo htmlspecialchars((string)$requestRow["resource_type"]); ?>
                <?php if (!empty($requestRow["category"])): ?>
                    | Category: <?php echo htmlspecialchars((string)$requestRow["category"]); ?>
                <?php endif; ?>
                <?php if (!empty($requestRow["location"])): ?>
                    | Location: <?php echo htmlspecialchars((string)$requestRow["location"]); ?>
                <?php endif; ?>
                | Qty: <?php echo htmlspecialchars((string)($requestRow["quantity"] ?? "N/A")); ?>
            </p>

            <form method="POST" enctype="multipart/form-data">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo htmlspecialchars((string)$_SESSION["csrf_token"]); ?>"
                >

                <p class="mini-muted">
                    Account for every resource issued on this request. Issued materials
                    are not listed because they are not returned.
                </p>

                <?php foreach ($returnableLines as $returnLine): ?>
                    <?php
                    $lineId = (int) $returnLine["request_item_id"];
                    $lineReleased = max(1, (int) $returnLine["released_quantity"]);
                    $openUnits = array_values(array_filter(
                        $returnLine["units"],
                        static fn(array $u): bool => $u["returned_at"] === null
                    ));
                    ?>
                    <section class="return-line-block">
                        <h4>
                            <?php echo htmlspecialchars((string) $returnLine["resource_name"]); ?>
                            <?php if ((string) $returnLine["line_kind"] !== "Facility"): ?>
                                <small>(<?php echo $lineReleased; ?> issued)</small>
                            <?php endif; ?>
                        </h4>
                        <?php if ((string) $returnLine["tracking_mode"] === "Serialized"): ?>
                            <?php foreach ($openUnits as $unit): ?>
                                <div class="return-unit-row">
                                    <span><?php echo htmlspecialchars((string) $unit["asset_tag"]); ?></span>
                                    <select name="lines[<?php echo $lineId; ?>][units][<?php echo (int) $unit["unit_id"]; ?>][condition]" required>
                                        <option value="">Select condition</option>
                                        <?php foreach (getConditionStatusOptions() as $conditionOption): ?>
                                            <option value="<?php echo htmlspecialchars($conditionOption); ?>"><?php echo htmlspecialchars($conditionOption); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        <?php elseif($returnLine['line_kind']!=='Facility'): ?>
                            <p>Account for all <?php echo $lineReleased;?> units. Good and repairable quantities are physically returned; Lost quantities are not.</p>
                            <div class="return-unit-row"><?php foreach(getConditionStatusOptions() as $outcome):?><label><?php echo htmlspecialchars($outcome);?><input type="number" name="lines[<?php echo $lineId;?>][outcomes][<?php echo htmlspecialchars($outcome);?>]" min="0" max="<?php echo $lineReleased;?>" value="0" required></label><?php endforeach;?></div>
                        <?php else: ?>
                            <label>Facility post-use condition<select name="lines[<?php echo $lineId;?>][condition]" required><option value="">Select condition</option><?php foreach(array_diff(getConditionStatusOptions(),['Lost']) as $outcome):?><option><?php echo htmlspecialchars($outcome);?></option><?php endforeach;?></select></label>
                        <?php endif; ?>
                    </section>
                <?php endforeach; ?>

                <label for="condition_notes"><strong>Condition / Notes</strong></label>
                <textarea
                    id="condition_notes"
                    name="condition_notes"
                    rows="4"
                    class="form-control-block"
                ><?php echo htmlspecialchars((string)($existing["condition_notes"] ?? "")); ?></textarea>

                <div class="mt-14">
                    <label><strong>Proof Photos (max 3)</strong></label>
                    <input type="file" name="proof_photos[]" accept="image/*" multiple <?php echo $facilityReturnLine !== null ? "required" : ""; ?>>
                    <small class="help-text-block">
                        JPG/PNG/GIF/WEBP, 5MB max each.
                    </small>
                </div>

                <label class="return-confirmation">
                    <input type="checkbox" required>
                    <span>I confirm that the condition details and proof photos are accurate.</span>
                </label>

                <div class="action-row mt-16">
                    <button type="submit" class="request-btn">Submit Return</button>
                    <a href="<?php echo htmlspecialchars($returnHome);?>" class="request-btn neutral-action">Back</a>
                </div>
            </form>
        </div>
    </main>

</div>
