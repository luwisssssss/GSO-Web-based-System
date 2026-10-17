# Phase B results - 2026-10-17

10-USER LOAD: **PASS**. 15-USER LOAD: **PASS**. PHASE B DATA INTEGRITY: **PASS**.

## Checkpoint and environment

Phase A test/documentation-only commit: `92e0242077675a842420d1def52bff2e883b22f8` (`Add Phase A concurrent load-test harness`). Review found generated random fixture passwords only, no real users, SMTP secrets or embedded runtime credentials; the absolute private evidence location was removed from Phase A documentation before committing. The ignored evidence locators and all private runtime manifests were excluded. HEAD remains this checkpoint. Production files match baseline `e2cbea932ed4073a814361c455c4f3086fd768a9`. No production code/schema edits, second commit, push or deployment were performed.

Fresh database: **gso_load_test_phase_b_20261017_104116**, created from database/schema.sql with the reused Phase A synthetic fixture (12 Borrowers, 3 operational users, Director, MIS; 3 facilities, 3 aggregate equipment resources, 2 consumables, 2 serialized resources/24 units, and 96 controlled requests). It did not copy legitimate user records or connect the load server to a normal application database. A new runtime user has DML permissions only on this database.

Acer Nitro ANV15-41; AMD Ryzen 5 6600H (6 cores/12 logical processors); 16 GB installed RAM (15.31 GiB visible); KINGSTON 512 GB disk; Windows 11 Home Single Language 10.0.26300 build 26300. Local k6 2.2.0 and Apache 2.4.58/PHP 8.2.12/MariaDB 10.4.32-MariaDB share the machine. Separate Apache listened only on 127.0.0.1:8121, with private PHP configuration, sessions, uploads and evidence. Health verified the exact database/runtime identity and disabled SMTP functions. No real SMTP email or model-provider calls were sent. The owned monitor/server processes were stopped after the tests; database and evidence were retained.

## Workload and metrics

10 users ran for a configured 300 seconds, followed by the full integrity/log gate. Only after that gate passed did 15 users run for a configured 600 seconds. Actual times below include graceful completion (up to 30 seconds). The mixes were 6 Borrowers + GSO Head + GSO Staff + Director + MIS, and 10 Borrowers + GSO Head + 2 GSO Staff + Director + MIS. Every VU used a distinct account, CSRF login, and independent cookie jar. Think time was 2 to 4 seconds between actions and 2 to 5 seconds between journeys; initial logins were staggered by up to 3 seconds.

Journeys covered Browse, My Requests, facility availability, a filtered owned request/status, deterministic chatbot owned-status and resource-availability answers, operational dashboards, request queues, active borrowing, Create Walk-in, maintenance and maintenance history, Director schedules, and MIS account reads. Login/chat history were the only intended writes; no business mutation race requests were made. All page content, role redirects and deterministic chatbot source/answer checks passed. Metrics are k6 HTTP request durations in milliseconds, including logins and chat calls; monitor/control requests are excluded.

| VUs | Actual seconds | Requests | req/s | Min | Avg | Median | p90 | p95 | p99 | Max | HTTP failures | HTTP 500 | Unintended |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| 10 | 317.275 | 831 | 2.619 | 20.78 | 73.81 | 68.63 | 108.93 | 117.97 | 141.93 | 1291.98 | 0 | 0 | 0 |
| 15 | 618.023 | 2456 | 3.974 | 12.96 | 71.83 | 69.40 | 105.49 | 116.04 | 131.78 | 182.34 | 0 | 0 | 0 |

Both runs stayed below the preferred p95 <= 2000 ms. HTTP status/content/JSON checks had zero failures, and no crashes or PHP fatals were observed. No performance degradation was classified solely from an isolated maximum.

## Integrity and logs

Before load, immediately after 10 users, and immediately after 15 users: all integrity checks passed with zero violations. Checks included stock/issuance arithmetic, orphan rows across all 57 FKs, duplicate/open allocations and serialized quantities, confirmed facility conflicts, Claim Slip uniqueness/format/status, request quantities/actors/statuses and approved facility letters. Request/status distributions and all business table content remained identical to seed hashes. Only expected Login Success activity and chatbot conversation/message rows changed. Chat owners were Borrowers; all stored user messages had matching assistant replies. There were 25 successful login events across the two workloads, no failed logins and no unexpected application events.

Frozen schema verified at every gate: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns; version 20261010_008**. Every table-definition fingerprint matched the seed (AUTO_INCREMENT counter excluded as data state). No schema file was changed.

10-user and 15-user log gates: no PHP/application errors or warnings, Apache request errors/warnings, or new MariaDB log entries. Reviewed for Fatal error, uncaught Throwable, SQLSTATE, deadlock, lock wait timeout, connection exhaustion and memory exhaustion. Apache workload responses were only expected 200 pages/JSON and 302 login redirects. Login Success events were the only activity-log additions. Existing MariaDB global logging settings were unchanged.

## Resource observations

| VUs | Samples | CPU avg % | CPU max % | Min free RAM GiB | Apache busy max | DB connected max | Current lock waits max | Lock-wait count delta |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| 10 | 55 | 11.47 | 76 | 3.98 | 11 | 2 | 0 | 0 |
| 15 | 110 | 11.92 | 64 | 4.36 | 16 | 3 | 0 | 0 |


CPU/free RAM are host-wide; Apache process-memory samples include other local Apache processes. BusyWorkers/IdleWorkers are from the isolated Apache, and may include keep-alive connections and the status request. MariaDB counters are server-wide and include the telemetry connection and other local clients. Missing deadlock counters are not interpreted as zero; no deadlocks appeared in logs and sampled row-lock counters did not increase. Five-second sampling cannot exclude short peaks.

No saturated resource or definitive system bottleneck was established. The endpoint table below identifies the slowest observed request groups. Low request rates are intentionally limited by think time and this small synthetic fixture; they are not maximum throughput. The lower/higher overall averages between runs also reflect request mix, scheduling and caching and are not proof of scaling efficiency.

| VUs | Endpoint group | Requests | Avg ms | p95 ms | Max ms |
|---|---|---:|---:|---:|---:|
| 10 | login_submit | 10 | 128.05 | 152.34 | 160.14 |
| 10 | chat_csrf_form | 6 | 112.17 | 141.99 | 143.90 |
| 10 | gso_requests | 28 | 148.89 | 134.02 | 1291.98 |
| 10 | borrower_my_requests | 84 | 107.44 | 128.29 | 474.14 |
| 10 | borrower_owned_status | 84 | 93.82 | 121.03 | 148.63 |
| 10 | gso_on_loan | 28 | 98.86 | 119.18 | 128.80 |
| 10 | gso_dashboard | 28 | 77.64 | 111.87 | 117.06 |
| 10 | facility_availability | 84 | 76.68 | 95.83 | 423.94 |
| 10 | gso_maintenance | 28 | 72.22 | 90.16 | 94.26 |
| 10 | gso_walkin | 28 | 72.04 | 87.84 | 118.33 |
| 10 | chat_availability | 84 | 66.49 | 83.06 | 92.41 |
| 10 | gso_maintenance_history | 28 | 57.67 | 81.06 | 96.37 |
| 10 | chat_owned_status | 84 | 58.93 | 79.67 | 135.19 |
| 10 | mis_system_accounts | 23 | 57.34 | 76.93 | 87.52 |
| 10 | borrower_browse | 84 | 55.91 | 76.10 | 93.33 |
| 10 | mis_borrower_accounts | 23 | 51.96 | 69.94 | 72.24 |
| 10 | mis_dashboard | 23 | 49.02 | 64.78 | 66.26 |
| 10 | director_schedule | 32 | 34.32 | 47.03 | 55.29 |
| 10 | director_filtered_schedule | 32 | 31.87 | 46.49 | 50.79 |
| 10 | login_form | 10 | 27.70 | 35.52 | 36.27 |
| 15 | login_submit | 15 | 123.82 | 143.13 | 152.86 |
| 15 | borrower_my_requests | 276 | 103.98 | 133.74 | 168.18 |
| 15 | gso_requests | 82 | 101.76 | 126.76 | 137.57 |
| 15 | gso_on_loan | 82 | 100.08 | 120.08 | 166.95 |
| 15 | borrower_owned_status | 276 | 91.13 | 117.21 | 182.34 |
| 15 | chat_csrf_form | 10 | 91.91 | 109.12 | 110.32 |
| 15 | gso_dashboard | 82 | 64.80 | 103.44 | 116.03 |
| 15 | facility_availability | 276 | 72.76 | 93.22 | 133.38 |
| 15 | gso_maintenance | 82 | 73.05 | 91.87 | 122.85 |
| 15 | gso_walkin | 82 | 69.02 | 86.23 | 103.28 |
| 15 | chat_availability | 276 | 67.41 | 85.13 | 104.37 |
| 15 | chat_owned_status | 276 | 57.09 | 76.83 | 113.20 |
| 15 | borrower_browse | 276 | 56.25 | 76.79 | 91.76 |
| 15 | mis_system_accounts | 48 | 53.55 | 75.18 | 81.72 |
| 15 | gso_maintenance_history | 82 | 53.95 | 73.51 | 80.06 |
| 15 | mis_borrower_accounts | 48 | 52.37 | 73.18 | 77.68 |
| 15 | mis_dashboard | 48 | 50.57 | 70.65 | 72.42 |
| 15 | login_form | 15 | 29.86 | 50.87 | 62.05 |
| 15 | director_schedule | 62 | 32.39 | 49.64 | 59.22 |
| 15 | director_filtered_schedule | 62 | 30.75 | 46.90 | 53.07 |

## Artifacts and scope

Phase A RESULTS.md and evidence remain preserved. Phase B private evidence is in the user's TEMP folder `GSO_PHASE_B_20261017_104116`; its absolute local location is recorded only by ignored latest-phase-b-evidence.txt. Evidence includes machine.json, seed summary/table hashes, manifest/health/configuration (private credentials), integrity_before/ten/fifteen.json, ten/fifteen_summary.json and samples, execution durations, Apache access/error logs, PHP log if any, MariaDB gate deltas, telemetry.jsonl, database status snapshots and phase_b_report.json. Do not commit private manifests or evidence.

Phase B assets: PHASE_B.md, phase_b.js, run_phase_b.ps1, phase_b_manifest.php, telemetry.php, monitor.ps1, phase_b_report.php, and this PHASE_B_RESULTS.md. Shared common.php/integrity.php/.gitignore changes are confined to the test directory. No production files changed.

These are HTTP/backend tests with synthetic data, normal authentication and chat-history writes. Browser JavaScript/assets, production-size datasets, remote deployments, dedicated mutation races and the 20-user spike were not tested. **Dedicated race-condition safety is not established.**

Final git status is recorded in the execution response; all post-checkpoint changes are under scripts/load/ and remain uncommitted for review.
