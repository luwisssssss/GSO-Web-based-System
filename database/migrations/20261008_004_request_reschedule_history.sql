-- R4: one immutable old/new snapshot per client-requested schedule attempt.
CREATE TABLE IF NOT EXISTS request_reschedule_history (
    reschedule_id BIGINT(20) NOT NULL AUTO_INCREMENT,
    request_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    old_date_needed DATE NOT NULL,
    old_start_time TIME NULL,
    old_end_time TIME NULL,
    old_facility_slot ENUM('AM','PM') NULL,
    new_date_needed DATE NOT NULL,
    new_start_time TIME NOT NULL,
    new_end_time TIME NOT NULL,
    new_facility_slot ENUM('AM','PM') NOT NULL,
    client_reason VARCHAR(1000) NOT NULL,
    status ENUM('Pending','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Pending',
    reviewed_by INT(11) NULL,
    reviewed_at DATETIME NULL,
    review_remarks VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    pending_request_id INT(11) GENERATED ALWAYS AS (CASE WHEN status='Pending' THEN request_id ELSE NULL END) STORED,
    PRIMARY KEY (reschedule_id),
    UNIQUE KEY uq_reschedule_pending_request (pending_request_id),
    KEY idx_reschedule_request_created (request_id,created_at,reschedule_id),
    KEY idx_reschedule_status_created (status,created_at,reschedule_id),
    KEY idx_reschedule_requester (requested_by),
    KEY idx_reschedule_reviewer (reviewed_by),
    CONSTRAINT chk_reschedule_proposed_interval CHECK (
        new_start_time < new_end_time AND
        ((new_facility_slot='AM' AND HOUR(new_start_time)<12) OR
         (new_facility_slot='PM' AND HOUR(new_start_time)>=12))
    ),
    CONSTRAINT fk_reschedule_request FOREIGN KEY (request_id) REFERENCES resource_requests(request_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reschedule_requester FOREIGN KEY (requested_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_reschedule_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
