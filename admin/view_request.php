<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/request_helper.php";

$pageTitle = "View Request";
$activePage = "requests";
$cssFile = "../assets/css/admin.css";

if (!isset($_GET["request_id"]) || empty($_GET["request_id"])) {
    redirectWithFlash("requests.php", "Request ID is required.");
}

$requestId = (int) $_GET["request_id"];

$stmt = $pdo->prepare("
    SELECT
        rr.request_id,
        rr.borrower_id,
        rr.resource_id,
        rr.quantity,
        rr.contact_number,
        rr.date_needed,
        rr.start_time,
        rr.end_time,
        rr.request_date,
        rr.status,
        rr.approved_by,
        rr.approved_at,
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

        approver.full_name AS reviewed_by_name,

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
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
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

$displayStatus = getRequestLifecycleStatus($request);
$statusClass = getStatusCssClass($displayStatus);
$resourceStatusClass = strtolower($request["resource_status"] ?? "");
$archiveState = ((int)($request["is_archived"] ?? 0) === 1) ? "Archived" : "Active";
$archiveClass = ((int)($request["is_archived"] ?? 0) === 1) ? "archived" : "available";

$timeDisplay = buildTimeRangeDisplay($request["start_time"] ?? null, $request["end_time"] ?? null);
$borrowerCallHref = buildPhoneCallHref($request["contact_number"] ?? null);

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<style>
.request-view-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
}

.detail-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.detail-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    padding: 10px 0;
    border-bottom: 1px solid #e2e8f0;
    align-items: flex-start;
}

.detail-row span {
    color: #64748b;
    min-width: 140px;
}

.detail-row strong {
    color: #0f172a;
    text-align: right;
    word-break: break-word;
}

.detail-block {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
}

.detail-block h3 {
    margin-top: 0;
    margin-bottom: 14px;
    color: #0f172a;
}

.note-box {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 12px;
    padding: 14px 16px;
    color: #334155;
    line-height: 1.6;
    white-space: pre-wrap;
}

.status-inline-group {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 8px;
}

.page-action-bar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 16px;
}

.section-title {
    margin: 0 0 12px 0;
    color: #0f172a;
}

.mini-muted {
    color: #64748b;
    font-size: 13px;
}

.detail-call-link {
    color: #1d4ed8;
    text-decoration: none;
    font-weight: 800;
}

.detail-call-link:hover {
    text-decoration: underline;
}
</style>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
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
                        <strong><?php echo !empty($request["approved_at"]) ? htmlspecialchars(formatDateTimeDisplay($request["approved_at"])) : "N/A"; ?></strong>
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
            <div class="note-box" style="margin-top: 12px;">
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
            <p class="mini-muted" style="margin-top: 12px;">
                This page is for viewing request details only. Status actions are still handled from the Requests page.
            </p>
        </div>
    </main>
</div>

