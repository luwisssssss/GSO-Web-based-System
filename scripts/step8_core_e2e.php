<?php
declare(strict_types=1);

/**
 * Step 8 full-core regression harness.
 *
 * Run with local Apache/MySQL:
 *   C:\xampp\php\php.exe scripts\step8_core_e2e.php
 *
 * The harness refuses non-loopback URLs. It creates uniquely marked users,
 * resources, requests, return evidence, and maintenance data, exercises the
 * real HTTP controllers, then removes only its own rows/files and verifies the
 * original row-count baselines are restored.
 */

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";
require_once $root . "/includes/request_helper.php";
require_once $root . "/includes/return_upload_helper.php";
require_once $root . "/includes/notification_helper.php";

final class Step8Failure extends RuntimeException
{
}

function step8Assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Step8Failure($message);
    }
}

function step8Rows(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function step8Value(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function step8Http(
    string $baseUrl,
    string $path,
    string $method = "GET",
    ?string $cookieFile = null,
    array|string|null $data = null,
    array $headers = []
): array {
    $curl = curl_init(rtrim($baseUrl, "/") . "/" . ltrim($path, "/"));
    step8Assert($curl !== false, "Could not initialize HTTP client.");
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => "GSO-Step8-E2E/1.0",
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($cookieFile !== null) {
        $options[CURLOPT_COOKIEJAR] = $cookieFile;
        $options[CURLOPT_COOKIEFILE] = $cookieFile;
    }
    if ($method === "POST") {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = is_array($data)
            ? http_build_query($data, "", "&", PHP_QUERY_RFC3986)
            : (string) $data;
        if (is_array($data)) {
            $options[CURLOPT_HTTPHEADER] = array_merge($headers, ["Content-Type: application/x-www-form-urlencoded"]);
        }
    } elseif ($method === "HEAD") {
        $options[CURLOPT_NOBODY] = true;
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    step8Assert(is_string($raw), "HTTP request failed: " . ($error !== "" ? $error : "unknown error"));
    $headerText = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    $location = "";
    if (preg_match_all('/^Location:\s*(.+)$/mi', $headerText, $matches) && !empty($matches[1])) {
        $location = trim((string) end($matches[1]));
    }
    return ["status" => $status, "headers" => $headerText, "body" => $body, "location" => $location];
}

function step8Multipart(
    string $baseUrl,
    string $path,
    string $cookieFile,
    array $fields,
    string $fileField,
    string $filePath,
    string $filename = "fixture.png"
): array {
    $bytes = file_get_contents($filePath);
    step8Assert(is_string($bytes), "Could not read multipart fixture.");
    $boundary = "----GsoStep8" . bin2hex(random_bytes(16));
    $body = "";
    foreach ($fields as $name => $value) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
    }
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$fileField}\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: image/png\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
    return step8Http(
        $baseUrl,
        $path,
        "POST",
        $cookieFile,
        $body,
        ["Content-Type: multipart/form-data; boundary={$boundary}"]
    );
}

function step8Csrf(array $response, string $page): string
{
    step8Assert($response["status"] === 200, "Could not open {$page} (HTTP {$response["status"]}).");
    if (preg_match('/<input\b(?=[^>]*\bname="csrf_token")(?=[^>]*\bvalue="([^"]+)")[^>]*>/i', $response["body"], $matches) !== 1) {
        throw new Step8Failure("Could not find CSRF token on {$page}.");
    }
    return html_entity_decode((string) $matches[1], ENT_QUOTES, "UTF-8");
}

function step8BootstrapSession(
    string $baseUrl,
    string $bootstrapName,
    string $token,
    string $persona,
    string $cookieFile
): array {
    $response = step8Http(
        $baseUrl,
        $bootstrapName,
        "POST",
        $cookieFile,
        ["persona" => $persona],
        ["X-Step8-Token: {$token}"]
    );
    step8Assert($response["status"] === 200, "Could not create {$persona} test session.");
    $json = json_decode($response["body"], true);
    step8Assert(is_array($json) && !empty($json["csrf_token"]), "Invalid {$persona} bootstrap response.");
    return $json;
}

function step8CreateUser(PDO $pdo, array $values): int
{
    $stmt = $pdo->prepare("INSERT INTO users
        (full_name, department, university_id, email, uploaded_id, uploaded_id_type, role, username, password_hash, account_status, email_verified, verification_token)
        VALUES (:full_name, 'Step 8 QA', :university_id, :email, :uploaded_id, 'image/png', :role, :username, :password_hash, :account_status, :email_verified, :verification_token)");
    $stmt->bindValue(":full_name", $values["full_name"]);
    $stmt->bindValue(":university_id", $values["university_id"]);
    $stmt->bindValue(":email", $values["email"]);
    $stmt->bindValue(":uploaded_id", $values["uploaded_id"], PDO::PARAM_LOB);
    $stmt->bindValue(":role", $values["role"]);
    $stmt->bindValue(":username", $values["username"]);
    $stmt->bindValue(":password_hash", password_hash($values["password"], PASSWORD_DEFAULT));
    $stmt->bindValue(":account_status", $values["account_status"]);
    $stmt->bindValue(":email_verified", $values["email_verified"], PDO::PARAM_INT);
    $stmt->bindValue(":verification_token", $values["verification_token"]);
    $stmt->execute();
    return (int) $pdo->lastInsertId();
}

function step8CreateResource(PDO $pdo, string $name, string $type, int $stock = 0): int
{
    $stmt = $pdo->prepare("INSERT INTO resources
        (resource_name, resource_type, category, description, location, total_stock, available_stock, capacity, status, condition_status, is_archived)
        VALUES (:name, :type, :category, :description, :location, :total_stock, :available_stock, :capacity, 'Available', 'Good', 0)");
    $stmt->execute([
        ":name" => $name,
        ":type" => $type,
        ":category" => $type === "Item" ? "Equipment" : "Facility",
        ":description" => "Controlled Step 8 fixture",
        ":location" => "Step 8 QA Room",
        ":total_stock" => $type === "Item" ? $stock : null,
        ":available_stock" => $type === "Item" ? $stock : null,
        ":capacity" => $type === "Facility" ? 40 : null,
    ]);
    return (int) $pdo->lastInsertId();
}

function step8Status(PDO $pdo, int $requestId): string
{
    return (string) step8Value($pdo, "SELECT status FROM resource_requests WHERE request_id = :id", [":id" => $requestId]);
}

function step8NotificationCount(PDO $pdo, int $userId, string $title, ?string $link = null): int
{
    $sql = "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND title = :title";
    $params = [":user_id" => $userId, ":title" => $title];
    if ($link !== null) {
        $sql .= " AND link = :link";
        $params[":link"] = $link;
    }
    return (int) step8Value($pdo, $sql, $params);
}

$baseUrl = (string) (getenv("GSO_STEP8_BASE_URL") ?: "http://127.0.0.1/GSO_WebSystem");
$urlParts = parse_url($baseUrl);
step8Assert(in_array(strtolower((string) ($urlParts["host"] ?? "")), ["127.0.0.1", "localhost", "::1"], true), "Refusing non-loopback test target.");
step8Assert(extension_loaded("curl"), "PHP cURL is required.");

$token = bin2hex(random_bytes(24));
$marker = "S8-" . strtoupper(substr($token, 0, 12));
$password = "Step8!Pass-" . substr($token, 0, 8);
$pngPath = tempnam(sys_get_temp_dir(), "gso_s8_png_");
$png = base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=", true);
step8Assert(is_string($pngPath) && is_string($png) && file_put_contents($pngPath, $png, LOCK_EX) !== false, "Could not create PNG fixture.");
$bootstrapName = "step8_session_" . substr($token, 0, 20) . ".php";
$bootstrapPath = $root . DIRECTORY_SEPARATOR . $bootstrapName;
$cookies = [];
foreach (["admin", "a", "b", "expired", "guest", "login1", "login2", "login3", "signup1", "signup2", "signup3", "signup4"] as $name) {
    $cookies[$name] = tempnam(sys_get_temp_dir(), "gso_s8_{$name}_");
}
step8Assert(!in_array(false, $cookies, true), "Could not allocate cookie fixtures.");

$tables = ["users", "resources", "resource_requests", "return_submissions", "return_submission_photos", "notifications", "activity_logs", "maintenance_schedules", "incident_reports"];
$baseline = [];
foreach ($tables as $table) {
    $baseline[$table] = (int) step8Value($pdo, "SELECT COUNT(*) FROM `{$table}`");
}
$logBaseline = (int) step8Value($pdo, "SELECT COALESCE(MAX(log_id), 0) FROM activity_logs");
$notificationBaseline = (int) step8Value($pdo, "SELECT COALESCE(MAX(notification_id), 0) FROM notifications");
$userIds = [];
$resourceIds = [];
$requestIds = [];
$returnIds = [];
$maintenanceIds = [];
$returnPhotoNames = [];
$bootstrapCreated = false;
$checks = 0;
$failure = null;

try {
    $adminId = (int) step8Value($pdo, "SELECT user_id FROM users WHERE role='Admin' AND account_status='Approved' AND email_verified=1 ORDER BY user_id LIMIT 1");
    step8Assert($adminId > 0, "An approved Admin is required.");

    $userBase = strtolower(str_replace("-", "", $marker));
    $borrowerA = step8CreateUser($pdo, [
        "full_name" => "Step 8 Borrower A {$marker}", "university_id" => "UA" . substr($token, 0, 12),
        "email" => "{$userBase}a@example.invalid", "username" => "{$userBase}a", "password" => $password,
        "role" => "Borrower", "account_status" => "Approved", "email_verified" => 1,
        "verification_token" => null, "uploaded_id" => $png,
    ]);
    $borrowerB = step8CreateUser($pdo, [
        "full_name" => "Step 8 Borrower B {$marker}", "university_id" => "UB" . substr($token, 0, 12),
        "email" => "{$userBase}b@example.invalid", "username" => "{$userBase}b", "password" => $password,
        "role" => "Borrower", "account_status" => "Approved", "email_verified" => 1,
        "verification_token" => null, "uploaded_id" => $png,
    ]);
    $verifyToken = bin2hex(random_bytes(32));
    $lifecycleUser = step8CreateUser($pdo, [
        "full_name" => "Step 8 Lifecycle {$marker}", "university_id" => "UL" . substr($token, 0, 12),
        "email" => "{$userBase}life@example.invalid", "username" => "{$userBase}life", "password" => $password,
        "role" => "Borrower", "account_status" => "Pending", "email_verified" => 0,
        "verification_token" => $verifyToken, "uploaded_id" => $png,
    ]);
    $pendingAdmin = step8CreateUser($pdo, [
        "full_name" => "Step 8 Pending Admin {$marker}", "university_id" => "UP" . substr($token, 0, 12),
        "email" => "{$userBase}admin@example.invalid", "username" => "{$userBase}admin", "password" => $password,
        "role" => "Admin", "account_status" => "Pending", "email_verified" => 1,
        "verification_token" => null, "uploaded_id" => $png,
    ]);
    $userIds = [$borrowerA, $borrowerB, $lifecycleUser, $pendingAdmin];

    $bootstrapSource = "<?php\ndeclare(strict_types=1);\n"
        . '$remote=(string)($_SERVER["REMOTE_ADDR"]??""); if(!in_array($remote,["127.0.0.1","::1"],true)||($_SERVER["REQUEST_METHOD"]??"")!=="POST"){http_response_code(404);exit;}' . "\n"
        . '$provided=(string)($_SERVER["HTTP_X_STEP8_TOKEN"]??""); if(!hash_equals(' . var_export($token, true) . ',$provided)){http_response_code(404);exit;}' . "\n"
        . 'require_once __DIR__."/config/bootstrap.php"; gsoSecureSessionStart(); if(($_POST["persona"]??"")==="destroy"){gsoDestroySession();echo "ok";exit;}' . "\n"
        . '$people=' . var_export(["admin" => [$adminId, "Admin"], "a" => [$borrowerA, "Borrower"], "b" => [$borrowerB, "Borrower"], "expired" => [$borrowerA, "Borrower"]], true) . ';' . "\n"
        . '$persona=(string)($_POST["persona"]??""); if(!isset($people[$persona])){http_response_code(400);exit;} $_SESSION=[];session_regenerate_id(true);$_SESSION["user_id"]=(int)$people[$persona][0];$_SESSION["role"]=(string)$people[$persona][1];$_SESSION["username"]="step8_".$persona;$_SESSION["full_name"]="Step 8 ".strtoupper($persona);$_SESSION["email"]="step8@example.invalid";$_SESSION["csrf_token"]=bin2hex(random_bytes(32));if($persona==="expired"){$_SESSION["auth_started_at"]=time()-86401;$_SESSION["last_activity_at"]=time()-3601;}header("Content-Type: application/json");echo json_encode(["csrf_token"=>$_SESSION["csrf_token"]]);' . "\n";
    step8Assert(file_put_contents($bootstrapPath, $bootstrapSource, LOCK_EX) !== false, "Could not create session bootstrap.");
    $bootstrapCreated = true;
    $adminSession = step8BootstrapSession($baseUrl, $bootstrapName, $token, "admin", $cookies["admin"]);
    $aSession = step8BootstrapSession($baseUrl, $bootstrapName, $token, "a", $cookies["a"]);
    $bSession = step8BootstrapSession($baseUrl, $bootstrapName, $token, "b", $cookies["b"]);
    step8BootstrapSession($baseUrl, $bootstrapName, $token, "expired", $cookies["expired"]);

    // Logged-out and cross-role direct URL authorization.
    step8Assert(in_array(step8Http($baseUrl, "admin/dashboard.php", "GET", $cookies["guest"])["status"], [302, 303], true), "Guest accessed Admin dashboard.");
    step8Assert(in_array(step8Http($baseUrl, "borrower/browse.php", "GET", $cookies["guest"])["status"], [302, 303], true), "Guest accessed Borrower resources.");
    step8Assert(in_array(step8Http($baseUrl, "admin/dashboard.php", "GET", $cookies["a"])["status"], [302, 303], true), "Borrower accessed Admin dashboard.");
    step8Assert(in_array(step8Http($baseUrl, "borrower/browse.php", "GET", $cookies["admin"])["status"], [302, 303], true), "Admin accessed Borrower-only route.");
    step8Assert(step8Http($baseUrl, "admin/dashboard.php", "GET", $cookies["admin"])["status"] === 200, "Approved Admin could not access dashboard.");
    step8Assert(step8Http($baseUrl, "admin/profile.php", "GET", $cookies["admin"])["status"] === 200, "Approved Admin could not access profile.");
    step8Assert(step8Http($baseUrl, "admin/on_loan.php", "GET", $cookies["admin"])["status"] === 200, "Approved Admin could not access on-loan management.");
    step8Assert(step8Http($baseUrl, "borrower/profile.php", "GET", $cookies["a"])["status"] === 200, "Approved Borrower could not access profile.");
    step8Assert(in_array(step8Http($baseUrl, "borrower/browse.php", "GET", $cookies["expired"])["status"], [302, 303], true), "Expired authenticated session retained protected-page access.");
    $pdo->prepare("UPDATE users SET account_status='Disabled' WHERE user_id=:id")->execute([":id" => $borrowerA]);
    step8Assert(in_array(step8Http($baseUrl, "borrower/browse.php", "GET", $cookies["a"])["status"], [302, 303], true), "A disabled account retained protected-page access through an existing session.");
    $pdo->prepare("UPDATE users SET account_status='Approved' WHERE user_id=:id")->execute([":id" => $borrowerA]);
    $aSession = step8BootstrapSession($baseUrl, $bootstrapName, $token, "a", $cookies["a"]);
    $checks += 10;

    // Account lifecycle and login rules.
    $loginPage = step8Http($baseUrl, "auth/login.php", "GET", $cookies["login1"]);
    $loginCsrf = step8Csrf($loginPage, "login page");
    $unverified = step8Http($baseUrl, "auth/login.php", "POST", $cookies["login1"], ["csrf_token" => $loginCsrf, "login_input" => "{$userBase}life", "password" => $password]);
    step8Assert($unverified["status"] === 200 && str_contains($unverified["body"], "verify your email"), "Unverified login was not blocked.");
    $verify = step8Http($baseUrl, "auth/verify.php?token=" . rawurlencode($verifyToken), "GET", $cookies["guest"]);
    step8Assert($verify["status"] === 200 && (int) step8Value($pdo, "SELECT email_verified FROM users WHERE user_id=:id", [":id" => $lifecycleUser]) === 1, "Email verification failed.");
    $pendingLoginPage = step8Http($baseUrl, "auth/login.php", "GET", $cookies["login2"]);
    $pendingLogin = step8Http($baseUrl, "auth/login.php", "POST", $cookies["login2"], ["csrf_token" => step8Csrf($pendingLoginPage, "pending login page"), "login_input" => "{$userBase}life", "password" => $password]);
    step8Assert($pendingLogin["status"] === 200 && str_contains($pendingLogin["body"], "pending admin approval"), "Pending Borrower login was not blocked.");
    $pendingAdminPage = step8Http($baseUrl, "auth/login.php", "GET", $cookies["login3"]);
    $pendingAdminLogin = step8Http($baseUrl, "auth/login.php", "POST", $cookies["login3"], ["csrf_token" => step8Csrf($pendingAdminPage, "pending Admin login page"), "login_input" => "{$userBase}admin", "password" => $password]);
    step8Assert($pendingAdminLogin["status"] === 200 && !str_contains($pendingAdminLogin["location"], "admin/dashboard.php"), "Pending Admin login was not blocked.");
    $pdo->prepare("UPDATE users SET account_status='Approved' WHERE user_id=:id")->execute([":id" => $lifecycleUser]);
    $approvedCookie = tempnam(sys_get_temp_dir(), "gso_s8_approved_");
    step8Assert(is_string($approvedCookie), "Could not allocate approved login cookie.");
    $cookies["approved"] = $approvedCookie;
    $approvedPage = step8Http($baseUrl, "auth/login.php", "GET", $approvedCookie);
    $approvedLogin = step8Http($baseUrl, "auth/login.php", "POST", $approvedCookie, ["csrf_token" => step8Csrf($approvedPage, "approved login page"), "login_input" => "{$userBase}life", "password" => $password]);
    step8Assert(in_array($approvedLogin["status"], [302, 303], true) && str_contains($approvedLogin["location"], "borrower/browse.php"), "Approved Borrower login failed.");
    $logout = step8Http($baseUrl, "auth/logout.php", "GET", $approvedCookie);
    step8Assert(in_array($logout["status"], [302, 303], true) && in_array(step8Http($baseUrl, "borrower/browse.php", "GET", $approvedCookie)["status"], [302, 303], true), "Logout did not invalidate the session.");
    $checks += 6;

    // Signup validation without sending external email.
    $duplicateCases = [
        ["signup1", "new{$userBase}1@example.invalid", "new{$userBase}1", "UL" . substr($token, 0, 12), "University ID already exists"],
        ["signup2", "{$userBase}life@example.invalid", "new{$userBase}2", "NEW2" . substr($token, 0, 8), "Email already exists"],
        ["signup3", "new{$userBase}3@example.invalid", "{$userBase}life", "NEW3" . substr($token, 0, 8), "Username already exists"],
    ];
    foreach ($duplicateCases as [$cookieName, $email, $username, $universityId, $expected]) {
        $signupPage = step8Http($baseUrl, "auth/signup.php", "GET", $cookies[$cookieName]);
        $signup = step8Multipart($baseUrl, "auth/signup.php", $cookies[$cookieName], [
            "csrf_token" => step8Csrf($signupPage, "signup page"), "full_name" => "Duplicate {$marker}", "department" => "QA",
            "university_id" => $universityId, "email" => $email, "username" => $username,
            "password" => $password, "confirm_password" => $password,
        ], "uploaded_id", $pngPath, "id.png");
        step8Assert($signup["status"] === 200 && str_contains($signup["body"], $expected), "Signup duplicate validation failed: {$expected}.");
    }
    $invalidSignupPage = step8Http($baseUrl, "auth/signup.php", "GET", $cookies["signup4"]);
    $invalidSignup = step8Multipart($baseUrl, "auth/signup.php", $cookies["signup4"], [
        "csrf_token" => step8Csrf($invalidSignupPage, "invalid signup page"), "full_name" => "Invalid {$marker}", "department" => "QA",
        "university_id" => "INV" . substr($token, 0, 8), "email" => "not-an-email", "username" => "invalid{$userBase}",
        "password" => "short", "confirm_password" => "short",
    ], "uploaded_id", $pngPath, "id.png");
    step8Assert($invalidSignup["status"] === 200 && str_contains($invalidSignup["body"], "valid email address"), "Invalid signup input was accepted.");
    $checks += 4;

    $itemId = step8CreateResource($pdo, "Step8 Item {$marker}", "Item", 5);
    $facilityId = step8CreateResource($pdo, "Step8 Facility {$marker}", "Facility");
    $maintenanceResourceId = step8CreateResource($pdo, "Step8 Maintenance {$marker}", "Item", 2);
    $resourceIds = [$itemId, $facilityId, $maintenanceResourceId];
    $tomorrow = date("Y-m-d", strtotime("+1 day"));
    $facilityDate = date("Y-m-d", strtotime("+10 days"));

    // Item borrowing: submit, approve, release, overdue, return, and replay protection.
    $submit = step8Http($baseUrl, "borrower/request_resource.php?resource_id={$itemId}", "POST", $cookies["a"], [
        "csrf_token" => $aSession["csrf_token"], "quantity" => "2", "contact_number" => "+63 900 000 0000",
        "date_needed" => $tomorrow, "start_time" => "", "end_time" => "", "notes" => "Core flow {$marker}",
    ]);
    step8Assert($submit["status"] === 200 && str_contains($submit["body"], "Request submitted successfully"), "Item request submission failed.");
    $itemRequestId = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:borrower AND resource_id=:resource ORDER BY request_id DESC LIMIT 1", [":borrower" => $borrowerA, ":resource" => $itemId]);
    step8Assert($itemRequestId > 0 && step8Status($pdo, $itemRequestId) === "Pending", "Submitted item request was not Pending.");
    $requestIds[] = $itemRequestId;
    step8Assert(step8NotificationCount($pdo, $adminId, "New Resource Request") >= 1, "Admin request notification was not created.");
    $approve = step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $itemRequestId, "action" => "approve"]);
    step8Assert(in_array($approve["status"], [302, 303], true) && step8Status($pdo, $itemRequestId) === "Approved", "Request approval failed.");
    step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 5, "Approval incorrectly reduced stock.");
    $approvedNoticeCount = step8NotificationCount($pdo, $borrowerA, "Request Approved", "my_requests.php");
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $itemRequestId, "action" => "approve"]);
    step8Assert(step8NotificationCount($pdo, $borrowerA, "Request Approved", "my_requests.php") === $approvedNoticeCount, "Repeated approval duplicated a notification.");
    $release = step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $itemRequestId, "action" => "release"]);
    step8Assert(in_array($release["status"], [302, 303], true) && step8Status($pdo, $itemRequestId) === "Released", "Request release failed.");
    step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 3, "Release did not decrement stock exactly once.");
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $itemRequestId, "action" => "release"]);
    step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 3, "Repeated release decremented stock twice.");
    $pdo->prepare("UPDATE resource_requests SET due_date=DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE request_id=:id")->execute([":id" => $itemRequestId]);
    $loanPage = step8Http($baseUrl, "borrower/my_borrowed.php", "GET", $cookies["a"]);
    step8Http($baseUrl, "borrower/my_borrowed.php", "GET", $cookies["a"]);
    step8Assert($loanPage["status"] === 200 && str_contains($loanPage["body"], "Overdue"), "Overdue loan was not displayed.");
    step8Assert(step8NotificationCount($pdo, $borrowerA, "Overdue Borrowed Item", "my_borrowed.php?loan_state=Overdue&focus_request_id={$itemRequestId}") === 1, "Overdue notification was missing or duplicated.");
    $return = step8Multipart($baseUrl, "borrower/return_submit.php?request_id={$itemRequestId}", $cookies["a"], [
        "csrf_token" => $aSession["csrf_token"], "condition_notes" => "Returned safely {$marker}", "reported_condition" => "Good",
    ], "proof_photos[]", $pngPath, "return.png");
    step8Assert(in_array($return["status"], [302, 303], true), "Return submission failed.");
    $returnRow = step8Rows($pdo, "SELECT return_id,status FROM return_submissions WHERE request_id=:id", [":id" => $itemRequestId])[0] ?? null;
    step8Assert(is_array($returnRow) && $returnRow["status"] === "Pending", "Return submission was not Pending.");
    $returnId = (int) $returnRow["return_id"];
    $returnIds[] = $returnId;
    $photoRow = step8Rows($pdo, "SELECT photo_id,filename FROM return_submission_photos WHERE return_id=:id LIMIT 1", [":id" => $returnId])[0] ?? null;
    step8Assert(is_array($photoRow), "Return photo was not stored.");
    $returnPhotoNames[] = (string) $photoRow["filename"];
    $adminPhoto = step8Http($baseUrl, "admin/return_photo.php?photo_id=" . (int) $photoRow["photo_id"], "GET", $cookies["admin"]);
    $borrowerPhoto = step8Http($baseUrl, "admin/return_photo.php?photo_id=" . (int) $photoRow["photo_id"], "GET", $cookies["a"]);
    step8Assert($adminPhoto["status"] === 200 && str_starts_with($adminPhoto["body"], "\x89PNG"), "Admin could not access return evidence.");
    step8Assert(in_array($borrowerPhoto["status"], [302, 303], true), "Borrower accessed Admin return evidence endpoint.");
    $returnApprove = step8Http($baseUrl, "admin/review_return.php?request_id={$itemRequestId}", "POST", $cookies["admin"], [
        "csrf_token" => $adminSession["csrf_token"], "action" => "approve", "inspection_condition" => "Good", "inspection_remarks" => "Verified {$marker}",
    ]);
    step8Assert(in_array($returnApprove["status"], [302, 303], true) && step8Status($pdo, $itemRequestId) === "Returned", "Return approval failed.");
    step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 5, "Return did not restore stock.");
    step8Http($baseUrl, "admin/review_return.php?request_id={$itemRequestId}", "POST", $cookies["admin"], [
        "csrf_token" => $adminSession["csrf_token"], "action" => "approve", "inspection_condition" => "Good", "inspection_remarks" => "Replay {$marker}",
    ]);
    step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 5, "Repeated return approval restored stock twice.");
    step8Assert(step8Http($baseUrl, "borrower/history.php", "GET", $cookies["a"])["status"] === 200, "Returned transaction was not available in history.");
    $checks += 17;

    // A rejected return keeps the loan and stock unchanged, then permits a safe resubmission.
    step8Http($baseUrl, "borrower/request_resource.php?resource_id={$itemId}", "POST", $cookies["b"], [
        "csrf_token" => $bSession["csrf_token"], "quantity" => "1", "contact_number" => "+63 900 000 0003",
        "date_needed" => date("Y-m-d", strtotime("+2 days")), "start_time" => "", "end_time" => "", "notes" => "Return rejection {$marker}",
    ]);
    $rejectedReturnRequestId = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:borrower AND resource_id=:resource AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":borrower" => $borrowerB, ":resource" => $itemId]);
    step8Assert($rejectedReturnRequestId > 0, "Could not create the return-rejection fixture.");
    $requestIds[] = $rejectedReturnRequestId;
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $rejectedReturnRequestId, "action" => "approve"]);
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $rejectedReturnRequestId, "action" => "release"]);
    step8Assert(step8Status($pdo, $rejectedReturnRequestId) === "Released" && (int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 4, "Return-rejection fixture was not released correctly.");
    step8Http($baseUrl, "borrower/return_submit.php?request_id={$rejectedReturnRequestId}", "POST", $cookies["b"], [
        "csrf_token" => $bSession["csrf_token"], "condition_notes" => "Needs clearer evidence {$marker}", "reported_condition" => "Good",
    ]);
    $rejectedReturnId = (int) step8Value($pdo, "SELECT return_id FROM return_submissions WHERE request_id=:id", [":id" => $rejectedReturnRequestId]);
    step8Assert($rejectedReturnId > 0, "Return-rejection submission was not created.");
    $returnIds[] = $rejectedReturnId;
    step8Http($baseUrl, "admin/review_return.php?request_id={$rejectedReturnRequestId}", "POST", $cookies["admin"], [
        "csrf_token" => $adminSession["csrf_token"], "action" => "reject", "inspection_condition" => "Good", "inspection_remarks" => "Please resubmit {$marker}",
    ]);
    step8Assert((string) step8Value($pdo, "SELECT status FROM return_submissions WHERE return_id=:id", [":id" => $rejectedReturnId]) === "Rejected", "Admin return rejection failed.");
    step8Assert(step8Status($pdo, $rejectedReturnRequestId) === "Released" && (int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 4, "Rejected return changed the loan or restored stock.");
    step8Http($baseUrl, "borrower/return_submit.php?request_id={$rejectedReturnRequestId}", "POST", $cookies["b"], [
        "csrf_token" => $bSession["csrf_token"], "condition_notes" => "Resubmitted {$marker}", "reported_condition" => "Good",
    ]);
    step8Assert((string) step8Value($pdo, "SELECT status FROM return_submissions WHERE return_id=:id", [":id" => $rejectedReturnId]) === "Pending", "Rejected return could not be resubmitted.");
    step8Http($baseUrl, "admin/review_return.php?request_id={$rejectedReturnRequestId}", "POST", $cookies["admin"], [
        "csrf_token" => $adminSession["csrf_token"], "action" => "approve", "inspection_condition" => "Good", "inspection_remarks" => "Accepted resubmission {$marker}",
    ]);
    step8Assert(step8Status($pdo, $rejectedReturnRequestId) === "Returned" && (int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 5, "Approved return resubmission did not restore stock exactly once.");
    $checks += 6;

    // Rejection and cancellation do not change stock.
    foreach (["reject" => $borrowerA, "cancel" => $borrowerB] as $path => $borrowerId) {
        $session = $path === "reject" ? $aSession : $bSession;
        $cookie = $path === "reject" ? $cookies["a"] : $cookies["b"];
        step8Http($baseUrl, "borrower/request_resource.php?resource_id={$itemId}", "POST", $cookie, [
            "csrf_token" => $session["csrf_token"], "quantity" => "1", "contact_number" => "+63 900 000 0001",
            "date_needed" => date("Y-m-d", strtotime("+4 days")), "start_time" => "", "end_time" => "", "notes" => ucfirst($path) . " {$marker}",
        ]);
        $requestId = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:borrower AND resource_id=:resource AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":borrower" => $borrowerId, ":resource" => $itemId]);
        step8Assert($requestId > 0, "Could not create {$path} fixture.");
        $requestIds[] = $requestId;
        if ($path === "reject") {
            step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $requestId, "action" => "reject"]);
            step8Assert(step8Status($pdo, $requestId) === "Rejected", "Admin rejection failed.");
        } else {
            step8Http($baseUrl, "borrower/my_requests.php", "POST", $cookie, ["csrf_token" => $session["csrf_token"], "request_id" => $requestId, "action" => "cancel"]);
            step8Assert(step8Status($pdo, $requestId) === "Cancelled", "Borrower cancellation failed.");
        }
        step8Assert((int) step8Value($pdo, "SELECT available_stock FROM resources WHERE resource_id=:id", [":id" => $itemId]) === 5, ucfirst($path) . " changed stock.");
    }
    $checks += 4;

    // Facility overlap, cancellation, rejection, approval, and boundary non-overlap.
    $facilitySubmit = static function (string $cookie, array $session, string $start, string $end) use ($baseUrl, $facilityId, $facilityDate, $marker): array {
        return step8Http($baseUrl, "borrower/request_resource.php?resource_id={$facilityId}", "POST", $cookie, [
            "csrf_token" => $session["csrf_token"], "quantity" => "1", "contact_number" => "+63 900 000 0002",
            "date_needed" => $facilityDate, "start_time" => $start, "end_time" => $end, "notes" => "Facility {$marker}",
        ]);
    };
    $facilitySubmit($cookies["a"], $aSession, "09:00", "10:00");
    $facilityRequestA = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:b AND resource_id=:r AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":b" => $borrowerA, ":r" => $facilityId]);
    step8Assert($facilityRequestA > 0, "Facility request failed.");
    $requestIds[] = $facilityRequestA;
    $overlap = $facilitySubmit($cookies["b"], $bSession, "09:30", "10:30");
    step8Assert($overlap["status"] === 200 && str_contains($overlap["body"], "occupies the selected date and time"), "Overlapping facility request was accepted.");
    step8Http($baseUrl, "borrower/my_requests.php", "POST", $cookies["a"], ["csrf_token" => $aSession["csrf_token"], "request_id" => $facilityRequestA, "action" => "cancel"]);
    step8Assert(step8Status($pdo, $facilityRequestA) === "Cancelled", "Facility cancellation failed.");
    $facilitySubmit($cookies["b"], $bSession, "09:30", "10:30");
    $facilityRequestB = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:b AND resource_id=:r AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":b" => $borrowerB, ":r" => $facilityId]);
    step8Assert($facilityRequestB > 0, "Cancelled reservation still blocked availability.");
    $requestIds[] = $facilityRequestB;
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $facilityRequestB, "action" => "reject"]);
    step8Assert(step8Status($pdo, $facilityRequestB) === "Rejected", "Facility rejection failed.");
    $facilitySubmit($cookies["a"], $aSession, "09:00", "10:00");
    $facilityApprovedId = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:b AND resource_id=:r AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":b" => $borrowerA, ":r" => $facilityId]);
    $requestIds[] = $facilityApprovedId;
    step8Http($baseUrl, "admin/requests.php", "POST", $cookies["admin"], ["csrf_token" => $adminSession["csrf_token"], "request_id" => $facilityApprovedId, "action" => "approve"]);
    step8Assert(step8Status($pdo, $facilityApprovedId) === "Approved", "Facility approval failed.");
    $approvedOverlap = $facilitySubmit($cookies["b"], $bSession, "09:30", "10:30");
    step8Assert(str_contains($approvedOverlap["body"], "occupies the selected date and time"), "Approved facility reservation did not block overlap.");
    $facilitySubmit($cookies["b"], $bSession, "10:00", "11:00");
    $nonOverlapId = (int) step8Value($pdo, "SELECT request_id FROM resource_requests WHERE borrower_id=:b AND resource_id=:r AND status='Pending' ORDER BY request_id DESC LIMIT 1", [":b" => $borrowerB, ":r" => $facilityId]);
    step8Assert($nonOverlapId > 0, "Boundary non-overlapping facility request was blocked.");
    $requestIds[] = $nonOverlapId;
    $checks += 8;

    // Inventory edit invariants and maintenance replay protection.
    $edit = step8Http($baseUrl, "admin/edit_resource.php?resource_id={$maintenanceResourceId}", "POST", $cookies["admin"], [
        "csrf_token" => $adminSession["csrf_token"], "resource_name" => "Step8 Maintenance {$marker}", "resource_type" => "Item",
        "category" => "Equipment", "description" => "Controlled Step 8 fixture", "location" => "Step 8 QA Room",
        "total_stock" => "3", "available_stock" => "3", "capacity" => "", "status" => "Available",
        "condition_status" => "Good", "condition_notes" => "",
    ]);
    step8Assert($edit["status"] === 200 && (int) step8Value($pdo, "SELECT total_stock FROM resources WHERE resource_id=:id", [":id" => $maintenanceResourceId]) === 3, "Inventory edit failed.");
    step8Assert((int) step8Value($pdo, "SELECT COUNT(*) FROM resources WHERE resource_id=:id AND available_stock BETWEEN 0 AND total_stock", [":id" => $maintenanceResourceId]) === 1, "Inventory edit violated stock bounds.");
    $maintenancePost = [
        "csrf_token" => $adminSession["csrf_token"], "action" => "add_schedule", "resource_id" => $maintenanceResourceId,
        "start_date" => date("Y-m-d"), "duration_days" => "2", "reason" => "Controlled maintenance {$marker}", "remarks" => "Step 8 regression",
    ];
    step8Http($baseUrl, "admin/maintenance.php", "POST", $cookies["admin"], $maintenancePost);
    $maintenanceId = (int) step8Value($pdo, "SELECT maintenance_id FROM maintenance_schedules WHERE resource_id=:id AND reason LIKE :marker ORDER BY maintenance_id DESC LIMIT 1", [":id" => $maintenanceResourceId, ":marker" => "%{$marker}%"]);
    step8Assert($maintenanceId > 0, "Maintenance schedule was not created.");
    $maintenanceIds[] = $maintenanceId;
    step8Assert((string) step8Value($pdo, "SELECT status FROM resources WHERE resource_id=:id", [":id" => $maintenanceResourceId]) === "Maintenance", "Active maintenance did not update resource status.");
    step8Http($baseUrl, "admin/maintenance.php", "POST", $cookies["admin"], $maintenancePost);
    step8Assert((int) step8Value($pdo, "SELECT COUNT(*) FROM maintenance_schedules WHERE resource_id=:id AND status IN ('Scheduled','In Progress')", [":id" => $maintenanceResourceId]) === 1, "Duplicate active maintenance was created.");
    $blockedMaintenanceRequest = step8Http($baseUrl, "borrower/request_resource.php?resource_id={$maintenanceResourceId}", "POST", $cookies["a"], [
        "csrf_token" => $aSession["csrf_token"], "quantity" => "1", "contact_number" => "+63 900 000 0003",
        "date_needed" => $tomorrow, "start_time" => "", "end_time" => "", "notes" => "Blocked {$marker}",
    ]);
    step8Assert(str_contains($blockedMaintenanceRequest["body"], "under maintenance") && (int) step8Value($pdo, "SELECT COUNT(*) FROM resource_requests WHERE resource_id=:id", [":id" => $maintenanceResourceId]) === 0, "Maintenance resource accepted a request.");
    $completePost = ["csrf_token" => $adminSession["csrf_token"], "action" => "complete_schedule", "maintenance_id" => $maintenanceId];
    step8Http($baseUrl, "admin/maintenance.php", "POST", $cookies["admin"], $completePost);
    step8Assert((string) step8Value($pdo, "SELECT status FROM resources WHERE resource_id=:id", [":id" => $maintenanceResourceId]) === "Available", "Completing maintenance did not restore resource status.");
    $completeLogs = (int) step8Value($pdo, "SELECT COUNT(*) FROM activity_logs WHERE log_id>:baseline AND action='Maintenance Completed' AND details LIKE :marker", [":baseline" => $logBaseline, ":marker" => "%{$marker}%"]);
    step8Http($baseUrl, "admin/maintenance.php", "POST", $cookies["admin"], $completePost);
    $completeLogsAfterReplay = (int) step8Value($pdo, "SELECT COUNT(*) FROM activity_logs WHERE log_id>:baseline AND action='Maintenance Completed' AND details LIKE :marker", [":baseline" => $logBaseline, ":marker" => "%{$marker}%"]);
    step8Assert($completeLogs === 1 && $completeLogsAfterReplay === 1, "Repeated maintenance completion created duplicate processing/logging.");
    $checks += 8;

    // Notifications page/read controls and representative activity actors.
    $notificationPage = step8Http($baseUrl, "borrower/notifications.php", "GET", $cookies["a"]);
    step8Assert($notificationPage["status"] === 200 && str_contains($notificationPage["body"], "Request Approved") && str_contains($notificationPage["body"], "Return Completed"), "Borrower notification page missed workflow events.");
    $unreadId = (int) step8Value($pdo, "SELECT notification_id FROM notifications WHERE user_id=:id AND is_read=0 ORDER BY notification_id LIMIT 1", [":id" => $borrowerA]);
    step8Assert($unreadId > 0, "No unread notification available for read-state test.");
    $markOne = step8Http($baseUrl, "includes/mark_read.php", "POST", $cookies["a"], ["csrf_token" => $aSession["csrf_token"], "notification_id" => $unreadId]);
    step8Assert($markOne["status"] === 200 && (int) step8Value($pdo, "SELECT is_read FROM notifications WHERE notification_id=:id", [":id" => $unreadId]) === 1, "Mark-one notification failed.");
    $markAll = step8Http($baseUrl, "includes/mark_read.php", "POST", $cookies["a"], ["csrf_token" => $aSession["csrf_token"], "action" => "mark_all"]);
    step8Assert($markAll["status"] === 200 && (int) step8Value($pdo, "SELECT COUNT(*) FROM notifications WHERE user_id=:id AND is_read=0", [":id" => $borrowerA]) === 0, "Mark-all notifications failed.");
    foreach (["Borrower Request Submitted" => $borrowerA, "Request Approved" => $adminId, "Request Released" => $adminId, "Return Approved" => $adminId, "Resource Updated" => $adminId, "Maintenance Scheduled" => $adminId] as $action => $actorId) {
        step8Assert((int) step8Value($pdo, "SELECT COUNT(*) FROM activity_logs WHERE log_id>:baseline AND user_id=:actor AND action=:action", [":baseline" => $logBaseline, ":actor" => $actorId, ":action" => $action]) >= 1, "Activity actor/action missing: {$action}.");
    }
    $checks += 9;

    // Existing reports and protected deployment paths.
    $reportQuery = http_build_query([
        "date_from" => date("Y-m-d"),
        "date_to" => date("Y-m-d"),
        "incident_location" => $marker,
    ], "", "&", PHP_QUERY_RFC3986);
    $reports = step8Http($baseUrl, "admin/reports.php?{$reportQuery}", "GET", $cookies["admin"]);
    step8Assert($reports["status"] === 200 && str_contains($reports["body"], "REQ-" . str_pad((string) $itemRequestId, 3, "0", STR_PAD_LEFT)) && str_contains($reports["body"], "Incident Reporting Analytics"), "Integrated Admin reports did not include controlled borrowing/incident sections.");
    $excel = step8Http($baseUrl, "admin/reports.php?{$reportQuery}&export=excel", "GET", $cookies["admin"]);
    step8Assert($excel["status"] === 200 && str_contains(strtolower($excel["headers"]), "application/vnd.ms-excel"), "Reports export failed.");
    foreach ([".env", ".git/HEAD", "config/db.php", "database/migrations/20260810_001_create_incident_reporting.sql", "uploads/returns/not-a-file.png", "uploads_temp/"] as $sensitivePath) {
        $response = step8Http($baseUrl, $sensitivePath, "GET", $cookies["guest"]);
        step8Assert(in_array($response["status"], [403, 404], true), "Sensitive path was exposed: {$sensitivePath} (HTTP {$response["status"]}).");
    }
    $checks += 8;

    // No new consistency defects from the controlled workflow.
    $consistencySql = "SELECT
        (SELECT COUNT(*) FROM resources WHERE resource_type='Item' AND (total_stock<0 OR available_stock<0 OR available_stock>total_stock))
        + (SELECT COUNT(*) FROM resource_requests WHERE status='Returned' AND return_date IS NULL)
        + (SELECT COUNT(*) FROM resource_requests WHERE status='Released' AND due_date IS NULL)
        + (SELECT COUNT(*) FROM return_submissions rs JOIN resource_requests rr ON rr.request_id=rs.request_id WHERE rs.status='Approved' AND rr.status<>'Returned')";
    step8Assert((int) step8Value($pdo, $consistencySql) === 0, "Controlled workflow left a database consistency defect.");
    $checks++;
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $cleanupErrors = [];
    try {
        foreach ($returnPhotoNames as $filename) {
            deleteReturnPhotoFile($filename);
        }
        if ($returnIds !== []) {
            $placeholders = implode(",", array_fill(0, count($returnIds), "?"));
            $pdo->prepare("DELETE FROM return_submission_photos WHERE return_id IN ({$placeholders})")->execute($returnIds);
            $pdo->prepare("DELETE FROM return_submissions WHERE return_id IN ({$placeholders})")->execute($returnIds);
        }
        if ($maintenanceIds !== []) {
            $placeholders = implode(",", array_fill(0, count($maintenanceIds), "?"));
            $pdo->prepare("DELETE FROM maintenance_schedules WHERE maintenance_id IN ({$placeholders})")->execute($maintenanceIds);
        }
        if ($requestIds !== []) {
            $placeholders = implode(",", array_fill(0, count($requestIds), "?"));
            $pdo->prepare("DELETE FROM resource_requests WHERE request_id IN ({$placeholders})")->execute($requestIds);
        }
        $links = array_map(static fn (int $id): string => "review_return.php?request_id={$id}", $requestIds);
        foreach ($userIds as $userId) {
            $pdo->prepare("DELETE FROM notifications WHERE user_id=:id")->execute([":id" => $userId]);
            $pdo->prepare("DELETE FROM activity_logs WHERE user_id=:id")->execute([":id" => $userId]);
        }
        $pdo->prepare("DELETE FROM notifications WHERE notification_id>:baseline AND message LIKE :marker")->execute([":baseline" => $notificationBaseline, ":marker" => "%{$marker}%"]);
        foreach ($links as $link) {
            $pdo->prepare("DELETE FROM notifications WHERE notification_id>:baseline AND link=:link")->execute([":baseline" => $notificationBaseline, ":link" => $link]);
        }
        $pdo->prepare("DELETE FROM activity_logs WHERE log_id>:baseline AND details LIKE :marker")->execute([":baseline" => $logBaseline, ":marker" => "%{$marker}%"]);
        foreach ($requestIds as $requestId) {
            $pdo->prepare("DELETE FROM activity_logs WHERE log_id>:baseline AND (details LIKE :request_code OR details LIKE :borrow_code)")->execute([
                ":baseline" => $logBaseline,
                ":request_code" => "%request #{$requestId}%",
                ":borrow_code" => "%BRW-" . str_pad((string) $requestId, 3, "0", STR_PAD_LEFT) . "%",
            ]);
        }
        if ($resourceIds !== []) {
            $placeholders = implode(",", array_fill(0, count($resourceIds), "?"));
            $pdo->prepare("DELETE FROM resources WHERE resource_id IN ({$placeholders})")->execute($resourceIds);
        }
        if ($userIds !== []) {
            $placeholders = implode(",", array_fill(0, count($userIds), "?"));
            $pdo->prepare("DELETE FROM users WHERE user_id IN ({$placeholders})")->execute($userIds);
        }
    } catch (Throwable $exception) {
        $cleanupErrors[] = "database fixtures: " . $exception->getMessage();
    }

    if ($bootstrapCreated && is_file($bootstrapPath)) {
        foreach (["admin", "a", "b"] as $persona) {
            try {
                step8Http($baseUrl, $bootstrapName, "POST", $cookies[$persona], ["persona" => "destroy"], ["X-Step8-Token: {$token}"]);
            } catch (Throwable $exception) {
                $cleanupErrors[] = "{$persona} session: " . $exception->getMessage();
            }
        }
    }
    foreach (array_merge([$bootstrapPath, $pngPath], array_values($cookies)) as $file) {
        if (is_string($file) && is_file($file) && !unlink($file)) {
            $cleanupErrors[] = "temporary file " . basename($file);
        }
    }

    try {
        foreach ($baseline as $table => $count) {
            $current = (int) step8Value($pdo, "SELECT COUNT(*) FROM `{$table}`");
            step8Assert($current === $count, "Baseline mismatch for {$table}: expected {$count}, got {$current}.");
        }
        step8Assert((int) step8Value($pdo, "SELECT COUNT(*) FROM users WHERE email LIKE :marker", [":marker" => "%{$userBase}%"]) === 0, "A Step 8 user fixture remains.");
        step8Assert((int) step8Value($pdo, "SELECT COUNT(*) FROM resources WHERE resource_name LIKE :marker", [":marker" => "%{$marker}%"]) === 0, "A Step 8 resource fixture remains.");
    } catch (Throwable $exception) {
        $cleanupErrors[] = "baseline verification: " . $exception->getMessage();
    }

    if ($cleanupErrors !== []) {
        $cleanupMessage = "Cleanup warning(s): " . implode("; ", $cleanupErrors);
        $failure ??= new Step8Failure($cleanupMessage);
    }
}

if ($failure !== null) {
    fwrite(STDERR, "FAIL: " . $failure->getMessage() . PHP_EOL);
    exit(1);
}

echo "PASS: Step 8 core E2E completed ({$checks} grouped checks; all baselines restored)." . PHP_EOL;
