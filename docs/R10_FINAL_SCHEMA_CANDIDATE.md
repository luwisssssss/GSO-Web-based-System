# R10 candidate final schema

Generated from the live database after migration `20261010_008_claim_slip_code`. This is the starting point for the R11 read-only schema freeze. No ERD is included.

| Measure | Count |
|---|---:|
| Tables | 22 |
| Foreign keys | 57 |
| Indexes (including primary keys) | 104 |
| UNIQUE constraints (excluding primary keys) | 18 |
| CHECK constraints | 11 |
| Generated columns | 3 |
| Triggers, views, routines, events | 0 |

## Added in R10A

`resource_requests.claim_slip_code` (nullable `varchar(20)`), UNIQUE key `uq_requests_claim_slip_code`, and CHECK constraint `chk_requests_claim_slip`. No table and no foreign key was added.

## Removed in R10

**Tables:** `request_adviser_history`, `request_director_history` (both empty in live).

**Columns of `resource_requests`:** `adviser_state`, `adviser_user_id`, `adviser_required_by`, `adviser_required_at`, `director_state`, `director_required_by`, `director_required_at`, `resource_id`, `quantity`.

**Foreign keys (10):** `fk_adviser_history_request`, `fk_adviser_history_adviser`, `fk_adviser_history_actor`, `fk_director_history_request`, `fk_director_history_actor`, `fk_director_history_winner`, `fk_rr_adviser_user`, `fk_rr_adviser_required_by`, `fk_rr_director_required_by`, `fk_rr_resource`.

**Indexes of `resource_requests` (6):** `idx_rr_adviser_queue`, `fk_rr_adviser_required_by`, `idx_rr_director_queue`, `fk_rr_director_required_by`, `fk_resource_requests_resource`, `idx_requests_resource_status`. The nine indexes of the two dropped tables went with them.

## Tables

### `users`

Accounts for every role: Borrower (Student, Faculty, University Staff), GSO Staff, Admin (GSO Head), Director and Super Admin (MIS).

**Primary key:** `user_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `user_id` | `int(11)` | no |  | auto increment |
| `full_name` | `varchar(100)` | no |  |  |
| `department` | `varchar(100)` | yes | `NULL` |  |
| `university_id` | `varchar(30)` | yes | `NULL` |  |
| `email` | `varchar(100)` | no |  |  |
| `uploaded_id` | `longblob` | yes | `NULL` |  |
| `uploaded_id_type` | `varchar(100)` | yes | `NULL` |  |
| `profile_image` | `longblob` | yes | `NULL` |  |
| `profile_image_type` | `varchar(100)` | yes | `NULL` |  |
| `role` | `enum('Admin','Borrower','GSO Staff','Director','Super Admin')` | no | `Borrower` |  |
| `account_type` | `enum('Student','Faculty','University Staff')` | yes | `NULL` |  |
| `username` | `varchar(50)` | no |  |  |
| `password_hash` | `varchar(255)` | no |  |  |
| `reset_token_hash` | `varchar(255)` | yes | `NULL` |  |
| `reset_token_expires_at` | `datetime` | yes | `NULL` |  |
| `account_status` | `enum('Pending','Approved','Rejected','Disabled')` | no | `Pending` |  |
| `approved_by` | `int(11)` | yes | `NULL` |  |
| `approved_at` | `datetime` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `password_updated_at` | `datetime` | yes | `NULL` |  |
| `email_verified` | `tinyint(1)` | yes | `0` |  |
| `verification_token` | `varchar(255)` | yes | `NULL` |  |

**Foreign keys (1):**
- `fk_users_approved_by`: `approved_by` references `users`(`user_id`), on delete SET NULL

**UNIQUE constraints:**
- `uq_users_email` (`email`)
- `uq_users_university_id` (`university_id`)
- `uq_users_username` (`username`)

**CHECK constraints:** none

**Other indexes:**
- `fk_users_approved_by` (`approved_by`)

### `resources`

Facilities and inventory items with tracking mode, stock counters, status and condition.

**Primary key:** `resource_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `resource_id` | `int(11)` | no |  | auto increment |
| `resource_name` | `varchar(100)` | no |  |  |
| `resource_type` | `enum('Item','Facility')` | no |  |  |
| `tracking_mode` | `enum('Facility','Serialized','Aggregate','Consumable')` | no | `Aggregate` |  |
| `category` | `varchar(50)` | yes | `NULL` |  |
| `description` | `varchar(255)` | yes | `NULL` |  |
| `resource_image` | `varchar(255)` | yes | `NULL` |  |
| `location` | `varchar(100)` | yes | `NULL` |  |
| `total_stock` | `int(11)` | yes | `NULL` |  |
| `available_stock` | `int(11)` | yes | `NULL` |  |
| `under_repair_stock` | `int(11)` | no | `0` |  |
| `capacity` | `int(11)` | yes | `NULL` |  |
| `status` | `enum('Available','Unavailable','Maintenance')` | no | `Available` |  |
| `condition_status` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | no | `Good` |  |
| `condition_notes` | `varchar(500)` | yes | `NULL` |  |
| `is_archived` | `tinyint(1)` | no | `0` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (0):** none

**UNIQUE constraints:** none

**CHECK constraints:**
- `chk_resources_tracking_mode`: ``resource_type` = 'Facility' and `tracking_mode` = 'Facility' or `resource_type` = 'Item' and `tracking_mode` in ('Serialized','Aggregate','Consumable')`

**Other indexes:**
- `idx_resources_archived_status` (`is_archived`, `status`)
- `idx_resources_type_category` (`resource_type`, `category`)

### `equipment_units`

Individually tracked physical units of Serialized equipment.

**Primary key:** `unit_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `unit_id` | `bigint(20)` | no |  | auto increment |
| `resource_id` | `int(11)` | no |  |  |
| `asset_tag` | `varchar(60)` | no |  |  |
| `serial_number` | `varchar(120)` | yes | `NULL` |  |
| `status` | `enum('Available','Released','Under Repair','Lost','Retired')` | no | `Available` |  |
| `condition_status` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | no | `Good` |  |
| `unit_notes` | `varchar(500)` | yes | `NULL` |  |
| `acquired_at` | `date` | yes | `NULL` |  |
| `registered_by` | `int(11)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | yes | `NULL` |  |

**Foreign keys (2):**
- `fk_equipment_units_registrar`: `registered_by` references `users`(`user_id`), on delete SET NULL
- `fk_equipment_units_resource`: `resource_id` references `resources`(`resource_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_equipment_units_asset_tag` (`asset_tag`)
- `uq_equipment_units_serial` (`serial_number`)

**CHECK constraints:** none

**Other indexes:**
- `fk_equipment_units_registrar` (`registered_by`)
- `idx_equipment_units_resource` (`resource_id`, `status`)

### `resource_requests`

Request header: requester (registered borrower or walk-in snapshot), schedule, status, Claim Slip Code and audit actors. Holds no resource or quantity.

**Primary key:** `request_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `request_id` | `int(11)` | no |  | auto increment |
| `borrower_id` | `int(11)` | yes | `NULL` |  |
| `contact_number` | `varchar(30)` | yes | `NULL` |  |
| `date_needed` | `date` | yes | `NULL` |  |
| `start_time` | `time` | yes | `NULL` |  |
| `end_time` | `time` | yes | `NULL` |  |
| `request_date` | `datetime` | no | `current_timestamp()` |  |
| `status` | `enum('Pending','Under Review','Approved','Rejected','Cancelled','Released','Return','Returned','Completed')` | no | `Pending` |  |
| `claim_slip_code` | `varchar(20)` | yes | `NULL` |  |
| `approved_by` | `int(11)` | yes | `NULL` |  |
| `reviewed_by` | `int(11)` | yes | `NULL` |  |
| `released_by` | `int(11)` | yes | `NULL` |  |
| `approved_at` | `datetime` | yes | `NULL` |  |
| `reviewed_at` | `datetime` | yes | `NULL` |  |
| `released_at` | `datetime` | yes | `NULL` |  |
| `due_date` | `datetime` | yes | `NULL` |  |
| `return_date` | `datetime` | yes | `NULL` |  |
| `notes` | `varchar(255)` | yes | `NULL` |  |
| `last_reminded_at` | `datetime` | yes | `NULL` |  |
| `reminder_count` | `int(11)` | no | `0` |  |
| `requester_type` | `enum('Registered','Walk-in')` | no | `Registered` |  |
| `walkin_full_name` | `varchar(150)` | yes | `NULL` |  |
| `walkin_organization` | `varchar(200)` | yes | `NULL` |  |
| `walkin_email` | `varchar(150)` | yes | `NULL` |  |
| `walkin_contact_number` | `varchar(30)` | yes | `NULL` |  |
| `cancellation_reason` | `varchar(1000)` | yes | `NULL` |  |
| `encoded_by` | `int(11)` | yes | `NULL` |  |

**Foreign keys (5):**
- `fk_rr_approved_by`: `approved_by` references `users`(`user_id`), on delete SET NULL
- `fk_rr_borrower`: `borrower_id` references `users`(`user_id`), on delete RESTRICT
- `fk_rr_encoder`: `encoded_by` references `users`(`user_id`), on delete RESTRICT
- `fk_rr_released_by`: `released_by` references `users`(`user_id`), on delete SET NULL
- `fk_rr_reviewed_by`: `reviewed_by` references `users`(`user_id`), on delete SET NULL

**UNIQUE constraints:**
- `uq_requests_claim_slip_code` (`claim_slip_code`)

**CHECK constraints:**
- `chk_requester_mode`: ``requester_type` = 'Registered' and `borrower_id` is not null and `encoded_by` is null and `walkin_full_name` is null and `walkin_organization` is null and `walkin_email` is null and `walkin_contact_number` is null or `requester_type` = 'Walk-in' and `borrower_id` is null and `encoded_by` is not null and `walkin_full_name` is not null and char_length(trim(`walkin_full_name`)) > 0`
- `chk_requests_claim_slip`: `(`claim_slip_code` is null or `claim_slip_code` regexp '^GSO-[2-9A-HJ-NP-Z]{8}$') and (`status` not in ('Pending','Under Review') or `claim_slip_code` is null) and (`status` <> 'Approved' or `claim_slip_code` is not null)`

**Other indexes:**
- `fk_resource_requests_approved_by` (`approved_by`)
- `fk_resource_requests_reviewed_by` (`reviewed_by`)
- `fk_rr_encoder` (`encoded_by`)
- `fk_rr_released_by` (`released_by`)
- `idx_requests_borrower_return` (`borrower_id`, `return_date`)
- `idx_requests_status_due` (`status`, `due_date`)

### `request_items`

Resource lines of a request. The only store of requested resources and quantities, including the facility AM/PM period.

**Primary key:** `request_item_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `request_item_id` | `bigint(20)` | no |  | auto increment |
| `request_id` | `int(11)` | no |  |  |
| `resource_id` | `int(11)` | no |  |  |
| `line_kind` | `enum('Facility','Equipment','Consumable')` | no |  |  |
| `facility_slot` | `enum('AM','PM')` | yes | `NULL` |  |
| `requested_quantity` | `int(11)` | no | `1` |  |
| `released_quantity` | `int(11)` | no | `0` |  |
| `returned_quantity` | `int(11)` | no | `0` |  |
| `issued_quantity` | `int(11)` | no | `0` |  |
| `line_notes` | `varchar(255)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `facility_guard` | `int(11)` | yes | `NULL` | generated (stored): `case when `line_kind` = 'Facility' then `request_id` end` |

**Foreign keys (2):**
- `fk_request_items_request`: `request_id` references `resource_requests`(`request_id`), on delete CASCADE
- `fk_request_items_resource`: `resource_id` references `resources`(`resource_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_request_items_one_facility` (`facility_guard`)
- `uq_request_items_resource` (`request_id`, `resource_id`)

**CHECK constraints:**
- `chk_request_items_quantities`: ``requested_quantity` >= 1 and `released_quantity` >= 0 and `released_quantity` <= `requested_quantity` and `returned_quantity` >= 0 and `returned_quantity` <= `released_quantity` and `issued_quantity` >= 0 and `issued_quantity` <= `requested_quantity``
- `chk_request_item_facility_slot`: ``facility_slot` is null or `line_kind` = 'Facility'`

**Other indexes:**
- `idx_request_items_kind` (`request_id`, `line_kind`)
- `idx_request_items_resource` (`resource_id`, `request_id`)

### `request_item_units`

Physical units issued for a serialized request line and when each was returned.

**Primary key:** `allocation_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `allocation_id` | `bigint(20)` | no |  | auto increment |
| `request_item_id` | `bigint(20)` | no |  |  |
| `unit_id` | `bigint(20)` | no |  |  |
| `allocated_by` | `int(11)` | no |  |  |
| `allocated_at` | `datetime` | no | `current_timestamp()` |  |
| `released_at` | `datetime` | yes | `NULL` |  |
| `returned_at` | `datetime` | yes | `NULL` |  |
| `open_unit_guard` | `bigint(20)` | yes | `NULL` | generated (stored): `case when `returned_at` is null then `unit_id` end` |

**Foreign keys (3):**
- `fk_request_item_units_actor`: `allocated_by` references `users`(`user_id`), on delete RESTRICT
- `fk_request_item_units_line`: `request_item_id` references `request_items`(`request_item_id`), on delete CASCADE
- `fk_request_item_units_unit`: `unit_id` references `equipment_units`(`unit_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_request_item_units_open` (`open_unit_guard`)
- `uq_request_item_units_pair` (`request_item_id`, `unit_id`)

**CHECK constraints:** none

**Other indexes:**
- `fk_request_item_units_actor` (`allocated_by`)
- `idx_request_item_units_unit` (`unit_id`, `returned_at`)

### `request_attachments`

Versioned manually signed facility request letters and their GSO verification result.

**Primary key:** `attachment_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `attachment_id` | `bigint(20)` | no |  | auto increment |
| `request_id` | `int(11)` | no |  |  |
| `uploaded_by` | `int(11)` | no |  |  |
| `attachment_type` | `enum('Facility Request Letter')` | no | `Facility Request Letter` |  |
| `version_no` | `int(10) unsigned` | no |  |  |
| `original_file_name` | `varchar(255)` | no |  |  |
| `stored_file_name` | `varchar(80)` | no |  |  |
| `mime_type` | `varchar(100)` | no |  |  |
| `file_size` | `int(10) unsigned` | no |  |  |
| `verification_status` | `enum('Pending','Verified','Rejected')` | no | `Pending` |  |
| `verified_by` | `int(11)` | yes | `NULL` |  |
| `verified_at` | `datetime` | yes | `NULL` |  |
| `verification_remarks` | `varchar(1000)` | yes | `NULL` |  |
| `uploaded_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (3):**
- `fk_request_attachment_request`: `request_id` references `resource_requests`(`request_id`), on delete RESTRICT
- `fk_request_attachment_reviewer`: `verified_by` references `users`(`user_id`), on delete RESTRICT
- `fk_request_attachment_uploader`: `uploaded_by` references `users`(`user_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_request_attachment_file` (`stored_file_name`)
- `uq_request_attachment_version` (`request_id`, `attachment_type`, `version_no`)

**CHECK constraints:** none

**Other indexes:**
- `idx_request_attachment_reviewer` (`verified_by`)
- `idx_request_attachment_uploader` (`uploaded_by`)

### `request_reschedule_history`

Immutable old/new schedule snapshot for each client-requested reschedule attempt.

**Primary key:** `reschedule_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `reschedule_id` | `bigint(20)` | no |  | auto increment |
| `request_id` | `int(11)` | no |  |  |
| `requested_by` | `int(11)` | yes | `NULL` |  |
| `old_date_needed` | `date` | no |  |  |
| `old_start_time` | `time` | yes | `NULL` |  |
| `old_end_time` | `time` | yes | `NULL` |  |
| `old_facility_slot` | `enum('AM','PM')` | yes | `NULL` |  |
| `new_date_needed` | `date` | no |  |  |
| `new_start_time` | `time` | no |  |  |
| `new_end_time` | `time` | no |  |  |
| `new_facility_slot` | `enum('AM','PM')` | no |  |  |
| `client_reason` | `varchar(1000)` | no |  |  |
| `status` | `enum('Pending','Approved','Rejected','Cancelled')` | no | `Pending` |  |
| `reviewed_by` | `int(11)` | yes | `NULL` |  |
| `reviewed_at` | `datetime` | yes | `NULL` |  |
| `review_remarks` | `varchar(1000)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `processed_at` | `datetime` | yes | `NULL` |  |
| `pending_request_id` | `int(11)` | yes | `NULL` | generated (stored): `case when `status` = 'Pending' then `request_id` else NULL end` |
| `encoded_by` | `int(11)` | yes | `NULL` |  |

**Foreign keys (4):**
- `fk_reschedule_encoder`: `encoded_by` references `users`(`user_id`), on delete RESTRICT
- `fk_reschedule_request`: `request_id` references `resource_requests`(`request_id`), on delete RESTRICT
- `fk_reschedule_requester`: `requested_by` references `users`(`user_id`), on delete RESTRICT
- `fk_reschedule_reviewer`: `reviewed_by` references `users`(`user_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_reschedule_pending_request` (`pending_request_id`)

**CHECK constraints:**
- `chk_reschedule_actor`: ``requested_by` is not null and `encoded_by` is null or `requested_by` is null and `encoded_by` is not null`
- `chk_reschedule_proposed_interval`: ``new_start_time` < `new_end_time` and (`new_facility_slot` = 'AM' and hour(`new_start_time`) < 12 or `new_facility_slot` = 'PM' and hour(`new_start_time`) >= 12)`

**Other indexes:**
- `fk_reschedule_encoder` (`encoded_by`)
- `idx_reschedule_requester` (`requested_by`)
- `idx_reschedule_request_created` (`request_id`, `created_at`, `reschedule_id`)
- `idx_reschedule_reviewer` (`reviewed_by`)
- `idx_reschedule_status_created` (`status`, `created_at`, `reschedule_id`)

### `return_submissions`

Return or post-use submission for a released request, with its inspection result.

**Primary key:** `return_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `return_id` | `int(11)` | no |  | auto increment |
| `request_id` | `int(11)` | no |  |  |
| `borrower_id` | `int(11)` | yes | `NULL` |  |
| `condition_notes` | `varchar(500)` | yes | `NULL` |  |
| `reported_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | yes | `NULL` |  |
| `status` | `enum('Pending','Approved','Rejected')` | no | `Pending` |  |
| `admin_id` | `int(11)` | yes | `NULL` |  |
| `admin_notes` | `varchar(500)` | yes | `NULL` |  |
| `inspection_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | yes | `NULL` |  |
| `inspection_remarks` | `varchar(500)` | yes | `NULL` |  |
| `submitted_at` | `datetime` | no | `current_timestamp()` |  |
| `reviewed_at` | `datetime` | yes | `NULL` |  |
| `submitted_by` | `int(11)` | yes | `NULL` |  |

**Foreign keys (4):**
- `fk_rs_admin`: `admin_id` references `users`(`user_id`), on delete SET NULL
- `fk_rs_borrower`: `borrower_id` references `users`(`user_id`), on delete RESTRICT
- `fk_rs_submitter`: `submitted_by` references `users`(`user_id`), on delete RESTRICT
- `return_submissions_ibfk_1`: `request_id` references `resource_requests`(`request_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_return_request` (`request_id`)

**CHECK constraints:**
- `chk_return_actor`: ``borrower_id` is not null or `submitted_by` is not null`

**Other indexes:**
- `fk_return_admin` (`admin_id`)
- `fk_return_borrower` (`borrower_id`)
- `fk_rs_submitter` (`submitted_by`)
- `idx_return_submissions_status_date` (`status`, `submitted_at`)

### `return_submission_items`

Per-line and per-unit return outcomes: Good, repairable conditions and Lost.

**Primary key:** `return_item_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `return_item_id` | `bigint(20)` | no |  | auto increment |
| `return_id` | `int(11)` | no |  |  |
| `request_item_id` | `bigint(20)` | no |  |  |
| `unit_id` | `bigint(20)` | yes | `NULL` |  |
| `reported_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | yes | `NULL` |  |
| `inspection_condition` | `enum('Good','Damaged','Missing Parts','Needs Repair','Lost')` | yes | `NULL` |  |
| `returned_quantity` | `int(11)` | no | `0` |  |
| `lost_quantity` | `int(11)` | no | `0` |  |
| `inspection_remarks` | `varchar(500)` | yes | `NULL` |  |
| `inspected_by` | `int(11)` | yes | `NULL` |  |
| `inspected_at` | `datetime` | yes | `NULL` |  |
| `stock_applied` | `tinyint(1)` | no | `0` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (4):**
- `fk_return_items_inspector`: `inspected_by` references `users`(`user_id`), on delete SET NULL
- `fk_return_items_line`: `request_item_id` references `request_items`(`request_item_id`), on delete RESTRICT
- `fk_return_items_return`: `return_id` references `return_submissions`(`return_id`), on delete CASCADE
- `fk_return_items_unit`: `unit_id` references `equipment_units`(`unit_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_return_items_unit` (`return_id`, `unit_id`)

**CHECK constraints:**
- `chk_return_items_quantities`: ``returned_quantity` >= 0 and `lost_quantity` >= 0 and `returned_quantity` + `lost_quantity` >= 1 and (`unit_id` is null or `returned_quantity` + `lost_quantity` = 1)`

**Other indexes:**
- `fk_return_items_inspector` (`inspected_by`)
- `fk_return_items_unit` (`unit_id`)
- `idx_return_items_line` (`request_item_id`, `return_id`)

### `return_submission_photos`

Proof photos attached to a return submission.

**Primary key:** `photo_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `photo_id` | `int(11)` | no |  | auto increment |
| `return_id` | `int(11)` | no |  |  |
| `filename` | `varchar(255)` | no |  |  |
| `mime_type` | `varchar(100)` | no |  |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (1):**
- `fk_rsp_return`: `return_id` references `return_submissions`(`return_id`), on delete CASCADE

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_return_photos_return` (`return_id`)

### `borrower_liabilities`

Non-financial accountability review of a damaged, incomplete or lost return detail.

**Primary key:** `liability_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `liability_id` | `bigint(20)` | no |  | auto increment |
| `request_id` | `int(11)` | no |  |  |
| `return_item_id` | `bigint(20)` | yes | `NULL` |  |
| `borrower_id` | `int(11)` | yes | `NULL` |  |
| `equipment_unit_id` | `bigint(20)` | yes | `NULL` |  |
| `accountability_type` | `enum('Damage','Loss','Missing Parts')` | no |  |  |
| `accountability_status` | `enum('Pending Review','Confirmed','Waived','Resolved')` | no | `Pending Review` |  |
| `reviewed_by` | `int(11)` | no |  |  |
| `reviewed_at` | `datetime` | no | `current_timestamp()` |  |
| `resolution_notes` | `varchar(1000)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` | updates on change |

**Foreign keys (5):**
- `fk_liability_borrower`: `borrower_id` references `users`(`user_id`), on delete RESTRICT
- `fk_liability_request`: `request_id` references `resource_requests`(`request_id`), on delete RESTRICT
- `fk_liability_return_item`: `return_item_id` references `return_submission_items`(`return_item_id`), on delete RESTRICT
- `fk_liability_reviewer`: `reviewed_by` references `users`(`user_id`), on delete RESTRICT
- `fk_liability_unit`: `equipment_unit_id` references `equipment_units`(`unit_id`), on delete RESTRICT

**UNIQUE constraints:**
- `uq_liability_return_item` (`return_item_id`)

**CHECK constraints:** none

**Other indexes:**
- `fk_liability_reviewer` (`reviewed_by`)
- `fk_liability_unit` (`equipment_unit_id`)
- `idx_liability_borrower` (`borrower_id`, `accountability_status`)
- `idx_liability_request` (`request_id`)

### `incident_reports`

Borrower-reported incidents about a resource and their GSO resolution.

**Primary key:** `incident_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `incident_id` | `int(11)` | no |  | auto increment |
| `reporter_id` | `int(11)` | no |  |  |
| `resource_id` | `int(11)` | yes | `NULL` |  |
| `incident_title` | `varchar(150)` | no |  |  |
| `incident_type` | `enum('Furniture','Equipment','Electrical','Door/Window','Plumbing','Facility','Safety','Other')` | no |  |  |
| `location` | `varchar(255)` | no |  |  |
| `description` | `text` | no |  |  |
| `photo_path` | `varchar(255)` | yes | `NULL` |  |
| `priority` | `enum('Low','Normal','High','Urgent')` | no | `Normal` |  |
| `status` | `enum('Submitted','Under Review','In Progress','Resolved','Rejected')` | no | `Submitted` |  |
| `assigned_to` | `int(11)` | yes | `NULL` |  |
| `admin_remarks` | `text` | yes | `NULL` |  |
| `resolution_notes` | `text` | yes | `NULL` |  |
| `reported_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` | updates on change |
| `resolved_at` | `datetime` | yes | `NULL` |  |

**Foreign keys (3):**
- `fk_incident_reports_assigned_to`: `assigned_to` references `users`(`user_id`), on delete SET NULL
- `fk_incident_reports_reporter`: `reporter_id` references `users`(`user_id`), on delete RESTRICT
- `fk_incident_reports_resource`: `resource_id` references `resources`(`resource_id`), on delete SET NULL

**UNIQUE constraints:**
- `uq_incident_reports_photo_path` (`photo_path`)

**CHECK constraints:** none

**Other indexes:**
- `idx_incident_reports_assignee_status` (`assigned_to`, `status`, `updated_at`)
- `idx_incident_reports_queue` (`status`, `priority`, `reported_at`)
- `idx_incident_reports_reported_at` (`reported_at`)
- `idx_incident_reports_reporter_date` (`reporter_id`, `reported_at`)
- `idx_incident_reports_resource_status_date` (`resource_id`, `status`, `reported_at`)
- `idx_incident_reports_type_date` (`incident_type`, `reported_at`)

### `preventive_maintenance_plans`

Recurring preventive maintenance policy: target, recurrence and next due date.

**Primary key:** `plan_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `plan_id` | `bigint(20)` | no |  | auto increment |
| `resource_id` | `int(11)` | no |  |  |
| `equipment_unit_id` | `bigint(20)` | yes | `NULL` |  |
| `quantity` | `int(11)` | no | `0` |  |
| `title` | `varchar(255)` | no |  |  |
| `recurrence` | `enum('Once','Monthly','Quarterly','Semiannual','Annual')` | no |  |  |
| `next_due_date` | `date` | no |  |  |
| `anchor_day` | `tinyint(3) unsigned` | no |  |  |
| `duration_days` | `int(11)` | no | `1` |  |
| `assigned_to` | `int(11)` | yes | `NULL` |  |
| `is_active` | `tinyint(1)` | no | `1` |  |
| `revision` | `int(11)` | no | `1` |  |
| `created_by` | `int(11)` | no |  |  |
| `updated_by` | `int(11)` | no |  |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (5):**
- `fk_plan_assignee`: `assigned_to` references `users`(`user_id`), on delete RESTRICT
- `fk_plan_creator`: `created_by` references `users`(`user_id`), on delete RESTRICT
- `fk_plan_resource`: `resource_id` references `resources`(`resource_id`), on delete RESTRICT
- `fk_plan_unit`: `equipment_unit_id` references `equipment_units`(`unit_id`), on delete RESTRICT
- `fk_plan_updater`: `updated_by` references `users`(`user_id`), on delete RESTRICT

**UNIQUE constraints:** none

**CHECK constraints:**
- `chk_plan_values`: ``quantity` >= 0 and `duration_days` between 1 and 365 and `anchor_day` between 1 and 31 and `revision` >= 1 and `is_active` in (0,1)`

**Other indexes:**
- `fk_plan_assignee` (`assigned_to`)
- `fk_plan_creator` (`created_by`)
- `fk_plan_resource` (`resource_id`)
- `fk_plan_unit` (`equipment_unit_id`)
- `fk_plan_updater` (`updated_by`)
- `idx_preventive_due` (`is_active`, `next_due_date`)

### `maintenance_schedules`

Actual maintenance work orders (Corrective or Preventive). Completed and Cancelled rows are the maintenance history.

**Primary key:** `maintenance_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `maintenance_id` | `int(11)` | no |  | auto increment |
| `resource_id` | `int(11)` | no |  |  |
| `quantity` | `int(11)` | no | `0` |  |
| `source_return_id` | `int(11)` | yes | `NULL` |  |
| `source_return_item_id` | `bigint(20)` | yes | `NULL` |  |
| `incident_id` | `int(11)` | yes | `NULL` |  |
| `equipment_unit_id` | `bigint(20)` | yes | `NULL` |  |
| `assigned_to` | `int(11)` | yes | `NULL` |  |
| `start_date` | `date` | no |  |  |
| `end_date` | `date` | no |  |  |
| `duration_days` | `int(11)` | no | `1` |  |
| `reason` | `varchar(255)` | no |  |  |
| `remarks` | `varchar(500)` | yes | `NULL` |  |
| `completion_notes` | `varchar(1000)` | yes | `NULL` |  |
| `status` | `enum('Scheduled','In Progress','Completed','Cancelled')` | no | `Scheduled` |  |
| `created_by` | `int(11)` | no |  |  |
| `updated_by` | `int(11)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | yes | `NULL` |  |
| `completed_at` | `datetime` | yes | `NULL` |  |
| `restored_at` | `datetime` | yes | `NULL` |  |
| `maintenance_type` | `enum('Corrective','Preventive')` | yes | `NULL` |  |
| `preventive_plan_id` | `bigint(20)` | yes | `NULL` |  |
| `plan_occurrence_date` | `date` | yes | `NULL` |  |
| `started_at` | `datetime` | yes | `NULL` |  |

**Foreign keys (9):**
- `fk_maintenance_plan`: `preventive_plan_id` references `preventive_maintenance_plans`(`plan_id`), on delete RESTRICT
- `fk_ms_assigned_to`: `assigned_to` references `users`(`user_id`), on delete SET NULL
- `fk_ms_created_by`: `created_by` references `users`(`user_id`), on delete RESTRICT
- `fk_ms_equipment_unit`: `equipment_unit_id` references `equipment_units`(`unit_id`), on delete SET NULL
- `fk_ms_incident`: `incident_id` references `incident_reports`(`incident_id`), on delete SET NULL
- `fk_ms_resource`: `resource_id` references `resources`(`resource_id`), on delete RESTRICT
- `fk_ms_source_return`: `source_return_id` references `return_submissions`(`return_id`), on delete SET NULL
- `fk_ms_source_return_item`: `source_return_item_id` references `return_submission_items`(`return_item_id`), on delete SET NULL
- `fk_ms_updated_by`: `updated_by` references `users`(`user_id`), on delete SET NULL

**UNIQUE constraints:**
- `uq_preventive_occurrence` (`preventive_plan_id`, `plan_occurrence_date`)

**CHECK constraints:**
- `chk_preventive_origin`: ``maintenance_type` is null or `maintenance_type` <> 'Preventive' or `preventive_plan_id` is not null and `plan_occurrence_date` is not null`

**Other indexes:**
- `fk_ms_created_by` (`created_by`)
- `fk_ms_updated_by` (`updated_by`)
- `idx_maintenance_dates` (`start_date`, `end_date`)
- `idx_maintenance_resource` (`resource_id`)
- `idx_maintenance_status` (`status`)
- `idx_ms_assigned_to` (`assigned_to`)
- `idx_ms_equipment_unit_id` (`equipment_unit_id`)
- `idx_ms_incident_id` (`incident_id`)
- `idx_ms_source_return_id` (`source_return_id`)
- `idx_ms_source_return_item_id` (`source_return_item_id`)

### `notifications`

In-app notifications for registered users.

**Primary key:** `notification_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `notification_id` | `int(11)` | no |  | auto increment |
| `user_id` | `int(11)` | no |  |  |
| `type` | `varchar(50)` | no |  |  |
| `title` | `varchar(120)` | no |  |  |
| `message` | `varchar(255)` | no |  |  |
| `link` | `varchar(255)` | yes | `NULL` |  |
| `is_read` | `tinyint(1)` | no | `0` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (1):**
- `fk_notif_user`: `user_id` references `users`(`user_id`), on delete CASCADE

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_notifications_user_read_date` (`user_id`, `is_read`, `created_at`)

### `activity_logs`

Audit trail of operational actions, including client email delivery markers.

**Primary key:** `log_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `log_id` | `int(11)` | no |  | auto increment |
| `user_id` | `int(11)` | yes | `NULL` |  |
| `action` | `varchar(100)` | no |  |  |
| `details` | `varchar(255)` | yes | `NULL` |  |
| `log_date` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (1):**
- `fk_al_user`: `user_id` references `users`(`user_id`), on delete SET NULL

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_activity_logs_user_date` (`user_id`, `log_date`)

### `login_attempts`

Ephemeral login throttling state keyed by a one-way hash.

**Primary key:** `attempt_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `attempt_id` | `bigint(20)` | no |  | auto increment |
| `attempt_key` | `varchar(190)` | no |  |  |
| `attempted_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (0):** none

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_login_attempts_key_time` (`attempt_key`, `attempted_at`)

### `system_settings`

Key/value settings: facility operating hours and the recorded schema version.

**Primary key:** `setting_key`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `setting_key` | `varchar(64)` | no |  |  |
| `setting_value` | `varchar(255)` | yes | `NULL` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (0):** none

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:** none

### `ai_conversations`

Chatbot conversations per user.

**Primary key:** `conversation_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `conversation_id` | `bigint(20)` | no |  | auto increment |
| `user_id` | `int(11)` | no |  |  |
| `title` | `varchar(160)` | no | `New conversation` |  |
| `status` | `enum('Active','Archived')` | no | `Active` |  |
| `started_at` | `datetime` | no | `current_timestamp()` |  |
| `ended_at` | `datetime` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (1):**
- `fk_ai_conversations_user`: `user_id` references `users`(`user_id`), on delete CASCADE

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_ai_conversations_user` (`user_id`, `status`, `updated_at`)

### `ai_messages`

Messages within a chatbot conversation.

**Primary key:** `message_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `message_id` | `bigint(20)` | no |  | auto increment |
| `conversation_id` | `bigint(20)` | no |  |  |
| `sender_type` | `enum('user','assistant','system')` | no |  |  |
| `message` | `mediumtext` | no |  |  |
| `intent` | `varchar(60)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (1):**
- `fk_ai_messages_conversation`: `conversation_id` references `ai_conversations`(`conversation_id`), on delete CASCADE

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `idx_ai_messages_conversation` (`conversation_id`, `message_id`)

### `ai_knowledge_entries`

Curated knowledge-base answers for the chatbot.

**Primary key:** `entry_id`

| Field | Type | Null | Default | Notes |
|---|---|---|---|---|
| `entry_id` | `int(11)` | no |  | auto increment |
| `title` | `varchar(160)` | no |  |  |
| `category` | `varchar(60)` | no | `General` |  |
| `question` | `varchar(255)` | no |  |  |
| `content` | `text` | no |  |  |
| `keywords` | `varchar(500)` | no |  |  |
| `status` | `enum('Active','Inactive')` | no | `Active` |  |
| `created_by` | `int(11)` | yes | `NULL` |  |
| `updated_by` | `int(11)` | yes | `NULL` |  |
| `created_at` | `datetime` | no | `current_timestamp()` |  |
| `updated_at` | `datetime` | no | `current_timestamp()` |  |

**Foreign keys (2):**
- `fk_ai_knowledge_created_by`: `created_by` references `users`(`user_id`), on delete SET NULL
- `fk_ai_knowledge_updated_by`: `updated_by` references `users`(`user_id`), on delete SET NULL

**UNIQUE constraints:** none

**CHECK constraints:** none

**Other indexes:**
- `fk_ai_knowledge_created_by` (`created_by`)
- `fk_ai_knowledge_updated_by` (`updated_by`)
- `idx_ai_knowledge_status` (`status`, `category`)
