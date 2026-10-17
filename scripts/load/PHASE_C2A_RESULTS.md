# Phase C2A return, reschedule and maintenance concurrency results

**PHASE C2A DATA INTEGRITY: PASS. PHASE C2A FUNCTIONAL CONCURRENCY: PASS for the tested scenarios.** All applicable requested categories passed. No production defect was detected. These correctness tests do not establish general race safety or performance capacity. No 20-user spike, consolidated capacity report, deployment, push or Phase C2B was performed.

## Checkpoint, scope and isolation

Discovered Phase C1 checkpoint: `daa9b4860e98fd728c084c5345a7c1ead3d83de3` (`Add critical transaction race-condition tests`). Initial working tree was clean. That commit changed only scripts/load/; production files matched `e2cbea932ed4073a814361c455c4f3086fd768a9`. The Phase A, B and C1 reports remain unchanged.

Fresh isolated database: **gso_load_test_phase_c2a_20261017_154946**, imported from the frozen schema with entirely synthetic records. No previous phase database or normal/live database was used. The proven guarded runtime served a git archive of the exact production baseline on **127.0.0.1:8123**, excluding local .env/mail.local.php secrets. Its DB user has DML access only to this database. Private sessions, letters, mail outbox and evidence are outside the isolated public root. Mail/socket PHP functions were disabled and verified, with the existing loopback/nonproduction local outbox fallback capturing mail. No real SMTP or model-provider calls occurred. No production code, schema, ERD, authorization or transaction helper was modified or bypassed.

Runtime: Windows/XAMPP; Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32. Fixture users/resources/Pending headers were synthetic setup records; every tested request approval, release, Return submission/inspection, reschedule, preventive plan/occurrence and corrective work-order transition used the real authenticated application POST path. No tested settled state was forced by direct SQL. Work orders were never inserted or marked completed by the test harness.

## Synchronization and evidence

Five independent sessions were established: GSO Head, GSO Staff, a second session of the GSO Head for management-only competing operations, and two sessions of the same Borrower for competing proposals. Each used its own cookie jar and real CSRF token. Queued cURL multi transfers were prepared before a shared 50 ms release barrier; competing POSTs were not sent sequentially. Apache begin/end timestamps correlated each contender with its response and fixture.

All **64 pairs / 128 contender requests overlapped in server execution**. Recorded start gaps: **0 to 1000 microseconds**. Windows clock resolution means a zero gap is indistinguishable at the recorded resolution, not exact physical simultaneity. No barrier was injected into application transactions. Sanitized start/completion timestamps, paths and statuses are in `phase_c2a_timings.csv`; raw responses, row snapshots, hashes and logs remain private.

Independent evidence review rechecked every original response body hash, all relevant before/after table hashes, local captured-message hashes, all per-race integrity gates, and exact notification/event/inventory outcomes. HTTP status counts: **{"302":123,"200":5}**. Safe borrower proposal rejections may render HTTP 200 with the documented eligibility message; GSO conflict responses redirect with a safe flash. HTTP 302 alone is not treated as proof of a successful business action. No HTTP 500 or transport failure occurred.

## Final classifications

| Classification | Result | Iterations and exact semantics |
|---|---|---|
| DAMAGED RETURN CONCURRENCY | PASS | 5 aggregate + 5 serialized pairs. Returned once. Aggregate stock 5: available 3, repair-held 2. Serialized stock 2: one affected unit Under Repair and one unaffected Available. No automatic liability or work order. |
| LOST RETURN CONCURRENCY | PASS | 5 aggregate + 5 serialized pairs. Aggregate total 5-to-3 once, available stays 3. Serialized total 2-to-1 once, affected unit Lost and unaffected unit Available. Zero repair work orders or repair-source links to Lost outcomes. |
| MIXED RETURN CONCURRENCY | PASS | 3 aggregate + 3 serialized pairs, with one Good, one Damaged and one Lost. Aggregate final total 4, available 3, repair-held 1. Serialized final total 3, available 2, repair-held 1, one Lost unit; Good and unaffected units remain Available. No double arithmetic. |
| RESCHEDULE PROPOSAL CONCURRENCY | PASS | 5 pairs. One active Pending proposal/history entry, one submission event/email, original approved parent/line schedule unchanged. Proposal tables are not reservation inputs. |
| RESCHEDULE APPROVAL COLLISION | PASS | 5 pairs. Two legitimate approved original bookings with Pending proposals for the same target interval. One proposal Approved and authoritative; loser Pending with original booking retained; zero confirmed conflicts. |
| RESCHEDULE APPROVE-vs-REJECT | PASS | 5 pairs. One terminal decision, processed reviewer metadata, one terminal audit/event/email; parent schedule follows that decision. Observed decisions: {"Approved":3,"Rejected":2}. |
| MAINTENANCE START CONCURRENCY | PASS | 5 pairs across aggregate/serialized preventive occurrences. One explicit Scheduled-to-In Progress transition, one start audit and one quantity/unit hold. No implicit start. |
| MAINTENANCE COMPLETE CONCURRENCY | PASS | 5 aggregate + 5 serialized corrective pairs. Actual Damaged-return work orders were Head-confirmed and assigned, after a Staff-only creation attempt was safely denied. Completion/restoration and history occurred once. |
| MAINTENANCE CONFLICTING TRANSITION | PASS | 5 supported preventive START-vs-CANCEL pairs. Final Cancelled, no impossible completion, stock fully available, one cancellation audit. Cancel won before Start in 1; Start legitimately serialized before Cancel in 4. Both ordered actions are valid in the latter case; the hold was restored once. |
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
| damaged_aggregate_01 | 97 | 302/302 | 0 | {"status":"Returned","total":5,"available":3,"under_repair":2,"outcomes":{"Damaged":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_aggregate_02 | 98 | 302/302 | 0 | {"status":"Returned","total":5,"available":3,"under_repair":2,"outcomes":{"Damaged":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_aggregate_03 | 99 | 302/302 | 0 | {"status":"Returned","total":5,"available":3,"under_repair":2,"outcomes":{"Damaged":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_aggregate_04 | 100 | 302/302 | 0 | {"status":"Returned","total":5,"available":3,"under_repair":2,"outcomes":{"Damaged":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_aggregate_05 | 101 | 302/302 | 0 | {"status":"Returned","total":5,"available":3,"under_repair":2,"outcomes":{"Damaged":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_serialized_01 | 102 | 302/302 | 0 | {"status":"Returned","total":2,"available":1,"under_repair":1,"outcomes":{"Damaged":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_serialized_02 | 103 | 302/302 | 0 | {"status":"Returned","total":2,"available":1,"under_repair":1,"outcomes":{"Damaged":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_serialized_03 | 104 | 302/302 | 0 | {"status":"Returned","total":2,"available":1,"under_repair":1,"outcomes":{"Damaged":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_serialized_04 | 105 | 302/302 | 0 | {"status":"Returned","total":2,"available":1,"under_repair":1,"outcomes":{"Damaged":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| damaged_serialized_05 | 106 | 302/302 | 0 | {"status":"Returned","total":2,"available":1,"under_repair":1,"outcomes":{"Damaged":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_aggregate_01 | 107 | 302/302 | 0 | {"status":"Returned","total":3,"available":3,"under_repair":0,"outcomes":{"Lost":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_aggregate_02 | 108 | 302/302 | 0 | {"status":"Returned","total":3,"available":3,"under_repair":0,"outcomes":{"Lost":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_aggregate_03 | 109 | 302/302 | 0 | {"status":"Returned","total":3,"available":3,"under_repair":0,"outcomes":{"Lost":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_aggregate_04 | 110 | 302/302 | 0 | {"status":"Returned","total":3,"available":3,"under_repair":0,"outcomes":{"Lost":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_aggregate_05 | 111 | 302/302 | 0 | {"status":"Returned","total":3,"available":3,"under_repair":0,"outcomes":{"Lost":2},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_serialized_01 | 112 | 302/302 | 0 | {"status":"Returned","total":1,"available":1,"under_repair":0,"outcomes":{"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_serialized_02 | 113 | 302/302 | 0 | {"status":"Returned","total":1,"available":1,"under_repair":0,"outcomes":{"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_serialized_03 | 114 | 302/302 | 0 | {"status":"Returned","total":1,"available":1,"under_repair":0,"outcomes":{"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_serialized_04 | 115 | 302/302 | 0 | {"status":"Returned","total":1,"available":1,"under_repair":0,"outcomes":{"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| lost_serialized_05 | 116 | 302/302 | 0 | {"status":"Returned","total":1,"available":1,"under_repair":0,"outcomes":{"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_aggregate_01 | 117 | 302/302 | 0 | {"status":"Returned","total":4,"available":3,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_aggregate_02 | 118 | 302/302 | 0 | {"status":"Returned","total":4,"available":3,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_aggregate_03 | 119 | 302/302 | 0 | {"status":"Returned","total":4,"available":3,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_serialized_01 | 120 | 302/302 | 0 | {"status":"Returned","total":3,"available":2,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_serialized_02 | 121 | 302/302 | 0 | {"status":"Returned","total":3,"available":2,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| mixed_serialized_03 | 122 | 302/302 | 0 | {"status":"Returned","total":3,"available":2,"under_repair":1,"outcomes":{"Good":1,"Damaged":1,"Lost":1},"work_orders":0,"liabilities":0,"accepted_events":1} | PASS |
| proposal_01 | 123 | 200/302 | 0 | {"pending_proposals":1,"authoritative_schedule":"unchanged","proposed_time_reserved":false} | PASS |
| proposal_02 | 124 | 200/302 | 0 | {"pending_proposals":1,"authoritative_schedule":"unchanged","proposed_time_reserved":false} | PASS |
| proposal_03 | 125 | 200/302 | 0 | {"pending_proposals":1,"authoritative_schedule":"unchanged","proposed_time_reserved":false} | PASS |
| proposal_04 | 126 | 200/302 | 0 | {"pending_proposals":1,"authoritative_schedule":"unchanged","proposed_time_reserved":false} | PASS |
| proposal_05 | 127 | 200/302 | 0 | {"pending_proposals":1,"authoritative_schedule":"unchanged","proposed_time_reserved":false} | PASS |
| reschedule_collision_01 | 128, 129 | 302/302 | 0 | {"approved_proposals":1,"loser":"Pending, original retained","confirmed_conflicts":0} | PASS |
| reschedule_collision_02 | 130, 131 | 302/302 | 994 | {"approved_proposals":1,"loser":"Pending, original retained","confirmed_conflicts":0} | PASS |
| reschedule_collision_03 | 132, 133 | 302/302 | 0 | {"approved_proposals":1,"loser":"Pending, original retained","confirmed_conflicts":0} | PASS |
| reschedule_collision_04 | 134, 135 | 302/302 | 0 | {"approved_proposals":1,"loser":"Pending, original retained","confirmed_conflicts":0} | PASS |
| reschedule_collision_05 | 136, 137 | 302/302 | 0 | {"approved_proposals":1,"loser":"Pending, original retained","confirmed_conflicts":0} | PASS |
| reschedule_decision_01 | 138 | 302/302 | 0 | {"decision":"Approved","authoritative_date":"2026-10-31","terminal_events":1} | PASS |
| reschedule_decision_02 | 139 | 302/302 | 1000 | {"decision":"Rejected","authoritative_date":"2026-10-24","terminal_events":1} | PASS |
| reschedule_decision_03 | 140 | 302/302 | 0 | {"decision":"Rejected","authoritative_date":"2026-10-24","terminal_events":1} | PASS |
| reschedule_decision_04 | 141 | 302/302 | 0 | {"decision":"Approved","authoritative_date":"2026-10-31","terminal_events":1} | PASS |
| reschedule_decision_05 | 142 | 302/302 | 0 | {"decision":"Approved","authoritative_date":"2026-10-31","terminal_events":1} | PASS |
| maintenance_start_01 | resource/work order | 302/302 | 0 | {"status":"In Progress","start_events":1,"available":3,"under_repair":2} | PASS |
| maintenance_start_02 | resource/work order | 302/302 | 0 | {"status":"In Progress","start_events":1,"available":1,"under_repair":1} | PASS |
| maintenance_start_03 | resource/work order | 302/302 | 0 | {"status":"In Progress","start_events":1,"available":3,"under_repair":2} | PASS |
| maintenance_start_04 | resource/work order | 302/302 | 0 | {"status":"In Progress","start_events":1,"available":1,"under_repair":1} | PASS |
| maintenance_start_05 | resource/work order | 302/302 | 0 | {"status":"In Progress","start_events":1,"available":3,"under_repair":2} | PASS |
| complete_aggregate_01 | 143 | 302/302 | 0 | {"maintenance":"Completed","available":5,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_aggregate_02 | 144 | 302/302 | 0 | {"maintenance":"Completed","available":5,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_aggregate_03 | 145 | 302/302 | 0 | {"maintenance":"Completed","available":5,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_aggregate_04 | 146 | 302/302 | 0 | {"maintenance":"Completed","available":5,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_aggregate_05 | 147 | 302/302 | 0 | {"maintenance":"Completed","available":5,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_serialized_01 | 148 | 302/302 | 0 | {"maintenance":"Completed","available":2,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_serialized_02 | 149 | 302/302 | 0 | {"maintenance":"Completed","available":2,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_serialized_03 | 150 | 302/302 | 0 | {"maintenance":"Completed","available":2,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_serialized_04 | 151 | 302/302 | 0 | {"maintenance":"Completed","available":2,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| complete_serialized_05 | 152 | 302/302 | 0 | {"maintenance":"Completed","available":2,"under_repair":0,"completion_events":1,"Head_confirmation":"verified"} | PASS |
| maintenance_conflict_01 | resource/work order | 302/302 | 0 | {"status":"Cancelled","start_won_first":true,"available":5,"under_repair":0,"note":"Start may serialize before a legitimate cancellation; both ordered transitions are allowed."} | PASS |
| maintenance_conflict_02 | resource/work order | 302/302 | 0 | {"status":"Cancelled","start_won_first":true,"available":2,"under_repair":0,"note":"Start may serialize before a legitimate cancellation; both ordered transitions are allowed."} | PASS |
| maintenance_conflict_03 | resource/work order | 302/302 | 0 | {"status":"Cancelled","start_won_first":false,"available":5,"under_repair":0,"note":"Start may serialize before a legitimate cancellation; both ordered transitions are allowed."} | PASS |
| maintenance_conflict_04 | resource/work order | 302/302 | 0 | {"status":"Cancelled","start_won_first":true,"available":2,"under_repair":0,"note":"Start may serialize before a legitimate cancellation; both ordered transitions are allowed."} | PASS |
| maintenance_conflict_05 | resource/work order | 302/302 | 0 | {"status":"Cancelled","start_won_first":true,"available":5,"under_repair":0,"note":"Start may serialize before a legitimate cancellation; both ordered transitions are allowed."} | PASS |
| preventive_generate_01 | resource/work order | 302/302 | 0 | {"occurrences":1,"status":"Scheduled","current_inventory":"unchanged"} | PASS |
| preventive_generate_02 | resource/work order | 302/302 | 0 | {"occurrences":1,"status":"Scheduled","current_inventory":"unchanged"} | PASS |
| preventive_generate_03 | resource/work order | 302/302 | 0 | {"occurrences":1,"status":"Scheduled","current_inventory":"unchanged"} | PASS |

## Assets, private evidence and commit

Added under scripts/load/: c2a_runtime.php, c2a_http_semantics.php, c2a_integrity.php, c2a_races.php, c2a_report.php, run_phase_c2a.ps1, phase_c2a_timings.csv and PHASE_C2A_RESULTS.md. Updated only shared common.php for the exact C2A database-name guard and .gitignore for the private locator/sanitized timing CSV. C1 files and all earlier reports remain unchanged.

Private evidence: the user's TEMP folder `GSO_PHASE_C2A_20261017_154946`, located only by ignored latest-phase-c2a-evidence.txt. It contains the frozen archive/hash, private runtime manifest/grants, before/after relevant rows/hashes, each contender's response/start/end, outbox messages, 64 integrity/log snapshots, console output, completion record and c2a_evidence_review.json. Credentials, session/Claim Slip values and raw mail are not committed. The reviewed timing CSV contains only synthetic identifiers, paths, timestamps and HTTP statuses.

The requested test/documentation commit is `Add return reschedule and maintenance race tests`; its hash and final git status are reported in the execution response. No push, 20-user spike, consolidated report, deployment, schema/ERD change or Phase C2B work was performed.
