<?php

require_once dirname(__DIR__, 2) . "/config/bootstrap.php";
require_once __DIR__ . "/upload_helper.php";

if (!function_exists("incidentPhotoMaxBytes")) {
    function incidentPhotoMaxBytes(): int
    {
        return 5 * 1024 * 1024;
    }
}

if (!function_exists("incidentPhotoAllowedMimeTypes")) {
    function incidentPhotoAllowedMimeTypes(): array
    {
        return [
            "image/jpeg" => "jpg",
            "image/png" => "png",
        ];
    }
}

if (!function_exists("incidentPathIsAbsolute")) {
    function incidentPathIsAbsolute(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, DIRECTORY_SEPARATOR)
            || str_starts_with($path, "/");
    }
}

if (!function_exists("incidentNormalizeFilesystemPath")) {
    function incidentNormalizeFilesystemPath(string $path): string
    {
        return rtrim(str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }
}

if (!function_exists("incidentPathIsWithin")) {
    function incidentPathIsWithin(string $path, string $root): bool
    {
        $path = incidentNormalizeFilesystemPath($path);
        $root = incidentNormalizeFilesystemPath($root);

        if (DIRECTORY_SEPARATOR === "\\") {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }
}

if (!function_exists("incidentDefaultPhotoDirectory")) {
    function incidentDefaultPhotoDirectory(): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $privateBase = dirname($projectRoot, 2);

        return $privateBase
            . DIRECTORY_SEPARATOR . "private"
            . DIRECTORY_SEPARATOR . "GSO_WebSystem"
            . DIRECTORY_SEPARATOR . "incident_photos";
    }
}

if (!function_exists("incidentPhotoStorageRoot")) {
    function incidentPhotoStorageRoot(bool $mustBeWritable = false): string
    {
        $configured = trim((string) gsoEnv("GSO_INCIDENT_PHOTO_DIR", ""));
        $directory = $configured !== "" ? $configured : incidentDefaultPhotoDirectory();

        if (!incidentPathIsAbsolute($directory)) {
            throw new RuntimeException("Incident photo storage must use an absolute path.");
        }

        $directory = incidentNormalizeFilesystemPath($directory);

        // Reject the configured path itself before realpath() resolves the link target.
        if (is_link($directory)) {
            throw new RuntimeException("Incident photo storage cannot be a symbolic link.");
        }

        if (!is_dir($directory)) {
            // Another request may create the directory after the check above.
            // Suppress only mkdir's race warning, then verify the final state.
            if (!@mkdir($directory, 0750, true) && !is_dir($directory)) {
                throw new RuntimeException("Incident photo storage is unavailable.");
            }
        }

        $realDirectory = realpath($directory);
        if ($realDirectory === false
            || !is_dir($realDirectory)
            || is_link($directory)
            || is_link($realDirectory)
        ) {
            throw new RuntimeException("Incident photo storage is invalid.");
        }

        $projectRoot = realpath(dirname(__DIR__, 2));
        if ($projectRoot !== false && incidentPathIsWithin($realDirectory, $projectRoot)) {
            throw new RuntimeException("Incident photos cannot be stored inside the application directory.");
        }

        $documentRootValue = trim((string) ($_SERVER["DOCUMENT_ROOT"] ?? ""));
        $documentRoot = $documentRootValue !== "" ? realpath($documentRootValue) : false;
        if ($documentRoot !== false && incidentPathIsWithin($realDirectory, $documentRoot)) {
            throw new RuntimeException("Incident photos cannot be stored inside the public web root.");
        }

        if (!is_readable($realDirectory) || ($mustBeWritable && !is_writable($realDirectory))) {
            throw new RuntimeException("Incident photo storage permissions are invalid.");
        }

        return incidentNormalizeFilesystemPath($realDirectory);
    }
}

if (!function_exists("isValidIncidentPhotoFilename")) {
    function isValidIncidentPhotoFilename(?string $filename): bool
    {
        return preg_match('/\Ainc_[a-f0-9]{32}\.(jpg|png)\z/', (string) $filename) === 1;
    }
}

if (!function_exists("storeIncidentPhoto")) {
    function storeIncidentPhoto(array $file): array
    {
        $allowedMimeTypes = incidentPhotoAllowedMimeTypes();
        $validation = gsoValidateUploadedImage(
            $file,
            $allowedMimeTypes,
            incidentPhotoMaxBytes(),
            "JPG and PNG",
            "incident photo"
        );

        if (!($validation["success"] ?? false)) {
            return [
                "success" => false,
                "message" => (string) ($validation["message"] ?? "Incident photo validation failed."),
            ];
        }

        $tmpPath = (string) ($file["tmp_name"] ?? "");
        $imageInfo = @getimagesize($tmpPath);
        $mime = (string) ($validation["mime"] ?? "");
        $imageMime = is_array($imageInfo) ? (string) ($imageInfo["mime"] ?? "") : "";
        $width = is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : 0;
        $height = is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : 0;

        if ($imageMime === "" || !hash_equals($mime, $imageMime)) {
            return ["success" => false, "message" => "The incident photo format could not be verified."];
        }

        if ($width <= 0 || $height <= 0 || $width > 4096 || $height > 4096 || ($width * $height) > 12000000) {
            return ["success" => false, "message" => "The incident photo dimensions are too large."];
        }

        if ($mime === "image/jpeg" && function_exists("exif_read_data")) {
            $exif = @exif_read_data($tmpPath, "GPS", true, false);
            if (is_array($exif) && !empty($exif["GPS"])) {
                return [
                    "success" => false,
                    "message" => "Please remove location metadata from the photo before uploading it.",
                ];
            }
        }

        try {
            $directory = incidentPhotoStorageRoot(true);
            $extension = $allowedMimeTypes[$mime];
            $filename = "";
            $destination = "";

            for ($attempt = 0; $attempt < 5; $attempt++) {
                $filename = "inc_" . bin2hex(random_bytes(16)) . "." . $extension;
                $destination = $directory . DIRECTORY_SEPARATOR . $filename;

                if (!file_exists($destination)) {
                    break;
                }

                $filename = "";
                $destination = "";
            }

            if ($filename === "" || $destination === "") {
                throw new RuntimeException("Could not allocate a secure photo filename.");
            }

            if (!move_uploaded_file($tmpPath, $destination)) {
                throw new RuntimeException("Could not save the incident photo.");
            }

            if (DIRECTORY_SEPARATOR !== "\\" && !chmod($destination, 0640)) {
                if (!unlink($destination)) {
                    error_log("Incident photo permission cleanup failed for a newly stored file.");
                }
                throw new RuntimeException("Could not secure the incident photo permissions.");
            }

            return [
                "success" => true,
                "filename" => $filename,
                "mime" => $mime,
                "size" => (int) ($validation["size"] ?? 0),
                "width" => $width,
                "height" => $height,
            ];
        } catch (Throwable $e) {
            error_log("Incident photo storage failed: " . $e->getMessage());
            return ["success" => false, "message" => "The incident photo could not be stored securely."];
        }
    }
}

if (!function_exists("resolveIncidentPhotoPath")) {
    function resolveIncidentPhotoPath(string $filename): ?string
    {
        if (!isValidIncidentPhotoFilename($filename)) {
            return null;
        }

        try {
            $directory = incidentPhotoStorageRoot(false);
        } catch (Throwable $e) {
            error_log("Incident photo lookup failed: " . $e->getMessage());
            return null;
        }

        $candidate = $directory . DIRECTORY_SEPARATOR . $filename;
        if (is_link($candidate)) {
            return null;
        }

        $realPath = realpath($candidate);

        if ($realPath === false
            || !is_file($realPath)
            || is_link($realPath)
            || !incidentPathIsWithin($realPath, $directory)
        ) {
            return null;
        }

        return $realPath;
    }
}

if (!function_exists("deleteIncidentPhoto")) {
    function deleteIncidentPhoto(string $filename): bool
    {
        $path = resolveIncidentPhotoPath($filename);
        if ($path === null) {
            return false;
        }

        if (!unlink($path)) {
            error_log("Incident photo cleanup failed for incident upload rollback.");
            return false;
        }

        return true;
    }
}
