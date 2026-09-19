-- Sanitized baseline schema for new installations.
-- Contains no user accounts, password hashes, requests, evidence, or operational data.
-- Create/select the target database before importing this file.

SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `activity_logs` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `details` varchar(255) DEFAULT NULL,
  `log_date` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`log_id`),
  KEY `idx_activity_logs_user_date` (`user_id`,`log_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `login_attempts` (
  `attempt_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attempt_key` varchar(190) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_key_time` (`attempt_key`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `maintenance_schedules` (
  `maintenance_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `duration_days` int(11) NOT NULL DEFAULT 1,
  `reason` varchar(255) NOT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `status` enum('Scheduled','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`maintenance_id`),
  KEY `idx_maintenance_resource` (`resource_id`),
  KEY `idx_maintenance_status` (`status`),
  KEY `idx_maintenance_dates` (`start_date`,`end_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  KEY `idx_notifications_user_read_date` (`user_id`,`is_read`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resource_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `borrower_id` int(11) NOT NULL,
  `resource_id` int(11) NOT NULL,
  `quantity` int(11) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `date_needed` date DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `request_date` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('Pending','Under Review','Approved','Rejected','Cancelled','Released','Returned') NOT NULL DEFAULT 'Pending',
  `adviser_state` enum('Not Required','Selection Required','Pending Adviser','Revision Required','Endorsed') NOT NULL DEFAULT 'Not Required',
  `adviser_user_id` int(11) DEFAULT NULL,
  `adviser_required_by` int(11) DEFAULT NULL,
  `adviser_required_at` datetime DEFAULT NULL,
  `director_state` enum('Not Required','Pending','Approved','Rejected') NOT NULL DEFAULT 'Not Required',
  `director_required_by` int(11) DEFAULT NULL,
  `director_required_at` datetime DEFAULT NULL,
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
  PRIMARY KEY (`request_id`),
  KEY `fk_resource_requests_resource` (`resource_id`),
  KEY `fk_resource_requests_approved_by` (`approved_by`),
  KEY `fk_resource_requests_reviewed_by` (`reviewed_by`),
  KEY `fk_resource_requests_released_by` (`released_by`),
  KEY `idx_rr_director_queue` (`director_state`,`status`,`director_required_at`),
  KEY `idx_requests_borrower_return` (`borrower_id`,`return_date`),
  KEY `idx_requests_status_due` (`status`,`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_adviser_history` (
  `history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `adviser_user_id` int(11) NOT NULL,
  `cycle_no` int(11) NOT NULL,
  `action` enum('Submitted','Endorsed','Returned for Revision') NOT NULL,
  `remarks` varchar(1000) DEFAULT NULL,
  `acted_by` int(11) NOT NULL,
  `acted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `idx_adviser_history_request` (`request_id`,`acted_at`,`history_id`),
  KEY `idx_adviser_history_adviser` (`adviser_user_id`,`acted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `request_director_history` (
  `director_history_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `director_user_id` int(11) NOT NULL,
  `action` enum('Approved','Rejected','Prioritized','Rejected by Prioritization') NOT NULL,
  `remarks` varchar(1000) DEFAULT NULL,
  `decision_batch` char(36) DEFAULT NULL,
  `winner_request_id` int(11) DEFAULT NULL,
  `decided_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`director_history_id`),
  KEY `idx_director_history_request` (`request_id`,`decided_at`,`director_history_id`),
  KEY `idx_director_history_actor` (`director_user_id`,`decided_at`),
  KEY `idx_director_history_batch` (`decision_batch`,`director_history_id`),
  KEY `idx_director_history_winner` (`winner_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `resources` (
  `resource_id` int(11) NOT NULL AUTO_INCREMENT,
  `resource_name` varchar(100) NOT NULL,
  `resource_type` enum('Item','Facility') NOT NULL,
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
  PRIMARY KEY (`resource_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `return_submission_photos` (
  `photo_id` int(11) NOT NULL AUTO_INCREMENT,
  `return_id` int(11) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`photo_id`),
  KEY `idx_return_photos_return` (`return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `return_submissions` (
  `return_id` int(11) NOT NULL AUTO_INCREMENT,
  `request_id` int(11) NOT NULL,
  `borrower_id` int(11) NOT NULL,
  `condition_notes` varchar(500) DEFAULT NULL,
  `reported_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `status` enum('Pending','Approved','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_id` int(11) DEFAULT NULL,
  `admin_notes` varchar(500) DEFAULT NULL,
  `inspection_condition` enum('Good','Damaged','Missing Parts','Needs Repair','Lost') DEFAULT NULL,
  `inspection_remarks` varchar(500) DEFAULT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`return_id`),
  UNIQUE KEY `uq_return_request` (`request_id`),
  KEY `idx_return_submissions_status_date` (`status`,`submitted_at`),
  KEY `fk_return_borrower` (`borrower_id`),
  KEY `fk_return_admin` (`admin_id`),
  CONSTRAINT `return_submissions_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  `role` enum('Admin','Borrower','GSO Staff','Director') NOT NULL DEFAULT 'Borrower',
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
  KEY `fk_users_approved_by` (`approved_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
  CONSTRAINT `fk_incident_reports_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_incident_reports_title_length` CHECK (char_length(trim(`incident_title`)) between 5 and 150),
  CONSTRAINT `chk_incident_reports_location_not_blank` CHECK (char_length(trim(`location`)) > 0),
  CONSTRAINT `chk_incident_reports_description_length` CHECK (char_length(trim(`description`)) between 10 and 5000),
  CONSTRAINT `chk_incident_reports_resolved_time` CHECK (`resolved_at` is null or `resolved_at` >= `reported_at`),
  CONSTRAINT `chk_incident_reports_resolution_required` CHECK (`status` <> 'Resolved' or `resolved_at` is not null and `resolution_notes` is not null and char_length(trim(`resolution_notes`)) > 0),
  CONSTRAINT `chk_incident_reports_rejection_remarks` CHECK (`status` <> 'Rejected' or `admin_remarks` is not null and char_length(trim(`admin_remarks`)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `activity_logs`
  ADD CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `maintenance_schedules`
  ADD CONSTRAINT `fk_ms_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ms_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_ms_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `resource_requests`
  ADD CONSTRAINT `fk_rr_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_rr_resource` FOREIGN KEY (`resource_id`) REFERENCES `resources` (`resource_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_rr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_released_by` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rr_adviser_user` FOREIGN KEY (`adviser_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_rr_adviser_required_by` FOREIGN KEY (`adviser_required_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `resource_requests`
  ADD CONSTRAINT `fk_rr_director_required_by` FOREIGN KEY (`director_required_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `request_adviser_history`
  ADD CONSTRAINT `fk_adviser_history_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_adviser_history_adviser` FOREIGN KEY (`adviser_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_adviser_history_actor` FOREIGN KEY (`acted_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT;

ALTER TABLE `request_director_history`
  ADD CONSTRAINT `fk_director_history_request` FOREIGN KEY (`request_id`) REFERENCES `resource_requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_director_history_actor` FOREIGN KEY (`director_user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_director_history_winner` FOREIGN KEY (`winner_request_id`) REFERENCES `resource_requests` (`request_id`) ON DELETE SET NULL;

ALTER TABLE `return_submission_photos`
  ADD CONSTRAINT `fk_rsp_return` FOREIGN KEY (`return_id`) REFERENCES `return_submissions` (`return_id`) ON DELETE CASCADE;

ALTER TABLE `return_submissions`
  ADD CONSTRAINT `fk_rs_borrower` FOREIGN KEY (`borrower_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_rs_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = @OLD_FOREIGN_KEY_CHECKS;

CREATE TABLE IF NOT EXISTS `ai_conversations` (
    `conversation_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `user_id` INT(11) NOT NULL,
    `title` VARCHAR(160) NOT NULL DEFAULT 'New conversation',
    `status` ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`conversation_id`),
    KEY `idx_ai_conversations_user` (`user_id`, `status`, `updated_at`),
    CONSTRAINT `fk_ai_conversations_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
    `message_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `conversation_id` BIGINT(20) NOT NULL,
    `sender_type` ENUM('user', 'assistant', 'system') NOT NULL,
    `message` MEDIUMTEXT NOT NULL,
    `intent` VARCHAR(60) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`message_id`),
    KEY `idx_ai_messages_conversation` (`conversation_id`, `message_id`),
    CONSTRAINT `fk_ai_messages_conversation`
        FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`conversation_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_knowledge_entries` (
    `entry_id` INT(11) NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(160) NOT NULL,
    `category` VARCHAR(60) NOT NULL DEFAULT 'General',
    `question` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `keywords` VARCHAR(500) NOT NULL DEFAULT '',
    `status` ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    `created_by` INT(11) DEFAULT NULL,
    `updated_by` INT(11) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`entry_id`),
    KEY `idx_ai_knowledge_status` (`status`, `category`),
    CONSTRAINT `fk_ai_knowledge_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ai_knowledge_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
