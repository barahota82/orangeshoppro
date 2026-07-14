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
 * Fail-safe post-validation failure persistence. Each step is attempted independently.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @param array<string, mixed> $options
 * @return array{
 *   job:array<string,mixed>,
 *   persisted:array<string,bool>,
 *   reporting_errors:array<string,string>,
 *   emergency_log_path:?string
 * }
 */
function orange_restore_merge_post_validation_record_failure(
    string $workRoot,
    string $jobId,
    int $adminId,
    array $job,
    array $report,
    int $durationSeconds,
    array $options = []
): array {
    $reportPath = orange_restore_production_post_validation_report_path($workRoot, $jobId);
    $finalReportPath = orange_restore_final_restore_report_path($workRoot, $jobId);
    $finalReport = array_merge($report, [
        'completed_at' => gmdate('c'),
        'job_status' => ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        'maintenance_disabled' => false,
    ]);

    $writeJson = isset($options['write_json_override']) && is_callable($options['write_json_override'])
        ? $options['write_json_override']
        : static function (string $path, array $payload): void {
            orange_backup_write_json($path, $payload);
        };
    $auditAppend = isset($options['audit_append_override']) && is_callable($options['audit_append_override'])
        ? $options['audit_append_override']
        : static function (string $wr, string $jid, array $event) use ($workRoot, $jobId): void {
            orange_restore_audit_append($wr, $jid, $event);
        };
    $markFailed = isset($options['mark_failed_override']) && is_callable($options['mark_failed_override'])
        ? $options['mark_failed_override']
        : static function (
            string $wr,
            string $jid,
            string $stageFailed,
            string $errorSummary,
            array $patch
        ) use ($workRoot, $jobId, $report, $durationSeconds): array {
            return orange_restore_job_mark_failed_post_merge($wr, $jid, $stageFailed, $errorSummary, $patch);
        };

    $persisted = [
        'failed_post_merge' => false,
        'production_post_validation.json' => false,
        'final_restore_report.json' => false,
        'production_post_validation_failed_audit' => false,
    ];
    $reportingErrors = [];

    try {
        $job = $markFailed(
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
        $persisted['failed_post_merge'] = true;
    } catch (Throwable $e) {
        $reportingErrors['failed_post_merge'] = $e->getMessage();
    }

    try {
        $writeJson($reportPath, $report);
        $persisted['production_post_validation.json'] = true;
    } catch (Throwable $e) {
        $reportingErrors['production_post_validation.json'] = $e->getMessage();
    }

    try {
        $writeJson($finalReportPath, $finalReport);
        $persisted['final_restore_report.json'] = true;
    } catch (Throwable $e) {
        $reportingErrors['final_restore_report.json'] = $e->getMessage();
    }

    try {
        $auditAppend($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_failed', 'failed', [
            'operator_admin_id' => $adminId,
            'hard_failures' => $report['hard_failures'],
            'warnings' => $report['warnings'],
            'duration_seconds' => $durationSeconds,
            'database_writes' => false,
            'production_writes' => false,
        ]));
        $persisted['production_post_validation_failed_audit'] = true;
    } catch (Throwable $e) {
        $reportingErrors['production_post_validation_failed_audit'] = $e->getMessage();
    }

    $emergencyLogPath = null;
    if ($reportingErrors !== []) {
        $emergencyLogPath = orange_restore_merge_post_validation_write_emergency_failure_log(
            $workRoot,
            $jobId,
            implode('; ', $report['hard_failures'] ?? []),
            $report,
            $reportingErrors,
            $persisted
        );
    }

    return [
        'job' => $job,
        'persisted' => $persisted,
        'reporting_errors' => $reportingErrors,
        'emergency_log_path' => $emergencyLogPath,
    ];
}

/**
 * @param array<string, mixed> $report
 * @param array<string, string> $reportingErrors
 * @param array<string, bool> $persisted
 */
function orange_restore_merge_post_validation_write_emergency_failure_log(
    string $workRoot,
    string $jobId,
    string $originalFailure,
    array $report,
    array $reportingErrors,
    array $persisted
): ?string {
    $path = orange_restore_post_validation_emergency_failure_log_path($workRoot, $jobId);
    $payload = [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'original_failure' => $originalFailure,
        'hard_failures' => $report['hard_failures'] ?? [],
        'warnings' => $report['warnings'] ?? [],
        'reporting_errors' => $reportingErrors,
        'persisted' => $persisted,
        'unpersisted_artifacts' => array_keys(array_filter(
            $persisted,
            static fn (bool $ok): bool => !$ok
        )),
    ];

    try {
        orange_backup_write_json($path, $payload);

        return $path;
    } catch (Throwable) {
        $fallbackPath = $workRoot . DIRECTORY_SEPARATOR . $jobId . '_post_validation_emergency_failure.log';
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line !== false && file_put_contents($fallbackPath, $line . "\n", FILE_APPEND | LOCK_EX) !== false) {
            return $fallbackPath;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $failureResult
 */
function orange_restore_merge_post_validation_compose_failure_exception(
    Throwable $original,
    array $failureResult,
    array $report
): RuntimeException {
    $reportingErrors = is_array($failureResult['reporting_errors'] ?? null) ? $failureResult['reporting_errors'] : [];
    $persisted = is_array($failureResult['persisted'] ?? null) ? $failureResult['persisted'] : [];
    $parts = [
        'Production post-validation failed: ' . implode('; ', $report['hard_failures'] ?? []),
        'Original error: ' . $original->getMessage(),
    ];

    if ($reportingErrors !== []) {
        $secondary = [];
        foreach ($reportingErrors as $step => $message) {
            $secondary[] = $step . ': ' . $message;
        }
        $parts[] = 'Reporting failures: ' . implode(' | ', $secondary);
    }

    $unpersisted = [];
    foreach ($persisted as $artifact => $ok) {
        if (!$ok) {
            $unpersisted[] = (string) $artifact;
        }
    }
    if ($unpersisted !== []) {
        $parts[] = 'Unpersisted artifacts/events: ' . implode(', ', $unpersisted);
    }

    $emergencyLogPath = (string) ($failureResult['emergency_log_path'] ?? '');
    if ($emergencyLogPath !== '') {
        $parts[] = 'Emergency failure log: ' . $emergencyLogPath;
    }

    return new RuntimeException(implode(' || ', $parts), 0, $original);
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

    $originalFailure = null;

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

        if (!($postcheck['ok'] ?? false)) {
            $originalFailure = new RuntimeException(
                'Production post-validation failed (job=' . $jobId . '): '
                . implode('; ', $report['hard_failures'] ?? [])
            );
            $failureResult = orange_restore_merge_post_validation_record_failure(
                $workRoot,
                $jobId,
                $adminId,
                $job,
                $report,
                $durationSeconds,
                is_array($options['failure_record_override'] ?? null) ? $options['failure_record_override'] : []
            );
            throw orange_restore_merge_post_validation_compose_failure_exception(
                $originalFailure,
                $failureResult,
                $report
            );
        }

        orange_backup_write_json($reportPath, $report);

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
        if ($originalFailure === null && ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_MERGED) {
            $durationSeconds = (int) round(microtime(true) - $startedAt);
            $postcheck = orange_restore_merge_post_validation_postcheck_from_exception(
                $e,
                $job,
                $projectRoot,
                $env
            );
            $report = orange_restore_merge_post_validation_build_report($job, $jobId, $postcheck, $durationSeconds);
            $failureResult = orange_restore_merge_post_validation_record_failure(
                $workRoot,
                $jobId,
                $adminId,
                $job,
                $report,
                $durationSeconds,
                is_array($options['failure_record_override'] ?? null) ? $options['failure_record_override'] : []
            );
            throw orange_restore_merge_post_validation_compose_failure_exception($e, $failureResult, $report);
        }

        if ($originalFailure !== null && $e->getPrevious() === $originalFailure) {
            throw $e;
        }

        throw $e;
    }
}
