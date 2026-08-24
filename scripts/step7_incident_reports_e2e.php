<?php
declare(strict_types=1);

/**
 * Step 7 Incident Reporting analytics integration test.
 *
 * Run with local Apache and MySQL available:
 *   C:\xampp\php\php.exe scripts\step7_incident_reports_e2e.php
 *
 * The harness refuses non-loopback URLs, creates uniquely marked incidents,
 * exercises the analytics helper and real HTTP report/export endpoints, and
 * removes only the rows/files it created.
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";
require_once $root . "/includes/incident_helper.php";
require_once $root . "/includes/incident_report_analytics_helper.php";

final class Step7Failure extends RuntimeException
{
}

function step7Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Step7Failure($message);
    }
}

function step7Value(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function step7Http(string $baseUrl, string $path, ?string $cookieFile = null, ?array $post = null, array $headers = []): array
{
    $curl = curl_init(rtrim($baseUrl, "/") . "/" . ltrim($path, "/"));
    step7Assert($curl !== false, "Could not initialize HTTP client.");
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => "GSO-Step7-E2E/1.0",
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($post !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($post, "", "&", PHP_QUERY_RFC3986);
        $options[CURLOPT_HTTPHEADER] = array_merge($headers, ["Content-Type: application/x-www-form-urlencoded"]);
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    step7Assert(is_string($raw), "HTTP request failed: " . ($error !== "" ? $error : "unknown error"));
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $location = "";
    if (preg_match_all('/^Location:\s*(.+)$/mi', $headerText, $matches) && !empty($matches[1])) {
        $location = trim((string) end($matches[1]));
    }
    return ["status" => $status, "headers" => $headerText, "body" => $body, "location" => $location];
}

function step7Session(string $baseUrl, string $bootstrapName, string $token, string $persona, string $cookie): void
{
    $response = step7Http(
        $baseUrl,
        $bootstrapName,
        $cookie,
        ["persona" => $persona],
        ["X-Step7-Token: " . $token]
    );
    step7Assert($response["status"] === 200, "Could not create the {$persona} test session.");
}

$baseUrl = (string) (getenv("GSO_STEP7_BASE_URL") ?: "http://127.0.0.1/GSO_WebSystem");
$parts = parse_url($baseUrl);
step7Assert(in_array(strtolower((string) ($parts["host"] ?? "")), ["127.0.0.1", "localhost", "::1"], true), "Refusing to test a non-loopback host.");
step7Assert(extension_loaded("curl"), "The PHP cURL extension is required.");

$token = bin2hex(random_bytes(24));
$marker = "S7-" . strtoupper(substr($token, 0, 12));
$location = "Step7 QA 100% _ {$marker}";
$privateText = "PRIVATE-DESCRIPTION-{$marker}";
$bootstrapName = "step7_session_" . substr($token, 0, 20) . ".php";
$bootstrapPath = $root . DIRECTORY_SEPARATOR . $bootstrapName;
$cookies = [
    "admin" => tempnam(sys_get_temp_dir(), "gso_s7_admin_"),
    "borrower" => tempnam(sys_get_temp_dir(), "gso_s7_borrower_"),
    "guest" => tempnam(sys_get_temp_dir(), "gso_s7_guest_"),
];
step7Assert(!in_array(false, $cookies, true), "Could not allocate cookie files.");

$incidentIds = [];
$activityBaseline = (int) step7Value($pdo, "SELECT COALESCE(MAX(log_id), 0) FROM activity_logs");
$incidentBaseline = (int) step7Value($pdo, "SELECT COUNT(*) FROM incident_reports");
$bootstrapCreated = false;
$failure = null;
$checks = 0;

try {
    $adminId = (int) step7Value($pdo, "SELECT user_id FROM users WHERE role = 'Admin' AND account_status = 'Approved' AND email_verified = 1 ORDER BY user_id LIMIT 1");
    $borrowerId = (int) step7Value($pdo, "SELECT user_id FROM users WHERE role = 'Borrower' AND account_status = 'Approved' AND email_verified = 1 ORDER BY user_id LIMIT 1");
    step7Assert($adminId > 0 && $borrowerId > 0, "An approved Admin and Borrower are required.");

    $bootstrapSource = "<?php\ndeclare(strict_types=1);\n"
        . '$remote = (string) ($_SERVER["REMOTE_ADDR"] ?? ""); if (!in_array($remote, ["127.0.0.1", "::1"], true) || ($_SERVER["REQUEST_METHOD"] ?? "") !== "POST") { http_response_code(404); exit; }' . "\n"
        . '$provided = (string) ($_SERVER["HTTP_X_STEP7_TOKEN"] ?? ""); if (!hash_equals(' . var_export($token, true) . ', $provided)) { http_response_code(404); exit; }' . "\n"
        . 'require_once __DIR__ . "/config/bootstrap.php"; gsoSecureSessionStart();' . "\n"
        . 'if (($_POST["persona"] ?? "") === "destroy") { gsoDestroySession(); echo "ok"; exit; }' . "\n"
        . '$people = ' . var_export(["admin" => [$adminId, "Admin"], "borrower" => [$borrowerId, "Borrower"]], true) . ';' . "\n"
        . '$persona = (string) ($_POST["persona"] ?? ""); if (!isset($people[$persona])) { http_response_code(400); exit; }' . "\n"
        . '$_SESSION = []; session_regenerate_id(true); $_SESSION["user_id"] = (int) $people[$persona][0]; $_SESSION["role"] = (string) $people[$persona][1]; $_SESSION["username"] = "step7_" . $persona; $_SESSION["full_name"] = "Step 7 " . ucfirst($persona); $_SESSION["email"] = "step7_" . $persona . "@example.invalid"; $_SESSION["csrf_token"] = bin2hex(random_bytes(32)); echo "ok";' . "\n";
    step7Assert(file_put_contents($bootstrapPath, $bootstrapSource, LOCK_EX) !== false, "Could not create the temporary session bootstrap.");
    $bootstrapCreated = true;

    $fixtures = [
        ["Submitted", "Furniture", "Low", "2026-01-01 08:00:00", null],
        ["Submitted", "Furniture", "Normal", "2026-01-08 09:00:00", null],
        ["Under Review", "Equipment", "High", "2026-02-01 10:00:00", null],
        ["Under Review", "Electrical", "Urgent", "2026-03-01 11:00:00", null],
        ["In Progress", "Furniture", "High", "2026-08-01 08:00:00", null],
        ["In Progress", "Plumbing", "Urgent", "2026-08-02 08:00:00", null],
        ["Resolved", "Furniture", "Normal", "2026-08-03 08:00:00", "2026-08-03 10:00:00"],
        ["Resolved", "Equipment", "High", "2026-08-04 08:00:00", "2026-08-05 10:00:00"],
        ["Rejected", "Safety", "Normal", "2026-08-05 08:00:00", null],
        ["Rejected", "Other", "Low", "2026-08-06 08:00:00", null],
    ];
    $insert = $pdo->prepare("INSERT INTO incident_reports
        (reporter_id, resource_id, incident_title, incident_type, location, description, priority, status, admin_remarks, resolution_notes, reported_at, updated_at, resolved_at)
        VALUES (:reporter_id, NULL, :title, :type, :location, :description, :priority, :status, :remarks, :resolution, :reported_at, :updated_at, :resolved_at)");
    foreach ($fixtures as $index => $fixture) {
        [$status, $category, $priority, $reportedAt, $resolvedAt] = $fixture;
        $insert->execute([
            ":reporter_id" => $borrowerId,
            ":title" => "Step7 Incident " . ($index + 1) . " {$marker}",
            ":type" => $category,
            ":location" => $location,
            ":description" => $privateText . " controlled fixture " . ($index + 1),
            ":priority" => $priority,
            ":status" => $status,
            ":remarks" => $status === "Rejected" ? "Controlled rejection reason {$marker}" : null,
            ":resolution" => $status === "Resolved" ? "Controlled resolution notes {$marker}" : null,
            ":reported_at" => $reportedAt,
            ":updated_at" => $reportedAt,
            ":resolved_at" => $resolvedAt,
        ]);
        $incidentIds[] = (int) $pdo->lastInsertId();
    }
    step7Assert(count($incidentIds) === 10, "The controlled incident fixture set was not created.");
    $checks++;

    $allFilters = ["date_from" => "", "date_to" => "", "status" => "All", "category" => "All", "priority" => "All", "location" => $marker];
    $analytics = getIncidentReportAnalytics($pdo, $allFilters);
    step7Assert($analytics["total"] === 10, "Total incident count is inaccurate.");
    step7Assert($analytics["status_counts"] === ["Submitted" => 2, "Under Review" => 2, "In Progress" => 2, "Resolved" => 2, "Rejected" => 2], "Status counts are inaccurate.");
    step7Assert($analytics["open"] === 6 && $analytics["closed"] === 4, "Open/closed counts are inaccurate.");
    step7Assert($analytics["trend_granularity"] === "Monthly", "Long-span trend should be monthly.");
    step7Assert($analytics["resolution"]["valid_count"] === 2, "Valid resolution count is inaccurate.");
    step7Assert((int) round((float) $analytics["resolution"]["average_seconds"]) === 50400, "Average resolution time is inaccurate.");
    step7Assert($analytics["resolution"]["minimum_seconds"] === 7200 && $analytics["resolution"]["maximum_seconds"] === 93600, "Resolution time bounds are inaccurate.");
    $checks += 6;

    $daily = getIncidentReportAnalytics($pdo, array_merge($allFilters, ["date_from" => "2026-08-01", "date_to" => "2026-08-31"]));
    $weekly = getIncidentReportAnalytics($pdo, array_merge($allFilters, ["date_from" => "2026-01-01", "date_to" => "2026-03-31"]));
    step7Assert($daily["trend_granularity"] === "Daily" && $daily["total"] === 6, "Daily trend/date filter is inaccurate.");
    step7Assert($weekly["trend_granularity"] === "Weekly" && $weekly["total"] === 4, "Weekly trend/date filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["category" => "Furniture"]))["total"] === 4, "Category filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["priority" => "Urgent"]))["total"] === 2, "Priority filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["status" => "Resolved"]))["total"] === 2, "Status filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["status" => "Rejected", "category" => "Safety"]))["total"] === 1, "Single-incident combined filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["location" => "100% _ {$marker}"]))["total"] === 10, "Literal wildcard location filter is inaccurate.");
    step7Assert(getIncidentReportAnalytics($pdo, array_merge($allFilters, ["date_from" => "2035-01-01", "date_to" => "2035-01-31"]))["total"] === 0, "Zero-data report is inaccurate.");
    $checks += 8;

    $transitionId = $incidentIds[4];
    $transition = $pdo->prepare("UPDATE incident_reports SET status = 'Resolved', resolution_notes = :notes, resolved_at = DATE_ADD(reported_at, INTERVAL 5 HOUR) WHERE incident_id = :id AND status = 'In Progress'");
    $transition->execute([":notes" => "Controlled transition resolution {$marker}", ":id" => $transitionId]);
    $afterTransition = getIncidentReportAnalytics($pdo, $allFilters);
    step7Assert($transition->rowCount() === 1 && $afterTransition["open"] === 5 && $afterTransition["closed"] === 5, "Analytics did not update after a status transition.");
    step7Assert($afterTransition["resolution"]["valid_count"] === 3, "Resolution metrics did not update after transition.");
    $checks += 2;

    step7Session($baseUrl, $bootstrapName, $token, "admin", $cookies["admin"]);
    step7Session($baseUrl, $bootstrapName, $token, "borrower", $cookies["borrower"]);
    $query = http_build_query(["incident_location" => $marker], "", "&", PHP_QUERY_RFC3986);
    $adminPage = step7Http($baseUrl, "admin/reports.php?{$query}", $cookies["admin"]);
    step7Assert(
        $adminPage["status"] === 200
            && str_contains($adminPage["body"], "Incident Reporting Analytics")
            && str_contains($adminPage["body"], formatIncidentNumber($incidentIds[0]))
            && str_contains($adminPage["body"], "Borrowing Transactions")
            && str_contains($adminPage["body"], "Maintenance Records")
            && str_contains($adminPage["body"], "Inventory Status"),
        "Admin report page did not render the integrated incident and existing report sections."
    );
    $borrowerPage = step7Http($baseUrl, "admin/reports.php?{$query}", $cookies["borrower"]);
    $guestPage = step7Http($baseUrl, "admin/reports.php?{$query}", $cookies["guest"]);
    step7Assert(in_array($borrowerPage["status"], [302, 303], true), "Borrower was not denied the Admin report.");
    step7Assert(in_array($guestPage["status"], [302, 303], true), "Unauthenticated user was not denied the Admin report.");
    $checks += 3;

    $excel = step7Http($baseUrl, "admin/reports.php?{$query}&export=excel", $cookies["admin"]);
    step7Assert($excel["status"] === 200 && str_contains(strtolower($excel["headers"]), "application/vnd.ms-excel") && str_contains($excel["body"], $marker), "Excel export did not include filtered incident data.");
    step7Assert(!str_contains($excel["body"], $privateText) && !str_contains($excel["body"], "photo_path") && !str_contains($excel["body"], "admin_remarks"), "Excel export exposed a private incident field.");
    $pdf = step7Http($baseUrl, "admin/reports.php?{$query}&export=pdf", $cookies["admin"]);
    step7Assert($pdf["status"] === 200 && str_contains($pdf["body"], "Print / Save as PDF") && str_contains($pdf["body"], $marker), "Print/PDF export did not include filtered incident data.");
    step7Assert(!str_contains($pdf["body"], $privateText) && !str_contains($pdf["body"], "photo_path"), "Print/PDF export exposed a private incident field.");
    $invalid = step7Http($baseUrl, "admin/reports.php?date_from=2026-09-01&date_to=2026-08-01&export=excel", $cookies["admin"]);
    step7Assert($invalid["status"] === 200 && str_contains($invalid["body"], "start date cannot be later"), "Invalid dates were not rejected before export.");
    $checks += 5;
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $cleanupErrors = [];
    try {
        if ($incidentIds !== []) {
            $placeholders = implode(",", array_fill(0, count($incidentIds), "?"));
            $delete = $pdo->prepare("DELETE FROM incident_reports WHERE incident_id IN ({$placeholders})");
            $delete->execute($incidentIds);
        }
    } catch (Throwable $exception) {
        $cleanupErrors[] = "incidents: " . $exception->getMessage();
    }
    try {
        $deleteLogs = $pdo->prepare("DELETE FROM activity_logs WHERE log_id > :baseline AND details LIKE :marker");
        $deleteLogs->execute([":baseline" => $activityBaseline, ":marker" => "%{$marker}%"]);
    } catch (Throwable $exception) {
        $cleanupErrors[] = "activity logs: " . $exception->getMessage();
    }
    if ($bootstrapCreated && is_file($bootstrapPath)) {
        foreach (["admin", "borrower"] as $persona) {
            try {
                step7Http($baseUrl, $bootstrapName, $cookies[$persona], ["persona" => "destroy"], ["X-Step7-Token: " . $token]);
            } catch (Throwable $exception) {
                $cleanupErrors[] = "{$persona} session: " . $exception->getMessage();
            }
        }
    }
    foreach (array_merge([$bootstrapPath], array_values($cookies)) as $file) {
        if (is_string($file) && is_file($file) && !unlink($file)) {
            $cleanupErrors[] = "temporary file " . basename($file);
        }
    }
    try {
        step7Assert((int) step7Value($pdo, "SELECT COUNT(*) FROM incident_reports") === $incidentBaseline, "Incident baseline was not restored.");
        step7Assert((int) step7Value($pdo, "SELECT COUNT(*) FROM incident_reports WHERE incident_title LIKE :marker", [":marker" => "%{$marker}%"]) === 0, "A Step 7 fixture remains.");
    } catch (Throwable $exception) {
        $cleanupErrors[] = "baseline verification: " . $exception->getMessage();
    }
    if ($cleanupErrors !== []) {
        $message = "Cleanup warning(s): " . implode("; ", $cleanupErrors);
        $failure ??= new Step7Failure($message);
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: " . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "PASS: Step 7 incident analytics E2E completed ({$checks} grouped checks; baseline restored)." . PHP_EOL;
