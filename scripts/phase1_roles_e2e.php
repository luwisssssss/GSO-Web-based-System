<?php
declare(strict_types=1);
require_once __DIR__ . '/test_environment_helper.php';

$root = dirname(__DIR__);
require_once $root . "/config/bootstrap.php";
require_once $root . "/config/db.php";

final class Phase1Failure extends RuntimeException {}
function p1Assert(bool $ok, string $message): void { if (!$ok) throw new Phase1Failure($message); }
function p1Http(string $base, string $path, string $cookie, string $method = "GET", array $data = []): array {
    $ch = curl_init(rtrim($base, "/") . "/" . ltrim($path, "/"));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEJAR=>$cookie, CURLOPT_COOKIEFILE=>$cookie, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>30]);
    if ($method === "POST") { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); }
    $raw = curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE); $size=(int)curl_getinfo($ch,CURLINFO_HEADER_SIZE);
    p1Assert(is_string($raw), "HTTP request failed: " . curl_error($ch)); curl_close($ch);
    $headers=substr($raw,0,$size); $location="";
    if (preg_match_all('/^Location:\s*(.+)$/mi',$headers,$m) && $m[1]) $location=trim((string)end($m[1]));
    return ["status"=>$status,"body"=>substr($raw,$size),"location"=>$location];
}
function p1Login(string $base, string $cookie, string $username, string $password): array {
    $page=p1Http($base,"auth/login.php",$cookie);
    p1Assert(preg_match('/name="csrf_token" value="([^"]+)"/',$page["body"],$m)===1,"Login CSRF missing.");
    $csrf=html_entity_decode($m[1]);
    $response=p1Http($base,"auth/login.php",$cookie,"POST",["csrf_token"=>$csrf,"login_input"=>$username,"password"=>$password]);
    $response["csrf_token"]=$csrf;
    return $response;
}

$base=(string)(getenv("GSO_PHASE1_BASE_URL") ?: "http://127.0.0.1/GSO_WebSystem");
$host=strtolower((string)(parse_url($base,PHP_URL_HOST) ?: ""));
p1Assert(in_array($host,["127.0.0.1","localhost","::1"],true),"Refusing non-loopback target.");
$token=bin2hex(random_bytes(8)); $password="Phase1!".$token; $ids=[]; $cookies=[]; $csrf=[]; $checks=0;
$roles=["Borrower"=>"borrower/browse.php","GSO Staff"=>"gso_staff/dashboard.php","Admin"=>"admin/dashboard.php","Director"=>"director/dashboard.php","Super Admin"=>"admin/accounts_dashboard.php"];

try {
    foreach ($roles as $role=>$home) {
        $slug=strtolower(str_replace([" ","/"],"_",$role)); $username="p1_{$slug}_{$token}";
        $stmt=$pdo->prepare("INSERT INTO users(full_name,university_id,email,role,account_type,username,password_hash,account_status,email_verified) VALUES(:name,:uid,:email,:role,:type,:username,:hash,'Approved',1)");
        $stmt->execute([":name"=>"Phase 1 {$role} {$token}",":uid"=>"P1".strtoupper(substr($slug,0,3)).$token,":email"=>"{$username}@example.invalid",":role"=>$role,":type"=>$role==="Borrower"?"Faculty":null,":username"=>$username,":hash"=>password_hash($password,PASSWORD_DEFAULT)]);
        $ids[$role]=(int)$pdo->lastInsertId(); $cookies[$role]=tempnam(sys_get_temp_dir(),"gso_p1_");
        p1Assert(is_string($cookies[$role]),"Cookie allocation failed.");
        $login=p1Login($base,$cookies[$role],$username,$password);
        $csrf[$role]=$login["csrf_token"];
        p1Assert(in_array($login["status"],[302,303],true) && str_contains($login["location"],$home),"{$role} login routed to {$login['location']} instead of {$home}.");
        $checks++;
    }

    $allowedStaff=["gso_staff/dashboard.php","admin/requests.php","admin/on_loan.php","admin/inventory.php","admin/maintenance.php"];
    foreach($allowedStaff as $path){ $r=p1Http($base,$path,$cookies["GSO Staff"]); p1Assert($r["status"]===200,"GSO Staff denied {$path} ({$r['status']})."); $checks++; }
    foreach(["admin/reports.php","admin/borrowers_accounts.php","admin/admin_accounts.php","admin/activity_logs.php","admin/ai_knowledge.php","director/dashboard.php","borrower/browse.php"] as $path){
        $r=p1Http($base,$path,$cookies["GSO Staff"]); p1Assert(in_array($r["status"],[301,302,303,401,403],true),"GSO Staff reached {$path}."); $checks++;
    }
    foreach(["admin/requests.php","admin/reports.php","gso_staff/dashboard.php","director/dashboard.php"] as $path){
        $r=p1Http($base,$path,$cookies["Borrower"]); p1Assert(in_array($r["status"],[301,302,303,401,403],true),"Borrower reached {$path}."); $checks++;
    }
    foreach(["admin/reports.php","admin/requests.php","admin/on_loan.php","borrower/my_requests.php"] as $path){
        $r=p1Http($base,$path,$cookies["Director"]); p1Assert(in_array($r["status"],[301,302,303,401,403],true),"Director reached {$path}."); $checks++;
    }
    foreach(["admin/dashboard.php","admin/reports.php","admin/ai_knowledge.php","admin/requests.php","admin/maintenance.php"] as $path){
        $r=p1Http($base,$path,$cookies["Admin"]); p1Assert($r["status"]===200,"Admin lost {$path} ({$r['status']})."); $checks++;
    }
    foreach(["admin/admin_accounts.php","admin/borrowers_accounts.php"] as $path){
        p1Assert(p1Http($base,$path,$cookies["Admin"])["status"]===302,"GSO Head retained Account Management.");$checks++;
        p1Assert(p1Http($base,$path,$cookies["Super Admin"])["status"]===200,"MIS lost Account Management.");$checks++;
    }
    foreach(["admin/requests.php","admin/on_loan.php","admin/maintenance.php","admin/inventory.php","admin/reports.php"] as $path){p1Assert(p1Http($base,$path,$cookies["Super Admin"])["status"]===302,"MIS gained operational access.");$checks++;}
    foreach($roles as $role=>$home){ $r=p1Http($base,"chatbot/history.php",$cookies[$role],"POST",["csrf_token"=>$csrf[$role],"action"=>"list"]); $expected=in_array($role,["Director","Super Admin"],true)?403:200;p1Assert($r["status"]===$expected,"{$role} chatbot guard failed ({$r['status']})."); $checks++; }
    echo "PASS: Phase 1 roles and direct endpoint isolation ({$checks} checks).\n";
} catch(Throwable $e) { fwrite(STDERR,"FAIL: {$e->getMessage()}\n"); $failure=true; }
finally {
    foreach($ids as $id){
        $pdo->prepare("DELETE FROM ai_messages WHERE conversation_id IN (SELECT conversation_id FROM ai_conversations WHERE user_id=:id)")->execute([":id"=>$id]);
        $pdo->prepare("DELETE FROM ai_conversations WHERE user_id=:id")->execute([":id"=>$id]);
        $pdo->prepare("DELETE FROM notifications WHERE user_id=:id")->execute([":id"=>$id]);
        $pdo->prepare("DELETE FROM activity_logs WHERE user_id=:id")->execute([":id"=>$id]);
        $pdo->prepare("DELETE FROM users WHERE user_id=:id")->execute([":id"=>$id]);
    }
    foreach($cookies as $cookie){ if(is_string($cookie)&&is_file($cookie)) @unlink($cookie); }
}
if (!empty($failure)) exit(1);
