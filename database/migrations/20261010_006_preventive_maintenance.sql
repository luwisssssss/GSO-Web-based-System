-- R7: plans describe policy; maintenance_schedules remains occurrence/history storage.
CREATE TABLE IF NOT EXISTS preventive_maintenance_plans (
    plan_id BIGINT(20) NOT NULL AUTO_INCREMENT,
    resource_id INT(11) NOT NULL,
    equipment_unit_id BIGINT(20) NULL,
    quantity INT(11) NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    recurrence ENUM('Once','Monthly','Quarterly','Semiannual','Annual') NOT NULL,
    next_due_date DATE NOT NULL,
    anchor_day TINYINT UNSIGNED NOT NULL,
    duration_days INT NOT NULL DEFAULT 1,
    assigned_to INT(11) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    revision INT NOT NULL DEFAULT 1,
    created_by INT(11) NOT NULL,
    updated_by INT(11) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(plan_id),
    KEY idx_preventive_due(is_active,next_due_date),
    CONSTRAINT fk_plan_resource FOREIGN KEY(resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_unit FOREIGN KEY(equipment_unit_id) REFERENCES equipment_units(unit_id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_assignee FOREIGN KEY(assigned_to) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_creator FOREIGN KEY(created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_plan_updater FOREIGN KEY(updated_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT chk_plan_values CHECK(quantity>=0 AND duration_days BETWEEN 1 AND 365 AND anchor_day BETWEEN 1 AND 31 AND revision>=1 AND is_active IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- NULL intentionally preserves unclassified legacy maintenance; never guess its origin.
ALTER TABLE maintenance_schedules
    ADD COLUMN IF NOT EXISTS maintenance_type ENUM('Corrective','Preventive') NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS preventive_plan_id BIGINT(20) NULL,
    ADD COLUMN IF NOT EXISTS plan_occurrence_date DATE NULL,
    ADD COLUMN IF NOT EXISTS started_at DATETIME NULL,
    ADD UNIQUE KEY uq_preventive_occurrence(preventive_plan_id,plan_occurrence_date),
    ADD CONSTRAINT fk_maintenance_plan FOREIGN KEY(preventive_plan_id) REFERENCES preventive_maintenance_plans(plan_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_preventive_origin CHECK(maintenance_type IS NULL OR maintenance_type<>'Preventive' OR(preventive_plan_id IS NOT NULL AND plan_occurrence_date IS NOT NULL));
-- Evidence-based backfill only: a work order linked to an inspected return or an
-- incident is provably Corrective. Rows without such a source link stay NULL.
UPDATE maintenance_schedules
SET maintenance_type='Corrective'
WHERE maintenance_type IS NULL
  AND (source_return_item_id IS NOT NULL OR source_return_id IS NOT NULL OR incident_id IS NOT NULL);
