-- Phase 3: Campus Director routing and append-only decision history.
-- Existing requests remain safely classified as Director approval not required.
ALTER TABLE resource_requests
    ADD COLUMN IF NOT EXISTS director_state
        ENUM('Not Required','Pending','Approved','Rejected')
        NOT NULL DEFAULT 'Not Required' AFTER adviser_required_at,
    ADD COLUMN IF NOT EXISTS director_required_by INT NULL AFTER director_state,
    ADD COLUMN IF NOT EXISTS director_required_at DATETIME NULL AFTER director_required_by,
    ADD INDEX IF NOT EXISTS idx_rr_director_queue (director_state, status, director_required_at);

CREATE TABLE IF NOT EXISTS request_director_history (
    director_history_id BIGINT NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    director_user_id INT NOT NULL,
    action ENUM('Approved','Rejected','Prioritized','Rejected by Prioritization') NOT NULL,
    remarks VARCHAR(1000) NULL,
    decision_batch CHAR(36) NULL,
    winner_request_id INT NULL,
    decided_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (director_history_id),
    KEY idx_director_history_request (request_id, decided_at, director_history_id),
    KEY idx_director_history_actor (director_user_id, decided_at),
    KEY idx_director_history_batch (decision_batch, director_history_id),
    KEY idx_director_history_winner (winner_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @phase3_fk_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='resource_requests' AND CONSTRAINT_NAME='fk_rr_director_required_by'),
    'SELECT 1',
    'ALTER TABLE resource_requests ADD CONSTRAINT fk_rr_director_required_by FOREIGN KEY (director_required_by) REFERENCES users(user_id) ON DELETE SET NULL'
);
PREPARE phase3_stmt FROM @phase3_fk_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @phase3_fk_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='request_director_history' AND CONSTRAINT_NAME='fk_director_history_request'),
    'SELECT 1',
    'ALTER TABLE request_director_history ADD CONSTRAINT fk_director_history_request FOREIGN KEY (request_id) REFERENCES resource_requests(request_id) ON DELETE CASCADE'
);
PREPARE phase3_stmt FROM @phase3_fk_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @phase3_fk_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='request_director_history' AND CONSTRAINT_NAME='fk_director_history_actor'),
    'SELECT 1',
    'ALTER TABLE request_director_history ADD CONSTRAINT fk_director_history_actor FOREIGN KEY (director_user_id) REFERENCES users(user_id) ON DELETE RESTRICT'
);
PREPARE phase3_stmt FROM @phase3_fk_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;

SET @phase3_fk_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='request_director_history' AND CONSTRAINT_NAME='fk_director_history_winner'),
    'SELECT 1',
    'ALTER TABLE request_director_history ADD CONSTRAINT fk_director_history_winner FOREIGN KEY (winner_request_id) REFERENCES resource_requests(request_id) ON DELETE SET NULL'
);
PREPARE phase3_stmt FROM @phase3_fk_sql; EXECUTE phase3_stmt; DEALLOCATE PREPARE phase3_stmt;
