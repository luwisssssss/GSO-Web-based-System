<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/resource_image_helper.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/request_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Inventory";
$activePage = "inventory";
$cssFile = "../assets/css/admin.css?v=20260415-inventory-cards";

/*
|--------------------------------------------------------------------------
| Handle archive / restore / delete
|--------------------------------------------------------------------------
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $resourceId = isset($_POST["resource_id"]) ? (int) $_POST["resource_id"] : 0;
    $action = $_POST["action"] ?? "";

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        header("Location: inventory.php");
        exit();
    }

    if ($resourceId <= 0) {
        $_SESSION["flash_message"] = "Invalid resource action.";
        $_SESSION["flash_type"] = "error";
        header("Location: inventory.php");
        exit();
    }

    $checkStmt = $pdo->prepare("
        SELECT resource_id, resource_name, resource_image, is_archived
        FROM resources
        WHERE resource_id = :resource_id
        LIMIT 1
    ");
    $checkStmt->execute([
        ":resource_id" => $resourceId
    ]);

    $resource = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$resource) {
        $_SESSION["flash_message"] = "Resource not found.";
        $_SESSION["flash_type"] = "error";
        header("Location: inventory.php");
        exit();
    }

    if ($action === "archive") {
        $updateStmt = $pdo->prepare("
            UPDATE resources
            SET is_archived = 1
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $success = $updateStmt->execute([
            ":resource_id" => $resourceId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                (int) ($_SESSION["user_id"] ?? 0),
                "Resource Archived",
                "Archived resource: " . ($resource["resource_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Resource archived successfully."
            : "Failed to archive resource.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: inventory.php");
        exit();
    }

    if ($action === "restore") {
        $updateStmt = $pdo->prepare("
            UPDATE resources
            SET is_archived = 0
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $success = $updateStmt->execute([
            ":resource_id" => $resourceId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                (int) ($_SESSION["user_id"] ?? 0),
                "Resource Restored",
                "Restored resource: " . ($resource["resource_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Resource restored successfully."
            : "Failed to restore resource.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: inventory.php");
        exit();
    }

    if ($action === "delete") {
        $refStmt = $pdo->prepare("
            SELECT COUNT(*) AS total_refs
            FROM resource_requests
            WHERE resource_id = :resource_id
        ");
        $refStmt->execute([
            ":resource_id" => $resourceId
        ]);
        $refCount = (int) $refStmt->fetchColumn();

        if ($refCount > 0) {
            $_SESSION["flash_message"] = "This resource cannot be deleted because it is already used in request records. Archive it instead.";
            $_SESSION["flash_type"] = "error";
            header("Location: inventory.php");
            exit();
        }

        deleteResourceImage($resource["resource_image"] ?? null);

        $deleteStmt = $pdo->prepare("
            DELETE FROM resources
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $success = $deleteStmt->execute([
            ":resource_id" => $resourceId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                (int) ($_SESSION["user_id"] ?? 0),
                "Resource Deleted",
                "Deleted resource: " . ($resource["resource_name"] ?? "Unknown")
            );
        }

        $_SESSION["flash_message"] = $success
            ? "Resource deleted successfully."
            : "Failed to delete resource.";
        $_SESSION["flash_type"] = $success ? "success" : "error";

        header("Location: inventory.php");
        exit();
    }

    $_SESSION["flash_message"] = "Unknown action.";
    $_SESSION["flash_type"] = "error";
    header("Location: inventory.php");
    exit();
}

$search = trim($_GET["search"] ?? "");
$categoryFilter = $_GET["category"] ?? "All";
$statusFilter = $_GET["status"] ?? "All";
$typeFilter = $_GET["type"] ?? "All";
$archiveFilter = $_GET["archive"] ?? "Active";
$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

/*
|--------------------------------------------------------------------------
| Load filter values
|--------------------------------------------------------------------------
*/
$categoryStmt = $pdo->query("
    SELECT DISTINCT category
    FROM resources
    WHERE category IS NOT NULL AND category <> ''
    ORDER BY category ASC
");
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

$typeStmt = $pdo->query("
    SELECT DISTINCT resource_type
    FROM resources
    WHERE resource_type IS NOT NULL AND resource_type <> ''
    ORDER BY resource_type ASC
");
$types = $typeStmt->fetchAll(PDO::FETCH_COLUMN);

/*
|--------------------------------------------------------------------------
| Inventory summary cards
|--------------------------------------------------------------------------
*/
$summaryStmt = $pdo->query("
    SELECT
        SUM(CASE WHEN is_archived = 0 THEN 1 ELSE 0 END) AS total_active_resources,
        SUM(CASE WHEN is_archived = 0 AND status = 'Available' THEN 1 ELSE 0 END) AS available_resources,
        SUM(CASE WHEN is_archived = 0 AND status = 'Unavailable' THEN 1 ELSE 0 END) AS unavailable_resources,
        SUM(CASE WHEN is_archived = 0 AND status = 'Maintenance' THEN 1 ELSE 0 END) AS maintenance_resources,
        SUM(CASE WHEN is_archived = 1 THEN 1 ELSE 0 END) AS archived_resources
    FROM resources
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Base WHERE for count + list
|--------------------------------------------------------------------------
*/
$baseWhereSql = "
    FROM resources
    WHERE 1=1
";

$params = [];

if ($archiveFilter === "Active") {
    $baseWhereSql .= " AND is_archived = 0";
} elseif ($archiveFilter === "Archived") {
    $baseWhereSql .= " AND is_archived = 1";
}

if ($search !== "") {
    $baseWhereSql .= " AND (
        resource_name LIKE :search
        OR resource_type LIKE :search
        OR category LIKE :search
        OR description LIKE :search
        OR location LIKE :search
        OR resource_id LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}

if ($categoryFilter !== "All") {
    $baseWhereSql .= " AND category = :category";
    $params[":category"] = $categoryFilter;
}

if ($statusFilter !== "All") {
    $baseWhereSql .= " AND status = :status";
    $params[":status"] = $statusFilter;
}

if ($typeFilter !== "All") {
    $baseWhereSql .= " AND resource_type = :resource_type";
    $params[":resource_type"] = $typeFilter;
}

/*
|--------------------------------------------------------------------------
| Count total rows
|--------------------------------------------------------------------------
*/
$countSql = "SELECT COUNT(*) " . $baseWhereSql;
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();

$totalPages = max(1, (int) ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

/*
|--------------------------------------------------------------------------
| Resource listing
|--------------------------------------------------------------------------
*/
$listSql = "
    SELECT
        resource_id,
        resource_name,
        resource_image,
        resource_type,
        category,
        description,
        location,
        total_stock,
        available_stock,
        capacity,
        status,
        condition_status,
        is_archived,
        created_at
    " . $baseWhereSql . "
    ORDER BY
        is_archived ASC,
        CASE
            WHEN status = 'Available' THEN 1
            WHEN status = 'Unavailable' THEN 2
            WHEN status = 'Maintenance' THEN 3
            ELSE 4
        END,
        resource_name ASC
    LIMIT {$perPage} OFFSET {$offset}
";

$stmt = $pdo->prepare($listSql);
$stmt->execute($params);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "category" => $categoryFilter,
    "status" => $statusFilter,
    "type" => $typeFilter,
    "archive" => $archiveFilter
];

require_once "../includes/header.php";
require_once "../includes/admin_sidebar.php";
?>

<style>
.pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 18px;
}

.pagination-info {
    color: #64748b;
    font-size: 14px;
}

.pagination-links {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    padding: 9px 12px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1e293b;
}

.pagination-link:hover {
    background: #f8fafc;
}

.pagination-link.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

.pagination-link.disabled {
    pointer-events: none;
    opacity: 0.5;
}

.table-resource-thumb {
    width: 70px;
    height: 70px;
    object-fit: cover;
    border-radius: 10px;
    border: 1px solid #dbe2ea;
    display: block;
    background: #f8fafc;
}
</style>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <div class="page-header-row">
                <div>
                    <h2>Inventory</h2>
                    <p>Manage inventory records from the <code>resources</code> table.</p>
                </div>

                <div class="action-group">
                    <a href="add_resource.php" class="admin-btn primary-btn">Add Resource</a>
                </div>
            </div>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="admin-grid">
            <div class="summary-card">
                <h3>Active Resources</h3>
                <p class="summary-number"><?php echo (int) ($summary["total_active_resources"] ?? 0); ?></p>
            </div>

            <div class="summary-card">
                <h3>Available</h3>
                <p class="summary-number"><?php echo (int) ($summary["available_resources"] ?? 0); ?></p>
            </div>

            <div class="summary-card">
                <h3>Unavailable</h3>
                <p class="summary-number"><?php echo (int) ($summary["unavailable_resources"] ?? 0); ?></p>
            </div>

            <div class="summary-card">
                <h3>Maintenance</h3>
                <p class="summary-number"><?php echo (int) ($summary["maintenance_resources"] ?? 0); ?></p>
            </div>

            <div class="summary-card">
                <h3>Archived</h3>
                <p class="summary-number"><?php echo (int) ($summary["archived_resources"] ?? 0); ?></p>
            </div>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search name, type, category, location, or ID..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="type">
                    <option value="All" <?php echo $typeFilter === "All" ? "selected" : ""; ?>>All Types</option>
                    <?php foreach ($types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $typeFilter === $type ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($type); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="category">
                    <option value="All" <?php echo $categoryFilter === "All" ? "selected" : ""; ?>>All Categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $categoryFilter === $category ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($category); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Available" <?php echo $statusFilter === "Available" ? "selected" : ""; ?>>Available</option>
                    <option value="Unavailable" <?php echo $statusFilter === "Unavailable" ? "selected" : ""; ?>>Unavailable</option>
                    <option value="Maintenance" <?php echo $statusFilter === "Maintenance" ? "selected" : ""; ?>>Maintenance</option>
                </select>

                <select name="archive">
                    <option value="Active" <?php echo $archiveFilter === "Active" ? "selected" : ""; ?>>Active Only</option>
                    <option value="Archived" <?php echo $archiveFilter === "Archived" ? "selected" : ""; ?>>Archived Only</option>
                    <option value="All" <?php echo $archiveFilter === "All" ? "selected" : ""; ?>>All Records</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Filter</button>
            </form>

            <div class="inventory-browse-grid">
                <?php if (count($resources) > 0): ?>
                    <?php foreach ($resources as $resource): ?>
                        <?php
                        $statusClass = getStatusCssClass((string) $resource["status"]);
                        $archiveState = ((int)$resource["is_archived"] === 1) ? "Archived" : "Active";
                        $archiveClass = ((int)$resource["is_archived"] === 1) ? "archived" : "available";
                        $conditionClass = getStatusCssClass((string) ($resource["condition_status"] ?? "Good"));
                        $resourceImagePath = resourceImagePublicPath($resource["resource_image"] ?? null, "../");
                        $resourceCode = "RES-" . str_pad((string) $resource["resource_id"], 3, "0", STR_PAD_LEFT);
                        ?>
                        <article class="inventory-browser-card">
                            <div class="inventory-browser-image">
                                <img
                                    src="<?php echo htmlspecialchars($resourceImagePath); ?>"
                                    alt="<?php echo htmlspecialchars($resource["resource_name"]); ?>"
                                >

                                <span class="inventory-id-chip"><?php echo htmlspecialchars($resourceCode); ?></span>

                                <div class="inventory-card-actions">
                                    <div class="row-actions">
                                        <button
                                            type="button"
                                            class="row-actions-trigger"
                                            data-row-actions-toggle
                                            aria-expanded="false"
                                            aria-label="Open actions for <?php echo htmlspecialchars($resource["resource_name"]); ?>"
                                        >
                                            <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                        </button>

                                        <div class="row-actions-menu" role="menu">
                                            <a href="view_resource.php?resource_id=<?php echo (int) $resource["resource_id"]; ?>" class="row-action-item" role="menuitem">
                                                <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                <span>View details</span>
                                            </a>

                                            <a href="edit_resource.php?resource_id=<?php echo (int) $resource["resource_id"]; ?>" class="row-action-item" role="menuitem">
                                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                <span>Edit resource</span>
                                            </a>

                                            <a href="maintenance.php?resource_id=<?php echo (int) $resource["resource_id"]; ?>" class="row-action-item warning-action" role="menuitem">
                                                <i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i>
                                                <span>Maintenance</span>
                                            </a>

                                            <?php if ((int)$resource["is_archived"] === 0): ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Archive this resource?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                    <input type="hidden" name="resource_id" value="<?php echo htmlspecialchars($resource["resource_id"]); ?>">
                                                    <input type="hidden" name="action" value="archive">
                                                    <button type="submit" class="warning-action" role="menuitem">
                                                        <i class="fa-solid fa-box-archive" aria-hidden="true"></i>
                                                        <span>Archive</span>
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Restore this resource?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                    <input type="hidden" name="resource_id" value="<?php echo htmlspecialchars($resource["resource_id"]); ?>">
                                                    <input type="hidden" name="action" value="restore">
                                                    <button type="submit" class="success-action" role="menuitem">
                                                        <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                                                        <span>Restore</span>
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                            <form method="POST" class="inline-form" onsubmit="return confirm('Delete this resource permanently? This cannot be undone.');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="resource_id" value="<?php echo htmlspecialchars($resource["resource_id"]); ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <button type="submit" class="danger-action" role="menuitem">
                                                    <i class="fa-solid fa-trash-can" aria-hidden="true"></i>
                                                    <span>Delete</span>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="inventory-browser-body">
                                <div class="inventory-card-heading">
                                    <div>
                                        <h3><?php echo htmlspecialchars($resource["resource_name"]); ?></h3>
                                        <p>
                                            <?php echo htmlspecialchars($resource["resource_type"]); ?>
                                            <?php if (!empty($resource["category"])): ?>
                                                | <?php echo htmlspecialchars((string) $resource["category"]); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>

                                <p class="inventory-card-description">
                                    <?php echo htmlspecialchars($resource["description"] ?? "No description available."); ?>
                                </p>

                                <div class="inventory-badge-row">
                                    <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>">
                                        <?php echo htmlspecialchars($resource["status"]); ?>
                                    </span>
                                    <span class="status-badge <?php echo htmlspecialchars($conditionClass); ?>">
                                        <?php echo htmlspecialchars((string) ($resource["condition_status"] ?? "Good")); ?>
                                    </span>
                                    <span class="status-badge <?php echo htmlspecialchars($archiveClass); ?>">
                                        <?php echo htmlspecialchars($archiveState); ?>
                                    </span>
                                </div>

                                <div class="inventory-card-meta">
                                    <div>
                                        <span>Location</span>
                                        <strong><?php echo htmlspecialchars($resource["location"] ?? "N/A"); ?></strong>
                                    </div>
                                    <div>
                                        <span>Created</span>
                                        <strong><?php echo htmlspecialchars(formatDateTimeDisplay($resource["created_at"] ?? "")); ?></strong>
                                    </div>
                                </div>

                                <div class="inventory-card-stats">
                                    <span>
                                        <small>Total</small>
                                        <b><?php echo htmlspecialchars($resource["total_stock"] ?? "N/A"); ?></b>
                                    </span>
                                    <span>
                                        <small>Available</small>
                                        <b><?php echo htmlspecialchars($resource["available_stock"] ?? "N/A"); ?></b>
                                    </span>
                                    <span>
                                        <small>Capacity</small>
                                        <b><?php echo htmlspecialchars($resource["capacity"] ?? "N/A"); ?></b>
                                    </span>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="inventory-empty-card">
                        <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                        <strong>No inventory records found.</strong>
                        <span>Try changing the search or filter options.</span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($resources); ?> of <?php echo $totalRows; ?> resource(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);

                    $prevUrl = "inventory.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "inventory.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? 'disabled' : ''; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="inventory.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
                                class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>"
                            >
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i === 2 && $page > 4): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php elseif ($i === $totalPages - 1 && $page < $totalPages - 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <a href="<?php echo $nextUrl; ?>" class="pagination-link <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">Next</a>
                </div>
            </div>
        </div>
    </main>
</div>

