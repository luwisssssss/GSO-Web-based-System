<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/resource_image_helper.php";
require_once "../includes/request_helper.php";
require_once "../includes/facility_calendar_helper.php";

$pageTitle = "View Resource";
$activePage = "inventory";
$cssFile = "../assets/css/admin.css";

if (!isset($_GET["resource_id"]) || empty($_GET["resource_id"])) {
    redirectWithFlash("inventory.php", "Resource ID is required.");
}

$resourceId = (int) $_GET["resource_id"];

$stmt = $pdo->prepare("
    SELECT
        resource_id,
        resource_name,
        resource_type,
        category,
        description,
        resource_image,
        location,
        total_stock,
        available_stock,
        capacity,
        status,
        condition_status,
        condition_notes,
        is_archived,
        created_at
    FROM resources
    WHERE resource_id = :resource_id
    LIMIT 1
");
$stmt->execute([
    ":resource_id" => $resourceId
]);

$resource = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resource) {
    redirectWithFlash("inventory.php", "Resource not found.");
}

$statusClass = getStatusCssClass((string) ($resource["status"] ?? ""));
$conditionClass = getStatusCssClass((string) ($resource["condition_status"] ?? "Good"));
$archiveState = ((int)($resource["is_archived"] ?? 0) === 1) ? "Archived" : "Active";
$archiveClass = ((int)($resource["is_archived"] ?? 0) === 1) ? "archived" : "available";
$facilityCalendarHtml = "";

if (($resource["resource_type"] ?? "") === "Facility") {
    $facilityCalendarMonth = normalizeFacilityCalendarMonth($_GET["facility_calendar_month"] ?? null);
    $facilityCalendarHtml = renderFacilityAvailabilityCalendar($pdo, [
        "title" => "Facility Availability",
        "subtitle" => "Active reservations and maintenance schedules for this facility.",
        "month" => $facilityCalendarMonth,
        "resource_id" => $resourceId,
        "show_borrower" => true,
        "show_resource_name" => false,
        "show_resource_filter" => false,
        "base_url" => "view_resource.php",
        "query_params" => [
            "resource_id" => $resourceId
        ],
        "month_param" => "facility_calendar_month",
        "resource_param" => "facility_calendar_resource_id"
    ]);
}

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<style>
.resource-view-grid {
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

.resource-image-view-wrap {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    background: #f8fafc;
}

.resource-image-view {
    width: 100%;
    max-height: 360px;
    object-fit: cover;
    display: block;
}
</style>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>View Resource</h2>
            <p>Full details for resource <strong>#<?php echo htmlspecialchars($resource["resource_id"]); ?></strong>.</p>

            <div class="status-inline-group">
                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                    <?php echo htmlspecialchars($resource["status"]); ?>
                </span>

                <span class="status-badge <?php echo htmlspecialchars($conditionClass); ?>">
                    Condition: <?php echo htmlspecialchars((string) ($resource["condition_status"] ?? "Good")); ?>
                </span>

                <span class="status-badge <?php echo htmlspecialchars($archiveClass); ?>">
                    <?php echo htmlspecialchars($archiveState); ?>
                </span>
            </div>

            <div class="page-action-bar">
                <a href="inventory.php" class="admin-btn info-btn">Back to Inventory</a>
                <a href="edit_resource.php?resource_id=<?php echo $resource["resource_id"]; ?>" class="admin-btn primary-btn">Edit Resource</a>
                <a href="maintenance.php?resource_id=<?php echo $resource["resource_id"]; ?>" class="admin-btn neutral-btn">Maintenance</a>
            </div>
        </div>

        <div class="resource-view-grid">
            <div class="card detail-block">
                <h3>Resource Image</h3>
                <div class="resource-image-view-wrap">
                    <img
                        src="<?php echo htmlspecialchars(resourceImagePublicPath($resource["resource_image"] ?? null, "../")); ?>"
                        alt="<?php echo htmlspecialchars($resource["resource_name"] ?? "Resource Image"); ?>"
                        class="resource-image-view"
                    >
                </div>
            </div>

            <div class="card detail-block">
                <h3>Basic Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Resource ID</span>
                        <strong><?php echo htmlspecialchars($resource["resource_id"]); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Resource Name</span>
                        <strong><?php echo htmlspecialchars($resource["resource_name"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Resource Type</span>
                        <strong><?php echo htmlspecialchars($resource["resource_type"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Category</span>
                        <strong><?php echo htmlspecialchars($resource["category"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Location</span>
                        <strong><?php echo htmlspecialchars($resource["location"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Created At</span>
                        <strong><?php echo htmlspecialchars($resource["created_at"] ?? "N/A"); ?></strong>
                    </div>
                </div>
            </div>

            <div class="card detail-block">
                <h3>Inventory Information</h3>
                <div class="detail-list">
                    <div class="detail-row">
                        <span>Total Stock</span>
                        <strong><?php echo htmlspecialchars($resource["total_stock"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Available Stock</span>
                        <strong><?php echo htmlspecialchars($resource["available_stock"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Capacity</span>
                        <strong><?php echo htmlspecialchars($resource["capacity"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Status</span>
                        <strong><?php echo htmlspecialchars($resource["status"] ?? "N/A"); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Condition</span>
                        <strong><?php echo htmlspecialchars((string) ($resource["condition_status"] ?? "Good")); ?></strong>
                    </div>

                    <div class="detail-row">
                        <span>Archive State</span>
                        <strong><?php echo htmlspecialchars($archiveState); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <h3 class="section-title">Description</h3>
            <div class="note-box">
                <?php echo !empty($resource["description"]) ? htmlspecialchars($resource["description"]) : "No description provided."; ?>
            </div>
            <div class="note-box" style="margin-top: 12px;">
                <?php echo !empty($resource["condition_notes"]) ? htmlspecialchars((string) $resource["condition_notes"]) : "No condition notes provided."; ?>
            </div>
            <p class="mini-muted" style="margin-top: 12px;">
                This page is for viewing resource details only. Edit, archive, restore, and delete actions are handled from the Inventory page.
            </p>
        </div>

        <?php if (($resource["resource_type"] ?? "") === "Facility"): ?>
            <div class="card">
                <?php echo $facilityCalendarHtml; ?>
            </div>
        <?php endif; ?>
    </main>
</div>
