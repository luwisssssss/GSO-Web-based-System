<?php

require_once __DIR__ . "/schema_helper.php";

if (!function_exists("avatarColumnsAvailable")) {
    function avatarColumnsAvailable(PDO $pdo): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        $available = columnExists($pdo, "users", "profile_image")
            && columnExists($pdo, "users", "profile_image_type");

        return $available;
    }
}

if (!function_exists("buildAvatarSource")) {
    function buildAvatarSource(array $userRow, string $defaultPath): string
    {
        if (!empty($userRow["profile_image"]) && !empty($userRow["profile_image_type"])) {
            return "data:" . $userRow["profile_image_type"] . ";base64," . base64_encode($userRow["profile_image"]);
        }

        if (
            !empty($userRow["uploaded_id"]) &&
            !empty($userRow["uploaded_id_type"]) &&
            strpos((string) $userRow["uploaded_id_type"], "image/") === 0
        ) {
            return "data:" . $userRow["uploaded_id_type"] . ";base64," . base64_encode($userRow["uploaded_id"]);
        }

        return $defaultPath;
    }
}

if (!function_exists("getUserAvatarSource")) {
    function getUserAvatarSource(PDO $pdo, int $userId, string $defaultPath): string
    {
        if ($userId <= 0) {
            return $defaultPath;
        }

        $supportsAvatarColumns = avatarColumnsAvailable($pdo);

        $select = $supportsAvatarColumns
            ? "profile_image, profile_image_type, uploaded_id, uploaded_id_type"
            : "uploaded_id, uploaded_id_type";

        $stmt = $pdo->prepare("
            SELECT {$select}
            FROM users
            WHERE user_id = :user_id
            LIMIT 1
        ");
        $stmt->execute([
            ":user_id" => $userId
        ]);

        $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if (!$supportsAvatarColumns) {
            $userRow["profile_image"] = null;
            $userRow["profile_image_type"] = null;
        }

        return buildAvatarSource($userRow, $defaultPath);
    }
}
