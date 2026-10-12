<?php
/** Server-private, explicit EMPTY staging database rehearsal. Never drops data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$target = trim((string) getenv('GSO_STAGING_DB_NAME'));
if (!preg_match('/\Agso_stage_[a-zA-Z0-9_]+\z/', $target)
    || getenv('GSO_STAGING_ALLOW_SCHEMA_IMPORT') !== '1') {
    fwrite(STDERR, "REFUSED: explicitly set GSO_STAGING_DB_NAME=gso_stage_* and GSO_STAGING_ALLOW_SCHEMA_IMPORT=1.\n"); exit(2);
}
define('GSO_SCHEMA_TOOL', true);
require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/app/models/schema_migrator.php';
if (strcasecmp($target, (string) gsoEnv('GSO_DB_NAME', 'gso_database')) === 0) {
    fwrite(STDERR, "REFUSED: staging target equals the configured application database.\n"); exit(2);
}
try {
    $pdo = new PDO('mysql:host=' . gsoEnv('GSO_DB_HOST', 'localhost') . ';port=' . gsoEnv('GSO_DB_PORT', '3306')
        . ';dbname=' . $target . ';charset=utf8mb4', gsoEnv('GSO_DB_USERNAME', 'root'), gsoEnv('GSO_DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $server = $pdo->query('SELECT VERSION() version, @@version_comment vendor')->fetch();
    $objects = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    foreach (['ROUTINES'=>'ROUTINE_SCHEMA', 'TRIGGERS'=>'TRIGGER_SCHEMA', 'EVENTS'=>'EVENT_SCHEMA'] as $table => $column) {
        $objects += (int) $pdo->query('SELECT COUNT(*) FROM information_schema.' . $table . ' WHERE ' . $column . '=DATABASE()')->fetchColumn();
    }
    if ($objects !== 0) { fwrite(STDERR, "REFUSED: staging database is not empty. Nothing imported.\n"); exit(2); }
    $root = dirname(__DIR__);
    $migration = gsoRunSchemaMigrations($pdo, $root);
    $repeat = gsoRunSchemaMigrations($pdo, $root);
    $queries = [
        'tables' => 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()',
        'columns' => 'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()',
        'foreign_keys' => 'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()',
        'indexes' => 'SELECT COUNT(*) FROM (SELECT DISTINCT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()) x',
        'unique_constraints' => "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='UNIQUE'",
        'checks' => 'SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()',
        'generated_columns' => "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND EXTRA LIKE '%GENERATED%'",
    ];
    $expected = ['tables'=>22, 'columns'=>275, 'foreign_keys'=>57, 'indexes'=>104, 'unique_constraints'=>18, 'checks'=>11, 'generated_columns'=>3];
    $actual = [];
    foreach ($queries as $name => $sql) $actual[$name] = (int) $pdo->query($sql)->fetchColumn();
    $version = gsoRecordedSchemaVersion($pdo);
    // DDL success/counts alone are insufficient: verify FK actions and generated
    // expressions against the frozen definition, tolerating engine formatting.
    $frozen = json_decode(file_get_contents($root . '/docs/R11_SCHEMA_FREEZE.json'), true, 512, JSON_THROW_ON_ERROR);
    $definitionIssues = [];
    $normalize = static fn(string $text): string => strtolower(preg_replace('/[\s`()]+/', '', $text));
    foreach ($frozen['tables'] as $name => $table) {
        foreach ($table['columns'] as $column) {
            if (trim((string) $column['GENERATION_EXPRESSION']) === '') continue;
            $s = $pdo->prepare('SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $s->execute([$name, $column['COLUMN_NAME']]);
            if ($normalize((string) $s->fetchColumn()) !== $normalize($column['GENERATION_EXPRESSION'])) $definitionIssues[] = $name . '.' . $column['COLUMN_NAME'];
        }
        foreach ($table['foreign_keys'] as $fk) {
            $s = $pdo->prepare('SELECT UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
            $s->execute([$name, $fk['CONSTRAINT_NAME']]); $row = $s->fetch();
            if (!$row || $row['UPDATE_RULE'] !== $fk['UPDATE_RULE'] || $row['DELETE_RULE'] !== $fk['DELETE_RULE']) $definitionIssues[] = $name . '.' . $fk['CONSTRAINT_NAME'];
        }
    }
    $pass = $actual === $expected && $version === '20261010_008' && $definitionIssues === []
        && $repeat === ['Schema is current at version 20261010_008.'];
    echo json_encode(['result'=>$pass?'PASS':'FAIL', 'server'=>$server, 'schema_version'=>$version,
        'counts'=>$actual, 'definition_issues'=>$definitionIssues, 'migration'=>$migration, 'repeat'=>$repeat,
        'scope'=>'Explicit empty staging database only; retained for operator inspection. No application rows inserted.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($pass ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . gsoSafeApplicationError($error, 'staging schema rehearsal')
        . " Staging database may contain partial DDL; retain it for inspection. No automatic cleanup.\n"); exit(1);
}
