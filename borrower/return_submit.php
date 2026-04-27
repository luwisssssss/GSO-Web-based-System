<?php
/**
 * File: /borrower/return_submit.php
 */

require_once "../includes/borrower_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/notification_helper.php";
require_once "../includes/return_upload_helper.php";
require_once "../includes/request_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Return Submission";
$activePage = "borrowed";

$borrowerId = (int)($_SESSION["user_id"] ?? 0);
$requestId = isset($_GET["request_id"]) ? (int)$_GET["request_id"] : 0;

if ($borrowerId <= 0 || $requestId <= 0) {
    redirectWithFlash("my_borrowed.php", "Invalid return request.");
}

$reqStmt = $pdo->prepare("
    SELECT
        rr.request_id,
        rr.resource_id,
        rr.quantity,
        rr.status,
        rr.due_date,
        rr.return_date,
        r.resource_name,
        r.resource_type,
        r.category,
        r.location
    FROM resource_requests rr
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    WHERE rr.request_id = :request_id
      AND rr.borrower_id = :borrower_id
      AND rr.status = 'Released'
      AND rr.return_date IS NULL
    LIMIT 1
");
$reqStmt->execute([
    ":request_id" => $requestId,
    ":borrower_id" => $borrowerId,
]);
$requestRow = $reqStmt->fetch(PDO::FETCH_ASSOC);

if (!$requestRow) {
    redirectWithFlash("my_borrowed.php", "Borrowed request not found or not eligible for return.");
}

$subStmt = $pdo->prepare("
    SELECT return_id, status, condition_notes, reported_condition, admin_notes
    FROM return_submissions
    WHERE request_id = :request_id
    LIMIT 1
");
$subStmt->execute([":request_id" => $requestId]);
$existing = $subStmt->fetch(PDO::FETCH_ASSOC);

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = (string)($_POST["csrf_token"] ?? "");
    if (!hash_equals((string)$_SESSION["csrf_token"], $csrf)) {
        $message = "Invalid request token.";
    } else {
        $notes = trim((string)($_POST["condition_notes"] ?? ""));
        $reportedCondition = trim((string) ($_POST["reported_condition"] ?? "Good"));
        $files = $_FILES["proof_photos"] ?? null;
        $allowedConditions = getConditionStatusOptions();

        $photos = [];
        if (is_array($files)) {
            $photos = normalizeUploadedFiles($files);
            $photos = array_values(array_filter($photos, function (array $f): bool {
                return (($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);
            }));
        }

        if (count($photos) > 3) {
            $message = "Max 3 photos only.";
        }

        if ($message === "" && !in_array($reportedCondition, $allowedConditions, true)) {
            $message = "Invalid return condition selected.";
        }

        if ($message === "") {
            try {
                $pdo->beginTransaction();

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
                            submitted_at = NOW()
                        WHERE return_id = :return_id
                        LIMIT 1
                    ")->execute([
                        ":notes" => ($notes === "" ? null : $notes),
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

                    foreach ($oldPhotos as $p) {
                        if (!empty($p["filename"])) {
                            deleteReturnPhotoFile((string)$p["filename"]);
                        }
                    }

                    $pdo->prepare("
                        DELETE FROM return_submission_photos
                        WHERE return_id = :return_id
                    ")->execute([":return_id" => $returnId]);
                } else {
                    $insStmt = $pdo->prepare("
                        INSERT INTO return_submissions (request_id, borrower_id, condition_notes, reported_condition)
                        VALUES (:request_id, :borrower_id, :notes, :reported_condition)
                    ");
                    $insStmt->execute([
                        ":request_id" => $requestId,
                        ":borrower_id" => $borrowerId,
                        ":notes" => ($notes === "" ? null : $notes),
                        ":reported_condition" => $reportedCondition,
                    ]);
                    $returnId = (int)$pdo->lastInsertId();
                }

                foreach ($photos as $file) {
                    $up = uploadReturnPhoto($file);
                    if (!($up["success"] ?? false)) {
                        throw new Exception((string)($up["message"] ?? "Photo upload failed."));
                    }

                    $pdo->prepare("
                        INSERT INTO return_submission_photos (return_id, filename, mime_type)
                        VALUES (:return_id, :filename, :mime_type)
                    ")->execute([
                        ":return_id" => $returnId,
                        ":filename" => (string)$up["filename"],
                        ":mime_type" => (string)$up["mime"],
                    ]);
                }

                $admins = $pdo->query("
                    SELECT user_id
                    FROM users
                    WHERE role = 'Admin'
                      AND account_status <> 'Disabled'
                ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

                $borrowNo = "BRW-" . str_pad((string)$requestId, 3, "0", STR_PAD_LEFT);

                foreach ($admins as $a) {
                    createNotification(
                        $pdo,
                        (int)$a["user_id"],
                        "RETURN_SUBMITTED",
                        "Return Submitted",
                        "Borrower submitted return proof for {$borrowNo}.",
                        "../admin/review_return.php?request_id=" . $requestId
                    );
                }

                addActivityLog(
                    $pdo,
                    $borrowerId,
                    "Return Submitted",
                    "Borrower submitted return proof for {$borrowNo} with reported condition {$reportedCondition}."
                );

                $pdo->commit();

                $_SESSION["flash_message"] = "Return submitted successfully. Wait for admin confirmation.";
                $_SESSION["flash_type"] = "success";
                header("Location: my_borrowed.php");
                exit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();
            }
        }
    }
}

require_once "../includes/header.php";
require_once "../includes/borrower_sidebar.php";
?>
<div class="main-content">
    <?php require_once "../includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Return Submission</h2>
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

                <label for="reported_condition"><strong>Reported Condition</strong></label>
                <select
                    id="reported_condition"
                    name="reported_condition"
                    style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:10px; margin-bottom:14px;"
                >
                    <?php
                    $selectedReportedCondition = (string) ($existing["reported_condition"] ?? "Good");
                    foreach (getConditionStatusOptions() as $conditionOption):
                    ?>
                        <option
                            value="<?php echo htmlspecialchars($conditionOption); ?>"
                            <?php echo $selectedReportedCondition === $conditionOption ? "selected" : ""; ?>
                        >
                            <?php echo htmlspecialchars($conditionOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="condition_notes"><strong>Condition / Notes</strong></label>
                <textarea
                    id="condition_notes"
                    name="condition_notes"
                    rows="4"
                    style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:10px;"
                ><?php echo htmlspecialchars((string)($existing["condition_notes"] ?? "")); ?></textarea>

                <div style="margin-top:14px;">
                    <label><strong>Proof Photos (max 3)</strong></label>
                    <input type="file" name="proof_photos[]" accept="image/*" multiple>
                    <small style="display:block; color:#64748b; margin-top:6px;">
                        JPG/PNG/GIF/WEBP, 5MB max each.
                    </small>
                </div>

                <label class="return-confirmation">
                    <input type="checkbox" required>
                    <span>I confirm that the condition details and proof photos are accurate.</span>
                </label>

                <div style="margin-top:16px; display:flex; gap:10px; flex-wrap:wrap;">
                    <button type="submit" class="request-btn">Submit Return</button>
                    <a href="my_borrowed.php" class="request-btn" style="background:#64748b;">Back</a>
                </div>
            </form>
        </div>
    </main>
    
</div>
