<section class="card"><h3>Reschedule History</h3>
<p>Pending proposals do not reserve their proposed interval. The current approved reservation stays active until GSO accepts a proposal.</p>
<div class="table-wrapper"><table class="request-table"><thead><tr><th>Attempt / Created</th><th>Original schedule</th><th>Proposed schedule</th><th>Client reason</th><th>Decision / Reviewer</th><th>Review remarks / Processed</th></tr></thead><tbody>
<?php if(!$rescheduleHistory):?><tr><td colspan="6">No reschedule attempts.</td></tr><?php endif;?>
<?php foreach($rescheduleHistory as $attempt):?><tr><td><?php echo (int)$attempt['reschedule_id'];?><br><?php echo htmlspecialchars($attempt['created_at']);?></td>
<td><?php echo htmlspecialchars(gsoRescheduleSchedule($attempt,'old'));?></td><td><?php echo htmlspecialchars(gsoRescheduleSchedule($attempt,'new'));?></td>
<td><?php echo htmlspecialchars($attempt['client_reason']);?></td><td><?php echo htmlspecialchars($attempt['status'].' | '.($attempt['reviewer_name']??''));?></td>
<td><?php echo htmlspecialchars((string)$attempt['review_remarks'].' | '.(string)$attempt['processed_at']);?></td></tr><?php endforeach;?></tbody></table></div></section>
