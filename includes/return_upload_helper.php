<?php
/**
 * File: /includes/return_upload_helper.php
 *
 * Why:
 * - Validate & store return proof photos in /uploads/returns/
 * - Keep DB storing filenames only (not blobs)
 */

require_once __DIR__ . "/upload_helper.php";

if (!function_exists("returnUploadDir")) {
    function returnUploadDir(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "returns" . DIRECTORY_SEPARATOR;
    }
}

if (!function_exists("returnPhotoPublicPath")) {
    function returnPhotoPublicPath(?string $filename, string $prefix = "../"): string
    {
        if (empty($filename)) {
            return "";
        }

        $safe = basename($filename);
        return $prefix . "uploads/returns/" . rawurlencode($safe);
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

if (!function_exists("deleteReturnPhotoFile")) {
    function deleteReturnPhotoFile(string $filename): void
    {
        if ($filename === "") {
            return;
        }

        $safe = basename($filename);
        $path = returnUploadDir() . $safe;

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
