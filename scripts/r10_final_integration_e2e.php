<?php
declare(strict_types=1);
/**
 * R10 final integration E2E: cross-feature journeys on the cleaned final schema.
 * Run only against an isolated gso_r*_test_* database with the loopback test
 * server and the private outbox mail transport.
 */
require_once dirname(__DIR__).'/config/bootstrap.php';
if(!preg_match('/^gso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME',''))||gsoEnv('GSO_MAIL_TEST_TRANSPORT','')!=='outbox')throw new RuntimeException('Isolated test database and outbox mail transport required.');
$base=(string)gsoEnv('GSO_R10_BASE_URL','http://127.0.0.1:8100/GSO_WebSystem');
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback only.');
$root=dirname(__DIR__);
require_once $root.'/config/db.php';require_once __DIR__.'/claim_slip_test_helper.php';
require_once $root.'/includes/request_helper.php';
require_once $root.'/includes/request_line_helper.php';
require_once $root.'/includes/request_attachment_helper.php';
require_once $root.'/includes/reschedule_helper.php';
require_once $root.'/includes/walkin_helper.php';
require_once $root.'/includes/work_order_helper.php';
require_once $root.'/includes/preventive_maintenance_helper.php';
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
function qDenied(callable $f):bool{try{$f();return false;}catch(DomainException $e){return true;}}
function qState(int $id):string{return (string)qVal('SELECT status FROM resource_requests WHERE request_id=?',[$id]);}
function qCode(int $id):string{return formatRequestCode($id);}

$mark='R10-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$outbox=gsoMailOutboxDirectory();$failureFile=$outbox.DIRECTORY_SEPARATOR.'.simulate_failure';@unlink($failureFile);
/** Captured emails for one request reference, optionally limited to one subject title. */
function qMails(int $id,?string $title=null):array{global $outbox;$found=[];$reference=qCode($id);foreach(glob($outbox.'/*.html')?:[] as $file){$body=(string)file_get_contents($file);if(preg_match('/'.preg_quote($reference,'/').'\b/',$body)&&($title===null||str_contains($body,'GSO - '.$title.' - '.$reference)))$found[$file]=$body;}return $found;}
function qMailCount(int $id,string $title):int{return count(qMails($id,$title));}

$legacyRequests=qRows('SELECT * FROM resource_requests ORDER BY request_id');
$legacyInventory=qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id');
$legacyUsers=(int)qVal('SELECT COUNT(*) FROM users');
try{
    // ---- Final schema: retired structures are gone ------------------------
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()")===22,'Final schema does not have 22 tables');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema=DATABASE()")===57,'Final schema does not have 57 foreign keys');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('request_adviser_history','request_director_history','maintenance_history','preventive_work_orders')")===0,'A retired or duplicate table exists');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='resource_requests' AND (column_name LIKE 'adviser%' OR column_name LIKE 'director%' OR column_name IN('resource_id','quantity'))")===0,'Retired request columns exist');
    qAssert(gsoRecordedSchemaVersion($pdo)===GSO_SCHEMA_VERSION,'Recorded schema version does not match the code');
    foreach(['ensureSystemSchema','gsoLegacyBaselineUpgrade','gsoRunSchemaMigrations','gsoSyncRequestLegacyMirror','gsoPrimaryMirrorLine','gsoAdviserHistory','gsoDirectorHistory'] as $name)qAssert(!function_exists($name),'Web-path code still defines '.$name);
    $dbBootstrap=(string)file_get_contents($root.'/config/db.php');qAssert(!str_contains($dbBootstrap,'ensureSystemSchema')&&!str_contains($dbBootstrap,'legacy_schema_upgrader')&&!str_contains($dbBootstrap,'schema_migrator')&&str_contains($dbBootstrap,'gsoAssertSchemaCurrent'),'Request bootstrap can still migrate the schema');
    foreach(glob($root.'/app/{controllers,models,views}/{*,*/*,*/*/*}.php',GLOB_BRACE)?:[] as $file){$name=str_replace('\\','/',substr($file,strlen($root)+1));if(in_array($name,['app/models/legacy_schema_upgrader.php','app/models/schema_migrator.php'],true)||str_starts_with($name,'app/controllers/gso_staff/pages/'))continue;$source=(string)file_get_contents($file);qAssert(!preg_match('/\b(?:ALTER|DROP)\s+TABLE\b|CREATE\s+TABLE\b/i',$source),'Request-path file can alter schema: '.$name);}

    // ---- Fixtures ----------------------------------------------------------
    $roles=['student'=>['Borrower','Student'],'faculty'=>['Borrower','Faculty'],'ustaff'=>['Borrower','University Staff'],'admin'=>['Admin',null],'staff'=>['GSO Staff',null],'mis'=>['Super Admin',null],'director'=>['Director',null]];
    foreach($roles as $key=>[$role,$type]){
        $username=strtolower($mark.'_'.$key);
        qExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified)VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$type]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r10_cookie_');
        $page=qHttp($key,'auth/login.php');preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m);$csrf[$key]=$m[1]??'';
        qAssert(qHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed: '.$key);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r10_guest_');
    $mailTo=fn(string $key)=>strtolower($mark.'_'.$key).'@example.invalid';
    $resource=function(string $name,string $type='Item',string $mode='Aggregate',int $stock=10)use($mark,&$resources):int{global $pdo;qExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status)VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$mode,$stock,$stock]);return $resources[]=(int)$pdo->lastInsertId();};
    $hall=$resource('Hall','Facility','Facility',1);$gym=$resource('Gym','Facility','Facility',1);$court=$resource('Court','Facility','Facility',1);$lab=$resource('Lab','Facility','Facility',1);
    $chairs=$resource('Chairs');$tables=$resource('Tables');$projector=$resource('Projector','Item','Serialized',3);$paper=$resource('Paper','Item','Consumable',50);
    $units=[];for($i=0;$i<3;$i++){qExec("INSERT INTO equipment_units(resource_id,asset_tag,serial_number,status,condition_status,registered_by)VALUES(?,?,?,'Available','Good',?)",[$projector,$mark.'-PRJ-'.$i,$mark.'-SN-'.$i,$users['admin']]);$units[]=(int)$pdo->lastInsertId();}
    gsoRecalculateSerializedStock($pdo,$projector);
    $day=fn(int $offset)=>date('Y-m-d',strtotime(($offset>=0?'+':'').$offset.' days'));$today=date('Y-m-d');
    $stock=fn(int $id)=>qRows('SELECT total_stock,available_stock,under_repair_stock FROM resources WHERE resource_id=?',[$id])[0];
    $unit=fn(int $id)=>qRows('SELECT status,condition_status FROM equipment_units WHERE unit_id=?',[$id])[0];
    $line=fn(int $request,int $res)=>(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=? AND resource_id=?',[$request,$res]);
    $newest=fn(string $who)=>(int)qVal('SELECT COALESCE(MAX(request_id),0) FROM resource_requests WHERE borrower_id=?',[$users[$who]]);
    $newestWalkin=fn()=>(int)qVal('SELECT COALESCE(MAX(request_id),0) FROM resource_requests WHERE encoded_by IN(?,?)',[$users['staff'],$users['admin']]);
    $png=gsoTestLetterImage();
    $submit=function(string $who,int $primary,array $data,bool $letter=false)use(&$csrf,$png,$newest,&$requests):int{
        $before=$newest($who);$post=['csrf_token'=>$csrf[$who],'contact_number'=>'09123456789']+$data;if($letter)$post['request_letter']=new CURLFile($png,'image/png','signed-letter.png');
        qHttp($who,'borrower/request_resource.php?resource_id='.$primary,$post);$id=$newest($who);if($id>$before){$requests[]=$id;return $id;}return 0;
    };
    $process=fn(string $who,int $id,string $action,array $extra=[])=>qHttp($who,'admin/requests.php',array_replace(['csrf_token'=>$csrf[$who]??'','request_id'=>$id,'action'=>$action]+($action==='release'?['claim_slip_code'=>gsoTestPresentedClaimSlip($id)]:[]),$extra));
    $verify=function(int $id,string $who='staff',string $action='verify_letter',string $remarks='')use(&$csrf,$pdo){$letter=gsoRequestLetters($pdo,$id)[0]??null;return qHttp($who,'admin/view_request.php?request_id='.$id,['csrf_token'=>$csrf[$who]??'','action'=>$action,'attachment_id'=>(int)($letter['attachment_id']??0),'verification_remarks'=>$remarks]);};
    $returnPost=function(string $who,int $id,array $lines,bool $photo=false,bool $walkin=false)use(&$csrf,$png){$data=['csrf_token'=>$csrf[$who]??'','condition_notes'=>'R10 return','lines'=>$lines];if($photo)$data['proof_photos']=[new CURLFile($png,'image/png','post-use.png')];return qHttp($who,($walkin?'admin/walkin_return.php':'borrower/return_submit.php').'?request_id='.$id,$data);};
    $returnId=fn(int $id)=>(int)qVal('SELECT return_id FROM return_submissions WHERE request_id=?',[$id]);
    $inspect=function(int $id,string $who='admin')use(&$csrf,$returnId){$payload=[];foreach(qRows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId($id)]) as $d)$payload[$d['return_item_id']]=['condition'=>$d['reported_condition'],'remarks'=>'R10 inspection'];return qHttp($who,'admin/review_return.php?request_id='.$id,['csrf_token'=>$csrf[$who]??'','action'=>'approve','inspection'=>$payload]);};
    $eventDayPassed=fn(int $id)=>qExec("UPDATE resource_requests SET date_needed=DATE_SUB(CURDATE(),INTERVAL 1 DAY),due_date=CONCAT(DATE_SUB(CURDATE(),INTERVAL 1 DAY),' 17:00:00') WHERE request_id=?",[$id]);
    $notified=fn(string $who,string $type)=>(int)qVal('SELECT COUNT(*) FROM notifications WHERE user_id=? AND type=?',[$users[$who],$type]);

    // ==== 1. Registered facility request, start to finish ====================
    $noLetter=$submit('student',$hall,['quantity'=>1,'date_needed'=>$day(20),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM']);
    qAssert($noLetter===0,'Facility request accepted without a signed letter');
    qAssert($submit('student',$hall,['quantity'=>1,'date_needed'=>$day(20),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'AM'],true)===0,'AM period accepted an afternoon time');
    $j1=$submit('student',$hall,['quantity'=>1,'date_needed'=>$day(20),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','notes'=>'Org assembly'],true);
    qAssert($j1>0&&qState($j1)==='Pending','Registered facility request was not created');
    $row=qRows('SELECT * FROM resource_requests WHERE request_id=?',[$j1])[0];
    qAssert(!array_key_exists('resource_id',$row)&&!array_key_exists('quantity',$row)&&!array_key_exists('adviser_state',$row)&&!array_key_exists('director_state',$row),'Request header still carries retired columns');
    qAssert($row['requester_type']==='Registered'&&(int)$row['borrower_id']===$users['student']&&$row['start_time']==='09:00:00'&&$row['end_time']==='11:00:00','Request header schedule/owner incorrect');
    $items=qRows('SELECT resource_id,line_kind,facility_slot,requested_quantity FROM request_items WHERE request_id=?',[$j1]);
    qAssert($items==[['resource_id'=>$hall,'line_kind'=>'Facility','facility_slot'=>'AM','requested_quantity'=>1]],'Facility line with AM classification was not stored in request_items');
    qAssert(count(gsoRequestLetters($pdo,$j1))===1&&gsoRequestLetters($pdo,$j1)[0]['verification_status']==='Pending','Signed letter was not stored pending verification');
    qAssert(qMailCount($j1,'Request Received')===1&&$notified('student','request_submitted')===1,'Submission did not notify in-app and by email');
    $process('admin',$j1,'approve');qAssert(qState($j1)!=='Approved','Facility request approved before letter verification');
    foreach(['student','director','mis','guest'] as $who){$verify($j1,$who);qAssert(gsoRequestLetters($pdo,$j1)[0]['verification_status']==='Pending',$who.' verified a request letter');}
    $verify($j1,'staff');qAssert(gsoRequestLetters($pdo,$j1)[0]['verification_status']==='Verified'&&qState($j1)==='Pending','GSO letter verification failed or approved the request');
    qAssert(qMailCount($j1,'Request Letter Verified')===1,'Letter verification email missing');
    foreach(['student','director','mis','guest'] as $who){$process($who,$j1,'approve');qAssert(qState($j1)==='Pending',$who.' approved a request');}
    $process('admin',$j1,'approve',['csrf_token'=>'forged']);qAssert(qState($j1)==='Pending','Approval bypassed CSRF');
    $process('admin',$j1,'approve');qAssert(qState($j1)==='Approved','GSO approval failed');
    $mail=qMails($j1,'Request Approved');qAssert(count($mail)===1&&$notified('student','approved')===1,'Approval did not notify in-app and by email exactly once');
    $body=(string)reset($mail);qAssert(str_contains($body,'<strong>To:</strong> '.$mailTo('student'))&&str_contains($body,rtrim((string)gsoEnv('GSO_APP_URL',''),'/').'/borrower/'),'Approval email did not go to the stored account address with a configured-URL link');
    qAssert(!preg_match('/Adviser|Director|QR code|payment|penalt/i',$body),'Approval email carries obsolete or financial wording');
    $process('admin',$j1,'approve');qAssert(qMailCount($j1,'Request Approved')===1,'Repeated approval resent the email');
    qAssert(!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$hall),1,$day(20),'09:30','10:30',null)['ok']&&checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$hall),1,$day(20),'13:00','15:00',null)['ok'],'Approved AM reservation did not block AM only');
    $director=qHttp('director','director/dashboard.php?facility_calendar_month='.date('Y-m',strtotime($day(20))))['body'];
    qAssert(str_contains($director,qCode($j1))||str_contains($director,'AM | 09:00 AM - 11:00 AM'),'Director schedule omitted the confirmed reservation');
    qAssert(!str_contains($director,$mark.' student')&&!str_contains($director,'Org assembly')&&!str_contains($director,'name="action"'),'Director schedule exposed borrower details or controls');
    $process('staff',$j1,'release');qAssert(qState($j1)==='Released'&&qMailCount($j1,'Request Released')===1,'Release failed or did not email');
    $returnPost('student',$j1,[$line($j1,$hall)=>['condition'=>'Good']],true);qAssert(qState($j1)==='Released','Facility post-use accepted before the schedule ended');
    $eventDayPassed($j1);qHttp('student','borrower/my_borrowed.php');qAssert(qState($j1)==='Released','Elapsed schedule completed the request automatically');
    $returnPost('faculty',$j1,[$line($j1,$hall)=>['condition'=>'Good']],true);qAssert(qState($j1)==='Released','Another borrower submitted the return');
    $returnPost('student',$j1,[$line($j1,$hall)=>['condition'=>'Good']],true);qAssert(qState($j1)==='Return'&&qMailCount($j1,'Return Submission Received')===1,'Return submission did not enter Return');
    foreach(['student','director','mis'] as $who){$inspect($j1,$who);qAssert(qState($j1)==='Return',$who.' inspected a return');}
    $inspect($j1);qAssert(qState($j1)==='Returned'&&qMailCount($j1,'Return Inspection Accepted')===1,'Inspection did not settle the request as Returned');
    $inspect($j1);qAssert(qMailCount($j1,'Return Inspection Accepted')===1,'Repeated inspection resent the email');
    qAssert(str_contains(qHttp('student','borrower/history.php')['body'],qCode($j1))&&!str_contains(qHttp('faculty','borrower/history.php')['body'],qCode($j1)),'Borrower history ownership incorrect');

    // ==== 2. Registered mixed request: facility + equipment + consumable =====
    $j2=$submit('faculty',$gym,['quantity'=>1,'date_needed'=>$day(21),'start_time'=>'13:00','end_time'=>'16:00','facility_slot'=>'PM','extra_lines'=>[['resource_id'=>$chairs,'quantity'=>4],['resource_id'=>$paper,'quantity'=>5]]],true);
    qAssert($j2>0,'Mixed request was not created');
    $kinds=array_column(qRows('SELECT line_kind,requested_quantity FROM request_items WHERE request_id=? ORDER BY FIELD(line_kind,\'Facility\',\'Equipment\',\'Consumable\')',[$j2]),'requested_quantity','line_kind');
    qAssert($kinds==['Facility'=>1,'Equipment'=>4,'Consumable'=>5],'Mixed request lines were not normalized into request_items');
    qAssert((int)qVal('SELECT COUNT(DISTINCT request_id) FROM request_items WHERE request_id=?',[$j2])===1&&(int)qVal('SELECT COUNT(*) FROM request_attachments WHERE request_id=?',[$j2])===1,'Mixed request does not share one reference and one letter');
    qAssert($stock($chairs)['available_stock']==10&&$stock($paper)['available_stock']==50,'Stock changed before release');
    qAssert(!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$chairs),7,$day(21),'13:00','16:00',null)['ok']&&checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$chairs),6,$day(21),'13:00','16:00',null)['ok'],'Shared timing did not reserve the equipment line');
    $verify($j2);$process('admin',$j2,'approve');qAssert(qState($j2)==='Approved'&&$stock($paper)['available_stock']==50,'Approval failed or deducted the consumable early');
    $process('admin',$j2,'release');qAssert(qState($j2)==='Released','Mixed release failed');
    qAssert($stock($chairs)==['total_stock'=>10,'available_stock'=>6,'under_repair_stock'=>0]&&$stock($paper)['available_stock']==45&&$stock($paper)['total_stock']==45,'Release did not issue equipment and consume the consumable exactly once');
    $process('admin',$j2,'release');qAssert($stock($paper)['available_stock']==45&&$stock($chairs)['available_stock']==6,'Repeated release deducted stock twice');
    $eventDayPassed($j2);
    $returnPost('faculty',$j2,[$line($j2,$gym)=>['condition'=>'Good'],$line($j2,$chairs)=>['condition'=>'Good','returned_quantity'=>4,'lost_quantity'=>0]],true);qAssert(qState($j2)==='Return','Mixed return was not accepted for inspection');
    $inspect($j2);qAssert(qState($j2)==='Returned'&&$stock($chairs)==['total_stock'=>10,'available_stock'=>10,'under_repair_stock'=>0]&&$stock($paper)['available_stock']==45,'Mixed return did not restore equipment only');

    // ==== 3. Registered reschedule ==========================================
    $j3=$submit('ustaff',$court,['quantity'=>1,'date_needed'=>$day(25),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM'],true);$verify($j3);$process('admin',$j3,'approve');qAssert(qState($j3)==='Approved','Reschedule fixture approval failed');
    $original=qRows('SELECT date_needed,start_time,end_time FROM resource_requests WHERE request_id=?',[$j3])[0];
    $propose=fn(string $who,int $offset)=>qHttp($who,'borrower/reschedule.php?request_id='.$j3,['csrf_token'=>$csrf[$who]??'','action'=>'create','date_needed'=>$day($offset),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','reason'=>'Speaker moved the date']);
    $attempt=fn(int $id)=>(int)qVal('SELECT COALESCE(MAX(reschedule_id),0) FROM request_reschedule_history WHERE request_id=?',[$id]);
    $propose('student',27);qAssert($attempt($j3)===0,'Another borrower proposed a reschedule');
    $propose('ustaff',27);$proposal=$attempt($j3);qAssert($proposal>0&&qRows('SELECT date_needed,start_time,end_time FROM resource_requests WHERE request_id=?',[$j3])[0]===$original,'Proposal was not recorded or changed the confirmed schedule');
    qAssert(!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$court),1,$day(25),'09:00','11:00',null)['ok']&&checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$court),1,$day(27),'13:00','15:00',null)['ok'],'Pending proposal reserved the new slot or released the original');
    qAssert(qMailCount($j3,'Reschedule Proposal Received')===1,'Proposal email missing');
    $review=fn(string $who,int $id,int $rid,string $action='approve')=>qHttp($who,'admin/reschedules.php?request_id='.$id,['csrf_token'=>$csrf[$who]??'','request_id'=>$id,'reschedule_id'=>$rid,'action'=>$action]);
    foreach(['ustaff','director','mis'] as $who){$review($who,$j3,$proposal);qAssert(qVal('SELECT status FROM request_reschedule_history WHERE reschedule_id=?',[$proposal])==='Pending',$who.' reviewed a reschedule');}
    $review('staff',$j3,$proposal);$now=qRows('SELECT date_needed,start_time,end_time FROM resource_requests WHERE request_id=?',[$j3])[0];
    qAssert($now==['date_needed'=>$day(27),'start_time'=>'13:00:00','end_time'=>'15:00:00']&&qVal('SELECT facility_slot FROM request_items WHERE request_id=?',[$j3])==='PM'&&qState($j3)==='Approved','Approved reschedule did not become the new schedule');
    qAssert(qMailCount($j3,'Facility Reschedule Approved')===1&&$notified('ustaff','reschedule_approved')===1,'Reschedule approval did not notify in-app and by email');
    qAssert(checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$court),1,$day(25),'09:00','11:00',null)['ok'],'Old slot stayed blocked after the reschedule');

    // ==== 4. Walk-in facility, start to finish ===============================
    $client=['walkin_full_name'=>$mark.' External Client','walkin_organization'=>'Community Group','walkin_email'=>strtolower($mark).'.client@outside.invalid','walkin_contact_number'=>'09123456789'];
    $walk=function(string $who,array $lines,int $offset,array $extra=[],bool $letter=true)use(&$csrf,$client,$png,$day,$newestWalkin,&$requests):int{$before=$newestWalkin();$data=['csrf_token'=>$csrf[$who]??'','date_needed'=>$day($offset),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','lines'=>$lines]+$extra+$client;if($letter)$data['request_letter']=new CURLFile($png,'image/png','signed-external.png');else unset($data['facility_slot']);qHttp($who,'admin/create_walkin.php',$data);$id=$newestWalkin();if($id>$before){$requests[]=$id;return $id;}return 0;};
    foreach(['student','director','mis','guest'] as $who)qAssert($walk($who,[['resource_id'=>$lab,'quantity'=>1]],30)===0,$who.' created a walk-in request');
    $userCount=(int)qVal('SELECT COUNT(*) FROM users');
    $j4=$walk('staff',[['resource_id'=>$lab,'quantity'=>1]],30);qAssert($j4>0&&qState($j4)==='Pending','GSO could not encode a walk-in facility request');
    $row=qRows('SELECT * FROM resource_requests WHERE request_id=?',[$j4])[0];
    qAssert($row['requester_type']==='Walk-in'&&$row['borrower_id']===null&&(int)$row['encoded_by']===$users['staff']&&$row['walkin_email']===$client['walkin_email'],'Walk-in request-level client snapshot incorrect');
    qAssert((int)qVal('SELECT COUNT(*) FROM users')===$userCount&&(int)qVal('SELECT COUNT(*) FROM users WHERE email=?',[$client['walkin_email']])===0,'Walk-in created a user account');
    qAssert(count(qMails($j4,'Request Received'))===1&&(int)qVal('SELECT COUNT(*) FROM notifications WHERE message LIKE ? AND user_id NOT IN(SELECT user_id FROM users WHERE role IN(\'Admin\',\'GSO Staff\'))',['%'.qCode($j4).'%'])===0,'Walk-in was not email-only');
    $verify($j4);$process('admin',$j4,'approve');qAssert(qState($j4)==='Approved','Walk-in approval failed');
    $walkMails=qMails($j4,'Request Approved');$mail=(string)reset($walkMails);qAssert($mail!==''&&str_contains($mail,'<strong>To:</strong> '.$client['walkin_email'])&&!str_contains($mail,'borrower/')&&!str_contains($mail,'my_requests.php'),'Walk-in approval email missing, misaddressed, or links to a borrower page');
    foreach(['student','faculty'] as $who){qAssert(!str_contains(qHttp($who,'borrower/my_requests.php')['body'],$client['walkin_full_name']),'Borrower portal exposed a walk-in');qAssert(in_array(qHttp($who,'borrower/return_submit.php?request_id='.$j4)['status'],[302,404],true),'Borrower reached a walk-in return');}
    $process('staff',$j4,'release');qAssert(qState($j4)==='Released','Walk-in release failed');$eventDayPassed($j4);
    $returnPost('student',$j4,[$line($j4,$lab)=>['condition'=>'Good']],true,true);qAssert(qState($j4)==='Released','Borrower encoded a walk-in return');
    $returnPost('staff',$j4,[$line($j4,$lab)=>['condition'=>'Good']],true,true);qAssert(qState($j4)==='Return','GSO-encoded walk-in return failed');
    qAssert(qRows('SELECT borrower_id,submitted_by FROM return_submissions WHERE request_id=?',[$j4])==[['borrower_id'=>null,'submitted_by'=>$users['staff']]],'Walk-in return actor is not the encoding GSO user');
    $inspect($j4);qAssert(qState($j4)==='Returned'&&qMailCount($j4,'Return Inspection Accepted')===1,'Walk-in inspection failed');
    $noEmail=$walk('staff',[['resource_id'=>$tables,'quantity'=>1]],31,['walkin_email'=>''],false);qAssert($noEmail>0&&count(qMails($noEmail))===0&&(int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Client Email Skipped' AND details LIKE ?",[qCode($noEmail).' %'])===1,'Walk-in without email was not recorded as skipped');

    // ==== 5. Walk-in reschedule =============================================
    $j5=$walk('staff',[['resource_id'=>$lab,'quantity'=>1]],40);$verify($j5);$process('admin',$j5,'approve');qAssert(qState($j5)==='Approved','Walk-in reschedule fixture failed');
    qHttp('student','admin/walkin_actions.php?request_id='.$j5,['csrf_token'=>$csrf['student'],'action'=>'reschedule','date_needed'=>$day(42),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','reason'=>'Tampered']);qAssert($attempt($j5)===0,'Borrower encoded a walk-in reschedule');
    qHttp('staff','admin/walkin_actions.php?request_id='.$j5,['csrf_token'=>$csrf['staff'],'action'=>'reschedule','date_needed'=>$day(42),'start_time'=>'13:00','end_time'=>'15:00','facility_slot'=>'PM','reason'=>'Client asked to move the event']);$walkProposal=$attempt($j5);
    qAssert($walkProposal>0&&qRows('SELECT requested_by,encoded_by FROM request_reschedule_history WHERE reschedule_id=?',[$walkProposal])==[['requested_by'=>null,'encoded_by'=>$users['staff']]],'Walk-in proposal invented a borrower requester');
    $review('admin',$j5,$walkProposal);qAssert(qVal('SELECT date_needed FROM resource_requests WHERE request_id=?',[$j5])===$day(42)&&qMailCount($j5,'Facility Reschedule Approved')===1,'Walk-in reschedule approval failed or did not email');
    qAssert((int)qVal('SELECT COUNT(*) FROM (SELECT request_id FROM request_reschedule_history WHERE status=\'Pending\' GROUP BY request_id HAVING COUNT(*)>1) d')===0,'Duplicate pending reschedules exist');

    // ==== 6. Serialized mixed return: Good / Damaged / Lost ==================
    $j6=$submit('student',$projector,['quantity'=>3,'date_needed'=>$day(2),'start_time'=>'09:00','end_time'=>'11:00']);$process('admin',$j6,'approve');qAssert($j6>0&&qState($j6)==='Approved','Serialized request fixture failed');
    $pl=$line($j6,$projector);$process('admin',$j6,'release',['unit_ids'=>[$pl=>[$units[0],$units[1]]]]);qAssert(qState($j6)==='Approved','Release accepted the wrong number of units');
    $process('admin',$j6,'release',['unit_ids'=>[$pl=>$units]]);qAssert(qState($j6)==='Released'&&(int)qVal('SELECT COUNT(*) FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL',[$pl])===3,'Serialized release did not allocate exact units');
    $returnPost('student',$j6,[$pl=>['units'=>[$units[0]=>['condition'=>'Good'],$units[1]=>['condition'=>'Damaged'],$units[2]=>['condition'=>'Lost']]]]);qAssert(qState($j6)==='Return','Serialized return was not submitted');
    $inspect($j6);qAssert(qState($j6)==='Returned','Serialized inspection failed');
    qAssert($unit($units[0])==['status'=>'Available','condition_status'=>'Good']&&$unit($units[1])==['status'=>'Under Repair','condition_status'=>'Damaged']&&$unit($units[2])==['status'=>'Lost','condition_status'=>'Lost'],'Exact per-unit outcomes were not recorded');
    qAssert($stock($projector)==['total_stock'=>2,'available_stock'=>1,'under_repair_stock'=>1],'Serialized counters incorrect after mixed return');
    qAssert(checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$projector),1,$day(5),'09:00','11:00',null)['ok']&&!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$projector),2,$day(5),'09:00','11:00',null)['ok'],'Unaffected unit unavailable or damaged/lost unit counted');
    $detail=[];foreach(qRows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId($j6)]) as $d)$detail[$d['inspection_condition']]=(int)$d['return_item_id'];
    qAssert(!gsoCreateReturnRepairWorkOrder($pdo,$detail['Lost'],$users['admin'],$users['staff'],1)['ok']&&!gsoCreateReturnRepairWorkOrder($pdo,$detail['Good'],$users['admin'],$users['staff'],1)['ok'],'Lost or Good unit entered repair');
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'create_return_work_order','return_item_id'=>$detail['Lost'],'assigned_to'=>$users['staff'],'duration_days'=>1]);qAssert((int)qVal('SELECT COUNT(*) FROM maintenance_schedules WHERE source_return_item_id=?',[$detail['Lost']])===0,'Lost unit entered repair by direct POST');
    qAssert(!gsoUpdateEquipmentUnitStatus($pdo,$units[2],'Available','Good','Restore lost unit',$users['admin'])['ok'],'Lost unit restored through a status edit');
    qAssert(qDenied(fn()=>gsoSavePreventivePlan($pdo,$users['admin'],['resource_id'=>$projector,'equipment_unit_id'=>$units[2],'quantity'=>1,'title'=>'Bypass','recurrence'=>'Once','next_due_date'=>$day(3),'duration_days'=>1,'assigned_to'=>0])),'Lost unit accepted a preventive plan');

    // ==== 8a. Corrective maintenance on the damaged unit =====================
    qAssert(!gsoCreateReturnRepairWorkOrder($pdo,$detail['Damaged'],$users['staff'],$users['staff'],1)['ok'],'Staff confirmed a repair work order');
    $work=gsoCreateReturnRepairWorkOrder($pdo,$detail['Damaged'],$users['admin'],$users['staff'],2);qAssert($work['ok'],'Damaged unit could not enter repair: '.$work['message']);$repair=(int)$work['maintenance_id'];
    $job=fn(int $id)=>qRows('SELECT * FROM maintenance_schedules WHERE maintenance_id=?',[$id])[0];
    qAssert($job($repair)['maintenance_type']==='Corrective'&&$job($repair)['status']==='In Progress'&&(int)$job($repair)['equipment_unit_id']===$units[1]&&(int)$job($repair)['source_return_item_id']===$detail['Damaged'],'Corrective work order is not linked to the exact unit and return detail');
    qExec('UPDATE maintenance_schedules SET start_date=?,end_date=? WHERE maintenance_id=?',[$day(-5),$day(-4),$repair]);qHttp('admin','admin/maintenance.php');
    qAssert($job($repair)['status']==='In Progress'&&$unit($units[1])['status']==='Under Repair','Elapsed end date completed the work order automatically');
    foreach(['student','director','mis'] as $who){qHttp($who,'admin/maintenance.php',['csrf_token'=>$csrf[$who],'action'=>'complete_schedule','maintenance_id'=>$repair]);qAssert($job($repair)['status']==='In Progress',$who.' completed maintenance');}
    qHttp('staff','admin/maintenance.php',['csrf_token'=>$csrf['staff'],'action'=>'complete_schedule','maintenance_id'=>$repair,'completion_notes'=>'Lens replaced']);
    qAssert($job($repair)['status']==='Completed'&&$job($repair)['completed_at']!==null&&$unit($units[1])==['status'=>'Available','condition_status'=>'Good']&&$unit($units[2])['status']==='Lost','Explicit completion did not restore exactly the repaired unit');
    qAssert($stock($projector)==['total_stock'=>2,'available_stock'=>2,'under_repair_stock'=>0],'Repair completion counters incorrect');
    qHttp('staff','admin/maintenance.php',['csrf_token'=>$csrf['staff'],'action'=>'complete_schedule','maintenance_id'=>$repair]);qAssert($stock($projector)['available_stock']==2,'Repeated completion restored twice');
    $code=fn(int $id)=>'MTN-'.str_pad((string)$id,3,'0',STR_PAD_LEFT);
    $history=qHttp('admin','admin/maintenance_history.php?unit_id='.$units[1])['body'];qAssert(str_contains($history,$code($repair))&&str_contains($history,'Corrective')&&str_contains($history,'Return Detail #'.$detail['Damaged'])&&str_contains($history,'Lens replaced'),'Corrective work is missing from maintenance history');

    // ==== 7. Aggregate mixed return =========================================
    $j7=$submit('faculty',$tables,['quantity'=>6,'date_needed'=>$day(3),'start_time'=>'09:00','end_time'=>'11:00']);
    $process('admin',$j7,'approve');$process('admin',$j7,'release');qAssert($j7>0&&qState($j7)==='Released'&&$stock($tables)['available_stock']==4,'Aggregate release fixture failed');
    $tl=$line($j7,$tables);
    foreach([['Good'=>3,'Damaged'=>2,'Lost'=>2],['Good'=>-1,'Damaged'=>5,'Lost'=>2],['Good'=>'2.5','Damaged'=>2,'Lost'=>1]] as $bad){$returnPost('faculty',$j7,[$tl=>['outcomes'=>$bad]]);qAssert(qState($j7)==='Released','Invalid aggregate reconciliation accepted');}
    $returnPost('faculty',$j7,[$tl=>['outcomes'=>['Good'=>3,'Damaged'=>2,'Lost'=>1]]]);qAssert(qState($j7)==='Return','Aggregate mixed return was not submitted');
    $inspect($j7);qAssert(qState($j7)==='Returned'&&$stock($tables)==['total_stock'=>9,'available_stock'=>7,'under_repair_stock'=>2],'Aggregate Good/Damaged/Lost arithmetic incorrect');
    $inspect($j7);qAssert($stock($tables)==['total_stock'=>9,'available_stock'=>7,'under_repair_stock'=>2],'Repeated inspection re-applied stock');
    $agg=[];foreach(qRows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId($j7)]) as $d)$agg[$d['inspection_condition']]=$d;
    qAssert((int)$agg['Good']['returned_quantity']===3&&(int)$agg['Damaged']['returned_quantity']===2&&(int)$agg['Lost']['lost_quantity']===1,'Aggregate outcome rows do not reconcile to the released quantity');
    qAssert(!gsoCreateReturnRepairWorkOrder($pdo,(int)$agg['Lost']['return_item_id'],$users['admin'],$users['staff'],1)['ok'],'Lost aggregate quantity entered repair');
    $work=gsoCreateReturnRepairWorkOrder($pdo,(int)$agg['Damaged']['return_item_id'],$users['admin'],$users['staff'],1);qAssert($work['ok']&&(int)$job((int)$work['maintenance_id'])['quantity']===2,'Aggregate repair order does not hold the damaged quantity');
    qAssert(gsoCompleteRepairWorkOrder($pdo,(int)$work['maintenance_id'],$users['staff'],'Tables repaired')['ok']&&$stock($tables)==['total_stock'=>9,'available_stock'=>9,'under_repair_stock'=>0],'Aggregate repair did not restore exactly the damaged quantity');
    $accountability=gsoRecordBorrowerAccountability($pdo,(int)$agg['Lost']['return_item_id'],$users['admin'],'Confirmed','Reported lost by the borrower');
    qAssert($accountability['ok']&&(int)qVal('SELECT COUNT(*) FROM borrower_liabilities WHERE return_item_id=?',[(int)$agg['Lost']['return_item_id']])===1,'Non-financial accountability was not recorded');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name REGEXP 'cost|amount|payment|penalty|price|(^|_)fee'")===0,'A financial column exists');

    // ==== 9. Preventive maintenance =========================================
    $planData=['resource_id'=>$chairs,'equipment_unit_id'=>0,'quantity'=>3,'title'=>$mark.' chair inspection','recurrence'=>'Quarterly','next_due_date'=>$today,'duration_days'=>1,'assigned_to'=>$users['staff']];
    foreach(['student','director','mis','staff'] as $who)qAssert(qDenied(fn()=>gsoSavePreventivePlan($pdo,$users[$who],$planData)),$who.' created a preventive plan');
    qAssert(qDenied(fn()=>gsoSavePreventivePlan($pdo,$users['admin'],['resource_id'=>$paper,'quantity'=>5]+$planData)),'Consumable accepted a preventive plan');
    $futurePlan=gsoSavePreventivePlan($pdo,$users['admin'],['next_due_date'=>$day(60),'title'=>$mark.' future chair check']+$planData);$futureJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$futurePlan);
    qAssert(gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$futurePlan)===$futureJob&&(int)qVal('SELECT COUNT(*) FROM maintenance_schedules WHERE preventive_plan_id=?',[$futurePlan])===1,'Occurrence generation is not idempotent');
    qAssert($stock($chairs)['available_stock']==10&&checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$chairs),7,$day(60),'09:00','11:00',null)['ok']&&!checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$chairs),8,$day(60),'09:00','11:00',null)['ok']&&checkResourceAvailability($pdo,gsoFetchRequestableResource($pdo,$chairs),10,$day(62),'09:00','11:00',null)['ok'],'Future preventive hold did not reduce only the overlapping capacity');
    $plan=gsoSavePreventivePlan($pdo,$users['admin'],$planData);$pmJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$plan);
    qHttp('admin','admin/maintenance.php');qAssert($job($pmJob)['status']==='Scheduled'&&$job($pmJob)['maintenance_type']==='Preventive','Preventive occurrence started by date');
    qAssert(!gsoPreventiveJobAction($pdo,$pmJob,$users['student'],'start')['ok']&&!gsoPreventiveJobAction($pdo,$pmJob,$users['admin'],'complete','x')['ok'],'Unauthorized start or completion without start accepted');
    qAssert(gsoPreventiveJobAction($pdo,$pmJob,$users['staff'],'start')['ok']&&$stock($chairs)==['total_stock'=>10,'available_stock'=>7,'under_repair_stock'=>3],'Preventive start did not hold exactly the planned quantity');
    qExec('UPDATE maintenance_schedules SET start_date=?,end_date=? WHERE maintenance_id=?',[$day(-3),$day(-2),$pmJob]);qHttp('admin','admin/maintenance.php');
    qAssert($job($pmJob)['status']==='In Progress'&&qVal('SELECT next_due_date FROM preventive_maintenance_plans WHERE plan_id=?',[$plan])===$today,'Elapsed date completed preventive maintenance or advanced the plan');
    qAssert(gsoPreventiveJobAction($pdo,$pmJob,$users['staff'],'complete','Chairs inspected')['ok']&&$stock($chairs)==['total_stock'=>10,'available_stock'=>10,'under_repair_stock'=>0],'Explicit preventive completion did not restore the held quantity');
    qAssert(qVal('SELECT next_due_date FROM preventive_maintenance_plans WHERE plan_id=?',[$plan])===gsoPreventiveNextDate($today,'Quarterly'),'Plan did not advance by three calendar months');
    $history=qHttp('admin','admin/maintenance_history.php?type=Preventive')['body'];qAssert(str_contains($history,$code($pmJob))&&str_contains($history,'Preventive Plan #'.$plan)&&!str_contains($history,$code($futureJob)),'Preventive history incorrect');
    qAssert(str_contains(qHttp('admin','admin/maintenance.php')['body'],$code($futureJob))&&str_contains(qHttp('admin','admin/preventive_plans.php')['body'],$mark.' future chair check'),'Active occurrence or plan not listed');

    // ==== 10. Chatbot: live availability and owned status ====================
    $auth=fn(string $who)=>['user_id'=>$users[$who],'role'=>$roles[$who][0]];
    $facility=fn(int $id,string $date,string $start,string $end)=>gsoChatCheckFacilityAvailability($pdo,$auth('student'),'',['facility_name'=>(string)qVal('SELECT resource_name FROM resources WHERE resource_id=?',[$id]),'date'=>$date,'start_time'=>$start,'end_time'=>$end]);
    qAssert(($facility($court,$day(27),'13:00','15:00')['facts']['available']??null)===false&&($facility($court,$day(27),'09:00','11:00')['facts']['available']??null)===true,'Chatbot availability ignores the confirmed reservation period');
    $gymPlan=gsoSavePreventivePlan($pdo,$users['admin'],['resource_id'=>$gym,'equipment_unit_id'=>0,'quantity'=>0,'title'=>$mark.' gym floor care','recurrence'=>'Annual','next_due_date'=>$day(70),'duration_days'=>1,'assigned_to'=>0]);gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$gymPlan);
    qAssert(($facility($gym,$day(70),'09:00','11:00')['facts']['available']??null)===false&&($facility($gym,$day(71),'09:00','11:00')['facts']['available']??null)===true,'Chatbot availability ignores preventive maintenance');
    $partial=gsoChatCheckResourceAvailability($pdo,$auth('student'),'',['resource_name'=>(string)qVal('SELECT resource_name FROM resources WHERE resource_id=?',[$projector])]);
    qAssert(($partial['facts']['borrowable_today']??false)===true,'Chatbot blocks a resource that still has usable units');
    $own=gsoChatGetRequestStatus($pdo,$auth('student'),qCode($j1));qAssert($own['success']&&str_contains(json_encode($own['facts']),'Returned'),'Owner could not read own request status');
    $foreign=gsoChatGetRequestStatus($pdo,$auth('faculty'),qCode($j1));qAssert(!str_contains(json_encode($foreign),'Returned')&&!str_contains(json_encode($foreign),$mark.' Hall'),'Chatbot leaked another borrower request');
    $walkinStatus=gsoChatGetRequestStatus($pdo,$auth('student'),qCode($j4));qAssert(!str_contains(json_encode($walkinStatus),$client['walkin_full_name'])&&!str_contains(json_encode($walkinStatus),'Returned'),'Chatbot leaked a walk-in request');
    foreach(['director','mis','guest'] as $who)qAssert(in_array(qHttp($who,'chatbot/send.php',['csrf_token'=>$csrf[$who]??'','message'=>'status of '.qCode($j1)])['status'],[302,401,403],true),$who.' used the chatbot');
    $reply=qHttp('student','chatbot/send.php',['csrf_token'=>$csrf['student'],'message'=>'What is the status of '.qCode($j1).'?']);qAssert($reply['status']===200&&str_contains($reply['body'],'Returned'),'Chatbot endpoint did not answer the owner');

    // ==== 11. Email failure never rolls back business state ==================
    $j11=$submit('ustaff',$tables,['quantity'=>1,'date_needed'=>$day(8),'start_time'=>'09:00','end_time'=>'11:00']);qAssert($j11>0,'Email-failure fixture was not created');
    file_put_contents($failureFile,'1');
    $response=$process('admin',$j11,'approve');@unlink($failureFile);
    qAssert(qState($j11)==='Approved'&&qMailCount($j11,'Request Approved')===0,'Mail failure rolled back approval or a mail was captured');
    qAssert($notified('ustaff','approved')>=1&&(int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Client Email Failed' AND details LIKE ?",[qCode($j11).' approved %'])===1,'Failed delivery was not recorded or the in-app notice was lost');
    $page=qHttp('admin','admin/requests.php')['body'];qAssert(!preg_match('/SMTP|PHPMailer|smtp\.gmail/i',$page),'Mail failure details leaked to the page');
    $process('admin',$j11,'approve');qAssert(qMailCount($j11,'Request Approved')===0,'Settled approval retried the email on a repeated POST');
    $process('admin',$j11,'release');qAssert(qState($j11)==='Released'&&qMailCount($j11,'Request Released')===1,'Mail delivery did not resume after the failure');

    // ==== 12. Final role matrix =============================================
    $matrix=[
        'admin/dashboard.php'=>['admin'],'admin/reports.php'=>['admin'],'admin/activity_logs.php'=>['admin'],'admin/ai_knowledge.php'=>['admin'],'admin/incidents.php'=>['admin'],
        'admin/requests.php'=>['admin','staff'],'admin/create_walkin.php'=>['admin','staff'],'admin/reschedules.php'=>['admin','staff'],'admin/on_loan.php'=>['admin','staff'],'admin/inventory.php'=>['admin','staff'],
        'admin/equipment_units.php'=>['admin','staff'],'admin/maintenance.php'=>['admin','staff'],'admin/preventive_plans.php'=>['admin','staff'],'admin/maintenance_history.php'=>['admin','staff'],
        'admin/accounts_dashboard.php'=>['mis'],'admin/borrowers_accounts.php'=>['mis'],'admin/admin_accounts.php'=>['mis'],
        'gso_staff/dashboard.php'=>['staff'],'director/dashboard.php'=>['director'],
        'borrower/browse.php'=>['student','faculty','ustaff'],'borrower/my_requests.php'=>['student','faculty','ustaff'],'borrower/my_borrowed.php'=>['student','faculty','ustaff'],'borrower/history.php'=>['student','faculty','ustaff'],
        'borrower/report_issue.php'=>['student','faculty','ustaff'],'borrower/my_incidents.php'=>['student','faculty','ustaff'],'borrower/notifications.php'=>['student','faculty','ustaff'],'borrower/profile.php'=>['student','faculty','ustaff'],
    ];
    foreach($matrix as $path=>$allowed)foreach(array_keys($roles)+[99=>'guest'] as $who){$status=qHttp($who,$path)['status'];if(in_array($who,$allowed,true))qAssert($status===200,$who.' cannot open '.$path.' ('.$status.')');else qAssert($status===302,$who.' reached '.$path.' ('.$status.')');}
    qHttp('mis','admin/requests.php',['csrf_token'=>$csrf['mis'],'request_id'=>$j5,'action'=>'reject']);qHttp('director','admin/requests.php',['csrf_token'=>$csrf['director'],'request_id'=>$j5,'action'=>'reject']);qAssert(qState($j5)==='Approved','MIS or Director changed a request');
    qAssert(qHttp('director','director/dashboard.php',['csrf_token'=>$csrf['director'],'action'=>'approve','request_id'=>$j5])['status']===405,'Director schedule accepted a mutation');
    qAssert(qHttp('student','borrower/request_document.php?attachment_id='.(int)gsoRequestLetters($pdo,$j1)[0]['attachment_id'])['status']===200&&in_array(qHttp('faculty','borrower/request_document.php?attachment_id='.(int)gsoRequestLetters($pdo,$j1)[0]['attachment_id'])['status'],[302,403,404],true)&&in_array(qHttp('guest','borrower/request_document.php?attachment_id='.(int)gsoRequestLetters($pdo,$j1)[0]['attachment_id'])['status'],[302,403,404],true),'Private request letter access is not limited to its owner');
    // Navigation: every link a role is shown resolves to a page that role can open.
    $homes=['admin'=>'admin/dashboard.php','staff'=>'gso_staff/dashboard.php','mis'=>'admin/accounts_dashboard.php','director'=>'director/dashboard.php','student'=>'borrower/browse.php'];
    foreach($homes as $who=>$home){$html=qHttp($who,$home)['body'];$dir=dirname($home);preg_match_all('/<aside class="sidebar".*?<\/aside>/s',$html,$asides);preg_match_all('/href="([^"#?]+\.php)(?:\?[^"]*)?"/',implode('',$asides[0]?:[$html]),$links);
        $targets=array_unique($links[1]);qAssert(count($targets)>0,'No navigation found for '.$who);
        foreach($targets as $href){$parts=[];foreach(explode('/',$dir.'/'.$href) as $part){if($part==='..')array_pop($parts);elseif($part!==''&&$part!=='.')$parts[]=$part;}$target=implode('/',$parts);qAssert(!preg_match('/endorse|adviser|faculty_search|director\/request/i',$target),'Navigation for '.$who.' links to a retired feature: '.$target);qAssert(is_file($root.'/'.$target),'Dead navigation link for '.$who.': '.$target);if(str_contains($target,'logout'))continue;$status=qHttp($who,$target)['status'];qAssert($status===200,'Navigation for '.$who.' shows a page that role cannot open: '.$target.' ('.$status.')');}
    }
    foreach(['director','mis'] as $who)qAssert(!str_contains(qHttp($who,$homes[$who])['body'],'chatbot/send.php')&&!str_contains(qHttp($who,$homes[$who])['body'],'Ask GSO'),'Chatbot button shown to a role that cannot use it: '.$who);
    qAssert(str_contains(qHttp('student','borrower/browse.php')['body'],'Ask GSO')&&str_contains(qHttp('admin','admin/dashboard.php')['body'],'Ask GSO'),'Chatbot button missing for a permitted role');
    $directorHome=qHttp('director','director/dashboard.php')['body'];qAssert(str_contains($directorHome,'<aside class="sidebar"')&&str_contains($directorHome,'auth/logout.php')&&!preg_match('/href="[^"]*(?:requests|inventory|maintenance|accounts)[a-z_]*\.php"/',$directorHome),'Director navigation is missing or links to operational pages');
    $misNav=qHttp('mis','admin/accounts_dashboard.php')['body'];foreach(['requests.php','inventory.php','maintenance.php','on_loan.php','reports.php'] as $operational)qAssert(!preg_match('/href="'.preg_quote($operational,'/').'"/',$misNav),'MIS navigation shows an operational page: '.$operational);

    // ==== 13. Retired endpoints cannot mutate ================================
    $snapshot=qRows('SELECT * FROM resource_requests WHERE request_id IN(?,?,?) ORDER BY request_id',[$j3,$j5,$j11]);$historyRows=(int)qVal('SELECT COUNT(*) FROM activity_logs');
    foreach(['borrower/adviser_request.php','borrower/faculty_search.php','borrower/endorsements.php','borrower/endorsement_detail.php','director/request.php'] as $path)foreach(['faculty','director','admin','guest'] as $who){
        qAssert(qHttp($who,$path.'?request_id='.$j3)['status']===404&&qHttp($who,$path.'?request_id='.$j3,['csrf_token'=>$csrf[$who]??'','request_id'=>$j3,'action'=>'approve','winner_request_id'=>$j3])['status']===404,'Retired endpoint still responds: '.$path);
    }
    foreach(['require_adviser','require_director','endorse','prioritize','select_winner','director_approve'] as $action)$process('admin',$j3,$action);
    qAssert(qRows('SELECT * FROM resource_requests WHERE request_id IN(?,?,?) ORDER BY request_id',[$j3,$j5,$j11])===$snapshot&&(int)qVal('SELECT COUNT(*) FROM activity_logs')===$historyRows,'A retired action changed a request or wrote an audit event');
    foreach(['config/db.php','scripts/migrate.php','database/schema.sql','database/migrations/20261010_007_final_cleanup.sql','app/models/schema_migrator.php','app/models/legacy_schema_upgrader.php','.env'] as $path)qAssert(in_array(qHttp('guest',$path)['status'],[403,404],true),'Deployment file is web-accessible: '.$path);

    // ==== 14. Normalized request storage and integrity =======================
    qAssert((int)qVal('SELECT COUNT(*) FROM resource_requests rr WHERE NOT EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=rr.request_id)')===0,'A request exists without request_items lines');
    qAssert((int)qVal('SELECT COUNT(*) FROM request_items i LEFT JOIN resource_requests r ON r.request_id=i.request_id LEFT JOIN resources x ON x.resource_id=i.resource_id WHERE r.request_id IS NULL OR x.resource_id IS NULL')===0,'Orphan request line exists');
    qAssert((int)qVal("SELECT COUNT(*) FROM resource_requests WHERE NOT((requester_type='Registered' AND borrower_id IS NOT NULL AND encoded_by IS NULL AND walkin_full_name IS NULL) OR (requester_type='Walk-in' AND borrower_id IS NULL AND encoded_by IS NOT NULL AND walkin_full_name IS NOT NULL))")===0,'Invalid requester combination exists');
    qAssert((int)qVal('SELECT COUNT(*) FROM resources WHERE available_stock<0 OR under_repair_stock<0 OR available_stock+under_repair_stock>total_stock')===0,'Invalid stock exists');
    qAssert((int)qVal('SELECT COUNT(*) FROM (SELECT unit_id FROM request_item_units WHERE returned_at IS NULL GROUP BY unit_id HAVING COUNT(*)>1) d')===0,'Duplicate open unit allocation exists');
    qAssert((int)qVal("SELECT COUNT(*) FROM maintenance_schedules m JOIN equipment_units u ON u.unit_id=m.equipment_unit_id WHERE m.status IN('Scheduled','In Progress') AND (u.status='Lost' OR u.condition_status='Lost')")===0,'Active maintenance targets a Lost unit');
    qAssert((int)qVal('SELECT COUNT(*) FROM request_attachments a LEFT JOIN resource_requests r ON r.request_id=a.request_id WHERE r.request_id IS NULL')===0,'Orphan attachment exists');
    qAssert(qRows('SELECT * FROM resource_requests WHERE request_id IN('.implode(',',array_map('intval',array_column($legacyRequests,'request_id'))?:[0]).') ORDER BY request_id')===$legacyRequests,'Pre-existing requests changed');
    qAssert((int)qVal('SELECT COUNT(*) FROM users')===$legacyUsers+count($users),'An unexpected user account was created');
    echo "PASS: R10 final integration E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally{
    @unlink($failureFile);
    if($pdo->inTransaction())$pdo->rollBack();
    if($users){
        $ids=array_values($users);$marks=implode(',',array_fill(0,count($ids),'?'));
        foreach(qRows('SELECT request_id FROM resource_requests WHERE borrower_id IN('.$marks.') OR encoded_by IN('.$marks.')',[...$ids,...$ids]) as $r)$requests[]=(int)$r['request_id'];
        qExec('DELETE FROM borrower_liabilities WHERE reviewed_by IN('.$marks.')',$ids);
        qExec('DELETE FROM maintenance_schedules WHERE created_by IN('.$marks.')',$ids);
        qExec('DELETE FROM preventive_maintenance_plans WHERE created_by IN('.$marks.')',$ids);
        foreach(array_unique($requests) as $id){foreach(qRows('SELECT p.filename FROM return_submission_photos p JOIN return_submissions r ON r.return_id=p.return_id WHERE r.request_id=?',[$id]) as $photo)deleteReturnPhotoFile($photo['filename']);qExec('DELETE FROM request_reschedule_history WHERE request_id=?',[$id]);gsoTestDeleteLetters($pdo,$id);qExec('DELETE FROM return_submissions WHERE request_id=?',[$id]);qExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
        foreach($ids as $uid){qExec('DELETE FROM ai_conversations WHERE user_id=?',[$uid]);qExec('DELETE FROM notifications WHERE user_id=?',[$uid]);qExec('DELETE FROM activity_logs WHERE user_id=?',[$uid]);}
        foreach($resources as $id){qExec('DELETE FROM equipment_units WHERE resource_id=?',[$id]);qExec('DELETE FROM resources WHERE resource_id=?',[$id]);}
        foreach($ids as $uid)qExec('DELETE FROM users WHERE user_id=?',[$uid]);
    }
    foreach($cookies as $file)@unlink($file);
    if(!$failed&&qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id')!==$legacyInventory){$failed=new RuntimeException('cleanup');fwrite(STDERR,"FAIL: Fixture cleanup left inventory changed.\n");}
}
exit($failed?1:0);
