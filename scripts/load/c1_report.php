<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/c1_http_semantics.php';
$dir=trim(file_get_contents(__DIR__.'/latest-phase-c1-evidence.txt'));
function cRead(string $path):array{return json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
function cCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException('Evidence review failed: '.$message);}
function cAdded(array $before,array $after,string $key):array{$known=array_column($before,$key);return array_values(array_filter($after,fn($r)=>!in_array($r[$key],$known,true)));}
function cStamp(int $us):string{return gmdate('Y-m-d\TH:i:s',intdiv($us,1000000)).sprintf('.%06dZ',$us%1000000);}
$results=cRead($dir.'/c1_results.private.json');$complete=cRead($dir.'/c1_completion.json');$meta=cRead($dir.'/run_metadata.json');$manifest=cRead($dir.'/manifest.private.json');
cCheck($complete['complete']&&count($results)===40,'40 completed races required');
$groups=[];$rows=[];$allGaps=[];$httpCounts=[];$mailCount=0;$review=[];
$csv=fopen(__DIR__.'/phase_c1_timings.csv','w');fputcsv($csv,['race','contender','request_ids','request','server_start_utc','server_completion_utc','http_status','server_duration_us','start_gap_us','server_intervals_overlap']);
foreach($results as $record){
    $label=$record['label'];$group=preg_replace('/_[0-9]{2}$/','',$label);$groups[$group]=($groups[$group]??0)+1;
    cCheck($record['passed']&&$record['server_intervals_overlap'],$label.' must pass with overlapping server intervals');
    $before=cRead($dir.'/'.$label.'_before.private.json');$after=cRead($dir.'/'.$label.'_after.private.json');$responses=cRead($dir.'/'.$label.'_responses.private.json');$audit=cRead($dir.'/integrity_'.$label.'.json');$logs=cRead($dir.'/logs_'.$label.'.private.json');
    foreach([$before,$after] as $snapshot)foreach($snapshot['tables'] as $table=>$data)cCheck(hash('sha256',json_encode($data))===$snapshot['hashes'][$table],$label.' original snapshot hash: '.$table);
    cCheck($audit['passed']&&array_sum($audit['violations'])===0&&$audit['foreign_keys_checked']===57,$label.' integrity');
    cCheck(trim($logs['php_error.log'])===''&&trim($logs['mariadb_delta'])==='',$label.' PHP/MariaDB logs');
    $apache=preg_replace('/^.*AH00098: pid file .*Unclean shutdown of previous Apache run\?.*\R?/m','',$logs['apache_error.log']);
    cCheck(!preg_match('/\[[^\]]*:(warn|error|crit|alert|emerg)\]/i',$apache),$label.' Apache request log');
    foreach($responses as $response){cCheck(c1ExpectedContenderResponse($response,$label),$label.' contender HTTP');cCheck(hash('sha256',$response['body'])===$response['body_sha256'],$label.' body hash');$httpCounts[$response['status']]=($httpCounts[$response['status']]??0)+1;}
    $b=$before['tables'];$a=$after['tables'];$newActivity=cAdded($b['activity'],$a['activity'],'log_id');$newNotifications=cAdded($b['notifications'],$a['notifications'],'notification_id');$newMail=array_diff_key($after['outbox_hashes'],$before['outbox_hashes']);
    $clientNotifications=array_values(array_filter($newNotifications,fn($r)=>(int)$r['user_id']===(int)$manifest['users'][0]['user_id']));
    $events=array_values(array_filter($newActivity,fn($r)=>$r['action']==='Client Event Recorded'));$attempts=array_filter($newActivity,fn($r)=>$r['action']==='Client Email Attempt');$sent=array_filter($newActivity,fn($r)=>$r['action']==='Client Email Sent');
    cCheck(count($events)===1&&count($attempts)===1&&count($sent)===1&&count($newMail)===1,$label.' exactly one client event, email attempt, sent record and captured message');
    foreach($newMail as $name=>$hash)cCheck(is_file($dir.'/mail_outbox/'.$name)&&hash_file('sha256',$dir.'/mail_outbox/'.$name)===$hash,$label.' original captured email hash');$mailCount++;
    $states=array_column($a['requests'],'status');$summary='';
    if($group==='facility'){
        cCheck(count(array_filter($states,fn($s)=>$s==='Approved'))===1&&count(array_filter($states,fn($s)=>in_array($s,['Pending','Under Review'],true)))===1,$label.' one approved, legitimate loser');
        foreach($a['requests'] as $r)if($r['status']!=='Approved')cCheck($r['claim_slip_code']===null&&$r['approved_by']===null&&$r['approved_at']===null,$label.' no partial losing approval');
        cCheck($b['resources']===$a['resources']&&count($newNotifications)===1,$label.' no inventory change and one approval notification');$summary='1 Approved; 1 Pending/Under Review; 0 confirmed conflicts';
    }elseif(in_array($group,['aggregate','serialized'],true)){
        $qty=$group==='aggregate'?4:1;$stock=$group==='aggregate'?5:1;
        cCheck(count(array_filter($states,fn($s)=>$s==='Released'))===1&&count(array_filter($states,fn($s)=>$s==='Approved'))===1,$label.' one release, one unchanged approval');
        cCheck(array_sum(array_column($a['items'],'released_quantity'))===$qty&&(int)$a['resources'][0]['available_stock']===$stock-$qty&&(int)$a['resources'][0]['total_stock']===$stock,$label.' exact stock arithmetic');
        foreach($a['requests'] as $r)if($r['status']==='Approved'){cCheck($r['released_by']===null&&$r['released_at']===null,$label.' no partial losing metadata');foreach($a['items'] as $line)if($line['request_id']===$r['request_id'])cCheck((int)$line['released_quantity']===0&&(int)$line['issued_quantity']===0,$label.' loser has no settlement');}
        if($group==='serialized')cCheck(count($a['allocations'])===1&&$a['allocations'][0]['returned_at']===null&&$a['units'][0]['status']==='Released',$label.' one active allocation');
        cCheck(count($clientNotifications)===1&&$clientNotifications[0]['title']==='Request Released',$label.' one client release notification');
        cCheck(count($newNotifications)===2&&count(array_filter($newNotifications,fn($n)=>$n['title']==='Low Stock Alert'))===1,$label.' one standard GSO low-stock alert');$summary=$group==='aggregate'?'4 of 5 issued; available 1; loser Approved':'1 active unit allocation; available 0; loser Approved';
    }elseif(in_array($group,['claim_double','claim_invalid'],true)){
        cCheck($states===['Released']&&count($a['allocations'])===1&&$a['units'][0]['status']==='Released',$label.' single mixed release');
        foreach($a['items'] as $line)cCheck((int)$line['released_quantity']===(int)$line['requested_quantity'],$label.' complete mixed lines');
        foreach($a['resources'] as $r){$expected=$r['tracking_mode']==='Serialized'?0:3;cCheck((int)$r['available_stock']===$expected,$label.' exactly one stock deduction');if($r['tracking_mode']==='Consumable')cCheck((int)$r['total_stock']===3,$label.' exactly one consumable deduction');}
        cCheck(count(array_filter($newActivity,fn($r)=>$r['action']==='Request Released'))===1&&count($clientNotifications)===1&&$clientNotifications[0]['title']==='Request Released',$label.' exactly one business release and client notification');
        cCheck(count($newNotifications)===2&&count(array_filter($newNotifications,fn($n)=>$n['title']==='Low Stock Alert'))===1,$label.' one expected low-stock alert for the serialized resource');
        if($group==='claim_invalid')cCheck((int)$a['requests'][0]['released_by']===(int)$manifest['users'][12]['user_id'],$label.' only valid contender A mutated business state');
        $summary='Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email';
    }elseif($group==='return_submit'){
        cCheck($states===['Return']&&$a['requests'][0]['return_date']===null&&$b['resources']===$a['resources']&&$b['items']===$a['items'],$label.' submission holds inventory');
        cCheck(count($a['return_headers'])===1&&$a['return_headers'][0]['status']==='Pending'&&count($a['return_details'])===1&&(int)$a['return_details'][0]['stock_applied']===0&&(int)$a['return_details'][0]['returned_quantity']===3,$label.' one complete pending return');
        cCheck(count(array_filter($newActivity,fn($r)=>$r['action']==='Return Submitted'))===1&&count($newNotifications)===2,$label.' one submission event, one borrower and one GSO Head notification');$summary='1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email';
    }else{
        $stock=$group==='inspect_aggregate'?5:1;$qty=$group==='inspect_aggregate'?3:1;
        cCheck($states===['Returned']&&$a['requests'][0]['return_date']!==null&&(int)$a['resources'][0]['available_stock']===$stock&&(int)$a['resources'][0]['under_repair_stock']===0,$label.' exact Good restoration');
        cCheck((int)$a['items'][0]['returned_quantity']===$qty&&count($a['return_headers'])===1&&$a['return_headers'][0]['status']==='Approved'&&count($a['return_details'])===1&&(int)$a['return_details'][0]['stock_applied']===1,$label.' return settled once');
        if($group==='inspect_serialized')cCheck($a['units'][0]['status']==='Available'&&count($a['allocations'])===1&&$a['allocations'][0]['returned_at']!==null,$label.' unit restored and allocation closed once');
        cCheck(count(array_filter($newActivity,fn($r)=>$r['action']==='Return Approved'))===1&&count($newNotifications)===1,$label.' one acceptance activity/notification');$summary='Returned once; stock '.$stock.'/'.$stock.'; Good quantity '.$qty.' applied once; 1 acceptance email';
    }
    foreach($record['server_timings'] as $tag=>$timing)fputcsv($csv,[$label,$tag,implode(';',$record['request_ids']),$timing['request'],cStamp($timing['start_us']),cStamp($timing['end_us']),$timing['status'],$timing['duration_us'],$record['server_start_gap_us'],'true']);
    $allGaps[]=$record['server_start_gap_us'];$rows[]='| '.$label.' | '.implode(', ',$record['request_ids']).' | '.implode('/',$record['http_statuses']).' | '.$record['server_start_gap_us'].' | '.$summary.' | PASS |';$review[$label]=['passed'=>true,'summary'=>$summary];
}
fclose($csv);foreach($groups as $group=>$count)cCheck($count===5,$group.' must have five iterations');cCheck(count($groups)===8,'eight scenario variants');
$last=cRead($dir.'/integrity_inspect_serialized_05.json');$gapMin=min($allGaps);$gapMax=max($allGaps);$table=implode("\n",$rows);$folder=basename($dir);$httpText=json_encode($httpCounts);$db=$meta['database'];$checkpoint=$meta['checkpoint'];
file_put_contents($dir.'/c1_evidence_review.json',json_encode(['passed'=>true,'groups'=>$groups,'races'=>40,'contenders'=>80,'http_status_counts'=>$httpCounts,'minimum_server_start_gap_us'=>$gapMin,'maximum_server_start_gap_us'=>$gapMax,'captured_client_emails_during_races'=>$mailCount,'race_reviews'=>$review],JSON_PRETTY_PRINT));
$doc=<<<MD
# Phase C1 critical transaction race-condition results

All seven requested categories PASS. PHASE C1 DATA INTEGRITY: **PASS**. PHASE C1 FUNCTIONAL CONCURRENCY: **PASS for the tested scenarios and repetitions**. No production concurrency defect was found. Phase C2, sustained load, a 20-user spike, deployment and push were not performed. These results make no performance-capacity claim or universal concurrency-safety claim.

## Preserved checkpoint and isolation

Phase B checkpoint: `$checkpoint` (`Add 10 and 15 user load-test verification`). Production baseline: `e2cbea932ed4073a814361c455c4f3086fd768a9`. Phase B was checkpointed with a clean working tree before C1. All subsequent changes were confined to scripts/load/. Phase A RESULTS.md and PHASE_B_RESULTS.md were preserved unchanged.

Existing isolated database: **$db**. The same database, frozen export and completed Facility results were preserved across every resume. The five Facility races were not repeated. Synthetic seed: 12 Borrowers, 3 operational accounts, Director, MIS, controlled resources, units and requests. Each race used fresh dedicated resources/requests; normal application approval, release and submission flows established the tested preconditions. No legitimate user data or normal/live database was used.

Apache listened only on 127.0.0.1:8122 and served an unmodified git archive of the frozen production baseline from private temporary storage. The archive excludes ignored local .env/mail.local.php files and real secrets. The runtime DB account has DML privileges only on the C1 database. Existing local, nonproduction mail fallback captured messages outside the web root, with invalid/empty SMTP configuration and mail/socket PHP functions disabled and verified at health checks. No SMTP or external model-provider calls occurred. No application mail helper, permission gate, transaction code or schema was replaced or bypassed. The owned test server was stopped after execution; database/evidence retained.

Windows/XAMPP environment: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32. This is correctness testing using PHP cURL, not a repeat of Phase B load measurement.

## Synchronization and recorded timing

Four independent authenticated cookie jars were used: GSO Head and GSO Staff for operational races, plus two separate sessions of the same synthetic Borrower for duplicate submission. Every POST used its own session's real CSRF token. No shared-session PHP lock serialized the two contenders.

Both cURL handles were configured and queued in curl_multi before a shared 50 ms release barrier. Transfers began together through the multi event loop, never through sequential curl_exec calls. Apache recorded each contender's request-start and completion timestamps, HTTP status and duration, correlated by X-GSO-C1-Contender. All **40 request pairs had overlapping server intervals**. Observed start gaps were **$gapMin to $gapMax microseconds**. Windows/Apache timestamps have finite resolution; a recorded zero means indistinguishable at that resolution, not proven exact physical simultaneity. This observes overlapping HTTP execution, not an instrumented barrier inside a database statement.

`phase_c1_timings.csv` contains all 80 contender start/completion timestamps in UTC, statuses, request paths/fixture IDs and pair timing gaps. Raw cURL start/completion times, response headers/bodies/body hashes, Apache logs and before/after relevant rows/table hashes remain in private evidence. Every race's original snapshot hashes and captured message hashes were independently rechecked when generating this report.

## Classification and expected outcomes

| Category | Iterations | Classification | Verified outcome |
|---|---:|---|---|
| Facility double approval | 5 preserved | PASS | Same facility/date/09:00-10:00 interval; both Pending with verified synthetic letters; exactly one Approved, legitimate losing state, zero confirmed overlaps or partial approval metadata. |
| Aggregate oversubscription | 5 | PASS | Stock 5, competing quantities 4+4; both normally Approved for separate future windows, then raced at physical release. Exactly one Released, stock 1, other request still Approved with no partial release. |
| Serialized unit collision | 5 | PASS | One available unit; two normally Approved requests explicitly selected that same unit. One active allocation, one Released request, stock 0; loser unchanged. |
| Claim Slip double release | 5 | PASS | Same Approved mixed request and valid code; aggregate 5-to-3, consumables 5-to-3, exactly one serialized allocation and business settlement, one release notification/event/email. |
| Valid vs invalid Claim Slip | 5 | PASS | Same mixed fixture with valid contender A versus wrong code B. released_by was the valid actor in every snapshot; exactly one settlement and notification/event/email, no raw code in application activity logs. |
| Duplicate Return submission | 5 | PASS | Same Released aggregate request, independent Borrower sessions. One Pending header/detail, request Return, available stock unchanged at 2/5, no inspection stock applied; one submission event/email and one notification to each intended recipient. |
| Duplicate Return inspection | 10: 5 aggregate + 5 serialized | PASS | Pending Good-return acceptance raced by two GSO sessions. Request Returned once, quantity restored once; aggregate available 5/5, or unit Available with its sole allocation closed. One acceptance activity/notification/event/email; no maintenance/liability side effects. |

All 80 contender HTTP results: **$httpText** (status-to-count). HTTP 302 includes success and safe rejection redirects; final DB snapshots distinguish winner and loser. Duplicate-return losers may instead render HTTP 200 with the explicit safe message 'This request is no longer eligible for return submission.' No HTTP 500 or transport failure occurred. In each pair, exactly one client event, delivery attempt, successful local capture and captured message were added. Low-stock release cases also correctly generated one separate GSO Low Stock Alert; this is distinct from the single client release notification and was verified, not classified as a duplicate. Approval emails legitimately contain the issued Claim Slip; application activity logs and public artifacts do not expose raw Claim Slips.

## Integrity and logs after every race

Every one of the 40 post-race gates passed, including all 57 FK orphan checks, aggregate/serialized/consumable stock arithmetic, negative inventory, over-allocation, open/pair allocation uniqueness and unit status, confirmed facility conflicts, Claim Slip format/status/uniqueness, request actors/quantities/statuses, return header/detail/settlement consistency, duplicate client notifications/events/delivery attempts, Good-return maintenance/liability exclusions, and raw-code activity-log leakage. All invalid counts were zero. Extra resume/review integrity gates also passed. The rejected fixture-preparation requests were retained in their legitimate Pending state; no successful business result was reset.

Frozen schema verified before races and at every integrity gate: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns; schema version 20261010_008**. Every table-definition fingerprint matched the seed, excluding AUTO_INCREMENT position as data state. No schema change occurred.

PHP/application and MariaDB logs were inspected after every pair, as were isolated Apache errors. **No application PHP fatal, uncaught exception/Throwable, SQLSTATE, duplicate-key failure, rollback error, connection failure, deadlock, lock-wait timeout or memory exhaustion was observed.** No business-request Apache error/warning was found. One known Apache AH00098 stale PID-file warning occurred on the first controlled restart after a harness stop; it was retained and explicitly distinguished from request failures. Subsequent resumes use unique PID files. All other Apache warnings/errors remain stop conditions.

## Harness interruptions, distinguished from product defects

1. After five successful Facility pairs, an aggregate fixture used today's past 09:00 start. Normal approval rejected it. This was a test fixture error before an aggregate race. Remaining fixtures were corrected to future windows; original requests/evidence were retained, with no production modification.
2. A controlled Apache restart emitted the documented AH00098 PID warning and blocked the conservative log gate before more races. Unique resume PID files prevent repeats; the original warning remains in evidence.
3. Execution was interrupted by automatic approval-review usage limits. The later approved resume preserved the same database/export and skipped all already passed labels.
4. The first duplicate-return pair safely produced HTTP 302 plus HTTP 200. A harness assumption requiring two redirects stopped progression. A separate read-only evidence/current-state review verified one complete submission, unchanged stock, one message and clean integrity; the original false harness classification was archived and the existing result reclassified without replaying it. Future return pairs accept only that exact expected conflict page or normal redirects.
5. A later session resume tried to find a CSRF input on a My Requests page whose current rows had no cancellation form. CSRF retrieval was corrected to the normal authenticated resource-request form. No token, authentication or permission check was bypassed.
6. The independent CLI report audit initially counted the normal GSO Low Stock Alert together with the client release notification. Its assertion was corrected to separately verify exactly one of each in low-stock cases. This did not change or replay any application transaction.

None of these interruptions was classified as a production race defect. Unpassed genuine race results block further execution. Completed scenario labels are skipped on resume, and existing databases/exports are never recreated by resume_phase_c1.ps1.

## Per-iteration results

Each row's full timestamps are in phase_c1_timings.csv. All rows include a successful integrity gate and reviewed logs.

| Race | Synthetic request IDs | HTTP A/B | Start gap us | Final state | Integrity / request logs |
|---|---|---|---:|---|---|
$table

## Files, evidence and commit

Added: c1_races.php, c1_integrity.php, c1_http_semantics.php, c1_review_return_result.php, c1_report.php, run_phase_c1.ps1, resume_phase_c1.ps1, phase_c1_timings.csv and PHASE_C1_RESULTS.md. Updated only scripts/load/common.php (C1 DB-name guard) and scripts/load/.gitignore (private evidence locator). These are test/documentation assets. No production files or prior reports changed.

Private evidence is retained in the user's TEMP folder `$folder`, located by ignored latest-phase-c1-evidence.txt. It contains exact relevant rows/hashes, claims/session information, outbox messages, raw responses, original/reviewed result records, per-race integrity/log snapshots, server configuration, frozen source archive/hash and execution consoles. None of that private evidence or credentials is included in the test/documentation commit. The sanitized timing CSV contains only synthetic identifiers, paths, timestamps and HTTP outcomes.

The requested final test/documentation commit is `Add critical transaction race-condition tests`; its hash and final git status are provided in the execution response. No push or Phase C2 was performed.
MD;
file_put_contents(__DIR__.'/PHASE_C1_RESULTS.md',$doc."\n");
echo json_encode(['evidence_review'=>'PASS','races'=>count($results),'groups'=>$groups,'http_statuses'=>$httpCounts,'start_gap_us'=>[$gapMin,$gapMax],'schema'=>$last['schema'],'captured_race_emails'=>$mailCount],JSON_PRETTY_PRINT),PHP_EOL;
