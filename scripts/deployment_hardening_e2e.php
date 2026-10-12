<?php
declare(strict_types=1);
require_once __DIR__ . '/test_environment_helper.php';
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/work_order_helper.php';
require_once dirname(__DIR__) . '/includes/mail_helper.php';
$dir = realpath((string) getenv('GSO_HARDENING_FIXTURE_DIR'));
$base = rtrim((string) getenv('GSO_APP_URL'), '/');
if (!$dir || str_starts_with($dir, realpath(dirname(__DIR__))) || parse_url($base, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Temporary fixture directory outside the application and loopback server required.');
}
$checks = 0; $fail = null; $user = $resource = $request = $work = 0; $cookies = [];
$assert = static function (bool $ok, string $name) use (&$checks): void { $checks++; if (!$ok) throw new RuntimeException($name); };
$private = ['SQLSTATE','gso_database','resource_requests','return_submissions','C:\\private\\secret.php','Stack trace:', 'HARDENING_PRIVATE_SECRET'];
$safe = static function (string $text, string $name) use ($assert, $private): void {
    foreach ($private as $marker) $assert(!str_contains($text, $marker), $name . ' disclosed ' . $marker);
    $assert(str_contains($text, 'GSOERR-'), $name . ' lacks diagnostic reference');
};
$http = static function (string $path, ?array $data = null, string $who = 'guest') use (&$cookies, $base): array {
    $cookies[$who] ??= tempnam(sys_get_temp_dir(), 'gso_hardening_cookie_');
    $url = str_starts_with($path, '/gso-hardening-') ? 'http://127.0.0.1:' . parse_url($base, PHP_URL_PORT) . $path : $base . '/' . $path;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$cookies[$who],CURLOPT_COOKIEJAR=>$cookies[$who],CURLOPT_TIMEOUT=>20]);
    if ($data !== null) curl_setopt_array($c, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$data]);
    $raw = curl_exec($c); $length = (int) curl_getinfo($c, CURLINFO_HEADER_SIZE); $status = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); curl_close($c);
    if (!is_string($raw)) throw new RuntimeException('Loopback fixture transport failed.');
    return ['status'=>$status, 'headers'=>substr($raw,0,$length), 'body'=>substr($raw,$length)];
};
$exec = static function (string $sql, array $args = []) use ($pdo): void { $s = $pdo->prepare($sql); $s->execute($args); };
class HardeningWorkPDO extends PDO {
    public function __construct(private PDO $real) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return preg_match('/UPDATE\s+maintenance_schedules\s+SET\s+assigned_to/i', $query)
            ? new HardeningWorkStatement() : $this->real->prepare($query, $options);
    }
    public function beginTransaction(): bool { return $this->real->beginTransaction(); }
    public function inTransaction(): bool { return $this->real->inTransaction(); }
    public function rollBack(): bool { return $this->real->rollBack(); }
}
class HardeningWorkStatement extends PDOStatement {
    public function __construct() {}
    public function execute(?array $params = null): bool {
        throw new PDOException('SQLSTATE[HY000] gso_database resource_requests C:\\private\\secret.php Stack trace: HARDENING_PRIVATE_SECRET');
    }
}
try {
    ini_set('error_log', $dir . '/diagnostics.log');
    $mark = 'hardening_' . bin2hex(random_bytes(6));
    $exec("INSERT INTO users(full_name,email,username,password_hash,role,account_type,account_status,email_verified) VALUES(?,?,?,?,'Borrower','University Staff','Approved',1)",
        ['Hardening fixture',$mark.'@example.invalid',$mark,password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
    $user = (int) $pdo->lastInsertId();
    $exec("INSERT INTO resources(resource_name,resource_type,tracking_mode,total_stock,available_stock,status,condition_status) VALUES(?,'Item','Aggregate',100,99,'Available','Good')", [$mark]);
    $resource = (int) $pdo->lastInsertId();
    $exec("INSERT INTO resource_requests(borrower_id,date_needed,status) VALUES(?,CURDATE(),'Released')", [$user]); $request = (int) $pdo->lastInsertId();
    $exec("INSERT INTO request_items(request_id,resource_id,line_kind,requested_quantity,released_quantity) VALUES(?,?,'Equipment',1,1)", [$request,$resource]); $line = (int) $pdo->lastInsertId();
    file_put_contents($dir . '/state.json', json_encode(['user'=>$user,'resource'=>$resource,'request'=>$request]));
    file_put_contents($dir . '/flow.php', '<?php define("GSO_HARDENING_FIXTURE",true); require ' . var_export(__DIR__ . '/deployment_fault_fixture.php', true) . ';');
    $png = $dir . '/id.png'; file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII='));
    foreach (['request','return','signup'] as $flow) {
        $path = '/gso-hardening-fixtures/flow.php?flow=' . $flow;
        $get = $http($path, null, $flow);
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $get['body'], $m); $csrf = $m[1] ?? '';
        $assert($get['status'] === 200 && $csrf !== '', $flow . ' actual controller fixture GET');
        $data = ['csrf_token'=>$csrf];
        if ($flow === 'request') $data += ['quantity'=>1,'contact_number'=>'09123456789','date_needed'=>date('Y-m-d', strtotime('+400 days')),'notes'=>'Fixture purpose'];
        elseif ($flow === 'return') $data += ['lines['.$line.'][outcomes][Good]'=>1];
        else $data += ['full_name'=>'Hardening signup','account_type'=>'University Staff','email'=>$mark.'_signup@example.invalid','username'=>$mark.'_signup',
            'password'=>'Fixture-Only-Pass123','confirm_password'=>'Fixture-Only-Pass123','uploaded_id'=>new CURLFile($png,'image/png','id.png')];
        $result = $http($path, $data, $flow);
        $assert($result['status'] === 200, $flow . ' handled persistence failure');
        $safe($result['body'], $flow . ' real controller response');
    }
    $assert((int) $pdo->query('SELECT COUNT(*) FROM return_submissions WHERE request_id=' . $request)->fetchColumn() === 0, 'Return fault rolled back');
    $s = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username=?'); $s->execute([$mark.'_signup']); $assert((int)$s->fetchColumn() === 0, 'Signup fault persisted no user');
    $exec("UPDATE users SET role='Admin' WHERE user_id=?", [$user]);
    $exec("INSERT INTO maintenance_schedules(maintenance_type,resource_id,quantity,start_date,end_date,duration_days,reason,status,created_by) VALUES('Corrective',?,1,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 2 DAY),2,'Hardening fixture','In Progress',?)", [$resource,$user]);
    $work=(int)$pdo->lastInsertId();
    $result = gsoAssignRepairWorkOrder(new HardeningWorkPDO($pdo), $work, $user, $user);
    $assert(!$result['ok'], 'Work-order PDO fault rejected'); $safe($result['message'], 'work-order response');
    $assert(!$pdo->inTransaction() && $pdo->query('SELECT assigned_to FROM maintenance_schedules WHERE maintenance_id='.$work)->fetchColumn()===null, 'Work-order persistence fault rolled back');
    $assert(gsoSafeApplicationError(new DomainException('Only approved requests can be released.'), 'validation fixture') === 'Only approved requests can be released.', 'Authored business validation remains useful');
    $safe(gsoSafeApplicationError(new RuntimeException('C:\\private\\secret.php HARDENING_PRIVATE_SECRET'), 'filesystem fixture'), 'Filesystem response');
    $safe(gsoSafeApplicationError(new \PHPMailer\PHPMailer\Exception('SMTP password=HARDENING_PRIVATE_SECRET'), 'SMTP fixture'), 'SMTP response');
    $r = $http('/gso-hardening-fixtures/flow.php?flow=warning');
    $assert($r['body'] === 'SAFE_WARNING_RESPONSE', 'Warning hidden even when display_errors enabled');
    $r = $http('/gso-hardening-fixtures/flow.php?flow=configuration');
    $assert($r['status'] === 503 && str_contains($r['body'],'temporarily unavailable'), 'Public invalid production configuration fails safely');
    $assert(!str_contains($r['body'],'GSO_APP_URL') && !str_contains($r['body'],'SMTP'), 'Configuration values/names absent from public response');
    $old = $_ENV; $env = getenv();
    $valid = [
        'GSO_APP_ENV'=>'production','GSO_APP_DEBUG'=>'0','GSO_APP_URL'=>'https://gso.example.edu/app','GSO_APP_KEY'=>bin2hex(random_bytes(32)),
        'GSO_DB_HOST'=>'localhost','GSO_DB_PORT'=>'3306','GSO_DB_NAME'=>'gso_hardening_config','GSO_DB_USERNAME'=>'gso_app','GSO_DB_PASSWORD'=>'HARDENING_PRIVATE_SECRET',
        'GSO_SMTP_HOST'=>'smtp.example.edu','GSO_SMTP_PORT'=>'587','GSO_SMTP_SECURE'=>'tls','GSO_SMTP_USERNAME'=>'mailer@example.edu',
        'GSO_SMTP_PASSWORD'=>'HARDENING_PRIVATE_SECRET','GSO_SMTP_FROM'=>'mailer@example.edu','GSO_SMTP_FROM_NAME'=>'GSO',
        'GSO_INCIDENT_PHOTO_DIR'=>$dir,'GSO_REQUEST_LETTER_DIR'=>$dir,'GSO_SESSION_SECURE'=>'1','GSO_RUN_MIGRATIONS'=>'0',
        'GSO_ALLOW_LOCAL_MAIL_FALLBACK'=>'0','GSO_MAIL_TEST_TRANSPORT'=>''];
    $_ENV = array_merge($_ENV, $valid);
    $assert(gsoProductionConfigurationIssues() === [], 'Complete external production configuration accepted');
    $issue = gsoGetMailConfigurationIssue([]); $safe($issue, 'Production mail configuration response');
    $assert(!str_contains($issue,'SMTP')&&!str_contains($issue,'.env'), 'SMTP configuration internals hidden');
    // Bind a disposable loopback TCP port without listening. SMTP connection
    // fails locally; no message can be sent and no external host is contacted.
    $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$errorText,STREAM_SERVER_BIND);
    if (!is_resource($socket)) throw new RuntimeException('Cannot reserve local SMTP fault port.');
    $port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);
    $smtp=gsoSendEmail(['host'=>'127.0.0.1','port'=>$port,'secure'=>'tls','username'=>'fixture@example.edu',
        'password'=>'HARDENING_PRIVATE_SECRET','from_email'=>'fixture@example.edu','from_name'=>'Fixture'],
        'fixture@example.invalid','Fixture','Hardening no-send fixture','<p>Fixture</p>','Fixture');
    fclose($socket); $assert(!$smtp['success'], 'Loopback-only SMTP connection fault rejected'); $safe($smtp['message'], 'Actual SMTP failure response');
    $assert(!str_contains($smtp['message'],'SMTP')&&!str_contains($smtp['message'],'127.0.0.1'), 'SMTP transport internals hidden');
    foreach (array_keys($valid) as $name) {
        if (in_array($name,['GSO_APP_DEBUG','GSO_RUN_MIGRATIONS','GSO_ALLOW_LOCAL_MAIL_FALLBACK','GSO_MAIL_TEST_TRANSPORT'],true)) continue;
        $value = $_ENV[$name]; $_ENV[$name] = ''; $assert(in_array($name,gsoProductionConfigurationIssues(),true), 'Missing ' . $name . ' refused'); $_ENV[$name] = $value;
    }
    foreach (['GSO_APP_URL'=>'http://localhost/app','GSO_DB_USERNAME'=>'root','GSO_DB_NAME'=>'gso_r13_test_unsafe',
        'GSO_APP_KEY'=>str_repeat('a',64),'GSO_APP_DEBUG'=>'1','GSO_MAIL_TEST_TRANSPORT'=>'outbox','GSO_ALLOW_LOCAL_MAIL_FALLBACK'=>'1',
        'GSO_RUN_MIGRATIONS'=>'1','GSO_REQUEST_LETTER_DIR'=>dirname(__DIR__).'/uploads'] as $name=>$value) {
        $saved = $_ENV[$name]; $_ENV[$name]=$value; $assert(in_array($name,gsoProductionConfigurationIssues(),true), 'Unsafe '.$name.' refused'); $_ENV[$name]=$saved;
    }
    $_SERVER['HTTP_HOST']='attacker.invalid';
    $assert(gsoBuildAppUrl('auth/login.php')==='https://gso.example.edu/app/auth/login.php', 'Host header cannot change configured URL');
    $_ENV['GSO_APP_URL']=''; $denied=false; try { gsoBuildAppUrl('auth/login.php'); } catch (RuntimeException $e) { $denied=true; }
    $assert($denied, 'Production URL does not fall back to Host'); $_ENV['GSO_APP_URL']=$valid['GSO_APP_URL'];
    gsoSecureSessionStart(); $params = session_get_cookie_params();
    $assert($params['secure'] && $params['httponly'] && $params['samesite']==='Lax', 'Production session cookie policy'); session_write_close();
    // Execute the actual bootstrap in a fresh process. No DB or SMTP connection.
    foreach (['valid','missing','test_outbox','test_script'] as $mode) {
        $childEnv = array_merge($env,$valid); if ($mode==='missing') $childEnv['GSO_DB_HOST']='';
        if ($mode==='test_outbox') $childEnv['GSO_MAIL_TEST_TRANSPORT']='outbox';
        $code = 'ini_set("error_log",'.var_export($dir.'/diagnostics.log',true).');require '.var_export(dirname(__DIR__).'/config/bootstrap.php',true).';echo "BOOTSTRAP_OK";';
        if ($mode==='missing') $code='$_ENV["GSO_DB_HOST"]="";'.$code;
        if ($mode==='test_script') $code='require '.var_export(__DIR__.'/test_environment_helper.php',true).';echo "UNSAFE_TEST_OK";';
        $p=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$childEnv);
        $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit=proc_close($p);
        $assert($mode==='valid' ? $exit===0&&str_contains($output,'BOOTSTRAP_OK') : $exit!==0, 'Fresh process '.$mode.' guard');
        $assert(!str_contains($output,'HARDENING_PRIVATE_SECRET')&&!str_contains($output,$valid['GSO_APP_KEY']), 'Fresh process '.$mode.' secret privacy');
    }
    $_ENV=$old; unset($_SERVER['HTTP_HOST']);
    foreach (['.env','config/bootstrap.php','database/schema.sql','database/migrations/','scripts/migrate.php','app/controllers/pages/landing.php','private/','uploads/returns/'] as $path) {
        $r=$http($path); $assert($r['status']===403, 'Internal path denial: '.$path);
    }
    $r=$http('borrower/request_letter.php?attachment_id=1'); $assert($r['status']===302&&str_contains($r['headers'],'auth/login.php'), 'Unauthenticated private letter denied');
    file_put_contents($dir.'/upload-canary/canary.php','<?php echo "GSO_STAGING_CANARY_EXECUTED";');
    $r=$http('/gso-hardening-upload/canary.php'); $assert($r['status']===403&&!str_contains($r['body'],'GSO_STAGING_CANARY_EXECUTED'), 'Known-existing PHP denied by actual uploads .htaccess');
    foreach (['gso_database','gso_r13_test_unsafe','gso_stage_unsafe'] as $target) {
        $childEnv=array_merge($env,['GSO_STAGING_DB_NAME'=>$target,'GSO_STAGING_ALLOW_SCHEMA_IMPORT'=>$target==='gso_stage_unsafe'?'0':'1']);
        $p=proc_open([PHP_BINARY,__DIR__.'/staging_database_check.php'],[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$childEnv);
        $output=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        $assert($exit===2&&str_contains($output,'REFUSED'), 'Unsafe staging target/flag refused before connecting');
    }
    $log=file_get_contents($dir.'/diagnostics.log');
    $assert(substr_count($log,'GSO application diagnostic')>=6, 'Server-side technical diagnostics recorded');
    $assert(str_contains($log,'"sqlstate":"HY000"')&&str_contains($log,'"driver_code":9999'), 'Safe PDO diagnostic codes retained');
    $assert(str_contains($log,'"trace":')&&str_contains($log,'"line":')&&str_contains($log,'"operation":'), 'Argument-free fault location/stack metadata retained');
    $assert(!str_contains($log,'HARDENING_PRIVATE_SECRET')&&!str_contains($log,$valid['GSO_APP_KEY']), 'Secrets absent from server diagnostics');
    echo "PASS: Deployment hardening E2E ({$checks} checks).\n";
} catch (Throwable $e) { $fail=$e; fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL); }
finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($request) $exec('DELETE FROM resource_requests WHERE request_id=?',[$request]);
    if ($work) $exec('DELETE FROM maintenance_schedules WHERE maintenance_id=?',[$work]);
    if ($resource) $exec('DELETE FROM resources WHERE resource_id=?',[$resource]);
    if ($user) $exec('DELETE FROM users WHERE user_id=?',[$user]);
    foreach ($cookies as $cookie) if (is_file($cookie)) unlink($cookie);
}
exit($fail?1:0);
