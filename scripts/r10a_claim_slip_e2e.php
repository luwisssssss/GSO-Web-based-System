<?php
declare(strict_types=1);
/**
 * R10A Claim Slip Code E2E: issuance on approval, owner/GSO display, and
 * server-side verification before release. Isolated test database, loopback
 * test server and private outbox mail transport only.
 */
require_once dirname(__DIR__).'/config/bootstrap.php';
if(!preg_match('/^gso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME',''))||gsoEnv('GSO_MAIL_TEST_TRANSPORT','')!=='outbox')throw new RuntimeException('Isolated test database and outbox mail transport required.');
$base=(string)gsoEnv('GSO_R10A_BASE_URL','http://127.0.0.1:8100/GSO_WebSystem');
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback only.');
$root=dirname(__DIR__);
require_once $root.'/config/db.php';
require_once $root.'/includes/request_helper.php';
require_once $root.'/includes/request_line_helper.php';
require_once $root.'/includes/request_attachment_helper.php';
require_once $root.'/includes/reschedule_helper.php';
require_once $root.'/includes/client_notification_helper.php';
require_once $root.'/includes/equipment_unit_helper.php';
require_once $root.'/includes/return_upload_helper.php';
require_once $root.'/includes/mail_helper.php';
require_once $root.'/includes/ai_live_data_helper.php';
require_once __DIR__.'/request_letter_test_helper.php';

$checks=0;$users=[];$cookies=[];$csrf=[];$resources=[];$requests=[];$failed=null;
function qAssert(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function qRows(string $q,array $a=[]):array{global $pdo;$s=$pdo->prepare($q);$s->execute($a);return $s->fetchAll(PDO::FETCH_ASSOC);}
function qVal(string $q,array $a=[]):mixed{$r=qRows($q,$a);return $r?reset($r[0]):null;}
function qExec(string $q,array $a=[]):void{global $pdo;$s=$pdo->prepare($q);$s->execute($a);}
function qFlat(array $d,string $p=''):array{$r=[];foreach($d as $k=>$v){$n=$p===''?(string)$k:$p.'['.$k.']';if(is_array($v))$r+=qFlat($v,$n);else $r[$n]=$v;}return $r;}
function qHttp(string $who,string $path,?array $data=null):array{global $base,$cookies;$c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookies[$who],CURLOPT_COOKIEJAR=>$cookies[$who],CURLOPT_TIMEOUT=>30]);if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>qFlat($data)]);$raw=curl_exec($c);if(!is_string($raw))throw new RuntimeException(curl_error($c));$n=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$body=substr($raw,$n);if($status>=500||preg_match('/<b>(Warning|Fatal error|Notice|Deprecated)<\/b>:/i',$body))throw new RuntimeException('HTTP/PHP failure '.$path.' ['.$status.'] '.substr(strip_tags($body),0,300));return ['status'=>$status,'body'=>$body];}
function qDbRejects(string $sql,array $args=[]):bool{try{qExec($sql,$args);return false;}catch(PDOException $e){return true;}}
function qState(int $id):string{return (string)qVal('SELECT status FROM resource_requests WHERE request_id=?',[$id]);}
function qSlip(int $id):?string{$v=qVal('SELECT claim_slip_code FROM resource_requests WHERE request_id=?',[$id]);return $v===null?null:(string)$v;}
function qCode(int $id):string{return formatRequestCode($id);}
const SLIP_FORMAT='/^GSO-[2-9A-HJ-NP-Z]{8}$/';

$mark='R10A-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$outbox=gsoMailOutboxDirectory();$failureFile=$outbox.DIRECTORY_SEPARATOR.'.simulate_failure';@unlink($failureFile);
function qMails(int $id,?string $title=null):array{global $outbox;$found=[];$reference=qCode($id);foreach(glob($outbox.'/*.html')?:[] as $file){$body=(string)file_get_contents($file);if(preg_match('/'.preg_quote($reference,'/').'\b/',$body)&&($title===null||str_contains($body,'GSO - '.$title.' - '.$reference)))$found[$file]=$body;}return $found;}

$legacy=qRows('SELECT * FROM resource_requests ORDER BY request_id');
$legacyInventory=qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id');
try{
    // ---- Schema ------------------------------------------------------------
    $column=qRows("SELECT column_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='resource_requests' AND column_name='claim_slip_code'");
    qAssert(count($column)===1&&$column[0]['column_type']==='varchar(20)'&&$column[0]['is_nullable']==='YES','claim_slip_code column missing or not a nullable varchar');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='resource_requests' AND index_name='uq_requests_claim_slip_code' AND non_unique=0")===1,'Unique key on claim_slip_code missing');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema=DATABASE() AND constraint_name='chk_requests_claim_slip'")===1,'Claim slip CHECK constraint missing');
    qAssert(gsoRecordedSchemaVersion($pdo)===GSO_SCHEMA_VERSION&&GSO_SCHEMA_VERSION==='20261010_008','Schema version is not the claim slip version');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")===22&&(int)qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE '%claim%'")===0,'A separate claim slip table exists or the table count changed');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='resource_requests' AND column_name IN('resource_id','quantity')")===0,'Legacy request mirror columns returned');
    foreach($legacy as $row)if(in_array($row['status'],['Cancelled','Released','Return','Returned','Completed','Rejected'],true))qAssert($row['claim_slip_code']===null,'A closed historical request was given a fabricated code');

    // ---- Generator ---------------------------------------------------------
    $generated=[];for($i=0;$i<400;$i++){$code=gsoGenerateClaimSlipCode();qAssert((bool)preg_match(SLIP_FORMAT,$code),'Generated code has the wrong format: '.$code);$generated[$code]=true;}
    qAssert(count($generated)===400,'Generator repeated a code within 400 draws');
    $source=(string)file_get_contents($root.'/app/models/claim_slip_helper.php');qAssert(str_contains($source,'random_int(')&&!preg_match('/\b(?:mt_rand|rand|uniqid|lcg_value|microtime|md5)\s*\(/',$source),'Code generation does not use only a cryptographically secure source');
    qAssert(gsoClaimSlipMatches('GSO-ABCD2345',' gso-abcd2345 ')&&!gsoClaimSlipMatches('GSO-ABCD2345','GSO-ABCD2346')&&!gsoClaimSlipMatches('GSO-ABCD2345','')&&!gsoClaimSlipMatches(null,'')&&!gsoClaimSlipMatches(null,null)&&!gsoClaimSlipMatches('','')&&!gsoClaimSlipMatches('GSO-ABCD2345',['GSO-ABCD2345']),'Claim slip comparison accepts a blank, missing or non-string value');

    // ---- Fixtures ----------------------------------------------------------
    $roles=['student'=>['Borrower','Student'],'faculty'=>['Borrower','Faculty'],'admin'=>['Admin',null],'staff'=>['GSO Staff',null],'mis'=>['Super Admin',null],'director'=>['Director',null]];
    foreach($roles as $key=>[$role,$type]){
        $username=strtolower($mark.'_'.$key);
        qExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified)VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$type]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r10a_cookie_');
        $page=qHttp($key,'auth/login.php');preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m);$csrf[$key]=$m[1]??'';
        qAssert(qHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed: '.$key);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r10a_guest_');
    $mailTo=fn(string $key)=>strtolower($mark.'_'.$key).'@example.invalid';
    $resource=function(string $name,string $type='Item',string $mode='Aggregate',int $stock=10)use($mark,&$resources):int{global $pdo;qExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status)VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$mode,$stock,$stock]);return $resources[]=(int)$pdo->lastInsertId();};
    $hall=$resource('Hall','Facility','Facility',1);$gym=$resource('Gym','Facility','Facility',1);$chairs=$resource('Chairs','Item','Aggregate',40);$projector=$resource('Projector','Item','Serialized',2);$paper=$resource('Paper','Item','Consumable',20);
    $units=[];for($i=0;$i<2;$i++){qExec("INSERT INTO equipment_units(resource_id,asset_tag,status,condition_status,registered_by)VALUES(?,?,'Available','Good',?)",[$projector,$mark.'-PRJ-'.$i,$users['admin']]);$units[]=(int)$pdo->lastInsertId();}
    gsoRecalculateSerializedStock($pdo,$projector);
    $day=fn(int $offset)=>date('Y-m-d',strtotime('+'.$offset.' days'));
    $stock=fn(int $id)=>qRows('SELECT total_stock,available_stock,under_repair_stock FROM resources WHERE resource_id=?',[$id])[0];
    $line=fn(int $request,int $res)=>(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=? AND resource_id=?',[$request,$res]);
    $newest=fn(string $who)=>(int)qVal('SELECT COALESCE(MAX(request_id),0) FROM resource_requests WHERE borrower_id=?',[$users[$who]]);
    $newestWalkin=fn()=>(int)qVal('SELECT COALESCE(MAX(request_id),0) FROM resource_requests WHERE encoded_by=?',[$users['staff']]);
    $png=gsoTestLetterImage();
    $submit=function(string $who,int $primary,array $data,bool $letter=false)use(&$csrf,$png,$newest,&$requests):int{$before=$newest($who);$post=['csrf_token'=>$csrf[$who],'contact_number'=>'09123456789']+$data;if($letter)$post['request_letter']=new CURLFile($png,'image/png','signed-letter.png');qHttp($who,'borrower/request_resource.php?resource_id='.$primary,$post);$id=$newest($who);if($id>$before){$requests[]=$id;return $id;}return 0;};
    // No automatic code here: every release in this suite states exactly what the client presented.
    $process=fn(string $who,int $id,string $action,array $extra=[])=>qHttp($who,'admin/requests.php',array_replace(['csrf_token'=>$csrf[$who]??'','request_id'=>$id,'action'=>$action],$extra));
    $release=fn(string $who,int $id,mixed $presented,array $extra=[])=>$process($who,$id,'release',($presented===false?[]:['claim_slip_code'=>$presented])+$extra);
    $verify=function(int $id)use(&$csrf,$pdo){$letter=gsoRequestLetters($pdo,$id)[0]??null;return qHttp('staff','admin/view_request.php?request_id='.$id,['csrf_token'=>$csrf['staff'],'action'=>'verify_letter','attachment_id'=>(int)($letter['attachment_id']??0)]);};
    $item=fn(string $who,int $res,int $qty,int $offset)=>$submit($who,$res,['quantity'=>$qty,'date_needed'=>$day($offset),'start_time'=>'09:00','end_time'=>'11:00']);
    $failures=fn()=>(int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Claim Slip Verification Failed'");
    $wrongFor=function(string $real):string{do{$other=gsoGenerateClaimSlipCode();}while($other===$real);return $other;};

    // ==== Before approval: no code (1, 2, 38) ================================
    $r1=$item('student',$chairs,2,5);qAssert($r1>0&&qState($r1)==='Pending'&&qSlip($r1)===null,'Pending request has a Claim Slip Code');
    foreach(['admin/requests.php','admin/view_request.php?request_id='.$r1] as $path)qHttp('admin',$path);qHttp('student','borrower/my_requests.php');
    qAssert(qSlip($r1)===null,'Viewing a pending request generated a code');
    $process('admin',$r1,'review');qAssert(qState($r1)==='Under Review'&&qSlip($r1)===null,'Under Review request has a Claim Slip Code');
    qAssert(!str_contains(qHttp('student','borrower/my_requests.php')['body'],'Claim Slip Code'),'Borrower page shows a claim slip before approval');
    qAssert(!str_contains(qHttp('admin','admin/view_request.php?request_id='.$r1)['body'],'claim-slip-card'),'GSO page shows a claim slip before approval');
    qAssert(qDbRejects("UPDATE resource_requests SET claim_slip_code='GSO-ABCD2345' WHERE request_id=?",[$r1]),'Database allowed a code on an unapproved request');
    qAssert(qDbRejects("UPDATE resource_requests SET status='Approved' WHERE request_id=?",[$r1]),'Database allowed an Approved request without a code');
    qAssert(qState($r1)==='Under Review'&&qSlip($r1)===null,'Rejected database write changed the request');

    // ==== Failed approvals leave no code (8, 40) =============================
    foreach(['student','faculty','director','mis','guest'] as $who){$process($who,$r1,'approve');qAssert(qState($r1)==='Under Review'&&qSlip($r1)===null,$who.' approved a request or caused a code');}
    $process('admin',$r1,'approve',['csrf_token'=>'forged']);qAssert(qState($r1)==='Under Review'&&qSlip($r1)===null,'Forged approval issued a code');
    $unverified=$submit('student',$hall,['quantity'=>1,'date_needed'=>$day(20),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM'],true);
    $process('admin',$unverified,'approve');qAssert(qState($unverified)==='Pending'&&qSlip($unverified)===null,'Approval refused for an unverified letter still issued a code');
    $tooMany=$item('faculty',$chairs,38,5);qAssert($tooMany>0,'Queue fixture was not created');$process('admin',$tooMany,'approve');qAssert(qState($tooMany)==='Pending'&&qSlip($tooMany)===null,'Approval refused by the first-come queue still issued a code');
    $rejected=$item('faculty',$chairs,1,6);$process('admin',$rejected,'reject');qAssert(qState($rejected)==='Rejected'&&qSlip($rejected)===null,'Rejected request has a code');
    $process('admin',$tooMany,'reject');

    // ==== Successful approval issues one stable code (3, 4, 6, 7, 32, 37) ====
    $process('admin',$r1,'approve');$code1=qSlip($r1);
    qAssert(qState($r1)==='Approved'&&$code1!==null&&(bool)preg_match(SLIP_FORMAT,$code1),'Approval did not issue a well-formed Claim Slip Code');
    qAssert($code1!=='GSO-'.$r1&&!str_contains($code1,(string)$r1)&&!str_contains($code1,(string)$users['student'])&&$code1!==qCode($r1),'Claim Slip Code is derived from the request or borrower id');
    $mails=qMails($r1,'Request Approved');$mail=(string)reset($mails);
    qAssert(count($mails)===1&&str_contains($mail,'<strong>To:</strong> '.$mailTo('student'))&&str_contains($mail,'Claim Slip Code: '.$code1)&&str_contains($mail,'GSO Office'),'Approval email is missing, misaddressed, or lacks the code and instruction');
    qAssert(!preg_match('/QR/i',$mail),'Approval email mentions QR claiming');
    $notice=(string)qVal("SELECT message FROM notifications WHERE user_id=? AND type='approved' ORDER BY notification_id DESC LIMIT 1",[$users['student']]);
    qAssert($notice!==''&&str_contains($notice,'Claim Slip Code')&&!str_contains($notice,$code1),'In-app notice is missing or exposes the code');
    qAssert((int)qVal('SELECT COUNT(*) FROM activity_logs WHERE details LIKE ?',['%'.$code1.'%'])===0&&(int)qVal('SELECT COUNT(*) FROM notifications WHERE message LIKE ? OR link LIKE ?',['%'.$code1.'%','%'.$code1.'%'])===0,'Raw Claim Slip Code was written to logs or notifications');
    $process('admin',$r1,'approve');$process('staff',$r1,'approve');qHttp('admin','admin/view_request.php?request_id='.$r1);qHttp('student','borrower/my_requests.php');qHttp('admin','admin/requests.php');
    qAssert(qSlip($r1)===$code1&&count(qMails($r1,'Request Approved'))===1,'Repeated approval or page views changed the code or resent the email');

    // ==== Uniqueness (5) =====================================================
    $batch=[];for($i=0;$i<6;$i++){$id=$item($i%2?'faculty':'student',$chairs,1,7+$i);$process('admin',$id,'approve');qAssert(qState($id)==='Approved','Batch approval failed');$batch[$id]=qSlip($id);}
    qAssert(count(array_unique(array_merge(array_values($batch),[$code1])))===7,'Two requests share a Claim Slip Code');
    $other=array_key_first($batch);qAssert(qDbRejects('UPDATE resource_requests SET claim_slip_code=? WHERE request_id=?',[$code1,$other]),'Database allowed a duplicate Claim Slip Code');
    qAssert(qDbRejects("UPDATE resource_requests SET claim_slip_code='1234' WHERE request_id=?",[$other])&&qDbRejects("UPDATE resource_requests SET claim_slip_code='GSO-0O1I0O1I' WHERE request_id=?",[$other]),'Database allowed a malformed Claim Slip Code');
    qAssert(qSlip($other)===$batch[$other],'A rejected write changed a stored code');

    // ==== Display: owner and GSO only (9, 10, 13, 14, 39) =====================
    $mine=qHttp('student','borrower/my_requests.php')['body'];
    qAssert(str_contains($mine,$code1)&&str_contains($mine,'Present this Claim Slip Code at the GSO Office when claiming your approved request.'),'Owner cannot see the code and instruction on their request');
    $theirs=qHttp('faculty','borrower/my_requests.php')['body'];qAssert(!str_contains($theirs,$code1),'Another borrower sees the code');
    qAssert(!str_contains(qHttp('faculty','borrower/my_requests.php?search='.$code1)['body'],qCode($r1)),'Another borrower found a request by searching its Claim Slip Code');
    foreach(['borrower/my_requests.php?search='.qCode($r1),'borrower/history.php','borrower/my_borrowed.php','borrower/reschedule.php?request_id='.$r1,'borrower/return_submit.php?request_id='.$r1] as $path)qAssert(!str_contains(qHttp('faculty',$path)['body'],$code1),'Another borrower reached the code through '.$path);
    foreach(['admin','staff'] as $who){$detail=qHttp($who,'admin/view_request.php?request_id='.$r1)['body'];qAssert(str_contains($detail,$code1)&&str_contains($detail,'Print Claim Slip'),$who.' cannot view or print the code on the operational request details');}
    qAssert(!str_contains(qHttp('admin','admin/requests.php')['body'],$code1)&&str_contains(qHttp('admin','admin/requests.php')['body'],'name="claim_slip_code"'),'Requests list prints the code or lacks the verification field');
    foreach(['director','mis','guest','student','faculty'] as $who)qAssert(qHttp($who,'admin/view_request.php?request_id='.$r1)['status']===302,$who.' opened the operational request details');
    foreach(['director/dashboard.php','director/dashboard.php?facility_calendar_month='.date('Y-m',strtotime($day(5)))] as $path)qAssert(!str_contains(qHttp('director',$path)['body'],'GSO-')&&!str_contains(qHttp('director',$path)['body'],'Claim Slip'),'Director page exposes claim slip information');
    qAssert(!str_contains(qHttp('mis','admin/accounts_dashboard.php')['body'],$code1),'MIS page exposes the code');
    foreach(['borrower/my_requests.php','admin/requests.php','admin/view_request.php?request_id='.$r1,'admin/view_request.php?claim_slip_code='.$code1,'index.php?claim_slip_code='.$code1] as $path){$anon=qHttp('guest',$path);qAssert(!str_contains($anon['body'],$code1)&&!str_contains($anon['body'],qCode($r1)),'Anonymous request exposed claim information via '.$path);}
    $auth=fn(string $who)=>['user_id'=>$users[$who],'role'=>$roles[$who][0]];
    foreach(['student','faculty'] as $who)qAssert(!str_contains(json_encode(gsoChatGetRequestStatus($pdo,$auth($who),qCode($r1))),$code1),'Chatbot status exposed the Claim Slip Code to '.$who);
    $reply=qHttp('student','chatbot/send.php',['csrf_token'=>$csrf['student'],'message'=>'What is the status of '.qCode($r1).'?']);qAssert($reply['status']===200&&str_contains($reply['body'],'Approved')&&!str_contains($reply['body'],$code1),'Chatbot endpoint failed or exposed the code');

    // ==== Release verification (15-18, 20, 40) ================================
    $before=$stock($chairs);$mailsBefore=count(qMails($r1));$failuresBefore=$failures();
    $attempts=['blank'=>'','spaces'=>'   ','missing'=>false,'wrong'=>$wrongFor($code1),'another request code'=>$batch[$other],'request reference'=>qCode($r1),'request id'=>(string)$r1,'prefix only'=>'GSO-','truncated'=>substr($code1,0,-1),'extended'=>$code1.'A','sql'=>"' OR '1'='1",'array'=>[$code1]];
    foreach($attempts as $label=>$presented){$release('admin',$r1,$presented);qAssert(qState($r1)==='Approved'&&$stock($chairs)===$before&&qVal('SELECT released_at FROM resource_requests WHERE request_id=?',[$r1])===null,'Release succeeded or mutated state with '.$label.' code');}
    qAssert((int)qVal('SELECT released_quantity+issued_quantity FROM request_items WHERE request_id=?',[$r1])===0&&count(qMails($r1))===$mailsBefore,'Denied release changed the request line or sent an email');
    qAssert($failures()===$failuresBefore+count($attempts)&&(int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Claim Slip Verification Failed' AND details REGEXP 'GSO-[2-9A-HJ-NP-Z]{8}'")===0,'Denied releases were not audited, or the audit stores a code');
    $denied=$release('admin',$r1,$wrongFor($code1));$page=qHttp('admin','admin/requests.php')['body'];qAssert(str_contains($page,'Release denied')&&!str_contains($page,$code1),'Denial message missing or reveals the correct code');
    foreach(['student','faculty','director','mis','guest'] as $who){$release($who,$r1,$code1);qAssert(qState($r1)==='Approved'&&$stock($chairs)===$before,$who.' released a request with the correct code');}
    $release('admin',$r1,$code1,['csrf_token'=>'forged']);qAssert(qState($r1)==='Approved','Release with the correct code bypassed CSRF');
    $release('admin',$other,$code1);qAssert(qState($other)==='Approved'&&qState($r1)==='Approved','A code released a different request');
    $release('staff',$r1,' '.strtolower($code1).' ');
    qAssert(qState($r1)==='Released'&&$stock($chairs)==['total_stock'=>40,'available_stock'=>$before['available_stock']-2,'under_repair_stock'=>0]&&count(qMails($r1,'Request Released'))===1,'Release with the correct code failed');
    qAssert(qSlip($r1)===$code1,'Release changed or erased the code');
    $afterRelease=$stock($chairs);$release('staff',$r1,$code1);qAssert($stock($chairs)===$afterRelease&&count(qMails($r1,'Request Released'))===1,'Repeated release with the code issued stock or email twice');

    // ==== Serialized: no allocation on a wrong code (19, 21, 23) ==============
    $serial=$item('faculty',$projector,2,9);$process('admin',$serial,'approve');$serialCode=qSlip($serial);$sl=$line($serial,$projector);
    $unitState=fn()=>qRows('SELECT unit_id,status FROM equipment_units WHERE resource_id=? ORDER BY unit_id',[$projector]);$unitsBefore=$unitState();
    $release('admin',$serial,$wrongFor($serialCode),['unit_ids'=>[$sl=>$units]]);
    qAssert(qState($serial)==='Approved'&&$unitState()===$unitsBefore&&(int)qVal('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=?',[$sl])===0&&$stock($projector)['available_stock']==2,'Wrong code allocated a serialized unit');
    $release('admin',$serial,$serialCode,['unit_ids'=>[$sl=>[$units[0]]]]);qAssert(qState($serial)==='Approved'&&(int)qVal('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=?',[$sl])===0,'Correct code bypassed the exact-unit rule');
    $release('admin',$serial,$serialCode,['unit_ids'=>[$sl=>$units]]);qAssert(qState($serial)==='Released'&&(int)qVal('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL',[$sl])===2&&qSlip($serial)===$serialCode,'Serialized release with the correct code failed');

    // ==== Mixed and facility-only requests (22, 24, 25) =======================
    $mixed=$submit('student',$hall,['quantity'=>1,'date_needed'=>$day(21),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','extra_lines'=>[['resource_id'=>$chairs,'quantity'=>3],['resource_id'=>$paper,'quantity'=>4]]],true);
    $verify($mixed);$process('admin',$mixed,'approve');$mixedCode=qSlip($mixed);
    qAssert(qState($mixed)==='Approved'&&(bool)preg_match(SLIP_FORMAT,(string)$mixedCode)&&(int)qVal('SELECT COUNT(*) FROM request_items WHERE request_id=?',[$mixed])===3,'Mixed request did not receive one request-level code');
    $paperBefore=$stock($paper);$chairsBefore=$stock($chairs);$release('admin',$mixed,$wrongFor($mixedCode));
    qAssert(qState($mixed)==='Approved'&&$stock($paper)===$paperBefore&&$stock($chairs)===$chairsBefore,'Wrong code deducted a consumable or equipment');
    $release('admin',$mixed,$mixedCode);qAssert(qState($mixed)==='Released'&&$stock($paper)['available_stock']==$paperBefore['available_stock']-4&&$stock($chairs)['available_stock']==$chairsBefore['available_stock']-3,'Valid mixed release did not issue every line exactly once');
    $release('admin',$mixed,$mixedCode);qAssert($stock($paper)['available_stock']==$paperBefore['available_stock']-4,'Repeated valid release deducted the consumable twice');
    $facilityOnly=$submit('faculty',$gym,['quantity'=>1,'date_needed'=>$day(22),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM'],true);$verify($facilityOnly);$process('admin',$facilityOnly,'approve');$gymCode=qSlip($facilityOnly);
    qAssert(qState($facilityOnly)==='Approved'&&(bool)preg_match(SLIP_FORMAT,(string)$gymCode),'Facility-only request did not receive a code');
    $release('admin',$facilityOnly,'');qAssert(qState($facilityOnly)==='Approved','Facility turnover skipped claim slip verification');

    // ==== Reschedule keeps the code (26) ======================================
    qHttp('faculty','borrower/reschedule.php?request_id='.$facilityOnly,['csrf_token'=>$csrf['faculty'],'action'=>'create','date_needed'=>$day(24),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','reason'=>'Moved by the organizer']);
    $proposal=(int)qVal('SELECT COALESCE(MAX(reschedule_id),0) FROM request_reschedule_history WHERE request_id=?',[$facilityOnly]);qAssert($proposal>0&&qSlip($facilityOnly)===$gymCode,'Reschedule proposal failed or changed the code');
    qHttp('staff','admin/reschedules.php?request_id='.$facilityOnly,['csrf_token'=>$csrf['staff'],'request_id'=>$facilityOnly,'reschedule_id'=>$proposal,'action'=>'approve']);
    qAssert(qVal('SELECT date_needed FROM resource_requests WHERE request_id=?',[$facilityOnly])===$day(24)&&qState($facilityOnly)==='Approved'&&qSlip($facilityOnly)===$gymCode,'Approved reschedule regenerated or dropped the code');
    qAssert(count(qMails($facilityOnly,'Request Approved'))===1&&count(qMails($facilityOnly,'Facility Reschedule Approved'))===1,'Reschedule re-issued the approval email or did not confirm the new schedule');
    $release('admin',$facilityOnly,$gymCode);qAssert(qState($facilityOnly)==='Released','Rescheduled request could not be released with its original code');

    // ==== Code is kept through the lifecycle (28-31) ==========================
    qHttp('student','borrower/return_submit.php?request_id='.$r1,['csrf_token'=>$csrf['student'],'condition_notes'=>'R10A','lines'=>[$line($r1,$chairs)=>['condition'=>'Good','returned_quantity'=>2,'lost_quantity'=>0]]]);
    qAssert(qState($r1)==='Return'&&qSlip($r1)===$code1,'Return submission failed or changed the code');
    $inspection=[];foreach(qRows('SELECT d.* FROM return_submission_items d JOIN return_submissions s ON s.return_id=d.return_id WHERE s.request_id=?',[$r1]) as $d)$inspection[$d['return_item_id']]=['condition'=>$d['reported_condition'],'remarks'=>'R10A'];
    qHttp('admin','admin/review_return.php?request_id='.$r1,['csrf_token'=>$csrf['admin'],'action'=>'approve','inspection'=>$inspection]);
    qAssert(qState($r1)==='Returned'&&qSlip($r1)===$code1&&$stock($chairs)['available_stock']==$afterRelease['available_stock']+2-3,'Inspection failed, changed the code, or mis-restored stock');
    $history=qHttp('student','borrower/my_requests.php')['body'];qAssert(str_contains($history,$code1)&&str_contains($history,'kept for record'),'Owner no longer sees the historical code');
    $detail=qHttp('admin','admin/view_request.php?request_id='.$r1)['body'];qAssert(str_contains($detail,$code1)&&str_contains($detail,'no longer authorizes a release')&&!str_contains($detail,'Print Claim Slip'),'GSO detail does not mark a used code as historical');
    $supplies=$item('faculty',$paper,2,10);$process('admin',$supplies,'approve');$suppliesCode=qSlip($supplies);$release('admin',$supplies,$suppliesCode);
    qAssert(qState($supplies)==='Completed'&&qSlip($supplies)===$suppliesCode,'Consumable-only request did not complete or lost its code');

    // ==== Cancelled after approval: old code no longer releases (27) ==========
    $cancelled=array_key_last($batch);$cancelledCode=$batch[$cancelled];$beforeCancel=$stock($chairs);
    qExec("UPDATE resource_requests SET status='Cancelled',cancellation_reason='Client withdrew after approval' WHERE request_id=?",[$cancelled]);
    $release('admin',$cancelled,$cancelledCode);
    qAssert(qState($cancelled)==='Cancelled'&&qSlip($cancelled)===$cancelledCode&&$stock($chairs)===$beforeCancel,'A cancelled request was released with its old code, or the code was erased');

    // ==== Walk-in (11, 12, 33, 34) ============================================
    $client=['walkin_full_name'=>$mark.' External Client','walkin_organization'=>'Community Group','walkin_email'=>strtolower($mark).'.client@outside.invalid','walkin_contact_number'=>'09123456789'];
    $walk=function(array $extra=[])use(&$csrf,$client,$chairs,$day,$newestWalkin,&$requests):int{$before=$newestWalkin();qHttp('staff','admin/create_walkin.php',['csrf_token'=>$csrf['staff'],'date_needed'=>$day(12),'start_time'=>'09:00','end_time'=>'11:00','lines'=>[['resource_id'=>$chairs,'quantity'=>1]]]+$extra+$client);$id=$newestWalkin();if($id>$before){$requests[]=$id;return $id;}return 0;};
    $userCount=(int)qVal('SELECT COUNT(*) FROM users');$walkin=$walk();qAssert($walkin>0&&qSlip($walkin)===null,'Walk-in was not created, or has a code before approval');
    $process('admin',$walkin,'approve');$walkCode=qSlip($walkin);
    qAssert(qState($walkin)==='Approved'&&(bool)preg_match(SLIP_FORMAT,(string)$walkCode)&&(int)qVal('SELECT COUNT(*) FROM users')===$userCount&&qVal('SELECT borrower_id FROM resource_requests WHERE request_id=?',[$walkin])===null,'Walk-in approval did not issue a code, or created an account');
    $walkMails=qMails($walkin,'Request Approved');$walkMail=(string)reset($walkMails);
    qAssert(count($walkMails)===1&&str_contains($walkMail,'<strong>To:</strong> '.$client['walkin_email'])&&str_contains($walkMail,'Claim Slip Code: '.$walkCode)&&!str_contains($walkMail,'borrower/'),'Walk-in approval email is missing, misaddressed, lacks the code, or links to a borrower page');
    foreach(['staff','admin'] as $who){$detail=qHttp($who,'admin/view_request.php?request_id='.$walkin)['body'];qAssert(str_contains($detail,$walkCode)&&str_contains($detail,'Print Claim Slip'),$who.' cannot view or print the walk-in code');}
    foreach(['student','faculty'] as $who)foreach(['borrower/my_requests.php','borrower/my_borrowed.php','borrower/history.php'] as $path)qAssert(!str_contains(qHttp($who,$path)['body'],$walkCode),'Borrower portal exposed a walk-in code');
    qAssert((int)qVal('SELECT COUNT(*) FROM notifications WHERE message LIKE ?',['%'.$walkCode.'%'])===0,'Walk-in code was written to a notification');
    foreach(['student','guest','director','mis'] as $who)qAssert(!str_contains(json_encode(gsoChatGetRequestStatus($pdo,['user_id'=>$users[$who]??0,'role'=>$roles[$who][0]??'']??[],qCode($walkin))),(string)$walkCode),'Chatbot exposed a walk-in code');
    $release('staff',$walkin,$wrongFor($walkCode));qAssert(qState($walkin)==='Approved','Walk-in released with a wrong code');
    $release('staff',$walkin,$walkCode);qAssert(qState($walkin)==='Released'&&qSlip($walkin)===$walkCode,'Walk-in release with the correct code failed');
    $noEmail=$walk(['walkin_email'=>'','date_needed'=>$day(13)]);$process('admin',$noEmail,'approve');
    qAssert(qState($noEmail)==='Approved'&&(bool)preg_match(SLIP_FORMAT,(string)qSlip($noEmail))&&count(qMails($noEmail,'Request Approved'))===0,'Walk-in without email was not approved with a code, or mail was attempted');
    qAssert(str_contains(qHttp('staff','admin/view_request.php?request_id='.$noEmail)['body'],(string)qSlip($noEmail)),'GSO cannot read the code of a walk-in without email');

    // ==== Email timing and failure (35, 36) ===================================
    $mailFail=$item('student',$chairs,1,14);file_put_contents($failureFile,'1');$process('admin',$mailFail,'approve');@unlink($failureFile);$failCode=qSlip($mailFail);
    qAssert(qState($mailFail)==='Approved'&&(bool)preg_match(SLIP_FORMAT,(string)$failCode)&&count(qMails($mailFail,'Request Approved'))===0,'Mail failure rolled back the approval or the code');
    qAssert(str_contains(qHttp('student','borrower/my_requests.php')['body'],(string)$failCode),'Owner cannot see the code after a mail failure');
    $process('admin',$mailFail,'approve');qAssert(qSlip($mailFail)===$failCode&&count(qMails($mailFail,'Request Approved'))===0,'Repeated approval after a mail failure changed the code or resent');
    $release('admin',$mailFail,$failCode);qAssert(qState($mailFail)==='Released','Request approved during a mail failure could not be released');
    $rolled=$item('faculty',$chairs,1,15);$pending=gsoNewUniqueClaimSlipCode($pdo);
    $pdo->beginTransaction();qExec("UPDATE resource_requests SET status='Approved',claim_slip_code=? WHERE request_id=?",[$pending,$rolled]);$event=gsoPrepareClientEvent($pdo,$rolled,'approved');
    qAssert(str_contains((string)$event['plain'],'Claim Slip Code: '.$pending)&&!gsoDeliverClientEvent($pdo,$event)['success']&&count(qMails($rolled))===1-1+count(qMails($rolled,'Request Received')),'Approval email was sent before the transaction committed');
    $pdo->rollBack();
    qAssert(qState($rolled)==='Pending'&&qSlip($rolled)===null&&(int)qVal('SELECT COUNT(*) FROM resource_requests WHERE claim_slip_code=?',[$pending])===0,'Rolled-back approval left a Claim Slip Code');
    qAssert(!empty(gsoDeliverClientEvent($pdo,$event)['skipped'])&&count(qMails($rolled,'Request Approved'))===0,'Rolled-back approval sent an approval email');

    // ==== Integrity ===========================================================
    qAssert((int)qVal("SELECT COUNT(*) FROM resource_requests WHERE status='Approved' AND claim_slip_code IS NULL")===0&&(int)qVal("SELECT COUNT(*) FROM resource_requests WHERE status IN('Pending','Under Review') AND claim_slip_code IS NOT NULL")===0,'Invalid status and code combination exists');
    qAssert((int)qVal('SELECT COUNT(*) FROM (SELECT claim_slip_code FROM resource_requests WHERE claim_slip_code IS NOT NULL GROUP BY claim_slip_code HAVING COUNT(*)>1) d')===0,'Duplicate Claim Slip Codes exist');
    qAssert((int)qVal('SELECT COUNT(*) FROM resource_requests r WHERE NOT EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id)')===0,'A request exists without request_items lines');
    qAssert((int)qVal('SELECT COUNT(*) FROM resources WHERE available_stock<0 OR under_repair_stock<0 OR available_stock+under_repair_stock>total_stock')===0,'Invalid stock exists');
    qAssert(qRows('SELECT * FROM resource_requests WHERE request_id IN('.implode(',',array_map('intval',array_column($legacy,'request_id'))?:[0]).') ORDER BY request_id')===$legacy,'Pre-existing requests changed');
    echo "PASS: R10A Claim Slip Code E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally{
    @unlink($failureFile);
    if($pdo->inTransaction())$pdo->rollBack();
    if($users){
        $ids=array_values($users);$marks=implode(',',array_fill(0,count($ids),'?'));
        foreach(qRows('SELECT request_id FROM resource_requests WHERE borrower_id IN('.$marks.') OR encoded_by IN('.$marks.')',[...$ids,...$ids]) as $r)$requests[]=(int)$r['request_id'];
        foreach(array_unique($requests) as $id){qExec('DELETE FROM request_reschedule_history WHERE request_id=?',[$id]);gsoTestDeleteLetters($pdo,$id);qExec('DELETE FROM return_submissions WHERE request_id=?',[$id]);qExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
        foreach($ids as $uid){qExec('DELETE FROM ai_conversations WHERE user_id=?',[$uid]);qExec('DELETE FROM notifications WHERE user_id=?',[$uid]);qExec('DELETE FROM activity_logs WHERE user_id=?',[$uid]);}
        foreach($resources as $id){qExec('DELETE FROM equipment_units WHERE resource_id=?',[$id]);qExec('DELETE FROM resources WHERE resource_id=?',[$id]);}
        foreach($ids as $uid)qExec('DELETE FROM users WHERE user_id=?',[$uid]);
    }
    foreach($cookies as $file)@unlink($file);
    if(!$failed&&qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id')!==$legacyInventory){$failed=new RuntimeException('cleanup');fwrite(STDERR,"FAIL: Fixture cleanup left inventory changed.\n");}
}
exit($failed?1:0);
