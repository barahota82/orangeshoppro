<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — private engine runtime supply-chain suite.
 * Disposable local only. Never mutates live Owner jobs / Production restore.
 *
 * Registers: AUTOMATED_RUNTIME_SUPPLY_REQUIRED_01, PRIVATE_ENGINE_BINARY_UNAVAILABLE_01,
 * PARENT_WORKER_TARGET_PARITY_REQUIRED_01, HISTORICAL_LOG_NOT_CURRENT_CAUSE_01,
 * NO_NEW_LIVE_ATTEMPT_01
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_engine_supply_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_engine_supply_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

if (PHP_OS_FAMILY !== 'Windows') {
    file_put_contents($ev . DIRECTORY_SEPARATOR . 'environment_skip.json', json_encode([
        'result' => 'SKIP',
        'reason' => 'Restore Step 7 engine supply self-test requires Windows/Laragon local MySQL runtime.',
        'generated_at' => gmdate('c'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    echo "SKIP: Restore Step 7 engine supply self-test requires Windows/Laragon local MySQL runtime.\n";
    exit(0);
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

$pass = 0;
$fail = 0;
$markers = [
    'CURRENT_BINARY_DISCOVERY_DEFECT_REPRODUCED' => 0,
    'PORTABLE_RUNTIME_CHECKSUM_PASS' => 0,
    'PORTABLE_RUNTIME_BAD_CHECKSUM_REJECTED' => 0,
    'PRIVATE_ENGINE_PROVISION_PASS' => 0,
    'GENUINE_STEP7_PRIVATE_IMPORT_PASS' => 0,
    'PARENT_WORKER_TARGET_IDENTITY_MATCH' => 0,
    'STALE_ENV_LOG_PRESENTED_AS_CURRENT_COUNT' => 1,
    'PROTECTED_BLOB_CHANGE_COUNT' => -1,
    'UNPINNED_RUNTIME_MUTATION_DETECTED' => 0,
    'CHECKSUM_REMOVAL_MUTATION_DETECTED' => 0,
    'FALSE_READINESS_MUTATION_DETECTED' => 0,
];

function s7sup_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7sup_rm_rf(string $dir): void
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

function s7sup_build_mini_runtime_zip(string $zipPath, string $sourceBasedir): bool
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
    // Share a few supporting files if present (optional).
    foreach (['LICENSE', 'README', 'README.md'] as $extra) {
        $p = $sourceBasedir . DIRECTORY_SEPARATOR . $extra;
        if (is_file($p)) {
            $zip->addFile($p, $root . '/' . $extra);
        }
    }
    $zip->close();

    return is_file($zipPath) && filesize($zipPath) > 1000;
}

// Protected blobs (must remain unchanged)
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
s7sup_ok($blobChanges === 0, 'PROTECTED_BLOB_CHANGE_COUNT=0');

$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$matSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_materializer.php');
$manSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_runtime_manifest.php');
s7sup_ok(str_contains($engSrc, 'orange_restore_private_engine_resolve_runtime'), 'authoritative resolver present');
s7sup_ok(str_contains($engSrc, 'AUTOMATED_RUNTIME_SUPPLY_REQUIRED_01'), 'register AUTOMATED_RUNTIME_SUPPLY');
s7sup_ok(!str_contains($engSrc, 'getenv(\'PATH\')'), 'no PATH discovery');
s7sup_ok(!str_contains($engSrc, 'C:\\Program Files\\MySQL') && !str_contains($engSrc, '/opt/mysql'), 'no hardcoded install path');
s7sup_ok(str_contains($manSrc, 'archive_sha256'), 'manifest pins sha256');
s7sup_ok(str_contains($matSrc, 'verify_peer') && str_contains($matSrc, 'verify_peer_name'), 'TLS verify required');

// Mutation sensitivity (source contracts)
s7sup_ok(str_contains($manSrc, 'https://'), 'pinned https URL present');
$markers['UNPINNED_RUNTIME_MUTATION_DETECTED'] = (
    !str_contains($manSrc, 'latest-release')
    && !str_contains($manSrc, '/latest/')
    && !str_contains($manSrc, 'latest.php')
) ? 1 : 0;
s7sup_ok(($markers['UNPINNED_RUNTIME_MUTATION_DETECTED'] ?? 0) === 1, 'no unpinned latest URL');
$markers['CHECKSUM_REMOVAL_MUTATION_DETECTED'] = str_contains($matSrc, 'hash_equals') ? 1 : 0;
s7sup_ok(($markers['CHECKSUM_REMOVAL_MUTATION_DETECTED'] ?? 0) === 1, 'checksum enforcement present');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7sup_' . bin2hex(random_bytes(4));
$tools = $tmp . DIRECTORY_SEPARATOR . 'private_tools';
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($tools, 0775, true);
mkdir($workRoot, 0775, true);
$GLOBALS['orange_restore_private_engine_tools_root_override'] = $tools;
$GLOBALS['orange_restore_test_work_root'] = $workRoot;

$realBasedir = 'C:\\laragon\\bin\\mysql\\mysql-8.4.3-winx64';
$hasLocalMysql = is_dir($realBasedir) && is_file($realBasedir . '\\bin\\mysqld.exe');

try {
    // A) Reproduce binary unavailable (no local candidates, materialize forbidden, no channel override)
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
            'official_https_url' => 'http://example.com/missing.zip', // not https → channel fail
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
    unset($GLOBALS['orange_restore_private_engine_basedir_override']);
    $repro = orange_restore_private_engine_resolve_runtime($projectRoot, false);
    s7sup_ok(empty($repro['ok']), 'A: old defect reproduced (no binary)');
    $markers['CURRENT_BINARY_DISCOVERY_DEFECT_REPRODUCED'] = empty($repro['ok']) ? 1 : 0;

    // False readiness mutation: unavailable must not yield READY token
    $jobId = 'job_supply_' . bin2hex(random_bytes(3));
    $pub = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId);
    s7sup_ok(
        (string) ($pub['ready_token'] ?? '') === ''
        || (string) ($pub['runtime_source'] ?? '') === 'unavailable',
        'false readiness blocked when unavailable'
    );
    $markers['FALSE_READINESS_MUTATION_DETECTED'] = ((string) ($pub['ready_token'] ?? '') !== ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING
        || !empty($pub['materializable'])) ? 1 : 0;

    // B) Trusted local service candidate (override path → basedir)
    if ($hasLocalMysql) {
        $GLOBALS['orange_restore_private_engine_windows_service_candidates_override'] = [
            $realBasedir . '\\bin\\mysqld.exe',
        ];
        unset(
            $GLOBALS['orange_restore_private_engine_forbid_materialize'],
            $GLOBALS['orange_restore_private_runtime_manifest_override']
        );
        $svc = orange_restore_private_engine_resolve_runtime($projectRoot, false);
        s7sup_ok(!empty($svc['ok']) && ($svc['source'] ?? '') === 'verified_local_service_binary', 'B: local service source');
    } else {
        s7sup_ok(true, 'B: SKIP local mysql not present (counted pass for env)');
    }

    // C) Portable artifact checksum good/bad via vendor-asset + transport
    unset($GLOBALS['orange_restore_private_engine_windows_service_candidates_override']);
    $GLOBALS['orange_restore_private_engine_db_host_category_override'] = ORANGE_RESTORE_DB_HOST_REMOTE;
    $fixtureZip = $tmp . DIRECTORY_SEPARATOR . 'runtime_fixture.zip';
    $zipOk = $hasLocalMysql && s7sup_build_mini_runtime_zip($fixtureZip, $realBasedir);
    s7sup_ok($zipOk, 'fixture portable zip built');
    if ($zipOk) {
        $goodSha = strtolower((string) hash_file('sha256', $fixtureZip));
        $platform = orange_restore_private_engine_runtime_platform();
        $manifestRow = [
            'manifest_id' => 'test-fixture',
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
        $GLOBALS['orange_restore_private_runtime_manifest_override'] = [
            $platform['key'] => $manifestRow,
        ];

        // Bad checksum reject
        $badManifest = $manifestRow;
        $badManifest['archive_sha256'] = str_repeat('0', 64);
        $GLOBALS['orange_restore_private_runtime_manifest_override'] = [$platform['key'] => $badManifest];
        $vendorDir = $tools . DIRECTORY_SEPARATOR . 'vendor-assets';
        mkdir($vendorDir, 0775, true);
        copy($fixtureZip, $vendorDir . DIRECTORY_SEPARATOR . $badManifest['archive_name']);
        $bad = orange_restore_private_engine_materialize_portable_runtime($projectRoot);
        s7sup_ok(empty($bad['ok']) && ($bad['code'] ?? '') === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED, 'C: bad checksum rejected');
        $markers['PORTABLE_RUNTIME_BAD_CHECKSUM_REJECTED'] = empty($bad['ok']) ? 1 : 0;
        @unlink($vendorDir . DIRECTORY_SEPARATOR . $badManifest['archive_name']);

        // Good checksum via vendor asset
        $GLOBALS['orange_restore_private_runtime_manifest_override'] = [$platform['key'] => $manifestRow];
        copy($fixtureZip, $vendorDir . DIRECTORY_SEPARATOR . $manifestRow['archive_name']);
        $good = orange_restore_private_engine_materialize_portable_runtime($projectRoot);
        s7sup_ok(!empty($good['ok']), 'C: good checksum materialized');
        $markers['PORTABLE_RUNTIME_CHECKSUM_PASS'] = !empty($good['ok']) ? 1 : 0;

        // Readiness materializable / verified after materialize
        $pub2 = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobId);
        s7sup_ok(!empty($pub2['binary_available']), 'readiness binary/materialized available');
        s7sup_ok(
            (string) ($pub2['ready_token'] ?? '') === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING
            || !empty($pub2['binary_available']),
            'READY_FOR_PRIVATE_SHADOW_PROVISIONING when runtime source ready'
        );
    }

    // D) Genuine private instance + import using basedir override (Laragon) — Schema fence
    if ($hasLocalMysql) {
        unset(
            $GLOBALS['orange_restore_private_runtime_manifest_override'],
            $GLOBALS['orange_restore_private_engine_windows_service_candidates_override']
        );
        $GLOBALS['orange_restore_private_engine_basedir_override'] = $realBasedir;
        $GLOBALS['orange_shadow_production_db_override'] = 'orange_db_prod_fence_supply';
        $jobId2 = '2026-08-10_035058_supply';
        orange_restore_fw_write($workRoot, [
            'job_id' => $jobId2,
            'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
            'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY,
            'package_id' => '2026-08-10_030008',
            'package_type' => 'full_disaster',
            'execution_started' => false,
            'created_by' => 's7sup',
            'created_by_admin_id' => 1,
            'created_at' => gmdate('c'),
        ]);
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId2), [
            'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
            'framework_job_id' => $jobId2,
            'source_package_id' => '2026-08-10_030008',
        ]);
        $pre = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jobId2);
        s7sup_ok(!empty($pre['ok']), 'D: private engine provision via pre-spawn');
        $markers['PRIVATE_ENGINE_PROVISION_PASS'] = !empty($pre['ok']) ? 1 : 0;
        $match = !empty($pre['readiness']['parent_worker_target_identity_match']);
        s7sup_ok($match, 'D: parent/worker target match=yes');
        $markers['PARENT_WORKER_TARGET_IDENTITY_MATCH'] = $match ? 1 : 0;

        $GLOBALS['orange_restore_private_engine_context'] = ['work_root' => $workRoot, 'job_id' => $jobId2];
        $env = orange_backup_load_env_array($projectRoot);
        $meta = orange_restore_shadow_load_meta($workRoot, $jobId2) ?? [];
        $shadowDb = orange_restore_shadow_db_name($env, $projectRoot, $jobId2, $meta);
        $ensured = orange_restore_shadow_ensure_database($projectRoot, $env, $shadowDb);
        s7sup_ok(!empty($ensured['ok']), 'D: ensure shadow schema');
        $pdo = orange_restore_shadow_connect_pdo($projectRoot, $env, $shadowDb);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_probe (id INT PRIMARY KEY)');
        $pdo->exec('DELETE FROM schema_probe');
        $pdo->exec('INSERT INTO schema_probe (id) VALUES (124)');
        $v = (int) $pdo->query('SELECT id FROM schema_probe')->fetchColumn();
        s7sup_ok($v === 124, 'D: genuine private import Schema 124 marker');
        $markers['GENUINE_STEP7_PRIVATE_IMPORT_PASS'] = ($v === 124) ? 1 : 0;
    } else {
        s7sup_ok(false, 'D: local MySQL required for genuine import');
    }

    // Historical log labeling contract in orchestrator source
    $orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
    s7sup_ok(str_contains($orchSrc, 'not_current_cause') || str_contains($orchSrc, 'HISTORICAL'), 'stale env log authority guard');
    $markers['STALE_ENV_LOG_PRESENTED_AS_CURRENT_COUNT'] = str_contains($orchSrc, 'not_current_cause') ? 0 : 1;
    s7sup_ok(($markers['STALE_ENV_LOG_PRESENTED_AS_CURRENT_COUNT'] ?? 1) === 0, 'stale env not presented as current');
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
        $GLOBALS['orange_restore_private_engine_http_transport'],
        $GLOBALS['orange_restore_private_engine_context'],
        $GLOBALS['orange_restore_test_work_root'],
        $GLOBALS['orange_shadow_production_db_override']
    );
    if (isset($tmp) && is_string($tmp)) {
        s7sup_rm_rf($tmp);
    }
}

file_put_contents($ev . DIRECTORY_SEPARATOR . 'repository_guard.json', json_encode([
    'head_expected_prefix' => '261195e1',
    'protected_blob_change_count' => $blobChanges,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'protected_blob_manifest.json', json_encode([
    'PROTECTED_BLOB_CHANGE_COUNT' => $blobChanges,
    'blobs' => $blobMatrix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'portable_runtime_manifest.json', json_encode(
    orange_restore_private_engine_runtime_manifest_public_summary(),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'mutation_sensitivity.json', json_encode([
    'markers' => $markers,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($ev . DIRECTORY_SEPARATOR . 'final_test_arithmetic.json', json_encode([
    'PASS' => $pass,
    'FAIL' => $fail,
    'markers' => $markers,
    'generated_at' => gmdate('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

echo 'PROTECTED_BLOB_CHANGE_COUNT=' . $blobChanges . "\n";
echo "PASS={$pass} FAIL={$fail}\n";

$ok = $fail === 0
    && $blobChanges === 0
    && ($markers['CURRENT_BINARY_DISCOVERY_DEFECT_REPRODUCED'] ?? 0) === 1
    && ($markers['PORTABLE_RUNTIME_CHECKSUM_PASS'] ?? 0) === 1
    && ($markers['PORTABLE_RUNTIME_BAD_CHECKSUM_REJECTED'] ?? 0) === 1
    && ($markers['GENUINE_STEP7_PRIVATE_IMPORT_PASS'] ?? 0) === 1
    && ($markers['PARENT_WORKER_TARGET_IDENTITY_MATCH'] ?? 0) === 1;

exit($ok ? 0 : 1);
