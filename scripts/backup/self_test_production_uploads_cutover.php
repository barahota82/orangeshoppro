<?php

declare(strict_types=1);

/**
 * Phase 3B.4D — Production Uploads Cutover self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_production_uploads_cutover.php
 *
 * Temp directories only. Never renames real production uploads/.
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
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

$failures = 0;
$passes = 0;
$tmpRoot = '';

function uc_self_test(bool $ok, string $label): void
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

function uc_rmtree(string $dir): void
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
            uc_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function uc_retire_job(string $workRoot, string $jobId): void
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
        if ((string) ($maint['related_job_id'] ?? '') === $jobId) {
            orange_restore_maint_fw_release($workRoot, 'tester');
        }
    } catch (Throwable) {
    }
    try {
        orange_restore_merge_maintenance_disable($workRoot, $jobId);
    } catch (Throwable) {
    }
}

function uc_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create test zip');
        }
        foreach ($files as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }
        $zip->close();

        return;
    }
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

function uc_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    uc_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello-uploads']);
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
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO,fixture_project:string,shadow_checksum:string,shadow_count:int}
 */
function uc_seed_import_ready_job(string $workRoot, string $backupRoot, string $sourceId, string $fixtureProject): array
{
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

    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourceId,
        'rollback_package_id' => '2026-07-17_999999',
        'ready_for_rollback' => true,
        'retention_pinned' => true,
        'retention_pin_id' => 'pin_test',
        'execution_started' => false,
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
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY,
        ORANGE_RESTORE_FW_PHASE_CUTOVER_READINESS_READY,
        100,
        'cutover readiness ready (fixture)',
        'cutover_readiness_ready'
    );

    // Live uploads (old production tree) + shadow workspace (new tree).
    $uploadsLive = $fixtureProject . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsLive)) {
        mkdir($uploadsLive, 0775, true);
    }
    file_put_contents($uploadsLive . DIRECTORY_SEPARATOR . 'old.txt', 'old-production');

    $shadowWs = orange_restore_shadow_files_workspace_path($workRoot, $jobId);
    if (!is_dir($shadowWs)) {
        mkdir($shadowWs, 0775, true);
    }
    file_put_contents($shadowWs . DIRECTORY_SEPARATOR . 'a.txt', 'hello-uploads');
    file_put_contents($shadowWs . DIRECTORY_SEPARATOR . 'b.txt', 'second');
    $shadowInv = orange_restore_uploads_tree_inventory($shadowWs);

    orange_restore_shadow_files_write_json(orange_restore_shadow_files_meta_path($workRoot, $jobId), [
        'record_version' => '1',
        'framework_job_id' => $jobId,
        'ready' => true,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        'execution_started' => false,
    ]);
    orange_restore_shadow_files_write_json(orange_restore_shadow_files_report_path($workRoot, $jobId), [
        'overall_result' => 'PASS',
        'framework_job_id' => $jobId,
        'verification' => [
            'ok' => true,
            'tree_checksum_sha256' => $shadowInv['tree_checksum_sha256'],
            'actual_file_count' => $shadowInv['file_count'],
            'total_size' => $shadowInv['total_size'],
            'warnings' => [],
            'errors' => [],
        ],
    ]);

    // Import lineage (C5/C6 + report).
    foreach (['C0', 'C1', 'C2', 'C3', 'C4'] as $cp) {
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, $cp, ['fixture' => true]);
    }
    orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C5, [
        'verification' => ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => [], 'details' => []],
    ]);
    orange_restore_prod_import_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_PROD_IMPORT_C6, [
        'committed_at' => gmdate('c'),
    ]);
    orange_backup_write_json(orange_restore_prod_import_report_path($workRoot, $jobId), [
        'overall' => 'PASS',
        'verification' => ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => []],
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

    // Jump to production_import_ready (DB import already "done" in fixture).
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_READY,
        100,
        'fixture production import ready',
        'restore_production_import_ready'
    );
    $j2 = orange_restore_fw_read($workRoot, $jobId);
    $j2['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j2);

    return [
        'job_id' => $jobId,
        'admin' => $admin,
        'pdo' => $pdo,
        'fixture_project' => $fixtureProject,
        'shadow_checksum' => $shadowInv['tree_checksum_sha256'],
        'shadow_count' => $shadowInv['file_count'],
    ];
}

try {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_uploads_cutover_' . bin2hex(random_bytes(4));
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

    $pkgId = '2026-07-18_010000';
    uc_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId, $pkgId);

    // Surface checks
    $cliSrc = (string) file_get_contents($projectRootReal . '/scripts/backup/restore_uploads_cutover.php');
    uc_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && str_contains($cliSrc, 'arbitrary path/directory'),
        'CLI only + forbids path args'
    );
    $reqApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/request-uploads-cutover.php');
    $stApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/uploads-cutover.php');
    uc_self_test(str_contains($reqApi, 'metadata_only') && !str_contains($reqApi, 'orange_restore_uploads_cutover_run_cli'), 'HTTP request metadata only');
    uc_self_test(str_contains($stApi, 'restore_admin_api_require_get') && !str_contains($stApi, 'orange_restore_uploads_cutover_run_cli'), 'HTTP status GET only');

    $engine = (string) file_get_contents($projectRootReal . '/includes/backup/restore/restore_production_uploads_cutover.php');
    uc_self_test(
        str_contains($engine, 'orange_restore_merge_uploads_cutover_atomic_rename')
        && !str_contains($engine, 'orange_restore_production_wipe')
        && !str_contains($engine, 'orange_restore_sql_runner_import_gzip')
        && !str_contains($engine, 'orange_restore_merge_rollback')
        && !str_contains($engine, 'orange_restore_maint_fw_release'),
        'scope: rename only; no DB import/rollback/maint release'
    );

    // Entry gate: not import-ready
    $seedBad = uc_seed_import_ready_job($workRoot, $backupRoot, $pkgId, $fixtureProject);
    $jobBad = $seedBad['job_id'];
    orange_restore_fw_transition(
        $workRoot,
        $jobBad,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_PHASE_MAINTENANCE_ACTIVE,
        50,
        'downgrade for gate test',
        'test'
    );
    $gatesBad = orange_restore_uploads_cutover_validate_entry($workRoot, $jobBad, $backupRoot, $fixtureProject);
    uc_self_test(($gatesBad['ok'] ?? true) === false && ($gatesBad['code'] ?? '') === 'production_import_not_ready', 'entry: rejects without production_import_ready');
    uc_retire_job($workRoot, $jobBad);
    uc_rmtree($fixtureProject . DIRECTORY_SEPARATOR . 'uploads');
    @mkdir($fixtureProject . DIRECTORY_SEPARATOR . 'uploads', 0775, true);

    // Happy path
    $seed = uc_seed_import_ready_job($workRoot, $backupRoot, $pkgId, $fixtureProject);
    $jobId = $seed['job_id'];
    $gates = orange_restore_uploads_cutover_validate_entry($workRoot, $jobId, $backupRoot, $fixtureProject);
    uc_self_test(($gates['ok'] ?? false) === true, 'entry: gates pass on import_ready + shadow_files');

    $req = orange_restore_uploads_cutover_request(
        $workRoot,
        $jobId,
        $backupRoot,
        $fixtureProject,
        $seed['admin']
    );
    uc_self_test(($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING, 'state: → uploads_cutover_pending');

    $run = orange_restore_uploads_cutover_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'tester',
        'disk_free_bytes_override' => 50 * 1024 * 1024,
    ]);
    uc_self_test(($run['ok'] ?? false) === true, 'cutover: rename succeeds on temp project');
    uc_self_test(($run['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY, 'state: → uploads_cutover_ready');
    uc_self_test(($run['rollback_executed'] ?? true) === false, 'no rollback');
    uc_self_test(($run['maintenance_released'] ?? true) === false, 'no maintenance release');
    uc_self_test(($run['restore_completed'] ?? true) === false, 'not restore completed');
    uc_self_test(($run['database_import_performed'] ?? true) === false, 'no DB import in this phase');

    $liveUploads = $fixtureProject . DIRECTORY_SEPARATOR . 'uploads';
    $preMerge = orange_restore_uploads_pre_merge_directory($fixtureProject, $jobId);
    $next = orange_restore_uploads_next_directory($fixtureProject);
    uc_self_test(is_file($liveUploads . DIRECTORY_SEPARATOR . 'a.txt'), 'verify: new uploads tree live');
    uc_self_test(is_file($preMerge . DIRECTORY_SEPARATOR . 'old.txt'), 'verify: old uploads retained as pre_merge');
    uc_self_test(!is_dir($next), 'verify: uploads_next absent after cutover');
    $hist = orange_restore_uploads_cutover_checkpoint_history($workRoot, $jobId);
    $ids = array_column($hist, 'checkpoint_id');
    uc_self_test($ids === ['C7', 'C8'], 'checkpoints: C7 + C8 persisted');
    uc_self_test(is_file(orange_restore_uploads_cutover_report_path($workRoot, $jobId)), 'report: uploads_cutover_report.json');

    // Resume verify_only after C7
    @unlink(orange_restore_prod_import_checkpoint_path($workRoot, $jobId, 'C8'));
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_PENDING,
        ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_PENDING,
        10,
        're-request for resume',
        'test'
    );
    $resume = orange_restore_uploads_cutover_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'tester',
        'disk_free_bytes_override' => 50 * 1024 * 1024,
    ]);
    uc_self_test(($resume['ok'] ?? false) === true && ($resume['resume_mode'] ?? '') === 'verify_only', 'resume: C7 → verify_only');
    uc_retire_job($workRoot, $jobId);

    $ui = (string) file_get_contents($projectRootReal . '/admin/pages/restore_center.php');
    uc_self_test(
        str_contains($ui, 'Uploads Cutover Pending')
        && str_contains($ui, 'rc-uploads-cutover-req')
        && str_contains($ui, 'Restore is NOT completed'),
        'UI: pending/ready + warning + controls'
    );

    echo 'UPLOADS_CUTOVER_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "UPLOADS_CUTOVER_TEST_RESULT: FAIL\n";
} finally {
    if ($tmpRoot !== '') {
        uc_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
