<?php
require __DIR__.'/common.php';
$dir=realpath((string)getenv('GSO_LOAD_EVIDENCE'));$manifest=json_decode(file_get_contents($dir.'/manifest.private.json'),true,512,JSON_THROW_ON_ERROR);
if ($manifest['database']!==loadDatabaseName()) throw new RuntimeException('Manifest/database mismatch.');
$pdo=loadConnection();$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
$results=['database'=>loadDatabaseName(),'schema'=>loadAssertSchema($pdo),'violations'=>[]];
$queries=[
 'negative_stock'=>"SELECT COUNT(*) FROM resources WHERE total_stock<0 OR available_stock<0 OR under_repair_stock<0 OR available_stock+under_repair_stock>total_stock",
 'aggregate_stock_arithmetic'=>"SELECT COUNT(*) FROM resources r WHERE tracking_mode='Aggregate' AND total_stock<>available_stock+under_repair_stock+(SELECT COALESCE(SUM(i.released_quantity-i.returned_quantity),0) FROM request_items i WHERE i.resource_id=r.resource_id AND i.line_kind='Equipment')",
 'consumable_stock_arithmetic'=>"SELECT COUNT(*) FROM resources WHERE tracking_mode='Consumable' AND (available_stock<>total_stock OR under_repair_stock<>0)",
 'serialized_stock_arithmetic'=>"SELECT COUNT(*) FROM resources r WHERE tracking_mode='Serialized' AND (total_stock<>(SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id=r.resource_id AND status NOT IN ('Lost','Retired')) OR available_stock<>(SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id=r.resource_id AND status='Available') OR under_repair_stock<>(SELECT COUNT(*) FROM equipment_units u WHERE u.resource_id=r.resource_id AND status='Under Repair'))",
 'duplicate_open_allocations'=>"SELECT COUNT(*) FROM (SELECT unit_id FROM request_item_units WHERE returned_at IS NULL GROUP BY unit_id HAVING COUNT(*)>1) x",
 'duplicate_line_unit_allocations'=>"SELECT COUNT(*) FROM (SELECT request_item_id,unit_id FROM request_item_units GROUP BY request_item_id,unit_id HAVING COUNT(*)>1) x",
 'allocation_resource_mismatch'=>"SELECT COUNT(*) FROM request_item_units a JOIN request_items i ON i.request_item_id=a.request_item_id JOIN equipment_units u ON u.unit_id=a.unit_id WHERE i.resource_id<>u.resource_id",
 'released_unit_allocation_invariants'=>"SELECT COUNT(*) FROM equipment_units u WHERE (status='Released' AND (SELECT COUNT(*) FROM request_item_units a WHERE a.unit_id=u.unit_id AND a.returned_at IS NULL)<>1) OR (status<>'Released' AND EXISTS(SELECT 1 FROM request_item_units a WHERE a.unit_id=u.unit_id AND a.returned_at IS NULL))",
 'serialized_line_allocations'=>"SELECT COUNT(*) FROM request_items i JOIN resources r ON r.resource_id=i.resource_id WHERE tracking_mode='Serialized' AND released_quantity-returned_quantity<>(SELECT COUNT(*) FROM request_item_units a WHERE a.request_item_id=i.request_item_id AND a.returned_at IS NULL)",
 'facility_conflicts'=>"SELECT COUNT(*) FROM request_items a JOIN resource_requests x ON x.request_id=a.request_id JOIN request_items b ON b.resource_id=a.resource_id AND b.request_id>a.request_id JOIN resource_requests y ON y.request_id=b.request_id WHERE a.line_kind='Facility' AND b.line_kind='Facility' AND x.status IN ('Approved','Released','Return') AND y.status IN ('Approved','Released','Return') AND x.date_needed=y.date_needed AND x.start_time<y.end_time AND y.start_time<x.end_time",
 'facility_period_invariants'=>"SELECT COUNT(*) FROM request_items i JOIN resource_requests r ON r.request_id=i.request_id WHERE i.line_kind='Facility' AND (i.facility_slot IS NULL OR start_time>=end_time OR (i.facility_slot='AM' AND HOUR(start_time)>=12) OR (i.facility_slot='PM' AND HOUR(start_time)<12))",
 'claim_slip_format_and_status'=>"SELECT COUNT(*) FROM resource_requests WHERE (claim_slip_code IS NOT NULL AND claim_slip_code NOT REGEXP '^GSO-[2-9A-HJ-NP-Z]{8}$') OR (status IN ('Pending','Under Review') AND claim_slip_code IS NOT NULL) OR (status IN ('Approved','Released','Return','Completed') AND claim_slip_code IS NULL)",
 'duplicate_claim_slips'=>"SELECT COUNT(*) FROM (SELECT claim_slip_code FROM resource_requests WHERE claim_slip_code IS NOT NULL GROUP BY claim_slip_code HAVING COUNT(*)>1) x",
 'requests_without_lines'=>"SELECT COUNT(*) FROM resource_requests r WHERE NOT EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id)",
 'line_quantity_integrity'=>"SELECT COUNT(*) FROM request_items WHERE requested_quantity<1 OR released_quantity<0 OR released_quantity>requested_quantity OR returned_quantity<0 OR returned_quantity>released_quantity OR issued_quantity<0 OR issued_quantity>requested_quantity",
 'request_actor_integrity'=>"SELECT COUNT(*) FROM resource_requests WHERE (requester_type='Registered' AND (borrower_id IS NULL OR encoded_by IS NOT NULL OR walkin_full_name IS NOT NULL)) OR (requester_type='Walk-in' AND (borrower_id IS NOT NULL OR encoded_by IS NULL OR walkin_full_name IS NULL))",
 'status_release_integrity'=>"SELECT COUNT(*) FROM resource_requests r WHERE (status IN ('Pending','Under Review','Rejected','Cancelled') AND EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id AND (released_quantity>0 OR issued_quantity>0))) OR (status IN ('Approved','Released','Completed') AND (approved_by IS NULL OR approved_at IS NULL)) OR (status='Released' AND (released_by IS NULL OR released_at IS NULL OR NOT EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id AND released_quantity>returned_quantity)))",
 'approved_facility_letters'=>"SELECT COUNT(*) FROM resource_requests r WHERE status='Approved' AND EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id AND line_kind='Facility') AND NOT EXISTS(SELECT 1 FROM request_attachments a WHERE a.request_id=r.request_id AND verification_status='Verified')",
];
foreach($queries as $key=>$sql)$results['violations'][$key]=(int)$pdo->query($sql)->fetchColumn();
$fk=$pdo->query("SELECT CONSTRAINT_NAME,TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION")->fetchAll();
$groups=[];foreach($fk as $row)$groups[$row['TABLE_NAME'].'.'.$row['CONSTRAINT_NAME']][]=$row;
$orphanDetails=[];foreach($groups as $key=>$columns){$first=$columns[0];$nonnull=[];$join=[];foreach($columns as $column){$nonnull[]='c.`'.$column['COLUMN_NAME'].'` IS NOT NULL';$join[]='p.`'.$column['REFERENCED_COLUMN_NAME'].'`=c.`'.$column['COLUMN_NAME'].'`';}
    $count=(int)$pdo->query('SELECT COUNT(*) FROM `'.$first['TABLE_NAME'].'` c WHERE '.implode(' AND ',$nonnull).' AND NOT EXISTS(SELECT 1 FROM `'.$first['REFERENCED_TABLE_NAME'].'` p WHERE '.implode(' AND ',$join).')')->fetchColumn();$orphanDetails[$key]=$count;}
$results['foreign_keys_checked']=count($groups);$results['orphan_rows_by_fk']=$orphanDetails;$results['violations']['orphan_rows']=array_sum($orphanDetails);
foreach($manifest['resources']['Consumable'] as $id){$issued=(int)$pdo->query('SELECT COALESCE(SUM(issued_quantity),0) FROM request_items WHERE resource_id='.(int)$id)->fetchColumn();$total=(int)$pdo->query('SELECT total_stock FROM resources WHERE resource_id='.(int)$id)->fetchColumn();$results['violations']['consumable_issuance_'.$id]=(int)($manifest['base_stock'][$id]-$issued!==$total);}
$snapshot=loadSnapshot($pdo);$seed=json_decode(file_get_contents($dir.'/seed_snapshot.json'),true);$changed=[];$schemaChanged=[];
$phaseB=str_starts_with(loadDatabaseName(),'gso_load_test_phase_b_');
$allowedChanges=$phaseB?['activity_logs','login_attempts','ai_conversations','ai_messages']:['activity_logs','login_attempts'];
foreach($snapshot as $table=>$meta){
    if(!in_array($table,$allowedChanges,true)&&$meta!==$seed[$table])$changed[]=$table;
    if($meta['schema_hash']!==$seed[$table]['schema_hash'])$schemaChanged[]=$table;
}
$results['schema_changed_tables']=$schemaChanged;$results['violations']['schema_fingerprint_changes']=count($schemaChanged);
if($phaseB){
    $results['violations']['chat_conversation_owner']=(int)$pdo->query("SELECT COUNT(*) FROM ai_conversations c JOIN users u ON u.user_id=c.user_id WHERE u.role<>'Borrower'")->fetchColumn();
    $results['violations']['chat_message_pairs']=(int)$pdo->query("SELECT COUNT(*) FROM (SELECT conversation_id FROM ai_messages GROUP BY conversation_id HAVING SUM(sender_type='user')<>SUM(sender_type='assistant') OR SUM(sender_type='system')<>0) x")->fetchColumn();
    $results['chat_counts']=$pdo->query('SELECT sender_type,COUNT(*) AS count FROM ai_messages GROUP BY sender_type')->fetchAll();
}
$results['violations']['unexpected_business_table_changes']=count($changed);$results['changed_business_tables']=$changed;
$results['status_counts']=$pdo->query('SELECT status,COUNT(*) AS count FROM resource_requests GROUP BY status')->fetchAll();
$results['activity_logs']=(int)$pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
$results['failed_login_attempts']=(int)$pdo->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn();
$results['unexpected_application_events']=$pdo->query("SELECT action,COUNT(*) AS count FROM activity_logs WHERE action<>'Login Success' GROUP BY action")->fetchAll();
$results['violations']['failed_logins']=$results['failed_login_attempts'];$results['violations']['unexpected_application_events']=count($results['unexpected_application_events']);
$pdo->rollBack();$results['passed']=array_sum($results['violations'])===0;
$stage=getenv('GSO_LOAD_STAGE')?:'after';if(!in_array($stage,['before','baseline','five','ten','fifteen'],true))throw new RuntimeException('Unknown integrity stage.');
file_put_contents($dir.'/integrity_'.$stage.'.json',json_encode($results,JSON_PRETTY_PRINT));
echo json_encode(['stage'=>$stage,'passed'=>$results['passed'],'foreign_keys_checked'=>count($groups),'violations'=>$results['violations']]),PHP_EOL;
exit($results['passed']?0:1);
