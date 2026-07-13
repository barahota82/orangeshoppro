<?php

declare(strict_types=1);

/**
 * Phase 2B.1 — Full disaster restore → staging self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_full_staging.php
 *
 * Most tests use temp fixtures (no production writes). Live staging restore runs only when
 * .env.php defines ORANGE_RESTORE_STAGING_DB and database connectivity is available.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_full.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'recovery_validation.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_uploads_applicator.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_validation_adapter.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_fresh_backup_gate.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_full_staging.php';

$failures = 0;

function restore_staging_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function restore_staging_temp_root(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_2b1_' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

function restore_staging_rmdir(string $dir): void
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
            restore_staging_rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/**
 * @param array<string, mixed> $overrides
 */
function restore_staging_write_full_package(string $dir, array $overrides = []): void
{
    $sql = "-- Orange restore staging self-test\n"
        . "CREATE TABLE restore_demo (id INT PRIMARY KEY, label VARCHAR(64));\n"
        . "INSERT INTO restore_demo VALUES (1, 'staging');\n"
        . "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    $dumpPath = $dir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
    $out = gzopen($dumpPath, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);

    $uploadsZip = $dir . DIRECTORY_SEPARATOR . 'uploads.zip';
    orange_country_uploads_write_empty_zip($uploadsZip);
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'uploads_probe.txt', 'not-in-zip');

    $manifest = array_merge([
        'package_type' => 'full_disaster',
        'package_version' => '1.2',
        'generated_at' => gmdate('c'),
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'dump_file' => 'orange_db.sql.gz',
        'uploads_file' => 'uploads.zip',
        'dump_sha256' => orange_backup_sha256_file($dumpPath),
        'uploads_sha256' => orange_backup_sha256_file($uploadsZip),
        'dump_size_bytes' => filesize($dumpPath),
        'uploads_size_bytes' => filesize($uploadsZip),
        'table_count' => 1,
        'backup_status' => 'success',
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
    ], $overrides['manifest'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $health = array_merge([
        'package_type' => 'full_disaster',
        'package_status' => 'healthy',
        'schema_revision' => 121,
        'export_backend' => 'php_pdo',
        'failure_reasons' => [],
        'warnings' => [],
        'maintenance_notes' => [],
    ], $overrides['health'] ?? []);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);
    orange_backup_write_checksums($dir, ['orange_db.sql.gz', 'uploads.zip', 'manifest.json', 'health.json']);
}

/**
 * @return array{backup_root:string,work_root:string,package_dir:string}
 */
function restore_staging_fixture_layout(): array
{
    $backupRoot = restore_staging_temp_root();
    $workRoot = $backupRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $packageDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_ok';
    mkdir($workRoot, 0775, true);
    mkdir($packageDir, 0775, true);
    restore_staging_write_full_package($packageDir);

    return [
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_dir' => $packageDir,
    ];
}

$fixture = restore_staging_fixture_layout();
$backupRoot = $fixture['backup_root'];
$workRoot = $fixture['work_root'];
$packageDir = $fixture['package_dir'];

$envOverride = [
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_RESTORE_WORK_DIR' => $workRoot,
];

// missing package
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'missing_pkg',
        'skip_fresh_backup' => true,
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: missing package aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: missing package aborts');
}

// failed package validation (DRV score below threshold)
$failPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_fail';
mkdir($failPkgDir, 0775, true);
restore_staging_write_full_package($failPkgDir, [
    'health' => [
        'package_status' => 'failed',
        'failure_reasons' => ['simulated failure for restore self-test'],
    ],
]);
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $failPkgDir,
        'skip_fresh_backup' => true,
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: failed validation aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: failed validation aborts');
}

$precheckFail = orange_restore_validation_adapter_package_precheck($failPkgDir);
restore_staging_self_test($precheckFail['ok'] === false, 'validation adapter: failed package precheck');

$precheckOk = orange_restore_validation_adapter_package_precheck($packageDir);
restore_staging_self_test($precheckOk['ok'] === true, 'validation adapter: healthy package precheck');

// missing staging DB config
try {
    orange_restore_staging_db_name([], $projectRoot);
    restore_staging_self_test(false, 'staging target: missing ORANGE_RESTORE_STAGING_DB rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'ORANGE_RESTORE_STAGING_DB'),
        'staging target: missing ORANGE_RESTORE_STAGING_DB rejected'
    );
}

$productionDb = orange_restore_production_db_name($projectRoot);
try {
    orange_restore_staging_db_name([ORANGE_RESTORE_ENV_STAGING_DB => $productionDb], $projectRoot);
    restore_staging_self_test(false, 'staging target: staging db equals production rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'must not equal production'),
        'staging target: staging db equals production rejected'
    );
}

// gzip failure / corrupted gzip
$badGzipPath = $backupRoot . DIRECTORY_SEPARATOR . 'bad.sql.gz';
file_put_contents($badGzipPath, 'not-a-gzip-stream');
$pdoSqlite = new PDO('sqlite::memory:');
$pdoSqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$badGzipResult = orange_restore_sql_runner_import_gzip($pdoSqlite, $badGzipPath);
restore_staging_self_test($badGzipResult['ok'] === false, 'sql runner: corrupted gzip fails');

// corrupted SQL (valid gzip, invalid SQL statement)
$corruptSqlPath = $backupRoot . DIRECTORY_SEPARATOR . 'corrupt.sql.gz';
$gz = gzopen($corruptSqlPath, 'wb9');
gzwrite($gz, "CREATE TABLE t1 (id INT);\nINSERT INTO t1 VALUES (;\n");
gzclose($gz);
$corruptSqlResult = orange_restore_sql_runner_import_gzip($pdoSqlite, $corruptSqlPath);
restore_staging_self_test($corruptSqlResult['ok'] === false, 'sql runner: corrupted SQL fails import');

// successful SQL streaming import (sqlite harness)
$goodSqlPath = $backupRoot . DIRECTORY_SEPARATOR . 'good.sql.gz';
$gz = gzopen($goodSqlPath, 'wb9');
gzwrite($gz, "CREATE TABLE stream_demo (id INTEGER PRIMARY KEY);\nINSERT INTO stream_demo VALUES (42);\n");
gzclose($gz);
$goodSqlResult = orange_restore_sql_runner_import_gzip($pdoSqlite, $goodSqlPath);
restore_staging_self_test($goodSqlResult['ok'] === true, 'sql runner: streaming import success');
restore_staging_self_test(
    (int) ($goodSqlResult['statements_executed'] ?? 0) >= 2,
    'sql runner: progress counters populated'
);

// upload restore
$uploadsTarget = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_target';
mkdir($uploadsTarget, 0775, true);
$uploadsZipWithFile = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_with_file.zip';
$zip = new ZipArchive();
$zip->open($uploadsZipWithFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('products/demo.txt', 'restore-upload-test');
$zip->close();
$uploadsResult = orange_restore_uploads_applicator_extract($uploadsZipWithFile, $uploadsTarget);
restore_staging_self_test($uploadsResult['ok'] === true, 'uploads applicator: extract success');
restore_staging_self_test(
    is_file($uploadsTarget . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . 'demo.txt'),
    'uploads applicator: file present after extract'
);

$traversalZip = $backupRoot . DIRECTORY_SEPARATOR . 'traversal.zip';
$zip = new ZipArchive();
$zip->open($traversalZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('../escape.txt', 'bad');
$zip->close();
$traversalResult = orange_restore_uploads_applicator_extract($traversalZip, $uploadsTarget);
restore_staging_self_test($traversalResult['ok'] === false, 'uploads applicator: zip traversal blocked');

// rollback anchor creation (job fields)
$anchorJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
    'source_package_path' => $packageDir,
    'source_package_checksum' => str_repeat('c', 64),
    'package_version' => '1.2',
    'schema_revision' => 121,
    'approval_phrase_expected' => 'RESTORE',
]);
$anchorJobId = (string) ($anchorJob['job_id'] ?? '');
$anchorPath = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'fresh_anchor';
mkdir($anchorPath, 0775, true);
restore_staging_write_full_package($anchorPath);
$anchorChecksum = orange_restore_fresh_backup_anchor_checksum(
    $anchorPath,
    json_decode((string) file_get_contents($anchorPath . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: []
);
$anchorUpdated = orange_restore_job_record_fresh_backup_anchor($workRoot, $anchorJobId, $anchorPath, $anchorChecksum);
restore_staging_self_test(
    ($anchorUpdated['fresh_backup_path'] ?? '') === $anchorPath,
    'rollback anchor: fresh backup path recorded in job'
);
restore_staging_self_test(
    ($anchorUpdated['rollback_anchor_job_only'] ?? false) === true,
    'rollback anchor: job-only flag preserved'
);

// post-restore validation (DRV-style report builder)
$stagingPostFake = [
    'ok' => true,
    'errors' => [],
    'warnings' => [],
    'table_count' => 5,
    'database' => 'orange_restore_staging',
];
$stagingDrv = orange_restore_validation_adapter_build_staging_drv_report(
    ['recovery_score' => 100, 'overall_result' => 'pass'],
    $stagingPostFake
);
restore_staging_self_test(($stagingDrv['overall_result'] ?? '') === 'pass', 'post-restore validation: staging DRV report pass');
restore_staging_self_test(
    ($stagingDrv['validation_target'] ?? '') === 'staging_database',
    'post-restore validation: staging target labeled'
);
$stagingDrvFail = orange_restore_validation_adapter_build_staging_drv_report(
    ['recovery_score' => 100, 'overall_result' => 'pass'],
    ['ok' => false, 'errors' => ['no tables'], 'warnings' => [], 'table_count' => 0, 'database' => 'x']
);
restore_staging_self_test(($stagingDrvFail['overall_result'] ?? '') === 'fail', 'post-restore validation: staging DRV report fail');

// failure policy: staging marked dirty on import failure (unit-level)
$dirtyJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
    'source_package_path' => $packageDir,
    'source_package_checksum' => str_repeat('d', 64),
    'package_version' => '1.2',
    'schema_revision' => 121,
    'approval_phrase_expected' => 'RESTORE',
]);
$dirtyJobId = (string) ($dirtyJob['job_id'] ?? '');
$failedJob = orange_restore_job_mark_failed($workRoot, $dirtyJobId, 'staging_restore', 'simulated import failure', true, [
    'fresh_backup_path' => $anchorPath,
    'fresh_backup_checksum' => $anchorChecksum,
]);
restore_staging_self_test(($failedJob['staging_dirty'] ?? false) === true, 'failure policy: staging_dirty on failure');
restore_staging_self_test(
    ($failedJob['fresh_backup_checksum'] ?? '') === $anchorChecksum,
    'failure policy: rollback anchor preserved on failure'
);

// live staging restore (optional — requires .env.php + ORANGE_RESTORE_STAGING_DB + connectivity)
$envReport = orange_backup_collect_environment_report($projectRoot);
$liveEnv = orange_backup_load_env_array($projectRoot);
$stagingDbConfigured = trim((string) ($liveEnv[ORANGE_RESTORE_ENV_STAGING_DB] ?? '')) !== '';
if (!empty($envReport['database_connected']) && $stagingDbConfigured && is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    $liveEnvOverride = array_merge($envOverride, [
        ORANGE_RESTORE_ENV_STAGING_DB => trim((string) $liveEnv[ORANGE_RESTORE_ENV_STAGING_DB]),
    ]);
    try {
        $liveResult = orange_restore_full_staging_run([
            'project_root' => $projectRoot,
            'package_path' => $packageDir,
            'skip_fresh_backup' => true,
            'env_override' => $liveEnvOverride,
        ]);
        restore_staging_self_test(($liveResult['ok'] ?? false) === true, 'orchestrator: successful staging restore (live)');
        $liveJobId = (string) ($liveResult['job_id'] ?? '');
        $liveJob = orange_restore_job_read($workRoot, $liveJobId);
        restore_staging_self_test(
            ($liveJob['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
            'orchestrator: job awaits approval after staging (no merge)'
        );
        restore_staging_self_test(
            is_file((string) ($liveResult['staging_manifest_path'] ?? '')),
            'orchestrator: staging_restore_manifest.json written'
        );
        restore_staging_self_test(
            is_file((string) ($liveResult['report_path'] ?? '')),
            'orchestrator: restore_report.json written'
        );
        $reportJson = json_decode((string) file_get_contents((string) $liveResult['report_path']), true);
        restore_staging_self_test(
            is_array($reportJson) && ($reportJson['production_touched'] ?? true) === false,
            'orchestrator: production_touched false in report'
        );
    } catch (Throwable $e) {
        restore_staging_self_test(false, 'orchestrator: successful staging restore (live) — ' . $e->getMessage());
    }
} else {
    echo "SKIP: live staging restore (requires .env.php, DB connectivity, ORANGE_RESTORE_STAGING_DB)\n";
}

restore_staging_rmdir($backupRoot);

echo PHP_EOL . ($failures === 0 ? 'ALL RESTORE STAGING SELF-TESTS PASSED' : "FAILURES: {$failures}") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
