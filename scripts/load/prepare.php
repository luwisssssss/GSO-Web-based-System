<?php
require __DIR__.'/common.php';
require_once $loadRoot.'/app/models/schema_migrator.php';
$name=loadDatabaseName(); $dir=realpath((string)getenv('GSO_LOAD_EVIDENCE'));
if (!$dir || str_starts_with(strtolower(str_replace('\\','/',$dir)),strtolower(str_replace('\\','/',$loadRoot)))) throw new RuntimeException('Private evidence directory outside repository required.');
$pdo=loadConnection(false);
$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$s->execute([$name]);
if ((int)$s->fetchColumn() !== 0) throw new RuntimeException('Refusing to reuse an existing database.');
$pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `$name`"); gsoExecuteSqlFile($pdo,$loadRoot.'/database/schema.sql');
$schema=loadAssertSchema($pdo); $users=[];
foreach (array_merge(array_fill(0,12,'Borrower'),['Admin','GSO Staff','GSO Staff','Director','Super Admin']) as $i=>$role) {
    $username='phase_a_'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);$password='TestOnly!'.bin2hex(random_bytes(12));
    loadExec($pdo,"INSERT INTO users(full_name,department,email,username,password_hash,role,account_type,account_status,email_verified) VALUES(?,?,?,?,?,?,?,'Approved',1)",
        ['Synthetic Phase A '.$role.' '.($i+1),'Synthetic Test Office',$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role,$role==='Borrower'?'University Staff':null]);
    $users[]=['user_id'=>(int)$pdo->lastInsertId(),'username'=>$username,'password'=>$password,'role'=>$role];
}
$head=$users[12]['user_id'];$resources=['Facility'=>[],'Aggregate'=>[],'Consumable'=>[],'Serialized'=>[]];$baseStock=[];$units=[];
foreach (['Facility'=>3,'Aggregate'=>3,'Consumable'=>2,'Serialized'=>2] as $mode=>$count) for ($i=0;$i<$count;$i++) {
    $stock=match($mode){'Facility'=>1,'Aggregate'=>100,'Consumable'=>500,'Serialized'=>12};
    loadExec($pdo,"INSERT INTO resources(resource_name,resource_type,tracking_mode,category,description,location,total_stock,available_stock,capacity,status,condition_status) VALUES(?,?,?,?,?,?,?,?,?,'Available','Good')",
        ['Synthetic '.$mode.' '.($i+1),$mode==='Facility'?'Facility':'Item',$mode,'Phase A Synthetic','Synthetic load fixture only','Synthetic Campus',$stock,$stock,$mode==='Facility'?50:null]);
    $id=(int)$pdo->lastInsertId();$resources[$mode][]=$id;$baseStock[$id]=$stock;
    if ($mode==='Serialized') for ($n=0;$n<12;$n++) {
        loadExec($pdo,"INSERT INTO equipment_units(resource_id,asset_tag,serial_number,status,condition_status,registered_by) VALUES(?,?,?,'Available','Good',?)",[$id,'PA-'.$id.'-'.$n,'SYNTHETIC-'.$id.'-'.$n,$head]);
        $units[$id][]=(int)$pdo->lastInsertId();
    }
}
$alphabet='23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$slip=static function() use($alphabet):string {$code='GSO-';for($n=0;$n<8;$n++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];return $code;};
$letterDir=$dir.'/request_letters';if(!is_dir($letterDir))mkdir($letterDir,0700,true);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=');
for ($i=0;$i<12;$i++) foreach (['Pending','Under Review','Approved','ReleasedAggregate','ReleasedSerialized','Completed','Rejected','Cancelled'] as $state) {
    $status=str_starts_with($state,'Released')?'Released':$state;
    $mode=match($state){'Approved'=>'Facility','ReleasedSerialized'=>'Serialized','Completed','Rejected'=>'Consumable',default=>'Aggregate'};
    $resource=$resources[$mode][$i%count($resources[$mode])];$qty=$mode==='Facility'||$mode==='Serialized'?1:($mode==='Consumable'?3:2);
    $approved=in_array($status,['Approved','Released','Completed'],true);$released=in_array($status,['Released','Completed'],true);
    $date=date('Y-m-d',strtotime(($released?'-1':'+'.(5+intdiv($i,3))).' days'));
    $past=date('Y-m-d H:i:s',strtotime('-2 days'));$now=date('Y-m-d H:i:s');
    loadExec($pdo,'INSERT INTO resource_requests(borrower_id,contact_number,date_needed,start_time,end_time,request_date,status,claim_slip_code,reviewed_by,reviewed_at,approved_by,approved_at,released_by,released_at,due_date,notes) VALUES(?,?,?,\'09:00:00\',\'10:00:00\',?,?,?,?,?,?,?,?,?,?,?)',
        [$users[$i]['user_id'],'0900000'.str_pad((string)$i,4,'0',STR_PAD_LEFT),$date,$past,$status,$approved?$slip():null,$status!=='Pending'?$head:null,$status!=='Pending'?$past:null,$approved?$head:null,$approved?$past:null,$released?$head:null,$released?$past:null,$released?$date.' 10:00:00':null,'Synthetic Phase A fixture']);
    $request=(int)$pdo->lastInsertId();$kind=match($mode){'Facility'=>'Facility','Consumable'=>'Consumable',default=>'Equipment'};
    loadExec($pdo,'INSERT INTO request_items(request_id,resource_id,line_kind,facility_slot,requested_quantity,released_quantity,issued_quantity) VALUES(?,?,?,?,?,?,?)',
        [$request,$resource,$kind,$mode==='Facility'?'AM':null,$qty,$status==='Released'?$qty:0,$state==='Completed'?$qty:0]);$line=(int)$pdo->lastInsertId();
    if ($status==='Released') {
        loadExec($pdo,'UPDATE resources SET available_stock=available_stock-? WHERE resource_id=?',[$qty,$resource]);
        if($mode==='Serialized') {
            $unit=$units[$resource][intdiv($i,2)];loadExec($pdo,"UPDATE equipment_units SET status='Released' WHERE unit_id=?",[$unit]);
            loadExec($pdo,'INSERT INTO request_item_units(request_item_id,unit_id,allocated_by,allocated_at,released_at) VALUES(?,?,?,?,?)',[$line,$unit,$head,$past,$past]);
        }
    }
    if ($state==='Completed') loadExec($pdo,'UPDATE resources SET total_stock=total_stock-?,available_stock=available_stock-? WHERE resource_id=?',[$qty,$qty,$resource]);
    if ($mode==='Facility') {
        $stored='letter_'.bin2hex(random_bytes(16)).'.png';file_put_contents($letterDir.'/'.$stored,$png);
        loadExec($pdo,"INSERT INTO request_attachments(request_id,uploaded_by,version_no,original_file_name,stored_file_name,mime_type,file_size,verification_status,verified_by,verified_at,verification_remarks) VALUES(?,?,1,'synthetic-letter.png',?,'image/png',?,'Verified',?,?,'Synthetic verified fixture')",[$request,$users[$i]['user_id'],$stored,strlen($png),$head,$now]);
    }
}
// The HTTP account can access only this new database, never the normal database.
$runtimeUser='gso_load_'.substr($name,14);$runtimePassword=bin2hex(random_bytes(24));
$identity=$pdo->quote($runtimeUser)."@'localhost'";
$pdo->exec('CREATE USER '.$identity.' IDENTIFIED BY '.$pdo->quote($runtimePassword));
$pdo->exec("GRANT SELECT,INSERT,UPDATE,DELETE ON `$name`.* TO ".$identity);
$manifest=['database'=>$name,'schema'=>$schema,'users'=>$users,'actors'=>[$users[0],$users[1],$users[12],$users[15],$users[16]],'resources'=>$resources,'base_stock'=>$baseStock,'calendar_month'=>date('Y-m'),'runtime_user'=>$runtimeUser,'runtime_password'=>$runtimePassword,'app_key'=>bin2hex(random_bytes(32))];
file_put_contents($dir.'/manifest.private.json',json_encode($manifest,JSON_PRETTY_PRINT));
file_put_contents($dir.'/seed_summary.json',json_encode(['database'=>$name,'schema'=>$schema,'borrowers'=>12,'operational_users'=>3,'director'=>1,'mis'=>1,'resources'=>$resources,'requests'=>(int)$pdo->query('SELECT COUNT(*) FROM resource_requests')->fetchColumn(),'serialized_units'=>24],JSON_PRETTY_PRINT));
file_put_contents($dir.'/seed_snapshot.json',json_encode(loadSnapshot($pdo),JSON_PRETTY_PRINT));
echo json_encode(['database'=>$name,'schema'=>$schema,'users'=>count($users),'resources'=>10,'requests'=>96,'serialized_units'=>24]),PHP_EOL;
