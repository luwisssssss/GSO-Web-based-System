<?php
declare(strict_types=1);

/** Run only with GSO_DB_NAME=gso_r1_test_* and GSO_R1_BASE_URL pointing
 * at a loopback PHP server configured for that same isolated database. */
$root = dirname(__DIR__);
require_once $root . '/config/bootstrap.php';
$database = (string) gsoEnv('GSO_DB_NAME', '');
if (!preg_match('/^gso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+$/', $database)) {
    throw new RuntimeException('R1 fixtures require an explicitly isolated gso_r1_test_* or gso_r2a_test_* or gso_r2b_test_* database.');
}
$base = (string) gsoEnv('GSO_R1_BASE_URL', 'http://127.0.0.1:8091/GSO_WebSystem');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost', '::1'], true)) {
    throw new RuntimeException('R1 HTTP target must be loopback.');
}
require_once $root . '/config/db.php';
require_once $root . '/includes/return_detail_helper.php';
require_once $root . '/includes/overdue_helper.php';
require_once $root . '/includes/ai_live_data_helper.php';
require_once $root . '/includes/work_order_helper.php';

$checks = 0;
function r1assert(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    $checks++;
}
function r1rows(string $sql, array $args = []): array {
    global $pdo;
    $s = $pdo->prepare($sql); $s->execute($args); return $s->fetchAll(PDO::FETCH_ASSOC);
}
function r1value(string $sql, array $args = []): mixed {
    $rows = r1rows($sql, $args); return $rows === [] ? null : reset($rows[0]);
}
function r1exec(string $sql, array $args = []): void {
    global $pdo;
    $s = $pdo->prepare($sql); $s->execute($args);
}
function r1flatten(array $data, string $prefix = ''): array {
    $result = [];
    foreach ($data as $key => $value) {
        $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
        if (is_array($value)) $result += r1flatten($value, $name);
        else $result[$name] = $value;
    }
    return $result;
}
function r1http(string $who, string $path, ?array $data = null): array {
    global $base, $cookies;
    $c = curl_init($base . '/' . $path);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookies[$who], CURLOPT_COOKIEFILE => $cookies[$who], CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => r1flatten($data)]);
    $body = curl_exec($c);
    if (!is_string($body)) throw new RuntimeException('HTTP failed: ' . curl_error($c));
    $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    if ($status >= 500) throw new RuntimeException("HTTP {$status}: " . substr(strip_tags($body), 0, 300));
    return ['status' => $status, 'body' => $body];
}

$mark = 'R1-' . strtoupper(bin2hex(random_bytes(6)));
$users = []; $resources = []; $requests = []; $cookies = []; $csrf = [];
$boot = $root . '/r1_session_' . $mark . '.php';
$proof = tempnam(sys_get_temp_dir(), 'r1_proof_');
file_put_contents($proof, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII='));
$historical = r1rows("SELECT * FROM resource_requests WHERE status = 'Returned' ORDER BY request_id");
$failed = null;
try {
    r1assert(str_contains((string) r1value("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='resource_requests' AND COLUMN_NAME='status'"), "'Return'"), 'Return ENUM missing');
    foreach (['borrower' => 'Borrower', 'other' => 'Borrower', 'admin' => 'Admin'] as $key => $role) {
        r1exec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified) VALUES(?,?,?,? ,?,?,'Approved',1)",
            [$mark . ' ' . $key, strtolower($mark . $key) . '@example.invalid', strtolower($mark . $key), password_hash($mark, PASSWORD_DEFAULT), $role, $role === 'Borrower' ? 'University Staff' : null]);
        $users[$key] = (int) $pdo->lastInsertId(); $cookies[$key] = tempnam(sys_get_temp_dir(), 'r1_cookie_');
    }
    $people = []; foreach ($users as $key => $id) $people[$key] = [$id, $key === 'admin' ? 'Admin' : 'Borrower'];
    $token = bin2hex(random_bytes(16));
    $source = '<?php require __DIR__."/config/bootstrap.php"; if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!hash_equals(' . var_export($token,true) . ',(string)($_POST["token"]??""))){http_response_code(404);exit;} require __DIR__."/config/db.php"; if($pdo->query("SELECT DATABASE()")->fetchColumn()!==' . var_export($database,true) . '){http_response_code(409);exit;} $people=' . var_export($people,true) . ';$p=$people[$_POST["persona"]??""]??null;if(!$p){http_response_code(400);exit;}gsoSecureSessionStart();$_SESSION=[];session_regenerate_id(true);$_SESSION["user_id"]=$p[0];$_SESSION["role"]=$p[1];$_SESSION["full_name"]="R1 QA";$_SESSION["csrf_token"]=bin2hex(random_bytes(32));header("Content-Type: application/json");echo json_encode(["csrf"=>$_SESSION["csrf_token"]]);';
    file_put_contents($boot, $source);
    foreach ($users as $key => $id) {
        $response = r1http($key, basename($boot), ['token' => $token, 'persona' => $key]);
        r1assert($response['status'] === 200, 'Server database/session mismatch');
        $session = json_decode($response['body'], true);
        if (!is_array($session)) throw new RuntimeException('Session bootstrap failed: ' . strip_tags($response['body']));
        $csrf[$key] = $session['csrf'];
    }
    $resource = function(string $kind, int $stock = 10) use (&$resources, $mark, $pdo): int {
        $facility = $kind === 'Facility';
        r1exec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status) VALUES(?,?,?,?,?,'Available','Good')", [$mark . ' ' . $kind . count($resources), $facility ? 'Facility' : 'Item', $kind, $facility ? null : $stock, $facility ? null : $stock]);
        $id = (int) $pdo->lastInsertId(); $resources[] = $id; return $id;
    };
    $request = function(array $lines) use (&$requests, $users, $pdo): int {
        r1exec("INSERT INTO resource_requests(borrower_id,date_needed,start_time,end_time,status) VALUES(?,DATE_ADD(CURDATE(),INTERVAL 8 DAY),'08:00:00','10:00:00','Approved')", [$users['borrower']]);
        $id = (int) $pdo->lastInsertId(); $requests[] = $id;
        foreach ($lines as [$resource, $quantity]) r1exec("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity) SELECT ?,resource_id,CASE WHEN tracking_mode='Facility' THEN 'Facility' WHEN tracking_mode='Consumable' THEN 'Consumable' ELSE 'Equipment' END,? FROM resources WHERE resource_id=?", [$id,$quantity,$resource]);
        return $id;
    };
    $status = fn(int $id): string => (string) r1value('SELECT status FROM resource_requests WHERE request_id=?', [$id]);
    $stock = fn(int $id): array => r1rows('SELECT total_stock,available_stock,under_repair_stock FROM resources WHERE resource_id=?', [$id])[0];
    $line = fn(int $id, int $resource): int => (int) r1value('SELECT request_item_id FROM request_items WHERE request_id=? AND resource_id=?', [$id,$resource]);
    $release = function(int $id, array $units = []) use (&$csrf): void {
        r1http('admin', 'admin/requests.php', ['csrf_token'=>$csrf['admin'],'request_id'=>$id,'action'=>'release','unit_ids'=>$units]);
    };
    $submit = function(int $id, array $lines, string $who = 'borrower', bool $photo = false) use (&$csrf, $proof): array {
        $data = ['csrf_token'=>$csrf[$who], 'condition_notes'=>'R1 proof', 'lines'=>$lines];
        if ($photo) $data['proof_photos'][0] = new CURLFile($proof, 'image/png', 'proof.png');
        return r1http($who, 'borrower/return_submit.php?request_id='.$id, $data);
    };
    $inspect = function(int $id, string $action = 'approve', array $conditions = []) use (&$csrf): array {
        $inspection = [];
        foreach (r1rows('SELECT i.* FROM return_submission_items i JOIN return_submissions s ON s.return_id=i.return_id WHERE s.request_id=?', [$id]) as $d) {
            $inspection[$d['return_item_id']] = ['condition'=>$conditions[$d['unit_id']??0]??$d['reported_condition'],'remarks'=>'R1 inspection'];
        }
        return r1http('admin', 'admin/review_return.php?request_id='.$id, ['csrf_token'=>$csrf['admin'],'action'=>$action,'inspection'=>$inspection,'inspection_remarks'=>'R1 rejection reason retained']);
    };

    // Equipment: invalid ownership/payload, atomic submission, pending custody.
    $equipment = $resource('Aggregate', 2); $id = $request([[$equipment,2]]); $release($id);
    $lid = $line($id,$equipment); $payload = [$lid=>['condition'=>'Good','returned_quantity'=>2,'lost_quantity'=>0]];
    r1assert($status($id)==='Released', 'Equipment release failed'); $before = $stock($equipment);
    $submit($id,$payload,'other'); r1assert($status($id)==='Released' && (int)r1value('SELECT COUNT(*) FROM return_submissions WHERE request_id=?',[$id])===0,'Cross-borrower submission accepted');
    $submit($id,[$lid=>['condition'=>'Good','returned_quantity'=>1]]); r1assert($status($id)==='Released','Incomplete submission changed status');
    $submit($id,$payload); r1assert($status($id)==='Return','Released did not enter Return');
    r1assert($stock($equipment)===$before,'Submission restored stock');
    r1assert(getRequestLifecycleStatus(['status'=>'Return','due_date'=>'2000-01-01'])==='Return','Pending inspection misclassified as overdue');
    r1exec('UPDATE resource_requests SET due_date=DATE_SUB(NOW(),INTERVAL 1 DAY) WHERE request_id=?',[$id]);
    $notificationCount=(int)r1value('SELECT COUNT(*) FROM notifications WHERE user_id=?',[$users['borrower']]);
    ensureBorrowerOverdueNotifications($pdo,$users['borrower']);
    r1http('admin','admin/on_loan.php',['csrf_token'=>$csrf['admin'],'request_id'=>$id,'action'=>'remind_overdue']);
    r1assert((int)r1value('SELECT COUNT(*) FROM notifications WHERE user_id=?',[$users['borrower']])===$notificationCount,'Return sent overdue reminder');
    r1assert(gsoCurrentReleasedQuantity($pdo,$equipment)===2,'Return excluded from outstanding quantity');
    r1assert(!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$equipment),1,date('Y-m-d'),null,null,null)['ok'],'Return inventory became available');
    $returnId=(int)r1value('SELECT return_id FROM return_submissions WHERE request_id=?',[$id]);
    $detailsBefore=r1rows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId]);
    $submit($id,$payload); r1assert(r1rows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId])===$detailsBefore,'Duplicate submission overwrote details');
    r1http('admin','admin/review_return.php?request_id='.$id,['csrf_token'=>$csrf['admin'],'action'=>'approve','inspection'=>[]]);
    r1assert($status($id)==='Return' && $stock($equipment)===$before && r1rows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId])===$detailsBefore,'Incomplete inspection changed status or stock');
    r1assert(str_contains(r1http('admin','admin/dashboard.php')['body'],'Return 1'),'Dashboard omitted pending inspection count');
    foreach (['borrower/my_borrowed.php?loan_state=Return','borrower/my_requests.php?status=Return','admin/on_loan.php?loan_state=Return','admin/requests.php?status=Return','admin/reports.php?status=Return','admin/view_request.php?request_id='.$id] as $page) {
        $who=str_starts_with($page,'admin/')?'admin':'borrower';$body=r1http($who,$page)['body'];
        r1assert(str_contains($body,formatRequestCode($id)) || str_contains($body,'BRW-'.str_pad((string)$id,3,'0',STR_PAD_LEFT)), 'Return hidden on '.$page);
    }
    $owner=['user_id'=>$users['borrower'],'role'=>'Borrower'];$other=['user_id'=>$users['other'],'role'=>'Borrower'];
    $chat=gsoChatGetRequestStatus($pdo,$owner,'status of '.formatRequestCode($id));
    r1assert(str_contains(json_encode($chat),'awaiting GSO inspection'),'Chatbot Return explanation missing');
    r1assert(!gsoChatGetRequestStatus($pdo,$other,'status of '.formatRequestCode($id))['success'],'Chatbot leaked another borrower request');
    r1assert(str_contains(json_encode(gsoChatListOwnRequests($pdo,$owner)),formatRequestCode($id)),'Chatbot open requests omitted Return');
    // Rejection and correction preserve audit, do not restore stock.
    $inspect($id,'reject'); r1assert($status($id)==='Released','Rejected return did not restore Released');
    r1assert(r1value('SELECT status FROM return_submissions WHERE request_id=?',[$id])==='Rejected' && $stock($equipment)===$before,'Rejection changed inventory or lost header');
    $submit($id,$payload); r1assert($status($id)==='Return','Correction did not reenter Return');
    r1assert((int)r1value("SELECT COUNT(*) FROM activity_logs WHERE action='Return Rejection Reason' AND details LIKE ?",['%R1 rejection reason retained%'])>0,'Rejection reason was erased on resubmission');
    $inspect($id); r1assert($status($id)==='Returned','Accepted inspection did not enter Returned');
    r1assert((int)$stock($equipment)['available_stock']===2,'Good equipment not restored');
    r1assert((int)r1value('SELECT COUNT(*) FROM return_submission_items WHERE return_id=? AND stock_applied=1',[$returnId])===1,'Stock application flag missing');
    $settled=$stock($equipment);$inspect($id);r1assert($stock($equipment)===$settled,'Repeated approval restored stock twice');
    r1assert(str_contains(r1http('borrower','borrower/history.php?status=Returned')['body'],formatRequestCode($id)),'Returned missing from history');

    // Facility-only: no automatic completion, explicit post-use photo.
    $facility=$resource('Facility');$fid=$request([[$facility,1]]);$release($fid);$flid=$line($fid,$facility);
    $submit($fid,[$flid=>['condition'=>'Good']], 'borrower',true);r1assert($status($fid)==='Released','Facility returned before schedule end');
    r1exec('UPDATE resource_requests SET date_needed=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE request_id=?',[$fid]);
    r1assert($status($fid)==='Released','Schedule end automatically completed facility');
    $submit($fid,[$flid=>['condition'=>'Good']]);r1assert($status($fid)==='Released','Facility accepted missing proof');
    $facilitySubmit=$submit($fid,[$flid=>['condition'=>'Good']], 'borrower',true);
    r1assert($status($fid)==='Return','Facility did not enter Return: '.strip_tags($facilitySubmit['body']));
    r1assert((int)r1value('SELECT COUNT(*) FROM return_submission_photos p JOIN return_submissions s ON s.return_id=p.return_id WHERE s.request_id=?',[$fid])===1,'Facility proof not stored');
    r1assert(count(findScheduleConflicts($pdo,$facility,'Facility',date('Y-m-d',strtotime('-1 day')),'08:00:00','10:00:00',null))===1,'Return facility disappeared from active reservations');
    $inspect($fid);r1assert($status($fid)==='Returned','Facility inspection failed');

    // Mixed request: consumable issuance is permanent; only returnables settle.
    $chairs=$resource('Aggregate');$paper=$resource('Consumable');$hall=$resource('Facility');
    $mid=$request([[$hall,1],[$chairs,2],[$paper,3]]);$release($mid);
    $paperBefore=$stock($paper);r1assert((int)$paperBefore['total_stock']===7,'Consumables not deducted at issuance');
    r1exec('UPDATE resource_requests SET date_needed=DATE_SUB(CURDATE(),INTERVAL 1 DAY) WHERE request_id=?',[$mid]);
    $submit($mid,[$line($mid,$hall)=>['condition'=>'Good'],$line($mid,$chairs)=>['condition'=>'Damaged','returned_quantity'=>1,'lost_quantity'=>1]],'borrower',true);
    r1assert($status($mid)==='Return','Mixed request did not enter Return');
    r1assert((int)r1value("SELECT COUNT(*) FROM return_submission_items i JOIN request_items l ON l.request_item_id=i.request_item_id WHERE l.request_id=? AND l.line_kind='Consumable'",[$mid])===0,'Consumable required a return');
    $mixedDetails=r1rows('SELECT i.* FROM return_submission_items i JOIN return_submissions s ON s.return_id=i.return_id WHERE s.request_id=?',[$mid]);
    $one=$mixedDetails[0];r1exec('DELETE FROM return_submission_items WHERE return_item_id=?',[$one['return_item_id']]);
    $held=$stock($chairs);$inspect($mid);
    r1assert($status($mid)==='Return' && $stock($chairs)===$held,'Missing mixed obligation was accepted');
    r1exec('INSERT INTO return_submission_items(return_item_id,return_id,request_item_id,unit_id,reported_condition,returned_quantity,lost_quantity) VALUES(?,?,?,?,?,?,?)',[$one['return_item_id'],$one['return_id'],$one['request_item_id'],$one['unit_id'],$one['reported_condition'],$one['returned_quantity'],$one['lost_quantity']]);
    $inspect($mid);r1assert($status($mid)==='Returned','Mixed request blocked on independent damage/loss follow-up');
    r1assert((int)$stock($chairs)['total_stock']===9 && (int)$stock($chairs)['under_repair_stock']===1,'Damage/loss quantities changed incorrectly');
    r1assert($stock($paper)===$paperBefore,'Mixed return restored consumables');
    $cid=$request([[$paper,1]]);$release($cid);r1assert($status($cid)==='Completed','Consumable-only completion changed');
    r1assert(str_contains(r1http('borrower','borrower/history.php?status=Completed')['body'],formatRequestCode($cid)),'Completed hidden from history');

    // Serialized: exact allocation, one damaged and one lost; no Lost repair.
    $serial=$resource('Aggregate',2);$unitIds=[];
    for($n=1;$n<=2;$n++){ $result=gsoRegisterEquipmentUnit($pdo,$serial,$mark.'-UNIT-'.$n,null,$users['admin']);r1assert($result['ok'],'Unit fixture registration failed');$unitIds[]=(int)$result['unit_id']; }
    r1assert(gsoActivateSerializedTracking($pdo,$serial,$users['admin'])['ok'],'Serialized fixture activation failed');
    $sid=$request([[$serial,2]]);$slid=$line($sid,$serial);$release($sid,[$slid=>$unitIds]);
    $submit($sid,[$slid=>['units'=>[$unitIds[0]=>['condition'=>'Damaged']]]]);r1assert($status($sid)==='Released','Incomplete units accepted');
    $submit($sid,[$slid=>['units'=>[$unitIds[0]=>['condition'=>'Damaged'],$unitIds[1]=>['condition'=>'Lost'],99999999=>['condition'=>'Good']]]]);r1assert($status($sid)==='Released','Foreign serialized unit accepted');
    $unitsBefore=r1rows('SELECT * FROM equipment_units WHERE resource_id=? ORDER BY unit_id',[$serial]);
    $submit($sid,[$slid=>['units'=>[$unitIds[0]=>['condition'=>'Damaged'],$unitIds[1]=>['condition'=>'Lost']]]]);
    r1assert($status($sid)==='Return' && r1rows('SELECT * FROM equipment_units WHERE resource_id=? ORDER BY unit_id',[$serial])===$unitsBefore,'Serialized submission changed unit availability');
    $inspect($sid);r1assert($status($sid)==='Returned','Serialized return failed');
    r1assert(r1value('SELECT status FROM equipment_units WHERE unit_id=?',[$unitIds[0]])==='Under Repair' && r1value('SELECT status FROM equipment_units WHERE unit_id=?',[$unitIds[1]])==='Lost','Per-unit damage/loss outcomes merged');
    r1assert((int)r1value('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL',[$slid])===0 && (int)r1value('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=?',[$slid])===2,'Allocation history/closure invalid');
    $lostItem=(int)r1value("SELECT i.return_item_id FROM return_submission_items i JOIN return_submissions s ON s.return_id=i.return_id WHERE s.request_id=? AND i.inspection_condition='Lost'",[$sid]);
    r1assert(!gsoCreateReturnRepairWorkOrder($pdo,$lostItem,$users['admin'],0,1)['ok'],'Lost created a repair work order');
    r1assert(gsoSerializedStockMismatches($pdo)===[],'Serialized stock drifted');
    r1assert(r1rows("SELECT * FROM resource_requests WHERE status='Returned' AND borrower_id<>? ORDER BY request_id",[$users['borrower']])===$historical,'Historical Returned rows changed');
    echo "PASS: R1 Return lifecycle E2E ({$checks} checks).\n";
} catch (Throwable $e) {
    $failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($requests as $id) {
        r1exec('DELETE FROM notifications WHERE link=?', ['review_return.php?request_id='.$id]);
        foreach(r1rows('SELECT p.filename FROM return_submission_photos p JOIN return_submissions s ON s.return_id=p.return_id WHERE s.request_id=?',[$id]) as $p) {
            require_once $root.'/includes/return_upload_helper.php';deleteReturnPhotoFile($p['filename']);
        }
        r1exec('DELETE FROM return_submission_photos WHERE return_id IN (SELECT return_id FROM return_submissions WHERE request_id=?)',[$id]);
        r1exec('DELETE FROM return_submission_items WHERE return_id IN (SELECT return_id FROM return_submissions WHERE request_id=?)',[$id]);
        r1exec('DELETE FROM return_submissions WHERE request_id=?',[$id]);
        r1exec('DELETE FROM resource_requests WHERE request_id=? AND borrower_id=?',[$id,$users['borrower']]);
    }
    foreach($resources as $id){r1exec('DELETE FROM equipment_units WHERE resource_id=?',[$id]);r1exec('DELETE FROM resources WHERE resource_id=?',[$id]);}
    foreach($users as $id){r1exec('DELETE FROM notifications WHERE user_id=?',[$id]);r1exec('DELETE FROM activity_logs WHERE user_id=?',[$id]);r1exec('DELETE FROM users WHERE user_id=?',[$id]);}
    foreach($cookies as $file)@unlink($file);@unlink($boot);@unlink($proof);
}
exit($failed ? 1 : 0);
