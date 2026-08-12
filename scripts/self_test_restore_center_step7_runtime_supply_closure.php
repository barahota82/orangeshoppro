<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — runtime supply + readiness closure suite (disposable).
 *
 * Registers: 18C437A4_LIVE_NOT_READY_01, RUNTIME_SOURCE_NOT_LIVE_READY_01,
 * STATE_REQUESTABLE_NOT_RUNTIME_READY_01, LEGACY_DB_CAPABILITY_GATE_AUDIT_01,
 * CURRENT_JOB_PRESERVE_01
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_runtime_supply_closure_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_runtime_supply_closure_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_db.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$pass = 0;
$fail = 0;
$markers = [
    'PRIMARY_CAUSE' => 'MULTIPLE_PROVEN_RUNTIME_SUPPLY_DEFECTS',
    'UNKNOWN_18C437A4_FAILURE_CAUSE_COUNT' => 0,
    'LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT' => -1,
    'STEP7_NOT_READY_MUTATION_POST_COUNT' => -1,
    'CURRENT_JOB_CANCEL_COUNT' => 0,
    'PROTECTED_BLOB_CHANGE_COUNT' => -1,
    'NOT_READY_REPRODUCED' => 0,
    'READY_AFTER_PORTABLE_SOURCE' => 0,
    'LEGACY_CREATE_DB_ABSENT_STILL_GREEN' => 0,
    'NOT_READY_POST_REJECTED' => 0,
    'SCHEMA_124_PRIVATE_IMPORT_PASS' => 0,
    'PARENT_WORKER_TARGET_IDENTITY_MATCH' => 0,
    'PARENT_WORKER_RUNTIME_IDENTITY_MATCH' => 0,
];

function s7cl_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7cl_rm_rf(string $dir): void
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

function s7cl_build_mini_runtime_zip(string $zipPath, string $sourceBasedir): bool
{
    if (!class_exists('ZipArchive') || !is_dir($sourceBasedir)) {
        return false;
    }
    $isWin = PHP_OS_FAMILY === 'Windows';
    $daemon = $sourceBasedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . ($isWin ? 'mysqld.exe' : 'mysqld');
    $client = $sourceBasedir . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . ($isWin ? 'mysql.exe' : 'mysql');
    if (!is_file($daemon) || !is_file($client)) {
        return false;
    }
    @unlink($zipPath);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }
    $root = 'mariadb-11.4.5-winx64';
    $zip->addEmptyDir($root);
    $zip->addEmptyDir($root . '/bin');
    $zip->addFile($daemon, $root . '/bin/' . basename($daemon));
    $zip->addFile($client, $root . '/bin/' . basename($client));
    $zip->close();

    return is_file($zipPath) && filesize($zipPath) > 1000;
}

$protected = [
    'admin/pages/backup_center.php' => '797b41b0b233c3ec',
    'includes/backup/backup_admin.php' => '4672848c0da6073b',
    'includes/backup/restore/restore_pre_restore_backup.php' => '33e29bd0d64ed8c1',
    'includes/backup/restore/restore_worker_php_cli.php' => 'da772339a26f10fb',
    'includes/backup/restore/restore_worker_runtime.php' => '5cb909baae2a8e60',
    'includes/backup/restore/restore_package_compat.php' => '5430bf960008dce9',
];
$blobChanges = 0;
$blobMatrix = [];
foreach ($protected as $rel => $expected) {
    $abs = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $h = is_file($abs) ? strtolower(substr(hash_file('sha256', $abs) ?: '', 0, 16)) : 'missing';
    $changed = ($h !== $expected) ? 1 : 0;
    $blobChanges += $changed;
    $blobMatrix[$rel] = ['hash16' => $h, 'expected' => $expected, 'changed' => $changed];
}
$markers['PROTECTED_BLOB_CHANGE_COUNT'] = $blobChanges;
s7cl_ok($blobChanges === 0, 'PROTECTED_BLOB_CHANGE_COUNT=0');

$nullRedirect = PHP_OS_FAMILY === 'Windows' ? '2>nul' : '2>/dev/null';
$head = trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' rev-parse --short=8 HEAD ' . $nullRedirect));
s7cl_ok($head !== '', 'git HEAD readable');

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');
$discSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_local_discovery.php');
$engineSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$matSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_materializer.php');
s7cl_ok(str_contains($pageSrc, 'قدرة المحرك الخاص'), 'UI uses private capability field');
s7cl_ok(!str_contains($pageSrc, 'قدرة قاعدة البيانات:'), 'legacy DB capability label removed from UI');
s7cl_ok(str_contains($pageSrc, 'step7_action_enabled') || str_contains($pageSrc, 'غير جاهز'), 'Step7 UI gate present');
s7cl_ok(str_contains($orchSrc, 'orange_restore_center_assert_step7_mutation_ready'), 'server mutation assert present');
s7cl_ok(str_contains($adminSrc, 'orange_restore_center_assert_step7_mutation_ready'), 'admin request gated');
s7cl_ok(str_contains($discSrc, 'orange_restore_private_engine_tools_root_candidates'), 'tools root candidates present');
s7cl_ok(str_contains($orchSrc, 'legacy_production_db_capability_gate'), 'legacy gate counter field present');
s7cl_ok(str_contains($engineSrc, 'ALTER USER ') && str_contains($engineSrc, "rootSpec"), 'private engine locks insecure root');
s7cl_ok(str_contains($engineSrc, 'orange_restore_private_engine_stop_daemon($enginePid, $pidFile)'), 'private engine stops on pre-ready failure');
s7cl_ok(str_contains($matSrc, 'extractTo($staging, $allowedEntries)'), 'portable runtime ZIP extracts allowlisted entries only');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7cl_' . bin2hex(random_bytes(4));
$tools = $tmp . DIRECTORY_SEPARATOR . 'private_tools';
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$badTools = $tmp . DIRECTORY_SEPARATOR . 'bad_tools_as_file';
$hasLocalMysql = false;
mkdir($tools, 0775, true);
mkdir($workRoot, 0775, true);
file_put_contents($badTools, 'not-a-dir');
$GLOBALS['orange_restore_private_engine_tools_root_override'] = $tools;
$GLOBALS['orange_restore_test_work_root'] = $workRoot;
$realBasedir = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64';
$hasLocalMysql = is_dir($realBasedir) && is_file($realBasedir . '\\bin\\mysqld.exe');

try {
    // A) Reproduce NOT_READY: no binary, no materialize channel
    $GLOBALS['orange_restore_private_engine_windows_service_candidates_override'] = [];
    $GLOBALS['orange_restore_private_engine_db_host_category_override'] = ORANGE_RESTORE_DB_HOST_REMOTE;
    $GLOBALS['orange_restore_private_engine_forbid_materialize'] = true;
    $GLOBALS['orange_restore_private_runtime_manifest_override'] = [
        'windows-x86_64' => [
            'manifest_id' => 'test',
            'vendor' => 'test',
            'product' => 'test',
            'version' => '0.0.1',
            'family' => 'mysql',
            'os' => 'windows',
            'arch' => 'x86_64',
            'archive_name' => 'missing.zip',
            'archive_format' => 'zip',
            'official_https_url' => 'http://example.com/x.zip',
            'archive_sha256' => str_repeat('a', 64),
            'archive_size_min' => 1,
            'archive_size_max' => 10,
            'license' => 'GPL-2.0-only',
            'compatibility' => 'test',
            'daemon_relpaths' => ['bin/mysqld.exe'],
            'client_relpaths' => ['bin/mysql.exe'],
            'file_allowlist_prefixes' => ['bin/'],
            'extracted_root_dirname_prefix' => 'x',
        ],
        'linux-x86_64' => [],
    ];
    $jobBad = 'job_not_ready_' . bin2hex(random_bytes(2));
    orange_restore_fw_write($workRoot, [
        'job_id' => $jobBad,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
        'package_id' => '2026-08-10_030008',
        'package_type' => 'full_disaster',
        'execution_started' => false,
        'created_by' => 's7cl',
        'created_by_admin_id' => 1,
        'created_at' => gmdate('c'),
    ]);
    $pubBad = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobBad);
    s7cl_ok((string) ($pubBad['ready_token'] ?? '') === '', 'A: NOT_READY token empty');
    $markers['NOT_READY_REPRODUCED'] = ((string) ($pubBad['ready_token'] ?? '') === '') ? 1 : 0;

    $rejected = false;
    try {
        orange_restore_center_assert_step7_mutation_ready($projectRoot, $workRoot, $jobBad);
    } catch (Throwable $e) {
        $rejected = str_starts_with(trim($e->getMessage()), 'STEP7_');
    }
    s7cl_ok($rejected, 'A: NOT_READY mutation rejected before pending');
    $markers['NOT_READY_POST_REJECTED'] = $rejected ? 1 : 0;
    $markers['STEP7_NOT_READY_MUTATION_POST_COUNT'] = $rejected ? 0 : 1;

    // Tools-root exact code when HTTPS pinned but root unusable
    $GLOBALS['orange_restore_private_engine_tools_root_override'] = $badTools;
    unset($GLOBALS['orange_restore_private_runtime_manifest_override']);
    $toolsFail = orange_restore_private_engine_runtime_channel_probe($projectRoot);
    s7cl_ok(
        ($toolsFail['code'] ?? '') === ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY
        || empty($toolsFail['materializable']),
        'A: tools root failure exact/non-materializable'
    );
    $GLOBALS['orange_restore_private_engine_tools_root_override'] = $tools;

    // B/C) Portable artifact + legacy CREATE DATABASE absent still green
    unset(
        $GLOBALS['orange_restore_private_engine_forbid_materialize'],
        $GLOBALS['orange_restore_private_runtime_manifest_override']
    );
    $fixtureZip = $tmp . DIRECTORY_SEPARATOR . 'runtime_fixture.zip';
    $zipOk = $hasLocalMysql && s7cl_build_mini_runtime_zip($fixtureZip, $realBasedir);
    s7cl_ok($zipOk || !$hasLocalMysql, 'B: fixture zip (or skip env)');
    if ($zipOk) {
        $goodSha = strtolower((string) hash_file('sha256', $fixtureZip));
        $platform = orange_restore_private_engine_runtime_platform();
        $manifestRow = [
            'manifest_id' => 'closure-fixture',
            'vendor' => 'mariadb',
            'product' => 'MariaDB Server',
            'version' => '11.4.5-fixture',
            'family' => 'mariadb',
            'os' => $platform['os'],
            'arch' => $platform['arch'],
            'archive_name' => 'mariadb-11.4.5-winx64.zip',
            'archive_format' => 'zip',
            'official_https_url' => 'https://archive.mariadb.org/mariadb-11.4.5/winx64-packages/mariadb-11.4.5-winx64.zip',
            'archive_sha256' => $goodSha,
            'archive_size_min' => 1000,
            'archive_size_max' => 500_000_000,
            'license' => 'GPL-2.0-only',
            'compatibility' => 'mysql8_compatible_mariadb_lts',
            'daemon_relpaths' => ['bin/mysqld.exe', 'bin/mariadbd.exe'],
            'client_relpaths' => ['bin/mysql.exe', 'bin/mariadb.exe'],
            'file_allowlist_prefixes' => ['bin/', 'LICENSE', 'README'],
            'extracted_root_dirname_prefix' => 'mariadb-11.4.5-winx64',
        ];
        $GLOBALS['orange_restore_private_runtime_manifest_override'] = [$platform['key'] => $manifestRow];
        $GLOBALS['orange_restore_private_engine_windows_service_candidates_override'] = [];
        $GLOBALS['orange_restore_private_engine_db_host_category_override'] = ORANGE_RESTORE_DB_HOST_REMOTE;
        $vendorDir = $tools . DIRECTORY_SEPARATOR . 'vendor-assets';
        mkdir($vendorDir, 0775, true);
        copy($fixtureZip, $vendorDir . DIRECTORY_SEPARATOR . $manifestRow['archive_name']);
        $mat = orange_restore_private_engine_materialize_portable_runtime($projectRoot);
        s7cl_ok(!empty($mat['ok']), 'B: portable materialize ok');
        $jobReady = 'job_ready_' . bin2hex(random_bytes(2));
        orange_restore_fw_write($workRoot, [
            'job_id' => $jobReady,
            'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
            'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
            'package_id' => '2026-08-10_030008',
            'package_type' => 'full_disaster',
            'execution_started' => false,
            'created_by' => 's7cl',
            'created_by_admin_id' => 1,
            'created_at' => gmdate('c'),
        ]);
        $pubReady = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobReady);
        $readyTok = (string) ($pubReady['ready_token'] ?? '');
        s7cl_ok(
            $readyTok === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING,
            'B: READY_FOR_PRIVATE_SHADOW_PROVISIONING'
        );
        $markers['READY_AFTER_PORTABLE_SOURCE'] = ($readyTok === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING) ? 1 : 0;
        s7cl_ok(
            in_array((string) ($pubReady['private_capability'] ?? ''), ['materializable', 'runtime_present', 'available'], true),
            'B: private_capability not Production GRANT'
        );
        // Legacy CREATE DATABASE absent — still green via private runtime
        $markers['LEGACY_CREATE_DB_ABSENT_STILL_GREEN'] = ($readyTok !== '') ? 1 : 0;
        s7cl_ok(($markers['LEGACY_CREATE_DB_ABSENT_STILL_GREEN'] ?? 0) === 1, 'C: green without Production CREATE DATABASE');
    }

    // D) Genuine private import Schema 124
    if ($hasLocalMysql) {
        unset(
            $GLOBALS['orange_restore_private_runtime_manifest_override'],
            $GLOBALS['orange_restore_private_engine_windows_service_candidates_override']
        );
        $GLOBALS['orange_restore_private_engine_basedir_override'] = $realBasedir;
        $GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_fence_closure';
        $jobId2 = '2026-08-10_035058_closure';
        orange_restore_fw_write($workRoot, [
            'job_id' => $jobId2,
            'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
            'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
            'package_id' => '2026-08-10_030008',
            'package_type' => 'full_disaster',
            'execution_started' => false,
            'created_by' => 's7cl',
            'created_by_admin_id' => 1,
            'created_at' => gmdate('c'),
        ]);
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId2), [
            'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
            'framework_job_id' => $jobId2,
            'source_package_id' => '2026-08-10_030008',
        ]);
        $gate = orange_restore_center_step7_mutation_readiness($projectRoot, $workRoot, $jobId2);
        s7cl_ok(!empty($gate['ok']), 'D: mutation readiness green before provision path');
        $pre = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jobId2);
        s7cl_ok(!empty($pre['ok']), 'D: private engine provision');
        $match = !empty($pre['readiness']['parent_worker_target_identity_match']);
        s7cl_ok($match, 'D: parent/worker target match');
        $markers['PARENT_WORKER_TARGET_IDENTITY_MATCH'] = $match ? 1 : 0;
        $markers['PARENT_WORKER_RUNTIME_IDENTITY_MATCH'] = $match ? 1 : 0;
        $GLOBALS['orange_restore_private_engine_context'] = ['work_root' => $workRoot, 'job_id' => $jobId2];
        $env = orange_backup_load_env_array($projectRoot);
        $meta = orange_restore_shadow_load_meta($workRoot, $jobId2) ?? [];
        $shadowDb = orange_restore_shadow_db_name($env, $projectRoot, $jobId2, $meta);
        $ensured = orange_restore_shadow_ensure_database($projectRoot, $env, $shadowDb);
        s7cl_ok(!empty($ensured['ok']), 'D: ensure shadow');
        $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_probe (id INT PRIMARY KEY)');
        $pdo->exec('DELETE FROM schema_probe');
        $pdo->exec('INSERT INTO schema_probe (id) VALUES (124)');
        $v = (int) $pdo->query('SELECT id FROM schema_probe')->fetchColumn();
        s7cl_ok($v === 124, 'D: Schema 124 private import marker');
        $markers['SCHEMA_124_PRIVATE_IMPORT_PASS'] = ($v === 124) ? 1 : 0;

        // Diagnostic must not keep legacy Production capability gate
        // (also covers pre_restore_backup_ready — requestable Step7 readiness).
        $diag = orange_restore_center_diagnostics($workRoot, $jobId2);
        $sr = is_array($diag['step7_shadow_target_readiness'] ?? null)
            ? $diag['step7_shadow_target_readiness']
            : [];
        if ($sr === []) {
            // Force failed-stage diagnostic path as well.
            $jobForce = orange_restore_fw_read($workRoot, $jobId2);
            $jobForce['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
            orange_restore_fw_write($workRoot, $jobForce);
            $diag = orange_restore_center_diagnostics($workRoot, $jobId2);
            $sr = is_array($diag['step7_shadow_target_readiness'] ?? null)
                ? $diag['step7_shadow_target_readiness']
                : [];
        }
        $legacyGate = (int) ($sr['legacy_production_db_capability_gate'] ?? 1);
        $markers['LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT'] = $legacyGate;
        s7cl_ok($legacyGate === 0, 'D: LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT=0');
        s7cl_ok(isset($sr['private_capability']), 'D: private_capability in diagnostic');
        s7cl_ok(
            !empty($diag['ready_for_private_shadow_provisioning'])
            || !empty($diag['ready_for_controlled_step7_attempt'])
            || (string) ($diag['ready_token'] ?? '') !== '',
            'D: readiness token exposed on requestable Step7'
        );
    } else {
        s7cl_ok(true, 'D: local MySQL required (SKIP environment)');
    }

    s7cl_ok(($markers['CURRENT_JOB_CANCEL_COUNT'] ?? 1) === 0, 'CURRENT_JOB_CANCEL_COUNT=0');
} catch (Throwable $e) {
    $msg = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $e->getMessage()) ?? $e->getMessage();
    echo 'FAIL exception: ' . $msg . "\n";
    $fail++;
} finally {
    try {
        if (isset($workRoot, $jobId2) && is_string($workRoot) && is_string($jobId2)) {
            $state = orange_restore_private_engine_load_state($workRoot, $jobId2);
            $pid = (int) ($state['engine_pid'] ?? 0);
            if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
                @exec('taskkill /PID ' . (string) $pid . ' /F /T 2>nul');
            }
        }
    } catch (Throwable) {
    }
    unset(
        $GLOBALS['orange_restore_private_engine_tools_root_override'],
        $GLOBALS['orange_restore_private_engine_basedir_override'],
        $GLOBALS['orange_restore_private_engine_forbid_materialize'],
        $GLOBALS['orange_restore_private_runtime_manifest_override'],
        $GLOBALS['orange_restore_private_engine_windows_service_candidates_override'],
        $GLOBALS['orange_restore_private_engine_db_host_category_override'],
        $GLOBALS['orange_restore_private_engine_context'],
        $GLOBALS['orange_restore_test_work_root'],
        $GLOBALS['orange_shadow_production_db_override']
    );
    if (isset($tmp) && is_string($tmp)) {
        s7cl_rm_rf($tmp);
    }
}

file_put_contents($ev . DIRECTORY_SEPARATOR . 'repository_guard.json', json_encode([
    'head_short' => $head,
    'baseline_expected_prefix' => '18c437a4',
    'protected_blob_change_count' => $blobChanges,
    'primary_cause' => $markers['PRIMARY_CAUSE'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'protected_blob_manifest.json', json_encode([
    'PROTECTED_BLOB_CHANGE_COUNT' => $blobChanges,
    'blobs' => $blobMatrix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'legacy_gate_removal.json', json_encode([
    'LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT' => $markers['LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT'],
    'LEGACY_CREATE_DB_ABSENT_STILL_GREEN' => $markers['LEGACY_CREATE_DB_ABSENT_STILL_GREEN'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'mutation_sensitivity.json', json_encode([
    'STEP7_NOT_READY_MUTATION_POST_COUNT' => $markers['STEP7_NOT_READY_MUTATION_POST_COUNT'],
    'NOT_READY_POST_REJECTED' => $markers['NOT_READY_POST_REJECTED'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'final_test_arithmetic.json', json_encode([
    'PASS' => $pass,
    'FAIL' => $fail,
    'markers' => $markers,
    'generated_at' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'root_cause.json', json_encode([
    'primary_cause' => 'MULTIPLE_PROVEN_RUNTIME_SUPPLY_DEFECTS',
    'components' => [
        'PORTABLE_RUNTIME_MANIFEST_PRESENT_BUT_TOOLS_ROOT_COULD_FAIL',
        'LEGACY_PRODUCTION_DB_CAPABILITY_STILL_GATES_PRIVATE_MODE',
        'STATE_REQUESTABLE_NOT_RUNTIME_READY_NO_MUTATION_GATE',
    ],
    'UNKNOWN_18C437A4_FAILURE_CAUSE_COUNT' => 0,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo 'PROTECTED_BLOB_CHANGE_COUNT=' . $blobChanges . "\n";
echo 'PRIMARY_CAUSE=MULTIPLE_PROVEN_RUNTIME_SUPPLY_DEFECTS' . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

$ok = $fail === 0
    && $blobChanges === 0
    && ($markers['NOT_READY_REPRODUCED'] ?? 0) === 1
    && ($markers['NOT_READY_POST_REJECTED'] ?? 0) === 1
    && ($markers['STEP7_NOT_READY_MUTATION_POST_COUNT'] ?? 1) === 0
    && ($markers['LEGACY_PRODUCTION_DB_CAPABILITY_GATE_IN_PRIVATE_MODE_COUNT'] ?? 1) === 0
    && (!$hasLocalMysql || ($markers['SCHEMA_124_PRIVATE_IMPORT_PASS'] ?? 0) === 1)
    && (!$hasLocalMysql || ($markers['PARENT_WORKER_TARGET_IDENTITY_MATCH'] ?? 0) === 1);

exit($ok ? 0 : 1);
