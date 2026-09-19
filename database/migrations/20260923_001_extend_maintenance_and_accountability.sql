ALTER TABLE maintenance_schedules
 ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 0 AFTER resource_id,
 ADD COLUMN IF NOT EXISTS source_return_id INT NULL AFTER quantity,
 ADD COLUMN IF NOT EXISTS source_return_item_id BIGINT NULL AFTER source_return_id,
 ADD COLUMN IF NOT EXISTS incident_id INT NULL AFTER source_return_item_id,
 ADD COLUMN IF NOT EXISTS equipment_unit_id BIGINT NULL AFTER incident_id,
 ADD COLUMN IF NOT EXISTS assigned_to INT NULL AFTER equipment_unit_id,
 ADD COLUMN IF NOT EXISTS completion_notes VARCHAR(1000) NULL AFTER remarks,
 ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER updated_at,
 ADD COLUMN IF NOT EXISTS restored_at DATETIME NULL AFTER completed_at;

CREATE TABLE IF NOT EXISTS borrower_liabilities (
 liability_id BIGINT NOT NULL AUTO_INCREMENT,request_id INT NOT NULL,return_item_id BIGINT NULL,
 borrower_id INT NOT NULL,equipment_unit_id BIGINT NULL,
 accountability_type ENUM('Damage','Loss','Missing Parts') NOT NULL,
 accountability_status ENUM('Pending Review','Confirmed','Waived','Resolved') NOT NULL DEFAULT 'Pending Review',
 reviewed_by INT NOT NULL,reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,resolution_notes VARCHAR(1000) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(liability_id),UNIQUE KEY uq_liability_return_item(return_item_id),
 KEY idx_liability_borrower(borrower_id,accountability_status),KEY idx_liability_request(request_id),
 CONSTRAINT fk_liability_request FOREIGN KEY(request_id) REFERENCES resource_requests(request_id) ON DELETE RESTRICT,
 CONSTRAINT fk_liability_return_item FOREIGN KEY(return_item_id) REFERENCES return_submission_items(return_item_id) ON DELETE RESTRICT,
 CONSTRAINT fk_liability_borrower FOREIGN KEY(borrower_id) REFERENCES users(user_id) ON DELETE RESTRICT,
 CONSTRAINT fk_liability_unit FOREIGN KEY(equipment_unit_id) REFERENCES equipment_units(unit_id) ON DELETE RESTRICT,
 CONSTRAINT fk_liability_reviewer FOREIGN KEY(reviewed_by) REFERENCES users(user_id) ON DELETE RESTRICT
);

DROP PROCEDURE IF EXISTS p35c_add_fk;
DELIMITER $$
CREATE PROCEDURE p35c_add_fk(IN p_table VARCHAR(64),IN p_name VARCHAR(64),IN p_ddl TEXT)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=p_table AND CONSTRAINT_NAME=p_name) THEN
  SET @p35c_sql=p_ddl; PREPARE p35c_stmt FROM @p35c_sql; EXECUTE p35c_stmt; DEALLOCATE PREPARE p35c_stmt;
 END IF;
END$$
DELIMITER ;
CALL p35c_add_fk('maintenance_schedules','fk_ms_assigned_to','ALTER TABLE maintenance_schedules ADD KEY idx_ms_assigned_to(assigned_to), ADD CONSTRAINT fk_ms_assigned_to FOREIGN KEY(assigned_to) REFERENCES users(user_id) ON DELETE SET NULL');
CALL p35c_add_fk('maintenance_schedules','fk_ms_incident','ALTER TABLE maintenance_schedules ADD KEY idx_ms_incident_id(incident_id), ADD CONSTRAINT fk_ms_incident FOREIGN KEY(incident_id) REFERENCES incident_reports(incident_id) ON DELETE SET NULL');
CALL p35c_add_fk('maintenance_schedules','fk_ms_source_return','ALTER TABLE maintenance_schedules ADD KEY idx_ms_source_return_id(source_return_id), ADD CONSTRAINT fk_ms_source_return FOREIGN KEY(source_return_id) REFERENCES return_submissions(return_id) ON DELETE SET NULL');
CALL p35c_add_fk('maintenance_schedules','fk_ms_source_return_item','ALTER TABLE maintenance_schedules ADD KEY idx_ms_source_return_item_id(source_return_item_id), ADD CONSTRAINT fk_ms_source_return_item FOREIGN KEY(source_return_item_id) REFERENCES return_submission_items(return_item_id) ON DELETE SET NULL');
CALL p35c_add_fk('maintenance_schedules','fk_ms_equipment_unit','ALTER TABLE maintenance_schedules ADD KEY idx_ms_equipment_unit_id(equipment_unit_id), ADD CONSTRAINT fk_ms_equipment_unit FOREIGN KEY(equipment_unit_id) REFERENCES equipment_units(unit_id) ON DELETE SET NULL');
DROP PROCEDURE p35c_add_fk;
