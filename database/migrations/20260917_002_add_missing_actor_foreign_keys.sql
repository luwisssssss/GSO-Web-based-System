-- Step 8 stabilization: enforce actor relationships that older installations
-- indexed but did not constrain. Run after confirming the four orphan checks are zero.

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

ALTER TABLE `resource_requests`
    ADD CONSTRAINT `fk_rr_reviewed_by`
        FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;

ALTER TABLE `maintenance_schedules`
    ADD CONSTRAINT `fk_ms_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`)
        ON DELETE RESTRICT,
    ADD CONSTRAINT `fk_ms_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;
