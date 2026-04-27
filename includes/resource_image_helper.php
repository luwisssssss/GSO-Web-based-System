<?php

require_once __DIR__ . "/upload_helper.php";

function resourceImageUploadDir()
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . "uploads" . DIRECTORY_SEPARATOR . "resources" . DIRECTORY_SEPARATOR;
}

function resourceImagePlaceholder()
{
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400">
        <rect width="100%" height="100%" fill="#eef2f8"/>
        <text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#64748b" font-family="Arial, sans-serif" font-size="24">
            No Image
        </text>
    </svg>';

    return "data:image/svg+xml;charset=UTF-8," . rawurlencode($svg);
}

function resourceImagePublicPath($filename, $prefix = "../")
{
    if (empty($filename)) {
        return resourceImagePlaceholder();
    }

    $safeName = basename($filename);
    $fullPath = resourceImageUploadDir() . $safeName;

    if (!is_file($fullPath)) {
        return resourceImagePlaceholder();
    }

    return $prefix . "uploads/resources/" . rawurlencode($safeName);
}

function deleteResourceImage($filename)
{
    if (empty($filename)) {
        return;
    }

    $safeName = basename($filename);
    $fullPath = resourceImageUploadDir() . $safeName;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function uploadResourceImage($file, $oldFilename = null)
{
    if (
        empty($file) ||
        !isset($file["error"]) ||
        $file["error"] === UPLOAD_ERR_NO_FILE
    ) {
        return [
            "success" => true,
            "filename" => $oldFilename,
            "message" => ""
        ];
    }

    $allowedMimeTypes = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/gif"  => "gif",
        "image/webp" => "webp"
    ];

    $validation = gsoValidateUploadedImage(
        $file,
        $allowedMimeTypes,
        5 * 1024 * 1024,
        "JPG, PNG, GIF, and WEBP"
    );

    if (!($validation["success"] ?? false)) {
        return [
            "success" => false,
            "filename" => $oldFilename,
            "message" => (string) ($validation["message"] ?? "Image validation failed.")
        ];
    }

    $uploadDir = resourceImageUploadDir();

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return [
                "success" => false,
                "filename" => $oldFilename,
                "message" => "Failed to create image upload folder."
            ];
        }
    }

    $extension = $allowedMimeTypes[(string) $validation["mime"]];
    $newFileName = "res_" . date("Ymd_His") . "_" . bin2hex(random_bytes(4)) . "." . $extension;
    $destination = $uploadDir . $newFileName;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        return [
            "success" => false,
            "filename" => $oldFilename,
            "message" => "Failed to save uploaded image."
        ];
    }

    if (!empty($oldFilename)) {
        deleteResourceImage($oldFilename);
    }

    return [
        "success" => true,
        "filename" => $newFileName,
        "message" => ""
    ];
}
