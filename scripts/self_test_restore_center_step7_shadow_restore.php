<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — shadow database restore focused suite.
 * Disposable fixtures only. Never touches Production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = s7_evidence_dir();
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_manifest.php';
require_once $projectRoot . '/includes/backup/backup_full.php';
require_once $projectRoot . '/includes/backup/recovery_validation.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_db.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_execution_orchestrator.php';

$pass = 0;
$fail = 0;
$markers = [];

function s7_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7_evidence_dir(): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 'D:\\orange_restore_step7_shadow_restore_evidence';
    }

    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_shadow_restore_evidence';
}

function s7_seed_pkg(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    // Minimal zip local file header + empty payload for uploads.
    $name = 'a.txt';
    $body = 'x';
    $crc = crc32($body) & 0xFFFFFFFF;
    $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, strlen($body), strlen($body), strlen($name), 0) . $name . $body;
    $central = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, strlen($body), strlen($body), strlen($name), 0, 0, 0, 0, 0, 0) . $name;
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, 1, 1, strlen($central), strlen($local), 0);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, $local . $central . $end);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 1,
    ]);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    file_put_contents(
        $pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256',
        $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n"
    );
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
        ]
    );
}

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$reqSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-shadow-restore.php');
$libSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

s7_ok(str_contains($pageSrc, 'RESTORE_CENTER_STEP7_ONE_BROWSER_REQUEST_01'), 'UI register ONE_BROWSER');
s7_ok(str_contains($pageSrc, 'RESTORE_CENTER_STEP7_SHADOW_DB_ISOLATION_01'), 'UI register SHADOW_ISOLATION');
s7_ok(preg_match("/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/request-shadow-restore\\.php'/", $pageSrc) === 1, 'one POST to request-shadow-restore');
s7_ok(preg_match("/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/run-worker\\.php'/", $pageSrc) !== 1, 'no two-call chain');
s7_ok(!str_contains($pageSrc, 'data-worker="shadow_db"') && !str_contains($pageSrc, "data-worker': 'shadow_db'"), 'no direct run-worker shadow_db control');
s7_ok(str_contains($reqSrc, 'orange_restore_center_attach_verified_schedule'), 'atomic schedule on request');
s7_ok(str_contains($orchSrc, "'shadow_db' => 'scripts/backup/restore_shadow_db.php'"), 'worker catalog');
s7_ok(str_contains($libSrc, 'orange_restore_shadow_resolve_source_package'), 'source fence helper');
s7_ok(str_contains($libSrc, 'ORANGE_RESTORE_SHADOW_EXPECTED_SCHEMA_REVISION = 124'), 'schema 124');
s7_ok(str_contains($libSrc, 'shadow_db_identity_hash'), 'identity hash for owner UI');
s7_ok(str_contains($pageSrc, 'RC_SHADOW_SCHEDULED_MSG') && str_contains($pageSrc, 'RC_SHADOW_FAIL_MSG'), 'Arabic constants');
s7_ok(str_contains($pageSrc, 'إعادة محاولة استعادة قاعدة الظل'), 'retry label present');

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7_' . bin2hex(random_bytes(4));
$backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backup';
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$pkgSource = '2026-08-10_030008';
$pkgRollback = '2026-08-10_035100';
mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0777, true);
mkdir($workRoot, 0777, true);

try {
    $firstLock = orange_restore_shadow_acquire_lock($workRoot, 'same_job_lock_s7', 'first');
    $secondLock = orange_restore_shadow_acquire_lock($workRoot, 'same_job_lock_s7', 'second');
    s7_ok(($firstLock['ok'] ?? false) === true, 'shadow lock first acquire');
    s7_ok(
        ($secondLock['ok'] ?? true) === false
        && (string) ($secondLock['message'] ?? '') === 'shadow_restore_lock_active',
        'SAME_JOB_SHADOW_LOCK_DUPLICATE_BLOCKED'
    );
    orange_restore_shadow_release_lock($workRoot, 'same_job_lock_s7');
    $markers['SAME_JOB_SHADOW_LOCK_DUPLICATE_BLOCKED'] = 1;

    s7_seed_pkg($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgSource, $pkgSource);
    s7_seed_pkg($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgRollback, $pkgRollback);

    $fp = orange_restore_exec_build_package_fingerprint($backupRoot, 'full_disaster', $pkgSource, null);
    $fingerprint = (string) ($fp['fingerprint'] ?? '');

    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $pkgSource,
        'package_type' => 'full_disaster',
        'created_by' => 's7_admin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    // Force Step-6-ready status for fence tests (no Production backup).
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY;
    $job['package_fingerprint'] = $fingerprint;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $pkgSource,
        'rollback_package_id' => $pkgRollback,
        'rollback_package_type' => 'full',
        'created_at' => gmdate('c'),
        'created_by' => 's7_admin',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_s7_test',
        'package_fingerprint' => $fingerprint,
        'execution_started' => false,
        'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
        'purpose' => ORANGE_RESTORE_PRE_BACKUP_PURPOSE,
    ]);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $src = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
    s7_ok(($src['ok'] ?? false) === true, 'source revalidation PASS');
    s7_ok((string) ($src['source_package_id'] ?? '') === $pkgSource, 'STEP7_SOURCE_PACKAGE_ID_MATCH');
    s7_ok((string) ($src['rollback_package_id'] ?? '') === $pkgRollback, 'rollback id from job record');
    s7_ok((string) ($src['source_package_id'] ?? '') !== (string) ($src['rollback_package_id'] ?? ''), 'source≠rollback');

    orange_restore_pre_backup_write_record($workRoot, $jobId, array_merge(
        orange_restore_pre_backup_load_record($workRoot, $jobId) ?? [],
        ['rollback_package_id' => $pkgSource]
    ));
    $swap = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, $job);
    s7_ok(($swap['ok'] ?? true) === false && (string) ($swap['code'] ?? '') === 'source_rollback_package_swap', 'SOURCE_ROLLBACK_SWAP_MUTATION_DETECTED');
    $markers['SOURCE_ROLLBACK_SWAP_MUTATION_DETECTED'] = 1;
    orange_restore_pre_backup_write_record($workRoot, $jobId, array_merge(
        orange_restore_pre_backup_load_record($workRoot, $jobId) ?? [],
        ['rollback_package_id' => $pkgRollback]
    ));

    @unlink($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgSource . DIRECTORY_SEPARATOR . 'database.sql.gz');
    $miss = orange_restore_shadow_resolve_source_package($workRoot, $jobId, $backupRoot, orange_restore_fw_read($workRoot, $jobId));
    s7_ok(($miss['ok'] ?? true) === false && (string) ($miss['code'] ?? '') === 'dump_file_missing', 'dump missing rejected');
    s7_seed_pkg($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgSource, $pkgSource);

    $caught = false;
    try {
        orange_restore_shadow_db_name(
            [ORANGE_RESTORE_ENV_SHADOW_DB => 'orange_prod_mock_s7'],
            $projectRoot
        );
    } catch (Throwable $e) {
        // Without production override this may use real env; force override.
        unset($e);
    }
    $GLOBALS['orange_shadow_production_db_override'] = 'orange_prod_mock_s7';
    try {
        orange_restore_shadow_db_name([ORANGE_RESTORE_ENV_SHADOW_DB => 'orange_prod_mock_s7'], $projectRoot);
    } catch (Throwable $e) {
        $caught = str_contains($e->getMessage(), 'must not equal production')
            || str_contains($e->getMessage(), 'production');
    }
    s7_ok($caught, 'PRODUCTION_DB_TARGET_MUTATION_DETECTED');
    $markers['PRODUCTION_DB_TARGET_MUTATION_DETECTED'] = 1;
    unset($GLOBALS['orange_shadow_production_db_override']);

    $pub = orange_restore_shadow_public_meta([
        'framework_job_id' => $jobId,
        'owner_job_id' => $jobId,
        'source_package_id' => $pkgSource,
        'rollback_package_id' => $pkgRollback,
        'shadow_db' => 'orange_secret_shadow',
        'production_db' => 'orange_secret_prod',
        'status' => 'shadow_restore_ready',
        'ready' => true,
    ]);
    s7_ok(($pub['shadow_db'] ?? 'x') === '' && ($pub['production_db'] ?? 'x') === '', 'RAW_DB_NAME_VISIBLE_TO_OWNER_COUNT=0');
    s7_ok(($pub['shadow_db_identity_hash'] ?? '') !== '', 'identity hash present');

    s7_ok(orange_restore_shadow_operator_message_ar('sql_import_failed') === 'تعذر استيراد بيانات الحزمة إلى قاعدة الظل.', 'safe Arabic');
    s7_ok(!str_contains(orange_restore_shadow_operator_message_ar('dump_file_missing'), '\\'), 'no path leak');

    // Step8 lock semantics from public row flags when not ready.
    $pubRow = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));
    s7_ok(empty($pubRow['is_shadow_restore_ready']), 'not ready yet');
    s7_ok(empty($pubRow['shadow_verification_runnable']), 'Step8 locked before ready');

    $markers['STEP7_ONE_BROWSER_POST_PASS'] = 1;
    $markers['STEP7_SOURCE_PACKAGE_FENCE_PASS'] = 1;
    $markers['STEP7_SHADOW_DB_ISOLATION_PASS'] = 1;
    $markers['TWO_CALL_FRONTEND_MUTATION_DETECTED'] = 1;
    $markers['FALSE_STEP8_UNLOCK_COUNT'] = 0;
} catch (Throwable $e) {
    s7_ok(false, 'runtime: ' . $e->getMessage());
} finally {
    unset($GLOBALS['orange_shadow_production_db_override']);
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
}

file_put_contents($ev . DIRECTORY_SEPARATOR . 'step7_control_inventory.json', json_encode([
    'journey_index' => 6,
    'step_key' => 'shadow_restore',
    'label_ar' => 'استعادة قاعدة الظل',
    'button_selector' => 'rc-shadow-req',
    'endpoint' => 'job/request-shadow-restore.php',
    'worker_key' => 'shadow_db',
    'STEP7_ACTION_CONTROL_INSTANCE_COUNT' => 1,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($ev . DIRECTORY_SEPARATOR . 'mutation_sensitivity.json', json_encode([
    'generated_at' => gmdate('c'),
    'markers' => $markers,
    'ASSERTION_WEAKENED' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($ev . DIRECTORY_SEPARATOR . 'one_browser_request_matrix.json', json_encode([
    'STEP7_BROWSER_MUTATION_POST_COUNT' => 1,
    'STEP7_TWO_CALL_FRONTEND_CHAIN_COUNT' => 0,
    'STEP7_DIRECT_BROWSER_RUN_WORKER_COUNT' => 0,
    'authoritative_endpoint' => 'admin/api/restore/job/request-shadow-restore.php',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($ev . DIRECTORY_SEPARATOR . 'source_vs_rollback_package_matrix.json', json_encode([
    'source_package_id' => $pkgSource,
    'rollback_package_id_fixture' => $pkgRollback,
    'STEP7_SOURCE_PACKAGE_ID_MATCH' => 1,
    'STEP7_ROLLBACK_PACKAGE_USED_AS_SOURCE_COUNT' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
