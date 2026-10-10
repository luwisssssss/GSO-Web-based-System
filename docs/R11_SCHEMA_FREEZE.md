# R11 final schema freeze

**SCHEMA FREEZE: PASSED**

Frozen implementation: `62fdff1c40391d247e7741536fd397c7832a07f8`. Captured 2026-10-10T22:35:35+08:00. No production code, schema.sql, migration, live schema or live row was changed. No ERD was generated. The machine-readable companion retains exact metadata, queries and SHOW CREATE TABLE definitions; it contains no user/password/token values.

## Verified counts and versions

| Measure | Actual |
|---|---:|
| tables | 22 |
| foreign keys | 57 |
| indexes | 104 |
| primary keys | 22 |
| unique constraints | 18 |
| check constraints | 11 |
| generated columns | 3 |
| ordinary indexes | 64 |

All four sources agree: live metadata, a fresh database created from `database/schema.sql`, migration/application schema version, and `docs/R10_FINAL_SCHEMA_CANDIDATE.md`. Live/fresh/required version is `20261010_008`; latest migration is `20261010_008_claim_slip_code.sql`. All 23 migration identifiers are unique and ordered; none is newer than the recorded version. Schema import executed 24 statements in an isolated empty database.

Fresh/live comparison includes exact columns, order, types, nullability/defaults, collations/comments, PKs, FKs and update/delete rules, all indexes, UNIQUEs, CHECKs and generated expressions. Only current AUTO_INCREMENT counters and actual rows were excluded. The candidate agrees with all 275 columns, 22 PKs, 57 FKs, 18 UNIQUEs, 64 ordinary indexes, 11 CHECKs and 3 generated expressions. No discrepancy was found.

Schema SQL SHA-256: `dd9ba9405031581b0fcbad1b1a39da1db7d5bb67f7964c40b0b21f9d0f098662`. Candidate SHA-256: `396d96309aa16e5d28cd879f23750e3e3711e33866113fc75bfa18c5c40fa86f`.

Ordinary web requests only assert the current version. `config/db.php` does not invoke schema migration; `schema_migrator.php` is restricted to CLI. Its functions were used only to import the reviewed schema into the isolated database, not to migrate live.

## Complete ordered inventory and module grouping

| Table | Module | Exact live rows | Engine | Collation | PK, in order | Current AUTO_INCREMENT |
|---|---|---:|---|---|---|---|
| `activity_logs` | Notifications / Audit | 177 | InnoDB | utf8mb4_unicode_ci | `log_id` | 5011 |
| `ai_conversations` | AI Chatbot | 4 | InnoDB | utf8mb4_unicode_ci | `conversation_id` | 1706 |
| `ai_knowledge_entries` | AI Chatbot | 14 | InnoDB | utf8mb4_unicode_ci | `entry_id` | 82 |
| `ai_messages` | AI Chatbot | 18 | InnoDB | utf8mb4_unicode_ci | `message_id` | 4162 |
| `borrower_liabilities` | Maintenance / Accountability | 0 | InnoDB | utf8mb4_general_ci | `liability_id` | 21 |
| `equipment_units` | Inventory / Unit Tracking | 0 | InnoDB | utf8mb4_unicode_ci | `unit_id` | 91 |
| `incident_reports` | Maintenance / Accountability | 0 | InnoDB | utf8mb4_general_ci | `incident_id` | 464 |
| `login_attempts` | Identity / Security | 0 | InnoDB | utf8mb4_general_ci | `attempt_id` | 427 |
| `maintenance_schedules` | Maintenance / Accountability | 1 | InnoDB | utf8mb4_general_ci | `maintenance_id` | 259 |
| `notifications` | Notifications / Audit | 318 | InnoDB | utf8mb4_unicode_ci | `notification_id` | 4912 |
| `preventive_maintenance_plans` | Maintenance / Accountability | 0 | InnoDB | utf8mb4_unicode_ci | `plan_id` | 1 |
| `request_attachments` | Requests / Borrowing | 0 | InnoDB | utf8mb4_unicode_ci | `attachment_id` | 1 |
| `request_items` | Requests / Borrowing | 10 | InnoDB | utf8mb4_unicode_ci | `request_item_id` | 1039 |
| `request_item_units` | Inventory / Unit Tracking | 0 | InnoDB | utf8mb4_unicode_ci | `allocation_id` | 53 |
| `request_reschedule_history` | Requests / Borrowing | 0 | InnoDB | utf8mb4_unicode_ci | `reschedule_id` | 1 |
| `resources` | Inventory / Unit Tracking | 12 | InnoDB | utf8mb4_unicode_ci | `resource_id` | 754 |
| `resource_requests` | Requests / Borrowing | 10 | InnoDB | utf8mb4_unicode_ci | `request_id` | 1448 |
| `return_submissions` | Return / Inspection | 6 | InnoDB | utf8mb4_unicode_ci | `return_id` | 297 |
| `return_submission_items` | Return / Inspection | 6 | InnoDB | utf8mb4_unicode_ci | `return_item_id` | 163 |
| `return_submission_photos` | Return / Inspection | 2 | InnoDB | utf8mb4_unicode_ci | `photo_id` | 69 |
| `system_settings` | Configuration | 4 | InnoDB | utf8mb4_general_ci | `setting_key` | none |
| `users` | Identity / Security | 6 | InnoDB | utf8mb4_unicode_ci | `user_id` | 1416 |

Each table belongs to exactly one module. Module placement is documentation only, not an extra table or inferred foreign key.

## Architecture confirmations

- `resource_requests` references users through nullable `borrower_id` and operational actor columns. `request_items.request_id` is the physical child link, and `request_items.resource_id` references resources. The removed header `resource_id` and `quantity` do not exist; line storage is authoritative. Physical allocation links are `request_item_units.request_item_id` and `request_item_units.unit_id` to `equipment_units`.
- Walk-in mode supports NULL `borrower_id`, request-scoped `walkin_full_name`, `walkin_organization`, `walkin_email`, `walkin_contact_number`, and `encoded_by`; Registered mode is constrained separately. Exact types/nullability/FKs are listed below.
- Request letters use `request_attachments` with request, uploader and verifier FKs, versions, file metadata and verification state. There is no current Adviser electronic approval structure.
- Reschedule history retains old/new dates, exact times and AM/PM, requester/encoder/reviewer and status. A generated Pending request key is unique. Request headers keep the authoritative current schedule.
- Return headers/details/photos support a Registered borrower or GSO-encoded Walk-in submission, Facility evidence, aggregate quantities and serialized unit references. Exact conditions include Good, Damaged, Missing Parts, Needs Repair and Lost. Lost quantity is separately stored; accepted settlement is tracked with `stock_applied`.
- Maintenance schedules reference resources, units, return headers/details, incidents, preventive plans and actors. `maintenance_type` is nullable `enum('Corrective','Preventive')`. The one historical NULL/Unclassified row is valid data, not a missing schema object. Plans store recurrence/target policy; schedules are also the maintenance history.
- `borrower_liabilities` references request/detail, nullable borrower, optional unit and reviewer. Its type/status definitions are below. It has no financial amount, fee, payment or billing fields.
- Notifications and activity logs reference users; R9 uses activity logs operationally for email diagnostics. There is no separate email-delivery or Claim Slip table.
- AI ownership uses users -> ai_conversations -> ai_messages; knowledge creator/editor references are optional user FKs. Static knowledge being non-authoritative for live availability is application behavior, not an ERD relationship.
- Final users.role is `enum('Admin','Borrower','GSO Staff','Director','Super Admin')`. Adviser is absent from that ENUM.

Relationships without their own physical FK: generated `facility_guard`, `open_unit_guard` and `pending_request_id` derive IDs from already-FK-constrained base columns and act as UNIQUE guards. `login_attempts.attempt_key` is a hashed security key, not a users FK. Notification `link` and activity `details` may carry public references in text; no relational request FK is asserted for those strings. The exact physical FK inventory below is exhaustive.

## Claim Slip freeze

`resource_requests.claim_slip_code` is nullable `varchar(20)` with UNIQUE index `uq_requests_claim_slip_code` and CHECK `chk_requests_claim_slip`. Its exact SQL expression and index participation are frozen below. Approved requires a code; Pending/Under Review require NULL; the actual condition permits legacy NULL codes in other states. Live non-NULL code count is 0. No code was generated or backfilled by R11.

## Confirmed removed objects

Absent tables: `request_adviser_history`, `request_director_history`.

Absent resource_requests columns: `adviser_state`, `adviser_user_id`, `adviser_required_by`, `adviser_required_at`, `director_state`, `director_required_by`, `director_required_at`, `resource_id`, `quantity`. No equivalent obsolete approval/storage column was found in its complete inventory. Historical forward migration files remain as migration history; their retired objects are absent from live and the fresh schema.

## Current settings rows, separate from schema

| Key | Live value |
|---|---|
| `facility_operating_end` | `17:00` |
| `facility_operating_start` | `08:00` |
| `maintenance_synced_at` | `2026-09-13 05:26:07` |
| `schema_version` | `20261010_008` |

The stale `maintenance_synced_at` row is preserved. It is a configuration DATA row, not a table column.

## Exact table definitions

SQL types below retain lengths, precision and unsigned markers. Default `none` means information_schema reported no default; SQL `NULL` is retained explicitly. Precision/scale and charset/collation are also retained in JSON. AUTO_INCREMENT counters above are snapshot observations, not part of the fresh/live structural comparison. ENUM values are preserved verbatim in each SQL type.

### `activity_logs`

Optional user-linked operational audit, including R9 event/delivery markers; no separate email table.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `log_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `log_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `user_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 3 | `action` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `details` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `log_date` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_al_user`: (`user_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_activity_logs_user_date` | ordinary | `user_id`, `log_date` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `log_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `log_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_activity_logs_user_date` (`user_id`,`log_date`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5011 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `ai_conversations`

Account-owned chatbot threads.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `conversation_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `conversation_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `user_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `title` | `varchar(160)` | NO | `'New conversation'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `status` | `enum('Active','Archived')` | NO | `'Active'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `started_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 6 | `ended_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 8 | `updated_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_ai_conversations_user`: (`user_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_ai_conversations_user` | ordinary | `user_id`, `status`, `updated_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `conversation_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `ai_conversations` (
  `conversation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL DEFAULT 'New conversation',
  `status` enum('Active','Archived') NOT NULL DEFAULT 'Active',
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ended_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`conversation_id`),
  KEY `idx_ai_conversations_user` (`user_id`,`status`,`updated_at`),
  CONSTRAINT `fk_ai_conversations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1706 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `ai_knowledge_entries`

Procedural knowledge with optional creator/editor actors; live availability authority remains application behavior.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `entry_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `entry_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `title` | `varchar(160)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 3 | `category` | `varchar(60)` | NO | `'General'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `question` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `content` | `text` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `keywords` | `varchar(500)` | NO | `''` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `status` | `enum('Active','Inactive')` | NO | `'Active'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `created_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 9 | `updated_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 10 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 11 | `updated_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_ai_knowledge_created_by`: (`created_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ai_knowledge_updated_by`: (`updated_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_ai_knowledge_created_by` | ordinary | `created_by` | `BTREE` |  |
| `fk_ai_knowledge_updated_by` | ordinary | `updated_by` | `BTREE` |  |
| `idx_ai_knowledge_status` | ordinary | `status`, `category` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `entry_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `ai_knowledge_entries` (
  `entry_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(160) NOT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'General',
  `question` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `keywords` varchar(500) NOT NULL DEFAULT '',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`entry_id`),
  KEY `idx_ai_knowledge_status` (`status`,`category`),
  KEY `fk_ai_knowledge_created_by` (`created_by`),
  KEY `fk_ai_knowledge_updated_by` (`updated_by`),
  CONSTRAINT `fk_ai_knowledge_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ai_knowledge_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `ai_messages`

Messages owned through their parent conversation.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `message_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `message_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `conversation_id` | `bigint(20)` | NO | none | `` | `- / -` | `` |
| 3 | `sender_type` | `enum('user','assistant','system')` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `message` | `mediumtext` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `intent` | `varchar(60)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_ai_messages_conversation`: (`conversation_id`) -> `ai_conversations` (`conversation_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_ai_messages_conversation` | ordinary | `conversation_id`, `message_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `message_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `ai_messages` (
  `message_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) NOT NULL,
  `sender_type` enum('user','assistant','system') NOT NULL,
  `message` mediumtext NOT NULL,
  `intent` varchar(60) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`),
  KEY `idx_ai_messages_conversation` (`conversation_id`,`message_id`),
  CONSTRAINT `fk_ai_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`conversation_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4162 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `borrower_liabilities`

Non-financial accountability review, linked request/detail/unit, nullable borrower and reviewer.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `liability_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `liability_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `return_item_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 4 | `borrower_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 5 | `equipment_unit_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 6 | `accountability_type` | `enum('Damage','Loss','Missing Parts')` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 7 | `accountability_status` | `enum('Pending Review','Confirmed','Waived','Resolved')` | NO | `'Pending Review'` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 8 | `reviewed_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 9 | `reviewed_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 10 | `resolution_notes` | `varchar(1000)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 11 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 12 | `updated_at` | `datetime` | NO | `current_timestamp()` | `on update current_timestamp()` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_liability_borrower`: (`borrower_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_liability_request`: (`request_id`) -> `resource_requests` (`request_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_liability_return_item`: (`return_item_id`) -> `return_submission_items` (`return_item_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_liability_reviewer`: (`reviewed_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_liability_unit`: (`equipment_unit_id`) -> `equipment_units` (`unit_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_liability_reviewer` | ordinary | `reviewed_by` | `BTREE` |  |
| `fk_liability_unit` | ordinary | `equipment_unit_id` | `BTREE` |  |
| `idx_liability_borrower` | ordinary | `borrower_id`, `accountability_status` | `BTREE` |  |
| `idx_liability_request` | ordinary | `request_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `liability_id` | `BTREE` |  |
| `uq_liability_return_item` | UNIQUE | `return_item_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `borrower_liabilities` (
  `liability_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `return_item_id` bigint(20) DEFAULT NULL,
  `borrower_id` int(11) DEFAULT NULL,
  `equipment_unit_id` bigint(20) DEFAULT NULL,
  `accountability_type` enum('Damage','Loss','Missing Parts') NOT NULL,
  `accountability_status` enum('Pending Review','Confirmed','Waived','Resolved') NOT NULL DEFAULT 'Pending Review',
  `reviewed_by` int(11) NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolution_notes` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`liability_id`),
  UNIQUE KEY `uq_liability_return_item` (`return_item_id`),
  KEY `idx_liability_borrower` (`borrower_id`,`accountability_status`),
  KEY `idx_liability_request` (`request_id`),
  KEY `fk_liability_unit` (`equipment_unit_id`),
  KEY `fk_liability_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_liability_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_liability_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`),
  CONSTRAINT `fk_liability_return_item` FOREIGN KEY (`return_item_id`) REFERENCES `return_submission_items` (`return_item_id`),
  CONSTRAINT `fk_liability_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_liability_unit` FOREIGN KEY (`equipment_unit_id`) REFERENCES `equipment_units` (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### `equipment_units`

Physical serialized inventory, status/condition and registrar.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `unit_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `unit_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `resource_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `asset_tag` | `varchar(60)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `serial_number` | `varchar(120)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `status` | `enum('Available','Released','Under Repair','Lost','Retired')` | NO | `'Available'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `condition_status` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | NO | `'Good'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `unit_notes` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `acquired_at` | `date` | YES | `NULL` | `` | `- / -` | `` |
| 9 | `registered_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 10 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 11 | `updated_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_equipment_units_registrar`: (`registered_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_equipment_units_resource`: (`resource_id`) -> `resources` (`resource_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_equipment_units_registrar` | ordinary | `registered_by` | `BTREE` |  |
| `idx_equipment_units_resource` | ordinary | `resource_id`, `status` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `unit_id` | `BTREE` |  |
| `uq_equipment_units_asset_tag` | UNIQUE | `asset_tag` | `BTREE` |  |
| `uq_equipment_units_serial` | UNIQUE | `serial_number` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `equipment_units` (
  `unit_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `asset_tag` varchar(60) NOT NULL,
  `serial_number` varchar(120) DEFAULT NULL,
  `status` enum('Available','Released','Under Repair','Lost','Retired') NOT NULL DEFAULT 'Available',
  `condition_status` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') NOT NULL DEFAULT 'Good',
  `unit_notes` varchar(500) DEFAULT NULL,
  `acquired_at` date DEFAULT NULL,
  `registered_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `uq_equipment_units_asset_tag` (`asset_tag`),
  UNIQUE KEY `uq_equipment_units_serial` (`serial_number`),
  KEY `idx_equipment_units_resource` (`resource_id`,`status`),
  KEY `fk_equipment_units_registrar` (`registered_by`),
  CONSTRAINT `fk_equipment_units_registrar` FOREIGN KEY (`registered_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_equipment_units_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`)
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `incident_reports`

Incident reporting, resource association, assignment, resolution and reporter.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `incident_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `incident_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `reporter_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `resource_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 4 | `incident_title` | `varchar(150)` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 5 | `incident_type` | `enum('Furniture','Equipment','Electrical','Door/Window','Plumbing','Facility','Safety','Other')` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 6 | `location` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 7 | `description` | `text` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 8 | `photo_path` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 9 | `priority` | `enum('Low','Normal','High','Urgent')` | NO | `'Normal'` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 10 | `status` | `enum('Submitted','Under Review','In Progress','Resolved','Rejected')` | NO | `'Submitted'` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 11 | `assigned_to` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 12 | `admin_remarks` | `text` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 13 | `resolution_notes` | `text` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 14 | `reported_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 15 | `updated_at` | `datetime` | NO | `current_timestamp()` | `on update current_timestamp()` | `- / -` | `` |
| 16 | `resolved_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_incident_reports_assigned_to`: (`assigned_to`) -> `users` (`user_id`); ON UPDATE CASCADE; ON DELETE SET NULL.
- `fk_incident_reports_reporter`: (`reporter_id`) -> `users` (`user_id`); ON UPDATE CASCADE; ON DELETE RESTRICT.
- `fk_incident_reports_resource`: (`resource_id`) -> `resources` (`resource_id`); ON UPDATE CASCADE; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_incident_reports_assignee_status` | ordinary | `assigned_to`, `status`, `updated_at` | `BTREE` |  |
| `idx_incident_reports_queue` | ordinary | `status`, `priority`, `reported_at` | `BTREE` |  |
| `idx_incident_reports_reported_at` | ordinary | `reported_at` | `BTREE` |  |
| `idx_incident_reports_reporter_date` | ordinary | `reporter_id`, `reported_at` | `BTREE` |  |
| `idx_incident_reports_resource_status_date` | ordinary | `resource_id`, `status`, `reported_at` | `BTREE` |  |
| `idx_incident_reports_type_date` | ordinary | `incident_type`, `reported_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `incident_id` | `BTREE` |  |
| `uq_incident_reports_photo_path` | UNIQUE | `photo_path` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `incident_reports` (
  `incident_id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter_id` int(11) NOT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `incident_title` varchar(150) NOT NULL,
  `incident_type` enum('Furniture','Equipment','Electrical','Door/Window','Plumbing','Facility','Safety','Other') NOT NULL,
  `location` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `priority` enum('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  `status` enum('Submitted','Under Review','In Progress','Resolved','Rejected') NOT NULL DEFAULT 'Submitted',
  `assigned_to` int(11) DEFAULT NULL,
  `admin_remarks` text DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  `reported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`incident_id`),
  UNIQUE KEY `uq_incident_reports_photo_path` (`photo_path`),
  KEY `idx_incident_reports_reporter_date` (`reporter_id`,`reported_at`),
  KEY `idx_incident_reports_resource_status_date` (`resource_id`,`status`,`reported_at`),
  KEY `idx_incident_reports_queue` (`status`,`priority`,`reported_at`),
  KEY `idx_incident_reports_assignee_status` (`assigned_to`,`status`,`updated_at`),
  KEY `idx_incident_reports_type_date` (`incident_type`,`reported_at`),
  KEY `idx_incident_reports_reported_at` (`reported_at`),
  CONSTRAINT `fk_incident_reports_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_incident_reports_reporter` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_incident_reports_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=464 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### `login_attempts`

Hashed login-throttle keys; deliberately no physical user foreign key.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `attempt_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `attempt_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `attempt_key` | `varchar(190)` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 3 | `attempted_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

None.


All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_login_attempts_key_time` | ordinary | `attempt_key`, `attempted_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `attempt_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attempt_key` varchar(190) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_key_time` (`attempt_key`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=427 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### `maintenance_schedules`

Corrective/preventive work-order and history records, resource/unit/return/incident/plan references and operational actors.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `maintenance_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `maintenance_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `resource_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 4 | `source_return_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 5 | `source_return_item_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 6 | `incident_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `equipment_unit_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 8 | `assigned_to` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 9 | `start_date` | `date` | NO | none | `` | `- / -` | `` |
| 10 | `end_date` | `date` | NO | none | `` | `- / -` | `` |
| 11 | `duration_days` | `int(11)` | NO | `1` | `` | `- / -` | `` |
| 12 | `reason` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 13 | `remarks` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 14 | `completion_notes` | `varchar(1000)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 15 | `status` | `enum('Scheduled','In Progress','Completed','Cancelled')` | NO | `'Scheduled'` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 16 | `created_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 17 | `updated_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 18 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 19 | `updated_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 20 | `completed_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 21 | `restored_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 22 | `maintenance_type` | `enum('Corrective','Preventive')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 23 | `preventive_plan_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 24 | `plan_occurrence_date` | `date` | YES | `NULL` | `` | `- / -` | `` |
| 25 | `started_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_maintenance_plan`: (`preventive_plan_id`) -> `preventive_maintenance_plans` (`plan_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_ms_assigned_to`: (`assigned_to`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ms_created_by`: (`created_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_ms_equipment_unit`: (`equipment_unit_id`) -> `equipment_units` (`unit_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ms_incident`: (`incident_id`) -> `incident_reports` (`incident_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ms_resource`: (`resource_id`) -> `resources` (`resource_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_ms_source_return`: (`source_return_id`) -> `return_submissions` (`return_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ms_source_return_item`: (`source_return_item_id`) -> `return_submission_items` (`return_item_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_ms_updated_by`: (`updated_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_ms_created_by` | ordinary | `created_by` | `BTREE` |  |
| `fk_ms_updated_by` | ordinary | `updated_by` | `BTREE` |  |
| `idx_maintenance_dates` | ordinary | `start_date`, `end_date` | `BTREE` |  |
| `idx_maintenance_resource` | ordinary | `resource_id` | `BTREE` |  |
| `idx_maintenance_status` | ordinary | `status` | `BTREE` |  |
| `idx_ms_assigned_to` | ordinary | `assigned_to` | `BTREE` |  |
| `idx_ms_equipment_unit_id` | ordinary | `equipment_unit_id` | `BTREE` |  |
| `idx_ms_incident_id` | ordinary | `incident_id` | `BTREE` |  |
| `idx_ms_source_return_id` | ordinary | `source_return_id` | `BTREE` |  |
| `idx_ms_source_return_item_id` | ordinary | `source_return_item_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `maintenance_id` | `BTREE` |  |
| `uq_preventive_occurrence` | UNIQUE | `preventive_plan_id`, `plan_occurrence_date` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_preventive_origin`

```sql
`maintenance_type` is null or `maintenance_type` <> 'Preventive' or `preventive_plan_id` is not null and `plan_occurrence_date` is not null
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `maintenance_schedules` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `source_return_id` int(11) DEFAULT NULL,
  `source_return_item_id` bigint(20) DEFAULT NULL,
  `incident_id` int(11) DEFAULT NULL,
  `equipment_unit_id` bigint(20) DEFAULT NULL,
  `assigned_to` int(11) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 1,
  `reason` varchar(255) NOT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `completion_notes` varchar(1000) DEFAULT NULL,
  `status` enum('Scheduled','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `maintenance_type` enum('Corrective','Preventive') DEFAULT NULL,
  `preventive_plan_id` bigint(20) DEFAULT NULL,
  `plan_occurrence_date` date DEFAULT NULL,
  `started_at` datetime DEFAULT NULL,
  PRIMARY KEY (`maintenance_id`),
  UNIQUE KEY `uq_preventive_occurrence` (`preventive_plan_id`,`plan_occurrence_date`),
  KEY `idx_maintenance_resource` (`resource_id`),
  KEY `idx_maintenance_status` (`status`),
  KEY `idx_maintenance_dates` (`start_date`,`end_date`),
  KEY `fk_ms_created_by` (`created_by`),
  KEY `fk_ms_updated_by` (`updated_by`),
  KEY `idx_ms_assigned_to` (`assigned_to`),
  KEY `idx_ms_incident_id` (`incident_id`),
  KEY `idx_ms_source_return_id` (`source_return_id`),
  KEY `idx_ms_source_return_item_id` (`source_return_item_id`),
  KEY `idx_ms_equipment_unit_id` (`equipment_unit_id`),
  CONSTRAINT `fk_maintenance_plan` FOREIGN KEY (`preventive_plan_id`) REFERENCES `preventive_maintenance_plans` (`plan_id`),
  CONSTRAINT `fk_ms_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_ms_equipment_unit` FOREIGN KEY (`equipment_unit_id`) REFERENCES `equipment_units` (`unit_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_incident` FOREIGN KEY (`incident_id`) REFERENCES `incident_reports` (`incident_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`),
  CONSTRAINT `fk_ms_source_return` FOREIGN KEY (`source_return_id`) REFERENCES `return_submissions` (`return_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_source_return_item` FOREIGN KEY (`source_return_item_id`) REFERENCES `return_submission_items` (`return_item_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_preventive_origin` CHECK (`maintenance_type` is null or `maintenance_type` <> 'Preventive' or `preventive_plan_id` is not null and `plan_occurrence_date` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=259 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### `notifications`

Account-owned in-app mailbox; Walk-in clients have no fake account recipient.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `notification_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `notification_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `user_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `type` | `varchar(50)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `title` | `varchar(120)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `message` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `link` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `is_read` | `tinyint(1)` | NO | `0` | `` | `- / -` | `` |
| 8 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_notif_user`: (`user_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_notifications_user_read_date` | ordinary | `user_id`, `is_read`, `created_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `notification_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(120) NOT NULL,
  `message` varchar(255) NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user_read_date` (`user_id`,`is_read`,`created_at`),
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4912 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `preventive_maintenance_plans`

Recurring preventive policy target, quantity, cadence, due date and actors.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `plan_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `plan_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `resource_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `equipment_unit_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 4 | `quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 5 | `title` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `recurrence` | `enum('Once','Monthly','Quarterly','Semiannual','Annual')` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `next_due_date` | `date` | NO | none | `` | `- / -` | `` |
| 8 | `anchor_day` | `tinyint(3) unsigned` | NO | none | `` | `- / -` | `` |
| 9 | `duration_days` | `int(11)` | NO | `1` | `` | `- / -` | `` |
| 10 | `assigned_to` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 11 | `is_active` | `tinyint(1)` | NO | `1` | `` | `- / -` | `` |
| 12 | `revision` | `int(11)` | NO | `1` | `` | `- / -` | `` |
| 13 | `created_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 14 | `updated_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 15 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 16 | `updated_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_plan_assignee`: (`assigned_to`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_plan_creator`: (`created_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_plan_resource`: (`resource_id`) -> `resources` (`resource_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_plan_unit`: (`equipment_unit_id`) -> `equipment_units` (`unit_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_plan_updater`: (`updated_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_plan_assignee` | ordinary | `assigned_to` | `BTREE` |  |
| `fk_plan_creator` | ordinary | `created_by` | `BTREE` |  |
| `fk_plan_resource` | ordinary | `resource_id` | `BTREE` |  |
| `fk_plan_unit` | ordinary | `equipment_unit_id` | `BTREE` |  |
| `fk_plan_updater` | ordinary | `updated_by` | `BTREE` |  |
| `idx_preventive_due` | ordinary | `is_active`, `next_due_date` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `plan_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_plan_values`

```sql
`quantity` >= 0 and `duration_days` between 1 and 365 and `anchor_day` between 1 and 31 and `revision` >= 1 and `is_active` in (0,1)
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `preventive_maintenance_plans` (
  `plan_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `equipment_unit_id` bigint(20) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `title` varchar(255) NOT NULL,
  `recurrence` enum('Once','Monthly','Quarterly','Semiannual','Annual') NOT NULL,
  `next_due_date` date NOT NULL,
  `anchor_day` tinyint(3) unsigned NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 1,
  `assigned_to` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `revision` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`plan_id`),
  KEY `idx_preventive_due` (`is_active`,`next_due_date`),
  KEY `fk_plan_resource` (`resource_id`),
  KEY `fk_plan_unit` (`equipment_unit_id`),
  KEY `fk_plan_assignee` (`assigned_to`),
  KEY `fk_plan_creator` (`created_by`),
  KEY `fk_plan_updater` (`updated_by`),
  CONSTRAINT `fk_plan_assignee` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_plan_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_plan_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`),
  CONSTRAINT `fk_plan_unit` FOREIGN KEY (`equipment_unit_id`) REFERENCES `equipment_units` (`unit_id`),
  CONSTRAINT `fk_plan_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_plan_values` CHECK (`quantity` >= 0 and `duration_days` between 1 and 365 and `anchor_day` between 1 and 31 and `revision` >= 1 and `is_active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `request_attachments`

Versioned manually signed request-letter file metadata, uploader and verification actor.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `attachment_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `attachment_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `uploaded_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 4 | `attachment_type` | `enum('Facility Request Letter')` | NO | `'Facility Request Letter'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `version_no` | `int(10) unsigned` | NO | none | `` | `- / -` | `` |
| 6 | `original_file_name` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `stored_file_name` | `varchar(80)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `mime_type` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 9 | `file_size` | `int(10) unsigned` | NO | none | `` | `- / -` | `` |
| 10 | `verification_status` | `enum('Pending','Verified','Rejected')` | NO | `'Pending'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 11 | `verified_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 12 | `verified_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 13 | `verification_remarks` | `varchar(1000)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 14 | `uploaded_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_request_attachment_request`: (`request_id`) -> `resource_requests` (`request_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_request_attachment_reviewer`: (`verified_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_request_attachment_uploader`: (`uploaded_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_request_attachment_reviewer` | ordinary | `verified_by` | `BTREE` |  |
| `idx_request_attachment_uploader` | ordinary | `uploaded_by` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `attachment_id` | `BTREE` |  |
| `uq_request_attachment_file` | UNIQUE | `stored_file_name` | `BTREE` |  |
| `uq_request_attachment_version` | UNIQUE | `request_id`, `attachment_type`, `version_no` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `request_attachments` (
  `attachment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `attachment_type` enum('Facility Request Letter') NOT NULL DEFAULT 'Facility Request Letter',
  `version_no` int(10) unsigned NOT NULL,
  `original_file_name` varchar(255) NOT NULL,
  `stored_file_name` varchar(80) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `verification_status` enum('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verification_remarks` varchar(1000) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  UNIQUE KEY `uq_request_attachment_version` (`request_id`,`attachment_type`,`version_no`),
  UNIQUE KEY `uq_request_attachment_file` (`stored_file_name`),
  KEY `idx_request_attachment_uploader` (`uploaded_by`),
  KEY `idx_request_attachment_reviewer` (`verified_by`),
  CONSTRAINT `fk_request_attachment_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`),
  CONSTRAINT `fk_request_attachment_reviewer` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_request_attachment_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `request_items`

Authoritative resource lines, line kind, quantities and optional AM/PM classification.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `request_item_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `request_item_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `resource_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 4 | `line_kind` | `enum('Facility','Equipment','Consumable')` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `facility_slot` | `enum('AM','PM')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `requested_quantity` | `int(11)` | NO | `1` | `` | `- / -` | `` |
| 7 | `released_quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 8 | `returned_quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 9 | `issued_quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 10 | `line_notes` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 11 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 12 | `facility_guard` | `int(11)` | YES | `NULL` | `STORED GENERATED` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_request_items_request`: (`request_id`) -> `resource_requests` (`request_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.
- `fk_request_items_resource`: (`resource_id`) -> `resources` (`resource_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_request_items_kind` | ordinary | `request_id`, `line_kind` | `BTREE` |  |
| `idx_request_items_resource` | ordinary | `resource_id`, `request_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `request_item_id` | `BTREE` |  |
| `uq_request_items_one_facility` | UNIQUE | `facility_guard` | `BTREE` | `facility_guard` |
| `uq_request_items_resource` | UNIQUE | `request_id`, `resource_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_request_items_quantities`

```sql
`requested_quantity` >= 1 and `released_quantity` >= 0 and `released_quantity` <= `requested_quantity` and `returned_quantity` >= 0 and `returned_quantity` <= `released_quantity` and `issued_quantity` >= 0 and `issued_quantity` <= `requested_quantity`
```

`chk_request_item_facility_slot`

```sql
`facility_slot` is null or `line_kind` = 'Facility'
```

Generated columns:

`facility_guard` `int(11)`; STORED GENERATED; indexes `uq_request_items_one_facility`.

```sql
case when `line_kind` = 'Facility' then `request_id` end
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `request_items` (
  `request_item_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `line_kind` enum('Facility','Equipment','Consumable') NOT NULL,
  `facility_slot` enum('AM','PM') DEFAULT NULL,
  `requested_quantity` int(11) NOT NULL DEFAULT 1,
  `released_quantity` int(11) NOT NULL DEFAULT 0,
  `returned_quantity` int(11) NOT NULL DEFAULT 0,
  `issued_quantity` int(11) NOT NULL DEFAULT 0,
  `line_notes` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `facility_guard` int(11) GENERATED ALWAYS AS (case when `line_kind` = 'Facility' then `request_id` end) STORED,
  PRIMARY KEY (`request_item_id`),
  UNIQUE KEY `uq_request_items_resource` (`request_id`,`resource_id`),
  UNIQUE KEY `uq_request_items_one_facility` (`facility_guard`),
  KEY `idx_request_items_resource` (`resource_id`,`request_id`),
  KEY `idx_request_items_kind` (`request_id`,`line_kind`),
  CONSTRAINT `fk_request_items_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_request_items_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`),
  CONSTRAINT `chk_request_items_quantities` CHECK (`requested_quantity` >= 1 and `released_quantity` >= 0 and `released_quantity` <= `requested_quantity` and `returned_quantity` >= 0 and `returned_quantity` <= `released_quantity` and `issued_quantity` >= 0 and `issued_quantity` <= `requested_quantity`),
  CONSTRAINT `chk_request_item_facility_slot` CHECK (`facility_slot` is null or `line_kind` = 'Facility')
) ENGINE=InnoDB AUTO_INCREMENT=1039 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `request_item_units`

Serialized unit allocation history with one-open-allocation uniqueness guard.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `allocation_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `allocation_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_item_id` | `bigint(20)` | NO | none | `` | `- / -` | `` |
| 3 | `unit_id` | `bigint(20)` | NO | none | `` | `- / -` | `` |
| 4 | `allocated_by` | `int(11)` | NO | none | `` | `- / -` | `` |
| 5 | `allocated_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 6 | `released_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `returned_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 8 | `open_unit_guard` | `bigint(20)` | YES | `NULL` | `STORED GENERATED` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_request_item_units_actor`: (`allocated_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_request_item_units_line`: (`request_item_id`) -> `request_items` (`request_item_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.
- `fk_request_item_units_unit`: (`unit_id`) -> `equipment_units` (`unit_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_request_item_units_actor` | ordinary | `allocated_by` | `BTREE` |  |
| `idx_request_item_units_unit` | ordinary | `unit_id`, `returned_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `allocation_id` | `BTREE` |  |
| `uq_request_item_units_open` | UNIQUE | `open_unit_guard` | `BTREE` | `open_unit_guard` |
| `uq_request_item_units_pair` | UNIQUE | `request_item_id`, `unit_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Generated columns:

`open_unit_guard` `bigint(20)`; STORED GENERATED; indexes `uq_request_item_units_open`.

```sql
case when `returned_at` is null then `unit_id` end
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `request_item_units` (
  `allocation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_item_id` bigint(20) NOT NULL,
  `unit_id` bigint(20) NOT NULL,
  `allocated_by` int(11) NOT NULL,
  `allocated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `released_at` datetime DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `open_unit_guard` bigint(20) GENERATED ALWAYS AS (case when `returned_at` is null then `unit_id` end) STORED,
  PRIMARY KEY (`allocation_id`),
  UNIQUE KEY `uq_request_item_units_pair` (`request_item_id`,`unit_id`),
  UNIQUE KEY `uq_request_item_units_open` (`open_unit_guard`),
  KEY `idx_request_item_units_unit` (`unit_id`,`returned_at`),
  KEY `fk_request_item_units_actor` (`allocated_by`),
  CONSTRAINT `fk_request_item_units_actor` FOREIGN KEY (`allocated_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_request_item_units_line` FOREIGN KEY (`request_item_id`) REFERENCES `request_items` (`request_item_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_request_item_units_unit` FOREIGN KEY (`unit_id`) REFERENCES `equipment_units` (`unit_id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `request_reschedule_history`

Immutable old/proposed schedule snapshots, requester/encoder/reviewer and Pending uniqueness guard.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `reschedule_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `reschedule_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `requested_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 4 | `old_date_needed` | `date` | NO | none | `` | `- / -` | `` |
| 5 | `old_start_time` | `time` | YES | `NULL` | `` | `- / -` | `` |
| 6 | `old_end_time` | `time` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `old_facility_slot` | `enum('AM','PM')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `new_date_needed` | `date` | NO | none | `` | `- / -` | `` |
| 9 | `new_start_time` | `time` | NO | none | `` | `- / -` | `` |
| 10 | `new_end_time` | `time` | NO | none | `` | `- / -` | `` |
| 11 | `new_facility_slot` | `enum('AM','PM')` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 12 | `client_reason` | `varchar(1000)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 13 | `status` | `enum('Pending','Approved','Rejected','Cancelled')` | NO | `'Pending'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 14 | `reviewed_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 15 | `reviewed_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 16 | `review_remarks` | `varchar(1000)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 17 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 18 | `processed_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 19 | `pending_request_id` | `int(11)` | YES | `NULL` | `STORED GENERATED` | `- / -` | `` |
| 20 | `encoded_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_reschedule_encoder`: (`encoded_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_reschedule_request`: (`request_id`) -> `resource_requests` (`request_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_reschedule_requester`: (`requested_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_reschedule_reviewer`: (`reviewed_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_reschedule_encoder` | ordinary | `encoded_by` | `BTREE` |  |
| `idx_reschedule_requester` | ordinary | `requested_by` | `BTREE` |  |
| `idx_reschedule_request_created` | ordinary | `request_id`, `created_at`, `reschedule_id` | `BTREE` |  |
| `idx_reschedule_reviewer` | ordinary | `reviewed_by` | `BTREE` |  |
| `idx_reschedule_status_created` | ordinary | `status`, `created_at`, `reschedule_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `reschedule_id` | `BTREE` |  |
| `uq_reschedule_pending_request` | UNIQUE | `pending_request_id` | `BTREE` | `pending_request_id` |

CHECK constraints, exact unmodified SQL:

`chk_reschedule_actor`

```sql
`requested_by` is not null and `encoded_by` is null or `requested_by` is null and `encoded_by` is not null
```

`chk_reschedule_proposed_interval`

```sql
`new_start_time` < `new_end_time` and (`new_facility_slot` = 'AM' and hour(`new_start_time`) < 12 or `new_facility_slot` = 'PM' and hour(`new_start_time`) >= 12)
```

Generated columns:

`pending_request_id` `int(11)`; STORED GENERATED; indexes `uq_reschedule_pending_request`.

```sql
case when `status` = 'Pending' then `request_id` else NULL end
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `request_reschedule_history` (
  `reschedule_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `requested_by` int(11) DEFAULT NULL,
  `old_date_needed` date NOT NULL,
  `old_start_time` time DEFAULT NULL,
  `old_end_time` time DEFAULT NULL,
  `old_facility_slot` enum('AM','PM') DEFAULT NULL,
  `new_date_needed` date NOT NULL,
  `new_start_time` time NOT NULL,
  `new_end_time` time NOT NULL,
  `new_facility_slot` enum('AM','PM') NOT NULL,
  `client_reason` varchar(1000) NOT NULL,
  `status` enum('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(1000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  `pending_request_id` int(11) GENERATED ALWAYS AS (case when `status` = 'Pending' then `request_id` else NULL end) STORED,
  `encoded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`reschedule_id`),
  UNIQUE KEY `uq_reschedule_pending_request` (`pending_request_id`),
  KEY `idx_reschedule_request_created` (`request_id`,`created_at`,`reschedule_id`),
  KEY `idx_reschedule_status_created` (`status`,`created_at`,`reschedule_id`),
  KEY `idx_reschedule_requester` (`requested_by`),
  KEY `idx_reschedule_reviewer` (`reviewed_by`),
  KEY `fk_reschedule_encoder` (`encoded_by`),
  CONSTRAINT `fk_reschedule_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_reschedule_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`),
  CONSTRAINT `fk_reschedule_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_reschedule_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `chk_reschedule_proposed_interval` CHECK (`new_start_time` < `new_end_time` and (`new_facility_slot` = 'AM' and hour(`new_start_time`) < 12 or `new_facility_slot` = 'PM' and hour(`new_start_time`) >= 12)),
  CONSTRAINT `chk_reschedule_actor` CHECK (`requested_by` is not null and `encoded_by` is null or `requested_by` is null and `encoded_by` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `resources`

Facility and equipment catalogue with tracking mode, condition and stock counters.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `resource_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `resource_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `resource_name` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 3 | `resource_type` | `enum('Item','Facility')` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `tracking_mode` | `enum('Facility','Serialized','Aggregate','Consumable')` | NO | `'Aggregate'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `category` | `varchar(50)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `description` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `resource_image` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `location` | `varchar(100)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 9 | `total_stock` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 10 | `available_stock` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 11 | `under_repair_stock` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 12 | `capacity` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 13 | `status` | `enum('Available','Unavailable','Maintenance')` | NO | `'Available'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 14 | `condition_status` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | NO | `'Good'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 15 | `condition_notes` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 16 | `is_archived` | `tinyint(1)` | NO | `0` | `` | `- / -` | `` |
| 17 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

None.


All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_resources_archived_status` | ordinary | `is_archived`, `status` | `BTREE` |  |
| `idx_resources_type_category` | ordinary | `resource_type`, `category` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `resource_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_resources_tracking_mode`

```sql
`resource_type` = 'Facility' and `tracking_mode` = 'Facility' or `resource_type` = 'Item' and `tracking_mode` in ('Serialized','Aggregate','Consumable')
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `resources` (
  `resource_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_name` varchar(100) NOT NULL,
  `resource_type` enum('Item','Facility') NOT NULL,
  `tracking_mode` enum('Facility','Serialized','Aggregate','Consumable') NOT NULL DEFAULT 'Aggregate',
  `category` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `resource_image` varchar(255) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `total_stock` int(11) DEFAULT NULL,
  `available_stock` int(11) DEFAULT NULL,
  `under_repair_stock` int(11) NOT NULL DEFAULT 0,
  `capacity` int(11) DEFAULT NULL,
  `status` enum('Available','Unavailable','Maintenance') NOT NULL DEFAULT 'Available',
  `condition_status` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') NOT NULL DEFAULT 'Good',
  `condition_notes` varchar(500) DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`resource_id`),
  KEY `idx_resources_archived_status` (`is_archived`,`status`),
  KEY `idx_resources_type_category` (`resource_type`,`category`),
  CONSTRAINT `chk_resources_tracking_mode` CHECK (`resource_type` = 'Facility' and `tracking_mode` = 'Facility' or `resource_type` = 'Item' and `tracking_mode` in ('Serialized','Aggregate','Consumable'))
) ENGINE=InnoDB AUTO_INCREMENT=754 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `resource_requests`

Registered/Walk-in transaction header, authoritative current schedule, operational actors and public Claim Slip Code.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `request_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `request_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `borrower_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 3 | `contact_number` | `varchar(30)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `date_needed` | `date` | YES | `NULL` | `` | `- / -` | `` |
| 5 | `start_time` | `time` | YES | `NULL` | `` | `- / -` | `` |
| 6 | `end_time` | `time` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `request_date` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 8 | `status` | `enum('Pending','Under Review','Approved','Rejected','Cancelled','Released','Return','Returned','Completed')` | NO | `'Pending'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 9 | `claim_slip_code` | `varchar(20)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 10 | `approved_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 11 | `reviewed_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 12 | `released_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 13 | `approved_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 14 | `reviewed_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 15 | `released_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 16 | `due_date` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 17 | `return_date` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 18 | `notes` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 19 | `last_reminded_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 20 | `reminder_count` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 21 | `requester_type` | `enum('Registered','Walk-in')` | NO | `'Registered'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 22 | `walkin_full_name` | `varchar(150)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 23 | `walkin_organization` | `varchar(200)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 24 | `walkin_email` | `varchar(150)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 25 | `walkin_contact_number` | `varchar(30)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 26 | `cancellation_reason` | `varchar(1000)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 27 | `encoded_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_rr_approved_by`: (`approved_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_rr_borrower`: (`borrower_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_rr_encoder`: (`encoded_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_rr_released_by`: (`released_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_rr_reviewed_by`: (`reviewed_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_resource_requests_approved_by` | ordinary | `approved_by` | `BTREE` |  |
| `fk_resource_requests_reviewed_by` | ordinary | `reviewed_by` | `BTREE` |  |
| `fk_rr_encoder` | ordinary | `encoded_by` | `BTREE` |  |
| `fk_rr_released_by` | ordinary | `released_by` | `BTREE` |  |
| `idx_requests_borrower_return` | ordinary | `borrower_id`, `return_date` | `BTREE` |  |
| `idx_requests_status_due` | ordinary | `status`, `due_date` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `request_id` | `BTREE` |  |
| `uq_requests_claim_slip_code` | UNIQUE | `claim_slip_code` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_requester_mode`

```sql
`requester_type` = 'Registered' and `borrower_id` is not null and `encoded_by` is null and `walkin_full_name` is null and `walkin_organization` is null and `walkin_email` is null and `walkin_contact_number` is null or `requester_type` = 'Walk-in' and `borrower_id` is null and `encoded_by` is not null and `walkin_full_name` is not null and char_length(trim(`walkin_full_name`)) > 0
```

`chk_requests_claim_slip`

```sql
(`claim_slip_code` is null or `claim_slip_code` regexp '^GSO-[2-9A-HJ-NP-Z]{8}$') and (`status` not in ('Pending','Under Review') or `claim_slip_code` is null) and (`status` <> 'Approved' or `claim_slip_code` is not null)
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `resource_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `borrower_id` int(11) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `date_needed` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `request_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Under Review','Approved','Rejected','Cancelled','Released','Return','Returned','Completed') NOT NULL DEFAULT 'Pending',
  `claim_slip_code` varchar(20) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `released_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `due_date` datetime DEFAULT NULL,
  `return_date` datetime DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `last_reminded_at` datetime DEFAULT NULL,
  `reminder_count` int(11) NOT NULL DEFAULT 0,
  `requester_type` enum('Registered','Walk-in') NOT NULL DEFAULT 'Registered',
  `walkin_full_name` varchar(150) DEFAULT NULL,
  `walkin_organization` varchar(200) DEFAULT NULL,
  `walkin_email` varchar(150) DEFAULT NULL,
  `walkin_contact_number` varchar(30) DEFAULT NULL,
  `cancellation_reason` varchar(1000) DEFAULT NULL,
  `encoded_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  UNIQUE KEY `uq_requests_claim_slip_code` (`claim_slip_code`),
  KEY `fk_resource_requests_approved_by` (`approved_by`),
  KEY `fk_resource_requests_reviewed_by` (`reviewed_by`),
  KEY `idx_requests_borrower_return` (`borrower_id`,`return_date`),
  KEY `idx_requests_status_due` (`status`,`due_date`),
  KEY `fk_rr_released_by` (`released_by`),
  KEY `fk_rr_encoder` (`encoded_by`),
  CONSTRAINT `fk_rr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rr_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_rr_encoder` FOREIGN KEY (`encoded_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_rr_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rr_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_requester_mode` CHECK (`requester_type` = 'Registered' and `borrower_id` is not null and `encoded_by` is null and `walkin_full_name` is null and `walkin_organization` is null and `walkin_email` is null and `walkin_contact_number` is null or `requester_type` = 'Walk-in' and `borrower_id` is null and `encoded_by` is not null and `walkin_full_name` is not null and char_length(trim(`walkin_full_name`)) > 0),
  CONSTRAINT `chk_requests_claim_slip` CHECK ((`claim_slip_code` is null or `claim_slip_code` regexp '^GSO-[2-9A-HJ-NP-Z]{8}$') and (`status` not in ('Pending','Under Review') or `claim_slip_code` is null) and (`status` <> 'Approved' or `claim_slip_code` is not null))
) ENGINE=InnoDB AUTO_INCREMENT=1448 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `return_submissions`

Return/post-use evidence header and submission/inspection actors for Registered or GSO-encoded Walk-in transactions.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `return_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `return_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `request_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `borrower_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 4 | `condition_notes` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `reported_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `status` | `enum('Pending','Approved','Rejected')` | NO | `'Pending'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `admin_id` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 8 | `admin_notes` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 9 | `inspection_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 10 | `inspection_remarks` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 11 | `submitted_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 12 | `reviewed_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 13 | `submitted_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_rs_admin`: (`admin_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_rs_borrower`: (`borrower_id`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_rs_submitter`: (`submitted_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `return_submissions_ibfk_1`: (`request_id`) -> `resource_requests` (`request_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_return_admin` | ordinary | `admin_id` | `BTREE` |  |
| `fk_return_borrower` | ordinary | `borrower_id` | `BTREE` |  |
| `fk_rs_submitter` | ordinary | `submitted_by` | `BTREE` |  |
| `idx_return_submissions_status_date` | ordinary | `status`, `submitted_at` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `return_id` | `BTREE` |  |
| `uq_return_request` | UNIQUE | `request_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_return_actor`

```sql
`borrower_id` is not null or `submitted_by` is not null
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `return_submissions` (
  `return_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `borrower_id` int(11) DEFAULT NULL,
  `condition_notes` varchar(500) DEFAULT NULL,
  `reported_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_id` int(11) DEFAULT NULL,
  `admin_notes` varchar(500) DEFAULT NULL,
  `inspection_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `inspection_remarks` varchar(500) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `submitted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`return_id`),
  UNIQUE KEY `uq_return_request` (`request_id`),
  KEY `idx_return_submissions_status_date` (`status`,`submitted_at`),
  KEY `fk_return_borrower` (`borrower_id`),
  KEY `fk_return_admin` (`admin_id`),
  KEY `fk_rs_submitter` (`submitted_by`),
  CONSTRAINT `fk_rs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_rs_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `fk_rs_submitter` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`),
  CONSTRAINT `return_submissions_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`),
  CONSTRAINT `chk_return_actor` CHECK (`borrower_id` is not null or `submitted_by` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=297 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `return_submission_items`

Per-line/per-unit conditions, physical return/loss quantities and settlement/inspection metadata.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `return_item_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `return_item_id` | `bigint(20)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `return_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `request_item_id` | `bigint(20)` | NO | none | `` | `- / -` | `` |
| 4 | `unit_id` | `bigint(20)` | YES | `NULL` | `` | `- / -` | `` |
| 5 | `reported_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `inspection_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 7 | `returned_quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 8 | `lost_quantity` | `int(11)` | NO | `0` | `` | `- / -` | `` |
| 9 | `inspection_remarks` | `varchar(500)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 10 | `inspected_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 11 | `inspected_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 12 | `stock_applied` | `tinyint(1)` | NO | `0` | `` | `- / -` | `` |
| 13 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_return_items_inspector`: (`inspected_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.
- `fk_return_items_line`: (`request_item_id`) -> `request_items` (`request_item_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.
- `fk_return_items_return`: (`return_id`) -> `return_submissions` (`return_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.
- `fk_return_items_unit`: (`unit_id`) -> `equipment_units` (`unit_id`); ON UPDATE RESTRICT; ON DELETE RESTRICT.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_return_items_inspector` | ordinary | `inspected_by` | `BTREE` |  |
| `fk_return_items_unit` | ordinary | `unit_id` | `BTREE` |  |
| `idx_return_items_line` | ordinary | `request_item_id`, `return_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `return_item_id` | `BTREE` |  |
| `uq_return_items_unit` | UNIQUE | `return_id`, `unit_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

`chk_return_items_quantities`

```sql
`returned_quantity` >= 0 and `lost_quantity` >= 0 and `returned_quantity` + `lost_quantity` >= 1 and (`unit_id` is null or `returned_quantity` + `lost_quantity` = 1)
```

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `return_submission_items` (
  `return_item_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `request_item_id` bigint(20) NOT NULL,
  `unit_id` bigint(20) DEFAULT NULL,
  `reported_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `inspection_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `returned_quantity` int(11) NOT NULL DEFAULT 0,
  `lost_quantity` int(11) NOT NULL DEFAULT 0,
  `inspection_remarks` varchar(500) DEFAULT NULL,
  `inspected_by` int(11) DEFAULT NULL,
  `inspected_at` datetime DEFAULT NULL,
  `stock_applied` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`return_item_id`),
  UNIQUE KEY `uq_return_items_unit` (`return_id`,`unit_id`),
  KEY `idx_return_items_line` (`request_item_id`,`return_id`),
  KEY `fk_return_items_unit` (`unit_id`),
  KEY `fk_return_items_inspector` (`inspected_by`),
  CONSTRAINT `fk_return_items_inspector` FOREIGN KEY (`inspected_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_return_items_line` FOREIGN KEY (`request_item_id`) REFERENCES `request_items` (`request_item_id`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `return_submissions` (`return_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `equipment_units` (`unit_id`),
  CONSTRAINT `chk_return_items_quantities` CHECK (`returned_quantity` >= 0 and `lost_quantity` >= 0 and `returned_quantity` + `lost_quantity` >= 1 and (`unit_id` is null or `returned_quantity` + `lost_quantity` = 1))
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `return_submission_photos`

Evidence file metadata attached to a return submission.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `photo_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `photo_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `return_id` | `int(11)` | NO | none | `` | `- / -` | `` |
| 3 | `filename` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `mime_type` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_rsp_return`: (`return_id`) -> `return_submissions` (`return_id`); ON UPDATE RESTRICT; ON DELETE CASCADE.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `idx_return_photos_return` | ordinary | `return_id` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `photo_id` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `return_submission_photos` (
  `photo_id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_id`),
  KEY `idx_return_photos_return` (`return_id`),
  CONSTRAINT `fk_rsp_return` FOREIGN KEY (`return_id`) REFERENCES `return_submissions` (`return_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `system_settings`

Key/value configuration and schema version; keys are rows, not schema columns.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `setting_key`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `setting_key` | `varchar(64)` | NO | none | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 2 | `setting_value` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_general_ci` | `` |
| 3 | `updated_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |

Foreign keys (ordered composite columns; physical rules):

None.


All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `PRIMARY` | PRIMARY | `setting_key` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `system_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
```

### `users`

Accounts, verification, roles and account lifecycle; approval actor is a self-reference.

Table comment: ``. Primary-key name: `PRIMARY`; ordered columns: `user_id`.

| Ordinal | Column | Exact SQL type | NULL | Exact SQL default | Extra | Charset / collation | Comment |
|---:|---|---|---|---|---|---|---|
| 1 | `user_id` | `int(11)` | NO | none | `auto_increment` | `- / -` | `` |
| 2 | `full_name` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 3 | `department` | `varchar(100)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 4 | `university_id` | `varchar(30)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 5 | `email` | `varchar(100)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 6 | `uploaded_id` | `longblob` | YES | `NULL` | `` | `- / -` | `` |
| 7 | `uploaded_id_type` | `varchar(100)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 8 | `profile_image` | `longblob` | YES | `NULL` | `` | `- / -` | `` |
| 9 | `profile_image_type` | `varchar(100)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 10 | `role` | `enum('Admin','Borrower','GSO Staff','Director','Super Admin')` | NO | `'Borrower'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 11 | `account_type` | `enum('Student','Faculty','University Staff')` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 12 | `username` | `varchar(50)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 13 | `password_hash` | `varchar(255)` | NO | none | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 14 | `reset_token_hash` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 15 | `reset_token_expires_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 16 | `account_status` | `enum('Pending','Approved','Rejected','Disabled')` | NO | `'Pending'` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |
| 17 | `approved_by` | `int(11)` | YES | `NULL` | `` | `- / -` | `` |
| 18 | `approved_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 19 | `created_at` | `datetime` | NO | `current_timestamp()` | `` | `- / -` | `` |
| 20 | `password_updated_at` | `datetime` | YES | `NULL` | `` | `- / -` | `` |
| 21 | `email_verified` | `tinyint(1)` | YES | `0` | `` | `- / -` | `` |
| 22 | `verification_token` | `varchar(255)` | YES | `NULL` | `` | `utf8mb4 / utf8mb4_unicode_ci` | `` |

Foreign keys (ordered composite columns; physical rules):

- `fk_users_approved_by`: (`approved_by`) -> `users` (`user_id`); ON UPDATE RESTRICT; ON DELETE SET NULL.

All indexes, including PRIMARY and UNIQUE:

| Name | Classification | Columns, in order | Type / prefix | Generated participation |
|---|---|---|---|---|
| `fk_users_approved_by` | ordinary | `approved_by` | `BTREE` |  |
| `PRIMARY` | PRIMARY | `user_id` | `BTREE` |  |
| `uq_users_email` | UNIQUE | `email` | `BTREE` |  |
| `uq_users_university_id` | UNIQUE | `university_id` | `BTREE` |  |
| `uq_users_username` | UNIQUE | `username` | `BTREE` |  |

CHECK constraints, exact unmodified SQL:

None.

Exact SHOW CREATE TABLE (current AUTO_INCREMENT counter retained as observed):

```sql
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `department` varchar(100) DEFAULT NULL,
  `university_id` varchar(30) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `uploaded_id` longblob DEFAULT NULL,
  `uploaded_id_type` varchar(100) DEFAULT NULL,
  `profile_image` longblob DEFAULT NULL,
  `profile_image_type` varchar(100) DEFAULT NULL,
  `role` enum('Admin','Borrower','GSO Staff','Director','Super Admin') NOT NULL DEFAULT 'Borrower',
  `account_type` enum('Student','Faculty','University Staff') DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `reset_token_hash` varchar(255) DEFAULT NULL,
  `reset_token_expires_at` datetime DEFAULT NULL,
  `account_status` enum('Pending','Approved','Rejected','Disabled') NOT NULL DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `password_updated_at` datetime DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_university_id` (`university_id`),
  KEY `fk_users_approved_by` (`approved_by`),
  CONSTRAINT `fk_users_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1416 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Referential and domain integrity

Every physical FK was checked with nullable composite-key semantics preserved. All 57 checks return zero orphan rows. Each of the 11 physical CHECK expressions was evaluated read-only and returns zero violations.

| FK constraint | Child | Orphans |
|---|---|---:|
| `fk_al_user` | `activity_logs` | 0 |
| `fk_ai_conversations_user` | `ai_conversations` | 0 |
| `fk_ai_knowledge_created_by` | `ai_knowledge_entries` | 0 |
| `fk_ai_knowledge_updated_by` | `ai_knowledge_entries` | 0 |
| `fk_ai_messages_conversation` | `ai_messages` | 0 |
| `fk_liability_borrower` | `borrower_liabilities` | 0 |
| `fk_liability_request` | `borrower_liabilities` | 0 |
| `fk_liability_return_item` | `borrower_liabilities` | 0 |
| `fk_liability_reviewer` | `borrower_liabilities` | 0 |
| `fk_liability_unit` | `borrower_liabilities` | 0 |
| `fk_equipment_units_registrar` | `equipment_units` | 0 |
| `fk_equipment_units_resource` | `equipment_units` | 0 |
| `fk_incident_reports_assigned_to` | `incident_reports` | 0 |
| `fk_incident_reports_reporter` | `incident_reports` | 0 |
| `fk_incident_reports_resource` | `incident_reports` | 0 |
| `fk_maintenance_plan` | `maintenance_schedules` | 0 |
| `fk_ms_assigned_to` | `maintenance_schedules` | 0 |
| `fk_ms_created_by` | `maintenance_schedules` | 0 |
| `fk_ms_equipment_unit` | `maintenance_schedules` | 0 |
| `fk_ms_incident` | `maintenance_schedules` | 0 |
| `fk_ms_resource` | `maintenance_schedules` | 0 |
| `fk_ms_source_return` | `maintenance_schedules` | 0 |
| `fk_ms_source_return_item` | `maintenance_schedules` | 0 |
| `fk_ms_updated_by` | `maintenance_schedules` | 0 |
| `fk_notif_user` | `notifications` | 0 |
| `fk_plan_assignee` | `preventive_maintenance_plans` | 0 |
| `fk_plan_creator` | `preventive_maintenance_plans` | 0 |
| `fk_plan_resource` | `preventive_maintenance_plans` | 0 |
| `fk_plan_unit` | `preventive_maintenance_plans` | 0 |
| `fk_plan_updater` | `preventive_maintenance_plans` | 0 |
| `fk_request_attachment_request` | `request_attachments` | 0 |
| `fk_request_attachment_reviewer` | `request_attachments` | 0 |
| `fk_request_attachment_uploader` | `request_attachments` | 0 |
| `fk_request_items_request` | `request_items` | 0 |
| `fk_request_items_resource` | `request_items` | 0 |
| `fk_request_item_units_actor` | `request_item_units` | 0 |
| `fk_request_item_units_line` | `request_item_units` | 0 |
| `fk_request_item_units_unit` | `request_item_units` | 0 |
| `fk_reschedule_encoder` | `request_reschedule_history` | 0 |
| `fk_reschedule_request` | `request_reschedule_history` | 0 |
| `fk_reschedule_requester` | `request_reschedule_history` | 0 |
| `fk_reschedule_reviewer` | `request_reschedule_history` | 0 |
| `fk_rr_approved_by` | `resource_requests` | 0 |
| `fk_rr_borrower` | `resource_requests` | 0 |
| `fk_rr_encoder` | `resource_requests` | 0 |
| `fk_rr_released_by` | `resource_requests` | 0 |
| `fk_rr_reviewed_by` | `resource_requests` | 0 |
| `fk_rs_admin` | `return_submissions` | 0 |
| `fk_rs_borrower` | `return_submissions` | 0 |
| `fk_rs_submitter` | `return_submissions` | 0 |
| `return_submissions_ibfk_1` | `return_submissions` | 0 |
| `fk_return_items_inspector` | `return_submission_items` | 0 |
| `fk_return_items_line` | `return_submission_items` | 0 |
| `fk_return_items_return` | `return_submission_items` | 0 |
| `fk_return_items_unit` | `return_submission_items` | 0 |
| `fk_rsp_return` | `return_submission_photos` | 0 |
| `fk_users_approved_by` | `users` | 0 |

| Domain check | Violations |
|---|---:|
| invalid_requester_combinations | 0 |
| registered_missing_borrower | 0 |
| walkin_with_borrower | 0 |
| invalid_facility_slots | 0 |
| nonfacility_with_slot | 0 |
| invalid_claim_status | 0 |
| duplicate_claim_codes | 0 |
| negative_stock | 0 |
| available_exceeds_total | 0 |
| repair_exceeds_total | 0 |
| invalid_stock_arithmetic | 0 |
| invalid_serialized_allocations | 0 |
| duplicate_open_allocations | 0 |
| duplicate_pending_reschedules | 0 |
| invalid_attachment_versions | 0 |
| duplicate_attachment_versions | 0 |
| lost_target_active_repair | 0 |
| invalid_preventive_targets | 0 |
| orphaned_preventive_occurrences | 0 |
| overapplied_return_settlement | 0 |
| unapproved_stock_application | 0 |

Exact read-only audit queries are in the JSON companion. No repair/update/backfill was performed. Triggers/views/routines/events: all 0/0/0/0. No extra physical object was found.

## Regression and final immutability checks

| Active suite | Actual checks | Result |
|---|---:|---|
| `phase1_roles_e2e.php` | 44 | PASS |
| `phase35a_combined_borrowing_e2e.php` | 74 | PASS |
| `phase35b_unit_tracking_e2e.php` | 87 | PASS |
| `phase35c_workorder_accountability_e2e.php` | 47 | PASS |
| `r10_final_integration_e2e.php` | 656 | PASS |
| `r10a_claim_slip_e2e.php` | 563 | PASS |
| `r1_return_lifecycle_e2e.php` | 62 | PASS |
| `r2a_role_director_cutover_e2e.php` | 137 | PASS |
| `r2b_manual_letter_adviser_retirement_e2e.php` | 98 | PASS |
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

**22 active suites; 3,033 actual checks passed.** Tests used the isolated database `gso_r11_test_20261010_223653` created from current schema.sql, with a copied baseline so suites needing existing approved accounts/historical fixtures could run. Live source access was read-only. Apache was loopback-only on port 8101 and the test mail transport used a private local outbox, with no real SMTP dependency or live emails.

Superseded, not run: `scripts/superseded/phase2_adviser_endorsement_e2e.php` and `scripts/superseded/phase3_director_conflict_e2e.php`.

All 22 live table row hashes, row counts and full captured metadata remained unchanged after tests. Git showed only the protected landing deletion before documentation generation; no production/schema/migration file changed. `git diff --check` passed. 167 relevant production/configuration/wrapper PHP files pass syntax checks.

## Caveats and handoff

- Protected deletion `app/controllers/pages/landing.php` and untracked `app/controllers/gso_staff/pages/` remain untouched. The missing landing controller is an existing application/root-page caveat, not a schema discrepancy; R11 did not repair it.
- Live has only Admin/Borrower accounts, NULL borrower account types, zero preventive plans and serialized allocations, and one NULL-type historical maintenance row. Those are valid current DATA states. Constraints and isolated tests cover the other implemented roles/modes.
- No schema discrepancy was found. No normalization, renamed field, inferred schema object, fabricated Claim Slip Code, data cleanup or R12 ERD artifact was introduced.
- Frozen implementation remains `62fdff1c40391d247e7741536fd397c7832a07f8` even if a later documentation-only commit contains these files.

Audit evidence: `C:/Users/ACER/AppData/Local/Temp/GSO_R11_20261010_223533`. Companion `docs/R11_SCHEMA_FREEZE.json` is the machine-readable R12 input. Credentials and actual user/transaction record values are excluded.

**SCHEMA FREEZE: PASSED**
