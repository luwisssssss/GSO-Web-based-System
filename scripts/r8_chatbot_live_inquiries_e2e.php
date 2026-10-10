<?php
declare(strict_types=1);
/** R8 real HTTP/authentication and authoritative live-query regression; isolated DB only. */
require_once dirname(__DIR__).'/config/bootstrap.php';
if (!preg_match('/^gso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+$/', (string)gsoEnv('GSO_DB_NAME',''))) throw new RuntimeException('R8 requires an isolated database.');
$base=(string)gsoEnv('GSO_R8_BASE_URL','http://127.0.0.1:8099/GSO_WebSystem');
if (!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true)) throw new RuntimeException('Loopback only.');
define('GSO_CHAT_READ_ONLY',true);
require_once dirname(__DIR__).'/config/db.php';
require_once dirname(__DIR__).'/includes/ai_chat_helper.php';
require_once dirname(__DIR__).'/includes/reschedule_helper.php';
require_once dirname(__DIR__).'/includes/preventive_maintenance_helper.php';
require_once __DIR__.'/request_letter_test_helper.php';
$checks=0;$users=[];$cookies=[];$resources=[];$requests=[];$knowledge=[];$failed=null;
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


$mark='R8-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
function qSnapshot(): array {
    $out=[];
    foreach(['users','resources','resource_requests','request_items','request_reschedule_history','maintenance_schedules','preventive_maintenance_plans','equipment_units','request_item_units','return_submissions','return_submission_items','borrower_liabilities','ai_knowledge_entries','system_settings'] as $table) {
        $rows=qRows('SELECT * FROM `'.$table.'`');$encoded=array_map(fn($r)=>serialize($r),$rows);sort($encoded);$out[$table]=hash('sha256',serialize($encoded));
    }
    return $out;
}
try {
    foreach(['a'=>'Borrower','b'=>'Borrower','admin'=>'Admin','staff'=>'GSO Staff','mis'=>'Super Admin','director'=>'Director'] as $key=>$role) {
        $username=strtolower($mark.'_'.$key);
        qExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified)VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$role==='Borrower'?'University Staff':null]);
        $users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r8_cookie_');
        $page=qHttp($key,'auth/login.php');preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m);$csrf[$key]=$m[1]??'';
        qAssert(qHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed: '.$key);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r8_guest_');
    $resource=function(string $name,string $type='Item',string $mode='Aggregate',int $stock=10)use($mark):int {
        global $pdo,$resources;
        qExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status)VALUES(?,?,?,?,?,'Available','Good')",[$mark.' '.$name,$type,$mode,$stock,$stock]);
        return $resources[]=(int)$pdo->lastInsertId();
    };
    $hall=$resource('Hall','Facility','Facility',1);$chairs=$resource('Chairs',stock:20);$serial=$resource('Laptops','Item','Serialized',5);$paper=$resource('Paper','Item','Consumable',30);
    $amb1=$resource('Panel East');$amb2=$resource('Panel West');$archive=$resource('Hidden');qExec('UPDATE resources SET is_archived=1 WHERE resource_id=?',[$archive]);
    $name=fn(int $id)=>(string)qVal('SELECT resource_name FROM resources WHERE resource_id=?',[$id]);
    $auth=['user_id'=>$users['a'],'role'=>'Borrower'];$date=date('Y-m-d',strtotime('+30 days'));$proposed=date('Y-m-d',strtotime('+31 days'));
    $make=function(int $res,string $status='Approved',string $who='a',string $mode='Registered',int $qty=1)use($users,$date,$hall,$paper):int {
        global $pdo,$requests;
        qExec('INSERT INTO resource_requests(borrower_id,date_needed,start_time,end_time,status,requester_type,encoded_by,walkin_full_name,walkin_contact_number,notes)VALUES(?,?,?,?,?,?,?,?,?,?)',[$mode==='Registered'?$users[$who]:null,$date,'09:00:00','10:00:00',$status,$mode,$mode==='Walk-in'?$users['admin']:null,$mode==='Walk-in'?'PRIVATE-WALKIN-NAME':null,$mode==='Walk-in'?'PRIVATE-PHONE':null,'PRIVATE-STAFF-NOTE']);
        $id=(int)$pdo->lastInsertId();$requests[]=$id;
        qExec('INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity,facility_slot)VALUES(?,?,?,?,?)',[$id,$res,$res===$hall?'Facility':($res===$paper?'Consumable':'Equipment'),$qty,$res===$hall?'AM':null]);return $id;
    };
    $fa=fn(string $day,?string $start='09:00:00',?string $end='10:00:00')=>gsoChatCheckFacilityAvailability($pdo,$auth,$name($hall),['facility_name'=>$name($hall),'date'=>$day,'start_time'=>$start,'end_time'=>$end]);
    $eq=fn(int $id,string $day,int $qty=1)=>gsoChatCheckResourceAvailability($pdo,$auth,$name($id),['resource_name'=>$name($id),'date'=>$day,'quantity'=>$qty]);
    $status=fn(int $id,array $actor=[])=>gsoChatGetRequestStatus($pdo,$actor?:$auth,'REQ-'.$id);
    $f=$fa($date);qAssert($f['facts']['available']===true,'Exact facility interval not free initially');
    qAssert(gsoChatCheckFacilityAvailability($pdo,$auth,$name($hall).' AM')['needs']===['date','start_time','end_time'],'AM invented clock bounds/date');
    qAssert($fa($date,null,null)['needs']===['start_time','end_time'],'Missing exact interval did not clarify');
    qAssert(!$fa($date,'23:00:00','24:00:00')['success'],'Invalid time accepted');
    qAssert(!$fa($date,'00:00:00','01:00:00')['success'],'Configured opening not respected');
    $hours=gsoFacilityOperatingHours($pdo);$late=$fa($date,$hours['start'],'23:59:00');qAssert($late['facts']['extended_hours_required']&&str_contains($late['message'],'GSO review')&&!str_contains($late['message'],'Director'),'Extended hours not GSO controlled');
    $reservation=$make($hall);
    foreach(['Approved','Released','Return'] as $state){qExec('UPDATE resource_requests SET status=? WHERE request_id=?',[$state,$reservation]);qAssert(!$fa($date)['facts']['available'],$state.' failed to block');qAssert($fa($date,'10:00:00','11:00:00')['facts']['available'],'Same AM nonoverlap blocked for '.$state);}
    foreach(['Pending','Under Review','Rejected','Cancelled','Returned','Completed'] as $state){qExec('UPDATE resource_requests SET status=? WHERE request_id=?',[$state,$reservation]);qAssert($fa($date)['facts']['available'],$state.' falsely blocked');}
    qExec("UPDATE resource_requests SET status='Approved' WHERE request_id=?",[$reservation]);
    gsoTestVerifiedLetter($pdo,$reservation,$users['admin']);
    $proposal=gsoCreateReschedule($pdo,$users['a'],$reservation,['date_needed'=>$proposed,'start_time'=>'09:00','end_time'=>'10:00','facility_slot'=>'AM','reason'=>'PRIVATE-RESCHEDULE-REASON']);
    $attempt=(int)qVal('SELECT reschedule_id FROM request_reschedule_history WHERE request_id=?',[$reservation]);
    qAssert($fa($proposed)['facts']['available'],'Pending reschedule blocked proposed interval');
    $owned=$status($reservation);qAssert($owned['facts']['date_needed']===$date&&!str_contains($owned['message'],$proposed),'Pending proposal presented as current');
    gsoReviewReschedule($pdo,$users['admin'],$reservation,$attempt,'approve',['review_remarks'=>'PRIVATE-REVIEW']);
    qAssert($fa($date)['facts']['available']&&!$fa($proposed)['facts']['available'],'Approval did not move authoritative interval');
    qAssert($status($reservation)['facts']['date_needed']===$proposed,'Approved reschedule not reflected in status');
    $walk=$make($hall,mode:'Walk-in');qAssert(!$fa($date)['facts']['available'],'Walk-in confirmed reservation did not block');
    $job=function(int $res,string $type,string $day,int $qty=0,?int $unit=null,string $state='Scheduled')use($users):int{
        global $pdo;
        if ($type === 'Preventive') {
            $plan=gsoSavePreventivePlan($pdo,$users['admin'],['resource_id'=>$res,'equipment_unit_id'=>$unit??0,'quantity'=>$qty,'title'=>'PRIVATE-WORK-NOTE','recurrence'=>'Monthly','next_due_date'=>$day,'duration_days'=>1,'assigned_to'=>0]);
            return gsoGeneratePreventiveOccurrence($pdo,$users['admin'],$plan);
        }
        qExec('INSERT INTO maintenance_schedules(resource_id,maintenance_type,quantity,equipment_unit_id,start_date,end_date,reason,remarks,status,created_by)VALUES(?,?,?,?,?,?,?,?,?,?)',[$res,$type,$qty,$unit,$day,$day,'PRIVATE-WORK-NOTE','PRIVATE-WORK-REMARKS',$state,$users['admin']]);return (int)$pdo->lastInsertId();
    };
    $maintDate=date('Y-m-d',strtotime('+32 days'));$job($hall,'Preventive',$maintDate);qAssert(!$fa($maintDate)['facts']['available'],'Facility preventive date did not block');
    qExec('UPDATE resources SET available_stock=17,under_repair_stock=3,condition_status=? WHERE resource_id=?',['Damaged',$chairs]);
    $r=$eq($chairs,$date,10);qAssert($r['facts']['available_quantity']===17&&$r['facts']['available'],'Partial corrective stock unavailable');qAssert(!str_contains($r['message'],'Lost'),'Damaged described as Lost');
    $job($chairs,'Preventive',$maintDate,2);$r=$eq($chairs,$maintDate,10);qAssert($r['facts']['available_quantity']===15&&$r['facts']['available'],'Partial aggregate preventive incorrect');qAssert(!$eq($chairs,$maintDate,16)['facts']['available'],'Excess quantity allowed');
    $stockRequest=$make($chairs,qty:4);qAssert($eq($chairs,$date,10)['facts']['available_quantity']===13,'Confirmed equipment quantity not subtracted');qExec("UPDATE resource_requests SET status='Pending' WHERE request_id=?",[$stockRequest]);qAssert($eq($chairs,$date)['facts']['available_quantity']===13,'Existing equipment scheduling priority diverged from shared helper');
    $units=[];for($i=0;$i<5;$i++){qExec('INSERT INTO equipment_units(resource_id,asset_tag,serial_number,status,condition_status)VALUES(?,?,?,?,?)',[$serial,$mark.'-PRIVATE-TAG-'.$i,$mark.'-PRIVATE-SERIAL-'.$i,$i===4?'Lost':($i===3?'Under Repair':'Available'),$i===4?'Lost':($i===3?'Damaged':'Good')]);$units[]=(int)$pdo->lastInsertId();}
    qExec('UPDATE resources SET total_stock=4,available_stock=3,under_repair_stock=1 WHERE resource_id=?',[$serial]);
    $job($serial,'Preventive',$maintDate,1,$units[0]);$r=$eq($serial,$maintDate,2);qAssert($r['facts']['available_quantity']===2&&$r['facts']['available'],'Serialized partial preventive count incorrect');
    qAssert(!preg_match('/PRIVATE-(?:TAG|SERIAL)|unit_id|asset_tag|serial_number/',json_encode($r)),'Serialized identity leakage');
    qAssert($eq($serial,$date)['facts']['available_quantity']===3,'Lost/repair unit counted as available');
    qExec('UPDATE resources SET total_stock=99,available_stock=99 WHERE resource_id=?',[$serial]);qAssert($eq($serial,$date)['facts']['available_quantity']===3,'Stale counters overrode physical unit count');qExec('UPDATE resources SET total_stock=4,available_stock=3 WHERE resource_id=?',[$serial]);
    $maint=gsoChatGetMaintenanceStatus($pdo,$auth,$name($chairs));qAssert(str_contains($maint['message'],'Partial maintenance')&&!str_contains(json_encode($maint),'PRIVATE-'),'Partial maintenance or privacy incorrect');
    $adminMaint=gsoChatGetMaintenanceStatus($pdo,['user_id'=>$users['admin'],'role'=>'Admin'],$name($chairs));qAssert(!str_contains(json_encode($adminMaint),'PRIVATE-'),'Private maintenance notes leaked through admin chatbot');
    foreach(['Pending','Under Review','Approved','Released','Return','Returned','Completed','Rejected','Cancelled'] as $state){$id=$make($state==='Completed'?$paper:$chairs,$state);$r=$status($id);qAssert($r['success']&&$r['facts']['status']===$state,'Own status failed: '.$state);qAssert(!str_contains(json_encode($r),'PRIVATE-'),'Private request data leaked: '.$state);if($state==='Return')qAssert(str_contains($r['message'],'awaiting GSO inspection')&&!str_contains($r['message'],'restored'),'Return prematurely accepted');if($state==='Returned')qAssert(str_contains($r['message'],'inspection was accepted'),'Returned wording wrong');if($state==='Completed')qAssert(str_contains($r['message'],'consumables do not require return'),'Consumable Completed wording wrong');}
    $other=$make($chairs,who:'b');$denied=$status($other);qAssert(!$denied['success']&&$denied['facts']===['found'=>false],'Other borrower lookup leaked');qAssert(!$status($walk)['success'],'Walk-in lookup leaked');
    qAssert(!$status($other,['user_id'=>$users['a'],'role'=>'Admin'])['success'],'Role forged into privileged query');
    foreach(['staff','mis','director'] as $key)qAssert(!$status($other,['user_id'=>$users[$key],'role'=>'Admin'])['success'],'Broader visibility granted to '.$key);
    qAssert(!$status($other,['user_id'=>0,'role'=>'Admin'])['success'],'Anonymous helper lookup allowed');
    $list=gsoChatListOwnRequests($pdo,$auth);qAssert($list['facts']['open_requests']<=5&&!str_contains($list['message'],'PRIVATE-')&&!str_contains($list['message'],formatRequestCode($other)),'Own request list leaked');
    qAssert(gsoChatCheckResourceAvailability($pdo,$auth,'Availability of nonexistent quantum teleporter')['facts']['matched']===0,'Unknown resource guessed');
    qAssert(gsoChatCheckResourceAvailability($pdo,$auth,$mark.' Panel')['facts']['matched']===2,'Ambiguous resource guessed');
    qAssert(gsoChatCheckResourceAvailability($pdo,$auth,'Hidden')['facts']['matched']===0,'Archived catalog exposed');
    foreach(['Available ba ang '.$name($serial),'May available bang 3 '.$name($serial).' sa '.$date,'Ilan '.$name($chairs).' available?'] as $msg)qAssert(gsoChatRefineLiveIntent($pdo,$msg,gsoClassifyChatIntent($msg))['live'],'Availability routed to static knowledge');
    qAssert(gsoChatExtractDate('February 30 2027')===null,'Invalid named date rolled over');qAssert(gsoChatExtractDate('2027-02-30')===null,'Invalid ISO date accepted');qAssert(gsoChatExtractTimeRange('13 am to 14 am')['start']===null,'Impossible AM clock accepted');qAssert(gsoChatExtractQuantity('3 laptops sa October 15')===3,'Quantity/date extraction wrong');qAssert(gsoChatExtractQuantity('May available bang 3 laptops sa October 15')===3,'Tagalog quantity missing');qAssert(gsoChatExtractQuantity('qty 1.5')===0&&gsoChatExtractQuantity('qty -1')===0,'Invalid quantities truncated');qAssert(gsoChatExtractQuantity('October 15')===null,'Date guessed as quantity');
    qAssert(str_contains(gsoChatKnownWorkflowGuidance([['title'=>'How to return borrowed items','content'=>'Stock restored on submission']]),'awaiting GSO inspection'),'Legacy seeded content bypassed compatibility');
    qAssert(gsoChatKnownWorkflowGuidance([['title'=>'Custom user procedure','content'=>'Keep this']])===null,'Unrelated edited knowledge replaced');
    qAssert(gsoChatCheckResourceAvailability($pdo,$auth,$name($chairs).' on 02/30/2027')['needs']===['date'],'Invalid slash date became today');
    qAssert(gsoChatCheckResourceAvailability($pdo,$auth,$name($chairs).' qty -1')['needs']===['quantity'],'Negative quantity accepted');
    qAssert(gsoChatExtractQuantity('available 15 October')===null,'Named date guessed as quantity');
    qAssert(gsoChatExtractQuantity('May available ba 3 amplifiers')===3,'Generic resource quantity not parsed');
    qAssert(gsoChatWorkflowGuidance('faq_return','Official penalty fee in pesos?')===null,'General guidance invented specific policy');
    $q=gsoChatMergePendingParams(['resource_name'=>$name($serial)],'2');qAssert($q['quantity']===2,'Quantity followup not merged');
    $f=gsoChatMergePendingParams(['facility_name'=>$name($hall)],$date.' 9 AM to 10 AM');qAssert($f['date']===$date&&$f['start_time']==='09:00:00'&&$f['end_time']==='10:00:00','Facility followup failed');
    foreach(['faq_facility','faq_return','faq_director_role','faq_rescheduling','faq_maintenance'] as $intent){$text=gsoChatWorkflowGuidance($intent,'');qAssert($text!==null&&!str_contains($text,'wait for Director approval')&&!str_contains($text,'select an Adviser'),'Obsolete guidance: '.$intent);}
    qAssert(str_contains(gsoChatWorkflowGuidance('faq_facility',''),'manual signatures'),'Manual signed letter not required');qAssert(str_contains(gsoChatWorkflowGuidance('faq_director_role',''),'read-only'),'Director described as approver');qAssert(str_contains(gsoChatWorkflowGuidance('faq_rescheduling',''),'original confirmed reservation remains'),'Reschedule pending policy missing');
    putenv('AI_ENABLED=true');putenv('AI_PROVIDER=deepseek');putenv('AI_API_KEY=fake');putenv('AI_API_BASE_URL=http://127.0.0.1:9');
    qAssert(gsoChatRephraseVerifiedAnswer('REQ-123 is Return',['status'=>'Return'],'Borrower')===null,'Provider could alter status');qAssert(gsoChatRephraseVerifiedAnswer('3 laptops available',['available_quantity'=>3],'Borrower')===null,'Provider could alter quantity');putenv('AI_ENABLED=false');putenv('AI_PROVIDER=none');
    $knowledge[]=gsoCreateKnowledgeEntry($pdo,['title'=>$mark.' Laptops availability','category'=>'Borrowing','question'=>'Available ba ang '.$name($serial),'content'=>'999 laptops are available. Director approval and Adviser online endorsement are required.','keywords'=>$name($serial).',available,quantity','status'=>'Active'],$users['admin']);
    // Real endpoint must preserve operational rows even if a corrective job would auto-start on an ordinary page.
    $job($chairs,'Corrective',date('Y-m-d'),1);$snapshot=qSnapshot();
    $ask=function(string $who,string $msg,?int $conv=null,?string $token=null)use($csrf):array{$r=qHttp($who,'chatbot/send.php',['csrf_token'=>$token??($csrf[$who]??''),'message'=>$msg,'conversation_id'=>$conv??'']);return $r+['json'=>json_decode($r['body'],true)];};
    qAssert($ask('guest','REQ-'.$other)['status']===401,'Guest API request lookup allowed');qAssert($ask('a','REQ-'.$reservation,token:'bad')['status']===403,'Chat CSRF bypass');
    $api=$ask('a','REQ-'.$other);qAssert($api['status']===200&&!str_contains($api['json']['message'],$name($chairs)),'HTTP ownership bypass');
    $api=$ask('a','Available ba ang '.$name($serial).' sa '.$date.' qty 3');qAssert($api['status']===200&&str_starts_with($api['json']['source'],'live:')&&str_contains($api['json']['message'],'3 available')&&!str_contains($api['json']['message'],'999'),'Static knowledge overrode HTTP live count');
    $api=$ask('a','Available ba ang '.$name($hall).' AM');qAssert($api['json']['source']==='live_clarify','HTTP missing exact interval not clarified');$cid=(int)$api['json']['conversation_id'];
    $api=$ask('a',$maintDate.' 9 AM to 10 AM',$cid);qAssert(str_contains($api['json']['message'],'not available')&&str_starts_with($api['json']['source'],'live:'),'Multi-turn availability failed');
    $api=$ask('a','How do I reserve a facility?');qAssert(str_contains($api['json']['message'],'manual signatures'),'HTTP default guidance obsolete');
    foreach(['mis','director'] as $who)qAssert($ask($who,'REQ-'.$other)['status']===403,'Chat permission expanded to '.$who);
    qAssert(qSnapshot()===$snapshot,'Chatbot changed operational rows or production knowledge');
    echo "PASS: R8 chatbot live inquiries E2E ({$checks} checks).\n";
} catch(Throwable $e){$failed=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally {
    foreach($knowledge as $id)qExec('DELETE FROM ai_knowledge_entries WHERE entry_id=?',[$id]);
    foreach($users as $uid){qExec('DELETE FROM ai_messages WHERE conversation_id IN(SELECT conversation_id FROM ai_conversations WHERE user_id=?)',[$uid]);qExec('DELETE FROM ai_conversations WHERE user_id=?',[$uid]);qExec('DELETE FROM maintenance_schedules WHERE created_by=?',[$uid]);qExec('DELETE FROM preventive_maintenance_plans WHERE created_by=?',[$uid]);}
    foreach($requests as $id){qExec('DELETE FROM request_reschedule_history WHERE request_id=?',[$id]);gsoTestDeleteLetters($pdo,$id);qExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
    foreach($users as $uid){qExec('DELETE FROM notifications WHERE user_id=?',[$uid]);qExec('DELETE FROM activity_logs WHERE user_id=?',[$uid]);}
    foreach($resources as $id){qExec('DELETE FROM equipment_units WHERE resource_id=?',[$id]);qExec('DELETE FROM resources WHERE resource_id=?',[$id]);}
    foreach($users as $id)qExec('DELETE FROM users WHERE user_id=?',[$id]);foreach($cookies as $file)@unlink($file);
}
exit($failed?1:0);
