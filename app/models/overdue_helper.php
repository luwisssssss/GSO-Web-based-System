<?php
/**
 * File: /includes/overdue_helper.php
 *
 * Why:
 * - Compute overdue loans (no DB enum changes)
 * - Create notification once per overdue request (no spam)
 */

require_once __DIR__ . "/notification_helper.php";

if (!function_exists("ensureBorrowerOverdueNotifications")) {
    function ensureBorrowerOverdueNotifications(PDO $pdo, int $borrowerId): void
    {
        if ($borrowerId <= 0) {
            return;
        }

        $stmt = $pdo->prepare("
            SELECT
                rr.request_id,
                rr.due_date,
                r.resource_name
            FROM resource_requests rr
            INNER JOIN resources r ON rr.resource_id = r.resource_id
            WHERE rr.borrower_id = :borrower_id
              AND rr.status = 'Released'
              AND rr.return_date IS NULL
              AND rr.due_date IS NOT NULL
              AND rr.due_date < NOW()
        ");
        $stmt->execute([
            ":borrower_id" => $borrowerId
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $requestId = (int) ($row["request_id"] ?? 0);
            if ($requestId <= 0) {
                continue;
            }

            $resourceName = (string) ($row["resource_name"] ?? "");
            $dueDate = (string) ($row["due_date"] ?? "");

            $borrowNo = "BRW-" . str_pad((string)$requestId, 3, "0", STR_PAD_LEFT);
            $link = "my_borrowed.php?loan_state=Overdue&focus_request_id=" . $requestId;

            if (notificationExists($pdo, $borrowerId, "OVERDUE", $link)) {
                continue;
            }

            createNotification(
                $pdo,
                $borrowerId,
                "OVERDUE",
                "Overdue Borrowed Item",
                "{$borrowNo} is overdue. Resource: {$resourceName}. Due: {$dueDate}.",
                $link
            );
        }
    }
}