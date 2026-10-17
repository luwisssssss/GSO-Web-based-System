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
if(!str_starts_with(loadDatabaseName(),'gso_load_test_phase_c2a_'))throw new RuntimeException('C2A database required.');
$extra=[
 'duplicate_return_headers'=>"SELECT COUNT(*) FROM (SELECT request_id FROM return_submissions GROUP BY request_id HAVING COUNT(*)>1) x",
 'pending_return_stock_applied'=>"SELECT COUNT(*) FROM return_submission_items i JOIN return_submissions r ON r.return_id=i.return_id WHERE r.status='Pending' AND i.stock_applied<>0",
 'approved_return_details'=>"SELECT COUNT(*) FROM return_submission_items i JOIN return_submissions r ON r.return_id=i.return_id WHERE r.status='Approved' AND (i.stock_applied<>1 OR i.inspection_condition IS NULL OR i.inspected_by IS NULL OR i.inspected_at IS NULL)",
 'return_settlement_quantities'=>"SELECT COUNT(*) FROM request_items i WHERE i.returned_quantity<>(SELECT COALESCE(SUM(d.returned_quantity+d.lost_quantity),0) FROM return_submission_items d WHERE d.request_item_id=i.request_item_id AND d.stock_applied=1)",
 'returned_request_status'=>"SELECT COUNT(*) FROM resource_requests r WHERE r.status='Returned' AND (r.return_date IS NULL OR NOT EXISTS(SELECT 1 FROM return_submissions s WHERE s.request_id=r.request_id AND s.status='Approved') OR EXISTS(SELECT 1 FROM request_items i WHERE i.request_id=r.request_id AND i.line_kind<>'Consumable' AND i.released_quantity<>i.returned_quantity))",
 'pending_request_return_state'=>"SELECT COUNT(*) FROM return_submissions s JOIN resource_requests r ON r.request_id=s.request_id WHERE s.status='Pending' AND (r.status<>'Return' OR r.return_date IS NOT NULL)",
 'duplicate_client_events'=>"SELECT COUNT(*) FROM (SELECT details FROM activity_logs WHERE action='Client Event Recorded' GROUP BY details HAVING COUNT(*)>1) x",
 'duplicate_email_attempts'=>"SELECT COUNT(*) FROM (SELECT details FROM activity_logs WHERE action='Client Email Attempt' GROUP BY details HAVING COUNT(*)>1) x",
 'duplicate_email_deliveries'=>"SELECT COUNT(*) FROM (SELECT details FROM activity_logs WHERE action='Client Email Sent' GROUP BY details HAVING COUNT(*)>1) x",
 'failed_email_deliveries'=>"SELECT COUNT(*) FROM activity_logs WHERE action='Client Email Failed'",
 'duplicate_notifications'=>"SELECT COUNT(*) FROM (SELECT user_id,type,message,link FROM notifications GROUP BY user_id,type,message,link HAVING COUNT(*)>1) x",


];
foreach($extra as $key=>$sql)$results['violations'][$key]=(int)$pdo->query($sql)->fetchColumn();
$c2checks=[
 'lost_in_repair_work_order'=>"SELECT COUNT(*) FROM maintenance_schedules m JOIN return_submission_items d ON d.return_item_id=m.source_return_item_id WHERE d.inspection_condition='Lost' OR d.lost_quantity>0",
 'invalid_repair_source'=>"SELECT COUNT(*) FROM maintenance_schedules m JOIN return_submission_items d ON d.return_item_id=m.source_return_item_id WHERE d.stock_applied<>1 OR d.inspection_condition NOT IN ('Damaged','Missing Parts','Needs Repair') OR d.returned_quantity<1",
 'duplicate_repair_work_order'=>"SELECT COUNT(*) FROM (SELECT source_return_item_id FROM maintenance_schedules WHERE source_return_item_id IS NOT NULL AND (status IN ('Scheduled','In Progress') OR restored_at IS NOT NULL) GROUP BY source_return_item_id HAVING COUNT(*)>1) x",
 'duplicate_liability'=>"SELECT COUNT(*) FROM (SELECT return_item_id FROM borrower_liabilities WHERE return_item_id IS NOT NULL GROUP BY return_item_id HAVING COUNT(*)>1) x",
 'duplicate_pending_proposal'=>"SELECT COUNT(*) FROM (SELECT request_id FROM request_reschedule_history WHERE status='Pending' GROUP BY request_id HAVING COUNT(*)>1) x",
 'pending_proposal_authority'=>"SELECT COUNT(*) FROM request_reschedule_history h JOIN resource_requests r ON r.request_id=h.request_id WHERE h.status='Pending' AND (r.status<>'Approved' OR r.date_needed<>h.old_date_needed OR r.start_time<>h.old_start_time OR r.end_time<>h.old_end_time)",
 'reschedule_terminal_metadata'=>"SELECT COUNT(*) FROM request_reschedule_history WHERE status IN ('Approved','Rejected') AND (reviewed_by IS NULL OR reviewed_at IS NULL OR processed_at IS NULL)",
 'preventive_duplicate_occurrence'=>"SELECT COUNT(*) FROM (SELECT preventive_plan_id,plan_occurrence_date FROM maintenance_schedules WHERE preventive_plan_id IS NOT NULL GROUP BY preventive_plan_id,plan_occurrence_date HAVING COUNT(*)>1) x",
 'preventive_state_metadata'=>"SELECT COUNT(*) FROM maintenance_schedules WHERE maintenance_type='Preventive' AND ((status='Scheduled' AND (started_at IS NOT NULL OR restored_at IS NOT NULL)) OR (status='In Progress' AND (started_at IS NULL OR restored_at IS NOT NULL OR completed_at IS NOT NULL)) OR (status='Completed' AND (started_at IS NULL OR completed_at IS NULL)))",
 'completed_maintenance_metadata'=>"SELECT COUNT(*) FROM maintenance_schedules WHERE status='Completed' AND (completed_at IS NULL OR restored_at IS NULL)",
 'lost_serialized_unavailable'=>"SELECT COUNT(*) FROM equipment_units WHERE condition_status='Lost' AND status NOT IN ('Lost','Retired')",
 'damaged_serialized_unavailable'=>"SELECT COUNT(*) FROM equipment_units WHERE condition_status IN ('Damaged','Missing Parts','Needs Repair') AND status='Available'",
 'aggregate_repair_ledger'=>"SELECT COUNT(*) FROM resources r WHERE tracking_mode='Aggregate' AND under_repair_stock<>(SELECT COALESCE(SUM(d.returned_quantity),0) FROM return_submission_items d JOIN request_items i ON i.request_item_id=d.request_item_id WHERE i.resource_id=r.resource_id AND d.stock_applied=1 AND d.inspection_condition IN ('Damaged','Missing Parts','Needs Repair'))+(SELECT COALESCE(SUM(m.quantity),0) FROM maintenance_schedules m WHERE m.resource_id=r.resource_id AND m.maintenance_type='Preventive' AND m.status='In Progress' AND m.started_at IS NOT NULL AND m.restored_at IS NULL)-(SELECT COALESCE(SUM(m.quantity),0) FROM maintenance_schedules m WHERE m.resource_id=r.resource_id AND m.maintenance_type='Corrective' AND m.source_return_item_id IS NOT NULL AND m.restored_at IS NOT NULL)",
];
foreach($c2checks as $key=>$sql)$results['violations'][$key]=(int)$pdo->query($sql)->fetchColumn();
$bases=$manifest['base_stock'];if(is_file($dir.'/c2a_stock_bases.json'))$bases+=json_decode(file_get_contents($dir.'/c2a_stock_bases.json'),true);
$consumableBad=0;foreach($pdo->query("SELECT resource_id,total_stock FROM resources WHERE tracking_mode='Consumable'") as $r){$id=(int)$r['resource_id'];$issued=(int)$pdo->query('SELECT COALESCE(SUM(issued_quantity),0) FROM request_items WHERE resource_id='.$id)->fetchColumn();if(!isset($bases[$id])||$bases[$id]-$issued!==(int)$r['total_stock'])$consumableBad++;}
$results['violations']['consumable_issuance_arithmetic']=$consumableBad;
$snapshot=loadSnapshot($pdo);$seed=json_decode(file_get_contents($dir.'/seed_snapshot.json'),true);$schemaChanges=[];
foreach($snapshot as $table=>$meta)if($meta['schema_hash']!==$seed[$table]['schema_hash'])$schemaChanges[]=$table;
$results['violations']['schema_fingerprint_changes']=count($schemaChanges);$results['schema_changed_tables']=$schemaChanges;
$codes=$pdo->query('SELECT claim_slip_code FROM resource_requests WHERE claim_slip_code IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN);$codes[]='GSO-22222222';$leaks=0;
foreach($pdo->query('SELECT details FROM activity_logs')->fetchAll(PDO::FETCH_COLUMN) as $detail)foreach($codes as $code)if(str_contains((string)$detail,$code))$leaks++;
$results['violations']['raw_claim_slips_in_activity_log']=$leaks;
$results['table_hashes']=$snapshot;$pdo->rollBack();$results['passed']=array_sum($results['violations'])===0;
$stage=(string)getenv('GSO_LOAD_STAGE');if(!preg_match('/\A(?:before|[a-z_]+_[0-9]{2})\z/',$stage))throw new RuntimeException('Invalid C2A integrity label.');
file_put_contents($dir.'/integrity_'.$stage.'.json',json_encode($results,JSON_PRETTY_PRINT));
echo json_encode(['stage'=>$stage,'passed'=>$results['passed'],'foreign_keys_checked'=>count($groups),'violations'=>$results['violations']]),PHP_EOL;
exit($results['passed']?0:1);
