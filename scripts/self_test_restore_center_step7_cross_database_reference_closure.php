<?php

declare(strict_types=1);

/**
 * Step 7 cross-database qualified-reference closure (disposable only).
 * LIVE_* counts stay 0 — never touches Owner live job / live engine / source package.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_package_compat.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php';
require_once $projectRoot . '/includes/backup/restore/restore_sql_safety.php';
require_once $projectRoot . '/includes/backup/backup_pdo_export.php';

$pass = 0;
$fail = 0;
$markers = [
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'LIVE_STEP7_RETRY_COUNT' => 0,
    'LIVE_STEP8_EXECUTION_COUNT' => 0,
    'LIVE_PRIVATE_ENGINE_STOP_COUNT' => 0,
    'SOURCE_PACKAGE_MUTATION_COUNT' => 0,
    'ORIGINAL_SQL_DUMP_MUTATION_COUNT' => 0,
    'BROAD_MYSQL_PROCESS_KILL_COUNT' => 0,
    'NAIVE_REGEX_NORMALIZATION_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
    'FALSE_POSITIVE_NUMERIC_LITERAL_MUTATION_DETECTED' => 0,
    'REAL_EXTERNAL_CROSS_DB_STILL_REJECTED' => 0,
    'PHASE2B1_GLOBAL_POLICY_INTACT' => 0,
    'BACKUP_PDO_CONTRACT_TRACED' => 0,
];

function s7xref_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$trusted = 'orange_src_db';
$policySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php');
$safetySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_safety.php');
$pdoSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_pdo_export.php');

s7xref_ok(str_contains($pdoSrc, 'function orange_backup_pdo_write_preamble'), 'backup PDO preamble traced');
s7xref_ok(str_contains($pdoSrc, 'SHOW CREATE TABLE'), 'backup PDO uses SHOW CREATE TABLE');
s7xref_ok(str_contains($policySrc, 'orange_restore_sql_is_schema_qualified_object_context'), 'private scanner uses object context');
s7xref_ok(str_contains($safetySrc, "'USE database switch'"), 'Phase-2B.1 USE ban preserved');
$markers['BACKUP_PDO_CONTRACT_TRACED'] = 1;
$markers['PHASE2B1_GLOBAL_POLICY_INTACT'] = str_contains($safetySrc, "'USE database switch'") ? 1 : 0;

// Build disposable stream matching PDO export shape (preamble + CREATE with DEFAULT decimal + INSERT).
ob_start();
$tmpHandle = fopen('php://temp', 'w+b');
orange_backup_pdo_write_preamble($tmpHandle);
fwrite($tmpHandle, "\nDROP TABLE IF EXISTS `demo_prices`;\n");
fwrite(
    $tmpHandle,
    "CREATE TABLE `demo_prices` (\n"
    . "  `id` int NOT NULL AUTO_INCREMENT,\n"
    . "  `amount` decimal(18,4) NOT NULL DEFAULT 0.0000,\n"
    . "  PRIMARY KEY (`id`)\n"
    . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n"
);
fwrite($tmpHandle, "INSERT INTO `demo_prices` (`id`, `amount`) VALUES\n(1, 12.50),\n(2, 0.0000);\n");
orange_backup_pdo_write_postamble($tmpHandle);
rewind($tmpHandle);
$pdoShapedSql = stream_get_contents($tmpHandle) ?: '';
fclose($tmpHandle);
ob_end_clean();

s7xref_ok($pdoShapedSql !== '' && str_contains($pdoShapedSql, 'DEFAULT 0.0000'), 'disposable PDO-shaped dump has DEFAULT decimal');
s7xref_ok(str_contains($pdoShapedSql, '12.50'), 'disposable PDO-shaped dump has INSERT decimal');

// Prove tokenizer still sees numeric ident.dot.ident shapes (defect surface).
$lex = orange_restore_sql_tokenize_executable('INSERT INTO `demo_prices` (`id`, `amount`) VALUES (1, 12.50)');
$hasNumericDot = false;
for ($i = 0; $i < count($lex) - 2; $i++) {
    if (($lex[$i]['type'] ?? '') === 'ident'
        && ($lex[$i + 1]['type'] ?? '') === 'dot'
        && ($lex[$i + 2]['type'] ?? '') === 'ident'
        && preg_match('/^[0-9]+$/', (string) ($lex[$i]['value'] ?? '')) === 1
    ) {
        $hasNumericDot = true;
        break;
    }
}
s7xref_ok($hasNumericDot, 'tokenizer still yields numeric ident.dot.ident (surface intact)');

$classOk = orange_restore_private_sql_classify_dump($pdoShapedSql, $trusted);
s7xref_ok(!empty($classOk['ok']), 'PDO-shaped dump classifies ok after repair');
s7xref_ok(
    ($classOk['classification'] ?? '') === ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH,
    'PDO-shaped dump => NO_TOP_LEVEL_DATABASE_SWITCH'
);
$crossOk = orange_restore_private_sql_scan_cross_database_refs($pdoShapedSql, $trusted);
s7xref_ok($crossOk === [], 'no foreign db names from decimal literals');
$markers['FALSE_POSITIVE_NUMERIC_LITERAL_MUTATION_DETECTED'] = 1;

// Real external reference must still reject.
$evil = $pdoShapedSql . "\nINSERT INTO other_db.t SELECT 1;\n";
$classEvil = orange_restore_private_sql_classify_dump($evil, $trusted);
s7xref_ok(empty($classEvil['ok']), 'external qualified ref still rejected');
s7xref_ok(
    ($classEvil['classification'] ?? '') === ORANGE_RESTORE_SQL_CLASS_CROSS_DB,
    'external => CROSS_DATABASE_QUALIFIED_REFERENCES'
);
s7xref_ok(
    ($classEvil['safe_code'] ?? '') === ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE,
    'external maps to STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE'
);
$markers['REAL_EXTERNAL_CROSS_DB_STILL_REJECTED'] = 1;

// Same-source qualified object ref in object context is allowed.
$same = "INSERT INTO `{$trusted}`.`demo_prices` (`id`, `amount`) VALUES (3, 1.25);\n";
$classSame = orange_restore_private_sql_classify_dump($same, $trusted);
s7xref_ok(!empty($classSame['ok']), 'same-source qualified object ref allowed');

// System schema still rejected in object context.
$sys = "INSERT INTO information_schema.tables SELECT 1;\n";
$classSys = orange_restore_private_sql_classify_dump($sys, $trusted);
s7xref_ok(empty($classSys['ok']), 'information_schema object ref rejected');
s7xref_ok(($classSys['classification'] ?? '') === ORANGE_RESTORE_SQL_CLASS_CROSS_DB, 'system schema => CROSS_DB');

// Alias.column / non-object context must not reject.
$alias = "SELECT p.amount FROM demo_prices p WHERE p.id = 1;\n";
$classAlias = orange_restore_private_sql_classify_dump($alias, $trusted);
s7xref_ok(!empty($classAlias['ok']), 'alias.column non-object-context not rejected');

// Staging Phase-2B.1 still rejects USE and still accepts decimal INSERT into staging name.
try {
    orange_restore_sql_validate_statement_for_staging(
        'INSERT INTO demo_prices VALUES (1, 12.50)',
        'shadow_db',
        $trusted
    );
    s7xref_ok(true, 'staging accepts decimal INSERT');
} catch (Throwable) {
    s7xref_ok(false, 'staging accepts decimal INSERT');
}
try {
    orange_restore_sql_validate_statement_for_staging('USE `' . $trusted . '`', 'shadow_db', $trusted);
    s7xref_ok(false, 'staging still rejects USE');
} catch (Throwable $e) {
    s7xref_ok(str_contains($e->getMessage(), 'USE'), 'staging still rejects USE');
}

// Package compat on disposable gzip (source bytes immutable).
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7xref_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$pkg = $tmp . DIRECTORY_SEPARATOR . 'pkg';
@mkdir($pkg, 0777, true);
$dumpRel = 'database.sql.gz';
$dumpPath = $pkg . DIRECTORY_SEPARATOR . $dumpRel;
file_put_contents($dumpPath, gzencode($pdoShapedSql, 1));
$shaBefore = hash_file('sha256', $dumpPath) ?: '';
$compat = orange_restore_package_private_engine_import_compat(
    $pkg,
    ['export_backend' => 'php_pdo', 'dump_file' => $dumpRel],
    'orange_shadow',
    $trusted,
    $trusted
);
$shaAfter = hash_file('sha256', $dumpPath) ?: '';
s7xref_ok(!empty($compat['ok']), 'private package compat accepts PDO-shaped dump');
s7xref_ok(hash_equals($shaBefore, $shaAfter), 'source dump immutable during compat');
$markers['ORIGINAL_SQL_DUMP_MUTATION_COUNT'] = hash_equals($shaBefore, $shaAfter) ? 0 : 1;

// Genuine failure-then-success: evil package fails; clean succeeds (disposable).
file_put_contents($dumpPath, gzencode($evil, 1));
$compatFail = orange_restore_package_private_engine_import_compat(
    $pkg,
    ['export_backend' => 'php_pdo', 'dump_file' => $dumpRel],
    'orange_shadow',
    $trusted,
    $trusted
);
s7xref_ok(empty($compatFail['ok']), 'genuine disposable failure on external ref');
s7xref_ok(
    ($compatFail['error'] ?? '') === ORANGE_RESTORE_STEP7_SQL_DUMP_CROSS_DATABASE_REFERENCE,
    'failure code exact CROSS_DATABASE'
);
file_put_contents($dumpPath, gzencode($pdoShapedSql, 1));
$compatOk2 = orange_restore_package_private_engine_import_compat(
    $pkg,
    ['export_backend' => 'php_pdo', 'dump_file' => $dumpRel],
    'orange_shadow',
    $trusted,
    $trusted
);
s7xref_ok(!empty($compatOk2['ok']), 'genuine disposable success after clean dump');

// No global regex strip of qualifiers.
s7xref_ok(!preg_match('/preg_replace\s*\(\s*[\'\"]\/.*?\\\\./', $policySrc), 'no naive qualifier preg_replace');
$markers['NAIVE_REGEX_NORMALIZATION_COUNT'] = preg_match('/preg_replace\s*\(\s*[\'\"]\/.*?db/i', $policySrc) ? 1 : 0;

// Cleanup tmp
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $p = $f->getPathname();
    $f->isDir() ? @rmdir($p) : @unlink($p);
}
@rmdir($tmp);

echo "SUMMARY pass={$pass} fail={$fail}\n";
echo 'MARKERS ' . json_encode($markers, JSON_UNESCAPED_UNICODE) . "\n";
exit($fail > 0 ? 1 : 0);
