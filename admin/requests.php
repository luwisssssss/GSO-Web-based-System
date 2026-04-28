<?php
require_once "../includes/admin_check.php";
require_once "../config/db.php";
require_once "../includes/activity_log_helper.php";
require_once "../includes/notification_helper.php";
require_once "../includes/request_helper.php";
require_once "../includes/maintenance_helper.php";
require_once "../includes/facility_calendar_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Requests";
$activePage = "requests";
$cssFile = "../assets/css/admin.css";

function redirectRequestsPage(): void
{
    header("Location: requests.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $requestId = isset($_POST["request_id"]) ? (int) $_POST["request_id"] : 0;
    $action = trim((string) ($_POST["action"] ?? ""));
    $adminUserId = (int) ($_SESSION["user_id"] ?? 0);

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    if ($requestId <= 0 || !in_array($action, ["approve", "reject", "release"], true)) {
        $_SESSION["flash_message"] = "Invalid request action.";
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    $checkStmt = $pdo->prepare("
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
            rr.due_date,
            rr.return_date,
            rr.notes,
            r.resource_name,
            r.resource_type,
            r.category,
            r.status AS resource_status,
            r.total_stock,
            r.available_stock,
            r.condition_status,
            r.is_archived
        FROM resource_requests rr
        INNER JOIN resources r ON rr.resource_id = r.resource_id
        WHERE rr.request_id = :request_id
        LIMIT 1
    ");
    $checkStmt->execute([
        ":request_id" => $requestId
    ]);
    $requestRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$requestRow) {
        $_SESSION["flash_message"] = "Request not found.";
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    $quantity = max(1, (int) ($requestRow["quantity"] ?? 0));
    $resourceType = (string) ($requestRow["resource_type"] ?? "Item");
    $dateNeeded = $requestRow["date_needed"] ?? null;
    $startTime = $requestRow["start_time"] ?? null;
    $endTime = $requestRow["end_time"] ?? null;
    $scheduleDueDate = null;

    if ($action === "reject") {
        if (!in_array((string) ($requestRow["status"] ?? ""), ["Pending", "Under Review"], true)) {
            $_SESSION["flash_message"] = "Only pending or under review requests can be rejected.";
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $updateStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Rejected',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE request_id = :request_id
              AND status IN ('Pending', 'Under Review')
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":approved_by" => $adminUserId,
            ":request_id" => $requestId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Request Rejected",
                "Admin rejected request #{$requestId} for resource: " . ($requestRow["resource_name"] ?? "Unknown")
            );

            createNotification(
                $pdo,
                (int) $requestRow["borrower_id"],
                "rejected",
                "Request Rejected",
                "Your request for " . ($requestRow["resource_name"] ?? "resource") . " has been rejected.",
                "my_requests.php"
            );
        }

        $_SESSION["flash_message"] = $success ? "Request rejected successfully." : "Failed to reject request.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectRequestsPage();
    }

    if ($action === "approve") {
        $validationErrors = validateBorrowScheduleInputs(
            $resourceType,
            $quantity,
            $dateNeeded,
            $startTime,
            $endTime,
            $scheduleDueDate
        );

        if (!empty($validationErrors)) {
            $_SESSION["flash_message"] = implode(" ", $validationErrors);
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }
    }

    if ($action === "approve") {
        if (!in_array((string) ($requestRow["status"] ?? ""), ["Pending", "Under Review"], true)) {
            $_SESSION["flash_message"] = "Only pending or under review requests can be approved.";
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $queueBlocker = findEarlierFirstComeFirstServedRequest(
            $pdo,
            $requestRow,
            ["Pending", "Under Review"]
        );

        if ($queueBlocker) {
            $_SESSION["flash_message"] = buildFirstComeFirstServedMessage($queueBlocker);
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $availability = checkResourceAvailability(
            $pdo,
            $requestRow,
            $quantity,
            $dateNeeded,
            $startTime,
            $endTime,
            $scheduleDueDate,
            $requestId
        );

        if (!($availability["ok"] ?? false)) {
            $_SESSION["flash_message"] = (string) ($availability["message"] ?? "This request is no longer available.");
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $updateStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Approved',
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE request_id = :request_id
              AND status IN ('Pending', 'Under Review')
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":approved_by" => $adminUserId,
            ":request_id" => $requestId
        ]);

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Request Approved",
                "Admin approved request #{$requestId} for resource: " . ($requestRow["resource_name"] ?? "Unknown")
            );

            createNotification(
                $pdo,
                (int) $requestRow["borrower_id"],
                "approved",
                "Request Approved",
                "Your request for " . ($requestRow["resource_name"] ?? "resource") . " has been approved.",
                "my_requests.php"
            );
        }

        $_SESSION["flash_message"] = $success ? "Request approved successfully." : "Failed to approve request.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectRequestsPage();
    }

    if (($requestRow["status"] ?? "") !== "Approved") {
        $_SESSION["flash_message"] = "Only approved requests can be released.";
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    $releaseQueueBlocker = findEarlierFirstComeFirstServedRequest(
        $pdo,
        $requestRow,
        ["Pending", "Under Review", "Approved"]
    );

    if ($releaseQueueBlocker) {
        $_SESSION["flash_message"] = buildFirstComeFirstServedMessage($releaseQueueBlocker);
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    $availability = checkResourceAvailability(
        $pdo,
        $requestRow,
        $quantity,
        $dateNeeded,
        $startTime,
        $endTime,
        $scheduleDueDate,
        $requestId
    );

    if (!($availability["ok"] ?? false)) {
        $_SESSION["flash_message"] = (string) ($availability["message"] ?? "This request is no longer available.");
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("
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
                rr.due_date,
                rr.return_date,
                rr.notes,
                r.resource_name,
                r.resource_type,
                r.category,
                r.status AS resource_status,
                r.total_stock,
                r.available_stock,
                r.condition_status,
                r.is_archived
            FROM resource_requests rr
            INNER JOIN resources r ON rr.resource_id = r.resource_id
            WHERE rr.request_id = :request_id
            LIMIT 1
            FOR UPDATE
        ");
        $lockStmt->execute([
            ":request_id" => $requestId
        ]);
        $lockedRequest = $lockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$lockedRequest) {
            throw new Exception("Request not found.");
        }

        if (($lockedRequest["status"] ?? "") !== "Approved") {
            throw new Exception("Only approved requests can be released.");
        }

        $releaseQueueBlocker = findEarlierFirstComeFirstServedRequest(
            $pdo,
            $lockedRequest,
            ["Pending", "Under Review", "Approved"]
        );

        if ($releaseQueueBlocker) {
            throw new Exception(buildFirstComeFirstServedMessage($releaseQueueBlocker));
        }

        $requestRow = $lockedRequest;
        $quantity = max(1, (int) ($requestRow["quantity"] ?? 0));
        $resourceType = (string) ($requestRow["resource_type"] ?? "Item");
        $dateNeeded = $requestRow["date_needed"] ?? null;
        $startTime = $requestRow["start_time"] ?? null;
        $endTime = $requestRow["end_time"] ?? null;

        $availability = checkResourceAvailability(
            $pdo,
            $requestRow,
            $quantity,
            $dateNeeded,
            $startTime,
            $endTime,
            $scheduleDueDate,
            $requestId
        );

        if (!($availability["ok"] ?? false)) {
            throw new Exception((string) ($availability["message"] ?? "This request is no longer available."));
        }

        if ($resourceType === "Item") {
            if ((int) ($requestRow["is_archived"] ?? 0) === 1) {
                throw new Exception("This resource is archived and cannot be released.");
            }

            if ((string) ($requestRow["resource_status"] ?? "") !== "Available") {
                throw new Exception("Only available item resources can be released.");
            }

            if ((string) ($requestRow["condition_status"] ?? "Good") !== "Good") {
                throw new Exception("This item cannot be released because its condition is not good.");
            }

            $availableStock = (int) ($requestRow["available_stock"] ?? 0);

            if ($availableStock < $quantity) {
                throw new Exception("Not enough physical stock is available for release.");
            }

            $updateStockStmt = $pdo->prepare("
                UPDATE resources
                SET available_stock = :available_stock
                WHERE resource_id = :resource_id
                LIMIT 1
            ");
            $updateStockStmt->execute([
                ":available_stock" => $availableStock - $quantity,
                ":resource_id" => $requestRow["resource_id"]
            ]);
        }

        $releasedAt = date("Y-m-d H:i:s");
        $generatedDueDate = generateLoanDueDate(
            $resourceType,
            $dateNeeded,
            $startTime,
            $endTime,
            $releasedAt
        );

        $updateRequestStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Released',
                approved_by = :approved_by,
                approved_at = :approved_at,
                due_date = :due_date,
                last_reminded_at = NULL,
                reminder_count = 0
            WHERE request_id = :request_id
              AND status = 'Approved'
            LIMIT 1
        ");
        $updateRequestStmt->execute([
            ":approved_by" => $adminUserId,
            ":approved_at" => $releasedAt,
            ":due_date" => $generatedDueDate,
            ":request_id" => $requestId
        ]);

        if ($updateRequestStmt->rowCount() !== 1) {
            throw new Exception("This request was already updated. Please refresh the page.");
        }

        refreshResourceOperationalStatus($pdo, (int) $requestRow["resource_id"]);

        addActivityLog(
            $pdo,
            $adminUserId,
            "Request Released",
            "Admin released request #{$requestId} for resource: " . ($requestRow["resource_name"] ?? "Unknown")
        );

        $releaseMessage = substr(
            "Your approved request for " . ($requestRow["resource_name"] ?? "resource") . " has been released. Due date: " . formatDateTimeDisplay($generatedDueDate) . ".",
            0,
            255
        );

        createNotification(
            $pdo,
            (int) $requestRow["borrower_id"],
            "system",
            "Request Released",
            $releaseMessage,
            "my_borrowed.php"
        );

        $currentResourceStmt = $pdo->prepare("
            SELECT available_stock
            FROM resources
            WHERE resource_id = :resource_id
            LIMIT 1
        ");
        $currentResourceStmt->execute([
            ":resource_id" => $requestRow["resource_id"]
        ]);
        $remainingStock = (int) $currentResourceStmt->fetchColumn();

        if ($resourceType === "Item" && $remainingStock <= 2) {
            $adminRows = $pdo->query("
                SELECT user_id
                FROM users
                WHERE role = 'Admin'
                  AND account_status = 'Approved'
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($adminRows as $adminRow) {
                createNotification(
                    $pdo,
                    (int) $adminRow["user_id"],
                    "system",
                    "Low Stock Alert",
                    "Resource stock is running low for " . ($requestRow["resource_name"] ?? "resource") . ".",
                    "inventory.php"
                );
            }
        }

        $pdo->commit();

        $_SESSION["flash_message"] = "Request released successfully. Due date generated: " . formatDateTimeDisplay($generatedDueDate) . ".";
        $_SESSION["flash_type"] = "success";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $_SESSION["flash_message"] = $e->getMessage();
        $_SESSION["flash_type"] = "error";
    }

    redirectRequestsPage();
}

$statusFilter = trim((string) ($_GET["status"] ?? "All"));
$search = trim((string) ($_GET["search"] ?? ""));
$page = isset($_GET["page"]) ? max(1, (int) $_GET["page"]) : 1;
$perPage = 10;

$listStmt = $pdo->query("
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
        rr.approved_at,
        rr.due_date,
        rr.return_date,
        rr.notes,
        borrower.full_name AS borrower_name,
        borrower.email AS borrower_email,
        borrower.username AS borrower_username,
        approver.full_name AS reviewed_by_name,
        r.resource_name,
        r.resource_type,
        r.category,
        r.status AS resource_status,
        r.available_stock,
        r.total_stock,
        r.condition_status,
        rs.inspection_condition,
        rs.status AS return_submission_status
    FROM resource_requests rr
    INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN return_submissions rs ON rr.request_id = rs.request_id
    ORDER BY rr.request_date DESC, rr.request_id DESC
");
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filteredRows = [];

foreach ($rows as $row) {
    $isPastFacilityUnavailable = isPastFacilityRequest($row);
    $displayStatus = $isPastFacilityUnavailable ? "Unavailable" : getRequestLifecycleStatus($row);
    $row["display_status"] = $displayStatus;
    $row["display_status_class"] = getStatusCssClass($displayStatus);
    $row["is_past_facility_unavailable"] = $isPastFacilityUnavailable;
    $row["display_status_note"] = $isPastFacilityUnavailable ? "Past facility schedule" : "";

    if ($statusFilter !== "All" && strcasecmp($displayStatus, $statusFilter) !== 0) {
        continue;
    }

    if ($search !== "") {
        $haystack = implode(" ", [
            (string) ($row["request_id"] ?? ""),
            (string) ($row["borrower_name"] ?? ""),
            (string) ($row["borrower_email"] ?? ""),
            (string) ($row["borrower_username"] ?? ""),
            (string) ($row["resource_name"] ?? ""),
            (string) ($row["category"] ?? "")
        ]);

        if (stripos($haystack, $search) === false) {
            continue;
        }
    }

    $filteredRows[] = $row;
}

usort($filteredRows, function (array $left, array $right): int {
    $priority = [
        "Pending" => 1,
        "Under Review" => 2,
        "Approved" => 3,
        "Released" => 4,
        "Unavailable" => 5,
        "Overdue" => 6,
        "Returned" => 7,
        "Damaged" => 8,
        "Rejected" => 9,
        "Cancelled" => 10
    ];

    $leftPriority = $priority[$left["display_status"] ?? "Cancelled"] ?? 99;
    $rightPriority = $priority[$right["display_status"] ?? "Cancelled"] ?? 99;

    if ($leftPriority === $rightPriority) {
        $timeCompare = strtotime((string) ($left["request_date"] ?? "")) <=> strtotime((string) ($right["request_date"] ?? ""));

        if ($timeCompare !== 0) {
            return $timeCompare;
        }

        return (int) ($left["request_id"] ?? 0) <=> (int) ($right["request_id"] ?? 0);
    }

    return $leftPriority <=> $rightPriority;
});

$totalRows = count($filteredRows);
$totalPages = max(1, (int) ceil($totalRows / $perPage));

if ($page > $totalPages) {
    $page = $totalPages;
}

$offset = ($page - 1) * $perPage;
$requests = array_slice($filteredRows, $offset, $perPage);

foreach ($requests as $requestIndex => $requestRowForQueue) {
    $requests[$requestIndex]["queue_rank"] = !empty($requestRowForQueue["is_past_facility_unavailable"])
        ? null
        : getFirstComeFirstServedQueueRank($pdo, $requestRowForQueue);
}

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$queryBase = [
    "search" => $search,
    "status" => $statusFilter
];

$facilityCalendarResources = fetchFacilityCalendarResources($pdo);
$facilityCalendarResourceId = isset($_GET["facility_calendar_resource_id"])
    ? (int) $_GET["facility_calendar_resource_id"]
    : 0;
$facilityCalendarMonth = normalizeFacilityCalendarMonth($_GET["facility_calendar_month"] ?? null);
$facilityCalendarHtml = renderFacilityAvailabilityCalendar($pdo, [
    "title" => "Facility Availability",
    "subtitle" => "Pending, approved, released, and maintenance schedules block the shown dates and times.",
    "month" => $facilityCalendarMonth,
    "resource_id" => $facilityCalendarResourceId > 0 ? $facilityCalendarResourceId : null,
    "show_borrower" => true,
    "show_resource_name" => $facilityCalendarResourceId <= 0,
    "show_resource_filter" => true,
    "resources" => $facilityCalendarResources,
    "base_url" => "requests.php",
    "query_params" => $queryBase,
    "month_param" => "facility_calendar_month",
    "resource_param" => "facility_calendar_resource_id"
]);

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
</style>

<div class="main-content">
    <?php require_once "../includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Requests</h2>
            <p>Review borrower submissions in first-come-first-served order. Older competing requests must be processed before later requests for the same resource schedule.</p>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <?php echo $facilityCalendarHtml; ?>
        </div>

        <div class="status-tabs" aria-label="Request status filters">
            <?php foreach (["All", "Pending", "Under Review", "Approved", "Released", "Unavailable", "Overdue", "Returned", "Rejected", "Cancelled"] as $statusTab): ?>
                <a
                    href="requests.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["status" => $statusTab, "page" => 1]))); ?>"
                    class="<?php echo strcasecmp($statusFilter, $statusTab) === 0 ? "active" : ""; ?>"
                >
                    <?php echo htmlspecialchars($statusTab); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search request, borrower, resource, or category..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="All" <?php echo $statusFilter === "All" ? "selected" : ""; ?>>All Status</option>
                    <option value="Pending" <?php echo $statusFilter === "Pending" ? "selected" : ""; ?>>Pending</option>
                    <option value="Under Review" <?php echo $statusFilter === "Under Review" ? "selected" : ""; ?>>Under Review</option>
                    <option value="Approved" <?php echo $statusFilter === "Approved" ? "selected" : ""; ?>>Approved</option>
                    <option value="Released" <?php echo $statusFilter === "Released" ? "selected" : ""; ?>>Released</option>
                    <option value="Unavailable" <?php echo $statusFilter === "Unavailable" ? "selected" : ""; ?>>Unavailable</option>
                    <option value="Overdue" <?php echo $statusFilter === "Overdue" ? "selected" : ""; ?>>Overdue</option>
                    <option value="Returned" <?php echo $statusFilter === "Returned" ? "selected" : ""; ?>>Returned</option>
                    <option value="Damaged" <?php echo $statusFilter === "Damaged" ? "selected" : ""; ?>>Damaged</option>
                    <option value="Rejected" <?php echo $statusFilter === "Rejected" ? "selected" : ""; ?>>Rejected</option>
                    <option value="Cancelled" <?php echo $statusFilter === "Cancelled" ? "selected" : ""; ?>>Cancelled</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Search</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table request-management-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>Request No.</th>
                            <th>Queue</th>
                            <th>Borrower</th>
                            <th>Resource</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($requests) > 0): ?>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                $borrowerCallHref = buildPhoneCallHref($request["contact_number"] ?? null);
                                $isPastFacilityUnavailable = !empty($request["is_past_facility_unavailable"]);
                                $canApproveRequest = in_array((string) ($request["status"] ?? ""), ["Pending", "Under Review"], true)
                                    && !$isPastFacilityUnavailable;
                                $canRejectRequest = in_array((string) ($request["status"] ?? ""), ["Pending", "Under Review"], true);
                                $canReleaseRequest = ((string) ($request["status"] ?? "") === "Approved")
                                    && !$isPastFacilityUnavailable;
                                ?>
                                <tr class="<?php echo $isPastFacilityUnavailable ? "request-row-unavailable" : ""; ?>">
                                    <td>REQ-<?php echo str_pad((string) $request["request_id"], 3, "0", STR_PAD_LEFT); ?></td>

                                    <td>
                                        <?php if (!empty($request["queue_rank"])): ?>
                                            <strong>#<?php echo (int) $request["queue_rank"]; ?></strong><br>
                                            <small><?php echo (int) $request["queue_rank"] === 1 ? "First in line" : "FCFS queue"; ?></small>
                                        <?php else: ?>
                                            <small>Closed</small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $request["borrower_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars((string) $request["borrower_username"]); ?>
                                            <?php if (!empty($request["contact_number"])): ?>
                                                | <?php echo htmlspecialchars((string) $request["contact_number"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong><?php echo htmlspecialchars((string) $request["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars((string) $request["resource_type"]); ?>
                                            | Qty: <?php echo htmlspecialchars((string) ($request["quantity"] ?? "N/A")); ?>
                                            <?php if (!empty($request["category"])): ?>
                                                - <?php echo htmlspecialchars((string) $request["category"]); ?>
                                            <?php endif; ?>
                                            <?php if (($request["resource_type"] ?? "") === "Item"): ?>
                                                | Stock: <?php echo htmlspecialchars((string) ($request["available_stock"] ?? "0")); ?>/<?php echo htmlspecialchars((string) ($request["total_stock"] ?? "0")); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong><?php echo htmlspecialchars(formatDateTimeDisplay($request["date_needed"] ?? "")); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars(buildTimeRangeDisplay($request["start_time"] ?? null, $request["end_time"] ?? null)); ?>
                                            | Due: <?php echo !empty($request["due_date"])
                                                ? htmlspecialchars(formatDateTimeDisplay($request["due_date"]))
                                                : "Generated on release"; ?>
                                            | Requested: <?php echo htmlspecialchars(formatDateTimeDisplay($request["request_date"] ?? "")); ?>
                                        </small>
                                    </td>

                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars((string) $request["display_status_class"]); ?>">
                                            <?php echo htmlspecialchars((string) $request["display_status"]); ?>
                                        </span>
                                        <?php if (!empty($request["display_status_note"])): ?>
                                            <br><small class="request-status-note"><?php echo htmlspecialchars((string) $request["display_status_note"]); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($request["reviewed_by_name"])): ?>
                                            <br><small>Reviewed by <?php echo htmlspecialchars((string) $request["reviewed_by_name"]); ?></small>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for request REQ-<?php echo str_pad((string) $request["request_id"], 3, "0", STR_PAD_LEFT); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <a
                                                    href="view_request.php?request_id=<?php echo (int) $request["request_id"]; ?>"
                                                    class="row-action-item"
                                                    role="menuitem"
                                                >
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                    <span>View details</span>
                                                </a>

                                                <?php if ($borrowerCallHref !== ""): ?>
                                                    <a
                                                        href="<?php echo htmlspecialchars($borrowerCallHref); ?>"
                                                        class="row-action-item"
                                                        role="menuitem"
                                                    >
                                                        <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                                        <span>Call <?php echo htmlspecialchars((string) $request["contact_number"]); ?></span>
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ($canApproveRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Approve this request?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <button type="submit" class="success-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                                                            <span>Approve</span>
                                                        </button>
                                                    </form>
                                                <?php elseif ($isPastFacilityUnavailable): ?>
                                                    <span class="row-action-note">Past facility schedule</span>
                                                <?php endif; ?>

                                                <?php if ($canRejectRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Reject this request?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="reject">
                                                        <button type="submit" class="danger-action" role="menuitem">
                                                            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i>
                                                            <span>Reject</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($canReleaseRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Release this approved request now? The return due date will be generated from the release time.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="release">
                                                        <button type="submit" role="menuitem">
                                                            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                                                            <span>Release</span>
                                                        </button>
                                                    </form>
                                                <?php elseif (($request["status"] ?? "") === "Approved" && $isPastFacilityUnavailable): ?>
                                                    <span class="row-action-note">Release blocked for past schedule</span>
                                                <?php elseif (in_array((string) ($request["display_status"] ?? ""), ["Released", "Overdue"], true)): ?>
                                                    <a href="on_loan.php" class="row-action-item" role="menuitem">
                                                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                                                        <span>Monitor borrowed</span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="row-action-note">No action available</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="empty-note">No request records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-bar">
                <div class="pagination-info">
                    Showing <?php echo count($requests); ?> of <?php echo $totalRows; ?> request(s)
                </div>

                <div class="pagination-links">
                    <?php
                    $prevPage = max(1, $page - 1);
                    $nextPage = min($totalPages, $page + 1);
                    $prevUrl = "requests.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $prevPage])));
                    $nextUrl = "requests.php?" . htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $nextPage])));
                    ?>

                    <a href="<?php echo $prevUrl; ?>" class="pagination-link <?php echo $page <= 1 ? "disabled" : ""; ?>">Previous</a>

                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i === 1 || $i === $totalPages || abs($i - $page) <= 1): ?>
                            <a
                                href="requests.php?<?php echo htmlspecialchars(http_build_query(array_merge($queryBase, ["page" => $i]))); ?>"
                                class="pagination-link <?php echo $i === $page ? "active" : ""; ?>"
                            >
                                <?php echo $i; ?>
                            </a>
                        <?php elseif ($i === 2 && $page > 4): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php elseif ($i === $totalPages - 1 && $page < $totalPages - 3): ?>
                            <span class="pagination-link disabled">...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <a href="<?php echo $nextUrl; ?>" class="pagination-link <?php echo $page >= $totalPages ? "disabled" : ""; ?>">Next</a>
                </div>
            </div>
        </div>
    </main>
</div>
