<?php
$notificationPageTitle = (string) ($notificationPageTitle ?? "Notifications");
$notificationPageData = is_array($notificationPageData ?? null) ? $notificationPageData : [];
$notificationRows = $notificationPageData["notifications"] ?? [];
$notificationFilter = (string) ($notificationPageData["filter"] ?? "all");
$notificationPage = (int) ($notificationPageData["page"] ?? 1);
$notificationTotalPages = (int) ($notificationPageData["total_pages"] ?? 1);
$notificationTotalRows = (int) ($notificationPageData["total_rows"] ?? 0);
$notificationGlobalUnread = max(0, (int) ($notificationPageData["global_unread_count"] ?? 0));
?>

<main class="page-content notification-page">
    <div class="card notification-page-hero">
        <div>
            <h2><?php echo htmlspecialchars($notificationPageTitle, ENT_QUOTES, "UTF-8"); ?></h2>
            <p>Review account updates and open their protected destination pages.</p>
        </div>
        <button type="button" class="admin-btn primary-btn" data-page-mark-all <?php echo $notificationGlobalUnread === 0 ? "disabled" : ""; ?>>
            <i class="fa-solid fa-check-double" aria-hidden="true"></i> Mark all as read
        </button>
    </div>

    <nav class="status-tabs" aria-label="Notification filters">
        <?php foreach (["all" => "All", "unread" => "Unread", "incident" => "Incidents"] as $value => $label): ?>
            <a href="notifications.php?filter=<?php echo rawurlencode($value); ?>" class="<?php echo $notificationFilter === $value ? "active" : ""; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, "UTF-8"); ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="card notification-page-card">
        <?php if (empty($notificationRows)): ?>
            <div class="notification-empty-state notification-page-empty">
                <i class="fa-regular fa-bell-slash" aria-hidden="true"></i>
                <strong>No notifications found</strong>
                <p>New account and workflow updates will appear here.</p>
            </div>
        <?php else: ?>
            <div class="notification-page-list">
                <?php foreach ($notificationRows as $notification): ?>
                    <?php
                    $presentation = getNotificationPresentation($notification);
                    $safeLink = gsoSafeNotificationLink($notification["link"] ?? "#");
                    $isUnread = (int) ($notification["is_read"] ?? 0) === 0;
                    ?>
                    <a
                        href="<?php echo htmlspecialchars($safeLink, ENT_QUOTES, "UTF-8"); ?>"
                        class="notification-page-item notif-item--<?php echo htmlspecialchars((string) $presentation["tone"], ENT_QUOTES, "UTF-8"); ?><?php echo $isUnread ? " unread" : ""; ?>"
                        data-page-notification-id="<?php echo (int) $notification["notification_id"]; ?>"
                    >
                        <span class="notification-page-icon"><i class="fa-solid <?php echo htmlspecialchars((string) $presentation["icon"], ENT_QUOTES, "UTF-8"); ?>" aria-hidden="true"></i></span>
                        <span class="notification-page-copy">
                            <span class="notification-page-meta"><?php echo htmlspecialchars((string) $presentation["label"], ENT_QUOTES, "UTF-8"); ?><?php if ($isUnread): ?><span>New</span><?php endif; ?></span>
                            <strong><?php echo htmlspecialchars((string) $notification["title"], ENT_QUOTES, "UTF-8"); ?></strong>
                            <small><?php echo htmlspecialchars((string) $notification["message"], ENT_QUOTES, "UTF-8"); ?></small>
                            <time><?php echo htmlspecialchars(formatNotificationTimestamp($notification["created_at"] ?? null), ENT_QUOTES, "UTF-8"); ?></time>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($notificationTotalPages > 1): ?>
            <div class="pagination-bar">
                <div class="pagination-info"><?php echo $notificationTotalRows; ?> notification(s)</div>
                <div class="pagination-links">
                    <?php for ($pageNumber = 1; $pageNumber <= $notificationTotalPages; $pageNumber++): ?>
                        <?php if ($pageNumber === 1 || $pageNumber === $notificationTotalPages || abs($pageNumber - $notificationPage) <= 1): ?>
                            <a class="pagination-link <?php echo $pageNumber === $notificationPage ? "active" : ""; ?>" href="notifications.php?<?php echo htmlspecialchars(http_build_query(["filter" => $notificationFilter, "page" => $pageNumber]), ENT_QUOTES, "UTF-8"); ?>"><?php echo $pageNumber; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const csrfToken = <?php echo json_encode((string) ($_SESSION["csrf_token"] ?? "")); ?>;
    const endpoint = "../includes/mark_read.php";
    const markAll = document.querySelector("[data-page-mark-all]");
    let remainingUnread = <?php echo $notificationGlobalUnread; ?>;

    function markNotificationItemRead(item) {
        if (!item.classList.contains("unread")) return;

        item.classList.remove("unread");
        const newPill = item.querySelector(".notification-page-meta > span");
        if (newPill) newPill.remove();

        remainingUnread = Math.max(0, remainingUnread - 1);
        if (markAll && remainingUnread === 0) markAll.disabled = true;
    }

    document.querySelectorAll("[data-page-notification-id]").forEach(function (item) {
        item.addEventListener("click", function (event) {
            if (!item.classList.contains("unread")) return;

            markNotificationItemRead(item);
            const payload = new URLSearchParams({
                notification_id: item.dataset.pageNotificationId,
                csrf_token: csrfToken
            });

            if ((item.getAttribute("href") || "#") === "#") {
                event.preventDefault();
                fetch(endpoint, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: payload, keepalive: true })
                    .then(function () { window.location.reload(); })
                    .catch(function () { window.location.reload(); });
                return;
            }

            const queued = navigator.sendBeacon ? navigator.sendBeacon(endpoint, payload) : false;
            if (!queued) {
                fetch(endpoint, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: payload, keepalive: true }).catch(function () {});
            }
        });
    });

    if (markAll) {
        markAll.addEventListener("click", function () {
            if (markAll.disabled) return;

            markAll.disabled = true;
            const payload = new URLSearchParams({ action: "mark_all", csrf_token: csrfToken });
            fetch(endpoint, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: payload })
                .then(function (response) { if (!response.ok) throw new Error(); window.location.reload(); })
                .catch(function () { markAll.disabled = remainingUnread === 0; });
        });
    }
});
</script>
