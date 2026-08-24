<?php
/**
 * File: /includes/notification_helper.php
 *
 * Why:
 * - Central place for notification (mailbox) operations.
 */

if (!function_exists("createNotification")) {
    function createNotification(
        PDO $pdo,
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $link = null
    ): bool {
        try {
            if ($userId <= 0) {
                return false;
            }

            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, link)
                VALUES
                    (:user_id, :type, :title, :message, :link)
            ");

            return $stmt->execute([
                ":user_id" => $userId,
                ":type" => $type,
                ":title" => $title,
                ":message" => $message,
                ":link" => $link
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists("gsoSafeNotificationLink")) {
    function gsoSafeNotificationLink(?string $link): string
    {
        $link = trim((string) $link);

        if ($link === ""
            || str_contains($link, "\0")
            || str_contains($link, "\\")
            || str_starts_with($link, "/")
            || str_starts_with($link, "//")
            || preg_match('/^[a-z][a-z0-9+.-]*:/i', $link)
        ) {
            return "#";
        }

        $parts = parse_url($link);
        if ($parts === false
            || isset($parts["scheme"])
            || isset($parts["host"])
            || isset($parts["user"])
            || isset($parts["pass"])
            || isset($parts["port"])
        ) {
            return "#";
        }

        $path = (string) ($parts["path"] ?? "");
        if ($path === "") {
            return "#";
        }

        $allowedPath = preg_match(
            '#\A(?:\.\./(?:admin|borrower)/)?[a-z][a-z0-9_]*\.php\z#i',
            $path
        ) === 1;
        if (!$allowedPath) {
            return "#";
        }

        return $link;
    }
}

if (!function_exists("notificationExists")) {
    function notificationExists(
        PDO $pdo,
        int $userId,
        string $type,
        ?string $link = null,
        ?string $title = null,
        ?string $message = null
    ): bool {
        try {
            if ($userId <= 0) {
                return false;
            }

            if (!empty($link)) {
                $stmt = $pdo->prepare("
                    SELECT 1
                    FROM notifications
                    WHERE user_id = :user_id
                      AND type = :type
                      AND link = :link
                    LIMIT 1
                ");
                $stmt->execute([
                    ":user_id" => $userId,
                    ":type" => $type,
                    ":link" => $link
                ]);

                return (bool) $stmt->fetchColumn();
            }

            if ($title === null || $message === null) {
                return false;
            }

            $stmt = $pdo->prepare("
                SELECT 1
                FROM notifications
                WHERE user_id = :user_id
                  AND type = :type
                  AND title = :title
                  AND message = :message
                LIMIT 1
            ");
            $stmt->execute([
                ":user_id" => $userId,
                ":type" => $type,
                ":title" => $title,
                ":message" => $message
            ]);

            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists("getUnreadNotificationCount")) {
    function getUnreadNotificationCount(PDO $pdo, int $userId): int
    {
        try {
            if ($userId <= 0) {
                return 0;
            }

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE user_id = :user_id
                  AND is_read = 0
            ");
            $stmt->execute([":user_id" => $userId]);

            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists("getNotifications")) {
    function getNotifications(
        PDO $pdo,
        int $userId,
        int $limit = 20,
        int $offset = 0,
        bool $onlyUnread = false
    ): array {
        try {
            if ($userId <= 0) {
                return [];
            }

            $offset = max(0, $offset);

            $sql = "
                SELECT
                    notification_id,
                    type,
                    title,
                    message,
                    link,
                    is_read,
                    created_at
                FROM notifications
                WHERE user_id = :user_id
            ";

            $params = [":user_id" => $userId];

            if ($onlyUnread) {
                $sql .= " AND is_read = 0 ";
            }

            $sql .= " ORDER BY created_at DESC, notification_id DESC ";

            if ($limit > 0) {
                $limit = min(500, $limit);
                $sql .= " LIMIT {$limit} OFFSET {$offset}";
            } elseif ($offset > 0) {
                $sql .= " LIMIT 18446744073709551615 OFFSET {$offset}";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists("markNotificationRead")) {
    function markNotificationRead(PDO $pdo, int $userId, int $notificationId): bool
    {
        try {
            if ($userId <= 0 || $notificationId <= 0) {
                return false;
            }

            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE notification_id = :notification_id
                  AND user_id = :user_id
                LIMIT 1
            ");

            return $stmt->execute([
                ":notification_id" => $notificationId,
                ":user_id" => $userId
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists("markAllNotificationsRead")) {
    function markAllNotificationsRead(PDO $pdo, int $userId): bool
    {
        try {
            if ($userId <= 0) {
                return false;
            }

            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = :user_id
                  AND is_read = 0
            ");

            return $stmt->execute([":user_id" => $userId]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists("getNotificationPresentation")) {
    function getNotificationPresentation(array $notification): array
    {
        $type = strtoupper(trim((string) ($notification["type"] ?? "")));
        $title = strtoupper(trim((string) ($notification["title"] ?? "")));
        $message = strtoupper(trim((string) ($notification["message"] ?? "")));

        $presentation = [
            "icon" => "fa-circle-info",
            "tone" => "system",
            "label" => "System update"
        ];

        if ($type === "INCIDENT_RESOLVED") {
            $presentation = [
                "icon" => "fa-circle-check",
                "tone" => "approved",
                "label" => "Incident resolved"
            ];
        } elseif ($type === "INCIDENT_REJECTED") {
            $presentation = [
                "icon" => "fa-circle-xmark",
                "tone" => "rejected",
                "label" => "Incident rejected"
            ];
        } elseif ($type === "INCIDENT" || str_starts_with($type, "INCIDENT_")) {
            $presentation = [
                "icon" => "fa-triangle-exclamation",
                "tone" => "alert",
                "label" => "Incident report"
            ];
        } elseif ($type === "REQUEST") {
            $presentation = [
                "icon" => "fa-clipboard-list",
                "tone" => "request",
                "label" => "New request"
            ];
        } elseif ($type === "APPROVED") {
            $presentation = [
                "icon" => "fa-circle-check",
                "tone" => "approved",
                "label" => "Approved"
            ];
        } elseif ($type === "REJECTED") {
            $presentation = [
                "icon" => "fa-circle-xmark",
                "tone" => "rejected",
                "label" => "Rejected"
            ];
        } elseif ($type === "RETURN_SUBMITTED") {
            $presentation = [
                "icon" => "fa-arrow-rotate-left",
                "tone" => "return",
                "label" => "Return submitted"
            ];
        } elseif ($type === "RETURN_APPROVED") {
            $presentation = [
                "icon" => "fa-box-open",
                "tone" => "approved",
                "label" => "Return completed"
            ];
        } elseif ($type === "RETURN_REJECTED") {
            $presentation = [
                "icon" => "fa-rotate-left",
                "tone" => "rejected",
                "label" => "Return rejected"
            ];
        } elseif (in_array($type, ["OVERDUE", "OVERDUE_REMINDER"], true)) {
            $presentation = [
                "icon" => "fa-triangle-exclamation",
                "tone" => "overdue",
                "label" => "Overdue"
            ];
        } elseif ($type === "SYSTEM" && (str_contains($title, "LOW STOCK") || str_contains($message, "LOW STOCK"))) {
            $presentation = [
                "icon" => "fa-boxes-stacked",
                "tone" => "alert",
                "label" => "Stock alert"
            ];
        } elseif ($type === "SYSTEM" && (str_contains($title, "RELEASED") || str_contains($message, "RELEASED"))) {
            $presentation = [
                "icon" => "fa-hand-holding",
                "tone" => "release",
                "label" => "Borrowed"
            ];
        }

        return $presentation;
    }
}

if (!function_exists("formatNotificationTimestamp")) {
    function formatNotificationTimestamp(?string $createdAt): string
    {
        $createdAt = trim((string) $createdAt);
        if ($createdAt === "") {
            return "";
        }

        $timestamp = strtotime($createdAt);
        if ($timestamp === false) {
            return "";
        }

        $today = date("Y-m-d");
        $yesterday = date("Y-m-d", strtotime("-1 day"));
        $createdDay = date("Y-m-d", $timestamp);

        if ($createdDay === $today) {
            return "Today, " . date("g:i A", $timestamp);
        }

        if ($createdDay === $yesterday) {
            return "Yesterday, " . date("g:i A", $timestamp);
        }

        return date("M d, Y g:i A", $timestamp);
    }
}
