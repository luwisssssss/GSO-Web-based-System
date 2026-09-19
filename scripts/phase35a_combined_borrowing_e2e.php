<?php
declare(strict_types=1);
/**
 * Phase 3.5A end-to-end suite: combined borrowing and resource classification.
 *
 * Fixtures are marked, created here, and always swept in the finally block.
 * The suite never touches a real request, resource or user: every row it reads
 * or writes carries its own run marker.
 */
$root=dirname(__DIR__);require_once $root."/config/bootstrap.php";require_once $root."/config/db.php";
require_once $root."/includes/request_helper.php";require_once $root."/includes/request_line_helper.php";
final class P35Fail extends RuntimeException{}
function p35a(bool $v,string $m):void{if(!$v)throw new P35Fail($m);}
function p35v(PDO $p,string $q,array $a=[]):mixed{$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}
function p35h(string $b,string $p,string $c,string $m="GET",array $d=[],array $heads=[]):array{$x=curl_init(rtrim($b,"/")."/".ltrim($p,"/"));curl_setopt_array($x,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$c,CURLOPT_COOKIEFILE=>$c,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$heads]);if($m==="POST"){curl_setopt($x,CURLOPT_POST,true);curl_setopt($x,CURLOPT_POSTFIELDS,http_build_query($d));}$r=curl_exec($x);p35a(is_string($r),"HTTP failed: ".curl_error($x));$n=(int)curl_getinfo($x,CURLINFO_HEADER_SIZE);$s=(int)curl_getinfo($x,CURLINFO_RESPONSE_CODE);curl_close($x);return["status"=>$s,"body"=>substr($r,$n)];}
function p35user(PDO $p,string $mark,string $key,string $role,?string $type):int{$u="p35_{$key}_".strtolower(substr($mark,4));$s=$p->prepare("INSERT INTO users(full_name,department,university_id,email,role,account_type,username,password_hash,account_status,email_verified)VALUES(:n,'Phase 3.5A QA',:uid,:e,:r,:t,:u,:pw,'Approved',1)");$s->execute([":n"=>"{$mark} {$key}",":uid"=>strtoupper(substr(md5($key),0,4)).substr($mark,4),":e"=>"{$u}@example.invalid",":r"=>$role,":t"=>$type,":u"=>$u,":pw"=>password_hash("P35!{$mark}",PASSWORD_DEFAULT)]);return(int)$p->lastInsertId();}

$base=(string)(getenv("GSO_PHASE35A_BASE_URL")?:"http://127.0.0.1/GSO_WebSystem");
p35a(in_array((string)parse_url($base,PHP_URL_HOST),["127.0.0.1","localhost","::1"],true),"Loopback only.");

$tok=bin2hex(random_bytes(8));$mark="P35A-".strtoupper($tok);$ids=[];$cookies=[];$res=[];$boot=$root."/phase35a_session_{$tok}.php";$checks=0;$failed=null;
try{
 // ---- Migration and backfill integrity (real historical data) ----------
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items'")===1,"request_items missing.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='resources' AND COLUMN_NAME='tracking_mode'")===1,"resources.tracking_mode missing.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM (SELECT rr.request_id,COUNT(ri.request_item_id) c FROM resource_requests rr LEFT JOIN request_items ri ON ri.request_id=rr.request_id GROUP BY rr.request_id HAVING c=0) x")===0,"A request has no line after backfill.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items ri LEFT JOIN resource_requests rr ON rr.request_id=ri.request_id WHERE rr.request_id IS NULL")===0,"Orphan request line found.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resources WHERE (resource_type='Facility')<>(tracking_mode='Facility')")===0,"tracking_mode incoherent with resource_type.");
 $checks+=5;
 // Legacy requests still mirror exactly one line; multi-line requests created
 // later in this run are excluded because they legitimately hold more.
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests rr JOIN request_items ri ON ri.request_id=rr.request_id WHERE (SELECT COUNT(*) FROM request_items x WHERE x.request_id=rr.request_id)=1 AND (ri.resource_id<>rr.resource_id OR ri.requested_quantity<>GREATEST(1,COALESCE(rr.quantity,1)))")===0,"Legacy mirror parity broken.");$checks++;
 $stockBefore=(string)p35v($pdo,"SELECT GROUP_CONCAT(CONCAT(resource_id,':',IFNULL(total_stock,'N'),'/',IFNULL(available_stock,'N'),'/',under_repair_stock) ORDER BY resource_id) FROM resources");
 $linesBefore=(int)p35v($pdo,"SELECT COUNT(*) FROM request_items");
 require_once $root."/includes/schema_helper.php";
 // Re-running the migration must be a no-op for both lines and stock.
 $rerun=shell_exec(escapeshellarg(PHP_BINARY)." ".escapeshellarg($root."/scripts/migrate.php")." 2>&1");
 p35a(is_string($rerun)&&str_contains($rerun,"Migrations completed"),"Migration rerun failed: ".(string)$rerun);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items")===$linesBefore,"Migration rerun duplicated request lines.");
 p35a((string)p35v($pdo,"SELECT GROUP_CONCAT(CONCAT(resource_id,':',IFNULL(total_stock,'N'),'/',IFNULL(available_stock,'N'),'/',under_repair_stock) ORDER BY resource_id) FROM resources")===$stockBefore,"Migration rerun changed stock.");
 $checks+=3;

 // ---- Fixtures ---------------------------------------------------------
 foreach(["admin"=>["Admin",null],"staff"=>["GSO Staff",null],"student"=>["Borrower","Student"],"other"=>["Borrower","University Staff"]] as $k=>$v)$ids[$k]=p35user($pdo,$mark,$k,$v[0],$v[1]);
 $mk=function(string $name,string $type,string $mode,?int $stock)use($pdo,&$res,$mark):int{$s=$pdo->prepare("INSERT INTO resources(resource_name,resource_type,tracking_mode,category,location,total_stock,available_stock,capacity,status,condition_status,is_archived)VALUES(:n,:t,:m,'QA','QA',:s,:s2,:cap,'Available','Good',0)");$s->execute([":n"=>"{$mark} {$name}",":t"=>$type,":m"=>$mode,":s"=>$stock,":s2"=>$stock,":cap"=>$type==="Facility"?30:null]);$id=(int)$pdo->lastInsertId();$res[$name]=$id;return $id;};
 $itemA=$mk("Item A","Item","Aggregate",20);$itemB=$mk("Item B","Item","Aggregate",20);
 $hallA=$mk("Hall A","Facility","Facility",null);$hallB=$mk("Hall B","Facility","Facility",null);
 $serial=$mk("Serial Cam","Item","Serialized",5);$paper=$mk("Bond Paper","Item","Consumable",50);

 // Classification guards are enforced by the database, not only by the app.
 $bad=false;try{$pdo->prepare("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status,is_archived)VALUES(:n,'Facility','Aggregate',NULL,NULL,'Available','Good',0)")->execute([":n"=>"{$mark} Bad Facility"]);}catch(Throwable $e){$bad=true;}
 p35a($bad,"Facility with non-facility tracking_mode was accepted.");
 $bad=false;try{$pdo->prepare("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status,is_archived)VALUES(:n,'Item','Facility',5,5,'Available','Good',0)")->execute([":n"=>"{$mark} Bad Item"]);}catch(Throwable $e){$bad=true;}
 p35a($bad,"Item classified as Facility was accepted.");$checks+=2;

 $people=[];foreach(["admin","staff","student","other"] as $k)$people[$k]=[$ids[$k],["admin"=>"Admin","staff"=>"GSO Staff","student"=>"Borrower","other"=>"Borrower"][$k]];
 $src="<?php require_once __DIR__.'/config/bootstrap.php';gsoSecureSessionStart();if(!in_array((string)(\$_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)||!hash_equals('{$tok}',(string)(\$_SERVER['HTTP_X_P35_TOKEN']??''))){http_response_code(404);exit;}\$p=".var_export($people,true).";\$k=(string)(\$_POST['persona']??'');if(!isset(\$p[\$k])){http_response_code(400);exit;}\$_SESSION=[];session_regenerate_id(true);\$_SESSION['user_id']=\$p[\$k][0];\$_SESSION['role']=\$p[\$k][1];\$_SESSION['full_name']='P35 '.\$k;\$_SESSION['email']='p35@example.invalid';\$_SESSION['csrf_token']=bin2hex(random_bytes(32));header('Content-Type: application/json');echo json_encode(['csrf_token'=>\$_SESSION['csrf_token']]);";
 p35a(file_put_contents($boot,$src)!==false,"Bootstrap file failed.");$csrf=[];
 foreach($people as $k=>$v){$cookies[$k]=tempnam(sys_get_temp_dir(),"p35_");$r=p35h($base,basename($boot),$cookies[$k],"POST",["persona"=>$k],["X-P35-Token: {$tok}"]);$j=json_decode($r["body"],true);p35a($r["status"]===200&&is_array($j),"Bootstrap {$k} failed.");$csrf[$k]=$j["csrf_token"];}

 $day=date("Y-m-d",strtotime("+12 days"));$day2=date("Y-m-d",strtotime("+13 days"));$day3=date("Y-m-d",strtotime("+14 days"));
 $submit=function(string $who,int $primaryId,int $primaryQty,array $extras,string $date,string $start="08:00",string $end="10:00",?string $token=null)use($base,&$cookies,&$csrf):array{
   $d=["csrf_token"=>$token??$csrf[$who],"quantity"=>$primaryQty,"contact_number"=>"09123456789","date_needed"=>$date,"start_time"=>$start,"end_time"=>$end,"notes"=>"Phase 3.5A activity"];
   foreach(array_values($extras) as $i=>$x){$d["extra_lines"][$i]["resource_id"]=$x[0];$d["extra_lines"][$i]["quantity"]=$x[1];}
   return p35h($base,"borrower/request_resource.php?resource_id={$primaryId}",$cookies[$who],"POST",$d);};
 $newest=function(int $uid)use($pdo):int{return (int)p35v($pdo,"SELECT request_id FROM resource_requests WHERE borrower_id=? ORDER BY request_id DESC LIMIT 1",[$uid]);};
 $lineSig=function(int $rid)use($pdo):string{$s=$pdo->prepare("SELECT GROUP_CONCAT(CONCAT(resource_id,':',line_kind,':',requested_quantity) ORDER BY resource_id) FROM request_items WHERE request_id=?");$s->execute([$rid]);return (string)$s->fetchColumn();};

 // ---- A. Equipment-only, multi-line -----------------------------------
 $submit("student",$itemA,2,[[$itemB,3]],$day);
 $reqA=$newest($ids["student"]);p35a($reqA>0,"Equipment-only request not created.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=?",[$reqA])===2,"Equipment-only request did not create two lines.");
 p35a($lineSig($reqA)==="{$itemA}:Equipment:2,{$itemB}:Equipment:3","Equipment line signature wrong: ".$lineSig($reqA));
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=? AND line_kind='Facility'",[$reqA])===0,"Equipment-only request has a facility line.");
 $checks+=4;

 // ---- B. Facility-only -------------------------------------------------
 $submit("student",$hallA,1,[],$day2);
 $reqB=$newest($ids["student"]);p35a($reqB!==$reqA&&(int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=? AND line_kind='Facility'",[$reqB])===1,"Facility-only request failed.");$checks++;

 // ---- C. Facility + equipment -----------------------------------------
 $submit("other",$hallB,1,[[$itemA,2],[$itemB,1]],$day);
 $reqC=$newest($ids["other"]);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=?",[$reqC])===3,"Combined request did not create three lines.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=? AND line_kind='Facility'",[$reqC])===1,"Combined request lost its facility line.");
 p35a((int)p35v($pdo,"SELECT COUNT(DISTINCT request_id) FROM request_items WHERE request_id=?",[$reqC])===1,"Combined request spans more than one reference.");
 // The legacy mirror must point at the facility and must not be mistaken for the whole request.
 p35a((int)p35v($pdo,"SELECT resource_id FROM resource_requests WHERE request_id=?",[$reqC])===$hallB,"Mirror did not prefer the facility line.");
 $checks+=4;

 // ---- D/E. Consumable-bearing shapes ----------------------------------
 $submit("other",$itemA,1,[[$paper,2]],$day3);
 $reqD=$newest($ids["other"]);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=? AND line_kind='Consumable'",[$reqD])===1,"Consumable line not classified.");
 $submit("student",$hallB,1,[[$itemB,2],[$paper,3]],$day3);
 $reqE=$newest($ids["student"]);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=?",[$reqE])===3,"Facility+equipment+consumable request failed.");
 p35a((string)p35v($pdo,"SELECT line_kind FROM request_items WHERE request_id=? AND resource_id=?",[$reqE,$paper])==="Consumable","Consumable misclassified in combined request.");
 $checks+=3;

 // ---- Validation: merge, second facility, forged ids, bad quantities ---
 $submit("other",$itemA,2,[[$itemA,3]],date("Y-m-d",strtotime("+15 days")));
 $reqMerge=$newest($ids["other"]);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=?",[$reqMerge])===1,"Duplicate resource was not merged into one line.");
 p35a((int)p35v($pdo,"SELECT requested_quantity FROM request_items WHERE request_id=?",[$reqMerge])===5,"Merged quantity wrong.");$checks+=2;
 $beforeCount=(int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["student"]]);
 $r=$submit("student",$hallA,1,[[$hallB,1]],date("Y-m-d",strtotime("+16 days")));
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["student"]])===$beforeCount,"Second facility was accepted.");
 p35a(str_contains($r["body"],"only one facility"),"No clear message for a second facility.");$checks+=2;
 $ghost=(int)p35v($pdo,"SELECT MAX(resource_id)+99999 FROM resources");
 foreach([[$ghost,1],[0,1],[-5,1]] as $forged){$before=(int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE resource_id=?",[$forged[0]]);$submit("student",$itemA,1,[[$forged[0],$forged[1]]],date("Y-m-d",strtotime("+17 days")));p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE resource_id=?",[$forged[0]])===$before,"Forged resource id {$forged[0]} created a line.");$checks++;}
 $beforeCount=(int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["other"]]);
 foreach([0,-3,5000] as $badQty){$submit("other",$itemA,1,[[$itemB,$badQty]],date("Y-m-d",strtotime("+18 days")));}
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["other"]])===$beforeCount,"An invalid quantity was accepted.");$checks++;
 // A forged line_kind must be ignored: classification comes from the database.
 $submit("student",$paper,2,[],date("Y-m-d",strtotime("+19 days")));
 $reqKind=$newest($ids["student"]);
 p35a((string)p35v($pdo,"SELECT line_kind FROM request_items WHERE request_id=?",[$reqKind])==="Consumable","Server-side classification was not applied.");$checks++;
 // Per-line availability: more than the whole stock must be refused.
 $beforeCount=(int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["other"]]);
 $submit("other",$itemA,1,[[$itemB,999]],date("Y-m-d",strtotime("+20 days")));
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["other"]])===$beforeCount,"Per-line availability was not enforced.");$checks++;
 // CSRF and ownership.
 $beforeCount=(int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["student"]]);
 $submit("student",$itemA,1,[[$itemB,1]],date("Y-m-d",strtotime("+21 days")),"08:00","10:00","forged-token");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["student"]])===$beforeCount,"CSRF bypassed on request creation.");$checks++;
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE request_id=? AND borrower_id=?",[$reqC,$ids["other"]])===1,"Request ownership not recorded from the session.");$checks++;
 // Database backstops, independent of the application.
 $bad=false;try{$pdo->prepare("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity)VALUES(?,?,'Facility',1)")->execute([$reqC,$hallA]);}catch(Throwable $e){$bad=true;}
 p35a($bad,"Database allowed a second facility line.");$checks++;
 $bad=false;try{$pdo->prepare("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity)VALUES(?,?,'Equipment',1)")->execute([$reqC,$itemA]);}catch(Throwable $e){$bad=true;}
 p35a($bad,"Database allowed a duplicate resource line.");$checks++;
 $bad=false;try{$pdo->prepare("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity)VALUES(?,?,'Equipment',0)")->execute([$reqB,$itemA]);}catch(Throwable $e){$bad=true;}
 p35a($bad,"Database allowed a zero quantity line.");$checks++;

 // ---- Availability and conflict read from lines ------------------------
 $facilityConflicts=array_map(static fn(array $r):int=>(int)$r["request_id"],findScheduleConflicts($pdo,$hallB,"Facility",$day,"08:00:00","10:00:00",null,0));
 p35a(in_array($reqC,$facilityConflicts,true),"Facility line not visible to the conflict service.");
 $equipConflicts=array_map(static fn(array $r):int=>(int)$r["request_id"],findScheduleConflicts($pdo,$itemB,"Item",$day,"08:00:00","10:00:00",null,0));
 p35a(in_array($reqA,$equipConflicts,true)&&in_array($reqC,$equipConflicts,true),"Extra equipment lines are invisible to availability.");
 p35a(!in_array($reqB,$equipConflicts,true),"An unrelated request appeared in the equipment conflict set.");
 // Reserved quantity must count every line, not just the mirrored one.
 $reserved=calculateReservedItemQuantity($pdo,$itemB,$day,"08:00:00","10:00:00",null,null);
 p35a($reserved>=4,"Reserved quantity ignored non-mirrored lines (got {$reserved}).");
 $checks+=4;
 // An equipment-only request has no facility line, so it never enters
 // facility prioritisation.
 require_once $root."/includes/director_approval_helper.php";
 $rowA=$pdo->query("SELECT * FROM resource_requests WHERE request_id={$reqA}")->fetch(PDO::FETCH_ASSOC);
 p35a(gsoFacilityConflictsForRequest($pdo,$rowA)===[],"Equipment-only request entered facility prioritisation.");
 $rowC=$pdo->query("SELECT * FROM resource_requests WHERE request_id={$reqC}")->fetch(PDO::FETCH_ASSOC);
 p35a(gsoRequestFacilityLine($pdo,$reqC)!==null,"Facility line not resolvable for a combined request.");
 $checks+=2;

 // ---- Adviser and Director stay request-level --------------------------
 p35h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$reqE,"action"=>"require_adviser"]);
 p35a((string)p35v($pdo,"SELECT adviser_state FROM resource_requests WHERE request_id=?",[$reqE])==="Selection Required","Adviser routing failed for a combined request.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME LIKE '%adviser%'")===0,"Adviser state leaked onto request lines.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_items' AND COLUMN_NAME LIKE '%director%'")===0,"Director state leaked onto request lines.");
 p35h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$reqC,"action"=>"require_director"]);
 p35a((string)p35v($pdo,"SELECT director_state FROM resource_requests WHERE request_id=?",[$reqC])==="Pending","Director routing failed for a combined request.");
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items WHERE request_id=?",[$reqC])===3,"Routing changed the request's lines.");
 $checks+=5;

 // ---- Release and return safety ---------------------------------------
 // Phase 3.5B delivers serialized allocation, consumable issuance and
 // per-resource return inspection, so these shapes are now supported rather
 // than deferred. Only a request with no lines at all remains unfulfillable.
 p35a(gsoRequestFulfilmentBlocker($pdo,$reqA)===null,"Multi-returnable-line release is still blocked.");
 p35a(gsoRequestFulfilmentBlocker($pdo,$reqD)===null,"Consumable-bearing release is still blocked.");
 p35a(gsoRequestFulfilmentBlocker($pdo,$reqB)===null,"Facility-only release was wrongly blocked.");
 $submit("other",$serial,1,[],date("Y-m-d",strtotime("+22 days")));
 $reqSerial=$newest($ids["other"]);
 p35a(gsoRequestFulfilmentBlocker($pdo,$reqSerial)===null,"Serialized release is still blocked.");
 $checks+=4;
 // A release that cannot be fulfilled must mutate nothing at all. The serialized
 // line has no registered units, so the whole request must roll back.
 $pdo->prepare("UPDATE resource_requests SET status='Approved' WHERE request_id=?")->execute([$reqSerial]);
 $stockPre=(string)p35v($pdo,"SELECT CONCAT(IFNULL(available_stock,'N'),'/',IFNULL(total_stock,'N')) FROM resources WHERE resource_id=?",[$serial]);
 p35h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$reqSerial,"action"=>"release"]);
 p35a((string)p35v($pdo,"SELECT status FROM resource_requests WHERE request_id=?",[$reqSerial])==="Approved","An unfulfillable request was released.");
 p35a((string)p35v($pdo,"SELECT CONCAT(IFNULL(available_stock,'N'),'/',IFNULL(total_stock,'N')) FROM resources WHERE resource_id=?",[$serial])===$stockPre,"A failed release left a partial stock deduction.");
 p35a((int)p35v($pdo,"SELECT COALESCE(SUM(released_quantity),0) FROM request_items WHERE request_id=?",[$reqSerial])===0,"A failed release marked lines as released.");
 $pdo->prepare("UPDATE resource_requests SET status='Pending' WHERE request_id=?")->execute([$reqSerial]);
 $checks+=3;
 // Positive control: a single-line request still releases and deducts once.
 $pdo->prepare("UPDATE resource_requests SET status='Approved' WHERE request_id=?")->execute([$reqMerge]);
 $availPre=(int)p35v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$itemA]);
 p35h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$reqMerge,"action"=>"release"]);
 p35a((string)p35v($pdo,"SELECT status FROM resource_requests WHERE request_id=?",[$reqMerge])==="Released","Single-line release regressed.");
 p35a((int)p35v($pdo,"SELECT available_stock FROM resources WHERE resource_id=?",[$itemA])===$availPre-5,"Release deducted the wrong quantity.");
 p35a((int)p35v($pdo,"SELECT released_quantity FROM request_items WHERE request_id=?",[$reqMerge])===5,"Release did not record the line quantity.");
 $checks+=3;
 // A combined request now reaches the return form, and it must offer EVERY
 // returnable resource rather than one condition for the whole request.
 $pdo->prepare("UPDATE resource_requests SET status='Released' WHERE request_id=?")->execute([$reqA]);
 $pdo->prepare("UPDATE request_items SET released_quantity=requested_quantity WHERE request_id=?")->execute([$reqA]);
 $ret=p35h($base,"borrower/return_submit.php?request_id={$reqA}",$cookies["student"]);
 p35a(!str_contains($ret["body"],"Reported Condition"),"A combined request still shows a single request-wide condition.");
 p35a(str_contains($ret["body"],htmlspecialchars("{$mark} Item A"))&&str_contains($ret["body"],htmlspecialchars("{$mark} Item B")),"The return form omitted a returnable resource.");
 $pdo->prepare("UPDATE resource_requests SET status='Pending' WHERE request_id=?")->execute([$reqA]);
 $pdo->prepare("UPDATE request_items SET released_quantity=0 WHERE request_id=?")->execute([$reqA]);
 $checks+=2;

 // ---- Display and reporting must not fan out ---------------------------
 // Scoped by search so the assertions do not depend on pagination.
 $mine=p35h($base,"borrower/my_requests.php",$cookies["student"]);
 // One table row per request: the reference cell is what would multiply if
 // request lines were joined instead of summarized.
 p35a(substr_count($mine["body"],"<td>REQ-".str_pad((string)$reqA,3,"0",STR_PAD_LEFT)."</td>")===1,"Borrower list showed a combined request more than once.");
 p35a(str_contains($mine["body"],htmlspecialchars("{$mark} Item B")),"Borrower list hid a non-mirrored resource line.");
 $hist=p35h($base,"borrower/history.php",$cookies["student"]);
 p35a(substr_count($hist["body"],"<td>REQ-".str_pad((string)$reqA,3,"0",STR_PAD_LEFT)."</td>")<=1,"Borrower history duplicated a combined request.");
 $adminList=p35h($base,"admin/requests.php?status=".urlencode("Pending Director"),$cookies["admin"]);
 p35a(substr_count($adminList["body"],">REQ-".str_pad((string)$reqC,3,"0",STR_PAD_LEFT)."<")===1,"Admin queue duplicated a combined request.");
 $adminDetail=p35h($base,"admin/view_request.php?request_id={$reqC}",$cookies["admin"]);
 foreach(["Hall B","Item A","Item B"] as $label)p35a(str_contains($adminDetail["body"],htmlspecialchars("{$mark} {$label}")),"GSO detail omitted line {$label}.");
 $checks+=7;
 // Request-level statistics stay request-level.
 $distinct=(int)p35v($pdo,"SELECT COUNT(DISTINCT rr.request_id) FROM resource_requests rr JOIN request_items ri ON ri.request_id=rr.request_id WHERE rr.borrower_id=?",[$ids["student"]]);
 $headerCount=(int)p35v($pdo,"SELECT COUNT(*) FROM resource_requests WHERE borrower_id=?",[$ids["student"]]);
 p35a($distinct===$headerCount,"Joining request lines inflated a request-level count.");$checks++;

 // ---- Chatbot stays read-only and ownership-scoped ---------------------
 require_once $root."/includes/ai_live_data_helper.php";
 $owner=["user_id"=>$ids["other"],"role"=>"Borrower","full_name"=>"P35 other"];
 $answer=gsoChatGetRequestStatus($pdo,$owner,"status of REQ-".str_pad((string)$reqC,3,"0",STR_PAD_LEFT),[]);
 $answerText=json_encode($answer);
 p35a(str_contains($answerText,"{$mark} Hall B")&&str_contains($answerText,"{$mark} Item A"),"Chatbot hid authorized resource lines.");
 $stranger=["user_id"=>$ids["student"],"role"=>"Borrower","full_name"=>"P35 student"];
 $denied=json_encode(gsoChatGetRequestStatus($pdo,$stranger,"status of REQ-".str_pad((string)$reqC,3,"0",STR_PAD_LEFT),[]));
 p35a(!str_contains($denied,"{$mark} Hall B"),"Chatbot leaked another borrower's request lines.");
 $linesPre=(int)p35v($pdo,"SELECT COUNT(*) FROM request_items");
 gsoChatGetRequestStatus($pdo,$owner,"release REQ-".str_pad((string)$reqC,3,"0",STR_PAD_LEFT)." and issue everything",[]);
 p35a((int)p35v($pdo,"SELECT COUNT(*) FROM request_items")===$linesPre&&(string)p35v($pdo,"SELECT status FROM resource_requests WHERE request_id=?",[$reqC])!=="Released","Chatbot mutated a request.");
 $checks+=3;

 echo "PASS: Phase 3.5A combined borrowing E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}
finally{
 if(is_file($boot))@unlink($boot);
 if($res){
  $resourceIds=array_values($res);$marks=implode(",",array_fill(0,count($resourceIds),"?"));
  // Fixture identity is proven by BOTH the resource and the borrower: a
  // request that merely shares a fixture resource is never assumed to be ours.
  $userMarks=implode(",",array_fill(0,count($ids),"?"));
  $reqStmt=$pdo->prepare("SELECT DISTINCT ri.request_id FROM request_items ri JOIN resource_requests rr ON rr.request_id=ri.request_id WHERE ri.resource_id IN ({$marks}) AND rr.borrower_id IN ({$userMarks})");$reqStmt->execute(array_merge($resourceIds,array_values($ids)));
  $fixtureRequests=$reqStmt->fetchAll(PDO::FETCH_COLUMN)?:[];
  foreach($fixtureRequests as $rid){
   $pdo->prepare("DELETE FROM notifications WHERE link LIKE :l")->execute([":l"=>"%request_id={$rid}"]);
   $pdo->prepare("DELETE FROM request_director_history WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_adviser_history WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM return_submissions WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM request_items WHERE request_id=:r")->execute([":r"=>$rid]);
   $pdo->prepare("DELETE FROM resource_requests WHERE request_id=:r")->execute([":r"=>$rid]);
  }
  $pdo->prepare("DELETE FROM resources WHERE resource_id IN ({$marks})")->execute($resourceIds);
 }
 $pdo->prepare("DELETE FROM resources WHERE resource_name LIKE :m")->execute([":m"=>"{$mark}%"]);
 foreach($ids as $id){$pdo->prepare("DELETE FROM notifications WHERE user_id=:i")->execute([":i"=>$id]);$pdo->prepare("DELETE FROM activity_logs WHERE user_id=:i")->execute([":i"=>$id]);}
 $pdo->prepare("DELETE FROM users WHERE full_name LIKE :m")->execute([":m"=>"{$mark}%"]);
 foreach($cookies as $c)if(is_string($c)&&is_file($c))@unlink($c);
 // A cleanup that quietly fails is how a stray fixture resource survived a
 // previous run. Prove the sweep worked instead of assuming it did.
 $leftoverResources=(int)$pdo->query("SELECT COUNT(*) FROM resources WHERE resource_name LIKE '{$mark}%'")->fetchColumn();
 $leftoverUsers=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE full_name LIKE '{$mark}%'")->fetchColumn();
 $orphanLines=(int)$pdo->query("SELECT COUNT(*) FROM request_items ri LEFT JOIN resource_requests rr ON rr.request_id=ri.request_id WHERE rr.request_id IS NULL")->fetchColumn();
 if($leftoverResources||$leftoverUsers||$orphanLines){fwrite(STDERR,"FAIL: fixture cleanup incomplete (resources={$leftoverResources}, users={$leftoverUsers}, orphan lines={$orphanLines}).
");if(!$failed)$failed=new RuntimeException("cleanup incomplete");}
}
if($failed)exit(1);
