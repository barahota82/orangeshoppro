<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_staging_target.php';
require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_merge_precheck.php';
require_once __DIR__ . '/restore_production_target.php';
require_once __DIR__ . '/restore_sql_runner.php';
require_once __DIR__ . '/restore_uploads_applicator.php';
require_once __DIR__ . '/restore_uploads_fs.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_validation_adapter_production.php';
require_once __DIR__ . '/restore_fresh_backup_gate.php';
require_once __DIR__ . '/../recovery_validation.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * Assert rollback operator re-authentication (Super Admin + backup_restore_full + password + ROLLBACK).
 *
 * @param array<string, mixed> $job
 */
function orange_restore_merge_rollback_assert_operator_reauth(
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
    if (!orange_restore_validate_rollback_phrase($confirmationPhrase)) {
        throw new RuntimeException('Rollback confirmation phrase mismatch (expected ROLLBACK).');
    }

    return $admin;
}

/**
 * Resolve and verify job-scoped rollback anchor only (no arbitrary package selection).
 *
 * @param array<string, mixed> $job
 * @return array{path:string,checksum:string,manifest:array<string,mixed>,dump_path:string,uploads_path:string}
 */
function orange_restore_merge_rollback_resolve_anchor(string $backupRoot, array $job): array
{
    $path = trim((string) ($job['fresh_backup_path'] ?? ''));
    $checksum = trim((string) ($job['fresh_backup_checksum'] ?? ''));
    if ($path === '' || $checksum === '') {
        throw new RuntimeException('Rollback anchor (fresh backup) is missing on job.');
    }
    if (!(bool) ($job['rollback_anchor_job_only'] ?? false)) {
        throw new RuntimeException('Rollback anchor must be job-only (rollback_anchor_job_only=true).');
    }

    $resolved = orange_restore_resolve_package_path($backupRoot, $path);
    $liveChecksum = orange_restore_merge_precheck_live_rollback_checksum($resolved);
    if (!hash_equals($checksum, $liveChecksum)) {
        throw new RuntimeException('Rollback anchor checksum mismatch (job vs live anchor package).');
    }

    $manifestPath = $resolved . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Rollback anchor manifest.json missing.');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest)) {
        throw new RuntimeException('Rollback anchor manifest invalid.');
    }
    if (($manifest['package_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Rollback anchor must be full_disaster package.');
    }

    $precheck = orange_restore_validation_adapter_package_precheck($resolved);
    if (!$precheck['ok']) {
        throw new RuntimeException('Rollback anchor package verify failed: ' . implode('; ', $precheck['errors']));
    }

    $drv = orange_recovery_validate_package($resolved);
    if ((int) ($drv['recovery_score'] ?? 0) < 70) {
        throw new RuntimeException('Rollback anchor DRV score below threshold.');
    }

    $dumpFile = (string) ($manifest['dump_file'] ?? '');
    $uploadsFile = (string) ($manifest['uploads_file'] ?? '');

    return [
        'path' => $resolved,
        'checksum' => $liveChecksum,
        'manifest' => $manifest,
        'dump_path' => $dumpFile !== '' ? $resolved . DIRECTORY_SEPARATOR . $dumpFile : '',
        'uploads_path' => $uploadsFile !== '' ? $resolved . DIRECTORY_SEPARATOR . $uploadsFile : '',
    ];
}

/**
 * @return array{action:string,source:string}
 */
function orange_restore_merge_rollback_resolve_uploads_action(string $projectRoot, string $jobId): array
{
    $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
    $preMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);
    $preMergeExists = is_dir($preMergeDir) || is_file($preMergeDir);
    $uploadsExists = is_dir($uploadsDir) || is_file($uploadsDir);

    if ($preMergeExists) {
        return ['action' => 'rename_pre_merge', 'source' => 'uploads_pre_merge'];
    }

    return ['action' => 'anchor_extract', 'source' => 'rollback_anchor_uploads'];
}

function orange_restore_merge_rollback_atomic_rename(
    string $from,
    string $to,
    ?callable $renameOverride = null
): void {
    if ($renameOverride !== null) {
        $renameOverride($from, $to);

        return;
    }
    if (!@rename($from, $to)) {
        throw new RuntimeException('Atomic directory rename failed: ' . $from . ' -> ' . $to);
    }
}

/**
 * Phase 2D.4 — manual production rollback from job-scoped anchor only.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_rollback_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Production rollback is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);
    $password = (string) ($options['password'] ?? '');
    $confirmationPhrase = (string) ($options['confirmation_phrase'] ?? '');

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0 || $password === '') {
        throw new InvalidArgumentException('project_root, job_id, admin_id, and password are required.');
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
    $entryStatus = (string) ($job['status'] ?? '');

    if (!in_array($entryStatus, orange_restore_job_rollback_entry_statuses(), true)) {
        throw new RuntimeException(
            'Rollback illegal entry state (status=' . $entryStatus . '). '
            . 'Not allowed from completed or rolled_back.'
        );
    }

    if (in_array($entryStatus, [ORANGE_RESTORE_JOB_STATUS_COMPLETED, ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK], true)) {
        throw new RuntimeException('Rollback is not allowed from completed or rolled_back.');
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Rollback applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    $adminPdo = $options['admin_pdo_override'] ?? null;
    if (!$adminPdo instanceof PDO) {
        require_once dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config.php';
        $adminPdo = db();
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_precheck_started', 'started', [
        'operator_admin_id' => $adminId,
        'entry_status' => $entryStatus,
        'database_writes' => false,
        'production_writes' => false,
    ]));

    $operator = orange_restore_merge_rollback_assert_operator_reauth(
        $adminPdo,
        $job,
        $adminId,
        $password,
        $confirmationPhrase
    );

    $anchor = orange_restore_merge_rollback_resolve_anchor($backupRoot, $job);
    $productionDb = orange_restore_production_db_name($projectRoot);
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $checkpoint = (string) ($job['rollback_checkpoint'] ?? '');
    $isRetryAfterFailure = $entryStatus === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED;

    if ($entryStatus !== ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
        $job = orange_restore_job_rollback_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS, [
            'rollback_started_at' => gmdate('c'),
            'result' => $isRetryAfterFailure ? 'rollback_retry_in_progress' : 'rollback_in_progress',
        ]);
        if ($checkpoint === '' || !$isRetryAfterFailure) {
            $job = orange_restore_job_write_rollback_checkpoint(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_ROLLBACK_CHECKPOINT_PRECHECK_PASSED
            );
            $checkpoint = ORANGE_RESTORE_ROLLBACK_CHECKPOINT_PRECHECK_PASSED;
        } else {
            $job = orange_restore_job_read($workRoot, $jobId);
        }
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_precheck_passed', 'pass', [
        'operator_admin_id' => $adminId,
        'rollback_anchor_path' => $anchor['path'],
        'rollback_anchor_checksum' => $anchor['checksum'],
        'database_writes' => false,
        'production_writes' => false,
    ]));

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_started', 'started', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($operator['username'] ?? ''),
        'rollback_anchor_checksum' => $anchor['checksum'],
        'database_writes' => true,
        'production_writes' => true,
    ]));

    $mergePdo = $options['merge_pdo_override'] ?? null;
    $mergePdo = $mergePdo instanceof PDO
        ? $mergePdo
        : orange_restore_connect_merge_pdo($projectRoot, $env);

    $renameOverride = isset($options['rename_override']) && is_callable($options['rename_override'])
        ? $options['rename_override']
        : null;

    try {
        if (!in_array($checkpoint, [
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE,
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_PENDING,
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE,
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED,
        ], true)) {
            if ($checkpoint === ORANGE_RESTORE_ROLLBACK_CHECKPOINT_PRECHECK_PASSED) {
                $job = orange_restore_job_write_rollback_checkpoint(
                    $workRoot,
                    $jobId,
                    ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_PENDING
                );
            }

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_database_started', 'started', [
                'operator_admin_id' => $adminId,
                'production_db' => $productionDb,
                'rollback_anchor_checksum' => $anchor['checksum'],
                'database_writes' => true,
                'production_writes' => true,
            ]));

            if (isset($options['db_import_override']) && is_callable($options['db_import_override'])) {
                ($options['db_import_override'])([
                    'pdo' => $mergePdo,
                    'production_db' => $productionDb,
                    'dump_path' => $anchor['dump_path'],
                ]);
            } else {
                if ($anchor['dump_path'] === '' || !is_file($anchor['dump_path'])) {
                    throw new RuntimeException('Rollback anchor database dump missing.');
                }
                orange_restore_production_wipe($mergePdo, $productionDb);
                $import = orange_restore_sql_runner_import_gzip_to_target(
                    $mergePdo,
                    $anchor['dump_path'],
                    $productionDb,
                    $stagingDb
                );
                if (!$import['ok']) {
                    throw new RuntimeException((string) ($import['error'] ?? 'Rollback database import failed.'));
                }
            }

            $job = orange_restore_job_write_rollback_checkpoint(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE,
                ['rollback_database_completed_at' => gmdate('c')]
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_database_completed', 'pass', [
                'operator_admin_id' => $adminId,
                'production_db' => $productionDb,
                'database_writes' => true,
                'production_writes' => true,
            ]));
            $checkpoint = ORANGE_RESTORE_ROLLBACK_CHECKPOINT_DATABASE_COMPLETE;
        }

        if (!in_array($checkpoint, [
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE,
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED,
        ], true)) {
            $job = orange_restore_job_write_rollback_checkpoint(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_PENDING
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_uploads_started', 'started', [
                'operator_admin_id' => $adminId,
                'database_writes' => false,
                'production_writes' => true,
            ]));

            $uploadsAction = orange_restore_merge_rollback_resolve_uploads_action($projectRoot, $jobId);
            $uploadsDir = orange_restore_production_uploads_directory($projectRoot);
            $preMergeDir = orange_restore_uploads_pre_merge_directory($projectRoot, $jobId);

            if (isset($options['uploads_rollback_override']) && is_callable($options['uploads_rollback_override'])) {
                ($options['uploads_rollback_override'])([
                    'project_root' => $projectRoot,
                    'job_id' => $jobId,
                    'action' => $uploadsAction,
                ]);
            } elseif ($uploadsAction['action'] === 'rename_pre_merge') {
                orange_restore_uploads_fs_assert_atomic_rename_volume([$uploadsDir, $preMergeDir]);

                if (is_dir($uploadsDir) || is_file($uploadsDir)) {
                    $trashDir = dirname($uploadsDir) . DIRECTORY_SEPARATOR . 'uploads_rollback_trash_' . $jobId;
                    if (is_dir($trashDir) || is_file($trashDir)) {
                        throw new RuntimeException('Rollback uploads trash path already exists.');
                    }
                    orange_restore_merge_rollback_atomic_rename($uploadsDir, $trashDir, $renameOverride);
                }
                orange_restore_merge_rollback_atomic_rename($preMergeDir, $uploadsDir, $renameOverride);
                $uploadsAction['source'] = 'uploads_pre_merge';
            } elseif ($anchor['uploads_path'] !== '' && is_file($anchor['uploads_path'])) {
                if (is_dir($uploadsDir)) {
                    $trashDir = dirname($uploadsDir) . DIRECTORY_SEPARATOR . 'uploads_rollback_trash_' . $jobId;
                    if (!is_dir($trashDir) && !is_file($trashDir)) {
                        orange_restore_merge_rollback_atomic_rename($uploadsDir, $trashDir, $renameOverride);
                    }
                }
                if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0775, true) && !is_dir($uploadsDir)) {
                    throw new RuntimeException('Cannot create uploads directory for anchor extract.');
                }
                $extract = orange_restore_uploads_applicator_extract($anchor['uploads_path'], $uploadsDir);
                if (!$extract['ok']) {
                    throw new RuntimeException((string) ($extract['error'] ?? 'Rollback uploads extract failed.'));
                }

                $snapshotDir = orange_restore_pre_merge_uploads_snapshot_directory($workRoot, $jobId);
                $snapshotManifestPath = orange_restore_pre_merge_uploads_snapshot_manifest_path($workRoot, $jobId);
                if (is_file($snapshotManifestPath)) {
                    $snapshotManifest = json_decode((string) file_get_contents($snapshotManifestPath), true);
                    if (is_array($snapshotManifest)) {
                        $liveInventory = orange_restore_uploads_tree_inventory($uploadsDir);
                        if ((int) ($snapshotManifest['file_count'] ?? -1) !== $liveInventory['file_count']
                            || !hash_equals(
                                (string) ($snapshotManifest['tree_checksum_sha256'] ?? ''),
                                $liveInventory['tree_checksum_sha256']
                            )) {
                            throw new RuntimeException(
                                'Anchor uploads extract does not match pre_merge_uploads_snapshot verification.'
                            );
                        }
                        $uploadsAction['source'] = 'pre_merge_uploads_snapshot_verified';
                    }
                } else {
                    $uploadsAction['source'] = 'rollback_anchor_uploads';
                }
            } else {
                orange_restore_log('Rollback uploads: no pre_merge and no anchor uploads file; skipping uploads mutation.');
                $uploadsAction['source'] = 'skipped_no_source';
            }

            $job = orange_restore_job_write_rollback_checkpoint(
                $workRoot,
                $jobId,
                ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE,
                [
                    'rollback_uploads_source' => $uploadsAction['source'],
                    'rollback_uploads_completed_at' => gmdate('c'),
                ]
            );

            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_uploads_completed', 'pass', [
                'operator_admin_id' => $adminId,
                'uploads_source' => $uploadsAction['source'],
                'database_writes' => false,
                'production_writes' => true,
            ]));
            $checkpoint = ORANGE_RESTORE_ROLLBACK_CHECKPOINT_UPLOADS_COMPLETE;
        }

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_validation_started', 'started', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]));

        if (isset($options['rollback_postcheck_override']) && is_callable($options['rollback_postcheck_override'])) {
            /** @var array<string, mixed> $rollbackPost */
            $rollbackPost = ($options['rollback_postcheck_override'])([
                'project_root' => $projectRoot,
                'job' => orange_restore_job_read($workRoot, $jobId),
                'env' => $env,
            ]);
        } else {
            $rollbackPost = orange_restore_validation_adapter_rollback_postcheck(
                $projectRoot,
                orange_restore_job_read($workRoot, $jobId),
                $env,
                $mergePdo
            );
        }

        if (!($rollbackPost['ok'] ?? false)) {
            orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_validation_failed', 'failed', [
                'operator_admin_id' => $adminId,
                'hard_failures' => $rollbackPost['hard_failures'] ?? [],
                'database_writes' => false,
                'production_writes' => false,
            ]));
            throw new RuntimeException(
                'Rollback post-validation failed: ' . implode('; ', $rollbackPost['hard_failures'] ?? [])
            );
        }

        $job = orange_restore_job_write_rollback_checkpoint(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_ROLLBACK_CHECKPOINT_VALIDATION_PASSED
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_validation_passed', 'pass', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]));

        orange_restore_merge_maintenance_disable($workRoot, $jobId, ['reason' => 'rollback_complete']);

        $durationSeconds = (int) round(microtime(true) - $startedAt);
        $job = orange_restore_job_rollback_transition($workRoot, $jobId, ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK, [
            'rollback_completed_at' => gmdate('c'),
            'duration_seconds' => $durationSeconds,
            'result' => 'rolled_back',
        ]);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_completed', 'pass', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($operator['username'] ?? ''),
            'rollback_anchor_checksum' => $anchor['checksum'],
            'duration_seconds' => $durationSeconds,
            'database_writes' => true,
            'production_writes' => true,
        ]));

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK,
            'job' => $job,
            'rollback_anchor_checksum' => $anchor['checksum'],
            'duration_seconds' => $durationSeconds,
            'database_writes' => true,
            'production_writes' => true,
            'automatic_rollback' => false,
        ];
    } catch (Throwable $e) {
        $job = orange_restore_job_read($workRoot, $jobId);
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_rollback_event($job, 'rollback_failed', 'failed', [
            'operator_admin_id' => $adminId,
            'error' => $e->getMessage(),
            'rollback_checkpoint' => (string) ($job['rollback_checkpoint'] ?? ''),
            'database_writes' => true,
            'production_writes' => true,
        ]));

        if (($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_ROLLBACK_IN_PROGRESS) {
            orange_restore_job_mark_rollback_failed(
                $workRoot,
                $jobId,
                'rollback',
                $e->getMessage(),
                ['rollback_checkpoint' => (string) ($job['rollback_checkpoint'] ?? '')]
            );
        }

        throw new RuntimeException(
            'Production rollback failed (job=' . $jobId . '): ' . $e->getMessage(),
            0,
            $e
        );
    }
}
