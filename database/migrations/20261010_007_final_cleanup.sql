-- R10: final cleanup before schema freeze.
--
-- Removes structures retired by earlier revisions:
--   * request_adviser_history and request_director_history (online Adviser
--     endorsement and Director approval were retired in R2B and R2A);
--   * the Adviser and Director routing columns on resource_requests;
--   * the legacy single-resource header mirror resource_requests.resource_id
--     and resource_requests.quantity (request_items is the only store of a
--     request's resources and quantities).
--
-- Apply through scripts/migrate.php. It runs a detailed data gate first and
-- refuses to continue if any of these structures still holds information.
-- The guard below repeats the essential check so that a manual import also
-- stops before anything is dropped: it raises "Subquery returns more than 1
-- row" when a blocker exists.
SET @gso_r10_blockers = (
    (SELECT COUNT(*) FROM request_adviser_history)
  + (SELECT COUNT(*) FROM request_director_history)
  + (SELECT COUNT(*) FROM resource_requests
      WHERE adviser_state <> 'Not Required'
         OR adviser_user_id IS NOT NULL
         OR adviser_required_by IS NOT NULL
         OR adviser_required_at IS NOT NULL
         OR director_state <> 'Not Required'
         OR director_required_by IS NOT NULL
         OR director_required_at IS NOT NULL)
  + (SELECT COUNT(*) FROM resource_requests rr
      WHERE NOT EXISTS (
            SELECT 1 FROM request_items ri
            WHERE ri.request_id = rr.request_id
              AND ri.resource_id = rr.resource_id
              AND ri.requested_quantity <=> rr.quantity))
);
SET @gso_r10_guard = (
    SELECT IF(@gso_r10_blockers = 0, 1, (SELECT table_name FROM information_schema.tables))
);

DROP TABLE IF EXISTS request_adviser_history;
DROP TABLE IF EXISTS request_director_history;

ALTER TABLE resource_requests
    DROP FOREIGN KEY IF EXISTS fk_rr_adviser_user,
    DROP FOREIGN KEY IF EXISTS fk_rr_adviser_required_by,
    DROP FOREIGN KEY IF EXISTS fk_rr_director_required_by,
    DROP FOREIGN KEY IF EXISTS fk_rr_resource;

ALTER TABLE resource_requests
    DROP INDEX IF EXISTS idx_rr_adviser_queue,
    DROP INDEX IF EXISTS fk_rr_adviser_required_by,
    DROP INDEX IF EXISTS idx_rr_director_queue,
    DROP INDEX IF EXISTS fk_rr_director_required_by,
    DROP INDEX IF EXISTS fk_resource_requests_resource,
    DROP INDEX IF EXISTS idx_requests_resource_status;

ALTER TABLE resource_requests
    DROP COLUMN IF EXISTS adviser_state,
    DROP COLUMN IF EXISTS adviser_user_id,
    DROP COLUMN IF EXISTS adviser_required_by,
    DROP COLUMN IF EXISTS adviser_required_at,
    DROP COLUMN IF EXISTS director_state,
    DROP COLUMN IF EXISTS director_required_by,
    DROP COLUMN IF EXISTS director_required_at,
    DROP COLUMN IF EXISTS resource_id,
    DROP COLUMN IF EXISTS quantity;
