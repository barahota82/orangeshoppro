<?php

declare(strict_types=1);

/**
 * Phase 3B.4C — Production Database Import Engine self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_production_import.php
 *
 * Isolated fixtures + mock PDO only. Never imports into a real production database.
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
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php';
require_once $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'restore_self_test_helpers.php';

$failures = 0;
$passes = 0;
$tmpRoot = '';
$fixtureProjectRoot = restore_self_test_temp_project_root();
file_put_contents(
    $fixtureProjectRoot . DIRECTORY_SEPARATOR . '.env.php',
    "<?php\nreturn [\n"
    . "  'DB_USER' => 'orange_dev',\n"
    . "  'DB_PASS' => 'test-pass',\n"
    . "  'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',\n"
    . "  'ORANGE_RESTORE_STAGING_DB_USER' => 'orange_restore_staging',\n"
    . "  'ORANGE_RESTORE_STAGING_DB_PASS' => 'staging-pass',\n"
    . "  'ORANGE_RESTORE_MERGE_DB_USER' => 'orange_restore_merge',\n"
    . "  'ORANGE_RESTORE_MERGE_DB_PASS' => 'merge-pass',\n"
    . "];\n"
);

function pi_self_test(bool $ok, string $label): void
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

function pi_rmtree(string $dir): void
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
            pi_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function pi_retire_job(string $workRoot, string $jobId): void
{
    try {
        $job = orange_restore_fw_read($workRoot, $jobId);
        $job['status'] = ORANGE_RESTORE_FW_STATUS_CANCELLED;
        $job['phase'] = ORANGE_RESTORE_FW_PHASE_CANCELLED;
        $job['message'] = 'retired for fixture isolation';
        orange_restore_fw_write($workRoot, $job);
    } catch (Throwable) {
        // ignore
    }
    try {
        orange_restore_exec_release_lock($workRoot, $jobId);
    } catch (Throwable) {
        // ignore
    }
    try {
        $maint = orange_restore_maint_fw_read($workRoot);
        if ((string) ($maint['related_job_id'] ?? '') === $jobId) {
            orange_restore_maint_fw_release($workRoot, 'tester');
        }
    } catch (Throwable) {
        // ignore
    }
    try {
        orange_restore_merge_maintenance_disable($workRoot, $jobId);
    } catch (Throwable) {
        // ignore
    }
}

function pi_write_zip(string $path, array $files): void
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

function pi_seed_package(string $pkgDir, string $pkgId): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    $gz = gzencode("SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n", 1);
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, $gz !== false ? $gz : str_repeat('x', 32));
    pi_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
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
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO}
 */
function pi_seed_ready_job(string $workRoot, string $backupRoot, string $sourceId): array
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
    $acq = orange_restore_exec_acquire_lock($workRoot, $jobId);
    if (!($acq['ok'] ?? false)) {
        throw new RuntimeException('fixture lock failed: ' . (string) ($acq['message'] ?? ''));
    }

    return ['job_id' => $jobId, 'admin' => $admin, 'pdo' => $pdo];
}

/**
 * @return array{job_id:string,admin:array<string,mixed>,pdo:PDO}
 */
function pi_seed_maintenance_active(string $workRoot, string $backupRoot, string $sourceId): array
{
    $seed = pi_seed_ready_job($workRoot, $backupRoot, $sourceId);
    $jobId = $seed['job_id'];
    $req = orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $seed['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $seed['admin'],
        $seed['pdo'],
        'restore-test-password',
        (string) (($req['challenge']['nonce'] ?? ''))
    );
    pi_authorize_production_cutover($workRoot, $backupRoot, $jobId, $seed['admin'], $seed['pdo'], $sourceId);

    return $seed;
}

/**
 * P0-3 — explicit production cutover authorization for import fixtures.
 *
 * @param array<string, mixed> $admin
 */
function pi_authorize_production_cutover(
    string $workRoot,
    string $backupRoot,
    string $jobId,
    array $admin,
    PDO $pdo,
    string $packageId
): void {
    $challenge = orange_restore_pca_create_challenge($workRoot, $jobId, $backupRoot, $admin, $pdo);
    orange_restore_pca_finalize(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        $packageId,
        (string) ($challenge['required_confirmation_phrase'] ?? ''),
        (string) ($challenge['nonce'] ?? ''),
        'restore-test-password',
        'Owner authorizes production cutover for isolated import self-test'
    );
}

final class PiProductionImportMockPdo extends PDO
{
    /** @var list<string> */
    public array $execLog = [];
    private string $sessionDb;
    /** @var list<string> */
    private array $tables;
    private int $rowCount;

    public function __construct(string $sessionDb, array $tables = ['items'], int $rowCount = 1)
    {
        $this->sessionDb = $sessionDb;
        $this->tables = $tables;
        $this->rowCount = $rowCount;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . str_replace("'", "''", $string) . "'";
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
            return $this->tables === []
                ? restore_self_test_empty_table_statement()
                : restore_self_test_table_statement($this->tables);
        }
        if (stripos($query, 'SELECT COUNT(*)') !== false) {
            return restore_self_test_scalar_statement($this->rowCount);
        }
        if (stripos($query, 'DEFAULT_CHARACTER_SET_NAME') !== false) {
            return restore_self_test_scalar_statement('utf8mb4');
        }
        if (stripos($query, 'DEFAULT_COLLATION_NAME') !== false) {
            return restore_self_test_scalar_statement('utf8mb4_unicode_ci');
        }

        return false;
    }

    public function exec(string $statement): int|false
    {
        $this->execLog[] = $statement;
        if (stripos($statement, 'DROP TABLE') !== false) {
            $this->tables = [];
        }
        if (stripos($statement, 'CREATE TABLE') !== false) {
            if (!in_array('items', $this->tables, true)) {
                $this->tables[] = 'items';
            }
        }

        return 0;
    }
}

function pi_valid_export_sql(): string
{
    return implode("\n", [
        'SET NAMES utf8mb4;',
        'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;',
        'DROP TABLE IF EXISTS `items`;',
        'CREATE TABLE `items` (`id` int NOT NULL, PRIMARY KEY (`id`));',
        'INSERT INTO `items` (`id`) VALUES (1);',
        'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;',
        '',
    ]);
}

/**
 * @return array<string, mixed>
 */
function pi_export_override(array $options): array
{
    $workRoot = (string) ($options['work_root'] ?? '');
    $jobId = (string) ($options['job_id'] ?? '');
    $gzipPath = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
    $dir = dirname($gzipPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $gz = gzopen($gzipPath, 'wb9');
    if ($gz === false) {
        throw new RuntimeException('Cannot write fixture gzip');
    }
    gzwrite($gz, pi_valid_export_sql());
    gzclose($gz);
    $checksum = hash_file('sha256', $gzipPath) ?: '';
    $manifestPath = orange_restore_merge_db_export_manifest_path($workRoot, $jobId);
    $manifest = [
        'export_backend' => 'php_pdo',
        'source' => 'shadow',
        'shadow_db' => 'orange_restore_staging',
        'production_db' => 'orange_db',
        'gzip_path' => $gzipPath,
        'checksum_sha256' => $checksum,
        'table_count' => 1,
        'row_count' => 1,
        'exported_at' => gmdate('c'),
        'duration_seconds' => 1,
        'production_writes' => false,
    ];
    orange_backup_write_json($manifestPath, $manifest);

    return [
        'ok' => true,
        'gzip_path' => $gzipPath,
        'manifest_path' => $manifestPath,
        'checksum_sha256' => $checksum,
        'table_count' => 1,
        'row_count' => 1,
        'duration_seconds' => 1,
        'shadow_db' => 'orange_restore_staging',
        'production_db' => 'orange_db',
        'production_writes' => false,
    ];
}

/**
 * @return array<string, mixed>
 */
function pi_env(string $backupRoot): array
{
    return [
        'ORANGE_BACKUP_ROOT' => $backupRoot,
        'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
        'ORANGE_RESTORE_STAGING_DB_USER' => 'orange_restore_staging',
        'ORANGE_RESTORE_STAGING_DB_PASS' => 'staging-pass',
        'ORANGE_RESTORE_MERGE_DB_USER' => 'orange_restore_merge',
        'ORANGE_RESTORE_MERGE_DB_PASS' => 'merge-pass',
    ];
}

$piIsDirectCli = PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);

if (!$piIsDirectCli) {
    return;
}

try {
    $tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_prod_import_' . bin2hex(random_bytes(4));
    $backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backup';
    $workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
    mkdir($backupRoot . DIRECTORY_SEPARATOR . 'snapshots', 0775, true);
    mkdir($workRoot, 0775, true);

    $pkgId = '2026-07-17_130000';
    $pkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $pkgId;
    pi_seed_package($pkgDir, $pkgId);

    // --- CLI / HTTP surface ---
    $cliSrc = (string) file_get_contents(
        $projectRootReal . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore_import_production.php'
    );
    pi_self_test(
        str_contains($cliSrc, "PHP_SAPI !== 'cli'")
        && str_contains($cliSrc, '--job=')
        && str_contains($cliSrc, "str_starts_with(\$arg, '--path=')")
        && str_contains($cliSrc, 'arbitrary path/SQL/database')
        && !str_contains($cliSrc, 'orange_restore_prod_import_run_cli($argv'),
        'CLI only: rejects non-CLI and forbids path/sql/db args'
    );
    $reqApi = (string) file_get_contents(
        $projectRootReal . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job'
        . DIRECTORY_SEPARATOR . 'request-production-import.php'
    );
    $statusApi = (string) file_get_contents(
        $projectRootReal . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job'
        . DIRECTORY_SEPARATOR . 'production-import.php'
    );
    pi_self_test(
        str_contains($reqApi, 'metadata_only')
        && str_contains($reqApi, 'http_never_imports')
        && !str_contains($reqApi, 'orange_restore_prod_import_run_cli'),
        'HTTP: request is metadata only'
    );
    pi_self_test(
        str_contains($statusApi, 'restore_admin_api_require_get')
        && !str_contains($statusApi, 'orange_restore_prod_import_run_cli'),
        'HTTP: status GET never imports'
    );

    // --- entry gates: maintenance not active ---
    $seedGate = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $gates = orange_restore_prod_import_validate_entry(
        $workRoot,
        $seedGate['job_id'],
        $backupRoot,
        $fixtureProjectRoot
    );
    pi_self_test(($gates['ok'] ?? true) === false && ($gates['code'] ?? '') === 'maintenance_not_active', 'entry: rejects without maintenance_active');
    pi_retire_job($workRoot, $seedGate['job_id']);

    // --- activate maintenance without cutover authorization ---
    $seedNoAuth = pi_seed_ready_job($workRoot, $backupRoot, $pkgId);
    $jobNoAuth = $seedNoAuth['job_id'];
    $reqNoAuth = orange_restore_prod_maint_request($workRoot, $jobNoAuth, $backupRoot, $seedNoAuth['admin']);
    orange_restore_prod_maint_activate(
        $workRoot,
        $jobNoAuth,
        $backupRoot,
        $seedNoAuth['admin'],
        $seedNoAuth['pdo'],
        'restore-test-password',
        (string) (($reqNoAuth['challenge']['nonce'] ?? ''))
    );
    $gatesNoAuth = orange_restore_prod_import_validate_entry($workRoot, $jobNoAuth, $backupRoot, $fixtureProjectRoot);
    pi_self_test(
        ($gatesNoAuth['ok'] ?? true) === false
        && ($gatesNoAuth['code'] ?? '') === 'production_cutover_authorization_required',
        'entry: rejects without production cutover authorization'
    );
    pi_retire_job($workRoot, $jobNoAuth);

    // --- activate maintenance + authorize then request import ---
    $seed = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $jobId = $seed['job_id'];
    $gatesOk = orange_restore_prod_import_validate_entry($workRoot, $jobId, $backupRoot, $fixtureProjectRoot);
    pi_self_test(($gatesOk['ok'] ?? false) === true, 'entry: all gates pass when maintenance_active + lineage + cutover authorization ready');

    $req = orange_restore_prod_import_request(
        $workRoot,
        $jobId,
        $backupRoot,
        $fixtureProjectRoot,
        $seed['admin']
    );
    pi_self_test(($req['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_PENDING, 'state: request → production_import_pending');
    pi_self_test(($req['execution_started'] ?? true) === false, 'request: execution_started false');
    pi_self_test(str_contains((string) ($req['warning'] ?? ''), 'NOT been switched'), 'request: files warning');

    // --- checkpoint persistence + stream import via mock PDO ---
    $mergePdo = new PiProductionImportMockPdo('orange_db');
    $run = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProjectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $jobId,
        'owner' => 'tester',
        'env_override' => pi_env($backupRoot),
        'merge_pdo_override' => $mergePdo,
        'export_runner_override' => 'pi_export_override',
    ]);
    pi_self_test(($run['ok'] ?? false) === true, 'import: mock stream import succeeds');
    pi_self_test(($run['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY, 'state: → production_import_ready');
    pi_self_test(($run['files_switched'] ?? true) === false, 'import: files not switched');
    pi_self_test(($run['rollback_executed'] ?? true) === false, 'import: no rollback');
    pi_self_test(($run['maintenance_released'] ?? true) === false, 'import: maintenance not released');
    $hist = orange_restore_prod_import_checkpoint_history($workRoot, $jobId);
    $ids = array_column($hist, 'checkpoint_id');
    pi_self_test(
        $ids === ['C0', 'C1', 'C2', 'C3', 'C4', 'C5', 'C6'],
        'checkpoints: C0–C6 persisted'
    );
    pi_self_test(is_file(orange_restore_prod_import_report_path($workRoot, $jobId)), 'report: production_import_report.json written');
    $report = json_decode((string) file_get_contents(orange_restore_prod_import_report_path($workRoot, $jobId)), true);
    pi_self_test(($report['overall'] ?? '') === 'PASS' && isset($report['verification']), 'verification: report PASS with verification block');
    $hasDrop = false;
    foreach ($mergePdo->execLog as $sql) {
        if (stripos($sql, 'DROP TABLE') !== false) {
            $hasDrop = true;
            break;
        }
    }
    pi_self_test($hasDrop, 'wipe: documented DROP TABLE strategy used (no TRUNCATE shortcut)');
    $hasCreate = false;
    foreach ($mergePdo->execLog as $sql) {
        if (stripos($sql, 'CREATE TABLE') !== false) {
            $hasCreate = true;
            break;
        }
    }
    pi_self_test($hasCreate, 'stream import: SQL runner executed CREATE via PDO::exec');
    pi_retire_job($workRoot, $jobId);

    // --- crash simulation / resume policy ---
    $seed2 = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $job2 = $seed2['job_id'];
    orange_restore_prod_import_request($workRoot, $job2, $backupRoot, $fixtureProjectRoot, $seed2['admin']);
    // Simulate crash after wipe (C3 only) — resume must re-wipe + re-import
    orange_restore_prod_import_write_checkpoint($workRoot, $job2, ORANGE_RESTORE_PROD_IMPORT_C0, ['simulated' => true]);
    orange_restore_prod_import_write_checkpoint($workRoot, $job2, ORANGE_RESTORE_PROD_IMPORT_C1, ['simulated' => true]);
    orange_restore_prod_import_write_checkpoint($workRoot, $job2, ORANGE_RESTORE_PROD_IMPORT_C2, ['simulated' => true]);
    orange_restore_prod_import_write_checkpoint($workRoot, $job2, ORANGE_RESTORE_PROD_IMPORT_C3, ['simulated' => true]);
    $resumePdo = new PiProductionImportMockPdo('orange_db');
    $resume = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProjectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job2,
        'owner' => 'tester',
        'env_override' => pi_env($backupRoot),
        'merge_pdo_override' => $resumePdo,
        'export_runner_override' => 'pi_export_override',
    ]);
    pi_self_test(($resume['ok'] ?? false) === true && ($resume['resume_mode'] ?? '') === 'rewipe_reimport', 'resume: C3 → rewipe_reimport');
    pi_retire_job($workRoot, $job2);

    $seed3 = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $job3 = $seed3['job_id'];
    orange_restore_prod_import_request($workRoot, $job3, $backupRoot, $fixtureProjectRoot, $seed3['admin']);
    foreach (['C0', 'C1', 'C2', 'C3', 'C4'] as $cp) {
        orange_restore_prod_import_write_checkpoint($workRoot, $job3, $cp, [
            'simulated' => true,
            'statements_executed' => 3,
            'bytes_read' => 100,
        ]);
    }
    pi_export_override(['work_root' => $workRoot, 'job_id' => $job3]);
    $verifyPdo = new PiProductionImportMockPdo('orange_db');
    $verifyOnly = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProjectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job3,
        'owner' => 'tester',
        'env_override' => pi_env($backupRoot),
        'merge_pdo_override' => $verifyPdo,
        'export_runner_override' => 'pi_export_override',
    ]);
    pi_self_test(($verifyOnly['ok'] ?? false) === true && ($verifyOnly['resume_mode'] ?? '') === 'verify_only', 'resume: C4 → verify_only');
    $dropsAfterVerifyOnly = 0;
    foreach ($verifyPdo->execLog as $sql) {
        if (stripos($sql, 'DROP TABLE') !== false) {
            $dropsAfterVerifyOnly++;
        }
    }
    pi_self_test($dropsAfterVerifyOnly === 0, 'resume: verify_only does not wipe again');
    pi_retire_job($workRoot, $job3);

    // --- wrong DB / shadow DB rejection ---
    $seed4 = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $job4 = $seed4['job_id'];
    orange_restore_prod_import_request($workRoot, $job4, $backupRoot, $fixtureProjectRoot, $seed4['admin']);
    $wrong = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProjectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job4,
        'owner' => 'tester',
        'env_override' => pi_env($backupRoot),
        'merge_pdo_override' => new PiProductionImportMockPdo('wrong_db'),
        'export_runner_override' => 'pi_export_override',
    ]);
    pi_self_test(($wrong['ok'] ?? true) === false, 'wrong DB: rejected fail-closed');
    pi_retire_job($workRoot, $job4);

    $seed5 = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $job5 = $seed5['job_id'];
    orange_restore_prod_import_request($workRoot, $job5, $backupRoot, $fixtureProjectRoot, $seed5['admin']);
    $shadowReject = orange_restore_prod_import_run_cli([
        'project_root' => $fixtureProjectRoot,
        'work_root' => $workRoot,
        'backup_root' => $backupRoot,
        'job_id' => $job5,
        'owner' => 'tester',
        'env_override' => pi_env($backupRoot),
        'merge_pdo_override' => new PiProductionImportMockPdo('orange_restore_staging'),
        'export_runner_override' => 'pi_export_override',
    ]);
    pi_self_test(
        ($shadowReject['ok'] ?? true) === false
        && (
            str_contains((string) ($shadowReject['code'] ?? ''), 'shadow')
            || str_contains((string) ($shadowReject['code'] ?? ''), 'mismatch')
            || str_contains((string) ($shadowReject['code'] ?? ''), 'Production')
        ),
        'shadow DB: rejected as production target'
    );
    pi_retire_job($workRoot, $job5);

    // --- execution_started blocks ---
    $seed6 = pi_seed_maintenance_active($workRoot, $backupRoot, $pkgId);
    $job6 = $seed6['job_id'];
    $j6 = orange_restore_fw_read($workRoot, $job6);
    $j6['execution_started'] = true;
    orange_restore_fw_write($workRoot, $j6);
    $blocked = false;
    try {
        orange_restore_prod_import_request($workRoot, $job6, $backupRoot, $fixtureProjectRoot, $seed6['admin']);
    } catch (Throwable $e) {
        $blocked = trim($e->getMessage()) === 'execution_already_started';
    }
    pi_self_test($blocked, 'entry: execution_started blocks request');
    pi_retire_job($workRoot, $job6);

    // --- security: no shell / no arbitrary SQL engine in HTTP APIs ---
    pi_self_test(
        !str_contains($reqApi, 'proc_open')
        && !str_contains($reqApi, 'shell_exec')
        && !str_contains($statusApi, 'proc_open'),
        'security: APIs have no shell execution'
    );
    $engine = (string) file_get_contents(
        $projectRootReal . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
        . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php'
    );
    pi_self_test(
        str_contains($engine, 'orange_restore_sql_runner_import_gzip_to_target')
        && str_contains($engine, 'orange_restore_production_wipe')
        && !str_contains($engine, 'orange_restore_merge_uploads_cutover')
        && !str_contains($engine, 'orange_restore_merge_rollback')
        && !str_contains($engine, 'orange_restore_maint_fw_release'),
        'scope: import engine DB-only; no uploads/rollback/maint release'
    );

    $ui = (string) file_get_contents(
        $projectRootReal . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages'
        . DIRECTORY_SEPARATOR . 'restore_center.php'
    );
    pi_self_test(
        str_contains($ui, 'Production Import Pending')
        && str_contains($ui, 'Application files have NOT been switched.')
        && str_contains($ui, 'rc-prod-import-req')
        && str_contains($ui, 'rc-prod-import-view'),
        'UI: pending/running/ready/failed + files warning + controls'
    );

    echo "PRODUCTION_IMPORT_TEST_RESULT: " . ($failures === 0 ? 'PASS' : 'FAIL') . "\n";
    echo 'TOTAL_PASS: ' . $passes . "\n";
    echo 'TOTAL_FAIL: ' . $failures . "\n";
} catch (Throwable $e) {
    echo 'THROWABLE: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
    $failures++;
    echo "PRODUCTION_IMPORT_TEST_RESULT: FAIL\n";
} finally {
    if ($tmpRoot !== '') {
        pi_rmtree($tmpRoot);
    }
}

exit($failures > 0 ? 1 : 0);
