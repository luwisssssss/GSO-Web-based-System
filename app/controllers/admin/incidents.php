<?php
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";

$pageTitle = "Incident Reports";
$activePage = "incidents";
$cssFile = "../public/assets/css/pages/admin.css";
$cssFiles = array_merge($cssFiles ?? [], [
    "../public/assets/css/pages/borrower-incidents.css",
    "../public/assets/css/pages/admin-incidents.css",
]);

$adminId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedAdminAccount($pdo, $adminId)) {
    redirectWithFlash("dashboard.php", "Your account is not authorized to manage incident reports.");
}

$statusOptions = getIncidentStatusOptions();
$typeOptions = getIncidentTypeOptions();
$priorityOptions = getIncidentPriorityOptions();

$statusFilter = trim((string) ($_GET["status"] ?? "All"));
$typeFilter = trim((string) ($_GET["type"] ?? "All"));
$priorityFilter = trim((string) ($_GET["priority"] ?? "All"));
$search = normalizeIncidentSingleLine($_GET["search"] ?? "");
$locationFilter = normalizeIncidentSingleLine($_GET["location"] ?? "");
$dateFrom = trim((string) ($_GET["date_from"] ?? ""));
$dateTo = trim((string) ($_GET["date_to"] ?? ""));
$openOnly = (string) ($_GET["open"] ?? "") === "1";
$page = max(1, (int) ($_GET["page"] ?? 1));
$perPage = 15;
$filterError = "";

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
if (incidentTextLength($locationFilter) > 100) {
    $locationFilter = truncateIncidentText($locationFilter, 100);
}

$isValidDate = static function (string $value): bool {
    if ($value === "") {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    return $date instanceof DateTimeImmutable && $date->format("Y-m-d") === $value;
};

if (!$isValidDate($dateFrom) || !$isValidDate($dateTo)) {
    $filterError = "Invalid date filter. Use a valid start and end date.";
    $dateFrom = "";
    $dateTo = "";
} elseif ($dateFrom !== "" && $dateTo !== "" && $dateFrom > $dateTo) {
    $filterError = "The start date cannot be later than the end date.";
    $dateFrom = "";
    $dateTo = "";
}

$whereSql = "
    FROM incident_reports ir
    INNER JOIN users reporter ON reporter.user_id = ir.reporter_id
    LEFT JOIN resources r ON r.resource_id = ir.resource_id
    LEFT JOIN users assignee ON assignee.user_id = ir.assigned_to
    WHERE 1 = 1
";
$params = [];

if ($statusFilter !== "All") {
    $whereSql .= " AND ir.status = :status";
    $params[":status"] = $statusFilter;
}
if ($openOnly) {
    $whereSql .= " AND ir.status IN ('Submitted', 'Under Review', 'In Progress')";
}
if ($typeFilter !== "All") {
    $whereSql .= " AND ir.incident_type = :incident_type";
    $params[":incident_type"] = $typeFilter;
}
if ($priorityFilter !== "All") {
    $whereSql .= " AND ir.priority = :priority";
    $params[":priority"] = $priorityFilter;
}
if ($locationFilter !== "") {
    $whereSql .= " AND ir.location LIKE :location_filter";
    $params[":location_filter"] = "%" . $locationFilter . "%";
}
if ($dateFrom !== "") {
    $whereSql .= " AND ir.reported_at >= :date_from";
    $params[":date_from"] = $dateFrom . " 00:00:00";
}
if ($dateTo !== "") {
    $whereSql .= " AND ir.reported_at <= :date_to";
    $params[":date_to"] = $dateTo . " 23:59:59";
}
if ($search !== "") {
    $pattern = "%" . $search . "%";
    $whereSql .= " AND (
        ir.incident_title LIKE :search_title
        OR ir.location LIKE :search_location
        OR reporter.full_name LIKE :search_reporter
        OR r.resource_name LIKE :search_resource
        OR CONCAT('INC-', LPAD(ir.incident_id, 6, '0')) LIKE :search_reference
    )";
    $params[":search_title"] = $pattern;
    $params[":search_location"] = $pattern;
    $params[":search_reporter"] = $pattern;
    $params[":search_resource"] = $pattern;
    $params[":search_reference"] = $pattern;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) " . $whereSql);
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("
    SELECT
        ir.incident_id,
        ir.incident_title,
        ir.incident_type,
        ir.location,
        ir.priority,
        ir.status,
        ir.reported_at,
        ir.updated_at,
        reporter.full_name AS reporter_name,
        r.resource_name,
        assignee.full_name AS assigned_to_name
    " . $whereSql . "
    ORDER BY
        CASE WHEN ir.status IN ('Submitted', 'Under Review', 'In Progress') THEN 0 ELSE 1 END,
        CASE ir.priority
            WHEN 'Urgent' THEN 1
            WHEN 'High' THEN 2
            WHEN 'Normal' THEN 3
            WHEN 'Low' THEN 4
            ELSE 5
        END,
        ir.reported_at ASC,
        ir.incident_id ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$incidents = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = $pdo->query("
    SELECT
        SUM(status = 'Submitted') AS submitted_count,
        SUM(status = 'Under Review') AS under_review_count,
        SUM(status = 'In Progress') AS in_progress_count,
        SUM(status = 'Resolved') AS resolved_count,
        SUM(priority = 'Urgent' AND status IN ('Submitted', 'Under Review', 'In Progress')) AS urgent_count
    FROM incident_reports
")->fetch(PDO::FETCH_ASSOC) ?: [];

$flashMessage = (string) ($_SESSION["flash_message"] ?? "");
$flashType = (string) ($_SESSION["flash_type"] ?? "");
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter,
    "type" => $typeFilter,
    "priority" => $priorityFilter,
    "location" => $locationFilter,
    "date_from" => $dateFrom,
    "date_to" => $dateTo,
    "open" => $openOnly ? "1" : "",
];

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content incident-page admin-incident-page">
        <div class="card incident-page-hero">
            <div>
                <h2>Incident Reports</h2>
                <p>Review reported damage, prioritize corrective work, and track each incident through resolution.</p>
            </div>
            <a href="incidents.php?status=Submitted" class="admin-btn primary-btn">
                <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                Review New Reports
            </a>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>" role="status">
                <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>
        <?php if ($filterError !== ""): ?>
            <div class="flash-message flash-error" role="alert">
                <?php echo htmlspecialchars($filterError, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>

        <section class="incident-summary-grid admin-incident-summary" aria-label="Incident report summary">
            <?php
            $summaryCards = [
                ["Submitted", "submitted_count", "Submitted", "fa-inbox"],
                ["Under Review", "under_review_count", "Under Review", "fa-magnifying-glass"],
                ["In Progress", "in_progress_count", "In Progress", "fa-screwdriver-wrench"],
                ["Resolved", "resolved_count", "Resolved", "fa-circle-check"],
                ["Urgent Open", "urgent_count", "priority=Urgent", "fa-triangle-exclamation"],
            ];
            ?>
            <?php foreach ($summaryCards as [$label, $key, $filterValue, $icon]): ?>
                <?php
                $href = str_starts_with($filterValue, "priority=")
                    ? "incidents.php?priority=Urgent&open=1"
                    : "incidents.php?status=" . rawurlencode($filterValue);
                ?>
                <a class="summary-card incident-summary-link" href="<?php echo htmlspecialchars($href, ENT_QUOTES, "UTF-8"); ?>">
                    <i class="fa-solid <?php echo htmlspecialchars($icon, ENT_QUOTES, "UTF-8"); ?>" aria-hidden="true"></i>
                    <div>
                        <h3><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></h3>
                        <p class="summary-number"><?php echo (int) ($summary[$key] ?? 0); ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </section>

        <nav class="status-tabs incident-status-tabs" aria-label="Incident status filters">
            <?php foreach (array_merge(["All"], $statusOptions) as $statusOption): ?>
                <a
                    href="incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["status" => $statusOption, "open" => "", "page" => 1])), ENT_QUOTES, "UTF-8"); ?>"
                    class="<?php echo $statusFilter === $statusOption ? "active" : ""; ?>"
                ><?php echo htmlspecialchars($statusOption, ENT_QUOTES, "UTF-8"); ?></a>
            <?php endforeach; ?>
        </nav>

        <section class="card incident-list-card">
            <form method="GET" class="table-toolbar incident-admin-filter-bar">
                <input type="search" name="search" maxlength="100" aria-label="Search incidents" placeholder="Search incident, reporter, resource..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, "UTF-8"); ?>">
                <input type="search" name="location" maxlength="100" aria-label="Filter by location" placeholder="Location contains..." value="<?php echo htmlspecialchars($locationFilter, ENT_QUOTES, "UTF-8"); ?>">
                <select name="status" aria-label="Filter by status">
                    <option value="All">All Statuses</option>
                    <?php foreach ($statusOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?>" <?php echo $statusFilter === $option ? "selected" : ""; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="type" aria-label="Filter by category">
                    <option value="All">All Categories</option>
                    <?php foreach ($typeOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?>" <?php echo $typeFilter === $option ? "selected" : ""; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="priority" aria-label="Filter by priority">
                    <option value="All">All Priorities</option>
                    <?php foreach ($priorityOptions as $option): ?>
                        <option value="<?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?>" <?php echo $priorityFilter === $option ? "selected" : ""; ?>><?php echo htmlspecialchars($option, ENT_QUOTES, "UTF-8"); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="incident-date-filter">From<input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, "UTF-8"); ?>"></label>
                <label class="incident-date-filter">To<input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, "UTF-8"); ?>"></label>
                <button type="submit" class="admin-btn primary-btn">Filter</button>
                <a href="incidents.php" class="admin-btn info-btn">Reset</a>
            </form>

            <div class="table-wrapper">
                <table class="request-table incident-table table-has-actions">
                    <thead>
                        <tr>
                            <th>Incident ID</th>
                            <th>Title</th>
                            <th>Category</th>
                            <th>Reporter</th>
                            <th>Location</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Reported Date</th>
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
                                <tr class="<?php echo $priority === "Urgent" && !in_array($status, ["Resolved", "Rejected"], true) ? "incident-row-urgent" : ""; ?>">
                                    <td><strong><?php echo htmlspecialchars(formatIncidentNumber($incidentId), ENT_QUOTES, "UTF-8"); ?></strong></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $incident["incident_title"], ENT_QUOTES, "UTF-8"); ?></strong>
                                        <?php if (!empty($incident["resource_name"])): ?><small class="incident-table-subline"><?php echo htmlspecialchars((string) $incident["resource_name"], ENT_QUOTES, "UTF-8"); ?></small><?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) $incident["incident_type"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars((string) $incident["reporter_name"], ENT_QUOTES, "UTF-8"); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) $incident["location"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><span class="incident-priority <?php echo htmlspecialchars(getIncidentPriorityCssClass($priority), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($priority, ENT_QUOTES, "UTF-8"); ?></span></td>
                                    <td><span class="status-badge incident-status <?php echo htmlspecialchars(getIncidentStatusCssClass($status), ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?></span></td>
                                    <td><?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><a class="table-action-btn" href="view_incident.php?incident_id=<?php echo $incidentId; ?>"><i class="fa-solid fa-eye" aria-hidden="true"></i> View Details</a></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="empty-note">No incident reports match the selected filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">Showing <?php echo count($incidents); ?> of <?php echo $totalRows; ?> incident report(s)</div>
                <div class="pagination-links">
                    <?php $previousPage = max(1, $page - 1); $nextPage = min($totalPages, $page + 1); ?>
                    <a href="incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $previousPage])), ENT_QUOTES, "UTF-8"); ?>" class="pagination-link <?php echo $page <= 1 ? "disabled" : ""; ?>">Previous</a>
                    <?php for ($pageNumber = 1; $pageNumber <= $totalPages; $pageNumber++): ?>
                        <?php if ($pageNumber === 1 || $pageNumber === $totalPages || abs($pageNumber - $page) <= 1): ?>
                            <a href="incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $pageNumber])), ENT_QUOTES, "UTF-8"); ?>" class="pagination-link <?php echo $pageNumber === $page ? "active" : ""; ?>"><?php echo $pageNumber; ?></a>
                        <?php elseif ($pageNumber === 2 && $page > 4): ?><span class="pagination-link disabled">...</span>
                        <?php elseif ($pageNumber === $totalPages - 1 && $page < $totalPages - 3): ?><span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <a href="incidents.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])), ENT_QUOTES, "UTF-8"); ?>" class="pagination-link <?php echo $page >= $totalPages ? "disabled" : ""; ?>">Next</a>
                </div>
            </div>
        </section>
    </main>
</div>
