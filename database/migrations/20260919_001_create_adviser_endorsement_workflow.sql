ALTER TABLE resource_requests
    ADD COLUMN adviser_state ENUM('Not Required','Selection Required','Pending Adviser','Revision Required','Endorsed') NOT NULL DEFAULT 'Not Required' AFTER status,
    ADD COLUMN adviser_user_id INT NULL AFTER adviser_state,
    ADD COLUMN adviser_required_by INT NULL AFTER adviser_user_id,
    ADD COLUMN adviser_required_at DATETIME NULL AFTER adviser_required_by,
    ADD KEY idx_rr_adviser_queue (adviser_user_id, adviser_state),
    ADD KEY fk_rr_adviser_required_by (adviser_required_by),
    ADD CONSTRAINT fk_rr_adviser_user FOREIGN KEY (adviser_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_rr_adviser_required_by FOREIGN KEY (adviser_required_by) REFERENCES users(user_id) ON DELETE SET NULL;

CREATE TABLE request_adviser_history (
    history_id BIGINT NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    adviser_user_id INT NOT NULL,
    cycle_no INT NOT NULL,
    action ENUM('Submitted','Endorsed','Returned for Revision') NOT NULL,
    remarks VARCHAR(1000) NULL,
    acted_by INT NOT NULL,
    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (history_id),
    KEY idx_adviser_history_request (request_id, acted_at, history_id),
    KEY idx_adviser_history_adviser (adviser_user_id, acted_at),
    CONSTRAINT fk_adviser_history_request FOREIGN KEY (request_id) REFERENCES resource_requests(request_id) ON DELETE CASCADE,
    CONSTRAINT fk_adviser_history_adviser FOREIGN KEY (adviser_user_id) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_adviser_history_actor FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
