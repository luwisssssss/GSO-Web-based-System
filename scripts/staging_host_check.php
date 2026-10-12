<?php
/** Read-only HTTPS probes. Manual gates stay UNVERIFIED until evidenced. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim((string) ($argv[1] ?? ''), '/');
$parts = parse_url($base);
if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
    || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
    fwrite(STDERR, "Usage: php scripts/staging_host_check.php https://approved-staging-host/application\n"); exit(2);
}
if (!extension_loaded('curl')) { fwrite(STDERR, "This operator probe requires PHP cURL.\n"); exit(2); }
function stagingProbe(string $url): array {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2]);
    $raw = curl_exec($c); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $length = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE); $transport = is_string($raw); curl_close($c);
    return ['transport'=>$transport, 'status'=>$status, 'headers'=>$transport?substr($raw, 0, $length):'', 'body'=>$transport?substr($raw, $length):''];
}
$checks = []; $failure = false;
$record = static function (string $name, bool $pass, int $status) use (&$checks, &$failure): void {
    $checks[] = ['check'=>$name, 'result'=>$pass?'PASS':'FAIL', 'http_status'=>$status]; if (!$pass) $failure = true;
};
foreach (['','pages/landing.php','auth/login.php'] as $path) {
    $r = stagingProbe($base . '/' . $path);
    $publicStatus = $r['status'] === 200;
    if ($path === '' && $r['status'] === 302 && preg_match('/^Location:\s*([^\r\n]+)\s*$/mi', $r['headers'], $location)) {
        $publicStatus = in_array(trim($location[1]), ['pages/landing.php', $base . '/pages/landing.php',
            rtrim((string) ($parts['path'] ?? ''), '/') . '/pages/landing.php'], true);
    }
    // index.php intentionally redirects to the public landing wrapper. The
    // next explicit probe verifies its rendered HTTP 200 without following an
    // arbitrary cross-host redirect or trusting a response-supplied URL.
    $record('HTTPS public ' . ($path ?: 'root'), $r['transport'] && $publicStatus
        && !preg_match('/SQLSTATE|Stack trace:|<b>(?:Warning|Fatal error|Notice)<\/b>/', $r['body']), $r['status']);
    if ($path === 'auth/login.php') {
        $cookie = preg_match('/^Set-Cookie:\s*GSOSESSID=[^\r\n]+/mi', $r['headers'], $m) ? $m[0] : '';
        $record('Secure HttpOnly SameSite session cookie', preg_match('/;\s*secure\b/i', $cookie)
            && preg_match('/;\s*httponly\b/i', $cookie) && preg_match('/;\s*samesite=(?:Lax|Strict|None)\b/i', $cookie), $r['status']);
    }
}
foreach (['.env','.git/HEAD','config/bootstrap.php','database/schema.sql','database/migrations/',
    'scripts/migrate.php','app/controllers/pages/landing.php','PHPMailer/src/PHPMailer.php','private/',
    'uploads/returns/','GSO_WebSystem/docs/db_cleanup_backup_before_cleanup.sql'] as $path) {
    $r = stagingProbe($base . '/' . $path);
    $record('Internal denial ' . $path, $r['transport'] && in_array($r['status'], [403,404], true), $r['status']);
}
$r = stagingProbe($base . '/borrower/request_letter.php?attachment_id=1');
$record('Guest private letter denial', $r['transport'] && (in_array($r['status'], [403,404], true)
    || ($r['status'] === 302 && preg_match('~^Location:\s*(?:\.\./)?auth/login\.php\s*$~mi', $r['headers']))), $r['status']);
// A 404 at an imaginary PHP filename cannot prove execution denial. The operator
// must provision a harmless known-existing staging fixture, then supply its path.
$canary = trim((string) getenv('GSO_STAGING_UPLOAD_CANARY_PATH'));
if ($canary !== '' && preg_match('~\Auploads/[a-zA-Z0-9_/-]+\.php\z~', $canary)) {
    $r = stagingProbe($base . '/' . $canary);
    $record('Known-existing upload PHP denied', $r['transport'] && $r['status'] === 403
        && !str_contains($r['body'], 'GSO_STAGING_CANARY_EXECUTED'), $r['status']);
} else $checks[] = ['check'=>'Known-existing upload PHP denial', 'result'=>'UNVERIFIED'];
foreach (['Authorized vs unrelated-user private letter access','Private storage outside every document root/alias',
    'Canonical configured HTTPS URL in signup/reset/transactional links','Runtime PHP error settings and synthetic fault response',
    'Server rewrite/override rules and trusted proxy boundaries','Database compatibility rehearsal and restore drill'] as $gate) {
    $checks[] = ['check'=>$gate, 'result'=>'UNVERIFIED'];
}
echo json_encode(['automated_result'=>$failure?'FAIL':'PASS','host_readiness'=>'UNVERIFIED',
    'checks'=>$checks,'note'=>'No response bodies, cookies, credentials or private documents are printed. Complete manual gates in the runbook.'], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failure ? 1 : 0);
