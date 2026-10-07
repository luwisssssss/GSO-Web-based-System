<?php
declare(strict_types=1);
/**
 * Phase 3.5B end-to-end suite: mixed equipment tracking, complete release,
 * consumable issuance and per-resource return inspection.
 *
 * Every fixture carries this run's marker, is created here, and is swept in the
 * finally block. Fixture identity is proven by BOTH the marked resource and the
 * marked borrower before anything is deleted, so a real request that merely
 * shares a resource is never touched.
 */
$root=dirname(__DIR__);require_once $root."/config/bootstrap.php";require_once $root."/config/db.php";
require_once $root."/includes/request_helper.php";require_once $root."/includes/request_line_helper.php";
require_once $root."/includes/equipment_unit_helper.php";require_once $root."/includes/return_detail_helper.php";
final class P35bFail extends RuntimeException{}
function p5a(bool $v,string $m):void{if(!$v)throw new P35bFail($m);}
function p5v(PDO $p,string $q,array $a=[]):mixed{$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}
function p5multipart(array $d,string $prefix=""):array{$out=[];foreach($d as $k=>$v){$name=$prefix===""?(string)$k:$prefix."[{$k}]";if(is_array($v))$out+=p5multipart($v,$name);else $out[$name]=$v;}return $out;}
function p5h(string $b,string $p,string $c,string $m="GET",array $d=[],array $heads=[]):array{$x=curl_init(rtrim($b,"/")."/".ltrim($p,"/"));curl_setopt_array($x,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$c,CURLOPT_COOKIEFILE=>$c,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$heads]);if($m==="POST"){curl_setopt($x,CURLOPT_POST,true);curl_setopt($x,CURLOPT_POSTFIELDS,p5multipart($d));}$r=curl_exec($x);p5a(is_string($r),"HTTP failed: ".curl_error($x));$n=(int)curl_getinfo($x,CURLINFO_HEADER_SIZE);$s=(int)curl_getinfo($x,CURLINFO_RESPONSE_CODE);curl_close($x);return["status"=>$s,"body"=>substr($r,$n)];}
function p5user(PDO $p,string $mark,string $key,string $role,?string $type):int{$u="p5_{$key}_".strtolower(substr($mark,5));$s=$p->prepare("INSERT INTO users(full_name,department,university_id,email,role,account_type,username,password_hash,account_status,email_verified)VALUES(:n,'Phase 3.5B QA',:uid,:e,:r,:t,:u,:pw,'Approved',1)");$s->execute([":n"=>"{$mark} {$key}",":uid"=>strtoupper(substr(md5($key),0,4)).substr($mark,5),":e"=>"{$u}@example.invalid",":r"=>$role,":t"=>$type,":u"=>$u,":pw"=>password_hash("P5!{$mark}",PASSWORD_DEFAULT)]);return(int)$p->lastInsertId();}

$base=(string)(getenv("GSO_PHASE35B_BASE_URL")?:"http://127.0.0.1/GSO_WebSystem");
p5a(in_array((string)parse_url($base,PHP_URL_HOST),["127.0.0.1","localhost","::1"],true),"Loopback only.");

$tok=bin2hex(random_bytes(8));$mark="P35B-".strtoupper($tok);$ids=[];$cookies=[];$res=[];$boot=$root."/phase35b_session_{$tok}.php";$checks=0;$failed=null;
try{
 // ---- Schema and historical integrity ---------------------------------
 foreach(["equipment_units","request_item_units","return_submission_items"] as $t){
  p5a((int)p5v($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",[$t])===1,"{$t} missing.");$checks++;}
 p5a(str_contains((string)p5v($pdo,"SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='resource_requests' AND COLUMN_NAME='status'"),"Completed"),"Completed status missing.");$checks++;
 // Historical returns were backfilled once, already settled, with no fabricated unit.
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submissions rs WHERE NOT EXISTS(SELECT 1 FROM return_submission_items i WHERE i.return_id=rs.return_id)")===0,"A historical return has no detail row.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items WHERE stock_applied=0 AND inspected_at IS NOT NULL")===0,"Inspected history left unsettled.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items rsi JOIN return_submissions rs ON rs.return_id=rsi.return_id WHERE rsi.unit_id IS NOT NULL AND rs.submitted_at < NOW() - INTERVAL 1 DAY")===0,"A historical return invented a unit identity.");
 $checks+=3;
 $linesBefore=(int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items");
 $stockBefore=(string)p5v($pdo,"SELECT GROUP_CONCAT(CONCAT(resource_id,':',IFNULL(total_stock,'N'),'/',IFNULL(available_stock,'N'),'/',under_repair_stock) ORDER BY resource_id) FROM resources");
 $rerun=shell_exec(escapeshellarg(PHP_BINARY)." ".escapeshellarg($root."/scripts/migrate.php")." 2>&1");
 p5a(is_string($rerun)&&str_contains($rerun,"Migrations completed"),"Migration rerun failed.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items")===$linesBefore,"Migration rerun duplicated return details.");
 p5a((string)p5v($pdo,"SELECT GROUP_CONCAT(CONCAT(resource_id,':',IFNULL(total_stock,'N'),'/',IFNULL(available_stock,'N'),'/',under_repair_stock) ORDER BY resource_id) FROM resources")===$stockBefore,"Migration rerun changed stock.");
 $checks+=3;

 // ---- Fixtures ---------------------------------------------------------
 foreach(["admin"=>["Admin",null],"staff"=>["GSO Staff",null],"director"=>["Director",null],"borrower"=>["Borrower","University Staff"],"other"=>["Borrower","Student"]] as $k=>$v)$ids[$k]=p5user($pdo,$mark,$k,$v[0],$v[1]);
 $mk=function(string $name,string $type,string $mode,?int $stock)use($pdo,&$res,$mark):int{$s=$pdo->prepare("INSERT INTO resources(resource_name,resource_type,tracking_mode,category,location,total_stock,available_stock,capacity,status,condition_status,is_archived)VALUES(:n,:t,:m,'QA','QA',:s,:s2,:cap,'Available','Good',0)");$s->execute([":n"=>"{$mark} {$name}",":t"=>$type,":m"=>$mode,":s"=>$stock,":s2"=>$stock,":cap"=>$type==="Facility"?30:null]);$id=(int)$pdo->lastInsertId();$res[$name]=$id;return $id;};
 $cam=$mk("Camera","Item","Aggregate",3);$chair=$mk("Chair","Item","Aggregate",20);
 $hall=$mk("Hall","Facility","Facility",null);$paper=$mk("Paper","Item","Consumable",50);
 $otherSerial=$mk("Tripod","Item","Aggregate",2);

 // ---- Unit registration and reconciliation ----------------------------
 $reg=gsoRegisterEquipmentUnit($pdo,$cam,"{$mark}-CAM-1",null,$ids["admin"]);
 p5a($reg["ok"],"Unit registration failed: ".$reg["message"]);$unit1=(int)$reg["unit_id"];
 $dup=gsoRegisterEquipmentUnit($pdo,$cam,"{$mark}-CAM-1",null,$ids["admin"]);
 p5a(!$dup["ok"],"Duplicate asset tag accepted.");
 $reg2=gsoRegisterEquipmentUnit($pdo,$cam,"{$mark}-CAM-2","{$mark}-SN-2",$ids["admin"]);p5a($reg2["ok"],"Second unit failed.");$unit2=(int)$reg2["unit_id"];
 $dupSerial=gsoRegisterEquipmentUnit($pdo,$cam,"{$mark}-CAM-9","{$mark}-SN-2",$ids["admin"]);
 p5a(!$dupSerial["ok"],"Duplicate serial accepted.");
 $noSerialA=gsoRegisterEquipmentUnit($pdo,$cam,"{$mark}-CAM-3",null,$ids["admin"]);p5a($noSerialA["ok"],"Optional serial rejected.");$unit3=(int)$noSerialA["unit_id"];
 $consumableUnit=gsoRegisterEquipmentUnit($pdo,$paper,"{$mark}-PAPER-1",null,$ids["admin"]);
 p5a(!$consumableUnit["ok"],"A consumable accepted a physical unit.");
 $facilityUnit=gsoRegisterEquipmentUnit($pdo,$hall,"{$mark}-HALL-1",null,$ids["admin"]);
 p5a(!$facilityUnit["ok"],"A facility accepted a physical unit.");
 $checks+=7;
 // Activation is refused until units reconcile with the catalogue.
 $ready=gsoSerializedActivationReadiness($pdo,$cam);
 p5a($ready["ready"],"Three registered units did not reconcile with total stock 3: ".implode(" ",$ready["blockers"]));
 $notReady=gsoSerializedActivationReadiness($pdo,$otherSerial);
 p5a(!$notReady["ready"]&&$notReady["blockers"]!==[],"Activation allowed with no registered units.");
 $act=gsoActivateSerializedTracking($pdo,$cam,$ids["admin"]);p5a($act["ok"],"Activation failed: ".$act["message"]);
 p5a((string)p5v($pdo,"SELECT tracking_mode FROM resources WHERE resource_id=?",[$cam])==="Serialized","Tracking mode not applied.");
 p5a(gsoActivateSerializedTracking($pdo,$otherSerial,$ids["admin"])["ok"]===false,"Unreconciled resource was activated.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$cam])===3,"Counters not rebuilt from units.");
 p5a(gsoSerializedStockMismatches($pdo)===[],"Serialized counters disagree with units.");
 $checks+=6;

 // ---- Sessions ---------------------------------------------------------
 $people=[];foreach(["admin"=>"Admin","staff"=>"GSO Staff","director"=>"Director","borrower"=>"Borrower","other"=>"Borrower"] as $k=>$r)$people[$k]=[$ids[$k],$r];
 $src="<?php require_once __DIR__.'/config/bootstrap.php';gsoSecureSessionStart();if(!in_array((string)(\$_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)||!hash_equals('{$tok}',(string)(\$_SERVER['HTTP_X_P5_TOKEN']??''))){http_response_code(404);exit;}\$p=".var_export($people,true).";\$k=(string)(\$_POST['persona']??'');if(!isset(\$p[\$k])){http_response_code(400);exit;}\$_SESSION=[];session_regenerate_id(true);\$_SESSION['user_id']=\$p[\$k][0];\$_SESSION['role']=\$p[\$k][1];\$_SESSION['full_name']='P5 '.\$k;\$_SESSION['email']='p5@example.invalid';\$_SESSION['csrf_token']=bin2hex(random_bytes(32));header('Content-Type: application/json');echo json_encode(['csrf_token'=>\$_SESSION['csrf_token']]);";
 p5a(file_put_contents($boot,$src)!==false,"Bootstrap file failed.");$csrf=[];
 foreach($people as $k=>$v){$cookies[$k]=tempnam(sys_get_temp_dir(),"p5_");$r=p5h($base,basename($boot),$cookies[$k],"POST",["persona"=>$k],["X-P5-Token: {$tok}"]);$j=json_decode($r["body"],true);p5a($r["status"]===200&&is_array($j),"Bootstrap {$k} failed.");$csrf[$k]=$j["csrf_token"];}

 // ---- Authorization on the unit registry -------------------------------
 foreach(["borrower","director","other"] as $who){
  $v=p5h($base,"admin/equipment_units.php?resource_id={$cam}",$cookies[$who]);
  p5a($v["status"]===302&&!str_contains($v["body"],"Register a unit"),"{$who} reached the equipment unit registry.");$checks++;
  $before=(int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units WHERE resource_id=?",[$cam]);
  p5h($base,"admin/equipment_units.php",$cookies[$who],"POST",["csrf_token"=>$csrf[$who],"resource_id"=>$cam,"action"=>"register_unit","asset_tag"=>"{$mark}-HACK-{$who}"]);
  p5a((int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units WHERE resource_id=?",[$cam])===$before,"{$who} registered a unit.");$checks++;}
 $unitsBefore=(int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units");
 p5h($base,"admin/equipment_units.php",$cookies["admin"],"POST",["csrf_token"=>"forged","resource_id"=>$cam,"action"=>"register_unit","asset_tag"=>"{$mark}-CSRF"]);
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units")===$unitsBefore,"Unit registration CSRF bypassed.");$checks++;

 // ---- Helpers for building requests ------------------------------------
 $day=date("Y-m-d",strtotime("+25 days"));
 $newReq=function(int $uid,array $lines,string $date)use($pdo):int{
   $primary=$lines[0];
   $s=$pdo->prepare("INSERT INTO resource_requests(borrower_id,resource_id,quantity,contact_number,date_needed,start_time,end_time,status,notes)VALUES(:u,:r,:q,'09123456789',:d,'08:00:00','10:00:00','Approved','Phase 3.5B')");
   $s->execute([":u"=>$uid,":r"=>$primary[0],":q"=>$primary[1],":d"=>$date]);$rid=(int)$pdo->lastInsertId();
   $ls=$pdo->prepare("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity) SELECT :rid,:res,CASE WHEN r.resource_type='Facility' THEN 'Facility' WHEN r.tracking_mode='Consumable' THEN 'Consumable' ELSE 'Equipment' END,:qty FROM resources r WHERE r.resource_id=:res2");
   foreach($lines as $l)$ls->execute([":rid"=>$rid,":res"=>$l[0],":qty"=>$l[1],":res2"=>$l[0]]);
   return $rid;};
 $release=function(string $who,int $rid,array $unitIds=[])use($base,&$cookies,&$csrf):array{
   $d=["csrf_token"=>$csrf[$who],"request_id"=>$rid,"action"=>"release"];
   foreach($unitIds as $lineId=>$us){foreach(array_values($us) as $i=>$u)$d["unit_ids"][$lineId][$i]=$u;}
   return p5h($base,"admin/requests.php",$cookies[$who],"POST",$d);};
 $lineId=function(int $rid,int $resourceId)use($pdo):int{return (int)p5v($pdo,"SELECT request_item_id FROM request_items WHERE request_id=? AND resource_id=?",[$rid,$resourceId]);};
 $status=function(int $rid)use($pdo):string{return (string)p5v($pdo,"SELECT status FROM resource_requests WHERE request_id=?",[$rid]);};

 // ---- Serialized release: wrong count, foreign unit, then success ------
 $rSerial=$newReq($ids["borrower"],[[$cam,2]],$day);$lineCam=$lineId($rSerial,$cam);
 $release("admin",$rSerial,[$lineCam=>[$unit1]]);
 p5a($status($rSerial)==="Approved"&&(int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units WHERE request_item_id=?",[$lineCam])===0,"Release accepted too few units.");$checks++;
 $foreignReg=gsoRegisterEquipmentUnit($pdo,$otherSerial,"{$mark}-TRI-1",null,$ids["admin"]);$foreignUnit=(int)$foreignReg["unit_id"];
 $release("admin",$rSerial,[$lineCam=>[$unit1,$foreignUnit]]);
 p5a($status($rSerial)==="Approved","A unit from another resource was accepted.");
 p5a((int)p5v($pdo,"SELECT status='Available' FROM equipment_units WHERE unit_id=?",[$unit1])===1,"A rolled-back release left a unit claimed.");$checks+=2;
 $ghostUnit=(int)p5v($pdo,"SELECT MAX(unit_id)+9999 FROM equipment_units");
 $release("admin",$rSerial,[$lineCam=>[$unit1,$ghostUnit]]);
 p5a($status($rSerial)==="Approved","A forged unit id was accepted.");$checks++;
 $release("admin",$rSerial,[$lineCam=>[$unit1,$unit2]]);
 p5a($status($rSerial)==="Released","Serialized release failed.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL",[$lineCam])===2,"Allocations not recorded.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units WHERE unit_id IN (?,?) AND status='Released'",[$unit1,$unit2])===2,"Units not marked Released.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$cam])===1,"Serialized counters wrong after release.");
 p5a((int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$cam])===3,"Total stock changed on release.");
 $checks+=5;
 // The same physical unit cannot go out twice, and a repeated POST is inert.
 $rSerial2=$newReq($ids["other"],[[$cam,1]],$day);$lineCam2=$lineId($rSerial2,$cam);
 $release("admin",$rSerial2,[$lineCam2=>[$unit1]]);
 p5a($status($rSerial2)==="Approved","An already-issued unit was released again.");$checks++;
 $allocBefore=(int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units");
 $release("admin",$rSerial,[$lineCam=>[$unit3]]);
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units")===$allocBefore&&$status($rSerial)==="Released","Duplicate release created a second allocation.");$checks++;

 // ---- Mixed release: facility + aggregate + consumable -----------------
 $chairBefore=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair]);
 $paperAvailBefore=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$paper]);
 $paperTotalBefore=(int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$paper]);
 $rMixed=$newReq($ids["borrower"],[[$hall,1],[$chair,5],[$paper,4]],date("Y-m-d",strtotime("+26 days")));
 $release("admin",$rMixed);
 p5a($status($rMixed)==="Released","Mixed release failed.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair])===$chairBefore-5,"Aggregate stock not deducted.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$paper])===$paperAvailBefore-4,"Consumable available stock not deducted.");
 p5a((int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$paper])===$paperTotalBefore-4,"Consumable total stock not permanently reduced.");
 p5a((int)p5v($pdo,"SELECT issued_quantity FROM request_items WHERE request_id=? AND resource_id=?",[$rMixed,$paper])===4,"Issuance not recorded.");
 $checks+=5;
 // Approval alone must never consume anything.
 $rApproved=$newReq($ids["borrower"],[[$paper,3]],date("Y-m-d",strtotime("+27 days")));
 p5a((int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$paper])===$paperTotalBefore-4,"Approval deducted consumable stock.");$checks++;
 // Consumable-only requests complete without a return.
 $release("admin",$rApproved);
 p5a($status($rApproved)==="Completed","Consumable-only request did not complete.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submissions WHERE request_id=?",[$rApproved])===0,"A return was created for issued materials.");
 $consumableReturn=p5h($base,"borrower/return_submit.php?request_id={$rApproved}",$cookies["borrower"]);
 p5a(!str_contains($consumableReturn["body"],"Account for every resource"),"Consumable-only request offered a return form.");
 $checks+=3;

 // ---- All-or-nothing rollback ------------------------------------------
 $chairNow=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair]);
 $paperNow=(int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$paper]);
 $rFail=$newReq($ids["borrower"],[[$chair,2],[$paper,99999]],date("Y-m-d",strtotime("+28 days")));
 $release("admin",$rFail);
 p5a($status($rFail)==="Approved","A request with an unfulfillable line was released.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair])===$chairNow,"Rollback left an aggregate deduction.");
 p5a((int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$paper])===$paperNow,"Rollback left a consumable deduction.");
 p5a((int)p5v($pdo,"SELECT COALESCE(SUM(released_quantity),0) FROM request_items WHERE request_id=?",[$rFail])===0,"Rollback left lines marked released.");
 $checks+=4;

 // ---- Adviser and Director gates still bind at request level ----------
 $rGate=$newReq($ids["other"],[[$chair,1]],date("Y-m-d",strtotime("+29 days")));
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Pending Adviser' WHERE request_id=?")->execute([$rGate]);
 $release("admin",$rGate);p5a($status($rGate)==="Approved","Adviser gate bypassed at release.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Not Required',director_state='Pending' WHERE request_id=?")->execute([$rGate]);
 $release("admin",$rGate);p5a($status($rGate)==="Approved","Director gate bypassed at release.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET director_state='Not Required' WHERE request_id=?")->execute([$rGate]);
 foreach(["borrower","other","director"] as $who){$release($who,$rGate);p5a($status($rGate)==="Approved","{$who} released a request.");$checks++;}
 p5h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>"forged","request_id"=>$rGate,"action"=>"release"]);
 p5a($status($rGate)==="Approved","Release CSRF bypassed.");$checks++;
 $release("staff",$rGate);p5a($status($rGate)==="Released","GSO Staff could not release an ordinary request.");$checks++;

 // ---- Aggregate mixed-condition return ---------------------------------
 $chairLine=$lineId($rMixed,$chair);$hallLine=$lineId($rMixed,$hall);
 // The facility keeps its rule that completion is only submitted after the
 // scheduled end time, so the fixture schedule is moved into the past here
 // rather than weakening that rule.
 $pdo->prepare("UPDATE resource_requests SET date_needed=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE request_id=?")->execute([$rMixed]);
 $submitReturn=function(string $who,int $rid,array $lines,?string $token=null)use($base,&$cookies,&$csrf):array{
   $d=["csrf_token"=>$token??$csrf[$who],"condition_notes"=>"QA"];
   foreach($lines as $lid=>$payload)$d["lines"][$lid]=$payload;
   if(gsoRequestFacilityLine($GLOBALS['pdo'],$rid)!==null){
    $photo=tempnam(sys_get_temp_dir(),"p5_proof_");file_put_contents($photo,base64_decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWZkAAAAASUVORK5CYII="));
    $d['proof_photos'][0]=new CURLFile($photo,'image/png','proof.png');
    try{return p5h($base,"borrower/return_submit.php?request_id={$rid}",$cookies[$who],"POST",$d);}finally{@unlink($photo);}
   }
   return p5h($base,"borrower/return_submit.php?request_id={$rid}",$cookies[$who],"POST",$d);};
 // A borrower may not return someone else's request.
 $submitReturn("other",$rMixed,[$chairLine=>["returned_quantity"=>5,"lost_quantity"=>0,"condition"=>"Good"],$hallLine=>["condition"=>"Good"]]);
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submissions WHERE request_id=?",[$rMixed])===0,"Another borrower submitted the return.");$checks++;
 // Quantities must add up to what was actually released.
 $submitReturn("borrower",$rMixed,[$chairLine=>["returned_quantity"=>2,"lost_quantity"=>0,"condition"=>"Good"],$hallLine=>["condition"=>"Good"]]);
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submissions WHERE request_id=?",[$rMixed])===0,"An under-counted return was accepted.");$checks++;
 // 3 good, 2 lost; the lost pair is not counted as physically received.
 $submitReturn("borrower",$rMixed,[$chairLine=>["returned_quantity"=>3,"lost_quantity"=>2,"condition"=>"Good"],$hallLine=>["condition"=>"Good"]]);
 $returnId=(int)p5v($pdo,"SELECT return_id FROM return_submissions WHERE request_id=?",[$rMixed]);
 p5a($returnId>0,"Mixed return was not created.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items WHERE return_id=? AND request_item_id=?",[$returnId,$chairLine])===2,"Lost quantity was not split into its own group.");
 p5a((int)p5v($pdo,"SELECT returned_quantity FROM return_submission_items WHERE return_id=? AND request_item_id=? AND lost_quantity>0",[$returnId,$chairLine])===0,"A lost item was counted as received.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items WHERE return_id=? AND request_item_id=? AND unit_id IS NOT NULL",[$returnId,$chairLine])===0,"An aggregate line invented a unit.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items rsi JOIN request_items ri ON ri.request_item_id=rsi.request_item_id WHERE rsi.return_id=? AND ri.resource_id=?",[$returnId,$paper])===0,"A consumable entered the return.");
 $checks+=5;
 // Inspection: 3 Good restored, 2 Lost removed from total, chair only.
 $chairAvail=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair]);
 $chairTotal=(int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$chair]);
 $camAvail=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$cam]);
 $details=$pdo->prepare("SELECT return_item_id,request_item_id,lost_quantity FROM return_submission_items WHERE return_id=?");$details->execute([$returnId]);
 $inspect=[];foreach($details->fetchAll(PDO::FETCH_ASSOC) as $d)$inspect[(int)$d["return_item_id"]]=["condition"=>((int)$d["lost_quantity"]>0?"Lost":"Good"),"remarks"=>""];
 $post=["csrf_token"=>$csrf["admin"],"request_id"=>$rMixed,"action"=>"approve","admin_notes"=>"QA","inspection_remarks"=>"QA"];
 foreach($inspect as $rid2=>$v){$post["inspection"][$rid2]["condition"]=$v["condition"];$post["inspection"][$rid2]["remarks"]=$v["remarks"];}
 p5h($base,"admin/review_return.php?request_id={$rMixed}",$cookies["admin"],"POST",$post);
 p5a($status($rMixed)==="Returned","Mixed return inspection failed.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair])===$chairAvail+3,"Good chairs not restored.");
 p5a((int)p5v($pdo,"SELECT total_stock FROM resources WHERE resource_id=?",[$chair])===$chairTotal-2,"Lost chairs did not reduce total stock.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$cam])===$camAvail,"An unrelated resource was altered by this return.");
 $checks+=4;
 // A second inspection must not restore stock twice.
 $chairAfter=(int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair]);
 p5h($base,"admin/review_return.php?request_id={$rMixed}",$cookies["admin"],"POST",$post);
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$chair])===$chairAfter,"A second inspection restored stock twice.");$checks++;

 // ---- Serialized per-unit return ---------------------------------------
 $submitReturn("borrower",$rSerial,[$lineCam=>["units"=>[$unit1=>["condition"=>"Good"],$unit2=>["condition"=>"Damaged"]]]]);
 $serialReturnId=(int)p5v($pdo,"SELECT return_id FROM return_submissions WHERE request_id=?",[$rSerial]);
 p5a($serialReturnId>0,"Serialized return not created.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items WHERE return_id=? AND unit_id IS NOT NULL",[$serialReturnId])===2,"Per-unit rows missing.");
 $checks+=2;
 $sDetails=$pdo->prepare("SELECT return_item_id,unit_id FROM return_submission_items WHERE return_id=?");$sDetails->execute([$serialReturnId]);
 $sPost=["csrf_token"=>$csrf["admin"],"request_id"=>$rSerial,"action"=>"approve","admin_notes"=>"QA","inspection_remarks"=>"QA"];
 foreach($sDetails->fetchAll(PDO::FETCH_ASSOC) as $d){$sPost["inspection"][(int)$d["return_item_id"]]["condition"]=((int)$d["unit_id"]===$unit2?"Damaged":"Good");$sPost["inspection"][(int)$d["return_item_id"]]["remarks"]="";}
 p5h($base,"admin/review_return.php?request_id={$rSerial}",$cookies["admin"],"POST",$sPost);
 p5a((string)p5v($pdo,"SELECT status FROM equipment_units WHERE unit_id=?",[$unit1])==="Available","Good unit not returned to service.");
 p5a((string)p5v($pdo,"SELECT status FROM equipment_units WHERE unit_id=?",[$unit2])==="Under Repair","Damaged unit remained available.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units WHERE request_item_id=? AND returned_at IS NULL",[$lineCam])===0,"Allocations were not closed.");
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units WHERE request_item_id=?",[$lineCam])===2,"Allocation history was deleted.");
 p5a((int)p5v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$cam])===2,"Serialized available count wrong after return.");
 p5a((int)p5v($pdo,"SELECT under_repair_stock FROM resources WHERE resource_id=?",[$cam])===1,"Damaged unit not held out of service.");
 p5a(gsoSerializedStockMismatches($pdo)===[],"Serialized counters drifted after return.");
 $checks+=7;
 // A returned Good unit may be issued again; the damaged one may not.
 $free=array_column(gsoAvailableUnitsForResource($pdo,$cam),"unit_id");
 p5a(in_array($unit1,$free,true)&&!in_array($unit2,$free,true),"Unit availability after inspection is wrong.");$checks++;

 // ---- Facility still requires explicit return --------------------------
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM return_submission_items WHERE return_id=? AND request_item_id=?",[$returnId,$hallLine])===1,"Facility skipped return inspection.");$checks++;

 // ---- Chatbot stays read-only -----------------------------------------
 require_once $root."/includes/ai_live_data_helper.php";
 $owner=["user_id"=>$ids["borrower"],"role"=>"Borrower","full_name"=>"P5 borrower"];
 $unitsPre=(int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units");
 $allocPre=(int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units");
 gsoChatGetRequestStatus($pdo,$owner,"release REQ-".str_pad((string)$rGate,3,"0",STR_PAD_LEFT)." and issue all units",[]);
 p5a((int)p5v($pdo,"SELECT COUNT(*) FROM equipment_units")===$unitsPre&&(int)p5v($pdo,"SELECT COUNT(*) FROM request_item_units")===$allocPre,"Chatbot mutated inventory.");$checks++;

 echo "PASS: Phase 3.5B unit tracking and mixed fulfilment E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}
finally{
 if(is_file($boot))@unlink($boot);
 if($res&&$ids){
  $resourceIds=array_values($res);$marks=implode(",",array_fill(0,count($resourceIds),"?"));
  $userMarks=implode(",",array_fill(0,count($ids),"?"));
  // Fixture identity is proven by BOTH the marked resource and the marked
  // borrower, so a real request sharing a resource is never deleted.
  $reqStmt=$pdo->prepare("SELECT DISTINCT ri.request_id FROM request_items ri JOIN resource_requests rr ON rr.request_id=ri.request_id WHERE ri.resource_id IN ({$marks}) AND rr.borrower_id IN ({$userMarks})");
  $reqStmt->execute(array_merge($resourceIds,array_values($ids)));
  foreach($reqStmt->fetchAll(PDO::FETCH_COLUMN)?:[] as $rid){
   require_once $root.'/includes/return_upload_helper.php';$photos=$pdo->prepare('SELECT p.filename FROM return_submission_photos p JOIN return_submissions s ON s.return_id=p.return_id WHERE s.request_id=?');$photos->execute([$rid]);foreach($photos->fetchAll(PDO::FETCH_COLUMN) as $filename)deleteReturnPhotoFile($filename);
   $pdo->prepare("DELETE FROM notifications WHERE link LIKE :l")->execute([":l"=>"%request_id={$rid}"]);
   $pdo->prepare("DELETE FROM return_submission_items WHERE return_id IN (SELECT return_id FROM return_submissions WHERE request_id=:r)")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM return_submissions WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_item_units WHERE request_item_id IN (SELECT request_item_id FROM request_items WHERE request_id=:r)")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_director_history WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_adviser_history WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_items WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM resource_requests WHERE request_id=:r")->execute([":r"=>$rid]);
  }
  $pdo->prepare("DELETE FROM equipment_units WHERE resource_id IN ({$marks})")->execute($resourceIds);
  $pdo->prepare("DELETE FROM resources WHERE resource_id IN ({$marks})")->execute($resourceIds);
 }
 $pdo->prepare("DELETE FROM equipment_units WHERE asset_tag LIKE :m")->execute([":m"=>"{$mark}%"]);
 $pdo->prepare("DELETE FROM resources WHERE resource_name LIKE :m")->execute([":m"=>"{$mark}%"]);
 foreach($ids as $id){$pdo->prepare("DELETE FROM notifications WHERE user_id=:i")->execute([":i"=>$id]);$pdo->prepare("DELETE FROM activity_logs WHERE user_id=:i")->execute([":i"=>$id]);}
 $pdo->prepare("DELETE FROM users WHERE full_name LIKE :m")->execute([":m"=>"{$mark}%"]);
 foreach($cookies as $c)if(is_string($c)&&is_file($c))@unlink($c);
 $leftRes=(int)$pdo->query("SELECT COUNT(*) FROM resources WHERE resource_name LIKE '{$mark}%'")->fetchColumn();
 $leftUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE full_name LIKE '{$mark}%'")->fetchColumn();
 $leftUnits=(int)$pdo->query("SELECT COUNT(*) FROM equipment_units WHERE asset_tag LIKE '{$mark}%'")->fetchColumn();
 if($leftRes||$leftUsers||$leftUnits){fwrite(STDERR,"FAIL: fixture cleanup incomplete (resources={$leftRes}, users={$leftUsers}, units={$leftUnits}).\n");if(!$failed)$failed=new RuntimeException("cleanup incomplete");}
}
if($failed)exit(1);
