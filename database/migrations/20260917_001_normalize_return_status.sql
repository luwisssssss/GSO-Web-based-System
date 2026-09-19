-- Step 8 stabilization: align legacy return workflow status values with the application.
-- Safe to re-run. Existing `Under Review` rows represent submitted returns awaiting GSO review.

ALTER TABLE `return_submissions`
    MODIFY `status` ENUM('Pending', 'Under Review', 'Approved', 'Rejected')
        NOT NULL DEFAULT 'Pending';

UPDATE `return_submissions`
SET `status` = 'Pending'
WHERE `status` = 'Under Review';

ALTER TABLE `return_submissions`
    MODIFY `status` ENUM('Pending', 'Approved', 'Rejected')
        NOT NULL DEFAULT 'Pending';
