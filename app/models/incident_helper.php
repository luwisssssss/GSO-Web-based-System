<?php

if (!function_exists("getIncidentTypeOptions")) {
    function getIncidentTypeOptions(): array
    {
        return [
            "Furniture",
            "Equipment",
            "Electrical",
            "Door/Window",
            "Plumbing",
            "Facility",
            "Safety",
            "Other",
        ];
    }
}

if (!function_exists("getIncidentPriorityOptions")) {
    function getIncidentPriorityOptions(): array
    {
        return ["Low", "Normal", "High", "Urgent"];
    }
}

if (!function_exists("getIncidentStatusOptions")) {
    function getIncidentStatusOptions(): array
    {
        return ["Submitted", "Under Review", "In Progress", "Resolved", "Rejected"];
    }
}

if (!function_exists("getIncidentStatusTransitions")) {
    function getIncidentStatusTransitions(): array
    {
        return [
            "Submitted" => ["Under Review", "Rejected"],
            "Under Review" => ["In Progress", "Rejected"],
            "In Progress" => ["Resolved"],
            "Resolved" => [],
            "Rejected" => [],
        ];
    }
}

if (!function_exists("getIncidentAllowedTransitions")) {
    function getIncidentAllowedTransitions(string $status): array
    {
        $transitions = getIncidentStatusTransitions();
        return $transitions[$status] ?? [];
    }
}

if (!function_exists("isValidIncidentStatusTransition")) {
    function isValidIncidentStatusTransition(string $fromStatus, string $toStatus): bool
    {
        return in_array($toStatus, getIncidentAllowedTransitions($fromStatus), true);
    }
}

if (!function_exists("incidentTextLength")) {
    function incidentTextLength(string $value): int
    {
        if (function_exists("mb_strlen")) {
            return mb_strlen($value, "UTF-8");
        }

        return strlen($value);
    }
}

if (!function_exists("normalizeIncidentSingleLine")) {
    function normalizeIncidentSingleLine(?string $value): string
    {
        $value = trim((string) $value);
        $normalized = preg_replace('/\s+/u', ' ', $value);

        return is_string($normalized) ? $normalized : $value;
    }
}

if (!function_exists("normalizeIncidentDescription")) {
    function normalizeIncidentDescription(?string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
        return trim($value);
    }
}

if (!function_exists("formatIncidentNumber")) {
    function formatIncidentNumber(int $incidentId): string
    {
        return "INC-" . str_pad((string) max(0, $incidentId), 6, "0", STR_PAD_LEFT);
    }
}

if (!function_exists("formatIncidentDateTime")) {
    function formatIncidentDateTime(?string $value): string
    {
        $value = trim((string) $value);

        if ($value === "") {
            return "N/A";
        }

        $timestamp = strtotime($value);
        return $timestamp === false ? "N/A" : date("M d, Y h:i A", $timestamp);
    }
}

if (!function_exists("getIncidentStatusCssClass")) {
    function getIncidentStatusCssClass(string $status): string
    {
        $class = preg_replace('/[^a-z0-9]+/i', '', strtolower($status));
        return is_string($class) && $class !== "" ? $class : "submitted";
    }
}

if (!function_exists("getIncidentPriorityCssClass")) {
    function getIncidentPriorityCssClass(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ["low", "normal", "high", "urgent"], true)
            ? $priority
            : "normal";
    }
}

if (!function_exists("truncateIncidentText")) {
    function truncateIncidentText(string $value, int $maxLength): string
    {
        if ($maxLength <= 0 || incidentTextLength($value) <= $maxLength) {
            return $value;
        }

        if (function_exists("mb_substr")) {
            return rtrim(mb_substr($value, 0, max(1, $maxLength - 3), "UTF-8")) . "...";
        }

        return rtrim(substr($value, 0, max(1, $maxLength - 3))) . "...";
    }
}

if (!function_exists("isApprovedBorrowerAccount")) {
    function isApprovedBorrowerAccount(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM users
            WHERE user_id = :user_id
              AND role = 'Borrower'
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([":user_id" => $userId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("isApprovedAdminAccount")) {
    function isApprovedAdminAccount(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT 1
            FROM users
            WHERE user_id = :user_id
              AND role = 'Admin'
              AND account_status = 'Approved'
              AND email_verified = 1
            LIMIT 1
        ");
        $stmt->execute([":user_id" => $userId]);

        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists("getIncidentStatusNotification")) {
    function getIncidentStatusNotification(string $status, int $incidentId): ?array
    {
        $incidentNumber = formatIncidentNumber($incidentId);
        $link = "view_incident.php?incident_id=" . $incidentId;

        $messages = [
            "Under Review" => [
                "type" => "INCIDENT_UNDER_REVIEW",
                "title" => "Incident Under Review",
                "message" => "{$incidentNumber} is now being reviewed by the General Services Office.",
            ],
            "In Progress" => [
                "type" => "INCIDENT_IN_PROGRESS",
                "title" => "Incident In Progress",
                "message" => "Action is now being taken regarding {$incidentNumber}.",
            ],
            "Resolved" => [
                "type" => "INCIDENT_RESOLVED",
                "title" => "Incident Resolved",
                "message" => "{$incidentNumber} has been marked as resolved.",
            ],
            "Rejected" => [
                "type" => "INCIDENT_REJECTED",
                "title" => "Incident Rejected",
                "message" => "{$incidentNumber} was reviewed and could not be processed.",
            ],
        ];

        if (!isset($messages[$status])) {
            return null;
        }

        return array_merge($messages[$status], ["link" => $link]);
    }
}

if (!function_exists("getIncidentReportableResources")) {
    function getIncidentReportableResources(PDO $pdo): array
    {
        $stmt = $pdo->query("
            SELECT resource_id, resource_name, resource_type, location
            FROM resources
            WHERE is_archived = 0
            ORDER BY resource_type ASC, resource_name ASC, resource_id ASC
        ");

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists("findIncidentReportableResource")) {
    function findIncidentReportableResource(PDO $pdo, int $resourceId): ?array
    {
        if ($resourceId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT resource_id, resource_name, resource_type, location
            FROM resources
            WHERE resource_id = :resource_id
              AND is_archived = 0
            LIMIT 1
        ");
        $stmt->execute([":resource_id" => $resourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("findBorrowerIncidentReport")) {
    function findBorrowerIncidentReport(PDO $pdo, int $incidentId, int $reporterId): ?array
    {
        if ($incidentId <= 0 || $reporterId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                ir.*,
                reporter.full_name AS reporter_name,
                reporter.email AS reporter_email,
                r.resource_name,
                r.resource_type,
                r.location AS resource_location,
                assignee.full_name AS assigned_to_name
            FROM incident_reports ir
            INNER JOIN users reporter
                ON reporter.user_id = ir.reporter_id
            LEFT JOIN resources r
                ON r.resource_id = ir.resource_id
            LEFT JOIN users assignee
                ON assignee.user_id = ir.assigned_to
            WHERE ir.incident_id = :incident_id
              AND ir.reporter_id = :reporter_id
            LIMIT 1
        ");
        $stmt->execute([
            ":incident_id" => $incidentId,
            ":reporter_id" => $reporterId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}

if (!function_exists("findAdminIncidentReport")) {
    function findAdminIncidentReport(PDO $pdo, int $incidentId): ?array
    {
        if ($incidentId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("
            SELECT
                ir.*,
                reporter.full_name AS reporter_name,
                reporter.department AS reporter_department,
                reporter.university_id AS reporter_university_id,
                reporter.account_status AS reporter_account_status,
                r.resource_name,
                r.resource_type,
                r.category AS resource_category,
                r.location AS resource_location,
                r.status AS resource_status,
                r.condition_status AS resource_condition,
                r.total_stock,
                r.available_stock,
                r.capacity,
                r.is_archived AS resource_is_archived,
                assignee.full_name AS assigned_to_name
            FROM incident_reports ir
            INNER JOIN users reporter
                ON reporter.user_id = ir.reporter_id
            LEFT JOIN resources r
                ON r.resource_id = ir.resource_id
            LEFT JOIN users assignee
                ON assignee.user_id = ir.assigned_to
            WHERE ir.incident_id = :incident_id
            LIMIT 1
        ");
        $stmt->execute([":incident_id" => $incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }
}
