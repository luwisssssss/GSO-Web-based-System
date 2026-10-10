-- R5: request-scoped accountless walk-in identity and honest operational actors.
ALTER TABLE resource_requests
    MODIFY borrower_id INT(11) NULL,
    ADD COLUMN IF NOT EXISTS requester_type ENUM('Registered','Walk-in') NOT NULL DEFAULT 'Registered',
    ADD COLUMN IF NOT EXISTS walkin_full_name VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS walkin_organization VARCHAR(200) NULL,
    ADD COLUMN IF NOT EXISTS walkin_email VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS walkin_contact_number VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(1000) NULL,
    ADD COLUMN IF NOT EXISTS encoded_by INT(11) NULL,
    ADD CONSTRAINT fk_rr_encoder FOREIGN KEY(encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_requester_mode CHECK (
        (requester_type='Registered' AND borrower_id IS NOT NULL AND encoded_by IS NULL
         AND walkin_full_name IS NULL AND walkin_organization IS NULL AND walkin_email IS NULL AND walkin_contact_number IS NULL)
        OR (requester_type='Walk-in' AND borrower_id IS NULL AND encoded_by IS NOT NULL
            AND walkin_full_name IS NOT NULL AND CHAR_LENGTH(TRIM(walkin_full_name))>0)
    );
ALTER TABLE return_submissions
    MODIFY borrower_id INT(11) NULL,
    ADD COLUMN IF NOT EXISTS submitted_by INT(11) NULL,
    ADD CONSTRAINT fk_rs_submitter FOREIGN KEY(submitted_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_return_actor CHECK (borrower_id IS NOT NULL OR submitted_by IS NOT NULL);
ALTER TABLE borrower_liabilities MODIFY borrower_id INT(11) NULL;
ALTER TABLE request_reschedule_history
    MODIFY requested_by INT(11) NULL,
    ADD COLUMN IF NOT EXISTS encoded_by INT(11) NULL,
    ADD CONSTRAINT fk_reschedule_encoder FOREIGN KEY(encoded_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_reschedule_actor CHECK (
        (requested_by IS NOT NULL AND encoded_by IS NULL) OR (requested_by IS NULL AND encoded_by IS NOT NULL)
    );
