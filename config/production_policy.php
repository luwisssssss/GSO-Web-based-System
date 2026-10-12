<?php
/** Validate only configured production values. No credentials enter diagnostics. */
function gsoProductionConfigurationIssues(): array
{
    $issues = [];
    $required = ['GSO_APP_ENV', 'GSO_APP_URL', 'GSO_APP_KEY', 'GSO_DB_HOST', 'GSO_DB_PORT', 'GSO_DB_NAME', 'GSO_DB_USERNAME', 'GSO_DB_PASSWORD',
        'GSO_SMTP_HOST', 'GSO_SMTP_PORT', 'GSO_SMTP_SECURE', 'GSO_SMTP_USERNAME', 'GSO_SMTP_PASSWORD', 'GSO_SMTP_FROM', 'GSO_SMTP_FROM_NAME',
        'GSO_INCIDENT_PHOTO_DIR', 'GSO_REQUEST_LETTER_DIR'];
    foreach ($required as $name) {
        $value = trim((string) gsoEnv($name, ''));
        if ($value === '' || preg_match('/(?:replace[-_ ]with|your[-_ ](?:private|approved|production|smtp|database)|changeme)/i', $value)) $issues[] = $name;
    }
    if (gsoEnvBool('GSO_APP_DEBUG', false)) $issues[] = 'GSO_APP_DEBUG';
    $url = trim((string) gsoEnv('GSO_APP_URL', ''));
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !$parts || ($parts['scheme'] ?? '') !== 'https'
        || empty($parts['host']) || preg_match('/\A(?:localhost|127(?:\.[0-9]+){3}|\[?::1\]?)\z|\.(?:local|invalid|example)\z/i', (string) ($parts['host'] ?? ''))
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) $issues[] = 'GSO_APP_URL';
    $key = trim((string) gsoEnv('GSO_APP_KEY', ''));
    if (!preg_match('/\A[a-f0-9]{64}\z/i', $key) || count(array_unique(str_split(strtolower($key)))) < 8) $issues[] = 'GSO_APP_KEY';
    foreach (['GSO_DB_PORT', 'GSO_SMTP_PORT'] as $name) {
        if (!ctype_digit((string) gsoEnv($name, '')) || (int) gsoEnv($name, '0') < 1 || (int) gsoEnv($name, '0') > 65535) $issues[] = $name;
    }
    if (!preg_match('/\A[a-zA-Z0-9_]+\z/', (string) gsoEnv('GSO_DB_NAME', ''))
        || preg_match('/\Agso_(?:r[0-9]+[ab]?_test_|deploy_audit_|hardening_test_)/i', (string) gsoEnv('GSO_DB_NAME', ''))
        || (strtolower((string) gsoEnv('GSO_APP_ENV', '')) !== 'staging' && str_starts_with((string) gsoEnv('GSO_DB_NAME', ''), 'gso_stage_'))) $issues[] = 'GSO_DB_NAME';
    if (strtolower((string) gsoEnv('GSO_DB_USERNAME', '')) === 'root'
        && !(PHP_SAPI === 'cli' && defined('GSO_SCHEMA_TOOL') && GSO_SCHEMA_TOOL)) $issues[] = 'GSO_DB_USERNAME';
    if (!in_array(strtolower((string) gsoEnv('GSO_SMTP_SECURE', '')), ['tls', 'starttls', 'ssl', 'smtps'], true)) $issues[] = 'GSO_SMTP_SECURE';
    foreach (['GSO_SMTP_USERNAME', 'GSO_SMTP_FROM'] as $name) if (!filter_var(gsoEnv($name, ''), FILTER_VALIDATE_EMAIL)) $issues[] = $name;
    foreach (['GSO_DB_HOST', 'GSO_SMTP_HOST', 'GSO_SMTP_FROM_NAME'] as $name) if (preg_match('/[\r\n\x00;]/', (string) gsoEnv($name, ''))) $issues[] = $name;
    if (gsoEnvBool('GSO_ALLOW_LOCAL_MAIL_FALLBACK', false)) $issues[] = 'GSO_ALLOW_LOCAL_MAIL_FALLBACK';
    if (trim((string) gsoEnv('GSO_MAIL_TEST_TRANSPORT', '')) !== '') $issues[] = 'GSO_MAIL_TEST_TRANSPORT';
    if (gsoEnvBool('GSO_RUN_MIGRATIONS', false)) $issues[] = 'GSO_RUN_MIGRATIONS';
    if (!gsoEnvBool('GSO_SESSION_SECURE', false)) $issues[] = 'GSO_SESSION_SECURE';
    foreach (['GSO_INCIDENT_PHOTO_DIR', 'GSO_REQUEST_LETTER_DIR'] as $name) {
        $path = trim((string) gsoEnv($name, ''));
        $absolute = str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path);
        $resolved = $absolute ? realpath($path) : false;
        if (!$resolved || is_link($path) || !is_dir($resolved) || !is_readable($resolved) || !is_writable($resolved)) { $issues[] = $name; continue; }
        $roots = [dirname(__DIR__), dirname(__DIR__, 2)];
        if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = $_SERVER['DOCUMENT_ROOT'];
        foreach ($roots as $root) {
            $root = realpath($root);
            if (!$root) continue;
            $candidate = rtrim(str_replace('\\', '/', $resolved), '/'); $root = rtrim(str_replace('\\', '/', $root), '/');
            if (DIRECTORY_SEPARATOR === '\\') { $candidate = strtolower($candidate); $root = strtolower($root); }
            if ($candidate === $root || str_starts_with($candidate, $root . '/')) $issues[] = $name;
        }
    }
    return array_values(array_unique($issues));
}

function gsoEnforceProductionConfiguration(): void
{
    $issues = gsoProductionConfigurationIssues();
    if ($issues === []) return;
    // Names only: administrator diagnostics never include configured values.
    error_log('GSO production configuration rejected: ' . implode(', ', $issues));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Production configuration is incomplete or invalid. Check: ' . implode(', ', $issues) . PHP_EOL);
    } else {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'The system is temporarily unavailable. Please contact the administrator.';
    }
    exit(1);
}
