<?php
require __DIR__.'/common.php';
require __DIR__.'/c2a_http_semantics.php';
if(!str_starts_with(loadDatabaseName(),'gso_load_test_phase_c2a_'))throw new RuntimeException('C2A database required.');
$dir=realpath((string)getenv('GSO_LOAD_EVIDENCE'));$m=json_decode(file_get_contents($dir.'/manifest.private.json'),true,512,JSON_THROW_ON_ERROR);
if($m['database']!==loadDatabaseName())throw new RuntimeException('Manifest mismatch.');
$pdo=loadConnection();$base=(string)getenv('GSO_LOAD_URL');
if(!preg_match('#\Ahttp://127\.0\.0\.1:\d+/GSO_WebSystem\z#',$base))throw new RuntimeException('Loopback URL required.');
$results=is_file($dir.'/c2a_results.private.json')?json_decode(file_get_contents($dir.'/c2a_results.private.json'),true):[];
foreach($results as $prior)if(empty($prior['passed']))throw new RuntimeException('Unpassed race already recorded: do not resume beyond an unresolved race defect.');
$stockBases=is_file($dir.'/c2a_stock_bases.json')?json_decode(file_get_contents($dir.'/c2a_stock_bases.json'),true):[];$sessions=[];$sequence=0;
foreach(glob($dir.'/http_setup_*.private.json') as $file)if(preg_match('/http_setup_(\d+)\./',basename($file),$match))$sequence=max($sequence,(int)$match[1]);
function cSave(string $file,mixed $value):void {global $dir;file_put_contents($dir.'/'.$file,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
function cRows(string $sql,array $args=[]):array {global $pdo;$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll();}
function cAssert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
function cHandle(string $who,string $path,?array $data,string $tag):CurlHandle {
    global $dir,$base,$sessions;$c=curl_init($base.'/'.$path);
    $headers=['User-Agent: GSO-C2A-Correctness/1','X-GSO-C2A-Contender: '.$tag];
    curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30,CURLOPT_COOKIEFILE=>$dir.'/cookie_'.$who.'.private.txt',CURLOPT_COOKIEJAR=>$dir.'/cookie_'.$who.'.private.txt',CURLOPT_HTTPHEADER=>$headers]);
    if($data!==null){$data['csrf_token']=$sessions[$who]['csrf']??'';curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($data)]);}
    return $c;
}
function cResponse(CurlHandle $c,string $raw,string $tag,float $start,float $end):array {
    $info=curl_getinfo($c);$body=substr($raw,$info['header_size']);$header=substr($raw,0,$info['header_size']);
    $r=['tag'=>$tag,'start_wall_seconds'=>$start,'completion_wall_seconds'=>$end,'status'=>$info['http_code'],'total_seconds'=>$info['total_time'],'connect_seconds'=>$info['connect_time'],'first_byte_seconds'=>$info['starttransfer_time'],'headers'=>$header,'body'=>$body,'body_sha256'=>hash('sha256',$body)];
    cSave('http_'.$tag.'.private.json',$r);
    if(str_starts_with($tag,'setup_')){
        cAssert($r['status']<500&&$r['status']>0,'Unhandled HTTP/server failure: '.$tag);
        cAssert(!preg_match('/<b>\s*(Warning|Fatal error|Notice|Deprecated)\s*<\/b>|GSOERR-|Uncaught (PDOException|Error|Exception)/i',$body),'PHP/error marker: '.$tag);
    }
    return $r;
}
function cHttp(string $who,string $path,?array $data=null):array {
    global $sequence;$tag='setup_'.(++$sequence);$c=cHandle($who,$path,$data,$tag);$start=microtime(true);$raw=curl_exec($c);$end=microtime(true);cAssert(is_string($raw),'HTTP transport failed: '.curl_error($c));$r=cResponse($c,$raw,$tag,$start,$end);curl_close($c);return $r;
}
function cLogin(string $who,array $user):void {
    global $sessions,$m;$path=$user['role']==='Borrower'?'borrower/request_resource.php?resource_id='.$m['resources']['Aggregate'][0]:'admin/requests.php';
    $form=cHttp($who,$path);preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$form['body'],$match);
    if($form['status']===200&&!empty($match[1])){$sessions[$who]=['csrf'=>$match[1],'user_id'=>$user['user_id']];return;}
    $form=cHttp($who,'auth/login.php');preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$form['body'],$match);cAssert(!empty($match[1]),'Missing login CSRF');$sessions[$who]=['csrf'=>$match[1]];
    $r=cHttp($who,'auth/login.php',['login_input'=>$user['username'],'password'=>$user['password']]);cAssert($r['status']===302,'Login rejected');
    $path=$user['role']==='Borrower'?'borrower/my_requests.php':'admin/requests.php';$form=cHttp($who,$path);
    preg_match('/name="csrf_token"[^>]*value="([^"]+)"/',$form['body'],$match);cAssert(!empty($match[1]),'Missing authenticated CSRF');$sessions[$who]=['csrf'=>$match[1],'user_id'=>$user['user_id']];
}
function cResource(string $label,string $mode,int $stock):int {
    global $pdo,$stockBases,$m;loadExec($pdo,"INSERT INTO resources(resource_name,resource_type,tracking_mode,category,description,location,total_stock,available_stock,status,condition_status) VALUES(?,?,?,?,?,?,?,?,'Available','Good')",['C2A '.$label,$mode==='Facility'?'Facility':'Item',$mode,'C2A Synthetic','Synthetic correctness fixture only','Synthetic Campus',$stock,$stock]);$id=(int)$pdo->lastInsertId();$stockBases[$id]=$stock;cSave('c2a_stock_bases.json',$stockBases);
    if($mode==='Serialized')for($i=0;$i<$stock;$i++)loadExec($pdo,"INSERT INTO equipment_units(resource_id,asset_tag,serial_number,status,condition_status,registered_by) VALUES(?,?,?,'Available','Good',?)",[$id,'C2A-'.$id.'-'.$i,'C2A-SYNTHETIC-'.$id.'-'.$i,$m['users'][12]['user_id']]);
    return $id;
}
function cRequest(array $lines,int $day=1):int {
    global $pdo,$m,$dir;$date=date('Y-m-d',strtotime('+'.$day.' days'));loadExec($pdo,"INSERT INTO resource_requests(borrower_id,contact_number,date_needed,start_time,end_time,request_date,status,notes) VALUES(?,?,?,'09:00:00','10:00:00',NOW(),'Pending','Synthetic C2A fixture')",[$m['users'][0]['user_id'],'09000000000',$date]);$id=(int)$pdo->lastInsertId();
    foreach($lines as [$resource,$qty]){$r=cRows('SELECT tracking_mode FROM resources WHERE resource_id=?',[$resource])[0];$kind=match($r['tracking_mode']){'Facility'=>'Facility','Consumable'=>'Consumable',default=>'Equipment'};
        loadExec($pdo,'INSERT INTO request_items(request_id,resource_id,line_kind,facility_slot,requested_quantity) VALUES(?,?,?,?,?)',[$id,$resource,$kind,$kind==='Facility'?'AM':null,$qty]);
        if($kind==='Facility'){$name='letter_'.bin2hex(random_bytes(16)).'.png';$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC2AHAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');file_put_contents($dir.'/request_letters/'.$name,$png);loadExec($pdo,"INSERT INTO request_attachments(request_id,uploaded_by,version_no,original_file_name,stored_file_name,mime_type,file_size,verification_status,verified_by,verified_at) VALUES(?,?,1,'synthetic-signed-letter.png',?,'image/png',?,'Verified',?,NOW())",[$id,$m['users'][0]['user_id'],$name,strlen($png),$m['users'][12]['user_id']]);}
    }return $id;
}
function cApprove(int $id):void {cHttp('gso_a','admin/requests.php',['request_id'=>$id,'action'=>'approve']);cAssert(cRows('SELECT status FROM resource_requests WHERE request_id=?',[$id])[0]['status']==='Approved','Fixture normal approval failed: '.$id);}
function cReleaseData(int $id,bool $valid=true):array {
    $row=cRows('SELECT claim_slip_code FROM resource_requests WHERE request_id=?',[$id])[0];$p=['request_id'=>$id,'action'=>'release','claim_slip_code'=>$valid?$row['claim_slip_code']:'GSO-22222222'];
    foreach(cRows("SELECT i.request_item_id,i.requested_quantity,u.unit_id FROM request_items i JOIN resources r ON r.resource_id=i.resource_id JOIN equipment_units u ON u.resource_id=r.resource_id WHERE i.request_id=? AND r.tracking_mode='Serialized' ORDER BY u.unit_id",[$id]) as $u)if(count($p['unit_ids'][$u['request_item_id']]??[])<(int)$u['requested_quantity'])$p['unit_ids'][$u['request_item_id']][]=$u['unit_id'];return $p;
}
function cRelease(int $id):void {cHttp('gso_a','admin/requests.php',cReleaseData($id));cAssert(cRows('SELECT status FROM resource_requests WHERE request_id=?',[$id])[0]['status']==='Released','Fixture normal release failed: '.$id);}
function cReturnData(int $id):array {
    $p=['condition_notes'=>'Synthetic C2A Good return'];foreach(cRows("SELECT i.*,r.tracking_mode FROM request_items i JOIN resources r ON r.resource_id=i.resource_id WHERE i.request_id=? AND i.line_kind<>'Consumable'",[$id]) as $line){$key=$line['request_item_id'];if($line['tracking_mode']==='Serialized'){foreach(cRows('SELECT unit_id FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL',[$key]) as $u)$p['lines'][$key]['units'][$u['unit_id']]=['condition'=>'Good'];}else$p['lines'][$key]=['condition'=>'Good','returned_quantity'=>$line['released_quantity'],'lost_quantity'=>0];}return $p;
}
function cSubmit(int $id):void {cHttp('borrower_a','borrower/return_submit.php?request_id='.$id,cReturnData($id));cAssert(cRows('SELECT status FROM resource_requests WHERE request_id=?',[$id])[0]['status']==='Return','Fixture normal return submission failed');}
function cInspection(int $id):array {$p=['action'=>'approve','inspection_condition'=>'Good','inspection_remarks'=>'Synthetic C2A Good acceptance'];foreach(cRows('SELECT d.return_item_id FROM return_submission_items d JOIN return_submissions s ON s.return_id=d.return_id WHERE s.request_id=?',[$id]) as $r)$p['inspection'][$r['return_item_id']]=['condition'=>'Good','remarks'=>'Synthetic C2A'];return $p;}
function cSnapshot(array $ids):array {
    global $pdo,$dir,$c2Resources;$in=$ids?implode(',',array_map('intval',$ids)):'0';$resources=array_values(array_unique(array_merge($c2Resources??[],array_column(cRows('SELECT DISTINCT resource_id FROM request_items WHERE request_id IN ('.$in.')'),'resource_id'))));$res=$resources?implode(',',$resources):'0';
    $tables=['requests'=>cRows('SELECT * FROM resource_requests WHERE request_id IN ('.$in.') ORDER BY request_id'),'items'=>cRows('SELECT * FROM request_items WHERE request_id IN ('.$in.') ORDER BY request_item_id'),'resources'=>cRows('SELECT * FROM resources WHERE resource_id IN ('.$res.') ORDER BY resource_id'),'units'=>cRows('SELECT * FROM equipment_units WHERE resource_id IN ('.$res.') ORDER BY unit_id'),'allocations'=>cRows('SELECT a.* FROM request_item_units a JOIN request_items i ON i.request_item_id=a.request_item_id WHERE i.resource_id IN ('.$res.') ORDER BY a.allocation_id'),'return_headers'=>cRows('SELECT * FROM return_submissions WHERE request_id IN ('.$in.') ORDER BY return_id'),'return_details'=>cRows('SELECT d.* FROM return_submission_items d JOIN return_submissions s ON s.return_id=d.return_id WHERE s.request_id IN ('.$in.') ORDER BY d.return_item_id'),'notifications'=>cRows('SELECT * FROM notifications ORDER BY notification_id'),'activity'=>cRows('SELECT * FROM activity_logs ORDER BY log_id')];
    $tables['reschedules']=cRows('SELECT * FROM request_reschedule_history WHERE request_id IN ('.$in.') ORDER BY reschedule_id');
    $tables['maintenance']=cRows('SELECT * FROM maintenance_schedules WHERE resource_id IN ('.$res.') ORDER BY maintenance_id');
    $tables['plans']=cRows('SELECT * FROM preventive_maintenance_plans WHERE resource_id IN ('.$res.') ORDER BY plan_id');
    $tables['liabilities']=cRows('SELECT * FROM borrower_liabilities WHERE request_id IN ('.$in.') ORDER BY liability_id');
    $hashes=[];foreach($tables as $name=>$rows)$hashes[$name]=hash('sha256',json_encode($rows));$mail=[];foreach(glob($dir.'/mail_outbox/*.html') as $path)$mail[basename($path)]=hash_file('sha256',$path);
    return ['captured_at'=>microtime(true),'request_ids'=>$ids,'resource_ids'=>$resources,'tables'=>$tables,'hashes'=>$hashes,'outbox_hashes'=>$mail];
}
function cLogs(string $label):array {
    global $dir;$out=[];foreach(['php_error.log','apache_error.log'] as $file){$text=is_file($dir.'/'.$file)?file_get_contents($dir.'/'.$file):'';$out[$file]=$text;}
    $meta=json_decode(file_get_contents($dir.'/run_metadata.json'),true);$fp=fopen($meta['mariadb_log'],'r');fseek($fp,$meta['mariadb_log_offset']);$out['mariadb_delta']=stream_get_contents($fp);fclose($fp);cSave('logs_'.$label.'.private.json',$out);
    cAssert(trim($out['php_error.log'])==='','STOP: PHP/application log entries after '.$label);
    // A prior owned-process stop can leave a PID-file startup warning on Windows.
    // Preserve that exact known warning; it is not an application request error.
    $apacheRequestErrors=preg_replace('/^.*AH00098: pid file .*Unclean shutdown of previous Apache run\?.*\R?/m','',$out['apache_error.log']);
    cAssert(!preg_match('/\[[^\]]*:(?:warn|error|crit|alert|emerg)\]/i',$apacheRequestErrors),'STOP: Apache errors after '.$label);
    cAssert(!preg_match('/fatal|uncaught|SQLSTATE|deadlock|lock wait timeout|too many connections|memory exhausted|\[ERROR\]/i',$out['mariadb_delta']),'STOP: MariaDB error after '.$label);return ['php'=>'clean','apache'=>'clean','mariadb'=>'clean','deadlocks_or_timeouts'=>0];
}
function cAudit(string $label):array {
    global $dir;putenv('GSO_LOAD_STAGE='.$label);passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/c2a_integrity.php'),$exit);$r=json_decode(file_get_contents($dir.'/integrity_'.$label.'.json'),true);cAssert($exit===0&&$r['passed'],'STOP: integrity violation after '.$label);return $r;
}
function cRace(string $label,array $ids,array $calls,callable $verify):void {
    global $dir,$results;$before=cSnapshot($ids);cSave($label.'_before.private.json',$before);$multi=curl_multi_init();$handles=[];
    foreach($calls as $index=>[$who,$path,$payload]){$tag=$label.'_'.chr(65+$index);$c=cHandle($who,$path,$payload,$tag);curl_multi_add_handle($multi,$c);$handles[]=[$c,$tag,$who,$path];}
    // Barrier: all handles are prepared first; no transfer starts before release.
    $ready=microtime(true);$releaseAt=$ready+.050;while(microtime(true)<$releaseAt)usleep(200);$released=microtime(true);$completions=[];
    do{$code=curl_multi_exec($multi,$running);while($info=curl_multi_info_read($multi))$completions[spl_object_id($info['handle'])]=microtime(true);if($running)curl_multi_select($multi,.01);}while($running&&$code===CURLM_OK);
    $responses=[];foreach($handles as [$c,$tag,$who,$path]){$raw=curl_multi_getcontent($c);$responses[]=cResponse($c,$raw,$tag,$released,$completions[spl_object_id($c)]??microtime(true));curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);
    cSave($label.'_responses.private.json',$responses);$after=cSnapshot($ids);cSave($label.'_after.private.json',$after);
    $timings=[];foreach(file($dir.'/access.log',FILE_IGNORE_NEW_LINES) as $line)if(preg_match('/^(\d+) (\d+) (\d+) (\d+) "([^"]*)" "('.preg_quote($label,'/').'_[AB])"$/',$line,$match))$timings[$match[6]]=['start_us'=>(int)$match[1],'end_us'=>(int)$match[2],'status'=>(int)$match[3],'duration_us'=>(int)$match[4],'request'=>$match[5]];
    $pair=array_values($timings);$overlap=count($pair)===2&&max($pair[0]['start_us'],$pair[1]['start_us'])<min($pair[0]['end_us'],$pair[1]['end_us']);$gap=count($pair)===2?abs($pair[0]['start_us']-$pair[1]['start_us']):null;
    $record=['label'=>$label,'request_ids'=>$ids,'barrier_ready'=>$ready,'barrier_released'=>$released,'server_timings'=>$timings,'server_start_gap_us'=>$gap,'server_intervals_overlap'=>$overlap,'http_statuses'=>array_column($responses,'status'),'passed'=>false];
    $results[]=$record;cSave('c2a_results.private.json',$results);
    $audit=cAudit($label);$logs=cLogs($label);cAssert($overlap,'Harness synchronization did not produce overlapping server requests: '.$label);
    foreach($responses as $response){cAssert(c2aExpectedContenderResponse($response,$label),'Unexpected contender status: '.$label);cAssert(!str_contains($response['body'],'GSOERR-'),'Unhandled contender error: '.$label);}
    $outcome=$verify($before,$after,$responses);$record+=['outcome'=>$outcome,'integrity_passed'=>$audit['passed'],'logs'=>$logs];$record['passed']=true;$results[count($results)-1]=$record;cSave('c2a_results.private.json',$results);echo 'RACE PASS '.$label.' gap_us='.$gap.PHP_EOL;
}
function cEvents(int $id,string $event):int {return count(cRows("SELECT * FROM activity_logs WHERE action='Client Event Recorded' AND details LIKE ?",['REQ-'.str_pad((string)$id,3,'0',STR_PAD_LEFT).' '.$event.' R9 %']));}
function cSettled(array $ids,int $usage,string $mode,int $stock):array {
    $in=implode(',',$ids);$requests=cRows('SELECT request_id,status FROM resource_requests WHERE request_id IN ('.$in.')');$released=array_values(array_filter($requests,fn($r)=>$r['status']==='Released'));cAssert(count($released)===1,'Expected exactly one Released winner');
    $items=cRows('SELECT * FROM request_items WHERE request_id IN ('.$in.')');$sum=array_sum(array_column($items,'released_quantity'));cAssert($sum===$usage,'Incorrect released quantity');$resource=cRows('SELECT r.* FROM resources r JOIN request_items i ON i.resource_id=r.resource_id WHERE i.request_id=?',[$ids[0]])[0];cAssert((int)$resource['available_stock']===$stock-$usage,'Incorrect physical available stock');
    foreach($requests as $r)if($r['status']!=='Released')cAssert($r['status']==='Approved','Losing release changed request status');
    if($mode==='Serialized')cAssert(count(cRows('SELECT a.* FROM request_item_units a JOIN equipment_units u ON u.unit_id=a.unit_id WHERE u.resource_id=? AND a.returned_at IS NULL',[$resource['resource_id']]))===1,'Serialized collision');return ['statuses'=>$requests,'available_stock'=>(int)$resource['available_stock'],'committed_usage'=>$usage];
}
function cMixedVerify(int $id,array $before,array $after):array {
    cAssert(cRows('SELECT status FROM resource_requests WHERE request_id=?',[$id])[0]['status']==='Released','Mixed release not settled');$rows=cRows('SELECT i.*,r.tracking_mode,r.total_stock,r.available_stock FROM request_items i JOIN resources r ON r.resource_id=i.resource_id WHERE request_id=?',[$id]);
    foreach($rows as $r){cAssert((int)$r['released_quantity']===(int)$r['requested_quantity'],'Partial mixed release');$mode=$r['tracking_mode'];cAssert((int)$r['available_stock']===($mode==='Serialized'?0:3),'Mixed stock was not deducted exactly once');if($mode==='Consumable')cAssert((int)$r['total_stock']===3&&(int)$r['issued_quantity']===2,'Consumable double deduction');}
    cAssert(cEvents($id,'released')===1,'Duplicate release client event');$added=array_diff_key($after['outbox_hashes'],$before['outbox_hashes']);cAssert(count($added)===1,'Release email not captured exactly once');
    $releaseLogs=cRows("SELECT * FROM activity_logs WHERE action='Request Released' AND details LIKE ?",['Admin released request #'.$id.' for:%']);cAssert(count($releaseLogs)===1,'Duplicate business release activity');return ['status'=>'Released','aggregate_available'=>3,'consumable_total_and_available'=>3,'serialized_active_allocations'=>1,'release_events'=>1,'captured_release_emails'=>1];
}
