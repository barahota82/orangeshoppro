<?php

declare(strict_types=1);

/**
 * Phase 2D.4 — Production post-validation + manual rollback self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_post_validation_rollback.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_audit.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_reauth.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_maintenance.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_uploads_fs.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter_production.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_post_validation.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_rollback.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_merge_uploads_cutover.php';
require_once $repoRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function pvrb_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function pvrb_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2d4_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function pvrb_rmdir(string $dir): void
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
            pvrb_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function pvrb_try(callable $fn): ?Throwable
{
    try {
        $fn();

        return null;
    } catch (Throwable $e) {
        return $e;
    }
}

function pvrb_write_file(string $path, string $contents): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Cannot write test file: ' . $path);
    }
}

function pvrb_write_uploads_tree(string $root, array $files): void
{
    foreach ($files as $relative => $contents) {
        pvrb_write_file($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative), $contents);
    }
}

function pvrb_test_pdo(string $permKey = 'backup_restore_full', bool $superuser = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $hash = password_hash('correct-pass', PASSWORD_DEFAULT);
    $pdo->exec(
        'CREATE TABLE admins (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            display_name TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            is_superuser INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'CREATE TABLE admin_permissions (
            admin_id INTEGER NOT NULL,
            resource_key TEXT NOT NULL,
            can_view INTEGER NOT NULL DEFAULT 0,
            can_edit INTEGER NOT NULL DEFAULT 0,
            can_delete INTEGER NOT NULL DEFAULT 0
        )'
    );
    $pdo->exec(
        'INSERT INTO admins (id, username, password_hash, display_name, is_active, is_superuser)
         VALUES (1, \'superadmin\', ' . $pdo->quote($hash) . ', \'Super Admin\', 1, ' . ($superuser ? '1' : '0') . ')'
    );
    if ($permKey !== '') {
        $pdo->exec(
            'INSERT INTO admin_permissions (admin_id, resource_key, can_view, can_edit, can_delete)
             VALUES (1, ' . $pdo->quote($permKey) . ', 1, 0, 0)'
        );
    }

    $GLOBALS['orange_schema_table_cache'] = [
        'admins' => true,
        'admin_permissions' => true,
    ];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

function pvrb_create_anchor(string $backupRoot, string $suffix = ''): array
{
    $anchorDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'anchor' . $suffix . '_' . bin2hex(random_bytes(2));
    mkdir($anchorDir, 0775, true);
    $dumpFile = 'dump.sql.gz';
    $uploadsFile = 'uploads.zip';
    $dumpPath = $anchorDir . DIRECTORY_SEPARATOR . $dumpFile;
    $uploadsPath = $anchorDir . DIRECTORY_SEPARATOR . $uploadsFile;
    $dumpSql = "-- Orange Phase 1A PDO SQL export\n"
        . "CREATE TABLE `orange_restore_self_test` (`id` INT NOT NULL);\n"
        . "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    $gz = gzencode($dumpSql);
    if ($gz === false) {
        throw new RuntimeException('Cannot build test rollback dump.');
    }
    pvrb_write_file($dumpPath, $gz);
    orange_country_uploads_write_empty_zip($uploadsPath);

    $health = [
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'package_status' => 'healthy',
        'failure_reasons' => [],
        'warnings' => [],
    ];
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'package_version' => ORANGE_BACKUP_FULL_PACKAGE_VERSION,
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'dump_file' => $dumpFile,
        'uploads_file' => $uploadsFile,
        'dump_sha256' => orange_backup_sha256_file($dumpPath),
        'uploads_sha256' => orange_backup_sha256_file($uploadsPath),
        'dump_size_bytes' => filesize($dumpPath) ?: 0,
        'uploads_size_bytes' => filesize($uploadsPath) ?: 0,
        'backup_status' => 'success',
        'health_report_file' => ORANGE_BACKUP_HEALTH_FILE,
        'checksums_file' => ORANGE_BACKUP_CHECKSUMS_FILE,
    ]);
    orange_backup_write_json($anchorDir . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE, $health);
    orange_backup_write_checksums($anchorDir, ['manifest.json', $dumpFile, $uploadsFile, ORANGE_BACKUP_HEALTH_FILE]);
    $checksum = orange_backup_sha256_file($anchorDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    return ['path' => $anchorDir, 'checksum' => $checksum];
}

/**
 * @return array<string, mixed>
 */
function pvrb_seed_uploads_cutover_complete_job(string $entryStatus = ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE): array
{
    $backupRoot = pvrb_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    mkdir($workRoot);

    $projectRoot = $backupRoot . DIRECTORY_SEPARATOR . 'project';
    mkdir($projectRoot);
    pvrb_write_file(
        $projectRoot . DIRECTORY_SEPARATOR . 'config.php',
        "<?php\n"
        . "if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }\n"
        . "if (!defined('DB_NAME')) { define('DB_NAME', 'orange_db'); }\n"
    );
    pvrb_write_file(
        $projectRoot . DIRECTORY_SEPARATOR . '.env.php',
        "<?php\nreturn ['DB_USER' => 'orange_restore_test', 'DB_PASS' => 'test'];\n"
    );

    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_' . bin2hex(random_bytes(2));
    mkdir($packageDir, 0775, true);
    orange_backup_write_json($packageDir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'full_disaster',
        'dump_sha256' => str_repeat('c', 64),
    ]);
    orange_backup_write_checksums($packageDir, ['manifest.json']);
    $packageChecksum = orange_backup_sha256_file($packageDir . DIRECTORY_SEPARATOR . 'checksums.sha256');

    $anchor = pvrb_create_anchor($backupRoot);

    $job = orange_restore_job_create($workRoot, [
        'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_path' => $packageDir,
        'source_package_checksum' => $packageChecksum,
        'schema_revision' => 121,
        'operator_admin_id' => 1,
        'operator_username' => 'superadmin',
    ]);
    $jobId = (string) $job['job_id'];

    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED);
    orange_restore_job_record_fresh_backup_anchor($workRoot, $jobId, $anchor['path'], $anchor['checksum']);

    $stagingUploadsDir = orange_restore_staging_uploads_directory($workRoot, $jobId);
    pvrb_write_uploads_tree($stagingUploadsDir, ['products/a.webp' => 'live-a']);

    $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($manifestPath, [
        'staging_uploads_path' => $stagingUploadsDir,
        'schema_revision' => 121,
        'staging_post_validation' => ['table_count' => 10],
        'table_count' => 10,
    ]);
    $manifestChecksum = orange_backup_sha256_file($manifestPath);

    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    pvrb_write_uploads_tree($uploadsDir, ['products/a.webp' => 'live-a']);
    $liveInventory = orange_restore_uploads_tree_inventory($uploadsDir);

    orange_backup_write_json(orange_restore_uploads_next_manifest_path($workRoot, $jobId), [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'verified' => true,
        'file_count' => $liveInventory['file_count'],
        'total_size_bytes' => $liveInventory['total_size'],
        'aggregate_tree_checksum' => $liveInventory['tree_checksum_sha256'],
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
    ]);

    $binding = [
        'job_id' => $jobId,
        'operator_id' => 1,
        'scope' => ORANGE_RESTORE_JOB_TYPE_FULL,
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchor['checksum'],
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job['status'] = $entryStatus;
    $job['approval_token_hash'] = $issued['hash'];
    $job['approval_token_binding'] = $binding;
    $job['approval_token_consumed_at'] = gmdate('c');
    $job['production_merge_approved'] = true;
    $job['staging_restore_manifest_path'] = $manifestPath;
    $job['uploads_cutover_completed_at'] = gmdate('c');
    orange_restore_job_write($workRoot, $job);

    $env = [
        'ORANGE_BACKUP_ROOT' => $backupRoot,
        'ORANGE_RESTORE_STAGING_DB' => 'orange_restore_staging',
    ];

    return [
        'workRoot' => $workRoot,
        'backupRoot' => $backupRoot,
        'projectRoot' => $projectRoot,
        'jobId' => $jobId,
        'job' => orange_restore_job_read($workRoot, $jobId),
        'env' => $env,
        'anchor' => $anchor,
        'packageChecksum' => $packageChecksum,
        'manifestChecksum' => $manifestChecksum,
        'adminPdo' => pvrb_test_pdo(),
        'mergePdo' => pvrb_test_pdo(),
    ];
}

function pvrb_prepare_runtime(array $seed): void
{
    orange_restore_acquire_lock($seed['workRoot'], $seed['jobId']);
    orange_restore_merge_maintenance_enable($seed['workRoot'], $seed['jobId']);
}

function pvrb_copy_registry(string $projectRoot): void
{
    $src = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'backup_table_registry.json';
    $destDir = $projectRoot . DIRECTORY_SEPARATOR . 'config';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    if (!copy($src, $destDir . DIRECTORY_SEPARATOR . 'backup_table_registry.json')) {
        throw new RuntimeException('Cannot copy backup_table_registry.json for self-test project.');
    }
}

/**
 * @param array<string, array<string, mixed>> $tables
 */
function pvrb_write_registry_tables(string $projectRoot, array $tables): void
{
    $destDir = $projectRoot . DIRECTORY_SEPARATOR . 'config';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0775, true);
    }
    orange_backup_write_json($destDir . DIRECTORY_SEPARATOR . 'backup_table_registry.json', [
        'registry_version' => ORANGE_BACKUP_REGISTRY_VERSION,
        'schema_revision' => 121,
        'generated_at' => gmdate('c'),
        'generated_by' => 'self-test',
        'table_count' => count($tables),
        'tables' => $tables,
    ]);
}

/**
 * @return array<string, mixed>
 */
function pvrb_registry_global(int $order = 1, bool $critical = false): array
{
    return orange_backup_registry_row(
        'global',
        $order,
        orange_backup_registry_full_table(),
        null,
        false,
        $critical
    );
}

/**
 * @return array<string, mixed>
 */
function pvrb_registry_country_owned(array $rule, int $order = 50, bool $critical = false): array
{
    return orange_backup_registry_row(
        'country_owned',
        $order,
        $rule,
        null,
        false,
        $critical
    );
}

function pvrb_sqlite_cross_country_pdo(bool $contaminated = false): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO countries (id) VALUES (1), (2)');
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, country_id INTEGER)');
    if ($contaminated) {
        $pdo->exec('INSERT INTO products (id, country_id) VALUES (1, 999)');
    } else {
        $pdo->exec('INSERT INTO products (id, country_id) VALUES (1, 1)');
    }

    return $pdo;
}

/**
 * @param list<string> $productionTables
 * @return array{passed:bool,gate:array<string,mixed>}
 */
function pvrb_eval_critical_row_gate(PDO $productionPdo, PDO $stagingPdo, array $productionTables, string $stagingDb): array
{
    $criticalRowChecks = [];
    $criticalMismatch = false;
    foreach (ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES as $tableName) {
        if (!in_array($tableName, $productionTables, true)) {
            $criticalMismatch = true;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'error' => 'Critical table missing from production schema.',
                'ok' => false,
            ];
            continue;
        }
        $quoted = '`' . str_replace('`', '``', $tableName) . '`';
        try {
            orange_restore_staging_assert_safe_target($stagingPdo, $stagingDb);
            $expectedCount = (int) ($stagingPdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn() ?: 0);
            $liveCount = (int) ($productionPdo->query('SELECT COUNT(*) FROM ' . $quoted)->fetchColumn() ?: 0);
            $ok = $liveCount === $expectedCount;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'expected_rows' => $expectedCount,
                'live_rows' => $liveCount,
                'ok' => $ok,
            ];
            if (!$ok) {
                $criticalMismatch = true;
            }
        } catch (Throwable $e) {
            $criticalMismatch = true;
            $criticalRowChecks[] = [
                'table' => $tableName,
                'error' => 'Critical table unreadable or staging compare failed: ' . $e->getMessage(),
                'ok' => false,
            ];
        }
    }
    $gate = orange_restore_validation_adapter_production_gate(
        'critical_row_counts',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        !$criticalMismatch && count($criticalRowChecks) === count(ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES),
        !$criticalMismatch
            ? 'Critical row counts match validated staging.'
            : 'Critical row count validation failed (missing/unreadable/mismatch).',
        ['checks' => $criticalRowChecks]
    );

    return ['passed' => (bool) ($gate['passed'] ?? false), 'gate' => $gate];
}

function pvrb_sqlite_critical_tables_pdo(bool $unreadableTable = false): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES as $tableName) {
        if ($unreadableTable && $tableName === 'inventory_cost_layers') {
            $pdo->exec('CREATE VIEW inventory_cost_layers AS SELECT id FROM __missing_inventory_cost_layers__');
            continue;
        }
        $pdo->exec('CREATE TABLE ' . $tableName . ' (id INTEGER PRIMARY KEY)');
    }

    return $pdo;
}

// --- gate summarizer: warnings do not bypass hard failures ---
$summary = orange_restore_validation_adapter_summarize_gates([
    orange_restore_validation_adapter_production_gate('hard_gate', ORANGE_RESTORE_PRODUCTION_GATE_HARD, false, 'fail'),
    orange_restore_validation_adapter_production_gate('warn_gate', ORANGE_RESTORE_PRODUCTION_GATE_WARNING, false, 'warn only'),
]);
pvrb_self_test($summary['passed'] === false && count($summary['hard_failures']) === 1, 'gates: warnings do not bypass hard failures');

// --- ROLLBACK phrase ---
pvrb_self_test(orange_restore_validate_rollback_phrase('ROLLBACK'), 'rollback phrase: exact ROLLBACK accepted');
pvrb_self_test(!orange_restore_validate_rollback_phrase('rollback'), 'rollback phrase: lowercase rejected');
pvrb_self_test(!orange_restore_validate_rollback_phrase('RESTORE'), 'rollback phrase: RESTORE rejected');

// --- post-validation: wrong entry state ---
$wrongState = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE);
pvrb_prepare_runtime($wrongState);
$err = pvrb_try(static function () use ($wrongState): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $wrongState['projectRoot'],
        'work_root' => $wrongState['workRoot'],
        'job_id' => $wrongState['jobId'],
        'admin_id' => 1,
        'env_override' => $wrongState['env'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'uploads_cutover_complete'), 'post-validation: wrong entry state rejected');
orange_restore_release_lock($wrongState['workRoot']);
pvrb_rmdir($wrongState['backupRoot']);

// --- post-validation: lock missing ---
$noLock = pvrb_seed_uploads_cutover_complete_job();
$err = pvrb_try(static function () use ($noLock): void {
    orange_restore_merge_maintenance_enable($noLock['workRoot'], $noLock['jobId']);
    orange_restore_merge_post_validation_run([
        'project_root' => $noLock['projectRoot'],
        'work_root' => $noLock['workRoot'],
        'job_id' => $noLock['jobId'],
        'admin_id' => 1,
        'env_override' => $noLock['env'],
    ]);
});
pvrb_self_test($err !== null, 'post-validation: lock missing rejected');
pvrb_rmdir($noLock['backupRoot']);

// --- post-validation: maintenance missing ---
$noMaint = pvrb_seed_uploads_cutover_complete_job();
orange_restore_acquire_lock($noMaint['workRoot'], $noMaint['jobId']);
$err = pvrb_try(static function () use ($noMaint): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $noMaint['projectRoot'],
        'work_root' => $noMaint['workRoot'],
        'job_id' => $noMaint['jobId'],
        'admin_id' => 1,
        'env_override' => $noMaint['env'],
    ]);
});
pvrb_self_test($err !== null, 'post-validation: maintenance missing rejected');
orange_restore_release_lock($noMaint['workRoot']);
pvrb_rmdir($noMaint['backupRoot']);

// --- post-validation: hard failure -> failed_post_merge ---
$hardFail = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($hardFail);
$err = pvrb_try(static function () use ($hardFail): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $hardFail['projectRoot'],
        'work_root' => $hardFail['workRoot'],
        'job_id' => $hardFail['jobId'],
        'admin_id' => 1,
        'env_override' => $hardFail['env'],
        'postcheck_override' => static fn (): array => [
            'ok' => false,
            'overall_result' => 'fail',
            'hard_failures' => ['schema_revision_exact_match: mismatch'],
            'warnings' => ['sample_warning'],
            'informational' => [],
            'gates' => [],
            'production_db' => 'orange_db',
            'schema_revision' => 121,
        ],
    ]);
});
$jobAfterFail = orange_restore_job_read($hardFail['workRoot'], $hardFail['jobId']);
$maintAfterFail = orange_restore_merge_maintenance_status($hardFail['workRoot']);
pvrb_self_test($err !== null, 'post-validation: hard failure throws');
pvrb_self_test(
    ($jobAfterFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
    'post-validation: hard failure -> failed_post_merge'
);
pvrb_self_test(($maintAfterFail['active'] ?? false) === true, 'post-validation: maintenance stays active on hard failure');
pvrb_self_test(is_file(orange_restore_production_post_validation_report_path($hardFail['workRoot'], $hardFail['jobId'])), 'post-validation: report written on failure');
orange_restore_release_lock($hardFail['workRoot']);
pvrb_rmdir($hardFail['backupRoot']);

// --- post-validation: success -> completed only after maintenance disable ---
$success = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($success);
$result = orange_restore_merge_post_validation_run([
    'project_root' => $success['projectRoot'],
    'work_root' => $success['workRoot'],
    'job_id' => $success['jobId'],
    'admin_id' => 1,
    'env_override' => $success['env'],
    'postcheck_override' => static fn (): array => [
        'ok' => true,
        'overall_result' => 'pass',
        'hard_failures' => [],
        'warnings' => ['non_blocking_warning'],
        'informational' => ['maintenance_active_during_validation: ok'],
        'gates' => [
            ['gate_id' => 'table_count_exact_match', 'details' => ['expected' => 10, 'live' => 10]],
            ['gate_id' => 'critical_row_counts', 'details' => ['checks' => []]],
            ['gate_id' => 'gl_debit_credit_balance', 'details' => ['difference' => 0.0]],
            ['gate_id' => 'uploads_checksum_match', 'details' => ['match' => true]],
        ],
        'production_db' => 'orange_db',
        'schema_revision' => 121,
    ],
]);
$jobDone = orange_restore_job_read($success['workRoot'], $success['jobId']);
$maintDone = orange_restore_merge_maintenance_status($success['workRoot']);
pvrb_self_test(($result['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED, 'post-validation: success -> completed');
pvrb_self_test(($maintDone['active'] ?? true) === false, 'post-validation: maintenance disabled after pass');
pvrb_self_test(is_file(orange_restore_final_restore_report_path($success['workRoot'], $success['jobId'])), 'post-validation: final_restore_report.json written');
$auditSuccess = orange_restore_audit_read_all($success['workRoot'], $success['jobId']);
$auditEvents = array_column($auditSuccess, 'post_validation_event');
pvrb_self_test(in_array('restore_completed', $auditEvents, true), 'post-validation: restore_completed audit event');
orange_restore_release_lock($success['workRoot']);
pvrb_rmdir($success['backupRoot']);

// --- rollback: wrong state (completed) ---
$completed = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_COMPLETED);
pvrb_prepare_runtime($completed);
$err = pvrb_try(static function () use ($completed): void {
    orange_restore_merge_rollback_run([
        'project_root' => $completed['projectRoot'],
        'work_root' => $completed['workRoot'],
        'job_id' => $completed['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $completed['env'],
        'admin_pdo_override' => $completed['adminPdo'],
        'merge_pdo_override' => $completed['mergePdo'],
    ]);
});
pvrb_self_test($err !== null, 'rollback: completed state rejected');
orange_restore_release_lock($completed['workRoot']);
pvrb_rmdir($completed['backupRoot']);

// --- rollback: wrong password ---
$badPass = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($badPass);
$err = pvrb_try(static function () use ($badPass): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPass['projectRoot'],
        'work_root' => $badPass['workRoot'],
        'job_id' => $badPass['jobId'],
        'admin_id' => 1,
        'password' => 'wrong-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badPass['env'],
        'admin_pdo_override' => $badPass['adminPdo'],
        'merge_pdo_override' => $badPass['mergePdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'password'), 'rollback: wrong password rejected');
orange_restore_release_lock($badPass['workRoot']);
pvrb_rmdir($badPass['backupRoot']);

// --- rollback: wrong ROLLBACK phrase ---
$badPhrase = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($badPhrase);
$err = pvrb_try(static function () use ($badPhrase): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPhrase['projectRoot'],
        'work_root' => $badPhrase['workRoot'],
        'job_id' => $badPhrase['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'RESTORE',
        'env_override' => $badPhrase['env'],
        'admin_pdo_override' => $badPhrase['adminPdo'],
        'merge_pdo_override' => $badPhrase['mergePdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'ROLLBACK'), 'rollback: wrong confirmation phrase rejected');
orange_restore_release_lock($badPhrase['workRoot']);
pvrb_rmdir($badPhrase['backupRoot']);

// --- rollback: wrong permission (non-superuser) ---
$badPerm = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$badPerm['adminPdo'] = pvrb_test_pdo('backup_restore_full', false);
pvrb_prepare_runtime($badPerm);
$err = pvrb_try(static function () use ($badPerm): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badPerm['projectRoot'],
        'work_root' => $badPerm['workRoot'],
        'job_id' => $badPerm['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badPerm['env'],
        'admin_pdo_override' => $badPerm['adminPdo'],
        'merge_pdo_override' => $badPerm['mergePdo'],
    ]);
});
pvrb_self_test($err !== null, 'rollback: non-superuser rejected');
orange_restore_release_lock($badPerm['workRoot']);
pvrb_rmdir($badPerm['backupRoot']);

// --- rollback: missing anchor ---
$noAnchor = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobNoAnchor = orange_restore_job_read($noAnchor['workRoot'], $noAnchor['jobId']);
$jobNoAnchor['fresh_backup_path'] = '';
$jobNoAnchor['fresh_backup_checksum'] = '';
orange_restore_job_write($noAnchor['workRoot'], $jobNoAnchor);
pvrb_prepare_runtime($noAnchor);
$err = pvrb_try(static function () use ($noAnchor): void {
    orange_restore_merge_rollback_run([
        'project_root' => $noAnchor['projectRoot'],
        'work_root' => $noAnchor['workRoot'],
        'job_id' => $noAnchor['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $noAnchor['env'],
        'admin_pdo_override' => $noAnchor['adminPdo'],
        'merge_pdo_override' => $noAnchor['mergePdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'anchor'), 'rollback: missing anchor rejected');
orange_restore_release_lock($noAnchor['workRoot']);
pvrb_rmdir($noAnchor['backupRoot']);

// --- rollback: anchor checksum mismatch ---
$badChecksum = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobBadCs = orange_restore_job_read($badChecksum['workRoot'], $badChecksum['jobId']);
$jobBadCs['fresh_backup_checksum'] = str_repeat('0', 64);
orange_restore_job_write($badChecksum['workRoot'], $jobBadCs);
pvrb_prepare_runtime($badChecksum);
$err = pvrb_try(static function () use ($badChecksum): void {
    orange_restore_merge_rollback_run([
        'project_root' => $badChecksum['projectRoot'],
        'work_root' => $badChecksum['workRoot'],
        'job_id' => $badChecksum['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $badChecksum['env'],
        'admin_pdo_override' => $badChecksum['adminPdo'],
        'merge_pdo_override' => $badChecksum['mergePdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'checksum'), 'rollback: anchor checksum mismatch rejected');
orange_restore_release_lock($badChecksum['workRoot']);
pvrb_rmdir($badChecksum['backupRoot']);

// --- rollback: another job anchor rejected (job-only flag false) ---
$otherAnchor = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$jobOther = orange_restore_job_read($otherAnchor['workRoot'], $otherAnchor['jobId']);
$jobOther['rollback_anchor_job_only'] = false;
orange_restore_job_write($otherAnchor['workRoot'], $jobOther);
pvrb_prepare_runtime($otherAnchor);
$err = pvrb_try(static function () use ($otherAnchor): void {
    orange_restore_merge_rollback_run([
        'project_root' => $otherAnchor['projectRoot'],
        'work_root' => $otherAnchor['workRoot'],
        'job_id' => $otherAnchor['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $otherAnchor['env'],
        'admin_pdo_override' => $otherAnchor['adminPdo'],
        'merge_pdo_override' => $otherAnchor['mergePdo'],
    ]);
});
pvrb_self_test($err !== null && str_contains($err->getMessage(), 'job-only'), 'rollback: non job-only anchor rejected');
orange_restore_release_lock($otherAnchor['workRoot']);
pvrb_rmdir($otherAnchor['backupRoot']);

// --- rollback: DB failure keeps maintenance active ---
$dbFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($dbFail);
$err = pvrb_try(static function () use ($dbFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $dbFail['projectRoot'],
        'work_root' => $dbFail['workRoot'],
        'job_id' => $dbFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $dbFail['env'],
        'admin_pdo_override' => $dbFail['adminPdo'],
        'merge_pdo_override' => $dbFail['mergePdo'],
        'db_import_override' => static function (): void {
            throw new RuntimeException('Simulated DB rollback failure.');
        },
    ]);
});
$jobDbFail = orange_restore_job_read($dbFail['workRoot'], $dbFail['jobId']);
$maintDbFail = orange_restore_merge_maintenance_status($dbFail['workRoot']);
pvrb_self_test($err !== null, 'rollback: DB failure throws');
pvrb_self_test(($jobDbFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED, 'rollback: DB failure -> rollback_failed');
pvrb_self_test(($maintDbFail['active'] ?? false) === true, 'rollback: DB failure keeps maintenance active');
orange_restore_release_lock($dbFail['workRoot']);
pvrb_rmdir($dbFail['backupRoot']);

// --- rollback: crash after DB before uploads (resume checkpoint) ---
$crashUploads = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS);
$jobCrash = orange_restore_job_read($crashUploads['workRoot'], $crashUploads['jobId']);
$jobCrash['rollback_checkpoint'] = ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE;
orange_restore_job_write($crashUploads['workRoot'], $jobCrash);
pvrb_prepare_runtime($crashUploads);
$uploadsCalled = false;
$resultCrash = orange_restore_merge_rollback_run([
    'project_root' => $crashUploads['projectRoot'],
    'work_root' => $crashUploads['workRoot'],
    'job_id' => $crashUploads['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'ROLLBACK',
    'env_override' => $crashUploads['env'],
    'admin_pdo_override' => $crashUploads['adminPdo'],
    'merge_pdo_override' => $crashUploads['mergePdo'],
    'db_import_override' => static function (): void {
        throw new RuntimeException('DB must not re-run when checkpoint is database_complete.');
    },
    'uploads_rollback_override' => static function () use (&$uploadsCalled): void {
        $uploadsCalled = true;
    },
    'rollback_postcheck_override' => static fn (): array => [
        'ok' => true,
        'hard_failures' => [],
        'warnings' => [],
        'overall_result' => 'pass',
    ],
]);
pvrb_self_test($uploadsCalled, 'rollback: resumes uploads after database_complete checkpoint');
pvrb_self_test(($resultCrash['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK, 'rollback: crash resume -> rolled_back');
orange_restore_release_lock($crashUploads['workRoot']);
pvrb_rmdir($crashUploads['backupRoot']);

// --- rollback: uploads failure ---
$uploadsFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($uploadsFail);
$err = pvrb_try(static function () use ($uploadsFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $uploadsFail['projectRoot'],
        'work_root' => $uploadsFail['workRoot'],
        'job_id' => $uploadsFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $uploadsFail['env'],
        'admin_pdo_override' => $uploadsFail['adminPdo'],
        'merge_pdo_override' => $uploadsFail['mergePdo'],
        'db_import_override' => static function (): void {
            // ok
        },
        'uploads_rollback_override' => static function (): void {
            throw new RuntimeException('Simulated uploads rollback failure.');
        },
    ]);
});
pvrb_self_test($err !== null, 'rollback: uploads failure throws');
orange_restore_release_lock($uploadsFail['workRoot']);
pvrb_rmdir($uploadsFail['backupRoot']);

// --- rollback: validation failure after uploads ---
$valFail = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($valFail);
$err = pvrb_try(static function () use ($valFail): void {
    orange_restore_merge_rollback_run([
        'project_root' => $valFail['projectRoot'],
        'work_root' => $valFail['workRoot'],
        'job_id' => $valFail['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $valFail['env'],
        'admin_pdo_override' => $valFail['adminPdo'],
        'merge_pdo_override' => $valFail['mergePdo'],
        'db_import_override' => static function (): void {},
        'uploads_rollback_override' => static function (): void {},
        'rollback_postcheck_override' => static fn (): array => [
            'ok' => false,
            'hard_failures' => ['rollback_super_admin_present: missing'],
            'warnings' => [],
            'overall_result' => 'fail',
        ],
    ]);
});
$jobValFail = orange_restore_job_read($valFail['workRoot'], $valFail['jobId']);
$maintValFail = orange_restore_merge_maintenance_status($valFail['workRoot']);
pvrb_self_test($err !== null, 'rollback: validation failure throws');
pvrb_self_test(($jobValFail['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED, 'rollback: validation failure -> rollback_failed');
pvrb_self_test(($maintValFail['active'] ?? false) === true, 'rollback: validation failure keeps maintenance active');
orange_restore_release_lock($valFail['workRoot']);
pvrb_rmdir($valFail['backupRoot']);

// --- rollback: successful full path -> rolled_back ---
$rbOk = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$preMergeDir = orange_restore_uploads_pre_merge_directory($rbOk['projectRoot'], $rbOk['jobId']);
pvrb_write_uploads_tree($preMergeDir, ['products/old.webp' => 'pre-merge']);
pvrb_prepare_runtime($rbOk);
$resultRb = orange_restore_merge_rollback_run([
    'project_root' => $rbOk['projectRoot'],
    'work_root' => $rbOk['workRoot'],
    'job_id' => $rbOk['jobId'],
    'admin_id' => 1,
    'password' => 'correct-pass',
    'confirmation_phrase' => 'ROLLBACK',
    'env_override' => $rbOk['env'],
    'admin_pdo_override' => $rbOk['adminPdo'],
    'merge_pdo_override' => $rbOk['mergePdo'],
    'db_import_override' => static function (): void {},
    'rename_override' => static function (string $from, string $to): void {
        if (!@rename($from, $to)) {
            throw new RuntimeException('Test rename failed.');
        }
    },
    'rollback_postcheck_override' => static fn (): array => [
        'ok' => true,
        'hard_failures' => [],
        'warnings' => [],
        'overall_result' => 'pass',
    ],
]);
$jobRb = orange_restore_job_read($rbOk['workRoot'], $rbOk['jobId']);
$maintRb = orange_restore_merge_maintenance_status($rbOk['workRoot']);
pvrb_self_test(($resultRb['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK, 'rollback: success -> rolled_back');
pvrb_self_test(($resultRb['automatic_rollback'] ?? true) === false, 'rollback: no automatic rollback flag');
pvrb_self_test(($maintRb['active'] ?? true) === false, 'rollback: maintenance disabled after success');
pvrb_self_test(
    ($jobRb['rollback_checkpoint'] ?? '') === ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED,
    'rollback: validation_passed checkpoint persisted'
);
$auditRb = orange_restore_audit_read_all($rbOk['workRoot'], $rbOk['jobId']);
$rbEvents = array_column($auditRb, 'rollback_event');
pvrb_self_test(in_array('rollback_completed', $rbEvents, true), 'rollback: rollback_completed audit event');
orange_restore_release_lock($rbOk['workRoot']);
pvrb_rmdir($rbOk['backupRoot']);

// --- BLOCKER FIX: uploads path validation ---
function pvrb_seed_uploads_validation_project(): array
{
    $projectRoot = pvrb_temp_root();
    mkdir($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products', 0775, true);
    pvrb_copy_registry($projectRoot);
    pvrb_write_file(
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'ok.webp',
        'ok'
    );

    return ['projectRoot' => $projectRoot];
}

$uploadsPathProject = pvrb_seed_uploads_validation_project();
$traversal = orange_restore_validation_adapter_production_validate_upload_reference(
    $uploadsPathProject['projectRoot'],
    'uploads/products/../outside.webp'
);
pvrb_self_test(($traversal['ok'] ?? true) === false, 'blocker-fix: uploads path traversal blocked');
$absolute = orange_restore_validation_adapter_production_validate_upload_reference(
    $uploadsPathProject['projectRoot'],
    'C:/Windows/outside.webp'
);
pvrb_self_test(($absolute['ok'] ?? true) === false, 'blocker-fix: uploads absolute path blocked');
$outside = orange_restore_validation_adapter_production_validate_upload_reference(
    $uploadsPathProject['projectRoot'],
    'uploads/products/../../outside.webp'
);
pvrb_self_test(($outside['ok'] ?? true) === false, 'blocker-fix: uploads outside uploads root blocked');
$validUpload = orange_restore_validation_adapter_production_validate_upload_reference(
    $uploadsPathProject['projectRoot'],
    'ok.webp'
);
pvrb_self_test(($validUpload['ok'] ?? false) === true && ($validUpload['exists'] ?? false) === true, 'blocker-fix: valid uploads reference passes');
pvrb_rmdir($uploadsPathProject['projectRoot']);

$symlinkProject = pvrb_seed_uploads_validation_project();
$symlinkTarget = $symlinkProject['projectRoot'] . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'link.webp';
if (function_exists('symlink')) {
    @symlink(
        $symlinkProject['projectRoot'] . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'ok.webp',
        $symlinkTarget
    );
    $symlinkResult = orange_restore_validation_adapter_production_validate_upload_reference(
        $symlinkProject['projectRoot'],
        'uploads/products/link.webp'
    );
    pvrb_self_test(($symlinkResult['ok'] ?? true) === false, 'blocker-fix: uploads symlink blocked');
}
pvrb_rmdir($symlinkProject['projectRoot']);

$reparseProject = pvrb_seed_uploads_validation_project();
$reparseDir = $reparseProject['projectRoot'] . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'reparse_dir';
mkdir($reparseDir, 0775, true);
pvrb_write_file($reparseDir . DIRECTORY_SEPARATOR . 'inside.webp', 'inside');
orange_restore_uploads_fs_set_test_seam([
    'reparse_point_detector' => static function (string $path): ?bool {
        return str_contains(str_replace('\\', '/', $path), '/reparse_dir') ? true : false;
    },
]);
$reparseResult = orange_restore_validation_adapter_production_validate_upload_reference(
    $reparseProject['projectRoot'],
    'uploads/products/reparse_dir/inside.webp'
);
orange_restore_uploads_fs_clear_test_seam();
pvrb_self_test(($reparseResult['ok'] ?? true) === false, 'blocker-fix: uploads reparse point blocked');
pvrb_rmdir($reparseProject['projectRoot']);

$junctionProject = pvrb_seed_uploads_validation_project();
$junctionDir = $junctionProject['projectRoot'] . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'junction_dir';
mkdir($junctionDir, 0775, true);
pvrb_write_file($junctionDir . DIRECTORY_SEPARATOR . 'inside.webp', 'inside');
orange_restore_uploads_fs_set_test_seam([
    'reparse_point_detector' => static function (string $path): ?bool {
        return str_contains(str_replace('\\', '/', $path), '/junction_dir') ? true : false;
    },
]);
$junctionResult = orange_restore_validation_adapter_production_validate_upload_reference(
    $junctionProject['projectRoot'],
    'uploads/products/junction_dir/inside.webp'
);
orange_restore_uploads_fs_clear_test_seam();
pvrb_self_test(($junctionResult['ok'] ?? true) === false, 'blocker-fix: uploads junction blocked');
pvrb_rmdir($junctionProject['projectRoot']);

// --- BLOCKER FIX: FK / registry dependency validation ---
$fkPdo = pvrb_sqlite_cross_country_pdo(false);
$fkErrors = orange_restore_validation_adapter_production_cross_country_fk_checks($fkPdo, ['countries'], [1, 2]);
pvrb_self_test(
    (bool) array_filter($fkErrors, static fn (string $e): bool => str_contains($e, 'missing table')),
    'blocker-fix: FK table missing is hard failure'
);
$fkColPdo = new PDO('sqlite::memory:');
$fkColPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$fkColPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$fkColPdo->exec('INSERT INTO countries (id) VALUES (1)');
$fkColPdo->exec('CREATE TABLE journal_vouchers (id INTEGER PRIMARY KEY, country_id INTEGER)');
$fkColPdo->exec('CREATE TABLE party_subledger (id INTEGER PRIMARY KEY, voucher_id INTEGER)');
$fkColumnErrors = orange_restore_validation_adapter_production_cross_country_fk_checks(
    $fkColPdo,
    ['countries', 'party_subledger', 'journal_vouchers'],
    [1]
);
pvrb_self_test(
    (bool) array_filter($fkColumnErrors, static fn (string $e): bool => str_contains($e, 'missing column')),
    'blocker-fix: FK column missing is hard failure'
);

$noRegistryProject = pvrb_temp_root();
$noRegistryPdo = new PDO('sqlite::memory:');
$noRegistryPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$noRegistryPdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, main_image TEXT)');
$registryUploadsFail = orange_restore_validation_adapter_production_required_uploads_check(
    $noRegistryPdo,
    $noRegistryProject,
    ['products']
);
pvrb_self_test(
    ($registryUploadsFail['ok'] ?? true) === false
    && str_contains(implode('; ', $registryUploadsFail['scan_errors'] ?? []), 'Registry load failed'),
    'blocker-fix: registry load failure in required uploads check'
);
$registryCrossFail = orange_restore_validation_adapter_production_cross_country_checks(
    $noRegistryPdo,
    $noRegistryProject,
    ['countries']
);
pvrb_self_test(
    (bool) array_filter($registryCrossFail, static fn (string $e): bool => str_contains($e, 'Registry load failed')),
    'blocker-fix: registry load failure in cross-country check'
);
pvrb_rmdir($noRegistryProject);

$depProject = pvrb_temp_root();
pvrb_copy_registry($depProject);
$depPdo = new PDO('sqlite::memory:');
$depPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$depPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$depPdo->exec('INSERT INTO countries (id) VALUES (1)');
$depPdo->exec('CREATE TABLE order_items (id INTEGER PRIMARY KEY, order_id INTEGER, country_id INTEGER)');
$depErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $depPdo,
    $depProject,
    ['countries', 'order_items']
);
pvrb_self_test(
    (bool) array_filter($depErrors, static fn (string $e): bool => str_contains($e, 'parent table missing')),
    'blocker-fix: registry parent missing is hard failure'
);
pvrb_rmdir($depProject);

$malformedProject = pvrb_temp_root();
pvrb_copy_registry($malformedProject);
$malformedRegistryPath = $malformedProject . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'backup_table_registry.json';
$registryData = json_decode((string) file_get_contents($malformedRegistryPath), true);
if (is_array($registryData)) {
    $registryData['tables']['products'] = 'not-an-array';
    orange_backup_write_json($malformedRegistryPath, $registryData);
}
$malformedPdo = pvrb_sqlite_cross_country_pdo(false);
$malformedErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $malformedPdo,
    $malformedProject,
    ['countries', 'products']
);
pvrb_self_test(
    (bool) array_filter($malformedErrors, static fn (string $e): bool => str_contains($e, 'registry metadata invalid')),
    'blocker-fix: malformed registry metadata fails closed'
);
pvrb_rmdir($malformedProject);

// --- BLOCKER FIX: registry load failure reporting ---
$registryFailSeed = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($registryFailSeed);
pvrb_rmdir($registryFailSeed['projectRoot'] . DIRECTORY_SEPARATOR . 'config');
$err = pvrb_try(static function () use ($registryFailSeed): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $registryFailSeed['projectRoot'],
        'work_root' => $registryFailSeed['workRoot'],
        'job_id' => $registryFailSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $registryFailSeed['env'],
        'postcheck_override' => static function (array $ctx) use ($registryFailSeed): array {
            $load = orange_restore_validation_adapter_production_registry_load_safe($registryFailSeed['projectRoot']);

            return orange_restore_validation_adapter_production_postcheck_finalize(
                [
                    orange_restore_validation_adapter_production_gate(
                        'registry_load',
                        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
                        false,
                        'Registry load failed: ' . (string) ($load['error'] ?? 'unknown'),
                        ['registry_failure' => true]
                    ),
                ],
                $ctx['job'],
                orange_restore_production_db_name($registryFailSeed['projectRoot']),
                orange_restore_staging_db_name($registryFailSeed['env'], $registryFailSeed['projectRoot'])
            );
        },
    ]);
});
$registryFailJob = orange_restore_job_read($registryFailSeed['workRoot'], $registryFailSeed['jobId']);
$postReportPath = orange_restore_production_post_validation_report_path($registryFailSeed['workRoot'], $registryFailSeed['jobId']);
$finalReportPath = orange_restore_final_restore_report_path($registryFailSeed['workRoot'], $registryFailSeed['jobId']);
$registryFailAudit = orange_restore_audit_read_all($registryFailSeed['workRoot'], $registryFailSeed['jobId']);
$registryFailEvents = array_column($registryFailAudit, 'post_validation_event');
pvrb_self_test($err !== null, 'blocker-fix: registry load failure throws after structured handling');
pvrb_self_test(
    ($registryFailJob['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
    'blocker-fix: registry load failure -> failed_post_merge'
);
pvrb_self_test(is_file($postReportPath), 'blocker-fix: registry load failure writes production_post_validation.json');
pvrb_self_test(is_file($finalReportPath), 'blocker-fix: registry load failure writes final_restore_report.json');
$postReport = json_decode((string) file_get_contents($postReportPath), true);
pvrb_self_test(
    is_array($postReport)
    && ($postReport['overall_result'] ?? '') === 'fail'
    && ($postReport['hard_failures'] ?? []) !== [],
    'blocker-fix: failed report generation includes hard failures'
);
pvrb_self_test(
    in_array('production_post_validation_failed', $registryFailEvents, true),
    'blocker-fix: failed audit generation records production_post_validation_failed'
);
orange_restore_release_lock($registryFailSeed['workRoot']);
pvrb_rmdir($registryFailSeed['backupRoot']);

// --- BLOCKER 1: maintenance hard gate during validation ---
$maintGateSeed = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($maintGateSeed);
$gateOk = orange_restore_validation_adapter_production_maintenance_hard_gate(
    $maintGateSeed['workRoot'],
    $maintGateSeed['jobId'],
    'schema_and_counts'
);
pvrb_self_test(($gateOk['passed'] ?? false) === true, 'blocker1: maintenance hard gate passes when active');
orange_restore_merge_maintenance_disable($maintGateSeed['workRoot'], $maintGateSeed['jobId']);
$gateLost = orange_restore_validation_adapter_production_maintenance_hard_gate(
    $maintGateSeed['workRoot'],
    $maintGateSeed['jobId'],
    'accounting_inventory_integrity'
);
pvrb_self_test(($gateLost['passed'] ?? true) === false, 'blocker1: maintenance lost during validation fails hard gate');
$summaryMaint = orange_restore_validation_adapter_summarize_gates([$gateLost]);
pvrb_self_test($summaryMaint['passed'] === false, 'blocker1: maintenance lost aborts validation summary');
orange_restore_release_lock($maintGateSeed['workRoot']);
pvrb_rmdir($maintGateSeed['backupRoot']);

// --- BLOCKER 2: cross-country contamination detected ---
$ccProject = pvrb_temp_root();
pvrb_write_registry_tables($ccProject, [
    'countries' => pvrb_registry_global(1, true),
    'products' => pvrb_registry_country_owned(orange_backup_registry_country_id(), 60, true),
]);
$ccPdo = pvrb_sqlite_cross_country_pdo(true);
$ccTables = ['countries', 'products'];
$ccErrors = orange_restore_validation_adapter_production_cross_country_checks($ccPdo, $ccProject, $ccTables);
pvrb_self_test($ccErrors !== [], 'blocker2: cross-country contamination detected');
pvrb_self_test(
    (bool) array_filter($ccErrors, static fn (string $e): bool => str_contains($e, 'products') || str_contains($e, 'foreign country')),
    'blocker2: cross-country error references invalid country rows'
);
$ccClean = pvrb_sqlite_cross_country_pdo(false);
$ccCleanErrors = orange_restore_validation_adapter_production_cross_country_checks($ccClean, $ccProject, $ccTables);
pvrb_self_test($ccCleanErrors === [], 'blocker2: clean cross-country data passes');
pvrb_rmdir($ccProject);

// --- BLOCKER 3: critical table missing / unreadable ---
$critPdo = pvrb_sqlite_critical_tables_pdo(false);
$critTables = [];
$stCrit = $critPdo->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $stCrit->fetch(PDO::FETCH_NUM)) {
    $critTables[] = (string) $row[0];
}
$critTablesMissing = array_values(array_filter(
    $critTables,
    static fn (string $t): bool => $t !== 'inventory_cost_layers'
));
$missingGate = pvrb_eval_critical_row_gate($critPdo, $critPdo, $critTablesMissing, 'orange_restore_staging');
pvrb_self_test($missingGate['passed'] === false, 'blocker3: critical table missing fails hard');
$unreadableGate = pvrb_eval_critical_row_gate(
    pvrb_sqlite_critical_tables_pdo(true),
    pvrb_sqlite_critical_tables_pdo(true),
    ORANGE_RESTORE_PRODUCTION_CRITICAL_TABLES,
    'orange_restore_staging'
);
pvrb_self_test($unreadableGate['passed'] === false, 'blocker3: critical table unreadable fails hard');

// --- BLOCKER 4: required uploads missing / unverifiable ---
$uploadsProject = pvrb_temp_root();
pvrb_copy_registry($uploadsProject);
$uploadsPdo = new PDO('sqlite::memory:');
$uploadsPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$uploadsPdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, main_image TEXT)');
$uploadsPdo->exec("INSERT INTO products (id, main_image) VALUES (1, 'missing.webp')");
$uploadsDir = $uploadsProject . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'products';
mkdir($uploadsDir, 0775, true);
$missingUploads = orange_restore_validation_adapter_production_required_uploads_check(
    $uploadsPdo,
    $uploadsProject,
    ['products']
);
pvrb_self_test(($missingUploads['ok'] ?? true) === false, 'blocker4: required uploads missing fails');
pvrb_self_test(($missingUploads['verifiable'] ?? false) === true, 'blocker4: missing uploads remains verifiable');
$noRegistryProject = pvrb_temp_root();
$unverifiableUploads = orange_restore_validation_adapter_production_required_uploads_check(
    $uploadsPdo,
    $noRegistryProject,
    ['products']
);
pvrb_self_test(($unverifiableUploads['ok'] ?? true) === false, 'blocker4: required uploads unverifiable fails');
pvrb_self_test(($unverifiableUploads['verifiable'] ?? true) === false, 'blocker4: unverifiable uploads flagged');
pvrb_rmdir($uploadsProject);
pvrb_rmdir($noRegistryProject);

// --- BLOCKER 5: rollback uploads source enforcement ---
$rbNoSource = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($rbNoSource);
$err = pvrb_try(static function () use ($rbNoSource): void {
    orange_restore_merge_rollback_run([
        'project_root' => $rbNoSource['projectRoot'],
        'work_root' => $rbNoSource['workRoot'],
        'job_id' => $rbNoSource['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $rbNoSource['env'],
        'admin_pdo_override' => $rbNoSource['adminPdo'],
        'merge_pdo_override' => $rbNoSource['mergePdo'],
        'db_import_override' => static function (): void {},
    ]);
});
$jobNoSource = orange_restore_job_read($rbNoSource['workRoot'], $rbNoSource['jobId']);
pvrb_self_test($err !== null, 'blocker5: rollback with missing uploads source throws');
pvrb_self_test(
    ($jobNoSource['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
    'blocker5: rollback with missing uploads source -> rollback_failed'
);
pvrb_self_test(
    ($jobNoSource['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
    'blocker5: rollback must not reach rolled_back without uploads source'
);
orange_restore_release_lock($rbNoSource['workRoot']);
pvrb_rmdir($rbNoSource['backupRoot']);

$rbCorruptSnap = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$preMergeCorrupt = orange_restore_uploads_pre_merge_directory($rbCorruptSnap['projectRoot'], $rbCorruptSnap['jobId']);
pvrb_write_uploads_tree($preMergeCorrupt, ['products/old.webp' => 'pre-merge']);
$snapDir = orange_restore_pre_merge_uploads_snapshot_directory($rbCorruptSnap['workRoot'], $rbCorruptSnap['jobId']);
mkdir($snapDir, 0775, true);
orange_backup_write_json(orange_restore_pre_merge_uploads_snapshot_manifest_path($rbCorruptSnap['workRoot'], $rbCorruptSnap['jobId']), [
    'file_count' => 999,
    'tree_checksum_sha256' => str_repeat('0', 64),
]);
pvrb_prepare_runtime($rbCorruptSnap);
$err = pvrb_try(static function () use ($rbCorruptSnap): void {
    orange_restore_merge_rollback_run([
        'project_root' => $rbCorruptSnap['projectRoot'],
        'work_root' => $rbCorruptSnap['workRoot'],
        'job_id' => $rbCorruptSnap['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $rbCorruptSnap['env'],
        'admin_pdo_override' => $rbCorruptSnap['adminPdo'],
        'merge_pdo_override' => $rbCorruptSnap['mergePdo'],
        'db_import_override' => static function (): void {},
    ]);
});
$jobCorruptSnap = orange_restore_job_read($rbCorruptSnap['workRoot'], $rbCorruptSnap['jobId']);
pvrb_self_test($err !== null, 'blocker5: rollback with corrupt snapshot throws');
pvrb_self_test(
    ($jobCorruptSnap['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
    'blocker5: corrupt snapshot -> rollback_failed'
);
orange_restore_release_lock($rbCorruptSnap['workRoot']);
pvrb_rmdir($rbCorruptSnap['backupRoot']);

$rbCorruptPre = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
$preMergeBad = orange_restore_uploads_pre_merge_directory($rbCorruptPre['projectRoot'], $rbCorruptPre['jobId']);
mkdir($preMergeBad, 0775, true);
$liveUploads = orange_restore_production_uploads_directory($rbCorruptPre['projectRoot']);
pvrb_write_uploads_tree($liveUploads, ['products/live.webp' => 'live']);
orange_restore_merge_uploads_cutover_create_snapshot($rbCorruptPre['workRoot'], $rbCorruptPre['jobId'], $liveUploads);
pvrb_write_file($preMergeBad . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'wrong.webp', 'wrong-tree');
pvrb_prepare_runtime($rbCorruptPre);
$err = pvrb_try(static function () use ($rbCorruptPre): void {
    orange_restore_merge_rollback_run([
        'project_root' => $rbCorruptPre['projectRoot'],
        'work_root' => $rbCorruptPre['workRoot'],
        'job_id' => $rbCorruptPre['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $rbCorruptPre['env'],
        'admin_pdo_override' => $rbCorruptPre['adminPdo'],
        'merge_pdo_override' => $rbCorruptPre['mergePdo'],
        'db_import_override' => static function (): void {},
    ]);
});
$jobCorruptPre = orange_restore_job_read($rbCorruptPre['workRoot'], $rbCorruptPre['jobId']);
pvrb_self_test($err !== null, 'blocker5: rollback with corrupt uploads_pre_merge throws');
pvrb_self_test(
    ($jobCorruptPre['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
    'blocker5: corrupt uploads_pre_merge must not reach rolled_back'
);
orange_restore_release_lock($rbCorruptPre['workRoot']);
pvrb_rmdir($rbCorruptPre['backupRoot']);

$rbPartial = pvrb_seed_uploads_cutover_complete_job(ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE);
pvrb_prepare_runtime($rbPartial);
$err = pvrb_try(static function () use ($rbPartial): void {
    orange_restore_merge_rollback_run([
        'project_root' => $rbPartial['projectRoot'],
        'work_root' => $rbPartial['workRoot'],
        'job_id' => $rbPartial['jobId'],
        'admin_id' => 1,
        'password' => 'correct-pass',
        'confirmation_phrase' => 'ROLLBACK',
        'env_override' => $rbPartial['env'],
        'admin_pdo_override' => $rbPartial['adminPdo'],
        'merge_pdo_override' => $rbPartial['mergePdo'],
        'db_import_override' => static function (): void {},
    ]);
});
$jobPartial = orange_restore_job_read($rbPartial['workRoot'], $rbPartial['jobId']);
pvrb_self_test($err !== null, 'blocker5: DB-only rollback without uploads source throws');
pvrb_self_test(
    ($jobPartial['status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
    'blocker5: rolled_back requires DB and uploads both restored'
);
orange_restore_release_lock($rbPartial['workRoot']);
pvrb_rmdir($rbPartial['backupRoot']);

// --- production gate unit: GL imbalance ---
$glGates = orange_restore_validation_adapter_summarize_gates([
    orange_restore_validation_adapter_production_gate(
        'gl_debit_credit_balance',
        ORANGE_RESTORE_PRODUCTION_GATE_HARD,
        false,
        'GL imbalance',
        ['difference' => 5.0]
    ),
]);
pvrb_self_test($glGates['passed'] === false, 'gates: GL imbalance is hard failure');

// --- production gate unit: orphan / FIFO labels ---
pvrb_self_test(
    ORANGE_RESTORE_PRODUCTION_GL_TOLERANCE === 0.01,
    'gates: GL tolerance is 0.01'
);

/**
 * @return array<string, mixed>
 */
function pvrb_failure_report_seed(): array
{
    $seed = pvrb_seed_uploads_cutover_complete_job();
    $seed['job']['status'] = ORANGE_RESTORE_JOB_STATUS_MERGED;

    return $seed;
}

/**
 * @return array<string, mixed>
 */
function pvrb_failure_report_payload(array $seed): array
{
    return [
        'generated_at' => gmdate('c'),
        'job_id' => $seed['jobId'],
        'overall_result' => 'fail',
        'duration_seconds' => 1,
        'hard_failures' => ['simulated post-validation failure'],
        'warnings' => [],
        'informational' => [],
        'gates' => [],
    ];
}

// --- Phase 2D.4 BLOCKER 1: fail-safe failure reporting ---
$reportSeed = pvrb_failure_report_seed();
$reportPayload = pvrb_failure_report_payload($reportSeed);
$markFailResult = orange_restore_merge_post_validation_record_failure(
    $reportSeed['workRoot'],
    $reportSeed['jobId'],
    1,
    $reportSeed['job'],
    $reportPayload,
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
    ]
);
pvrb_self_test(
    ($markFailResult['persisted']['failed_post_merge'] ?? true) === false
    && isset($reportSeed['jobId'], $markFailResult['reporting_errors']['failed_post_merge']),
    'blocker1-reporting: job transition write failure recorded'
);
pvrb_self_test(
    ($markFailResult['persisted']['production_post_validation.json'] ?? false) === true,
    'blocker1-reporting: remaining steps run after failed_post_merge failure'
);
pvrb_rmdir($reportSeed['backupRoot']);

$jsonFailSeed = pvrb_failure_report_seed();
$jsonFailResult = orange_restore_merge_post_validation_record_failure(
    $jsonFailSeed['workRoot'],
    $jsonFailSeed['jobId'],
    1,
    $jsonFailSeed['job'],
    pvrb_failure_report_payload($jsonFailSeed),
    1,
    [
        'write_json_override' => static function (string $path, array $payload): void {
            if (str_contains($path, 'production_post_validation.json')) {
                throw new RuntimeException('Simulated production_post_validation.json write failure.');
            }
            orange_backup_write_json($path, $payload);
        },
    ]
);
pvrb_self_test(
    ($jsonFailResult['persisted']['production_post_validation.json'] ?? true) === false
    && isset($jsonFailResult['reporting_errors']['production_post_validation.json']),
    'blocker1-reporting: production_post_validation.json write failure recorded'
);
pvrb_self_test(
    ($jsonFailResult['persisted']['final_restore_report.json'] ?? false) === true,
    'blocker1-reporting: final_restore_report.json still attempted after post-validation json failure'
);
pvrb_rmdir($jsonFailSeed['backupRoot']);

$finalFailSeed = pvrb_failure_report_seed();
$finalFailResult = orange_restore_merge_post_validation_record_failure(
    $finalFailSeed['workRoot'],
    $finalFailSeed['jobId'],
    1,
    $finalFailSeed['job'],
    pvrb_failure_report_payload($finalFailSeed),
    1,
    [
        'write_json_override' => static function (string $path, array $payload): void {
            if (str_contains($path, 'final_restore_report.json')) {
                throw new RuntimeException('Simulated final_restore_report.json write failure.');
            }
            orange_backup_write_json($path, $payload);
        },
    ]
);
pvrb_self_test(
    ($finalFailResult['persisted']['final_restore_report.json'] ?? true) === false
    && isset($finalFailResult['reporting_errors']['final_restore_report.json']),
    'blocker1-reporting: final_restore_report.json write failure recorded'
);
pvrb_rmdir($finalFailSeed['backupRoot']);

$auditFailSeed = pvrb_failure_report_seed();
$auditFailResult = orange_restore_merge_post_validation_record_failure(
    $auditFailSeed['workRoot'],
    $auditFailSeed['jobId'],
    1,
    $auditFailSeed['job'],
    pvrb_failure_report_payload($auditFailSeed),
    1,
    [
        'audit_append_override' => static function (): void {
            throw new RuntimeException('Simulated audit append failure.');
        },
    ]
);
pvrb_self_test(
    ($auditFailResult['persisted']['production_post_validation_failed_audit'] ?? true) === false
    && isset($auditFailResult['reporting_errors']['production_post_validation_failed_audit']),
    'blocker1-reporting: audit append failure recorded'
);
pvrb_rmdir($auditFailSeed['backupRoot']);

$multiFailSeed = pvrb_failure_report_seed();
$multiFailResult = orange_restore_merge_post_validation_record_failure(
    $multiFailSeed['workRoot'],
    $multiFailSeed['jobId'],
    1,
    $multiFailSeed['job'],
    pvrb_failure_report_payload($multiFailSeed),
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
        'write_json_override' => static function (string $path, array $payload): void {
            if (str_contains($path, 'production_post_validation.json')) {
                throw new RuntimeException('Simulated production_post_validation.json write failure.');
            }
            if (str_contains($path, 'final_restore_report.json')) {
                throw new RuntimeException('Simulated final_restore_report.json write failure.');
            }
            orange_backup_write_json($path, $payload);
        },
        'audit_append_override' => static function (): void {
            throw new RuntimeException('Simulated audit append failure.');
        },
    ]
);
pvrb_self_test(count($multiFailResult['reporting_errors'] ?? []) === 4, 'blocker1-reporting: multiple reporting failures collected');
$multiUnpersisted = array_keys(array_filter(
    is_array($multiFailResult['persisted'] ?? null) ? $multiFailResult['persisted'] : [],
    static fn (bool $ok): bool => !$ok
));
pvrb_self_test(count($multiUnpersisted) === 4, 'blocker1-reporting: all unpersisted artifacts tracked on multi-failure');
pvrb_self_test(
    is_string($multiFailResult['emergency_log_path'] ?? null)
    && ($multiFailResult['emergency_log_path'] ?? '') !== ''
    && is_file((string) $multiFailResult['emergency_log_path']),
    'blocker1-reporting: emergency fallback log written on reporting failures'
);
$originalFailure = new RuntimeException('Original post-validation failure.');
$composed = orange_restore_merge_post_validation_compose_failure_exception(
    $originalFailure,
    $multiFailResult,
    pvrb_failure_report_payload($multiFailSeed)
);
pvrb_self_test(
    str_contains($composed->getMessage(), 'Original error: Original post-validation failure.')
    && str_contains($composed->getMessage(), 'Reporting failures:')
    && str_contains($composed->getMessage(), 'Unpersisted artifacts/events:')
    && str_contains($composed->getMessage(), 'Emergency failure log:'),
    'blocker1-reporting: final exception aggregates original and secondary failures'
);
pvrb_self_test($composed->getPrevious() === $originalFailure, 'blocker1-reporting: original failure preserved as previous exception');
pvrb_rmdir($multiFailSeed['backupRoot']);

$runFailSeed = pvrb_seed_uploads_cutover_complete_job();
pvrb_prepare_runtime($runFailSeed);
$runErr = pvrb_try(static function () use ($runFailSeed): void {
    orange_restore_merge_post_validation_run([
        'project_root' => $runFailSeed['projectRoot'],
        'work_root' => $runFailSeed['workRoot'],
        'job_id' => $runFailSeed['jobId'],
        'admin_id' => 1,
        'env_override' => $runFailSeed['env'],
        'postcheck_override' => static fn (): array => [
            'ok' => false,
            'overall_result' => 'fail',
            'hard_failures' => ['simulated gate failure'],
            'warnings' => [],
            'informational' => [],
            'gates' => [],
            'production_db' => 'orange_db',
            'schema_revision' => 121,
        ],
        'failure_record_override' => [
            'mark_failed_override' => static function (): array {
                throw new RuntimeException('Simulated failed_post_merge transition failure.');
            },
        ],
    ]);
});
pvrb_self_test(
    $runErr !== null
    && str_contains($runErr->getMessage(), 'Reporting failures:')
    && str_contains($runErr->getMessage(), 'failed_post_merge:'),
    'blocker1-reporting: run path surfaces reporting failure instead of silent abandon'
);
pvrb_self_test(
    is_file(orange_restore_production_post_validation_report_path($runFailSeed['workRoot'], $runFailSeed['jobId'])),
    'blocker1-reporting: run path still writes production_post_validation.json when mark_failed fails'
);
orange_restore_release_lock($runFailSeed['workRoot']);
pvrb_rmdir($runFailSeed['backupRoot']);

// --- Phase 2D.4 emergency logging blockers ---
$emPrimaryFailSeed = pvrb_failure_report_seed();
$emPrimaryFailResult = orange_restore_merge_post_validation_record_failure(
    $emPrimaryFailSeed['workRoot'],
    $emPrimaryFailSeed['jobId'],
    1,
    $emPrimaryFailSeed['job'],
    pvrb_failure_report_payload($emPrimaryFailSeed),
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
        'emergency_log_override' => [
            'write_primary_override' => static function (): void {
                throw new RuntimeException('Simulated primary emergency JSON write failure.');
            },
        ],
    ]
);
pvrb_self_test(
    ($emPrimaryFailResult['emergency_result']['primary_written'] ?? false) === false
    && ($emPrimaryFailResult['emergency_result']['fallback_written'] ?? false) === true
    && is_file((string) ($emPrimaryFailResult['emergency_result']['fallback_path'] ?? '')),
    'emergency-reporting: primary fails and fallback succeeds'
);
pvrb_self_test(
    isset($emPrimaryFailResult['reporting_errors']['post_validation_emergency_failure.json']),
    'emergency-reporting: primary failure added to reporting_errors'
);
$emPrimaryComposed = orange_restore_merge_post_validation_compose_failure_exception(
    new RuntimeException('Original post-validation failure.'),
    $emPrimaryFailResult,
    pvrb_failure_report_payload($emPrimaryFailSeed)
);
pvrb_self_test(
    str_contains($emPrimaryComposed->getMessage(), 'successful fallback emergency log:'),
    'emergency-reporting: successful fallback path appears in final exception'
);
pvrb_rmdir($emPrimaryFailSeed['backupRoot']);

$emPrimaryOkSeed = pvrb_failure_report_seed();
$emPrimaryOkResult = orange_restore_merge_post_validation_record_failure(
    $emPrimaryOkSeed['workRoot'],
    $emPrimaryOkSeed['jobId'],
    1,
    $emPrimaryOkSeed['job'],
    pvrb_failure_report_payload($emPrimaryOkSeed),
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
    ]
);
pvrb_self_test(
    ($emPrimaryOkResult['emergency_result']['primary_written'] ?? false) === true
    && ($emPrimaryOkResult['emergency_result']['fallback_attempted'] ?? true) === false,
    'emergency-reporting: primary succeeds and fallback is not attempted'
);
pvrb_self_test(
    is_file((string) ($emPrimaryOkResult['emergency_result']['primary_path'] ?? '')),
    'emergency-reporting: primary emergency JSON written in job directory'
);
pvrb_rmdir($emPrimaryOkSeed['backupRoot']);

$emBothFailSeed = pvrb_failure_report_seed();
$emBothFailResult = orange_restore_merge_post_validation_record_failure(
    $emBothFailSeed['workRoot'],
    $emBothFailSeed['jobId'],
    1,
    $emBothFailSeed['job'],
    pvrb_failure_report_payload($emBothFailSeed),
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
        'emergency_log_override' => [
            'write_primary_override' => static function (): void {
                throw new RuntimeException('Simulated primary emergency JSON write failure.');
            },
            'write_fallback_override' => static function (): void {
                throw new RuntimeException('Simulated fallback emergency log write failure.');
            },
        ],
    ]
);
pvrb_self_test(
    isset(
        $emBothFailResult['reporting_errors']['post_validation_emergency_failure.json'],
        $emBothFailResult['reporting_errors']['post_validation_emergency_failure.log']
    ),
    'emergency-reporting: both emergency failures appear in reporting_errors'
);
$emBothOriginal = new RuntimeException('Original post-validation failure.');
$emBothComposed = orange_restore_merge_post_validation_compose_failure_exception(
    $emBothOriginal,
    $emBothFailResult,
    pvrb_failure_report_payload($emBothFailSeed)
);
pvrb_self_test(
    str_contains($emBothComposed->getMessage(), 'Emergency failure reporting could not be persisted')
    && str_contains($emBothComposed->getMessage(), 'primary emergency path:')
    && str_contains($emBothComposed->getMessage(), 'fallback emergency path:'),
    'emergency-reporting: both emergency failures appear in final exception'
);
pvrb_self_test($emBothComposed->getPrevious() === $emBothOriginal, 'emergency-reporting: original failure remains getPrevious()');
pvrb_rmdir($emBothFailSeed['backupRoot']);

$emSecretSeed = pvrb_failure_report_seed();
$emSecretReport = pvrb_failure_report_payload($emSecretSeed);
$emSecretReport['password'] = 'super-secret-password';
$emSecretReport['approval_token'] = 'secret-token-value';
$emSecretReport['db_password'] = 'db-secret-password';
$emSecretResult = orange_restore_merge_post_validation_write_emergency_failure_log(
    $emSecretSeed['workRoot'],
    $emSecretSeed['jobId'],
    'simulated failure',
    $emSecretReport,
    ['failed_post_merge' => 'simulated'],
    ['failed_post_merge' => false]
);
$emSecretPayload = json_decode(
    (string) file_get_contents((string) ($emSecretResult['primary_path'] ?? '')),
    true
);
pvrb_self_test(
    is_array($emSecretPayload)
    && !array_key_exists('password', $emSecretPayload)
    && !array_key_exists('approval_token', $emSecretPayload)
    && !array_key_exists('db_password', $emSecretPayload)
    && !str_contains(json_encode($emSecretPayload, JSON_UNESCAPED_UNICODE), 'super-secret-password')
    && !str_contains(json_encode($emSecretPayload, JSON_UNESCAPED_UNICODE), 'secret-token-value')
    && !str_contains(json_encode($emSecretPayload, JSON_UNESCAPED_UNICODE), 'db-secret-password'),
    'emergency-reporting: emergency payload contains no password, approval token, or DB password'
);
pvrb_rmdir($emSecretSeed['backupRoot']);

$emStepsSeed = pvrb_failure_report_seed();
$emStepsResult = orange_restore_merge_post_validation_record_failure(
    $emStepsSeed['workRoot'],
    $emStepsSeed['jobId'],
    1,
    $emStepsSeed['job'],
    pvrb_failure_report_payload($emStepsSeed),
    1,
    [
        'mark_failed_override' => static function (): array {
            throw new RuntimeException('Simulated failed_post_merge transition failure.');
        },
        'emergency_log_override' => [
            'write_primary_override' => static function (): void {
                throw new RuntimeException('Simulated primary emergency JSON write failure.');
            },
        ],
    ]
);
pvrb_self_test(
    ($emStepsResult['persisted']['production_post_validation.json'] ?? false) === true
    && ($emStepsResult['persisted']['final_restore_report.json'] ?? false) === true
    && ($emStepsResult['persisted']['production_post_validation_failed_audit'] ?? false) === true,
    'emergency-reporting: normal remaining reporting steps still execute when emergency primary fails'
);
pvrb_rmdir($emStepsSeed['backupRoot']);

/**
 * @param array<string, mixed> $tableMeta
 */
function pvrb_patch_registry_table(string $projectRoot, string $tableName, array $tableMeta): void
{
    $registryPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'backup_table_registry.json';
    $registryData = json_decode((string) file_get_contents($registryPath), true);
    if (!is_array($registryData)) {
        throw new RuntimeException('Cannot patch registry for self-test.');
    }
    if (!isset($registryData['tables']) || !is_array($registryData['tables'])) {
        $registryData['tables'] = [];
    }
    $registryData['tables'][$tableName] = $tableMeta;
    orange_backup_write_json($registryPath, $registryData);
}

// --- Phase 2D.4 BLOCKER 2: country_owned rule validation ---
$ownedMissingRuleProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedMissingRuleProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => [
        'ownership_type' => 'country_owned',
        'export_order' => 50,
        'delete_order' => 450,
        'restore_order' => 50,
        'parent_dependency' => null,
        'uploads_linked' => false,
        'integrity_critical' => false,
    ],
]);
$ownedMissingRulePdo = new PDO('sqlite::memory:');
$ownedMissingRulePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedMissingRulePdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedMissingRulePdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedMissingRulePdo->exec('CREATE TABLE test_country_owned (id INTEGER PRIMARY KEY, country_id INTEGER)');
$ownedMissingRuleErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $ownedMissingRulePdo,
    $ownedMissingRuleProject,
    ['countries', 'test_country_owned']
);
pvrb_self_test(
    (bool) array_filter($ownedMissingRuleErrors, static fn (string $e): bool => str_contains($e, 'missing extraction_rule')),
    'blocker2-country_owned: missing extraction_rule fails hard'
);
pvrb_rmdir($ownedMissingRuleProject);

$ownedUnsupportedProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedUnsupportedProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => pvrb_registry_country_owned(['type' => 'unsupported_rule_type']),
]);
$ownedUnsupportedPdo = new PDO('sqlite::memory:');
$ownedUnsupportedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedUnsupportedPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedUnsupportedPdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedUnsupportedPdo->exec('CREATE TABLE test_country_owned (id INTEGER PRIMARY KEY, country_id INTEGER)');
$ownedUnsupportedErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $ownedUnsupportedPdo,
    $ownedUnsupportedProject,
    ['countries', 'test_country_owned']
);
pvrb_self_test(
    (bool) array_filter($ownedUnsupportedErrors, static fn (string $e): bool => str_contains($e, 'unsupported extraction_rule.type')),
    'blocker2-country_owned: unsupported extraction_rule fails hard'
);
pvrb_rmdir($ownedUnsupportedProject);

$ownedMalformedScopeProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedMalformedScopeProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => pvrb_registry_country_owned(['type' => 'country_scope_or', 'columns' => []]),
]);
$ownedMalformedScopePdo = new PDO('sqlite::memory:');
$ownedMalformedScopePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedMalformedScopePdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedMalformedScopePdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedMalformedScopePdo->exec('CREATE TABLE test_country_owned (id INTEGER PRIMARY KEY, country_id INTEGER)');
$ownedMalformedScopeErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $ownedMalformedScopePdo,
    $ownedMalformedScopeProject,
    ['countries', 'test_country_owned']
);
pvrb_self_test(
    (bool) array_filter($ownedMalformedScopeErrors, static fn (string $e): bool => str_contains($e, 'country_scope_or rule missing columns')),
    'blocker2-country_owned: malformed country_scope_or fails hard'
);
pvrb_rmdir($ownedMalformedScopeProject);

$ownedMissingColumnProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedMissingColumnProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => pvrb_registry_country_owned(['type' => 'country_id', 'column' => 'country_id']),
]);
$ownedMissingColumnPdo = new PDO('sqlite::memory:');
$ownedMissingColumnPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedMissingColumnPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedMissingColumnPdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedMissingColumnPdo->exec('CREATE TABLE test_country_owned (id INTEGER PRIMARY KEY)');
$ownedMissingColumnErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $ownedMissingColumnPdo,
    $ownedMissingColumnProject,
    ['countries', 'test_country_owned']
);
pvrb_self_test(
    (bool) array_filter($ownedMissingColumnErrors, static fn (string $e): bool => str_contains($e, 'missing ownership column country_id')),
    'blocker2-country_owned: country_id rule with missing column fails hard'
);
pvrb_rmdir($ownedMissingColumnProject);

$ownedValidIdProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedValidIdProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => pvrb_registry_country_owned(['type' => 'country_id', 'column' => 'country_id']),
]);
$ownedValidIdPdo = new PDO('sqlite::memory:');
$ownedValidIdPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedValidIdPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedValidIdPdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedValidIdPdo->exec('CREATE TABLE test_country_owned (id INTEGER PRIMARY KEY, country_id INTEGER)');
$ownedValidIdPdo->exec('INSERT INTO test_country_owned (id, country_id) VALUES (1, 1)');
$ownedValidIdErrors = orange_restore_validation_adapter_production_validate_country_owned_rule(
    $ownedValidIdPdo,
    'test_country_owned',
    [
        'ownership_type' => 'country_owned',
        'extraction_rule' => ['type' => 'country_id', 'column' => 'country_id'],
    ]
);
$ownedValidIdCrossErrors = orange_restore_validation_adapter_production_count_invalid_country_refs(
    $ownedValidIdPdo,
    'test_country_owned',
    'country_id',
    [1],
    false
);
pvrb_self_test($ownedValidIdErrors === [], 'blocker2-country_owned: valid country_id rule passes validation');
pvrb_self_test($ownedValidIdCrossErrors === [], 'blocker2-country_owned: valid country_id rule passes cross-country checks');
pvrb_rmdir($ownedValidIdProject);

$ownedValidScopeProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedValidScopeProject, [
    'countries' => pvrb_registry_global(1, true),
    'test_country_owned' => pvrb_registry_country_owned(['type' => 'country_scope_or', 'columns' => ['country_id', 'scope_country_id']]),
]);
$ownedValidScopePdo = new PDO('sqlite::memory:');
$ownedValidScopePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedValidScopePdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedValidScopePdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedValidScopePdo->exec(
    'CREATE TABLE test_country_owned (
        id INTEGER PRIMARY KEY,
        country_id INTEGER,
        scope_country_id INTEGER
    )'
);
$ownedValidScopePdo->exec('INSERT INTO test_country_owned (id, country_id, scope_country_id) VALUES (1, 1, NULL)');
$ownedValidScopeErrors = orange_restore_validation_adapter_production_validate_country_owned_rule(
    $ownedValidScopePdo,
    'test_country_owned',
    [
        'ownership_type' => 'country_owned',
        'extraction_rule' => ['type' => 'country_scope_or', 'columns' => ['country_id', 'scope_country_id']],
    ]
);
$ownedValidScopeCrossErrors = orange_restore_validation_adapter_production_count_country_scope_or_violations(
    $ownedValidScopePdo,
    'test_country_owned',
    ['country_id', 'scope_country_id'],
    [1]
);
pvrb_self_test($ownedValidScopeErrors === [], 'blocker2-country_owned: valid country_scope_or rule passes validation');
pvrb_self_test($ownedValidScopeCrossErrors === [], 'blocker2-country_owned: valid country_scope_or rule passes cross-country checks');
pvrb_rmdir($ownedValidScopeProject);

$ownedValidCustomProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedValidCustomProject, [
    'countries' => pvrb_registry_global(1, true),
    'accounts' => pvrb_registry_country_owned(orange_backup_registry_country_id(), 50, true),
    'test_country_owned_custom' => pvrb_registry_country_owned([
        'type' => 'custom_sql',
        'sql' => 'SELECT t.id FROM test_country_owned_custom t INNER JOIN accounts a ON a.id = t.account_id WHERE a.country_id = :country_id',
    ], 51),
]);
$ownedValidCustomPdo = new PDO('sqlite::memory:');
$ownedValidCustomPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedValidCustomPdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedValidCustomPdo->exec('INSERT INTO countries (id) VALUES (1), (2)');
$ownedValidCustomPdo->exec('CREATE TABLE accounts (id INTEGER PRIMARY KEY, country_id INTEGER)');
$ownedValidCustomPdo->exec('INSERT INTO accounts (id, country_id) VALUES (1, 1), (2, 2)');
$ownedValidCustomPdo->exec('CREATE TABLE test_country_owned_custom (id INTEGER PRIMARY KEY, account_id INTEGER)');
$ownedValidCustomPdo->exec('INSERT INTO test_country_owned_custom (id, account_id) VALUES (1, 1), (2, 2)');
$ownedValidCustomErrors = orange_restore_validation_adapter_production_count_custom_sql_coverage_violations(
    $ownedValidCustomPdo,
    'test_country_owned_custom',
    [
        'type' => 'custom_sql',
        'sql' => 'SELECT t.id FROM test_country_owned_custom t INNER JOIN accounts a ON a.id = t.account_id WHERE a.country_id = :country_id',
    ],
    [1, 2]
);
pvrb_self_test($ownedValidCustomErrors === [], 'blocker2-country_owned: valid custom_sql rule passes cross-country checks');
pvrb_rmdir($ownedValidCustomProject);

$ownedMissingTableProject = pvrb_temp_root();
pvrb_write_registry_tables($ownedMissingTableProject, [
    'countries' => pvrb_registry_global(1, true),
    'missing_country_owned_table' => pvrb_registry_country_owned(['type' => 'country_id', 'column' => 'country_id']),
]);
$ownedMissingTablePdo = new PDO('sqlite::memory:');
$ownedMissingTablePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$ownedMissingTablePdo->exec('CREATE TABLE countries (id INTEGER PRIMARY KEY)');
$ownedMissingTablePdo->exec('INSERT INTO countries (id) VALUES (1)');
$ownedMissingTableErrors = orange_restore_validation_adapter_production_cross_country_checks(
    $ownedMissingTablePdo,
    $ownedMissingTableProject,
    ['countries']
);
pvrb_self_test(
    (bool) array_filter(
        $ownedMissingTableErrors,
        static fn (string $e): bool => str_contains($e, 'missing_country_owned_table')
            && str_contains($e, 'missing from production schema')
    ),
    'blocker2-country_owned: registry table absent from productionTables hard-fails'
);
pvrb_rmdir($ownedMissingTableProject);

echo PHP_EOL;
if ($failures === 0) {
    echo "ALL Phase 2D.4 post-validation + rollback self-tests PASSED.\n";
    exit(0);
}

echo "FAILED: {$failures} test(s).\n";
exit(1);
