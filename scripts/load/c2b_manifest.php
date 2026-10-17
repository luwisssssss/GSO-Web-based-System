<?php
require __DIR__.'/common.php';
if(!str_starts_with(loadDatabaseName(),'gso_load_test_phase_c2b_'))throw new RuntimeException('C2B database required.');
$dir=realpath((string)getenv('GSO_LOAD_EVIDENCE'));
$path=$dir.'/manifest.private.json';$m=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($m['database']!==loadDatabaseName()||count($m['users'])!==17)throw new RuntimeException('Fresh C2B fixture required; refusing replay.');
$pdo=loadConnection();
foreach(['GSO Staff','Director','Super Admin'] as $i=>$role){
    $username='phase_c2b_'.($i+18);$password='TestOnly!'.bin2hex(random_bytes(12));
    loadExec($pdo,"INSERT INTO users(full_name,department,email,username,password_hash,role,account_status,email_verified) VALUES(?,?,?,?,?,?,'Approved',1)",['Synthetic C2B '.$role,'Synthetic Test Office',$username.'@example.invalid',$username,password_hash($password,PASSWORD_DEFAULT),$role]);
    $m['users'][]=['user_id'=>(int)$pdo->lastInsertId(),'username'=>$username,'password'=>$password,'role'=>$role];
}
$m['spike_actors']=$m['users'];$m['owned_requests']=[];
foreach($pdo->query("SELECT borrower_id,request_id FROM resource_requests WHERE status='Approved'") as $r)$m['owned_requests'][$r['borrower_id']]=(int)$r['request_id'];
$m['query_date']=date('Y-m-d',strtotime('+5 days'));
file_put_contents($path,json_encode($m,JSON_PRETTY_PRINT));
file_put_contents($dir.'/seed_snapshot.json',json_encode(loadSnapshot($pdo),JSON_PRETTY_PRINT));
$summary=json_decode(file_get_contents($dir.'/seed_summary.json'),true);$summary['operational_users']=4;$summary['director']=2;$summary['mis']=2;
file_put_contents($dir.'/seed_summary.json',json_encode($summary,JSON_PRETTY_PRINT));
echo "C2B ready: 12 Borrowers, 4 operational, 2 Directors, 2 MIS; 20 independent accounts.",PHP_EOL;
