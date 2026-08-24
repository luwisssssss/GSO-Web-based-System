<?php
require_once dirname(__DIR__, 3) . "/includes/admin_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/return_upload_helper.php";

if (!function_exists("sendAdminReturnPhotoNotFound")) {
    function sendAdminReturnPhotoNotFound(): void
    {
        http_response_code(404);
        header("Content-Type: text/plain; charset=UTF-8");
        header("Cache-Control: private, no-store, max-age=0");
        if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) !== "HEAD") {
            echo "Return photo not found.";
        }
        exit();
    }
}

$method = strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET"));
if (!in_array($method, ["GET", "HEAD"], true)) {
    http_response_code(405);
    header("Allow: GET, HEAD");
    exit();
}

$adminId = (int) ($_SESSION["user_id"] ?? 0);
$photoId = filter_input(INPUT_GET, "photo_id", FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);
if (!is_int($photoId) || $photoId <= 0 || !isApprovedAdminAccount($pdo, $adminId)) {
    sendAdminReturnPhotoNotFound();
}

$photoStmt = $pdo->prepare("
    SELECT rsp.filename, rsp.mime_type
    FROM return_submission_photos rsp
    INNER JOIN return_submissions rs ON rs.return_id = rsp.return_id
    INNER JOIN resource_requests rr ON rr.request_id = rs.request_id
    WHERE rsp.photo_id = :photo_id
    LIMIT 1
");
$photoStmt->execute([":photo_id" => $photoId]);
$photo = $photoStmt->fetch(PDO::FETCH_ASSOC);
if (!$photo || !isValidReturnPhotoFilename((string) ($photo["filename"] ?? ""))) {
    sendAdminReturnPhotoNotFound();
}

$photoPath = resolveReturnPhotoPath((string) $photo["filename"]);
if ($photoPath === null) {
    sendAdminReturnPhotoNotFound();
}

$mimeByExtension = [
    "jpg" => "image/jpeg",
    "png" => "image/png",
    "gif" => "image/gif",
    "webp" => "image/webp",
];
$extension = strtolower((string) pathinfo((string) $photo["filename"], PATHINFO_EXTENSION));
$expectedMime = $mimeByExtension[$extension] ?? "";
$storedMime = strtolower(trim((string) ($photo["mime_type"] ?? "")));
$detectedMime = gsoDetectUploadedMime($photoPath);
$imageInfo = @getimagesize($photoPath);
$imageMime = is_array($imageInfo) ? strtolower((string) ($imageInfo["mime"] ?? "")) : "";
$fileSize = filesize($photoPath);

if ($expectedMime === ""
    || !hash_equals($expectedMime, $storedMime)
    || !hash_equals($expectedMime, $detectedMime)
    || !hash_equals($expectedMime, $imageMime)
    || !is_int($fileSize)
    || $fileSize <= 0
    || $fileSize > 5 * 1024 * 1024
) {
    sendAdminReturnPhotoNotFound();
}

$handle = null;
if ($method === "GET") {
    $handle = fopen($photoPath, "rb");
    if ($handle === false) {
        sendAdminReturnPhotoNotFound();
    }
}

header("Content-Type: " . $expectedMime);
header('Content-Disposition: inline; filename="return-proof-' . $photoId . '.' . $extension . '"');
header("Content-Length: " . $fileSize);
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");
header("Referrer-Policy: no-referrer");
header("Cross-Origin-Resource-Policy: same-origin");
header("Content-Security-Policy: default-src 'none'; sandbox");
session_write_close();

if ($method === "HEAD") {
    exit();
}

fpassthru($handle);
fclose($handle);
exit();
