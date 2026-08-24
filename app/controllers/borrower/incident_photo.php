<?php
require_once dirname(__DIR__, 3) . "/includes/borrower_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/incident_helper.php";
require_once dirname(__DIR__, 3) . "/includes/incident_upload_helper.php";

if (!function_exists("sendIncidentPhotoNotFound")) {
    function sendIncidentPhotoNotFound(): void
    {
        http_response_code(404);
        header("Content-Type: text/plain; charset=UTF-8");
        header("Cache-Control: private, no-store, max-age=0");
        if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) !== "HEAD") {
            echo "Incident photo not found.";
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

$borrowerId = (int) ($_SESSION["user_id"] ?? 0);
$incidentId = filter_input(INPUT_GET, "incident_id", FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);

if (!is_int($incidentId)
    || $incidentId <= 0
    || !isApprovedBorrowerAccount($pdo, $borrowerId)
) {
    sendIncidentPhotoNotFound();
}

$stmt = $pdo->prepare("
    SELECT photo_path
    FROM incident_reports
    WHERE incident_id = :incident_id
      AND reporter_id = :reporter_id
    LIMIT 1
");
$stmt->execute([
    ":incident_id" => $incidentId,
    ":reporter_id" => $borrowerId,
]);
$photoFilename = $stmt->fetchColumn();

if (!is_string($photoFilename) || !isValidIncidentPhotoFilename($photoFilename)) {
    sendIncidentPhotoNotFound();
}

$photoPath = resolveIncidentPhotoPath($photoFilename);
if ($photoPath === null) {
    sendIncidentPhotoNotFound();
}

$fileSize = filesize($photoPath);
if (!is_int($fileSize) || $fileSize <= 0 || $fileSize > incidentPhotoMaxBytes()) {
    sendIncidentPhotoNotFound();
}

$extension = strtolower((string) pathinfo($photoFilename, PATHINFO_EXTENSION));
$expectedMime = $extension === "jpg" ? "image/jpeg" : ($extension === "png" ? "image/png" : "");
$detectedMime = gsoDetectUploadedMime($photoPath);
$imageInfo = @getimagesize($photoPath);
$imageMime = is_array($imageInfo) ? (string) ($imageInfo["mime"] ?? "") : "";

if ($expectedMime === ""
    || $detectedMime === ""
    || !hash_equals($expectedMime, $detectedMime)
    || !hash_equals($expectedMime, $imageMime)
) {
    sendIncidentPhotoNotFound();
}

$downloadName = "incident-" . formatIncidentNumber($incidentId) . "-photo." . $extension;
$handle = null;

if ($method === "GET") {
    $handle = fopen($photoPath, "rb");
    if ($handle === false) {
        sendIncidentPhotoNotFound();
    }
}

header("Content-Type: " . $expectedMime);
header('Content-Disposition: inline; filename="' . $downloadName . '"');
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
