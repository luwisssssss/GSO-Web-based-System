<?php
declare(strict_types=1);

/**
 * Step 6 incident workflow smoke test.
 *
 * Run from a CLI while local Apache/MySQL are available:
 *   C:\xampp\php\php.exe scripts\step6_incident_e2e.php
 *
 * The harness refuses non-loopback URLs. It creates uniquely named fixtures, uses
 * the real HTTP controllers, restores pre-existing notification read flags, and
 * removes only IDs/files that it created.
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";
require_once $root . "/includes/incident_helper.php";
require_once $root . "/includes/incident_upload_helper.php";
require_once $root . "/includes/notification_helper.php";

final class Step6Failure extends RuntimeException
{
}

function step6Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Step6Failure($message);
    }
}

function step6Rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function step6Value(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function step6DeleteIds(PDO $pdo, string $table, string $column, array $ids): void
{
    $ids = array_values(array_unique(array_filter(array_map("intval", $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return;
    }

    $allowed = [
        "notifications" => "notification_id",
        "activity_logs" => "log_id",
        "maintenance_schedules" => "maintenance_id",
        "incident_reports" => "incident_id",
        "resources" => "resource_id",
    ];
    step6Assert(isset($allowed[$table]) && $allowed[$table] === $column, "Unsafe cleanup target rejected.");
    $placeholders = implode(",", array_fill(0, count($ids), "?"));
    $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})");
    $stmt->execute($ids);
}

function step6Http(
    string $baseUrl,
    string $path,
    string $method = "GET",
    ?string $cookieFile = null,
    array|string|null $data = null,
    array $headers = []
): array {
    $curl = curl_init(rtrim($baseUrl, "/") . "/" . ltrim($path, "/"));
    step6Assert($curl !== false, "Could not initialize HTTP client.");
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => "GSO-Step6-E2E/1.0",
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($method === "POST") {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = is_array($data) ? $data : (string) $data;
        if (!is_array($data)) {
            $hasContentType = false;
            foreach ($headers as $header) {
                if (stripos((string) $header, "Content-Type:") === 0) {
                    $hasContentType = true;
                    break;
                }
            }
            $options[CURLOPT_HTTPHEADER] = $hasContentType
                ? $headers
                : array_merge($headers, ["Content-Type: application/x-www-form-urlencoded"]);
        }
    } elseif ($method === "HEAD") {
        $options[CURLOPT_NOBODY] = true;
    }
    $finalHttpHeaders = $options[CURLOPT_HTTPHEADER];
    unset($options[CURLOPT_HTTPHEADER]);
    curl_setopt_array($curl, $options);
    // Apply custom headers after CURLOPT_POSTFIELDS so libcurl cannot replace the
    // supplied multipart header list while it configures the request body.
    curl_setopt($curl, CURLOPT_HTTPHEADER, $finalHttpHeaders);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    step6Assert(is_string($raw), "HTTP request failed: " . ($error !== "" ? $error : "unknown error"));
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $location = null;
    if (preg_match_all('/^Location:\s*(.+)$/mi', $headerText, $matches) && !empty($matches[1])) {
        $location = trim((string) end($matches[1]));
    }
    return ["status" => $status, "headers" => $headerText, "body" => $body, "location" => $location];
}

function step6PostForm(string $baseUrl, string $path, string $cookie, array $fields): array
{
    return step6Http($baseUrl, $path, "POST", $cookie, http_build_query($fields, "", "&", PHP_QUERY_RFC3986));
}

function step6PostMultipartImage(
    string $baseUrl,
    string $path,
    string $cookie,
    array $fields,
    string $fileField,
    string $filePath,
    string $mimeType,
    string $originalName
): array {
    step6Assert(preg_match('/\A[A-Za-z0-9_]+\z/', $fileField) === 1, "Unsafe multipart file field.");
    step6Assert(preg_match('/\A[A-Za-z0-9_.-]+\z/', $originalName) === 1, "Unsafe multipart filename.");
    $fileBytes = file_get_contents($filePath);
    step6Assert(is_string($fileBytes), "Could not read the multipart image fixture.");

    $boundary = "----GsoStep6" . bin2hex(random_bytes(16));
    $body = "";
    foreach ($fields as $name => $value) {
        step6Assert(preg_match('/\A[A-Za-z0-9_]+\z/', (string) $name) === 1, "Unsafe multipart field.");
        $body .= "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n"
            . (string) $value . "\r\n";
    }
    $body .= "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$originalName}\"\r\n"
        . "Content-Type: {$mimeType}\r\n\r\n"
        . $fileBytes . "\r\n"
        . "--{$boundary}--\r\n";

    return step6Http(
        $baseUrl,
        $path,
        "POST",
        $cookie,
        $body,
        ["Content-Type: multipart/form-data; boundary={$boundary}"]
    );
}

function step6LoginPersona(string $baseUrl, string $bootstrap, string $token, string $persona, string $cookie): array
{
    $response = step6Http(
        $baseUrl,
        $bootstrap,
        "POST",
        $cookie,
        http_build_query(["persona" => $persona], "", "&", PHP_QUERY_RFC3986),
        ["X-Step6-Token: " . $token]
    );
    step6Assert($response["status"] === 200, "Could not create {$persona} test session (HTTP {$response["status"]}).");
    $json = json_decode($response["body"], true);
    step6Assert(is_array($json) && !empty($json["csrf_token"]), "Bootstrap returned invalid session data.");
    return $json;
}

function step6CsrfFromPage(array $response, string $pageName): string
{
    step6Assert($response["status"] === 200, "Could not open {$pageName} (HTTP {$response["status"]}).");
    $body = (string) ($response["body"] ?? "");
    $matched = preg_match(
        '/<input\b(?=[^>]*\bname="csrf_token")(?=[^>]*\bvalue="([^"]+)")[^>]*>/i',
        $body,
        $matches
    );
    $csrf = $matched === 1
        ? html_entity_decode((string) $matches[1], ENT_QUOTES, "UTF-8")
        : "";
    if ($csrf === ""
        && preg_match('/\bconst\s+csrfToken\s*=\s*("(?:[^"\\\\]|\\\\.)*")\s*;/', $body, $scriptMatch) === 1
    ) {
        $decoded = json_decode((string) $scriptMatch[1], true);
        $csrf = is_string($decoded) ? $decoded : "";
    }
    step6Assert($csrf !== "", "Could not read the CSRF token from {$pageName}.");
    return $csrf;
}

function step6IncidentFromRedirect(PDO $pdo, int $reporterId, string $title, array $response): int
{
    if (!in_array($response["status"], [302, 303], true)) {
        $responseBody = (string) ($response["body"] ?? "");
        $bodySummary = "";
        if (preg_match('/<div class="flash-message flash-error".*?<ul[^>]*>(.*?)<\/ul>/si', $responseBody, $errorBlock) === 1
            && preg_match_all('/<li[^>]*>(.*?)<\/li>/si', (string) $errorBlock[1], $errorItems)
        ) {
            $bodySummary = implode(
                " | ",
                array_map(
                    static fn (string $item): string => html_entity_decode(trim(strip_tags($item)), ENT_QUOTES, "UTF-8"),
                    $errorItems[1]
                )
            );
        }
        if ($bodySummary === "") {
            $bodySummary = trim(preg_replace('/\s+/', ' ', strip_tags($responseBody)) ?? "");
        }
        if (strlen($bodySummary) > 500) {
            $bodySummary = substr($bodySummary, 0, 500) . "...";
        }
        throw new Step6Failure(
            "Incident submission did not redirect (HTTP "
            . (int) ($response["status"] ?? 0)
            . "; session-cookie-reset="
            . (preg_match('/^Set-Cookie:\s*GSOSESSID=/mi', (string) ($response["headers"] ?? "")) === 1 ? "yes" : "no")
            . ($bodySummary !== "" ? "; response: {$bodySummary}" : "")
            . ")."
        );
    }
    $location = (string) ($response["location"] ?? "");
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $incidentId = filter_var($query["incident_id"] ?? null, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!is_int($incidentId)) {
        $incidentId = (int) step6Value(
            $pdo,
            "SELECT incident_id FROM incident_reports WHERE reporter_id = :reporter_id AND incident_title = :title ORDER BY incident_id DESC LIMIT 1",
            [":reporter_id" => $reporterId, ":title" => $title]
        );
    }
    step6Assert($incidentId > 0, "Could not identify the submitted incident.");
    return $incidentId;
}

function step6Status(
    string $baseUrl,
    string $cookie,
    string $csrf,
    int $incidentId,
    string $expected,
    string $next,
    string $currentRemarks = "",
    string $remarks = "",
    string $resolution = ""
): array {
    return step6PostForm($baseUrl, "admin/view_incident.php?incident_id={$incidentId}", $cookie, [
        "csrf_token" => $csrf,
        "action" => "change_status",
        "incident_id" => (string) $incidentId,
        "expected_status" => $expected,
        "next_status" => $next,
        "expected_remarks_hash" => hash("sha256", $currentRemarks),
        "status_remarks" => $remarks,
        "resolution_notes" => $resolution,
    ]);
}

function step6RestoreNotificationFlags(PDO $pdo, array $snapshot): void
{
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = :is_read WHERE notification_id = :notification_id LIMIT 1");
    foreach ($snapshot as $notificationId => $isRead) {
        $stmt->execute([":is_read" => (int) $isRead, ":notification_id" => (int) $notificationId]);
    }
}

$baseUrl = "http://127.0.0.1/GSO_WebSystem";
foreach ($argv as $argument) {
    if (str_starts_with($argument, "--base-url=")) {
        $baseUrl = rtrim(substr($argument, strlen("--base-url=")), "/");
    }
}
$baseParts = parse_url($baseUrl);
$host = strtolower((string) ($baseParts["host"] ?? ""));
step6Assert(in_array($host, ["127.0.0.1", "localhost", "::1"], true), "Refusing to run against a non-loopback host.");
step6Assert(in_array(strtolower((string) ($baseParts["scheme"] ?? "")), ["http", "https"], true), "Invalid base URL.");
step6Assert(extension_loaded("curl"), "The PHP cURL extension is required.");

$token = bin2hex(random_bytes(24));
$marker = "S6-" . strtoupper(substr($token, 0, 12));
$bootstrapName = "step6_session_" . substr($token, 0, 20) . ".php";
$bootstrapPath = $root . DIRECTORY_SEPARATOR . $bootstrapName;
$pngPath = tempnam(sys_get_temp_dir(), "gso_s6_png_");
$scriptPath = tempnam(sys_get_temp_dir(), "gso_s6_script_");
$cookies = [
    "a" => tempnam(sys_get_temp_dir(), "gso_s6_a_"),
    "b" => tempnam(sys_get_temp_dir(), "gso_s6_b_"),
    "admin" => tempnam(sys_get_temp_dir(), "gso_s6_admin_"),
    "guest" => tempnam(sys_get_temp_dir(), "gso_s6_guest_"),
];
step6Assert(
    is_string($pngPath) && is_string($scriptPath) && !in_array(false, $cookies, true),
    "Could not allocate temporary test files."
);

$state = [
    "incident_ids" => [],
    "notification_ids" => [],
    "fixture_notification_ids" => [],
    "maintenance_ids" => [],
    "resource_id" => 0,
    "photo_names" => [],
    "notification_snapshot" => [],
    "activity_baseline" => 0,
    "borrower_a" => 0,
    "borrower_b" => 0,
    "admin" => 0,
    "session_ready" => false,
];
$failure = null;
$passSummary = null;
$checks = 0;

try {
    foreach (["users", "resources", "notifications", "activity_logs", "maintenance_schedules", "incident_reports"] as $table) {
        step6Assert((int) step6Value($pdo, "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table", [":table" => $table]) === 1, "Required table {$table} is missing.");
    }

    $borrowers = step6Rows($pdo, "SELECT user_id FROM users WHERE role = 'Borrower' AND account_status = 'Approved' AND email_verified = 1 ORDER BY user_id ASC LIMIT 2");
    $admins = step6Rows($pdo, "SELECT user_id FROM users WHERE role = 'Admin' AND account_status = 'Approved' AND email_verified = 1 ORDER BY user_id ASC");
    step6Assert(count($borrowers) >= 2 && count($admins) >= 1, "Two approved borrowers and one approved Admin are required.");
    $state["borrower_a"] = (int) $borrowers[0]["user_id"];
    $state["borrower_b"] = (int) $borrowers[1]["user_id"];
    $state["admin"] = (int) $admins[0]["user_id"];
    $state["activity_baseline"] = (int) step6Value($pdo, "SELECT COALESCE(MAX(log_id), 0) FROM activity_logs");
    foreach (step6Rows($pdo, "SELECT notification_id, is_read FROM notifications WHERE user_id = :user_id", [":user_id" => $state["borrower_a"]]) as $row) {
        $state["notification_snapshot"][(int) $row["notification_id"]] = (int) $row["is_read"];
    }

    $bootstrapSource = "<?php\ndeclare(strict_types=1);\n"
        . '$remote = (string) ($_SERVER["REMOTE_ADDR"] ?? "");' . "\n"
        . 'if (!in_array($remote, ["127.0.0.1", "::1"], true) || ($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") { http_response_code(404); exit; }' . "\n"
        . '$provided = (string) ($_SERVER["HTTP_X_STEP6_TOKEN"] ?? "");' . "\n"
        . 'if (!hash_equals(' . var_export($token, true) . ', $provided)) { http_response_code(404); exit; }' . "\n"
        . 'require_once __DIR__ . "/config/bootstrap.php"; gsoSecureSessionStart();' . "\n"
        . 'if (($_POST["action"] ?? "") === "destroy") { gsoDestroySession(); header("Content-Type: application/json"); echo "{\\"ok\\":true}"; exit; }' . "\n"
        . '$people = ' . var_export([
            "a" => [$state["borrower_a"], "Borrower"],
            "b" => [$state["borrower_b"], "Borrower"],
            "admin" => [$state["admin"], "Admin"],
        ], true) . ';' . "\n"
        . '$persona = (string) ($_POST["persona"] ?? ""); if (!isset($people[$persona])) { http_response_code(400); exit; }' . "\n"
        . '$_SESSION = []; session_regenerate_id(true); $_SESSION["user_id"] = (int) $people[$persona][0]; $_SESSION["role"] = (string) $people[$persona][1]; $_SESSION["username"] = "step6_" . $persona; $_SESSION["full_name"] = "Step 6 " . ucfirst($persona); $_SESSION["email"] = "step6_" . $persona . "@example.invalid"; $_SESSION["csrf_token"] = bin2hex(random_bytes(32));' . "\n"
        . 'header("Content-Type: application/json"); header("Cache-Control: no-store"); echo json_encode(["csrf_token" => $_SESSION["csrf_token"], "session_id" => session_id()]);' . "\n";
    step6Assert(file_put_contents($bootstrapPath, $bootstrapSource, LOCK_EX) !== false, "Could not create the temporary session bootstrap.");
    $state["session_ready"] = true;

    $png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", true);
    step6Assert(is_string($png) && file_put_contents($pngPath, $png, LOCK_EX) !== false, "Could not create the PNG fixture.");
    step6Assert(
        file_put_contents($scriptPath, "<?php echo 'not-an-image'; ?>", LOCK_EX) !== false,
        "Could not create the script-upload fixture."
    );

    $sessionA = step6LoginPersona($baseUrl, $bootstrapName, $token, "a", $cookies["a"]);
    $sessionB = step6LoginPersona($baseUrl, $bootstrapName, $token, "b", $cookies["b"]);
    $sessionAdmin = step6LoginPersona($baseUrl, $bootstrapName, $token, "admin", $cookies["admin"]);
    $sessionA["csrf_token"] = step6CsrfFromPage(
        step6Http($baseUrl, "borrower/report_issue.php", "GET", $cookies["a"]),
        "the Borrower incident form"
    );
    $checks += 3;

    $resourceName = "Step6 Test Chair {$marker}";
    $resourceStmt = $pdo->prepare("INSERT INTO resources (resource_name, resource_type, category, description, resource_image, location, total_stock, available_stock, capacity, status, condition_status, condition_notes) VALUES (:name, 'Item', 'Furniture', :description, NULL, :location, 1, 1, NULL, 'Available', 'Good', NULL)");
    $resourceStmt->execute([":name" => $resourceName, ":description" => "Disposable Step 6 workflow fixture {$marker}", ":location" => "Step 6 Lab"]);
    $state["resource_id"] = (int) $pdo->lastInsertId();
    step6Assert($state["resource_id"] > 0, "Could not create the resource fixture.");

    $invalidCsrfTitle = "Rejected CSRF submission {$marker}";
    $notificationsBeforeInvalidCsrf = (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications");
    $invalidCsrfResponse = step6PostForm($baseUrl, "borrower/report_issue.php", $cookies["a"], [
        "csrf_token" => str_repeat("0", 64),
        "incident_title" => $invalidCsrfTitle,
        "incident_type" => "Safety",
        "resource_id" => (string) $state["resource_id"],
        "location" => "Step 6 Lab",
        "description" => "This controlled request must fail CSRF validation.",
    ]);
    step6Assert(
        $invalidCsrfResponse["status"] === 200
            && str_contains($invalidCsrfResponse["body"], "Invalid request token")
            && (int) step6Value(
                $pdo,
                "SELECT COUNT(*) FROM incident_reports WHERE reporter_id = :reporter_id AND incident_title = :title",
                [":reporter_id" => $state["borrower_a"], ":title" => $invalidCsrfTitle]
            ) === 0,
        "Invalid-CSRF incident submission was not rejected safely."
    );
    step6Assert(
        (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications") === $notificationsBeforeInvalidCsrf,
        "Invalid-CSRF submission created a notification."
    );
    $checks += 2;

    $rejectedUploadTitle = "Rejected script upload {$marker}";
    $notificationsBeforeRejectedUpload = (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications");
    $rejectedUpload = step6PostMultipartImage($baseUrl, "borrower/report_issue.php", $cookies["a"], [
        "csrf_token" => $sessionA["csrf_token"],
        "incident_title" => $rejectedUploadTitle,
        "incident_type" => "Equipment",
        "resource_id" => (string) $state["resource_id"],
        "location" => "Step 6 Lab",
        "description" => "This controlled script payload must be rejected as a non-image.",
    ], "incident_photo", $scriptPath, "image/png", "payload.php.png");
    step6Assert(
        $rejectedUpload["status"] === 200
            && str_contains($rejectedUpload["body"], "incident-error-list")
            && (int) step6Value(
                $pdo,
                "SELECT COUNT(*) FROM incident_reports WHERE reporter_id = :reporter_id AND incident_title = :title",
                [":reporter_id" => $state["borrower_a"], ":title" => $rejectedUploadTitle]
            ) === 0,
        "A script disguised as an image was not rejected safely."
    );
    step6Assert(
        (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications") === $notificationsBeforeRejectedUpload,
        "Rejected upload created a notification."
    );
    $checks += 2;

    $linkedTitle = "Broken test chair {$marker}";
    $linkedFields = [
        "csrf_token" => $sessionA["csrf_token"],
        "incident_title" => $linkedTitle,
        "incident_type" => "Furniture",
        "resource_id" => (string) $state["resource_id"],
        "location" => "Step 6 Lab",
        "description" => "Controlled linked-resource incident for {$marker}.",
    ];
    $linkedResponse = step6PostMultipartImage(
        $baseUrl,
        "borrower/report_issue.php",
        $cookies["a"],
        $linkedFields,
        "incident_photo",
        $pngPath,
        "image/png",
        "evidence.php.png"
    );
    $linkedId = step6IncidentFromRedirect($pdo, $state["borrower_a"], $linkedTitle, $linkedResponse);
    $state["incident_ids"][] = $linkedId;
    $linked = step6Rows($pdo, "SELECT * FROM incident_reports WHERE incident_id = :id", [":id" => $linkedId])[0] ?? null;
    step6Assert(is_array($linked) && (int) $linked["resource_id"] === $state["resource_id"] && $linked["status"] === "Submitted", "Linked incident was not stored correctly.");
    step6Assert(is_string($linked["photo_path"]) && isValidIncidentPhotoFilename($linked["photo_path"]), "Private photo filename is invalid.");
    $state["photo_names"][] = $linked["photo_path"];
    $resourceAfterSubmission = step6Rows($pdo, "SELECT status, total_stock, available_stock FROM resources WHERE resource_id = :id", [":id" => $state["resource_id"]])[0] ?? null;
    step6Assert(
        is_array($resourceAfterSubmission)
            && $resourceAfterSubmission["status"] === "Available"
            && (int) $resourceAfterSubmission["total_stock"] === 1
            && (int) $resourceAfterSubmission["available_stock"] === 1,
        "Submitting an incident changed resource maintenance or stock state."
    );
    $checks += 4;

    $adminNoticeCount = (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications WHERE type = 'INCIDENT_SUBMITTED' AND link = :link", [":link" => "view_incident.php?incident_id={$linkedId}"]);
    step6Assert($adminNoticeCount === count($admins), "Submission notification was not sent exactly once to every eligible Admin.");

    $ownerPhoto = step6Http($baseUrl, "borrower/incident_photo.php?incident_id={$linkedId}", "GET", $cookies["a"]);
    step6Assert($ownerPhoto["status"] === 200 && str_starts_with($ownerPhoto["body"], "\x89PNG"), "Reporter could not read their private photo.");
    step6Assert(stripos($ownerPhoto["headers"], "Cache-Control: private, no-store") !== false && stripos($ownerPhoto["headers"], "X-Content-Type-Options: nosniff") !== false, "Private photo security headers are incomplete.");
    foreach ([
        step6Http($baseUrl, "borrower/incident_photo.php?incident_id={$linkedId}", "GET", $cookies["b"]),
        step6Http($baseUrl, "borrower/incident_photo.php?incident_id=abc", "GET", $cookies["a"]),
        step6Http($baseUrl, "borrower/incident_photo.php?incident_id=" . rawurlencode("../{$linkedId}"), "GET", $cookies["a"]),
        step6Http($baseUrl, "borrower/incident_photo.php?incident_id={$linkedId}", "GET", $cookies["guest"]),
        step6Http($baseUrl, "private/GSO_WebSystem/incident_photos/" . rawurlencode($linked["photo_path"]), "GET", $cookies["guest"]),
        step6Http($baseUrl, "uploads/incidents/" . rawurlencode($linked["photo_path"]), "GET", $cookies["guest"]),
    ] as $denied) {
        step6Assert($denied["status"] !== 200, "Unauthorized/direct private-photo access was not denied.");
    }
    $adminPhoto = step6Http($baseUrl, "admin/incident_photo.php?incident_id={$linkedId}", "GET", $cookies["admin"]);
    step6Assert($adminPhoto["status"] === 200 && str_starts_with($adminPhoto["body"], "\x89PNG"), "Authorized Admin could not read incident evidence.");
    $checks += 9;

    $reviewRemarks = "Inspection accepted for {$marker}.";
    $review = step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $linkedId, "Submitted", "Under Review", "", $reviewRemarks);
    step6Assert(in_array($review["status"], [302, 303], true) && (string) step6Value($pdo, "SELECT status FROM incident_reports WHERE incident_id = :id", [":id" => $linkedId]) === "Under Review", "Under Review transition failed.");
    $beforeStale = (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND type = 'INCIDENT_UNDER_REVIEW' AND link = :link", [":uid" => $state["borrower_a"], ":link" => "view_incident.php?incident_id={$linkedId}"]);
    step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $linkedId, "Submitted", "Under Review", "", $reviewRemarks);
    $afterStale = (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND type = 'INCIDENT_UNDER_REVIEW' AND link = :link", [":uid" => $state["borrower_a"], ":link" => "view_incident.php?incident_id={$linkedId}"]);
    step6Assert($beforeStale === 1 && $afterStale === 1, "A stale repeated transition created a duplicate notification.");
    $progress = step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $linkedId, "Under Review", "In Progress", $reviewRemarks);
    step6Assert(in_array($progress["status"], [302, 303], true), "In Progress transition failed.");

    $maintenance = step6PostForm($baseUrl, "admin/view_incident.php?incident_id={$linkedId}", $cookies["admin"], [
        "csrf_token" => $sessionAdmin["csrf_token"], "action" => "start_maintenance", "incident_id" => (string) $linkedId,
        "duration_days" => "1", "maintenance_remarks" => "Step 6 controlled repair {$marker}",
    ]);
    step6Assert(in_array($maintenance["status"], [302, 303], true), "Explicit maintenance action failed.");
    $maintenanceRows = step6Rows($pdo, "SELECT maintenance_id FROM maintenance_schedules WHERE resource_id = :id", [":id" => $state["resource_id"]]);
    $resourceDuringMaintenance = step6Rows($pdo, "SELECT status, total_stock, available_stock FROM resources WHERE resource_id = :id", [":id" => $state["resource_id"]])[0] ?? null;
    step6Assert(
        count($maintenanceRows) === 1
            && is_array($resourceDuringMaintenance)
            && $resourceDuringMaintenance["status"] === "Maintenance"
            && (int) $resourceDuringMaintenance["total_stock"] === 1
            && (int) $resourceDuringMaintenance["available_stock"] === 1,
        "Maintenance state/inventory update is incorrect."
    );
    $state["maintenance_ids"][] = (int) $maintenanceRows[0]["maintenance_id"];
    step6PostForm($baseUrl, "admin/view_incident.php?incident_id={$linkedId}", $cookies["admin"], [
        "csrf_token" => $sessionAdmin["csrf_token"], "action" => "start_maintenance", "incident_id" => (string) $linkedId,
        "duration_days" => "1", "maintenance_remarks" => "Duplicate {$marker}",
    ]);
    step6Assert((int) step6Value($pdo, "SELECT COUNT(*) FROM maintenance_schedules WHERE resource_id = :id", [":id" => $state["resource_id"]]) === 1, "Repeated maintenance created a duplicate schedule.");
    $resolution = "Chair removed from service and repaired for {$marker}.";
    $resolved = step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $linkedId, "In Progress", "Resolved", $reviewRemarks, "", $resolution);
    step6Assert(in_array($resolved["status"], [302, 303], true), "Resolved transition failed.");
    $checks += 8;

    $statusNotices = step6Rows($pdo, "SELECT notification_id, type, link, is_read FROM notifications WHERE user_id = :uid AND link = :link ORDER BY notification_id", [":uid" => $state["borrower_a"], ":link" => "view_incident.php?incident_id={$linkedId}"]);
    step6Assert(array_column($statusNotices, "type") === ["INCIDENT_UNDER_REVIEW", "INCIDENT_IN_PROGRESS", "INCIDENT_RESOLVED"], "Incident status notification types/order are incorrect.");
    foreach ($statusNotices as $notice) {
        $state["notification_ids"][] = (int) $notice["notification_id"];
        step6Assert($notice["link"] === "view_incident.php?incident_id={$linkedId}", "Borrower notification link is incorrect.");
    }
    $linkedNumber = formatIncidentNumber($linkedId);
    $logs = step6Rows($pdo, "SELECT log_id, user_id, action, details FROM activity_logs WHERE log_id > :baseline AND details LIKE :incident ORDER BY log_id", [":baseline" => $state["activity_baseline"], ":incident" => "%{$linkedNumber}%"]);
    $logActions = array_column($logs, "action");
    foreach (["Incident Report Submitted", "Incident Status Changed", "Resource Marked as Maintenance", "Incident Resolved"] as $action) {
        step6Assert(in_array($action, $logActions, true), "Missing activity log: {$action}.");
    }
    foreach ($logs as $log) {
        $expectedActor = $log["action"] === "Incident Report Submitted" ? $state["borrower_a"] : $state["admin"];
        step6Assert((int) $log["user_id"] === $expectedActor, "An incident activity log has the wrong actor.");
    }
    $borrowerDetail = step6Http($baseUrl, "borrower/view_incident.php?incident_id={$linkedId}", "GET", $cookies["a"]);
    step6Assert($borrowerDetail["status"] === 200 && str_contains($borrowerDetail["body"], htmlspecialchars($resolution, ENT_QUOTES, "UTF-8")), "Borrower cannot see final resolution details.");
    step6Assert(step6Http($baseUrl, "borrower/view_incident.php?incident_id={$linkedId}", "GET", $cookies["b"])["status"] !== 200, "Another borrower could open the report detail.");
    $checks += 7;

    $sessionA["csrf_token"] = step6CsrfFromPage(
        step6Http($baseUrl, "borrower/report_issue.php", "GET", $cookies["a"]),
        "the Borrower incident form after the first submission"
    );
    $plainTitle = "Broken test door {$marker}";
    $plainResponse = step6PostForm($baseUrl, "borrower/report_issue.php", $cookies["a"], [
        "csrf_token" => $sessionA["csrf_token"], "incident_title" => $plainTitle, "incident_type" => "Door/Window",
        "resource_id" => "", "location" => "Step 6 Hallway", "description" => "Controlled no-resource incident for {$marker}.",
    ]);
    $plainId = step6IncidentFromRedirect($pdo, $state["borrower_a"], $plainTitle, $plainResponse);
    $state["incident_ids"][] = $plainId;
    step6Assert(step6Value($pdo, "SELECT resource_id FROM incident_reports WHERE incident_id = :id", [":id" => $plainId]) === null, "No-resource incident unexpectedly linked inventory.");
    $plainRemarks = "Facilities team inspected the door for {$marker}.";
    step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $plainId, "Submitted", "Under Review", "", $plainRemarks);
    $updatedRemarks = "Facilities team approved corrective work for {$marker}.";
    step6PostForm($baseUrl, "admin/view_incident.php?incident_id={$plainId}", $cookies["admin"], [
        "csrf_token" => $sessionAdmin["csrf_token"], "action" => "update_remarks", "incident_id" => (string) $plainId,
        "expected_remarks_hash" => hash("sha256", $plainRemarks), "admin_remarks" => $updatedRemarks,
    ]);
    step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $plainId, "Under Review", "In Progress", $updatedRemarks);
    $plainResolution = "Door hinge repaired and safety checked for {$marker}.";
    step6Status($baseUrl, $cookies["admin"], $sessionAdmin["csrf_token"], $plainId, "In Progress", "Resolved", $updatedRemarks, "", $plainResolution);
    $plainAdminPage = step6Http($baseUrl, "admin/view_incident.php?incident_id={$plainId}", "GET", $cookies["admin"]);
    step6Assert($plainAdminPage["status"] === 200 && str_contains($plainAdminPage["body"], "No inventory resource linked") && !str_contains($plainAdminPage["body"], 'name="duration_days"'), "No-resource Admin UI exposed maintenance controls.");
    $plainBorrowerPage = step6Http($baseUrl, "borrower/view_incident.php?incident_id={$plainId}", "GET", $cookies["a"]);
    step6Assert($plainBorrowerPage["status"] === 200 && str_contains($plainBorrowerPage["body"], htmlspecialchars($plainResolution, ENT_QUOTES, "UTF-8")), "No-resource resolution is not visible to its reporter.");
    $checks += 7;

    $fixturePresentations = [
        ["REQUEST", "New request", "", "request"], ["APPROVED", "Approved", "", "approved"],
        ["RETURN_APPROVED", "Return completed", "", "approved"], ["OVERDUE", "Overdue", "", "overdue"],
        ["SYSTEM", "Resource released", "Released to borrower", "release"],
    ];
    foreach ($fixturePresentations as [$type, $title, $message, $tone]) {
        step6Assert(getNotificationPresentation(["type" => $type, "title" => $title, "message" => $message])["tone"] === $tone, "Notification presentation regression for {$type}.");
    }
    foreach (["my_requests.php", "my_borrowed.php?request_id=1", "history.php", "../admin/review_return.php?submission_id=1"] as $safeLink) {
        step6Assert(gsoSafeNotificationLink($safeLink) === $safeLink, "A valid notification link was rejected: {$safeLink}");
    }
    foreach (["https://example.test/", "//example.test/x", "../../config/db.php", "/admin/dashboard.php"] as $unsafeLink) {
        step6Assert(gsoSafeNotificationLink($unsafeLink) === "#", "An unsafe notification link was accepted.");
    }

    $fixtureInsert = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, is_read) VALUES (:uid, :type, :title, :message, :link, 0)");
    foreach ([
        ["REQUEST", "Step6 request {$marker}", "Fixture", "my_requests.php"],
        ["APPROVED", "Step6 approved {$marker}", "Fixture", "my_requests.php"],
        ["RETURN_APPROVED", "Step6 return {$marker}", "Fixture", "history.php"],
        ["OVERDUE", "Step6 overdue {$marker}", "Fixture", "my_borrowed.php"],
    ] as [$type, $title, $message, $link]) {
        $fixtureInsert->execute([":uid" => $state["borrower_a"], ":type" => $type, ":title" => $title, ":message" => $message, ":link" => $link]);
        $state["fixture_notification_ids"][] = (int) $pdo->lastInsertId();
    }
    $fixtureInsert->execute([":uid" => $state["borrower_b"], ":type" => "SYSTEM", ":title" => "Step6 foreign {$marker}", ":message" => "Fixture", ":link" => "browse.php"]);
    $foreignId = (int) $pdo->lastInsertId();
    $state["fixture_notification_ids"][] = $foreignId;

    $allPage = step6Http($baseUrl, "borrower/notifications.php?filter=all", "GET", $cookies["a"]);
    $incidentPage = step6Http($baseUrl, "borrower/notifications.php?filter=incident", "GET", $cookies["a"]);
    $sessionA["csrf_token"] = step6CsrfFromPage($allPage, "the Borrower notifications page");
    $incidentPageMain = "";
    if (preg_match('/<main class="page-content notification-page">.*?<\/main>/si', $incidentPage["body"], $incidentMainMatch) === 1) {
        $incidentPageMain = (string) $incidentMainMatch[0];
    }
    step6Assert($allPage["status"] === 200 && str_contains($allPage["body"], $marker), "Notification page did not render representative events.");
    step6Assert(
        $incidentPage["status"] === 200
            && $incidentPageMain !== ""
            && str_contains($incidentPageMain, $linkedNumber)
            && !str_contains($incidentPageMain, "Step6 request {$marker}"),
        "Incident notification filter is incorrect."
    );

    $individualId = (int) $statusNotices[0]["notification_id"];
    $markOne = step6PostForm($baseUrl, "includes/mark_read.php", $cookies["a"], ["notification_id" => (string) $individualId, "csrf_token" => $sessionA["csrf_token"]]);
    step6Assert($markOne["status"] === 200 && (int) step6Value($pdo, "SELECT is_read FROM notifications WHERE notification_id = :id", [":id" => $individualId]) === 1, "Individual notification read failed.");
    step6PostForm($baseUrl, "includes/mark_read.php", $cookies["a"], ["notification_id" => (string) $foreignId, "csrf_token" => $sessionA["csrf_token"]]);
    step6Assert((int) step6Value($pdo, "SELECT is_read FROM notifications WHERE notification_id = :id", [":id" => $foreignId]) === 0, "A user changed another user's notification.");
    $markAll = step6PostForm($baseUrl, "includes/mark_read.php", $cookies["a"], ["action" => "mark_all", "csrf_token" => $sessionA["csrf_token"]]);
    step6Assert($markAll["status"] === 200 && (int) step6Value($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0", [":uid" => $state["borrower_a"]]) === 0, "Mark-all/unread count did not converge to zero.");
    $checks += 17;

    foreach ($state["incident_ids"] as $incidentId) {
        $link = "view_incident.php?incident_id={$incidentId}";
        foreach (step6Rows($pdo, "SELECT notification_id FROM notifications WHERE link = :link", [":link" => $link]) as $row) {
            $state["notification_ids"][] = (int) $row["notification_id"];
        }
    }

    $passSummary = "PASS: Step 6 incident E2E completed ({$checks} grouped checks; marker {$marker}).";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $cleanupErrors = [];
    try {
        if ($state["notification_snapshot"] !== []) {
            step6RestoreNotificationFlags($pdo, $state["notification_snapshot"]);
        }
    } catch (Throwable $exception) {
        $cleanupErrors[] = "notification flags: " . $exception->getMessage();
    }
    try {
        $notificationIds = array_merge($state["notification_ids"], $state["fixture_notification_ids"]);
        foreach ($state["incident_ids"] as $incidentId) {
            foreach (step6Rows($pdo, "SELECT notification_id FROM notifications WHERE link = :link", [":link" => "view_incident.php?incident_id={$incidentId}"]) as $row) {
                $notificationIds[] = (int) $row["notification_id"];
            }
        }
        step6DeleteIds($pdo, "notifications", "notification_id", $notificationIds);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "notifications: " . $exception->getMessage();
    }
    try {
        $logIds = [];
        $patterns = ["%{$marker}%"];
        foreach ($state["incident_ids"] as $incidentId) {
            $patterns[] = "%" . formatIncidentNumber((int) $incidentId) . "%";
        }
        foreach ($patterns as $index => $pattern) {
            foreach (step6Rows($pdo, "SELECT log_id FROM activity_logs WHERE log_id > :baseline AND details LIKE :pattern", [":baseline" => $state["activity_baseline"], ":pattern" => $pattern]) as $row) {
                $logIds[] = (int) $row["log_id"];
            }
        }
        step6DeleteIds($pdo, "activity_logs", "log_id", $logIds);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "activity logs: " . $exception->getMessage();
    }
    try {
        if ($state["resource_id"] > 0) {
            foreach (step6Rows($pdo, "SELECT maintenance_id FROM maintenance_schedules WHERE resource_id = :id", [":id" => $state["resource_id"]]) as $row) {
                $state["maintenance_ids"][] = (int) $row["maintenance_id"];
            }
        }
        step6DeleteIds($pdo, "maintenance_schedules", "maintenance_id", $state["maintenance_ids"]);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "maintenance: " . $exception->getMessage();
    }
    try {
        foreach ($state["incident_ids"] as $incidentId) {
            $photoName = step6Value($pdo, "SELECT photo_path FROM incident_reports WHERE incident_id = :id", [":id" => $incidentId]);
            if (is_string($photoName) && $photoName !== "") {
                $state["photo_names"][] = $photoName;
            }
        }
        foreach (array_unique($state["photo_names"]) as $photoName) {
            if (resolveIncidentPhotoPath((string) $photoName) !== null && !deleteIncidentPhoto((string) $photoName)) {
                $cleanupErrors[] = "private photo {$photoName}";
            }
        }
        step6DeleteIds($pdo, "incident_reports", "incident_id", $state["incident_ids"]);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "incidents/photos: " . $exception->getMessage();
    }
    try {
        step6DeleteIds($pdo, "resources", "resource_id", [$state["resource_id"]]);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "resource: " . $exception->getMessage();
    }

    if ($state["session_ready"] && is_file($bootstrapPath)) {
        foreach (["a", "b", "admin"] as $persona) {
            try {
                step6Http(
                    $baseUrl,
                    $bootstrapName,
                    "POST",
                    $cookies[$persona],
                    http_build_query(["action" => "destroy"], "", "&", PHP_QUERY_RFC3986),
                    ["X-Step6-Token: " . $token]
                );
            } catch (Throwable $exception) {
                $cleanupErrors[] = "{$persona} session: " . $exception->getMessage();
            }
        }
    }
    foreach (array_merge([$bootstrapPath, $pngPath, $scriptPath], array_values($cookies)) as $file) {
        if (is_string($file) && is_file($file) && !unlink($file)) {
            $cleanupErrors[] = "temporary file " . basename($file);
        }
    }

    if ($cleanupErrors !== []) {
        $cleanupMessage = "cleanup warning(s): " . implode("; ", $cleanupErrors);
        if ($failure === null) {
            $failure = new Step6Failure($cleanupMessage);
        } else {
            fwrite(STDERR, "NEEDS REVIEW: {$cleanupMessage}\n");
        }
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: " . $failure->getMessage() . "\n");
    exit(1);
}

echo $passSummary . "\n";
exit(0);
