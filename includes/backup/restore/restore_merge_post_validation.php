<?php

declare(strict_types=1);

require_once __DIR__ . '/restore_job.php';
require_once __DIR__ . '/restore_audit.php';
require_once __DIR__ . '/restore_lock.php';
require_once __DIR__ . '/restore_reauth.php';
require_once __DIR__ . '/restore_merge_maintenance.php';
require_once __DIR__ . '/restore_validation_adapter.php';
require_once __DIR__ . '/restore_validation_adapter_production.php';
require_once __DIR__ . '/restore_merge_precheck.php';
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
 *   emergency_log_path:?string,
 *   emergency_result:array<string,mixed>
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

    $emergencyResult = orange_restore_merge_post_validation_empty_emergency_result($workRoot, $jobId);
    if ($reportingErrors !== []) {
        $emergencyOptions = is_array($options['emergency_log_override'] ?? null) ? $options['emergency_log_override'] : [];
        $emergencyResult = orange_restore_merge_post_validation_write_emergency_failure_log(
            $workRoot,
            $jobId,
            implode('; ', $report['hard_failures'] ?? []),
            $report,
            $reportingErrors,
            $persisted,
            $emergencyOptions
        );

        if (($emergencyResult['primary_attempted'] ?? false) && !($emergencyResult['primary_written'] ?? false)) {
            $reportingErrors['post_validation_emergency_failure.json'] = (string) ($emergencyResult['primary_error'] ?? 'Primary emergency write failed.');
        }
        if (($emergencyResult['fallback_attempted'] ?? false) && !($emergencyResult['fallback_written'] ?? false)) {
            $reportingErrors['post_validation_emergency_failure.log'] = (string) ($emergencyResult['fallback_error'] ?? 'Fallback emergency write failed.');
        }
    }

    return [
        'job' => $job,
        'persisted' => $persisted,
        'reporting_errors' => $reportingErrors,
        'emergency_log_path' => is_string($emergencyResult['emergency_log_path'] ?? null)
            ? $emergencyResult['emergency_log_path']
            : null,
        'emergency_result' => $emergencyResult,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_empty_emergency_result(string $workRoot, string $jobId): array
{
    return [
        'primary_attempted' => false,
        'primary_written' => false,
        'primary_path' => orange_restore_post_validation_emergency_failure_log_path($workRoot, $jobId),
        'primary_error' => null,
        'fallback_attempted' => false,
        'fallback_written' => false,
        'fallback_path' => orange_restore_post_validation_emergency_failure_fallback_log_path($workRoot, $jobId),
        'fallback_error' => null,
        'emergency_log_path' => null,
    ];
}

/**
 * @param array<string, mixed> $report
 * @param array<string, string> $reportingErrors
 * @param array<string, bool> $persisted
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_write_emergency_failure_log(
    string $workRoot,
    string $jobId,
    string $originalFailure,
    array $report,
    array $reportingErrors,
    array $persisted,
    array $options = []
): array {
    $primaryPath = orange_restore_post_validation_emergency_failure_log_path($workRoot, $jobId);
    $fallbackPath = orange_restore_post_validation_emergency_failure_fallback_log_path($workRoot, $jobId);
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

    $writePrimary = isset($options['write_primary_override']) && is_callable($options['write_primary_override'])
        ? $options['write_primary_override']
        : static function (string $path, array $payloadToWrite): void {
            orange_backup_write_json($path, $payloadToWrite);
        };
    $writeFallback = isset($options['write_fallback_override']) && is_callable($options['write_fallback_override'])
        ? $options['write_fallback_override']
        : static function (string $path, array $payloadToWrite): void {
            $line = json_encode($payloadToWrite, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false || file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Fallback emergency failure log write failed.');
            }
        };

    $result = [
        'primary_attempted' => true,
        'primary_written' => false,
        'primary_path' => $primaryPath,
        'primary_error' => null,
        'fallback_attempted' => false,
        'fallback_written' => false,
        'fallback_path' => $fallbackPath,
        'fallback_error' => null,
        'emergency_log_path' => null,
    ];

    try {
        $writePrimary($primaryPath, $payload);
        $result['primary_written'] = true;
        $result['emergency_log_path'] = $primaryPath;

        return $result;
    } catch (Throwable $e) {
        $result['primary_error'] = $e->getMessage();
    }

    $result['fallback_attempted'] = true;
    try {
        $writeFallback($fallbackPath, $payload);
        $result['fallback_written'] = true;
        $result['emergency_log_path'] = $fallbackPath;

        return $result;
    } catch (Throwable $e) {
        $result['fallback_error'] = $e->getMessage();
    }

    return $result;
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

    $emergencyResult = is_array($failureResult['emergency_result'] ?? null) ? $failureResult['emergency_result'] : [];
    $primaryAttempted = (bool) ($emergencyResult['primary_attempted'] ?? false);
    $primaryWritten = (bool) ($emergencyResult['primary_written'] ?? false);
    $fallbackAttempted = (bool) ($emergencyResult['fallback_attempted'] ?? false);
    $fallbackWritten = (bool) ($emergencyResult['fallback_written'] ?? false);
    $primaryPath = (string) ($emergencyResult['primary_path'] ?? '');
    $fallbackPath = (string) ($emergencyResult['fallback_path'] ?? '');
    $primaryError = (string) ($emergencyResult['primary_error'] ?? '');
    $fallbackError = (string) ($emergencyResult['fallback_error'] ?? '');
    $emergencyLogPath = (string) ($failureResult['emergency_log_path'] ?? '');

    if ($primaryAttempted && !$primaryWritten && $fallbackAttempted && !$fallbackWritten) {
        $parts[] = 'Emergency failure reporting could not be persisted'
            . '; primary emergency path: ' . $primaryPath . ' (' . $primaryError . ')'
            . '; fallback emergency path: ' . $fallbackPath . ' (' . $fallbackError . ')';
    } elseif ($primaryAttempted && !$primaryWritten && $fallbackWritten) {
        $parts[] = 'Primary emergency write failed: ' . $primaryPath . ' (' . $primaryError . ')'
            . '; successful fallback emergency log: ' . $fallbackPath;
    } elseif ($primaryWritten && $primaryPath !== '') {
        $parts[] = 'Emergency failure log: ' . $primaryPath;
    } elseif ($emergencyLogPath !== '') {
        $parts[] = 'Emergency failure log: ' . $emergencyLogPath;
    }

    return new RuntimeException(implode(' || ', $parts), 0, $original);
}

/**
 * @return list<string>
 */
function orange_restore_merge_post_validation_resume_entry_statuses(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE,
        ORANGE_RESTORE_JOB_STATUS_MERGED,
    ];
}

/**
 * @return list<string>
 */
function orange_restore_merge_post_validation_finalize_entry_statuses(): array
{
    return orange_restore_job_post_validation_finalize_entry_statuses();
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @return list<string>
 */
function orange_restore_merge_post_validation_completion_missing_artifacts(
    string $workRoot,
    string $jobId,
    array $job,
    array $report = []
): array {
    $missing = [];

    if ((string) ($job['restore_completed_at'] ?? '') === '') {
        $missing[] = 'restore_completed_at';
    }
    if ((string) ($job['maintenance_disable_pending_at'] ?? '') === '') {
        $missing[] = 'maintenance_disable_pending_at';
    }
    if ((string) ($job['maintenance_disabled_at'] ?? '') === '') {
        $missing[] = 'maintenance_disabled_at';
    }

    $finalReportPath = (string) ($job['final_restore_report_path'] ?? '');
    if ($finalReportPath === '') {
        $finalReportPath = orange_restore_final_restore_report_path($workRoot, $jobId);
    }
    if (!is_file($finalReportPath)) {
        $missing[] = 'final_restore_report.json';
    } else {
        $raw = file_get_contents($finalReportPath);
        $finalReport = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($finalReport)
            || (string) ($finalReport['job_status'] ?? '') !== ORANGE_RESTORE_JOB_STATUS_COMPLETED
            || (string) ($finalReport['completed_at'] ?? '') === ''
            || ($finalReport['maintenance_disabled'] ?? null) !== true) {
            $missing[] = 'final_restore_report_completion_fields';
        }
    }

    foreach ([
        'maintenance_disable_pending',
        'maintenance_disabled_checkpoint',
        'maintenance_disabled',
        'restore_completed',
    ] as $eventName) {
        if (!orange_restore_audit_post_validation_has_event($workRoot, $jobId, $eventName)) {
            $missing[] = 'audit:' . $eventName;
        }
    }

    return $missing;
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_merge_post_validation_is_fully_reconciled(
    string $workRoot,
    string $jobId,
    array $job
): bool {
    return orange_restore_merge_post_validation_completion_missing_artifacts($workRoot, $jobId, $job) === [];
}

/**
 * @param array<string, mixed> $extra
 */
function orange_restore_merge_post_validation_throw_reconciliation_failure(
    string $workRoot,
    string $jobId,
    string $failureSummary,
    array $missingArtifacts,
    array $reportingErrors,
    array $persisted,
    array $extra = []
): void {
    $report = [
        'generated_at' => gmdate('c'),
        'job_id' => $jobId,
        'overall_result' => 'fail',
        'hard_failures' => [$failureSummary],
        'warnings' => [],
    ];
    $emergencyResult = orange_restore_merge_post_validation_write_emergency_failure_log(
        $workRoot,
        $jobId,
        $failureSummary,
        $report,
        $reportingErrors,
        $persisted,
        is_array($extra['emergency_log_override'] ?? null) ? $extra['emergency_log_override'] : []
    );

    $message = 'Restore completion reconciliation failed (job=' . $jobId . '): ' . $failureSummary
        . '; missing=' . implode(',', $missingArtifacts);
    if ($reportingErrors !== []) {
        $message .= '; reporting_errors=' . implode('; ', array_map(
            static fn (string $key, string $value): string => $key . ': ' . $value,
            array_keys($reportingErrors),
            array_values($reportingErrors)
        ));
    }
    if ((string) ($emergencyResult['emergency_log_path'] ?? '') !== '') {
        $message .= '; emergency_log=' . (string) $emergencyResult['emergency_log_path'];
    }

    throw new RuntimeException($message);
}

/**
 * Fail closed when a checkpoint requires maintenance to already be OFF.
 */
function orange_restore_merge_post_validation_assert_maintenance_off_for_checkpoint(
    string $workRoot,
    string $jobId,
    string $checkpointContext
): void {
    $state = orange_restore_merge_maintenance_read_state($workRoot);
    if (!(bool) ($state['active'] ?? false)) {
        return;
    }

    if ((bool) ($state['corrupt'] ?? false)) {
        throw new RuntimeException(
            'Restore maintenance mode file is corrupt (' . $checkpointContext . ' aborted).'
        );
    }

    $ownerJobId = (string) ($state['payload']['job_id'] ?? '');
    throw new RuntimeException(
        'Inconsistent maintenance/job checkpoint state (' . $checkpointContext . '): maintenance is active'
        . ($ownerJobId !== '' ? ' for job ' . $ownerJobId : '')
        . ' but ' . $checkpointContext . ' requires maintenance OFF. Manual operator investigation required.'
    );
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_merge_post_validation_handle_maintenance_for_finalize(
    string $workRoot,
    string $jobId,
    array $job
): bool {
    $status = (string) ($job['status'] ?? '');

    if ($status === ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED) {
        orange_restore_merge_post_validation_assert_maintenance_off_for_checkpoint(
            $workRoot,
            $jobId,
            'maintenance_disabled'
        );

        return false;
    }

    if ($status === ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING) {
        $maintenanceActive = orange_restore_merge_post_validation_assert_maintenance_owned_active($workRoot, $jobId, $job);
        if ($maintenanceActive) {
            orange_restore_merge_maintenance_disable($workRoot, $jobId, [
                'reason' => 'post_validation_passed',
            ]);

            return true;
        }

        orange_restore_merge_post_validation_verify_finalize_audit_chain($workRoot, $jobId, $job);

        return false;
    }

    // post_validation_passed — legacy recovery may resume with maintenance already OFF.
    $maintenanceActive = orange_restore_merge_post_validation_assert_maintenance_owned_active($workRoot, $jobId, $job);
    if ($maintenanceActive) {
        orange_restore_merge_maintenance_disable($workRoot, $jobId, [
            'reason' => 'post_validation_passed',
        ]);

        return true;
    }

    orange_restore_merge_post_validation_verify_finalize_audit_chain($workRoot, $jobId, $job);

    return false;
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @param array<string, mixed> $options
 */
function orange_restore_merge_post_validation_verify_finalize_prerequisites(
    string $projectRoot,
    string $backupRoot,
    string $workRoot,
    string $jobId,
    array $job,
    array $report,
    array $options = []
): void {
    if (isset($options['finalize_prerequisites_override']) && is_callable($options['finalize_prerequisites_override'])) {
        ($options['finalize_prerequisites_override'])([
            'project_root' => $projectRoot,
            'backup_root' => $backupRoot,
            'work_root' => $workRoot,
            'job_id' => $jobId,
            'job' => $job,
            'report' => $report,
        ]);

        return;
    }

    if ((string) ($job['post_validation_passed_at'] ?? '') === '') {
        throw new RuntimeException('Post-validation passed timestamp missing on job (job=' . $jobId . ').');
    }

    if ((string) ($report['job_id'] ?? '') !== $jobId) {
        throw new RuntimeException('Post-validation report job_id does not match restore job.');
    }

    if ((string) ($report['overall_result'] ?? '') !== 'pass') {
        throw new RuntimeException('Post-validation report does not show pass (overall_result=' . (string) ($report['overall_result'] ?? '') . ').');
    }

    $hardFailures = $report['hard_failures'] ?? [];
    if (!is_array($hardFailures)) {
        throw new RuntimeException('Post-validation report hard_failures is malformed.');
    }
    if ($hardFailures !== []) {
        throw new RuntimeException('Post-validation report contains hard failures.');
    }

    $jobSchemaRevision = (int) ($job['schema_revision'] ?? 0);
    $reportSchemaRevision = (int) ($report['schema_revision'] ?? 0);
    if ($jobSchemaRevision <= 0 || $reportSchemaRevision <= 0 || $jobSchemaRevision !== $reportSchemaRevision) {
        throw new RuntimeException('Post-validation report schema_revision does not match job.');
    }

    $reportProductionDb = trim((string) ($report['production_db_identity'] ?? ''));
    $jobProductionDb = trim((string) ($job['merge_precheck_production_db'] ?? ''));
    if ($reportProductionDb === '') {
        throw new RuntimeException('Post-validation report production_db_identity is missing.');
    }
    if ($jobProductionDb !== '' && $reportProductionDb !== $jobProductionDb) {
        throw new RuntimeException('Post-validation report production_db_identity does not match job.');
    }

    $packageIdentity = is_array($report['package_identity'] ?? null) ? $report['package_identity'] : [];
    $reportPackagePath = trim((string) ($packageIdentity['source_package_path'] ?? ''));
    $reportPackageChecksum = trim((string) ($packageIdentity['source_package_checksum'] ?? ''));
    $jobPackagePath = trim((string) ($job['source_package_path'] ?? ''));
    $jobPackageChecksum = trim((string) ($job['source_package_checksum'] ?? ''));
    if ($jobPackagePath === '' || $jobPackageChecksum === '') {
        throw new RuntimeException('Job source package identity is missing.');
    }
    if ($reportPackagePath !== '' && $reportPackagePath !== $jobPackagePath) {
        throw new RuntimeException('Post-validation report package path does not match job.');
    }
    if ($reportPackageChecksum !== '' && !hash_equals($reportPackageChecksum, $jobPackageChecksum)) {
        throw new RuntimeException('Post-validation report package checksum does not match job.');
    }

    $jobPath = trim((string) ($job['fresh_backup_path'] ?? ''));
    $jobChecksum = trim((string) ($job['fresh_backup_checksum'] ?? ''));
    if ($jobPath === '' || $jobChecksum === '') {
        throw new RuntimeException('Rollback anchor (fresh backup) is missing on job.');
    }
    if (!(bool) ($job['rollback_anchor_job_only'] ?? false)) {
        throw new RuntimeException('Rollback anchor must be job-only (rollback_anchor_job_only=true).');
    }

    $reportAnchor = is_array($report['rollback_anchor_identity'] ?? null) ? $report['rollback_anchor_identity'] : [];
    $reportAnchorPath = trim((string) ($reportAnchor['fresh_backup_path'] ?? ''));
    $reportAnchorChecksum = trim((string) ($reportAnchor['fresh_backup_checksum'] ?? ''));
    if ($reportAnchorPath !== '' && $reportAnchorPath !== $jobPath) {
        throw new RuntimeException('Post-validation report rollback anchor path does not match job.');
    }
    if ($reportAnchorChecksum !== '' && !hash_equals($reportAnchorChecksum, $jobChecksum)) {
        throw new RuntimeException('Post-validation report rollback anchor checksum does not match job.');
    }

    /** @var array<string, mixed> $binding */
    $binding = is_array($job['approval_token_binding'] ?? null) ? $job['approval_token_binding'] : [];
    if ($binding === []) {
        throw new RuntimeException('Approval token binding is missing on job.');
    }
    orange_restore_merge_precheck_assert_binding_checksums($job, $binding);

    orange_restore_orchestrator_verify_live_package_checksum($backupRoot, $job);

    $stagingGate = orange_restore_validation_adapter_staging_gate($workRoot, $job);
    if (!$stagingGate['ok']) {
        throw new RuntimeException(
            'Staging manifest binding verification failed: ' . implode('; ', $stagingGate['errors'])
        );
    }

    $liveManifestChecksum = (string) ($stagingGate['manifest_checksum'] ?? '');
    if ($liveManifestChecksum === '') {
        throw new RuntimeException('Live staging_restore_manifest checksum is missing.');
    }
    if (!hash_equals((string) ($binding['staging_restore_manifest_checksum'] ?? ''), $liveManifestChecksum)) {
        throw new RuntimeException('Staging manifest checksum mismatch (binding vs live staging manifest).');
    }

    $liveAnchorChecksum = orange_restore_merge_precheck_live_rollback_checksum($jobPath);
    if (!hash_equals($jobChecksum, $liveAnchorChecksum)) {
        throw new RuntimeException('Rollback anchor checksum mismatch (job vs live anchor package).');
    }
    if (!hash_equals((string) ($binding['rollback_anchor_checksum'] ?? ''), $liveAnchorChecksum)) {
        throw new RuntimeException('Rollback anchor checksum mismatch (binding vs live anchor package).');
    }

    if (!orange_restore_audit_post_validation_has_event($workRoot, $jobId, 'production_post_validation_passed')) {
        throw new RuntimeException('Audit chain missing production_post_validation_passed (job=' . $jobId . ').');
    }
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_merge_post_validation_verify_finalize_audit_chain(
    string $workRoot,
    string $jobId,
    array $job
): void {
    if (!orange_restore_audit_post_validation_has_event($workRoot, $jobId, 'production_post_validation_passed')) {
        throw new RuntimeException('Audit chain missing production_post_validation_passed (job=' . $jobId . ').');
    }

    $status = (string) ($job['status'] ?? '');
    if ($status === ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED
        && !orange_restore_audit_post_validation_has_event($workRoot, $jobId, 'maintenance_disable_pending')) {
        throw new RuntimeException('Audit chain missing maintenance_disable_pending (job=' . $jobId . ').');
    }
}

/**
 * @param array<string, mixed> $job
 */
function orange_restore_merge_post_validation_assert_maintenance_owned_active(string $workRoot, string $jobId, array $job): bool
{
    $state = orange_restore_merge_maintenance_read_state($workRoot);
    if (!(bool) ($state['active'] ?? false)) {
        return false;
    }
    if ((bool) ($state['corrupt'] ?? false)) {
        throw new RuntimeException('Restore maintenance mode file is corrupt.');
    }

    $payloadJobId = (string) ($state['payload']['job_id'] ?? '');
    if ($payloadJobId !== $jobId) {
        throw new RuntimeException(
            'Restore maintenance mode is active for a different job (expected=' . $jobId . ', actual=' . $payloadJobId . ').'
        );
    }

    return true;
}

/**
 * Read-only/idempotent completion artifact reconciliation for terminal completed jobs.
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_reconcile_completed_artifacts(
    string $workRoot,
    string $jobId,
    int $adminId,
    array $job,
    array $report,
    string $reportPath,
    int $durationSeconds,
    array $options = []
): array {
    orange_restore_require_production_mutating_credentials(
        array_merge($options, ['admin_id' => $adminId]),
        $job
    );

    orange_restore_merge_post_validation_assert_maintenance_off_for_checkpoint(
        $workRoot,
        $jobId,
        'completed reconciliation'
    );

    $missingBefore = orange_restore_merge_post_validation_completion_missing_artifacts($workRoot, $jobId, $job, $report);
    if ($missingBefore === []) {
        return [
            'ok' => true,
            'job_id' => $jobId,
            'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
            'job' => $job,
            'post_validation_report_path' => $reportPath,
            'final_restore_report_path' => (string) ($job['final_restore_report_path'] ?? orange_restore_final_restore_report_path($workRoot, $jobId)),
            'reconciled' => false,
            'idempotent' => true,
            'database_writes' => false,
            'production_writes' => false,
            'rollback_executed' => false,
        ];
    }

    $persisted = [];
    $reportingErrors = [];
    $completedAt = (string) ($job['restore_completed_at'] ?? '');
    if ($completedAt === '') {
        $completedAt = gmdate('c');
    }

    $finalReportPath = (string) ($job['final_restore_report_path'] ?? '');
    if ($finalReportPath === '') {
        $finalReportPath = orange_restore_final_restore_report_path($workRoot, $jobId);
    }

    $jobPatch = [
        'restore_completed_at' => $completedAt,
        'final_restore_report_path' => $finalReportPath,
    ];
    if ((string) ($job['maintenance_disable_pending_at'] ?? '') === '') {
        $jobPatch['maintenance_disable_pending_at'] = $completedAt;
    }
    if ((string) ($job['maintenance_disabled_at'] ?? '') === '') {
        $jobPatch['maintenance_disabled_at'] = $completedAt;
    }

    try {
        $job = orange_restore_job_read($workRoot, $jobId);
        foreach ($jobPatch as $key => $value) {
            if ($value !== '' && (string) ($job[(string) $key] ?? '') === '') {
                $job[(string) $key] = $value;
            }
        }
        orange_restore_job_write($workRoot, $job);
        $persisted['job_completion_fields'] = true;
    } catch (Throwable $e) {
        $reportingErrors['job_completion_fields'] = $e->getMessage();
        $persisted['job_completion_fields'] = false;
    }

    $finalReport = array_merge($report, [
        'completed_at' => $completedAt,
        'job_status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        'maintenance_disabled' => true,
    ]);

    $writeFinalReport = isset($options['final_report_write_override']) && is_callable($options['final_report_write_override'])
        ? $options['final_report_write_override']
        : static function (string $path, array $payload): void {
            orange_backup_write_json($path, $payload);
        };

    try {
        $writeFinalReport($finalReportPath, $finalReport);
        $persisted['final_restore_report.json'] = true;
    } catch (Throwable $e) {
        $reportingErrors['final_restore_report.json'] = $e->getMessage();
        $persisted['final_restore_report.json'] = false;
    }

    $job = orange_restore_job_read($workRoot, $jobId);

    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disable_pending', 'checkpoint', [
        'operator_admin_id' => $adminId,
        'reconciled' => true,
        'database_writes' => false,
        'production_writes' => false,
    ]);
    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled_checkpoint', 'checkpoint', [
        'operator_admin_id' => $adminId,
        'reconciled' => true,
        'maintenance_was_active' => false,
        'database_writes' => false,
        'production_writes' => false,
    ]);
    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled', 'pass', [
        'operator_admin_id' => $adminId,
        'reconciled' => true,
        'database_writes' => false,
        'production_writes' => false,
    ]);
    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'restore_completed', 'pass', [
        'operator_admin_id' => $adminId,
        'duration_seconds' => $durationSeconds,
        'reconciled' => true,
        'database_writes' => false,
        'production_writes' => false,
        'rollback_executed' => false,
    ]);

    $job = orange_restore_job_read($workRoot, $jobId);
    $missingAfter = orange_restore_merge_post_validation_completion_missing_artifacts($workRoot, $jobId, $job, $report);
    if ($missingAfter !== [] || in_array(false, $persisted, true)) {
        orange_restore_merge_post_validation_throw_reconciliation_failure(
            $workRoot,
            $jobId,
            'Completion artifact reconciliation could not persist all required artifacts.',
            $missingAfter,
            $reportingErrors,
            $persisted,
            $options
        );
    }

    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'restore_completion_reconciled', 'pass', [
        'operator_admin_id' => $adminId,
        'database_writes' => false,
        'production_writes' => false,
    ]);

    return [
        'ok' => true,
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        'job' => $job,
        'post_validation_report_path' => $reportPath,
        'final_restore_report_path' => $finalReportPath,
        'reconciled' => true,
        'idempotent' => false,
        'database_writes' => false,
        'production_writes' => false,
        'rollback_executed' => false,
    ];
}

/**
 * @param array<string, mixed> $job
 * @param array<string, mixed> $report
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_complete_after_pass(
    string $workRoot,
    string $jobId,
    int $adminId,
    array $job,
    array $report,
    string $reportPath,
    int $durationSeconds,
    array $options = []
): array {
    $projectRoot = (string) ($options['project_root'] ?? '');
    $backupRoot = (string) ($options['backup_root'] ?? '');
    $currentStatus = (string) ($job['status'] ?? '');

    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
        return orange_restore_merge_post_validation_reconcile_completed_artifacts(
            $workRoot,
            $jobId,
            $adminId,
            $job,
            $report,
            $reportPath,
            $durationSeconds,
            $options
        );
    }

    if (!in_array($currentStatus, [
        ORANGE_RESTORE_JOB_STATUS_MERGED,
        ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED,
        ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING,
        ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED,
    ], true)) {
        throw new RuntimeException(
            'Post-validation completion requires merged or finalize checkpoint status (status=' . $currentStatus . ').'
        );
    }

    $passedAt = gmdate('c');
    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_MERGED) {
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

        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'production_post_validation_passed', 'pass', [
            'operator_admin_id' => $adminId,
            'duration_seconds' => $durationSeconds,
            'warnings' => $report['warnings'] ?? [],
            'database_writes' => false,
            'production_writes' => false,
        ]);
    } elseif ((string) ($job['post_validation_report_path'] ?? '') === '') {
        $job['post_validation_report_path'] = $reportPath;
        orange_restore_job_write($workRoot, $job);
    }

    $currentStatus = (string) ($job['status'] ?? '');

    if ($currentStatus === ORANGE_RESTORE_JOB_STATUS_POST_VALIDATION_PASSED) {
        $pendingAt = gmdate('c');
        $job = orange_restore_job_post_validation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLE_PENDING,
            [
                'maintenance_disable_pending_at' => $pendingAt,
                'result' => 'maintenance_disable_pending',
            ]
        );

        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disable_pending', 'checkpoint', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]);
    }

    orange_restore_merge_post_validation_verify_finalize_prerequisites(
        $projectRoot,
        $backupRoot,
        $workRoot,
        $jobId,
        $job,
        $report,
        $options
    );

    $maintenanceActive = orange_restore_merge_post_validation_handle_maintenance_for_finalize($workRoot, $jobId, $job);

    $currentStatus = (string) ($job['status'] ?? '');
    if ($currentStatus !== ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED) {
        $disabledAt = gmdate('c');
        $job = orange_restore_job_post_validation_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_JOB_STATUS_MAINTENANCE_DISABLED,
            [
                'maintenance_disabled_at' => $disabledAt,
                'result' => 'maintenance_disabled',
            ]
        );

        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled_checkpoint', 'checkpoint', [
            'operator_admin_id' => $adminId,
            'maintenance_was_active' => $maintenanceActive,
            'database_writes' => false,
            'production_writes' => false,
        ]);

        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled', 'pass', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]);
    } else {
        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled_checkpoint', 'checkpoint', [
            'operator_admin_id' => $adminId,
            'maintenance_was_active' => false,
            'database_writes' => false,
            'production_writes' => false,
        ]);
        orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'maintenance_disabled', 'pass', [
            'operator_admin_id' => $adminId,
            'database_writes' => false,
            'production_writes' => false,
        ]);
    }

    if ((string) ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
        return orange_restore_merge_post_validation_reconcile_completed_artifacts(
            $workRoot,
            $jobId,
            $adminId,
            $job,
            $report,
            $reportPath,
            $durationSeconds,
            $options
        );
    }

    $completedAt = gmdate('c');
    $finalReportPath = orange_restore_final_restore_report_path($workRoot, $jobId);
    $job = orange_restore_job_post_validation_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        [
            'restore_completed_at' => $completedAt,
            'final_restore_report_path' => $finalReportPath,
            'result' => 'completed',
        ]
    );

    $finalReport = array_merge($report, [
        'completed_at' => $completedAt,
        'job_status' => ORANGE_RESTORE_JOB_STATUS_COMPLETED,
        'maintenance_disabled' => true,
    ]);
    orange_backup_write_json($finalReportPath, $finalReport);

    orange_restore_audit_post_validation_append_once($workRoot, $jobId, $job, 'restore_completed', 'pass', [
        'operator_admin_id' => $adminId,
        'duration_seconds' => $durationSeconds,
        'database_writes' => false,
        'production_writes' => false,
        'rollback_executed' => false,
    ]);

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
}

/**
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_load_pass_report(string $workRoot, string $jobId, array $job): array
{
    $reportPath = (string) ($job['post_validation_report_path'] ?? '');
    if ($reportPath === '') {
        $reportPath = orange_restore_production_post_validation_report_path($workRoot, $jobId);
    }
    if (!is_file($reportPath)) {
        throw new RuntimeException('Post-validation report missing for finalize (path=' . $reportPath . ').');
    }

    $raw = file_get_contents($reportPath);
    if ($raw === false) {
        throw new RuntimeException('Cannot read post-validation report: ' . $reportPath);
    }
    $report = json_decode($raw, true);
    if (!is_array($report)) {
        throw new RuntimeException('Invalid post-validation report JSON: ' . $reportPath);
    }
    if ((string) ($report['overall_result'] ?? '') !== 'pass') {
        throw new RuntimeException('Post-validation report does not show pass (overall_result=' . (string) ($report['overall_result'] ?? '') . ').');
    }

    return ['report' => $report, 'report_path' => $reportPath];
}

/**
 * Phase 2D.4 — finalize-only completion from post-validation checkpoints (no re-validation).
 *
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_restore_merge_post_validation_finalize_run(array $options): array
{
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Production post-validation finalize is CLI-only.');
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
    $backupRoot = orange_backup_resolve_root($env);

    $job = orange_restore_job_read($workRoot, $jobId);
    orange_restore_require_production_mutating_credentials($options, $job);
    $entryStatus = (string) ($job['status'] ?? '');

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Post-validation finalize applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);

    $loaded = orange_restore_merge_post_validation_load_pass_report($workRoot, $jobId, $job);

    orange_restore_merge_post_validation_verify_finalize_prerequisites(
        $projectRoot,
        $backupRoot,
        $workRoot,
        $jobId,
        $job,
        $loaded['report'],
        $options
    );

    if ($entryStatus === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_finalize_started', 'started', [
            'operator_admin_id' => $adminId,
            'resume_from' => $entryStatus,
            'reconciliation' => true,
            'database_writes' => false,
            'production_writes' => false,
        ]));

        return orange_restore_merge_post_validation_reconcile_completed_artifacts(
            $workRoot,
            $jobId,
            $adminId,
            $job,
            $loaded['report'],
            $loaded['report_path'],
            (int) ($job['duration_seconds'] ?? 0),
            array_merge($options, [
                'project_root' => $projectRoot,
                'backup_root' => $backupRoot,
            ])
        );
    }

    if (!in_array($entryStatus, orange_restore_merge_post_validation_finalize_entry_statuses(), true)) {
        throw new RuntimeException(
            'Post-validation finalize requires post_validation_passed, maintenance_disable_pending, maintenance_disabled, or completed reconciliation (status=' . $entryStatus . ').'
        );
    }

    orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_finalize_started', 'started', [
        'operator_admin_id' => $adminId,
        'resume_from' => $entryStatus,
        'database_writes' => false,
        'production_writes' => false,
    ]));

    return orange_restore_merge_post_validation_complete_after_pass(
        $workRoot,
        $jobId,
        $adminId,
        $job,
        $loaded['report'],
        $loaded['report_path'],
        (int) ($job['duration_seconds'] ?? 0),
        array_merge($options, [
            'project_root' => $projectRoot,
            'backup_root' => $backupRoot,
        ])
    );
}

/**
 * Phase 2D.4 — production post-validation after full DB + uploads cutover.
 *
 * Resume-aware entry: uploads_cutover_complete, production_merged.
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
    $backupRoot = orange_backup_resolve_root($env);

    $job = orange_restore_job_read($workRoot, $jobId);
    orange_restore_require_production_mutating_credentials($options, $job);
    $startedAt = microtime(true);
    $entryStatus = (string) ($job['status'] ?? '');

    if (!in_array($entryStatus, orange_restore_merge_post_validation_resume_entry_statuses(), true)) {
        throw new RuntimeException(
            'Post-validation entry requires uploads_cutover_complete or production_merged (status=' . $entryStatus . ').'
        );
    }

    if (($job['job_type'] ?? '') !== ORANGE_RESTORE_JOB_TYPE_FULL) {
        throw new RuntimeException('Post-validation applies to full_disaster jobs only.');
    }

    orange_restore_lock_assert_held_by_job($workRoot, $jobId);
    orange_restore_merge_maintenance_verify($workRoot, $jobId);

    if ($entryStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE) {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($job['operator_username'] ?? ''),
            'database_writes' => false,
            'production_writes' => false,
        ]));
    } else {
        orange_restore_audit_append($workRoot, $jobId, orange_restore_audit_post_validation_event($job, 'production_post_validation_resume_started', 'started', [
            'operator_admin_id' => $adminId,
            'operator_username' => (string) ($job['operator_username'] ?? ''),
            'resume_from' => $entryStatus,
            'database_writes' => false,
            'production_writes' => false,
        ]));
    }

    $originalFailure = null;

    try {
        if ($entryStatus === ORANGE_RESTORE_JOB_STATUS_UPLOADS_CUTOVER_COMPLETE) {
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
        }

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

        return orange_restore_merge_post_validation_complete_after_pass(
            $workRoot,
            $jobId,
            $adminId,
            $job,
            $report,
            $reportPath,
            $durationSeconds,
            array_merge($options, [
                'project_root' => $projectRoot,
                'backup_root' => $backupRoot,
            ])
        );
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
