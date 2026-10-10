<?php
/**
 * Claim Slip Code.
 *
 * GSO approval issues one code per request. The client presents it at the GSO
 * Office and GSO verifies it before release. The code is a turnover check
 * inside an already authorised GSO action, not a login credential.
 */

if (!function_exists("gsoClaimSlipAlphabet")) {
    /** 32 unambiguous characters: no 0, O, 1 or I. */
    function gsoClaimSlipAlphabet(): string
    {
        return "23456789ABCDEFGHJKLMNPQRSTUVWXYZ";
    }
}

if (!function_exists("gsoGenerateClaimSlipCode")) {
    /** A random GSO-XXXXXXXX code from a cryptographically secure source. */
    function gsoGenerateClaimSlipCode(): string
    {
        $alphabet = gsoClaimSlipAlphabet();
        $code = "";

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }

        return "GSO-" . $code;
    }
}

if (!function_exists("gsoNewUniqueClaimSlipCode")) {
    /**
     * A code no request currently holds. The UNIQUE key remains the final
     * guard: if two approvals ever drew the same code at the same moment, the
     * second approval fails and rolls back instead of sharing a code.
     */
    function gsoNewUniqueClaimSlipCode(PDO $pdo): string
    {
        $exists = $pdo->prepare("SELECT 1 FROM resource_requests WHERE claim_slip_code = ? LIMIT 1");

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = gsoGenerateClaimSlipCode();
            $exists->execute([$code]);

            if (!$exists->fetchColumn()) {
                return $code;
            }
        }

        throw new RuntimeException("A unique Claim Slip Code could not be generated.");
    }
}

if (!function_exists("gsoNormalizeClaimSlipInput")) {
    /** Tolerate spacing and letter case when the code is typed at the counter. */
    function gsoNormalizeClaimSlipInput(mixed $value): string
    {
        return is_string($value) ? strtoupper(preg_replace('/\s+/', '', $value) ?? "") : "";
    }
}

if (!function_exists("gsoClaimSlipMatches")) {
    /** Constant-time comparison of the presented code with the stored code. */
    function gsoClaimSlipMatches(?string $stored, mixed $presented): bool
    {
        $presented = gsoNormalizeClaimSlipInput($presented);

        return is_string($stored) && $stored !== "" && $presented !== "" && hash_equals($stored, $presented);
    }
}
