<?php
declare(strict_types=1);
/**
 * R7 preventive maintenance and maintenance history E2E.
 * Run only with GSO_DB_NAME=gso_r7_test_* and GSO_R7_BASE_URL pointing at a
 * loopback server bound to that same isolated database.
 */
require_once dirname(__DIR__).'/config/bootstrap.php';
if(!preg_match('/^gso_r[78]_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME','')))throw new RuntimeException('R7 requires an isolated gso_r7_test_* database.');
$base=(string)gsoEnv('GSO_R7_BASE_URL','http://127.0.0.1:8098/GSO_WebSystem');if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback only.');
require_once dirname(__DIR__).'/config/db.php';
require_once dirname(__DIR__).'/includes/request_helper.php';
require_once dirname(__DIR__).'/includes/request_line_helper.php';
require_once dirname(__DIR__).'/includes/reschedule_helper.php';
require_once dirname(__DIR__).'/includes/return_upload_helper.php';
require_once __DIR__.'/request_letter_test_helper.php';
require_once dirname(__DIR__).'/includes/walkin_helper.php';
require_once dirname(__DIR__).'/includes/work_order_helper.php';
require_once dirname(__DIR__).'/includes/preventive_maintenance_helper.php';
require_once dirname(__DIR__).'/includes/ai_live_data_helper.php';

$walkins=[];$checks=0;$users=[];$cookies=[];$csrf=[];$resources=[];$failed=null;
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

$mark='R7-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$legacyRequests=qRows('SELECT * FROM resource_requests ORDER BY request_id');
$legacyMaintenance=qRows('SELECT * FROM maintenance_schedules ORDER BY maintenance_id');
$legacyResources=qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id');
$legacyUnits=(int)qVal('SELECT COUNT(*) FROM equipment_units');
try{
    // ---- Fixtures ---------------------------------------------------------
    foreach(['a'=>'Borrower','admin'=>'Admin','staff'=>'GSO Staff','staff2'=>'GSO Staff','mis'=>'Super Admin','director'=>'Director'] as $key=>$role){
        $username=strtolower($mark.'_'.$key);
        qExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified)VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$role==='Borrower'?'University Staff':null]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r7_cookie_');
        $page=qHttp($key,'auth/login.php');preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m);$csrf[$key]=$m[1]??'';
        qAssert(qHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed for '.$key);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r7_guest_');
    $resource=function(string $name,string $type='Item',string $mode='Aggregate',int $stock=10)use($pdo,$mark,&$resources):int{qExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status)VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$mode,$stock,$stock]);return $resources[]=(int)$pdo->lastInsertId();};
    $hall=$resource('Hall','Facility','Facility',1);$hall2=$resource('Annex','Facility','Facility',1);$hall3=$resource('Court','Facility','Facility',1);
    $item=$resource('Chairs');$item2=$resource('Tables');$serial=$resource('Projector','Item','Serialized',3);$serial2=$resource('Speaker','Item','Serialized',2);$paper=$resource('Paper','Item','Consumable',50);
    $facilities=[$hall,$hall2,$hall3];
    $unitIds=[];for($i=0;$i<3;$i++){qExec("INSERT INTO equipment_units(resource_id,asset_tag,serial_number,status,condition_status,registered_by)VALUES(?,?,?,'Available','Good',?)",[$serial,$mark.'-PRJ-'.$i,$mark.'-SN-'.$i,$users['admin']]);$unitIds[]=(int)$pdo->lastInsertId();}
    $otherUnits=[];for($i=0;$i<2;$i++){qExec("INSERT INTO equipment_units(resource_id,asset_tag,status,condition_status,registered_by)VALUES(?,?,'Available','Good',?)",[$serial2,$mark.'-SPK-'.$i,$users['admin']]);$otherUnits[]=(int)$pdo->lastInsertId();}
    qExec("INSERT INTO equipment_units(resource_id,asset_tag,status,condition_status,registered_by)VALUES(?,?,'Lost','Lost',?)",[$serial2,$mark.'-SPK-LOST',$users['admin']]);$lostUnit=(int)$pdo->lastInsertId();
    require_once dirname(__DIR__).'/includes/equipment_unit_helper.php';gsoRecalculateSerializedStock($pdo,$serial);gsoRecalculateSerializedStock($pdo,$serial2);
    $unitCount=(int)qVal('SELECT COUNT(*) FROM equipment_units');

    $date=fn(int $days)=>date('Y-m-d',strtotime($days.' days'));$today=date('Y-m-d');
    $stockOf=fn(int $id)=>qRows('SELECT total_stock,available_stock,under_repair_stock FROM resources WHERE resource_id=?',[$id])[0];
    $res=fn(int $id)=>gsoFetchRequestableResource($pdo,$id);
    $avail=fn(int $id,int $qty,string $day,?string $start='09:00',?string $end='11:00')=>(bool)checkResourceAvailability($pdo,$res($id),$qty,$day,$start,$end,null)['ok'];
    $job=fn(int $id)=>qRows('SELECT * FROM maintenance_schedules WHERE maintenance_id=?',[$id])[0];
    $planRow=fn(int $id)=>qRows('SELECT * FROM preventive_maintenance_plans WHERE plan_id=?',[$id])[0];
    $unitRow=fn(int $id)=>qRows('SELECT status,condition_status,asset_tag,serial_number FROM equipment_units WHERE unit_id=?',[$id])[0];
    $planData=fn(array $over=[])=>array_replace(['resource_id'=>$hall,'equipment_unit_id'=>0,'quantity'=>0,'title'=>$mark.' task','recurrence'=>'Monthly','next_due_date'=>$date(40),'duration_days'=>1,'assigned_to'=>0],$over);
    $save=fn(array $over=[],string $who='admin')=>gsoSavePreventivePlan($pdo,$users[$who],$planData($over));
    $planCount=fn()=>(int)qVal('SELECT COUNT(*) FROM preventive_maintenance_plans WHERE created_by IN(?,?,?,?,?,?)',array_values($users));
    $planPost=function(string $who,array $data,string $query='')use(&$csrf){return qHttp($who,'admin/preventive_plans.php'.$query,array_replace(['csrf_token'=>$csrf[$who]??''],$data));};
    $make=function(string $who,array $lines,int $offset,string $status='Approved',?string $period='AM',string $start='09:00:00',string $end='11:00:00',bool $verified=true)use($pdo,$users,$facilities,$paper,$date):int{
        $mirror=$lines[0][0];foreach($lines as [$id,$qty])if(in_array($id,$facilities,true))$mirror=$id;
        qExec('INSERT INTO resource_requests(borrower_id,resource_id,quantity,date_needed,start_time,end_time,status,approved_by,approved_at)VALUES(?,?,1,?,?,?,?,?,NOW())',[$users[$who],$mirror,$date($offset),$start,$end,$status,$users['admin']]);$rid=(int)$pdo->lastInsertId();
        foreach($lines as [$id,$qty]){$kind=in_array($id,$facilities,true)?'Facility':($id===$paper?'Consumable':'Equipment');qExec('INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity,facility_slot)VALUES(?,?,?,?,?)',[$rid,$id,$kind,$qty,$kind==='Facility'?$period:null]);}
        if($verified)gsoTestVerifiedLetter($pdo,$rid,$users['admin']);return $rid;
    };
    $process=function(string $who,int $id,string $action,array $extra=[])use(&$csrf){return qHttp($who,'admin/requests.php',array_replace(['csrf_token'=>$csrf[$who],'request_id'=>$id,'action'=>$action],$extra));};
    $state=fn(int $id)=>qVal('SELECT status FROM resource_requests WHERE request_id=?',[$id]);
    $schedule=fn(int $id)=>qRows('SELECT date_needed,start_time,end_time FROM resource_requests WHERE request_id=?',[$id])[0];
    $touch=fn()=>qHttp('admin','admin/maintenance.php'); // any page load runs the maintenance sync

    // ---- 1-4, 61: plan permissions and direct-POST protection --------------
    $create=['action'=>'create_plan','plan_id'=>0]+$planData();
    $planPost('admin',$create);qAssert($planCount()===1,'GSO Head could not create a preventive plan');
    $hallPlan=(int)qVal('SELECT MAX(plan_id) FROM preventive_maintenance_plans WHERE created_by=?',[$users['admin']]);
    qAssert($planRow($hallPlan)['resource_id']==$hall&&(int)$planRow($hallPlan)['quantity']===0&&$planRow($hallPlan)['equipment_unit_id']===null,'Facility plan target incorrect');
    foreach(['a'=>'Borrower','director'=>'Director','mis'=>'Super Admin / MIS','staff'=>'GSO Staff','guest'=>'Guest'] as $who=>$label){$planPost($who,$create);qAssert($planCount()===1,$label.' created a preventive plan through direct POST');}
    foreach(['a','director','mis','staff'] as $who)qAssert(qDenied(fn()=>$save([],$who)),'Plan helper accepted unauthorized role '.$who);
    $planPost('admin',['csrf_token'=>'bad']+$create);qAssert($planCount()===1,'Plan creation bypassed CSRF');
    foreach(['admin/preventive_plans.php','admin/maintenance_history.php','admin/maintenance.php'] as $path){
        foreach(['mis','director','a'] as $who)qAssert(qHttp($who,$path)['status']===302,$who.' reached '.$path);
        qAssert(qHttp('guest',$path)['status']===302,'Guest reached '.$path);
        foreach(['admin','staff'] as $who)qAssert(qHttp($who,$path)['status']===200,$who.' could not open '.$path);
    }
    qAssert(!str_contains(qHttp('staff','admin/preventive_plans.php')['body'],'value="create_plan"'),'Staff was shown plan-management controls');

    // ---- 5-12: target validation ------------------------------------------
    qAssert(qDenied(fn()=>$save(['resource_id'=>$paper,'quantity'=>5])),'Consumable accepted a preventive plan');
    qAssert(qDenied(fn()=>$save(['quantity'=>1])),'Facility plan accepted a stock quantity');
    qAssert(qDenied(fn()=>$save(['equipment_unit_id'=>$unitIds[0]])),'Facility plan accepted an equipment unit');
    $aggPlan=$save(['resource_id'=>$item,'quantity'=>2,'next_due_date'=>$date(50),'duration_days'=>2]);qAssert((int)$planRow($aggPlan)['quantity']===2,'Aggregate plan with valid quantity failed');
    foreach([0,11,-1,'2.5','abc'] as $bad)qAssert(qDenied(fn()=>$save(['resource_id'=>$item,'quantity'=>$bad])),'Aggregate plan accepted invalid quantity '.$bad);
    qAssert(qDenied(fn()=>$save(['resource_id'=>$item,'quantity'=>1,'equipment_unit_id'=>$unitIds[0]])),'Aggregate plan accepted a unit id');
    $serialPlan=$save(['resource_id'=>$serial,'equipment_unit_id'=>$unitIds[0],'quantity'=>1,'next_due_date'=>$date(55)]);qAssert((int)$planRow($serialPlan)['equipment_unit_id']===$unitIds[0],'Serialized plan did not target the real unit');
    qAssert(qDenied(fn()=>$save(['resource_id'=>$serial,'equipment_unit_id'=>$otherUnits[0],'quantity'=>1])),'Serialized plan accepted another resource unit');
    qAssert(qDenied(fn()=>$save(['resource_id'=>$serial2,'equipment_unit_id'=>$lostUnit,'quantity'=>1])),'Lost unit accepted a preventive plan');
    qAssert(qDenied(fn()=>$save(['resource_id'=>$serial,'equipment_unit_id'=>999999999,'quantity'=>1])),'Nonexistent unit accepted');
    qAssert(qDenied(fn()=>$save(['resource_id'=>$serial,'equipment_unit_id'=>0,'quantity'=>1])),'Serialized plan accepted without a unit');
    qAssert((int)qVal('SELECT COUNT(*) FROM equipment_units')===$unitCount,'Plan validation invented an equipment unit');
    foreach([['recurrence'=>'Weekly'],['recurrence'=>''],['next_due_date'=>'2026-02-30'],['next_due_date'=>$date(-1)],['next_due_date'=>''],['duration_days'=>0],['duration_days'=>366],['title'=>''],['title'=>str_repeat('x',256)],['assigned_to'=>$users['a']],['assigned_to'=>$users['director']],['assigned_to'=>$users['mis']],['resource_id'=>0]] as $bad)qAssert(qDenied(fn()=>$save($bad)),'Plan accepted invalid input '.json_encode($bad));
    qAssert($planCount()===3,'Rejected plan input still persisted a plan');

    // ---- 13-17: calendar recurrence ---------------------------------------
    qAssert(gsoPreventiveNextDate('2026-01-15','Monthly')==='2026-02-15','Monthly recurrence incorrect');
    qAssert(gsoPreventiveNextDate('2026-01-15','Quarterly')==='2026-04-15','Quarterly recurrence incorrect');
    qAssert(gsoPreventiveNextDate('2026-01-15','Semiannual')==='2026-07-15','Semiannual recurrence incorrect');
    qAssert(gsoPreventiveNextDate('2026-01-15','Annual')==='2027-01-15','Annual recurrence incorrect');
    qAssert(gsoPreventiveNextDate('2026-01-31','Monthly')==='2026-02-28','Month-end did not clamp to the last valid day');
    qAssert(gsoPreventiveNextDate('2026-02-28','Monthly',31)==='2026-03-31','Month-end anchor drifted after a short month');
    qAssert(gsoPreventiveNextDate('2026-11-30','Quarterly')==='2027-02-28','Quarterly month-end incorrect');
    qAssert(gsoPreventiveNextDate('2024-02-29','Annual')==='2025-02-28','Leap-day annual recurrence incorrect');
    qAssert(gsoPreventiveNextDate('2026-08-31','Semiannual')==='2027-02-28','Semiannual month-end incorrect');
    qAssert(gsoPreventiveNextDate('2026-01-15','Once')===null,'One-time plan produced a recurrence');
    qAssert(qDenied(fn()=>gsoPreventiveNextDate('2026-01-15','Weekly')),'Unsupported recurrence computed');

    // ---- 18-20: occurrence generation and idempotency ---------------------
    $hallJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$hallPlan);$row=$job($hallJob);
    qAssert($row['maintenance_type']==='Preventive'&&$row['status']==='Scheduled'&&(int)$row['preventive_plan_id']===$hallPlan&&$row['plan_occurrence_date']===$date(40)&&$row['started_at']===null&&$row['completed_at']===null,'Preventive occurrence not classified/linked correctly');
    qAssert(gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$hallPlan)===$hallJob,'Repeated generation created another occurrence');
    $planPost('admin',['action'=>'generate','plan_id'=>$hallPlan]);$planPost('admin',['action'=>'generate','plan_id'=>$hallPlan]);
    qRace([['admin','admin/preventive_plans.php',['csrf_token'=>$csrf['admin'],'action'=>'generate','plan_id'=>$hallPlan]],['admin','admin/preventive_plans.php',['csrf_token'=>$csrf['admin'],'action'=>'generate','plan_id'=>$hallPlan]]]);
    qAssert((int)qVal('SELECT COUNT(*) FROM maintenance_schedules WHERE preventive_plan_id=?',[$hallPlan])===1,'Duplicate preventive occurrence generated');
    qHttp('admin','admin/preventive_plans.php');qHttp('admin','admin/maintenance.php');qHttp('admin','admin/maintenance_history.php');
    qAssert((int)qVal('SELECT COUNT(*) FROM maintenance_schedules WHERE preventive_plan_id IN(?,?,?)',[$hallPlan,$aggPlan,$serialPlan])===1,'Page loads generated preventive occurrences');
    $dup=false;try{qExec("INSERT INTO maintenance_schedules(maintenance_type,preventive_plan_id,plan_occurrence_date,resource_id,start_date,end_date,reason,created_by)VALUES('Preventive',?,?,?,?,?,'dup',?)",[$hallPlan,$date(40),$hall,$date(40),$date(40),$users['admin']]);}catch(PDOException $e){$dup=true;}qAssert($dup,'Database allowed a duplicate plan occurrence');
    $orphan=false;try{qExec("INSERT INTO maintenance_schedules(maintenance_type,resource_id,start_date,end_date,reason,created_by)VALUES('Preventive',?,?,?,'no plan',?)",[$hall,$date(90),$date(90),$users['admin']]);}catch(PDOException $e){$orphan=true;}qAssert($orphan,'Database allowed a Preventive occurrence without a plan');
    foreach(['staff','a','director','mis'] as $who)qAssert(qDenied(fn()=>gsoGeneratePreventiveOccurrence($pdo,$users[$who],$aggPlan)),$who.' generated an occurrence');

    // ---- 19: corrective occurrences stay Corrective ------------------------
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'add_schedule','resource_id'=>$hall3,'start_date'=>$date(20),'duration_days'=>1,'reason'=>$mark.' corrective repair']);
    $manual=(int)qVal('SELECT MAX(maintenance_id) FROM maintenance_schedules WHERE resource_id=?',[$hall3]);qAssert($manual>0&&$job($manual)['maintenance_type']==='Corrective'&&$job($manual)['preventive_plan_id']===null,'Manual schedule was not classified Corrective');

    // ---- 21-24: future holds do not mutate current inventory ---------------
    $before=$stockOf($item);$aggJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$aggPlan);
    qAssert($stockOf($item)===$before&&$before==['total_stock'=>10,'available_stock'=>10,'under_repair_stock'=>0],'Future preventive schedule mutated current stock');
    qAssert($job($aggJob)['end_date']===$date(51)&&(int)$job($aggJob)['quantity']===2,'Aggregate occurrence snapshot incorrect');
    qAssert($avail($item,10,$today),'Future preventive schedule reduced current availability');
    qAssert($avail($item,8,$date(50))&&!$avail($item,9,$date(50)),'Aggregate preventive hold did not reduce overlapping capacity by its quantity');
    qAssert($avail($item,8,$date(51))&&!$avail($item,9,$date(51)),'Aggregate preventive hold ignored its last day');
    qAssert($avail($item,10,$date(49))&&$avail($item,10,$date(52)),'Aggregate preventive hold leaked outside its interval');
    $serialBefore=$stockOf($serial);$serialJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$serialPlan);
    qAssert($stockOf($serial)===$serialBefore&&$unitRow($unitIds[0])['status']==='Available','Future serialized preventive schedule mutated the unit');
    qAssert($avail($serial,2,$date(55))&&!$avail($serial,3,$date(55)),'Serialized preventive hold did not block exactly one unit');
    qAssert($avail($serial,3,$date(54))&&$avail($serial,3,$date(56)),'Serialized preventive hold leaked outside its interval');
    qAssert(gsoPreventiveUnitConflict($pdo,$unitIds[0],$date(55).' 09:00:00',$date(55).' 11:00:00')&&!gsoPreventiveUnitConflict($pdo,$unitIds[1],$date(55).' 09:00:00',$date(55).' 11:00:00'),'Unit conflict did not isolate the target unit');
    $serialRequest=$make('a',[[$serial,1]],55);$line=(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=?',[$serialRequest]);
    $process('admin',$serialRequest,'release',['unit_ids'=>[$line=>[$unitIds[0]]]]);qAssert($state($serialRequest)==='Approved'&&$unitRow($unitIds[0])['status']==='Available','Unit under preventive hold was allocated to an overlapping loan');
    $process('admin',$serialRequest,'release',['unit_ids'=>[$line=>[$unitIds[1]]]]);qAssert($state($serialRequest)==='Released'&&$unitRow($unitIds[1])['status']==='Released','Unaffected serialized unit could not be released');

    // ---- 25-28: facility blocks and request paths --------------------------
    qAssert(!$avail($hall,1,$date(40),'09:00','11:00')&&!$avail($hall,1,$date(40),'13:00','15:00'),'Facility preventive day did not block both AM and PM');
    qAssert($avail($hall,1,$date(39),'09:00','11:00')&&$avail($hall,1,$date(41),'13:00','15:00'),'Facility preventive block leaked to other days');
    $blocked=$make('a',[[$hall,1]],40,'Pending');qAssert(gsoRequestLineAvailabilityError($pdo,qRows('SELECT * FROM resource_requests WHERE request_id=?',[$blocked])[0])!==null,'Shared line check missed the preventive conflict');
    $process('admin',$blocked,'approve');qAssert($state($blocked)!=='Approved','Registered facility request bypassed preventive maintenance');
    $over=$make('a',[[$item,9]],50,'Pending');$process('admin',$over,'approve');qAssert($state($over)!=='Approved','Registered equipment request bypassed preventive capacity');
    qExec("UPDATE resource_requests SET status='Cancelled' WHERE request_id IN(?,?)",[$blocked,$over]);
    $fits=$make('a',[[$item,3]],50,'Pending');$process('admin',$fits,'approve');qAssert($state($fits)==='Approved','Registered request within remaining capacity was refused');
    $client=['walkin_full_name'=>$mark.' Client','walkin_contact_number'=>'09123456789','date_needed'=>$date(50),'start_time'=>'09:00','end_time'=>'11:00'];
    $lastWalkin=fn()=>(int)qVal('SELECT COALESCE(MAX(request_id),0) FROM resource_requests WHERE encoded_by=?',[$users['staff']]);
    qHttp('staff','admin/create_walkin.php',['csrf_token'=>$csrf['staff'],'lines'=>[['resource_id'=>$item,'quantity'=>6]]]+$client);qAssert($lastWalkin()===0,'Walk-in equipment request bypassed preventive capacity');
    qHttp('staff','admin/create_walkin.php',['csrf_token'=>$csrf['staff'],'lines'=>[['resource_id'=>$item,'quantity'=>5]]]+$client);$walkItem=$lastWalkin();qAssert($walkItem>0,'Walk-in within remaining capacity was refused');$walkins[]=$walkItem;
    qAssert(qVal('SELECT borrower_id FROM resource_requests WHERE request_id=?',[$walkItem])===null&&qVal('SELECT requester_type FROM resource_requests WHERE request_id=?',[$walkItem])==='Walk-in','R5 walk-in identity changed');
    $facilityClient=['walkin_full_name'=>$mark.' Hall Client','walkin_contact_number'=>'09123456789','start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','lines'=>[['resource_id'=>$hall,'quantity'=>1]]];
    qHttp('staff','admin/create_walkin.php',['csrf_token'=>$csrf['staff'],'date_needed'=>$date(40),'request_letter'=>new CURLFile(gsoTestLetterImage(),'image/png','signed.png')]+$facilityClient);qAssert($lastWalkin()===$walkItem,'Walk-in facility request bypassed preventive maintenance');
    qHttp('staff','admin/create_walkin.php',['csrf_token'=>$csrf['staff'],'date_needed'=>$date(31),'request_letter'=>new CURLFile(gsoTestLetterImage(),'image/png','signed.png')]+$facilityClient);$walkHall=$lastWalkin();qAssert($walkHall>$walkItem,'Walk-in facility request on a free day failed');$walkins[]=$walkHall;
    qAssert(count(gsoRequestLetters($pdo,$walkHall))===1,'R2B walk-in letter was not stored');

    // ---- 29-30, 56-58: rescheduling ----------------------------------------
    $reservation=$make('a',[[$hall,1]],30);
    qAssert(!$avail($hall,1,$date(30),'09:00','11:00')&&$avail($hall,1,$date(30),'13:00','15:00'),'R3 AM reservation no longer leaves PM free');
    $propose=fn(int $offset,string $period='PM',string $start='13:00',string $end='15:00')=>qHttp('a','borrower/reschedule.php?request_id='.$reservation,['csrf_token'=>$csrf['a'],'action'=>'create','date_needed'=>$date($offset),'start_time'=>$start,'end_time'=>$end,'facility_slot'=>$period,'reason'=>'Client requested a different event schedule']);
    $attempt=fn(int $id)=>(int)qVal('SELECT COALESCE(MAX(reschedule_id),0) FROM request_reschedule_history WHERE request_id=?',[$id]);
    $review=fn(string $who,int $id,int $rid,string $action='approve')=>qHttp($who,'admin/reschedules.php?request_id='.$id,['csrf_token'=>$csrf[$who],'request_id'=>$id,'reschedule_id'=>$rid,'action'=>$action]);
    $original=$schedule($reservation);$propose(40);$first=$attempt($reservation);qAssert($first>0,'Reschedule proposal was not recorded');
    $review('admin',$reservation,$first);qAssert($schedule($reservation)===$original,'Registered reschedule bypassed preventive maintenance');
    $review('admin',$reservation,$first,'reject');$propose(33);$second=$attempt($reservation);qAssert($second>$first,'Follow-up proposal not recorded');
    $review('admin',$reservation,$second);qAssert($schedule($reservation)['date_needed']===$date(33),'R4 valid reschedule approval failed');
    qExec("UPDATE resource_requests SET status='Approved',approved_by=?,approved_at=NOW() WHERE request_id=?",[$users['admin'],$walkHall]);$walkOriginal=$schedule($walkHall);
    qHttp('staff','admin/walkin_actions.php?request_id='.$walkHall,['csrf_token'=>$csrf['staff'],'action'=>'reschedule','date_needed'=>$date(40),'start_time'=>'09:00','end_time'=>'11:00','facility_slot'=>'AM','reason'=>'Client asked to move the event']);$walkAttempt=$attempt($walkHall);qAssert($walkAttempt>0,'Walk-in reschedule proposal was not recorded');
    $review('admin',$walkHall,$walkAttempt);qAssert($schedule($walkHall)===$walkOriginal,'Walk-in reschedule bypassed preventive maintenance');
    $unverified=$make('a',[[$hall2,1]],35,'Pending','AM','09:00:00','11:00:00',false);$process('admin',$unverified,'approve');qAssert($state($unverified)!=='Approved','R2B approval no longer requires a verified letter');

    // ---- 51-52: director schedule and live availability --------------------
    $director=qHttp('director','director/dashboard.php?facility_calendar_month='.date('Y-m',strtotime($date(40))))['body'];
    qAssert(str_contains($director,'MNT-'.str_pad((string)$hallJob,3,'0',STR_PAD_LEFT)),'Director schedule omitted the maintenance block');
    qAssert(!str_contains($director,'complete_job')&&!str_contains($director,'cancel_job')&&!str_contains($director,'preventive_plans.php')&&!str_contains($director,'maintenance_history.php'),'Director schedule exposed maintenance controls');
    qAssert(!str_contains($director,$mark.' task'),'Director schedule exposed the internal maintenance task');
    foreach(['start_job','complete_job','cancel_job'] as $action){$planPost('director',['action'=>$action,'plan_id'=>$hallPlan,'maintenance_id'=>$hallJob]);qAssert($job($hallJob)['status']==='Scheduled','Director mutated maintenance via '.$action);}
    qHttp('director','admin/maintenance.php',['csrf_token'=>$csrf['director'],'action'=>'cancel_schedule','maintenance_id'=>$hallJob]);qAssert($job($hallJob)['status']==='Scheduled','Director cancelled maintenance');
    $live=gsoChatCheckFacilityAvailability($pdo,['user_id'=>$users['a'],'role'=>'Borrower'],$mark.' Hall',['facility_name'=>$mark.' Hall','date'=>$date(40),'start_time'=>'09:00','end_time'=>'11:00']);qAssert(($live['facts']['available']??null)===false,'Live availability ignored preventive maintenance');
    $live=gsoChatCheckFacilityAvailability($pdo,['user_id'=>$users['a'],'role'=>'Borrower'],$mark.' Hall',['facility_name'=>$mark.' Hall','date'=>$date(41),'start_time'=>'09:00','end_time'=>'11:00']);qAssert(($live['facts']['available']??null)===true,'Live availability blocked a free facility day');

    // ---- 31, 34, 36, 40: aggregate start and completion --------------------
    qAssert(!gsoPreventiveJobAction($pdo,$aggJob,$users['admin'],'start')['ok']&&$stockOf($item)==['total_stock'=>10,'available_stock'=>10,'under_repair_stock'=>0],'A future occurrence was started before its due date');
    qAssert(!gsoPreventiveJobAction($pdo,$aggJob,$users['admin'],'complete','x')['ok']&&$job($aggJob)['status']==='Scheduled','A never-started occurrence was completed');
    $nowPlan=$save(['resource_id'=>$item2,'quantity'=>2,'next_due_date'=>$today,'assigned_to'=>$users['staff'],'title'=>$mark.' table check']);$nowJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$nowPlan);
    qAssert((int)$job($nowJob)['assigned_to']===$users['staff']&&(int)qVal("SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='work_order_assigned'",[$users['staff']])===1,'Assignee was not recorded/notified in-app');
    $touch();qAssert($job($nowJob)['status']==='Scheduled'&&$stockOf($item2)['available_stock']==10,'Preventive occurrence auto-started by date');
    foreach(['a','director','mis','staff2'] as $who)qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users[$who],'start')['ok'],$who.' started preventive maintenance');
    qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users['staff'],'cancel')['ok'],'Staff cancelled a preventive occurrence');
    qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users['admin'],'purge')['ok'],'Unknown job action accepted');
    qAssert(gsoPreventiveJobAction($pdo,$nowJob,$users['staff'],'start')['ok'],'Assigned staff could not start preventive maintenance');
    qAssert($stockOf($item2)==['total_stock'=>10,'available_stock'=>8,'under_repair_stock'=>2]&&$job($nowJob)['status']==='In Progress'&&$job($nowJob)['started_at']!==null,'Aggregate start did not hold exactly the target quantity');
    qAssert(qVal('SELECT status FROM resources WHERE resource_id=?',[$item2])==='Available','Partial aggregate maintenance blocked the whole resource');
    qAssert($avail($item2,8,$today,null,null)&&!$avail($item2,9,$today,null,null),'Unaffected aggregate quantity was not usable during maintenance');
    qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users['staff'],'start')['ok']&&$stockOf($item2)['under_repair_stock']==2,'Repeated start double-held stock');
    $planPost('a',['action'=>'complete_job','plan_id'=>$nowPlan,'maintenance_id'=>$nowJob]);$planPost('mis',['action'=>'complete_job','plan_id'=>$nowPlan,'maintenance_id'=>$nowJob]);$planPost('admin',['csrf_token'=>'bad','action'=>'complete_job','plan_id'=>$nowPlan,'maintenance_id'=>$nowJob]);
    $planPost('admin',['action'=>'complete_job','plan_id'=>$hallPlan,'maintenance_id'=>$nowJob]);$planPost('admin',['action'=>'complete_job','plan_id'=>$nowPlan,'maintenance_id'=>$nowJob],'?plan_id='.$hallPlan);
    qAssert($job($nowJob)['status']==='In Progress'&&$stockOf($item2)['under_repair_stock']==2,'Completion bypassed role, CSRF, or plan/work-order ID checks');
    qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users['staff2'],'complete','x')['ok'],'Unassigned staff completed preventive maintenance');
    $done=gsoPreventiveJobAction($pdo,$nowJob,$users['staff'],'complete','Tables inspected and tightened');qAssert($done['ok']&&$done['restored']===2,'Authorized aggregate completion failed');
    $row=$job($nowJob);qAssert($stockOf($item2)==['total_stock'=>10,'available_stock'=>10,'under_repair_stock'=>0]&&$row['status']==='Completed'&&$row['completed_at']!==null&&$row['completion_notes']==='Tables inspected and tightened'&&(int)$row['updated_by']===$users['staff'],'Aggregate completion did not restore exactly the held quantity');
    qAssert(!gsoPreventiveJobAction($pdo,$nowJob,$users['staff'],'complete','again')['ok']&&!gsoCompleteRepairWorkOrder($pdo,$nowJob,$users['admin'],'again')['ok']&&$stockOf($item2)['available_stock']==10,'Repeated completion double-restored stock');
    qAssert(qVal('SELECT condition_status FROM resources WHERE resource_id=?',[$item2])==='Good','Preventive completion changed resource condition');
    $expectedNext=gsoPreventiveNextDate($today,'Monthly');qAssert($planRow($nowPlan)['next_due_date']===$expectedNext&&(int)$planRow($nowPlan)['is_active']===1,'Completion did not advance next_due_date by one calendar month');
    qAssert($job($nowJob)['plan_occurrence_date']===$today&&$job($nowJob)['start_date']===$today,'Advancing the plan rewrote the completed occurrence');

    // ---- 32, 35, 36: serialized start and completion ------------------------
    $unitPlan=$save(['resource_id'=>$serial,'equipment_unit_id'=>$unitIds[2],'quantity'=>1,'next_due_date'=>$today,'recurrence'=>'Quarterly','assigned_to'=>$users['staff'],'title'=>$mark.' lamp check']);$unitJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$unitPlan);
    $planPost('staff',['action'=>'start_job','plan_id'=>$unitPlan,'maintenance_id'=>$unitJob]);
    qAssert($job($unitJob)['status']==='In Progress'&&$unitRow($unitIds[2])==['status'=>'Under Repair','condition_status'=>'Good','asset_tag'=>$mark.'-PRJ-2','serial_number'=>$mark.'-SN-2'],'Serialized start did not hold exactly the target unit or changed its identity');
    qAssert($unitRow($unitIds[0])['status']==='Available'&&$unitRow($unitIds[1])['status']==='Released','Serialized start affected unrelated units');
    qAssert($stockOf($serial)==['total_stock'=>3,'available_stock'=>1,'under_repair_stock'=>1]&&qVal('SELECT status FROM resources WHERE resource_id=?',[$serial])==='Available','Serialized counters incorrect during maintenance');
    qAssert(!gsoUpdateEquipmentUnitStatus($pdo,$unitIds[2],'Available','Good','Bypass preventive completion',$users['admin'])['ok'],'Unit status edit bypassed explicit completion');
    $payload=['action'=>'complete_job','plan_id'=>$unitPlan,'maintenance_id'=>$unitJob,'completion_notes'=>'Lamp hours checked'];
    qRace([['admin','admin/preventive_plans.php',['csrf_token'=>$csrf['admin']]+$payload],['staff','admin/preventive_plans.php',['csrf_token'=>$csrf['staff']]+$payload]]);
    qAssert($job($unitJob)['status']==='Completed'&&$unitRow($unitIds[2])['status']==='Available'&&$unitRow($unitIds[2])['condition_status']==='Good','Serialized completion did not restore the target unit');
    qAssert($stockOf($serial)==['total_stock'=>3,'available_stock'=>2,'under_repair_stock'=>0]&&$unitRow($unitIds[1])['status']==='Released','Concurrent completion double-restored or touched another unit');
    qAssert((int)qVal("SELECT COUNT(*) FROM activity_logs WHERE action='Preventive Maintenance Complete' AND details LIKE ?",['MTN-'.$unitJob.';%'])===1,'Concurrent completion was recorded twice');
    qAssert($planRow($unitPlan)['next_due_date']===gsoPreventiveNextDate($today,'Quarterly'),'Quarterly plan did not advance by three calendar months');
    qAssert((int)qVal('SELECT COUNT(*) FROM equipment_units')===$unitCount,'Maintenance workflow invented or removed units');

    // ---- 38: completion never overwrites an unrelated condition -------------
    $planPost('admin',['action'=>'edit_plan','plan_id'=>$unitPlan,'revision'=>$planRow($unitPlan)['revision']]+$planData(['resource_id'=>$serial,'equipment_unit_id'=>$unitIds[2],'quantity'=>1,'next_due_date'=>$date(1),'recurrence'=>'Quarterly','assigned_to'=>$users['staff'],'title'=>$mark.' lamp check revised']),'?plan_id='.$unitPlan);
    qAssert($planRow($unitPlan)['title']===$mark.' lamp check revised'&&$job($unitJob)['reason']===$mark.' lamp check','Plan edit rewrote completed history');
    $secondJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$unitPlan);qAssert($secondJob!==$unitJob&&$job($secondJob)['reason']===$mark.' lamp check revised','Edited due date did not allow the next occurrence');
    qExec('UPDATE maintenance_schedules SET start_date=?,end_date=? WHERE maintenance_id=?',[$today,$today,$secondJob]); // the due day arrives
    qAssert(gsoPreventiveJobAction($pdo,$secondJob,$users['admin'],'start')['ok'],'Second serialized occurrence could not start');
    qExec("UPDATE equipment_units SET condition_status='Needs Repair' WHERE unit_id=?",[$unitIds[2]]);
    $result=gsoPreventiveJobAction($pdo,$secondJob,$users['admin'],'complete','Fault found during service');qAssert($result['ok']&&$result['restored']===0,'Completion with a found fault failed');
    qAssert($unitRow($unitIds[2])['status']==='Under Repair'&&$unitRow($unitIds[2])['condition_status']==='Needs Repair','Preventive completion overwrote a non-Good condition');
    qExec("UPDATE equipment_units SET status='Available',condition_status='Good' WHERE unit_id=?",[$unitIds[2]]);gsoRecalculateSerializedStock($pdo,$serial);

    // ---- 37, 54: Lost can never be serviced or restored --------------------
    $lostPlan=$save(['resource_id'=>$serial2,'equipment_unit_id'=>$otherUnits[0],'quantity'=>1,'next_due_date'=>$today,'recurrence'=>'Once','title'=>$mark.' speaker check']);$lostJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$lostPlan);
    qAssert(gsoPreventiveJobAction($pdo,$lostJob,$users['admin'],'start')['ok'],'Speaker occurrence could not start');
    qExec("UPDATE equipment_units SET status='Lost',condition_status='Lost' WHERE unit_id=?",[$otherUnits[0]]);
    qAssert(!gsoPreventiveJobAction($pdo,$lostJob,$users['admin'],'complete','restore it')['ok']&&!gsoCompleteRepairWorkOrder($pdo,$lostJob,$users['admin'],'restore it')['ok'],'Preventive completion accepted a Lost unit');
    qAssert($unitRow($otherUnits[0])['status']==='Lost'&&$unitRow($otherUnits[0])['condition_status']==='Lost','Preventive maintenance restored a Lost unit');
    qAssert(gsoPreventiveJobAction($pdo,$lostJob,$users['admin'],'cancel','Unit reported lost')['ok']&&$unitRow($otherUnits[0])['status']==='Lost'&&$job($lostJob)['status']==='Cancelled'&&$job($lostJob)['restored_at']===null,'Cancelling a Lost-target occurrence restored inventory');
    qAssert(qDenied(fn()=>gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$lostPlan)),'A Lost unit received a new preventive occurrence');
    qAssert(qDenied(fn()=>$save(['resource_id'=>$serial2,'equipment_unit_id'=>$otherUnits[0],'quantity'=>1])),'Lost unit used a new plan as a repair bypass');
    $oncePlan=$save(['resource_id'=>$serial2,'equipment_unit_id'=>$otherUnits[1],'quantity'=>1,'next_due_date'=>$today,'recurrence'=>'Once','title'=>$mark.' one time']);$onceJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$oncePlan);
    qAssert(gsoPreventiveJobAction($pdo,$onceJob,$users['admin'],'start')['ok']&&gsoPreventiveJobAction($pdo,$onceJob,$users['admin'],'complete','done')['ok'],'One-time occurrence failed');
    qAssert((int)$planRow($oncePlan)['is_active']===0&&$planRow($oncePlan)['next_due_date']===$today&&qDenied(fn()=>gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$oncePlan)),'One-time plan kept recurring after completion');

    // ---- 33, 39, 40: facility start and no completion by elapsed date -------
    $annexPlan=$save(['resource_id'=>$hall2,'next_due_date'=>$today,'recurrence'=>'Annual','title'=>$mark.' annex aircon']);$annexJob=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$annexPlan);
    qExec('UPDATE maintenance_schedules SET start_date=?,end_date=? WHERE maintenance_id=?',[$date(-3),$date(-2),$annexJob]);$touch();
    qAssert($job($annexJob)['status']==='Scheduled'&&$job($annexJob)['completed_at']===null,'Scheduled preventive maintenance changed status because its end date passed');
    qAssert($planRow($annexPlan)['next_due_date']===$today,'next_due_date advanced only because time passed');
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'start_schedule','maintenance_id'=>$annexJob]);
    qAssert($job($annexJob)['status']==='In Progress'&&qVal('SELECT status FROM resources WHERE resource_id=?',[$hall2])==='Maintenance','Facility start did not mark the facility under maintenance');
    qAssert(!$avail($hall2,1,$date(2),'09:00','11:00')&&$avail($hall,1,$date(2),'09:00','11:00'),'Facility under active maintenance stayed bookable or blocked another facility');
    $touch();qAssert($job($annexJob)['status']==='In Progress'&&$job($annexJob)['completed_at']===null,'In Progress preventive maintenance auto-completed after its end date');
    qExec("INSERT INTO maintenance_schedules(maintenance_type,resource_id,start_date,end_date,duration_days,reason,status,created_by)VALUES('Corrective',?,?,?,2,?,'In Progress',?)",[$hall3,$date(-5),$date(-4),$mark.' overdue corrective',$users['admin']]);$overdue=(int)$pdo->lastInsertId();
    $touch();qAssert($job($overdue)['status']==='In Progress','Corrective maintenance auto-completed because its end date passed');
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'complete_schedule','maintenance_id'=>$annexJob,'completion_notes'=>'Aircon cleaned']);
    qAssert($job($annexJob)['status']==='Completed'&&qVal('SELECT status FROM resources WHERE resource_id=?',[$hall2])==='Available'&&$avail($hall2,1,$date(2),'09:00','11:00'),'Explicit facility completion did not restore availability');
    qAssert($planRow($annexPlan)['next_due_date']===gsoPreventiveNextDate($today,'Annual'),'Annual plan did not advance by one calendar year');

    // ---- 41-47: maintenance history ----------------------------------------
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'complete_schedule','maintenance_id'=>$manual,'completion_notes'=>'Court floor repaired']);qAssert($job($manual)['status']==='Completed'&&$job($manual)['maintenance_type']==='Corrective','Corrective completion failed');
    qHttp('admin','admin/maintenance.php',['csrf_token'=>$csrf['admin'],'action'=>'cancel_schedule','maintenance_id'=>$overdue]);qAssert($job($overdue)['status']==='Cancelled','Corrective cancellation failed');
    $returned=$make('a',[[$item2,2]],60);$process('admin',$returned,'release');qAssert($state($returned)==='Released','R1 release failed');$returnLine=(int)qVal('SELECT request_item_id FROM request_items WHERE request_id=?',[$returned]);
    qHttp('a','borrower/return_submit.php?request_id='.$returned,['csrf_token'=>$csrf['a'],'lines'=>[$returnLine=>['outcomes'=>['Damaged'=>1,'Lost'=>1]]]]);qAssert($state($returned)==='Return','R1 return submission failed');
    $returnId=(int)qVal('SELECT return_id FROM return_submissions WHERE request_id=?',[$returned]);$inspection=[];foreach(qRows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId]) as $d)$inspection[$d['return_item_id']]=['condition'=>$d['reported_condition'],'remarks'=>'R7 inspection'];
    qHttp('admin','admin/review_return.php?request_id='.$returned,['csrf_token'=>$csrf['admin'],'action'=>'approve','inspection'=>$inspection]);qAssert($state($returned)==='Returned'&&$stockOf($item2)==['total_stock'=>9,'available_stock'=>8,'under_repair_stock'=>1],'R1/R6 return inspection arithmetic changed');
    $detail=[];foreach(qRows('SELECT * FROM return_submission_items WHERE return_id=?',[$returnId]) as $d)$detail[$d['inspection_condition']]=(int)$d['return_item_id'];
    qAssert(!gsoCreateReturnRepairWorkOrder($pdo,$detail['Lost'],$users['admin'],$users['staff'],1)['ok'],'R6 Lost return detail entered repair');
    $work=gsoCreateReturnRepairWorkOrder($pdo,$detail['Damaged'],$users['admin'],$users['staff'],1);qAssert($work['ok']&&$job((int)$work['maintenance_id'])['maintenance_type']==='Corrective','Damaged return work order is not Corrective');$repair=(int)$work['maintenance_id'];
    qAssert(gsoCompleteRepairWorkOrder($pdo,$repair,$users['staff'],'Table leg replaced')['ok']&&$stockOf($item2)==['total_stock'=>9,'available_stock'=>9,'under_repair_stock'=>0],'R6 corrective completion changed');
    $code=fn(int $id)=>'MTN-'.str_pad((string)$id,3,'0',STR_PAD_LEFT);
    $history=qHttp('admin','admin/maintenance_history.php')['body'];$active=qHttp('admin','admin/maintenance.php')['body'];
    foreach([$nowJob,$unitJob,$annexJob,$onceJob] as $id)qAssert(str_contains($history,$code($id)),'Completed preventive occurrence missing from history '.$id);
    qAssert(str_contains($history,$code($manual))&&str_contains($history,$code($repair)),'Completed corrective occurrence missing from history');
    qAssert(str_contains($history,$code($overdue))&&str_contains($history,$code($lostJob)),'Cancelled occurrence missing from history');
    foreach([$hallJob,$aggJob,$serialJob] as $id){qAssert(!str_contains($history,$code($id)),'Active occurrence shown as history '.$id);qAssert(str_contains($active,$code($id)),'Active occurrence missing from active list '.$id);}
    foreach([$nowJob,$manual,$overdue] as $id)qAssert(!str_contains($active,$code($id)),'Terminal occurrence still listed as active '.$id);
    qAssert(str_contains($history,'Preventive Plan #'.$nowPlan)&&str_contains($history,'preventive_plans.php?plan_id='.$nowPlan),'Preventive history lost its plan link');
    qAssert(str_contains($history,'Return Detail #'.$detail['Damaged'])&&str_contains($history,'Manual schedule'),'Corrective history lost its source relationship');
    qAssert((int)$job($repair)['source_return_item_id']===$detail['Damaged']&&(int)$job($repair)['source_return_id']===$returnId,'Return source link was rewritten');
    qAssert(str_contains($history,'Tables inspected and tightened')&&str_contains($history,$mark.' staff')&&str_contains($history,$mark.'-PRJ-2')&&str_contains($history,'Quantity 2'),'History omitted notes, actors, unit, or quantity');
    qAssert(!str_contains($history,$mark.' a<')&&!str_contains($history,strtolower($mark.'_a@example.invalid')),'History exposed borrower identity');
    $onlyPreventive=qHttp('admin','admin/maintenance_history.php?type=Preventive')['body'];qAssert(str_contains($onlyPreventive,$code($nowJob))&&!str_contains($onlyPreventive,$code($manual)),'History type filter does not separate Preventive');
    $onlyCorrective=qHttp('admin','admin/maintenance_history.php?type=Corrective')['body'];qAssert(str_contains($onlyCorrective,$code($manual))&&!str_contains($onlyCorrective,$code($nowJob)),'History type filter does not separate Corrective');
    $onlyCancelled=qHttp('admin','admin/maintenance_history.php?status=Cancelled')['body'];qAssert(str_contains($onlyCancelled,$code($overdue))&&!str_contains($onlyCancelled,$code($manual)),'History status filter incorrect');
    $unitHistory=qHttp('admin','admin/maintenance_history.php?unit_id='.$unitIds[2])['body'];qAssert(str_contains($unitHistory,$code($unitJob))&&str_contains($unitHistory,$code($secondJob))&&!str_contains($unitHistory,$code($nowJob)),'Unit history does not trace the exact unit');
    $facilityHistory=qHttp('staff','admin/maintenance_history.php?resource_id='.$hall2)['body'];qAssert(str_contains($facilityHistory,$code($annexJob))&&str_contains($facilityHistory,'Aircon cleaned')&&!str_contains($facilityHistory,$code($manual)),'Facility history incorrect');
    qAssert(str_contains(qHttp('admin','admin/view_resource.php?resource_id='.$hall2)['body'],'maintenance_history.php?resource_id='.$hall2)&&str_contains(qHttp('admin','admin/equipment_units.php?resource_id='.$serial)['body'],'maintenance_history.php?unit_id='.$unitIds[2]),'Resource/unit pages do not link to maintenance history');
    qAssert((int)qVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('maintenance_history','preventive_work_orders')")===0,'A duplicate history/work-order table exists');

    // ---- 43, 48-50, 61: cancel, edit and disable ---------------------------
    $planPost('staff',['action'=>'cancel_job','plan_id'=>$hallPlan,'maintenance_id'=>$hallJob]);qAssert($job($hallJob)['status']==='Scheduled','Staff cancelled an occurrence through direct POST');
    $planPost('admin',['action'=>'cancel_job','plan_id'=>$hallPlan,'maintenance_id'=>$hallJob,'completion_notes'=>'Contractor unavailable']);qAssert($job($hallJob)['status']==='Cancelled'&&$job($hallJob)['completed_at']===null,'Occurrence cancellation failed');
    qAssert($avail($hall,1,$date(40),'09:00','11:00'),'Cancelled occurrence still blocked the facility');
    qAssert($planRow($hallPlan)['next_due_date']===$date(40)&&qDenied(fn()=>gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$hallPlan)),'Cancelled occurrence was silently regenerated');
    $revision=(int)$planRow($hallPlan)['revision'];
    qAssert(qDenied(fn()=>gsoSavePreventivePlan($pdo,$users['admin'],$planData(['next_due_date'=>$date(41)]),$hallPlan,$revision+5)),'Stale plan revision accepted');
    qAssert(qDenied(fn()=>gsoSavePreventivePlan($pdo,$users['staff'],$planData(['next_due_date'=>$date(41)]),$hallPlan,$revision)),'Staff edited a plan');
    $planPost('admin',['action'=>'edit_plan','plan_id'=>$aggPlan,'revision'=>$revision]+$planData(['next_due_date'=>$date(41)]),'?plan_id='.$hallPlan);qAssert($planRow($hallPlan)['next_due_date']===$date(40)&&(int)$planRow($aggPlan)['resource_id']===$item,'Plan ID tampering edited a plan');
    gsoSavePreventivePlan($pdo,$users['admin'],$planData(['next_due_date'=>$date(41),'title'=>$mark.' task v2']),$hallPlan,$revision);qAssert($planRow($hallPlan)['next_due_date']===$date(41)&&(int)$planRow($hallPlan)['revision']===$revision+1,'Plan edit failed');
    qAssert($job($hallJob)['reason']===$mark.' task'&&$job($hallJob)['start_date']===$date(40)&&$job($hallJob)['status']==='Cancelled','Plan edit rewrote existing occurrence history');
    $nextHall=gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$hallPlan);qAssert($nextHall!==$hallJob&&$job($nextHall)['start_date']===$date(41)&&$job($nextHall)['reason']===$mark.' task v2','New occurrence after edit incorrect');
    $historyBefore=qRows('SELECT * FROM maintenance_schedules WHERE preventive_plan_id=? ORDER BY maintenance_id',[$hallPlan]);
    $planPost('staff',['action'=>'disable_plan','plan_id'=>$hallPlan,'revision'=>$planRow($hallPlan)['revision']]);qAssert((int)$planRow($hallPlan)['is_active']===1,'Staff disabled a plan');
    $planPost('admin',['action'=>'disable_plan','plan_id'=>$hallPlan,'revision'=>(int)$planRow($hallPlan)['revision']+3]);qAssert((int)$planRow($hallPlan)['is_active']===1,'Stale revision disabled a plan');
    $planPost('admin',['action'=>'disable_plan','plan_id'=>$hallPlan,'revision'=>$planRow($hallPlan)['revision']]);qAssert((int)$planRow($hallPlan)['is_active']===0,'Plan disable failed');
    qAssert(qRows('SELECT * FROM maintenance_schedules WHERE preventive_plan_id=? ORDER BY maintenance_id',[$hallPlan])===$historyBefore&&$job($nextHall)['status']==='Scheduled','Disabling a plan deleted or changed generated occurrences');
    qAssert(qDenied(fn()=>gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$hallPlan))&&qDenied(fn()=>gsoDisablePreventivePlan($pdo,$users['admin'],$hallPlan,(int)$planRow($hallPlan)['revision'])),'Disabled plan generated an occurrence or was disabled twice');
    qAssert((int)qVal('SELECT COUNT(*) FROM preventive_maintenance_plans WHERE plan_id=?',[$hallPlan])===1,'Disabled plan record was removed');
    qAssert(gsoPreventiveJobAction($pdo,$nextHall,$users['admin'],'cancel','Plan retired')['ok']&&$job($nextHall)['status']==='Cancelled','Open occurrence of a disabled plan could not be cancelled explicitly');
    $deleted=false;try{qExec('DELETE FROM preventive_maintenance_plans WHERE plan_id=?',[$hallPlan]);}catch(PDOException $e){$deleted=true;}qAssert($deleted,'A plan with maintenance history could be physically deleted');

    // ---- Integrity ---------------------------------------------------------
    qAssert((int)qVal('SELECT COUNT(*) FROM (SELECT preventive_plan_id,plan_occurrence_date FROM maintenance_schedules WHERE preventive_plan_id IS NOT NULL GROUP BY 1,2 HAVING COUNT(*)>1) d')===0,'Duplicate preventive occurrences exist');
    qAssert((int)qVal('SELECT COUNT(*) FROM resources WHERE available_stock<0 OR under_repair_stock<0 OR available_stock+under_repair_stock>total_stock')===0,'Invalid stock after R7 workflow');
    qAssert((int)qVal("SELECT COUNT(*) FROM maintenance_schedules m JOIN equipment_units u ON u.unit_id=m.equipment_unit_id WHERE m.status IN('Scheduled','In Progress') AND (u.status='Lost' OR u.condition_status='Lost')")===0,'An active maintenance row targets a Lost unit');
    qAssert((int)qVal('SELECT COUNT(*) FROM (SELECT unit_id FROM request_item_units WHERE returned_at IS NULL GROUP BY unit_id HAVING COUNT(*)>1) d')===0,'Duplicate open equipment allocations');
    qAssert(qRows('SELECT * FROM resource_requests WHERE (borrower_id IS NULL OR borrower_id NOT IN ('.implode(',',array_fill(0,count($users),'?')).')) AND (encoded_by IS NULL OR encoded_by NOT IN ('.implode(',',array_fill(0,count($users),'?')).')) ORDER BY request_id',[...array_values($users),...array_values($users)])===$legacyRequests,'Historical requests changed');
    qAssert(qRows('SELECT * FROM maintenance_schedules WHERE created_by NOT IN ('.implode(',',array_fill(0,count($users),'?')).') ORDER BY maintenance_id',array_values($users))===$legacyMaintenance,'Historical maintenance rows changed');
    foreach($legacyMaintenance as $old)if($old['incident_id']===null&&$old['source_return_item_id']===null&&$old['source_return_id']===null)qAssert($old['maintenance_type']===null,'An unsourced legacy maintenance row was given a guessed type');
    echo "PASS: R7 preventive maintenance and history E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    if($users){
        $marks=implode(',',array_fill(0,count($users),'?'));$ids=array_values($users);
        qExec('DELETE FROM borrower_liabilities WHERE request_id IN(SELECT request_id FROM resource_requests WHERE encoded_by IN('.$marks.') OR borrower_id IN('.$marks.'))',[...$ids,...$ids]);
        qExec('DELETE FROM maintenance_schedules WHERE created_by IN('.$marks.')',$ids);
        qExec('DELETE FROM preventive_maintenance_plans WHERE created_by IN('.$marks.')',$ids);
    }
    $ids=$walkins;foreach($users as $uid)foreach(qRows('SELECT request_id FROM resource_requests WHERE borrower_id=? OR encoded_by=?',[$uid,$uid]) as $r)$ids[]=(int)$r['request_id'];
    foreach(array_unique($ids) as $id){foreach(qRows('SELECT p.filename FROM return_submission_photos p JOIN return_submissions r ON r.return_id=p.return_id WHERE r.request_id=?',[$id]) as $photo)deleteReturnPhotoFile($photo['filename']);qExec('DELETE FROM request_reschedule_history WHERE request_id=?',[$id]);gsoTestDeleteLetters($pdo,$id);qExec('DELETE FROM return_submissions WHERE request_id=?',[$id]);qExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
    foreach($users as $uid){qExec('DELETE FROM notifications WHERE user_id=?',[$uid]);qExec('DELETE FROM activity_logs WHERE user_id=?',[$uid]);}
    foreach($resources as $id){qExec('DELETE FROM equipment_units WHERE resource_id=?',[$id]);qExec('DELETE FROM resources WHERE resource_id=?',[$id]);}
    foreach($users as $id)qExec('DELETE FROM users WHERE user_id=?',[$id]);foreach($cookies as $file)@unlink($file);
    if(!$failed){
        $after=qRows('SELECT resource_id,total_stock,available_stock,under_repair_stock,status,condition_status FROM resources ORDER BY resource_id');
        if($after!==$legacyResources||(int)qVal('SELECT COUNT(*) FROM equipment_units')!==$legacyUnits){$failed=new RuntimeException('Fixture cleanup left inventory changed.');fwrite(STDERR,"FAIL: Fixture cleanup left inventory changed.\n");}
    }
}
exit($failed?1:0);
