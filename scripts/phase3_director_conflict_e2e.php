<?php
declare(strict_types=1);
/**
 * Phase 3 end-to-end suite: Campus Director approval and facility conflict
 * prioritisation. Fixtures are marked, created here, and always swept in the
 * finally block so the suite is safe to re-run on a working database.
 */
$root=dirname(__DIR__);require_once $root."/config/bootstrap.php";require_once $root."/config/db.php";require_once $root."/includes/request_helper.php";
final class P3Fail extends RuntimeException{}
function p3a(bool $v,string $m):void{if(!$v)throw new P3Fail($m);}
function p3v(PDO $p,string $q,array $a=[]):mixed{$s=$p->prepare($q);$s->execute($a);return $s->fetchColumn();}
function p3h(string $b,string $p,string $c,string $m="GET",array $d=[],array $heads=[]):array{$x=curl_init(rtrim($b,"/")."/".ltrim($p,"/"));curl_setopt_array($x,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$c,CURLOPT_COOKIEFILE=>$c,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>$heads]);if($m==="POST"){curl_setopt($x,CURLOPT_POST,true);curl_setopt($x,CURLOPT_POSTFIELDS,http_build_query($d));}$r=curl_exec($x);p3a(is_string($r),"HTTP failed: ".curl_error($x));$n=(int)curl_getinfo($x,CURLINFO_HEADER_SIZE);$s=(int)curl_getinfo($x,CURLINFO_RESPONSE_CODE);curl_close($x);return["status"=>$s,"body"=>substr($r,$n)];}
function p3user(PDO $p,string $mark,string $key,string $role,?string $type,string $status="Approved"):int{$u="p3_{$key}_".strtolower(substr($mark,3));$s=$p->prepare("INSERT INTO users(full_name,department,university_id,email,role,account_type,username,password_hash,account_status,email_verified)VALUES(:n,'Phase 3 QA',:uid,:e,:r,:t,:u,:pw,:st,1)");$s->execute([":n"=>"{$mark} {$key}",":uid"=>strtoupper(substr(md5($key),0,4)).substr($mark,3),":e"=>"{$u}@example.invalid",":r"=>$role,":t"=>$type,":u"=>$u,":pw"=>password_hash("P3!{$mark}",PASSWORD_DEFAULT),":st"=>$status]);return(int)$p->lastInsertId();}

$base=(string)(getenv("GSO_PHASE3_BASE_URL")?:"http://127.0.0.1/GSO_WebSystem");
p3a(in_array((string)parse_url($base,PHP_URL_HOST),["127.0.0.1","localhost","::1"],true),"Loopback only.");

$tok=bin2hex(random_bytes(8));$mark="P3-".strtoupper($tok);$ids=[];$cookies=[];$itemId=0;$facilityId=0;$facilityId2=0;$boot=$root."/phase3_session_{$tok}.php";$checks=0;$failed=null;
try{
 $spec=["admin"=>["Admin",null],"staff"=>["GSO Staff",null],"director"=>["Director",null],"director2"=>["Director",null],"student"=>["Borrower","Student"],"other"=>["Borrower","University Staff"],"third"=>["Borrower","University Staff"],"fourth"=>["Borrower","University Staff"],"faculty"=>["Borrower","Faculty"]];
 foreach($spec as $k=>$v)$ids[$k]=p3user($pdo,$mark,$k,$v[0],$v[1]);

 $mkResource=function(string $name,string $type)use($pdo):int{$s=$pdo->prepare("INSERT INTO resources(resource_name,resource_type,tracking_mode,category,location,total_stock,available_stock,status,condition_status,is_archived)VALUES(:n,:t,:m,'Equipment','QA',10,10,'Available','Good',0)");$s->execute([":n"=>$name,":t"=>$type,":m"=>$type==="Facility"?"Facility":"Aggregate"]);return(int)$pdo->lastInsertId();};
 $itemId=$mkResource("{$mark} Item","Item");$facilityId=$mkResource("{$mark} Hall","Facility");$facilityId2=$mkResource("{$mark} Annex","Facility");

 $day=date("Y-m-d",strtotime("+9 days"));$day2=date("Y-m-d",strtotime("+10 days"));$day3=date("Y-m-d",strtotime("+11 days"));
 $newReq=function(int $uid,int $res,string $date,?string $start=null,?string $end=null,string $notes="Phase 3 activity")use($pdo):int{$s=$pdo->prepare("INSERT INTO resource_requests(borrower_id,resource_id,quantity,contact_number,date_needed,start_time,end_time,status,notes)VALUES(:u,:r,1,'09123456789',:d,:s,:e,'Pending',:n)");$s->execute([":u"=>$uid,":r"=>$res,":d"=>$date,":s"=>$start,":e"=>$end,":n"=>$notes]);$id=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity) SELECT :rid,:res,CASE WHEN r.resource_type='Facility' THEN 'Facility' WHEN r.tracking_mode='Consumable' THEN 'Consumable' ELSE 'Equipment' END,:qty FROM resources r WHERE r.resource_id=:res2")->execute([":rid"=>$id,":res"=>$res,":qty"=>1,":res2"=>$res]);return $id;};

 // Session bootstrap: loopback + secret header only, removed in finally.
 $people=[];foreach(["admin","staff","director","director2","student","other","third","fourth","faculty"] as $k)$people[$k]=[$ids[$k],$spec[$k][0]];
 $src="<?php require_once __DIR__.'/config/bootstrap.php';gsoSecureSessionStart();if(!in_array((string)(\$_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true)||!hash_equals('{$tok}',(string)(\$_SERVER['HTTP_X_P3_TOKEN']??''))){http_response_code(404);exit;}\$p=".var_export($people,true).";\$k=(string)(\$_POST['persona']??'');if(!isset(\$p[\$k])){http_response_code(400);exit;}\$_SESSION=[];session_regenerate_id(true);\$_SESSION['user_id']=\$p[\$k][0];\$_SESSION['role']=\$p[\$k][1];\$_SESSION['full_name']='P3 '.\$k;\$_SESSION['email']='p3@example.invalid';\$_SESSION['csrf_token']=bin2hex(random_bytes(32));header('Content-Type: application/json');echo json_encode(['csrf_token'=>\$_SESSION['csrf_token']]);";
 p3a(file_put_contents($boot,$src)!==false,"Bootstrap file failed.");$csrf=[];
 foreach($people as $k=>$v){$cookies[$k]=tempnam(sys_get_temp_dir(),"p3_");$r=p3h($base,basename($boot),$cookies[$k],"POST",["persona"=>$k],["X-P3-Token: {$tok}"]);$j=json_decode($r["body"],true);p3a($r["status"]===200&&is_array($j),"Bootstrap {$k} failed.");$csrf[$k]=$j["csrf_token"];}
 $route=function(string $who,int $rid)use($base,&$cookies,&$csrf):array{return p3h($base,"admin/requests.php",$cookies[$who],"POST",["csrf_token"=>$csrf[$who],"request_id"=>$rid,"action"=>"require_director"]);};
 $dstate=function(int $rid)use($pdo):string{return (string)p3v($pdo,"SELECT director_state FROM resource_requests WHERE request_id=?",[$rid]);};
 $rstatus=function(int $rid)use($pdo):string{return (string)p3v($pdo,"SELECT status FROM resource_requests WHERE request_id=?",[$rid]);};
 $decide=function(string $who,int $rid,string $action,string $remarks="",?string $token=null)use($base,&$cookies,&$csrf):array{return p3h($base,"director/request.php?request_id={$rid}",$cookies[$who],"POST",["csrf_token"=>$token??$csrf[$who],"request_id"=>$rid,"action"=>$action,"remarks"=>$remarks]);};

 // ---- Routing authorization -------------------------------------------
 $rid=$newReq($ids["other"],$itemId,$day);
 foreach(["staff","student","director","other"] as $who){$route($who,$rid);p3a($dstate($rid)==="Not Required","{$who} routed a request to the Director.");$checks++;}
 p3h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>"forged","request_id"=>$rid,"action"=>"require_director"]);p3a($dstate($rid)==="Not Required","Admin require_director CSRF bypassed.");$checks++;
 $route("admin",$rid);p3a($dstate($rid)==="Pending","Admin could not route to Director.");
 p3a((int)p3v($pdo,"SELECT director_required_by FROM resource_requests WHERE request_id=?",[$rid])===$ids["admin"]&&p3v($pdo,"SELECT director_required_at FROM resource_requests WHERE request_id=?",[$rid])!==null,"Routing actor/timestamp missing.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_pending'",[$ids["other"]])===1,"Borrower not notified of Director routing.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_pending'",[$ids["director"]])===1,"Director not notified of routing.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action='Director Approval Required'",[$ids["admin"]])===1,"Routing not logged.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action='Director Email Notification Failed'",[$ids["admin"]])===1,"Director email failure not logged.");$checks+=5;
 $route("admin",$rid);p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$rid])===0&&$dstate($rid)==="Pending","Double routing changed state.");$checks++;

 // ---- Adviser gate ordering -------------------------------------------
 $advRid=$newReq($ids["student"],$itemId,$day);
 foreach(["Selection Required","Pending Adviser","Revision Required"] as $blockedState){
  $pdo->prepare("UPDATE resource_requests SET adviser_state=?,adviser_user_id=? WHERE request_id=?")->execute([$blockedState,$blockedState==="Selection Required"?null:$ids["faculty"],$advRid]);
  $route("admin",$advRid);p3a($dstate($advRid)==="Not Required","Adviser state {$blockedState} did not block Director routing.");$checks++;
 }
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Endorsed',adviser_user_id=? WHERE request_id=?")->execute([$ids["faculty"],$advRid]);
 $route("admin",$advRid);p3a($dstate($advRid)==="Pending","Adviser-endorsed request could not be routed.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Pending Adviser' WHERE request_id=?")->execute([$advRid]);
 $decide("director",$advRid,"approve");p3a($dstate($advRid)==="Pending","Director decided while Adviser workflow was pending.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Endorsed' WHERE request_id=?")->execute([$advRid]);
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_adviser_history WHERE request_id=?",[$advRid])===0,"Adviser history unexpectedly written.");$checks++;

 // Ineligible states: already Approved, and already Pending Director.
 $approvedRid=$newReq($ids["other"],$itemId,$day);$pdo->prepare("UPDATE resource_requests SET status='Approved' WHERE request_id=?")->execute([$approvedRid]);
 $route("admin",$approvedRid);p3a($dstate($approvedRid)==="Not Required","Approved request was routed to the Director.");$checks++;

 // ---- Queue visibility -------------------------------------------------
 $quiet=$newReq($ids["other"],$itemId,$day2);
 $ref=formatRequestCode($rid);$quietRef=formatRequestCode($quiet);
 $dash=p3h($base,"director/dashboard.php",$cookies["director"]);
 p3a(str_contains($dash["body"],$ref),"Routed request missing from Director queue.");
 p3a(!str_contains($dash["body"],$quietRef),"Non-routed request exposed in Director queue.");$checks+=2;
 foreach(["admin","staff","student","other"] as $who){$d=p3h($base,"director/dashboard.php",$cookies[$who]);p3a(!str_contains($d["body"],$ref),"Director dashboard exposed to {$who}.");$checks++;}
 $anon=tempnam(sys_get_temp_dir(),"p3anon_");$cookies["anon"]=$anon;
 $g=p3h($base,"director/request.php?request_id={$rid}",$anon);p3a(!str_contains($g["body"],"Record Decision"),"Unauthenticated Director detail leaked.");
 $decide("anon",$rid,"approve","","anon-token"); p3a($dstate($rid)==="Pending","Unauthenticated Director decision mutated the request.");$checks+=2;

 // ---- Forged / unauthorized decisions ---------------------------------
 // The Admin role holds the "*" permission wildcard, so the Director
 // workspace must be gated on the database role, not on permissions alone.
 foreach(["admin","staff","student","other"] as $who){$v=p3h($base,"director/request.php?request_id={$rid}",$cookies[$who]);p3a($v["status"]===302&&!str_contains($v["body"],"Director Review"),"{$who} reached the Director request detail.");$checks++;}
 foreach(["admin","staff","student","other"] as $who){$decide($who,$rid,"approve");p3a($dstate($rid)==="Pending","{$who} forged a Director approval.");$checks++;}
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$rid])===0,"Unauthorized actor wrote Director history.");$checks++;
 $decide("director",$rid,"approve","",'forged-token');p3a($dstate($rid)==="Pending","Director CSRF bypassed.");$checks++;
 $decide("director",$rid,"sabotage");p3a($dstate($rid)==="Pending","Unknown Director action accepted.");$checks++;
 $decide("director",$rid,"approve",str_repeat("x",1001));p3a($dstate($rid)==="Pending","Oversized remarks accepted.");$checks++;
 $decide("director",$quiet,"approve");p3a($dstate($quiet)==="Not Required","Director decided a request that was never routed.");$checks++;

 // ---- GSO hard stop while Director pending ----------------------------
 foreach(["admin","staff"] as $who){foreach(["review","approve","reject","release"] as $act){p3h($base,"admin/requests.php",$cookies[$who],"POST",["csrf_token"=>$csrf[$who],"request_id"=>$rid,"action"=>$act]);}p3a($rstatus($rid)==="Under Review"&&$dstate($rid)==="Pending","{$who} bypassed the pending Director gate.");$checks++;}
 $pdo->prepare("UPDATE resource_requests SET status='Approved' WHERE request_id=?")->execute([$rid]);
 foreach(["admin","staff"] as $who){p3h($base,"admin/requests.php",$cookies[$who],"POST",["csrf_token"=>$csrf[$who],"request_id"=>$rid,"action"=>"release"]);p3a($rstatus($rid)==="Approved","{$who} released a request pending Director approval.");$checks++;}
 $pdo->prepare("UPDATE resource_requests SET status='Under Review' WHERE request_id=?")->execute([$rid]);

 // ---- Normal Director approval ----------------------------------------
 $xss="<script>alert('p3')</script>";
 $decide("director",$rid,"approve",$xss);
 p3a($dstate($rid)==="Approved"&&$rstatus($rid)==="Approved","Director approval failed.");
 p3a((int)p3v($pdo,"SELECT approved_by FROM resource_requests WHERE request_id=?",[$rid])===$ids["director"]&&p3v($pdo,"SELECT approved_at FROM resource_requests WHERE request_id=?",[$rid])!==null,"Approval actor/timestamp missing.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=? AND action='Approved' AND director_user_id=?",[$rid,$ids["director"]])===1,"Approval history missing.");
 p3a(p3v($pdo,"SELECT remarks FROM request_director_history WHERE request_id=? AND action='Approved'",[$rid])===$xss,"Remarks not stored verbatim.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_approved'",[$ids["other"]])===1,"Borrower not notified of approval.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_decision'",[$ids["admin"]])===1,"GSO Head not notified of decision.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_decision'",[$ids["staff"]])===1,"GSO Staff not notified of decision.");$checks+=7;
 $detail=p3h($base,"director/request.php?request_id={$rid}",$cookies["director"]);
 p3a(!str_contains($detail["body"],$xss)&&str_contains($detail["body"],"&lt;script&gt;"),"Director remarks XSS not escaped.");$checks++;
 $adminView=p3h($base,"admin/view_request.php?request_id={$rid}",$cookies["admin"]);
 p3a(str_contains($adminView["body"],"Campus Director Approval")&&!str_contains($adminView["body"],$xss),"GSO Director panel missing or unescaped.");$checks++;
 $before=(int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$rid]);
 $decide("director",$rid,"approve");$decide("director",$rid,"reject");$decide("director2",$rid,"reject");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$rid])===$before&&$dstate($rid)==="Approved","Duplicate or stale Director decision recorded.");$checks++;
 // Positive control: release must still succeed once the Director approved.
 p3h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$rid,"action"=>"release"]);
 p3a($rstatus($rid)==="Released"&&(int)p3v($pdo,"SELECT released_by FROM resource_requests WHERE request_id=?",[$rid])===$ids["admin"],"Director-approved request could not be released.");$checks++;

 // ---- Normal Director rejection ---------------------------------------
 $rejRid=$newReq($ids["other"],$itemId,$day2);$route("admin",$rejRid);p3a($dstate($rejRid)==="Pending","Rejection fixture routing failed.");$checks++;
 $decide("director",$rejRid,"reject","Not endorsed by the campus.");
 p3a($dstate($rejRid)==="Rejected"&&$rstatus($rejRid)==="Rejected","Director rejection failed.");
 p3a(p3v($pdo,"SELECT approved_by FROM resource_requests WHERE request_id=?",[$rejRid])===null,"Rejected request retained an approver.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=? AND action='Rejected'",[$rejRid])===1,"Rejection history missing.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=? AND type='director_rejected'",[$ids["other"]])===1,"Borrower not notified of rejection.");$checks+=4;
 $decide("director",$rejRid,"approve");p3a($dstate($rejRid)==="Rejected"&&(int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$rejRid])===1,"Stale approve after rejection accepted.");$checks++;
 foreach(["admin","staff"] as $who){foreach(["approve","release"] as $act){p3h($base,"admin/requests.php",$cookies[$who],"POST",["csrf_token"=>$csrf[$who],"request_id"=>$rejRid,"action"=>$act]);}p3a($rstatus($rejRid)==="Rejected","{$who} overrode a Director rejection.");$checks++;}

 // ---- Facility conflict detection -------------------------------------
 $cA=$newReq($ids["other"],$facilityId,$day,"08:00:00","10:00:00","Seminar A");
 $cB=$newReq($ids["third"],$facilityId,$day,"09:00:00","11:00:00","Seminar B");
 $cC=$newReq($ids["fourth"],$facilityId,$day,"09:30:00","10:30:00","Seminar C");
 $noOverlap=$newReq($ids["other"],$facilityId,$day,"14:00:00","16:00:00","Late session");
 $otherFacility=$newReq($ids["third"],$facilityId2,$day,"08:00:00","10:00:00","Different hall");
 $cancelled=$newReq($ids["fourth"],$facilityId,$day,"08:30:00","09:30:00","Cancelled session");$pdo->prepare("UPDATE resource_requests SET status='Cancelled' WHERE request_id=?")->execute([$cancelled]);
 $rowA=$pdo->query("SELECT rr.*,r.resource_type FROM resource_requests rr JOIN request_items ri ON ri.request_id=rr.request_id JOIN resources r ON r.resource_id=ri.resource_id WHERE rr.request_id={$cA} AND ri.line_kind='Facility'")->fetch(PDO::FETCH_ASSOC);
 $conf=array_map(static fn(array $r):int=>(int)$r["request_id"],findScheduleConflicts($pdo,$facilityId,"Facility",$rowA["date_needed"],$rowA["start_time"],$rowA["end_time"],$rowA["due_date"],$cA));
 sort($conf);$expect=[$cB,$cC];sort($expect);
 p3a($conf===$expect,"Conflict set wrong: got ".implode(",",$conf)." expected ".implode(",",$expect));
 p3a(!in_array($noOverlap,$conf,true),"Non-overlapping request treated as a conflict.");
 p3a(!in_array($otherFacility,$conf,true),"Different facility treated as a conflict.");
 p3a(!in_array($cancelled,$conf,true),"Cancelled request treated as a conflict.");
 p3a(findScheduleConflict($pdo,$facilityId,"Facility",$rowA["date_needed"],$rowA["start_time"],$rowA["end_time"],$rowA["due_date"],$cA)!==null,"Legacy single-conflict API regressed.");$checks+=5;

 // ---- Three-way prioritisation ----------------------------------------
 $route("admin",$cA);p3a($dstate($cA)==="Pending","Conflict candidate routing failed.");$checks++;
 $detailA=p3h($base,"director/request.php?request_id={$cA}",$cookies["director"]);
 p3a(str_contains($detailA["body"],formatRequestCode($cB))&&str_contains($detailA["body"],formatRequestCode($cC)),"Competing requests not shown to the Director.");
 p3a(!str_contains($detailA["body"],formatRequestCode($noOverlap)),"Non-competing request shown as a conflict.");
 p3a(str_contains($detailA["body"],"Prioritize Request")&&!str_contains($detailA["body"],'value="approve"'),"Conflict case did not force prioritisation.");$checks+=3;
 $decide("director",$cA,"approve");p3a($dstate($cA)==="Pending","Plain approve accepted despite an active facility conflict.");$checks++;
 foreach(["admin","staff","student"] as $who){$decide($who,$cA,"prioritize");p3a($dstate($cA)==="Pending","{$who} forged a prioritisation.");$checks++;}
 $decide("director",$cA,"prioritize","Approved per campus calendar.");
 p3a($dstate($cA)==="Approved"&&$rstatus($cA)==="Approved","Winner was not approved.");
 foreach([$cB,$cC] as $loser){p3a($dstate($loser)==="Rejected"&&$rstatus($loser)==="Rejected","Loser ".formatRequestCode($loser)." was not rejected.");$checks++;}
 p3a($rstatus($noOverlap)==="Pending"&&$dstate($noOverlap)==="Not Required","Unrelated non-overlapping request was affected.");
 p3a($rstatus($otherFacility)==="Pending","Request on a different facility was affected.");$checks+=3;
 $batch=(string)p3v($pdo,"SELECT decision_batch FROM request_director_history WHERE request_id=? AND action='Prioritized'",[$cA]);
 p3a($batch!==""&&(int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE decision_batch=?",[$batch])===3,"Decision batch did not capture all three outcomes.");
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE decision_batch=? AND winner_request_id=?",[$batch,$cA])===3,"Batch winner reference missing.");
 foreach([$cB,$cC] as $loser){p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=? AND action='Rejected by Prioritization'",[$loser])===1,"Loser history missing.");p3a((int)p3v($pdo,"SELECT COUNT(*) FROM notifications WHERE user_id=(SELECT borrower_id FROM resource_requests WHERE request_id=?) AND type='director_rejected'",[$loser])===1,"Loser borrower not notified.");$checks+=2;}
 p3a((int)p3v($pdo,"SELECT COUNT(*) FROM activity_logs WHERE user_id=? AND action='Director Conflict Rejection'",[$ids["director"]])===2,"Conflict rejections not logged.");$checks+=3;
 $loserView=p3h($base,"borrower/my_requests.php",$cookies["third"]);
 p3a(str_contains($loserView["body"],"Rejected due to facility prioritisation"),"Borrower does not see the prioritisation outcome.");$checks++;
 $decide("director",$cA,"prioritize");p3a((int)p3v($pdo,"SELECT COUNT(*) FROM request_director_history WHERE request_id=?",[$cA])===1,"Duplicate prioritisation recorded.");$checks++;
 p3h($base,"admin/requests.php",$cookies["admin"],"POST",["csrf_token"=>$csrf["admin"],"request_id"=>$cB,"action"=>"approve"]);
 p3a($rstatus($cB)==="Rejected","GSO Head revived a prioritisation loser.");$checks++;

 // ---- Stale conflict: overlap removed before the decision --------------
 $sA=$newReq($ids["other"],$facilityId,$day2,"08:00:00","10:00:00","Stale A");
 $sB=$newReq($ids["third"],$facilityId,$day2,"09:00:00","11:00:00","Stale B");
 $route("admin",$sA);p3a($dstate($sA)==="Pending","Stale-case routing failed.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET start_time='15:00:00',end_time='17:00:00' WHERE request_id=?")->execute([$sB]);
 $decide("director",$sA,"prioritize");
 p3a($dstate($sA)==="Pending"&&$rstatus($sB)==="Pending","Stale prioritisation was committed after the conflict disappeared.");$checks++;
 $decide("director",$sA,"approve");p3a($dstate($sA)==="Approved"&&$rstatus($sB)==="Pending","Director could not approve once the conflict cleared.");$checks++;
 // Competitor resolved before the decision must abort the whole batch.
 $tA=$newReq($ids["other"],$facilityId,$day3,"08:00:00","10:00:00","Batch A");
 $tB=$newReq($ids["third"],$facilityId,$day3,"09:00:00","11:00:00","Batch B");
 $route("admin",$tA);$pdo->prepare("UPDATE resource_requests SET status='Cancelled' WHERE request_id=?")->execute([$tB]);
 $decide("director",$tA,"prioritize");
 p3a($rstatus($tB)==="Cancelled","Cancelled competitor was overwritten.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET status='Pending' WHERE request_id=?")->execute([$tB]);
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Pending Adviser',adviser_user_id=? WHERE request_id=?")->execute([$ids["faculty"],$tB]);
 $decide("director",$tA,"prioritize");
 p3a($dstate($tA)==="Pending"&&$rstatus($tB)==="Pending","Adviser-pending competitor was rejected by prioritisation.");$checks++;
 $pdo->prepare("UPDATE resource_requests SET adviser_state='Not Required',adviser_user_id=NULL WHERE request_id=?")->execute([$tB]);
 $decide("director",$tA,"prioritize");
 p3a($dstate($tA)==="Approved"&&$rstatus($tB)==="Rejected","Prioritisation failed once every competitor was eligible.");$checks++;

 echo "PASS: Phase 3 Campus Director and facility conflict E2E ({$checks} checks).\n";
}catch(Throwable $e){$failed=$e;fwrite(STDERR,"FAIL: {$e->getMessage()}\n");}
finally{
 if(is_file($boot))@unlink($boot);
 $resourceIds=array_values(array_filter([$itemId,$facilityId,$facilityId2]));
 if($resourceIds){
  $marks=implode(",",array_fill(0,count($resourceIds),"?"));
  $reqIds=$pdo->prepare("SELECT DISTINCT request_id FROM request_items WHERE resource_id IN ({$marks})");$reqIds->execute($resourceIds);
  foreach($reqIds->fetchAll(PDO::FETCH_COLUMN)?:[] as $cleanupId){$pdo->prepare("DELETE FROM notifications WHERE link LIKE :l")->execute([":l"=>"%request_id={$cleanupId}"]);}
  $pdo->prepare("DELETE FROM request_director_history WHERE request_id IN (SELECT request_id FROM request_items WHERE resource_id IN ({$marks}))")->execute($resourceIds);
  $pdo->prepare("DELETE FROM request_adviser_history WHERE request_id IN (SELECT request_id FROM request_items WHERE resource_id IN ({$marks}))")->execute($resourceIds);
  $userMarks=implode(",",array_fill(0,count($ids),"?"));$pdo->prepare("DELETE FROM resource_requests WHERE borrower_id IN ({$userMarks}) AND request_id IN (SELECT request_id FROM request_items WHERE resource_id IN ({$marks}))")->execute(array_merge(array_values($ids),$resourceIds));
  $pdo->prepare("DELETE rr FROM resource_requests rr JOIN request_items ri ON ri.request_id=rr.request_id WHERE ri.resource_id IN ({$marks})")->execute($resourceIds);
  $pdo->prepare("DELETE FROM resources WHERE resource_id IN ({$marks})")->execute($resourceIds);
 }
 foreach($ids as $id){$pdo->prepare("DELETE FROM notifications WHERE user_id=:i")->execute([":i"=>$id]);$pdo->prepare("DELETE FROM activity_logs WHERE user_id=:i")->execute([":i"=>$id]);}
 $pdo->prepare("DELETE FROM users WHERE full_name LIKE :m")->execute([":m"=>"{$mark}%"]);
 foreach($cookies as $c)if(is_string($c)&&is_file($c))@unlink($c);
}
if($failed)exit(1);
