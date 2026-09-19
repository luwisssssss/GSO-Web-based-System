-- Step 8 inventory stabilization: track units held out of service for repair.
--
-- A return inspected as Damaged / Missing Parts / Needs Repair must take only
-- the affected units out of circulation while the remaining good units stay
-- borrowable. Those units were previously just subtracted from availability
-- with no record, so `total_stock` and `available_stock` drifted apart
-- permanently and a completed repair could not put them back.
--
-- `under_repair_stock` already existed on some installations but was never
-- read or written by the application. This makes it part of the declared
-- schema so every environment matches.

ALTER TABLE `resources`
    ADD COLUMN IF NOT EXISTS `under_repair_stock` INT(11) NOT NULL DEFAULT 0
    AFTER `available_stock`;

-- Keep the invariant available_stock + under_repair_stock <= total_stock.
UPDATE `resources`
SET `under_repair_stock` = GREATEST(0, `total_stock` - `available_stock`)
WHERE `resource_type` = 'Item'
  AND `under_repair_stock` > GREATEST(0, `total_stock` - `available_stock`);
