param([string]$Evidence = (Get-Content -Raw scripts/load/latest-evidence.txt).Trim())
$ErrorActionPreference = 'Stop'
$modes = @('baseline','five')
$rows = @(); $coverage = @{}
foreach ($mode in $modes) {
    $s = Get-Content -Raw "$Evidence/$($mode)_summary.json" | ConvertFrom-Json
    $i = Get-Content -Raw "$Evidence/integrity_$mode.json" | ConvertFrom-Json
    if (!$i.passed -or $s.metrics.unintended_errors.values.count -ne 0) { throw 'Run did not pass.' }
    $v = $s.metrics.http_req_duration.values
    $rows += '| {0} | {1} | {2:N3} | {3:N2} | {4:N2} | {5:N2} | {6:N2} | {7:N2} | {8:N2} | {9} | {10} |' -f $mode,$s.metrics.http_reqs.values.count,$s.metrics.http_reqs.values.rate,$v.avg,$v.med,$v.'p(90)',$v.'p(95)',$v.'p(99)',$v.max,$s.metrics.http_500.values.count,$s.metrics.unintended_errors.values.count
    $points = Get-Content "$Evidence/$($mode)_samples.json" | ForEach-Object { $_ | ConvertFrom-Json } | Where-Object { $_.type -eq 'Point' -and $_.metric -eq 'http_reqs' }
    $coverage[$mode] = @($points | Group-Object { $_.data.tags.name } | Sort-Object Name | ForEach-Object { @{endpoint=$_.Name;requests=$_.Count} })
    $roles = @($points | ForEach-Object { $_.data.tags.role } | Sort-Object -Unique)
    if (($roles -join ',') -ne 'Admin,Borrower,Director,Super Admin') { throw "Missing role coverage: $mode" }
}
$coverage | ConvertTo-Json -Depth 6 | Set-Content "$Evidence/coverage.json"
$five = Get-Content -Raw "$Evidence/integrity_five.json" | ConvertFrom-Json
$seed = Get-Content -Raw "$Evidence/seed_summary.json" | ConvertFrom-Json
$health = Get-Content -Raw "$Evidence/server_health.private.json" | ConvertFrom-Json
$meta = Get-Content -Raw "$Evidence/run_metadata.json" | ConvertFrom-Json
$phpEntries = if (Test-Path "$Evidence/php_error.log") { @(Get-Content "$Evidence/php_error.log").Count } else { 0 }
$apacheErrors = @(Select-String -LiteralPath "$Evidence/apache_error.log" -Pattern '\[(?:php\d?:error|.*:warn|.*:error|.*:crit|.*:alert|.*:emerg)\]').Count
$mariaDelta = (Get-Content -Raw "$Evidence/mariadb_log_delta.txt").Trim()
if ($phpEntries -or $apacheErrors -or $mariaDelta.Length) { throw 'Log findings require manual review before reporting a pass.' }
$b = Get-Content -Raw "$Evidence/baseline_summary.json" | ConvertFrom-Json
$f = Get-Content -Raw "$Evidence/five_summary.json" | ConvertFrom-Json
$report = @"
# Phase A results - 2026-10-17

Completed only the single-user baseline and five-user workload. No production defect was detected within this read-only HTTP workload. No higher-user or dedicated race testing was performed.

## Baseline and isolation

Initial git status was clean. HEAD was and remains e2cbea932ed4073a814361c455c4f3086fd768a9. k6 is v2.2.0 (commit 00a9a1b7f5, Go 1.26.5, windows/amd64). No tracked application code or schema file was modified, and no commit, push or deployment was made.

Database: **$($seed.database)**. Imported the frozen database/schema.sql into this newly created database. Verified before and after both workloads: 22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns, version 20261010_008. A database-scoped runtime account and loopback Apache on port 8120 isolated HTTP traffic from the normal application database. SMTP functions were disabled in a private PHP startup configuration and verified before application traffic. No real SMTP email was sent. The isolated Apache process tree was stopped after completion; test database and private evidence are retained.

Fixture: 12 Borrowers, GSO Head plus two GSO Staff, one Director, one MIS; three facilities, three aggregate equipment resources, two consumable resources and two serialized equipment resources (24 units); 96 requests with pending, reviewed, approved, released, completed, rejected and cancelled examples. All identities and records are synthetic.

## Machine

Acer Nitro ANV15-41; AMD Ryzen 5 6600H, 6 cores / 12 logical processors; 16 GB installed RAM (15.31 GiB visible); KINGSTON OM8SEP4512Q-AA 512 GB disk; Windows 11 Home Single Language 10.0.26300, build 26300. Apache 2.4.58 Win64, PHP $($health.php), MariaDB $($health.mariadb). Load generator and server share this machine.

## Metrics

Baseline configured for 90 seconds with 1 VU; five-user run configured for 120 seconds with 5 VUs. Each allows 15 seconds for an active journey to finish. Actual k6 elapsed time including graceful completion: baseline $([math]::Round($b.state.testRunDurationMs/1000,3)) seconds; five-user $([math]::Round($f.state.testRunDurationMs/1000,3)) seconds. Request rate uses this actual duration. All latency values below are milliseconds and include login HTTP requests.

| Run | Requests | req/s | Avg | Median | p90 | p95 | p99 | Max | HTTP 500 | Unintended errors |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
$($rows -join "`n")

Both runs covered all four role types. Baseline rotated five independent accounts sequentially with one active VU. Five VUs used two Borrowers, one GSO Head, one Director and one MIS with independent sessions and CSRF login. Navigation covered Browse, My Requests, actual facility availability, GSO dashboard/request/active-borrowing reads, Director schedules and MIS account reads. Think time: 2 to 4 seconds between reads, 2 to 5 seconds between journeys. HTTP failures, HTTP 5xx, and failed status/content checks were zero in both completed workloads.

## Integrity and logs

All before/baseline/five integrity gates passed. Zero stock arithmetic or issuance errors; zero orphan rows across all 57 FKs; zero duplicate/open allocation inconsistencies; zero confirmed facility conflicts; zero Claim Slip format/status/uniqueness violations; zero request quantity/actor/status violations. All business table content and table definitions matched seed hashes. Request counts/status distribution remained unchanged. Only expected Login Success activity was added: $($five.activity_logs) total events after both workloads, with zero failed logins or unexpected application events.

No PHP log entries, Apache request errors/warnings, or new MariaDB error-log entries were found. Apache access logs contain only expected HTTP 200 pages and HTTP 302 login redirects, plus the successful control health check. MariaDB's existing shared error log was inspected from pre-run byte offset $($meta.mariadb_log_offset); global logging settings were unchanged.

## Evidence and limits

Private evidence: the user's TEMP directory, folder $(Split-Path -Leaf $Evidence). The ignored latest-evidence.txt records the local location.

Includes machine.json, seed_summary.json, seed_snapshot.json, integrity_before/baseline/five.json, baseline/five_summary.json, baseline/five_samples.json, coverage.json, workload console output, isolated Apache access/error logs, MariaDB log delta and private runtime manifest/health/configuration. Credentials remain outside the repository. See README.md for rerunning the guarded harness.

Two discarded setup attempts are retained separately: gso_load_test_20261017_102314 (SMTP guard blocked all application load), and gso_load_test_20261017_102407 (three HTTP requests before a harness assertion falsely matched a dormant JavaScript error string). Neither was an application defect, and neither is included in these completed-run metrics. The harness was corrected before this fresh run; production code was unchanged.

This is an HTTP/backend read workload against a small synthetic fixture, with normal login writes. It does not exercise browser JavaScript/assets, business mutation contention, races, higher concurrency, a remote deployment or large production datasets. These results do not establish capacity for later phases.

## Generated files and Git

scripts/load/.gitignore, README.md, common.php, prepare.php, integrity.php, phase_a.js, run_phase_a.ps1, report.ps1 and RESULTS.md. The ignored latest-evidence.txt is a local locator only. Final git status --short:

    ?? scripts/load/

HEAD remains the required baseline; the only working-tree addition is the requested test-assets directory.
"@
Set-Content -LiteralPath scripts/load/RESULTS.md -Value $report
Write-Output ($rows -join "`n")
Write-Output 'Report and role/endpoint coverage saved.'
