<?php
require_once dirname(__DIR__,3)."/includes/client_notification_helper.php";
define("GSO_REQUIRED_PERMISSION", "request.view_queue");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/notification_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/maintenance_helper.php";
require_once dirname(__DIR__, 3) . "/includes/facility_calendar_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_attachment_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_line_helper.php";
require_once dirname(__DIR__, 3) . "/includes/equipment_unit_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Requests";
$activePage = "requests";
$cssFile = "../public/assets/css/pages/admin.css";

function redirectRequestsPage(): void
{
    global $pdo;
    if ($pdo->inTransaction()) $pdo->rollBack();
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

    if ($requestId <= 0 || !in_array($action, ["review", "approve", "reject", "release"], true)) {
        $_SESSION["flash_message"] = "Invalid request action.";
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    $checkStmt = $pdo->prepare("
        SELECT
            rr.request_id,
            rr.borrower_id, rr.requester_type, rr.walkin_organization, rr.encoded_by,
            rr.contact_number,
            rr.date_needed,
            rr.start_time,
        (SELECT MAX(sp.facility_slot) FROM request_items sp WHERE sp.request_id=rr.request_id) AS facility_slot,
            rr.end_time,
            rr.request_date,
            rr.status,
            rr.due_date,
            rr.return_date,
            rr.notes,
            " . gsoRequestLineSummarySql("rr") . " AS line_summary,
            " . gsoRequesterSql("account_type","rr","borrower") . " AS borrower_account_type
        FROM resource_requests rr
        LEFT JOIN users borrower ON borrower.user_id = rr.borrower_id
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
    $requestSummary = (string) ($requestRow["line_summary"] ?? "requested resources");

    $dateNeeded = $requestRow["date_needed"] ?? null;
    $startTime = $requestRow["start_time"] ?? null;
    $endTime = $requestRow["end_time"] ?? null;
    $scheduleDueDate = null;

    if ($action === "review") {
        if ((string) ($requestRow["status"] ?? "") !== "Pending") {
            $_SESSION["flash_message"] = "Only pending requests can be moved under review.";
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $updateStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Under Review',
                reviewed_by = :reviewed_by,
                reviewed_at = NOW()
            WHERE request_id = :request_id
              AND status = 'Pending'
            LIMIT 1
        ");
        $success = $updateStmt->execute([
            ":reviewed_by" => $adminUserId,
            ":request_id" => $requestId,
        ]) && $updateStmt->rowCount() === 1;

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Request Under Review",
                "Admin started reviewing request #{$requestId} for: {$requestSummary}"
            );
            createNotification(
                $pdo,
                (int) $requestRow["borrower_id"],
                "request_review",
                "Request Under Review",
                "Your request for {$requestSummary} is now under GSO review.",
                "my_requests.php"
            );
        }

        $_SESSION["flash_message"] = $success ? "Request moved under review." : "Failed to update the request.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectRequestsPage();
    }

    if ($action === "reject") {
        if (!in_array((string) ($requestRow["status"] ?? ""), ["Pending", "Under Review"], true)) {
            $_SESSION["flash_message"] = "Only pending or under review requests can be rejected.";
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

        $pdo->beginTransaction();
        $updateStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Rejected',
                reviewed_by = :reviewed_by,
                reviewed_at = NOW(),
                approved_by = NULL,
                approved_at = NULL
            WHERE request_id = :request_id
              AND status IN ('Pending', 'Under Review')
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":reviewed_by" => $adminUserId,
            ":request_id" => $requestId
        ]) && $updateStmt->rowCount() === 1;

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Request Rejected",
                "Admin rejected request #{$requestId} for: {$requestSummary}"
            );

        }

        $event=$success?gsoPrepareClientEvent($pdo,$requestId,'rejected'):null;
        $pdo->commit();
        gsoDeliverClientEvent($pdo,$event);
        $_SESSION["flash_message"] = $success ? "Request rejected successfully." : "Failed to reject request.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectRequestsPage();
    }

    if ($action === "approve") {
        try {
        $pdo->beginTransaction();
        $lock=$pdo->prepare("SELECT status FROM resource_requests WHERE request_id=? FOR UPDATE");$lock->execute([$requestId]);
        $requestRow["status"]=$lock->fetchColumn();
        gsoLockRequestSchedulingResources($pdo,$requestId);
        $facilityLine=gsoRequestFacilityLine($pdo,$requestId);
        $extendedHoursRemarks=trim((string)($_POST["extended_hours_remarks"]??""));
        if($facilityLine && $facilityLine["facility_slot"]!==null) {
            $periodError=gsoFacilityPeriodError($facilityLine["facility_slot"],$requestRow["start_time"],$requestRow["end_time"]);
            if($periodError!==null)throw new DomainException($periodError);
            $hoursError=gsoFacilitySubmissionHoursError($pdo,$requestRow["start_time"],$requestRow["end_time"],true);
            if($hoursError!==null)throw new DomainException($hoursError);
            if(gsoFacilityNeedsExtendedHours($pdo,$requestRow["start_time"],$requestRow["end_time"]) && (($_POST["allow_extended_hours"]??"")!=="1"||$extendedHoursRemarks===""||mb_strlen($extendedHoursRemarks)>120))throw new DomainException("Explicit GSO extended-hours authorization and audit remarks (up to 120 characters) are required before approving this schedule.");
        }
        if (!gsoRequestLetterAllowsApproval($pdo,$requestId)) {
            $_SESSION["flash_message"]="GSO must verify the current signed facility request letter before approving this request.";
            $_SESSION["flash_type"]="error";redirectRequestsPage();
        }
        $lineError = gsoRequestLineAvailabilityError($pdo, $requestRow);

        if ($lineError !== null) {
            $_SESSION["flash_message"] = $lineError;
            $_SESSION["flash_type"] = "error";
            redirectRequestsPage();
        }

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

        $updateStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = 'Approved',
                reviewed_by = :reviewed_by,
                reviewed_at = NOW(),
                approved_by = :approved_by,
                approved_at = NOW()
            WHERE request_id = :request_id
              AND status IN ('Pending', 'Under Review')
            LIMIT 1
        ");

        $success = $updateStmt->execute([
            ":reviewed_by" => $adminUserId,
            ":approved_by" => $adminUserId,
            ":request_id" => $requestId
        ]) && $updateStmt->rowCount() === 1;

        if ($success) {
            addActivityLog(
                $pdo,
                $adminUserId,
                "Request Approved",
                "Admin approved request #{$requestId} for: {$requestSummary}"
            );

        }

        if($success && $facilityLine && $facilityLine["facility_slot"]!==null && gsoFacilityNeedsExtendedHours($pdo,$requestRow["start_time"],$requestRow["end_time"])) {
            if(!addActivityLog($pdo,$adminUserId,"Extended Facility Hours Authorized","Request #{$requestId}; {$dateNeeded}; ".gsoRequestScheduleDisplay($requestRow)."; GSO authorization: ".$extendedHoursRemarks))throw new RuntimeException("Extended-hours authorization audit could not be recorded.");
        }
        $event=$success?gsoPrepareClientEvent($pdo,$requestId,'approved'):null;
        $pdo->commit();
        gsoDeliverClientEvent($pdo,$event);
        $_SESSION["flash_message"] = $success ? "Request approved successfully." : "Failed to approve request.";
        $_SESSION["flash_type"] = $success ? "success" : "error";
        redirectRequestsPage();
        }catch(Throwable $approvalError){
            if($pdo->inTransaction())$pdo->rollBack();
            $_SESSION["flash_message"]=$approvalError instanceof DomainException?$approvalError->getMessage():"Approval could not be saved. Refresh and retry.";$_SESSION["flash_type"]="error";redirectRequestsPage();
        }
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

    $lineError = gsoRequestLineAvailabilityError($pdo, $requestRow);
    if ($lineError !== null) {
        $_SESSION["flash_message"] = $lineError;
        $_SESSION["flash_type"] = "error";
        redirectRequestsPage();
    }

    try {
        $pdo->beginTransaction();

        $lockStmt = $pdo->prepare("
            SELECT
                rr.request_id,
                rr.borrower_id, rr.requester_type, rr.walkin_organization, rr.encoded_by,
                rr.contact_number,
                rr.date_needed,
                rr.start_time,
        (SELECT MAX(sp.facility_slot) FROM request_items sp WHERE sp.request_id=rr.request_id) AS facility_slot,
                rr.end_time,
                rr.request_date,
                rr.status,
                rr.due_date,
                rr.return_date,
                rr.notes,
                " . gsoRequestLineSummarySql("rr") . " AS line_summary
            FROM resource_requests rr
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

        // Phase 3.5A: serialized allocation, consumable issuance and per-line
        // return inspection are Phase 3.5B. Rather than let those request
        // shapes through the single-resource release path - which would deduct
        // stock for one line and mishandle the rest - the whole release is
        // refused before anything is mutated.
        $fulfilmentBlocker = gsoRequestFulfilmentBlocker($pdo, $requestId);

        if ($fulfilmentBlocker !== null) {
            throw new Exception($fulfilmentBlocker);
        }

        // Lines are authoritative for what is actually handed over. Past this
        // gate exactly one returnable line remains, so the deduction below
        // operates on it rather than on the legacy header mirror.
        $releaseLinesStmt = $pdo->prepare("
            SELECT ri.request_item_id, ri.resource_id, ri.line_kind, ri.requested_quantity,
                   r.resource_type, r.tracking_mode
            FROM request_items ri
            INNER JOIN resources r ON r.resource_id = ri.resource_id
            WHERE ri.request_id = :request_id
            ORDER BY ri.request_item_id
            FOR UPDATE
        ");
        $releaseLinesStmt->execute([":request_id" => $requestId]);
        $releaseLines = $releaseLinesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($releaseLines === []) {
            throw new Exception("This request has no resource lines and cannot be released.");
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
        $dateNeeded = $requestRow["date_needed"] ?? null;
        $startTime = $requestRow["start_time"] ?? null;
        $endTime = $requestRow["end_time"] ?? null;

        // Phase 3.5B: every line of the request is fulfilled inside this one
        // transaction. The whole release is validated before any mutation, and
        // any single failure throws, so the enclosing rollback guarantees no
        // stock is deducted, no consumable is issued and no unit allocation
        // survives while another line went unfulfilled.
        $selectedUnits = (array) ($_POST["unit_ids"] ?? []);
        $lineResourceTypes = [];
        $lineIsFacility = false;

        foreach ($releaseLines as $releaseLine) {
            $lineId = (int) $releaseLine["request_item_id"];
            $lineResourceId = (int) $releaseLine["resource_id"];
            $lineKind = (string) $releaseLine["line_kind"];
            $lineQuantity = max(1, (int) $releaseLine["requested_quantity"]);
            $lineResourceTypes[] = (string) $releaseLine["resource_type"];

            $resourceStmt = $pdo->prepare("
                SELECT resource_id, resource_name, resource_type, tracking_mode, category,
                       total_stock, available_stock, under_repair_stock, capacity,
                       status, condition_status, is_archived
                FROM resources WHERE resource_id = :resource_id FOR UPDATE
            ");
            $resourceStmt->execute([":resource_id" => $lineResourceId]);
            $lineResource = $resourceStmt->fetch(PDO::FETCH_ASSOC);

            if (!$lineResource) {
                throw new Exception("A requested resource no longer exists.");
            }

            $lineName = (string) $lineResource["resource_name"];

            if ((int) ($lineResource["is_archived"] ?? 0) === 1) {
                throw new Exception("{$lineName} is archived and cannot be released.");
            }

            if ((string) $lineResource["status"] !== "Available") {
                throw new Exception("{$lineName} is not available for release.");
            }

            // Schedule and stock availability are revalidated per line through
            // the shared service, which already reads request lines.
            $availability = checkResourceAvailability(
                $pdo,
                $lineResource,
                $lineKind === "Facility" ? 1 : $lineQuantity,
                $dateNeeded,
                $startTime,
                $endTime,
                $scheduleDueDate,
                $requestId
            );

            if (!($availability["ok"] ?? false)) {
                throw new Exception($lineName . ": " . (string) ($availability["message"] ?? "no longer available."));
            }

            if ($lineKind === "Facility") {
                // A room is not an equipment unit and holds no stock; the line
                // is simply recorded as released and keeps its return duty.
                $lineIsFacility = true;
                $pdo->prepare("UPDATE request_items SET released_quantity = 1 WHERE request_item_id = :id")
                    ->execute([":id" => $lineId]);
                continue;
            }

            if ((string) $lineResource["condition_status"] !== "Good") {
                throw new Exception("{$lineName} cannot be released because its condition is not good.");
            }

            if ($lineKind === "Consumable") {
                // Issuance is the moment stock leaves permanently: both the
                // available and the total counters drop, because the material
                // is never coming back.
                $availableStock = (int) ($lineResource["available_stock"] ?? 0);

                if ($availableStock < $lineQuantity) {
                    throw new Exception("Not enough {$lineName} in stock to issue.");
                }

                $pdo->prepare("
                    UPDATE resources
                    SET available_stock = available_stock - :qty,
                        total_stock = GREATEST(0, total_stock - :qty2)
                    WHERE resource_id = :resource_id
                      AND available_stock >= :qty3
                ")->execute([
                    ":qty" => $lineQuantity,
                    ":qty2" => $lineQuantity,
                    ":qty3" => $lineQuantity,
                    ":resource_id" => $lineResourceId,
                ]);

                $pdo->prepare("
                    UPDATE request_items
                    SET issued_quantity = :qty, released_quantity = :qty2
                    WHERE request_item_id = :id
                ")->execute([":qty" => $lineQuantity, ":qty2" => $lineQuantity, ":id" => $lineId]);
                continue;
            }

            if ((string) $lineResource["tracking_mode"] === "Serialized") {
                // The operator hands over specific physical units. Each unit is
                // claimed with a conditional update plus the open-allocation
                // unique index, so a concurrent release cannot take the same
                // unit twice and a forged or foreign unit id cannot be used.
                $chosen = array_values(array_unique(array_map(
                    "intval",
                    (array) ($selectedUnits[$lineId] ?? [])
                )));

                if (count($chosen) !== $lineQuantity) {
                    throw new Exception(
                        "Select exactly {$lineQuantity} unit(s) of {$lineName} to issue."
                    );
                }

                foreach ($chosen as $unitId) {
                    $unitStmt = $pdo->prepare("
                        SELECT unit_id, resource_id, status, condition_status, asset_tag
                        FROM equipment_units WHERE unit_id = :unit_id FOR UPDATE
                    ");
                    $unitStmt->execute([":unit_id" => $unitId]);
                    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

                    if (!$unit || (int) $unit["resource_id"] !== $lineResourceId) {
                        throw new Exception("A selected unit does not belong to {$lineName}.");
                    }

                    if ((string) $unit["status"] !== "Available"
                        || (string) $unit["condition_status"] !== "Good"
                    ) {
                        throw new Exception("Unit {$unit['asset_tag']} is not available for release.");
                    }

                    $unitRange=buildRequestDateRange('Item',$dateNeeded,$startTime,$endTime,$requestRow['due_date']??null);
                    if($unitRange&&gsoPreventiveUnitConflict($pdo,$unitId,$unitRange['start'],$unitRange['end']))throw new DomainException("This physical unit has overlapping preventive maintenance. Select an unaffected unit.");
                    $claim = $pdo->prepare("
                        UPDATE equipment_units
                        SET status = 'Released', updated_at = NOW()
                        WHERE unit_id = :unit_id AND status = 'Available' AND condition_status = 'Good'
                    ");
                    $claim->execute([":unit_id" => $unitId]);

                    if ($claim->rowCount() !== 1) {
                        throw new Exception("Unit {$unit['asset_tag']} was taken by another release.");
                    }

                    $pdo->prepare("
                        INSERT INTO request_item_units
                            (request_item_id, unit_id, allocated_by, released_at)
                        VALUES (:line_id, :unit_id, :actor, NOW())
                    ")->execute([
                        ":line_id" => $lineId,
                        ":unit_id" => $unitId,
                        ":actor" => $adminUserId,
                    ]);
                }

                $pdo->prepare("UPDATE request_items SET released_quantity = :qty WHERE request_item_id = :id")
                    ->execute([":qty" => $lineQuantity, ":id" => $lineId]);

                // Counters are rebuilt from the unit rows rather than adjusted,
                // so no arithmetic slip can double-deduct.
                gsoRecalculateSerializedStock($pdo, $lineResourceId);
                continue;
            }

            // Aggregate equipment: deduct exactly what is handed over.
            $availableStock = (int) ($lineResource["available_stock"] ?? 0);

            if ($availableStock < $lineQuantity) {
                throw new Exception("Not enough {$lineName} in stock for release.");
            }

            $deduct = $pdo->prepare("
                UPDATE resources
                SET available_stock = available_stock - :qty
                WHERE resource_id = :resource_id AND available_stock >= :qty2
            ");
            $deduct->execute([
                ":qty" => $lineQuantity,
                ":qty2" => $lineQuantity,
                ":resource_id" => $lineResourceId,
            ]);

            if ($deduct->rowCount() !== 1) {
                throw new Exception("{$lineName} stock changed during release.");
            }

            $pdo->prepare("UPDATE request_items SET released_quantity = :qty WHERE request_item_id = :id")
                ->execute([":qty" => $lineQuantity, ":id" => $lineId]);
        }

        // The loan window follows the facility when one is present, otherwise
        // the equipment default, preserving the existing due-date behaviour.
        $resourceType = $lineIsFacility ? "Facility" : "Item";
        $releasedAt = date("Y-m-d H:i:s");
        $generatedDueDate = generateLoanDueDate(
            $resourceType,
            $dateNeeded,
            $startTime,
            $endTime,
            $releasedAt
        );

        // A request with nothing returnable - consumables only - is finished the
        // moment its materials are issued. Calling that 'Returned' would claim
        // goods came back that never can, so it goes straight to 'Completed'
        // and never waits for a return submission.
        $returnableLineCount = 0;

        foreach ($releaseLines as $releaseLine) {
            if ((string) $releaseLine["line_kind"] !== "Consumable") {
                $returnableLineCount++;
            }
        }

        $releaseTerminalStatus = $returnableLineCount === 0 ? "Completed" : "Released";

        $updateRequestStmt = $pdo->prepare("
            UPDATE resource_requests
            SET
                status = :terminal_status,
                released_by = :released_by,
                released_at = :released_at,
                due_date = :due_date,
                last_reminded_at = NULL,
                reminder_count = 0
            WHERE request_id = :request_id
              AND status = 'Approved'
            LIMIT 1
        ");
        $updateRequestStmt->execute([
            ":terminal_status" => $releaseTerminalStatus,
            ":released_by" => $adminUserId,
            ":released_at" => $releasedAt,
            ":due_date" => $generatedDueDate,
            ":request_id" => $requestId
        ]);

        // The status predicate is still 'Approved', so a repeated POST or a
        // concurrent release affects zero rows and the whole transaction is
        // rolled back - no duplicate issuance, no second unit allocation.
        if ($updateRequestStmt->rowCount() !== 1) {
            throw new Exception("This request was already updated. Please refresh the page.");
        }

        foreach ($releaseLines as $releaseLine) {
            refreshResourceOperationalStatus($pdo, (int) $releaseLine["resource_id"]);
        }

        addActivityLog(
            $pdo,
            $adminUserId,
            "Request Released",
            "Admin released request #{$requestId} for: " . ($requestRow["line_summary"] ?? "requested resources")
        );

        $lowStockLines = [];
        $stockStmt = $pdo->prepare("SELECT resource_name, available_stock FROM resources WHERE resource_id = :resource_id");
        foreach ($releaseLines as $releaseLine) {
            if ((string) $releaseLine["resource_type"] === "Facility") {
                continue;
            }
            $stockStmt->execute([":resource_id" => (int) $releaseLine["resource_id"]]);
            $stockRow = $stockStmt->fetch(PDO::FETCH_ASSOC);
            if ($stockRow && (int) $stockRow["available_stock"] <= 2) {
                $lowStockLines[] = $stockRow;
            }
        }

        if ($lowStockLines !== []) {
            $adminRows = $pdo->query("
                SELECT user_id
                FROM users
                WHERE role = 'Admin'
                  AND account_status = 'Approved'
            ")->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($lowStockLines as $lowStockLine) {
                foreach ($adminRows as $adminRow) {
                    createNotification(
                        $pdo,
                        (int) $adminRow["user_id"],
                        "system",
                        "Low Stock Alert",
                        "Resource stock is running low for " . $lowStockLine["resource_name"] . ".",
                        "inventory.php"
                    );
                }
            }
        }

        $event=gsoPrepareClientEvent($pdo,$requestId,'released');
        $pdo->commit();
        gsoDeliverClientEvent($pdo,$event);

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
        rr.borrower_id, rr.requester_type, rr.walkin_organization, rr.encoded_by,
        rr.contact_number,
        rr.date_needed,
        rr.start_time,
        (SELECT MAX(sp.facility_slot) FROM request_items sp WHERE sp.request_id=rr.request_id) AS facility_slot,
        rr.end_time,
        rr.request_date,
        rr.status,
        rr.reviewed_at,
        rr.approved_at,
        rr.released_at,
        rr.due_date,
        rr.return_date,
        rr.notes,
        " . gsoRequesterSql("full_name","rr","borrower") . " AS borrower_name,
        " . gsoRequesterSql("email","rr","borrower") . " AS borrower_email,
        " . gsoRequesterSql("username","rr","borrower") . " AS borrower_username,
        " . gsoRequesterSql("account_type","rr","borrower") . " AS borrower_account_type,
        reviewer.full_name AS reviewed_by_name,
        (SELECT GROUP_CONCAT(CONCAT(lr.resource_name, CASE WHEN li.line_kind = 'Facility' THEN '' ELSE CONCAT(' x', li.requested_quantity) END) ORDER BY FIELD(li.line_kind,'Facility','Equipment','Consumable'), lr.resource_name SEPARATOR ', ') FROM request_items li INNER JOIN resources lr ON lr.resource_id = li.resource_id WHERE li.request_id = rr.request_id) AS line_summary,
        (SELECT COUNT(*) FROM request_items lc WHERE lc.request_id = rr.request_id) AS line_count,
        (SELECT GROUP_CONCAT(DISTINCT lr.category ORDER BY lr.category SEPARATOR ', ') FROM request_items li INNER JOIN resources lr ON lr.resource_id=li.resource_id WHERE li.request_id=rr.request_id) AS category,
        CASE WHEN EXISTS(SELECT 1 FROM request_items fi WHERE fi.request_id=rr.request_id AND fi.line_kind='Facility') THEN 'Facility' ELSE 'Item' END AS resource_type,
        rs.inspection_condition,
        rs.status AS return_submission_status
    FROM resource_requests rr
    LEFT JOIN users borrower ON rr.borrower_id = borrower.user_id
    LEFT JOIN users reviewer ON rr.reviewed_by = reviewer.user_id
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
            (string) ($row["line_summary"] ?? ""),
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
        "Return" => 5,
        "Completed" => 7,
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
    "subtitle" => "Confirmed reservations block their exact intervals. Pending review does not reserve a facility; whole-day maintenance blocks both periods.",
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

$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/admin-requests.css"]);
require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>



<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Requests</h2>
            <p>GSO approval confirms a facility reservation after a fresh exact-time availability check. Pending facility requests may overlap; equipment processing retains its stock and queue protections.</p>
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
            <?php foreach (["All", "Pending", "Under Review", "Approved", "Released", "Return", "Completed", "Unavailable", "Overdue", "Returned", "Rejected", "Cancelled"] as $statusTab): ?>
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
                    <option value="Return" <?php echo $statusFilter === "Return" ? "selected" : ""; ?>>Return</option>
                    <option value="Completed" <?php echo $statusFilter === "Completed" ? "selected" : ""; ?>>Completed</option>
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
                                $canReviewRequest = ((string) ($request["status"] ?? "") === "Pending");
                                $canApproveRequest = in_array((string) ($request["status"] ?? ""), ["Pending", "Under Review"], true)
                                    && !$isPastFacilityUnavailable;
                                $canRejectRequest = in_array((string) ($request["status"] ?? ""), ["Pending", "Under Review"], true);
                                $canReleaseRequest = ((string) ($request["status"] ?? "") === "Approved")
                                    && !$isPastFacilityUnavailable;
                                if ($canApproveRequest && !gsoRequestLetterAllowsApproval($pdo,(int)$request["request_id"])) $canApproveRequest=false;
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
                                        <strong><?php echo htmlspecialchars((string) ($request["line_summary"] ?? "Requested resources")); ?></strong><?php if ((int) ($request["line_count"] ?? 1) > 1): ?> <span class="status-badge underreview"><?php echo (int) $request["line_count"]; ?> resources</span><?php endif; ?><br>
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
                                            <?php echo htmlspecialchars(gsoRequestScheduleDisplay($request)); ?>
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

                                                <?php if ($canReviewRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Move this request under review?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="review">
                                                        <button type="submit" role="menuitem">
                                                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                                                            <span>Start review</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>



                                                <?php if ($canApproveRequest): ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Approve this request?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="approve">
                                                        <?php if(($request["facility_slot"]??null)!==null && gsoFacilityNeedsExtendedHours($pdo,$request["start_time"],$request["end_time"])):?>
                                                        <label><input type="checkbox" name="allow_extended_hours" value="1" required> Authorize extended facility hours</label><label>GSO authorization remarks<textarea name="extended_hours_remarks" maxlength="120" required></textarea></label>
                                                        <?php endif;?>
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
                                                    <?php
                                                    // Serialized lines need the operator to name the exact
                                                    // physical units being handed over. Only units that are
                                                    // genuinely free are offered, and the release transaction
                                                    // revalidates every choice against locked rows anyway.
                                                    $releaseSelectionLines = gsoRequestLines($pdo, (int) $request["request_id"]);
                                                    $serializedReleaseLines = array_values(array_filter(
                                                        $releaseSelectionLines,
                                                        static fn(array $l): bool => (string) $l["tracking_mode"] === "Serialized"
                                                    ));
                                                    ?>
                                                    <form method="POST" class="inline-form" onsubmit="return confirm('Release this approved request now? The return due date will be generated from the release time.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                        <input type="hidden" name="request_id" value="<?php echo htmlspecialchars((string) $request["request_id"]); ?>">
                                                        <input type="hidden" name="action" value="release">
                                                        <?php foreach ($serializedReleaseLines as $serializedLine): ?>
                                                            <?php
                                                            $serializedLineId = (int) $serializedLine["request_item_id"];
                                                            $freeUnits = gsoAvailableUnitsForResource($pdo, (int) $serializedLine["resource_id"]);
                                                            ?>
                                                            <div class="release-unit-picker">
                                                                <strong><?php echo htmlspecialchars((string) $serializedLine["resource_name"]); ?></strong>
                                                                <small>select <?php echo (int) $serializedLine["requested_quantity"]; ?></small>
                                                                <?php foreach ($freeUnits as $freeUnit): ?>
                                                                    <label>
                                                                        <input
                                                                            type="checkbox"
                                                                            name="unit_ids[<?php echo $serializedLineId; ?>][]"
                                                                            value="<?php echo (int) $freeUnit["unit_id"]; ?>"
                                                                        >
                                                                        <?php echo htmlspecialchars((string) $freeUnit["asset_tag"]); ?>
                                                                    </label>
                                                                <?php endforeach; ?>
                                                                <?php if (!$freeUnits): ?>
                                                                    <em>No units available.</em>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <button type="submit" role="menuitem">
                                                            <i class="fa-solid fa-box-open" aria-hidden="true"></i>
                                                            <span>Release</span>
                                                        </button>
                                                    </form>
                                                <?php elseif (($request["status"] ?? "") === "Approved" && $isPastFacilityUnavailable): ?>
                                                    <span class="row-action-note">Release blocked for past schedule</span>
                                                <?php elseif (in_array((string) ($request["display_status"] ?? ""), ["Released", "Return", "Overdue"], true)): ?>
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
