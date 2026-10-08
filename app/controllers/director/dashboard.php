<?php
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/facility_calendar_helper.php";
ensureAuthenticatedPermission("director.dashboard");
ensureApprovedPermissionAccount($pdo, "director.dashboard");
ensureAuthenticatedPermission("facility.schedule.view");
if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "GET") {
    http_response_code(405);
    header("Allow: GET");
    exit("Facility schedules are read-only.");
}
$month = normalizeFacilityCalendarMonth($_GET["facility_calendar_month"] ?? null);
$resourceId = max(0, (int) ($_GET["facility_calendar_resource_id"] ?? 0));
$monthStart = date("Y-m-01", strtotime($month . "-01"));
$monthEnd = date("Y-m-t", strtotime($month . "-01"));
// Single-campus scope: normalized facility lines, without borrower identity
// or maintenance notes. Tighter scope can be applied in this shared service.
$events = fetchFacilityCalendarEvents($pdo, $resourceId ?: null, $monthStart, $monthEnd, false, false);
$pageTitle = "Director Facility Schedules";
$activePage = "director-dashboard";
$cssFile = "../public/assets/css/pages/admin.css";
$showGlobalBackButton = false;
require_once dirname(__DIR__, 3) . "/includes/header.php";
?>
<div class="main-content"><main class="page-content">
    <section class="card"><h1>Facility Schedules</h1><p>URS Binangonan GSO facility reservations. Schedule viewing is read-only.</p></section>
    <section class="card">
        <?php echo renderFacilityAvailabilityCalendar($pdo, [
            "month" => $month, "resource_id" => $resourceId ?: null,
            "show_resource_filter" => true, "show_borrower" => false,
            "show_maintenance_reason" => false, "base_url" => "dashboard.php",
        ]); ?>
    </section>
    <section class="card"><h2>Facility Reservations</h2><div class="table-wrapper">
        <table class="request-table"><thead><tr><th>Request</th><th>Facility</th><th>Date</th><th>Time</th><th>Status</th></tr></thead><tbody>
        <?php $reservations = array_filter($events, static fn(array $event): bool => $event["type"] === "reservation"); ?>
        <?php if (!$reservations): ?><tr><td colspan="5">No facility reservations in this month.</td></tr><?php endif; ?>
        <?php foreach ($reservations as $event): ?>
            <tr><td><?php echo facilityCalendarEscape($event["request_code"]); ?></td>
                <td><?php echo facilityCalendarEscape($event["resource_name"]); ?></td>
                <td><?php echo facilityCalendarEscape(formatDateTimeDisplay($event["date"])); ?></td>
                <td><?php echo facilityCalendarEscape($event["time"]); ?></td>
                <td><?php echo facilityCalendarEscape($event["status"]); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </div></section>
    <a class="admin-btn" href="../auth/logout.php">Sign out</a>
</main></div>
<?php require_once dirname(__DIR__, 3) . "/includes/footer.php"; ?>
