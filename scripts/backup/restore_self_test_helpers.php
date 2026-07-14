<?php

declare(strict_types=1);

/**
 * Shared Restore Engine self-test helpers (fixtures/mocks only — not production paths).
 */

require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_staging_target.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_approval.php';
require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_orchestrator.php';

function restore_self_test_write_minimal_zip_entry(string $zipPath, string $entryName, string $entryContent): void
{
    $dir = dirname($zipPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create uploads zip directory.');
    }

    $name = str_replace('\\', '/', $entryName);
    $data = $entryContent;
    $crc = crc32($data);
    if ($crc < 0) {
        $crc += 0x100000000;
    }
    $size = strlen($data);

    $local = pack('V', 0x04034b50)
        . pack('v', 20)
        . pack('v', 0)
        . pack('v', 0)
        . pack('V', 0)
        . pack('V', 0)
        . pack('V', $crc)
        . pack('V', $size)
        . pack('V', $size)
        . pack('v', strlen($name))
        . pack('v', 0)
        . $name
        . $data;

    $offset = strlen($local);

    $central = pack('V', 0x02014b50)
        . pack('v', 20)
        . pack('v', 20)
        . pack('v', 0)
        . pack('v', 0)
        . pack('V', 0)
        . pack('V', 0)
        . pack('V', $crc)
        . pack('V', $size)
        . pack('V', $size)
        . pack('v', strlen($name))
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', 0)
        . pack('V', 0)
        . pack('V', $offset)
        . $name;

    $end = pack('V', 0x06054b50)
        . pack('v', 0)
        . pack('v', 0)
        . pack('v', 1)
        . pack('v', 1)
        . pack('V', strlen($central))
        . pack('V', $offset)
        . pack('v', 0);

    if (file_put_contents($zipPath, $local . $central . $end, LOCK_EX) === false) {
        throw new RuntimeException('Cannot create uploads zip: ' . $zipPath);
    }
}

function restore_self_test_sqlite_statement_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function restore_self_test_scalar_statement(mixed $value): PDOStatement
{
    $pdo = restore_self_test_sqlite_statement_pdo();
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        return $pdo->query('SELECT ' . (string) (int) $value);
    }

    return $pdo->query('SELECT ' . $pdo->quote((string) $value));
}

/**
 * @param list<string> $lines
 */
function restore_self_test_grant_statement(array $lines): PDOStatement
{
    $pdo = restore_self_test_sqlite_statement_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS restore_self_test_grants (line TEXT NOT NULL)');
    $pdo->exec('DELETE FROM restore_self_test_grants');
    $insert = $pdo->prepare('INSERT INTO restore_self_test_grants (line) VALUES (?)');
    foreach ($lines as $line) {
        $insert->execute([$line]);
    }

    return $pdo->query('SELECT line FROM restore_self_test_grants');
}

/**
 * @param list<string> $tables
 */
function restore_self_test_table_statement(array $tables): PDOStatement
{
    $pdo = restore_self_test_sqlite_statement_pdo();
    $pdo->exec('CREATE TABLE IF NOT EXISTS restore_self_test_tables (tbl TEXT NOT NULL)');
    $pdo->exec('DELETE FROM restore_self_test_tables');
    $insert = $pdo->prepare('INSERT INTO restore_self_test_tables (tbl) VALUES (?)');
    foreach ($tables as $table) {
        $insert->execute([$table]);
    }

    return $pdo->query('SELECT tbl FROM restore_self_test_tables');
}

function restore_self_test_empty_table_statement(): PDOStatement
{
    return restore_self_test_sqlite_statement_pdo()->query('SELECT NULL AS tbl WHERE 0 = 1');
}

function restore_self_test_temp_project_root(): string
{
    static $root = null;
    if (is_string($root) && is_dir($root)) {
        return $root;
    }
    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_test_project_' . bin2hex(random_bytes(3));
    restore_self_test_bootstrap_project_root($root);

    return $root;
}

function restore_self_test_bootstrap_project_root(string $projectRoot): void
{
    if (!is_dir($projectRoot) && !@mkdir($projectRoot, 0775, true) && !is_dir($projectRoot)) {
        throw new RuntimeException('Cannot create self-test project root.');
    }
    $envPath = $projectRoot . DIRECTORY_SEPARATOR . '.env.php';
    if (!is_file($envPath)) {
        file_put_contents(
            $envPath,
            "<?php\nreturn ['DB_USER' => 'orange_dev', 'DB_PASS' => 'test-pass'];\n"
        );
    }
    $configPath = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    if (!is_file($configPath)) {
        file_put_contents(
            $configPath,
            "<?php\ndeclare(strict_types=1);\n\$env = require __DIR__ . '/.env.php';\n"
            . "if (!defined('DB_HOST')) { define('DB_HOST', 'localhost'); }\n"
            . "if (!defined('DB_NAME')) { define('DB_NAME', 'orange_db'); }\n"
        );
    }
}

function restore_self_test_write_zip_with_entry(string $zipPath, string $entryName, string $entryContent): void
{
    if (class_exists('ZipArchive')) {
        $dir = dirname($zipPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create uploads zip directory.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create zip archive: ' . $zipPath);
        }
        $zip->addFromString(str_replace('\\', '/', $entryName), $entryContent);
        $zip->close();

        return;
    }

    restore_self_test_write_minimal_zip_entry($zipPath, $entryName, $entryContent);
}

function restore_self_test_production_db_name(string $projectRoot): string
{
    try {
        return orange_restore_production_db_name($projectRoot);
    } catch (Throwable) {
        return 'orange_db';
    }
}

/**
 * @return array{user:string,pass:string}
 */
function restore_self_test_production_db_credentials(string $projectRoot): array
{
    try {
        return orange_restore_production_db_credentials($projectRoot);
    } catch (Throwable) {
        return ['user' => 'orange_dev', 'pass' => 'test-pass'];
    }
}

function restore_self_test_write_empty_zip(string $zipPath): void
{
    if (class_exists('ZipArchive')) {
        orange_country_uploads_write_empty_zip($zipPath);

        return;
    }

    restore_self_test_write_minimal_zip_entry(
        $zipPath,
        'README.txt',
        "Orange CRP uploads archive — no files referenced for this export.\n"
    );
}

/**
 * @param array<string, mixed> $manifestData
 * @param array<string, mixed> $reportData
 * @param array<string, mixed> $awaitingPatch
 * @return array{manifestPath:string,manifestChecksum:string,reportPath:string}
 */
function restore_self_test_apply_staging_chain(
    string $workRoot,
    string $jobId,
    string $anchorPath,
    string $anchorChecksum,
    array $manifestData = [],
    array $reportData = [],
    array $awaitingPatch = []
): array {
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED);
    orange_restore_job_record_fresh_backup_anchor($workRoot, $jobId, $anchorPath, $anchorChecksum);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING);
    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED);

    $manifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
    orange_backup_write_json($manifestPath, array_merge([
        'table_count' => 10,
        'schema_revision' => 121,
    ], $manifestData));
    $manifestChecksum = orange_backup_sha256_file($manifestPath);

    $reportPath = orange_restore_job_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, array_merge([
        'overall_result' => 'pass',
        'production_touched' => false,
        'staging_post_validation' => ['ok' => true],
        'staging_drv_report' => ['overall_result' => 'pass'],
    ], $reportData));

    orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, array_merge([
        'restore_report_path' => $reportPath,
        'staging_restore_manifest_path' => $manifestPath,
        'owner_approval_window_started_at' => gmdate('c'),
        'staging_db' => 'orange_restore_staging',
    ], $awaitingPatch));

    return [
        'manifestPath' => $manifestPath,
        'manifestChecksum' => $manifestChecksum,
        'reportPath' => $reportPath,
    ];
}

/**
 * @return array{job:array<string,mixed>,binding:array<string,mixed>,issued:array<string,mixed>}
 */
function restore_self_test_apply_owner_approval(
    string $workRoot,
    string $jobId,
    string $packageChecksum,
    string $manifestChecksum,
    string $anchorChecksum,
    string $jobType,
    int $adminId = 1,
    string $username = 'superadmin',
    int $countryId = 0,
    string $countryCode = ''
): array {
    $binding = [
        'job_id' => $jobId,
        'operator_id' => $adminId,
        'scope' => $jobType,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'source_package_checksum' => $packageChecksum,
        'staging_restore_manifest_checksum' => $manifestChecksum,
        'rollback_anchor_checksum' => $anchorChecksum,
    ];
    $issued = orange_restore_approval_issue_token($binding);

    $job = orange_restore_job_read($workRoot, $jobId);
    $job = orange_restore_approval_store_token_on_job($job, $issued['hash'], $issued['binding']);
    orange_restore_job_write($workRoot, $job);

    $verify = orange_restore_approval_verify_token($job, $issued['plaintext'], true);
    if (!$verify['ok']) {
        throw new RuntimeException('Approval token self-verify failed in self-test seed.');
    }

    $consumedAt = gmdate('c');
    $now = gmdate('c');
    $job = orange_restore_orchestrator_approval_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE, [
        'approval_token_hash' => $issued['hash'],
        'approval_token_binding' => $issued['binding'],
        'approval_token_issued_at' => $issued['issued_at'],
        'approval_token_expires_at' => $issued['expires_at'],
        'approval_token_consumed_at' => $consumedAt,
        'owner_approval_at' => $now,
        'owner_approval_by' => $username,
        'owner_approval_admin_id' => $adminId,
        'production_merge_approved' => true,
        'result' => 'approved_for_merge',
    ], true);

    return [
        'job' => $job,
        'binding' => $binding,
        'issued' => $issued,
    ];
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function restore_self_test_apply_merge_precheck_passed(string $workRoot, string $jobId, array $patch = []): array
{
    return orange_restore_job_merge_foundation_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED, array_merge([
        'merge_precheck_passed_at' => gmdate('c'),
        'merge_precheck_production_db' => 'orange_db',
        'merge_precheck_staging_db' => 'orange_restore_staging',
        'result' => 'merge_precheck_passed',
    ], $patch));
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function restore_self_test_apply_database_cutover_complete(string $workRoot, string $jobId, array $patch = []): array
{
    orange_restore_job_db_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED, [
        'merge_started_at' => gmdate('c'),
        'result' => 'merge_started',
    ]);

    return orange_restore_job_db_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE, array_merge([
        'database_cutover_completed_at' => gmdate('c'),
        'result' => 'database_cutover_complete',
    ], $patch));
}

/**
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function restore_self_test_apply_uploads_cutover_complete(string $workRoot, string $jobId, array $patch = []): array
{
    $now = gmdate('c');
    orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_UPLOADS_SNAPSHOT_COMPLETE, [
        'uploads_snapshot_completed_at' => $now,
    ]);
    orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_PENDING, [
        'uploads_first_rename_pending_at' => $now,
    ]);
    orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_UPLOADS_FIRST_RENAME_COMPLETE, [
        'uploads_first_rename_completed_at' => $now,
    ]);
    orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_UPLOADS_SECOND_RENAME_PENDING, [
        'uploads_second_rename_pending_at' => $now,
    ]);

    return orange_restore_job_uploads_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE, array_merge([
        'uploads_cutover_completed_at' => $now,
        'result' => 'uploads_cutover_complete',
    ], $patch));
}
