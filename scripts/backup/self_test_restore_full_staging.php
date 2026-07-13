<?php

declare(strict_types=1);

/**
 * Phase 2B.1 — Full disaster restore → staging self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_full_staging.php
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
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_lock.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_safety.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_sql_runner.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_package_compat.php';
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
        . "# hash comment line\n"
        . "SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;\n";
    $dumpPath = $dir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
    $out = gzopen($dumpPath, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);

    $uploadsZip = $dir . DIRECTORY_SEPARATOR . 'uploads.zip';
    orange_country_uploads_write_empty_zip($uploadsZip);

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

function restore_staging_write_gzip_sql(string $path, string $sql): void
{
    $out = gzopen($path, 'wb9');
    gzwrite($out, $sql);
    gzclose($out);
}

function restore_staging_validate_rejects(string $sql, string $stagingDb, string $productionDb): bool
{
    try {
        orange_restore_sql_validate_statement_for_staging($sql, $stagingDb, $productionDb);

        return false;
    } catch (Throwable) {
        return true;
    }
}

function restore_staging_validate_accepts(string $sql, string $stagingDb, string $productionDb): bool
{
    try {
        orange_restore_sql_validate_statement_for_staging($sql, $stagingDb, $productionDb);

        return true;
    } catch (Throwable) {
        return false;
    }
}

$fixture = restore_staging_fixture_layout();
$backupRoot = $fixture['backup_root'];
$workRoot = $fixture['work_root'];
$packageDir = $fixture['package_dir'];

$stagingDbName = 'orange_restore_staging_test';
$productionDbName = orange_restore_production_db_name($projectRoot);

$envOverride = [
    'ORANGE_BACKUP_ROOT' => $backupRoot,
    'ORANGE_RESTORE_WORK_DIR' => $workRoot,
    ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName,
    ORANGE_RESTORE_ENV_STAGING_DB_USER => 'restore_staging_user',
    ORANGE_RESTORE_ENV_STAGING_DB_PASS => 'restore_staging_pass',
];

// SQL safety validator
restore_staging_self_test(
    restore_staging_validate_rejects('USE `' . $productionDbName . '`;', $stagingDbName, $productionDbName),
    'sql safety: USE production_db rejected before execution'
);
restore_staging_self_test(
    restore_staging_validate_rejects('CREATE DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DROP DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: fully qualified production table rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DELIMITER ;;', $stagingDbName, $productionDbName),
    'sql safety: DELIMITER rejected'
);
restore_staging_self_test(
    orange_restore_sql_is_comment_only("# only hash comment\n-- and dash\n"),
    'sql safety: # comment-only statement recognized'
);

// Whitespace variants — all rejected before execution
restore_staging_self_test(
    restore_staging_validate_rejects("USE\t`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with tab rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("USE\n`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with newline rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("USE\r\n`{$productionDbName}`;", $stagingDbName, $productionDbName),
    'sql safety: USE with CRLF rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('USE    `' . $productionDbName . '`;', $stagingDbName, $productionDbName),
    'sql safety: USE with multiple spaces rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('CREATE  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("CREATE\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: CREATE DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('DROP  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("DROP\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: DROP DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('ALTER  DATABASE evil;', $stagingDbName, $productionDbName),
    'sql safety: ALTER DATABASE spacing variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects("ALTER\nDATABASE evil;", $stagingDbName, $productionDbName),
    'sql safety: ALTER DATABASE newline variant rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO ' . $productionDbName . '.accounts VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: unquoted db.table rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects(
        'INSERT INTO `' . $productionDbName . '`.`accounts` VALUES (1);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: quoted db.table rejected'
);
restore_staging_self_test(
    restore_staging_validate_accepts(
        'INSERT INTO prices VALUES (1, 12.34, -5.67, 1.2e3);',
        $stagingDbName,
        $productionDbName
    ),
    'sql safety: decimal numeric literals are not db.table references'
);
restore_staging_self_test(
    restore_staging_validate_rejects("/*!40101 USE `{$productionDbName}` */;", $stagingDbName, $productionDbName),
    'sql safety: executable comment USE rejected'
);
restore_staging_self_test(
    restore_staging_validate_rejects('CREATE /*!40101 DATABASE */ evil;', $stagingDbName, $productionDbName),
    'sql safety: executable comment CREATE DATABASE rejected'
);
restore_staging_self_test(
    restore_staging_validate_accepts('/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;', $stagingDbName, $productionDbName),
    'sql safety: safe executable comment SET accepted'
);

// Privilege fence — fail closed
try {
    orange_restore_staging_assert_no_production_privileges(
        new PDO('sqlite::memory:'),
        $stagingDbName,
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: SHOW GRANTS unavailable fails closed');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'SHOW GRANTS'),
        'privilege fence: SHOW GRANTS unavailable fails closed'
    );
}

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT USAGE ON *.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(true, 'privilege fence: no-op USAGE grant accepted');
} catch (Throwable) {
    restore_staging_self_test(false, 'privilege fence: no-op USAGE grant accepted');
}

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT ALL PRIVILEGES ON *.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: global *.* grant rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'production schema'),
        'privilege fence: global *.* grant rejected'
    );
}

try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT SELECT ON `{$productionDbName}`.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
    restore_staging_self_test(false, 'privilege fence: production schema grant rejected');
} catch (Throwable $e) {
    restore_staging_self_test(
        str_contains($e->getMessage(), 'production schema'),
        'privilege fence: production schema grant rejected'
    );
}

$stagingGrantOk = true;
try {
    orange_restore_staging_validate_grant_lines(
        ["GRANT ALL PRIVILEGES ON `{$stagingDbName}`.* TO 'restore_staging'@'localhost'"],
        $productionDbName
    );
} catch (Throwable) {
    $stagingGrantOk = false;
}
restore_staging_self_test($stagingGrantOk, 'privilege fence: staging-only grant accepted');

// Package backend compatibility
$pdoCompat = orange_restore_package_staging_import_compat(
    $packageDir,
    json_decode((string) file_get_contents($packageDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($pdoCompat['ok'] === true, 'package compat: php_pdo package accepted');

$mysqldumpDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_mysqldump';
mkdir($mysqldumpDir, 0775, true);
restore_staging_write_full_package($mysqldumpDir, ['manifest' => ['export_backend' => 'php_mysqldump']]);
$mysqldumpCompat = orange_restore_package_staging_import_compat(
    $mysqldumpDir,
    json_decode((string) file_get_contents($mysqldumpDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($mysqldumpCompat['ok'] === false, 'package compat: php_mysqldump rejected before mutation');

$delimiterDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_delimiter';
mkdir($delimiterDir, 0775, true);
restore_staging_write_full_package($delimiterDir);
$delimiterDump = $delimiterDir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
restore_staging_write_gzip_sql($delimiterDump, "DELIMITER ;;\nCREATE TABLE x (id INT);;\nDELIMITER ;\n");
$delimiterCompat = orange_restore_package_staging_import_compat(
    $delimiterDir,
    json_decode((string) file_get_contents($delimiterDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($delimiterCompat['ok'] === false, 'package compat: DELIMITER dump rejected before mutation');

$safeTextDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_safe_text';
mkdir($safeTextDir, 0775, true);
restore_staging_write_full_package($safeTextDir);
$safeTextDump = $safeTextDir . DIRECTORY_SEPARATOR . 'orange_db.sql.gz';
restore_staging_write_gzip_sql(
    $safeTextDump,
    "CREATE TABLE restore_notes (label VARCHAR(128));\n"
    . "INSERT INTO restore_notes VALUES ('Please USE the staging restore screen only; do not DROP DATABASE text.');\n"
);
$safeTextCompat = orange_restore_package_staging_import_compat(
    $safeTextDir,
    json_decode((string) file_get_contents($safeTextDir . DIRECTORY_SEPARATOR . 'manifest.json'), true) ?: [],
    $stagingDbName,
    $productionDbName
);
restore_staging_self_test($safeTextCompat['ok'] === true, 'package compat: SQL keywords inside data text accepted');

// Staging credentials fail closed
try {
    orange_restore_staging_credentials([ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName], $projectRoot);
    restore_staging_self_test(false, 'staging creds: missing ORANGE_RESTORE_STAGING_DB_USER rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'ORANGE_RESTORE_STAGING_DB_USER'), 'staging creds: missing user rejected');
}

$productionCreds = orange_restore_production_db_credentials($projectRoot);
try {
    orange_restore_staging_credentials([
        ORANGE_RESTORE_ENV_STAGING_DB => $stagingDbName,
        ORANGE_RESTORE_ENV_STAGING_DB_USER => $productionCreds['user'],
        ORANGE_RESTORE_ENV_STAGING_DB_PASS => 'x',
    ], $projectRoot);
    restore_staging_self_test(false, 'staging creds: production DB_USER reuse rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'must not equal production DB_USER'), 'staging creds: production DB_USER reuse rejected');
}

// Lock: active preserved, stale cleared
$lockWork = $backupRoot . DIRECTORY_SEPARATOR . 'lock_work';
mkdir($lockWork, 0775, true);
$activeLockPath = orange_restore_global_lock_path($lockWork);
$activePayload = json_encode([
    'pid' => getmypid(),
    'hostname' => php_uname('n'),
    'job_id' => 'active_job',
    'started_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($activeLockPath, $activePayload . "\n");
$activeAgain = orange_restore_acquire_lock($lockWork, 'blocked');
restore_staging_self_test($activeAgain['ok'] === false, 'lock: active lock preserved');
@unlink($activeLockPath);

$stalePayload = json_encode([
    'pid' => 999999,
    'hostname' => 'stale-host',
    'job_id' => 'stale_job',
    'started_at' => gmdate('c', time() - ORANGE_RESTORE_LOCK_STALE_SECONDS - 60),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
file_put_contents($activeLockPath, $stalePayload . "\n");
restore_staging_self_test(orange_restore_lock_is_stale(json_decode($stalePayload, true)), 'lock: stale lock detected');
$staleAcquire = orange_restore_acquire_lock($lockWork, 'after_stale');
restore_staging_self_test($staleAcquire['ok'] === true, 'lock: stale lock cleared and re-acquired');
orange_restore_release_lock($lockWork);

// Job lifecycle enforcement
$lifeJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 0,
    'operator_username' => 'cli',
    'source_package_path' => $packageDir,
    'source_package_checksum' => str_repeat('e', 64),
    'package_version' => '1.2',
    'schema_revision' => 121,
    'approval_phrase_expected' => 'RESTORE',
]);
$lifeJobId = (string) ($lifeJob['job_id'] ?? '');
try {
    orange_restore_job_transition($workRoot, $lifeJobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    restore_staging_self_test(false, 'job lifecycle: invalid jump created->staging rejected');
} catch (Throwable $e) {
    restore_staging_self_test(str_contains($e->getMessage(), 'Invalid full-disaster restore job transition'), 'job lifecycle: invalid transition rejected');
}

orange_restore_job_transition($workRoot, $lifeJobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED);
$failedStageJob = orange_restore_job_mark_failed($workRoot, $lifeJobId, 'package_compat', 'simulated', false);
restore_staging_self_test(($failedStageJob['stage_failed'] ?? '') === 'package_compat', 'job lifecycle: exact failed stage recorded');

// Orchestrator abort paths (no staging mutation)
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'missing_pkg',
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: missing package aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: missing package aborts');
}

$failPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . 'pkg_fail';
mkdir($failPkgDir, 0775, true);
restore_staging_write_full_package($failPkgDir, [
    'health' => ['package_status' => 'failed', 'failure_reasons' => ['simulated']],
]);
try {
    orange_restore_full_staging_run([
        'project_root' => $projectRoot,
        'package_path' => $failPkgDir,
        'env_override' => $envOverride,
    ]);
    restore_staging_self_test(false, 'orchestrator: failed validation aborts');
} catch (Throwable) {
    restore_staging_self_test(true, 'orchestrator: failed validation aborts');
}

// Upload restore
$uploadsTarget = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_target';
mkdir($uploadsTarget, 0775, true);
$uploadsZipWithFile = $backupRoot . DIRECTORY_SEPARATOR . 'uploads_with_file.zip';
$zip = new ZipArchive();
$zip->open($uploadsZipWithFile, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('products/demo.txt', 'restore-upload-test');
$zip->close();
restore_staging_self_test(orange_restore_uploads_applicator_extract($uploadsZipWithFile, $uploadsTarget)['ok'] === true, 'uploads applicator: extract success');

// CLI: --skip-fresh-backup unavailable
$cliScript = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_full_to_staging.php';
$cliPhp = (PHP_BINARY !== '' ? PHP_BINARY : 'php');
$cliOutput = [];
$cliExit = 0;
exec(escapeshellarg($cliPhp) . ' ' . escapeshellarg($cliScript) . ' --package=x --skip-fresh-backup 2>&1', $cliOutput, $cliExit);
restore_staging_self_test($cliExit === 2, 'cli: --skip-fresh-backup rejected');
restore_staging_self_test(
    str_contains(implode("\n", $cliOutput), 'not supported'),
    'cli: skip-fresh-backup error message'
);

restore_staging_rmdir($backupRoot);

echo PHP_EOL . ($failures === 0 ? 'ALL RESTORE STAGING SELF-TESTS PASSED' : "FAILURES: {$failures}") . PHP_EOL;
exit($failures === 0 ? 0 : 1);

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
