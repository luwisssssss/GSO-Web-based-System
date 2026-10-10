# R9 transactional email checkpoint

Baseline: R8 `4df375f8c8feb82c71f5455803fcf1a24ca6562f`.
Scope: important client-facing transactional email using existing PHPMailer/SMTP and private local mail capture. No schema migration, new table, historical migration edit, or production data mutation was required. No R10/R11/R12 work or push was performed.

## Pre-R9 backup

- Path: `C:\Users\ACER\gso_db_backups\gso_database_pre_r9_20261010_175356.sql`
- Size: **14,758,078 bytes**; dump exit code: **0**.
- SHA-256: `29de2a2ca40000c6fdc81ed8d67bad7501a80ed115b9eab7022205e0ca3647b5`.
- Size/checksum were reverified during final read-only integrity verification. Earlier backups and checkpoints were retained.

## Architecture and ordering

`gsoPrepareClientEvent` reads authoritative request/resources/schedule data inside the guarded business transaction, creates the registered owner's in-app notification, and records an event marker in existing activity logs. `gsoDeliverClientEvent` is explicitly called only after successful commit. It requires the committed marker, resolves the current stored recipient, claims one attempt under the request-row lock, then uses existing `gsoSendEmail`. Delivery occurs after the short claim transaction also commits; SMTP never runs while business locks are held.

Existing `activity_logs` hold readable request/event identifiers plus a stable occurrence hash, attempt, sent/failed/skipped outcomes. No delivery table or background queue was introduced. This is best-effort delivery with at most one automatic attempt per event, not a guaranteed retry service; an interrupted attempt remains unconfirmed in diagnostics. Transport success means accepted by the configured transport, not proof of inbox delivery.

Actual transition predicates, immutable proposal/document identifiers, return detail versions, reminder counters/cooldown, and locked event markers prevent duplicates. Failed/rolled-back actions have no committed marker and send no success email. Repeated settled POSTs, helper invocation, GETs and page refreshes do not resend. Identical accountability reviews do not create another event.

SMTP/configuration/capture failure is caught after business commit and cannot undo approval, rescheduling, release, return acceptance or stock settlement. Registered in-app notifications remain. Logs contain safe public reference/event diagnostics rather than SMTP errors or credentials. GSO reminder messages distinguish recorded events, missing optional email and unconfirmed delivery.

## Exact enabled event list

| Event | Existing trigger and semantics |
|---|---|
| Request received | Successful registered submission or GSO Walk-in creation; Pending |
| Request approved / ready to coordinate claim | Successful GSO approval; authoritative resources and current schedule |
| Request rejected | Successful rejection; no private review notes |
| Request cancelled by GSO | Existing GSO-encoded Walk-in cancellation; catalogue also supports Registered cancellation when produced by an authorized workflow |
| Reschedule proposal received | Successful owner submission or GSO-encoded Walk-in proposal; original schedule remains active |
| Reschedule approved | Successful GSO review; NEW confirmed schedule |
| Reschedule rejected | Proposal rejected; original reservation unchanged, request itself not described as rejected |
| Resources released | Successful release/issuance, including Completed consumable-only requests |
| Return/post-use submission received | Request enters Return, awaiting inspection |
| Return inspection accepted | Request is Returned after stock settlement; recorded Good/repairable/Lost outcomes |
| Return submission rejected | Request resumes Released; corrective resubmission instructions |
| Overdue reminder | Existing explicit GSO reminder POST; Released and actually overdue, with 24-hour cooldown |
| Non-financial accountability update | Successful client-visible review creation/change; safe outcome and review status |
| Request letter verified | Successful document verification, separate from request approval/manual signatures |
| Request letter rejected | Replacement required; explicitly client-visible correction reason escaped |

No new registered GSO-cancellation business action or claim-code system was invented. Existing request/reference and release information is used. Borrower self-cancellation retains its existing workflow. Existing passive overdue flags may still create their established in-app notices on page access; email reminders are tied to the explicit GSO POST, never GET/rendering. Internal assignments, work orders, maintenance plans/notes, chat, login throttling, Director viewing and MIS administration do not gain client email hooks.

## Recipient/content security

- Registered: legitimate associated `users.email` is reread at delivery, plus an in-app notification for the actual Registered owner. Arbitrary POST destinations are ignored.
- Walk-in: email uses only that request's `walkin_email` snapshot. Blank/invalid optional email records a skip without a send attempt. No account, fake mailbox recipient or external-client master record is created. Independent requests preserve independent email snapshots.
- Walk-in emails contain necessary safe transaction information and no borrower-only link. Registered links use only a valid configured application URL and approved authenticated borrower paths; missing configuration falls back to sign-in instructions. Browser Host headers cannot choose notification links; ordinary notification links carry no authentication token.
- One shared event defines in-app/email semantics. Existing in-app return titles remain compatible while describing accepted Returned state correctly. Facility emails use stored AM/PM and exact date/times, including legitimately approved extended hours. Equipment-only requests do not acquire fabricated facility classification.
- HTML escapes the complete dynamic plain-text content with `ENT_QUOTES | ENT_SUBSTITUTE`; the same content is the plain-text alternative. No private notes, other borrower data, unit IDs/asset tags/serials, password hashes or SMTP configuration are included.
- Lost remains separate from repairable Damaged/Missing Parts/Needs Repair; no fees, amounts, payment or billing are introduced. No Adviser endorsement or Director system-approval wording is added.
- Existing signup-verification and password-reset helpers/configuration/flows remain. Their existing account-token links and account-email commit behavior were intentionally retained; R9 adds no ordinary notification authentication tokens. Only the established mail helper instantiates PHPMailer.

## Files changed

- `app/controllers/admin/on_loan.php`
- `app/controllers/admin/requests.php`
- `app/controllers/admin/review_return.php`
- `app/controllers/admin/view_request.php`
- `app/controllers/borrower/request_resource.php`
- `app/controllers/borrower/return_submit.php`
- `app/models/mail_helper.php`
- `app/models/reschedule_helper.php`
- `app/models/walkin_helper.php`
- `app/models/work_order_helper.php`
- `scripts/r1_return_lifecycle_e2e.php`
- `scripts/r2a_role_director_cutover_e2e.php`
- `scripts/r2b_manual_letter_adviser_retirement_e2e.php`
- `scripts/r3_ampm_facility_scheduling_e2e.php`
- `scripts/r4_rescheduling_e2e.php`
- `scripts/r5_walkin_e2e.php`
- `scripts/r6_damage_loss_e2e.php`
- `scripts/r7_preventive_maintenance_e2e.php`
- `scripts/r8_chatbot_live_inquiries_e2e.php`
- `scripts/request_letter_test_helper.php`
- `app/models/client_notification_helper.php`
- `includes/client_notification_helper.php`
- `scripts/r9_email_notifications_e2e.php`
- `docs/R9_EMAIL_CHECKPOINT.md`

R1-R8 scripts and the letter fixture helper changed only isolated-database compatibility allowlists. R8 chatbot production files were not modified. Test capture in the existing mail helper is strictly restricted to a development/test, loopback, explicitly isolated R9 database. It bypasses SMTP even when local developer credentials exist; a private failure marker makes failure deterministic. This is unavailable to normal production database requests.

## Verification

All active suites ran against isolated `gso_r9_test_20261010_175747`, loopback Apache port 8100 and a private local outbox; no public SMTP or legitimate live recipient was used. The table records each suite's final passing output after resolving failures.

| Suite | Actual checks | Result |
|---|---:|---|
| `phase1_roles_e2e.php` | 44 | PASS |
| `phase35a_combined_borrowing_e2e.php` | 74 | PASS |
| `phase35b_unit_tracking_e2e.php` | 87 | PASS |
| `phase35c_workorder_accountability_e2e.php` | 47 | PASS |
| `r1_return_lifecycle_e2e.php` | 62 | PASS |
| `r2a_role_director_cutover_e2e.php` | 129 | PASS |
| `r2b_manual_letter_adviser_retirement_e2e.php` | 93 | PASS |
| `r3_ampm_facility_scheduling_e2e.php` | 90 | PASS |
| `r4_rescheduling_e2e.php` | 93 | PASS |
| `r5_walkin_e2e.php` | 136 | PASS |
| `r6_damage_loss_e2e.php` | 119 | PASS |
| `r7_preventive_maintenance_e2e.php` | 243 | PASS |
| `r8_chatbot_live_inquiries_e2e.php` | 113 | PASS |
| `r9_email_notifications_e2e.php` | 136 | PASS |
| `step10_live_data_e2e.php` | 46 | PASS |
| `step6_incident_e2e.php` | 59 | PASS |
| `step7_incident_reports_e2e.php` | 25 | PASS |
| `step8_core_e2e.php` | 120 | PASS |
| `step9_chatbot_e2e.php` | 63 | PASS |
| `step8_login_throttle_test.php` | 22 | PASS |

**20 active suites; 1,801 actual checks; focused R9: 136 checks.** Phase2 Adviser endorsement and Phase3 Director approval suites remain **SUPERSEDED** and were not run.

All **23 changed PHP files** pass syntax checks. `git diff --check` passes. Production call-site review confirms every new client delivery follows event preparation and commit. SMTP failure, rollback suppression, duplicate POST/GET/helper protection, successful return settlement, account-mail transport, recipient tampering, Walk-in privacy, escaped HTML and configured-only links are covered. No raw PHPMailer duplication, GET email hook, POST-selected destination, financial accountability content or client-facing SMTP diagnostic was introduced.

## Final read-only live integrity

All **24 tables** retain their pre-R9 row hashes, with **67 foreign keys**. No historical notification, request state, schedule, inventory, maintenance, account identity or AI record changed. No fake Walk-in user or production mail was created by verification.

| Records / quantity | Verified live value |
|---|---|
| Users | 6: Admin 1, Borrower 5; Approved 5, Pending 1 |
| Requests | 10 Registered: Cancelled 1, Released 3, Returned 6; Walk-in 0 |
| Resources | 12: 7 Aggregate items Available/Good; 4 facilities Available/Good; 1 facility Unavailable/Needs Repair |
| Request items | 10, including 3 legacy NULL Facility slots, preserved |
| Notifications / activity logs | 318 / 177, unchanged |
| Attachments / reschedules | 0 / 0 |
| Returns / details / photos | 6 / 6 / 2; 15 returned units and 0 lost |
| Maintenance / preventive plans | 1 Completed NULL/Unclassified schedule, preserved / 0 plans |
| Liabilities | 0 |
| Serialized units / allocations | 0 / 0 |
| AI conversations / messages / knowledge | 4 / 18 / 14 |
| Inventory totals | 633 total, 625 available, 0 repair |
| Settings | 4, unchanged |

Checked line, return, maintenance, liability and preventive-plan/occurrence orphans and duplicate open allocations: **0**. No invalid stock, Lost-in-repair, Lost work order, overapplied return or unapproved stock application was detected. No email was sent during the raw-PDO read-only audit.

Audit evidence is retained at `C:\Users\ACER\AppData\Local\Temp\GSO_R9_20261010_175338`: `active_regression.json`, individual suite logs, private test mail captures, and `live_integrity.json`. The final response supplies the single commit hash and final status. Protected landing deletion and untracked GSO Staff pages remain outside the commit.

R10 integrated obsolete-code/schema cleanup, R11 schema freeze and R12 final ERD were not started. No required R9 event was deferred; delivery retries/background workers were not requested or added.

## Final verification on resume

The session that implemented R9 was interrupted before committing. On resume, the suite logs showed that the 1,801-check total above had been assembled across partial runs: the first full run stopped at a `step8_core_e2e.php` failure, fixes followed, and the last edits to `app/controllers/admin/requests.php`, `app/controllers/admin/on_loan.php` and `app/models/client_notification_helper.php` postdated most suite logs.

All 20 active suites were therefore rerun in one uninterrupted pass on the final, unmodified working tree, against the same isolated `gso_r9_test_20261010_175747` database, loopback port 8100 and private local outbox. Every suite passed with the same per-suite counts as the table above: **20 suites, 1,801 checks, focused R9 136 checks**. Working-tree fingerprints taken before and after the rerun are identical, so the committed code is exactly the code that was tested.

Also reverified on resume: PHP syntax for all 23 changed PHP files, `git diff --check`, the pre-R9 backup size and SHA-256, and read-only live state (24 tables, 67 foreign keys, 318 notifications, 177 activity logs, 10 requests, stock 633/625/0, no client-event markers and no new notifications in live). The earlier partial-run logs are kept under `interrupted_session_evidence` in the audit folder.
