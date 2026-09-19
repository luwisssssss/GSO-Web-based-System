<?php
define("GSO_REQUIRED_PERMISSION", "return.inspect");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/return_upload_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/maintenance_helper.php";
require_once dirname(__DIR__, 3) . "/includes/return_detail_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Review Return";
$activePage = "on_loan";
$cssFile = "../public/assets/css/pages/admin.css";

$adminId = (int) ($_SESSION["user_id"] ?? 0);
$requestId = isset($_GET["request_id"]) ? (int) $_GET["request_id"] : 0;

if ($adminId <= 0 || $requestId <= 0) {
    redirectWithFlash("on_loan.php", "Invalid return review request.");
}

$infoStmt = $pdo->prepare("
    SELECT
        rs.return_id,
        rs.status AS return_status,
        rs.condition_notes,
        rs.reported_condition,
        rs.admin_notes,
        rs.inspection_condition,
        rs.inspection_remarks,
        rs.submitted_at,
        rs.reviewed_at,

        rr.request_id,
        rr.borrower_id,
        r.quantity,
        rr.status AS request_status,
        rr.released_at,
        rr.due_date,
        rr.return_date,

        b.full_name AS borrower_name,
        b.username AS borrower_username,
        b.email AS borrower_email,

        r.resource_name,
        r.resource_type,
        r.category,
        r.location,
        r.total_stock,
        r.available_stock,
        r.status AS resource_status,
        r.condition_status AS resource_condition_status,
        r.condition_notes AS resource_condition_notes
    FROM return_submissions rs
    INNER JOIN resource_requests rr ON rs.request_id = rr.request_id
    INNER JOIN users b ON rr.borrower_id = b.user_id
    INNER JOIN " . gsoRequestResourceRollupSql() . " r ON r.request_id = rr.request_id
    WHERE rr.request_id = :request_id
    LIMIT 1
");
$infoStmt->execute([
    ":request_id" => $requestId
]);
$info = $infoStmt->fetch(PDO::FETCH_ASSOC);

if (!$info) {
    redirectWithFlash("on_loan.php", "No return submission found for this request.");
}

$photosStmt = $pdo->prepare("
    SELECT photo_id, filename, mime_type
    FROM return_submission_photos
    WHERE return_id = :return_id
    ORDER BY photo_id ASC
");
$photosStmt->execute([
    ":return_id" => (int) $info["return_id"]
]);
$photos = $photosStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrf = (string) ($_POST["csrf_token"] ?? "");
    $action = trim((string) ($_POST["action"] ?? ""));
    $inspectionCondition = trim((string) ($_POST["inspection_condition"] ?? "Good"));
    $inspectionRemarks = trim((string) ($_POST["inspection_remarks"] ?? ""));

    if (!hash_equals((string) $_SESSION["csrf_token"], $csrf)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        header("Location: review_return.php?request_id=" . $requestId);
        exit();
    }

    if (!in_array($action, ["approve", "reject"], true)) {
        $_SESSION["flash_message"] = "Invalid action.";
        $_SESSION["flash_type"] = "error";
        header("Location: review_return.php?request_id=" . $requestId);
        exit();
    }

    if (!in_array($inspectionCondition, getConditionStatusOptions(), true)) {
        $_SESSION["flash_message"] = "Invalid inspection condition.";
        $_SESSION["flash_type"] = "error";
        header("Location: review_return.php?request_id=" . $requestId);
        exit();
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("
            SELECT
                rr.request_id,
                rr.borrower_id,
                rr.status AS request_status,
                rr.return_date,
                rs.return_id,
                rs.status AS return_status,
                r.resource_type,
                r.total_stock,
                r.available_stock,
                r.status AS resource_status,
                r.condition_status
            FROM resource_requests rr
            INNER JOIN return_submissions rs ON rs.request_id = rr.request_id
            INNER JOIN " . gsoRequestResourceRollupSql() . " r ON r.request_id = rr.request_id
            WHERE rr.request_id = :request_id
            FOR UPDATE
        ");
        $lockStmt->execute([
            ":request_id" => $requestId
        ]);
        $locked = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$locked) {
            throw new Exception("Record not found.");
        }

        if (($locked["return_status"] ?? "") !== "Pending") {
            throw new Exception("Only pending submissions can be reviewed.");
        }

        $borrowerId = (int) $locked["borrower_id"];
        $borrowNo = "BRW-" . str_pad((string) $requestId, 3, "0", STR_PAD_LEFT);

        if ($action === "approve") {
            if (($locked["request_status"] ?? "") !== "Released") {
                throw new Exception("Request is not in Released state.");
            }

            // Phase 3.5B: each returned resource line, and each physical unit,
            // carries its own inspection outcome. A damaged laptop parks only
            // that laptop; a lost chair reduces only chair stock. The helper
            // flips stock_applied inside this transaction, so a second
            // inspection can never restore the same stock twice.
            $inspectionResult = gsoApplyReturnInspection(
                $pdo,
                (int) $locked["return_id"],
                (array) ($_POST["inspection"] ?? []),
                $adminId
            );

            if (!($inspectionResult["ok"] ?? false)) {
                throw new Exception((string) $inspectionResult["message"]);
            }

            // The header keeps the worst outcome so existing displays, reports
            // and the lifecycle helper continue to work unchanged.
            $detailConditions = $pdo->prepare("
                SELECT inspection_condition FROM return_submission_items
                WHERE return_id = :return_id AND inspection_condition IS NOT NULL
            ");
            $detailConditions->execute([":return_id" => (int) $locked["return_id"]]);
            $inspectionCondition = gsoWorstReturnCondition(
                $detailConditions->fetchAll(PDO::FETCH_COLUMN) ?: ["Good"]
            );

            $pdo->prepare("
                UPDATE resource_requests
                SET
                    status = 'Returned',
                    return_date = NOW()
                WHERE request_id = :request_id
                  AND status = 'Released'
                LIMIT 1
            ")->execute([
                ":request_id" => $requestId
            ]);

            $pdo->prepare("
                UPDATE return_submissions
                SET
                    status = 'Approved',
                    admin_id = :admin_id,
                    admin_notes = :admin_notes,
                    inspection_condition = :inspection_condition,
                    inspection_remarks = :inspection_remarks,
                    reviewed_at = NOW()
                WHERE return_id = :return_id
                LIMIT 1
            ")->execute([
                ":admin_id" => $adminId,
                ":admin_notes" => ($inspectionRemarks === "" ? null : $inspectionRemarks),
                ":inspection_condition" => $inspectionCondition,
                ":inspection_remarks" => ($inspectionRemarks === "" ? null : $inspectionRemarks),
                ":return_id" => (int) $locked["return_id"]
            ]);

            createNotification(
                $pdo,
                $borrowerId,
                "RETURN_APPROVED",
                "Return Completed",
                "Your return for {$borrowNo} was approved with condition: {$inspectionCondition}.",
                "history.php"
            );

            addActivityLog(
                $pdo,
                $adminId,
                "Return Approved",
                "Approved return proof for {$borrowNo} with inspection condition {$inspectionCondition}."
            );

            $_SESSION["flash_message"] = "Return approved and inventory status updated.";
            $_SESSION["flash_type"] = "success";
        } else {
            $pdo->prepare("
                UPDATE return_submissions
                SET
                    status = 'Rejected',
                    admin_id = :admin_id,
                    admin_notes = :admin_notes,
                    inspection_condition = NULL,
                    inspection_remarks = NULL,
                    reviewed_at = NOW()
                WHERE return_id = :return_id
                LIMIT 1
            ")->execute([
                ":admin_id" => $adminId,
                ":admin_notes" => ($inspectionRemarks === "" ? "Please resubmit with clearer proof." : $inspectionRemarks),
                ":return_id" => (int) $locked["return_id"]
            ]);

            createNotification(
                $pdo,
                $borrowerId,
                "RETURN_REJECTED",
                "Return Rejected",
                "Your return for {$borrowNo} was rejected. Please resubmit your proof.",
                "return_submit.php?request_id=" . $requestId
            );

            addActivityLog(
                $pdo,
                $adminId,
                "Return Rejected",
                "Rejected return proof for {$borrowNo}."
            );

            $_SESSION["flash_message"] = "Return rejected. Borrower notified to resubmit.";
            $_SESSION["flash_type"] = "success";
        }

        $pdo->commit();

        header("Location: on_loan.php");
        exit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["flash_message"] = $e->getMessage();
        $_SESSION["flash_type"] = "error";
        header("Location: review_return.php?request_id=" . $requestId);
        exit();
    }
}

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Review Return</h2>
            <p>Inspect the submitted proof, confirm the actual returned condition, and update the inventory accordingly.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>
                BRW-<?php echo str_pad((string) $requestId, 3, "0", STR_PAD_LEFT); ?>
                - <?php echo htmlspecialchars((string) $info["resource_name"]); ?>
            </h3>

            <p>
                Borrower:
                <strong><?php echo htmlspecialchars((string) $info["borrower_name"]); ?></strong>
                (<?php echo htmlspecialchars((string) $info["borrower_username"]); ?>)
                <br>
                Submitted At: <?php echo htmlspecialchars(formatDateTimeDisplay($info["submitted_at"] ?? "")); ?>
                <br>
                Due Date: <?php echo htmlspecialchars(formatDateTimeDisplay($info["due_date"] ?? "")); ?>
                <br>
                Return Status:
                <strong><?php echo htmlspecialchars((string) $info["return_status"]); ?></strong>
                <br>
                Reported Condition:
                <span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) ($info["reported_condition"] ?? "Good"))); ?>">
                    <?php echo htmlspecialchars((string) ($info["reported_condition"] ?? "Good")); ?>
                </span>
            </p>

            <div class="mt-12">
                <strong>Borrower Notes:</strong><br>
                <?php echo nl2br(htmlspecialchars((string) ($info["condition_notes"] ?? "N/A"))); ?>
            </div>

            <?php if (count($photos) > 0): ?>
                <div class="mt-16">
                    <strong>Proof Photos:</strong>
                    <div class="action-row action-row--spaced">
                        <?php foreach ($photos as $photo): ?>
                            <?php $src = "return_photo.php?photo_id=" . (int) $photo["photo_id"]; ?>
                            <a href="<?php echo htmlspecialchars($src); ?>" target="_blank" rel="noopener">
                                <img
                                    src="<?php echo htmlspecialchars($src); ?>"
                                    alt="proof"
                                    class="review-proof-thumb"
                                >
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="mt-16">
                    <strong>Proof Photos:</strong> <em>No photos uploaded.</em>
                </div>
            <?php endif; ?>

            <form method="POST" class="review-return-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">

                <h4 class="section-title">Per-Resource Inspection</h4>
                <p class="mini-muted">
                    Record an outcome for every returned resource. Each line is settled
                    independently, so a damaged item never affects the others.
                </p>

                <?php $returnDetails = gsoReturnDetailRows($pdo, (int) $info["return_id"]); ?>
                <?php foreach ($returnDetails as $detail): ?>
                    <div class="return-unit-row">
                        <span>
                            <?php echo htmlspecialchars((string) $detail["resource_name"]); ?>
                            <?php if (!empty($detail["asset_tag"])): ?>
                                &mdash; <?php echo htmlspecialchars((string) $detail["asset_tag"]); ?>
                            <?php elseif ((string) $detail["line_kind"] !== "Facility"): ?>
                                &mdash; <?php echo (int) $detail["returned_quantity"]; ?> returned,
                                <?php echo (int) $detail["lost_quantity"]; ?> lost
                            <?php endif; ?>
                            <?php if ((int) $detail["stock_applied"] === 1): ?>
                                <em>(already settled)</em>
                            <?php endif; ?>
                        </span>
                        <select
                            name="inspection[<?php echo (int) $detail["return_item_id"]; ?>][condition]"
                            <?php echo (int) $detail["stock_applied"] === 1 ? "disabled" : "required"; ?>
                        >
                            <?php
                            $detailSelected = (string) ($detail["inspection_condition"]
                                ?? ($detail["lost_quantity"] > 0 ? "Lost" : ($detail["reported_condition"] ?? "Good")));
                            foreach (getConditionStatusOptions() as $conditionOption):
                            ?>
                                <option
                                    value="<?php echo htmlspecialchars($conditionOption); ?>"
                                    <?php echo $detailSelected === $conditionOption ? "selected" : ""; ?>
                                >
                                    <?php echo htmlspecialchars($conditionOption); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input
                            type="text"
                            name="inspection[<?php echo (int) $detail["return_item_id"]; ?>][remarks]"
                            placeholder="Remarks (optional)"
                            maxlength="500"
                            value="<?php echo htmlspecialchars((string) ($detail["inspection_remarks"] ?? "")); ?>"
                        >
                    </div>
                <?php endforeach; ?>

                <label for="inspection_remarks"><strong>Inspection Remarks</strong></label>
                <textarea
                    id="inspection_remarks"
                    name="inspection_remarks"
                    rows="3"
                    class="form-control-block"
                ><?php echo htmlspecialchars((string) ($info["inspection_remarks"] ?? "")); ?></textarea>

                <div class="action-row">
                    <button
                        class="admin-btn success-btn"
                        type="submit"
                        name="action"
                        value="approve"
                        onclick="return confirm('Approve this return and update inventory condition?');"
                    >
                        Approve Return
                    </button>

                    <button
                        class="admin-btn danger-btn"
                        type="submit"
                        name="action"
                        value="reject"
                        onclick="return confirm('Reject this return proof?');"
                    >
                        Reject (Resubmit)
                    </button>

                    <a class="admin-btn info-btn" href="on_loan.php">Back</a>
                </div>
            </form>
        </div>
    </main>
</div>
