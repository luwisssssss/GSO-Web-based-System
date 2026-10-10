# R8 chatbot checkpoint

Baseline: R7 `64f4b1a3017a4931b969d50d10bc8ff064c61f4e`.
Scope: existing chatbot live availability, authenticated request status, and R1-R7 guidance. No schema migration or production data mutation was required. No historical migrations were modified. No R9 work or push was performed.

## Backup

- Path: `C:\Users\ACER\gso_db_backups\gso_database_pre_r8_20261010_172934.sql`
- Size: 14,758,078 bytes; dump exit code: 0.
- SHA-256: `0b4e19b20e224d9a9949a049d5f558a4fe920a76ccd73a69cf82becd171c8a36`.
- Backup size and checksum were verified again during final live verification. Prior backups and the R2B/pre-R3 checkpoints were preserved.

## Behavior

- Availability cues, including supported English/Tagalog wording, route to existing live adapters. Unknown, archived, and ambiguous resources produce safe clarification. Explicit quantities, dates and intervals are validated; invalid dates do not roll forward and invalid quantities do not become valid integers. Conversation follow-ups carry established values.
- Facilities use the authoritative exact interval, AM/PM classification, configured campus operating settings, shared conflicts and date-based preventive maintenance. AM/PM-only questions ask for exact times without inventing clock boundaries. Approved, Released and Return block confirmed overlap; Pending/Under Review and Pending reschedule proposals do not. After approved rescheduling, the parent request's new schedule naturally controls availability and status. Walk-in reservations also block availability. Extended hours require existing explicit GSO handling, with no Director approval.
- Equipment capacity is determined through the shared scheduling check, including requested quantity/date, repair stock, preventive holds and overlapping requests. Existing equipment scheduling priority for Pending/Under Review is preserved. Partial holds leave other eligible stock usable. Serialized capacity is bounded by eligible physical units and current availability by the existing allocation-aware unit helper; Lost/Retired/repair units are excluded. No serials, tags or internal unit identifiers are returned.
- Request status queries recheck actual account authorization and role. Ordinary callers must match borrower_id and Registered requester_type inside both single/list queries. Guessed references, forged role arrays, another borrower's requests, Walk-in requests and anonymous callers cannot bypass ownership. Existing Admin broader visibility is retained; Staff, MIS and Director gain no wider chatbot scope.
- Owned status answers show current date, period and exact times, without proposed reschedule intervals or private remarks/contact fields. Return means awaiting inspection; Returned means inspection accepted, with recorded condition/quantity outcomes. Consumable-only Completed remains a closed issued-consumable transaction.
- Default guidance covers manual signed letters, document verification separate from processing/signature, retired Adviser/Director online approvals, Director schedule read-only visibility, reschedule proposals, Return/Returned, damaged versus permanently Lost, and partial preventive maintenance. Mixed Facility + Equipment requests keep the same facility reservation period. Unsupported policy/fee questions continue through the knowledge fallback.
- Repository seed wording was updated. All 14 live knowledge entries (none marked user-edited) were preserved byte-for-byte. A narrow compatibility mapping for six known seeded workflow topics serves current rules without rewriting rows or replacing unrelated custom knowledge.
- Live operational answers use deterministic templates. The provider cannot change live quantity, status, schedule or outcomes; its existing FAQ/provider-failure fallback remains. The legacy token-check helper is retained for compatibility, but is not an authorization or live fact-preservation gate.
- Chat endpoints skip schema initialization and maintenance synchronization. Only legitimate conversation/message logging remains in the chatbot service. A real HTTP test with an overdue-to-start corrective schedule proves operational records are unchanged by chat.

## Files changed

- `app/controllers/chatbot/guard.php`
- `app/models/ai_chat_helper.php`
- `app/models/ai_intent_helper.php`
- `app/models/ai_live_data_helper.php`
- `app/models/ai_param_helper.php`
- `config/db.php`
- `scripts/r1_return_lifecycle_e2e.php`
- `scripts/r2a_role_director_cutover_e2e.php`
- `scripts/r2b_manual_letter_adviser_retirement_e2e.php`
- `scripts/r3_ampm_facility_scheduling_e2e.php`
- `scripts/r4_rescheduling_e2e.php`
- `scripts/r5_walkin_e2e.php`
- `scripts/r6_damage_loss_e2e.php`
- `scripts/r7_preventive_maintenance_e2e.php`
- `scripts/request_letter_test_helper.php`
- `scripts/seed_ai_knowledge.php`
- `scripts/step10_live_data_e2e.php`
- `scripts/r8_chatbot_live_inquiries_e2e.php`
- `docs/R8_CHATBOT_CHECKPOINT.md`

Older revision suites changed only their isolated-database allowlists. Step10's fixture/expected capacity now accounts for its two-unit released loan as well as two repair units (six available), and its privacy expectation prohibits private maintenance notes in chatbot replies, including Admin replies. No protected user files were staged.

## Active regression

All suites ran against restored isolated `gso_r8_test_20261010_173750` and loopback Apache on port 8099. The table records the final passing run of each suite after resolving failures.

| Suite | Checks | Result |
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
| `step10_live_data_e2e.php` | 46 | PASS |
| `step6_incident_e2e.php` | 59 | PASS |
| `step7_incident_reports_e2e.php` | 25 | PASS |
| `step8_core_e2e.php` | 120 | PASS |
| `step9_chatbot_e2e.php` | 63 | PASS |
| `step8_login_throttle_test.php` | 22 | PASS |

Total: **19 suites, 1,665 checks**; focused R8: **113 checks**. Phase2 Adviser endorsement and Phase3 Director approval suites remain **SUPERSEDED** and were not run.

PHP syntax checks passed for all 18 changed PHP files, and `git diff --check` passed. Static/security review confirmed ownership and Registered predicates, endpoint authentication/CSRF, no anonymous Walk-in lookup, no serialized identifiers or private maintenance notes in replies, no proposed-reschedule read as a confirmed interval, and no provider override of operational facts. Director/Adviser references now explain retired functionality; existing presentation presets do not grant permissions. Operational mutation statements are absent from live adapters, apart from existing conversation/pending-marker logging.

## Final read-only live integrity

All **24 tables** match their pre-R8 row hashes, with **67 foreign keys**. No identity, request state, schedule, inventory, maintenance row, preventive plan or AI knowledge record changed.

| Record/quantity | Verified live value |
|---|---|
| Users | 6: Admin 1, Borrower 5; Approved 5, Pending 1 |
| Resources | 12: 7 Aggregate items Available/Good; 4 facilities Available/Good; 1 facility Unavailable/Needs Repair |
| Requests/items | 10/10; all Registered: Cancelled 1, Released 3, Returned 6 |
| Facility lines | 3 legacy NULL slots, preserved |
| Attachments/reschedules | 0/0 |
| Returns/details/photos | 6/6/2; 15 returned units, 0 lost |
| Maintenance | 1 Completed, type NULL/Unclassified, preserved |
| Preventive plans/liabilities | 0/0 |
| Serialized units/allocations | 0/0 |
| AI conversations/messages/knowledge | 4/18/14 |
| Inventory totals | 633 total, 625 available, 0 repair |
| Settings | 4, unchanged |

Checked line, return, maintenance, liability, preventive-plan/occurrence orphans and duplicate open allocations: **0**. No invalid stock, Lost-in-repair, Lost work order, overapplied return or unapproved stock application was detected. Historical Adviser/Director history tables remain unchanged and empty; destructive cleanup is deferred.

Audit evidence: `C:\Users\ACER\AppData\Local\Temp\GSO_R8_20261010_172933` (`active_regression.json`, individual suite logs, `live_integrity.json`). The final response records the commit hash and repository status. Protected landing deletion and untracked GSO Staff pages remain outside the commit.

R9 expanded transactional email notifications, destructive schema cleanup, schema freeze and final ERD were not started.
