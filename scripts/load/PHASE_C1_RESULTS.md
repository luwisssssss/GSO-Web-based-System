# Phase C1 critical transaction race-condition results

All seven requested categories PASS. PHASE C1 DATA INTEGRITY: **PASS**. PHASE C1 FUNCTIONAL CONCURRENCY: **PASS for the tested scenarios and repetitions**. No production concurrency defect was found. Phase C2, sustained load, a 20-user spike, deployment and push were not performed. These results make no performance-capacity claim or universal concurrency-safety claim.

## Preserved checkpoint and isolation

Phase B checkpoint: `46cb46c90e3b7e24c2171ec6c385e4f97997907b` (`Add 10 and 15 user load-test verification`). Production baseline: `e2cbea932ed4073a814361c455c4f3086fd768a9`. Phase B was checkpointed with a clean working tree before C1. All subsequent changes were confined to scripts/load/. Phase A RESULTS.md and PHASE_B_RESULTS.md were preserved unchanged.

Existing isolated database: **gso_load_test_phase_c1_20261017_124045**. The same database, frozen export and completed Facility results were preserved across every resume. The five Facility races were not repeated. Synthetic seed: 12 Borrowers, 3 operational accounts, Director, MIS, controlled resources, units and requests. Each race used fresh dedicated resources/requests; normal application approval, release and submission flows established the tested preconditions. No legitimate user data or normal/live database was used.

Apache listened only on 127.0.0.1:8122 and served an unmodified git archive of the frozen production baseline from private temporary storage. The archive excludes ignored local .env/mail.local.php files and real secrets. The runtime DB account has DML privileges only on the C1 database. Existing local, nonproduction mail fallback captured messages outside the web root, with invalid/empty SMTP configuration and mail/socket PHP functions disabled and verified at health checks. No SMTP or external model-provider calls occurred. No application mail helper, permission gate, transaction code or schema was replaced or bypassed. The owned test server was stopped after execution; database/evidence retained.

Windows/XAMPP environment: Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32. This is correctness testing using PHP cURL, not a repeat of Phase B load measurement.

## Synchronization and recorded timing

Four independent authenticated cookie jars were used: GSO Head and GSO Staff for operational races, plus two separate sessions of the same synthetic Borrower for duplicate submission. Every POST used its own session's real CSRF token. No shared-session PHP lock serialized the two contenders.

Both cURL handles were configured and queued in curl_multi before a shared 50 ms release barrier. Transfers began together through the multi event loop, never through sequential curl_exec calls. Apache recorded each contender's request-start and completion timestamps, HTTP status and duration, correlated by X-GSO-C1-Contender. All **40 request pairs had overlapping server intervals**. Observed start gaps were **0 to 1106 microseconds**. Windows/Apache timestamps have finite resolution; a recorded zero means indistinguishable at that resolution, not proven exact physical simultaneity. This observes overlapping HTTP execution, not an instrumented barrier inside a database statement.

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

All 80 contender HTTP results: **{"302":76,"200":4}** (status-to-count). HTTP 302 includes success and safe rejection redirects; final DB snapshots distinguish winner and loser. Duplicate-return losers may instead render HTTP 200 with the explicit safe message 'This request is no longer eligible for return submission.' No HTTP 500 or transport failure occurred. In each pair, exactly one client event, delivery attempt, successful local capture and captured message were added. Low-stock release cases also correctly generated one separate GSO Low Stock Alert; this is distinct from the single client release notification and was verified, not classified as a duplicate. Approval emails legitimately contain the issued Claim Slip; application activity logs and public artifacts do not expose raw Claim Slips.

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
| facility_01 | 97, 98 | 302/302 | 0 | 1 Approved; 1 Pending/Under Review; 0 confirmed conflicts | PASS |
| facility_02 | 99, 100 | 302/302 | 0 | 1 Approved; 1 Pending/Under Review; 0 confirmed conflicts | PASS |
| facility_03 | 101, 102 | 302/302 | 0 | 1 Approved; 1 Pending/Under Review; 0 confirmed conflicts | PASS |
| facility_04 | 103, 104 | 302/302 | 0 | 1 Approved; 1 Pending/Under Review; 0 confirmed conflicts | PASS |
| facility_05 | 105, 106 | 302/302 | 0 | 1 Approved; 1 Pending/Under Review; 0 confirmed conflicts | PASS |
| aggregate_01 | 109, 110 | 302/302 | 0 | 4 of 5 issued; available 1; loser Approved | PASS |
| aggregate_02 | 111, 112 | 302/302 | 0 | 4 of 5 issued; available 1; loser Approved | PASS |
| aggregate_03 | 113, 114 | 302/302 | 0 | 4 of 5 issued; available 1; loser Approved | PASS |
| aggregate_04 | 115, 116 | 302/302 | 0 | 4 of 5 issued; available 1; loser Approved | PASS |
| aggregate_05 | 117, 118 | 302/302 | 0 | 4 of 5 issued; available 1; loser Approved | PASS |
| serialized_01 | 119, 120 | 302/302 | 0 | 1 active unit allocation; available 0; loser Approved | PASS |
| serialized_02 | 121, 122 | 302/302 | 999 | 1 active unit allocation; available 0; loser Approved | PASS |
| serialized_03 | 123, 124 | 302/302 | 0 | 1 active unit allocation; available 0; loser Approved | PASS |
| serialized_04 | 125, 126 | 302/302 | 0 | 1 active unit allocation; available 0; loser Approved | PASS |
| serialized_05 | 127, 128 | 302/302 | 0 | 1 active unit allocation; available 0; loser Approved | PASS |
| claim_double_01 | 129 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_double_02 | 130 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_double_03 | 131 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_double_04 | 132 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_double_05 | 133 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_invalid_01 | 134 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_invalid_02 | 135 | 302/302 | 1106 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_invalid_03 | 136 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_invalid_04 | 137 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| claim_invalid_05 | 138 | 302/302 | 0 | Released once; aggregate 3/5; consumables 3/5; 1 unit allocation; 1 release notification/email | PASS |
| return_submit_01 | 139 | 200/302 | 0 | 1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email | PASS |
| return_submit_02 | 140 | 200/302 | 0 | 1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email | PASS |
| return_submit_03 | 141 | 200/302 | 0 | 1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email | PASS |
| return_submit_04 | 142 | 200/302 | 0 | 1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email | PASS |
| return_submit_05 | 143 | 302/302 | 0 | 1 Pending return/header/detail; request Return; stock unchanged at 2/5; 1 submission email | PASS |
| inspect_aggregate_01 | 144 | 302/302 | 0 | Returned once; stock 5/5; Good quantity 3 applied once; 1 acceptance email | PASS |
| inspect_aggregate_02 | 145 | 302/302 | 0 | Returned once; stock 5/5; Good quantity 3 applied once; 1 acceptance email | PASS |
| inspect_aggregate_03 | 146 | 302/302 | 0 | Returned once; stock 5/5; Good quantity 3 applied once; 1 acceptance email | PASS |
| inspect_aggregate_04 | 147 | 302/302 | 0 | Returned once; stock 5/5; Good quantity 3 applied once; 1 acceptance email | PASS |
| inspect_aggregate_05 | 148 | 302/302 | 0 | Returned once; stock 5/5; Good quantity 3 applied once; 1 acceptance email | PASS |
| inspect_serialized_01 | 149 | 302/302 | 0 | Returned once; stock 1/1; Good quantity 1 applied once; 1 acceptance email | PASS |
| inspect_serialized_02 | 150 | 302/302 | 0 | Returned once; stock 1/1; Good quantity 1 applied once; 1 acceptance email | PASS |
| inspect_serialized_03 | 151 | 302/302 | 0 | Returned once; stock 1/1; Good quantity 1 applied once; 1 acceptance email | PASS |
| inspect_serialized_04 | 152 | 302/302 | 0 | Returned once; stock 1/1; Good quantity 1 applied once; 1 acceptance email | PASS |
| inspect_serialized_05 | 153 | 302/302 | 0 | Returned once; stock 1/1; Good quantity 1 applied once; 1 acceptance email | PASS |

## Files, evidence and commit

Added: c1_races.php, c1_integrity.php, c1_http_semantics.php, c1_review_return_result.php, c1_report.php, run_phase_c1.ps1, resume_phase_c1.ps1, phase_c1_timings.csv and PHASE_C1_RESULTS.md. Updated only scripts/load/common.php (C1 DB-name guard) and scripts/load/.gitignore (private evidence locator). These are test/documentation assets. No production files or prior reports changed.

Private evidence is retained in the user's TEMP folder `GSO_PHASE_C1_20261017_124045`, located by ignored latest-phase-c1-evidence.txt. It contains exact relevant rows/hashes, claims/session information, outbox messages, raw responses, original/reviewed result records, per-race integrity/log snapshots, server configuration, frozen source archive/hash and execution consoles. None of that private evidence or credentials is included in the test/documentation commit. The sanitized timing CSV contains only synthetic identifiers, paths, timestamps and HTTP outcomes.

The requested final test/documentation commit is `Add critical transaction race-condition tests`; its hash and final git status are provided in the execution response. No push or Phase C2 was performed.
