<?php
/** Isolated load/race-test CLI tools. No application request may execute these scripts. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$loadRoot = dirname(__DIR__, 2);
require_once $loadRoot . '/config/bootstrap.php';
if (gsoEnv('GSO_APP_ENV') !== 'test') throw new RuntimeException('Explicit test environment required.');
function loadDatabaseName(): string {
    $name = (string) gsoEnv('GSO_DB_NAME', '');
    if (!preg_match('/\Agso_load_test_(?:phase_b_|phase_c1_|phase_c2a_|phase_c2b_)?[0-9]{8}_[0-9]{6}\z/', $name)) throw new RuntimeException('Isolated gso_load_test timestamp required.');
    return $name;
}
function loadConnection(bool $selectDatabase = true): PDO {
    $name = loadDatabaseName();
    return new PDO('mysql:host=' . gsoEnv('GSO_DB_HOST', 'localhost') . ';port=' . gsoEnv('GSO_DB_PORT', '3306')
        . ($selectDatabase ? ';dbname=' . $name : '') . ';charset=utf8mb4',
        gsoEnv('GSO_DB_USERNAME', 'root'), gsoEnv('GSO_DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function loadExec(PDO $pdo, string $sql, array $args = []): void { $pdo->prepare($sql)->execute($args); }
function loadSchemaCounts(PDO $pdo): array {
    $queries = [
        'tables'=>"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'",
        'foreign_keys'=>"SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()",
        'indexes'=>"SELECT COUNT(*) FROM (SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() GROUP BY TABLE_NAME,INDEX_NAME) x",
        'unique'=>"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='UNIQUE'",
        'checks'=>"SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'",
        'generated_columns'=>"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND GENERATION_EXPRESSION<>''",
    ];
    $out=[]; foreach ($queries as $key=>$sql) $out[$key]=(int)$pdo->query($sql)->fetchColumn();
    $out['schema_version']=$pdo->query("SELECT setting_value FROM system_settings WHERE setting_key='schema_version'")->fetchColumn();
    return $out;
}
function loadAssertSchema(PDO $pdo): array {
    $actual=loadSchemaCounts($pdo);
    $expected=['tables'=>22,'foreign_keys'=>57,'indexes'=>104,'unique'=>18,'checks'=>11,'generated_columns'=>3,'schema_version'=>'20261010_008'];
    if ($actual !== $expected) throw new RuntimeException('Frozen schema mismatch: '.json_encode($actual));
    return $actual;
}
function loadSnapshot(PDO $pdo): array {
    $result=[];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $indexes=$pdo->query("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'")->fetchAll();
        usort($indexes, fn($a,$b)=>$a['Seq_in_index']<=>$b['Seq_in_index']);
        $order=implode(',',array_map(fn($r)=>'`'.$r['Column_name'].'`',$indexes));
        $rows=$pdo->query("SELECT * FROM `$table`".($order?' ORDER BY '.$order:''))->fetchAll();
        $create=$pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
        // AUTO_INCREMENT position is data state, not schema structure.
        $create=preg_replace('/ AUTO_INCREMENT=\d+/', '', $create);
        $result[$table]=['rows'=>count($rows),'data_hash'=>hash('sha256',serialize($rows)),'schema_hash'=>hash('sha256',$create)];
    }
    return $result;
}
