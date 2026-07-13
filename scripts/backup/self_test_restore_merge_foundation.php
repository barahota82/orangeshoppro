<?php

declare(strict_types=1);

/**
 * Phase 2D.1 — Merge foundation self-tests (no production writes, no merge cutover).
 *
 * Usage:
 *   php scripts/backup/self_test_restore_merge_foundation.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_target.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_precheck.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

$failures = 0;

function merge_foundation_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function merge_foundation_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2d1f_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function merge_foundation_rmdir(string $dir): void
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
            merge_foundation_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function merge_foundation_temp_project_root(): string
{
    $dir = merge_foundation_temp_root();
    $config = <<<'PHP'
<?php
declare(strict_types=1);

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'orange_db');
}
PHP;
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'config.php', $config);
    file_put_contents(
        $dir . DIRECTORY_SEPARATOR . '.env.php',
        "<?php\nreturn ['DB_USER' => 'orange_app', 'DB_PASS' => 'app-pass'];\n"
    );

    return $dir;
}

/**
 * Minimal PDO stub for identity-only merge foundation tests.
 */
final class MergeFoundationIdentityMockPdo extends PDO
{
    /** @var list<string> */
    private array $grantLines;

    public function __construct(private string $databaseName, array $grantLines = [])
    {
        $this->grantLines = $grantLines !== [] ? $grantLines : [
            'GRANT ALL PRIVILEGES ON `' . $databaseName . '`.* TO \'mock\'@\'localhost\'',
        ];
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (stripos($query, 'SHOW GRANTS') !== false) {
            return new MergeFoundationMockGrantStatement($this->grantLines);
        }
        if (stripos($query, 'SELECT DATABASE()') !== false) {
            return new MergeFoundationMockScalarStatement($this->databaseName);
        }
        if (stripos($query, 'SELECT 1') !== false) {
            return new MergeFoundationMockScalarStatement('1');
        }

        return false;
    }
}

final class MergeFoundationMockScalarStatement extends PDOStatement
{
    public function __construct(private mixed $value)
    {
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->value;
    }
}

final class MergeFoundationMockGrantStatement extends PDOStatement
{
    /** @var list<string> */
    private array $lines;
    private int $index = 0;

    /**
     * @param list<string> $lines
     */
    public function __construct(array $lines)
    {
        $this->lines = $lines;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed
    {
        if ($this->index >= count($this->lines)) {
            return false;
        }

        return [$this->lines[$this->index++]];
    }
}

function merge_foundation_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

/**
 * @return array{
 *   workRoot:string,
 *   backupRoot:string,
 *   packagePath:string,
 *   anchorPath:string,
 *   jobId:string,
 *   job:array<string,mixed>,
 *   manifestChecksum:string,
 *   anchorChecksum:string
 * }
 */
function merge_foundation_seed_approved_job(bool $expiredWindow = false): array
{
    $backupRoot = merge_foundation_temp_root();
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
    orange_backup_write_json($manifestPath, ['table_count' => 5, 'schema_revision' => 121]);
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
        'issued_at' => gmdate('c'),
        'expires_at' => gmdate('c', time() + 3600),
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE;
    $job['approval_token_hash'] = $issued['hash'];
    $job['approval_token_binding'] = $binding;
    $job['approval_token_issued_at'] = $issued['issued_at'];
    $job['approval_token_expires_at'] = $issued['expires_at'];
    $job['approval_token_consumed_at'] = gmdate('c');
    $job['owner_approval_at'] = gmdate('c');
    $job['owner_approval_by'] = 'superadmin';
    $job['owner_approval_admin_id'] = 1;
    $job['production_merge_approved'] = true;
    $job['restore_report_path'] = $reportPath;
    $job['staging_restore_manifest_path'] = $manifestPath;
    orange_restore_job_write($workRoot, $job);

    return [
        'workRoot' => $workRoot,
        'backupRoot' => $backupRoot,
        'packagePath' => $packageDir,
        'anchorPath' => $anchorDir,
        'jobId' => $jobId,
        'job' => $job,
        'manifestChecksum' => $manifestChecksum,
        'anchorChecksum' => $anchorChecksum,
    ];
}

function merge_foundation_base_env(string $backupRoot): array
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

$projectRoot = merge_foundation_temp_project_root();

// --- Credential fencing ---
$envRoot = merge_foundation_temp_root();
$baseEnv = merge_foundation_base_env($envRoot);
try {
    orange_restore_merge_credentials(array_merge($baseEnv, [
        ORANGE_RESTORE_ENV_MERGE_DB_USER => '',
    ]), $projectRoot);
    merge_foundation_self_test(false, 'credentials: missing user rejected');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'credentials: missing user rejected');
}
try {
    $prodUser = orange_restore_production_db_credentials($projectRoot)['user'];
    orange_restore_merge_credentials(array_merge($baseEnv, [
        ORANGE_RESTORE_ENV_MERGE_DB_USER => $prodUser,
    ]), $projectRoot);
    merge_foundation_self_test(false, 'credentials: duplicate app user rejected');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'credentials: duplicate app user rejected');
}
merge_foundation_rmdir($envRoot);

// --- production == staging name ---
$envRoot = merge_foundation_temp_root();
try {
    orange_restore_production_verify_target($projectRoot, array_merge(merge_foundation_base_env($envRoot), [
        ORANGE_RESTORE_STAGING_DB => 'orange_db',
    ]), new MergeFoundationIdentityMockPdo('orange_db'));
    merge_foundation_self_test(false, 'identity: production==staging rejected');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'identity: production==staging rejected');
}
merge_foundation_rmdir($envRoot);

// --- Maintenance enable/disable/status/verify ---
$maintRoot = merge_foundation_temp_root();
$workRoot = $maintRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($workRoot);
$job = ['job_id' => 'maint_job', 'status' => ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE];
orange_restore_orchestrator_merge_maintenance_enable($workRoot, 'maint_job', $job);
$status = orange_restore_merge_maintenance_status($workRoot);
merge_foundation_self_test($status['active'] === true && ($status['corrupt'] ?? true) === false, 'maintenance: enable');
orange_restore_merge_maintenance_verify($workRoot, 'maint_job');
merge_foundation_self_test(true, 'maintenance: verify');
orange_restore_orchestrator_merge_maintenance_disable($workRoot, 'maint_job', $job);
merge_foundation_self_test(orange_restore_merge_maintenance_status($workRoot)['active'] === false, 'maintenance: disable');
merge_foundation_rmdir($maintRoot);

function merge_foundation_write_maintenance_file(string $workRoot, string $contents): string
{
    $path = orange_restore_merge_maintenance_file_path($workRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create maintenance directory.');
    }
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write maintenance file.');
    }

    return $path;
}

function merge_foundation_assert_maintenance_file_exists(string $workRoot): bool
{
    return is_file(orange_restore_merge_maintenance_file_path($workRoot));
}

// --- Maintenance ownership fail-closed ---
$ownerRoot = merge_foundation_temp_root();
$ownerWork = $ownerRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($ownerWork);
orange_restore_merge_maintenance_enable($ownerWork, 'owner_job');
try {
    orange_restore_merge_maintenance_disable($ownerWork, 'other_job');
    merge_foundation_self_test(false, 'maintenance: different job cannot disable');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: different job cannot disable');
}
merge_foundation_self_test(merge_foundation_assert_maintenance_file_exists($ownerWork), 'maintenance: file kept after foreign disable');
orange_restore_merge_maintenance_disable($ownerWork, 'owner_job');
merge_foundation_rmdir($ownerRoot);

$emptyJobRoot = merge_foundation_temp_root();
$emptyJobWork = $emptyJobRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($emptyJobWork);
merge_foundation_write_maintenance_file($emptyJobWork, json_encode([
    'job_id' => '',
    'reason' => 'full_merge_foundation',
    'enabled_at' => gmdate('c'),
    'pid' => 1234,
    'hostname' => 'test-host',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$emptyStatus = orange_restore_merge_maintenance_status($emptyJobWork);
merge_foundation_self_test($emptyStatus['active'] === true && $emptyStatus['corrupt'] === true, 'maintenance: status reports corrupt empty job_id');
try {
    orange_restore_merge_maintenance_disable($emptyJobWork, 'any_job');
    merge_foundation_self_test(false, 'maintenance: empty job_id cannot disable');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: empty job_id cannot disable');
}
merge_foundation_self_test(merge_foundation_assert_maintenance_file_exists($emptyJobWork), 'maintenance: corrupt empty job_id file kept');
try {
    orange_restore_merge_maintenance_verify($emptyJobWork, 'any_job');
    merge_foundation_self_test(false, 'maintenance: verify fails on empty job_id');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: verify fails on empty job_id');
}
merge_foundation_rmdir($emptyJobRoot);

$missingJobRoot = merge_foundation_temp_root();
$missingJobWork = $missingJobRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($missingJobWork);
merge_foundation_write_maintenance_file($missingJobWork, json_encode([
    'reason' => 'full_merge_foundation',
    'enabled_at' => gmdate('c'),
    'pid' => 1234,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$missingStatus = orange_restore_merge_maintenance_status($missingJobWork);
merge_foundation_self_test($missingStatus['active'] === true && $missingStatus['corrupt'] === true, 'maintenance: status reports corrupt missing job_id');
try {
    orange_restore_merge_maintenance_disable($missingJobWork, 'any_job');
    merge_foundation_self_test(false, 'maintenance: missing job_id cannot disable');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: missing job_id cannot disable');
}
merge_foundation_self_test(merge_foundation_assert_maintenance_file_exists($missingJobWork), 'maintenance: corrupt missing job_id file kept');
merge_foundation_rmdir($missingJobRoot);

$malformedRoot = merge_foundation_temp_root();
$malformedWork = $malformedRoot . DIRECTORY_SEPARATOR . 'restore_work';
mkdir($malformedWork);
merge_foundation_write_maintenance_file($malformedWork, '{not-json');
$malformedStatus = orange_restore_merge_maintenance_status($malformedWork);
merge_foundation_self_test($malformedStatus['active'] === true && $malformedStatus['corrupt'] === true, 'maintenance: status reports corrupt malformed JSON');
try {
    orange_restore_merge_maintenance_disable($malformedWork, 'any_job');
    merge_foundation_self_test(false, 'maintenance: malformed JSON cannot disable');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: malformed JSON cannot disable');
}
merge_foundation_self_test(merge_foundation_assert_maintenance_file_exists($malformedWork), 'maintenance: malformed JSON file kept');
try {
    orange_restore_merge_maintenance_verify($malformedWork, 'any_job');
    merge_foundation_self_test(false, 'maintenance: verify fails on malformed JSON');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'maintenance: verify fails on malformed JSON');
}
merge_foundation_rmdir($malformedRoot);

// --- expired approval ---
$expired = merge_foundation_seed_approved_job(true);
orange_restore_acquire_lock($expired['workRoot'], $expired['jobId']);
$err = merge_foundation_try(static function () use ($expired, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $expired['workRoot'],
        'job_id' => $expired['jobId'],
        'env_override' => merge_foundation_base_env($expired['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging', [
            'GRANT ALL PRIVILEGES ON `orange_restore_staging`.* TO \'staging\'@\'localhost\'',
        ]),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: expired approval rejected');
orange_restore_release_lock($expired['workRoot']);
merge_foundation_rmdir($expired['backupRoot']);

// --- checksum mismatch ---
$checksumBad = merge_foundation_seed_approved_job();
$jobBad = orange_restore_job_read($checksumBad['workRoot'], $checksumBad['jobId']);
$jobBad['source_package_checksum'] = str_repeat('f', 64);
orange_restore_job_write($checksumBad['workRoot'], $jobBad);
orange_restore_acquire_lock($checksumBad['workRoot'], $checksumBad['jobId']);
$err = merge_foundation_try(static function () use ($checksumBad, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $checksumBad['workRoot'],
        'job_id' => $checksumBad['jobId'],
        'env_override' => merge_foundation_base_env($checksumBad['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: checksum mismatch rejected');
orange_restore_release_lock($checksumBad['workRoot']);
merge_foundation_rmdir($checksumBad['backupRoot']);

// --- rollback anchor mismatch ---
$anchorBad = merge_foundation_seed_approved_job();
$jobAnchor = orange_restore_job_read($anchorBad['workRoot'], $anchorBad['jobId']);
$jobAnchor['fresh_backup_checksum'] = str_repeat('f', 64);
orange_restore_job_write($anchorBad['workRoot'], $jobAnchor);
orange_restore_acquire_lock($anchorBad['workRoot'], $anchorBad['jobId']);
$err = merge_foundation_try(static function () use ($anchorBad, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $anchorBad['workRoot'],
        'job_id' => $anchorBad['jobId'],
        'env_override' => merge_foundation_base_env($anchorBad['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: rollback anchor mismatch rejected');
orange_restore_release_lock($anchorBad['workRoot']);
merge_foundation_rmdir($anchorBad['backupRoot']);

// --- maintenance already active ---
$maintActive = merge_foundation_seed_approved_job();
orange_restore_merge_maintenance_enable($maintActive['workRoot'], $maintActive['jobId']);
orange_restore_acquire_lock($maintActive['workRoot'], $maintActive['jobId']);
$err = merge_foundation_try(static function () use ($maintActive, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $maintActive['workRoot'],
        'job_id' => $maintActive['jobId'],
        'env_override' => merge_foundation_base_env($maintActive['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: maintenance already active rejected');
orange_restore_merge_maintenance_disable($maintActive['workRoot'], $maintActive['jobId']);
orange_restore_release_lock($maintActive['workRoot']);
merge_foundation_rmdir($maintActive['backupRoot']);

// --- lock missing ---
$noLock = merge_foundation_seed_approved_job();
$err = merge_foundation_try(static function () use ($noLock, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $noLock['workRoot'],
        'job_id' => $noLock['jobId'],
        'env_override' => merge_foundation_base_env($noLock['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: lock missing rejected');
merge_foundation_rmdir($noLock['backupRoot']);

// --- lock conflict ---
$lockConflict = merge_foundation_seed_approved_job();
orange_restore_acquire_lock($lockConflict['workRoot'], 'other_job');
$err = merge_foundation_try(static function () use ($lockConflict, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $lockConflict['workRoot'],
        'job_id' => $lockConflict['jobId'],
        'env_override' => merge_foundation_base_env($lockConflict['backupRoot']),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: lock conflict rejected');
orange_restore_release_lock($lockConflict['workRoot']);
merge_foundation_rmdir($lockConflict['backupRoot']);

// --- duplicate staging user in merge credentials ---
$dupStaging = merge_foundation_seed_approved_job();
orange_restore_acquire_lock($dupStaging['workRoot'], $dupStaging['jobId']);
$err = merge_foundation_try(static function () use ($dupStaging, $projectRoot): void {
    orange_restore_merge_precheck_run([
        'project_root' => $projectRoot,
        'work_root' => $dupStaging['workRoot'],
        'job_id' => $dupStaging['jobId'],
        'env_override' => array_merge(merge_foundation_base_env($dupStaging['backupRoot']), [
            ORANGE_RESTORE_ENV_MERGE_DB_USER => 'orange_restore_staging',
        ]),
        'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
        'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging'),
    ]);
});
merge_foundation_self_test($err !== null, 'precheck: duplicate staging merge user rejected');
orange_restore_release_lock($dupStaging['workRoot']);
merge_foundation_rmdir($dupStaging['backupRoot']);

// --- successful precheck ---
$success = merge_foundation_seed_approved_job();
orange_restore_acquire_lock($success['workRoot'], $success['jobId']);
$result = orange_restore_merge_precheck_run([
    'project_root' => $projectRoot,
    'work_root' => $success['workRoot'],
    'job_id' => $success['jobId'],
    'env_override' => merge_foundation_base_env($success['backupRoot']),
    'merge_pdo_override' => new MergeFoundationIdentityMockPdo('orange_db'),
    'staging_pdo_override' => new MergeFoundationIdentityMockPdo('orange_restore_staging', [
        'GRANT ALL PRIVILEGES ON `orange_restore_staging`.* TO \'staging\'@\'localhost\'',
    ]),
]);
merge_foundation_self_test(
    ($result['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED,
    'precheck: successful transition'
);
merge_foundation_self_test(($result['production_writes'] ?? true) === false, 'precheck: no production writes');
$auditRows = orange_restore_audit_read_all($success['workRoot'], $success['jobId']);
$hasStarted = false;
$hasPassed = false;
foreach ($auditRows as $row) {
    if (($row['merge_event'] ?? '') === 'merge_precheck_started') {
        $hasStarted = true;
    }
    if (($row['merge_event'] ?? '') === 'merge_precheck_passed') {
        $hasPassed = true;
    }
}
merge_foundation_self_test($hasStarted && $hasPassed, 'audit: precheck started/passed recorded');
orange_restore_release_lock($success['workRoot']);
merge_foundation_rmdir($success['backupRoot']);

// --- state machine ---
merge_foundation_self_test(
    in_array(ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED, orange_restore_job_allowed_statuses(), true),
    'state: merge_precheck_passed allowed'
);
try {
    orange_restore_job_assert_merge_foundation_transition(
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED
    );
    merge_foundation_self_test(true, 'state: approved -> merge_precheck_passed');
} catch (Throwable $e) {
    merge_foundation_self_test(false, 'state: approved -> merge_precheck_passed');
}
try {
    orange_restore_job_assert_merge_foundation_transition(
        ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE,
        ORANGE_RESTORE_JOB_STATUS_MERGED
    );
    merge_foundation_self_test(false, 'state: direct production_merged blocked');
} catch (Throwable $e) {
    merge_foundation_self_test(true, 'state: direct production_merged blocked');
}

merge_foundation_rmdir($projectRoot);

exit($failures > 0 ? 1 : 0);
