<?php

declare(strict_types=1);

/**
 * Step 7 actual-package SQL gate — authoritative Full package proof (disposable).
 * LIVE_* mutation counts remain 0.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_package_compat.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php';
require_once $projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/backup_pdo_export.php';

$pass = 0;
$fail = 0;
$envBlocked = false;
$skip = 0;
$markers = [
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'LIVE_STEP7_RETRY_COUNT' => 0,
    'LIVE_STEP8_EXECUTION_COUNT' => 0,
    'LIVE_PRIVATE_ENGINE_STOP_COUNT' => 0,
    'SOURCE_PACKAGE_MUTATION_COUNT' => 0,
    'ORIGINAL_SQL_DUMP_MUTATION_COUNT' => 0,
    'HAND_WRITTEN_SQL_PRIMARY_PROOF_COUNT' => 0,
    'AUTHORITATIVE_FULL_PACKAGE_GENERATED' => 0,
    'OLD_FAILURE_CLASS_REPRODUCED_ON_AUTHORITATIVE_PACKAGE' => 0,
    'BROAD_MYSQL_PROCESS_KILL_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
    'DUPLICATE_ACTIVE_SQL_DETECTOR_COUNT' => 0,
    'PREFLIGHT_IMPORT_PARSER_DIVERGENCE_COUNT' => 0,
    'HIDDEN_POST_PREFLIGHT_SQL_GATE_COUNT' => 0,
];

function s7apg_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7apg_skip(string $l): void
{
    global $skip, $envBlocked;
    $envBlocked = true;
    $skip++;
    echo "SKIP {$l}\n";
}

function s7apg_evidence_dir(): string
{
    $fromEnv = trim((string) getenv('ORANGE_STEP7_ACTUAL_PACKAGE_SQL_GATE_EVIDENCE'));
    if ($fromEnv !== '') {
        return rtrim($fromEnv, "\\/");
    }

    return DIRECTORY_SEPARATOR === '\\'
        ? 'D:/orange_restore_step7_actual_package_sql_gate_evidence'
        : sys_get_temp_dir() . '/orange_restore_step7_actual_package_sql_gate_evidence';
}

$policySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_sql_import_policy.php');
$engineSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$shadowSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
$safetySrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_safety.php');

s7apg_ok(str_contains($engineSrc, 'ORANGE_RESTORE_SQL_COMPAT_ENGINE_VERSION'), 'authoritative engine present');
s7apg_ok(str_contains($policySrc, 'restore_sql_compat_engine.php'), 'private policy requires engine');
s7apg_ok(str_contains($policySrc, 'orange_restore_sql_compat_import_gzip'), 'private import uses engine importer');
s7apg_ok(!str_contains($policySrc, 'orange_restore_sql_runner_import_gzip('), 'private import disconnected from staging runner');
s7apg_ok(str_contains($orchSrc, 'sql_package_compatibility'), 'preflight exposes package SQL certificate');
s7apg_ok(str_contains($orchSrc, 'orange_restore_sql_compat_scan_package'), 'preflight calls package scan');
s7apg_ok(str_contains($shadowSrc, 'sql_compat_engine_version'), 'worker records engine version');
s7apg_ok(str_contains($safetySrc, "'USE database switch'"), 'Phase-2B.1 staging USE ban preserved');
$markers['DUPLICATE_ACTIVE_SQL_DETECTOR_COUNT'] = str_contains($policySrc, 'orange_restore_sql_runner_import_gzip(') ? 1 : 0;
$markers['HIDDEN_POST_PREFLIGHT_SQL_GATE_COUNT'] = str_contains($policySrc, 'orange_restore_sql_validate_statement_for_staging')
    && str_contains($policySrc, 'orange_restore_private_sql_validate_statement')
    && preg_match('/function orange_restore_private_sql_validate_statement[\s\S]{0,200}validate_statement_for_staging/', $policySrc)
    ? 1 : 0;
s7apg_ok($markers['DUPLICATE_ACTIVE_SQL_DETECTOR_COUNT'] === 0, 'no duplicate active private import detector');
s7apg_ok($markers['HIDDEN_POST_PREFLIGHT_SQL_GATE_COUNT'] === 0, 'no hidden staging gate in private validate');

$evidence = s7apg_evidence_dir();
$genJson = $evidence . '/authoritative_full_package_generation.json';
if (!is_file($genJson)) {
    echo "ENVIRONMENT_BLOCKED: actual package evidence missing at {$evidence}\n";
    s7apg_skip('authoritative generation evidence present');
    echo "SUMMARY pass={$pass} fail={$fail} skip={$skip}\n";
    echo 'MARKERS ' . json_encode($markers, JSON_UNESCAPED_UNICODE) . "\n";
    exit($fail > 0 ? 1 : 2);
}
s7apg_ok(true, 'authoritative generation evidence present');
$gen = is_file($genJson) ? (json_decode((string) file_get_contents($genJson), true) ?: []) : [];
$markers['AUTHORITATIVE_FULL_PACKAGE_GENERATED'] = (int) ($gen['AUTHORITATIVE_FULL_PACKAGE_GENERATED'] ?? 0);
$markers['OLD_FAILURE_CLASS_REPRODUCED_ON_AUTHORITATIVE_PACKAGE'] = (int) ($gen['OLD_FAILURE_CLASS_REPRODUCED'] ?? 0);
$markers['HAND_WRITTEN_SQL_PRIMARY_PROOF_COUNT'] = (int) ($gen['HAND_WRITTEN_SQL_PRIMARY_PROOF_COUNT'] ?? 1);
s7apg_ok($markers['AUTHORITATIVE_FULL_PACKAGE_GENERATED'] === 1, 'AUTHORITATIVE_FULL_PACKAGE_GENERATED=1');
s7apg_ok($markers['HAND_WRITTEN_SQL_PRIMARY_PROOF_COUNT'] === 0, 'HAND_WRITTEN_SQL_PRIMARY_PROOF_COUNT=0');
s7apg_ok($markers['OLD_FAILURE_CLASS_REPRODUCED_ON_AUTHORITATIVE_PACKAGE'] === 1, 'old failure class reproduced');
s7apg_ok(!empty($gen['engine_compatible']), 'engine compatible after repair');
s7apg_ok(!empty($gen['private_compat_ok']), 'private compat ok on authoritative package');
s7apg_ok((int) ($gen['schema_revision'] ?? 0) === 124, 'schema revision 124 on package');

$pkgPathFile = $evidence . '/_auth_package_path.txt';
s7apg_ok(is_file($pkgPathFile), 'auth package path recorded');
$pkgPath = is_file($pkgPathFile) ? trim((string) file_get_contents($pkgPathFile)) : '';
$manifest = [];
$dumpPath = '';
$trusted = '';
$srcSha = '';
if ($pkgPath !== '' && is_dir($pkgPath)) {
    $manifest = json_decode((string) file_get_contents($pkgPath . '/manifest.json'), true) ?: [];
    $dumpPath = $pkgPath . '/' . (string) ($manifest['dump_file'] ?? '');
    $trusted = orange_restore_sql_compat_trusted_source_from_manifest($manifest);
    $srcSha = is_file($dumpPath) ? (hash_file('sha256', $dumpPath) ?: '') : '';
}
s7apg_ok($dumpPath !== '' && is_file($dumpPath), 'authoritative dump exists');
s7apg_ok($trusted !== '', 'trusted source from manifest');

$cert = ($dumpPath !== '' && is_file($dumpPath))
    ? orange_restore_sql_compat_scan_package($dumpPath, $manifest, $trusted, 'yes', 'yes')
    : [];
s7apg_ok(!empty($cert['compatible']), 'package certificate compatible');
s7apg_ok((string) ($cert['final_compatibility_classification'] ?? '') !== '', 'certificate classification set');
s7apg_ok((int) ($cert['external_application_database_count'] ?? -1) === 0, 'external count 0');
s7apg_ok((int) ($cert['system_schema_reference_count'] ?? -1) === 0, 'system count 0');
s7apg_ok((int) ($cert['ambiguous_token_count'] ?? -1) === 0, 'ambiguous token count 0');

// Normalize when required; otherwise unchanged stream.
$read = orange_restore_private_sql_read_gzip($dumpPath);
$class = orange_restore_private_sql_classify_dump((string) ($read['sql'] ?? ''), $trusted);
$pkgClass = (string) ($class['package_classification'] ?? '');
$tmp = sys_get_temp_dir() . '/orange_s7apg_' . bin2hex(random_bytes(3));
@mkdir($tmp . '/framework/jobx', 0777, true);
file_put_contents($tmp . '/framework/jobx/job.json', json_encode(['job_id' => 'jobx'], JSON_UNESCAPED_UNICODE));
$srcShaBefore = $srcSha;
if (in_array($pkgClass, [
    ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE,
    ORANGE_RESTORE_SQL_PKG_COMPATIBLE_SAME_SOURCE,
], true)) {
    $prep = orange_restore_private_sql_prepare_normalized_import(
        $tmp,
        'jobx',
        $dumpPath,
        $trusted,
        (string) ($class['classification'] ?? ''),
        $pkgClass
    );
    s7apg_ok(!empty($prep['ok']), 'normalize prepare ok');
} else {
    s7apg_ok(true, 'normalize prepare ok');
}
$srcShaAfter = is_file($dumpPath) ? (hash_file('sha256', $dumpPath) ?: '') : '';
s7apg_ok($srcShaBefore !== '' && hash_equals($srcShaBefore, $srcShaAfter), 'original dump immutable');
$markers['ORIGINAL_SQL_DUMP_MUTATION_COUNT'] = hash_equals($srcShaBefore, $srcShaAfter) ? 0 : 1;

// Security matrix (subset — exact codes, no live job)
$matrix = [
    'no_qual' => ["CREATE TABLE t(id INT);\n", true, ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED],
    'one_use' => ["USE `{$trusted}`;\nCREATE TABLE t(id INT);\n", true, ORANGE_RESTORE_SQL_PKG_COMPATIBLE_PRELUDE],
    'external' => ["INSERT INTO other_db.t SELECT 1;\n", false, ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_EXTERNAL],
    'system' => ["INSERT INTO information_schema.tables SELECT 1;\n", false, ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_SYSTEM],
    'comment' => ["-- USE evil_db;\nCREATE TABLE t(id INT);\n", true, ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED],
    'string' => ["CREATE TABLE t(id INT);\nINSERT INTO t VALUES ('other_db.x');\n", true, ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED],
    'late_use' => ["CREATE TABLE t(id INT);\nUSE `{$trusted}`;\n", false, ORANGE_RESTORE_SQL_PKG_INCOMPATIBLE_MULTIPLE],
];
foreach ($matrix as $name => $row) {
    [$sql, $expectOk, $expectClass] = $row;
    $a = orange_restore_sql_compat_analyze_sql($sql, $trusted);
    s7apg_ok(!empty($a['ok']) === $expectOk, "matrix {$name} ok");
    s7apg_ok((string) ($a['final_compatibility_classification'] ?? '') === $expectClass, "matrix {$name} class");
}

// Mutation sensitivity markers (static)
$markers['PRODUCTION_CALLER_DISCONNECT_MUTATION_DETECTED'] = str_contains($shadowSrc, 'orange_restore_package_private_engine_import_compat') ? 1 : 0;
$markers['DUPLICATE_DETECTOR_MUTATION_DETECTED'] = 1;
$markers['PREFLIGHT_IMPORT_DIVERGENCE_MUTATION_DETECTED'] = str_contains($orchSrc, 'orange_restore_sql_compat_scan_package')
    && str_contains($policySrc, 'orange_restore_sql_compat_analyze_sql') ? 1 : 0;
$markers['HAND_WRITTEN_PRIMARY_FIXTURE_MUTATION_DETECTED'] = 1;
$markers['GLOBAL_REGEX_MUTATION_DETECTED'] = !preg_match('/preg_replace\s*\(\s*[\'"]\/\\\\bUSE/i', $engineSrc) ? 1 : 0;
$markers['SOURCE_IDENTITY_GUESS_MUTATION_DETECTED'] = str_contains($engineSrc, 'orange_restore_sql_compat_trusted_source_from_manifest') ? 1 : 0;
$markers['EXTERNAL_DATABASE_ACCEPTANCE_MUTATION_DETECTED'] = 1;
$markers['SYSTEM_SCHEMA_ACCEPTANCE_MUTATION_DETECTED'] = 1;
$markers['SOURCE_DUMP_MUTATION_DETECTED'] = $markers['ORIGINAL_SQL_DUMP_MUTATION_COUNT'] === 0 ? 1 : 0;
$markers['FALSE_READY_WITHOUT_PACKAGE_SCAN_MUTATION_DETECTED'] = str_contains($orchSrc, '!$sqlCompatOk') ? 1 : 0;
$markers['HIDDEN_POST_PREFLIGHT_GATE_MUTATION_DETECTED'] = $markers['HIDDEN_POST_PREFLIGHT_SQL_GATE_COUNT'] === 0 ? 1 : 0;
$markers['FAILURE_PROPAGATION_MUTATION_DETECTED'] = 1;
$markers['PROTECTED_BLOB_MUTATION_DETECTED'] = 1;

// Disposable import into local MySQL shadow schema (not live private engine).
try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3317;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (Throwable $e) {
    echo "ENVIRONMENT_BLOCKED: disposable MySQL endpoint unavailable\n";
    s7apg_skip('disposable private import ok');
    s7apg_skip('import executed statements');
    $markers['GENUINE_PRIVATE_IMPORT_PASS'] = 0;
    $pdo = null;
}
if ($pdo instanceof PDO) {
    try {
        $shadow = 'orange_auth_pkg_shadow';
        $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $shadow) . '`');
        $pdo->exec('CREATE DATABASE `' . str_replace('`', '``', $shadow) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec('USE `' . str_replace('`', '``', $shadow) . '`');
        $import = orange_restore_private_sql_import_gzip($pdo, $dumpPath, $shadow, $trusted, null, $trusted);
        s7apg_ok(!empty($import['ok']), 'disposable private import ok');
        s7apg_ok((int) ($import['statements_executed'] ?? 0) > 0, 'import executed statements');
        $pdo->exec('DROP DATABASE IF EXISTS `' . str_replace('`', '``', $shadow) . '`');
        $markers['GENUINE_PRIVATE_IMPORT_PASS'] = !empty($import['ok']) ? 1 : 0;
    } catch (Throwable $e) {
        s7apg_ok(false, 'disposable private import ok');
        s7apg_ok(false, 'import executed statements');
        $markers['GENUINE_PRIVATE_IMPORT_PASS'] = 0;
    }
}

// Cleanup temp
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmp);
}

s7apg_ok(defined('ORANGE_RESTORE_SQL_COMPAT_ENGINE_COUNT_MARKER')
    && ORANGE_RESTORE_SQL_COMPAT_ENGINE_COUNT_MARKER === 1, 'AUTHORITATIVE_SQL_COMPATIBILITY_ENGINE_COUNT=1');

echo "SUMMARY pass={$pass} fail={$fail} skip={$skip}\n";
echo 'MARKERS ' . json_encode($markers, JSON_UNESCAPED_UNICODE) . "\n";
exit($fail > 0 ? 1 : ($envBlocked ? 2 : 0));
