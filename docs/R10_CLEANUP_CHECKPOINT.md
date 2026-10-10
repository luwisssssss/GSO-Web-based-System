# R10 final cleanup and stabilization checkpoint

Baseline: R9 `0b022a431b53a89359c95433026a7c17f6c4ac14`.
Scope: cleanup and stabilization only. No new feature, workflow, chatbot intent or email event was added. R11 (schema freeze) and R12 (ERD) were not started.

## Backup and pre-cleanup state

- Backup: `C:\Users\ACER\gso_db_backups\gso_database_pre_r10_20261010_184052.sql`, 28,447,554 bytes, dump exit code 0.
- SHA-256: `97a383e9cd5f7fd2751375eb5efeea80c3ff1abe1bf69961e5a34a6653bd65d7`.
- Snapshots beside it: `_schema.txt` (tables, columns, keys, foreign keys, checks, generated columns, triggers) and `_rows.txt` (exact row count and checksum per table), plus the matching `post_r10` files.
- Before cleanup: 24 tables, 67 foreign keys, 118 indexes, 17 UNIQUE constraints, 10 CHECK constraints, 0 triggers.
- Live was byte-identical to the backup (every table's row count and checksum) immediately before the migration ran.

## Destructive cleanup gate (live values)

| Candidate | Evidence | Decision |
|---|---|---|
| `request_adviser_history` | 0 rows | dropped |
| `request_director_history` | 0 rows | dropped |
| `adviser_state` | 10 of 10 rows `Not Required` | dropped |
| `adviser_user_id`, `adviser_required_by`, `adviser_required_at` | 0 non-NULL | dropped |
| `director_state` | 10 of 10 rows `Not Required` | dropped |
| `director_required_by`, `director_required_at` | 0 non-NULL | dropped |
| `resource_requests.resource_id`, `quantity` | all 10 headers reproduced exactly by a `request_items` line; 0 requests without lines | dropped |

No activity log or notification in live mentions an Adviser or Director decision. The auto-increment counters of the two history tables (117 and 151) show that rows once existed and were deleted before R10; nothing recoverable remained in the database or was removed by R10.

After the migration, a hash of every surviving `resource_requests` column over all rows equals the same hash computed from a restored copy of the backup.

The gate is enforced twice. `scripts/migrate.php` runs `gsoFinalCleanupBlockers()` and stops with a per-reason list. The SQL file repeats the check and raises an error on a manual import. Both were rehearsed on a throwaway copy seeded with one history row, one non-default routing value and one unreproducible header: both refused and dropped nothing.

## Schema objects removed

- Tables: `request_adviser_history`, `request_director_history`.
- Columns of `resource_requests`: `adviser_state`, `adviser_user_id`, `adviser_required_by`, `adviser_required_at`, `director_state`, `director_required_by`, `director_required_at`, `resource_id`, `quantity`.
- Foreign keys (10): the six of the two history tables, plus `fk_rr_adviser_user`, `fk_rr_adviser_required_by`, `fk_rr_director_required_by`, `fk_rr_resource`.
- Indexes (15): the nine of the two history tables, plus `idx_rr_adviser_queue`, `fk_rr_adviser_required_by`, `idx_rr_director_queue`, `fk_rr_director_required_by`, `fk_resource_requests_resource`, `idx_requests_resource_status`.

Result: 22 tables, 57 foreign keys, 103 indexes, 17 UNIQUE constraints, 10 CHECK constraints, 3 generated columns. `docs/R10_FINAL_SCHEMA_CANDIDATE.md` lists every table for R11.

Kept on purpose: the `system_settings` row `maintenance_synced_at` (a stale marker no code reads) and the unclassified live maintenance row MTN-5.

## Code and routes removed

- Endpoints and controllers: `borrower/adviser_request.php`, `borrower/faculty_search.php`, `borrower/endorsements.php`, `borrower/endorsement_detail.php`, `director/request.php`. Nothing linked to them; they now answer 404. No tombstone was kept because a missing file cannot mutate anything and no legitimate caller exists.
- Helpers: `adviser_endorsement_helper.php`, `director_approval_helper.php` and their `includes/` shims.
- Request detail page: the "Historical Adviser Workflow" and "Historical Campus Director Workflow" sections and their joins.
- Request list and borrower list queries: unused Adviser and Director columns and the Director history subquery.
- Legacy mirror: both `INSERT` writers, `gsoSyncRequestLegacyMirror()` and `gsoPrimaryMirrorLine()`.
- CSS badge states for Adviser and Director workflow.

Classification of what remains: `legacy_schema_upgrader.php` and migrations before 007 are historical; the chatbot's "Director role" answer and README text describe the current read-only and manual-letter rules; `Faculty` remains a borrower account type.

## Runtime schema hardening

- `config/db.php` no longer calls any schema initialiser. It runs `gsoAssertSchemaCurrent()`, a single read of `system_settings.schema_version`, and answers HTTP 503 when the version is not the one the code requires.
- The former `ensureSystemSchema()` ran `ALTER TABLE`, `CREATE TABLE`, a login-attempt purge and several backfills on every page load. It is now `gsoLegacyBaselineUpgrade()` in `app/models/legacy_schema_upgrader.php`: command line only, and only for a database with no recorded version.
- `scripts/migrate.php` is the one explicit path. Empty database: loads `database/schema.sql`. Unversioned pre-R10 database: verifies the legacy baseline and records `20261010_006`. Then it applies newer migration files in order, one statement at a time, recording the version after each file.
- `database/schema.sql` is regenerated as the complete final schema with plain `CREATE TABLE`, so importing it over an existing database fails instead of mislabelling the version.
- `GSO_RUN_MIGRATIONS` is retired.
- `syncMaintenanceSchedules()` now only moves a corrective work order between Scheduled and In Progress on its start date, and refreshes only the resources whose order changed. It cannot complete or cancel anything and never touches preventive occurrences or history.

## Claim and release wording

Production code has no QR-code, Claim Slip Code or CCTV feature and no financial field. Approval text reads "Coordinate with GSO for claim/release" and release is the existing GSO action by request reference. Nothing was redesigned and no obsolete instruction was found, in code or in the 14 live knowledge-base entries.

## Navigation and rendered UI review

Pages were captured with headless Chrome against the isolated database and viewed: borrower request form, My Requests, reschedule, return submission, request letter; GSO requests, request detail, walk-in create, reschedule review, return inspection, maintenance, preventive plans, maintenance history; Director schedule; MIS accounts.

Defects found and fixed:

- Six pages (walk-in create, walk-in actions, reschedule review, borrower reschedule, request letter, return history) placed their whole body inside the dark header card, leaving labels and links dark on dark and omitting the top bar. The header card now holds only the title, and the forms use a shared `stack-form` style.
- The Director page had no sidebar. It now has one with Dashboard and Sign Out.
- The chatbot button was shown to Director and MIS, who are denied chatbot access. It is now shown only to roles with `chatbot.use`.
- The sidebar did not highlight Work Orders on the Preventive Plans and Maintenance History pages.

Not fixed, noted only: the active work-order table needs horizontal scrolling at 1366 px, and the request detail's resource summary adds stock across lines for mixed requests.

Limits: screenshots were taken at one desktop width in one browser. No mobile layout, interactive JavaScript behaviour or second browser was checked.

## Tests

Isolated database `gso_r10_test_20261010_184052`, loopback Apache on port 8100, private outbox mail transport. No production email was sent.

| Suite | Checks |
|---|---:|
| `phase1_roles_e2e.php` | 44 |
| `phase35a_combined_borrowing_e2e.php` | 74 |
| `phase35b_unit_tracking_e2e.php` | 87 |
| `phase35c_workorder_accountability_e2e.php` | 47 |
| `step6_incident_e2e.php` | 59 |
| `step7_incident_reports_e2e.php` | 25 |
| `step8_core_e2e.php` | 120 |
| `step8_login_throttle_test.php` | 22 |
| `step9_chatbot_e2e.php` | 63 |
| `step10_live_data_e2e.php` | 46 |
| `r1_return_lifecycle_e2e.php` | 62 |
| `r2a_role_director_cutover_e2e.php` | 137 |
| `r2b_manual_letter_adviser_retirement_e2e.php` | 98 |
| `r3_ampm_facility_scheduling_e2e.php` | 90 |
| `r4_rescheduling_e2e.php` | 93 |
| `r5_walkin_e2e.php` | 136 |
| `r6_damage_loss_e2e.php` | 119 |
| `r7_preventive_maintenance_e2e.php` | 243 |
| `r8_chatbot_live_inquiries_e2e.php` | 113 |
| `r9_email_notifications_e2e.php` | 136 |
| `r10_final_integration_e2e.php` | 655 |
| **21 suites** | **2,469** |

All 21 passed in one uninterrupted run on the final code. Only documentation changed afterwards.

Suite maintenance: fixtures no longer write the dropped header columns; assertions that expected the retired tables, columns, helpers or HTTP 410 tombstones now assert that the storage, files and functions are gone, that the URLs answer 404, and that no retired action changes a request. R2A rose from 129 to 137 checks and R2B from 93 to 98 for that reason. The isolated-database guard in every suite and in the test mail transport now accepts any `gso_r<N>_test_*` name.

Superseded and not run: `scripts/superseded/phase2_adviser_endorsement_e2e.php`, `scripts/superseded/phase3_director_conflict_e2e.php`.

Fresh install: `scripts/migrate.php` on an empty database applied `schema.sql` (24 statements) and produced a structure identical to migrated live. On that database R10 (655), R9 (136), R7 (242), R6 (119) and Phase 1 (44) passed. `step8_core_e2e.php` needs a pre-existing approved Admin account and therefore does not run on an empty database.

## Final live state

22 tables, 57 foreign keys. Row counts: activity_logs 177, ai_conversations 4, ai_knowledge_entries 14, ai_messages 18, borrower_liabilities 0, equipment_units 0, incident_reports 0, login_attempts 0, maintenance_schedules 1, notifications 318, preventive_maintenance_plans 0, request_attachments 0, request_item_units 0, request_items 10, request_reschedule_history 0, resource_requests 10, resources 12, return_submission_items 6, return_submission_photos 2, return_submissions 6, system_settings 4, users 6.

Every table other than `resource_requests` (columns dropped) and `system_settings` (version recorded) has the same checksum as before R10. Users: 1 Admin, 5 Borrowers (4 Approved, 1 Pending). Requests: 10 Registered (1 Cancelled, 3 Released, 6 Returned). Stock: 633 total, 625 available, 0 under repair. All orphan, duplicate, invalid-requester, invalid-stock and Lost-target checks return 0.

## Open items for the owner

- The public landing page, and so the site root, currently shows a PHP fatal error. `pages/landing.php` requires `app/controllers/pages/landing.php`, which is deleted in the protected uncommitted work; an identical copy sits untracked in `app/controllers/gso_staff/pages/`. R10 did not touch either path.
- Live has no GSO Staff, Director or Super Admin account and its Borrowers have no `account_type`. These are data states, not schema issues.

Nothing found blocks the R11 schema freeze.
