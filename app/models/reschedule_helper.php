<?php
require_once __DIR__.'/client_notification_helper.php';
require_once __DIR__ . '/request_line_helper.php';
require_once __DIR__ . '/request_attachment_helper.php';
require_once __DIR__ . '/activity_log_helper.php';
require_once __DIR__ . '/notification_helper.php';

function gsoRescheduleActor(PDO $pdo,int $actorId,string $permission): void
{
    $s=$pdo->prepare("SELECT role FROM users WHERE user_id=? AND account_status='Approved' AND email_verified=1");
    $s->execute([$actorId]);$role=$s->fetchColumn();
    if(!is_string($role)||!gsoUserHasPermission($role,$permission))throw new DomainException('Your account is not authorized for this reschedule action.');
}

function gsoRescheduleEligible(PDO $pdo,array $request,bool $checkPending=true): bool
{
    $start=buildScheduleStartDateTime($request['date_needed']??null,$request['start_time']??null);
    if(($request['status']??'')!=='Approved'||$start===null||strtotime($start)<=time()||!gsoRequestHasFacility($pdo,(int)$request['request_id']))return false;
    return !$checkPending||gsoPendingReschedule($pdo,(int)$request['request_id'])===null;
}

function gsoPendingReschedule(PDO $pdo,int $requestId): ?array
{
    $s=$pdo->prepare("SELECT * FROM request_reschedule_history WHERE request_id=? AND status='Pending' LIMIT 1");$s->execute([$requestId]);$row=$s->fetch(PDO::FETCH_ASSOC);return $row?:null;
}

function gsoRescheduleHistory(PDO $pdo,int $requestId): array
{
    $s=$pdo->prepare('SELECT h.*,CASE WHEN h.requested_by IS NULL THEN CONCAT(parent.walkin_full_name," (Walk-in)") ELSE u.full_name END AS requester_name,encoder.full_name AS encoder_name,v.full_name AS reviewer_name FROM request_reschedule_history h LEFT JOIN users u ON u.user_id=h.requested_by LEFT JOIN users encoder ON encoder.user_id=h.encoded_by JOIN resource_requests parent ON parent.request_id=h.request_id LEFT JOIN users v ON v.user_id=h.reviewed_by WHERE h.request_id=? ORDER BY h.created_at DESC,h.reschedule_id DESC');$s->execute([$requestId]);return $s->fetchAll(PDO::FETCH_ASSOC);
}

function gsoRescheduleSchedule(array $row,string $prefix): string
{
    return formatDateTimeDisplay($row[$prefix.'_date_needed']).' | '.gsoRequestScheduleDisplay(['resource_type'=>'Facility','facility_slot'=>$row[$prefix.'_facility_slot'],'start_time'=>$row[$prefix.'_start_time'],'end_time'=>$row[$prefix.'_end_time']]);
}

function gsoValidateRescheduleProposal(PDO $pdo,array $data,bool $requestExtension): array
{
    $date=trim((string)($data['date_needed']??''));$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Enter a valid proposed reservation date.');
    $period=(string)($data['facility_slot']??'');$start=gsoFacilityClock((string)($data['start_time']??''));$end=gsoFacilityClock((string)($data['end_time']??''));
    $error=gsoFacilityPeriodError($period,$start,$end);
    if($error!==null)throw new DomainException($error);
    $errors=validateBorrowScheduleInputs('Facility',1,$date,$start,$end,null);
    if($errors)throw new DomainException(implode(' ',$errors));
    $error=gsoFacilitySubmissionHoursError($pdo,$start,$end,$requestExtension);
    if($error!==null)throw new DomainException($error);
    return ['date_needed'=>$date,'start_time'=>$start,'end_time'=>$end,'facility_slot'=>$period];
}

function gsoReschedulePreview(PDO $pdo,array $request,array $proposal): ?string
{
    // Only this request is excluded. Proposals are never availability inputs.
    return gsoRequestLineAvailabilityError($pdo,array_replace($request,$proposal));
}

function gsoRescheduleAudit(PDO $pdo,int $actor,string $action,string $details): void
{
    if(!addActivityLog($pdo,$actor,$action,mb_substr($details,0,255)))throw new RuntimeException('The reschedule audit could not be saved.');
}

function gsoNotifyRescheduleOperators(PDO $pdo,int $requestId,int $attempt,bool $walkin=false): void
{
    $roles=[];foreach(gsoRolePermissions() as $role=>$permissions)if(in_array('request.reschedule.manage',$permissions,true))$roles[]=$role;
    if(!$roles)return;
    $s=$pdo->prepare("SELECT user_id FROM users WHERE role IN (".implode(',',array_fill(0,count($roles),'?')).") AND account_status='Approved' AND email_verified=1");$s->execute($roles);
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $id)createNotification($pdo,(int)$id,'reschedule_pending','Facility Reschedule Requested',formatRequestCode($requestId).' has a new client-requested schedule proposal #'.$attempt.'. The original reservation remains active.','reschedules.php?request_id='.$requestId);
}

function gsoCreateReschedule(PDO $pdo,int $actor,int $requestId,array $data,bool $walkin=false): array
{
    gsoRescheduleActor($pdo,$actor,$walkin?'walk_in.create':'request.view_own');
    $proposal=gsoValidateRescheduleProposal($pdo,$data,($data['request_extended_hours']??'')==='1');
    $reason=trim((string)($data['reason']??''));if($reason===''||mb_strlen($reason)>1000)throw new DomainException('Provide a client reason of up to 1,000 characters.');
    try{
        $pdo->beginTransaction();$s=$pdo->prepare($walkin?"SELECT * FROM resource_requests WHERE request_id=? AND requester_type='Walk-in' AND borrower_id IS NULL FOR UPDATE":"SELECT * FROM resource_requests WHERE request_id=? AND borrower_id=? AND requester_type='Registered' FOR UPDATE");$s->execute($walkin?[$requestId]:[$requestId,$actor]);$request=$s->fetch(PDO::FETCH_ASSOC);
        if(!$request||!gsoRescheduleEligible($pdo,$request))throw new DomainException('Only your approved future facility reservation with no pending reschedule is eligible.');
        $facility=gsoRequestFacilityLine($pdo,$requestId);
        if([$request['date_needed'],$request['start_time'],$request['end_time'],$facility['facility_slot']]===array_values($proposal))throw new DomainException('Choose a different proposed schedule.');
        $preview=gsoReschedulePreview($pdo,$request,$proposal);
        $s=$pdo->prepare('INSERT INTO request_reschedule_history(request_id,requested_by,encoded_by,old_date_needed,old_start_time,old_end_time,old_facility_slot,new_date_needed,new_start_time,new_end_time,new_facility_slot,client_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$requestId,$walkin?null:$actor,$walkin?$actor:null,$request['date_needed'],$request['start_time'],$request['end_time'],$facility['facility_slot'],$proposal['date_needed'],$proposal['start_time'],$proposal['end_time'],$proposal['facility_slot'],$reason]);$id=(int)$pdo->lastInsertId();
        gsoRescheduleAudit($pdo,$actor,'Facility Reschedule Requested','Request #'.$requestId.'; proposal #'.$id.' submitted. Original confirmed interval preserved.');
        gsoNotifyRescheduleOperators($pdo,$requestId,$id);$event=gsoPrepareClientEvent($pdo,$requestId,'reschedule_submitted',(string)$id);$pdo->commit();gsoDeliverClientEvent($pdo,$event);return ['reschedule_id'=>$id,'availability_error'=>$preview];
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function gsoCancelReschedule(PDO $pdo,int $actor,int $requestId,int $attempt,bool $walkin=false): void
{
    gsoRescheduleActor($pdo,$actor,$walkin?'walk_in.create':'request.view_own');
    try{
        $pdo->beginTransaction();$s=$pdo->prepare($walkin?"SELECT request_id FROM resource_requests WHERE request_id=? AND requester_type='Walk-in' AND borrower_id IS NULL FOR UPDATE":"SELECT request_id FROM resource_requests WHERE request_id=? AND borrower_id=? AND requester_type='Registered' FOR UPDATE");$s->execute($walkin?[$requestId]:[$requestId,$actor]);if(!$s->fetchColumn())throw new DomainException('Reservation not found.');
        $s=$pdo->prepare("UPDATE request_reschedule_history SET status='Cancelled',processed_at=NOW() WHERE reschedule_id=? AND request_id=? AND ".($walkin?"requested_by IS NULL AND encoded_by IS NOT NULL":"requested_by=? AND encoded_by IS NULL")." AND status='Pending'");$s->execute($walkin?[$attempt,$requestId]:[$attempt,$requestId,$actor]);if($s->rowCount()!==1)throw new DomainException('Only your pending reschedule can be cancelled.');
        gsoRescheduleAudit($pdo,$actor,'Facility Reschedule Cancelled','Request #'.$requestId.'; proposal #'.$attempt.' cancelled on the client request. Original reservation preserved.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}

function gsoReviewReschedule(PDO $pdo,int $actor,int $requestId,int $attempt,string $decision,array $data): void
{
    gsoRescheduleActor($pdo,$actor,'request.reschedule.manage');
    if(!in_array($decision,['approve','reject'],true))throw new DomainException('Select Approve or Reject.');
    $remarks=trim((string)($data['review_remarks']??''));if(mb_strlen($remarks)>1000)throw new DomainException('Review remarks must not exceed 1,000 characters.');
    try{
        $pdo->beginTransaction();$s=$pdo->prepare('SELECT * FROM resource_requests WHERE request_id=? FOR UPDATE');$s->execute([$requestId]);$request=$s->fetch(PDO::FETCH_ASSOC);if(!$request)throw new DomainException('Reservation not found.');
        $s=$pdo->prepare("SELECT * FROM request_reschedule_history WHERE reschedule_id=? AND request_id=? AND status='Pending' FOR UPDATE");$s->execute([$attempt,$requestId]);$row=$s->fetch(PDO::FETCH_ASSOC);if(!$row)throw new DomainException('The proposal is no longer pending for this request.');
        if($decision==='approve'){
            // Resource locks precede all consistent reads used by the final availability check.
            gsoLockRequestSchedulingResources($pdo,$requestId);
            if(!gsoRescheduleEligible($pdo,$request,false))throw new DomainException('The reservation is no longer approved and future.');
            $facility=gsoRequestFacilityLine($pdo,$requestId);
            if([$request['date_needed'],$request['start_time'],$request['end_time'],$facility['facility_slot']]!==[$row['old_date_needed'],$row['old_start_time'],$row['old_end_time'],$row['old_facility_slot']])throw new DomainException('The current reservation changed since this proposal. Reject it and request a new proposal.');
            $proposal=gsoValidateRescheduleProposal($pdo,['date_needed'=>$row['new_date_needed'],'start_time'=>$row['new_start_time'],'end_time'=>$row['new_end_time'],'facility_slot'=>$row['new_facility_slot']],true);
            $extended=gsoFacilityNeedsExtendedHours($pdo,$proposal['start_time'],$proposal['end_time']);$extendedRemarks=trim((string)($data['extended_hours_remarks']??''));
            if($extended&&(($data['allow_extended_hours']??'')!=='1'||$extendedRemarks===''||mb_strlen($extendedRemarks)>120))throw new DomainException('Explicit GSO extended-hours authorization and audit remarks (up to 120 characters) are required.');
            $error=gsoReschedulePreview($pdo,$request,$proposal);if($error!==null)throw new DomainException('Current availability: '.$error);
            if(!gsoRequestLetterAllowsApproval($pdo,$requestId))throw new DomainException('The current facility request letter must remain Verified before reschedule approval.');
            $s=$pdo->prepare("UPDATE resource_requests SET date_needed=?,start_time=?,end_time=? WHERE request_id=? AND status='Approved'");$s->execute([$proposal['date_needed'],$proposal['start_time'],$proposal['end_time'],$requestId]);
            $pdo->prepare("UPDATE request_items SET facility_slot=? WHERE request_item_id=? AND line_kind='Facility'")->execute([$proposal['facility_slot'],$facility['request_item_id']]);
            // due_date remains release-derived, as in R3; no allocation, issuance or stock mutation occurs here.
            if($extended){
                $remarks=trim($remarks."\nExtended-hours authorization: ".$extendedRemarks);if(mb_strlen($remarks)>1000)throw new DomainException('Combined review and authorization remarks exceed 1,000 characters.');
                gsoRescheduleAudit($pdo,$actor,'Extended Facility Hours Authorized','Reschedule #'.$attempt.'; request #'.$requestId.'; '.$proposal['date_needed'].'; '.$proposal['facility_slot'].' '.$proposal['start_time'].'-'.$proposal['end_time'].'; '.$extendedRemarks);
            }
        }
        $status=$decision==='approve'?'Approved':'Rejected';
        $s=$pdo->prepare("UPDATE request_reschedule_history SET status=?,reviewed_by=?,reviewed_at=NOW(),review_remarks=?,processed_at=NOW() WHERE reschedule_id=? AND request_id=? AND status='Pending'");$s->execute([$status,$actor,$remarks!==''?$remarks:null,$attempt,$requestId]);if($s->rowCount()!==1)throw new DomainException('Another action already processed this proposal.');
        gsoRescheduleAudit($pdo,$actor,'Facility Reschedule '.$status,'Request #'.$requestId.'; proposal #'.$attempt.' '.$status.'. '.gsoRescheduleSchedule($row,'old').' -> '.gsoRescheduleSchedule($row,'new'));
        $event=gsoPrepareClientEvent($pdo,$requestId,'reschedule_'.strtolower($status),(string)$attempt);
        $pdo->commit();
        gsoDeliverClientEvent($pdo,$event);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
