<?php
define('GSO_REQUIRED_PERMISSION','walk_in.create');
require_once dirname(__DIR__,3).'/includes/admin_check.php';
require_once dirname(__DIR__,3).'/config/db.php';
require_once dirname(__DIR__,3).'/includes/walkin_helper.php';
if(empty($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{if(!hash_equals((string)$_SESSION['csrf_token'],(string)($_POST['csrf_token']??'')))throw new DomainException('Invalid request token.');$id=gsoCreateWalkin($pdo,(int)$_SESSION['user_id'],$_POST,$_FILES['request_letter']??[]);redirectWithFlash('view_request.php?request_id='.$id,'Walk-in request created. Approval and release remain separate actions.','success');}
    catch(Throwable $e){$error=gsoSafeApplicationError($e, "app/controllers/admin/create_walkin.php", 'The walk-in request could not be saved. Refresh and retry.');}
}
$resources=$pdo->query("SELECT resource_id,resource_name,resource_type,tracking_mode FROM resources WHERE is_archived=0 ORDER BY resource_type,resource_name")->fetchAll(PDO::FETCH_ASSOC);
$pageTitle='Create Walk-in';$cssFile='../public/assets/css/pages/admin.css';require dirname(__DIR__,3).'/includes/header.php';require dirname(__DIR__,3).'/includes/admin_sidebar.php';
?>
<div class="main-content"><?php require_once dirname(__DIR__,3).'/includes/admin_topbar.php';?><main class="page-content"><section class="card"><h1>Create Walk-in</h1></section>
<section class="card"><p>Encode an external client's request. The client receives no system account. Every request retains its own identity snapshot.</p>
<?php if($error):?><p role="alert"><?php echo htmlspecialchars($error);?></p><?php endif;?>
<form class="stack-form" method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']);?>">
<?php foreach(['walkin_full_name'=>['Full name',150],'walkin_organization'=>['Organization / Office / Group',200],'walkin_email'=>['Email (optional)',150],'walkin_contact_number'=>['Contact number',30]] as $key=>[$label,$max]):?><label><?php echo $label;?><input name="<?php echo $key;?>" type="<?php echo $key==='walkin_email'?'email':'text';?>" maxlength="<?php echo $max;?>" value="<?php echo htmlspecialchars((string)($_POST[$key]??''));?>" <?php echo $key==='walkin_full_name'?'required':'';?>></label><?php endforeach;?>
<fieldset><legend>Resources</legend><p>Choose at most one Facility and any needed Equipment or Consumables. Leave unused rows blank.</p><div id="walkin-lines">
<?php $submitted=array_values((array)($_POST['lines']??[]));if(!$submitted)$submitted=[['resource_id'=>'','quantity'=>1]];foreach($submitted as $i=>$line):?><div class="walkin-line"><label>Resource<select name="lines[<?php echo (int)$i;?>][resource_id]"><option value="">Select resource</option><?php foreach($resources as $r):?><option value="<?php echo (int)$r['resource_id'];?>" <?php echo (int)($line['resource_id']??0)===(int)$r['resource_id']?'selected':'';?>><?php echo htmlspecialchars($r['resource_name'].' / '.$r['resource_type'].' / '.$r['tracking_mode']);?></option><?php endforeach;?></select></label><label>Quantity<input type="number" min="1" name="lines[<?php echo (int)$i;?>][quantity]" value="<?php echo max(1,(int)($line['quantity']??1));?>"></label><button type="button" onclick="this.parentElement.remove()">Remove</button></div><?php endforeach;?></div><button type="button" id="add-walkin-line">Add resource</button></fieldset>
<label>Date<input type="date" name="date_needed" min="<?php echo date('Y-m-d');?>" required value="<?php echo htmlspecialchars((string)($_POST['date_needed']??''));?>"></label>
<label>Facility period<select name="facility_slot"><option value="">No Facility</option><?php foreach(['AM','PM'] as $period):?><option <?php echo ($_POST['facility_slot']??'')===$period?'selected':'';?>><?php echo $period;?></option><?php endforeach;?></select></label>
<?php foreach(['start_time'=>'Exact start time','end_time'=>'Exact end time'] as $key=>$label):?><label><?php echo $label;?><input type="time" name="<?php echo $key;?>" value="<?php echo htmlspecialchars((string)($_POST[$key]??''));?>"></label><?php endforeach;?>
<p>Facility schedules require exact times and a matching AM/PM classification. Normal campus hours: <?php $hours=gsoFacilityOperatingHours($pdo);echo htmlspecialchars(buildTimeRangeDisplay($hours['start'],$hours['end']));?>.</p>
<label><input type="checkbox" name="request_extended_hours" value="1" <?php echo ($_POST['request_extended_hours']??'')==='1'?'checked':'';?>> Request extended-hours review; approval requires explicit GSO authorization and audit remarks</label>
<label>Manually signed Facility request letter (required when selecting a Facility)<input type="file" name="request_letter" accept=".pdf,.jpg,.jpeg,.png"></label><label>Notes<textarea name="notes" maxlength="255"><?php echo htmlspecialchars((string)($_POST['notes']??''));?></textarea></label><button>Create Pending Request</button></form></section></main></div>
<script>const lines=document.getElementById('walkin-lines');const template=lines.firstElementChild.cloneNode(true);let nextLine=<?php echo count($submitted);?>;document.getElementById('add-walkin-line').addEventListener('click',()=>{const row=template.cloneNode(true);row.querySelectorAll('[name]').forEach(el=>{el.name=el.name.replace(/lines\[\d+\]/,'lines['+nextLine+']');el.value=el.type==='number'?'1':'';});nextLine++;lines.appendChild(row);});</script>
<?php require dirname(__DIR__,3).'/includes/footer.php';?>
