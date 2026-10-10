<?php
/**
 * Claim Slip Code helpers for regression fixtures only.
 *
 * Production issues a code in the approval transaction. A suite that inserts an
 * already-Approved request directly must give it a code, exactly as a real
 * approval would have, and a suite that releases a request presents the stored
 * code the way the client would at the GSO Office.
 */
require_once dirname(__DIR__) . '/app/models/claim_slip_helper.php';

/** Code for a fixture inserted directly in the given status (NULL before approval). */
function gsoTestClaimSlipFor(?string $status): ?string
{
    return in_array($status, ['Pending', 'Under Review', 'Rejected'], true) || $status === null ? null : gsoGenerateClaimSlipCode();
}

/** The code the client of this request would present. */
function gsoTestPresentedClaimSlip(int $requestId): string
{
    global $pdo;
    $stmt = $pdo->prepare('SELECT claim_slip_code FROM resource_requests WHERE request_id = ?');
    $stmt->execute([$requestId]);

    return (string) $stmt->fetchColumn();
}
