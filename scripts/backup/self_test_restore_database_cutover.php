<?php

declare(strict_types=1);

/**
 * Phase 2D.2 — Production database cutover self-tests (no uploads, no rollback).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_database_cutover.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_staging_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_db_cutover.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_target.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function db_cutover_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function db_cutover_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2d2_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function db_cutover_rmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            db_cutover_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function db_cutover_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function db_cutover_test_admin_pdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hash = password_hash('merge-pass', PASSWORD_DEFAULT);
    $pdo->exec(
        'CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, password_hash TEXT, display_name TEXT, is_active INTEGER, is_superuser INTEGER)'
    );
    $pdo->exec(
        'CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)'
    );
    $pdo->exec('INSERT INTO admins VALUES (1, \'superadmin\', ' . $pdo->quote($hash) . ', \'Super\', 1, 1)');
    $pdo->exec('INSERT INTO admin_permissions VALUES (1, \'backup_restore_full\', 1, 0, 0)');
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

function db_cutover_write_gzip(string $path, string $sql): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    orange_backup_gzip_file_from_string($path, $sql);
}

function orange_backup_gzip_file_from_string(string $destinationFile, string $contents): void
{
    $out = gzopen($destinationFile, 'wb9');
    if ($out === false) {
        throw new RuntimeException('Cannot open gzip destination.');
    }
    gzwrite($out, $contents);
    gzclose($out);
}

function db_cutover_valid_export_sql(): string
{
    return implode("\n", [
        'SET NAMES utf8mb4;',
        'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;',
        'SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\';',
        'SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE=\'+00:00\';',
        'DROP TABLE IF EXISTS `items`;',
        'CREATE TABLE `items` (`id` int NOT NULL, PRIMARY KEY (`id`));',
        'INSERT INTO `items` (`id`) VALUES (1);',
        'SET TIME_ZONE=@OLD_TIME_ZONE;',
        'SET SQL_MODE=@OLD_SQL_MODE;',
        'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;',
        '',
    ]);
}

/**
 * @return list<string>
 */
function db_cutover_mock_grant_lines(string $db): array
{
    return ['GRANT ALL PRIVILEGES ON `' . $db . '`.* TO \'mock\'@\'localhost\''];
}

final class DbCutoverIdentityMockPdo extends PDO
{
    public function __construct(private string $databaseName, private array $grantLines = [])
    {
        $this->grantLines = $grantLines !== [] ? $grantLines : db_cutover_mock_grant_lines($databaseName);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        unset($fetchMode, $fetchModeArgs);

        if (stripos($query, 'SHOW GRANTS') !== false) {
            return new DbCutoverGrantStatement($this->grantLines);
        }
        if (stripos($query, 'SELECT DATABASE()') !== false) {
            return new DbCutoverScalarStatement($this->databaseName);
        }
        if (stripos($query, 'SELECT 1') !== false) {
            return new DbCutoverScalarStatement('1');
        }
        if (stripos($query, 'SHOW TABLES') !== false) {
            return new DbCutoverEmptyStatement();
        }

        return false;
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }
}

class DbCutoverProductionCutoverMockPdo extends PDO
{
    /** @var list<string> */
    public array $execLog = [];
    private string $sessionDb;
    private bool $driftAfterStatement = false;

    public function __construct(string $sessionDb, bool $driftAfterStatement = false)
    {
        $this->sessionDb = $sessionDb;
        $this->driftAfterStatement = $driftAfterStatement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        unset($fetchMode, $fetchModeArgs);

        if (stripos($query, 'SELECT DATABASE()') !== false) {
            return new DbCutoverScalarStatement($this->sessionDb);
        }
        if (stripos($query, 'SELECT 1') !== false) {
            return new DbCutoverScalarStatement('1');
        }
        if (stripos($query, 'SHOW TABLES') !== false) {
            return new DbCutoverTablesStatement(['legacy_table']);
        }

        return false;
    }

    public function exec(string $statement): int|false
    {
        $this->execLog[] = $statement;
        if ($this->driftAfterStatement) {
            $this->sessionDb = 'wrong_db';
        }

        return 0;
    }
}

final class DbCutoverImportFailMockPdo extends DbCutoverProductionCutoverMockPdo
{
    public function __construct(string $sessionDb)
    {
        parent::__construct($sessionDb);
    }

    public function exec(string $statement): int|false
    {
        if (stripos($statement, 'CREATE TABLE') !== false) {
            throw new RuntimeException('Simulated SQL failure mid-import.');
        }

        return parent::exec($statement);
    }
}

final class DbCutoverScalarStatement extends PDOStatement
{
    public function __construct(private mixed $value)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        unset($column);

        return $this->value;
    }
}

final class DbCutoverGrantStatement extends PDOStatement
{
    public function __construct(private array $lines)
    {
    }

    private int $index = 0;

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        unset($mode, $cursorOrientation, $cursorOffset);

        if ($this->index >= count($this->lines)) {
            return false;
        }

        return [$this->lines[$this->index++]];
    }
}

final class DbCutoverEmptyStatement extends PDOStatement
{
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        unset($mode, $cursorOrientation, $cursorOffset);

        return false;
    }
}

final class DbCutoverTablesStatement extends PDOStatement
{
    public function __construct(private array $tables)
    {
    }

    private int $index = 0;

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        unset($mode, $cursorOrientation, $cursorOffset);

        if ($this->index >= count($this->tables)) {
            return false;
        }

        return [$this->tables[$this->index++]];
    }
}

/**
 * @return array{workRoot:string,backupRoot:string,jobId:string,job:array<string,mixed>,env:array<string,mixed>}
 */
function db_cutover_seed_precheck_passed_job(bool $expiredWindow = false): array
{
    $backupRoot = db_cutover_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    mkdir($workRoot);

    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_' . bin2hex(random_bytes(2));
    mkdir($packageDir, 0775, true);
    orange_backup_write_json($packageDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_sha256' => str_repeat('c', 64),
    ]);
    orange_backup_write_checksums($packageDir, ['manifest.json']);
    $packageChecksum = orange_backup_sha256_file($packageDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $anchorDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'anchor_' . bin2hex(random_bytes(2));
    mkdir($anchorDir, 0775, true);
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_sha256' => str_repeat('b', 64),
    ]);
    orange_backup_write_checksums($anchorDir, ['manifest.json']);
    $anchorChecksum = orange_backup_sha256_file($anchorDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $job = orange_restore_job_create($workRoot, [
        'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_path' => $packageDir,
        'source_package_checksum' => $packageChecksum,
        'schema_revision' => 121,
    ]);
    $jobId = (string) $job['job_id'];

    orange_restore_job_record_fresh_backup_anchor($workRoot, $jobId, $anchorDir, $anchorChecksum);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED);

    $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($manifestPath, ['table_count' => 2, 'schema_revision' => 121]);
    $manifestChecksum = orange_backup_sha256_file($manifestPath);

    $reportPath = orange_restore_job_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, [
        'overall_result' => 'pass',
        'production_touched' => false,
        'staging_post_validation' => ['ok' => true],
        'staging_drv_report' => ['overall_result' => 'pass'],
    ]);

    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
        'restore_report_path' => $reportPath,
        'staging_restore_manifest_path' => $manifestPath,
        'owner_approval_window_started_at' => $expiredWindow
            ? gmdate('c', time() - ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS - 60)
            : gmdate('c'),
        'staging_db' => 'orange_restore_staging',
    ]);

    $binding = [
        'job_id' => $jobId,
        'operator_id' => 1,
        'scope' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchorChecksum,
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED;
    $job['approval_token_hash'] = $issued['hash'];
    $job['approval_token_binding'] = $binding;
    $job['approval_token_consumed_at'] = gmdate('c');
    $job['owner_approval_at'] = gmdate('c');
    $job['production_merge_approved'] = true;
    $job['merge_precheck_passed_at'] = gmdate('c');
    $job['merge_precheck_production_db'] = 'orange_db';
    $job['merge_precheck_staging_db'] = 'orange_restore_staging';
    orange_restore_job_write($workRoot, $job);

    $env = [
        'ORANGE_BACKUP_ROOT' => $backupRoot,
        'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
        'ORANGE_RESTORE_STAGING_DB_USER' => 'orange_restore_staging',
        'ORANGE_RESTORE_STAGING_DB_PASS' => 'staging-pass',
        'ORANGE_RESTORE_MERGE_DB_USER' => 'orange_restore_merge',
        'ORANGE_RESTORE_MERGE_DB_PASS' => 'merge-pass',
    ];

    return [
        'workRoot' => $workRoot,
        'backupRoot' => $backupRoot,
        'jobId' => $jobId,
        'job' => $job,
        'env' => $env,
    ];
}

function db_cutover_prepare_runtime(array $seed): void
{
    orange_restore_acquire_lock($seed['workRoot'], $seed['jobId']);
    orange_restore_merge_maintenance_enable($seed['workRoot'], $seed['jobId']);
}

function db_cutover_export_override(array $options): array
{
    $workRoot = (string) ($options['work_root'] ?? '');
    $jobId = (string) ($options['job_id'] ?? '');
    $gzipPath = orange_restore_merge_db_export_gzip_path($workRoot, $jobId);
    db_cutover_write_gzip($gzipPath, db_cutover_valid_export_sql());
    $checksum = orange_backup_sha256_file($gzipPath);

    return [
        'ok' => true,
        'gzip_path' => $gzipPath,
        'manifest_path' => orange_restore_merge_db_export_manifest_path($workRoot, $jobId),
        'checksum_sha256' => $checksum,
        'table_count' => 1,
        'row_count' => 1,
        'duration_seconds' => 1,
        'production_writes' => false,
    ];
}

// --- export verify forbidden patterns ---
$verifyRoot = db_cutover_temp_root();
$verifyGz = $verifyRoot . DIRECTORY_SEPARATOR . 'export.sql.gz';
db_cutover_write_gzip($verifyGz, db_cutover_valid_export_sql());
$okVerify = orange_restore_merge_staging_export_verify_gzip($verifyGz, 'orange_db', 'orange_restore_staging', 1);
db_cutover_self_test($okVerify['ok'] === true, 'export verify: valid artifact passes');

db_cutover_write_gzip($verifyRoot . DIRECTORY_SEPARATOR . 'use.sql.gz', "USE `other`;\n" . db_cutover_valid_export_sql());
$useVerify = orange_restore_merge_staging_export_verify_gzip($verifyRoot . DIRECTORY_SEPARATOR . 'use.sql.gz', 'orange_db', 'orange_restore_staging', 1);
db_cutover_self_test($useVerify['ok'] === false, 'export verify: USE rejected');

db_cutover_write_gzip($verifyRoot . DIRECTORY_SEPARATOR . 'ddl.sql.gz', "CREATE DATABASE `x`;\n" . db_cutover_valid_export_sql());
$ddlVerify = orange_restore_merge_staging_export_verify_gzip($verifyRoot . DIRECTORY_SEPARATOR . 'ddl.sql.gz', 'orange_db', 'orange_restore_staging', 1);
db_cutover_self_test($ddlVerify['ok'] === false, 'export verify: CREATE DATABASE rejected');

db_cutover_write_gzip(
    $verifyRoot . DIRECTORY_SEPARATOR . 'cross.sql.gz',
    'INSERT INTO `orange_restore_staging`.`items` (`id`) VALUES (1);'
);
$crossVerify = orange_restore_merge_staging_export_verify_gzip($verifyRoot . DIRECTORY_SEPARATOR . 'cross.sql.gz', 'orange_db', 'orange_restore_staging', 0);
db_cutover_self_test($crossVerify['ok'] === false, 'export verify: cross-schema reference rejected');
db_cutover_rmdir($verifyRoot);

// --- credentials ---
$envRoot = db_cutover_temp_root();
$env = [
    'ORANGE_BACKUP_ROOT' => $envRoot,
    'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
    'ORANGE_RESTORE_STAGING_DB_USER' => 'orange_restore_staging',
    'ORANGE_RESTORE_STAGING_DB_PASS' => 'x',
    'ORANGE_RESTORE_MERGE_DB_USER' => '',
    'ORANGE_RESTORE_MERGE_DB_PASS' => 'x',
];
$err = db_cutover_try(static fn () => orange_restore_merge_credentials($env, $projectRoot));
db_cutover_self_test($err !== null, 'credentials: missing merge user rejected');
db_cutover_rmdir($envRoot);

// --- production wipe scoped ---
$wipePdo = new DbCutoverProductionCutoverMockPdo('orange_db');
orange_restore_production_wipe($wipePdo, 'orange_db');
$dropCount = 0;
foreach ($wipePdo->execLog as $line) {
    if (stripos($line, 'DROP TABLE IF EXISTS `legacy_table`') !== false) {
        $dropCount++;
    }
    db_cutover_self_test(stripos($line, 'orange_restore_staging') === false, 'wipe: no staging schema references');
}
db_cutover_self_test($dropCount === 1, 'wipe: drops production tables only');

// --- session drift on import ---
$driftGz = db_cutover_temp_root() . DIRECTORY_SEPARATOR . 'drift.sql.gz';
db_cutover_write_gzip($driftGz, db_cutover_valid_export_sql());
$driftPdo = new DbCutoverProductionCutoverMockPdo('orange_db', true);
$driftImport = orange_restore_sql_runner_import_gzip_to_target($driftPdo, $driftGz, 'orange_db', 'orange_restore_staging');
db_cutover_self_test($driftImport['ok'] === false, 'import: session DB drift rejected');
db_cutover_rmdir(dirname($driftGz));

// --- SQL failure mid-import ---
$failGz = db_cutover_temp_root() . DIRECTORY_SEPARATOR . 'fail.sql.gz';
db_cutover_write_gzip($failGz, db_cutover_valid_export_sql());
$failPdo = new DbCutoverImportFailMockPdo('orange_db');
$failImport = orange_restore_sql_runner_import_gzip_to_target($failPdo, $failGz, 'orange_db', 'orange_restore_staging');
db_cutover_self_test($failImport['ok'] === false, 'import: SQL failure mid-import');
db_cutover_rmdir(dirname($failGz));

$identityBad = new DbCutoverIdentityMockPdo('wrong_production_db');
$err = db_cutover_try(static fn () => orange_restore_production_assert_identity($identityBad, 'orange_db'));
db_cutover_self_test($err !== null, 'identity: production session mismatch rejected');
unset($identityBad, $err);

// --- cutover gates ---
$wrongState = db_cutover_seed_precheck_passed_job();
db_cutover_prepare_runtime($wrongState);
$jobBad = orange_restore_job_read($wrongState['workRoot'], $wrongState['jobId']);
$jobBad['status'] = ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE;
orange_restore_job_write($wrongState['workRoot'], $jobBad);
$err = db_cutover_try(static function () use ($wrongState, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $wrongState['workRoot'],
        'job_id' => $wrongState['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $wrongState['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
        'merge_pdo_override' => new DbCutoverIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new DbCutoverIdentityMockPdo('orange_restore_staging', db_cutover_mock_grant_lines('orange_restore_staging')),
        'export_runner_override' => 'db_cutover_export_override',
    ]);
});
db_cutover_self_test($err !== null, 'cutover: wrong job state rejected');
orange_restore_release_lock($wrongState['workRoot']);
orange_restore_merge_maintenance_disable($wrongState['workRoot'], $wrongState['jobId']);
db_cutover_rmdir($wrongState['backupRoot']);

$noLock = db_cutover_seed_precheck_passed_job();
orange_restore_merge_maintenance_enable($noLock['workRoot'], $noLock['jobId']);
$err = db_cutover_try(static function () use ($noLock, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $noLock['workRoot'],
        'job_id' => $noLock['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $noLock['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
    ]);
});
db_cutover_self_test($err !== null, 'cutover: missing lock rejected');
orange_restore_merge_maintenance_disable($noLock['workRoot'], $noLock['jobId']);
db_cutover_rmdir($noLock['backupRoot']);

$noMaint = db_cutover_seed_precheck_passed_job();
orange_restore_acquire_lock($noMaint['workRoot'], $noMaint['jobId']);
$err = db_cutover_try(static function () use ($noMaint, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $noMaint['workRoot'],
        'job_id' => $noMaint['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $noMaint['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
    ]);
});
db_cutover_self_test($err !== null, 'cutover: maintenance not active rejected');
orange_restore_release_lock($noMaint['workRoot']);
db_cutover_rmdir($noMaint['backupRoot']);

$wrongMaint = db_cutover_seed_precheck_passed_job();
orange_restore_acquire_lock($wrongMaint['workRoot'], $wrongMaint['jobId']);
orange_restore_merge_maintenance_enable($wrongMaint['workRoot'], 'other_job');
$err = db_cutover_try(static function () use ($wrongMaint, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $wrongMaint['workRoot'],
        'job_id' => $wrongMaint['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $wrongMaint['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
    ]);
});
db_cutover_self_test($err !== null, 'cutover: maintenance owned by another job rejected');
orange_restore_release_lock($wrongMaint['workRoot']);
orange_restore_merge_maintenance_disable($wrongMaint['workRoot'], 'other_job');
db_cutover_rmdir($wrongMaint['backupRoot']);

$expired = db_cutover_seed_precheck_passed_job(true);
db_cutover_prepare_runtime($expired);
$err = db_cutover_try(static function () use ($expired, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $expired['workRoot'],
        'job_id' => $expired['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $expired['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
    ]);
});
db_cutover_self_test($err !== null, 'cutover: expired approval rejected');
orange_restore_release_lock($expired['workRoot']);
orange_restore_merge_maintenance_disable($expired['workRoot'], $expired['jobId']);
db_cutover_rmdir($expired['backupRoot']);

$checksumBad = db_cutover_seed_precheck_passed_job();
db_cutover_prepare_runtime($checksumBad);
$jobChecksum = orange_restore_job_read($checksumBad['workRoot'], $checksumBad['jobId']);
$jobChecksum['source_package_checksum'] = str_repeat('f', 64);
orange_restore_job_write($checksumBad['workRoot'], $jobChecksum);
$err = db_cutover_try(static function () use ($checksumBad, $projectRoot): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $checksumBad['workRoot'],
        'job_id' => $checksumBad['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $checksumBad['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
    ]);
});
db_cutover_self_test($err !== null, 'cutover: checksum drift rejected');
db_cutover_self_test(
    (string) (orange_restore_job_read($checksumBad['workRoot'], $checksumBad['jobId'])['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
    'cutover: pre-wipe failure keeps merge_precheck_passed'
);
orange_restore_release_lock($checksumBad['workRoot']);
orange_restore_merge_maintenance_disable($checksumBad['workRoot'], $checksumBad['jobId']);
db_cutover_rmdir($checksumBad['backupRoot']);

// --- successful cutover ---
$success = db_cutover_seed_precheck_passed_job();
db_cutover_prepare_runtime($success);
$mergePdo = new DbCutoverProductionCutoverMockPdo('orange_db');
$result = orange_restore_merge_db_cutover_run([
    'project_root' => $projectRoot,
    'work_root' => $success['workRoot'],
    'job_id' => $success['jobId'],
    'admin_id' => 1,
    'password' => 'merge-pass',
    'confirmation_phrase' => 'RESTORE',
    'env_override' => $success['env'],
    'admin_pdo_override' => db_cutover_test_admin_pdo(),
    'merge_pdo_override' => $mergePdo,
    'staging_pdo_override' => new DbCutoverIdentityMockPdo('orange_restore_staging', db_cutover_mock_grant_lines('orange_restore_staging')),
    'export_runner_override' => 'db_cutover_export_override',
]);
db_cutover_self_test(
    ($result['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
    'cutover: successful transition to database_cutover_complete'
);
db_cutover_self_test(($result['uploads_touched'] ?? true) === false, 'cutover: uploads untouched');
db_cutover_self_test(($result['rollback_executed'] ?? true) === false, 'cutover: no automatic rollback');
$auditRows = orange_restore_audit_read_all($success['workRoot'], $success['jobId']);
$events = array_map(static fn (array $row): string => (string) ($row['db_cutover_event'] ?? ''), $auditRows);
db_cutover_self_test(in_array('staging_export_started', $events, true), 'audit: staging_export_started');
db_cutover_self_test(in_array('database_cutover_complete', $events, true), 'audit: database_cutover_complete');
orange_restore_release_lock($success['workRoot']);
orange_restore_merge_maintenance_disable($success['workRoot'], $success['jobId']);
db_cutover_rmdir($success['backupRoot']);

// --- failed_merge after wipe ---
$failed = db_cutover_seed_precheck_passed_job();
db_cutover_prepare_runtime($failed);
$failMergePdo = new DbCutoverImportFailMockPdo('orange_db');
$err = db_cutover_try(static function () use ($failed, $projectRoot, $failMergePdo): void {
    orange_restore_merge_db_cutover_run([
        'project_root' => $projectRoot,
        'work_root' => $failed['workRoot'],
        'job_id' => $failed['jobId'],
        'admin_id' => 1,
        'password' => 'merge-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $failed['env'],
        'admin_pdo_override' => db_cutover_test_admin_pdo(),
        'merge_pdo_override' => $failMergePdo,
        'staging_pdo_override' => new DbCutoverIdentityMockPdo('orange_restore_staging', db_cutover_mock_grant_lines('orange_restore_staging')),
        'export_runner_override' => 'db_cutover_export_override',
    ]);
});
db_cutover_self_test($err !== null, 'cutover: post-wipe failure surfaces error');
$failedJob = orange_restore_job_read($failed['workRoot'], $failed['jobId']);
db_cutover_self_test(
    ($failedJob['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
    'cutover: post-wipe failure sets failed_merge'
);
db_cutover_self_test(orange_restore_merge_maintenance_status($failed['workRoot'])['active'] === true, 'cutover: maintenance remains active after failed_merge');
orange_restore_release_lock($failed['workRoot']);
orange_restore_merge_maintenance_disable($failed['workRoot'], $failed['jobId']);
db_cutover_rmdir($failed['backupRoot']);

// --- state machine ---
try {
    orange_restore_job_assert_db_cutover_transition(
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
        ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED
    );
    db_cutover_self_test(true, 'state: merge_precheck_passed -> merge_started');
} catch (Throwable) {
    db_cutover_self_test(false, 'state: merge_precheck_passed -> merge_started');
}
try {
    orange_restore_job_assert_db_cutover_transition(
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
        ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE
    );
    db_cutover_self_test(false, 'state: direct database_cutover_complete blocked');
} catch (Throwable) {
    db_cutover_self_test(true, 'state: direct database_cutover_complete blocked');
}

exit($failures > 0 ? 1 : 0);
