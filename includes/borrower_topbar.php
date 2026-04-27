<?php
require_once "../config/db.php";
require_once "../includes/avatar_helper.php";
require_once "../includes/notification_helper.php";

$user_id = $_SESSION["user_id"];

$unreadCount = getUnreadNotificationCount($pdo, $user_id);
$notifications = getNotifications($pdo, $user_id, 0);
$avatar = getUserAvatarSource($pdo, (int) $user_id, "../assets/css/img/default.png");

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$notificationSummary = $unreadCount > 0
    ? $unreadCount . " unread notification" . ($unreadCount === 1 ? "" : "s")
    : "All caught up";
?>

<div class="topbar">
    <div class="topbar-clock" data-live-datetime>
        <i class="fa-regular fa-clock"></i>
        <span>Loading date and time...</span>
    </div>

    <div class="topbar-right">
        <div class="quick-actions-wrapper">
            <button type="button" class="topbar-icon-btn quick-action-trigger" data-toggle-quick-actions aria-label="Open quick actions">
                <i class="fa-solid fa-bolt"></i>
                <span>Quick</span>
            </button>

            <div class="quick-actions-dropdown" id="quickActionsDropdown">
                <h4>Quick Actions</h4>
                <a href="browse.php"><i class="fa-solid fa-magnifying-glass"></i><span>Browse Resources</span></a>
                <a href="my_requests.php"><i class="fa-solid fa-clipboard-list"></i><span>Track Requests</span></a>
                <a href="my_borrowed.php"><i class="fa-solid fa-box-open"></i><span>Active Borrowings</span></a>
                <a href="history.php"><i class="fa-solid fa-clock-rotate-left"></i><span>View History</span></a>
            </div>
        </div>

        <div class="notification-wrapper">
            <button
                type="button"
                class="notification-bell"
                data-toggle-notifications
                aria-label="Open notifications"
                aria-expanded="false"
                aria-controls="notifDropdown"
            >
                <i class="fa-solid fa-bell"></i>

                <?php if ($unreadCount > 0): ?>
                    <span class="notif-badge" data-notification-badge><?php echo $unreadCount > 99 ? "99+" : (int) $unreadCount; ?></span>
                <?php endif; ?>
            </button>

            <div class="notification-dropdown" id="notifDropdown" data-notification-dropdown>
                <div class="notification-dropdown-header">
                    <div>
                        <h4>Notifications</h4>
                        <p class="notification-subtitle" data-unread-status><?php echo htmlspecialchars($notificationSummary); ?></p>
                    </div>

                    <?php if (!empty($notifications)): ?>
                        <button
                            type="button"
                            class="notification-mark-all-btn<?php echo $unreadCount === 0 ? " is-disabled" : ""; ?>"
                            data-mark-all-read
                            <?php echo $unreadCount === 0 ? "disabled" : ""; ?>
                        >
                            <i class="fa-solid fa-check-double" aria-hidden="true"></i>
                            <span>Mark all as read</span>
                        </button>
                    <?php endif; ?>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="notification-empty-state">
                        <div class="notification-empty-icon">
                            <i class="fa-regular fa-bell-slash" aria-hidden="true"></i>
                        </div>
                        <strong>No notifications yet</strong>
                        <p>Request updates, approvals, returns, and reminders will appear here.</p>
                    </div>
                <?php else: ?>
                    <div class="notification-list" data-notification-list>
                        <?php foreach ($notifications as $notif): ?>
                            <?php
                            $presentation = getNotificationPresentation($notif);
                            $safeLink = gsoSafeNotificationLink($notif["link"] ?? "#");
                            $isUnread = (int) ($notif["is_read"] ?? 0) === 0;
                            $timeLabel = formatNotificationTimestamp($notif["created_at"] ?? "");
                            ?>
                            <a
                                href="<?php echo htmlspecialchars($safeLink, ENT_QUOTES, "UTF-8"); ?>"
                                class="notif-item notif-item--<?php echo htmlspecialchars($presentation["tone"], ENT_QUOTES, "UTF-8"); ?><?php echo $isUnread ? " unread" : ""; ?>"
                                data-notification-id="<?php echo (int) $notif["notification_id"]; ?>"
                                data-notification-unread="<?php echo $isUnread ? "1" : "0"; ?>"
                            >
                                <div class="notif-icon">
                                    <i class="fa-solid <?php echo htmlspecialchars($presentation["icon"], ENT_QUOTES, "UTF-8"); ?>" aria-hidden="true"></i>
                                </div>

                                <div class="notif-content">
                                    <div class="notif-meta-row">
                                        <span class="notif-type-label"><?php echo htmlspecialchars($presentation["label"]); ?></span>
                                        <?php if ($isUnread): ?>
                                            <span class="notif-pill">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars($notif["title"]); ?></strong>
                                    <p><?php echo htmlspecialchars($notif["message"]); ?></p>
                                    <?php if ($timeLabel !== ""): ?>
                                        <span class="notif-time">
                                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                                            <?php echo htmlspecialchars($timeLabel); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="topbar-user" onclick="toggleUserMenu()">
            <div class="topbar-avatar">
                <img src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, "UTF-8"); ?>" alt="User">
            </div>

            <div class="topbar-user-info">
                <strong><?php echo htmlspecialchars($_SESSION["full_name"]); ?></strong>
                <small><?php echo htmlspecialchars($_SESSION["role"]); ?></small>
            </div>

            <i class="fa-solid fa-chevron-down dropdown-icon"></i>

            <div class="user-dropdown" id="userDropdown">
                <div class="user-dropdown-header">
                    <strong><?php echo htmlspecialchars($_SESSION["full_name"]); ?></strong>
                    <p><?php echo htmlspecialchars($_SESSION["email"] ?? "admin@gso.gov"); ?></p>
                </div>

                <a href="profile.php" class="logout-btn profile-menu-link">
                    <i class="fa-solid fa-user"></i> Profile
                </a>

                <a href="../auth/logout.php" class="logout-btn">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function toggleUserMenu() {
    const menu = document.getElementById("userDropdown");
    const icon = document.querySelector(".dropdown-icon");

    menu.classList.toggle("show");

    if (menu.classList.contains("show")) {
        icon.style.transform = "rotate(180deg)";
    } else {
        icon.style.transform = "rotate(0deg)";
    }
}
</script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const notifWrapper = document.querySelector(".notification-wrapper");
    if (!notifWrapper) {
        return;
    }

    const notifToggle = notifWrapper.querySelector("[data-toggle-notifications]");
    const notifDropdown = notifWrapper.querySelector("[data-notification-dropdown]");
    const unreadStatus = notifWrapper.querySelector("[data-unread-status]");
    const markAllButton = notifWrapper.querySelector("[data-mark-all-read]");
    const csrfToken = "<?php echo htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8"); ?>";

    function setBadgeCount(count) {
        let badge = notifToggle.querySelector("[data-notification-badge]");

        if (count > 0) {
            if (!badge) {
                badge = document.createElement("span");
                badge.className = "notif-badge";
                badge.setAttribute("data-notification-badge", "");
                notifToggle.appendChild(badge);
            }

            badge.textContent = count > 99 ? "99+" : String(count);
        } else if (badge) {
            badge.remove();
        }
    }

    function updateUnreadUi() {
        const unreadItems = notifWrapper.querySelectorAll(".notif-item.unread");
        const unreadCount = unreadItems.length;

        setBadgeCount(unreadCount);

        if (unreadStatus) {
            unreadStatus.textContent = unreadCount > 0
                ? unreadCount + " unread notification" + (unreadCount === 1 ? "" : "s")
                : "All caught up";
        }

        if (markAllButton) {
            markAllButton.disabled = unreadCount === 0;
            markAllButton.classList.toggle("is-disabled", unreadCount === 0);
        }
    }

    function markNotificationItemRead(notifItem) {
        if (!notifItem || !notifItem.classList.contains("unread")) {
            return;
        }

        notifItem.classList.remove("unread");
        notifItem.dataset.notificationUnread = "0";

        const pill = notifItem.querySelector(".notif-pill");
        if (pill) {
            pill.remove();
        }

        updateUnreadUi();
    }

    notifToggle.addEventListener("click", function (event) {
        event.stopPropagation();

        const willShow = !notifDropdown.classList.contains("show");
        notifDropdown.classList.toggle("show", willShow);
        notifToggle.setAttribute("aria-expanded", willShow ? "true" : "false");
    });

    if (markAllButton) {
        markAllButton.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();

            if (markAllButton.disabled) {
                return;
            }

            const payload = new URLSearchParams({
                action: "mark_all",
                csrf_token: csrfToken
            });

            fetch("../includes/mark_read.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: payload
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error("Failed to mark notifications.");
                    }

                    notifWrapper.querySelectorAll(".notif-item.unread").forEach(markNotificationItemRead);
                })
                .catch(function () {
                    updateUnreadUi();
                });
        });
    }

    document.addEventListener("click", function (event) {
        const notifItem = event.target.closest(".notif-item");

        if (notifItem && notifWrapper.contains(notifItem)) {
            const notifId = notifItem.dataset.notificationId;
            if (notifId) {
                markNotificationItemRead(notifItem);

                const payload = new URLSearchParams({
                    notification_id: notifId,
                    csrf_token: csrfToken
                });

                if (navigator.sendBeacon) {
                    navigator.sendBeacon("../includes/mark_read.php", payload);
                } else {
                    fetch("../includes/mark_read.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: payload
                    }).catch(function () {});
                }
            }

            return;
        }

        if (!notifWrapper.contains(event.target)) {
            notifDropdown.classList.remove("show");
            notifToggle.setAttribute("aria-expanded", "false");
        }
    });
});
</script>
