<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/c2a_http_semantics.php';
$dir=trim(file_get_contents(__DIR__.'/latest-phase-c2a-evidence.txt'));
function vRead(string $path):array{return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
function vAssert(bool $ok,string $why):void{if(!$ok)throw new RuntimeException('C2A evidence review: '.$why);}
function vAdded(array $before,array $after,string $key):array{$ids=array_column($before,$key);return array_values(array_filter($after,fn($r)=>!in_array($r[$key],$ids,true)));}
function vStamp(int $us):string{return gmdate('Y-m-d\TH:i:s',intdiv($us,1000000)).sprintf('.%06dZ',$us%1000000);}
$records=vRead($dir.'/c2a_results.private.json');$complete=vRead($dir.'/c2a_completion.json');$meta=vRead($dir.'/run_metadata.json');$manifest=vRead($dir.'/manifest.private.json');
vAssert($complete['complete']&&count($records)===64,'64 completed pairs');$groups=[];$gaps=[];$statuses=[];$rows=[];$reviews=[];$cancelAfterStart=0;$cancelBeforeStart=0;$decisions=[];$mailCount=0;
$csv=fopen(__DIR__.'/phase_c2a_timings.csv','w');fputcsv($csv,['race','contender','synthetic_request_ids','request','server_start_utc','server_completion_utc','http_status','duration_us','pair_start_gap_us','overlap']);
foreach($records as $record){
    $label=$record['label'];$group=preg_replace('/_[0-9]{2}$/','',$label);$groups[$group]=($groups[$group]??0)+1;vAssert($record['passed']&&$record['server_intervals_overlap'],$label.' pass/overlap');
    $before=vRead($dir.'/'.$label.'_before.private.json');$after=vRead($dir.'/'.$label.'_after.private.json');$responses=vRead($dir.'/'.$label.'_responses.private.json');$audit=vRead($dir.'/integrity_'.$label.'.json');$logs=vRead($dir.'/logs_'.$label.'.private.json');$b=$before['tables'];$a=$after['tables'];
    foreach([$before,$after] as $snapshot)foreach($snapshot['tables'] as $table=>$data)vAssert(hash('sha256',json_encode($data))===$snapshot['hashes'][$table],$label.' snapshot '.$table);
    vAssert($audit['passed']&&array_sum($audit['violations'])===0&&$audit['foreign_keys_checked']===57,$label.' all invariants');
    vAssert(trim($logs['php_error.log'])===''&&trim($logs['mariadb_delta'])===''&&!preg_match('/\[[^\]]*:(warn|error|crit|alert|emerg)\]/i',$logs['apache_error.log']),$label.' logs');
    foreach($responses as $r){vAssert(c2aExpectedContenderResponse($r,$label),$label.' HTTP outcome');vAssert(hash('sha256',$r['body'])===$r['body_sha256'],$label.' original response body hash');$statuses[$r['status']]=($statuses[$r['status']]??0)+1;}
    $activity=vAdded($b['activity'],$a['activity'],'log_id');$notifications=vAdded($b['notifications'],$a['notifications'],'notification_id');$mail=array_diff_key($after['outbox_hashes'],$before['outbox_hashes']);$mailCount+=count($mail);
    foreach($mail as $name=>$hash)vAssert(hash_file('sha256',$dir.'/mail_outbox/'.$name)===$hash,$label.' captured message hash');
    $actionCount=fn(string $name)=>count(array_filter($activity,fn($r)=>$r['action']===$name));$clientNotifications=array_filter($notifications,fn($r)=>(int)$r['user_id']===(int)$manifest['users'][0]['user_id']);
    $outcome=$record['outcome'];
    if(str_starts_with($group,'damaged_')||str_starts_with($group,'lost_')||str_starts_with($group,'mixed_')){
        $resource=$a['resources'][0];$initial=$b['resources'][0];$lost=0;$damaged=0;$good=0;
        foreach($a['return_details'] as $detail){vAssert((int)$detail['stock_applied']===1&&$detail['inspected_by']!==null,$label.' detail settled once');if($detail['inspection_condition']==='Lost')$lost+=(int)$detail['lost_quantity'];elseif($detail['inspection_condition']==='Damaged')$damaged+=(int)$detail['returned_quantity'];elseif($detail['inspection_condition']==='Good')$good+=(int)$detail['returned_quantity'];else vAssert(false,$label.' unexpected outcome');}
        vAssert((int)$resource['total_stock']===(int)$initial['total_stock']-$lost&&(int)$resource['available_stock']===(int)$initial['available_stock']+$good&&(int)$resource['under_repair_stock']===(int)$initial['under_repair_stock']+$damaged,$label.' exact split arithmetic');
        vAssert($a['requests'][0]['status']==='Returned'&&count($a['return_headers'])===1&&$a['return_headers'][0]['status']==='Approved'&&$a['maintenance']===[]&&$a['liabilities']===[],$label.' one return, no automatic work order/accountability');
        vAssert($actionCount('Return Approved')===1&&$actionCount('Client Event Recorded')===1&&$actionCount('Client Email Attempt')===1&&$actionCount('Client Email Sent')===1&&count($clientNotifications)===1&&count($mail)===1,$label.' exactly-once return effects');
        if(str_ends_with($group,'serialized')){$counts=array_count_values(array_column($a['units'],'status'));vAssert(($counts['Lost']??0)===$lost&&($counts['Under Repair']??0)===$damaged&&($counts['Available']??0)===(int)$resource['available_stock'],$label.' exact affected units only');foreach($a['allocations'] as $allocation)vAssert($allocation['returned_at']!==null,$label.' allocation closed');}
    }elseif(in_array($group,['proposal','reschedule_collision','reschedule_decision'],true)){
        vAssert($actionCount('Client Event Recorded')===1&&$actionCount('Client Email Attempt')===1&&$actionCount('Client Email Sent')===1&&count($clientNotifications)===1&&count($mail)===1,$label.' exactly one proposal/decision client effect');
        if($group==='proposal'){vAssert($b['requests']===$a['requests']&&$b['items']===$a['items']&&count($a['reschedules'])===1&&$a['reschedules'][0]['status']==='Pending',$label.' original booking remains authoritative');vAssert($actionCount('Facility Reschedule Requested')===1,$label.' one proposal audit');vAssert(count($notifications)===4,$label.' one client plus three operational notifications');}
        elseif($group==='reschedule_collision'){
            vAssert(count(array_filter($a['reschedules'],fn($r)=>$r['status']==='Approved'))===1&&count(array_filter($a['reschedules'],fn($r)=>$r['status']==='Pending'))===1,$label.' one target wins');
            foreach($a['reschedules'] as $history){$parent=array_values(array_filter($a['requests'],fn($r)=>$r['request_id']===$history['request_id']))[0];vAssert($parent['date_needed']===($history['status']==='Approved'?$history['new_date_needed']:$history['old_date_needed']),$label.' winner/loser booking matches history');}
            vAssert($actionCount('Facility Reschedule Approved')===1,$label.' one approval audit');
        }else{$h=$a['reschedules'][0];$decisions[$h['status']]=($decisions[$h['status']]??0)+1;vAssert(in_array($h['status'],['Approved','Rejected'],true)&&$h['processed_at']!==null&&$h['reviewed_by']!==null,$label.' one terminal decision');vAssert($actionCount('Facility Reschedule Approved')+$actionCount('Facility Reschedule Rejected')===1,$label.' no contradictory decision audit');vAssert($a['requests'][0]['date_needed']===($h['status']==='Approved'?$h['new_date_needed']:$h['old_date_needed']),$label.' schedule follows winning decision');}
    }elseif(str_starts_with($group,'complete_')){
        $w=$a['maintenance'][0];$r=$a['resources'][0];vAssert($b['maintenance'][0]['status']==='In Progress'&&$w['status']==='Completed'&&$w['completed_at']!==null&&$w['restored_at']!==null,$label.' explicit completion');vAssert((int)$r['available_stock']===(int)$r['total_stock']&&(int)$r['under_repair_stock']===0,$label.' exactly one restoration');
        vAssert((int)$w['created_by']===(int)$manifest['users'][12]['user_id']&&$w['source_return_item_id']!==null,$label.' Head-confirmed repair source');vAssert($actionCount('Maintenance Completed')===1,$label.' single completion history');vAssert(count($mail)===0&&count($notifications)===0,$label.' no unimplemented/duplicate completion messaging');
        if($group==='complete_serialized')foreach($a['units'] as $unit)vAssert($unit['status']==='Available'&&$unit['condition_status']==='Good',$label.' repaired unit and unaffected unit available');
    }elseif($group==='maintenance_start'){
        $w=$a['maintenance'][0];$r=$a['resources'][0];vAssert($w['status']==='In Progress'&&$w['started_at']!==null&&$w['restored_at']===null&&$w['completed_at']===null,$label.' one start state');vAssert($actionCount('Preventive Maintenance Start')===1,$label.' one start history');vAssert((int)$r['available_stock']===(int)$b['resources'][0]['available_stock']-(int)$w['quantity']&&(int)$r['under_repair_stock']===(int)$w['quantity'],$label.' held once');vAssert(count($mail)===0&&count($notifications)===0,$label.' no duplicate messaging');
    }elseif($group==='maintenance_conflict'){
        $w=$a['maintenance'][0];$r=$a['resources'][0];vAssert($w['status']==='Cancelled'&&$w['completed_at']===null&&(int)$r['available_stock']===(int)$r['total_stock']&&(int)$r['under_repair_stock']===0,$label.' cancellation leaves valid restored stock');vAssert($actionCount('Preventive Maintenance Cancel')===1,$label.' one cancel history');
        if($w['started_at']===null){$cancelBeforeStart++;vAssert($w['restored_at']===null&&$actionCount('Preventive Maintenance Start')===0,$label.' cancelled before hold');}else{$cancelAfterStart++;vAssert($w['restored_at']!==null&&$actionCount('Preventive Maintenance Start')===1,$label.' ordered start then cancel releases one hold');}
        vAssert(count($mail)===0&&count($notifications)===0,$label.' no duplicate messaging');
    }else{
        vAssert(count($a['maintenance'])===1&&$a['maintenance'][0]['status']==='Scheduled'&&$a['maintenance'][0]['started_at']===null&&$a['maintenance'][0]['completed_at']===null&&$b['resources']===$a['resources']&&$b['units']===$a['units'],$label.' one scheduled occurrence, no inventory action');vAssert($actionCount('Preventive Occurrence Generated')===1&&count($notifications)===1&&count($mail)===0,$label.' idempotent generation effects');
    }
    foreach($record['server_timings'] as $tag=>$timing)fputcsv($csv,[$label,$tag,implode(';',$record['request_ids']),$timing['request'],vStamp($timing['start_us']),vStamp($timing['end_us']),$timing['status'],$timing['duration_us'],$record['server_start_gap_us'],'true']);
    $gaps[]=$record['server_start_gap_us'];$rows[]='| '.$label.' | '.($record['request_ids']?implode(', ',$record['request_ids']):'resource/work order'). ' | '.implode('/',$record['http_statuses']).' | '.$record['server_start_gap_us'].' | '.str_replace('|','/',json_encode($outcome,JSON_UNESCAPED_SLASHES)).' | PASS |';$reviews[$label]=['passed'=>true,'outcome'=>$outcome];
}
fclose($csv);$expected=['damaged_aggregate'=>5,'damaged_serialized'=>5,'lost_aggregate'=>5,'lost_serialized'=>5,'mixed_aggregate'=>3,'mixed_serialized'=>3,'proposal'=>5,'reschedule_collision'=>5,'reschedule_decision'=>5,'maintenance_start'=>5,'complete_aggregate'=>5,'complete_serialized'=>5,'maintenance_conflict'=>5,'preventive_generate'=>3];vAssert($groups===$expected,'expected repetitions');
$last=vRead($dir.'/integrity_preventive_generate_03.json');$min=min($gaps);$max=max($gaps);$http=json_encode($statuses);$decisionText=json_encode($decisions);$table=implode("\n",$rows);$folder=basename($dir);
file_put_contents($dir.'/c2a_evidence_review.json',json_encode(['passed'=>true,'pairs'=>64,'contenders'=>128,'groups'=>$groups,'statuses'=>$statuses,'start_gap_us'=>[$min,$max],'cancel_before_start'=>$cancelBeforeStart,'cancel_after_start'=>$cancelAfterStart,'reschedule_decisions'=>$decisions,'race_mail_captures'=>$mailCount,'reviews'=>$reviews],JSON_PRETTY_PRINT));
$text=<<<MD
# Phase C2A return, reschedule and maintenance concurrency results

**PHASE C2A DATA INTEGRITY: PASS. PHASE C2A FUNCTIONAL CONCURRENCY: PASS for the tested scenarios.** All applicable requested categories passed. No production defect was detected. These correctness tests do not establish general race safety or performance capacity. No 20-user spike, consolidated capacity report, deployment, push or Phase C2B was performed.

## Checkpoint, scope and isolation

Discovered Phase C1 checkpoint: `$meta[checkpoint]` (`Add critical transaction race-condition tests`). Initial working tree was clean. That commit changed only scripts/load/; production files matched `e2cbea932ed4073a814361c455c4f3086fd768a9`. The Phase A, B and C1 reports remain unchanged.

Fresh isolated database: **$meta[database]**, imported from the frozen schema with entirely synthetic records. No previous phase database or normal/live database was used. The proven guarded runtime served a git archive of the exact production baseline on **127.0.0.1:8123**, excluding local .env/mail.local.php secrets. Its DB user has DML access only to this database. Private sessions, letters, mail outbox and evidence are outside the isolated public root. Mail/socket PHP functions were disabled and verified, with the existing loopback/nonproduction local outbox fallback capturing mail. No real SMTP or model-provider calls occurred. No production code, schema, ERD, authorization or transaction helper was modified or bypassed.

Runtime: Windows/XAMPP; Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32. Fixture users/resources/Pending headers were synthetic setup records; every tested request approval, release, Return submission/inspection, reschedule, preventive plan/occurrence and corrective work-order transition used the real authenticated application POST path. No tested settled state was forced by direct SQL. Work orders were never inserted or marked completed by the test harness.

## Synchronization and evidence

Five independent sessions were established: GSO Head, GSO Staff, a second session of the GSO Head for management-only competing operations, and two sessions of the same Borrower for competing proposals. Each used its own cookie jar and real CSRF token. Queued cURL multi transfers were prepared before a shared 50 ms release barrier; competing POSTs were not sent sequentially. Apache begin/end timestamps correlated each contender with its response and fixture.

All **64 pairs / 128 contender requests overlapped in server execution**. Recorded start gaps: **$min to $max microseconds**. Windows clock resolution means a zero gap is indistinguishable at the recorded resolution, not exact physical simultaneity. No barrier was injected into application transactions. Sanitized start/completion timestamps, paths and statuses are in `phase_c2a_timings.csv`; raw responses, row snapshots, hashes and logs remain private.

Independent evidence review rechecked every original response body hash, all relevant before/after table hashes, local captured-message hashes, all per-race integrity gates, and exact notification/event/inventory outcomes. HTTP status counts: **$http**. Safe borrower proposal rejections may render HTTP 200 with the documented eligibility message; GSO conflict responses redirect with a safe flash. HTTP 302 alone is not treated as proof of a successful business action. No HTTP 500 or transport failure occurred.

## Final classifications

| Classification | Result | Iterations and exact semantics |
|---|---|---|
| DAMAGED RETURN CONCURRENCY | PASS | 5 aggregate + 5 serialized pairs. Returned once. Aggregate stock 5: available 3, repair-held 2. Serialized stock 2: one affected unit Under Repair and one unaffected Available. No automatic liability or work order. |
| LOST RETURN CONCURRENCY | PASS | 5 aggregate + 5 serialized pairs. Aggregate total 5-to-3 once, available stays 3. Serialized total 2-to-1 once, affected unit Lost and unaffected unit Available. Zero repair work orders or repair-source links to Lost outcomes. |
| MIXED RETURN CONCURRENCY | PASS | 3 aggregate + 3 serialized pairs, with one Good, one Damaged and one Lost. Aggregate final total 4, available 3, repair-held 1. Serialized final total 3, available 2, repair-held 1, one Lost unit; Good and unaffected units remain Available. No double arithmetic. |
| RESCHEDULE PROPOSAL CONCURRENCY | PASS | 5 pairs. One active Pending proposal/history entry, one submission event/email, original approved parent/line schedule unchanged. Proposal tables are not reservation inputs. |
| RESCHEDULE APPROVAL COLLISION | PASS | 5 pairs. Two legitimate approved original bookings with Pending proposals for the same target interval. One proposal Approved and authoritative; loser Pending with original booking retained; zero confirmed conflicts. |
| RESCHEDULE APPROVE-vs-REJECT | PASS | 5 pairs. One terminal decision, processed reviewer metadata, one terminal audit/event/email; parent schedule follows that decision. Observed decisions: $decisionText. |
| MAINTENANCE START CONCURRENCY | PASS | 5 pairs across aggregate/serialized preventive occurrences. One explicit Scheduled-to-In Progress transition, one start audit and one quantity/unit hold. No implicit start. |
| MAINTENANCE COMPLETE CONCURRENCY | PASS | 5 aggregate + 5 serialized corrective pairs. Actual Damaged-return work orders were Head-confirmed and assigned, after a Staff-only creation attempt was safely denied. Completion/restoration and history occurred once. |
| MAINTENANCE CONFLICTING TRANSITION | PASS | 5 supported preventive START-vs-CANCEL pairs. Final Cancelled, no impossible completion, stock fully available, one cancellation audit. Cancel won before Start in $cancelBeforeStart; Start legitimately serialized before Cancel in $cancelAfterStart. Both ordered actions are valid in the latter case; the hold was restored once. |
| PREVENTIVE MAINTENANCE CONCURRENCY | PASS | 3 duplicate-generation pairs for the same active plan/due date. Both callers may idempotently observe success, but only one Scheduled occurrence/audit/assignment notification exists; no inventory change. Start/conflicting-transition tests additionally exercised occurrence holds. |
| PHASE C2A DATA INTEGRITY | PASS | All 64 post-pair gates and the initial gate: zero violations. |
| PHASE C2A FUNCTIONAL CONCURRENCY | PASS | Exact expected winner/loser or valid serialized/idempotent semantics verified, without production changes. |

## Business rules and integrity

Inspection itself creates no repair work order or accountability review automatically. Damaged quantities remain held until explicit GSO Head work-order confirmation and authorized completion. During corrective setup, Staff attempts to create the work order were denied with no row created; Head approval created exactly one In Progress repair order linked to the inspected repairable detail. Lost details had no maintenance links at all. Good quantities and unaffected units remained available. No completed order was auto-created to satisfy a fixture.

Every pair was followed immediately by all **57 FK orphan checks**, negative-inventory and stock arithmetic checks, aggregate repair ledger reconciliation, serialized status/allocation checks, confirmed facility overlap detection, Claim Slip/request/status/return settlement checks, duplicate client events/notifications/delivery attempts, liability uniqueness, Lost-not-repairable and matching repair-source checks, active reschedule/history validity, preventive occurrence uniqueness, and maintenance lifecycle metadata checks. All invalid counts were zero.

Frozen schema before and after testing and at every gate: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns; version 20261010_008**. Every table-definition fingerprint matched the fresh seed; advancing AUTO_INCREMENT position is data state, not structural change.

Maintenance was never completed by changing a fixture date. New preventive occurrences remained Scheduled until an explicit Start, active jobs remained active until an explicit permitted terminal action, and completed corrective jobs had an explicit completion action, one completion audit and completed_at/restored_at. Frozen syncMaintenanceSchedules was also inspected: it does not complete work on elapsed dates and does not auto-activate preventive occurrences. **No real-time midnight/end-date rollover was simulated**, so these results do not claim a separate clock-boundary test. The real plan form prohibits creating a new plan with a past due date; that rule was preserved.

## Logs and defects

Application/PHP, isolated Apache, and MariaDB error-log deltas were reviewed after every pair. They were clean: no application fatal, uncaught Throwable, HTTP 500, SQLSTATE, duplicate-key failure, rollback failure, connection failure, deadlock, lock-wait timeout, impossible-state exception or memory exhaustion. Expected guarded conflict rejections were not classified as defects. No C2A fixture/harness interruption or production repair occurred. Existing C1 harness lessons were retained in test-only setup/response handling. Corrective maintenance has no client mail/notification on completion in this implementation; review verified zero unexpected or duplicate completion messages, rather than inventing that behavior.

## Per-pair state and HTTP outcomes

Full timestamps for every contender are in phase_c2a_timings.csv. Every row below has overlapping execution, a successful integrity gate and clean request logs. Detailed work-order/plan/resource/return/proposal rows and their hashes are retained in private snapshots, including for maintenance-only fixtures with no request parent.

| Pair | Synthetic request IDs | HTTP A/B | Start gap us | Final verified state | Integrity/logs |
|---|---|---|---:|---|---|
$table

## Assets, private evidence and commit

Added under scripts/load/: c2a_runtime.php, c2a_http_semantics.php, c2a_integrity.php, c2a_races.php, c2a_report.php, run_phase_c2a.ps1, phase_c2a_timings.csv and PHASE_C2A_RESULTS.md. Updated only shared common.php for the exact C2A database-name guard and .gitignore for the private locator/sanitized timing CSV. C1 files and all earlier reports remain unchanged.

Private evidence: the user's TEMP folder `$folder`, located only by ignored latest-phase-c2a-evidence.txt. It contains the frozen archive/hash, private runtime manifest/grants, before/after relevant rows/hashes, each contender's response/start/end, outbox messages, 64 integrity/log snapshots, console output, completion record and c2a_evidence_review.json. Credentials, session/Claim Slip values and raw mail are not committed. The reviewed timing CSV contains only synthetic identifiers, paths, timestamps and HTTP statuses.

The requested test/documentation commit is `Add return reschedule and maintenance race tests`; its hash and final git status are reported in the execution response. No push, 20-user spike, consolidated report, deployment, schema/ERD change or Phase C2B work was performed.
MD;
file_put_contents(__DIR__.'/PHASE_C2A_RESULTS.md',$text."\n");
echo json_encode(['review'=>'PASS','pairs'=>64,'groups'=>$groups,'statuses'=>$statuses,'start_gap_us'=>[$min,$max],'cancel_before_start'=>$cancelBeforeStart,'cancel_after_start'=>$cancelAfterStart,'reschedule_decisions'=>$decisions,'captured_race_emails'=>$mailCount,'schema'=>$last['schema']],JSON_PRETTY_PRINT),PHP_EOL;
