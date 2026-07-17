<?php

declare(strict_types=1);

/**
 * Phase 3B.4F — Restore Finalization & Maintenance Release self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_production_finalize.php
 *
 * Isolated fixtures only. Never touches real production.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRootReal = dirname(__DIR__, 2);
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_retention.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_rollback.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_finalize.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

$failures = 0;
$passes = 0;
$tmpRoot = '';

function fz_self_test(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function fz_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            fz_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function fz_retire_job(string $workRoot, string $jobId): void
{
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
        $job['status'] = ORANGE_RESTORE_FW_STATUS_CANCELLED;
        $job['phase'] = ORANGE_RESTORE_FW_PHASE_CANCELLED;
        orange_restore_fw_write($workRoot, $job);
    } catch (Throwable) {
    }
    try {
        orange_restore_exec_release_lock($workRoot, $jobId);
    } catch (Throwable) {
    }
    try {
        $maint = orange_restore_maint_fw_read($workRoot);
        if ((string) ($maint['related_job_id'] ?? '') === $jobId
            && (string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            orange_restore_maint_fw_release($workRoot, 'tester');
        }
    } catch (Throwable) {
    }
    try {
        orange_restore_merge_maintenance_disable($workRoot, $jobId);
    } catch (Throwable) {
    }
}

function fz_write_zip(string $path, array $files): void
{
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $body) {
        $name = (string) $name;
        $body = (string) $body;
        $nameLen = strlen($name);
        $size = strlen($body);
        $crc = crc32($body);
        if ($crc < 0) {
            $crc = $crc & 0xFFFFFFFF;
        }
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
            . $name . $body;
        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;
        $local .= $localHeader;
        $central .= $centralHeader;
        $offset += strlen($localHeader);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
}

function fz_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    fz_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello-uploads']);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION,
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
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

/**
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO,rollback_package_id:string}
 */
function fz_seed_base_job(
    string $workRoot,
    string $backupRoot,
    string $sourceId,
    string $rollbackPackageId,
    string $targetStatus
): array {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $hash = password_hash('restore-test-password', PASSWORD_DEFAULT);
    $pdo->exec('INSERT INTO admins VALUES (1, \'superadmin\', 1, 1, \'SA\', ' . $pdo->quote($hash) . ')');
    $pdo->exec("INSERT INTO admin_permissions VALUES (1, 'backup_restore_full', 1, 0, 0)");
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];
    $admin = ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1];

    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $sourceId,
        'package_type' => 'full_disaster',
        'created_by' => 'superadmin',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    orange_restore_dry_run_execute($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
    ]);
    orange_restore_exec_prepare_plan($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => 'superadmin',
        'operator_admin_id' => 1,
    ]);
    $jobNow = orange_restore_fw_read($workRoot, $jobId);
    $planFp = orange_restore_final_approval_plan_fingerprint($workRoot, $jobId);
    file_put_contents(
        orange_restore_final_approval_record_path($workRoot, $jobId),
        json_encode([
            'approval_version' => ORANGE_RESTORE_FINAL_APPROVAL_VERSION,
            'job_id' => $jobId,
            'package_id' => $sourceId,
            'package_type' => 'full_disaster',
            'approved_by' => 'superadmin',
            'approved_by_admin_id' => 1,
            'approved_at' => gmdate('c'),
            'plan_fingerprint' => $planFp,
            'package_fingerprint' => (string) ($jobNow['package_fingerprint'] ?? ''),
            'dry_run_fingerprint' => (string) ($jobNow['dry_run_fingerprint'] ?? ''),
            'confirmation_phrase_hash' => hash('sha256', 'phrase'),
            'nonce_id_hash' => hash('sha256', 'nonce'),
            'approval_consumed' => true,
            'execution_started' => false,
            'cli_invoked' => false,
            'maintenance_enabled' => false,
            'recent_auth_verified' => true,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    );
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_PHASE_APPROVED_WAITING_EXECUTION,
        100,
        'test approved',
        'restore_final_approval_granted'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['package_fingerprint'] = (string) ($jobNow['package_fingerprint'] ?? '');
    $j['dry_run_fingerprint'] = (string) ($jobNow['dry_run_fingerprint'] ?? '');
    $j['approved_at'] = gmdate('c');
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

    $pin = orange_backup_retention_pin_package($backupRoot, $rollbackPackageId, [
        'framework_job_id' => $jobId,
        'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
        'created_by' => 'superadmin',
        'source_package_id' => $sourceId,
    ]);
    $anchorPath = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $rollbackPackageId;
    $liveChecksum = orange_restore_merge_precheck_live_rollback_checksum($anchorPath);
    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'rollback_package_id' => $rollbackPackageId,
        'rollback_package_type' => 'full',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => (string) ($pin['pin_id'] ?? ''),
        'rollback_package_fingerprint' => $liveChecksum,
        'execution_started' => false,
        'verify_result' => 'pass',
        'drv_result' => 'pass',
    ]);

    orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_report_path($workRoot, $jobId), [
        'overall_result' => 'PASS',
        'readiness_score' => 95,
        'job_id' => $jobId,
    ]);
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
        'framework_job_id' => $jobId,
        'ready' => true,
        'execution_started' => false,
    ]);
    orange_backup_write_json(orange_restore_shadow_smoke_report_path($workRoot, $jobId), [
        'overall_result' => 'READY',
        'readiness_score' => 90,
        'job_id' => $jobId,
        'blocking_errors' => [],
        'warnings' => [],
    ]);
    orange_backup_write_json(orange_restore_cutover_readiness_path($workRoot, $jobId), [
        'status' => 'READY',
        'framework_status' => ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        'production_cutover_allowed' => false,
        'smoke_ready' => true,
        'rollback_anchor_ready' => true,
    ]);

    foreach (['C0', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6'] as $cp) {
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, $cp, ['fixture' => true]);
    }
    orange_backup_write_json(orange_restore_prod_import_report_path($workRoot, $jobId), [
        'overall' => 'PASS',
        'verification' => ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => []],
        'job_id' => $jobId,
    ]);
    orange_restore_uploads_cutover_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C7, ['fixture' => true]);
    orange_restore_uploads_cutover_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C8, [
        'verification' => ['ok' => true, 'overall' => 'PASS'],
    ]);
    orange_backup_write_json(orange_restore_uploads_cutover_report_path($workRoot, $jobId), [
        'overall' => 'PASS',
        'job_id' => $jobId,
    ]);

    $acq = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!($acq['ok'] ?? false)) {
        throw new RuntimeException('fixture lock failed');
    }
    $req = orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $admin);
    orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        'restore-test-password',
        (string) (($req['challenge']['nonce'] ?? ''))
    );
    orange_restore_merge_maintenance_enable($workRoot, $jobId, [
        'reason' => 'finalize_self_test',
    ]);

    if ($targetStatus === ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY) {
        foreach (['C9', 'C10', 'C11', 'C12'] as $cp) {
            orange_restore_prod_rollback_write_checkpoint($workRoot, $jobId, $cp, [
                'fixture' => true,
                'verification' => ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => [], 'details' => ['schema' => true]],
            ]);
        }
        orange_backup_write_json(orange_restore_prod_rollback_report_path($workRoot, $jobId), [
            'overall' => 'PASS',
            'job_id' => $jobId,
            'final_verification' => ['ok' => true, 'overall' => 'PASS'],
        ]);
        orange_backup_write_json(orange_restore_prod_rollback_meta_path($workRoot, $jobId), [
            'status' => ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
            'framework_job_id' => $jobId,
        ]);
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY,
            ORANGE_RESTORE_FW_PHASE_ROLLBACK_READY,
            100,
            'fixture rollback ready',
            'restore_rollback_ready'
        );
    } else {
        orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_READY,
            100,
            'fixture uploads cutover ready',
            'restore_uploads_cutover_ready'
        );
    }
    $j2 = orange_restore_fw_read($workRoot, $jobId);
    $j2['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j2);

    return [
        'job_id' => $jobId,
        'admin' => $admin,
        'pdo' => $pdo,
        'rollback_package_id' => $rollbackPackageId,
    ];
}

try {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_prod_finalize_' . bin2hex(random_bytes(4));
    $backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backup';
    $workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
    $fixtureProject = $tmpRoot . DIRECTORY_SEPARATOR . 'project';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
    mkdir($workRoot, 0775, true);
    mkdir($fixtureProject, 0775, true);
    file_put_contents(
        $fixtureProject . DIRECTORY_SEPARATOR . '.env.php',
        "<?php\nreturn ['DB_USER'=>'orange_dev','DB_PASS'=>'x','ORANGE_RESTORE_STAGING_DB'=>'orange_restore_staging','ORANGE_RESTORE_STAGING_DB_USER'=>'orange_restore_staging','ORANGE_RESTORE_STAGING_DB_PASS'=>'p','ORANGE_RESTORE_MERGE_DB_USER'=>'orange_restore_merge','ORANGE_RESTORE_MERGE_DB_PASS'=>'p'];\n"
    );
    file_put_contents(
        $fixtureProject . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\nif (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }\nif (!defined('DB_NAME')) { define('DB_NAME', 'orange_db'); }\n"
    );

    $pkgId = '2026-07-18_030000';
    fz_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId, $pkgId);
    $makeRollbackPkg = static function () use ($backupRoot): string {
        $id = '2026-07-17_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        fz_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $id, $id);

        return $id;
    };

    $cliSrc = (string) file_get_contents($projectRootReal . '/scripts/backup/restore_finalize.php');
    fz_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && str_contains($cliSrc, 'arbitrary path/directory'),
        'CLI only + forbids path args'
    );
    $reqApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/request-finalize.php');
    $stApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/finalize.php');
    fz_self_test(str_contains($reqApi, 'metadata_only') && !str_contains($reqApi, 'orange_restore_prod_finalize_run_cli'), 'HTTP request metadata only');
    fz_self_test(str_contains($stApi, 'restore_admin_api_require_get') && !str_contains($stApi, 'orange_restore_prod_finalize_run_cli'), 'HTTP status GET only');

    $engine = (string) file_get_contents($projectRootReal . '/includes/backup/restore/restore_production_finalize.php');
    fz_self_test(
        str_contains($engine, 'orange_restore_maint_fw_release')
        && str_contains($engine, 'orange_restore_exec_release_lock')
        && !str_contains($engine, 'orange_restore_production_wipe')
        && !str_contains($engine, 'orange_restore_sql_runner_import_gzip')
        && !str_contains($engine, 'orange_restore_prod_rollback_run_cli')
        && !str_contains($engine, 'orange_restore_uploads_cutover_run_cli')
        && !str_contains($engine, 'orange_backup_retention_unpin'),
        'scope: finalize/release only; no import/rename/rollback/unpin'
    );

    // --- Restore completion path ---
    $rb1 = $makeRollbackPkg();
    $seed1 = fz_seed_base_job($workRoot, $backupRoot, $pkgId, $rb1, ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY);
    $job1 = $seed1['job_id'];
    $gates1 = orange_restore_prod_finalize_validate_entry($workRoot, $job1, $backupRoot);
    fz_self_test(($gates1['ok'] ?? false) === true && ($gates1['path'] ?? '') === ORANGE_RESTORE_PROD_FINALIZE_PATH_RESTORE, 'entry: restore success path');

    $req1 = orange_restore_prod_finalize_request($workRoot, $job1, $backupRoot, $seed1['admin']);
    fz_self_test(($req1['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_RESTORE_FINALIZING, 'state: → restore_finalizing');

    $importReportBefore = is_file(orange_restore_prod_import_report_path($workRoot, $job1));
    $uploadsReportBefore = is_file(orange_restore_uploads_cutover_report_path($workRoot, $job1));
    $c6Before = orange_restore_prod_import_load_checkpoint($workRoot, $job1, 'C6') !== null;
    $pinBefore = orange_backup_retention_pin_public($backupRoot, $rb1);
    $anchorBefore = orange_restore_pre_backup_load_record($workRoot, $job1);

    $run1 = orange_restore_prod_finalize_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job1,
        'owner' => 'tester',
    ]);
    fz_self_test(($run1['ok'] ?? false) === true, 'restore finalize: CLI succeeds');
    fz_self_test(($run1['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED, 'state: → restore_completed');
    fz_self_test(($run1['overall_result'] ?? '') === 'RESTORE_COMPLETED', 'report: RESTORE_COMPLETED');
    fz_self_test(($run1['maintenance_released'] ?? false) === true, 'restore: maintenance released');
    fz_self_test(($run1['restore_completed'] ?? false) === true, 'restore: restore_completed true');
    fz_self_test(($run1['rollback_anchor_deleted'] ?? true) === false, 'restore: anchor not deleted');
    fz_self_test(($run1['retention_pin_removed'] ?? true) === false, 'restore: pin not removed');

    $maint1 = orange_restore_maint_fw_read($workRoot);
    fz_self_test((string) ($maint1['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'restore: framework maintenance inactive');
    $merge1 = orange_restore_merge_maintenance_status($workRoot);
    fz_self_test(empty($merge1['active']), 'restore: merge maintenance released');
    fz_self_test(!is_file(orange_restore_exec_lock_path($workRoot)), 'restore: execution lock released');

    fz_self_test(is_file(orange_restore_pre_backup_record_path($workRoot, $job1)) && $anchorBefore !== null, 'restore: pre_backup preserved');
    fz_self_test(orange_backup_retention_pin_public($backupRoot, $rb1) !== null && $pinBefore !== null, 'restore: retention pin preserved');
    fz_self_test($importReportBefore && is_file(orange_restore_prod_import_report_path($workRoot, $job1)), 'restore: import report preserved');
    fz_self_test($uploadsReportBefore && is_file(orange_restore_uploads_cutover_report_path($workRoot, $job1)), 'restore: uploads report preserved');
    fz_self_test($c6Before && orange_restore_prod_import_load_checkpoint($workRoot, $job1, 'C6') !== null, 'restore: checkpoints preserved');
    fz_self_test(is_file(orange_restore_prod_finalize_report_path($workRoot, $job1)), 'restore: restore_final_report.json written');

    $st1 = orange_restore_prod_finalize_status($workRoot, $job1);
    fz_self_test(!empty($st1['restore_completed']) && !empty($st1['maintenance_released']) && !empty($st1['execution_finished']), 'restore status: completed + maint released + finished');
    fz_retire_job($workRoot, $job1);

    // --- Rollback completion path ---
    $rb2 = $makeRollbackPkg();
    $seed2 = fz_seed_base_job($workRoot, $backupRoot, $pkgId, $rb2, ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY);
    $job2 = $seed2['job_id'];
    $gates2 = orange_restore_prod_finalize_validate_entry($workRoot, $job2, $backupRoot);
    fz_self_test(($gates2['ok'] ?? false) === true && ($gates2['path'] ?? '') === ORANGE_RESTORE_PROD_FINALIZE_PATH_ROLLBACK, 'entry: rollback success path');

    $req2 = orange_restore_prod_finalize_request($workRoot, $job2, $backupRoot, $seed2['admin']);
    fz_self_test(($req2['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_ROLLBACK_FINALIZING, 'state: → rollback_finalizing');

    $rbReportBefore = is_file(orange_restore_prod_rollback_report_path($workRoot, $job2));
    $c12Before = orange_restore_prod_rollback_load_checkpoint($workRoot, $job2, 'C12') !== null;

    $run2 = orange_restore_prod_finalize_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job2,
        'owner' => 'tester',
    ]);
    fz_self_test(($run2['ok'] ?? false) === true, 'rollback finalize: CLI succeeds');
    fz_self_test(($run2['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED, 'state: → rollback_completed');
    fz_self_test(($run2['overall_result'] ?? '') === 'ROLLBACK_COMPLETED', 'report: ROLLBACK_COMPLETED');
    fz_self_test(($run2['maintenance_released'] ?? false) === true, 'rollback: maintenance released');
    fz_self_test(($run2['rollback_completed'] ?? false) === true, 'rollback: rollback_completed true');
    fz_self_test(($run2['retention_pin_removed'] ?? true) === false, 'rollback: pin kept');
    fz_self_test(($run2['rollback_anchor_deleted'] ?? true) === false, 'rollback: anchor kept');

    $maint2 = orange_restore_maint_fw_read($workRoot);
    fz_self_test((string) ($maint2['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'rollback: framework maintenance inactive');
    fz_self_test(!is_file(orange_restore_exec_lock_path($workRoot)), 'rollback: execution lock released');
    fz_self_test($rbReportBefore && is_file(orange_restore_prod_rollback_report_path($workRoot, $job2)), 'rollback: rollback report preserved');
    fz_self_test($c12Before && orange_restore_prod_rollback_load_checkpoint($workRoot, $job2, 'C12') !== null, 'rollback: C12 preserved');
    fz_self_test(orange_backup_retention_pin_public($backupRoot, $rb2) !== null, 'rollback: retention pin preserved');
    fz_self_test(is_file(orange_restore_pre_backup_record_path($workRoot, $job2)), 'rollback: pre_backup preserved');
    fz_self_test(is_file(orange_restore_prod_finalize_report_path($workRoot, $job2)), 'rollback: restore_final_report.json written');
    fz_retire_job($workRoot, $job2);

    // Reject mid-import
    $rb3 = $makeRollbackPkg();
    $seed3 = fz_seed_base_job($workRoot, $backupRoot, $pkgId, $rb3, ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY);
    $job3 = $seed3['job_id'];
    orange_restore_fw_transition(
        $workRoot,
        $job3,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_READY,
        50,
        'not ready for finalize',
        'test'
    );
    $gates3 = orange_restore_prod_finalize_validate_entry($workRoot, $job3, $backupRoot);
    fz_self_test(($gates3['ok'] ?? true) === false && ($gates3['code'] ?? '') === 'finalize_entry_not_allowed', 'entry: rejects non-ready status');
    fz_retire_job($workRoot, $job3);

    $ui = (string) file_get_contents($projectRootReal . '/admin/pages/restore_center.php');
    fz_self_test(
        str_contains($ui, 'rc-finalize-req')
        && str_contains($ui, 'Restore Completed')
        && str_contains($ui, 'Rollback Completed')
        && str_contains($ui, 'Maintenance Released')
        && str_contains($ui, 'Execution Finished'),
        'UI: finalize controls + completion badges'
    );

    echo 'PRODUCTION_FINALIZE_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "PRODUCTION_FINALIZE_TEST_RESULT: FAIL\n";
} finally {
    if ($tmpRoot !== '') {
        fz_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
