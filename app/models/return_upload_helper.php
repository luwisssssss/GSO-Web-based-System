<?php
/**
 * File: /includes/return_upload_helper.php
 *
 * Why:
 * - Validate & store return proof photos in the protected /uploads/returns/ folder
 * - Keep direct HTTP access disabled; authorized views use the Admin stream endpoint
 * - Keep DB storing filenames only (not blobs)
 */

require_once __DIR__ . "/upload_helper.php";

if (!function_exists("returnUploadDir")) {
    function returnUploadDir(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "returns" . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists("normalizeUploadedFiles")) {
    function normalizeUploadedFiles(array $files): array
    {
        if (!isset($files["name"]) || !is_array($files["name"])) {
            return [$files];
        }

        $normalized = [];
        $count = count($files["name"]);

        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                "name" => $files["name"][$i] ?? "",
                "type" => $files["type"][$i] ?? "",
                "tmp_name" => $files["tmp_name"][$i] ?? "",
                "error" => $files["error"][$i] ?? UPLOAD_ERR_NO_FILE,
                "size" => $files["size"][$i] ?? 0,
            ];
        }

        return $normalized;
    }
}

if (!function_exists("uploadReturnPhoto")) {
    function uploadReturnPhoto(array $file): array
    {
        $allowed = [
            "image/jpeg" => "jpg",
            "image/png" => "png",
            "image/gif" => "gif",
            "image/webp" => "webp",
        ];

        $validation = gsoValidateUploadedImage(
            $file,
            $allowed,
            5 * 1024 * 1024,
            "JPG, PNG, GIF, and WEBP",
            "return photo"
        );

        if (!($validation["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($validation["message"] ?? "Return photo validation failed."),
            ];
        }

        $dir = returnUploadDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ["success" => false, "message" => "Failed to create upload folder."];
            }
        }

        $ext = $allowed[(string) $validation["mime"]];
        $filename = "ret_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $ext;
        $dest = $dir . $filename;

        if (!move_uploaded_file($file["tmp_name"], $dest)) {
            return ["success" => false, "message" => "Failed to save photo."];
        }

        return [
            "success" => true,
            "filename" => $filename,
            "mime" => (string) $validation["mime"]
        ];
    }
}

if (!function_exists("isValidReturnPhotoFilename")) {
    function isValidReturnPhotoFilename(?string $filename): bool
    {
        return preg_match(
            '/\Aret_[0-9]{8}_[0-9]{6}_[a-f0-9]{8}\.(jpg|png|gif|webp)\z/',
            (string) $filename
        ) === 1;
    }
}

if (!function_exists("resolveReturnPhotoPath")) {
    function resolveReturnPhotoPath(string $filename): ?string
    {
        if (!isValidReturnPhotoFilename($filename)) {
            return null;
        }

        $directory = realpath(returnUploadDir());
        if ($directory === false || !is_dir($directory) || is_link($directory)) {
            return null;
        }

        $candidate = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_link($candidate)) {
            return null;
        }

        $realPath = realpath($candidate);
        if ($realPath === false || !is_file($realPath) || is_link($realPath)) {
            return null;
        }

        $normalizedDirectory = rtrim(str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $directory), DIRECTORY_SEPARATOR);
        $normalizedPath = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $realPath);
        if (DIRECTORY_SEPARATOR === "\\") {
            $normalizedDirectory = strtolower($normalizedDirectory);
            $normalizedPath = strtolower($normalizedPath);
        }

        if (!str_starts_with($normalizedPath, $normalizedDirectory . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realPath;
    }
}

if (!function_exists("deleteReturnPhotoFile")) {
    function deleteReturnPhotoFile(string $filename): void
    {
        if ($filename === "") {
            return;
        }

        $path = resolveReturnPhotoPath($filename);

        if ($path !== null) {
            @unlink($path);
        }
    }
}
