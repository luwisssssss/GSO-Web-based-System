<?php
/** Client events are prepared with the business change and delivered after commit. */
require_once __DIR__.'/request_helper.php';
require_once __DIR__.'/notification_helper.php';
require_once __DIR__.'/activity_log_helper.php';
require_once __DIR__.'/mail_helper.php';

function gsoClientEventCatalog(): array
{
    return [
        'submitted'=>['Pending','request_submitted','Request Received','Your request was received for GSO review.'],
        'approved'=>['Approved','approved','Request Approved','Your request is approved. Coordinate with GSO for claim/release.'],
        'rejected'=>['Rejected','rejected','Request Rejected','GSO declined this request. Contact GSO for guidance.'],
        'cancelled'=>['Cancelled','request_cancelled','Request Cancelled','GSO recorded the cancellation of this request.'],
        'reschedule_submitted'=>['Approved','reschedule_submitted','Reschedule Proposal Received','Your proposed schedule was recorded for GSO review. It is not guaranteed or reserved. The original confirmed reservation remains active.'],
        'reschedule_approved'=>['Approved','reschedule_approved','Facility Reschedule Approved','GSO approved the reschedule. The schedule below is the NEW confirmed reservation.'],
        'reschedule_rejected'=>[['Approved','Released','Return','Returned','Completed','Cancelled','Rejected'],'reschedule_rejected','Facility Reschedule Rejected','GSO rejected the proposal, not the reservation. The original confirmed reservation remains unchanged.'],
        'released'=>[['Released','Completed'],'system','Request Released','GSO released the approved resources. Issued consumables do not require return.'],
        'return_submitted'=>['Return','RETURN_SUBMITTED','Return Submission Received','Your return/post-use submission was received and is awaiting GSO inspection. This is not yet an accepted Returned transaction.'],
        'return_accepted'=>['Returned','RETURN_APPROVED','Return Inspection Accepted','GSO accepted the inspection and recorded the request as Returned. Only accepted good stock becomes available; repairable outcomes remain withheld and Lost remains permanently unavailable.'],
        'return_rejected'=>['Released','RETURN_REJECTED','Return Submission Rejected','GSO rejected the submission. Correct and resubmit return/post-use evidence; contact GSO for assistance.'],
        'overdue'=>['Released','OVERDUE_REMINDER','Return Reminder','This released borrowing is overdue. Please coordinate the return with GSO.'],
        'letter_verified'=>[['Pending','Under Review'],'request_letter_verified','Request Letter Verified','Your manually signed document was verified for GSO processing. Request approval remains a separate action. Manual signatures were obtained outside the system.'],
        'letter_rejected'=>[['Pending','Under Review'],'request_letter_rejected','Request Letter Rejected','Replace the rejected document with a corrected manually signed request letter for GSO verification.'],
        'accountability'=>['Returned','accountability_notice','Accountability Review Update','GSO recorded a non-financial accountability review. Contact GSO about the recorded condition and next steps. Lost is permanently unavailable; it is not a repairable condition.'],
    ];
}

function gsoClientRequest(PDO $pdo, int $id, bool $lock=false): ?array
{
    $s=$pdo->prepare('SELECT rr.*,u.email AS registered_email,u.full_name AS registered_name FROM resource_requests rr LEFT JOIN users u ON u.user_id=rr.borrower_id WHERE rr.request_id=?'.($lock?' FOR UPDATE':''));
    $s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Must be called only from a successfully guarded state transition. */
function gsoPrepareClientEvent(PDO $pdo, int $requestId, string $event, string $occurrence='once', array $context=[]): ?array
{
    if (!$pdo->inTransaction()) throw new LogicException('Client events must be prepared within a business transaction.');
    $spec=gsoClientEventCatalog()[$event]??null;
    if (!$spec) throw new LogicException('Unsupported client event.');
    $row=gsoClientRequest($pdo,$requestId,true);
    if (!$row || !in_array($row['status'],(array)$spec[0],true)) throw new LogicException('Client event does not match the authoritative state.');
    if ($event==='overdue' && (!$row['due_date'] || strtotime($row['due_date'])>=time() || $row['return_date'])) return null;
    $actor=(int)($row['requester_type']==='Walk-in'?$row['encoded_by']:$row['borrower_id']);
    $key=formatRequestCode($requestId).' '.$event.' R9 '.hash('sha256',$requestId.':'.$event.':'.$occurrence);
    $s=$pdo->prepare("SELECT 1 FROM activity_logs WHERE action='Client Event Recorded' AND details=? LIMIT 1");$s->execute([$key]);
    if ($s->fetchColumn()) return null; // Request-row lock serializes duplicate event markers.
    $lines=$pdo->prepare('SELECT r.resource_name,i.line_kind,i.requested_quantity,i.facility_slot FROM request_items i JOIN resources r ON r.resource_id=i.resource_id WHERE i.request_id=? ORDER BY i.request_item_id');$lines->execute([$requestId]);$items=$lines->fetchAll(PDO::FETCH_ASSOC);
    $summary=[];$period=null;
    foreach($items as $item){$summary[]=$item['resource_name'].($item['line_kind']==='Facility'?'':' x'.$item['requested_quantity']);if($item['line_kind']==='Facility')$period=$item['facility_slot'];}
    $text=$spec[3];
    if ($event==='return_accepted' || $event==='accountability') {
        $s=$pdo->prepare('SELECT '.gsoReturnOutcomeSummarySql().' FROM resource_requests rr WHERE rr.request_id=?');$s->execute([$requestId]);$outcomes=(string)$s->fetchColumn();if($outcomes!=='')$text.=' Recorded outcomes: '.$outcomes.'.';
    }
    // Only rejection reasons explicitly intended for the client are accepted.
    if ($event==='letter_rejected' && isset($context['client_reason'])) $text.=' Correction: '.mb_substr(trim((string)$context['client_reason']),0,1000);
    if ($event==='accountability' && isset($context['accountability_status'])) $text.=' Review state: '.$context['accountability_status'].'.';
    $code=formatRequestCode($requestId);
    $schedule=(string)($row['date_needed']??'');
    if ($period) $schedule.=' | '.$period;
    if ($row['start_time'] && $row['end_time']) $schedule.=' | '.substr($row['start_time'],0,5).' to '.substr($row['end_time'],0,5);
    $plain=$spec[2]."\nReference: ".$code."\nStatus: ".$row['status']."\nResources: ".implode(', ',$summary)."\n".($schedule!==''?'Current request schedule: '.$schedule."\n":'').$text;
    if (in_array($event,['released','overdue'],true) && $row['due_date']) $plain.="\nDue: ".$row['due_date'];
    $registered=$row['requester_type']==='Registered' && (int)$row['borrower_id']>0 && $row['registered_email']!==null;
    if (!$registered && !($row['requester_type']==='Walk-in' && $row['borrower_id']===null && $actor>0)) throw new LogicException('Invalid client relationship.');
    $link='my_requests.php';
    if ($event==='overdue') $link='my_borrowed.php?loan_state=Overdue&focus_request_id='.$requestId;
    if ($event==='released') $link='my_borrowed.php';
    if ($event==='return_accepted') $link='history.php';
    if ($event==='return_rejected') $link='return_submit.php?request_id='.$requestId;
    if (str_starts_with($event,'reschedule_')) $link='reschedule.php?request_id='.$requestId;
    if (str_starts_with($event,'letter_')) $link='request_letter.php?request_id='.$requestId;
    $inAppTitle=match($event){'return_accepted'=>'Return Completed','return_rejected'=>'Return Rejected','reschedule_submitted'=>'Reschedule Proposal Submitted',default=>$spec[2]};
    if ($registered && !createNotification($pdo,(int)$row['borrower_id'],$spec[1],$inAppTitle,mb_substr($code.' '.$text.(in_array($event,['approved','reschedule_approved','reschedule_rejected'],true)?' Schedule: '.$schedule:''),0,255),$link)) throw new RuntimeException('Client notification could not be recorded.');
    if (!addActivityLog($pdo,$actor,'Client Event Recorded',$key)) throw new RuntimeException('Client event could not be recorded.');
    return ['request_id'=>$requestId,'key'=>$key,'actor'=>$actor,'event'=>$event,'title'=>$spec[2],'plain'=>$plain,'link'=>$registered?$link:null];
}

/** Notification links use configured application identity, never a browser Host header. */
function gsoClientNotificationUrl(string $link): ?string
{
    $base=trim((string)gsoEnv('GSO_APP_URL',''));
    $parts=parse_url($base);
    if (!$parts || !in_array($parts['scheme']??'', ['http','https'],true)
        || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || gsoSafeNotificationLink($link)==='#') return null;
    return rtrim($base,'/').'/borrower/'.$link;
}

/** No SMTP or local mail capture is allowed before the authoritative commit. */
function gsoDeliverClientEvent(PDO $pdo, ?array $event): array
{
    if ($event===null) return ['success'=>true,'skipped'=>true];
    if ($pdo->inTransaction()) return ['success'=>false,'skipped'=>true];
    try {
        // Claim one delivery attempt using existing audit records under the
        // same request lock. No retry queue or schema expansion is introduced.
        $pdo->beginTransaction();$row=gsoClientRequest($pdo,(int)$event['request_id'],true);
        $s=$pdo->prepare("SELECT 1 FROM activity_logs WHERE action='Client Event Recorded' AND details=? LIMIT 1");$s->execute([$event['key']]);
        if (!$row || !$s->fetchColumn()) {$pdo->rollBack();return ['success'=>true,'skipped'=>true];}
        $s=$pdo->prepare("SELECT 1 FROM activity_logs WHERE action IN ('Client Email Attempt','Client Email Skipped') AND details=? LIMIT 1");
        $s->execute([$event['key']]);
        if ($s->fetchColumn()) {$pdo->rollBack();return ['success'=>true,'skipped'=>true];}
        $registered=$row['requester_type']==='Registered';
        $email=trim((string)($registered?$row['registered_email']:$row['walkin_email']));
        $name=(string)($registered?$row['registered_name']:$row['walkin_full_name']);
        if ($email==='' || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
            if (!addActivityLog($pdo,(int)$event['actor'],'Client Email Skipped',$event['key'])) throw new RuntimeException('Delivery skip could not be recorded.');
            $pdo->commit();
            return ['success'=>true,'skipped'=>true];
        }
        if (!addActivityLog($pdo,(int)$event['actor'],'Client Email Attempt',$event['key'])) throw new RuntimeException('Delivery attempt could not be recorded.');
        $pdo->commit();
        $plain="University of Rizal System - Binangonan Campus\nGeneral Services Office\n\n".$event['plain'];
        if ($registered && $event['link']) {
            $url=gsoClientNotificationUrl($event['link']);
            $plain.=$url ? "\n\nSign in to view: ".$url : "\n\nSign in through the GSO system and open My Requests for details.";
        }
        $html='<p>'.nl2br(htmlspecialchars($plain,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';
        $mailConfig=require dirname(__DIR__,2).'/config/mail.php';
        $result=gsoSendEmail($mailConfig,$email,$name,'GSO - '.$event['title'].' - '.formatRequestCode((int)$event['request_id']),$html,$plain);
        $ok=!empty($result['success']);
        addActivityLog($pdo,(int)$event['actor'],$ok?'Client Email Sent':'Client Email Failed',$event['key']);
        if (!$ok) error_log('GSO client email delivery could not be confirmed: '.$event['key']);
        return ['success'=>$ok,'skipped'=>false]; // Never expose SMTP details.
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack(); // Only the delivery claim, never business state.
        error_log('GSO client email delivery could not be confirmed.');
        return ['success'=>false,'skipped'=>false];
    }
}
