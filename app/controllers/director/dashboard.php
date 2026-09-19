<?php
require_once dirname(__DIR__, 3) . "/includes/auth_check.php";
require_once dirname(__DIR__, 3) . "/includes/role_helper.php";
require_once dirname(__DIR__, 3) . "/config/db.php";
require_once dirname(__DIR__, 3) . "/includes/request_helper.php";
require_once dirname(__DIR__, 3) . "/includes/director_approval_helper.php";
// Role-exact gate first: the Admin wildcard permission must not reach the
// Director workspace.
ensureAuthenticatedRole("Director");
ensureApprovedRoleAccount($pdo, "Director");
ensureAuthenticatedPermission("director.dashboard");
ensureApprovedPermissionAccount($pdo, "director.dashboard");
$pending=$pdo->query("SELECT rr.*,r.resource_name,r.resource_type,r.location,b.full_name AS borrower_name,b.account_type,b.department FROM resource_requests rr JOIN ".gsoRequestResourceRollupSql()." r ON r.request_id=rr.request_id JOIN users b ON b.user_id=rr.borrower_id WHERE rr.director_state='Pending' AND rr.status IN ('Pending','Under Review') AND rr.adviser_state IN ('Not Required','Endorsed') ORDER BY rr.director_required_at,rr.request_id")->fetchAll(PDO::FETCH_ASSOC)?:[];
foreach($pending as $i=>$row)$pending[$i]["conflict_count"]=count(gsoFacilityConflictsForRequest($pdo,$row));
$stmt=$pdo->prepare("SELECT h.*,rr.status,r.resource_name,b.full_name AS borrower_name FROM request_director_history h JOIN resource_requests rr ON rr.request_id=h.request_id JOIN ".gsoRequestResourceRollupSql()." r ON r.request_id=rr.request_id JOIN users b ON b.user_id=rr.borrower_id WHERE h.director_user_id=:id ORDER BY h.decided_at DESC,h.director_history_id DESC LIMIT 50");$stmt->execute([":id"=>(int)$_SESSION["user_id"]]);$history=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
$pageTitle="Campus Director Decisions";$activePage="director-dashboard";$cssFile="../public/assets/css/pages/admin.css";$showGlobalBackButton=false;require_once dirname(__DIR__,3)."/includes/header.php";
?>
<div class="main-content"><main class="page-content">
<section class="card"><h1>Campus Director Decisions</h1><p>Review only requests explicitly routed by the GSO Head. Conflict information supports your decision; it never chooses a winner.</p></section>
<section class="card"><h2>Pending Director Decisions</h2><div class="table-wrapper"><table class="request-table"><thead><tr><th>Request</th><th>Borrower</th><th>Resource</th><th>Schedule</th><th>Case</th><th></th></tr></thead><tbody>
<?php if(!$pending):?><tr><td colspan="6" class="empty-note">No requests currently require a Director decision.</td></tr><?php endif;?>
<?php foreach($pending as $row):?><tr><td><?php echo htmlspecialchars(formatRequestCode((int)$row['request_id']));?></td><td><strong><?php echo htmlspecialchars((string)$row['borrower_name']);?></strong><br><small><?php echo htmlspecialchars((string)($row['account_type']??'Unclassified'));?> · <?php echo htmlspecialchars((string)($row['department']??'N/A'));?></small></td><td><?php echo htmlspecialchars((string)$row['resource_name']);?><br><small><?php echo htmlspecialchars((string)$row['resource_type']);?></small></td><td><?php echo htmlspecialchars(formatDateTimeDisplay($row['date_needed']));?><br><small><?php echo htmlspecialchars(buildTimeRangeDisplay($row['start_time'],$row['end_time']));?></small></td><td><?php if((int)$row['conflict_count']>0):?><span class="status-badge pending">Facility conflict · <?php echo (int)$row['conflict_count'];?> competing</span><?php else:?>Standard review<?php endif;?></td><td><a class="admin-btn primary-btn" href="request.php?request_id=<?php echo (int)$row['request_id'];?>">Review</a></td></tr><?php endforeach;?>
</tbody></table></div></section>
<section class="card"><h2>Decision History</h2><div class="table-wrapper"><table class="request-table"><thead><tr><th>Request</th><th>Borrower</th><th>Resource</th><th>Decision</th><th>Recorded</th></tr></thead><tbody><?php if(!$history):?><tr><td colspan="5" class="empty-note">No Director decisions have been recorded.</td></tr><?php endif;?><?php foreach($history as $event):?><tr><td><a href="request.php?request_id=<?php echo (int)$event['request_id'];?>"><?php echo htmlspecialchars(formatRequestCode((int)$event['request_id']));?></a></td><td><?php echo htmlspecialchars((string)$event['borrower_name']);?></td><td><?php echo htmlspecialchars((string)$event['resource_name']);?></td><td><?php echo htmlspecialchars((string)$event['action']);?></td><td><?php echo htmlspecialchars(formatDateTimeDisplay($event['decided_at']));?></td></tr><?php endforeach;?></tbody></table></div></section>
<a class="admin-btn" href="../auth/logout.php">Sign out</a></main></div>
<?php require_once dirname(__DIR__,3)."/includes/footer.php";?>
