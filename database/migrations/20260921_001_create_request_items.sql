-- Phase 3.5A: combined borrowing (one request, many resource lines).
--
-- Additive and idempotent. resource_requests keeps resource_id and quantity
-- as temporary compatibility mirrors of the request's primary line; they are
-- NOT authoritative and are retired in a separate, separately verified
-- migration once every dependency and test fixture has switched over.
--
-- Nothing here recomputes stock: the backfill writes no `resources` row, so a
-- repeated run can never double-deduct or re-issue a historical request.

-- 1. Authoritative resource classification. Existing facilities become
--    'Facility'; every existing item stays aggregate. Projectors, laptops and
--    microphones are approved for individual tracking but are deliberately NOT
--    reclassified here: registering their physical units is Phase 3.5B work.
ALTER TABLE resources
    ADD COLUMN IF NOT EXISTS tracking_mode
        ENUM('Facility','Serialized','Aggregate','Consumable')
        NOT NULL DEFAULT 'Aggregate' AFTER resource_type;

UPDATE resources
SET tracking_mode = 'Facility'
WHERE resource_type = 'Facility' AND tracking_mode <> 'Facility';

UPDATE resources
SET tracking_mode = 'Aggregate'
WHERE resource_type = 'Item' AND tracking_mode = 'Facility';

-- A facility is never an equipment unit, and an item is never a facility.
SET @phase35a_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='resources'
             AND CONSTRAINT_NAME='chk_resources_tracking_mode'),
    'SELECT 1',
    'ALTER TABLE resources ADD CONSTRAINT chk_resources_tracking_mode CHECK ((resource_type = ''Facility'' AND tracking_mode = ''Facility'') OR (resource_type = ''Item'' AND tracking_mode IN (''Serialized'',''Aggregate'',''Consumable'')))'
);
PREPARE phase35a_stmt FROM @phase35a_sql; EXECUTE phase35a_stmt; DEALLOCATE PREPARE phase35a_stmt;

-- 2. Request lines. line_kind is a snapshot of the resource classification at
--    request time, so reclassifying a resource later never rewrites history.
--    facility_guard is NULL on every non-facility line, so its UNIQUE index
--    permits many equipment lines but at most one facility line per request.
CREATE TABLE IF NOT EXISTS request_items (
    request_item_id BIGINT NOT NULL AUTO_INCREMENT,
    request_id INT NOT NULL,
    resource_id INT NOT NULL,
    line_kind ENUM('Facility','Equipment','Consumable') NOT NULL,
    requested_quantity INT NOT NULL DEFAULT 1,
    released_quantity INT NOT NULL DEFAULT 0,
    returned_quantity INT NOT NULL DEFAULT 0,
    issued_quantity INT NOT NULL DEFAULT 0,
    line_notes VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    facility_guard INT AS (CASE WHEN line_kind = 'Facility' THEN request_id END) STORED,
    PRIMARY KEY (request_item_id),
    UNIQUE KEY uq_request_items_resource (request_id, resource_id),
    UNIQUE KEY uq_request_items_one_facility (facility_guard),
    KEY idx_request_items_resource (resource_id, request_id),
    KEY idx_request_items_kind (request_id, line_kind),
    CONSTRAINT chk_request_items_quantities CHECK (
        requested_quantity >= 1
        AND released_quantity >= 0 AND released_quantity <= requested_quantity
        AND returned_quantity >= 0 AND returned_quantity <= released_quantity
        AND issued_quantity >= 0 AND issued_quantity <= requested_quantity
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @phase35a_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='request_items'
             AND CONSTRAINT_NAME='fk_request_items_request'),
    'SELECT 1',
    'ALTER TABLE request_items ADD CONSTRAINT fk_request_items_request FOREIGN KEY (request_id) REFERENCES resource_requests(request_id) ON DELETE CASCADE'
);
PREPARE phase35a_stmt FROM @phase35a_sql; EXECUTE phase35a_stmt; DEALLOCATE PREPARE phase35a_stmt;

SET @phase35a_sql = IF(
    EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
           WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='request_items'
             AND CONSTRAINT_NAME='fk_request_items_resource'),
    'SELECT 1',
    'ALTER TABLE request_items ADD CONSTRAINT fk_request_items_resource FOREIGN KEY (resource_id) REFERENCES resources(resource_id) ON DELETE RESTRICT'
);
PREPARE phase35a_stmt FROM @phase35a_sql; EXECUTE phase35a_stmt; DEALLOCATE PREPARE phase35a_stmt;

-- 3. Historical backfill: exactly one line per existing request, mirroring the
--    legacy header pair. NOT EXISTS makes a repeated run a no-op, and the
--    UNIQUE (request_id, resource_id) key is the backstop.
INSERT INTO request_items
    (request_id, resource_id, line_kind, requested_quantity, released_quantity, returned_quantity, created_at)
SELECT
    rr.request_id,
    rr.resource_id,
    CASE WHEN r.resource_type = 'Facility' THEN 'Facility' ELSE 'Equipment' END,
    GREATEST(1, COALESCE(rr.quantity, 1)),
    CASE WHEN rr.status IN ('Released','Returned') THEN GREATEST(1, COALESCE(rr.quantity, 1)) ELSE 0 END,
    CASE WHEN rr.status = 'Returned' THEN GREATEST(1, COALESCE(rr.quantity, 1)) ELSE 0 END,
    rr.request_date
FROM resource_requests rr
INNER JOIN resources r ON r.resource_id = rr.resource_id
WHERE NOT EXISTS (
    SELECT 1 FROM request_items ri WHERE ri.request_id = rr.request_id
);
