<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__,2);
$dir=trim(file_get_contents(__DIR__.'/latest-phase-b-evidence.txt'));
function bRead(string $file):array {return json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}
function bPercentile(array $values,float $p):float {sort($values,SORT_NUMERIC);$i=(count($values)-1)*$p;$lo=(int)floor($i);$hi=(int)ceil($i);return $values[$lo]+($values[$hi]-$values[$lo])*($i-$lo);}
$meta=bRead($dir.'/run_metadata.json');$seed=bRead($dir.'/seed_summary.json');$health=bRead($dir.'/server_health.private.json');
$report=[];$table=[];$endpointRows=[];$telemetry=[];
foreach(file($dir.'/telemetry.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line)$telemetry[]=json_decode($line,true,512,JSON_THROW_ON_ERROR);
foreach(['ten'=>10,'fifteen'=>15] as $mode=>$vus){
    $summary=bRead($dir.'/'.$mode.'_summary.json');$integrity=bRead($dir.'/integrity_'.$mode.'.json');$metrics=$summary['metrics'];
    $group=[];$roles=[];$statuses=[];$times=[];
    $handle=fopen($dir.'/'.$mode.'_samples.json','r');
    while(($line=fgets($handle))!==false){$point=json_decode($line,true);if(($point['type']??'')!=='Point')continue;$data=$point['data'];$times[]=strtotime($data['time']);
        if($point['metric']==='http_req_duration'){$group[$data['tags']['name']][]=$data['value'];$roles[$data['tags']['role']]=true;$statuses[$data['tags']['status']]=($statuses[$data['tags']['status']]??0)+1;}}
    fclose($handle);
    $from=min($times);$to=max($times);$monitor=array_values(array_filter($telemetry,fn($r)=>strtotime($r['timestamp'])>=$from&&strtotime($r['timestamp'])<=$to));
    $cpu=array_column($monitor,'cpu_percent');$free=array_column($monitor,'available_ram_bytes');$busy=array_column($monitor,'BusyWorkers');
    $connections=[];$running=[];$locks=[];$lockWaits=[];$lockTime=[];
    foreach($monitor as $row){$db=$row['database'];$connections[]=$db['Threads_connected'];$running[]=$db['Threads_running'];$locks[]=$db['Innodb_row_lock_current_waits'];$lockWaits[]=$db['Innodb_row_lock_waits'];$lockTime[]=$db['Innodb_row_lock_time'];}
    $endpoints=[];foreach($group as $name=>$values)$endpoints[$name]=['requests'=>count($values),'min'=>min($values),'avg'=>array_sum($values)/count($values),'p95'=>bPercentile($values,.95),'max'=>max($values)];
    uasort($endpoints,fn($a,$b)=>$b['p95']<=>$a['p95']);
    $runtime=$summary['state']['testRunDurationMs']/1000;$latency=$metrics['http_req_duration']['values'];
    $errors=$metrics['unintended_errors']['values']['count'];$http500=$metrics['http_500']['values']['count'];
    // A Rate's passes count its true samples: true means request failed here.
    $failed=$metrics['http_req_failed']['values']['passes'];$requests=$metrics['http_reqs']['values']['count'];
    $logDelta=trim(file_get_contents($dir.'/mariadb_'.$mode.'_log_delta.txt'));
    $pass=$integrity['passed']&&$http500===0&&$errors/$requests<.01&&$failed===0&&$metrics['checks']['values']['fails']===0&&$logDelta==='';
    if($metrics['vus_max']['values']['max']!==$vus)throw new RuntimeException('Unexpected VU count.');
    $report[$mode]=['vus'=>$vus,'duration_seconds'=>$runtime,'requests'=>$requests,'request_rate'=>$metrics['http_reqs']['values']['rate'],'latency_ms'=>$latency,'http_failures'=>$failed,'http_500'=>$http500,'unintended_errors'=>$errors,'passed'=>$pass,'performance_degradation'=>$latency['p(95)']>2000,'roles'=>array_keys($roles),'statuses'=>$statuses,'endpoints'=>$endpoints,'integrity'=>$integrity,'mariadb_log_empty'=>$logDelta==='','telemetry'=>['samples'=>count($monitor),'cpu_avg'=>array_sum($cpu)/count($cpu),'cpu_max'=>max($cpu),'minimum_free_ram_gib'=>min($free)/1073741824,'apache_busy_max'=>max($busy),'db_connections_max'=>max($connections),'db_running_max'=>max($running),'current_lock_waits_max'=>max($locks),'lock_wait_count_delta'=>max($lockWaits)-min($lockWaits),'lock_wait_time_ms_delta'=>max($lockTime)-min($lockTime)]];
    $table[]=sprintf('| %d | %.3f | %d | %.3f | %.2f | %.2f | %.2f | %.2f | %.2f | %.2f | %.2f | %d | %d | %d |',$vus,$runtime,$requests,$metrics['http_reqs']['values']['rate'],$latency['min'],$latency['avg'],$latency['med'],$latency['p(90)'],$latency['p(95)'],$latency['p(99)'],$latency['max'],$failed,$http500,$errors);
    foreach($endpoints as $name=>$e)$endpointRows[]=sprintf('| %d | %s | %d | %.2f | %.2f | %.2f |',$vus,$name,$e['requests'],$e['avg'],$e['p95'],$e['max']);
}
$phpLog=is_file($dir.'/php_error.log')?trim(file_get_contents($dir.'/php_error.log')):'';
$apache=file_get_contents($dir.'/apache_error.log');$apacheBad=preg_match('/\[(?:php\d?:error|[^\]]*:(?:warn|error|crit|alert|emerg))\]/i',$apache);
$monitorError=is_file($dir.'/monitor_errors.log')?trim(file_get_contents($dir.'/monitor_errors.log')):'';
if($phpLog!==''||$apacheBad||$monitorError!=='')throw new RuntimeException('Logs require manual review before a clean report.');
$report['checkpoint']=$meta['checkpoint'];$report['database']=$seed['database'];
file_put_contents($dir.'/phase_b_report.json',json_encode($report,JSON_PRETTY_PRINT));
$rows=implode("\n",$table);$endpointText=implode("\n",$endpointRows);$folder=basename($dir);
$t=$report['ten']['telemetry'];$f=$report['fifteen']['telemetry'];$tenPass=$report['ten']['passed']?'PASS':'FAIL';$fifteenPass=$report['fifteen']['passed']?'PASS':'FAIL';
$integrityPass=$report['ten']['integrity']['passed']&&$report['fifteen']['integrity']['passed']?'PASS':'FAIL';
$telemetryText='';foreach(['ten'=>10,'fifteen'=>15] as $mode=>$vus){$v=$report[$mode]['telemetry'];$telemetryText.=sprintf("| %d | %d | %.2f | %.0f | %.2f | %d | %d | %d | %d |\n",$vus,$v['samples'],$v['cpu_avg'],$v['cpu_max'],$v['minimum_free_ram_gib'],$v['apache_busy_max'],$v['db_connections_max'],$v['current_lock_waits_max'],$v['lock_wait_count_delta']);}
$text=<<<MD
# Phase B results - 2026-10-17

10-USER LOAD: **$tenPass**. 15-USER LOAD: **$fifteenPass**. PHASE B DATA INTEGRITY: **$integrityPass**.

## Checkpoint and environment

Phase A test/documentation-only commit: `$meta[checkpoint]` (`Add Phase A concurrent load-test harness`). Review found generated random fixture passwords only, no real users, SMTP secrets or embedded runtime credentials; the absolute private evidence location was removed from Phase A documentation before committing. The ignored evidence locators and all private runtime manifests were excluded. HEAD remains this checkpoint. Production files match baseline `e2cbea932ed4073a814361c455c4f3086fd768a9`. No production code/schema edits, second commit, push or deployment were performed.

Fresh database: **$seed[database]**, created from database/schema.sql with the reused Phase A synthetic fixture (12 Borrowers, 3 operational users, Director, MIS; 3 facilities, 3 aggregate equipment resources, 2 consumables, 2 serialized resources/24 units, and 96 controlled requests). It did not copy legitimate user records or connect the load server to a normal application database. A new runtime user has DML permissions only on this database.

Acer Nitro ANV15-41; AMD Ryzen 5 6600H (6 cores/12 logical processors); 16 GB installed RAM (15.31 GiB visible); KINGSTON 512 GB disk; Windows 11 Home Single Language 10.0.26300 build 26300. Local k6 2.2.0 and Apache 2.4.58/PHP $health[php]/MariaDB $health[mariadb] share the machine. Separate Apache listened only on 127.0.0.1:8121, with private PHP configuration, sessions, uploads and evidence. Health verified the exact database/runtime identity and disabled SMTP functions. No real SMTP email or model-provider calls were sent. The owned monitor/server processes were stopped after the tests; database and evidence were retained.

## Workload and metrics

10 users ran for a configured 300 seconds, followed by the full integrity/log gate. Only after that gate passed did 15 users run for a configured 600 seconds. Actual times below include graceful completion (up to 30 seconds). The mixes were 6 Borrowers + GSO Head + GSO Staff + Director + MIS, and 10 Borrowers + GSO Head + 2 GSO Staff + Director + MIS. Every VU used a distinct account, CSRF login, and independent cookie jar. Think time was 2 to 4 seconds between actions and 2 to 5 seconds between journeys; initial logins were staggered by up to 3 seconds.

Journeys covered Browse, My Requests, facility availability, a filtered owned request/status, deterministic chatbot owned-status and resource-availability answers, operational dashboards, request queues, active borrowing, Create Walk-in, maintenance and maintenance history, Director schedules, and MIS account reads. Login/chat history were the only intended writes; no business mutation race requests were made. All page content, role redirects and deterministic chatbot source/answer checks passed. Metrics are k6 HTTP request durations in milliseconds, including logins and chat calls; monitor/control requests are excluded.

| VUs | Actual seconds | Requests | req/s | Min | Avg | Median | p90 | p95 | p99 | Max | HTTP failures | HTTP 500 | Unintended |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
$rows

Both runs stayed below the preferred p95 <= 2000 ms. HTTP status/content/JSON checks had zero failures, and no crashes or PHP fatals were observed. No performance degradation was classified solely from an isolated maximum.

## Integrity and logs

Before load, immediately after 10 users, and immediately after 15 users: all integrity checks passed with zero violations. Checks included stock/issuance arithmetic, orphan rows across all 57 FKs, duplicate/open allocations and serialized quantities, confirmed facility conflicts, Claim Slip uniqueness/format/status, request quantities/actors/statuses and approved facility letters. Request/status distributions and all business table content remained identical to seed hashes. Only expected Login Success activity and chatbot conversation/message rows changed. Chat owners were Borrowers; all stored user messages had matching assistant replies. There were 25 successful login events across the two workloads, no failed logins and no unexpected application events.

Frozen schema verified at every gate: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns; version 20261010_008**. Every table-definition fingerprint matched the seed (AUTO_INCREMENT counter excluded as data state). No schema file was changed.

10-user and 15-user log gates: no PHP/application errors or warnings, Apache request errors/warnings, or new MariaDB log entries. Reviewed for Fatal error, uncaught Throwable, SQLSTATE, deadlock, lock wait timeout, connection exhaustion and memory exhaustion. Apache workload responses were only expected 200 pages/JSON and 302 login redirects. Login Success events were the only activity-log additions. Existing MariaDB global logging settings were unchanged.

## Resource observations

| VUs | Samples | CPU avg % | CPU max % | Min free RAM GiB | Apache busy max | DB connected max | Current lock waits max | Lock-wait count delta |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
$telemetryText

CPU/free RAM are host-wide; Apache process-memory samples include other local Apache processes. BusyWorkers/IdleWorkers are from the isolated Apache, and may include keep-alive connections and the status request. MariaDB counters are server-wide and include the telemetry connection and other local clients. Missing deadlock counters are not interpreted as zero; no deadlocks appeared in logs and sampled row-lock counters did not increase. Five-second sampling cannot exclude short peaks.

No saturated resource or definitive system bottleneck was established. The endpoint table below identifies the slowest observed request groups. Low request rates are intentionally limited by think time and this small synthetic fixture; they are not maximum throughput. The lower/higher overall averages between runs also reflect request mix, scheduling and caching and are not proof of scaling efficiency.

| VUs | Endpoint group | Requests | Avg ms | p95 ms | Max ms |
|---|---|---:|---:|---:|---:|
$endpointText

## Artifacts and scope

Phase A RESULTS.md and evidence remain preserved. Phase B private evidence is in the user's TEMP folder `$folder`; its absolute local location is recorded only by ignored latest-phase-b-evidence.txt. Evidence includes machine.json, seed summary/table hashes, manifest/health/configuration (private credentials), integrity_before/ten/fifteen.json, ten/fifteen_summary.json and samples, execution durations, Apache access/error logs, PHP log if any, MariaDB gate deltas, telemetry.jsonl, database status snapshots and phase_b_report.json. Do not commit private manifests or evidence.

Phase B assets: PHASE_B.md, phase_b.js, run_phase_b.ps1, phase_b_manifest.php, telemetry.php, monitor.ps1, phase_b_report.php, and this PHASE_B_RESULTS.md. Shared common.php/integrity.php/.gitignore changes are confined to the test directory. No production files changed.

These are HTTP/backend tests with synthetic data, normal authentication and chat-history writes. Browser JavaScript/assets, production-size datasets, remote deployments, dedicated mutation races and the 20-user spike were not tested. **Dedicated race-condition safety is not established.**

Final git status is recorded in the execution response; all post-checkpoint changes are under scripts/load/ and remain uncommitted for review.
MD;
file_put_contents(__DIR__.'/PHASE_B_RESULTS.md',$text."\n");
echo json_encode(['ten'=>$report['ten']['passed'],'fifteen'=>$report['fifteen']['passed'],'database'=>$seed['database'],'telemetry_ten'=>$t,'telemetry_fifteen'=>$f],JSON_PRETTY_PRINT),PHP_EOL;
