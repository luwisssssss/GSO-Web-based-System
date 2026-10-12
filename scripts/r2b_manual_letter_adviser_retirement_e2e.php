<?php
declare(strict_types=1);
require_once __DIR__ . '/test_environment_helper.php';
require_once dirname(__DIR__).'/config/bootstrap.php';
if(!preg_match('/^gso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+$/',(string)gsoEnv('GSO_DB_NAME','')))throw new RuntimeException('R2B tests require an isolated gso_r2b_test_* database.');
$base=(string)gsoEnv('GSO_R2B_BASE_URL','http://127.0.0.1:8094/GSO_WebSystem');
if(!in_array(parse_url($base,PHP_URL_HOST),['127.0.0.1','localhost','::1'],true))throw new RuntimeException('Loopback only.');
require_once dirname(__DIR__).'/config/db.php';require_once __DIR__.'/claim_slip_test_helper.php';
require_once __DIR__.'/request_letter_test_helper.php';
$checks=0;$users=[];$cookies=[];$csrf=[];$resources=[];$requests=[];$files=[];$failure=null;
function bAssert(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function bRows(string $sql,array $args=[]):array{global $pdo;$s=$pdo->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
function bVal(string $sql,array $args=[]):mixed{$rows=bRows($sql,$args);return $rows?reset($rows[0]):null;}
function bExec(string $sql,array $args=[]):void{global $pdo;$s=$pdo->prepare($sql);$s->execute($args);}
function bFlat(array $data,string $prefix=''):array{$out=[];foreach($data as $k=>$v){$key=$prefix===''?(string)$k:$prefix.'['.$k.']';if(is_array($v))$out+=bFlat($v,$key);else $out[$key]=$v;}return $out;}
function bHttp(string $who,string $path,?array $data=null):array{
    global $base,$cookies;$c=curl_init($base.'/'.$path);curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$cookies[$who],CURLOPT_COOKIEJAR=>$cookies[$who],CURLOPT_TIMEOUT=>30]);
    if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>bFlat($data)]);
    $raw=curl_exec($c);if(!is_string($raw))throw new RuntimeException(curl_error($c));$n=(int)curl_getinfo($c,CURLINFO_HEADER_SIZE);$status=(int)curl_getinfo($c,CURLINFO_RESPONSE_CODE);curl_close($c);
    $body=substr($raw,$n);if($status>=500||preg_match('/<b>(Warning|Fatal error|Notice)<\/b>:/i',$body))throw new RuntimeException('HTTP/PHP failure at '.$path.': '.substr(strip_tags($body),0,250));
    return ['status'=>$status,'body'=>$body,'headers'=>substr($raw,0,$n)];
}
$mark='R2B-'.strtoupper(bin2hex(random_bytes(6)));$password=$mark.'!Secure';
$retiredTables=fn():int=>(int)bVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN('request_adviser_history','request_director_history')");$retiredColumns=fn():int=>(int)bVal("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='resource_requests' AND (column_name LIKE 'adviser%' OR column_name LIKE 'director%' OR column_name IN('resource_id','quantity'))");
try{
    foreach(['owner'=>['Borrower','Student'],'other'=>['Borrower','University Staff'],'faculty'=>['Borrower','Faculty'],'admin'=>['Admin',null],'staff'=>['GSO Staff',null],'mis'=>['Super Admin',null],'director'=>['Director',null]] as $key=>[$role,$type]){
        $username=strtolower($mark.'_'.$key);bExec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified) VALUES(?,?,?,?,?,?,'Approved',1)",[$mark.' '.$key,$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$type]);$users[$key]=(int)$pdo->lastInsertId();$cookies[$key]=tempnam(sys_get_temp_dir(),'r2b_cookie_');
        $page=bHttp($key,'auth/login.php');if(!preg_match('/name="csrf_token" value="([^"]+)"/',$page['body'],$m))throw new RuntimeException('Login token missing');$csrf[$key]=html_entity_decode($m[1]);
        bAssert(bHttp($key,'auth/login.php',['csrf_token'=>$csrf[$key],'login_input'=>$username,'password'=>$password])['status']===302,'Role login failed: '.$role);
    }
    $cookies['guest']=tempnam(sys_get_temp_dir(),'r2b_guest_');
    $mkResource=function(string $name,string $type,string $mode)use($mark,&$resources,$pdo):int{bExec("INSERT INTO resources(resource_name,resource_type,tracking_mode,category,total_stock,available_stock,status,condition_status) VALUES(?,?,?,'QA',20,20,'Available','Good')",[$mark.' '.$name,$type,$mode]);return $resources[]=(int)$pdo->lastInsertId();};
    $equipment=$mkResource('Equipment','Item','Aggregate');$hall=$mkResource('Hall','Facility','Facility');$paper=$mkResource('Paper','Item','Consumable');
    $png=gsoTestLetterImage();$pdf=tempnam(sys_get_temp_dir(),'r2b_pdf_');$files[]=$pdf;file_put_contents($pdf,"%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
    $bad=tempnam(sys_get_temp_dir(),'r2b_bad_');$files[]=$bad;file_put_contents($bad,'<?php echo "malicious";');
    $poly=tempnam(sys_get_temp_dir(),'r2b_poly_');$files[]=$poly;file_put_contents($poly,file_get_contents($png).'<?php echo "malicious";');
    $active=tempnam(sys_get_temp_dir(),'r2b_active_');$files[]=$active;file_put_contents($active,"%PDF-1.4\n1 0 obj\n<< /JavaScript (alert) >>\nendobj\n%%EOF");
    $submit=function(string $who,int $primary,array $extras=[],?CURLFile $letter=null,int $offset=100,?string $token=null)use(&$csrf):array{
        $data=['csrf_token'=>$token??$csrf[$who],'quantity'=>1,'contact_number'=>'09123456789','date_needed'=>date('Y-m-d',strtotime('+'.$offset.' days')),'start_time'=>'08:00','end_time'=>'10:00','notes'=>'Already manually signed outside the system'];
        global $pdo;$ids=[$primary,...$extras];$m=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT COUNT(*) FROM resources WHERE resource_type='Facility' AND resource_id IN ($m)");$s->execute($ids);if((int)$s->fetchColumn())$data['facility_slot']='AM';
        foreach($extras as $i=>$resource)$data['extra_lines'][$i]=['resource_id'=>$resource,'quantity'=>1];if($letter)$data['request_letter']=$letter;
        return bHttp($who,'borrower/request_resource.php?resource_id='.$primary,$data);
    };
    $count=(int)bVal('SELECT COUNT(*) FROM resource_requests');
    foreach([[$hall,[]],[$equipment,[$hall,$paper]]] as [$primary,$extras]){$submit('owner',$primary,$extras);bAssert((int)bVal('SELECT COUNT(*) FROM resource_requests')===$count,'Facility submission without a letter succeeded');}
    foreach([new CURLFile($bad,'application/pdf','letter.pdf'),new CURLFile($poly,'image/png','letter.png'),new CURLFile($active,'application/pdf','letter.pdf'),new CURLFile($png,'image/png','letter.php')] as $invalid){$submit('owner',$hall,[],$invalid);bAssert((int)bVal('SELECT COUNT(*) FROM resource_requests')===$count,'Unsafe letter accepted');}
    $oversized=tempnam(sys_get_temp_dir(),'r2b_big_');$files[]=$oversized;file_put_contents($oversized,"%PDF-1.4\n".str_repeat(' ',gsoEffectiveUploadLimitBytes(5*1024*1024)+1)."\n%%EOF");
    $submit('owner',$hall,[],new CURLFile($oversized,'application/pdf','large.pdf'));
    bAssert((int)bVal('SELECT COUNT(*) FROM resource_requests')===$count,'Oversized letter was accepted');
    $submit('owner',$hall,[],new CURLFile($pdf,'application/pdf','letter.pdf'),100,'forged');bAssert((int)bVal('SELECT COUNT(*) FROM resource_requests')===$count,'Submission CSRF bypassed');
    $submit('owner',$equipment);$eq=(int)bVal('SELECT MAX(request_id) FROM resource_requests WHERE borrower_id=?',[$users['owner']]);$requests[]=$eq;
    bAssert($eq>0&&bVal('SELECT COUNT(*) FROM request_attachments WHERE request_id=?',[$eq])===0,'Equipment-only submission imposed a letter');
    $submit('faculty',$hall,[],new CURLFile($pdf,'application/octet-stream','signed-letter.pdf'),101);$facility=(int)bVal('SELECT MAX(request_id) FROM resource_requests WHERE borrower_id=?',[$users['faculty']]);$requests[]=$facility;
    bAssert($facility>0,'Faculty facility PDF submission failed');$letter=gsoRequestLetters($pdo,$facility)[0]??null;bAssert($letter!==null,'Document metadata missing');
    foreach(['mime_type'=>'application/pdf','original_file_name'=>'signed-letter.pdf','verification_status'=>'Pending','version_no'=>1,'uploaded_by'=>$users['faculty']] as $field=>$expected)bAssert($letter[$field]==$expected,'Incorrect document metadata: '.$field);
    bAssert((int)$letter['file_size']===filesize($pdf)&&is_file(gsoRequestLetterPath($letter['stored_file_name'])),'File metadata/storage incoherent');
    $submit('owner',$equipment,[$hall,$paper],new CURLFile($png,'image/png','../signed.png'),102);$mixed=(int)bVal('SELECT MAX(request_id) FROM resource_requests WHERE borrower_id=?',[$users['owner']]);$requests[]=$mixed;
    bAssert($mixed!==$eq&&(int)bVal('SELECT COUNT(*) FROM request_items WHERE request_id=?',[$mixed])===3,'Mixed request failed');bAssert((int)bVal('SELECT COUNT(*) FROM request_attachments WHERE request_id=?',[$mixed])===1,'Mixed request did not use one request-level letter');
    $first=gsoRequestLetters($pdo,$mixed)[0];$firstPath=gsoRequestLetterPath($first['stored_file_name']);$firstHash=hash_file('sha256',$firstPath);
    bAssert($first['original_file_name']==='signed.png'&&!str_contains($first['stored_file_name'],'signed'),'Unsafe original/generated name handling');
    bAssert($retiredColumns()===0,'Adviser routing columns still exist on requests');bAssert($retiredTables()===0,'Adviser/Director history storage still exists');
    foreach(['owner'=>200,'admin'=>200,'staff'=>200,'other'=>404,'faculty'=>404,'mis'=>404,'director'=>404,'guest'=>302] as $who=>$expected)bAssert(bHttp($who,'borrower/request_document.php?attachment_id='.$first['attachment_id'])['status']===$expected,'Document access violated for '.$who);
    foreach(['../'.$first['attachment_id'],$first['attachment_id'].'/../../x','0','999999999999999999'] as $id)bAssert(bHttp('owner','borrower/request_document.php?attachment_id='.rawurlencode($id))['status']===404,'Document reference tampering accepted');
    $download=bHttp('owner','borrower/request_document.php?attachment_id='.$first['attachment_id']);bAssert(str_contains($download['headers'],'Content-Disposition: attachment')&&str_contains($download['headers'],'no-store'),'Unsafe download headers');
    bAssert(bHttp('owner','borrower/request_document.php?attachment_id='.$first['attachment_id'].'&file=../../.env')['body']===file_get_contents($firstPath),'Client path changed selected document');
    bAssert(bHttp('guest','uploads/request_letters/'.$first['stored_file_name'])['status']!==200,'Direct unauthenticated file access');
    $verify=function(string $who,int $id,int $attachment,string $action,string $remarks='',?string $token=null)use(&$csrf):array{return bHttp($who,'admin/view_request.php?request_id='.$id,['csrf_token'=>$token??$csrf[$who],'attachment_id'=>$attachment,'action'=>$action,'verification_remarks'=>$remarks]);};
    $approve=function(string $who,int $id)use(&$csrf):array{return bHttp($who,'admin/requests.php',['csrf_token'=>$csrf[$who],'request_id'=>$id,'action'=>'approve']);};
    $status=fn(int $id)=>bVal('SELECT status FROM resource_requests WHERE request_id=?',[$id]);
    $approve('admin',$mixed);bAssert($status($mixed)==='Pending','Direct approval bypassed Pending letter');
    foreach(['owner','other','mis','director'] as $who){$verify($who,$mixed,(int)$first['attachment_id'],'verify_letter');bAssert(bVal('SELECT verification_status FROM request_attachments WHERE attachment_id=?',[$first['attachment_id']])==='Pending','Unauthorized document verification');}
    $verify('admin',$mixed,(int)$first['attachment_id'],'verify_letter','','bad');bAssert(bVal('SELECT verification_status FROM request_attachments WHERE attachment_id=?',[$first['attachment_id']])==='Pending','Verification CSRF bypassed');
    $verify('staff',$mixed,(int)$first['attachment_id'],'reject_letter');bAssert(bVal('SELECT verification_status FROM request_attachments WHERE attachment_id=?',[$first['attachment_id']])==='Pending','Rejection accepted without remarks');
    $verify('staff',$mixed,(int)$first['attachment_id'],'reject_letter','Required manual signature is missing');$rejected=gsoRequestLetters($pdo,$mixed)[0];
    bAssert($rejected['verification_status']==='Rejected'&&(int)$rejected['verified_by']===$users['staff']&&$rejected['verified_at']!==null&&$rejected['verification_remarks']==='Required manual signature is missing','GSO rejection audit invalid');bAssert($status($mixed)==='Pending','Letter rejection rejected borrowing request');
    $approve('admin',$mixed);bAssert($status($mixed)==='Pending','Rejected letter did not block approval');
    bHttp('other','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>$csrf['other'],'request_letter'=>new CURLFile($png,'image/png','replacement.png')]);bAssert(count(gsoRequestLetters($pdo,$mixed))===1,'Cross-borrower replacement accepted');
    bHttp('owner','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>'bad','request_letter'=>new CURLFile($png,'image/png','replacement.png')]);bAssert(count(gsoRequestLetters($pdo,$mixed))===1,'Replacement CSRF bypassed');
    bHttp('owner','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>$csrf['owner'],'request_letter'=>new CURLFile($png,'image/png','replacement.png')]);$versions=gsoRequestLetters($pdo,$mixed);$current=$versions[0];
    bAssert(count($versions)===2&&(int)$current['version_no']===2&&$current['verification_status']==='Pending','Replacement did not append Pending version');bAssert($versions[1]===$rejected&&hash_file('sha256',$firstPath)===$firstHash,'Rejected history/file was overwritten');
    $verify('admin',$mixed,(int)$first['attachment_id'],'verify_letter');bAssert(gsoRequestLetters($pdo,$mixed)[1]['verification_status']==='Rejected','Stale version was verified');
    $approve('admin',$mixed);bAssert($status($mixed)==='Pending','Old document bypassed current-version gate');
    bHttp('owner','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>$csrf['owner'],'request_letter'=>new CURLFile($png,'image/png','pending-replacement.png')]);bAssert(count(gsoRequestLetters($pdo,$mixed))===2,'Pending letter replaced without GSO rejection');
    $verify('admin',$mixed,(int)$current['attachment_id'],'verify_letter','Manual signatures checked');$verified=gsoRequestLetters($pdo,$mixed)[0];bAssert($verified['verification_status']==='Verified'&&(int)$verified['verified_by']===$users['admin'],'GSO verification failed');bAssert($status($mixed)==='Pending','Letter verification auto-approved borrowing');
    bHttp('owner','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>$csrf['owner'],'request_letter'=>new CURLFile($png,'image/png','replacement.png')]);bAssert(count(gsoRequestLetters($pdo,$mixed))===2,'Verified letter replaced without rejection');
    $approve('mis',$mixed);$approve('director',$mixed);bAssert($status($mixed)==='Pending','MIS/Director gained operational approval');
    $beforeRetired=bRows('SELECT * FROM resource_requests WHERE request_id=?',[$mixed]);
    foreach(['adviser_request.php','faculty_search.php','endorsements.php','endorsement_detail.php'] as $path)foreach(['GET','POST'] as $method)bAssert(bHttp('faculty','borrower/'.$path.'?request_id='.$mixed,$method==='POST'?['csrf_token'=>$csrf['faculty'],'action'=>'endorse','request_id'=>$mixed]:null)['status']===404,'Retired Adviser endpoint still exists');bAssert(bRows('SELECT * FROM resource_requests WHERE request_id=?',[$mixed])===$beforeRetired,'Retired Adviser endpoint changed a request');
    foreach(['require_adviser','require_director'] as $action)bHttp('admin','admin/requests.php',['csrf_token'=>$csrf['admin'],'request_id'=>$mixed,'action'=>$action]);
    foreach(['gsoSendAdviserAssignmentEmail','gsoAdviserStateBlocksGso','gsoFindEligibleFaculty','gsoAdviserHistory'] as $retired)bAssert(!function_exists($retired),'Retired Adviser helper still loaded: '.$retired);
    foreach(['app/models/adviser_endorsement_helper.php','includes/adviser_endorsement_helper.php','borrower/adviser_request.php','borrower/faculty_search.php','borrower/endorsements.php','borrower/endorsement_detail.php','app/controllers/borrower/endorsements.php'] as $retired)bAssert(!is_file(dirname(__DIR__).'/'.$retired),'Retired Adviser file still present: '.$retired);
    $approve('staff',$mixed);bAssert($status($mixed)==='Approved','GSO approval did not proceed without an Adviser step');bAssert($retiredTables()===0&&$retiredColumns()===0,'Retired Adviser storage was re-created');
    bHttp('owner','borrower/request_letter.php?request_id='.$mixed,['csrf_token'=>$csrf['owner'],'request_letter'=>new CURLFile($png,'image/png','after-approval.png')]);bAssert(count(gsoRequestLetters($pdo,$mixed))===2,'Approved request accepted replacement');
    bAssert((int)bVal("SELECT COUNT(*) FROM notifications WHERE user_id IN (?,?) AND type LIKE 'adviser%'",[$users['owner'],$users['faculty']])===0,'New Adviser notification created');
    bAssert(bHttp('faculty','borrower/browse.php')['status']===200&&bVal('SELECT account_type FROM users WHERE user_id=?',[$users['faculty']])==='Faculty','Faculty borrower classification changed');
    bAssert(!str_contains(bHttp('faculty','borrower/my_requests.php')['body'],'endorsements.php'),'Borrower navigation retained Adviser queue');
    bAssert(bHttp('mis','admin/admin_accounts.php')['status']===200&&bHttp('admin','admin/admin_accounts.php')['status']===302,'R2A Account Management separation broke');
    bAssert(bHttp('director','director/dashboard.php')['status']===200&&!str_contains(bHttp('director','director/dashboard.php')['body'],'request_document.php'),'Director read-only schedule exposed documents');
    // Real R1 equipment release -> Return -> Returned remains operational.
    $approve('admin',$eq);bHttp('staff','admin/requests.php',['csrf_token'=>$csrf['staff'],'request_id'=>$eq,'action'=>'release','claim_slip_code'=>gsoTestPresentedClaimSlip((int)$eq)]);bAssert($status($eq)==='Released','R1 release failed');
    $line=(int)bVal('SELECT request_item_id FROM request_items WHERE request_id=?',[$eq]);
    bHttp('owner','borrower/return_submit.php?request_id='.$eq,['csrf_token'=>$csrf['owner'],'lines'=>[$line=>['condition'=>'Good','returned_quantity'=>1,'lost_quantity'=>0]]]);bAssert($status($eq)==='Return','R1 return submission failed');
    $detail=(int)bVal('SELECT d.return_item_id FROM return_submission_items d JOIN return_submissions r ON r.return_id=d.return_id WHERE r.request_id=?',[$eq]);
    bHttp('staff','admin/review_return.php?request_id='.$eq,['csrf_token'=>$csrf['staff'],'action'=>'approve','inspection'=>[$detail=>['condition'=>'Good','remarks'=>'R2B regression']]]);bAssert($status($eq)==='Returned','R1 inspection failed');
    bExec("UPDATE users SET account_status='Disabled' WHERE user_id=?",[$users['owner']]);
    bAssert(bHttp('owner','borrower/request_document.php?attachment_id='.$first['attachment_id'])['status']===302,'Disabled owner retained document access');
    bExec("UPDATE users SET account_status='Approved' WHERE user_id=?",[$users['owner']]);
    bAssert($retiredTables()===0,'Retired history tables were re-created');
    echo "PASS: R2B manual letter and Adviser retirement E2E ({$checks} checks).\n";
}catch(Throwable $e){$failure=$e;fwrite(STDERR,'FAIL: '.$e->getMessage()."\n".$e->getTraceAsString()."\n");}
finally{
    if($pdo->inTransaction())$pdo->rollBack();
    // Sweep only this run's borrower IDs, including requests created before a failed assertion.
    $ids=array_values($users);if($ids){$marks=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT request_id FROM resource_requests WHERE borrower_id IN ($marks)");$s->execute($ids);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id){gsoTestDeleteLetters($pdo,(int)$id);bExec('DELETE FROM return_submissions WHERE request_id=?',[$id]);bExec('DELETE FROM resource_requests WHERE request_id=?',[$id]);}
        foreach($ids as $id){bExec('DELETE FROM notifications WHERE user_id=?',[$id]);bExec('DELETE FROM activity_logs WHERE user_id=?',[$id]);}bExec("DELETE FROM users WHERE user_id IN ($marks)",$ids);}
    foreach($resources as $id)bExec('DELETE FROM resources WHERE resource_id=?',[$id]);foreach($cookies as $file)@unlink($file);foreach($files as $file)@unlink($file);
}
exit($failure?1:0);
