<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";

$pageTitle = "Incident Report Details";
$activePage = "incidents";
$cssFiles = array_merge($cssFiles ?? [], ["../public/assets/css/pages/borrower-incidents.css"]);
$backButtonFallback = "my_incidents.php";

$borrowerId = (int) ($_SESSION["user_id"] ?? 0);
if (!isApprovedBorrowerAccount($pdo, $borrowerId)) {
    redirectWithFlash("browse.php", "Your account is not authorized to access incident reports.");
}

$incidentId = filter_input(INPUT_GET, "incident_id", FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);

if (!is_int($incidentId) || $incidentId <= 0) {
    redirectWithFlash("my_incidents.php", "Incident report not found.");
}

$incident = findBorrowerIncidentReport($pdo, $incidentId, $borrowerId);
if ($incident === null) {
    redirectWithFlash("my_incidents.php", "Incident report not found.");
}

$incidentNumber = formatIncidentNumber($incidentId);
$status = (string) ($incident["status"] ?? "Submitted");
$priority = (string) ($incident["priority"] ?? "Normal");
$hasPhoto = !empty($incident["photo_path"]);
$flashMessage = (string) ($_SESSION["flash_message"] ?? "");
$flashType = (string) ($_SESSION["flash_type"] ?? "");
unset($_SESSION["flash_message"], $_SESSION["flash_type"]);

require_once dirname(__DIR__, 3) . "/includes/header.php";
require_once dirname(__DIR__, 3) . "/includes/borrower_sidebar.php";
?>

<div class="main-content">
    <?php require_once dirname(__DIR__, 3) . "/includes/borrower_topbar.php"; ?>

    <main class="page-content incident-page">
        <div class="card incident-page-hero incident-detail-hero">
            <div>
                <span class="incident-reference"><?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?></span>
                <h2><?php echo htmlspecialchars((string) $incident["incident_title"], ENT_QUOTES, "UTF-8"); ?></h2>
                <p>Reported <?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></p>
            </div>
            <div class="incident-hero-badges">
                <span class="incident-priority <?php echo htmlspecialchars(getIncidentPriorityCssClass($priority), ENT_QUOTES, "UTF-8"); ?>">
                    <?php echo htmlspecialchars($priority, ENT_QUOTES, "UTF-8"); ?> Priority
                </span>
                <span class="status-badge incident-status <?php echo htmlspecialchars(getIncidentStatusCssClass($status), ENT_QUOTES, "UTF-8"); ?>">
                    <?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>
                </span>
            </div>
        </div>

        <?php if ($flashMessage !== ""): ?>
            <div class="flash-message <?php echo $flashType === "success" ? "flash-success" : "flash-error"; ?>" role="status">
                <?php echo htmlspecialchars($flashMessage, ENT_QUOTES, "UTF-8"); ?>
            </div>
        <?php endif; ?>

        <div class="incident-detail-layout">
            <div class="incident-detail-main">
                <section class="card incident-detail-card">
                    <div class="incident-section-heading">
                        <div>
                            <span>Submitted report</span>
                            <h3>Issue details</h3>
                        </div>
                        <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i>
                    </div>

                    <dl class="incident-detail-list">
                        <div>
                            <dt>Incident Number</dt>
                            <dd><?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?></dd>
                        </div>
                        <div>
                            <dt>Category</dt>
                            <dd><?php echo htmlspecialchars((string) $incident["incident_type"], ENT_QUOTES, "UTF-8"); ?></dd>
                        </div>
                        <div>
                            <dt>Location</dt>
                            <dd><?php echo htmlspecialchars((string) $incident["location"], ENT_QUOTES, "UTF-8"); ?></dd>
                        </div>
                        <div>
                            <dt>Related Resource</dt>
                            <dd>
                                <?php if (!empty($incident["resource_name"])): ?>
                                    <?php echo htmlspecialchars((string) $incident["resource_name"], ENT_QUOTES, "UTF-8"); ?>
                                    <small>
                                        <?php echo htmlspecialchars((string) ($incident["resource_type"] ?? "Resource"), ENT_QUOTES, "UTF-8"); ?>
                                        <?php if (!empty($incident["resource_location"])): ?>
                                            &middot; <?php echo htmlspecialchars((string) $incident["resource_location"], ENT_QUOTES, "UTF-8"); ?>
                                        <?php endif; ?>
                                    </small>
                                <?php else: ?>
                                    Not linked to an inventory resource
                                <?php endif; ?>
                            </dd>
                        </div>
                        <div>
                            <dt>Date Reported</dt>
                            <dd><?php echo htmlspecialchars(formatIncidentDateTime($incident["reported_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></dd>
                        </div>
                        <div>
                            <dt>Last Updated</dt>
                            <dd><?php echo htmlspecialchars(formatIncidentDateTime($incident["updated_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></dd>
                        </div>
                    </dl>

                    <div class="incident-description-block">
                        <h3>Description</h3>
                        <p><?php echo nl2br(htmlspecialchars((string) $incident["description"], ENT_QUOTES, "UTF-8")); ?></p>
                    </div>
                </section>

                <section class="card incident-detail-card">
                    <div class="incident-section-heading">
                        <div>
                            <span>GSO review</span>
                            <h3>Remarks and resolution</h3>
                        </div>
                        <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                    </div>

                    <div class="incident-review-grid">
                        <article class="incident-note-panel">
                            <span>Admin Remarks</span>
                            <?php if (!empty($incident["admin_remarks"])): ?>
                                <p><?php echo nl2br(htmlspecialchars((string) $incident["admin_remarks"], ENT_QUOTES, "UTF-8")); ?></p>
                            <?php else: ?>
                                <p class="incident-muted">No Admin remarks have been added yet.</p>
                            <?php endif; ?>
                        </article>

                        <article class="incident-note-panel <?php echo $status === "Resolved" ? "is-resolved" : ""; ?>">
                            <span>Resolution Details</span>
                            <?php if (!empty($incident["resolution_notes"])): ?>
                                <p><?php echo nl2br(htmlspecialchars((string) $incident["resolution_notes"], ENT_QUOTES, "UTF-8")); ?></p>
                                <?php if (!empty($incident["resolved_at"])): ?>
                                    <small>Resolved <?php echo htmlspecialchars(formatIncidentDateTime($incident["resolved_at"]), ENT_QUOTES, "UTF-8"); ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="incident-muted">Resolution details will appear after GSO completes the report.</p>
                            <?php endif; ?>
                        </article>
                    </div>

                    <?php if (!empty($incident["assigned_to_name"])): ?>
                        <p class="incident-assignee">
                            <i class="fa-solid fa-user-gear" aria-hidden="true"></i>
                            Assigned to <?php echo htmlspecialchars((string) $incident["assigned_to_name"], ENT_QUOTES, "UTF-8"); ?>
                        </p>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="incident-detail-side">
                <section class="card incident-photo-card">
                    <div class="incident-section-heading compact">
                        <div>
                            <span>Evidence</span>
                            <h3>Submitted Photo</h3>
                        </div>
                        <i class="fa-solid fa-camera" aria-hidden="true"></i>
                    </div>

                    <?php if ($hasPhoto): ?>
                        <a
                            href="incident_photo.php?incident_id=<?php echo $incidentId; ?>"
                            class="incident-photo-link"
                            target="_blank"
                            rel="noopener"
                        >
                            <img
                                src="incident_photo.php?incident_id=<?php echo $incidentId; ?>"
                                alt="Evidence submitted for <?php echo htmlspecialchars($incidentNumber, ENT_QUOTES, "UTF-8"); ?>"
                            >
                            <span><i class="fa-solid fa-up-right-from-square" aria-hidden="true"></i> Open full image</span>
                        </a>
                    <?php else: ?>
                        <div class="incident-no-photo">
                            <i class="fa-regular fa-image" aria-hidden="true"></i>
                            <strong>No photo submitted</strong>
                            <span>This report was submitted without an attachment.</span>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="card incident-status-card">
                    <h3>Current Status</h3>
                    <span class="status-badge incident-status <?php echo htmlspecialchars(getIncidentStatusCssClass($status), ENT_QUOTES, "UTF-8"); ?>">
                        <?php echo htmlspecialchars($status, ENT_QUOTES, "UTF-8"); ?>
                    </span>
                    <p>
                        <?php
                        $statusMessages = [
                            "Submitted" => "Your report was received and is waiting for GSO review.",
                            "Under Review" => "GSO is inspecting and assessing the reported issue.",
                            "In Progress" => "Corrective action for this issue is currently underway.",
                            "Resolved" => "GSO marked this incident as resolved.",
                            "Rejected" => "GSO did not accept this report. Review the Admin remarks.",
                        ];
                        echo htmlspecialchars($statusMessages[$status] ?? "The report status was updated.", ENT_QUOTES, "UTF-8");
                        ?>
                    </p>
                </section>

                <div class="incident-detail-actions">
                    <a href="my_incidents.php" class="request-btn neutral-action">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                        Back to My Reports
                    </a>
                    <a href="report_issue.php" class="request-btn">
                        <i class="fa-solid fa-plus" aria-hidden="true"></i>
                        Report Another Issue
                    </a>
                </div>
            </aside>
        </div>
    </main>
</div>
