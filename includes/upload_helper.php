<?php

if (!function_exists("gsoParseIniSizeToBytes")) {
    function gsoParseIniSizeToBytes(string $value): int
    {
        $trimmed = trim($value);

        if ($trimmed === "") {
            return 0;
        }

        $unit = strtolower(substr($trimmed, -1));
        $number = (float) $trimmed;

        switch ($unit) {
            case "g":
                $number *= 1024;
                // no break
            case "m":
                $number *= 1024;
                // no break
            case "k":
                $number *= 1024;
                break;
        }

        return (int) round($number);
    }
}

if (!function_exists("gsoFormatBytesLabel")) {
    function gsoFormatBytesLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1) . " GB";
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1) . " MB";
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . " KB";
        }

        return $bytes . " bytes";
    }
}

if (!function_exists("gsoEffectiveUploadLimitBytes")) {
    function gsoEffectiveUploadLimitBytes(?int $applicationLimit = null): int
    {
        $limits = [];

        $uploadMax = gsoParseIniSizeToBytes((string) ini_get("upload_max_filesize"));
        $postMax = gsoParseIniSizeToBytes((string) ini_get("post_max_size"));

        if ($uploadMax > 0) {
            $limits[] = $uploadMax;
        }

        if ($postMax > 0) {
            $limits[] = $postMax;
        }

        if ($applicationLimit !== null && $applicationLimit > 0) {
            $limits[] = $applicationLimit;
        }

        if (count($limits) === 0) {
            return 0;
        }

        return min($limits);
    }
}

if (!function_exists("gsoUploadErrorMessage")) {
    function gsoUploadErrorMessage(int $errorCode, string $label = "file", ?int $applicationLimit = null): string
    {
        $label = trim($label) !== "" ? trim($label) : "file";
        $effectiveLimit = gsoEffectiveUploadLimitBytes($applicationLimit);
        $limitLabel = $effectiveLimit > 0 ? gsoFormatBytesLabel($effectiveLimit) : "";

        switch ($errorCode) {
            case UPLOAD_ERR_OK:
                return "";
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                if ($limitLabel !== "") {
                    return "The selected {$label} exceeds the upload limit of {$limitLabel}.";
                }

                return "The selected {$label} exceeds the current upload limit.";
            case UPLOAD_ERR_PARTIAL:
                return "The {$label} upload was interrupted. Please try again.";
            case UPLOAD_ERR_NO_FILE:
                return "Please choose a {$label} to upload.";
            case UPLOAD_ERR_NO_TMP_DIR:
                return "Upload failed because the temporary upload folder is unavailable.";
            case UPLOAD_ERR_CANT_WRITE:
                return "Upload failed because the server could not write the {$label}.";
            case UPLOAD_ERR_EXTENSION:
                return "Upload stopped because of a server extension restriction.";
            default:
                return "Upload failed for the selected {$label}.";
        }
    }
}

if (!function_exists("gsoDetectUploadedMime")) {
    function gsoDetectUploadedMime(string $tmpPath): string
    {
        if ($tmpPath === "" || !is_file($tmpPath)) {
            return "";
        }

        if (function_exists("finfo_open")) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = (string) finfo_file($finfo, $tmpPath);
                finfo_close($finfo);

                if ($mime !== "") {
                    return $mime;
                }
            }
        }

        if (function_exists("mime_content_type")) {
            return (string) mime_content_type($tmpPath);
        }

        return "";
    }
}

if (!function_exists("gsoValidateUploadedImage")) {
    function gsoValidateUploadedImage(
        array $file,
        array $allowedMimeTypes,
        int $maxBytes,
        string $allowedLabel,
        string $label = "image"
    ): array {
        $label = trim($label) !== "" ? trim($label) : "image";
        $lowerLabel = strtolower($label);
        $errorCode = (int) ($file["error"] ?? UPLOAD_ERR_NO_FILE);

        if ($errorCode !== UPLOAD_ERR_OK) {
            return [
                "success" => false,
                "message" => gsoUploadErrorMessage($errorCode, $label, $maxBytes),
                "mime" => "",
                "size" => 0,
            ];
        }

        if (!isset($file["tmp_name"]) || !is_uploaded_file((string) $file["tmp_name"])) {
            return [
                "success" => false,
                "message" => "Invalid uploaded {$lowerLabel} file.",
                "mime" => "",
                "size" => 0,
            ];
        }

        $size = (int) ($file["size"] ?? 0);

        if ($size <= 0) {
            return [
                "success" => false,
                "message" => "The selected {$lowerLabel} is empty.",
                "mime" => "",
                "size" => 0,
            ];
        }

        if ($maxBytes > 0 && $size > $maxBytes) {
            return [
                "success" => false,
                "message" => ucfirst($lowerLabel) . " must be " . gsoFormatBytesLabel($maxBytes) . " or less.",
                "mime" => "",
                "size" => $size,
            ];
        }

        $mime = gsoDetectUploadedMime((string) $file["tmp_name"]);
        $imageInfo = @getimagesize((string) $file["tmp_name"]);

        if ($mime === "" || $imageInfo === false) {
            return [
                "success" => false,
                "message" => "The uploaded file is not a valid {$lowerLabel}.",
                "mime" => "",
                "size" => $size,
            ];
        }

        if (!isset($allowedMimeTypes[$mime])) {
            return [
                "success" => false,
                "message" => "Only {$allowedLabel} files are allowed for the {$lowerLabel}.",
                "mime" => $mime,
                "size" => $size,
            ];
        }

        return [
            "success" => true,
            "message" => "",
            "mime" => $mime,
            "size" => $size,
        ];
    }
}

if (!function_exists("gsoStoreUserProfileImage")) {
    function gsoStoreUserProfileImage(PDO $pdo, int $userId, string $role, array $file, int $maxBytes = 5242880): array
    {
        $allowedMimeTypes = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/webp" => "webp",
            "image/gif" => "gif",
        ];

        $validation = gsoValidateUploadedImage(
            $file,
            $allowedMimeTypes,
            $maxBytes,
            "JPG, PNG, GIF, and WEBP",
            "profile image"
        );

        if (!($validation["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($validation["message"] ?? "Profile image validation failed."),
            ];
        }

        $imageData = @file_get_contents((string) ($file["tmp_name"] ?? ""));

        if ($imageData === false) {
            return [
                "success" => false,
                "message" => "Failed to read the uploaded profile image.",
            ];
        }

        $role = $role === "Admin" ? "Admin" : "Borrower";
        $stmt = $pdo->prepare("
            UPDATE users
            SET profile_image = :profile_image,
                profile_image_type = :profile_image_type
            WHERE user_id = :user_id
              AND role = :role
            LIMIT 1
        ");
        $stmt->bindValue(":profile_image", $imageData, PDO::PARAM_LOB);
        $stmt->bindValue(":profile_image_type", (string) $validation["mime"]);
        $stmt->bindValue(":user_id", $userId, PDO::PARAM_INT);
        $stmt->bindValue(":role", $role, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() !== 1) {
            return [
                "success" => false,
                "message" => "Profile image could not be updated for this account.",
            ];
        }

        return [
            "success" => true,
            "message" => "Profile image updated successfully.",
        ];
    }
}
