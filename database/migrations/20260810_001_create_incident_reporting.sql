-- Incident Reporting module: core incident table.
-- Target: MariaDB 10.4+.
-- The target database must already contain `users` and `resources`.
-- This migration intentionally does not change resource or maintenance state.

CREATE TABLE `incident_reports` (
    `incident_id` INT(11) NOT NULL AUTO_INCREMENT,
    `reporter_id` INT(11) NOT NULL,
    `resource_id` INT(11) DEFAULT NULL,
    `incident_title` VARCHAR(150) NOT NULL,
    `incident_type` ENUM(
        'Furniture',
        'Equipment',
        'Electrical',
        'Door/Window',
        'Plumbing',
        'Facility',
        'Safety',
        'Other'
    ) NOT NULL,
    `location` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `priority` ENUM(
        'Low',
        'Normal',
        'High',
        'Urgent'
    ) NOT NULL DEFAULT 'Normal',
    `status` ENUM(
        'Submitted',
        'Under Review',
        'In Progress',
        'Resolved',
        'Rejected'
    ) NOT NULL DEFAULT 'Submitted',
    `assigned_to` INT(11) DEFAULT NULL,
    `admin_remarks` TEXT DEFAULT NULL,
    `resolution_notes` TEXT DEFAULT NULL,
    `reported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `resolved_at` DATETIME DEFAULT NULL,

    PRIMARY KEY (`incident_id`),
    UNIQUE KEY `uq_incident_reports_photo_path` (`photo_path`),
    KEY `idx_incident_reports_reporter_date` (`reporter_id`, `reported_at`),
    KEY `idx_incident_reports_resource_status_date` (`resource_id`, `status`, `reported_at`),
    KEY `idx_incident_reports_queue` (`status`, `priority`, `reported_at`),
    KEY `idx_incident_reports_assignee_status` (`assigned_to`, `status`, `updated_at`),
    KEY `idx_incident_reports_type_date` (`incident_type`, `reported_at`),
    KEY `idx_incident_reports_reported_at` (`reported_at`),

    CONSTRAINT `fk_incident_reports_reporter`
        FOREIGN KEY (`reporter_id`)
        REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT `fk_incident_reports_resource`
        FOREIGN KEY (`resource_id`)
        REFERENCES `resources` (`resource_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    CONSTRAINT `fk_incident_reports_assigned_to`
        FOREIGN KEY (`assigned_to`)
        REFERENCES `users` (`user_id`)
        ON UPDATE CASCADE
        ON DELETE SET NULL,

    CONSTRAINT `chk_incident_reports_title_length`
        CHECK (CHAR_LENGTH(TRIM(`incident_title`)) BETWEEN 5 AND 150),
    CONSTRAINT `chk_incident_reports_location_not_blank`
        CHECK (CHAR_LENGTH(TRIM(`location`)) > 0),
    CONSTRAINT `chk_incident_reports_description_length`
        CHECK (CHAR_LENGTH(TRIM(`description`)) BETWEEN 10 AND 5000),
    CONSTRAINT `chk_incident_reports_resolved_time`
        CHECK (`resolved_at` IS NULL OR `resolved_at` >= `reported_at`),
    CONSTRAINT `chk_incident_reports_resolution_required`
        CHECK (
            `status` <> 'Resolved'
            OR (
                `resolved_at` IS NOT NULL
                AND `resolution_notes` IS NOT NULL
                AND CHAR_LENGTH(TRIM(`resolution_notes`)) > 0
            )
        ),
    CONSTRAINT `chk_incident_reports_rejection_remarks`
        CHECK (
            `status` <> 'Rejected'
            OR (
                `admin_remarks` IS NOT NULL
                AND CHAR_LENGTH(TRIM(`admin_remarks`)) > 0
            )
        )
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
