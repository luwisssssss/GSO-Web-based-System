-- Phase 3.5B: individually tracked equipment, per-unit allocation, and
-- per-resource return inspection.
--
-- Additive and idempotent. No physical unit is invented for existing stock:
-- equipment_units starts empty and is populated only by authorised GSO staff
-- registering real asset tags. Reclassifying a resource to Serialized remains
-- a deliberate, reconciled action (see gsoActivateSerializedTracking()).

-- 1. Physical equipment units. asset_tag is the institutional identifier and
--    is always present; serial_number is the manufacturer's and is optional,
--    so its UNIQUE index deliberately relies on MariaDB treating NULLs as
--    distinct (many units may have no serial, but no two may share one).
CREATE TABLE IF NOT EXISTS equipment_units (
    unit_id BIGINT NOT NULL AUTO_INCREMENT,
    resource_id INT NOT NULL,
    asset_tag VARCHAR(60) NOT NULL,
    serial_number VARCHAR(120) NULL,
    status ENUM('Available','Released','Under Repair','Lost','Retired') NOT NULL DEFAULT 'Available',
    condition_status ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NOT NULL DEFAULT 'Good',
    unit_notes VARCHAR(500) NULL,
    acquired_at DATE NULL,
    registered_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (unit_id),
    UNIQUE KEY uq_equipment_units_asset_tag (asset_tag),
    UNIQUE KEY uq_equipment_units_serial (serial_number),
    KEY idx_equipment_units_resource (resource_id, status),
    CONSTRAINT fk_equipment_units_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT,
    CONSTRAINT fk_equipment_units_registrar FOREIGN KEY (registered_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Which physical unit satisfied which request line.
--    open_unit_guard is the unit id only while the allocation is still open,
--    so its UNIQUE index makes it impossible for one unit to sit in two
--    un-returned allocations at once. History is kept forever: returning a
--    unit clears the guard without deleting the allocation row.
CREATE TABLE IF NOT EXISTS request_item_units (
    allocation_id BIGINT NOT NULL AUTO_INCREMENT,
    request_item_id BIGINT NOT NULL,
    unit_id BIGINT NOT NULL,
    allocated_by INT NOT NULL,
    allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    released_at DATETIME NULL,
    returned_at DATETIME NULL,
    open_unit_guard BIGINT AS (CASE WHEN returned_at IS NULL THEN unit_id END) STORED,
    PRIMARY KEY (allocation_id),
    UNIQUE KEY uq_request_item_units_pair (request_item_id, unit_id),
    UNIQUE KEY uq_request_item_units_open (open_unit_guard),
    KEY idx_request_item_units_unit (unit_id, returned_at),
    CONSTRAINT fk_request_item_units_line FOREIGN KEY (request_item_id) REFERENCES request_items(request_item_id) ON DELETE CASCADE,
    CONSTRAINT fk_request_item_units_unit FOREIGN KEY (unit_id) REFERENCES equipment_units(unit_id) ON DELETE RESTRICT,
    CONSTRAINT fk_request_item_units_actor FOREIGN KEY (allocated_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Per-resource return inspection. return_submissions stays the request-level
--    header; each row here is one condition group for an aggregate line, or one
--    physical unit for a serialized line.
--
--    UNIQUE (return_id, unit_id) is deliberate: MariaDB treats NULLs as
--    distinct, so an aggregate line may hold several condition groups while a
--    given unit can appear at most once per return.
--
--    lost_quantity is tracked separately from returned_quantity because a lost
--    item was never physically received and must not be counted as returned.
CREATE TABLE IF NOT EXISTS return_submission_items (
    return_item_id BIGINT NOT NULL AUTO_INCREMENT,
    return_id INT NOT NULL,
    request_item_id BIGINT NOT NULL,
    unit_id BIGINT NULL,
    reported_condition ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NULL,
    inspection_condition ENUM('Good','Damaged','Missing Parts','Needs Repair','Lost') NULL,
    returned_quantity INT NOT NULL DEFAULT 0,
    lost_quantity INT NOT NULL DEFAULT 0,
    inspection_remarks VARCHAR(500) NULL,
    inspected_by INT NULL,
    inspected_at DATETIME NULL,
    stock_applied TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (return_item_id),
    UNIQUE KEY uq_return_items_unit (return_id, unit_id),
    KEY idx_return_items_line (request_item_id, return_id),
    CONSTRAINT fk_return_items_return FOREIGN KEY (return_id) REFERENCES return_submissions(return_id) ON DELETE CASCADE,
    CONSTRAINT fk_return_items_line FOREIGN KEY (request_item_id) REFERENCES request_items(request_item_id) ON DELETE RESTRICT,
    CONSTRAINT fk_return_items_unit FOREIGN KEY (unit_id) REFERENCES equipment_units(unit_id) ON DELETE RESTRICT,
    CONSTRAINT fk_return_items_inspector FOREIGN KEY (inspected_by) REFERENCES users(user_id) ON DELETE SET NULL,
    CONSTRAINT chk_return_items_quantities CHECK (
        returned_quantity >= 0
        AND lost_quantity >= 0
        AND (returned_quantity + lost_quantity) >= 1
        AND (unit_id IS NULL OR (returned_quantity + lost_quantity) = 1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. A consumable-only request is finished once the materials are issued.
--    Labelling it 'Returned' would claim materials came back that never can,
--    so a dedicated terminal state is added. Existing statuses are untouched.
ALTER TABLE resource_requests
    MODIFY COLUMN status
        ENUM('Pending','Under Review','Approved','Rejected','Cancelled','Released','Returned','Completed')
        NOT NULL DEFAULT 'Pending';

-- 5. Backfill: every historical return becomes one aggregate detail row per
--    request line, carrying the header's conditions forward. stock_applied is
--    set so the new inspection path can never re-apply a stock movement that
--    the old header-level path already made. NOT EXISTS keeps a rerun a no-op,
--    and no `resources` row is written here.
INSERT INTO return_submission_items
    (return_id, request_item_id, unit_id, reported_condition, inspection_condition,
     returned_quantity, lost_quantity, inspection_remarks, inspected_by, inspected_at,
     stock_applied, created_at)
SELECT
    rs.return_id,
    ri.request_item_id,
    NULL,
    rs.reported_condition,
    rs.inspection_condition,
    CASE WHEN rs.inspection_condition = 'Lost' THEN 0 ELSE GREATEST(1, ri.requested_quantity) END,
    CASE WHEN rs.inspection_condition = 'Lost' THEN GREATEST(1, ri.requested_quantity) ELSE 0 END,
    rs.inspection_remarks,
    rs.admin_id,
    rs.reviewed_at,
    1,
    rs.submitted_at
FROM return_submissions rs
INNER JOIN request_items ri ON ri.request_id = rs.request_id AND ri.line_kind <> 'Consumable'
WHERE NOT EXISTS (
    SELECT 1 FROM return_submission_items rsi WHERE rsi.return_id = rs.return_id
);
