<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_approval.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_merge_staging_export.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/../backup_environment.php';

/**
 * Assert merge-time operator re-authentication (Super Admin + permission + password + phrase).
 *
 * @param array<string, mixed> $job
 */
function orange_restore_merge_db_cutover_assert_operator_reauth(
    PDO $adminPdo,
    array $job,
    int $adminId,
    string $password,
    string $confirmationPhrase
): array {
    $admin = orange_restore_reauth_load_admin($adminPdo, $adminId);
    orange_restore_reauth_assert_restore_permission($admin, $adminPdo, (string) ($job['job_type'] ?? ''));
    if (!orange_restore_verify_operator_password($adminPdo, $adminId, $password)) {
        throw new RuntimeException('Operator password re-authentication failed.');
    }

    $countryCode = (string) ($job['country_code'] ?? '');
    if (!orange_restore_validate_confirmation_phrase(
        (string) ($job['job_type'] ?? ''),
        $confirmationPhrase,
        $countryCode
    )) {
        throw new RuntimeException('Confirmation phrase mismatch.');
    }

    return $admin;
}

/**
 * Phase 2D.2 — production database cutover only (no uploads cutover).
 *
 * @param array{
 *   project_root:string,
 *   work_root?:string,
 *   job_id:string,
 *   admin_id:int,
 *   password:string,
 *   confirmation_phrase:string,
 *   env_override?:array<string,mixed>,
 *   admin_pdo_override?:?PDO,
 *   staging_pdo_override?:?PDO,
 *   merge_pdo_override?:?PDO,
 *   skip_production_mutation?:bool
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_merge_db_cutover_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Database cutover is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');
    $skipProductionMutation = (bool) ($options['skip_production_mutation'] ?? false);

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }
    if ($password === '') {
        throw new InvalidArgumentException('password is required for merge-time re-authentication.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }

    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $startedAt = microtime(true);

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Database cutover applies to full_disaster jobs only.');
    }

    $status = (string) ($job['status'] ?? '');
    if ($status !== ORANGE_RESTORE_JOB_STATUS_MERGE_PRECHECK_PASSED) {
        throw new RuntimeException('Restore job is not merge_precheck_passed (status=' . $status . ').');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $adminPdo = $options['admin_pdo_override'] ?? null;
    if (!$adminPdo instanceof PDO) {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';
        $adminPdo = db();
    }

    $operator = orange_restore_merge_db_cutover_assert_operator_reauth(
        $adminPdo,
        $job,
        $adminId,
        $password,
        $confirmationPhrase
    );

    $gateContext = orange_restore_merge_cutover_revalidate_gates([
        'project_root' => $projectRoot,
        'work_root' => $workRoot,
        'job' => $job,
        'env' => $env,
        'backup_root' => $backupRoot,
        'merge_pdo_override' => $options['merge_pdo_override'] ?? null,
        'staging_pdo_override' => $options['staging_pdo_override'] ?? null,
    ]);

    $productionDb = (string) ($gateContext['production_db'] ?? '');
    $stagingDb = (string) ($gateContext['staging_db'] ?? '');

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'staging_export_started', 'started', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($operator['username'] ?? ''),
        'production_db' => $productionDb,
        'staging_db' => $stagingDb,
        'production_writes' => false,
    ]));

    try {
        if (isset($options['export_runner_override']) && is_callable($options['export_runner_override'])) {
            /** @var array<string, mixed> $export */
            $export = ($options['export_runner_override'])(array_merge($options, [
                'work_root' => $workRoot,
                'env' => $env,
            ]));
            if (!is_array($export) || !($export['ok'] ?? false)) {
                throw new RuntimeException('Staging export override did not return ok=true.');
            }
        } else {
            $export = orange_restore_merge_staging_export_run([
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'env' => $env,
                'staging_pdo_override' => $options['staging_pdo_override'] ?? null,
            ]);
        }
    } catch (Throwable $e) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'database_cutover_failed', 'failed', [
            'stage' => 'staging_export',
            'error' => $e->getMessage(),
            'production_writes' => false,
        ]));
        throw $e;
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'staging_export_completed', 'pass', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($operator['username'] ?? ''),
        'production_db' => $productionDb,
        'staging_db' => $stagingDb,
        'staging_export_checksum' => (string) ($export['checksum_sha256'] ?? ''),
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'duration_seconds' => (int) ($export['duration_seconds'] ?? 0),
        'production_writes' => false,
    ]));

    $job = orange_restore_job_db_cutover_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED, [
        'merge_started_at' => gmdate('c'),
        'merge_db_export_path' => (string) ($export['gzip_path'] ?? ''),
        'merge_db_export_checksum' => (string) ($export['checksum_sha256'] ?? ''),
        'merge_db_export_table_count' => (int) ($export['table_count'] ?? 0),
        'merge_db_export_row_count' => (int) ($export['row_count'] ?? 0),
        'result' => 'merge_started',
    ]);

    if ($skipProductionMutation) {
        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_MERGE_STARTED,
            'job' => $job,
            'export' => $export,
            'production_writes' => false,
            'uploads_touched' => false,
            'merge_executed' => false,
        ];
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'database_cutover_started', 'started', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($operator['username'] ?? ''),
        'production_db' => $productionDb,
        'staging_export_checksum' => (string) ($export['checksum_sha256'] ?? ''),
        'table_count' => (int) ($export['table_count'] ?? 0),
        'row_count' => (int) ($export['row_count'] ?? 0),
        'production_writes' => true,
    ]));

    $cutoverStartedAt = gmdate('c');
    $job = orange_restore_job_read($workRoot, $jobId);
    $job['database_cutover_started_at'] = $cutoverStartedAt;
    orange_restore_job_write($workRoot, $job);

    $mergePdo = $options['merge_pdo_override'] ?? null;
    $mergePdo = $mergePdo instanceof PDO
        ? $mergePdo
        : orange_restore_connect_merge_pdo($projectRoot, $env);

    $import = [
        'ok' => false,
        'statements_executed' => 0,
        'bytes_read' => 0,
        'error' => null,
        'failed_statement' => null,
    ];

    try {
        orange_restore_production_wipe($mergePdo, $productionDb);

        $import = orange_restore_sql_runner_import_gzip_to_target(
            $mergePdo,
            (string) ($export['gzip_path'] ?? ''),
            $productionDb,
            $stagingDb
        );

        if (!$import['ok']) {
            throw new RuntimeException((string) ($import['error'] ?? 'Production SQL import failed.'));
        }

        $durationSeconds = (int) round(microtime(true) - $startedAt);
        $job = orange_restore_job_db_cutover_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
            [
                'database_cutover_completed_at' => gmdate('c'),
                'database_cutover_statement_count' => (int) ($import['statements_executed'] ?? 0),
                'duration_seconds' => $durationSeconds,
                'result' => 'database_cutover_complete',
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'database_cutover_complete', 'pass', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($operator['username'] ?? ''),
            'production_db' => $productionDb,
            'staging_export_checksum' => (string) ($export['checksum_sha256'] ?? ''),
            'table_count' => (int) ($export['table_count'] ?? 0),
            'row_count' => (int) ($export['row_count'] ?? 0),
            'statement_count' => (int) ($import['statements_executed'] ?? 0),
            'duration_seconds' => $durationSeconds,
            'production_writes' => true,
            'uploads_touched' => false,
        ]));

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_DATABASE_CUTOVER_COMPLETE,
            'job' => $job,
            'export' => $export,
            'import' => $import,
            'production_writes' => true,
            'uploads_touched' => false,
            'rollback_executed' => false,
        ];
    } catch (Throwable $e) {
        $job = orange_restore_job_read($workRoot, $jobId);
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_db_cutover_event($job, 'database_cutover_failed', 'failed', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($operator['username'] ?? ''),
            'production_db' => $productionDb,
            'staging_export_checksum' => (string) ($export['checksum_sha256'] ?? ''),
            'error' => $e->getMessage(),
            'failed_statement' => is_string($import['failed_statement'] ?? null) ? $import['failed_statement'] : null,
            'production_writes' => true,
            'uploads_touched' => false,
        ]));

        $job = orange_restore_job_mark_failed_merge(
            $workRoot,
            $jobId,
            'database_cutover',
            $e->getMessage(),
            [
                'database_cutover_statement_count' => (int) ($import['statements_executed'] ?? 0),
            ]
        );

        throw new RuntimeException(
            'Database cutover failed (job=' . $jobId . ', status=failed_merge): ' . $e->getMessage(),
            0,
            $e
        );
    }
}
