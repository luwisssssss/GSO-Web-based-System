<?php
require_once __DIR__.'/client_notification_helper.php';
require_once __DIR__.'/request_line_helper.php';
require_once __DIR__.'/request_attachment_helper.php';
require_once __DIR__.'/reschedule_helper.php';

function gsoCreateWalkin(PDO $pdo,int $actor,array $data,array $file): int
{
    gsoWalkinActor($pdo,$actor);
    $identity=[];foreach(['walkin_full_name'=>150,'walkin_organization'=>200,'walkin_email'=>150,'walkin_contact_number'=>30] as $key=>$max){$value=trim((string)($data[$key]??''));if(mb_strlen($value)>$max)throw new DomainException('Client information exceeds the allowed length.');$identity[$key]=$value===''?null:$value;}
    if($identity['walkin_full_name']===null)throw new DomainException('Client full name is required.');
    if($identity['walkin_email']!==null&&!filter_var($identity['walkin_email'],FILTER_VALIDATE_EMAIL))throw new DomainException('Enter a valid optional email address.');
    if($identity['walkin_contact_number']!==null&&!preg_match('/^[0-9+\-\s()]{7,30}$/',$identity['walkin_contact_number']))throw new DomainException('Enter a valid contact number.');
    $normalized=gsoNormalizeRequestedLines($pdo,array_values(array_filter((array)($data['lines']??[]),fn($line)=>is_array($line)&&(int)($line['resource_id']??0)>0)));if($normalized['errors'])throw new DomainException(implode(' ',$normalized['errors']));$lines=$normalized['lines'];if(!$lines)throw new DomainException('Choose at least one resource.');
    $facility=false;foreach($lines as $line)if($line['line_kind']==='Facility')$facility=true;
    $date=trim((string)($data['date_needed']??''));$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$parsed||$parsed->format('Y-m-d')!==$date)throw new DomainException('Enter a valid reservation date.');
    $start=trim((string)($data['start_time']??''));$end=trim((string)($data['end_time']??''));$period=(string)($data['facility_slot']??'');
    if($facility){$proposal=gsoValidateRescheduleProposal($pdo,$data,($data['request_extended_hours']??'')==='1');$start=$proposal['start_time'];$end=$proposal['end_time'];}
    else{$errors=validateBorrowScheduleInputs('Item',1,$date,$start?:null,$end?:null,null);if($errors)throw new DomainException(implode(' ',$errors));if($period!==''||isset($data['request_extended_hours']))throw new DomainException('AM/PM and extended hours apply only to Facility requests.');}
    $notes=trim((string)($data['notes']??''));if(mb_strlen($notes)>255)throw new DomainException('Notes must not exceed 255 characters.');
    $letter=$facility?gsoValidateRequestLetter($file):null;$stored=null;
    try{
        $pdo->beginTransaction();gsoWalkinActor($pdo,$actor);
        foreach($lines as $line){$availability=checkResourceAvailability($pdo,$line['resource'],(int)$line['quantity'],$date,$start?:null,$end?:null,null);if(!($availability['ok']??false))throw new DomainException($line['resource']['resource_name'].': '.($availability['message']??'Unavailable.'));}
        $s=$pdo->prepare("INSERT INTO resource_requests(requester_type,borrower_id,walkin_full_name,walkin_organization,walkin_email,walkin_contact_number,encoded_by,contact_number,date_needed,start_time,end_time,status,notes) VALUES('Walk-in',NULL,?,?,?,?,?,?,?,?,?,'Pending',?)");
        $s->execute([$identity['walkin_full_name'],$identity['walkin_organization'],$identity['walkin_email'],$identity['walkin_contact_number'],$actor,$identity['walkin_contact_number'],$date,$start?:null,$end?:null,$notes?:null]);$id=(int)$pdo->lastInsertId();
        $s=$pdo->prepare('INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity,facility_slot) VALUES(?,?,?,?,?)');foreach($lines as $line)$s->execute([$id,$line['resource_id'],$line['line_kind'],$line['quantity'],$line['line_kind']==='Facility'?$period:null]);
        if($letter!==null){$stored=gsoStoreRequestLetter($letter);gsoInsertRequestLetter($pdo,$id,$actor,$stored);}
        gsoRescheduleAudit($pdo,$actor,'Walk-in Request Created','Request #'.$id.' encoded on behalf of an external client; '.count($lines).' resource line(s). No account created.');
        $roles=[];foreach(gsoRolePermissions() as $role=>$permissions)if(in_array('walk_in.create',$permissions,true))$roles[]=$role;
        $s=$pdo->prepare("SELECT user_id FROM users WHERE role IN(".implode(',',array_fill(0,count($roles),'?')).") AND account_status='Approved' AND email_verified=1");$s->execute($roles);foreach($s->fetchAll(PDO::FETCH_COLUMN) as $user)createNotification($pdo,(int)$user,'walkin_request','Walk-in Request Created',formatRequestCode($id).' requires GSO review.','view_request.php?request_id='.$id);
        $event=gsoPrepareClientEvent($pdo,$id,'submitted');$pdo->commit();gsoDeliverClientEvent($pdo,$event);return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($stored)@unlink($stored['path']);throw $e;}
}
function gsoCancelWalkin(PDO $pdo,int $actor,int $id,string $reason): void
{
    gsoWalkinActor($pdo,$actor);$reason=trim($reason);if($reason===''||mb_strlen($reason)>1000)throw new DomainException('Provide the client cancellation reason (up to 1,000 characters).');
    try{$pdo->beginTransaction();$s=$pdo->prepare("SELECT status FROM resource_requests WHERE request_id=? AND requester_type='Walk-in' AND borrower_id IS NULL FOR UPDATE");$s->execute([$id]);if(!in_array($s->fetchColumn(),['Pending','Under Review'],true))throw new DomainException('Only pending or under-review walk-in requests may be cancelled.');
        $pdo->prepare("UPDATE resource_requests SET status='Cancelled',cancellation_reason=? WHERE request_id=?")->execute([$reason,$id]);gsoRescheduleAudit($pdo,$actor,'Walk-in Request Cancelled','Request #'.$id.'; client reason: '.$reason);$event=gsoPrepareClientEvent($pdo,$id,'cancelled');$pdo->commit();gsoDeliverClientEvent($pdo,$event);
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function gsoUploadWalkinLetter(PDO $pdo,int $actor,int $id,array $file): void
{
    gsoWalkinActor($pdo,$actor,'request.documents.verify');$letter=gsoValidateRequestLetter($file);$stored=null;
    try{$pdo->beginTransaction();$s=$pdo->prepare("SELECT status FROM resource_requests WHERE request_id=? AND requester_type='Walk-in' AND borrower_id IS NULL FOR UPDATE");$s->execute([$id]);if(!in_array($s->fetchColumn(),['Pending','Under Review'],true))throw new DomainException('Only unapproved walk-in requests accept letter uploads.');
        if(!gsoRequestHasFacility($pdo,$id))throw new DomainException('This request has no Facility.');$current=gsoRequestLetters($pdo,$id)[0]??null;if($current&&$current['verification_status']!=='Rejected')throw new DomainException('The current letter must be rejected before replacement.');
        $stored=gsoStoreRequestLetter($letter);gsoInsertRequestLetter($pdo,$id,$actor,$stored);gsoRescheduleAudit($pdo,$actor,'Walk-in Letter Uploaded','New letter version for request #'.$id.'. Verification remains a separate action.');$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($stored)@unlink($stored['path']);throw $e;}
}
