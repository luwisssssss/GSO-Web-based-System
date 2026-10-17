# Final concurrency verification report

Local load workloads at 1, 5, 10 and 15 users passed. The supplementary 20-user spike passed. All 104 recorded race pairs passed their integrity gates. No production changes were required. Results describe the tested local workload only.

## 1. Tested application baseline / checkpoints

Production baseline: `e2cbea932ed4073a814361c455c4f3086fd768a9`. Phase A assets: `92e0242077675a842420d1def52bff2e883b22f8`; Phase B: `46cb46c90e3b7e24c2171ec6c385e4f97997907b`; C1: `daa9b4860e98fd728c084c5345a7c1ead3d83de3`; C2A/C2B starting checkpoint: `5563b3042b498a6502e466699b30bdf362d58100`. Initial HEAD matched and working tree was clean. All subsequent changes are test/documentation assets in scripts/load. The C2B runtime was exported with git archive from the production baseline, excluding ignored developer configuration. Earlier reports remain unchanged.

## 2. Test machine

Acer Nitro ANV15-41; AMD Ryzen 5 6600H, 6 cores / 12 logical processors; 16 GB installed RAM (15.31 GiB visible); KINGSTON OM8SEP4512Q-AA 512 GB disk; Windows 11 Home Single Language 10.0.26300 build 26300. k6, Apache and MariaDB shared this computer. Detailed current hardware observations remain in private machine.json.

## 3. Software environment

Apache 2.4.58 Win64; PHP 8.2.12; MariaDB 10.4.32-MariaDB; k6 v2.2.0 (00a9a1b7f5, Go 1.26.5, windows/amd64). Loopback HTTP; Apache capacity 64 workers. No external AI service was used; chatbot checks required deterministic live-data replies.

## 4. Isolation methodology

Fresh C2B database: `gso_load_test_phase_c2b_20261017_155803`. Imported frozen database/schema.sql; no earlier test database or normal database was selected. Runtime credentials have SELECT/INSERT/UPDATE/DELETE rights only on this database. Dedicated Apache bound to 127.0.0.1:8124; separate sessions, upload directory, PHP configuration, logs and mail outbox. Startup disables mail/fsockopen/pfsockopen/stream_socket_client; health checks verified DB identity, schema version and rejected SMTP configuration. No real SMTP; zero C2B outbox messages. All synthetic addresses use example.invalid. Twenty distinct accounts, one cookie jar per VU, real login/CSRF/routes, no production bypass. Owned runtime processes were stopped; DB/evidence retained. No deployment or push.

## 5. Frozen schema verification

Before and immediately after C2B: **22 tables, 57 FKs, 104 indexes, 18 UNIQUE, 11 CHECK, 3 generated columns, version 20261010_008**. All 22 normalized SHOW CREATE TABLE hashes match seed; only AUTO_INCREMENT counters are excluded from schema hashes. Frozen schema and ERD files were unchanged throughout these test asset commits.

## 6. Phase A summary

PASS. One VU for 90 seconds, then five VUs for 120 seconds, with graceful journey completion. Baseline rotated five accounts sequentially; five-user mix was two Borrowers, one GSO Head, one Director and one MIS. Real login, availability, request and role reads. Both final integrity gates passed; zero HTTP failures, HTTP 500 and unintended errors. Source: [RESULTS.md](RESULTS.md); completed evidence folder GSO_PHASE_A_20261017_102501. Two earlier setup attempts were excluded from reported measurements (startup SMTP guard and harness HTML marker correction), as documented in that report.

## 7. Phase B summary

PASS. Ten independent users for 300 seconds; fifteen for 600 seconds; 30-second graceful completion allowed. Ten-user mix: six Borrowers, Head, Staff, Director, MIS. Fifteen: ten Borrowers, Head, two Staff, Director, MIS. Added owned request and deterministic chatbot reads. All final integrity/log gates passed. Source: [PHASE_B_RESULTS.md](PHASE_B_RESULTS.md), GSO_PHASE_B_20261017_104116. These runs were not repeated for consolidation.

## 8. Phase C1 summary

PASS: 40 pairs / 80 contenders; all server execution intervals overlapped; server start gaps 0-1106 microseconds. Independent sessions, actual authenticated CSRF POSTs, queued transfers and shared barrier. All 57 FK checks and invariant/log gates passed after each race. Exactly-once settlement and one winning allocation/release were verified from before/after rows and client events. HTTP 200 safe conflict responses were evaluated by database state rather than treated as server errors. Source: [PHASE_C1_RESULTS.md](PHASE_C1_RESULTS.md), [timings](phase_c1_timings.csv), GSO_PHASE_C1_20261017_124045. Prior harness interruptions/corrections are documented there; completed races were preserved.

## 9. Phase C2A summary

PASS: 64 pairs / 128 contenders; all server execution intervals overlapped, start gaps 0-1000 microseconds. Damaged/Lost/mixed returns, rescheduling and maintenance were checked after every pair. Lost produced zero repair work orders; Damaged corrective work required GSO Head confirmation. Approval/rejection produced one terminal proposal decision. Start/cancel allowed legitimate ordered transitions while restoring stock once. Preventive generation created one scheduled occurrence without auto-start. Source: [PHASE_C2A_RESULTS.md](PHASE_C2A_RESULTS.md), [timings](phase_c2a_timings.csv), GSO_PHASE_C2A_20261017_154946. No production defect or fix was recorded.

## 10. 20-user spike summary

PASS. Twenty constant VUs, configured 120 seconds plus 30-second graceful completion; actual k6 duration 140.556 seconds. Mix exactly 12 Borrowers, four operational users (Head plus three Staff), two Directors and two MIS. Three additional synthetic accounts extended the Phase B-equivalent fixture before the seed snapshot. Fixture: 96 requests, three facilities, three aggregate resources, two consumable resources and two serialized resources / 24 units. Random initial offset 0-3 seconds; think time 2-4 seconds between reads/chat and 2-5 seconds between journeys. 727 HTTP requests, 5.172300 req/s. Latency ms: min 14.3924, average 74.7175, median 50.3042, p90 89.9904, p95 114.1679, p99 738.3839, max 1701.0108. Zero HTTP failures, HTTP 500, unintended errors; unintended rate 0%. p95 is below 2000 ms; no performance degradation classification. No deliberate races were run during the spike.

25 telemetry samples: whole-host CPU average 11.96%, peak 79%; minimum available RAM 3.371 GiB; maximum busy Apache workers 21/64 (includes status observer); peak aggregate httpd working set 148.71 MiB (includes normal Apache). Sampled global DB connections peaked at 2; Threads_running peaked at 7. Current row-lock waits peak 0; lock-wait count delta 0; lock-wait time delta 0 ms; aborted-connect delta 0. MariaDB exposes no Innodb_deadlocks counter here; logs contain no observed deadlock.

Endpoint request counts:

- borrower_browse: 72
- borrower_my_requests: 72
- borrower_owned_status: 72
- chat_availability: 72
- chat_csrf_form: 12
- chat_owned_status: 72
- director_filtered_schedule: 24
- director_schedule: 24
- facility_availability: 72
- gso_dashboard: 23
- gso_maintenance: 23
- gso_maintenance_history: 23
- gso_on_loan: 23
- gso_requests: 23
- gso_walkin: 23
- login_form: 20
- login_submit: 20
- mis_borrower_accounts: 19
- mis_dashboard: 19
- mis_system_accounts: 19

## 11. Performance table

Latency is milliseconds; duration is configured / actual seconds including graceful completion. req/s uses actual k6 duration; requests include login and chat where present.

| Users | Duration (s) | Requests | req/s | Avg | Median | p90 | p95 | p99 | Max | HTTP 500 | Integrity |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 1 | 90 / 92.608 | 33 | 0.356 | 83.12 | 87.16 | 122.05 | 127.62 | 130.28 | 130.60 | 0 | PASS |
| 5 | 120 / 130.511 | 156 | 1.195 | 70.08 | 67.98 | 112.05 | 119.62 | 132.14 | 137.00 | 0 | PASS |
| 10 | 300 / 317.275 | 831 | 2.619 | 73.81 | 68.63 | 108.93 | 117.97 | 141.93 | 1291.98 | 0 | PASS |
| 15 | 600 / 618.023 | 2456 | 3.974 | 71.83 | 69.40 | 105.49 | 116.04 | 131.78 | 182.34 | 0 | PASS |
| 20 | 120 / 140.556 | 727 | 5.172 | 74.72 | 50.30 | 89.99 | 114.17 | 738.38 | 1701.01 | 0 | PASS |

Phase A did not record minimum latency in its summary; no minimum is inferred. Role mixes, run lengths and chat coverage vary, so rows are workload observations rather than a controlled scaling curve.

## 12. Race-condition table

| Scenario | Iterations / pairs | Result | Integrity violations | Notes |
|---|---:|---|---:|---|
| Facility double approval | 5 | PASS | 0 | One confirmed booking |
| Aggregate oversubscription | 5 | PASS | 0 | 4 of 5 issued; losing release unchanged |
| Serialized-unit collision | 5 | PASS | 0 | One open allocation |
| Claim Slip double release | 5 | PASS | 0 | One release and stock deduction |
| Valid vs invalid Claim Slip | 5 | PASS | 0 | Only valid claimant released |
| Duplicate Return submission | 5 | PASS | 0 | One Pending header/detail; stock unchanged |
| Duplicate Return inspection | 10 | PASS | 0 | Five aggregate and five serialized; settlement once |
| Damaged Return | 10 | PASS | 0 | Five aggregate and five serialized; repair hold once; no automatic work order |
| Lost Return | 10 | PASS | 0 | Five aggregate and five serialized; total reduced once; no repair |
| Mixed Return | 6 | PASS | 0 | Three aggregate and three serialized; Good/Damaged/Lost distinct |
| Reschedule proposal | 5 | PASS | 0 | One Pending proposal; parent unchanged |
| Reschedule approval collision | 5 | PASS | 0 | One target booking approved; competing original retained |
| Approve vs reject | 5 | PASS | 0 | One terminal decision: three Approved / two Rejected |
| Maintenance start | 5 | PASS | 0 | Hold and started event once |
| Maintenance complete | 10 | PASS | 0 | Five aggregate and five serialized; confirmed corrective restoration once |
| Conflicting maintenance transition | 5 | PASS | 0 | One cancel before start; four valid start-then-cancel; restoration once |
| Preventive maintenance | 3 | PASS | 0 | One scheduled occurrence per plan/date; no inventory change |

104 pairs / 208 contenders total. Scenario results come from retained C1/C2A reviewed evidence; no races were rerun in C2B.

## 13. Integrity findings

PASS. Immediate C2B read-only transaction audited all 57 FK relationships and 51 aggregate invariant categories: all invalid counts zero. Covered negative/aggregate/consumable/serialized stock, allocation ownership/uniqueness, facility overlap/periods, Claim Slip format/uniqueness/status/no log leakage, quantity/actor/request statuses, verified facility letters, return headers/details/settlement, repair/Lost separation, liabilities, proposal uniqueness/authority/terminal metadata, preventive occurrences/states, completed maintenance/restoration and repair ledger. All business tables matched seed row/hash snapshots; only successful login activity and paired Borrower chat rows were allowed to change. Zero failed logins/unexpected events. Reschedule/return/maintenance tables remained unchanged in this read-oriented spike; their functional mutation evidence comes from C1/C2A. All prior per-run/per-race integrity gates also passed.

C2B audit counts:

- negative_stock: 0
- aggregate_stock_arithmetic: 0
- consumable_stock_arithmetic: 0
- serialized_stock_arithmetic: 0
- duplicate_open_allocations: 0
- duplicate_line_unit_allocations: 0
- allocation_resource_mismatch: 0
- released_unit_allocation_invariants: 0
- serialized_line_allocations: 0
- facility_conflicts: 0
- facility_period_invariants: 0
- claim_slip_format_and_status: 0
- duplicate_claim_slips: 0
- requests_without_lines: 0
- line_quantity_integrity: 0
- request_actor_integrity: 0
- status_release_integrity: 0
- approved_facility_letters: 0
- orphan_rows: 0
- duplicate_return_headers: 0
- pending_return_stock_applied: 0
- approved_return_details: 0
- return_settlement_quantities: 0
- returned_request_status: 0
- pending_request_return_state: 0
- duplicate_client_events: 0
- duplicate_email_attempts: 0
- duplicate_email_deliveries: 0
- failed_email_deliveries: 0
- duplicate_notifications: 0
- lost_in_repair_work_order: 0
- invalid_repair_source: 0
- duplicate_repair_work_order: 0
- duplicate_liability: 0
- duplicate_pending_proposal: 0
- pending_proposal_authority: 0
- reschedule_terminal_metadata: 0
- preventive_duplicate_occurrence: 0
- preventive_state_metadata: 0
- completed_maintenance_metadata: 0
- lost_serialized_unavailable: 0
- damaged_serialized_unavailable: 0
- aggregate_repair_ledger: 0
- consumable_issuance_arithmetic: 0
- schema_fingerprint_changes: 0
- raw_claim_slips_in_activity_log: 0
- chat_conversation_owner: 0
- chat_message_pairs: 0
- unexpected_business_table_changes: 0
- failed_logins: 0
- unexpected_application_events: 0

## 14. Log findings

C2B private PHP/application log: absent/empty; Apache log: normal startup notices only, zero warning/error/critical entries; MariaDB byte-offset delta: empty. Raw k6 samples confirm zero HTTP 500 and expected 200/302 responses. No fatal error, uncaught Throwable, SQLSTATE, deadlock, lock timeout, connection exhaustion, memory exhaustion, maximum execution time or crash observed. No telemetry collection errors. Earlier completed A/B/C2A gates recorded no application errors; C1 recorded one controlled restart stale-PID warning (AH00098), documented separately from request failures. Prior setup/harness corrections are retained in original reports. No global MariaDB logging settings changed; log silence is evidence limited to configured log coverage.

## 15. Limitations

One local Windows/XAMPP computer; synthetic data and loopback HTTP; k6 and server share CPU/RAM. No WAN/TLS latency, production hardware/hosting limits, real SMTP delivery, external AI traffic, extended soak or distributed load tested. 20 users ran for two minutes only. These read-oriented load journeys do not measure sustained transactional throughput; critical writes were covered separately by paired races. Telemetry is sampled roughly every five seconds and may miss short peaks; DB status counters are global and include observer/other server activity; Threads_running is not isolated application concurrency. No exposed deadlock counter; no deadlocks observed in configured logs. Maintenance midnight/end-date rollover was not exercised by actual clock passage; preventive work was explicitly started/completed through real routes, as scoped in C2A. Finite races provide evidence for the recorded interleavings, not a proof of every possible schedule. Original private evidence contains credentials/sessions/raw mail and remains outside Git; sanitized summaries and timing CSVs are committed.

## 16. Final conclusion

Testing on the local XAMPP environment supports at least 15 concurrent users under the defined workload, with no observed data-integrity violations or unhandled application failures.

A short 20-user spike test also completed successfully, providing additional local headroom evidence.

Production capacity must be revalidated on the actual target staging or production host because server resources, database configuration, network latency, and hosting limits may differ from the local test environment.

| Classification | Result |
|---|---|
| 1-USER BASELINE | PASS |
| 5-USER LOAD | PASS |
| 10-USER LOAD | PASS |
| 15-USER LOAD | PASS |
| 20-USER SPIKE | PASS |
| PHASE C1 TRANSACTION RACES | PASS |
| PHASE C2A TRANSACTION RACES | PASS |
| FINAL DATA INTEGRITY | PASS |
| LOCAL 15-USER CAPACITY | SUPPORTED BY TEST EVIDENCE |
| LOCAL 20-USER HEADROOM | SUPPORTED BY SPIKE EVIDENCE |

## 17. Recommended thesis wording

Under the defined synthetic workload on the local XAMPP environment, normal concurrent load tests passed through 15 users, and a short 20-user spike provided supplementary headroom evidence. Across 104 deliberately synchronized transaction race pairs, recorded critical operations settled exactly once where required, preserving stock and relational integrity. No facility double booking, stock oversubscription, duplicate serialized allocation, duplicate release or duplicate Return settlement was observed. Lost returns were never routed to repair; Damaged corrective repair required GSO Head confirmation. Frozen schema counts and fingerprints remained unchanged. These findings support the tested local workloads and observed interleavings; they do not establish production capacity. Production capacity must be revalidated on the actual target staging or production host because server resources, database configuration, network latency, and hosting limits may differ from the local test environment.

Evidence index: earlier reports linked above; sanitized C2B metrics/coverage in [phase_c2b_summary.json](phase_c2b_summary.json). Private evidence folders under the operator TEMP directory: `GSO_PHASE_A_20261017_102501`, `GSO_PHASE_B_20261017_104116`, `GSO_PHASE_C1_20261017_124045`, `GSO_PHASE_C2A_20261017_154946`, `GSO_PHASE_C2B_20261017_155803`. C2B contains raw k6 samples/summary, seed and post-spike hashes, health/identity, runtime export hash, execution timing, telemetry and logs. Reusable C2B assets: run_phase_c2b.ps1, phase_c2b.js, c2b_manifest.php, c2b_integrity.php, c2b_monitor.ps1 and this report generator. Final test/documentation commit is named `Finalize concurrent-user capacity verification`; its hash and final git status are supplied in the execution response to avoid a self-referential report hash.
