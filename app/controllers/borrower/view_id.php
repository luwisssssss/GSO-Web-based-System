<?php
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/config/db.php";

if (!function_exists("sendUploadedIdNotFound")) {
    function sendUploadedIdNotFound(): void
    {
        http_response_code(404);
        header("Content-Type: text/plain; charset=UTF-8");
        header("Cache-Control: private, no-store, max-age=0");

        if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) !== "HEAD") {
            echo "Uploaded ID not found.";
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

$requestedUserId = filter_input(INPUT_GET, "user_id", FILTER_VALIDATE_INT, [
    "options" => ["min_range" => 1],
]);
$loggedInUserId = (int) ($_SESSION["user_id"] ?? 0);

if (!is_int($requestedUserId) || $requestedUserId <= 0 || $loggedInUserId <= 0) {
    sendUploadedIdNotFound();
}

$actorStmt = $pdo->prepare("
    SELECT role, account_status, email_verified
    FROM users
    WHERE user_id = :user_id
    LIMIT 1
");
$actorStmt->execute([":user_id" => $loggedInUserId]);
$actor = $actorStmt->fetch(PDO::FETCH_ASSOC);

$isApprovedAdmin = is_array($actor)
    && (string) ($actor["role"] ?? "") === "Admin"
    && (string) ($actor["account_status"] ?? "") === "Approved"
    && (int) ($actor["email_verified"] ?? 0) === 1;
$isApprovedOwner = is_array($actor)
    && $requestedUserId === $loggedInUserId
    && (string) ($actor["role"] ?? "") === "Borrower"
    && (string) ($actor["account_status"] ?? "") === "Approved"
    && (int) ($actor["email_verified"] ?? 0) === 1;

if (!$isApprovedAdmin && !$isApprovedOwner) {
    sendUploadedIdNotFound();
}

$idStmt = $pdo->prepare("
    SELECT uploaded_id, uploaded_id_type
    FROM users
    WHERE user_id = :user_id
    LIMIT 1
");
$idStmt->execute([":user_id" => $requestedUserId]);
$user = $idStmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($user) || empty($user["uploaded_id"]) || empty($user["uploaded_id_type"])) {
    sendUploadedIdNotFound();
}

$data = $user["uploaded_id"];
if (is_resource($data)) {
    $data = stream_get_contents($data);
}
if (!is_string($data) || $data === "" || strlen($data) > 5 * 1024 * 1024) {
    sendUploadedIdNotFound();
}

$storedMime = strtolower(trim((string) $user["uploaded_id_type"]));
$allowedTypes = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "application/pdf" => "pdf",
];
if (!isset($allowedTypes[$storedMime])) {
    sendUploadedIdNotFound();
}

$detectedMime = "";
if (function_exists("finfo_open")) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detectedMime = strtolower((string) finfo_buffer($finfo, $data));
        finfo_close($finfo);
    }
}

if (str_starts_with($storedMime, "image/")) {
    $imageInfo = @getimagesizefromstring($data);
    $imageMime = is_array($imageInfo) ? strtolower((string) ($imageInfo["mime"] ?? "")) : "";
    if ($detectedMime === "" || !hash_equals($storedMime, $detectedMime) || !hash_equals($storedMime, $imageMime)) {
        sendUploadedIdNotFound();
    }
} elseif ($detectedMime === ""
    || !hash_equals("application/pdf", $detectedMime)
    || !str_starts_with($data, "%PDF-")
) {
    sendUploadedIdNotFound();
}

$extension = $allowedTypes[$storedMime];
header("Content-Type: " . $storedMime);
header('Content-Disposition: inline; filename="uploaded-id-' . $requestedUserId . '.' . $extension . '"');
header("Content-Length: " . strlen($data));
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

echo $data;
exit();
