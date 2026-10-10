<?php
declare(strict_types=1);
/** R9: real HTTP business transitions, isolated DB and private test mail capture. */
require_once dirname(__DIR__).'/config/bootstrap.php';
if (!preg_match('/^gso_r9_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME','')) || gsoEnv('GSO_MAIL_TEST_TRANSPORT','')!=='outbox') throw new RuntimeException('Isolated R9 database and outbox transport required.');
$base=(string)gsoEnv('GSO_R9_BASE_URL','http://127.0.0.1:8100/GSO_WebSystem');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Loopback only.');
require_once dirname(__DIR__).'/config/db.php';
require_once dirname(__DIR__).'/includes/client_notification_helper.php';
require_once dirname(__DIR__).'/includes/reschedule_helper.php';
require_once dirname(__DIR__).'/includes/walkin_helper.php';
require_once dirname(__DIR__).'/includes/work_order_helper.php';
require_once dirname(__DIR__).'/includes/return_upload_helper.php';
require_once __DIR__.'/request_letter_test_helper.php';
$checks=0;$users=[];$cookies=[];$resources=[];$requests=[];$failed=null;
function qAssert(bool $v,string $m):void{global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
function qRows(string $q,array $a=[]):array{global $pdo;$s=$pdo->prepare($q);$s->execute($a);return $s->fetchAll(PDO::FETCH_ASSOC);}
function qVal(string $q,array $a=[]):mixed{$r=qRows($q,$a);return $r?reset($r[0]):null;}
function qExec(string $q,array $a=[]):void{global $pdo;$s=$pdo->prepare($q);$s->execute($a);}
function qFlat(array $d,string $p=''):array{$r=[];foreach($d as $k=>$v){$n=$p===''?(string)$k:$p.'['.$k.']';if(is_array($v))$r+=qFlat($v,$n);else $r[$n]=$v;}return $r;}
function qCurl(string $who,string $path,?array $data=null){global $base,$cookies;$c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookies[$who],CURLOPT_COOKIEJAR=>$cookies[$who],CURLOPT_TIMEOUT=>30]);if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>qFlat($data)]);return $c;}
function qHttp(string $who,string $path,?array $data=null):array{$c=qCurl($who,$path,$data);$raw=curl_exec($c);if(!is_string($raw))throw new RuntimeException(curl_error($c));$n=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);$body=substr($raw,$n);if($status>=500||preg_match('/<b>(Warning|Fatal error|Notice|Deprecated)<\/b>:/i',$body))throw new RuntimeException('HTTP/PHP failure '.$path.' '.substr(strip_tags($body),0,300));return ['status'=>$status,'body'=>$body];}
function qRace(array $calls):array{$multi=curl_multi_init();$handles=[];foreach($calls as [$who,$path,$data]){$c=qCurl($who,$path,$data);curl_multi_add_handle($multi,$c);$handles[]=$c;}do{$code=curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running&&$code===CURLM_OK);$statuses=[];foreach($handles as $c){$statuses[]=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);return $statuses;}
/** True when the callable is refused with a business-rule error. */
function qDenied(callable $f):bool{try{$f();return false;}catch(DomainException $e){return true;}}



$mark='R9-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$outbox=gsoMailOutboxDirectory();$failureFile=$outbox.DIRECTORY_SEPARATOR.'.simulate_failure';$tempFiles=[];
function qMails(int $id,?string $title=null):array {
    global $outbox;$messages=[];$reference=formatRequestCode($id);
    foreach(glob($outbox.'/*.html')?:[] as $file){$body=file_get_contents($file);if(preg_match('/'.preg_quote($reference,'/').'\b/',$body)&&($title===null||str_contains($body,'GSO - '.$title.' - '.$reference)))$messages[$file]=$body;}
    return $messages;
}
function qLastMail(int $id,string $title):string {$m=qMails($id,$title);return $m?end($m):'';}
function qState(int $id):string {return (string)qVal('SELECT status FROM resource_requests WHERE request_id=?',[$id]);}
try {
    foreach(['a'=>'Borrower','b'=>'Borrower','admin'=>'Admin','staff'=>'GSO Staff','mis'=>'Super Admin','director'=>'Director'] as $key=>$role){
        $username=strtolower($mark.'_'.$key);
        qExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified)VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$role==='Borrower'?'University Staff':null]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r9_cookie_');$page=qHttp($key,'auth/login.php');preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m);$csrf[$key]=$m[1]??'';
        qAssert(qHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed: '.$key);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r9_guest_');
    $resource=function(string $name,string $type='Item',string $mode='Aggregate',int $stock=100)use($mark):int {global $pdo,$resources;qExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status)VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$mode,$stock,$stock]);return $resources[]=(int)$pdo->lastInsertId();};
    $item=$resource('Equipment <script>alert(1)</script>');$hall=$resource('Hall','Facility','Facility',1);$paper=$resource('Paper','Item','Consumable',50);
    $day=fn(int $offset)=>date('Y-m-d',strtotime('+'.$offset.' days'));
    $make=function(int $res,int $offset,string $mode='Registered',?string $email=null,int $qty=2)use($day,$users,$hall,$paper):int{global $pdo,$requests;
        qExec('INSERT INTO resource_requests(borrower_id,resource_id,quantity,date_needed,start_time,end_time,status,requester_type,encoded_by,walkin_full_name,walkin_email,walkin_contact_number,notes)VALUES(?,?,?,?,?,?,\'Pending\',?,?,?,?,?,?)',[$mode==='Registered'?$users['a']:null,$res,$qty,$day($offset),'09:00:00','11:00:00',$mode,$mode==='Walk-in'?$users['staff']:null,$mode==='Walk-in'?'External <script>private()</script>':null,$mode==='Walk-in'?$email:null,$mode==='Walk-in'?'09123456789':null,'INTERNAL-PRIVATE-NOTE']);
        $id=(int)$pdo->lastInsertId();$requests[]=$id;qExec('INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity,facility_slot)VALUES(?,?,?,?,?)',[$id,$res,$res===$hall?'Facility':($res===$paper?'Consumable':'Equipment'),$qty,$res===$hall?'AM':null]);return $id;
    };
    $process=fn(int $id,string $action,array $extra=[],string $who='admin')=>qHttp($who,'admin/requests.php',array_replace(['csrf_token'=>$csrf[$who]??'','request_id'=>$id,'action'=>$action],$extra));
    $mailCount=fn(int $id,?string $title=null)=>count(qMails($id,$title));
    // Real registered submission ignores destination fields supplied by the browser.
    $result=qHttp('a','borrower/request_resource.php?resource_id='.$item,['csrf_token'=>$csrf['a'],'quantity'=>2,'contact_number'=>'09123456789','date_needed'=>$day(10),'notes'=>'Client purpose','email'=>'attacker@example.invalid','notification_email'=>'attacker@example.invalid']);
    $submitted=(int)qVal('SELECT MAX(request_id) FROM resource_requests WHERE borrower_id=?',[$users['a']]);$requests[]=$submitted;
    qAssert($submitted>0&&qState($submitted)==='Pending','Registered submission failed');
    qAssert((int)qVal("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='request_submitted'",[$users['a']])===1,'Registered receipt in-app missing');
    $body=qLastMail($submitted,'Request Received');qAssert($body!==''&&str_contains($body,'Status: Pending'),'Registered receipt email missing/state mismatch');qAssert(str_contains($body,strtolower($mark.'_a').'@example.invalid')&&!str_contains($body,'attacker@example.invalid'),'POST controlled recipient');
    qAssert(!str_contains($body,'<script>')&&str_contains($body,'&lt;script&gt;'),'Resource HTML injection');
    qAssert(!str_contains($body,'INTERNAL-PRIVATE-NOTE')&&!str_contains($body,'password_hash'),'Private fields leaked');
    // Real walk-in creation uses a request-scoped email and no fake client account.
    $userCount=(int)qVal('SELECT COUNT(*) FROM users');
    $walkData=['lines'=>[['resource_id'=>$item,'quantity'=>1]],'date_needed'=>$day(11),'walkin_full_name'=>'External Client','walkin_email'=>'external-'.$mark.'@example.invalid','walkin_contact_number'=>'09123456789','notes'=>'purpose'];
    $walk=gsoCreateWalkin($pdo,$users['staff'],$walkData,[]);$requests[]=$walk;
    qAssert(qState($walk)==='Pending'&&qLastMail($walk,'Request Received')!=='','Walk-in receipt missing');qAssert((int)qVal('SELECT COUNT(*) FROM users')===$userCount&&qVal('SELECT borrower_id FROM resource_requests WHERE request_id=?',[$walk])===null,'Fake Walk-in account created');
    qAssert(str_contains(qLastMail($walk,'Request Received'),$walkData['walkin_email'])&&!str_contains(qLastMail($walk,'Request Received'),'borrower/'),'Walk-in recipient/link incorrect');
    $without=gsoCreateWalkin($pdo,$users['staff'],array_replace($walkData,['date_needed'=>$day(12),'walkin_email'=>'']),[]);$requests[]=$without;qAssert(qState($without)==='Pending'&&$mailCount($without)===0,'Optional email blocked request or attempted mail');qAssert((int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Client Email Attempt' AND user_id=? AND details IN(SELECT details FROM activity_logs WHERE action='Client Email Skipped')",[$users['staff']])===0,'Missing optional email recorded a delivery attempt');
    $invalid=false;try{gsoCreateWalkin($pdo,$users['staff'],array_replace($walkData,['walkin_email'=>'invalid-address']),[]);}catch(DomainException $e){$invalid=true;}qAssert($invalid,'Invalid Walk-in email accepted');
    $walk2=gsoCreateWalkin($pdo,$users['staff'],array_replace($walkData,['date_needed'=>$day(13),'walkin_email'=>'changed-'.$mark.'@example.invalid']),[]);$requests[]=$walk2;qAssert(qVal('SELECT walkin_email FROM resource_requests WHERE request_id=?',[$walk])===$walkData['walkin_email'],'Walk-in history email rewritten');
    // Explicit prepare/rollback tests prove no message is sent before commit.
    $rolled=$make($item,14);$pdo->beginTransaction();qExec("UPDATE resource_requests SET status='Approved' WHERE request_id=?",[$rolled]);$event=gsoPrepareClientEvent($pdo,$rolled,'approved');qAssert(!gsoDeliverClientEvent($pdo,$event)['success']&&$mailCount($rolled)===0,'Mail sent before commit');$pdo->rollBack();qAssert(gsoDeliverClientEvent($pdo,$event)['skipped']&&qState($rolled)==='Pending'&&$mailCount($rolled)===0,'Rolled-back approval sent success mail');
    // HTTP approval, duplicate POST/GET, actual current registered email.
    qExec('UPDATE users SET email=? WHERE user_id=?',['current-'.$mark.'@example.invalid',$users['a']]);$process($submitted,'approve');qAssert(qState($submitted)==='Approved'&&$mailCount($submitted,'Request Approved')===1,'Approval commit/email failed');
    $body=qLastMail($submitted,'Request Approved');qAssert(str_contains($body,'current-'.$mark.'@example.invalid')&&str_contains($body,'Status: Approved'),'Current user email/state ignored');qAssert(str_contains($body,'borrower/my_requests.php')&&!preg_match('/[?&](?:token|auth)=/',$body),'Authenticated notification link unsafe');
    $before=$mailCount($submitted);qHttp('admin','admin/view_request.php?request_id='.$submitted);qHttp('admin','admin/requests.php');$process($submitted,'approve');qAssert($mailCount($submitted)===$before,'GET or repeated approval resent email');
    // Duplicate invocation within/across actions uses one recorded event.
    $pdo->beginTransaction();$duplicate=gsoPrepareClientEvent($pdo,$submitted,'approved');$pdo->commit();qAssert($duplicate===null,'Repeated event helper created duplicate');
    $failure=$make($item,15);file_put_contents($failureFile,'fail');$process($failure,'approve');qAssert(qState($failure)==='Approved'&&$mailCount($failure)===0,'Mail failure rolled back approval');qAssert((int)qVal("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='approved' AND message LIKE ?",[$users['a'],formatRequestCode($failure).'%'])===1,'SMTP failure removed in-app approval');qAssert((int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Client Email Failed' AND user_id=?",[$users['a']])>0,'Mail failure not logged');unlink($failureFile);
    $rejected=$make($item,16);$process($rejected,'reject');qAssert(qState($rejected)==='Rejected'&&str_contains(qLastMail($rejected,'Request Rejected'),'Status: Rejected'),'Rejection semantics wrong');
    gsoCancelWalkin($pdo,$users['staff'],$walk2,'Private internal reason');qAssert(qState($walk2)==='Cancelled'&&qLastMail($walk2,'Request Cancelled')!=='','GSO cancellation email missing');qAssert(!str_contains(qLastMail($walk2,'Request Cancelled'),'Private internal reason'),'Private cancellation remarks leaked');
    // Facility approval includes AM/PM and exact confirmed schedule.
    $facility=$make($hall,40,qty:1);gsoTestVerifiedLetter($pdo,$facility,$users['admin']);$process($facility,'approve');qAssert(qState($facility)==='Approved','Facility approval failed');$body=qLastMail($facility,'Request Approved');qAssert(str_contains($body,$day(40))&&str_contains($body,'AM')&&str_contains($body,'09:00 to 11:00'),'Facility schedule missing');
    $proposal=gsoCreateReschedule($pdo,$users['a'],$facility,['date_needed'=>$day(41),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','reason'=>'PRIVATE-PROPOSAL-NOTE']);$attempt=(int)$proposal['reschedule_id'];
    qAssert(str_contains(qLastMail($facility,'Reschedule Proposal Received'),'not guaranteed')&&str_contains(qLastMail($facility,'Reschedule Proposal Received'),$day(40)),'Proposal receipt confused confirmed schedule');
    file_put_contents($failureFile,'fail');gsoReviewReschedule($pdo,$users['admin'],$facility,$attempt,'approve',['review_remarks'=>'INTERNAL-REVIEW-NOTE']);qAssert(qVal('SELECT date_needed FROM resource_requests WHERE request_id=?',[$facility])===$day(41)&&qVal('SELECT status FROM request_reschedule_history WHERE reschedule_id=?',[$attempt])==='Approved','Mail failure undid reschedule');unlink($failureFile);
    $proposal=gsoCreateReschedule($pdo,$users['a'],$facility,['date_needed'=>$day(42),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','reason'=>'PRIVATE-PROPOSAL-NOTE']);gsoReviewReschedule($pdo,$users['admin'],$facility,(int)$proposal['reschedule_id'],'approve',[]);
    $body=qLastMail($facility,'Facility Reschedule Approved');qAssert(str_contains($body,$day(42))&&str_contains($body,'09:00 to 11:00')&&!str_contains($body,$day(41)),'Approved email showed old schedule');
    $proposal=gsoCreateReschedule($pdo,$users['a'],$facility,['date_needed'=>$day(43),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','reason'=>'PRIVATE-PROPOSAL-NOTE']);gsoReviewReschedule($pdo,$users['admin'],$facility,(int)$proposal['reschedule_id'],'reject',['review_remarks'=>'INTERNAL-REVIEW-NOTE']);$body=qLastMail($facility,'Facility Reschedule Rejected');qAssert(str_contains($body,'original confirmed reservation remains unchanged')&&str_contains($body,$day(42))&&!str_contains($body,$day(43)),'Rejected proposal presented as current');
    // Document verification is a separate transaction, not electronic approval.
    $letterReq=$make($hall,44,qty:1);gsoTestVerifiedLetter($pdo,$letterReq,$users['admin']);$attachment=(int)qVal('SELECT MAX(attachment_id) FROM request_attachments WHERE request_id=?',[$letterReq]);qExec("UPDATE request_attachments SET verification_status='Pending' WHERE attachment_id=?",[$attachment]);
    $verify=function(string $action,string $reason='')use($letterReq,$attachment,$csrf){return qHttp('admin','admin/view_request.php?request_id='.$letterReq,['csrf_token'=>$csrf['admin'],'action'=>$action,'attachment_id'=>$attachment,'verification_remarks'=>$reason]);};
    $verify('verify_letter');$body=qLastMail($letterReq,'Request Letter Verified');qAssert(str_contains($body,'document was verified')&&str_contains($body,'approval remains a separate action')&&!str_contains($body,'electronically'),'Document verification wording wrong');
    qExec("UPDATE request_attachments SET verification_status='Pending' WHERE attachment_id=?",[$attachment]);$verify('reject_letter','Replace <script>bad()</script> document');$body=qLastMail($letterReq,'Request Letter Rejected');qAssert(str_contains($body,'corrected manually signed')&&str_contains($body,'&lt;script&gt;')&&!str_contains($body,'<script>'),'Document rejection not escaped/corrective');
    // Release, then reject/resubmit/accept returns through real endpoints.
    $process($submitted,'release');qAssert(qState($submitted)==='Released'&&qLastMail($submitted,'Request Released')!=='','Release email missing');qAssert(!str_contains(qLastMail($submitted,'Request Released'),'Claim Code:'),'New claim system invented');
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');$photo=tempnam(sys_get_temp_dir(),'r9_photo_').'.png';file_put_contents($photo,$png);$tempFiles[]=$photo;
    $line=(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=?',[$submitted]);
    $submit=function()use($submitted,$line,$photo,$csrf){return qHttp('a','borrower/return_submit.php?request_id='.$submitted,['csrf_token'=>$csrf['a'],'reported_condition'=>'Good','condition_notes'=>'Client evidence','lines'=>[$line=>['outcomes'=>['Damaged'=>1,'Lost'=>1]]],'proof_photos'=>[new CURLFile($photo,'image/png','proof.png')]]);};
    $inspect=function(string $action)use($submitted,$line,$csrf){$details=qRows('SELECT return_item_id,reported_condition,returned_quantity,lost_quantity FROM return_submission_items WHERE return_id=(SELECT return_id FROM return_submissions WHERE request_id=?)',[$submitted]);$data=[];foreach($details as $d)$data[$d['return_item_id']]=['condition'=>$d['reported_condition'],'returned_quantity'=>$d['returned_quantity']];return qHttp('admin','admin/review_return.php?request_id='.$submitted,['csrf_token'=>$csrf['admin'],'action'=>$action,'inspection_condition'=>'Good','inspection_remarks'=>'PRIVATE-INSPECTION-NOTE','inspection'=>$data]);};
    $submit();qAssert(qState($submitted)==='Return','Return submission failed');$body=qLastMail($submitted,'Return Submission Received');qAssert(str_contains($body,'Status: Return')&&str_contains($body,'awaiting GSO inspection')&&!str_contains($body,'Successfully Returned'),'Return prematurely accepted');
    $inspect('reject');qAssert(qState($submitted)==='Released'&&str_contains(qLastMail($submitted,'Return Submission Rejected'),'Correct and resubmit'),'Return rejection semantics wrong');
    $submit();qAssert(qState($submitted)==='Return'&&$mailCount($submitted,'Return Submission Received')===2,'Return resubmission not a distinct event');
    $beforeStock=qRows('SELECT total_stock,available_stock,under_repair_stock FROM resources WHERE resource_id=?',[$item])[0];file_put_contents($failureFile,'fail');$inspect('approve');qAssert(qState($submitted)==='Returned','Mail failure undid accepted inspection');qAssert((int)qVal('SELECT total_stock FROM resources WHERE resource_id=?',[$item])===(int)$beforeStock['total_stock']-1&&(int)qVal('SELECT under_repair_stock FROM resources WHERE resource_id=?',[$item])===(int)$beforeStock['under_repair_stock']+1,'Mail failure undid loss/repair settlement');unlink($failureFile);
    $returnReq=$make($item,45);$process($returnReq,'approve');$process($returnReq,'release');
    $returnLine=(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=?',[$returnReq]);
    qHttp('a','borrower/return_submit.php?request_id='.$returnReq,['csrf_token'=>$csrf['a'],'lines'=>[$returnLine=>['outcomes'=>['Good'=>2]]]]);
    qAssert(qState($returnReq)==='Return'&&qLastMail($returnReq,'Return Inspection Accepted')==='','Acceptance email appeared before inspection');
    $returnDetail=(int)qVal('SELECT return_item_id FROM return_submission_items WHERE return_id=(SELECT return_id FROM return_submissions WHERE request_id=?)',[$returnReq]);
    qHttp('admin','admin/review_return.php?request_id='.$returnReq,['csrf_token'=>$csrf['admin'],'action'=>'approve','inspection'=>[$returnDetail=>['condition'=>'Good']]]);
    qAssert(qState($returnReq)==='Returned'&&str_contains(qLastMail($returnReq,'Return Inspection Accepted'),'Status: Returned'),'Successful inspection email missing');
    $before=$mailCount($returnReq);qHttp('admin','admin/review_return.php?request_id='.$returnReq,['csrf_token'=>$csrf['admin'],'action'=>'approve','inspection'=>[$returnDetail=>['condition'=>'Good']]]);qHttp('admin','admin/view_request.php?request_id='.$returnReq);qAssert($mailCount($returnReq)===$before,'Repeated accepted inspection or GET resent email');
    // Repeated delivery calls with the same prepared payload are also claimed once.
    $direct=$make($paper,46);$pdo->beginTransaction();qExec("UPDATE resource_requests SET status='Approved' WHERE request_id=?",[$direct]);$event=gsoPrepareClientEvent($pdo,$direct,'approved');$pdo->commit();gsoDeliverClientEvent($pdo,$event);$before=$mailCount($direct);gsoDeliverClientEvent($pdo,$event);qAssert($mailCount($direct)===$before,'Repeated delivery helper resent email');
    $details=qRows('SELECT return_item_id,inspection_condition FROM return_submission_items WHERE return_id=(SELECT return_id FROM return_submissions WHERE request_id=?)',[$submitted]);
    foreach($details as $detail){qAssert(gsoRecordBorrowerAccountability($pdo,(int)$detail['return_item_id'],$users['admin'],'Confirmed','PRIVATE-INTERNAL-ACCOUNTABILITY')['ok'],'Accountability review failed');$body=qLastMail($submitted,'Accountability Review Update');qAssert(str_contains($body,'Damaged')&&str_contains($body,'Lost')&&!str_contains($body,'PRIVATE-INTERNAL'),'Outcome distinction/privacy failed');qAssert(!preg_match('/(?:fee|billing|payment|replacement amount|repair cost|[0-9]+\s*pesos)/i',$body),'Financial accountability introduced');}
    $before=$mailCount($submitted);$detail=$details[0];gsoRecordBorrowerAccountability($pdo,(int)$detail['return_item_id'],$users['admin'],'Confirmed','PRIVATE-INTERNAL-ACCOUNTABILITY');qAssert($mailCount($submitted)===$before,'Duplicate accountability action resent email');
    // Overdue reminders: only Released, 24-hour cooldown, both requester modes.
    qExec("UPDATE resource_requests SET due_date=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE request_id=?",[$failure]);$process($failure,'release');qExec("UPDATE resource_requests SET due_date=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE request_id=?",[$failure]);
    $remind=fn(int $id)=>qHttp('staff','admin/on_loan.php',['csrf_token'=>$csrf['staff'],'action'=>'remind_overdue','request_id'=>$id]);$remind($failure);qAssert(qLastMail($failure,'Return Reminder')!==''&&qState($failure)==='Released','Registered overdue email missing');$before=$mailCount($failure);$remind($failure);qAssert($mailCount($failure)===$before,'Overdue cooldown duplicated email');
    qExec("UPDATE resource_requests SET status='Return' WHERE request_id=?",[$failure]);$remind($failure);qAssert($mailCount($failure)===$before,'Return state received ordinary reminder');
    $process($walk,'approve');$process($walk,'release');qExec("UPDATE resource_requests SET due_date=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE request_id=?",[$walk]);$beforeNotices=(int)qVal('SELECT COUNT(*) FROM notifications');$remind($walk);qAssert(str_contains(qLastMail($walk,'Return Reminder'),$walkData['walkin_email']),'Walk-in overdue snapshot email missing');qAssert((int)qVal('SELECT COUNT(*) FROM notifications')===$beforeNotices,'Walk-in reminder created in-app recipient');
    // No client email for assignments, ordinary GETs, or unrelated roles.
    $before=count(glob($outbox.'/*.html')?:[]);qHttp('director','director/dashboard.php');qHttp('mis','admin/users.php');qHttp('admin','admin/view_request.php?request_id='.$returnReq);qAssert(count(glob($outbox.'/*.html')?:[])===$before,'Viewing or internal account action sent client email');
    foreach(['director','mis','b','guest'] as $who){$before=$mailCount($rejected);$process($rejected,'approve',[],$who);qAssert($mailCount($rejected)===$before&&qState($rejected)==='Rejected','Unauthorized action sent email: '.$who);}
    $before=$mailCount($without);$process($without,'approve',['csrf_token'=>'bad']);qAssert(qState($without)==='Pending'&&$mailCount($without)===$before,'CSRF failure sent success email');
    $oldBase=getenv('GSO_APP_URL');putenv('GSO_APP_URL=');$_SERVER['HTTP_HOST']='attacker.invalid';qAssert(gsoClientNotificationUrl('my_requests.php')===null,'Browser Host became an email link');putenv('GSO_APP_URL=https://trusted.example.invalid/GSO_WebSystem');qAssert(gsoClientNotificationUrl('my_requests.php')==='https://trusted.example.invalid/GSO_WebSystem/borrower/my_requests.php','Configured application link incorrect');qAssert(gsoClientNotificationUrl('https://attacker.invalid/')===null,'External link accepted');putenv('GSO_APP_URL='.$oldBase);unset($_SERVER['HTTP_HOST']);
    $config=require dirname(__DIR__).'/config/mail.php';$result=gsoSendEmail($config,'signup-'.$mark.'@example.invalid','Test','Signup Verification','<p>Verify account</p>','Verify account');qAssert($result['success']&&$result['channel']==='test_outbox','Signup mail transport unavailable');$result=gsoSendEmail($config,'reset-'.$mark.'@example.invalid','Test','Password Reset','<p>Reset password</p>','Reset password');qAssert($result['success'],'Password reset mail transport unavailable');
    foreach($requests as $id)foreach(qMails($id) as $body){qAssert(!str_contains($body,'Director approved')&&!str_contains($body,'Adviser endorsement')&&!str_contains($body,'SMTP')&&!str_contains($body,'INTERNAL-PRIVATE-NOTE'),'Obsolete approval/SMTP/private wording');qAssert(!str_contains($body,strtolower($mark.'_director').'@example.invalid')&&!str_contains($body,strtolower($mark.'_mis').'@example.invalid'),'Director/MIS received borrower email');}
    qAssert((int)qVal('SELECT COUNT(*) FROM users')===$userCount,'Email delivery fabricated users');
    echo "PASS: R9 transactional email notifications E2E ({$checks} checks).\n";
} catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally {
    if($pdo->inTransaction())$pdo->rollBack();if(is_file($failureFile))unlink($failureFile);
    foreach($requests as $id){qExec('DELETE FROM borrower_liabilities WHERE request_id=?',[$id]);foreach(qRows('SELECT p.filename FROM return_submission_photos p JOIN return_submissions s ON s.return_id=p.return_id WHERE s.request_id=?',[$id]) as $photo)deleteReturnPhotoFile($photo['filename']);qExec('DELETE FROM request_reschedule_history WHERE request_id=?',[$id]);gsoTestDeleteLetters($pdo,$id);qExec('DELETE FROM return_submissions WHERE request_id=?',[$id]);qExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
    foreach($users as $uid){qExec('DELETE FROM notifications WHERE user_id=?',[$uid]);qExec('DELETE FROM activity_logs WHERE user_id=?',[$uid]);}
    foreach($resources as $id)qExec('DELETE FROM resources WHERE resource_id=?',[$id]);foreach($users as $uid)qExec('DELETE FROM users WHERE user_id=?',[$uid]);foreach([...array_values($cookies),...$tempFiles] as $file)@unlink($file);
}
exit($failed?1:0);
