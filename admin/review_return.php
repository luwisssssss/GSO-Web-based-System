<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/notification_helper.php";
require_once "../includes/return_upload_helper.php";
require_once "../includes/request_helper.php";
require_once "../includes/maintenance_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Review Return";
$activePage = "on_loan";
$cssFile = "../assets/css/admin.css";

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
        rr.resource_id,
        rr.quantity,
        rr.status AS request_status,
        rr.approved_at,
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
    INNER JOIN resources r ON rr.resource_id = r.resource_id
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
                rr.resource_id,
                rr.quantity,
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
            INNER JOIN resources r ON rr.resource_id = r.resource_id
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
        $resourceId = (int) $locked["resource_id"];
        $resourceType = (string) ($locked["resource_type"] ?? "");
        $returnedQty = max(0, (int) ($locked["quantity"] ?? 0));
        $borrowNo = "BRW-" . str_pad((string) $requestId, 3, "0", STR_PAD_LEFT);

        if ($action === "approve") {
            if (($locked["request_status"] ?? "") !== "Released") {
                throw new Exception("Request is not in Released state.");
            }

            if ($resourceType === "Item") {
                $resourceStmt = $pdo->prepare("
                    SELECT resource_id, total_stock, available_stock
                    FROM resources
                    WHERE resource_id = :resource_id
                    FOR UPDATE
                ");
                $resourceStmt->execute([
                    ":resource_id" => $resourceId
                ]);
                $resourceRow = $resourceStmt->fetch(PDO::FETCH_ASSOC);

                if (!$resourceRow) {
                    throw new Exception("Resource not found.");
                }

                $totalStock = (int) ($resourceRow["total_stock"] ?? 0);
                $availableStock = (int) ($resourceRow["available_stock"] ?? 0);
                $newAvailableStock = $availableStock;
                $newTotalStock = $totalStock;
                $nextConditionStatus = $inspectionCondition;
                $conditionNotes = ($inspectionRemarks === "" ? null : $inspectionRemarks);

                if ($inspectionCondition === "Good") {
                    $newAvailableStock = $availableStock + $returnedQty;

                    if ($newAvailableStock > $totalStock) {
                        $newAvailableStock = $totalStock;
                    }
                } elseif ($inspectionCondition === "Lost") {
                    $newTotalStock = max(0, $totalStock - $returnedQty);

                    if ($newAvailableStock > $newTotalStock) {
                        $newAvailableStock = $newTotalStock;
                    }

                    if ($newTotalStock > 0) {
                        $nextConditionStatus = "Good";
                        $conditionNotes = trim("Latest return inspection: {$returnedQty} item(s) marked Lost. " . $inspectionRemarks);
                    }
                } else {
                    if ($totalStock > $returnedQty) {
                        $nextConditionStatus = "Good";
                        $conditionNotes = trim("Latest return inspection: {$returnedQty} item(s) marked {$inspectionCondition}. " . $inspectionRemarks);
                    }
                }

                $updateResourceStmt = $pdo->prepare("
                    UPDATE resources
                    SET
                        available_stock = :available_stock,
                        total_stock = :total_stock,
                        condition_status = :condition_status,
                        condition_notes = :condition_notes
                    WHERE resource_id = :resource_id
                    LIMIT 1
                ");
                $updateResourceStmt->execute([
                    ":available_stock" => $newAvailableStock,
                    ":total_stock" => $newTotalStock,
                    ":condition_status" => $nextConditionStatus,
                    ":condition_notes" => ($conditionNotes === "" ? null : $conditionNotes),
                    ":resource_id" => $resourceId
                ]);
            } else {
                $updateResourceStmt = $pdo->prepare("
                    UPDATE resources
                    SET
                        condition_status = :condition_status,
                        condition_notes = :condition_notes
                    WHERE resource_id = :resource_id
                    LIMIT 1
                ");
                $updateResourceStmt->execute([
                    ":condition_status" => $inspectionCondition,
                    ":condition_notes" => ($inspectionRemarks === "" ? null : $inspectionRemarks),
                    ":resource_id" => $resourceId
                ]);
            }

            refreshResourceOperationalStatus($pdo, $resourceId);

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

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

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

            <div style="margin-top: 12px;">
                <strong>Borrower Notes:</strong><br>
                <?php echo nl2br(htmlspecialchars((string) ($info["condition_notes"] ?? "N/A"))); ?>
            </div>

            <?php if (count($photos) > 0): ?>
                <div style="margin-top: 16px;">
                    <strong>Proof Photos:</strong>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px;">
                        <?php foreach ($photos as $photo): ?>
                            <?php $src = returnPhotoPublicPath((string) $photo["filename"], "../"); ?>
                            <a href="<?php echo htmlspecialchars($src); ?>" target="_blank" rel="noopener">
                                <img
                                    src="<?php echo htmlspecialchars($src); ?>"
                                    alt="proof"
                                    style="width: 160px; height: 120px; object-fit: cover; border-radius: 10px; border: 1px solid #cbd5e1;"
                                >
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <div style="margin-top: 16px;">
                    <strong>Proof Photos:</strong> <em>No photos uploaded.</em>
                </div>
            <?php endif; ?>

            <form method="POST" style="margin-top: 18px; display: grid; gap: 10px;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">

                <label for="inspection_condition"><strong>Inspection Condition</strong></label>
                <select
                    id="inspection_condition"
                    name="inspection_condition"
                    style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px;"
                    required
                >
                    <?php
                    $selectedInspectionCondition = (string) ($info["inspection_condition"] ?? ($info["reported_condition"] ?? "Good"));
                    foreach (getConditionStatusOptions() as $conditionOption):
                    ?>
                        <option
                            value="<?php echo htmlspecialchars($conditionOption); ?>"
                            <?php echo $selectedInspectionCondition === $conditionOption ? "selected" : ""; ?>
                        >
                            <?php echo htmlspecialchars($conditionOption); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="inspection_remarks"><strong>Inspection Remarks</strong></label>
                <textarea
                    id="inspection_remarks"
                    name="inspection_remarks"
                    rows="3"
                    style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 10px;"
                ><?php echo htmlspecialchars((string) ($info["inspection_remarks"] ?? "")); ?></textarea>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
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
