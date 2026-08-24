<?php
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Active Borrowings";
$activePage = "on_loan";
$cssFile = "../public/assets/css/pages/admin.css";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $csrfToken = $_POST["csrf_token"] ?? "";
    $requestId = isset($_POST["request_id"]) ? (int) $_POST["request_id"] : 0;
    $action = trim((string) ($_POST["action"] ?? ""));
    $adminUserId = (int) ($_SESSION["user_id"] ?? 0);

    if (!hash_equals($_SESSION["csrf_token"], $csrfToken)) {
        $_SESSION["flash_message"] = "Invalid request token.";
        $_SESSION["flash_type"] = "error";
        header("Location: on_loan.php");
        exit();
    }

    if ($requestId <= 0 || $action !== "remind_overdue") {
        $_SESSION["flash_message"] = "Invalid reminder action.";
        $_SESSION["flash_type"] = "error";
        header("Location: on_loan.php");
        exit();
    }

    $reminderStmt = $pdo->prepare("
        SELECT
            rr.request_id,
            rr.borrower_id,
            rr.contact_number,
            rr.status,
            rr.due_date,
            rr.last_reminded_at,
            rr.reminder_count,
            borrower.full_name AS borrower_name,
            r.resource_name
        FROM resource_requests rr
        INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
        INNER JOIN resources r ON rr.resource_id = r.resource_id
        WHERE rr.request_id = :request_id
          AND rr.status = 'Released'
          AND rr.return_date IS NULL
        LIMIT 1
    ");
    $reminderStmt->execute([":request_id" => $requestId]);
    $reminderRow = $reminderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$reminderRow) {
        $_SESSION["flash_message"] = "Borrowing record not found or no longer active.";
        $_SESSION["flash_type"] = "error";
        header("Location: on_loan.php");
        exit();
    }

    $dueDate = (string) ($reminderRow["due_date"] ?? "");
    if ($dueDate === "" || strtotime($dueDate) > time()) {
        $_SESSION["flash_message"] = "Reminder is available only for overdue borrowings.";
        $_SESSION["flash_type"] = "error";
        header("Location: on_loan.php");
        exit();
    }

    $lastRemindedAt = (string) ($reminderRow["last_reminded_at"] ?? "");
    if ($lastRemindedAt !== "" && strtotime($lastRemindedAt) > strtotime("-24 hours")) {
        $_SESSION["flash_message"] = "A reminder was already sent within the last 24 hours. Next reminder is available after "
            . formatDateTimeDisplay(date("Y-m-d H:i:s", strtotime($lastRemindedAt . " +24 hours"))) . ".";
        $_SESSION["flash_type"] = "error";
        header("Location: on_loan.php?loan_state=Overdue");
        exit();
    }

    $borrowNo = "BRW-" . str_pad((string) $requestId, 3, "0", STR_PAD_LEFT);
    $resourceName = (string) ($reminderRow["resource_name"] ?? "resource");
    $contactNumber = trim((string) ($reminderRow["contact_number"] ?? ""));
    $link = "my_borrowed.php?loan_state=Overdue&focus_request_id=" . $requestId;
    $notificationMessage = substr("{$borrowNo} is overdue. Please return {$resourceName} immediately.", 0, 255);

    createNotification(
        $pdo,
        (int) $reminderRow["borrower_id"],
        "OVERDUE_REMINDER",
        "Return Reminder",
        $notificationMessage,
        $link
    );

    $updateReminderStmt = $pdo->prepare("
        UPDATE resource_requests
        SET
            last_reminded_at = NOW(),
            reminder_count = COALESCE(reminder_count, 0) + 1
        WHERE request_id = :request_id
        LIMIT 1
    ");
    $updateReminderStmt->execute([":request_id" => $requestId]);

    addActivityLog(
        $pdo,
        $adminUserId,
        "Overdue Return Reminder",
        "Sent overdue reminder for {$borrowNo}. Borrower: "
            . ($reminderRow["borrower_name"] ?? "Unknown")
            . ". Contact: "
            . ($contactNumber !== "" ? $contactNumber : "No contact number")
            . "."
    );

    $_SESSION["flash_message"] = $contactNumber !== ""
        ? "Reminder sent. Borrower contact number: {$contactNumber}."
        : "Reminder sent. No contact number was recorded for this request.";
    $_SESSION["flash_type"] = "success";
    header("Location: on_loan.php?loan_state=Overdue");
    exit();
}

$search = trim($_GET["search"] ?? "");
$loanStateFilter = trim((string) ($_GET["loan_state"] ?? "All"));
if ($loanStateFilter === "On Loan") {
    $loanStateFilter = "Borrowed";
}

$sql = "
    SELECT
        rr.request_id,
        rr.borrower_id,
        rr.resource_id,
        rr.quantity,
        rr.request_date,
        rr.date_needed,
        rr.start_time,
        rr.end_time,
        rr.status,
        rr.approved_by,
        rr.approved_at,
        rr.due_date,
        rr.return_date,
        rr.contact_number,
        rr.last_reminded_at,
        rr.reminder_count,
        rr.notes,

        borrower.full_name AS borrower_name,
        borrower.email AS borrower_email,
        borrower.username AS borrower_username,

        approver.full_name AS reviewed_by_name,

        r.resource_name,
        r.resource_type,
        r.category,
        r.location,

        rs.status AS return_status
    FROM resource_requests rr
    INNER JOIN users borrower ON rr.borrower_id = borrower.user_id
    INNER JOIN resources r ON rr.resource_id = r.resource_id
    LEFT JOIN users approver ON rr.approved_by = approver.user_id
    LEFT JOIN return_submissions rs ON rs.request_id = rr.request_id
    WHERE rr.status = 'Released'
      AND rr.return_date IS NULL
      AND NOT (
            r.resource_type = 'Facility'
            AND rr.date_needed IS NOT NULL
            AND rr.start_time IS NOT NULL
            AND TIMESTAMP(rr.date_needed, rr.start_time) <= NOW()
      )
";

$params = [];

if ($search !== "") {
    $sql .= " AND (
        rr.request_id LIKE :search
        OR borrower.full_name LIKE :search
        OR borrower.username LIKE :search
        OR borrower.email LIKE :search
        OR r.resource_name LIKE :search
        OR r.resource_type LIKE :search
        OR r.category LIKE :search
        OR r.location LIKE :search
    )";
    $params[":search"] = "%" . $search . "%";
}

if ($loanStateFilter === "Overdue") {
    $sql .= " AND rr.due_date IS NOT NULL AND rr.due_date <= NOW()";
} elseif ($loanStateFilter === "Borrowed") {
    $sql .= " AND (rr.due_date IS NULL OR rr.due_date > NOW())";
}

$sql .= " ORDER BY
            CASE
                WHEN rr.due_date IS NOT NULL AND rr.due_date <= NOW() THEN 1
                ELSE 2
            END,
            rr.approved_at ASC,
            rr.request_date ASC,
            rr.request_id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$loanRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$loanStats = [
    "active" => count($loanRows),
    "overdue" => 0,
    "submitted" => 0
];

foreach ($loanRows as $loanStatRow) {
    if (getRequestLifecycleStatus($loanStatRow) === "Overdue") {
        $loanStats["overdue"]++;
    }

    if (($loanStatRow["return_status"] ?? "") === "Pending") {
        $loanStats["submitted"]++;
    }
}

$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";

unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/admin-on-loan.css"]);
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>



<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Active Borrowings</h2>
            <p>Monitor released resources in first-borrowed-first-listed order, with overdue records kept at the top.</p>
        </div>

        <div class="ui-metric-strip">
            <div class="ui-metric">
                <span>Active</span>
                <strong><?php echo (int) $loanStats["active"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Overdue</span>
                <strong><?php echo (int) $loanStats["overdue"]; ?></strong>
            </div>
            <div class="ui-metric">
                <span>Return Proofs</span>
                <strong><?php echo (int) $loanStats["submitted"]; ?></strong>
            </div>
        </div>

        <?php if (!empty($flashMessage)): ?>
            <div class="flash-message <?php echo $flashType === 'success' ? 'flash-success' : 'flash-error'; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET" class="table-toolbar">
                <input
                    type="text"
                    name="search"
                    placeholder="Search borrower, resource, category, or location..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="loan_state">
                    <option value="All" <?php echo $loanStateFilter === "All" ? "selected" : ""; ?>>All Loan States</option>
                    <option value="Borrowed" <?php echo $loanStateFilter === "Borrowed" ? "selected" : ""; ?>>Borrowed</option>
                    <option value="Overdue" <?php echo $loanStateFilter === "Overdue" ? "selected" : ""; ?>>Overdue</option>
                </select>

                <button type="submit" class="admin-btn primary-btn">Filter</button>
            </form>

            <div class="table-wrapper">
                <table class="request-table table-has-actions table-actions-menu-enhanced">
                    <thead>
                        <tr>
                            <th>Borrow No.</th>
                            <th>Borrower</th>
                            <th>Resource</th>
                            <th>Quantity</th>
                            <th>Released At</th>
                            <th>Due Date</th>
                            <th>Loan State</th>
                            <th>Reviewed By</th>
                            <th>Return Proof</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (count($loanRows) > 0): ?>
                            <?php foreach ($loanRows as $row): ?>
                                <?php
                                $displayStatus = getRequestLifecycleStatus($row);
                                $loanStateLabel = $displayStatus === "Overdue" ? "Overdue" : "Borrowed";
                                $loanStateClass = $displayStatus === "Overdue" ? "overdue" : "borrowed";
                                $contactNumber = trim((string) ($row["contact_number"] ?? ""));
                                $phoneHref = buildPhoneCallHref($contactNumber);

                                $returnStatus = (string)($row["return_status"] ?? "");
                                $returnProofLabel = $returnStatus === "Pending"
                                    ? "Submitted"
                                    : ($returnStatus === "Rejected" ? "Rejected" : "None");
                                ?>
                                <tr>
                                    <td>BRW-<?php echo str_pad((string)$row["request_id"], 3, "0", STR_PAD_LEFT); ?></td>

                                    <td>
                                        <strong><?php echo htmlspecialchars($row["borrower_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars($row["borrower_username"]); ?>
                                            <?php if (!empty($row["contact_number"])): ?>
                                                | <?php echo htmlspecialchars($row["contact_number"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td>
                                        <strong><?php echo htmlspecialchars($row["resource_name"]); ?></strong><br>
                                        <small>
                                            <?php echo htmlspecialchars($row["resource_type"]); ?>
                                            <?php if (!empty($row["category"])): ?>
                                                - <?php echo htmlspecialchars($row["category"]); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($row["location"])): ?>
                                                - <?php echo htmlspecialchars($row["location"]); ?>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <td><?php echo htmlspecialchars($row["quantity"] ?? "N/A"); ?></td>

                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($row["approved_at"] ?? "")); ?></td>
                                    <td><?php echo htmlspecialchars(formatDateTimeDisplay($row["due_date"] ?? "")); ?></td>

                                    <td>
                                        <span class="status-badge <?php echo htmlspecialchars($loanStateClass); ?>">
                                            <?php echo htmlspecialchars($loanStateLabel); ?>
                                        </span>

                                        <?php if ($loanStateLabel === "Overdue"): ?>
                                            <?php if ($phoneHref !== ""): ?>
                                                <br>
                                                <a href="<?php echo htmlspecialchars($phoneHref); ?>" class="overdue-call-link">
                                                    <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                                    Call overdue borrower
                                                </a>
                                            <?php else: ?>
                                                <br>
                                                <span class="overdue-call-missing">No contact number recorded</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php echo !empty($row["reviewed_by_name"]) ? htmlspecialchars($row["reviewed_by_name"]) : "N/A"; ?>
                                    </td>

                                    <td><?php echo htmlspecialchars($returnProofLabel); ?></td>

                                    <td>
                                        <div class="row-actions">
                                            <button
                                                type="button"
                                                class="row-actions-trigger"
                                                data-row-actions-toggle
                                                aria-expanded="false"
                                                aria-label="Open actions for borrow record BRW-<?php echo str_pad((string)$row["request_id"], 3, "0", STR_PAD_LEFT); ?>"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i>
                                            </button>

                                            <div class="row-actions-menu" role="menu">
                                                <a
                                                    href="view_request.php?request_id=<?php echo (int)$row["request_id"]; ?>"
                                                    class="row-action-item"
                                                    role="menuitem"
                                                >
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                    <span>View details</span>
                                                </a>

                                                <?php if ($contactNumber !== "" && $phoneHref !== ""): ?>
                                                    <a
                                                        href="<?php echo htmlspecialchars($phoneHref); ?>"
                                                        class="row-action-item"
                                                        role="menuitem"
                                                    >
                                                        <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                                        <span>
                                                            <?php echo $loanStateLabel === "Overdue"
                                                                ? "Call overdue borrower"
                                                                : "Call " . htmlspecialchars($contactNumber); ?>
                                                        </span>
                                                    </a>
                                                <?php elseif ($loanStateLabel === "Overdue"): ?>
                                                    <span class="row-action-note">
                                                        <i class="fa-solid fa-phone-slash" aria-hidden="true"></i>
                                                        No contact number recorded
                                                    </span>
                                                <?php endif; ?>

                                                <?php if ($loanStateLabel === "Overdue"): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Send overdue return reminder to this borrower?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo (int) $row["request_id"]; ?>">
                                                        <input type="hidden" name="action" value="remind_overdue">
                                                        <button type="submit" class="warning-action" role="menuitem">
                                                            <i class="fa-solid fa-bell" aria-hidden="true"></i>
                                                            <span>Remind borrower</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($returnStatus === "Pending"): ?>
                                                    <a
                                                        href="review_return.php?request_id=<?php echo (int)$row["request_id"]; ?>"
                                                        class="row-action-item success-action"
                                                        role="menuitem"
                                                    >
                                                        <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                                                        <span>Review return</span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="row-action-note">Waiting for return proof</span>
                                                <?php endif; ?>

                                                <?php if (!empty($row["last_reminded_at"])): ?>
                                                    <span class="row-action-note">
                                                        Last reminded <?php echo htmlspecialchars(formatDateTimeDisplay($row["last_reminded_at"])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="empty-note">No released borrowing records found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>
        </div>
    </main>
</div>
