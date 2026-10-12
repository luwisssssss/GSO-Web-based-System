<?php
/** Included only by temporary, loopback-only hardening fixtures; never deploy. */
if (!defined('GSO_HARDENING_FIXTURE') || !GSO_HARDENING_FIXTURE
    || !in_array(getenv('GSO_APP_ENV'), ['development','test'], true)
    || !preg_match('/\Agso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+\z/', (string) getenv('GSO_DB_NAME'))
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) { http_response_code(404); exit; }
define('GSO_SCHEMA_TOOL', true);
require_once dirname(__DIR__) . '/config/db.php';
gsoInstallSafeErrorHandlers();
ini_set('display_errors', '1'); // Prove handlers/catches remain safe with bad php.ini.
ini_set('error_log', getenv('GSO_HARDENING_FIXTURE_DIR') . '/diagnostics.log');
$fixture = json_decode(file_get_contents(getenv('GSO_HARDENING_FIXTURE_DIR') . '/state.json'), true);
class HardeningFaultStatement extends PDOStatement {
    public function __construct() {}
    public function execute(?array $params = null): bool {
        $e = new PDOException('SQLSTATE[HY000] gso_database resource_requests users return_submissions C:\\private\\secret.php Stack trace: HARDENING_PRIVATE_SECRET');
        $e->errorInfo = ['HY000', 9999, 'password=HARDENING_PRIVATE_SECRET token=HARDENING_PRIVATE_SECRET']; throw $e;
    }
}
class HardeningFaultPDO extends PDO {
    public function __construct(private PDO $real, private string $pattern) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return preg_match($this->pattern, $query) ? new HardeningFaultStatement() : $this->real->prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        return $fetchMode === null ? $this->real->query($query) : $this->real->query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function beginTransaction(): bool { return $this->real->beginTransaction(); }
    public function inTransaction(): bool { return $this->real->inTransaction(); }
    public function rollBack(): bool { return $this->real->rollBack(); }
    public function commit(): bool { return $this->real->commit(); }
    public function lastInsertId(?string $name = null): string|false { return $this->real->lastInsertId($name); }
    public function exec(string $statement): int|false { return $this->real->exec($statement); }
}
$kind = (string) ($_GET['flow'] ?? '');
gsoSecureSessionStart();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
if ($kind === 'signup') {
    $pdo = new HardeningFaultPDO($pdo, '/INSERT\s+INTO\s+users/i');
    chdir(dirname(__DIR__) . '/auth'); require dirname(__DIR__) . '/app/controllers/auth/signup.php';
} elseif (in_array($kind, ['request','return'], true)) {
    $_SESSION['user_id'] = $fixture['user']; $_SESSION['role'] = 'Borrower'; $_SESSION['full_name'] = 'Hardening test';
    $pdo = new HardeningFaultPDO($pdo, $kind === 'request' ? '/INSERT\s+INTO\s+resource_requests/i' : '/INSERT\s+INTO\s+return_submissions/i');
    $_GET['resource_id'] = $fixture['resource']; $_GET['request_id'] = $fixture['request'];
    chdir(dirname(__DIR__) . '/borrower');
    require dirname(__DIR__) . '/app/controllers/borrower/' . ($kind === 'request' ? 'request_resource.php' : 'return_submit.php');
} elseif ($kind === 'configuration') {
    $_ENV['GSO_APP_ENV'] = 'production'; $_ENV['GSO_APP_URL'] = '';
    gsoEnforceProductionConfiguration();
} elseif ($kind === 'warning') {
    trigger_error('password=HARDENING_PRIVATE_SECRET C:\\private\\secret.php', E_USER_WARNING); echo 'SAFE_WARNING_RESPONSE';
} else { http_response_code(404); }
