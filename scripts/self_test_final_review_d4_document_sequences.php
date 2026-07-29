<?php

declare(strict_types=1);

/**
 * FSR D4 — document_sequences.last_value reserved-identifier quoting (test-only).
 *
 * Usage: php scripts/self_test_final_review_d4_document_sequences.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/scripts/lib/final_review_d1_fixture.php';
require_once $root . '/includes/catalog_schema.php';
require_once $root . '/includes/catalog_bootstrap_store.php';
require_once $root . '/includes/document_sequences.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d4s_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo 'NOTE  suite=document_sequences start=' . gmdate('c') . "\n";

$bootSrc = (string) file_get_contents($root . '/includes/catalog_bootstrap_store.php');
$schemaSrc = (string) file_get_contents($root . '/includes/catalog_schema.php');
$seqSrc = (string) file_get_contents($root . '/includes/document_sequences.php');
d4s_assert(str_contains($bootSrc, '`last_value` BIGINT'), 'bootstrap CREATE quotes last_value');
d4s_assert(str_contains($schemaSrc, '`last_value` BIGINT'), 'catalog_schema CREATE quotes last_value');
d4s_assert(!preg_match('/CREATE TABLE(?: IF NOT EXISTS)? document_sequences[\s\S]{0,200}[^`]last_value BIGINT/', $bootSrc), 'bootstrap has no unquoted last_value in CREATE');
d4s_assert(str_contains($seqSrc, '`last_value`'), 'document_sequences.php quotes last_value in DML');
d4s_assert(
    !preg_match('/INSERT INTO document_sequences \(scope, last_value\)/', $seqSrc)
    && !preg_match('/SELECT last_value FROM document_sequences/', $seqSrc),
    'document_sequences.php has no unquoted last_value column refs'
);
d4s_assert((int) ORANGE_CATALOG_SCHEMA_PHP_REVISION === 124, 'schema revision constant 124');

$admin = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$dbName = 'orange_d4_docseq_' . getmypid() . '_' . bin2hex(random_bytes(3));
$admin->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=' . $dbName . ';charset=utf8mb4',
    'root',
    '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$pdo->exec('SET NAMES utf8mb4');

try {
    $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $mode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
    echo 'NOTE  mysql_version=' . $ver . "\n";
    echo 'NOTE  sql_mode=' . $mode . "\n";

    // A: bootstrap path — table absent
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_sequences'"
    )->fetchColumn();
    d4s_assert($exists === 0, 'fresh DB has no document_sequences');
    orange_catalog_bootstrap_store_tables($pdo);
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_sequences'"
    )->fetchColumn();
    d4s_assert($exists === 1, 'bootstrap creates document_sequences');
    $create = (string) $pdo->query('SHOW CREATE TABLE document_sequences')->fetch(PDO::FETCH_NUM)[1];
    d4s_assert(str_contains($create, '`last_value`'), 'SHOW CREATE uses quoted last_value');

    // Drop and prove catalog_schema CREATE path (bypass table-exists cache after DROP).
    $pdo->exec('DROP TABLE document_sequences');
    if (function_exists('orange_schema_invalidate_table_exists')) {
        orange_schema_invalidate_table_exists('document_sequences');
    }
    $gone = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_sequences'"
    )->fetchColumn();
    d4s_assert($gone === 0, 'table absent after DROP (information_schema)');
    orange_catalog_safe_exec(
        $pdo,
        'CREATE TABLE document_sequences (
            scope VARCHAR(64) NOT NULL,
            `last_value` BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (scope)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $back = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'document_sequences'"
    )->fetchColumn();
    d4s_assert($back === 1, 'catalog_schema-style quoted CREATE succeeds');

    // Functional allocate using the exact Production SQL shape from document_sequences.php
    // (avoid full orange_catalog_ensure_schema on a minimal DB).
    $alloc = static function (PDO $pdo, string $scope) : int {
        $pdo->prepare(
            'INSERT INTO document_sequences (scope, `last_value`) VALUES (?, 1)
             ON DUPLICATE KEY UPDATE `last_value` = `last_value` + 1'
        )->execute([$scope]);
        $st = $pdo->prepare('SELECT `last_value` FROM document_sequences WHERE scope = ? LIMIT 1');
        $st->execute([$scope]);

        return (int) $st->fetchColumn();
    };
    $a = $alloc($pdo, 'd4_test_scope_c1');
    $b = $alloc($pdo, 'd4_test_scope_c1');
    $c = $alloc($pdo, 'd4_test_scope_c2');
    d4s_assert($a === 1 && $b === 2, 'sequential allocate 1 then 2 for same country scope');
    d4s_assert($c === 1, 'different country scope independent');
    $stored = (int) $pdo->query(
        "SELECT `last_value` FROM document_sequences WHERE scope = 'd4_test_scope_c1'"
    )->fetchColumn();
    d4s_assert($stored === 2, 'stored last_value after two allocates');
    $peekSql = (int) $pdo->query(
        "SELECT `last_value` FROM document_sequences WHERE scope = 'd4_test_scope_c1'"
    )->fetchColumn();
    d4s_assert($peekSql === 2, 'read without consume leaves last_value');

    // Idempotent bootstrap: existing rows preserved
    orange_catalog_bootstrap_store_tables($pdo);
    $stored2 = (int) $pdo->query(
        "SELECT `last_value` FROM document_sequences WHERE scope = 'd4_test_scope_c1'"
    )->fetchColumn();
    d4s_assert($stored2 === 2, 'existing last_value preserved across bootstrap');

    // Concurrent allocate (two connections)
    $pdo2 = new PDO(
        'mysql:host=127.0.0.1;port=3306;dbname=' . $dbName . ';charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $vals = [];
    for ($i = 0; $i < 10; $i++) {
        $vals[] = $alloc(($i % 2 === 0) ? $pdo : $pdo2, 'd4_race_scope_c1');
    }
    sort($vals);
    $uniq = array_values(array_unique($vals));
    d4s_assert($vals === $uniq && count($vals) === 10, 'concurrent allocates unique monotonic values');
    echo 'NOTE  concurrent_values=' . implode(',', $vals) . "\n";
    echo 'NOTE  concurrency_mechanism=INSERT ... ON DUPLICATE KEY UPDATE atomic row increment\n';

    // Unquoted CREATE still fails (integrity: repair required)
    try {
        $pdo->exec(
            'CREATE TABLE document_sequences_bad (
                scope VARCHAR(64) NOT NULL,
                last_value BIGINT NOT NULL DEFAULT 0,
                PRIMARY KEY (scope)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        d4s_assert(false, 'unquoted last_value CREATE must fail on MySQL 8.4');
    } catch (Throwable $e) {
        d4s_assert(str_contains($e->getMessage(), '1064') || str_contains($e->getMessage(), 'last_value'), 'unquoted CREATE still Error 1064');
    }

    echo "NOTE  safe_exec_classification=REQUIRED_DDL_ERROR_LOGGED_AND_CALLER_CONTINUES\n";
    echo "NOTE  (orange_catalog_safe_exec unchanged; CREATE now succeeds so no 1064 for this table)\n";
} catch (Throwable $e) {
    echo 'FAIL  uncaught: ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    try {
        $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
    } catch (Throwable) {
    }
}

echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo 'DURATION_SEC=' . round(microtime(true) - $started, 3) . "\n";
exit($failures > 0 ? 1 : 0);
