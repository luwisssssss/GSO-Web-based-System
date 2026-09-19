<?php
// Individual equipment unit registry. Inventory management is a GSO duty, so
// this screen sits behind the existing inventory permission: Borrowers and the
// Campus Director have no access to asset mutations.
define("GSO_REQUIRED_PERMISSION", "inventory.view");
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/activity_log_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/request_line_helper.php";
require_once dirname(__DIR__, 3) . "/includes/equipment_unit_helper.php";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$pageTitle = "Equipment Units";
$activePage = "inventory";
$cssFile = "../public/assets/css/pages/admin.css";

$actorId = (int) ($_SESSION["user_id"] ?? 0);
$resourceId = (int) ($_GET["resource_id"] ?? ($_POST["resource_id"] ?? 0));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!hash_equals((string) $_SESSION["csrf_token"], (string) ($_POST["csrf_token"] ?? ""))) {
        redirectWithFlash("equipment_units.php?resource_id={$resourceId}", "Invalid request token.");
    }

    $action = (string) ($_POST["action"] ?? "");
    $result = ["ok" => false, "message" => "Unsupported action."];

    if ($action === "register_unit") {
        $result = gsoRegisterEquipmentUnit(
            $pdo,
            $resourceId,
            (string) ($_POST["asset_tag"] ?? ""),
            (string) ($_POST["serial_number"] ?? ""),
            $actorId,
            (string) ($_POST["acquired_at"] ?? ""),
            trim((string) ($_POST["unit_notes"] ?? ""))
        );

        if ($result["ok"]) {
            addActivityLog($pdo, $actorId, "Equipment Unit Registered", $result["message"]);
        }
    } elseif ($action === "update_unit") {
        $result = gsoUpdateEquipmentUnitStatus(
            $pdo,
            (int) ($_POST["unit_id"] ?? 0),
            (string) ($_POST["status"] ?? ""),
            (string) ($_POST["condition_status"] ?? ""),
            trim((string) ($_POST["unit_notes"] ?? "")),
            $actorId
        );

        if ($result["ok"]) {
            addActivityLog($pdo, $actorId, "Equipment Unit Updated", "Unit #" . (int) ($_POST["unit_id"] ?? 0) . " updated.");
        }
    } elseif ($action === "activate_serialized") {
        $result = gsoActivateSerializedTracking($pdo, $resourceId, $actorId);

        if ($result["ok"]) {
            addActivityLog(
                $pdo,
                $actorId,
                "Serialized Tracking Activated",
                "Resource #{$resourceId} now uses individual unit tracking."
            );
        }
    }

    redirectWithFlash(
        "equipment_units.php?resource_id={$resourceId}",
        (string) $result["message"],
        !empty($result["ok"]) ? "success" : "error"
    );
}

$trackableStmt = $pdo->query("
    SELECT resource_id, resource_name, category, tracking_mode,
           total_stock, available_stock, under_repair_stock
    FROM resources
    WHERE resource_type = 'Item' AND is_archived = 0 AND tracking_mode <> 'Consumable'
    ORDER BY tracking_mode DESC, resource_name ASC
");
$trackableResources = $trackableStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selected = null;
$units = [];
$readiness = null;

if ($resourceId > 0) {
    $selected = gsoFetchRequestableResource($pdo, $resourceId);

    if ($selected !== null) {
        $units = gsoResourceUnits($pdo, $resourceId);
        $readiness = gsoSerializedActivationReadiness($pdo, $resourceId);
    }
}

$mismatches = gsoSerializedStockMismatches($pdo);
$flashMessage = $_SESSION["flash_message"] ?? "";
$flashType = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/admin_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/admin_topbar.php"; ?>

    <main class="page-content">
        <div class="card">
            <h2>Equipment Units</h2>
            <p>
                Register the real asset tag of each physical unit. A resource only
                switches to individual tracking once its registered units reconcile
                with the catalogue, including anything currently on loan.
            </p>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>">
                <?php echo htmlspecialchars($flashMessage); ?>
            </div>
        <?php endif; ?>

        <?php if ($mismatches): ?>
            <div class="card">
                <h3 class="section-title">Reconciliation Warnings</h3>
                <ul>
                    <?php foreach ($mismatches as $mismatch): ?>
                        <li>
                            <?php echo htmlspecialchars((string) $mismatch["resource_name"]); ?>:
                            catalogue <?php echo (int) $mismatch["total_stock"]; ?>/<?php echo (int) $mismatch["available_stock"]; ?>
                            vs units <?php echo (int) $mismatch["unit_total"]; ?>/<?php echo (int) $mismatch["unit_available"]; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3 class="section-title">Equipment</h3>
            <div class="table-wrapper">
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Resource</th><th>Tracking</th><th>Total</th>
                            <th>Available</th><th>Under Repair</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trackableResources as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string) $row["resource_name"]); ?></td>
                                <td><?php echo htmlspecialchars((string) $row["tracking_mode"]); ?></td>
                                <td><?php echo (int) $row["total_stock"]; ?></td>
                                <td><?php echo (int) $row["available_stock"]; ?></td>
                                <td><?php echo (int) $row["under_repair_stock"]; ?></td>
                                <td><a href="equipment_units.php?resource_id=<?php echo (int) $row["resource_id"]; ?>">Manage units</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($selected !== null): ?>
            <div class="card">
                <h3 class="section-title">
                    <?php echo htmlspecialchars((string) $selected["resource_name"]); ?>
                    &mdash; <?php echo htmlspecialchars((string) $selected["tracking_mode"]); ?>
                </h3>

                <?php if ($readiness !== null && !empty($readiness["blockers"])): ?>
                    <div class="note-box">
                        <strong>Not ready for individual tracking:</strong>
                        <ul>
                            <?php foreach ($readiness["blockers"] as $blocker): ?>
                                <li><?php echo htmlspecialchars((string) $blocker); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($readiness !== null && !empty($readiness["ready"])): ?>
                    <form method="POST" class="inline-form" onsubmit="return confirm('Switch this resource to individual unit tracking?');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                        <input type="hidden" name="resource_id" value="<?php echo $resourceId; ?>">
                        <input type="hidden" name="action" value="activate_serialized">
                        <button type="submit" class="admin-btn primary-btn">Activate individual tracking</button>
                    </form>
                <?php endif; ?>

                <h4 class="section-title mt-12">Register a unit</h4>
                <form method="POST" class="request-line-picker">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                    <input type="hidden" name="resource_id" value="<?php echo $resourceId; ?>">
                    <input type="hidden" name="action" value="register_unit">
                    <input type="text" name="asset_tag" placeholder="Institutional asset tag" maxlength="60" required>
                    <input type="text" name="serial_number" placeholder="Manufacturer serial (optional)" maxlength="120">
                    <input type="date" name="acquired_at">
                    <input type="text" name="unit_notes" placeholder="Notes (optional)" maxlength="500">
                    <button type="submit" class="admin-btn info-btn">Register unit</button>
                </form>

                <h4 class="section-title mt-12">Registered units (<?php echo count($units); ?>)</h4>
                <div class="table-wrapper">
                    <table class="request-table">
                        <thead>
                            <tr>
                                <th>Asset Tag</th><th>Serial</th><th>Status</th>
                                <th>Condition</th><th>Current Request</th><th>Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$units): ?>
                                <tr><td colspan="6">No physical units registered yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($units as $unit): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $unit["asset_tag"]); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($unit["serial_number"] ?? "—")); ?></td>
                                    <td><?php echo htmlspecialchars((string) $unit["status"]); ?></td>
                                    <td><?php echo htmlspecialchars((string) $unit["condition_status"]); ?></td>
                                    <td>
                                        <?php echo !empty($unit["open_request_id"])
                                            ? htmlspecialchars(formatRequestCode((int) $unit["open_request_id"]))
                                            : "—"; ?>
                                    </td>
                                    <td>
                                        <?php if ((string) $unit["status"] === "Released"): ?>
                                            <em>Issued &mdash; record its return first</em>
                                        <?php else: ?>
                                            <form method="POST" class="inline-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string) $_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="resource_id" value="<?php echo $resourceId; ?>">
                                                <input type="hidden" name="unit_id" value="<?php echo (int) $unit["unit_id"]; ?>">
                                                <input type="hidden" name="action" value="update_unit">
                                                <select name="status">
                                                    <?php foreach (gsoEquipmentUnitStatuses() as $statusOption): ?>
                                                        <?php if ($statusOption === "Released") { continue; } ?>
                                                        <option value="<?php echo htmlspecialchars($statusOption); ?>" <?php echo (string) $unit["status"] === $statusOption ? "selected" : ""; ?>>
                                                            <?php echo htmlspecialchars($statusOption); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <select name="condition_status">
                                                    <?php foreach (gsoUnitConditionOptions() as $conditionOption): ?>
                                                        <option value="<?php echo htmlspecialchars($conditionOption); ?>" <?php echo (string) $unit["condition_status"] === $conditionOption ? "selected" : ""; ?>>
                                                            <?php echo htmlspecialchars($conditionOption); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <input type="text" name="unit_notes" maxlength="500" value="<?php echo htmlspecialchars((string) ($unit["unit_notes"] ?? "")); ?>">
                                                <button type="submit" class="admin-btn">Save</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="mini-muted mt-12">
                    Units are never deleted: a lost or retired unit keeps its borrowing
                    history for audit while dropping out of borrowable stock.
                </p>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
