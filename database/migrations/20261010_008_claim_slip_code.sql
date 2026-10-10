-- R10A: Claim Slip Code.
--
-- One request has one Claim Slip Code. It is issued by the server when GSO
-- approves the request and is verified by GSO before release. It is NULL
-- before approval and is kept for history after release, return or
-- cancellation. Requests closed before this migration keep NULL: no code is
-- fabricated for them.
--
-- Apply through scripts/migrate.php, which first reports any request that is
-- currently Approved and would therefore need a code.
ALTER TABLE resource_requests
    ADD COLUMN IF NOT EXISTS claim_slip_code VARCHAR(20) NULL DEFAULT NULL AFTER status;

ALTER TABLE resource_requests
    ADD UNIQUE KEY uq_requests_claim_slip_code (claim_slip_code);

ALTER TABLE resource_requests
    ADD CONSTRAINT chk_requests_claim_slip CHECK (
        (claim_slip_code IS NULL OR claim_slip_code REGEXP '^GSO-[2-9A-HJ-NP-Z]{8}$')
        AND (status NOT IN ('Pending', 'Under Review') OR claim_slip_code IS NULL)
        AND (status <> 'Approved' OR claim_slip_code IS NOT NULL)
    );
