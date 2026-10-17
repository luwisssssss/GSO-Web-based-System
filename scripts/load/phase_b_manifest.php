<?php
require __DIR__.'/common.php';
if(!str_starts_with(loadDatabaseName(),'gso_load_test_phase_b_'))throw new RuntimeException('Phase B database required.');
$dir=realpath((string)getenv('GSO_LOAD_EVIDENCE'));
$path=$dir.'/manifest.private.json';$m=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
if($m['database']!==loadDatabaseName())throw new RuntimeException('Manifest mismatch.');
$pdo=loadConnection();$m['owned_requests']=[];
foreach($pdo->query("SELECT borrower_id,request_id FROM resource_requests WHERE status='Approved'")->fetchAll() as $r)$m['owned_requests'][$r['borrower_id']]=(int)$r['request_id'];
$m['query_date']=date('Y-m-d',strtotime('+5 days'));
file_put_contents($path,json_encode($m,JSON_PRETTY_PRINT));
echo "Phase B owned-request manifest ready.",PHP_EOL;
