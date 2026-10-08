<?php
declare(strict_types=1);
/** R2A regression: isolated database and matching loopback Apache only. */
$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
if (!preg_match('/^gso_r(?:2[ab]|3|4)_test_[a-zA-Z0-9_]+$/', (string) gsoEnv('GSO_DB_NAME', ''))) {
    throw new RuntimeException('R2A fixtures require an explicitly isolated gso_r2a_test_* database.');
}
$base = (string) gsoEnv('GSO_R2A_BASE_URL', 'http://127.0.0.1:8093/GSO_WebSystem');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1','localhost','::1'], true)) throw new RuntimeException('Loopback only.');
require_once $root . '/config/db.php';
require_once $root . '/includes/role_helper.php';
require_once __DIR__ . '/request_letter_test_helper.php';
require_once $root . '/includes/director_approval_helper.php';
require_once $root . '/includes/facility_calendar_helper.php';
$checks = 0;
function r2aAssert(bool $ok, string $message): void {
    global $checks; if (!$ok) throw new RuntimeException($message); $checks++;
}
function r2aRows(string $sql, array $args = []): array {
    global $pdo; $s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);
}
function r2aValue(string $sql, array $args = []): mixed {
    $rows=r2aRows($sql,$args);return $rows===[]?null:reset($rows[0]);
}
function r2aExec(string $sql, array $args = []): void {
    global $pdo;$s=$pdo->prepare($sql);$s->execute($args);
}
function r2aHttp(string $who, string $path, ?array $data = null): array {
    global $base,$cookies;
    $c=curl_init($base.'/'.$path);
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$cookies[$who],CURLOPT_COOKIEJAR=>$cookies[$who],CURLOPT_TIMEOUT=>30]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);
    $raw=curl_exec($c);if(!is_string($raw))throw new RuntimeException('HTTP failed: '.curl_error($c));
    $size=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    $headers=substr($raw,0,$size);$body=substr($raw,$size);$location='';
    if(preg_match('/^Location:\s*(.+)$/mi',$headers,$m))$location=trim($m[1]);
    if($status>=500)throw new RuntimeException("HTTP {$status}: ".substr(strip_tags($body),0,300));
    if(preg_match('/<b>(Warning|Fatal error|Notice)<\/b>:/i',$body))throw new RuntimeException('PHP diagnostic in '.$path);
    return ['status'=>$status,'body'=>$body,'location'=>$location];
}
function r2aLogin(string $who, string $username, string $password): array {
    $page=r2aHttp($who,'auth/login.php');
    if(!preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m))throw new RuntimeException('Login CSRF missing');
    $token=html_entity_decode($m[1]);
    $response=r2aHttp($who,'auth/login.php',['csrf_token'=>$token,'login_input'=>$username,'password'=>$password]);
    $response['csrf']=$token;return $response;
}

$mark='R2A-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$users=[];$cookies=[];$csrf=[];$resources=[];$requests=[];$failed=null;
$historyBefore=r2aRows('SELECT * FROM request_director_history ORDER BY director_history_id');
$adviserBefore=r2aRows('SELECT * FROM request_adviser_history ORDER BY history_id');
try {
    r2aAssert(str_contains((string)r2aValue("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'"),"'Super Admin'"),'Role ENUM missing Super Admin');
    foreach(['admin'=>'Admin','mis'=>'Super Admin','staff'=>'GSO Staff','director'=>'Director','borrower'=>'Borrower'] as $key=>$role){
        $username=strtolower($mark.'_'.$key);
        r2aExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified) VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$role==='Borrower'?'University Staff':null]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r2a_cookie_');
        $login=r2aLogin($key,$username,$password);$csrf[$key]=$login['csrf'];
        r2aAssert($login['status']===302&&str_contains($login['location'],getRoleHomePath($role)),"{$role} could not authenticate normally");
    }
    foreach(gsoRolePermissions() as $role=>$permissions)r2aAssert(!in_array('*',$permissions,true),'Wildcard remains for '.$role);
    $resource=function(string $name,string $type='Item')use(&$resources,$mark,$pdo):int{
        r2aExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status) VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$type==='Facility'?'Facility':'Aggregate',$type==='Facility'?null:10,$type==='Facility'?null:10]);
        $id=(int)$pdo->lastInsertId();$resources[]=$id;return $id;
    };
    $item=$resource('Private equipment');$facility=$resource('Campus hall','Facility');
    $makeRequest=function(array $lines,string $state='Not Required')use(&$requests,$users,$pdo):int{
        r2aExec("INSERT INTO resource_requests(borrower_id,resource_id,quantity,date_needed,start_time,end_time,status,director_state,notes) VALUES(?,?,?,DATE_ADD(CURDATE(),INTERVAL 3 DAY),'08:00:00','10:00:00','Pending',?,'Private borrower purpose')",[$users['borrower'],$lines[0][0],$lines[0][1],$state]);
        $id=(int)$pdo->lastInsertId();$requests[]=$id;
        foreach($lines as [$resource,$qty])r2aExec("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity) SELECT ?,resource_id,CASE WHEN resource_type='Facility' THEN 'Facility' ELSE 'Equipment' END,? FROM resources WHERE resource_id=?",[$id,$qty,$resource]);
        gsoTestVerifiedLetter($pdo,$id,$users['admin']);
        return $id;
    };
    // Facility is deliberately not the legacy mirrored resource.
    $mixed=$makeRequest([[$item,2],[$facility,1]],'Pending');
    $equipment=$makeRequest([[$item,1]],'Rejected');
    r2aExec("UPDATE resource_requests SET director_required_by=?,director_required_at=NOW() WHERE request_id=?",[$users['admin'],$mixed]);
    r2aExec("INSERT INTO request_director_history(request_id,director_user_id,action,remarks) VALUES(?,?,'Rejected','Historical fixture decision')",[$equipment,$users['director']]);
    $historical=r2aRows('SELECT * FROM request_director_history ORDER BY director_history_id');
    $directorFields=r2aRows('SELECT request_id,director_state,director_required_by,director_required_at FROM resource_requests WHERE request_id IN (?,?) ORDER BY request_id',[$mixed,$equipment]);

    foreach(['admin/dashboard.php','admin/requests.php','admin/inventory.php','admin/on_loan.php','admin/maintenance.php','admin/reports.php','admin/incidents.php'] as $path)r2aAssert(r2aHttp('admin',$path)['status']===200,'GSO Head lost '.$path);
    foreach(['admin/admin_accounts.php','admin/borrowers_accounts.php','admin/accounts_dashboard.php'] as $path){
        foreach(['admin','staff','director','borrower'] as $who){
            r2aAssert(r2aHttp($who,$path)['status']===302,"{$who} reached Account Management");
            $before=r2aRows('SELECT user_id,role,account_status FROM users ORDER BY user_id');
            r2aHttp($who,$path,['csrf_token'=>$csrf[$who],'user_id'=>$users['borrower'],'action'=>'disable']);
            r2aAssert(r2aRows('SELECT user_id,role,account_status FROM users ORDER BY user_id')===$before,"{$who} mutated an account");
        }
        r2aAssert(r2aHttp('mis',$path)['status']===200,'MIS denied '.$path);
    }
    foreach(['admin/requests.php','admin/on_loan.php','admin/review_return.php?request_id='.$mixed,'admin/add_resource.php','admin/equipment_units.php','admin/maintenance.php','admin/incidents.php','admin/view_incident.php','admin/ai_knowledge.php','admin/reports.php'] as $path){
        r2aAssert(r2aHttp('mis',$path)['status']===302,'MIS reached operational '.$path);
        r2aAssert(r2aHttp('mis',$path,['csrf_token'=>$csrf['mis'],'request_id'=>$mixed,'action'=>'approve'])['status']===302,'MIS posted operational action');
    }
    r2aAssert(r2aValue('SELECT status FROM resource_requests WHERE request_id=?',[$mixed])==='Pending','Unauthorized operation changed request');
    r2aAssert(!str_contains(r2aHttp('admin','admin/dashboard.php')['body'],'href="admin_accounts.php"'),'GSO sidebar retained account links');
    r2aAssert(!str_contains(r2aHttp('mis','admin/accounts_dashboard.php')['body'],'href="requests.php'),'MIS sidebar exposed operations');

    // Account creation, role assignment, status changes, CSRF and self protection.
    $create=['csrf_token'=>$csrf['mis'],'action'=>'create','role'=>'Director','full_name'=>$mark.' created Director','email'=>strtolower($mark).'_created@example.invalid','username'=>strtolower($mark).'_created','password'=>$password];
    r2aHttp('mis','admin/admin_accounts.php',$create+['department'=>'Campus']);
    $created=(int)r2aValue('SELECT user_id FROM users WHERE username=?',[$create['username']]);
    r2aAssert($created>0,'MIS could not create Director');$users['created']=$created;
    r2aAssert(r2aValue('SELECT role FROM users WHERE user_id=?',[$created])==='Director' && (int)r2aValue('SELECT approved_by FROM users WHERE user_id=?',[$created])===$users['mis'],'Director role/approval actor invalid');
    $cookies['created']=tempnam(sys_get_temp_dir(),'r2a_created_');$login=r2aLogin('created',$create['username'],$password);
    r2aAssert($login['status']===302&&str_contains($login['location'],'director/dashboard.php'),'MIS-created Director could not log in');
    foreach(['disable'=>'Disabled','enable'=>'Approved'] as $action=>$expected){r2aHttp('mis','admin/admin_accounts.php',['csrf_token'=>$csrf['mis'],'action'=>$action,'user_id'=>$created]);r2aAssert(r2aValue('SELECT account_status FROM users WHERE user_id=?',[$created])===$expected,'MIS system-account status action failed');}
    r2aHttp('mis','admin/admin_accounts.php',['csrf_token'=>'invalid','action'=>'disable','user_id'=>$created]);r2aAssert(r2aValue('SELECT account_status FROM users WHERE user_id=?',[$created])==='Approved','CSRF bypassed account management');
    r2aHttp('mis','admin/admin_accounts.php',['csrf_token'=>$csrf['mis'],'action'=>'disable','user_id'=>$users['mis']]);r2aAssert(r2aValue('SELECT account_status FROM users WHERE user_id=?',[$users['mis']])==='Approved','MIS disabled own account');
    $invalid=$create;$invalid['username'].='_invalid';$invalid['email']='invalid_'.$invalid['email'];$invalid['role']='Borrower';r2aHttp('mis','admin/admin_accounts.php',$invalid);r2aAssert(!r2aValue('SELECT user_id FROM users WHERE username=?',[$invalid['username']]),'Forged role accepted');
    foreach([['disable','Disabled'],['approve','Approved'],['reject','Rejected'],['approve','Approved']] as [$action,$expected]){r2aHttp('mis','admin/borrowers_accounts.php',['csrf_token'=>$csrf['mis'],'action'=>$action,'user_id'=>$users['borrower']]);r2aAssert(r2aValue('SELECT account_status FROM users WHERE user_id=?',[$users['borrower']])===$expected,'MIS borrower status action failed');}
    r2aExec("UPDATE users SET account_status='Approved' WHERE user_id=?",[$users['borrower']]);
    foreach(['admin/profile.php','admin/edit_profile.php','admin/change_password.php','admin/notifications.php'] as $path)r2aAssert(r2aHttp('mis',$path)['status']===200,'MIS lost own account/security page');
    $idProof=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII=');
    r2aExec("UPDATE users SET uploaded_id=?,uploaded_id_type='image/png' WHERE user_id=?",[$idProof,$users['borrower']]);
    r2aAssert(r2aHttp('mis','borrower/view_id.php?user_id='.$users['borrower'])['status']===200,'MIS cannot verify uploaded account ID');
    foreach(['admin','director'] as $who)r2aAssert(r2aHttp($who,'borrower/view_id.php?user_id='.$users['borrower'])['status']===404,'Operational/schedule role exposed another account ID');

    // Director read-only schedule: normalized facility lines and no private data.
    $month=date('Y-m',strtotime('+3 days'));$schedule=r2aHttp('director','director/dashboard.php?facility_calendar_month='.$month);
    r2aAssert($schedule['status']===200&&str_contains($schedule['body'],formatRequestCode($mixed)),'Director cannot see facility schedule');
    foreach([$mark.' Private equipment','Private borrower purpose',$mark.' borrower',formatRequestCode($equipment),'name="action"','value="approve"','value="reject"','Prioritize','uploaded_id','accountability'] as $private)r2aAssert(!str_contains($schedule['body'],$private),'Director schedule exposed '.$private);
    r2aAssert(str_contains($schedule['body'],'08:00 AM - 10:00 AM')&&str_contains($schedule['body'],'Pending'),'Director schedule omitted times/status');
    $events=fetchFacilityCalendarEvents($pdo,null,date('Y-m-01',strtotime('+3 days')),date('Y-m-t',strtotime('+3 days')),false,false);
    r2aAssert(in_array(formatRequestCode($mixed),array_column($events,'request_code'),true)&&!in_array(formatRequestCode($equipment),array_column($events,'request_code'),true),'Schedule depended on legacy resource mirror');
    foreach(['director','mis','admin'] as $who)foreach(['approve','reject','prioritize','select_winner'] as $action){
        $result=r2aHttp($who,'director/request.php?request_id='.$mixed,['csrf_token'=>$csrf[$who],'request_id'=>$mixed,'action'=>$action,'winner_request_id'=>$mixed]);
        r2aAssert(in_array($result['status'],[302,403,410],true),'Retired Director POST accepted');
    }
    r2aAssert(r2aHttp('director','director/dashboard.php',['csrf_token'=>$csrf['director'],'action'=>'approve','request_id'=>$mixed])['status']===405,'Schedule accepted mutation');
    r2aAssert(r2aHttp('director','admin/requests.php',['csrf_token'=>$csrf['director'],'action'=>'approve','request_id'=>$mixed])['status']===302,'Director reached GSO approval');
    r2aAssert(r2aRows('SELECT * FROM request_director_history ORDER BY director_history_id')===$historical,'Historical Director records changed/new decision written');
    r2aAssert(r2aRows('SELECT request_id,director_state,director_required_by,director_required_at FROM resource_requests WHERE request_id IN (?,?) ORDER BY request_id',[$mixed,$equipment])===$directorFields,'Director fields changed on denied actions');
    foreach(['director','mis'] as $who)r2aAssert(r2aHttp($who,'chatbot/history.php',['csrf_token'=>$csrf[$who],'action'=>'list'])['status']===403,'Schedule/account role gained chatbot record access');
    r2aAssert(!gsoCanRouteRequestToDirector(['status'=>'Pending'])&&!gsoDirectorStateBlocksGso(['director_state'=>'Pending'])&&gsoDirectorGateAllowsRelease(['director_state'=>'Rejected']),'Obsolete helper still gates GSO');
    $notifications=r2aRows('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id',[$users['director']]);
    r2aAssert(!gsoSendDirectorAssignmentEmails($pdo,$mixed,$users['admin'])&&r2aRows('SELECT * FROM notifications WHERE user_id=? ORDER BY notification_id',[$users['director']])===$notifications,'Director approval notifications still sent');
    r2aHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$mixed,'action'=>'require_director']);
    r2aAssert(r2aRows('SELECT request_id,director_state,director_required_by,director_required_at FROM resource_requests WHERE request_id IN (?,?) ORDER BY request_id',[$mixed,$equipment])===$directorFields,'Retired routing changed historical fields');
    r2aHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$mixed,'action'=>'approve']);
    r2aAssert(r2aValue('SELECT status FROM resource_requests WHERE request_id=?',[$mixed])==='Approved','Historical Pending Director blocked GSO approval');
    r2aHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$mixed,'action'=>'release']);
    r2aAssert(r2aValue('SELECT status FROM resource_requests WHERE request_id=?',[$mixed])==='Released','Historical Pending Director blocked release');
    r2aHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$equipment,'action'=>'approve']);
    r2aAssert(r2aValue('SELECT status FROM resource_requests WHERE request_id=?',[$equipment])==='Approved','Historical rejected Director state blocked GSO');
    r2aAssert(r2aRows('SELECT request_id,director_state,director_required_by,director_required_at FROM resource_requests WHERE request_id IN (?,?) ORDER BY request_id',[$mixed,$equipment])===$directorFields,'GSO processing rewrote Director fields');
    $newHall=$resource('New hall','Facility');$newRequest=$makeRequest([[$newHall,1]]);
    r2aHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$newRequest,'action'=>'approve']);
    r2aAssert(r2aValue('SELECT status FROM resource_requests WHERE request_id=?',[$newRequest])==='Approved'&&r2aValue('SELECT director_state FROM resource_requests WHERE request_id=?',[$newRequest])==='Not Required','New facility still requires Director approval');
    // DB role/status revalidation blocks stale MIS and disabled Director sessions.
    r2aExec("UPDATE users SET role='Admin' WHERE user_id=?",[$users['mis']]);
    r2aAssert(r2aHttp('mis','admin/admin_accounts.php')['status']===302,'Stale MIS session retained account authority');
    r2aExec("UPDATE users SET role='Super Admin' WHERE user_id=?",[$users['mis']]);
    r2aExec("UPDATE users SET account_status='Disabled' WHERE user_id=?",[$users['director']]);
    r2aAssert(r2aHttp('director','director/dashboard.php')['status']===302,'Disabled Director retained schedule access');
    r2aAssert(r2aRows('SELECT * FROM request_adviser_history ORDER BY history_id')===$adviserBefore,'Adviser history changed');
    echo "PASS: R2A role/Director cutover E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    foreach($requests as $id){gsoTestDeleteLetters($pdo,$id);r2aExec('DELETE FROM notifications WHERE link IN (?,?)',['review_return.php?request_id='.$id,'view_request.php?request_id='.$id]);r2aExec('DELETE FROM request_director_history WHERE request_id=?',[$id]);r2aExec('DELETE FROM resource_requests WHERE request_id=? AND borrower_id=?',[$id,$users['borrower']]);}
    foreach($resources as $id)r2aExec('DELETE FROM resources WHERE resource_id=?',[$id]);
    // Delete MIS-created accounts first; actor IDs on other fixture rows remain
    // valid until their dependent records have been removed.
    foreach(array_reverse($users,true) as $id){r2aExec('DELETE FROM notifications WHERE user_id=?',[$id]);r2aExec('DELETE FROM activity_logs WHERE user_id=?',[$id]);r2aExec('DELETE FROM users WHERE user_id=?',[$id]);}
    foreach($cookies as $file)@unlink($file);
    if(r2aRows('SELECT * FROM request_director_history ORDER BY director_history_id')!==$historyBefore)throw new RuntimeException('Director fixture history cleanup failed');
}
exit($failed?1:0);
