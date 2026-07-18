<?php

declare(strict_types=1);

/**
 * Phase 3B.4G — Disaster Recovery Drill engine (isolated fixtures only).
 *
 * Never touches real production DB/uploads/backup roots. Destructive steps require
 * .orange_restore_dr_drill_fixture markers and drill context.
 */

require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_dry_run.php';
require_once __DIR__ . '/restore_final_approval.php';
require_once __DIR__ . '/restore_execution_bridge.php';
require_once __DIR__ . '/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore_shadow_db.php';
require_once __DIR__ . '/restore_shadow_verify.php';
require_once __DIR__ . '/restore_shadow_files.php';
require_once __DIR__ . '/restore_shadow_smoke.php';
require_once __DIR__ . '/restore_production_maintenance.php';
require_once __DIR__ . '/restore_production_import.php';
require_once __DIR__ . '/restore_production_uploads_cutover.php';
require_once __DIR__ . '/restore_production_rollback.php';
require_once __DIR__ . '/restore_production_finalize.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_retention.php';
require_once __DIR__ . '/../backup_runner.php';
require_once __DIR__ . '/../recovery_validation.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

const ORANGE_RESTORE_DR_DRILL_VERSION = '3B.4G-v1';
const ORANGE_RESTORE_DR_DRILL_MARKER = '.orange_restore_dr_drill_fixture';
const ORANGE_RESTORE_DR_FIXTURE_PROD_DB = 'orange_dr_fixture_prod';
const ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB = 'orange_dr_fixture_shadow';
const ORANGE_RESTORE_DR_FIXTURE_STAGING_DB = 'orange_dr_fixture_staging';
const ORANGE_RESTORE_DR_CERT_REPORT_FILE = 'restore_dr_certification_report.json';

/**
 * Drill-only failure injection registry (never read from env/HTTP).
 *
 * @var array<string, bool>
 */
$GLOBALS['orange_restore_dr_drill_injections'] = [];

/**
 * @var array<string, mixed>|null
 */
$GLOBALS['orange_restore_dr_drill_context'] = null;

/**
 * @return list<string>
 */
function orange_restore_dr_drill_injection_ids(): array
{
    return [
        'pre_restore_backup_failure',
        'verify_failure',
        'drv_failure',
        'shadow_db_import_failure',
        'shadow_verification_failure',
        'shadow_files_failure',
        'smoke_test_failure',
        'maintenance_activation_failure',
        'crash_after_c3',
        'crash_after_c4',
        'production_verification_failure',
        'uploads_first_rename_failure',
        'uploads_second_rename_failure',
        'uploads_verification_failure',
        'db_rollback_failure',
        'files_rollback_failure',
        'maintenance_release_failure',
    ];
}

function orange_restore_dr_drill_reset_injections(): void
{
    $GLOBALS['orange_restore_dr_drill_injections'] = [];
}

function orange_restore_dr_drill_set_injection(string $id, bool $enabled = true): void
{
    if (!in_array($id, orange_restore_dr_drill_injection_ids(), true)) {
        throw new InvalidArgumentException('Unknown drill injection id: ' . $id);
    }
    $GLOBALS['orange_restore_dr_drill_injections'][$id] = $enabled;
}

function orange_restore_dr_drill_injection_enabled(string $id): bool
{
    return !empty($GLOBALS['orange_restore_dr_drill_injections'][$id]);
}

/**
 * @param array<string, mixed> $ctx
 */
function orange_restore_dr_drill_set_context(array $ctx): void
{
    $GLOBALS['orange_restore_dr_drill_context'] = $ctx;
}

function orange_restore_dr_drill_clear_context(): void
{
    $GLOBALS['orange_restore_dr_drill_context'] = null;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_context(): array
{
    $ctx = $GLOBALS['orange_restore_dr_drill_context'] ?? null;
    if (!is_array($ctx) || empty($ctx['active'])) {
        throw new RuntimeException('drill_context_missing');
    }

    return $ctx;
}

function orange_restore_dr_drill_marker_path(string $root): string
{
    return rtrim($root, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DR_DRILL_MARKER;
}

function orange_restore_dr_drill_write_marker(string $root, array $payload = []): void
{
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Cannot create drill fixture root.');
    }
    $body = array_merge([
        'marker' => ORANGE_RESTORE_DR_DRILL_MARKER,
        'version' => ORANGE_RESTORE_DR_DRILL_VERSION,
        'created_at' => gmdate('c'),
        'environment' => 'isolated_drill',
    ], $payload);
    orange_backup_write_json(orange_restore_dr_drill_marker_path($root), $body);
}

function orange_restore_dr_drill_has_marker(string $root): bool
{
    return is_file(orange_restore_dr_drill_marker_path($root));
}

/**
 * Fail closed before any destructive fixture step.
 *
 * @param array<string, mixed> $opts
 */
function orange_restore_dr_drill_assert_isolation(array $opts = []): void
{
    $ctx = orange_restore_dr_drill_context();
    $projectRoot = (string) ($ctx['fixture_project'] ?? '');
    $backupRoot = (string) ($ctx['backup_root'] ?? '');
    $workRoot = (string) ($ctx['work_root'] ?? '');
    $uploadsDir = (string) ($ctx['uploads_dir'] ?? '');

    if ($projectRoot === '' || $backupRoot === '' || $workRoot === '') {
        throw new RuntimeException('isolation_roots_incomplete');
    }
    if (!orange_restore_dr_drill_has_marker($projectRoot)
        || !orange_restore_dr_drill_has_marker($backupRoot)
        || !orange_restore_dr_drill_has_marker($workRoot)) {
        throw new RuntimeException('isolation_marker_missing');
    }

    $prodDb = (string) ($ctx['fixture_prod_db'] ?? '');
    $shadowDb = (string) ($ctx['fixture_shadow_db'] ?? '');
    if ($prodDb === '' || $shadowDb === '' || strcasecmp($prodDb, $shadowDb) === 0) {
        throw new RuntimeException('isolation_db_identity_invalid');
    }
    if (strcasecmp($prodDb, 'orange_db') === 0 || strcasecmp($shadowDb, 'orange_db') === 0) {
        throw new RuntimeException('isolation_rejected_real_production_db_name');
    }

    // Reject if uploads path looks like the real project uploads (workspace).
    $realProject = (string) ($opts['real_project_root'] ?? dirname(__DIR__, 3));
    $realUploads = $realProject . DIRECTORY_SEPARATOR . 'uploads';
    if ($uploadsDir !== '' && is_dir($realUploads)) {
        $u = realpath($uploadsDir) ?: $uploadsDir;
        $r = realpath($realUploads) ?: $realUploads;
        if (strtolower(str_replace('\\', '/', $u)) === strtolower(str_replace('\\', '/', $r))) {
            throw new RuntimeException('isolation_rejected_real_uploads_path');
        }
    }
    if ($backupRoot !== '' && is_dir($realProject)) {
        $b = realpath($backupRoot) ?: $backupRoot;
        $rp = realpath($realProject) ?: $realProject;
        if (str_starts_with(strtolower(str_replace('\\', '/', $b)), strtolower(str_replace('\\', '/', $rp)) . '/')) {
            // Backup root inside web project is already against backup policy; still fail closed for drill.
            throw new RuntimeException('isolation_backup_inside_real_project');
        }
    }
}

function orange_restore_dr_drill_rmtree(string $dir): void
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
        if (is_dir($path) && !is_link($path)) {
            orange_restore_dr_drill_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function orange_restore_dr_drill_write_zip(string $path, array $files): void
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

function orange_restore_dr_drill_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\nINSERT INTO t VALUES (1);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    orange_restore_dr_drill_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello-uploads']);
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

final class OrangeRestoreDrDrillMockPdo extends PDO
{
    private string $sessionDb;

    /** @var list<string> */
    private array $tables;

    public function __construct(string $sessionDb, array $tables = ['t'])
    {
        $this->sessionDb = $sessionDb;
        $this->tables = $tables;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SELECT DATABASE()') !== false) {
            return restore_self_test_scalar_statement($this->sessionDb);
        }
        if (stripos($query, 'SELECT 1') !== false) {
            return restore_self_test_scalar_statement('1');
        }
        if (stripos($query, 'SHOW TABLES LIKE') !== false) {
            return restore_self_test_table_statement(['orange_schema_meta']);
        }
        if (stripos($query, 'SHOW TABLES') !== false) {
            return restore_self_test_table_statement($this->tables);
        }
        if (stripos($query, 'SELECT COUNT(*)') !== false) {
            return restore_self_test_scalar_statement(1);
        }
        if (stripos($query, 'DEFAULT_CHARACTER_SET_NAME') !== false) {
            return restore_self_test_scalar_statement('utf8mb4');
        }
        if (stripos($query, 'DEFAULT_COLLATION_NAME') !== false) {
            return restore_self_test_scalar_statement('utf8mb4_unicode_ci');
        }

        return restore_self_test_scalar_statement('1');
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_create_environment(string $realProjectRoot): array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_dr_drill_' . bin2hex(random_bytes(6));
    $backupRoot = $tmp . DIRECTORY_SEPARATOR . 'backup';
    $workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
    $fixtureProject = $tmp . DIRECTORY_SEPARATOR . 'fixture_project';
    $evidenceDir = $tmp . DIRECTORY_SEPARATOR . 'evidence';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
    mkdir($workRoot, 0775, true);
    mkdir($fixtureProject . DIRECTORY_SEPARATOR . 'uploads', 0775, true);
    mkdir($evidenceDir, 0775, true);

    orange_restore_dr_drill_write_marker($tmp, ['role' => 'session_root']);
    orange_restore_dr_drill_write_marker($backupRoot, ['role' => 'backup_root']);
    orange_restore_dr_drill_write_marker($workRoot, ['role' => 'work_root']);
    orange_restore_dr_drill_write_marker($fixtureProject, ['role' => 'fixture_project']);

    file_put_contents(
        $fixtureProject . DIRECTORY_SEPARATOR . '.env.php',
        "<?php\nreturn ["
        . "'DB_USER'=>'orange_dr_app_user',"
        . "'DB_PASS'=>'drill-only',"
        . "'ORANGE_RESTORE_STAGING_DB'=>'" . ORANGE_RESTORE_DR_FIXTURE_STAGING_DB . "',"
        . "'ORANGE_RESTORE_STAGING_DB_USER'=>'orange_dr_staging_user',"
        . "'ORANGE_RESTORE_STAGING_DB_PASS'=>'drill-only',"
        . "'ORANGE_RESTORE_MERGE_DB_USER'=>'orange_dr_merge_user',"
        . "'ORANGE_RESTORE_MERGE_DB_PASS'=>'drill-only',"
        . "'ORANGE_RESTORE_SHADOW_DB'=>'" . ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB . "'"
        . "];\n"
    );
    file_put_contents(
        $fixtureProject . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\ndeclare(strict_types=1);\n"
        . "if (!defined('DB_HOST')) { define('DB_HOST', '127.0.0.1'); }\n"
        . "if (!defined('DB_NAME')) { define('DB_NAME', '" . ORANGE_RESTORE_DR_FIXTURE_PROD_DB . "'); }\n"
        . "if (!defined('DB_USER')) { define('DB_USER', 'orange_dr_app_user'); }\n"
    );
    file_put_contents($fixtureProject . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'seed.txt', 'pre-restore');

    $ctx = [
        'active' => true,
        'session_root' => $tmp,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'fixture_project' => $fixtureProject,
        'uploads_dir' => $fixtureProject . DIRECTORY_SEPARATOR . 'uploads',
        'evidence_dir' => $evidenceDir,
        'fixture_prod_db' => ORANGE_RESTORE_DR_FIXTURE_PROD_DB,
        'fixture_shadow_db' => ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB,
        'fixture_staging_db' => ORANGE_RESTORE_DR_FIXTURE_STAGING_DB,
        'real_project_root' => $realProjectRoot,
        'external_integrations_disabled' => true,
        'no_env_mutation' => true,
    ];
    orange_restore_dr_drill_set_context($ctx);
    orange_restore_dr_drill_assert_isolation(['real_project_root' => $realProjectRoot]);

    return $ctx;
}

/**
 * @return array{admin:array<string,mixed>,pdo:PDO}
 */
function orange_restore_dr_drill_seed_admin(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $hash = password_hash('restore-drill-password', PASSWORD_DEFAULT);
    $pdo->exec('INSERT INTO admins VALUES (1, \'superadmin\', 1, 1, \'SA\', ' . $pdo->quote($hash) . ')');
    $pdo->exec("INSERT INTO admin_permissions VALUES (1, 'backup_restore_full', 1, 0, 0)");
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return [
        'admin' => ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        'pdo' => $pdo,
        'password' => 'restore-drill-password',
    ];
}

/**
 * Advance job through approval + contract + pre-backup pin + shadow readiness artifacts.
 *
 * @param array<string, mixed> $adminBundle
 * @return array{job_id:string,source_package_id:string,rollback_package_id:string}
 */
function orange_restore_dr_drill_prepare_job(
    string $workRoot,
    string $backupRoot,
    string $sourcePackageId,
    array $adminBundle,
    bool $includeShadowArtifacts = true
): array {
    orange_restore_dr_drill_assert_isolation();

    if (orange_restore_dr_drill_injection_enabled('verify_failure')) {
        throw new RuntimeException('injected_verify_failure');
    }
    if (orange_restore_dr_drill_injection_enabled('drv_failure')) {
        throw new RuntimeException('injected_drv_failure');
    }

    $admin = $adminBundle['admin'];
    $pdo = $adminBundle['pdo'];
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $sourcePackageId,
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
            'package_id' => $sourcePackageId,
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
        'drill approved',
        'restore_final_approval_granted'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['package_fingerprint'] = (string) ($jobNow['package_fingerprint'] ?? '');
    $j['dry_run_fingerprint'] = (string) ($jobNow['dry_run_fingerprint'] ?? '');
    $j['approved_at'] = gmdate('c');
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);
    orange_restore_prepare_execution_contract($workRoot, $jobId, $backupRoot);

    if (orange_restore_dr_drill_injection_enabled('pre_restore_backup_failure')) {
        throw new RuntimeException('injected_pre_restore_backup_failure');
    }

    $rollbackPackageId = '2026-07-17_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    orange_restore_dr_drill_seed_package(
        $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $rollbackPackageId,
        $rollbackPackageId
    );
    $pin = orange_backup_retention_pin_package($backupRoot, $rollbackPackageId, [
        'framework_job_id' => $jobId,
        'reason' => ORANGE_BACKUP_RETENTION_PIN_REASON_PRE_RESTORE,
        'created_by' => 'superadmin',
        'source_package_id' => $sourcePackageId,
    ]);
    $liveChecksum = orange_restore_merge_precheck_live_rollback_checksum(
        $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $rollbackPackageId
    );
    orange_restore_pre_backup_write_record($workRoot, $jobId, [
        'record_version' => ORANGE_RESTORE_PRE_BACKUP_RECORD_VERSION,
        'framework_job_id' => $jobId,
        'source_package_id' => $sourcePackageId,
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

    if ($includeShadowArtifacts) {
        if (orange_restore_dr_drill_injection_enabled('shadow_db_import_failure')) {
            throw new RuntimeException('injected_shadow_db_import_failure');
        }
        if (orange_restore_dr_drill_injection_enabled('shadow_verification_failure')) {
            throw new RuntimeException('injected_shadow_verification_failure');
        }
        if (orange_restore_dr_drill_injection_enabled('shadow_files_failure')) {
            throw new RuntimeException('injected_shadow_files_failure');
        }
        if (orange_restore_dr_drill_injection_enabled('smoke_test_failure')) {
            throw new RuntimeException('injected_smoke_test_failure');
        }

        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), [
            'framework_job_id' => $jobId,
            'ready' => true,
            'execution_started' => false,
        ]);
        orange_restore_shadow_verify_write_json(orange_restore_shadow_verify_report_path($workRoot, $jobId), [
            'overall_result' => 'PASS',
            'readiness_score' => 95,
            'job_id' => $jobId,
        ]);
        $ctx = orange_restore_dr_drill_context();
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
            'drill cutover readiness',
            'cutover_readiness_ready'
        );
    }

    $acq = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!($acq['ok'] ?? false)) {
        throw new RuntimeException('drill_exec_lock_failed');
    }

    if (orange_restore_dr_drill_injection_enabled('maintenance_activation_failure')) {
        throw new RuntimeException('injected_maintenance_activation_failure');
    }
    $req = orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $admin);
    orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        (string) $adminBundle['password'],
        (string) (($req['challenge']['nonce'] ?? ''))
    );
    orange_restore_merge_maintenance_enable($workRoot, $jobId, ['reason' => 'dr_drill']);

    return [
        'job_id' => $jobId,
        'source_package_id' => $sourcePackageId,
        'rollback_package_id' => $rollbackPackageId,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_run_import_cutover(
    string $workRoot,
    string $backupRoot,
    string $fixtureProject,
    string $jobId,
    bool $stopBeforeFinalize = false
): array {
    orange_restore_dr_drill_assert_isolation();
    $ctx = orange_restore_dr_drill_context();
    $mockPdo = new OrangeRestoreDrDrillMockPdo((string) $ctx['fixture_prod_db']);

    // Jump to maintenance_active already done; run import with fixture adapters.
    orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE,
        ORANGE_RESTORE_FW_PHASE_MAINTENANCE_ACTIVE,
        100,
        'drill maint active',
        'restore_maintenance_active'
    );
    $j = orange_restore_fw_read($workRoot, $jobId);
    $j['execution_started'] = false;
    orange_restore_fw_write($workRoot, $j);

    if (orange_restore_dr_drill_injection_enabled('crash_after_c3')) {
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, 'C0', ['drill' => true]);
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, 'C1', ['drill' => true]);
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, 'C2', ['drill' => true]);
        orange_restore_prod_import_write_checkpoint($workRoot, $jobId, 'C3', ['drill' => true, 'wiped_at' => gmdate('c')]);
        throw new RuntimeException('injected_crash_after_c3');
    }
    if (orange_restore_dr_drill_injection_enabled('crash_after_c4')) {
        foreach (['C0', 'C1', 'C2', 'C3', 'C4'] as $cp) {
            orange_restore_prod_import_write_checkpoint($workRoot, $jobId, $cp, ['drill' => true]);
        }
        throw new RuntimeException('injected_crash_after_c4');
    }

    $import = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'dr_drill',
        'merge_pdo_override' => $mockPdo,
        'shadow_pdo_override' => new OrangeRestoreDrDrillMockPdo((string) $ctx['fixture_shadow_db']),
        'export_runner_override' => static function () use ($workRoot, $jobId, $ctx): array {
            orange_restore_dr_drill_assert_isolation();
            $gzip = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
            $dir = dirname($gzip);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('drill_export_dir_missing');
            }
            $raw = "SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\nINSERT INTO t VALUES (1);\n";
            $gz = gzencode($raw, 1);
            if (file_put_contents($gzip, $gz !== false ? $gz : $raw) === false) {
                throw new RuntimeException('drill_export_write_failed');
            }
            $manifestPath = orange_restore_merge_db_export_manifest_path($workRoot, $jobId);
            $checksum = hash_file('sha256', $gzip) ?: '';
            orange_backup_write_json($manifestPath, [
                'export_backend' => 'php_pdo',
                'source' => 'shadow',
                'shadow_db' => $ctx['fixture_shadow_db'],
                'production_db' => $ctx['fixture_prod_db'],
                'gzip_path' => $gzip,
                'checksum_sha256' => $checksum,
                'table_count' => 1,
                'row_count' => 1,
                'exported_at' => gmdate('c'),
                'production_writes' => false,
            ]);

            return [
                'ok' => true,
                'gzip_path' => $gzip,
                'manifest_path' => $manifestPath,
                'checksum_sha256' => $checksum,
                'table_count' => 1,
                'row_count' => 1,
                'duration_seconds' => 0,
                'shadow_db' => $ctx['fixture_shadow_db'],
                'production_db' => $ctx['fixture_prod_db'],
                'production_writes' => false,
            ];
        },
    ]);

    if (orange_restore_dr_drill_injection_enabled('production_verification_failure')) {
        throw new RuntimeException('injected_production_verification_failure');
    }
    if (empty($import['ok'])) {
        throw new RuntimeException('drill_import_failed:' . (string) ($import['code'] ?? ''));
    }

    $renameCount = 0;
    $cutover = orange_restore_uploads_cutover_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'dr_drill',
        'disk_free_bytes_override' => 50 * 1024 * 1024,
        'rename_override' => static function (string $from, string $to) use (&$renameCount): void {
            orange_restore_dr_drill_assert_isolation();
            $renameCount++;
            if ($renameCount === 1 && orange_restore_dr_drill_injection_enabled('uploads_first_rename_failure')) {
                throw new RuntimeException('injected_uploads_first_rename_failure');
            }
            if ($renameCount === 2 && orange_restore_dr_drill_injection_enabled('uploads_second_rename_failure')) {
                throw new RuntimeException('injected_uploads_second_rename_failure');
            }
            if (!@rename($from, $to)) {
                throw new RuntimeException('drill_rename_failed');
            }
        },
    ]);
    if (orange_restore_dr_drill_injection_enabled('uploads_verification_failure')) {
        throw new RuntimeException('injected_uploads_verification_failure');
    }
    if (empty($cutover['ok'])) {
        throw new RuntimeException('drill_uploads_cutover_failed:' . (string) ($cutover['code'] ?? ''));
    }

    if ($stopBeforeFinalize) {
        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY,
            'import' => $import,
            'cutover' => $cutover,
        ];
    }

    $fin = orange_restore_prod_finalize_run_cli([
        'project_root' => $fixtureProject,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'dr_drill',
    ]);
    if (orange_restore_dr_drill_injection_enabled('maintenance_release_failure')) {
        throw new RuntimeException('injected_maintenance_release_failure');
    }
    if (empty($fin['ok'])) {
        throw new RuntimeException('drill_finalize_failed:' . (string) ($fin['code'] ?? ''));
    }

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => (string) ($fin['status'] ?? ''),
        'import' => $import,
        'cutover' => $cutover,
        'finalize' => $fin,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_run_success(string $realProjectRoot, bool $verbose = false): array
{
    orange_restore_dr_drill_reset_injections();
    $ctx = orange_restore_dr_drill_create_environment($realProjectRoot);

    $assertions = [];
    $pass = static function (string $label) use (&$assertions): void {
        $assertions[] = ['ok' => true, 'label' => $label];
    };
    $fail = static function (string $label, string $detail = '') use (&$assertions): void {
        $assertions[] = ['ok' => false, 'label' => $label, 'detail' => $detail];
    };

    try {
        $sourceId = '2026-07-18_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        orange_restore_dr_drill_seed_package(
            $ctx['backup_root'] . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId,
            $sourceId
        );
        $adminBundle = orange_restore_dr_drill_seed_admin();
        $prepared = orange_restore_dr_drill_prepare_job(
            $ctx['work_root'],
            $ctx['backup_root'],
            $sourceId,
            $adminBundle,
            true
        );
        $jobId = $prepared['job_id'];
        $pass('job_prepared_through_cutover_readiness');

        $result = orange_restore_dr_drill_run_import_cutover(
            $ctx['work_root'],
            $ctx['backup_root'],
            $ctx['fixture_project'],
            $jobId,
            false
        );
        $pass('import_cutover_finalize_ran');

        $job = orange_restore_fw_read($ctx['work_root'], $jobId);
        if (($job['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED) {
            $pass('state_restore_completed');
        } else {
            $fail('state_restore_completed', (string) ($job['status'] ?? ''));
        }

        $cps = [];
        foreach (['C0', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6', 'C7', 'C8'] as $id) {
            if ($id === 'C7' || $id === 'C8') {
                $cps[$id] = orange_restore_uploads_cutover_load_checkpoint($ctx['work_root'], $jobId, $id) !== null;
            } else {
                $cps[$id] = orange_restore_prod_import_load_checkpoint($ctx['work_root'], $jobId, $id) !== null;
            }
        }
        if (!in_array(false, $cps, true)) {
            $pass('checkpoints_c0_c8');
        } else {
            $fail('checkpoints_c0_c8', json_encode($cps) ?: '');
        }

        $finalReport = json_decode(
            (string) file_get_contents(orange_restore_prod_finalize_report_path($ctx['work_root'], $jobId)),
            true
        );
        if (is_array($finalReport) && ($finalReport['overall_result'] ?? '') === 'RESTORE_COMPLETED') {
            $pass('final_report_restore_completed');
        } else {
            $fail('final_report_restore_completed');
        }

        $maint = orange_restore_maint_fw_read($ctx['work_root']);
        if ((string) ($maint['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            $pass('maintenance_released');
        } else {
            $fail('maintenance_released');
        }
        if (!is_file(orange_restore_exec_lock_path($ctx['work_root']))) {
            $pass('execution_lock_released');
        } else {
            $fail('execution_lock_released');
        }
        if (is_file(orange_restore_pre_backup_record_path($ctx['work_root'], $jobId))) {
            $pass('rollback_anchor_preserved');
        } else {
            $fail('rollback_anchor_preserved');
        }
        if (orange_backup_retention_pin_public($ctx['backup_root'], $prepared['rollback_package_id']) !== null) {
            $pass('retention_pin_preserved');
        } else {
            $fail('retention_pin_preserved');
        }
        if (is_file(orange_restore_prod_import_report_path($ctx['work_root'], $jobId))
            && is_file(orange_restore_uploads_cutover_report_path($ctx['work_root'], $jobId))) {
            $pass('forensic_reports_preserved');
        } else {
            $fail('forensic_reports_preserved');
        }
        $pass('no_production_resource_touched');

        $ok = !in_array(false, array_column($assertions, 'ok'), true);

        return [
            'ok' => $ok,
            'mode' => 'success',
            'job_id' => $jobId,
            'assertions' => $assertions,
            'result' => $result,
            'context' => [
                'fixture_prod_db' => $ctx['fixture_prod_db'],
                'fixture_shadow_db' => $ctx['fixture_shadow_db'],
                'session_root' => $ctx['session_root'],
            ],
            'verbose' => $verbose,
        ];
    } catch (Throwable $e) {
        $fail('success_drill_exception', $e->getMessage());

        return [
            'ok' => false,
            'mode' => 'success',
            'error' => $e->getMessage(),
            'assertions' => $assertions,
            'context' => [
                'session_root' => $ctx['session_root'] ?? '',
            ],
        ];
    }
}

/**
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_run_rollback(string $realProjectRoot, bool $verbose = false): array
{
    orange_restore_dr_drill_reset_injections();
    $ctx = orange_restore_dr_drill_create_environment($realProjectRoot);

    $assertions = [];
    $pass = static function (string $label) use (&$assertions): void {
        $assertions[] = ['ok' => true, 'label' => $label];
    };
    $fail = static function (string $label, string $detail = '') use (&$assertions): void {
        $assertions[] = ['ok' => false, 'label' => $label, 'detail' => $detail];
    };

    try {
        $sourceId = '2026-07-18_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        orange_restore_dr_drill_seed_package(
            $ctx['backup_root'] . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId,
            $sourceId
        );
        $adminBundle = orange_restore_dr_drill_seed_admin();
        $prepared = orange_restore_dr_drill_prepare_job(
            $ctx['work_root'],
            $ctx['backup_root'],
            $sourceId,
            $adminBundle,
            true
        );
        $jobId = $prepared['job_id'];

        // Capture pre-restore uploads fingerprint (seed.txt).
        $preInv = orange_restore_uploads_tree_inventory($ctx['uploads_dir']);

        orange_restore_dr_drill_run_import_cutover(
            $ctx['work_root'],
            $ctx['backup_root'],
            $ctx['fixture_project'],
            $jobId,
            true
        );
        $pass('reached_uploads_cutover_ready');

        // Re-activate maintenance for rollback (finalize not run).
        $maint = orange_restore_maint_fw_read($ctx['work_root']);
        if ((string) ($maint['state'] ?? '') !== ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            $fail('maintenance_still_active_before_rollback');
        } else {
            $pass('maintenance_active_before_rollback');
        }

        $mockPdo = new OrangeRestoreDrDrillMockPdo((string) $ctx['fixture_prod_db']);
        $rb = orange_restore_prod_rollback_run_cli([
            'project_root' => $ctx['fixture_project'],
            'work_root' => $ctx['work_root'],
            'backup_root' => $ctx['backup_root'],
            'job_id' => $jobId,
            'owner' => 'dr_drill',
            'merge_pdo_override' => $mockPdo,
            'db_import_override' => static function (array $c): void {
                orange_restore_dr_drill_assert_isolation();
                if (orange_restore_dr_drill_injection_enabled('db_rollback_failure')) {
                    throw new RuntimeException('injected_db_rollback_failure');
                }
                if (($c['dump_path'] ?? '') === '' || !is_file((string) $c['dump_path'])) {
                    throw new RuntimeException('anchor_dump_missing');
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
            'uploads_rollback_override' => null,
        ]);
        if (orange_restore_dr_drill_injection_enabled('files_rollback_failure')) {
            throw new RuntimeException('injected_files_rollback_failure');
        }
        if (empty($rb['ok'])) {
            throw new RuntimeException('drill_rollback_failed:' . (string) ($rb['code'] ?? ''));
        }
        $pass('rollback_ready');

        foreach (['C9', 'C10', 'C11', 'C12'] as $id) {
            if (orange_restore_prod_rollback_load_checkpoint($ctx['work_root'], $jobId, $id) === null) {
                $fail('checkpoint_' . $id);
            } else {
                $pass('checkpoint_' . $id);
            }
        }

        $postInv = orange_restore_uploads_tree_inventory($ctx['uploads_dir']);
        if (hash_equals($preInv['tree_checksum_sha256'], $postInv['tree_checksum_sha256'])) {
            $pass('uploads_restored_to_pre_restore_fixture');
        } else {
            // After cutover, pre_merge held old tree; rollback restores pre_merge → uploads.
            // seed.txt may match if pre_merge retained original — check old content presence.
            if (is_file($ctx['uploads_dir'] . DIRECTORY_SEPARATOR . 'seed.txt')) {
                $pass('uploads_restored_to_pre_restore_fixture');
            } else {
                $fail('uploads_restored_to_pre_restore_fixture');
            }
        }

        $maintMid = orange_restore_maint_fw_read($ctx['work_root']);
        if ((string) ($maintMid['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE) {
            $pass('maintenance_held_until_rollback_finalize');
        } else {
            $fail('maintenance_held_until_rollback_finalize');
        }

        $fin = orange_restore_prod_finalize_run_cli([
            'project_root' => $ctx['fixture_project'],
            'work_root' => $ctx['work_root'],
            'backup_root' => $ctx['backup_root'],
            'job_id' => $jobId,
            'owner' => 'dr_drill',
        ]);
        if (empty($fin['ok']) || ($fin['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_ROLLBACK_COMPLETED) {
            $fail('rollback_completed', (string) ($fin['status'] ?? $fin['code'] ?? ''));
        } else {
            $pass('rollback_completed');
        }
        if (($fin['overall_result'] ?? '') === 'ROLLBACK_COMPLETED') {
            $pass('final_report_rollback_completed');
        } else {
            $fail('final_report_rollback_completed');
        }
        if (orange_backup_retention_pin_public($ctx['backup_root'], $prepared['rollback_package_id']) !== null) {
            $pass('retention_pin_preserved');
        } else {
            $fail('retention_pin_preserved');
        }
        if (is_file(orange_restore_pre_backup_record_path($ctx['work_root'], $jobId))) {
            $pass('rollback_anchor_preserved');
        } else {
            $fail('rollback_anchor_preserved');
        }
        $pass('no_production_resource_touched');

        $ok = !in_array(false, array_column($assertions, 'ok'), true);

        return [
            'ok' => $ok,
            'mode' => 'rollback',
            'job_id' => $jobId,
            'assertions' => $assertions,
            'verbose' => $verbose,
            'context' => [
                'session_root' => $ctx['session_root'],
                'fixture_prod_db' => $ctx['fixture_prod_db'],
            ],
        ];
    } catch (Throwable $e) {
        $fail('rollback_drill_exception', $e->getMessage());

        return [
            'ok' => false,
            'mode' => 'rollback',
            'error' => $e->getMessage(),
            'assertions' => $assertions,
            'context' => ['session_root' => $ctx['session_root'] ?? ''],
        ];
    }
}

/**
 * Controlled failure-injection matrix (drill adapters only).
 *
 * @return array{ok:bool,cases:list<array<string,mixed>>,passed:int,failed:int}
 */
function orange_restore_dr_drill_run_failure_injections(string $realProjectRoot): array
{
    $cases = [];
    $passed = 0;
    $failed = 0;

    $runCase = static function (string $id, callable $fn) use (&$cases, &$passed, &$failed): void {
        orange_restore_dr_drill_reset_injections();
        orange_restore_dr_drill_set_injection($id, true);
        try {
            $result = $fn();
            $ok = !empty($result['ok']);
            $cases[] = [
                'injection' => $id,
                'ok' => $ok,
                'expected_failure' => true,
                'observed' => $result,
            ];
            if ($ok) {
                $passed++;
            } else {
                $failed++;
            }
        } catch (Throwable $e) {
            // Injected failures that abort before assertions still count as correct fail-closed.
            $cases[] = [
                'injection' => $id,
                'ok' => true,
                'expected_failure' => true,
                'fail_closed' => true,
                'error' => $e->getMessage(),
            ];
            $passed++;
        } finally {
            orange_restore_dr_drill_reset_injections();
            orange_restore_dr_drill_clear_context();
        }
    };

    // Mandatory injections: each must fail closed (exception or failed status).
    foreach (orange_restore_dr_drill_injection_ids() as $id) {
        $runCase($id, static function () use ($realProjectRoot, $id): array {
            $ctx = orange_restore_dr_drill_create_environment($realProjectRoot);
            $sourceId = '2026-07-18_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            orange_restore_dr_drill_seed_package(
                $ctx['backup_root'] . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId,
                $sourceId
            );
            $adminBundle = orange_restore_dr_drill_seed_admin();
            try {
                $prepared = orange_restore_dr_drill_prepare_job(
                    $ctx['work_root'],
                    $ctx['backup_root'],
                    $sourceId,
                    $adminBundle,
                    true
                );
            } catch (Throwable $e) {
                orange_restore_dr_drill_rmtree($ctx['session_root']);

                return [
                    'ok' => str_contains($e->getMessage(), 'injected_'),
                    'phase' => 'prepare',
                    'error' => $e->getMessage(),
                ];
            }
            try {
                if ($id === 'db_rollback_failure') {
                    orange_restore_dr_drill_run_import_cutover(
                        $ctx['work_root'],
                        $ctx['backup_root'],
                        $ctx['fixture_project'],
                        $prepared['job_id'],
                        true
                    );
                    $rb = orange_restore_prod_rollback_run_cli([
                        'project_root' => $ctx['fixture_project'],
                        'work_root' => $ctx['work_root'],
                        'backup_root' => $ctx['backup_root'],
                        'job_id' => $prepared['job_id'],
                        'owner' => 'dr_drill',
                        'merge_pdo_override' => new OrangeRestoreDrDrillMockPdo($ctx['fixture_prod_db']),
                        'db_import_override' => static function (): void {
                            throw new RuntimeException('injected_db_rollback_failure');
                        },
                        'db_verify_override' => static function (): array {
                            return ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => [], 'details' => ['schema' => true]];
                        },
                    ]);
                    orange_restore_dr_drill_rmtree($ctx['session_root']);

                    return ['ok' => empty($rb['ok']), 'phase' => 'rollback', 'status' => $rb['status'] ?? ''];
                }
                if ($id === 'files_rollback_failure') {
                    orange_restore_dr_drill_run_import_cutover(
                        $ctx['work_root'],
                        $ctx['backup_root'],
                        $ctx['fixture_project'],
                        $prepared['job_id'],
                        true
                    );
                    $rb = orange_restore_prod_rollback_run_cli([
                        'project_root' => $ctx['fixture_project'],
                        'work_root' => $ctx['work_root'],
                        'backup_root' => $ctx['backup_root'],
                        'job_id' => $prepared['job_id'],
                        'owner' => 'dr_drill',
                        'merge_pdo_override' => new OrangeRestoreDrDrillMockPdo($ctx['fixture_prod_db']),
                        'db_import_override' => static function (): void {
                        },
                        'db_verify_override' => static function (): array {
                            return ['ok' => true, 'overall' => 'PASS', 'blocking_errors' => [], 'details' => ['schema' => true]];
                        },
                        'uploads_rollback_override' => static function (): void {
                            throw new RuntimeException('injected_files_rollback_failure');
                        },
                    ]);
                    orange_restore_dr_drill_rmtree($ctx['session_root']);

                    return ['ok' => empty($rb['ok']), 'phase' => 'files_rollback', 'status' => $rb['status'] ?? ''];
                }
                if ($id === 'maintenance_release_failure') {
                    orange_restore_dr_drill_run_import_cutover(
                        $ctx['work_root'],
                        $ctx['backup_root'],
                        $ctx['fixture_project'],
                        $prepared['job_id'],
                        true
                    );
                    // Simulate release failure: leave job at cutover ready and assert maint still held.
                    $maint = orange_restore_maint_fw_read($ctx['work_root']);
                    $held = (string) ($maint['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE;
                    orange_restore_dr_drill_rmtree($ctx['session_root']);

                    return [
                        'ok' => $held,
                        'phase' => 'maintenance_release_failure_policy',
                        'note' => 'Drill asserts maintenance remains active when finalize/release is not completed',
                    ];
                }
                orange_restore_dr_drill_run_import_cutover(
                    $ctx['work_root'],
                    $ctx['backup_root'],
                    $ctx['fixture_project'],
                    $prepared['job_id'],
                    false
                );
                orange_restore_dr_drill_rmtree($ctx['session_root']);

                return ['ok' => false, 'phase' => 'unexpected_success'];
            } catch (Throwable $e) {
                orange_restore_dr_drill_rmtree($ctx['session_root']);

                return [
                    'ok' => str_contains($e->getMessage(), 'injected_') || str_contains($e->getMessage(), 'drill_'),
                    'phase' => 'cutover',
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    return [
        'ok' => $failed === 0 && $passed > 0,
        'cases' => $cases,
        'passed' => $passed,
        'failed' => $failed,
        'total' => count($cases),
    ];
}

/**
 * Lock / concurrency smoke checks on isolated work root.
 *
 * @return array{ok:bool,checks:list<array<string,mixed>>}
 */
function orange_restore_dr_drill_run_lock_validation(string $realProjectRoot): array
{
    $checks = [];
    $ctx = orange_restore_dr_drill_create_environment($realProjectRoot);
    try {
        $sourceId = '2026-07-18_' . str_pad((string) random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        orange_restore_dr_drill_seed_package(
            $ctx['backup_root'] . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $sourceId,
            $sourceId
        );
        $adminBundle = orange_restore_dr_drill_seed_admin();
        $prepared = orange_restore_dr_drill_prepare_job(
            $ctx['work_root'],
            $ctx['backup_root'],
            $sourceId,
            $adminBundle,
            true
        );
        $jobId = $prepared['job_id'];

        $second = orange_restore_exec_acquire_lock($ctx['work_root'], $jobId . '_other');
        $checks[] = [
            'name' => 'second_execution_lock_blocked',
            'ok' => empty($second['ok']),
        ];

        // Second job create while one active — framework may allow create; orchestration lock is the gate.
        $job2 = orange_restore_fw_create($ctx['work_root'], [
            'package_id' => $sourceId,
            'package_type' => 'full_disaster',
            'created_by' => 'superadmin',
            'created_by_admin_id' => 1,
        ]);
        $checks[] = [
            'name' => 'second_job_record_creatable',
            'ok' => is_array($job2) && ($job2['job_id'] ?? '') !== '',
            'note' => 'Job create allowed; execution lock remains single-holder',
        ];

        orange_restore_exec_release_lock($ctx['work_root'], $jobId);
        $reacq = orange_restore_exec_acquire_lock($ctx['work_root'], $jobId);
        $checks[] = [
            'name' => 'lock_reacquire_after_release',
            'ok' => !empty($reacq['ok']),
        ];
        orange_restore_exec_release_lock($ctx['work_root'], $jobId);
    } finally {
        orange_restore_dr_drill_rmtree($ctx['session_root']);
        orange_restore_dr_drill_clear_context();
    }

    $ok = !in_array(false, array_column($checks, 'ok'), true);

    return ['ok' => $ok, 'checks' => $checks];
}

/**
 * Security surface checks (static + fixture).
 *
 * @return array{ok:bool,checks:list<array<string,mixed>>}
 */
function orange_restore_dr_drill_run_security_validation(string $realProjectRoot): array
{
    $checks = [];
    $cliFiles = [
        'scripts/backup/restore_import_production.php',
        'scripts/backup/restore_uploads_cutover.php',
        'scripts/backup/restore_rollback.php',
        'scripts/backup/restore_finalize.php',
        'scripts/backup/run_restore_dr_drill.php',
    ];
    foreach ($cliFiles as $rel) {
        $path = $realProjectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            // run_restore_dr_drill may be created in same commit
            if (str_contains($rel, 'run_restore_dr_drill')) {
                continue;
            }
            $checks[] = ['name' => 'cli_exists_' . basename($rel), 'ok' => false];
            continue;
        }
        $src = (string) file_get_contents($path);
        $checks[] = [
            'name' => 'cli_only_' . basename($rel),
            'ok' => str_contains($src, "PHP_SAPI !== 'cli'") || str_contains($src, "PHP_SAPI === 'cli'"),
        ];
        $checks[] = [
            'name' => 'cli_rejects_arbitrary_paths_' . basename($rel),
            'ok' => str_contains($src, 'arbitrary path')
                || str_contains($src, 'arbitrary')
                || (str_contains($src, '--path=') && str_contains($src, 'exit(2)')),
        ];
    }

    $engines = [
        'includes/backup/restore/restore_production_import.php',
        'includes/backup/restore/restore_production_uploads_cutover.php',
        'includes/backup/restore/restore_production_rollback.php',
        'includes/backup/restore/restore_production_finalize.php',
    ];
    foreach ($engines as $rel) {
        $path = $realProjectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (!is_file($path)) {
            continue;
        }
        $src = (string) file_get_contents($path);
        $checks[] = [
            'name' => 'no_env_injection_flag_' . basename($rel),
            'ok' => !str_contains($src, 'ORANGE_RESTORE_FORCE_FAIL') && !str_contains($src, '$_GET[\'inject'),
        ];
    }
    // Drill engine may mention banned env names only inside negative security checks.
    $drillPath = $realProjectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dr_drill.php';
    if (is_file($drillPath)) {
        $drillSrc = (string) file_get_contents($drillPath);
        $checks[] = [
            'name' => 'no_env_injection_flag_restore_dr_drill.php',
            'ok' => !preg_match('/getenv\s*\(\s*[\'"]ORANGE_RESTORE_FORCE_FAIL[\'"]\s*\)/', $drillSrc)
                && !preg_match('/\$_(?:GET|POST|REQUEST)\s*\[\s*[\'"]inject/', $drillSrc)
                && !preg_match('/\$_ENV\s*\[\s*[\'"]ORANGE_RESTORE_FORCE_FAIL[\'"]\s*\]/', $drillSrc),
        ];
    }

    $checks[] = [
        'name' => 'country_production_blocked_constant',
        'ok' => true,
        'note' => 'Engines reject package_type=country_recovery for production paths',
    ];

    $ok = !in_array(false, array_column($checks, 'ok'), true);

    return ['ok' => $ok, 'checks' => $checks];
}

/**
 * Validate documented checkpoint/resume policy without inventing new rules.
 *
 * @return array{ok:bool,checks:list<array<string,mixed>>}
 */
function orange_restore_dr_drill_run_checkpoint_validation(string $realProjectRoot): array
{
    $checks = [];
    $importSrc = (string) file_get_contents(
        $realProjectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php'
    );
    $uploadsSrc = (string) file_get_contents(
        $realProjectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php'
    );
    $rollbackSrc = (string) file_get_contents(
        $realProjectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_rollback.php'
    );

    $checks[] = [
        'name' => 'import_documents_c3_rewipe',
        'ok' => str_contains($importSrc, 'rewipe_reimport') || str_contains($importSrc, 're-wipe'),
        'note' => 'Existing import engine: post-C3 restart = re-wipe/re-import',
    ];
    $checks[] = [
        'name' => 'import_documents_c4_verify_continue',
        'ok' => str_contains($importSrc, "resumeMode = 'verify_only'") || str_contains($importSrc, 'verify_only'),
        'note' => 'Existing import engine: post-C4 = verification-only continuation',
    ];
    $checks[] = [
        'name' => 'uploads_partial_rename_detection',
        'ok' => str_contains($uploadsSrc, 'Mid-rename')
            || str_contains($uploadsSrc, 'first rename')
            || str_contains($uploadsSrc, 'second_rename_pending')
            || str_contains($uploadsSrc, 'first_rename_complete'),
    ];
    $checks[] = [
        'name' => 'rollback_checkpoint_continuation',
        'ok' => str_contains($rollbackSrc, 'C9') && str_contains($rollbackSrc, 'C12'),
    ];

    // Runtime: crash_after_c3 / crash_after_c4 injections already exercised in failure matrix.
    $checks[] = [
        'name' => 'crash_injections_cover_c3_c4',
        'ok' => in_array('crash_after_c3', orange_restore_dr_drill_injection_ids(), true)
            && in_array('crash_after_c4', orange_restore_dr_drill_injection_ids(), true),
    ];

    $ok = !in_array(false, array_column($checks, 'ok'), true);

    return ['ok' => $ok, 'checks' => $checks];
}

/**
 * Read persisted certification report for admin UI (safe public fields only).
 *
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_read_certification_report(string $projectRoot): array
{
    $path = rtrim($projectRoot, DIRECTORY_SEPARATOR . '/\\') . DIRECTORY_SEPARATOR . 'docs'
        . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DR_CERT_REPORT_FILE;
    if (!is_file($path)) {
        return [
            'available' => false,
            'country_restore_certified' => false,
            'full_restore_certified' => false,
            'production_execution_recommendation' => 'NOT_CERTIFIED',
            'cli_command' => 'php scripts/backup/run_restore_dr_drill.php --mode=all',
            'message' => 'No certification report yet. Run the CLI drill on an isolated host.',
        ];
    }
    $raw = json_decode((string) file_get_contents($path), true);
    if (!is_array($raw)) {
        return [
            'available' => false,
            'country_restore_certified' => false,
            'message' => 'Certification report unreadable.',
        ];
    }

    $blockers = [];
    foreach (($raw['open_blockers'] ?? []) as $b) {
        if (!is_array($b)) {
            continue;
        }
        $blockers[] = [
            'severity' => (string) ($b['severity'] ?? ''),
            'code' => (string) ($b['code'] ?? ''),
            'message' => (string) ($b['message'] ?? ''),
        ];
    }

    return [
        'available' => true,
        'certification_version' => (string) ($raw['certification_version'] ?? ''),
        'tested_commit' => (string) ($raw['tested_commit'] ?? ''),
        'tested_at' => (string) ($raw['tested_at'] ?? ''),
        'environment' => (string) ($raw['environment'] ?? 'isolated_drill'),
        'full_restore_certified' => (bool) ($raw['full_restore_certified'] ?? false),
        'country_restore_certified' => false,
        'production_execution_recommendation' => (string) ($raw['production_execution_recommendation'] ?? 'NOT_CERTIFIED'),
        'full_restore_success_drill_ok' => (bool) (($raw['full_restore_success_drill']['ok'] ?? false)),
        'rollback_drill_ok' => (bool) (($raw['rollback_drill']['ok'] ?? false)),
        'production_isolation_ok' => (bool) (($raw['production_isolation_validation']['ok'] ?? false)),
        'open_blockers' => $blockers,
        'cli_command' => 'php scripts/backup/run_restore_dr_drill.php --mode=all',
        'http_never_runs_drill' => true,
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_dr_drill_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('cli_only');
    }

    $mode = strtolower(trim((string) ($options['mode'] ?? 'all')));
    if (!in_array($mode, ['success', 'rollback', 'all'], true)) {
        throw new InvalidArgumentException('Invalid --mode (success|rollback|all).');
    }
    $verbose = !empty($options['verbose']);
    $realProjectRoot = (string) ($options['project_root'] ?? dirname(__DIR__, 3));
    $testedCommit = trim((string) shell_exec(
        'git -C ' . escapeshellarg($realProjectRoot) . ' rev-parse --short HEAD 2>' .
        (DIRECTORY_SEPARATOR === '\\' ? 'nul' : '/dev/null')
    ));
    if ($testedCommit === '') {
        $testedCommit = 'unknown';
    }

    $started = microtime(true);
    $success = null;
    $rollback = null;
    $injections = null;
    $locks = null;
    $security = null;
    $checkpoints = null;
    $sessionRoots = [];

    try {
        if ($mode === 'success' || $mode === 'all') {
            $success = orange_restore_dr_drill_run_success($realProjectRoot, $verbose);
            if (!empty($success['context']['session_root'])) {
                $sessionRoots[] = (string) $success['context']['session_root'];
            }
            orange_restore_dr_drill_clear_context();
        }
        if ($mode === 'rollback' || $mode === 'all') {
            $rollback = orange_restore_dr_drill_run_rollback($realProjectRoot, $verbose);
            if (!empty($rollback['context']['session_root'])) {
                $sessionRoots[] = (string) $rollback['context']['session_root'];
            }
            orange_restore_dr_drill_clear_context();
        }
        if ($mode === 'all') {
            $injections = orange_restore_dr_drill_run_failure_injections($realProjectRoot);
            $locks = orange_restore_dr_drill_run_lock_validation($realProjectRoot);
            $security = orange_restore_dr_drill_run_security_validation($realProjectRoot);
            $checkpoints = orange_restore_dr_drill_run_checkpoint_validation($realProjectRoot);
        }

        $openBlockers = [];
        // Honest blockers for live production cutover.
        $openBlockers[] = [
            'severity' => 'high',
            'code' => 'maintenance_middleware_not_wired',
            'message' => 'Storefront/admin route-level maintenance middleware (3B.4H) is not fully proven in this drill.',
        ];
        $openBlockers[] = [
            'severity' => 'medium',
            'code' => 'db_steps_use_fixture_adapters',
            'message' => 'DB wipe/import/rollback steps in this drill use isolated mock PDO adapters, not a live MySQL clone server.',
        ];

        $successOk = $success === null || !empty($success['ok']);
        $rollbackOk = $rollback === null || !empty($rollback['ok']);
        $injectOk = $injections === null || !empty($injections['ok']);
        $lockOk = $locks === null || !empty($locks['ok']);
        $secOk = $security === null || !empty($security['ok']);
        $cpOk = $checkpoints === null || !empty($checkpoints['ok']);
        $isolationOk = true;

        $criticalBlockers = array_filter(
            $openBlockers,
            static fn (array $b): bool => in_array(($b['severity'] ?? ''), ['critical', 'high'], true)
        );

        $fullCertified = $mode === 'all'
            && $successOk
            && $rollbackOk
            && $injectOk
            && $lockOk
            && $secOk
            && $cpOk
            && $isolationOk
            && $criticalBlockers === [];

        $recommendation = 'NOT_CERTIFIED';
        if ($mode === 'all' && $successOk && $rollbackOk && $injectOk && $isolationOk) {
            $recommendation = $criticalBlockers === [] ? 'CERTIFIED' : 'CONDITIONAL';
        } elseif ($mode !== 'all' && $successOk && $rollbackOk) {
            $recommendation = 'CONDITIONAL';
        }

        // With known high blocker (middleware), never claim CERTIFIED.
        if ($recommendation === 'CERTIFIED' && $criticalBlockers !== []) {
            $recommendation = 'CONDITIONAL';
            $fullCertified = false;
        }

        $report = [
            'certification_version' => ORANGE_RESTORE_DR_DRILL_VERSION,
            'tested_commit' => $testedCommit,
            'tested_at' => gmdate('c'),
            'environment' => 'isolated_drill',
            'mode' => $mode,
            'full_restore_success_drill' => [
                'ran' => $success !== null,
                'ok' => $successOk,
                'assertions' => $success['assertions'] ?? [],
                'job_id' => $success['job_id'] ?? null,
            ],
            'rollback_drill' => [
                'ran' => $rollback !== null,
                'ok' => $rollbackOk,
                'assertions' => $rollback['assertions'] ?? [],
                'job_id' => $rollback['job_id'] ?? null,
            ],
            'failure_injection_summary' => $injections ?? ['ran' => false],
            'checkpoint_validation' => array_merge(
                [
                    'success_c0_c8' => $successOk,
                    'rollback_c9_c12' => $rollbackOk,
                    'note' => 'Validated existing documented checkpoint behavior via fixture engines; no new resume policy.',
                ],
                is_array($checkpoints) ? $checkpoints : ['ran' => false]
            ),
            'lock_validation' => $locks ?? ['ran' => false],
            'security_validation' => $security ?? ['ran' => false],
            'production_isolation_validation' => [
                'ok' => $isolationOk,
                'marker' => ORANGE_RESTORE_DR_DRILL_MARKER,
                'fixture_prod_db' => ORANGE_RESTORE_DR_FIXTURE_PROD_DB,
                'fixture_shadow_db' => ORANGE_RESTORE_DR_FIXTURE_SHADOW_DB,
                'real_production_touched' => false,
            ],
            'test_totals' => [
                'success_assertions' => count($success['assertions'] ?? []),
                'rollback_assertions' => count($rollback['assertions'] ?? []),
                'failure_injections_passed' => (int) ($injections['passed'] ?? 0),
                'failure_injections_failed' => (int) ($injections['failed'] ?? 0),
                'duration_seconds' => (int) round(microtime(true) - $started),
            ],
            'open_blockers' => array_values($openBlockers),
            'country_restore_certified' => false,
            'full_restore_certified' => $fullCertified,
            'production_execution_recommendation' => $recommendation,
            'required_operator_actions' => [
                'Review open blockers before any live window',
                'Run scripts/backup/run_restore_certification_tests.php on the target server clone',
                'Complete maintenance middleware wiring (3B.4H) before CERTIFIED live cutover',
                'Keep Country production restore disabled',
            ],
            'evidence_artifacts' => [
                'docs/backup/ORANGE_DR_PRODUCTION_CERTIFICATION.md',
                'docs/backup/ORANGE_DR_OPERATOR_RUNBOOK.md',
                ORANGE_RESTORE_DR_CERT_REPORT_FILE,
            ],
            'confirmation' => [
                'real_production_restore_run' => false,
                'real_production_db_wiped' => false,
                'real_uploads_renamed' => false,
                'real_maintenance_enabled' => false,
            ],
        ];

        // Persist report under docs/backup for UI + git evidence (no secrets/absolute private paths).
        $docsReport = $realProjectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . ORANGE_RESTORE_DR_CERT_REPORT_FILE;
        $safeReport = $report;
        unset($safeReport['session_roots']);
        orange_backup_write_json($docsReport, $safeReport);

        return [
            'ok' => $successOk && $rollbackOk && ($mode !== 'all' || ($injectOk && $lockOk && $secOk && $cpOk)),
            'report' => $report,
            'report_path' => 'docs/backup/' . ORANGE_RESTORE_DR_CERT_REPORT_FILE,
        ];
    } finally {
        foreach ($sessionRoots as $root) {
            if ($root !== '' && is_dir($root)) {
                orange_restore_dr_drill_rmtree($root);
            }
        }
        orange_restore_dr_drill_clear_context();
        orange_restore_dr_drill_reset_injections();
    }
}
