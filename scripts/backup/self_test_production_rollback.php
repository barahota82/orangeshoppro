<?php

declare(strict_types=1);

/**
 * Phase 3B.4E — Production Rollback Engine self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_production_rollback.php
 *
 * Isolated fixtures / mock PDO only. Never rolls back real production.
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
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

$failures = 0;
$passes = 0;
$tmpRoot = '';

function rb_self_test(bool $ok, string $label): void
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

function rb_rmtree(string $dir): void
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
            rb_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function rb_retire_job(string $workRoot, string $jobId): void
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

function rb_write_zip(string $path, array $files): void
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

function rb_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    rb_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello-uploads']);
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

final class RbProductionRollbackMockPdo extends PDO
{
    private string $sessionDb;

    public function __construct(string $sessionDb)
    {
        $this->sessionDb = $sessionDb;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SELECT DATABASE()') !== false) {
            return restore_self_test_scalar_statement($this->sessionDb);
        }
        if (stripos($query, 'SELECT 1') !== false) {
            return restore_self_test_scalar_statement('1');
        }

        return restore_self_test_scalar_statement('1');
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }
}

/**
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO,fixture_project:string,rollback_package_id:string}
 */
function rb_seed_cutover_ready_job(
    string $workRoot,
    string $backupRoot,
    string $sourceId,
    string $rollbackPackageId,
    string $fixtureProject
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

    // Live uploads (post-cutover new tree) + pre_merge (old production).
    $uploadsLive = $fixtureProject . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadsLive)) {
        mkdir($uploadsLive, 0775, true);
    }
    file_put_contents($uploadsLive . DIRECTORY_SEPARATOR . 'new.txt', 'post-cutover');

    $preMerge = orange_restore_uploads_pre_merge_directory($fixtureProject, $jobId);
    if (!is_dir($preMerge)) {
        mkdir($preMerge, 0775, true);
    }
    file_put_contents($preMerge . DIRECTORY_SEPARATOR . 'old.txt', 'pre-cutover-original');
    $preInv = orange_restore_uploads_tree_inventory($preMerge);
    $snapDir = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
    if (!is_dir($snapDir) && !@mkdir($snapDir, 0775, true) && !is_dir($snapDir)) {
        throw new RuntimeException('Cannot create pre_merge snapshot dir for fixture');
    }
    orange_backup_write_json(orange_restore_pre_merge_uploads_snapshot_manifest_path($workRoot, $jobId), [
        'file_count' => $preInv['file_count'],
        'tree_checksum_sha256' => $preInv['tree_checksum_sha256'],
        'total_size' => $preInv['total_size'],
    ]);

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
            'tree_checksum_sha256' => $preInv['tree_checksum_sha256'],
            'actual_file_count' => $preInv['file_count'],
            'total_size' => $preInv['total_size'],
            'warnings' => [],
            'errors' => [],
        ],
    ]);

    foreach (['C0', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6'] as $cp) {
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, $cp, ['fixture' => true]);
    }
    orange_backup_write_json(orange_restore_prod_import_report_path($workRoot, $jobId), [
        'overall' => 'PASS',
        'verification' => ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => []],
        'job_id' => $jobId,
    ]);
    orange_restore_uploads_cutover_write_checkpoint($workRoot, $jobId, ORANGE_RESTORE_UPLOADS_CUTOVER_C7, [
        'fixture' => true,
        'expected_tree_checksum' => $preInv['tree_checksum_sha256'],
        'expected_file_count' => $preInv['file_count'],
    ]);
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

    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
        ORANGE_RESTORE_FW_PHASE_UPLOADS_CUTOVER_READY,
        100,
        'fixture uploads cutover ready',
        'restore_uploads_cutover_ready'
    );
    $j2 = orange_restore_fw_read($workRoot, $jobId);
    $j2['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j2);

    return [
        'job_id' => $jobId,
        'admin' => $admin,
        'pdo' => $pdo,
        'fixture_project' => $fixtureProject,
        'rollback_package_id' => $rollbackPackageId,
    ];
}

try {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_prod_rollback_' . bin2hex(random_bytes(4));
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

    $pkgId = '2026-07-18_020000';
    rb_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId, $pkgId);

    $makeRollbackPkg = static function () use ($backupRoot): string {
        $id = '2026-07-17_' . substr((string) time(), -6);
        // Ensure uniqueness across rapid seeds in one process.
        $id = '2026-07-17_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        rb_seed_package($backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $id, $id);

        return $id;
    };

    // Surface checks
    $cliSrc = (string) file_get_contents($projectRootReal . '/scripts/backup/restore_rollback.php');
    rb_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && str_contains($cliSrc, 'arbitrary path/directory'),
        'CLI only + forbids path args'
    );
    $reqApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/request-rollback.php');
    $stApi = (string) file_get_contents($projectRootReal . '/admin/api/restore/job/rollback.php');
    rb_self_test(str_contains($reqApi, 'metadata_only') && !str_contains($reqApi, 'orange_restore_prod_rollback_run_cli'), 'HTTP request metadata only');
    rb_self_test(str_contains($stApi, 'restore_admin_api_require_get') && !str_contains($stApi, 'orange_restore_prod_rollback_run_cli'), 'HTTP status GET only');

    $engine = (string) file_get_contents($projectRootReal . '/includes/backup/restore/restore_production_rollback.php');
    rb_self_test(
        str_contains($engine, 'orange_restore_prod_rollback_resolve_anchor')
        && str_contains($engine, 'uploads_pre_merge')
        && !str_contains($engine, 'orange_restore_maint_fw_release')
        && !str_contains($engine, 'orange_backup_retention_unpin')
        && !str_contains($engine, 'merge_maintenance_disable'),
        'scope: rollback only; no maint release / unpin / finalize'
    );
    rb_self_test(
        str_contains($engine, 'Never restore from shadow')
        || str_contains($engine, 'never shadow')
        || str_contains($engine, 'shadow_workspace_used') && str_contains($engine, "'shadow_db_used' => false"),
        'policy markers: no shadow DB/workspace as source'
    );

    // Entry gate: reject without cutover ready
    $rollbackPkgBad = $makeRollbackPkg();
    $seedBad = rb_seed_cutover_ready_job($workRoot, $backupRoot, $pkgId, $rollbackPkgBad, $fixtureProject);
    $jobBad = $seedBad['job_id'];
    orange_restore_fw_transition(
        $workRoot,
        $jobBad,
        ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY,
        ORANGE_RESTORE_FW_PHASE_PRODUCTION_IMPORT_READY,
        50,
        'downgrade for gate test',
        'test'
    );
    $gatesBad = orange_restore_prod_rollback_validate_entry($workRoot, $jobBad, $backupRoot, $fixtureProject, false);
    rb_self_test(($gatesBad['ok'] ?? true) === false && ($gatesBad['code'] ?? '') === 'uploads_cutover_not_ready', 'entry: rejects without uploads_cutover_ready');
    rb_retire_job($workRoot, $jobBad);
    rb_rmtree($fixtureProject . DIRECTORY_SEPARATOR . 'uploads');
    @mkdir($fixtureProject . DIRECTORY_SEPARATOR . 'uploads', 0775, true);

    // Happy path (mock DB + real uploads rename in temp project)
    $rollbackPkgId = $makeRollbackPkg();
    $seed = rb_seed_cutover_ready_job($workRoot, $backupRoot, $pkgId, $rollbackPkgId, $fixtureProject);
    $jobId = $seed['job_id'];
    $gates = orange_restore_prod_rollback_validate_entry($workRoot, $jobId, $backupRoot, $fixtureProject, false);
    rb_self_test(($gates['ok'] ?? false) === true, 'entry: gates pass on cutover_ready + anchor + pin + maint');

    $req = orange_restore_prod_rollback_request(
        $workRoot,
        $jobId,
        $backupRoot,
        $fixtureProject,
        $seed['admin']
    );
    rb_self_test(($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_ROLLBACK_PENDING, 'state: → rollback_pending');

    $mockPdo = new RbProductionRollbackMockPdo('orange_db');

    $run = orange_restore_prod_rollback_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'tester',
        'merge_pdo_override' => $mockPdo,
        'db_import_override' => static function (array $ctx): void {
            if (($ctx['dump_path'] ?? '') === '' || !is_file((string) $ctx['dump_path'])) {
                throw new RuntimeException('fixture expected anchor dump');
            }
            if (str_contains((string) $ctx['dump_path'], 'shadow')) {
                throw new RuntimeException('shadow path rejected');
            }
        },
        'db_verify_override' => static function (): array {
            return [
                'ok' => true,
                'overall' => 'PASS',
                'blocking_errors' => [],
                'details' => ['schema' => true, 'required_tables' => true, 'counts' => true],
            ];
        },
    ]);
    rb_self_test(($run['ok'] ?? false) === true, 'rollback: CLI succeeds on fixtures');
    rb_self_test(($run['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_ROLLBACK_READY, 'state: → rollback_ready');
    rb_self_test(($run['maintenance_released'] ?? true) === false, 'no maintenance release');
    rb_self_test(($run['restore_completed'] ?? true) === false, 'not restore completed');
    rb_self_test(($run['rollback_anchor_deleted'] ?? true) === false, 'anchor not deleted');
    rb_self_test(($run['retention_pin_removed'] ?? true) === false, 'pin not removed');

    $liveUploads = $fixtureProject . DIRECTORY_SEPARATOR . 'uploads';
    $preMerge = orange_restore_uploads_pre_merge_directory($fixtureProject, $jobId);
    rb_self_test(is_file($liveUploads . DIRECTORY_SEPARATOR . 'old.txt'), 'verify: uploads restored from pre_merge');
    rb_self_test(!is_dir($preMerge), 'verify: uploads_pre_merge consumed');
    rb_self_test(is_file(orange_restore_pre_backup_record_path($workRoot, $jobId)), 'confirm: pre_backup record retained');
    rb_self_test(orange_backup_retention_pin_public($backupRoot, $rollbackPkgId) !== null, 'confirm: retention pin retained');
    $maint = orange_restore_maint_fw_read($workRoot);
    rb_self_test((string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'confirm: maintenance still active');

    $hist = orange_restore_prod_rollback_checkpoint_history($workRoot, $jobId);
    $ids = array_column($hist, 'checkpoint_id');
    rb_self_test($ids === ['C9', 'C10', 'C11', 'C12'], 'checkpoints: C9–C12 persisted');
    rb_self_test(is_file(orange_restore_prod_rollback_report_path($workRoot, $jobId)), 'report: rollback_report.json');

    $status = orange_restore_prod_rollback_status($workRoot, $jobId);
    rb_self_test(($status['highest_checkpoint'] ?? '') === 'C12', 'status API: highest C12');
    rb_self_test(($status['maintenance_released'] ?? true) === false, 'status: maintenance_released false');

    // Reject already-running for new request (idempotent return)
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_ROLLBACK_DATABASE_RUNNING,
        ORANGE_RESTORE_FW_PHASE_ROLLBACK_DATABASE_RUNNING,
        20,
        'simulate running',
        'test'
    );
    // Clear C12 so status is mid-flight for gate semantics
    @unlink(orange_restore_prod_import_checkpoint_path($workRoot, $jobId, 'C12'));
    $idem = orange_restore_prod_rollback_request($workRoot, $jobId, $backupRoot, $fixtureProject, $seed['admin']);
    rb_self_test(!empty($idem['idempotent']) && !empty($idem['cli_needed']), 'request: idempotent while running');

    rb_retire_job($workRoot, $jobId);

    $ui = (string) file_get_contents($projectRootReal . '/admin/pages/restore_center.php');
    rb_self_test(
        str_contains($ui, 'rc-rollback-req')
        && str_contains($ui, 'Rollback Pending')
        && str_contains($ui, 'Rollback Ready'),
        'UI: rollback controls + states'
    );

    echo 'PRODUCTION_ROLLBACK_TEST_RESULT: ' . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "PRODUCTION_ROLLBACK_TEST_RESULT: FAIL\n";
} finally {
    if ($tmpRoot !== '') {
        rb_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
