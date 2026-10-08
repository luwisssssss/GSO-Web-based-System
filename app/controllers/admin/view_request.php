<?php
define("GSO_REQUIRED_PERMISSION", "request.view_queue");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/adviser_endorsement_helper.php";
require_once dirname(__DIR__, 3) . "/includes/director_approval_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_line_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_attachment_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";

$pageTitle = "View Request";
$activePage = "requests";
$cssFile = "../public/assets/css/pages/admin.css";

if (!isset($_GET["request_id"]) || empty($_GET["request_id"])) {
    redirectWithFlash("requests.php", "Request ID is required.");
}

$requestId = (int) $_GET["request_id"];

if (empty($_SESSION["csrf_token"])) $_SESSION["csrf_token"]=bin2hex(random_bytes(32));
if (($_SERVER["REQUEST_METHOD"]??"GET")==="POST") {
    try {
        ensureAuthenticatedPermission("request.documents.verify");
        if (!hash_equals((string)$_SESSION["csrf_token"],(string)($_POST["csrf_token"]??""))) throw new DomainException("Invalid request token.");
        $action=(string)($_POST["action"]??"");
        $next=$action==="verify_letter"?"Verified":($action==="reject_letter"?"Rejected":"");
        $remarks=trim((string)($_POST["verification_remarks"]??""));
        if ($next==="" || mb_strlen($remarks)>1000 || ($next==="Rejected" && $remarks==="")) throw new DomainException("Select a document verification action and provide remarks when rejecting (up to 1,000 characters).");
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT status,borrower_id FROM resource_requests WHERE request_id=? FOR UPDATE");$lock->execute([$requestId]);$locked=$lock->fetch(PDO::FETCH_ASSOC);
        $letter=gsoRequestLetters($pdo,$requestId)[0]??null;
        if (!$locked || !in_array($locked["status"],["Pending","Under Review"],true) || !$letter || (int)$letter["attachment_id"]!==(int)($_POST["attachment_id"]??0) || $letter["verification_status"]!=="Pending") throw new DomainException("Only the current pending letter on an unapproved request can be verified. Refresh this page.");
        if (!is_file(gsoRequestLetterPath($letter["stored_file_name"]))) throw new DomainException("The request-letter file is unavailable.");
        $update=$pdo->prepare("UPDATE request_attachments SET verification_status=?,verified_by=?,verified_at=NOW(),verification_remarks=? WHERE attachment_id=? AND verification_status='Pending'");
        $update->execute([$next,(int)$_SESSION["user_id"],$remarks!==""?$remarks:null,$letter["attachment_id"]]);
        addActivityLog($pdo,(int)$_SESSION["user_id"],"Request Letter ".$next,"Document verification for request #".$requestId);
        createNotification($pdo,(int)$locked["borrower_id"],"request_letter_".strtolower($next),"Request Letter ".$next,$next==="Rejected"?"Your facility request letter was rejected. Upload a corrected letter: ".mb_substr($remarks,0,140):"Your manually signed facility request letter was verified for GSO processing. Request approval remains a separate action.","request_letter.php?request_id=".$requestId);
        $pdo->commit();redirectWithFlash("view_request.php?request_id=".$requestId,"Request letter ".strtolower($next).".","success");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        redirectWithFlash("view_request.php?request_id=".$requestId,$e instanceof DomainException?$e->getMessage():"Document verification could not be saved.");
    }
}

$stmt = $pdo->prepare("
    SELECT
        rr.request_id,
        rr.borrower_id,
        r.quantity,
        rr.contact_number,
        rr.date_needed,
        rr.start_time,
        (SELECT MAX(sp.facility_slot) FROM request_items sp WHERE sp.request_id=rr.request_id) AS facility_slot,
        rr.end_time,
        rr.request_date,
        rr.status,
        rr.approved_by,
        rr.reviewed_by,
        rr.released_by,
        rr.adviser_state,
        rr.adviser_user_id,
        rr.adviser_required_at,
        rr.director_state,
        rr.director_required_by,
        rr.director_required_at,
        rr.approved_at,
        rr.reviewed_at,
        rr.released_at,
        rr.due_date,
        rr.return_date,
        rr.notes,
        rs.inspection_condition,
        rs.inspection_remarks,

        borrower.full_name AS borrower_name,
        borrower.email AS borrower_email,
        borrower.username AS borrower_username,
        borrower.department AS borrower_department,
        borrower.university_id AS borrower_university_id,
        borrower.account_type AS borrower_account_type,

        reviewer.full_name AS reviewed_by_name,
        approver.full_name AS approved_by_name,
        releaser.full_name AS released_by_name,
        adviser.full_name AS adviser_name,
        adviser.department AS adviser_department,
        requirer.full_name AS adviser_required_by_name,
        director_requirer.full_name AS director_required_by_name,

        r.resource_name,
        r.resource_type,
        r.category,
        r.description AS resource_description,
        r.location,
        r.total_stock,
        r.available_stock,
        r.capacity,
        r.status AS resource_status,
        r.is_archived
    FROM resource_requests rr
    INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
    INNER JOIN " . gsoRequestResourceRollupSql() . " r ON r.request_id = rr.request_id
    LEFT JOIN users reviewer ON rr.reviewed_by = reviewer.user_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN users releaser ON rr.released_by = releaser.user_id
    LEFT JOIN users adviser ON rr.adviser_user_id = adviser.user_id
    LEFT JOIN users requirer ON rr.adviser_required_by = requirer.user_id
    LEFT JOIN users director_requirer ON rr.director_required_by = director_requirer.user_id
    LEFT JOIN return_submissions rs ON rr.request_id = rs.request_id
    WHERE rr.request_id = :request_id
    LIMIT 1
");
$stmt->execute([
    ":request_id" => $requestId
]);

$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    redirectWithFlash("requests.php", "Request not found.");
}

$isPastFacilityUnavailable = isPastFacilityRequest($request);
$displayStatus = $isPastFacilityUnavailable ? "Unavailable" : getRequestLifecycleStatus($request);
$statusClass = getStatusCssClass($displayStatus);
$resourceStatusClass = strtolower($request["resource_status"] ?? "");
$archiveState = ((int)($request["is_archived"] ?? 0) === 1) ? "Archived" : "Active";
$archiveClass = ((int)($request["is_archived"] ?? 0) === 1) ? "archived" : "available";

$timeDisplay = gsoRequestScheduleDisplay($request);
$borrowerCallHref = buildPhoneCallHref($request["contact_number"] ?? null);

$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/admin-view-request.css"]);
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>



<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <?php if(!empty($_SESSION["flash_message"])): ?><div class="note-box" role="status"><?php echo htmlspecialchars((string)$_SESSION["flash_message"]);unset($_SESSION["flash_message"],$_SESSION["flash_type"]);?></div><?php endif; ?>
        <div class="card">
            <h2>View Request</h2>
            <p>Full details for request <strong>REQ-<?php echo str_pad($request["request_id"], 3, "0", STR_PAD_LEFT); ?></strong>.</p>

            <div class="status-inline-group">
                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                    <?php echo htmlspecialchars($displayStatus); ?>
                </span>

                <span class="status-badge <?php echo htmlspecialchars($resourceStatusClass); ?>">
                    Resource: <?php echo htmlspecialchars($request["resource_status"]); ?>
                </span>

                <span class="status-badge <?php echo htmlspecialchars($archiveClass); ?>">
                    <?php echo htmlspecialchars($archiveState); ?>
                </span>
            </div>

            <?php if ($isPastFacilityUnavailable): ?>
                <p class="mini-muted mt-12">
                    This facility request is shown as unavailable because its scheduled date and time are already in the past.
                </p>
            <?php endif; ?>

            <div class="page-action-bar">
                <a href="requests.php" class="admin-btn info-btn">Back to Requests</a>
                <?php if ($borrowerCallHref !== ""): ?>
                    <a href="<?php echo htmlspecialchars($borrowerCallHref); ?>" class="admin-btn success-btn">
                        Call Borrower
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="request-view-grid">
            <div class="card detail-block">
                <h3>Request Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Request No.</span>
                        <strong>REQ-<?php echo str_pad($request["request_id"], 3, "0", STR_PAD_LEFT); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Quantity</span>
                        <strong><?php echo htmlspecialchars($request["quantity"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Date Requested</span>
                        <strong><?php echo htmlspecialchars(formatDateTimeDisplay($request["request_date"] ?? "")); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Date Needed</span>
                        <strong><?php echo htmlspecialchars(formatDateTimeDisplay($request["date_needed"] ?? "")); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Time</span>
                        <strong><?php echo htmlspecialchars($timeDisplay); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Due Date</span>
                        <strong><?php echo !empty($request["due_date"]) ? htmlspecialchars(formatDateTimeDisplay($request["due_date"])) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Return Date</span>
                        <strong><?php echo !empty($request["return_date"]) ? htmlspecialchars(formatDateTimeDisplay($request["return_date"])) : "N/A"; ?></strong>
                    </div>
                </div>
            </div>

            <div class="card detail-block">
                <h3>Borrower Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Full Name</span>
                        <strong><?php echo htmlspecialchars($request["borrower_name"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Username</span>
                        <strong><?php echo htmlspecialchars($request["borrower_username"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Email</span>
                        <strong><?php echo htmlspecialchars($request["borrower_email"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Contact Number</span>
                        <strong>
                            <?php if ($borrowerCallHref !== ""): ?>
                                <a href="<?php echo htmlspecialchars($borrowerCallHref); ?>" class="detail-call-link">
                                    <?php echo htmlspecialchars((string) $request["contact_number"]); ?>
                                </a>
                            <?php else: ?>
                                N/A
                            <?php endif; ?>
                        </strong>
                    </div>

                    <div class="detail-row">
                        <span>Department</span>
                        <strong><?php echo htmlspecialchars($request["borrower_department"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>University ID</span>
                        <strong><?php echo htmlspecialchars($request["borrower_university_id"] ?? "N/A"); ?></strong>
                    </div>
                </div>
            </div>

            <div class="card detail-block">
                <h3>Resource Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Resource Name</span>
                        <strong><?php echo htmlspecialchars($request["resource_name"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Type</span>
                        <strong><?php echo htmlspecialchars($request["resource_type"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Category</span>
                        <strong><?php echo htmlspecialchars($request["category"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Location</span>
                        <strong><?php echo htmlspecialchars($request["location"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Total Stock</span>
                        <strong><?php echo htmlspecialchars($request["total_stock"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Available Stock</span>
                        <strong><?php echo htmlspecialchars($request["available_stock"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Capacity</span>
                        <strong><?php echo htmlspecialchars($request["capacity"] ?? "N/A"); ?></strong>
                    </div>
                </div>
            </div>

            <div class="card detail-block">
                <h3>Review Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Request Status</span>
                        <strong><?php echo htmlspecialchars($displayStatus); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Reviewed By</span>
                        <strong><?php echo !empty($request["reviewed_by_name"]) ? htmlspecialchars($request["reviewed_by_name"]) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Reviewed At</span>
                        <strong><?php echo !empty($request["reviewed_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["reviewed_at"])) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Approved By</span>
                        <strong><?php echo !empty($request["approved_by_name"]) ? htmlspecialchars($request["approved_by_name"]) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Approved At</span>
                        <strong><?php echo !empty($request["approved_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["approved_at"])) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Released By</span>
                        <strong><?php echo !empty($request["released_by_name"]) ? htmlspecialchars($request["released_by_name"]) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Released At</span>
                        <strong><?php echo !empty($request["released_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["released_at"])) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Resource Status</span>
                        <strong><?php echo htmlspecialchars($request["resource_status"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Archive State</span>
                        <strong><?php echo htmlspecialchars($archiveState); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?php
        $adviserState = (string) ($request["adviser_state"] ?? "Not Required");
        $adviserHistory = gsoAdviserHistory($pdo, (int) $request["request_id"]);
        ?>
        <?php if ($adviserState !== "Not Required" || $adviserHistory): ?>
            <div class="card detail-block">
                <h3 class="section-title">Historical Adviser Workflow</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Endorsement State</span>
                        <strong><span class="status-badge <?php echo htmlspecialchars(getStatusCssClass($adviserState)); ?>"><?php echo htmlspecialchars($adviserState); ?></span></strong>
                    </div>

                    <div class="detail-row">
                        <span>Assigned Adviser</span>
                        <strong><?php echo !empty($request["adviser_name"]) ? htmlspecialchars((string) $request["adviser_name"]) : "No historical assignment"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Adviser Department</span>
                        <strong><?php echo !empty($request["adviser_department"]) ? htmlspecialchars((string) $request["adviser_department"]) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Required By</span>
                        <strong><?php echo !empty($request["adviser_required_by_name"]) ? htmlspecialchars((string) $request["adviser_required_by_name"]) : "N/A"; ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Required At</span>
                        <strong><?php echo !empty($request["adviser_required_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["adviser_required_at"])) : "N/A"; ?></strong>
                    </div>
                </div>

                <h4 class="section-title mt-12">Endorsement History</h4>
                <?php if (!$adviserHistory): ?>
                    <div class="note-box">No historical endorsement events recorded.</div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="request-table">
                            <thead>
                                <tr>
                                    <th>Cycle</th>
                                    <th>Event</th>
                                    <th>Actor</th>
                                    <th>Recorded</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($adviserHistory as $event): ?>
                                    <tr>
                                        <td><?php echo (int) $event["cycle_no"]; ?></td>
                                        <td><?php echo htmlspecialchars((string) $event["action"]); ?></td>
                                        <td><?php echo htmlspecialchars((string) $event["actor_name"]); ?></td>
                                        <td><?php echo htmlspecialchars(formatDateTimeDisplay($event["acted_at"])); ?></td>
                                        <td><?php echo $event["remarks"] !== null ? nl2br(htmlspecialchars((string) $event["remarks"])) : "&mdash;"; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <p class="mini-muted mt-12">
                    The online Adviser workflow is retired. These historical records do not gate GSO processing.
                </p>
            </div>
        <?php endif; ?>

        <?php if(gsoRequestHasFacility($pdo,$requestId)): $letters=gsoRequestLetters($pdo,$requestId);$currentLetter=$letters[0]??null;?>
        <section class="card"><h3>Manually Signed Facility Request Letter</h3>
        <p>Verification confirms the submitted document is acceptable for processing. Manual signatures happen outside the system; request approval is a separate action.</p>
        <?php require dirname(__DIR__,2)."/views/partials/request_letters.php";?>
        <?php if($currentLetter && $currentLetter["verification_status"]==="Pending" && in_array($request["status"],["Pending","Under Review"],true) && gsoUserHasPermission((string)$_SESSION["role"],"request.documents.verify")):?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>"><input type="hidden" name="attachment_id" value="<?php echo (int)$currentLetter['attachment_id'];?>">
        <label>Verification remarks<textarea name="verification_remarks" maxlength="1000"></textarea></label>
        <button name="action" value="verify_letter" class="admin-btn primary-btn">Mark Document Verified</button><button name="action" value="reject_letter" class="admin-btn">Reject Document (remarks required)</button></form>
        <?php endif;?></section><?php endif;?>

        <?php $directorHistory=gsoDirectorHistory($pdo,(int)$request["request_id"]); $directorState=(string)($request["director_state"]??"Not Required"); ?>
        <?php if($directorState!=="Not Required"||$directorHistory): ?>
        <div class="card detail-block"><h3 class="section-title">Historical Campus Director Workflow</h3><div class="detail-list">
            <div class="detail-row"><span>Director State</span><strong><?php echo htmlspecialchars($directorState); ?></strong></div>
            <div class="detail-row"><span>Required By</span><strong><?php echo htmlspecialchars((string)($request["director_required_by_name"]??"N/A")); ?></strong></div>
            <div class="detail-row"><span>Required At</span><strong><?php echo !empty($request["director_required_at"])?htmlspecialchars(formatDateTimeDisplay($request["director_required_at"])):"N/A"; ?></strong></div>
        </div><h4 class="section-title mt-12">Director Decision History</h4>
        <?php if(!$directorHistory): ?><div class="note-box">No historical decision was recorded. Director approval is no longer required.</div><?php else: ?><div class="table-wrapper"><table class="request-table"><thead><tr><th>Decision</th><th>Director</th><th>Recorded</th><th>Conflict Outcome</th><th>Remarks</th></tr></thead><tbody><?php foreach($directorHistory as $event):?><tr><td><?php echo htmlspecialchars((string)$event["action"]); ?></td><td><?php echo htmlspecialchars((string)$event["director_name"]); ?></td><td><?php echo htmlspecialchars(formatDateTimeDisplay($event["decided_at"])); ?></td><td><?php
            if (empty($event["winner_request_id"])) {
                echo "Not a conflict decision";
            } elseif ((int) $event["winner_request_id"] === (int) $request["request_id"]) {
                echo "Prioritized over competing requests";
            } else {
                echo "Lost prioritisation to " . htmlspecialchars(formatRequestCode((int) $event["winner_request_id"]));
            }
        ?></td><td><?php echo nl2br(htmlspecialchars((string)($event["remarks"]??"—"))); ?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?></div>
        <?php endif; ?>

        <?php $requestLines = gsoRequestLines($pdo, (int) $request["request_id"]); ?>
        <div class="card detail-block">
            <h3 class="section-title">Requested Resources</h3>
            <div class="table-wrapper">
                <table class="request-table request-line-table">
                    <thead>
                        <tr>
                            <th>Kind</th>
                            <th>Resource</th>
                            <th>Requested</th>
                            <th>Released</th>
                            <th>Returned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$requestLines): ?>
                            <tr><td colspan="5">No resource lines recorded for this request.</td></tr>
                        <?php else: ?>
                            <?php foreach ($requestLines as $line): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $line["line_kind"]); ?></td>
                                    <td><?php echo htmlspecialchars((string) $line["resource_name"]); ?></td>
                                    <td><?php echo $line["line_kind"] === "Facility" ? "&mdash;" : (int) $line["requested_quantity"]; ?></td>
                                    <td><?php echo (int) $line["released_quantity"]; ?></td>
                                    <td><?php echo (int) $line["returned_quantity"]; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="mini-muted mt-12">
                All lines belong to this one request reference and share a single
                approval workflow and one request-level facility letter when required. Adviser and Director workflow records are historical.
            </p>
        </div>

        <div class="card">
            <h3 class="section-title">Resource Description</h3>
            <div class="note-box">
                <?php echo !empty($request["resource_description"]) ? htmlspecialchars($request["resource_description"]) : "No description provided."; ?>
            </div>
        </div>

        <div class="card">
            <h3 class="section-title">Request Notes</h3>
            <div class="note-box">
                <?php echo !empty($request["notes"]) ? htmlspecialchars($request["notes"]) : "No notes provided."; ?>
            </div>
            <div class="note-box mt-12">
                <?php
                if (!empty($request["inspection_condition"])) {
                    echo "Inspection Condition: " . htmlspecialchars((string) $request["inspection_condition"]);

                    if (!empty($request["inspection_remarks"])) {
                        echo "\n\nRemarks: " . htmlspecialchars((string) $request["inspection_remarks"]);
                    }
                } else {
                    echo "No inspection result recorded yet.";
                }
                ?>
            </div>
            <p class="mini-muted mt-12">
                This page is for viewing request details only. Status actions are still handled from the Requests page.
            </p>
        </div>
    </main>
</div>
