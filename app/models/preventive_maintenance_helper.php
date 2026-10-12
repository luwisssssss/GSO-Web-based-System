<?php
require_once __DIR__.'/request_line_helper.php';
require_once __DIR__.'/activity_log_helper.php';
require_once __DIR__.'/notification_helper.php';

function gsoPreventiveActor(PDO $pdo,int $actor,string $permission): string
{
    $s=$pdo->prepare("SELECT role FROM users WHERE user_id=? AND account_status='Approved' AND email_verified=1");$s->execute([$actor]);$role=$s->fetchColumn();if(!is_string($role)||!gsoUserHasPermission($role,$permission))throw new DomainException('Not authorized for this maintenance action.');return $role;
}
function gsoPreventiveAudit(PDO $pdo,int $actor,string $action,string $details): void
{
    if(!addActivityLog($pdo,$actor,$action,mb_substr($details,0,255)))throw new RuntimeException('Maintenance audit could not be saved.');
}
function gsoPreventiveDate(string $date): DateTimeImmutable
{
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||(int)$d->format('Y')<1000)throw new DomainException('Enter a valid calendar date.');return $d;
}
function gsoPreventiveNextDate(string $date,string $recurrence,?int $anchor=null): ?string
{
    $d=gsoPreventiveDate($date);$months=match($recurrence){'Once'=>0,'Monthly'=>1,'Quarterly'=>3,'Semiannual'=>6,'Annual'=>12,default=>throw new DomainException('Select a valid recurrence.')};if(!$months)return null;$anchor=$anchor??(int)$d->format('d');$next=$d->modify('first day of this month')->modify('+'.$months.' months');return $next->setDate((int)$next->format('Y'),(int)$next->format('m'),min($anchor,(int)$next->format('t')))->format('Y-m-d');
}
function gsoPreventiveInteger(mixed $value,int $min,int $max): int
{
    if(!is_string($value)&&!is_int($value))throw new DomainException('Use a whole-number maintenance quantity or duration.');$n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]]);if($n===false)throw new DomainException('Maintenance quantity or duration is outside the allowed range.');return $n;
}
function gsoPreventiveTarget(PDO $pdo,array $data,bool $lock=false): array
{
    $resourceId=gsoPreventiveInteger($data['resource_id']??0,1,PHP_INT_MAX);$s=$pdo->prepare('SELECT * FROM resources WHERE resource_id=?'.($lock?' FOR UPDATE':''));$s->execute([$resourceId]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r||(int)$r['is_archived']===1||$r['condition_status']==='Lost')throw new DomainException('Select a current resource that is not Lost.');
    if($r['tracking_mode']==='Consumable')throw new DomainException('Consumables cannot receive preventive maintenance.');$unitId=gsoPreventiveInteger($data['equipment_unit_id']??0,0,PHP_INT_MAX);$qty=gsoPreventiveInteger($data['quantity']??0,0,PHP_INT_MAX);$unit=null;
    if($r['resource_type']==='Facility'){if($unitId||$qty!==0)throw new DomainException('Facility maintenance targets the whole facility, without unit or stock quantity.');}
    elseif($r['tracking_mode']==='Serialized'){
        if($unitId<1||$qty!==1)throw new DomainException('Serialized preventive maintenance requires one actual unit and quantity 1.');$s=$pdo->prepare('SELECT * FROM equipment_units WHERE unit_id=? AND resource_id=?'.($lock?' FOR UPDATE':''));$s->execute([$unitId,$resourceId]);$unit=$s->fetch(PDO::FETCH_ASSOC);if(!$unit||in_array($unit['status'],['Lost','Retired','Under Repair'],true)||$unit['condition_status']!=='Good')throw new DomainException('Select a real serviceable unit of this resource. Lost or repair-held units are ineligible.');
    }elseif($unitId||$qty<1||$qty>(int)$r['total_stock'])throw new DomainException('Aggregate maintenance quantity must be within the selected resource capacity, without a unit id.');
    return ['resource'=>$r,'unit'=>$unit,'resource_id'=>$resourceId,'equipment_unit_id'=>$unitId?:null,'quantity'=>$qty];
}
function gsoPreventiveAssignee(PDO $pdo,mixed $value): ?int
{
    $id=gsoPreventiveInteger($value??0,0,PHP_INT_MAX);if(!$id)return null;gsoPreventiveActor($pdo,$id,'work_order.manage');return $id;
}
function gsoSavePreventivePlan(PDO $pdo,int $actor,array $data,int $id=0,?int $revision=null): int
{
    gsoPreventiveActor($pdo,$actor,'maintenance.plan.manage');$due=trim((string)($data['next_due_date']??''));gsoPreventiveDate($due);$recurrence=(string)($data['recurrence']??'');gsoPreventiveNextDate($due,$recurrence);$title=trim((string)($data['title']??''));if($title===''||mb_strlen($title)>255)throw new DomainException('Provide a maintenance task/title of up to 255 characters.');$duration=gsoPreventiveInteger($data['duration_days']??1,1,365);
    try{$pdo->beginTransaction();$old=null;if($id){$s=$pdo->prepare('SELECT * FROM preventive_maintenance_plans WHERE plan_id=? FOR UPDATE');$s->execute([$id]);$old=$s->fetch(PDO::FETCH_ASSOC);if(!$old||(int)$old['revision']!==$revision)throw new DomainException('The plan changed. Refresh before editing future settings.');}
        if((!$old||$old['next_due_date']!==$due)&&$due<date('Y-m-d'))throw new DomainException('The next due date cannot be in the past.');
        $target=gsoPreventiveTarget($pdo,$data,true);$assignee=gsoPreventiveAssignee($pdo,$data['assigned_to']??0);$anchor=$old&&$old['next_due_date']===$due?(int)$old['anchor_day']:(int)substr($due,8,2);
        $values=[$target['resource_id'],$target['equipment_unit_id'],$target['quantity'],$title,$recurrence,$due,$anchor,$duration,$assignee,$actor];
        if($old){$pdo->prepare('UPDATE preventive_maintenance_plans SET resource_id=?,equipment_unit_id=?,quantity=?,title=?,recurrence=?,next_due_date=?,anchor_day=?,duration_days=?,assigned_to=?,updated_by=?,updated_at=NOW(),revision=revision+1 WHERE plan_id=?')->execute([...$values,$id]);}
        else{$pdo->prepare('INSERT INTO preventive_maintenance_plans(resource_id,equipment_unit_id,quantity,title,recurrence,next_due_date,anchor_day,duration_days,assigned_to,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([...$values,$actor]);$id=(int)$pdo->lastInsertId();}
        gsoPreventiveAudit($pdo,$actor,$old?'Preventive Plan Updated':'Preventive Plan Created','Plan #'.$id.'; future settings recorded. Existing work-order snapshots retained.');$pdo->commit();return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function gsoDisablePreventivePlan(PDO $pdo,int $actor,int $id,int $revision): void
{
    gsoPreventiveActor($pdo,$actor,'maintenance.plan.manage');try{$pdo->beginTransaction();$s=$pdo->prepare('UPDATE preventive_maintenance_plans SET is_active=0,revision=revision+1,updated_by=?,updated_at=NOW() WHERE plan_id=? AND revision=? AND is_active=1');$s->execute([$actor,$id,$revision]);if($s->rowCount()!==1)throw new DomainException('The plan changed or is already inactive. Refresh this page.');gsoPreventiveAudit($pdo,$actor,'Preventive Plan Disabled','Plan #'.$id.' disabled; generated work and history retained.');$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function gsoPreventiveValidateWindow(PDO $pdo,array $target,string $start,string $end,?int $exclude=null): void
{
    $r=$target['resource'];$resourceId=(int)$r['resource_id'];
    if($r['resource_type']==='Facility'){
        if($r['status']==='Unavailable'&&$r['condition_status']==='Good')throw new DomainException('This facility is administratively unavailable.');
        if(getMaintenanceConflict($pdo,$resourceId,$start,$end,false,$exclude))throw new DomainException('This facility already has overlapping maintenance.');
        foreach(fetchActiveScheduleRequests($pdo,$resourceId) as $request){if(!in_array($request['status'],['Approved','Released','Return'],true))continue;$range=buildRequestDateRange('Facility',$request['date_needed'],$request['start_time'],$request['end_time'],$request['due_date'],$request['status']);if($range&&intervalsOverlap($start,$end,$range['start'],$range['end']))throw new DomainException('A confirmed facility reservation overlaps this maintenance interval.');}
    }else{
        $result=checkResourceAvailability($pdo,$r,$target['quantity'],substr($start,0,10),substr($start,11),substr($end,11),$end,null,$exclude);if(!$result['ok'])throw new DomainException('Maintenance target capacity: '.$result['message']);
        if($target['equipment_unit_id']){
            if(gsoPreventiveUnitConflict($pdo,$target['equipment_unit_id'],$start,$end,$exclude))throw new DomainException('This physical unit has overlapping preventive maintenance.');
            $s=$pdo->prepare("SELECT rr.* FROM request_item_units a JOIN request_items i ON i.request_item_id=a.request_item_id JOIN resource_requests rr ON rr.request_id=i.request_id WHERE a.unit_id=? AND a.returned_at IS NULL");$s->execute([$target['equipment_unit_id']]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $request){$range=buildRequestDateRange('Item',$request['date_needed'],$request['start_time'],$request['end_time'],$request['due_date'],$request['status']);if($range&&intervalsOverlap($start,$end,$range['start'],$range['end']))throw new DomainException('This physical unit is allocated to an overlapping borrowing request.');}
        }
    }
}
function gsoGeneratePreventiveOccurrence(PDO $pdo,int $actor,int $id): int
{
    gsoPreventiveActor($pdo,$actor,'maintenance.plan.manage');try{$pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM preventive_maintenance_plans WHERE plan_id=? FOR UPDATE');$s->execute([$id]);$plan=$s->fetch(PDO::FETCH_ASSOC);if(!$plan||(int)$plan['is_active']!==1)throw new DomainException('Select an active preventive plan.');
        $s=$pdo->prepare('SELECT maintenance_id,status FROM maintenance_schedules WHERE preventive_plan_id=? AND plan_occurrence_date=? FOR UPDATE');$s->execute([$id,$plan['next_due_date']]);$existing=$s->fetch(PDO::FETCH_ASSOC);if($existing){if(!in_array($existing['status'],['Scheduled','In Progress'],true))throw new DomainException('The occurrence for this due date was already '.strtolower($existing['status']).'. Edit the plan next due date before generating another occurrence.');$pdo->commit();return (int)$existing['maintenance_id'];}
        $target=gsoPreventiveTarget($pdo,array_replace($plan,['equipment_unit_id'=>$plan['equipment_unit_id']??0]),true);$end=calculateMaintenanceEndDate($plan['next_due_date'],(int)$plan['duration_days']);gsoPreventiveValidateWindow($pdo,$target,$plan['next_due_date'].' 00:00:00',$end.' 23:59:59');
        $s=$pdo->prepare("INSERT INTO maintenance_schedules(maintenance_type,preventive_plan_id,plan_occurrence_date,resource_id,equipment_unit_id,quantity,assigned_to,start_date,end_date,duration_days,reason,status,created_by,updated_by) VALUES('Preventive',?,?,?,?,?,?,?,?,?,?,'Scheduled',?,?)");$s->execute([$id,$plan['next_due_date'],$target['resource_id'],$target['equipment_unit_id'],$target['quantity'],$plan['assigned_to'],$plan['next_due_date'],$end,$plan['duration_days'],$plan['title'],$actor,$actor]);$job=(int)$pdo->lastInsertId();gsoPreventiveAudit($pdo,$actor,'Preventive Occurrence Generated','Plan #'.$id.'; MTN-'.$job.'; due '.$plan['next_due_date'].'. No current inventory movement.');if($plan['assigned_to'])createNotification($pdo,(int)$plan['assigned_to'],'work_order_assigned','Preventive Maintenance Scheduled','MTN-'.$job.' is assigned for '.$plan['next_due_date'].'.','maintenance.php');$pdo->commit();return $job;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function gsoPreventiveJobAction(PDO $pdo,int $id,int $actor,string $action,string $notes=''): array
{
    try{
        $role=gsoPreventiveActor($pdo,$actor,'work_order.manage');if(!in_array($action,['start','complete','cancel'],true)||mb_strlen($notes)>1000)throw new DomainException('Choose a valid maintenance action with notes up to 1,000 characters.');
        $s=$pdo->prepare("SELECT preventive_plan_id FROM maintenance_schedules WHERE maintenance_id=? AND maintenance_type='Preventive'");$s->execute([$id]);$planId=$s->fetchColumn();if(!$planId)throw new DomainException('Preventive work order not found.');
        $pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM preventive_maintenance_plans WHERE plan_id=? FOR UPDATE');$s->execute([$planId]);$plan=$s->fetch(PDO::FETCH_ASSOC);$s=$pdo->prepare("SELECT * FROM maintenance_schedules WHERE maintenance_id=? AND preventive_plan_id=? AND maintenance_type='Preventive' FOR UPDATE");$s->execute([$id,$planId]);$job=$s->fetch(PDO::FETCH_ASSOC);if(!$job)throw new DomainException('Preventive work order changed.');$manager=gsoUserHasPermission($role,'maintenance.plan.manage');if(!$manager&&(int)$job['assigned_to']!==$actor)throw new DomainException('Only authorized GSO management or the assigned personnel may work on this occurrence.');
        if(!in_array($job['status'],['Scheduled','In Progress'],true))throw new DomainException('This occurrence is already completed or cancelled.');if($action==='cancel'&&!$manager)throw new DomainException('Only GSO plan management may cancel this occurrence.');
        $s=$pdo->prepare('SELECT * FROM resources WHERE resource_id=? FOR UPDATE');$s->execute([$job['resource_id']]);$resource=$s->fetch(PDO::FETCH_ASSOC);if(!$resource)throw new DomainException('The maintenance resource no longer exists.');$gone=$resource['condition_status']==='Lost';$unit=null;
        if($job['equipment_unit_id']){$s=$pdo->prepare('SELECT * FROM equipment_units WHERE unit_id=? FOR UPDATE');$s->execute([$job['equipment_unit_id']]);$unit=$s->fetch(PDO::FETCH_ASSOC);if(!$unit||(int)$unit['resource_id']!==(int)$job['resource_id'])throw new DomainException('The work order no longer matches its physical unit.');if(in_array($unit['status'],['Lost','Retired'],true)||$unit['condition_status']==='Lost')$gone=true;}
        // A Lost or retired target can never be serviced or restored here. Cancelling the
        // occurrence stays possible so the work order can be closed without touching inventory.
        if($gone&&$action!=='cancel')throw new DomainException('A Lost or retired target cannot receive preventive service or be restored through it.');
        $restored=0;
        if($action==='start'){
            if($job['status']!=='Scheduled'||$job['started_at']!==null||$job['start_date']>date('Y-m-d'))throw new DomainException('Only a due Scheduled occurrence can be explicitly started.');
            $target=['resource'=>$resource,'resource_id'=>(int)$job['resource_id'],'equipment_unit_id'=>$job['equipment_unit_id'],'quantity'=>(int)$job['quantity']];$start=date('Y-m-d').' 00:00:00';$end=max(date('Y-m-d'),$job['end_date']).' 23:59:59';gsoPreventiveValidateWindow($pdo,$target,$start,$end,$id);
            if($unit){if($unit['status']!=='Available'||$unit['condition_status']!=='Good')throw new DomainException('The exact unit must be available and serviceable before starting preventive maintenance.');$pdo->prepare("UPDATE equipment_units SET status='Under Repair',updated_at=NOW() WHERE unit_id=?")->execute([$job['equipment_unit_id']]);}
            elseif($resource['resource_type']==='Item'){$q=(int)$job['quantity'];$s=$pdo->prepare('UPDATE resources SET available_stock=available_stock-?,under_repair_stock=under_repair_stock+? WHERE resource_id=? AND available_stock>=?');$s->execute([$q,$q,$job['resource_id'],$q]);if($s->rowCount()!==1)throw new DomainException('The target quantity is not currently available.');}
            $pdo->prepare("UPDATE maintenance_schedules SET status='In Progress',started_at=NOW(),updated_by=?,updated_at=NOW() WHERE maintenance_id=?")->execute([$actor,$id]);
        }else{
            if($action==='complete'&&($job['status']!=='In Progress'||$job['started_at']===null))throw new DomainException('Start this occurrence explicitly before completion.');
            if($job['started_at']!==null&&!$gone){
                if($unit){if($unit['status']!=='Under Repair')throw new DomainException('This maintenance no longer owns the held unit.');if($unit['condition_status']==='Good'){$pdo->prepare("UPDATE equipment_units SET status='Available',updated_at=NOW() WHERE unit_id=?")->execute([$job['equipment_unit_id']]);$restored=1;}}
                elseif($resource['resource_type']==='Item'){$q=(int)$job['quantity'];$s=$pdo->prepare('UPDATE resources SET available_stock=available_stock+?,under_repair_stock=under_repair_stock-? WHERE resource_id=? AND under_repair_stock>=? AND available_stock+?<=total_stock');$s->execute([$q,$q,$job['resource_id'],$q,$q]);if($s->rowCount()!==1)throw new DomainException('The held preventive quantity no longer matches inventory.');$restored=$q;}
            }
            $status=$action==='complete'?'Completed':'Cancelled';$pdo->prepare('UPDATE maintenance_schedules SET status=?,completion_notes=?,completed_at=?,restored_at=?,updated_by=?,updated_at=NOW() WHERE maintenance_id=?')->execute([$status,$notes?:null,$action==='complete'?date('Y-m-d H:i:s'):null,$restored>0?date('Y-m-d H:i:s'):null,$actor,$id]);
            if($action==='complete'){
                if($plan['recurrence']==='Once')$pdo->prepare('UPDATE preventive_maintenance_plans SET is_active=0,revision=revision+1,updated_by=?,updated_at=NOW() WHERE plan_id=?')->execute([$actor,$planId]);
                elseif($plan['next_due_date']===$job['plan_occurrence_date']){$next=gsoPreventiveNextDate($job['plan_occurrence_date'],$plan['recurrence'],(int)$plan['anchor_day']);$pdo->prepare('UPDATE preventive_maintenance_plans SET next_due_date=?,revision=revision+1,updated_by=?,updated_at=NOW() WHERE plan_id=?')->execute([$next,$actor,$planId]);}
            }
        }
        if($unit){require_once __DIR__.'/equipment_unit_helper.php';gsoRecalculateSerializedStock($pdo,(int)$job['resource_id']);}refreshResourceOperationalStatus($pdo,(int)$job['resource_id']);gsoPreventiveAudit($pdo,$actor,'Preventive Maintenance '.ucfirst($action),'MTN-'.$id.'; plan #'.$planId.'; '.$action.'; restored '.$restored.' unit(s).');$pdo->commit();return ['ok'=>true,'message'=>'Preventive maintenance '.$action.' recorded.','restored'=>$restored];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();return ['ok'=>false,'message'=>gsoSafeApplicationError($e, "app/models/preventive_maintenance_helper.php")];}
}
