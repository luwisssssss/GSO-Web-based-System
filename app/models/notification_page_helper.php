<?php

if (!function_exists("getNotificationPageData")) {
    function getNotificationPageData(PDO $pdo, int $userId, array $query): array
    {
        $filter = strtolower(trim((string) ($query["filter"] ?? "all")));
        if (!in_array($filter, ["all", "unread", "incident"], true)) {
            $filter = "all";
        }

        $page = max(1, (int) ($query["page"] ?? 1));
        $perPage = 20;
        $where = "WHERE user_id = :user_id";
        $params = [":user_id" => $userId];

        if ($filter === "unread") {
            $where .= " AND is_read = 0";
        } elseif ($filter === "incident") {
            $where .= " AND (UPPER(type) = 'INCIDENT' OR UPPER(type) LIKE 'INCIDENT\\_%' ESCAPE '\\\\')";
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications {$where}");
        $countStmt->execute($params);
        $totalRows = (int) $countStmt->fetchColumn();
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $listStmt = $pdo->prepare("
            SELECT notification_id, type, title, message, link, is_read, created_at
            FROM notifications
            {$where}
            ORDER BY created_at DESC, notification_id DESC
            LIMIT {$perPage} OFFSET {$offset}
        ");
        $listStmt->execute($params);

        return [
            "filter" => $filter,
            "page" => $page,
            "per_page" => $perPage,
            "total_rows" => $totalRows,
            "total_pages" => $totalPages,
            "notifications" => $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }
}
