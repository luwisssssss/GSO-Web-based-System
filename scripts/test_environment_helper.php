<?php
/** Regression fixtures must never connect to a production/development database. */
require_once dirname(__DIR__) . '/config/bootstrap.php';
if (PHP_SAPI !== 'cli'
    || !in_array(strtolower((string) gsoEnv('GSO_APP_ENV', '')), ['test', 'development'], true)
    || !preg_match('/\Agso_r[0-9]+[ab]?_test_[a-zA-Z0-9_]+\z/', (string) gsoEnv('GSO_DB_NAME', ''))) {
    throw new RuntimeException('Regression fixtures require CLI, an explicit nonproduction environment, and an isolated gso_rN_test_* database.');
}
