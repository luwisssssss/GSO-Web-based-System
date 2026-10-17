# Phase A results - 2026-10-17

Completed only the single-user baseline and five-user workload. No production defect was detected within this read-only HTTP workload. No higher-user or dedicated race testing was performed.

## Baseline and isolation

Initial git status was clean. HEAD was and remains e2cbea932ed4073a814361c455c4f3086fd768a9. k6 is v2.2.0 (commit 00a9a1b7f5, Go 1.26.5, windows/amd64). No tracked application code or schema file was modified, and no commit, push or deployment was made.

Database: **gso_load_test_20261017_102501**. Imported the frozen database/schema.sql into this newly created database. Verified before and after both workloads: 22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns, version 20261010_008. A database-scoped runtime account and loopback Apache on port 8120 isolated HTTP traffic from the normal application database. SMTP functions were disabled in a private PHP startup configuration and verified before application traffic. No real SMTP email was sent. The isolated Apache process tree was stopped after completion; test database and private evidence are retained.

Fixture: 12 Borrowers, GSO Head plus two GSO Staff, one Director, one MIS; three facilities, three aggregate equipment resources, two consumable resources and two serialized equipment resources (24 units); 96 requests with pending, reviewed, approved, released, completed, rejected and cancelled examples. All identities and records are synthetic.

## Machine

Acer Nitro ANV15-41; AMD Ryzen 5 6600H, 6 cores / 12 logical processors; 16 GB installed RAM (15.31 GiB visible); KINGSTON OM8SEP4512Q-AA 512 GB disk; Windows 11 Home Single Language 10.0.26300, build 26300. Apache 2.4.58 Win64, PHP 8.2.12, MariaDB 10.4.32-MariaDB. Load generator and server share this machine.

## Metrics

Baseline configured for 90 seconds with 1 VU; five-user run configured for 120 seconds with 5 VUs. Each allows 15 seconds for an active journey to finish. Actual k6 elapsed time including graceful completion: baseline 92.608 seconds; five-user 130.511 seconds. Request rate uses this actual duration. All latency values below are milliseconds and include login HTTP requests.

| Run | Requests | req/s | Avg | Median | p90 | p95 | p99 | Max | HTTP 500 | Unintended errors |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| baseline | 33 | 0.356 | 83.12 | 87.16 | 122.05 | 127.62 | 130.28 | 130.60 | 0 | 0 |
| five | 156 | 1.195 | 70.08 | 67.98 | 112.05 | 119.62 | 132.14 | 137.00 | 0 | 0 |

Both runs covered all four role types. Baseline rotated five independent accounts sequentially with one active VU. Five VUs used two Borrowers, one GSO Head, one Director and one MIS with independent sessions and CSRF login. Navigation covered Browse, My Requests, actual facility availability, GSO dashboard/request/active-borrowing reads, Director schedules and MIS account reads. Think time: 2 to 4 seconds between reads, 2 to 5 seconds between journeys. HTTP failures, HTTP 5xx, and failed status/content checks were zero in both completed workloads.

## Integrity and logs

All before/baseline/five integrity gates passed. Zero stock arithmetic or issuance errors; zero orphan rows across all 57 FKs; zero duplicate/open allocation inconsistencies; zero confirmed facility conflicts; zero Claim Slip format/status/uniqueness violations; zero request quantity/actor/status violations. All business table content and table definitions matched seed hashes. Request counts/status distribution remained unchanged. Only expected Login Success activity was added: 10 total events after both workloads, with zero failed logins or unexpected application events.

No PHP log entries, Apache request errors/warnings, or new MariaDB error-log entries were found. Apache access logs contain only expected HTTP 200 pages and HTTP 302 login redirects, plus the successful control health check. MariaDB's existing shared error log was inspected from pre-run byte offset 605752; global logging settings were unchanged.

## Evidence and limits

Private evidence: the user's TEMP directory, folder GSO_PHASE_A_20261017_102501. The ignored latest-evidence.txt records the local location.

Includes machine.json, seed_summary.json, seed_snapshot.json, integrity_before/baseline/five.json, baseline/five_summary.json, baseline/five_samples.json, coverage.json, workload console output, isolated Apache access/error logs, MariaDB log delta and private runtime manifest/health/configuration. Credentials remain outside the repository. See README.md for rerunning the guarded harness.

Two discarded setup attempts are retained separately: gso_load_test_20261017_102314 (SMTP guard blocked all application load), and gso_load_test_20261017_102407 (three HTTP requests before a harness assertion falsely matched a dormant JavaScript error string). Neither was an application defect, and neither is included in these completed-run metrics. The harness was corrected before this fresh run; production code was unchanged.

This is an HTTP/backend read workload against a small synthetic fixture, with normal login writes. It does not exercise browser JavaScript/assets, business mutation contention, races, higher concurrency, a remote deployment or large production datasets. These results do not establish capacity for later phases.

## Generated files and Git

scripts/load/.gitignore, README.md, common.php, prepare.php, integrity.php, phase_a.js, run_phase_a.ps1, report.ps1 and RESULTS.md. The ignored latest-evidence.txt is a local locator only. Final git status --short:

    ?? scripts/load/

HEAD remains the required baseline; the only working-tree addition is the requested test-assets directory.
