<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_validation_adapter_production.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/../backup_manifest.php';

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $postcheck
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_build_report(
    array $job,
    string $jobId,
    array $postcheck,
    int $durationSeconds
): array {
    $report = [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'overall_result' => (string) ($postcheck['overall_result'] ?? 'fail'),
        'duration_seconds' => $durationSeconds,
        'package_identity' => [
            'source_package_path' => (string) ($job['source_package_path'] ?? ''),
            'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
            'package_version' => (string) ($job['package_version'] ?? ''),
        ],
        'rollback_anchor_identity' => [
            'fresh_backup_path' => (string) ($job['fresh_backup_path'] ?? ''),
            'fresh_backup_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
            'rollback_anchor_job_only' => (bool) ($job['rollback_anchor_job_only'] ?? true),
        ],
        'production_db_identity' => (string) ($postcheck['production_db'] ?? ''),
        'schema_revision' => (int) ($postcheck['schema_revision'] ?? 0),
        'table_count' => null,
        'critical_row_counts' => [],
        'gl_result' => null,
        'inventory_fifo_result' => null,
        'uploads_result' => null,
        'hard_failures' => $postcheck['hard_failures'] ?? [],
        'warnings' => $postcheck['warnings'] ?? [],
        'informational' => $postcheck['informational'] ?? [],
        'gates' => $postcheck['gates'] ?? [],
    ];

    foreach ($postcheck['gates'] ?? [] as $gate) {
        if (!is_array($gate)) {
            continue;
        }
        $gateId = (string) ($gate['gate_id'] ?? '');
        if ($gateId === 'table_count_exact_match') {
            $report['table_count'] = $gate['details'] ?? null;
        }
        if ($gateId === 'critical_row_counts') {
            $report['critical_row_counts'] = $gate['details']['checks'] ?? [];
        }
        if ($gateId === 'gl_debit_credit_balance') {
            $report['gl_result'] = $gate['details'] ?? null;
        }
        if (in_array($gateId, ['stock_movement_integrity', 'warehouse_variant_stock_consistency', 'fifo_layer_integrity', 'negative_quantity_policy'], true)) {
            $report['inventory_fifo_result'] = is_array($report['inventory_fifo_result']) ? $report['inventory_fifo_result'] : [];
            $report['inventory_fifo_result'][$gateId] = $gate['details'] ?? [];
        }
        if ($gateId === 'uploads_checksum_match') {
            $report['uploads_result'] = $gate['details'] ?? null;
        }
    }

    return $report;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_postcheck_from_exception(
    Throwable $e,
    array $job,
    string $projectRoot,
    array $env
): array {
    $stagingDb = orange_restore_staging_db_name($env, $projectRoot);
    $productionDb = orange_restore_production_db_name($projectRoot);
    $message = $e->getMessage();
    $gateId = str_contains(strtolower($message), 'registry') ? 'registry_load' : 'production_post_validation_exception';

    return orange_restore_validation_adapter_production_postcheck_finalize(
        [
            orange_restore_validation_adapter_production_gate(
                $gateId,
                ORANGE_RESTORE_PRODUCTION_GATE_HARD,
                false,
                $message,
                ['exception_class' => $e::class]
            ),
        ],
        $job,
        $productionDb,
        $stagingDb
    );
}

/**
 * Persist failed post-validation artifacts (reports, audit, job state).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_record_failure(
    string $workRoot,
    string $jobId,
    int $adminId,
    array $job,
    array $report,
    int $durationSeconds
): array {
    $reportPath = orange_restore_production_post_validation_report_path($workRoot, $jobId);
    orange_backup_write_json($reportPath, $report);

    $finalReportPath = orange_restore_final_restore_report_path($workRoot, $jobId);
    $finalReport = array_merge($report, [
        'completed_at' => gmdate('c'),
        'job_status' => ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        'maintenance_disabled' => false,
    ]);
    orange_backup_write_json($finalReportPath, $finalReport);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_failed', 'failed', [
        'operator_admin_id' => $adminId,
        'hard_failures' => $report['hard_failures'],
        'warnings' => $report['warnings'],
        'duration_seconds' => $durationSeconds,
        'database_writes' => false,
        'production_writes' => false,
    ]));

    return orange_restore_job_mark_failed_post_merge(
        $workRoot,
        $jobId,
        'production_post_validation',
        implode('; ', $report['hard_failures'] ?? []),
        [
            'post_validation_report_path' => $reportPath,
            'final_restore_report_path' => $finalReportPath,
            'duration_seconds' => $durationSeconds,
        ]
    );
}

/**
 * Phase 2D.4 — production post-validation after full DB + uploads cutover.
 *
 * Legal entry: uploads_cutover_complete only.
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Production post-validation is CLI-only.');
    }

    $projectRoot = (string) ($options['project_root'] ?? '');
    $jobId = trim((string) ($options['job_id'] ?? ''));
    $adminId = (int) ($options['admin_id'] ?? 0);

    if ($projectRoot === '' || $jobId === '' || $adminId <= 0) {
        throw new InvalidArgumentException('project_root, job_id, and admin_id are required.');
    }

    $env = orange_backup_load_env_array($projectRoot);
    if (is_array($options['env_override'] ?? null)) {
        $env = array_merge($env, $options['env_override']);
    }

    $workRoot = (string) ($options['work_root'] ?? '');
    if ($workRoot === '') {
        $workRoot = orange_restore_resolve_work_root($env);
    }

    $job = orange_restore_job_read($workRoot, $jobId);
    $startedAt = microtime(true);
    $entryStatus = (string) ($job['status'] ?? '');

    if ($entryStatus !== ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE) {
        throw new RuntimeException(
            'Post-validation entry requires uploads_cutover_complete (status=' . $entryStatus . ').'
        );
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Post-validation applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_started', 'started', [
        'operator_admin_id' => $adminId,
        'operator_username' => (string) ($job['operator_username'] ?? ''),
        'database_writes' => false,
        'production_writes' => false,
    ]));

    try {
        $mergedAt = gmdate('c');
        $job = orange_restore_job_post_validation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_MERGED,
            [
                'production_merged_at' => $mergedAt,
                'result' => 'production_merged',
            ]
        );

        $productionPdo = isset($options['production_pdo_override']) && $options['production_pdo_override'] instanceof PDO
            ? $options['production_pdo_override']
            : null;
        $stagingPdo = isset($options['staging_pdo_override']) && $options['staging_pdo_override'] instanceof PDO
            ? $options['staging_pdo_override']
            : null;

        if (isset($options['postcheck_override']) && is_callable($options['postcheck_override'])) {
            /** @var array<string, mixed> $postcheck */
            $postcheck = ($options['postcheck_override'])([
                'project_root' => $projectRoot,
                'work_root' => $workRoot,
                'job' => $job,
                'env' => $env,
            ]);
        } else {
            try {
                $postcheck = orange_restore_validation_adapter_production_postcheck(
                    $projectRoot,
                    $workRoot,
                    $job,
                    $env,
                    $productionPdo,
                    $stagingPdo
                );
            } catch (Throwable $postcheckError) {
                $postcheck = orange_restore_merge_post_validation_postcheck_from_exception(
                    $postcheckError,
                    $job,
                    $projectRoot,
                    $env
                );
            }
        }

        orange_restore_merge_maintenance_verify($workRoot, $jobId);

        $durationSeconds = (int) round(microtime(true) - $startedAt);
        $report = orange_restore_merge_post_validation_build_report($job, $jobId, $postcheck, $durationSeconds);
        $reportPath = orange_restore_production_post_validation_report_path($workRoot, $jobId);

        orange_backup_write_json($reportPath, $report);

        if (!($postcheck['ok'] ?? false)) {
            $job = orange_restore_merge_post_validation_record_failure(
                $workRoot,
                $jobId,
                $adminId,
                $job,
                $report,
                $durationSeconds
            );

            throw new RuntimeException(
                'Production post-validation failed (job=' . $jobId . ', status=failed_post_merge): '
                . implode('; ', $report['hard_failures'])
            );
        }

        $passedAt = gmdate('c');
        $job = orange_restore_job_post_validation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
            [
                'post_validation_passed_at' => $passedAt,
                'post_validation_report_path' => $reportPath,
                'result' => 'post_validation_passed',
                'duration_seconds' => $durationSeconds,
            ]
        );

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_passed', 'pass', [
            'operator_admin_id' => $adminId,
            'duration_seconds' => $durationSeconds,
            'warnings' => $report['warnings'],
            'database_writes' => false,
            'production_writes' => false,
        ]));

        orange_restore_merge_maintenance_disable($workRoot, $jobId, [
            'reason' => 'post_validation_passed',
        ]);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'maintenance_disabled', 'pass', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]));

        $completedAt = gmdate('c');
        $job = orange_restore_job_post_validation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            [
                'restore_completed_at' => $completedAt,
                'final_restore_report_path' => orange_restore_final_restore_report_path($workRoot, $jobId),
                'result' => 'completed',
            ]
        );

        $finalReport = array_merge($report, [
            'completed_at' => $completedAt,
            'job_status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            'maintenance_disabled' => true,
        ]);
        orange_backup_write_json((string) $job['final_restore_report_path'], $finalReport);

        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'restore_completed', 'pass', [
            'operator_admin_id' => $adminId,
            'duration_seconds' => $durationSeconds,
            'database_writes' => false,
            'production_writes' => false,
            'rollback_executed' => false,
        ]));

        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            'job' => $job,
            'post_validation_report_path' => $reportPath,
            'final_restore_report_path' => (string) ($job['final_restore_report_path'] ?? ''),
            'database_writes' => false,
            'production_writes' => false,
            'rollback_executed' => false,
        ];
    } catch (Throwable $e) {
        if (($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_MERGED) {
            try {
                $durationSeconds = (int) round(microtime(true) - $startedAt);
                $postcheck = orange_restore_merge_post_validation_postcheck_from_exception(
                    $e,
                    $job,
                    $projectRoot,
                    $env
                );
                $report = orange_restore_merge_post_validation_build_report($job, $jobId, $postcheck, $durationSeconds);
                orange_restore_merge_post_validation_record_failure(
                    $workRoot,
                    $jobId,
                    $adminId,
                    $job,
                    $report,
                    $durationSeconds
                );
            } catch (Throwable) {
                // preserve original error
            }
        }
        throw $e;
    }
}
