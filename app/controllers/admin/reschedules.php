<?php
define('GSO_REQUIRED_PERMISSION','request.reschedule.manage');
require_once dirname(__DIR__,3).'/includes/admin_check.php';
require_once dirname(__DIR__,3).'/config/db.php';
require_once dirname(__DIR__,3).'/includes/reschedule_helper.php';
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$requestId=max(0,(int)($_GET['request_id']??0));
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??'')))throw new DomainException('Invalid request token.');
        $postedRequest=(int)($_POST['request_id']??0);
        if($requestId&&$postedRequest!==$requestId)throw new DomainException('The review request identifier does not match this page.');
        gsoReviewReschedule($pdo,(int)$_SESSION['user_id'],$postedRequest,(int)($_POST['reschedule_id']??0),(string)($_POST['action']??''),$_POST);
        redirectWithFlash('reschedules.php'.($requestId?'?request_id='.$requestId:''),'Reschedule decision saved.','success');
    }catch(Throwable $e){redirectWithFlash('reschedules.php'.($requestId?'?request_id='.$requestId:''),$e instanceof DomainException?$e->getMessage():'The reschedule decision could not be saved. Refresh and try again.');}
}
$status=(string)($_GET['status']??($requestId?'All':'Pending'));if(!in_array($status,['All','Pending','Approved','Rejected','Cancelled'],true))$status='Pending';
$where=[];$args=[];if($requestId){$where[]='h.request_id=?';$args[]=$requestId;}if($status!=='All'){$where[]='h.status=?';$args[]=$status;}
$s=$pdo->prepare('SELECT h.*,CASE WHEN h.requested_by IS NULL THEN CONCAT(parent.walkin_full_name," (Walk-in)") ELSE u.full_name END AS requester_name,encoder.full_name AS encoder_name,v.full_name AS reviewer_name FROM request_reschedule_history h LEFT JOIN users u ON u.user_id=h.requested_by LEFT JOIN users encoder ON encoder.user_id=h.encoded_by JOIN resource_requests parent ON parent.request_id=h.request_id LEFT JOIN users v ON v.user_id=h.reviewed_by'.($where?' WHERE '.implode(' AND ',$where):'')." ORDER BY (h.status='Pending') DESC,h.created_at,h.reschedule_id LIMIT 100");$s->execute($args);$attempts=$s->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Facility Reschedule Review';$cssFile='../public/assets/css/pages/admin.css';require dirname(__DIR__,3).'/includes/header.php';require dirname(__DIR__,3).'/includes/admin_sidebar.php';
?>
<div class="main-content"><main class="page-content"><section class="card"><h1>Facility Reschedule Review</h1>
<?php if(!empty($_SESSION['flash_message'])):?><p role="status"><?php echo htmlspecialchars((string)$_SESSION['flash_message']);unset($_SESSION['flash_message'],$_SESSION['flash_type']);?></p><?php endif;?>
<p>The current reservation remains active until a proposal is approved. Availability shown here is a preview; approval rechecks it inside the transaction.</p>
<form method="get"><?php if($requestId):?><input type="hidden" name="request_id" value="<?php echo $requestId;?>"><?php endif;?><label>Status<select name="status"><?php foreach(['All','Pending','Approved','Rejected','Cancelled'] as $option):?><option <?php echo $status===$option?'selected':'';?>><?php echo $option;?></option><?php endforeach;?></select></label><button>View</button></form></section>
<?php if(!$attempts):?><section class="card">No matching reschedule proposals.</section><?php endif;?>
<?php foreach($attempts as $attempt):
    $s=$pdo->prepare('SELECT * FROM resource_requests WHERE request_id=?');$s->execute([$attempt['request_id']]);$reservation=$s->fetch(PDO::FETCH_ASSOC);$facility=gsoRequestFacilityLine($pdo,(int)$attempt['request_id']);$reservation['facility_slot']=$facility['facility_slot']??null;$reservation['resource_type']='Facility';
    $proposal=['date_needed'=>$attempt['new_date_needed'],'start_time'=>$attempt['new_start_time'],'end_time'=>$attempt['new_end_time'],'facility_slot'=>$attempt['new_facility_slot']];$preview=gsoReschedulePreview($pdo,$reservation,$proposal);$extended=gsoFacilityNeedsExtendedHours($pdo,$proposal['start_time'],$proposal['end_time']);
?>
<section class="card"><h2><?php echo htmlspecialchars(formatRequestCode((int)$attempt['request_id']).' | '.($facility['resource_name']??'Facility').' | Proposal #'.$attempt['reschedule_id']);?></h2>
<p>Borrower/requester: <?php echo htmlspecialchars($attempt['requester_name']);?></p><?php if($attempt['encoder_name']):?><p>Encoded by: <?php echo htmlspecialchars($attempt['encoder_name']);?></p><?php endif;?><p>Current reservation: <?php echo htmlspecialchars(formatDateTimeDisplay($reservation['date_needed']).' | '.gsoRequestScheduleDisplay($reservation));?></p>
<p>Original snapshot: <?php echo htmlspecialchars(gsoRescheduleSchedule($attempt,'old'));?></p><p>Proposed schedule: <?php echo htmlspecialchars(gsoRescheduleSchedule($attempt,'new'));?></p><p>Client reason: <?php echo htmlspecialchars($attempt['client_reason']);?></p>
<p>Status: <?php echo htmlspecialchars($attempt['status']);?> | Created: <?php echo htmlspecialchars($attempt['created_at']);?></p><p>Current availability: <?php echo htmlspecialchars($preview??'Available for this exact proposed interval; approval must recheck.');?></p>
<?php if($extended):?><p>Extended/evening proposal: explicit GSO authorization and audit remarks are required at approval.</p><?php endif;?>
<?php if($attempt['status']==='Pending'):?><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>"><input type="hidden" name="request_id" value="<?php echo (int)$attempt['request_id'];?>"><input type="hidden" name="reschedule_id" value="<?php echo (int)$attempt['reschedule_id'];?>">
<label>Review remarks<textarea name="review_remarks" maxlength="1000"></textarea></label>
<?php if($extended):?><label><input type="checkbox" name="allow_extended_hours" value="1"> Explicitly authorize extended hours for approval</label><label>Extended-hours audit remarks<textarea name="extended_hours_remarks" maxlength="120"></textarea></label><?php endif;?>
<button name="action" value="approve" class="admin-btn primary-btn">Approve Reschedule</button><button name="action" value="reject" class="admin-btn">Reject Reschedule</button></form>
<?php else:?><p>Reviewer: <?php echo htmlspecialchars((string)$attempt['reviewer_name']);?> | Processed: <?php echo htmlspecialchars((string)$attempt['processed_at']);?></p><p>Review remarks: <?php echo htmlspecialchars((string)$attempt['review_remarks']);?></p><?php endif;?>
<a href="view_request.php?request_id=<?php echo (int)$attempt['request_id'];?>">Request details and verified letter</a></section><?php endforeach;?></main></div><?php require dirname(__DIR__,3).'/includes/footer.php';?>
