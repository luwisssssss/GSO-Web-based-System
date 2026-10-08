<?php
declare(strict_types=1);

require_once __DIR__ . "/request_helper.php";
require_once __DIR__ . "/adviser_endorsement_helper.php";
require_once __DIR__ . "/request_line_helper.php";
require_once __DIR__ . "/notification_helper.php";
require_once __DIR__ . "/activity_log_helper.php";

if (!function_exists("gsoIsApprovedDirector")) {
    /**
     * Historical compatibility account check. This does not grant decision
     * authority: all Director actions are retired after R2A.
     */
    function gsoIsApprovedDirector(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT 1 FROM users
            WHERE user_id = :user_id
              AND role = 'Director'
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([":user_id" => $userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("gsoDirectorStateBlocksGso")) {
    function gsoDirectorStateBlocksGso(array $request): bool
    {
        return false; // Retired: historical Director state does not gate GSO.
    }
}

if (!function_exists("gsoDirectorGateAllowsRelease")) {
    function gsoDirectorGateAllowsRelease(array $request): bool
    {
        return true; // GSO release still enforces its own validation.
    }
}

if (!function_exists("gsoCanRouteRequestToDirector")) {
    function gsoCanRouteRequestToDirector(array $request): bool
    {
        return false; // No new Director routing after R2A.
    }
}

if (!function_exists("gsoDirectorHistory")) {
    function gsoDirectorHistory(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare("
            SELECT h.*, d.full_name AS director_name
            FROM request_director_history h
            INNER JOIN users d ON d.user_id = h.director_user_id
            WHERE h.request_id = :request_id
            ORDER BY h.decided_at ASC, h.director_history_id ASC
        ");
        $stmt->execute([":request_id" => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("gsoFacilityConflictsForRequest")) {
    function gsoFacilityConflictsForRequest(PDO $pdo, array $request): array
    {
        // Phase 3.5A: the facility is a request line, not the request header.
        // An equipment-only request has no facility line and therefore never
        // enters facility conflict prioritisation.
        $facilityLine = gsoRequestFacilityLine($pdo, (int) ($request["request_id"] ?? 0));

        if ($facilityLine === null) {
            return [];
        }

        return findScheduleConflicts(
            $pdo,
            (int) $facilityLine["resource_id"],
            "Facility",
            $request["date_needed"] ?? null,
            $request["start_time"] ?? null,
            $request["end_time"] ?? null,
            $request["due_date"] ?? null,
            (int) ($request["request_id"] ?? 0)
        );
    }
}

if (!function_exists("gsoNotifyApprovedRoles")) {
    function gsoNotifyApprovedRoles(
        PDO $pdo,
        array $roles,
        string $type,
        string $title,
        string $message,
        string $link
    ): void {
        $roles = array_values(array_intersect(["Admin", "GSO Staff"], array_unique($roles)));
        if ($roles === []) {
            return;
        }
        $marks = implode(",", array_fill(0, count($roles), "?"));
        $stmt = $pdo->prepare("
            SELECT user_id FROM users
            WHERE role IN ({$marks}) AND account_status='Approved' AND email_verified=1
        ");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $userId) {
            createNotification($pdo, (int) $userId, $type, $title, $message, $link);
        }
    }
}

if (!function_exists("gsoNewDirectorDecisionBatch")) {
    function gsoNewDirectorDecisionBatch(): string
    {
        $hex = bin2hex(random_bytes(16));
        return substr($hex, 0, 8) . "-" . substr($hex, 8, 4) . "-" . substr($hex, 12, 4)
            . "-" . substr($hex, 16, 4) . "-" . substr($hex, 20, 12);
    }
}

if (!function_exists("gsoSendDirectorAssignmentEmails")) {
    /** Notification only: links always require the Director to authenticate. */
    function gsoSendDirectorAssignmentEmails(PDO $pdo, int $requestId, int $actorId): bool
    {
        return false; // Retired: never send approval assignment emails.
    }
}
