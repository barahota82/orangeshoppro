<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_uploads_applicator.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_fresh_backup_gate.php';
require_once __DIR__ . '/../backup_manifest.php';
require_once __DIR__ . '/../backup_environment.php';

/**
 * Full disaster restore into staging only (Phase 2B.1).
 *
 * @param array{
 *   project_root:string,
 *   package_path:string,
 *   skip_fresh_backup?:bool
 * } $options
 * @return array<string, mixed>
 */
function orange_restore_full_staging_run(array $options): array
{
    $startedAt = microtime(true);
    $projectRoot = (string) ($options['project_root'] ?? '');
    $packagePathInput = (string) ($options['package_path'] ?? '');
    $skipFreshBackup = (bool) ($options['skip_fresh_backup'] ?? false);

    if ($projectRoot === '' || $packagePathInput === '') {
        throw new InvalidArgumentException('project_root and package_path are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }
    $backupRoot = orange_backup_resolve_root($env);
    $workRoot = orange_restore_resolve_work_root($env);
    $packagePath = orange_restore_resolve_package_path($backupRoot, $packagePathInput);

    orange_restore_log('Restore full → staging START');
    orange_restore_log('Package=' . $packagePath);

    $lock = orange_restore_acquire_lock($workRoot, 'pending');
    if (!$lock['ok']) {
        throw new RuntimeException($lock['message']);
    }

    $jobId = '';
    $stagingDirty = false;
    $rollbackPreserved = false;

    try {
        $precheck = orange_restore_validation_adapter_package_precheck($packagePath);
        if (!$precheck['ok']) {
            throw new RuntimeException('Package pre-validation failed: ' . implode('; ', $precheck['errors']));
        }

        /** @var array<string, mixed> $manifest */
        $manifest = is_array($precheck['verify']['manifest'] ?? null) ? $precheck['verify']['manifest'] : [];
        if (($manifest['package_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
            throw new RuntimeException('Package is not full_disaster.');
        }

        $packageChecksum = orange_restore_package_anchor_checksum($packagePath, $manifest);
        $job = orange_restore_job_create($workRoot, [
            'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
            'operator_admin_id' => 0,
            'operator_username' => 'cli',
            'source_package_path' => $packagePath,
            'source_package_checksum' => $packageChecksum,
            'package_version' => (string) ($manifest['package_version'] ?? ''),
            'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
            'approval_phrase_expected' => 'RESTORE',
        ]);
        $jobId = (string) $job['job_id'];
        orange_restore_update_lock_job_id($workRoot, $jobId);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'package_precheck', 'pass'));
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_VALIDATED, [
            'source_package_checksum' => $packageChecksum,
        ]);

        if (!$skipFreshBackup) {
            $fresh = orange_restore_fresh_backup_gate($projectRoot, $backupRoot);
            if (!$fresh['ok']) {
                throw new RuntimeException('Fresh backup gate failed: ' . implode('; ', $fresh['errors']));
            }
            $job = orange_restore_job_record_fresh_backup_anchor(
                $workRoot,
                $jobId,
                $fresh['snapshot_path'],
                $fresh['checksum']
            );
            $rollbackPreserved = true;
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'fresh_backup_anchor', 'pass', [
                'fresh_backup_path' => $fresh['snapshot_path'],
                'fresh_backup_checksum' => $fresh['checksum'],
            ]));
        }

        $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
        $stagingUploads = orange_restore_staging_uploads_directory($workRoot, $jobId);
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING, [
            'staging_db' => $stagingDb,
            'staging_uploads_path' => $stagingUploads,
            'staging_dirty' => true,
        ]);
        $stagingDirty = true;

        $pdo = orange_restore_connect_staging_pdo($projectRoot, $env);
        orange_restore_staging_wipe($pdo);

        $dumpFile = (string) ($manifest['dump_file'] ?? '');
        if ($dumpFile === '') {
            throw new RuntimeException('manifest.dump_file missing.');
        }
        $dumpPath = $packagePath . DIRECTORY_SEPARATOR . $dumpFile;
        $sqlResult = orange_restore_sql_runner_import_gzip($pdo, $dumpPath);
        if (!$sqlResult['ok']) {
            throw new RuntimeException((string) ($sqlResult['error'] ?? 'SQL import failed'));
        }

        $uploadsFile = (string) ($manifest['uploads_file'] ?? '');
        $uploadsResult = ['ok' => true, 'files_extracted' => 0, 'bytes_extracted' => 0, 'error' => null];
        if ($uploadsFile !== '') {
            $uploadsPath = $packagePath . DIRECTORY_SEPARATOR . $uploadsFile;
            $uploadsResult = orange_restore_uploads_applicator_extract($uploadsPath, $stagingUploads);
            if (!$uploadsResult['ok']) {
                throw new RuntimeException((string) ($uploadsResult['error'] ?? 'Uploads extract failed'));
            }
        }

        $stagingPost = orange_restore_validation_adapter_staging_postcheck($pdo, $stagingDb, $manifest);
        $stagingDrv = orange_restore_validation_adapter_build_staging_drv_report(
            is_array($precheck['drv']) ? $precheck['drv'] : [],
            $stagingPost
        );
        if (!$stagingPost['ok']) {
            throw new RuntimeException('Staging post-validation failed: ' . implode('; ', $stagingPost['errors']));
        }

        $stagingManifest = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
            'source_package_path' => $packagePath,
            'source_package_checksum' => $packageChecksum,
            'staging_db' => $stagingDb,
            'staging_uploads_path' => $stagingUploads,
            'sql_import' => $sqlResult,
            'uploads_extract' => $uploadsResult,
            'staging_post_validation' => $stagingPost,
            'staging_drv_report' => $stagingDrv,
            'production_touched' => false,
        ];
        $stagingManifestPath = orange_restore_job_staging_manifest_path($workRoot, $jobId);
        orange_backup_write_json($stagingManifestPath, $stagingManifest);

        $duration = (int) round(microtime(true) - $startedAt);
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_STAGING_VALIDATED, [
            'staging_dirty' => false,
            'staging_restore_manifest_path' => $stagingManifestPath,
            'duration_seconds' => $duration,
            'result' => 'staging_validated',
        ]);
        $stagingDirty = false;

        $report = [
            'generated_at' => gmdate('c'),
            'job_id' => $jobId,
            'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
            'overall_result' => 'pass',
            'duration_seconds' => $duration,
            'source_package_path' => $packagePath,
            'rollback_anchor' => [
                'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
                'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
                'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
            ],
            'precheck_drv' => $precheck['drv'],
            'staging_manifest' => $stagingManifestPath,
            'staging_post_validation' => $stagingPost,
            'staging_drv_report' => $stagingDrv,
            'production_touched' => false,
        ];
        $reportPath = orange_restore_job_report_path($workRoot, $jobId);
        orange_backup_write_json($reportPath, $report);
        $job = orange_restore_job_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL, [
            'restore_report_path' => $reportPath,
        ]);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_from_job($job, 'staging_restore', 'pass', [
            'duration_seconds' => $duration,
        ]));

        orange_restore_log('Restore full → staging END (job_id=' . $jobId . ')');

        return [
            'ok' => true,
            'job_id' => $jobId,
            'job' => $job,
            'report_path' => $reportPath,
            'staging_manifest_path' => $stagingManifestPath,
            'rollback_anchor_preserved' => $rollbackPreserved || (string) ($job['fresh_backup_path'] ?? '') !== '',
        ];
    } catch (Throwable $e) {
        if ($jobId !== '') {
            orange_restore_job_mark_failed($workRoot, $jobId, 'staging_restore', $e->getMessage(), $stagingDirty);
            orange_restore_audit_append($workRoot, $jobId, [
                'stage' => 'staging_restore',
                'result' => 'failed',
                'error' => $e->getMessage(),
                'staging_dirty' => $stagingDirty,
                'rollback_anchor_preserved' => $rollbackPreserved,
            ]);
        }
        orange_restore_log('Restore full → staging FAIL: ' . $e->getMessage());
        throw $e;
    } finally {
        orange_restore_release_lock($workRoot);
    }
}

/**
 * @param array<string, mixed> $manifest
 */
function orange_restore_package_anchor_checksum(string $packagePath, array $manifest): string
{
    $checksumFile = $packagePath . DIRECTORY_SEPARATOR . 'checksums.sha256';
    if (is_file($checksumFile)) {
        return orange_backup_sha256_file($checksumFile);
    }
    $dumpSha = trim((string) ($manifest['dump_sha256'] ?? ''));
    if ($dumpSha !== '') {
        return $dumpSha;
    }

    throw new RuntimeException('Cannot determine source package checksum.');
}
