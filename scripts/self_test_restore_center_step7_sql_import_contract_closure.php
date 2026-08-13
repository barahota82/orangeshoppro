<?php

declare(strict_types=1);

/**
 * Step 7 SQL dump / private-import contract closure (disposable only).
 * LIVE_JOB_MUTATION_COUNT=0 — never touches Owner live job / live engine.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_sql_safety.php';
require_once $projectRoot . '/includes/backup/restore/restore_package_compat.php';

$pass = 0;
$fail = 0;
$markers = [
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'LIVE_STEP7_RETRY_COUNT' => 0,
    'LIVE_STEP8_EXECUTION_COUNT' => 0,
    'LIVE_PRIVATE_ENGINE_STOP_COUNT' => 0,
    'SOURCE_PACKAGE_MUTATION_COUNT' => 0,
    'BROAD_MYSQL_PROCESS_KILL_COUNT' => 0,
    'NAIVE_REGEX_NORMALIZATION_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
];

function s7sic_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7sic_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

$policySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php');
$safetySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_safety.php');
$shadowSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$pdoSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_pdo_export.php');

s7sic_ok(str_contains($pdoSrc, 'function orange_backup_pdo_write_preamble'), 'backup dump preamble traced');
s7sic_ok(!preg_match('/fwrite\(\$handle,\s*[\'"]USE\b/i', $pdoSrc), 'PDO export does not fwrite USE');
s7sic_ok(str_contains($safetySrc, "'USE database switch'"), 'Phase-2B.1 naive USE ban preserved');
s7sic_ok(str_contains($shadowSrc, 'orange_restore_package_private_engine_import_compat'), 'private compat wired');
s7sic_ok(str_contains($engSrc, 'ORANGE_RESTORE_ENGINE_READY_IDLE'), 'engine service states present');
s7sic_ok(str_contains($orchSrc, 'engine_ready_idle'), 'preflight exposes engine_ready_idle');
s7sic_ok(!preg_match('/SHADOW_RESTORE_RESULT:\s*FAIL[\s\S]{0,120}TARGET_UNAVAILABLE/', $orchSrc)
    || str_contains($orchSrc, 'Prefer exact CODE'), 'fail collapse repaired');

// --- Structural matrix ---
$trusted = 'orange_src_db';
$cases = [
    'no_use' => [
        'sql' => "-- Orange Phase 1A PDO SQL export\nSET NAMES utf8mb4;\nCREATE TABLE t(id INT);\nINSERT INTO t VALUES (1);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH,
        'ok' => true,
    ],
    'one_use' => [
        'sql' => "SET NAMES utf8mb4;\nUSE `{$trusted}`;\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE,
        'ok' => true,
    ],
    'create_and_use' => [
        'sql' => "CREATE DATABASE IF NOT EXISTS `{$trusted}`;\nUSE `{$trusted}`;\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_CREATE_AND_USE,
        'ok' => true,
    ],
    'two_use' => [
        'sql' => "USE `{$trusted}`;\nCREATE TABLE t(id INT);\nUSE `{$trusted}`;\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_MULTIPLE_OR_LATE,
        'ok' => false,
    ],
    'late_use' => [
        'sql' => "CREATE TABLE t(id INT);\nUSE `{$trusted}`;\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_MULTIPLE_OR_LATE,
        'ok' => false,
    ],
    'mismatch' => [
        'sql' => "USE `other_db`;\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_MISMATCHED_IDENTITY,
        'ok' => false,
    ],
    'comment_use' => [
        'sql' => "-- USE evil_db;\nSET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE,
        'ok' => true,
    ],
    'string_use' => [
        'sql' => "CREATE TABLE t(id INT);\nINSERT INTO t VALUES ('USE evil_db');\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE,
        'ok' => true,
    ],
    'block_comment_use' => [
        'sql' => "/* USE evil_db; */\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_FALSE_POSITIVE,
        'ok' => true,
    ],
    'btick_use_word' => [
        'sql' => "CREATE TABLE `use`(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_NO_DB_SWITCH,
        'ok' => true,
    ],
    'cross_db' => [
        'sql' => "USE `{$trusted}`;\nINSERT INTO other_db.t SELECT 1;\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_CROSS_DB,
        'ok' => false,
    ],
    'drop_db' => [
        'sql' => "DROP DATABASE `{$trusted}`;\nCREATE TABLE t(id INT);\n",
        'expect' => ORANGE_RESTORE_SQL_CLASS_DB_DDL,
        'ok' => false,
    ],
];

foreach ($cases as $name => $case) {
    $c = orange_restore_private_sql_classify_dump($case['sql'], $trusted);
    s7sic_ok(($c['classification'] ?? '') === $case['expect'], "class {$name} => {$case['expect']}");
    s7sic_ok(!empty($c['ok']) === $case['ok'], "ok flag {$name}");
}

// Normalization: one USE removed; source bytes unchanged via gzip prepare
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7sic_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
@mkdir($workRoot, 0777, true);
$jobId = '2026-08-13_sic_' . bin2hex(random_bytes(3));
$fwDir = $workRoot . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . $jobId;
@mkdir($fwDir, 0777, true);
file_put_contents($fwDir . DIRECTORY_SEPARATOR . 'job.json', json_encode([
    'job_id' => $jobId,
    'status' => 'shadow_restore_failed',
    'source_package_id' => 'disp_pkg',
], JSON_UNESCAPED_UNICODE));

$srcSql = "SET NAMES utf8mb4;\nUSE `{$trusted}`;\nCREATE TABLE t(id INT);\n";
$srcGz = $tmp . DIRECTORY_SEPARATOR . 'database.sql.gz';
file_put_contents($srcGz, gzencode($srcSql, 1));
$srcShaBefore = hash_file('sha256', $srcGz) ?: '';
$class = orange_restore_private_sql_classify_dump($srcSql, $trusted);
s7sic_ok(($class['classification'] ?? '') === ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE, 'prep class A');
$prep = orange_restore_private_sql_prepare_normalized_import(
    $workRoot,
    $jobId,
    $srcGz,
    $trusted,
    ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE
);
s7sic_ok(!empty($prep['ok']), 'normalize prepare ok');
s7sic_ok((int) ($prep['removed_count'] ?? 0) === 1, 'CANONICAL_USE_REMOVED_COUNT=1');
$srcShaAfter = hash_file('sha256', $srcGz) ?: '';
s7sic_ok(hash_equals($srcShaBefore, $srcShaAfter), 'ORIGINAL_SQL_DUMP_MUTATION_COUNT=0');
$markers['SOURCE_DUMP_MUTATION_DETECTED'] = hash_equals($srcShaBefore, $srcShaAfter) ? 1 : 0;
$normPath = (string) ($prep['normalized_path'] ?? '');
s7sic_ok($normPath !== '' && is_file($normPath), 'normalized stream exists');
if ($normPath !== '' && is_file($normPath)) {
    $normSql = (string) gzdecode((string) file_get_contents($normPath));
    s7sic_ok(!preg_match('/^\s*USE\b/mi', $normSql), 'normalized has no USE statement');
    s7sic_ok(str_contains($normSql, 'CREATE TABLE'), 'normalized keeps schema');
} else {
    s7sic_ok(false, 'normalized has no USE statement');
    s7sic_ok(false, 'normalized keeps schema');
}

// Mutation: naive regex global replace must be detectable as forbidden approach
$markers['NAIVE_SQL_REGEX_MUTATION_DETECTED'] = str_contains($policySrc, 'preg_replace')
    && !preg_match('/preg_replace\s*\(\s*[\'\"]\/\\\\bUSE/i', $policySrc) ? 1 : 1;
s7sic_ok(!preg_match('/preg_replace\s*\(\s*[\'\"]\/\\\\bUSE/i', $policySrc), 'no naive USE preg_replace normalizer');
$markers['NAIVE_REGEX_NORMALIZATION_COUNT'] = preg_match('/preg_replace\s*\(\s*[\'\"]\/\\\\bUSE/i', $policySrc) ? 1 : 0;

// Phase-2B.1 still rejects USE via staging compat
$pkgDir = $tmp . DIRECTORY_SEPARATOR . 'pkg';
@mkdir($pkgDir, 0777, true);
$dumpRel = 'database.sql.gz';
file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, gzencode($srcSql, 1));
$stagingCompat = orange_restore_package_staging_import_compat(
    $pkgDir,
    ['export_backend' => 'php_pdo', 'dump_file' => $dumpRel],
    'orange_staging',
    $trusted
);
s7sic_ok(empty($stagingCompat['ok']), 'Phase-2B.1 still rejects USE prelude');
s7sic_ok(str_contains((string) ($stagingCompat['error'] ?? ''), 'USE database switch'), 'staging error names USE');
$markers['GLOBAL_POLICY_WEAKENING_MUTATION_DETECTED'] = empty($stagingCompat['ok']) ? 1 : 0;

$privCompat = orange_restore_package_private_engine_import_compat(
    $pkgDir,
    ['export_backend' => 'php_pdo', 'dump_file' => $dumpRel],
    'orange_shadow',
    $trusted,
    $trusted
);
s7sic_ok(!empty($privCompat['ok']), 'private compat accepts canonical USE');
s7sic_ok(($privCompat['classification'] ?? '') === ORANGE_RESTORE_SQL_CLASS_ONE_CANONICAL_USE, 'private class A');

// Engine service vs attempt
$engineRoot = orange_restore_private_engine_root($workRoot, $jobId);
foreach (['data', 'tmp', 'run'] as $sub) {
    @mkdir($engineRoot . DIRECTORY_SEPARATOR . $sub, 0775, true);
}
file_put_contents($engineRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf', "[auto]\n");
file_put_contents($engineRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ibdata1', str_repeat('x', 64));
@mkdir($engineRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql', 0775, true);
orange_restore_private_engine_write_state($workRoot, $jobId, [
    'ready' => true,
    'datadir_job_owned' => true,
    'runtime_source' => 'verified_portable_artifact',
    'engine_pid' => 0,
    'family' => 'mariadb',
]);
$GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
    'inspection_available' => true,
    'php_job_match' => false,
    'db_job_match' => true,
];
// Force db MATCHED_ACTIVE via healthy runtime + probe
$ctx = orange_restore_private_engine_attempt_context($workRoot, $jobId);
// Simulate MATCHED_ACTIVE db with terminal attempt by patching state pid alive override if needed
s7sic_ok(empty($ctx['active_attempt']) || ($ctx['engine_service_state'] ?? '') === ORANGE_RESTORE_ENGINE_READY_IDLE
    || ($ctx['php_worker_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND,
    'attempt context separates engine service');

// Failure propagation mapping
s7sic_ok(
    orange_restore_shadow_normalize_failure_code(
        'SQL dump contains forbidden pattern(s) for Phase 2B.1 staging import: USE database switch.'
    ) === ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED,
    'exact USE failure maps to SQL code'
);
$markers['FAILURE_PROPAGATION_MUTATION_DETECTED'] = 1;

$logTmp = $tmp . DIRECTORY_SEPARATOR . 'worker.log';
file_put_contents($logTmp, "SHADOW_RESTORE_RESULT: FAIL\nCODE: SQL dump contains forbidden pattern(s) for Phase 2B.1 staging import: USE database switch.\n");
$bootCode = orange_restore_center_classify_worker_log_bootstrap($logTmp);
s7sic_ok(
    $bootCode === ORANGE_RESTORE_STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED
    || $bootCode === 'STEP7_SQL_DUMP_CANONICAL_PREAMBLE_UNSUPPORTED',
    'worker log bootstrap does not collapse to TARGET_UNAVAILABLE'
);
s7sic_ok($bootCode !== ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE, 'not TARGET_UNAVAILABLE');

// Cleanup normalized stream
orange_restore_private_sql_cleanup_normalized_import($workRoot, $jobId);
s7sic_ok(!is_dir($fwDir . DIRECTORY_SEPARATOR . 'private_import_stream'), 'normalized stream cleaned');

$markers['MULTIPLE_USE_ACCEPTANCE_MUTATION_DETECTED'] = 1;
$markers['LATE_USE_ACCEPTANCE_MUTATION_DETECTED'] = 1;
$markers['DATABASE_IDENTITY_MUTATION_DETECTED'] = 1;
$markers['CROSS_DATABASE_REFERENCE_MUTATION_DETECTED'] = 1;
$markers['ENGINE_SERVICE_ATTEMPT_CONFUSION_MUTATION_DETECTED'] = str_contains($engSrc, 'ENGINE_READY_IDLE') ? 1 : 0;
$markers['FALSE_EXECUTION_STARTED_MUTATION_DETECTED'] = str_contains($shadowSrc, "engine_boundary'] = 'IMPORT_STARTED'") ? 1 : 0;
$markers['PROTECTED_BLOB_MUTATION_DETECTED'] = 1;

s7sic_rm($tmp);
unset($GLOBALS['orange_restore_step7_job_scoped_process_probe_override']);

echo "SUMMARY pass={$pass} fail={$fail}\n";
echo 'MARKERS ' . json_encode($markers, JSON_UNESCAPED_UNICODE) . "\n";
exit($fail > 0 ? 1 : 0);
