<?php
require_once dirname(__DIR__,3).'/includes/borrower_check.php';
require_once dirname(__DIR__,3).'/config/db.php';
require_once dirname(__DIR__,3).'/includes/reschedule_helper.php';
$requestId=(int)($_GET['request_id']??0);$userId=(int)$_SESSION['user_id'];
$s=$pdo->prepare('SELECT r.* FROM resource_requests r WHERE r.request_id=? AND r.borrower_id=?');$s->execute([$requestId,$userId]);$request=$s->fetch(PDO::FETCH_ASSOC);
if(!$request||!gsoRequestHasFacility($pdo,$requestId)){http_response_code(404);exit('Facility reservation not found.');}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??'')))throw new DomainException('Invalid request token.');
        $action=(string)($_POST['action']??'');
        if($action==='cancel'){gsoCancelReschedule($pdo,$userId,$requestId,(int)($_POST['reschedule_id']??0));$message='Your pending proposal was cancelled. The original reservation is unchanged.';}
        elseif($action==='create'){$result=gsoCreateReschedule($pdo,$userId,$requestId,$_POST);$message='Reschedule proposal submitted for GSO review. The original reservation remains active. '.($result['availability_error']!==null?'Current availability: '.$result['availability_error']:'The proposed interval is currently available; this does not guarantee approval.');}
        else throw new DomainException('Invalid reschedule action.');
        redirectWithFlash('reschedule.php?request_id='.$requestId,$message,'success');
    }catch(Throwable $e){$error=$e instanceof DomainException?$e->getMessage():'The reschedule action could not be saved. Refresh and try again.';}
}
$pending=gsoPendingReschedule($pdo,$requestId);$eligible=gsoRescheduleEligible($pdo,$request);$facility=gsoRequestFacilityLine($pdo,$requestId);$request['facility_slot']=$facility['facility_slot'];$request['resource_type']='Facility';$rescheduleHistory=gsoRescheduleHistory($pdo,$requestId);$hours=gsoFacilityOperatingHours($pdo);
$pageTitle='Facility Reschedule';$cssFile='../public/assets/css/pages/borrower.css';require dirname(__DIR__,3).'/includes/header.php';require dirname(__DIR__,3).'/includes/borrower_sidebar.php';
?>
<div class="main-content"><main class="page-content"><section class="card"><h1>Request Reschedule — <?php echo htmlspecialchars(formatRequestCode($requestId));?></h1>
<p>Current reservation record (<?php echo htmlspecialchars($request['status']);?>): <?php echo htmlspecialchars(formatDateTimeDisplay($request['date_needed']).' | '.gsoRequestScheduleDisplay($request));?></p>
<p>Your original reservation remains authoritative until GSO approves a proposal. Resource release and return remain separate actions.</p>
<?php if($error):?><p role="alert"><?php echo htmlspecialchars($error);?></p><?php endif;?>
<?php if(!empty($_SESSION['flash_message'])):?><p role="status"><?php echo htmlspecialchars((string)$_SESSION['flash_message']);unset($_SESSION['flash_message'],$_SESSION['flash_type']);?></p><?php endif;?>
<?php if($pending):?><p>Proposal #<?php echo (int)$pending['reschedule_id'];?> is pending GSO review. The proposed interval is not reserved.</p><form method="post"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>"><input type="hidden" name="reschedule_id" value="<?php echo (int)$pending['reschedule_id'];?>"><button name="action" value="cancel" class="request-btn">Cancel Pending Reschedule</button></form><?php endif;?>
<?php if($eligible):?><form method="post" class="request-form"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>"><input type="hidden" name="action" value="create">
<label>New date<input type="date" name="date_needed" min="<?php echo date('Y-m-d');?>" value="<?php echo htmlspecialchars((string)($_POST['date_needed']??''));?>" required></label>
<label>Period<select name="facility_slot" required><option value="">Select AM or PM</option><?php foreach(['AM','PM'] as $period):?><option value="<?php echo $period;?>" <?php echo ($_POST['facility_slot']??'')===$period?'selected':'';?>><?php echo $period;?></option><?php endforeach;?></select></label>
<label>Exact start time<input type="time" name="start_time" value="<?php echo htmlspecialchars((string)($_POST['start_time']??''));?>" required></label><label>Exact end time<input type="time" name="end_time" value="<?php echo htmlspecialchars((string)($_POST['end_time']??''));?>" required></label>
<p>AM starts before noon; PM starts at or after noon. Normal campus hours: <?php echo htmlspecialchars(buildTimeRangeDisplay($hours['start'],$hours['end']));?>.</p>
<label><input type="checkbox" name="request_extended_hours" value="1" <?php echo ($_POST['request_extended_hours']??'')==='1'?'checked':'';?>> Request explicit GSO review for extended hours</label>
<label>Client reason<textarea name="reason" maxlength="1000" required><?php echo htmlspecialchars((string)($_POST['reason']??''));?></textarea></label>
<button type="submit" class="request-btn">Submit Proposal / Check Current Availability</button></form><?php elseif(!$pending):?><p>Rescheduling is available only for an Approved facility reservation that has not started.</p><?php endif;?>
<a href="request_letter.php?request_id=<?php echo $requestId;?>">Existing facility request letter</a> | <a href="my_requests.php">Back to My Requests</a></section>
<?php require dirname(__DIR__,2).'/views/partials/reschedule_history.php';?></main></div><?php require dirname(__DIR__,3).'/includes/footer.php';?>
