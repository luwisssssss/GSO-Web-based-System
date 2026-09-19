-- Step 8 stabilization: preserve request review/approval accountability separately
-- from the physical release actor and timestamp.

ALTER TABLE `resource_requests`
    ADD COLUMN `released_by` INT(11) NULL AFTER `reviewed_by`,
    ADD COLUMN `released_at` DATETIME NULL AFTER `reviewed_at`,
    ADD KEY `fk_resource_requests_released_by` (`released_by`);

-- Legacy code stored the release actor/time in approved_by/approved_at.
-- This is the best available historical backfill; original approval actors cannot
-- be reconstructed when a different releaser overwrote them.
UPDATE `resource_requests`
SET
    `reviewed_by` = COALESCE(`reviewed_by`, `approved_by`),
    `reviewed_at` = COALESCE(`reviewed_at`, `approved_at`)
WHERE `status` IN ('Approved', 'Rejected', 'Released', 'Returned')
  AND (`reviewed_by` IS NULL OR `reviewed_at` IS NULL);

UPDATE `resource_requests`
SET
    `released_by` = COALESCE(`released_by`, `approved_by`),
    `released_at` = COALESCE(`released_at`, `approved_at`)
WHERE `status` IN ('Released', 'Returned')
  AND (`released_by` IS NULL OR `released_at` IS NULL);

ALTER TABLE `resource_requests`
    ADD CONSTRAINT `fk_rr_released_by`
        FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL;
