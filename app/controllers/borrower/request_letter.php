<?php
require_once dirname(__DIR__, 3) . '/includes/borrower_check.php';
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/includes/request_attachment_helper.php';
require_once dirname(__DIR__, 3) . '/includes/activity_log_helper.php';
$requestId=(int)($_GET['request_id']??0);$userId=(int)$_SESSION['user_id'];
$s=$pdo->prepare('SELECT request_id,status FROM resource_requests WHERE request_id=? AND borrower_id=?');$s->execute([$requestId,$userId]);$request=$s->fetch(PDO::FETCH_ASSOC);
if(!$request || !gsoRequestHasFacility($pdo,$requestId)){http_response_code(404);exit('Facility request not found.');}
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
$message='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $stored=null;
    try{
        if(!hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??'')))throw new DomainException('Invalid request token.');
        $validated=gsoValidateRequestLetter($_FILES['request_letter']??[]);
        $pdo->beginTransaction();
        $s=$pdo->prepare('SELECT status FROM resource_requests WHERE request_id=? AND borrower_id=? FOR UPDATE');$s->execute([$requestId,$userId]);$status=$s->fetchColumn();
        $current=gsoRequestLetters($pdo,$requestId)[0]??null;
        if(!in_array($status,['Pending','Under Review'],true) || ($current && $current['verification_status']!=='Rejected'))throw new DomainException('A replacement is allowed after document rejection, while the request is pending or under review.');
        $stored=gsoStoreRequestLetter($validated);gsoInsertRequestLetter($pdo,$requestId,$userId,$stored);
        addActivityLog($pdo,$userId,'Request Letter Uploaded','Uploaded a new facility request-letter version for request #'.$requestId);
        $pdo->commit();redirectWithFlash('request_letter.php?request_id='.$requestId,'Request letter uploaded for GSO verification.','success');
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($stored)@unlink($stored['path']);$message=$e instanceof DomainException?$e->getMessage():'The request letter could not be stored. Please try again.';}
}
$letters=gsoRequestLetters($pdo,$requestId);$current=$letters[0]??null;
$canReplace=in_array($request['status'],['Pending','Under Review'],true)&&(!$current||$current['verification_status']==='Rejected');
$pageTitle='Facility Request Letter';$cssFile='../public/assets/css/pages/borrower.css';
require dirname(__DIR__,3).'/includes/header.php';require dirname(__DIR__,3).'/includes/borrower_sidebar.php';
?>
<div class="main-content"><?php require_once dirname(__DIR__,3).'/includes/borrower_topbar.php';?><main class="page-content"><section class="card"><h1>Facility Request Letter</h1></section>
<section class="card">
<p>Obtain required manual signatures outside the system. GSO verifies the uploaded document for processing; verification does not electronically sign or approve your borrowing request.</p>
<?php if($message):?><p role="alert"><?php echo htmlspecialchars($message);?></p><?php endif;?>
<?php if(!empty($_SESSION['flash_message'])):?><p role="status"><?php echo htmlspecialchars((string)$_SESSION['flash_message']);unset($_SESSION['flash_message'],$_SESSION['flash_type']);?></p><?php endif;?>
<?php require dirname(__DIR__,2).'/views/partials/request_letters.php';?>
<?php if($canReplace):?><form class="stack-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>"><label>Signed facility request letter (PDF, JPG, PNG; up to <?php echo htmlspecialchars(gsoFormatBytesLabel(gsoEffectiveUploadLimitBytes(5*1024*1024)));?>)<input type="file" name="request_letter" accept=".pdf,.jpg,.jpeg,.png" required></label><button type="submit" class="request-btn">Upload for verification</button></form><?php endif;?>
<a href="my_requests.php">Back to My Requests</a></section></main></div>
<?php require dirname(__DIR__,3).'/includes/footer.php';?>
