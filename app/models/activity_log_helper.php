<?php

if (!function_exists("addActivityLog")) {
    function addActivityLog(PDO $pdo, ?int $userId, string $action, ?string $details = null): bool
    {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO activity_logs
                (
                    user_id,
                    action,
                    details
                )
                VALUES
                (
                    :user_id,
                    :action,
                    :details
                )
            ");

            return $stmt->execute([
                ":user_id" => $userId,
                ":action"  => $action,
                ":details" => $details
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}