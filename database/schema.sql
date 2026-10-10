-- GSO Request and Maintenance Management System
-- Complete current schema, version 20261010_007: 22 tables, 57 foreign keys.
--
-- FRESH INSTALL ONLY. Import into an EMPTY database, or run scripts/migrate.php
-- against an empty database, which loads this file. The plain CREATE TABLE
-- statements fail on purpose if the tables already exist.
--
-- An EXISTING installation must not import this file. Run scripts/migrate.php,
-- which applies the ordered files in database/migrations and records the
-- version in system_settings.schema_version.
--
-- This file contains structure and two operating-hour defaults only: no users,
-- password hashes, requests or evidence.

-- users: Accounts for every role: Borrower, GSO Staff, Admin (GSO Head), Director and Super Admin (MIS).
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- resources: Facilities and inventory items with their tracking mode, stock counters and condition.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- equipment_units: Individually tracked physical units of Serialized equipment.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- resource_requests: Request header: requester (registered borrower or walk-in snapshot), schedule, status and audit actors.
CREATE TABLE `resource_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `borrower_id` int(11) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `date_needed` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `request_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Under Review','Approved','Rejected','Cancelled','Released','Return','Returned','Completed') NOT NULL DEFAULT 'Pending',
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
  CONSTRAINT `chk_requester_mode` CHECK (`requester_type` = 'Registered' and `borrower_id` is not null and `encoded_by` is null and `walkin_full_name` is null and `walkin_organization` is null and `walkin_email` is null and `walkin_contact_number` is null or `requester_type` = 'Walk-in' and `borrower_id` is null and `encoded_by` is not null and `walkin_full_name` is not null and char_length(trim(`walkin_full_name`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- request_items: Resource lines of a request. The only store of requested resources and quantities.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- request_item_units: Physical units issued for a serialized request line, with their return time.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- request_attachments: Versioned manually signed facility request letters and their verification result.
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

-- request_reschedule_history: Immutable old/new schedule snapshot for each client-requested reschedule attempt.
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

-- return_submissions: Return or post-use submission for a released request, with its inspection result.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- return_submission_items: Per-line and per-unit return outcomes: Good, repairable conditions and Lost.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- return_submission_photos: Proof photos attached to a return submission.
CREATE TABLE `return_submission_photos` (
  `photo_id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_id`),
  KEY `idx_return_photos_return` (`return_id`),
  CONSTRAINT `fk_rsp_return` FOREIGN KEY (`return_id`) REFERENCES `return_submissions` (`return_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- borrower_liabilities: Non-financial accountability review of a damaged, incomplete or lost return detail.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- incident_reports: Borrower-reported incidents about a resource and their GSO resolution.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- preventive_maintenance_plans: Recurring preventive maintenance policy: target, recurrence and next due date.
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

-- maintenance_schedules: Actual maintenance work orders (Corrective or Preventive). Completed and Cancelled rows are the maintenance history.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- notifications: In-app notifications for registered users.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- activity_logs: Audit trail of operational actions.
CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `log_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_activity_logs_user_date` (`user_id`,`log_date`),
  CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- login_attempts: Ephemeral login throttling state keyed by a one-way hash.
CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attempt_key` varchar(190) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_key_time` (`attempt_key`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- system_settings: Key/value settings: facility operating hours and the recorded schema version.
CREATE TABLE `system_settings` (
  `setting_key` varchar(64) NOT NULL,
  `setting_value` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ai_conversations: Chatbot conversations per user.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ai_messages: Messages within a chatbot conversation.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ai_knowledge_entries: Curated knowledge-base answers for the chatbot.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Normal campus operating hours for facility reservations.
INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('facility_operating_start', '08:00'),
    ('facility_operating_end', '17:00');

-- Version of this schema. The application refuses to run against any other version.
INSERT INTO system_settings (setting_key, setting_value) VALUES ('schema_version', '20261010_007');
