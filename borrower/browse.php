<?php
require_once "../includes/borrower_check.php";
require_once "../config/db.php";
require_once "../includes/resource_image_helper.php";
require_once "../includes/request_helper.php";

$pageTitle = "Browse";
$activePage = "browse";
$cssFile = "../assets/css/borrower.css";

$search = trim($_GET["search"] ?? "");
$categoryFilter = $_GET["category"] ?? "All";
$statusFilter = $_GET["status"] ?? "All";
$sort = $_GET["sort"] ?? "available";

$allowedSorts = ["available", "name_asc", "newest", "type"];
if (!in_array($sort, $allowedSorts, true)) {
    $sort = "available";
}

$sql = "
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
    WHERE is_archived = 0
";

$params = [];

if ($search !== "") {
    $sql .= " AND (
        resource_name LIKE :search
        OR resource_type LIKE :search
        OR category LIKE :search
        OR description LIKE :search
        OR location LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}

if ($categoryFilter !== "All") {
    $sql .= " AND category = :category";
    $params[":category"] = $categoryFilter;
}

if ($statusFilter !== "All") {
    $sql .= " AND status = :status";
    $params[":status"] = $statusFilter;
}

$orderSql = "status = 'Available' DESC, resource_name ASC";

if ($sort === "name_asc") {
    $orderSql = "resource_name ASC";
} elseif ($sort === "newest") {
    $orderSql = "created_at DESC, resource_name ASC";
} elseif ($sort === "type") {
    $orderSql = "resource_type ASC, resource_name ASC";
}

$sql .= " ORDER BY " . $orderSql;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryStmt = $pdo->query("
    SELECT DISTINCT category
    FROM resources
    WHERE is_archived = 0
      AND category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
");
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

$resourceStats = [
    "total" => count($resources),
    "available" => 0,
    "items" => 0,
    "facilities" => 0
];

foreach ($resources as $resourceStatRow) {
    if (($resourceStatRow["status"] ?? "") === "Available") {
        $resourceStats["available"]++;
    }

    if (($resourceStatRow["resource_type"] ?? "") === "Item") {
        $resourceStats["items"]++;
    }

    if (($resourceStatRow["resource_type"] ?? "") === "Facility") {
        $resourceStats["facilities"]++;
    }
}

require_once "../includes/header.php";
require_once "../includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once "../includes/borrower_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Browse Resources</h2>
            <p>Find available items and facilities, review key details, and submit a request quickly.</p>
        </div>

        <div class="ui-metric-strip">
            <div class="ui-metric">
                <span>Showing</span>
                <strong><?php echo (int) $resourceStats["total"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Available</span>
                <strong><?php echo (int) $resourceStats["available"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Items</span>
                <strong><?php echo (int) $resourceStats["items"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Facilities</span>
                <strong><?php echo (int) $resourceStats["facilities"]; ?></strong>
            </div>
        </div>

        <div class="browse-toolbar card">
            <form method="GET" class="browse-form">
                <div class="browse-search">
                    <input
                        type="text"
                        name="search"
                        placeholder="Search resource name, type, category, or location..."
                        value="<?php echo htmlspecialchars($search); ?>"
                    >
                </div>

                <div class="browse-filters">
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

                    <select name="sort">
                        <option value="available" <?php echo $sort === "available" ? "selected" : ""; ?>>Available First</option>
                        <option value="name_asc" <?php echo $sort === "name_asc" ? "selected" : ""; ?>>Name A-Z</option>
                        <option value="newest" <?php echo $sort === "newest" ? "selected" : ""; ?>>Newest</option>
                        <option value="type" <?php echo $sort === "type" ? "selected" : ""; ?>>Type</option>
                    </select>

                    <button type="submit">Search</button>
                </div>
            </form>
        </div>

        <div class="browse-grid">
            <?php if (count($resources) > 0): ?>
                <?php foreach ($resources as $resource): ?>
                    <?php
                    $resourceStatus = $resource["status"] ?? "Unavailable";
                    $isAvailable = $resourceStatus === "Available";
                    $isItem = ($resource["resource_type"] ?? "") === "Item";
                    $imagePath = resourceImagePublicPath($resource["resource_image"] ?? null, "../");
                    $conditionStatus = $resource["condition_status"] ?? "Good";
                    ?>
                    <div class="item-card">
                        <div class="item-image">
                            <img
                                src="<?php echo htmlspecialchars($imagePath); ?>"
                                alt="<?php echo htmlspecialchars($resource["resource_name"]); ?>"
                            >
                        </div>

                        <div class="item-details">
                            <h3><?php echo htmlspecialchars($resource["resource_name"]); ?></h3>

                            <p><strong>Type:</strong> <?php echo htmlspecialchars($resource["resource_type"]); ?></p>
                            <p><strong>Category:</strong> <?php echo htmlspecialchars($resource["category"] ?? "N/A"); ?></p>
                            <p><strong>Location:</strong> <?php echo htmlspecialchars($resource["location"] ?? "N/A"); ?></p>
                            <p>
                                <strong>Status:</strong>
                                <span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) $resourceStatus)); ?>">
                                    <?php echo htmlspecialchars($resourceStatus); ?>
                                </span>
                            </p>
                            <p>
                                <strong>Condition:</strong>
                                <span class="status-badge <?php echo htmlspecialchars(getStatusCssClass((string) $conditionStatus)); ?>">
                                    <?php echo htmlspecialchars((string) $conditionStatus); ?>
                                </span>
                            </p>

                            <?php if ($isItem): ?>
                                <p><strong>Available Stock:</strong> <?php echo htmlspecialchars($resource["available_stock"] ?? "0"); ?></p>
                                <p><strong>Total Stock:</strong> <?php echo htmlspecialchars($resource["total_stock"] ?? "0"); ?></p>
                            <?php else: ?>
                                <p><strong>Available Stock:</strong> N/A</p>
                                <p><strong>Total Stock:</strong> N/A</p>
                                <p><strong>Capacity:</strong> <?php echo htmlspecialchars($resource["capacity"] ?? "N/A"); ?></p>
                            <?php endif; ?>

                            <p><strong>Description:</strong> <?php echo htmlspecialchars($resource["description"] ?? "No description available."); ?></p>
                            <?php if (!empty($resource["condition_notes"])): ?>
                                <p><strong>Condition Notes:</strong> <?php echo htmlspecialchars((string) $resource["condition_notes"]); ?></p>
                            <?php endif; ?>

                            <?php if ($isAvailable): ?>
                                <a href="request_resource.php?resource_id=<?php echo (int) $resource["resource_id"]; ?>" class="request-btn request-link-btn">
                                    <?php echo $isItem ? "Request Resource" : "Reserve Facility"; ?>
                                </a>
                            <?php else: ?>
                                <button type="button" class="request-btn disabled-btn" disabled>
                                    <?php echo htmlspecialchars($resourceStatus); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card">
                    <p>No resources found.</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

</div>

