<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";

$pageTitle = "My Incident Reports";
$activePage = "incidents";
$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/borrower-incidents.css"]);

$borrowerId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedBorrowerAccount($pdo, $borrowerId)) {
    redirectWithFlash("browse.php", "Your account is not authorized to access incident reports.");
}

$statusOptions = getIncidentStatusOptions();
$typeOptions = getIncidentTypeOptions();
$priorityOptions = getIncidentPriorityOptions();

$statusFilter = trim((string) ($_GET["status"] ?? "All"));
$typeFilter = trim((string) ($_GET["type"] ?? "All"));
$priorityFilter = trim((string) ($_GET["priority"] ?? "All"));
$search = normalizeIncidentSingleLine($_GET["search"] ?? "");
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 10;

if ($statusFilter !== "All" && !in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = "All";
}

if ($typeFilter !== "All" && !in_array($typeFilter, $typeOptions, true)) {
    $typeFilter = "All";
}

if ($priorityFilter !== "All" && !in_array($priorityFilter, $priorityOptions, true)) {
    $priorityFilter = "All";
}

if (incidentTextLength($search) > 100) {
    $search = truncateIncidentText($search, 100);
}

$whereSql = "
    FROM incident_reports ir
    LEFT JOIN resources r
        ON r.resource_id = ir.resource_id
    WHERE ir.reporter_id = :reporter_id
";
$params = [":reporter_id" => $borrowerId];

if ($statusFilter !== "All") {
    $whereSql .= " AND ir.status = :status";
    $params[":status"] = $statusFilter;
}

if ($typeFilter !== "All") {
    $whereSql .= " AND ir.incident_type = :incident_type";
    $params[":incident_type"] = $typeFilter;
}

if ($priorityFilter !== "All") {
    $whereSql .= " AND ir.priority = :priority";
    $params[":priority"] = $priorityFilter;
}

if ($search !== "") {
    $whereSql .= " AND (
        ir.incident_title LIKE :search_title
        OR ir.location LIKE :search_location
        OR ir.incident_type LIKE :search_type
        OR r.resource_name LIKE :search_resource
        OR CONCAT('INC-', LPAD(ir.incident_id, 6, '0')) LIKE :search_reference
    )";
    $searchPattern = "%" . $search . "%";
    $params[":search_title"] = $searchPattern;
    $params[":search_location"] = $searchPattern;
    $params[":search_type"] = $searchPattern;
    $params[":search_resource"] = $searchPattern;
    $params[":search_reference"] = $searchPattern;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$listSql = "
    SELECT
        ir.incident_id,
        ir.incident_title,
        ir.incident_type,
        ir.location,
        ir.priority,
        ir.status,
        ir.reported_at,
        ir.updated_at,
        r.resource_name,
        r.resource_type
    " . $whereSql . "
    ORDER BY ir.reported_at DESC, ir.incident_id DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$incidents = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_count,
        SUM(status IN ('Submitted', 'Under Review', 'In Progress')) AS open_count,
        SUM(status = 'Resolved') AS resolved_count,
        SUM(status = 'Rejected') AS rejected_count
    FROM incident_reports
    WHERE reporter_id = :reporter_id
");
$summaryStmt->execute([":reporter_id" => $borrowerId]);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$flashMessage = (string) ($_SESSION["flash_message"] ?? "");
$flashType = (string) ($_SESSION["flash_type"] ?? "");
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter,
    "type" => $typeFilter,
    "priority" => $priorityFilter,
];

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>

    <main class="page-content incident-page">
        <div class="card incident-page-hero">
            <div>
                <h2>My Incident Reports</h2>
                <p>Track the reports you submitted to GSO and review their current status.</p>
            </div>
            <a href="report_issue.php" class="request-btn">
                <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                Report an Issue
            </a>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>" role="status">
                <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>

        <section class="incident-summary-grid" aria-label="Incident report summary">
            <article class="summary-card">
                <h3>Open</h3>
                <p class="summary-number"><?php echo (int) ($summary["open_count"] ?? 0); ?></p>
                <span class="stat-note">Submitted, under review, or in progress</span>
            </article>
            <article class="summary-card">
                <h3>Resolved</h3>
                <p class="summary-number"><?php echo (int) ($summary["resolved_count"] ?? 0); ?></p>
                <span class="stat-note">Completed reports</span>
            </article>
            <article class="summary-card">
                <h3>Rejected</h3>
                <p class="summary-number"><?php echo (int) ($summary["rejected_count"] ?? 0); ?></p>
                <span class="stat-note">Reports not accepted after review</span>
            </article>
            <article class="summary-card">
                <h3>Total</h3>
                <p class="summary-number"><?php echo (int) ($summary["total_count"] ?? 0); ?></p>
                <span class="stat-note">All reports submitted by you</span>
            </article>
        </section>

        <nav class="status-tabs incident-status-tabs" aria-label="Incident status filters">
            <?php foreach (array_merge(["All"], $statusOptions) as $statusOption): ?>
                <a
                    href="my_incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["status" => $statusOption, "page" => 1])), ENT_QUOTES, "UTF-8"); ?>"
                    class="<?php echo $statusFilter === $statusOption ? "active" : ""; ?>"
                >
                    <?php echo htmlspecialchars($statusOption, ENT_QUOTES, "UTF-8"); ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <section class="card incident-list-card">
            <form method="GET" class="table-toolbar incident-filter-bar">
                <input
                    type="search"
                    name="search"
                    maxlength="100"
                    placeholder="Search incident, title, location, or resource..."
                    value="<?php echo htmlspecialchars($search, ENT_QUOTES, "UTF-8"); ?>"
                >

                <select name="status" aria-label="Filter by status">
                    <option value="All">All Statuses</option>
                    <?php foreach ($statusOptions as $statusOption): ?>
                        <option value="<?php echo htmlspecialchars($statusOption, ENT_QUOTES, "UTF-8"); ?>" <?php echo $statusFilter === $statusOption ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($statusOption, ENT_QUOTES, "UTF-8"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="type" aria-label="Filter by category">
                    <option value="All">All Categories</option>
                    <?php foreach ($typeOptions as $typeOption): ?>
                        <option value="<?php echo htmlspecialchars($typeOption, ENT_QUOTES, "UTF-8"); ?>" <?php echo $typeFilter === $typeOption ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($typeOption, ENT_QUOTES, "UTF-8"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="priority" aria-label="Filter by priority">
                    <option value="All">All Priorities</option>
                    <?php foreach ($priorityOptions as $priorityOption): ?>
                        <option value="<?php echo htmlspecialchars($priorityOption, ENT_QUOTES, "UTF-8"); ?>" <?php echo $priorityFilter === $priorityOption ? "selected" : ""; ?>>
                            <?php echo htmlspecialchars($priorityOption, ENT_QUOTES, "UTF-8"); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="request-btn">Filter</button>
                <a href="my_incidents.php" class="request-btn neutral-action">Reset</a>
            </form>

            <div class="table-wrapper">
                <table class="request-table incident-table table-has-actions">
                    <thead>
                        <tr>
                            <th>Incident No.</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Reported Date</th>
                            <th>Priority</th>
                            <th>Current Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($incidents)): ?>
                            <?php foreach ($incidents as $incident): ?>
                                <?php
                                $incidentId = (int) $incident["incident_id"];
                                $status = (string) $incident["status"];
                                $priority = (string) $incident["priority"];
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars(formatIncidentNumber($incidentId), ENT_QUOTES, "UTF-8"); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $incident["incident_title"], ENT_QUOTES, "UTF-8"); ?></strong>
                                        <?php if (!empty($incident["resource_name"])): ?>
                                            <small class="incident-table-subline">
                                                <?php echo htmlspecialchars((string) $incident["resource_name"], ENT_QUOTES, "UTF-8"); ?>
                                                (<?php echo htmlspecialchars((string) $incident["resource_type"], ENT_QUOTES, "UTF-8"); ?>)
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) $incident["incident_type"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars((string) $incident["location"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                    <td>
                                        <span class="incident-priority <?php echo htmlspecialchars(getIncidentPriorityCssClass($priority), ENT_QUOTES, "UTF-8"); ?>">
                                            <?php echo htmlspecialchars($priority, ENT_QUOTES, "UTF-8"); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge incident-status <?php echo htmlspecialchars(getIncidentStatusCssClass($status), ENT_QUOTES, "UTF-8"); ?>">
                                            <?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a class="table-action-btn" href="view_incident.php?incident_id=<?php echo $incidentId; ?>">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                            View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="empty-note">
                                    <div class="incident-empty-state">
                                        <i class="fa-regular fa-clipboard" aria-hidden="true"></i>
                                        <strong>No incident reports found.</strong>
                                        <span>Adjust the filters or submit a new issue.</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($incidents); ?> of <?php echo $totalRows; ?> incident report(s)
                </div>
                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);
                    ?>
                    <a
                        href="my_incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])), ENT_QUOTES, "UTF-8"); ?>"
                        class="pagination-link <?php echo $page <= 1 ? "disabled" : ""; ?>"
                    >Previous</a>

                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <?php if ($pageNumber === 1 || $pageNumber === $totalPages || abs($pageNumber - $page) <= 1): ?>
                            <a
                                href="my_incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $pageNumber])), ENT_QUOTES, "UTF-8"); ?>"
                                class="pagination-link <?php echo $pageNumber === $page ? "active" : ""; ?>"
                            ><?php echo $pageNumber; ?></a>
                        <?php elseif ($pageNumber === 2 && $page > 4): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php elseif ($pageNumber === $totalPages - 1 && $page < $totalPages - 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <a
                        href="my_incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])), ENT_QUOTES, "UTF-8"); ?>"
                        class="pagination-link <?php echo $page >= $totalPages ? "disabled" : ""; ?>"
                    >Next</a>
                </div>
            </div>
        </section>
    </main>
</div>
