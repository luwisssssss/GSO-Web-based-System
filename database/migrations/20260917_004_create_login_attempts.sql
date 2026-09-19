-- Step 8 security stabilization: persistent login throttling that cannot be
-- bypassed by discarding the browser session. Keys are one-way hashes.

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `attempt_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
    `attempt_key` VARCHAR(190) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`attempt_id`),
    KEY `idx_login_attempts_key_time` (`attempt_key`, `attempted_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
