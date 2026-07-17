<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_admin.php';
require_once __DIR__ . '/restore/restore_paths.php';
require_once __DIR__ . '/restore/restore_job.php';
require_once __DIR__ . '/restore/restore_lock.php';
require_once __DIR__ . '/restore/restore_merge_maintenance.php';
require_once __DIR__ . '/restore/restore_validation_adapter.php';
require_once __DIR__ . '/restore/restore_job_framework.php';
require_once __DIR__ . '/restore/restore_dry_run.php';
require_once __DIR__ . '/restore/restore_execution_orchestrator.php';
require_once __DIR__ . '/restore/restore_version_lock.php';
require_once __DIR__ . '/restore/restore_maintenance_framework.php';
require_once __DIR__ . '/restore/restore_final_approval.php';
require_once __DIR__ . '/restore/restore_execution_bridge.php';
require_once __DIR__ . '/restore/restore_pre_restore_backup.php';
require_once __DIR__ . '/restore/restore_shadow_db.php';
require_once __DIR__ . '/restore/restore_shadow_verify.php';
require_once __DIR__ . '/restore/restore_shadow_files.php';
require_once __DIR__ . '/restore/restore_shadow_smoke.php';
require_once __DIR__ . '/restore/restore_production_maintenance.php';
require_once __DIR__ . '/restore/restore_production_import.php';
require_once __DIR__ . '/restore/restore_production_uploads_cutover.php';
require_once __DIR__ . '/restore/restore_production_rollback.php';

const ORANGE_RESTORE_ADMIN_JOB_ID_PATTERN = '/^[a-zA-Z0-9._-]+$/';

/** @var list<string> */
const ORANGE_RESTORE_ADMIN_VIEWABLE_JOB_FILES = [
    'staging_restore_manifest.json',
    'restore_report.json',
    'final_restore_report.json',
    'production_post_validation.json',
];

/** @var list<string> */
const ORANGE_RESTORE_ADMIN_EXTRA_SECRET_FRAGMENTS = [
    'approval_token',
    'token_hash',
    'password_hash',
    'reauth',
];

function orange_restore_admin_may_view_full(array $admin, PDO $pdo): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }

    return orange_admin_may_backup_restore_full($admin, $pdo);
}

function orange_restore_admin_may_view_country(array $admin, PDO $pdo): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }

    return orange_admin_may_backup_restore_country($admin, $pdo);
}

function orange_restore_admin_require_view(array $admin, PDO $pdo): void
{
    if (!orange_admin_may_restore_center_view($admin, $pdo)) {
        throw new RuntimeException('Operator lacks restore center view permission.');
    }
}

function orange_restore_admin_require_get(): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        throw new RuntimeException('Method not allowed');
    }
}

function orange_restore_admin_assert_job_id(string $jobId): void
{
    if ($jobId === '' || !preg_match(ORANGE_RESTORE_ADMIN_JOB_ID_PATTERN, $jobId)) {
        throw new RuntimeException('Invalid restore job identifier.');
    }
}

function orange_restore_admin_assert_job_allowlisted(string $workRoot, string $jobId): void
{
    orange_restore_admin_assert_job_id($jobId);
    if (!in_array($jobId, orange_restore_job_list_ids($workRoot), true)) {
        throw new RuntimeException('Restore job not found.');
    }
}

function orange_restore_admin_assert_fw_job_allowlisted(string $workRoot, string $jobId): void
{
    orange_restore_admin_assert_job_id($jobId);
    if (!in_array($jobId, orange_restore_fw_list_ids($workRoot), true)) {
        throw new RuntimeException('Restore framework job not found.');
    }
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function orange_restore_admin_redact_secrets(array $data): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $keyLower = strtolower((string) $key);
        $blocked = false;
        foreach (array_merge(ORANGE_BACKUP_ADMIN_SECRET_KEY_FRAGMENTS, ORANGE_RESTORE_ADMIN_EXTRA_SECRET_FRAGMENTS) as $fragment) {
            if (str_contains($keyLower, $fragment)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        if (is_array($value)) {
            $out[$key] = orange_restore_admin_redact_secrets($value);
            continue;
        }
        if (is_string($value)) {
            $out[$key] = orange_restore_admin_redact_text($value);
            continue;
        }
        $out[$key] = $value;
    }

    return $out;
}

function orange_restore_admin_redact_text(string $text): string
{
    return orange_backup_admin_redact_text($text);
}

function orange_restore_admin_public_path(string $absolutePath): string
{
    $absolutePath = trim($absolutePath);
    if ($absolutePath === '') {
        return '';
    }
    $norm = str_replace('\\', '/', $absolutePath);
    if (preg_match('#/snapshots/([^/]+)(?:/|$)#', $norm, $m)) {
        return 'snapshots/' . $m[1];
    }
    if (preg_match('#/country_packages/([A-Za-z]{2})/([^/]+)(?:/|$)#', $norm, $m)) {
        return 'country_packages/' . strtolower($m[1]) . '/' . $m[2];
    }
    if (preg_match('#/restore_work/([^/]+)/([^/]+)(?:/|$)#', $norm, $m)) {
        return 'restore_work/' . $m[1] . '/' . $m[2];
    }

    return basename($norm);
}

/**
 * @return array{backup_root:string,work_root:string,env:array<string,mixed>}
 */
function orange_restore_admin_context(string $projectRoot): array
{
    $viewCtx = orange_backup_admin_context_for_view($projectRoot);
    $env = $viewCtx['env'];
    $workRoot = '';
    try {
        $workRoot = orange_restore_resolve_work_root($env);
    } catch (Throwable) {
        $workRoot = '';
    }

    return [
        'backup_root' => $viewCtx['backup_root'],
        'work_root' => $workRoot,
        'env' => $env,
    ];
}

/**
 * @return array{drv_result:string,drv_score:?int}
 */
function orange_restore_admin_drv_fields_from_package(array $package): array
{
    $verification = is_array($package['verification'] ?? null) ? $package['verification'] : null;
    if ($verification === null) {
        return ['drv_result' => 'missing', 'drv_score' => null];
    }

    $overall = strtolower(trim((string) ($verification['overall_result'] ?? '')));
    $drvResult = match ($overall) {
        'pass' => 'pass',
        'fail' => 'fail',
        'warning' => 'unknown',
        default => 'unknown',
    };

    $score = orange_restore_admin_recovery_score_from_verification($verification);

    return ['drv_result' => $drvResult, 'drv_score' => $score];
}

function orange_restore_admin_recovery_score_from_verification(?array $verification): ?int
{
    if (!is_array($verification)) {
        return null;
    }

    return orange_backup_admin_recovery_score_from_report($verification);
}

function orange_restore_admin_eligibility_reason_label_ar(string $code): string
{
    return match ($code) {
        'package_not_healthy' => 'الحزمة غير سليمة',
        'package_type_invalid' => 'نوع الحزمة غير مدعوم للاسترداد',
        'schema_incompatible' => 'إصدار المخطط غير متوافق',
        'drv_report_missing' => 'تقرير DRV غير موجود',
        'drv_score_below_threshold' => 'درجة DRV أقل من الحد المطلوب (70)',
        'drv_not_pass' => 'فشل التحقق DRV',
        'drv_result_warning_country' => 'DRV بحالة تحذير — Country يتطلب pass',
        'export_backend_unsupported' => 'محرك التصدير غير مدعوم للاسترداد',
        'registry_invalid' => 'سجل الدولة غير صالح',
        'registry_missing' => 'سجل الدولة غير مسجل في الحزمة',
        default => 'غير مؤهل للاسترداد',
    };
}

/**
 * Read-only eligibility aligned with orange_restore_validation_adapter_*_package_precheck().
 *
 * @param array<string, mixed> $package
 * @return array{
 *   eligibility_status:string,
 *   eligibility_reason_code:string,
 *   eligibility_reason_label_ar:string,
 *   drv_result:string,
 *   drv_score:?int,
 *   reasons:list<string>
 * }
 */
function orange_restore_admin_package_eligibility(array $package, string $packageType): array
{
    $reasons = [];
    $drvFields = orange_restore_admin_drv_fields_from_package($package);
    $verification = is_array($package['verification'] ?? null) ? $package['verification'] : null;

    $declaredType = (string) ($package['package_type'] ?? '');
    if ($packageType === 'full_disaster' && $declaredType !== 'full_disaster') {
        $reasons[] = 'package_type_invalid';
    }
    if ($packageType === 'country_recovery' && $declaredType !== 'country_recovery') {
        $reasons[] = 'package_type_invalid';
    }

    if (empty($package['healthy'])) {
        $reasons[] = 'package_not_healthy';
    }

    $schemaRevision = (int) ($package['schema_revision'] ?? 0);
    if ($schemaRevision !== ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION) {
        $reasons[] = 'schema_incompatible';
    }

    if ($verification === null) {
        $reasons[] = 'drv_report_missing';
    }

    if ($packageType === 'full_disaster') {
        $backend = strtolower(trim((string) ($package['backend'] ?? '')));
        if ($backend !== '' && $backend !== 'php_pdo') {
            $reasons[] = 'export_backend_unsupported';
        }
        if ($verification !== null) {
            $overall = strtolower(trim((string) ($verification['overall_result'] ?? '')));
            if ($drvFields['drv_score'] !== null && $drvFields['drv_score'] < 70) {
                $reasons[] = 'drv_score_below_threshold';
            } elseif ($drvFields['drv_score'] === null && !in_array($overall, ['pass', 'warning'], true)) {
                $reasons[] = 'drv_not_pass';
            }
        }
    } else {
        $registryVersion = trim((string) ($package['registry_version'] ?? ''));
        if ($registryVersion === '') {
            $reasons[] = 'registry_missing';
        } elseif ($registryVersion !== ORANGE_RECOVERY_VALIDATION_EXPECTED_REGISTRY_VERSION) {
            $reasons[] = 'registry_invalid';
        }
        $overall = strtolower(trim((string) ($verification['overall_result'] ?? '')));
        if ($verification !== null && $overall !== 'pass') {
            $reasons[] = $overall === 'warning' ? 'drv_result_warning_country' : 'drv_not_pass';
        }
    }

    $reasons = array_values(array_unique($reasons));
    $primaryReason = $reasons[0] ?? '';

    if ($reasons === []) {
        return [
            'eligibility_status' => 'eligible',
            'eligibility_reason_code' => '',
            'eligibility_reason_label_ar' => '',
            'drv_result' => $drvFields['drv_result'],
            'drv_score' => $drvFields['drv_score'],
            'reasons' => [],
        ];
    }

    $status = 'not_eligible';
    if ($reasons === ['drv_report_missing']) {
        $status = 'unknown';
    }

    return [
        'eligibility_status' => $status,
        'eligibility_reason_code' => $primaryReason,
        'eligibility_reason_label_ar' => orange_restore_admin_eligibility_reason_label_ar($primaryReason),
        'drv_result' => $drvFields['drv_result'],
        'drv_score' => $drvFields['drv_score'],
        'reasons' => $reasons,
    ];
}

/**
 * @param array<string, mixed> $package
 * @return array<string, mixed>
 */
function orange_restore_admin_public_package_row(array $package, string $packageType): array
{
    unset($package['package_path']);
    $row = orange_restore_admin_redact_secrets($package);
    $eligibility = orange_restore_admin_package_eligibility($package, $packageType);
    $row['eligibility_status'] = $eligibility['eligibility_status'];
    $row['eligibility_reason_code'] = $eligibility['eligibility_reason_code'];
    $row['eligibility_reason_label_ar'] = $eligibility['eligibility_reason_label_ar'];
    $row['drv_result'] = $eligibility['drv_result'];
    $row['drv_score'] = $eligibility['drv_score'];
    $row['restore_eligibility'] = $eligibility['eligibility_status'] === 'eligible' ? 'eligible' : ($eligibility['eligibility_status'] === 'unknown' ? 'unknown' : 'not_eligible');
    $row['restore_eligibility_reasons'] = $eligibility['reasons'];
    if (!array_key_exists('recovery_score', $row) || $row['recovery_score'] === null) {
        unset($row['recovery_score']);
    }

    return $row;
}

/**
 * @return list<string>
 */
function orange_restore_admin_failed_statuses(): array
{
    return [
        ORANGE_RESTORE_JOB_STATUS_FAILED,
        ORANGE_RESTORE_JOB_STATUS_FAILED_MERGE,
        ORANGE_RESTORE_JOB_STATUS_FAILED_POST_MERGE,
        ORANGE_RESTORE_JOB_STATUS_ROLLBACK_FAILED,
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_collect_overview(string $workRoot): array
{
    $counts = [
        'total_jobs' => 0,
        'awaiting_owner_approval' => 0,
        'approved_for_merge' => 0,
        'active_in_progress' => 0,
        'failed_jobs' => 0,
        'completed_jobs' => 0,
        'rolled_back_jobs' => 0,
    ];

    $terminal = orange_restore_job_e2e_terminal_statuses();
    $failedStatuses = orange_restore_admin_failed_statuses();

    if ($workRoot !== '' && is_dir($workRoot)) {
        foreach (orange_restore_job_list_ids($workRoot) as $jobId) {
            try {
                $job = orange_restore_job_read($workRoot, $jobId);
            } catch (Throwable) {
                continue;
            }
            $status = (string) ($job['status'] ?? '');
            $counts['total_jobs']++;
            if ($status === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
                $counts['awaiting_owner_approval']++;
            }
            if ($status === ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE) {
                $counts['approved_for_merge']++;
            }
            if (in_array($status, $failedStatuses, true)) {
                $counts['failed_jobs']++;
            }
            if ($status === ORANGE_RESTORE_JOB_STATUS_COMPLETED) {
                $counts['completed_jobs']++;
            }
            if ($status === ORANGE_RESTORE_JOB_STATUS_ROLLED_BACK) {
                $counts['rolled_back_jobs']++;
            }
            if (!in_array($status, $terminal, true)
                && !in_array($status, $failedStatuses, true)
                && $status !== ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL
                && $status !== ORANGE_RESTORE_JOB_STATUS_APPROVED_FOR_MERGE) {
                $counts['active_in_progress']++;
            }
        }
    }

    $lock = $workRoot !== '' ? orange_restore_lock_status($workRoot) : ['held' => false, 'payload' => null];
    $maintenance = $workRoot !== '' ? orange_restore_merge_maintenance_status($workRoot) : ['active' => false, 'payload' => []];

    return [
        'job_counts' => $counts,
        'restore_lock' => [
            'held' => (bool) ($lock['held'] ?? false),
            'job_id' => (string) (($lock['payload'] ?? [])['job_id'] ?? ''),
            'started_at' => (string) (($lock['payload'] ?? [])['started_at'] ?? ''),
        ],
        'maintenance' => [
            'active' => (bool) ($maintenance['active'] ?? false),
            'job_id' => (string) (($maintenance['payload'] ?? [])['job_id'] ?? ''),
            'enabled_at' => (string) (($maintenance['payload'] ?? [])['enabled_at'] ?? ''),
            'reason' => (string) (($maintenance['payload'] ?? [])['reason'] ?? ''),
        ],
    ];
}

function orange_restore_admin_package_id_from_job(array $job): string
{
    $path = (string) ($job['source_package_path'] ?? '');
    if ($path === '') {
        return '';
    }

    return basename(str_replace('\\', '/', $path));
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_summarize_job(string $workRoot, string $jobId): array
{
    orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_job_read($workRoot, $jobId);

    return orange_restore_admin_redact_secrets([
        'job_id' => $jobId,
        'job_type' => (string) ($job['job_type'] ?? ''),
        'country_code' => (string) ($job['country_code'] ?? ''),
        'package_id' => orange_restore_admin_package_id_from_job($job),
        'package_ref' => orange_restore_admin_public_path((string) ($job['source_package_path'] ?? '')),
        'status' => (string) ($job['status'] ?? ''),
        'current_phase' => (string) ($job['status'] ?? ''),
        'updated_at' => (string) ($job['updated_at'] ?? ''),
        'created_at' => (string) ($job['created_at'] ?? ''),
        'operator_username' => (string) ($job['operator_username'] ?? ''),
        'operator_admin_id' => (int) ($job['operator_admin_id'] ?? 0),
        'rollback_anchor_checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
        'rollback_anchor_ref' => orange_restore_admin_public_path((string) ($job['fresh_backup_path'] ?? '')),
        'schema_revision' => (int) ($job['schema_revision'] ?? 0),
        'source_package_checksum' => (string) ($job['source_package_checksum'] ?? ''),
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_admin_list_jobs(string $workRoot, bool $mayFull, bool $mayCountry): array
{
    if ($workRoot === '' || !is_dir($workRoot)) {
        return [];
    }

    $rows = [];
    foreach (orange_restore_job_list_ids($workRoot) as $jobId) {
        try {
            $summary = orange_restore_admin_summarize_job($workRoot, $jobId);
        } catch (Throwable) {
            continue;
        }
        $type = (string) ($summary['job_type'] ?? '');
        if ($type === ORANGE_RESTORE_JOB_TYPE_FULL && !$mayFull) {
            continue;
        }
        if ($type === ORANGE_RESTORE_JOB_TYPE_COUNTRY && !$mayCountry) {
            continue;
        }
        $rows[] = $summary;
    }

    usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_job_detail(string $projectRoot, string $workRoot, string $jobId): array
{
    orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
    require_once __DIR__ . '/restore/restore_orchestrator.php';

    $report = orange_restore_orchestrator_job_status_report($workRoot, $jobId);
    $job = orange_restore_job_read($workRoot, $jobId);
    $lock = orange_restore_lock_status($workRoot);
    $maintenance = orange_restore_merge_maintenance_status($workRoot);

    $timeline = orange_restore_admin_build_timeline($job, $report['audit_events'] ?? []);

    $detail = [
        'job_id' => $jobId,
        'job_type' => (string) ($report['job_type'] ?? ''),
        'status' => (string) ($report['status'] ?? ''),
        'created_at' => (string) ($report['created_at'] ?? ''),
        'updated_at' => (string) ($report['updated_at'] ?? ''),
        'operator_username' => (string) ($job['operator_username'] ?? ''),
        'package' => [
            'ref' => orange_restore_admin_public_path((string) ($job['source_package_path'] ?? '')),
            'checksum' => (string) ($job['source_package_checksum'] ?? ''),
            'schema_revision' => (int) ($job['schema_revision'] ?? 0),
            'version' => (string) ($job['package_version'] ?? ''),
        ],
        'staging' => [
            'manifest_ref' => orange_restore_admin_public_path((string) ($report['staging']['manifest_path'] ?? '')),
            'manifest_checksum' => (string) ($report['staging']['manifest_checksum'] ?? ''),
            'validation_passed' => (bool) ($report['staging']['validation_passed'] ?? false),
            'validation_gate_ok' => (bool) ($report['staging']['validation_gate_ok'] ?? false),
        ],
        'rollback_anchor' => [
            'ref' => orange_restore_admin_public_path((string) ($job['fresh_backup_path'] ?? '')),
            'checksum' => (string) ($job['fresh_backup_checksum'] ?? ''),
            'checkpoint' => (string) ($job['rollback_checkpoint'] ?? ''),
        ],
        'approval' => [
            'status' => (string) (($report['approval'] ?? [])['status'] ?? ''),
            'window_started_at' => (string) (($report['approval'] ?? [])['window_started_at'] ?? ''),
            'window_expires_at' => (string) (($report['approval'] ?? [])['window_expires_at'] ?? ''),
            'approved_at' => (string) (($report['approval'] ?? [])['approved_at'] ?? ''),
            'approved_by' => (string) (($report['approval'] ?? [])['approved_by'] ?? ''),
            'token_consumed' => (string) (($report['approval'] ?? [])['token_consumed_at'] ?? '') !== '',
            'token_active' => (bool) (($report['approval'] ?? [])['token_active'] ?? false),
        ],
        'lock' => [
            'held' => (bool) ($lock['held'] ?? false),
            'job_id' => (string) (($lock['payload'] ?? [])['job_id'] ?? ''),
        ],
        'maintenance' => [
            'active' => (bool) ($maintenance['active'] ?? false),
            'job_id' => (string) (($maintenance['payload'] ?? [])['job_id'] ?? ''),
            'enabled_at' => (string) (($maintenance['payload'] ?? [])['enabled_at'] ?? ''),
        ],
        'database_cutover' => [
            'started_at' => (string) ($job['database_cutover_started_at'] ?? ''),
            'completed_at' => (string) ($job['database_cutover_completed_at'] ?? ''),
            'statement_count' => (int) ($job['database_cutover_statement_count'] ?? 0),
        ],
        'uploads_cutover' => [
            'started_at' => (string) ($job['uploads_cutover_started_at'] ?? ''),
            'completed_at' => (string) ($job['uploads_cutover_completed_at'] ?? ''),
            'first_rename_complete' => (bool) ($job['uploads_cutover_first_rename_complete'] ?? false),
            'second_rename_pending' => (bool) ($job['uploads_cutover_second_rename_pending'] ?? false),
        ],
        'post_validation' => [
            'passed_at' => (string) ($job['post_validation_passed_at'] ?? ''),
            'report_ref' => orange_restore_admin_public_path((string) ($job['post_validation_report_path'] ?? '')),
        ],
        'rollback_checkpoints' => [
            'checkpoint' => (string) ($job['rollback_checkpoint'] ?? ''),
            'production_merged_at' => (string) ($job['production_merged_at'] ?? ''),
            'restore_completed_at' => (string) ($job['restore_completed_at'] ?? ''),
        ],
        'timeline' => $timeline,
        'audit_events' => orange_restore_admin_sanitize_audit_list($report['audit_events'] ?? []),
        'errors' => array_map('orange_restore_admin_redact_text', $report['errors'] ?? []),
        'warnings' => array_map('orange_restore_admin_redact_text', $report['warnings'] ?? []),
        'read_only' => true,
    ];

    return orange_restore_admin_redact_secrets($detail);
}

/**
 * @param list<array<string, mixed>> $events
 * @return list<array<string, mixed>>
 */
function orange_restore_admin_sanitize_audit_list(array $events): array
{
    $out = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $out[] = orange_restore_admin_redact_secrets([
            'recorded_at' => (string) ($event['recorded_at'] ?? ''),
            'stage' => (string) ($event['stage'] ?? ''),
            'result' => (string) ($event['result'] ?? ''),
            'job_status' => (string) ($event['job_status'] ?? ''),
            'operator_username' => (string) ($event['operator_username'] ?? ''),
            'approval_event' => (string) ($event['approval_event'] ?? ''),
            'merge_event' => (string) ($event['merge_event'] ?? ''),
            'rollback_event' => (string) ($event['rollback_event'] ?? ''),
            'e2e_event' => (string) ($event['e2e_event'] ?? ''),
        ]);
    }

    return $out;
}

/**
 * @param array<string, mixed> $job
 * @param list<array<string, mixed>> $auditEvents
 * @return list<array<string, string>>
 */
function orange_restore_admin_build_timeline(array $job, array $auditEvents): array
{
    $entries = [];
    $stampFields = [
        'created_at' => 'created',
        'updated_at' => 'updated',
        'owner_approval_window_started_at' => 'approval_window_started',
        'owner_approval_at' => 'owner_approved',
        'merge_started_at' => 'merge_started',
        'database_cutover_completed_at' => 'database_cutover_complete',
        'uploads_cutover_completed_at' => 'uploads_cutover_complete',
        'production_merged_at' => 'production_merged',
        'post_validation_passed_at' => 'post_validation_passed',
        'maintenance_disabled_at' => 'maintenance_disabled',
        'restore_completed_at' => 'restore_completed',
    ];
    foreach ($stampFields as $field => $label) {
        $at = trim((string) ($job[$field] ?? ''));
        if ($at !== '') {
            $entries[] = ['at' => $at, 'event' => $label, 'source' => 'job'];
        }
    }
    foreach ($auditEvents as $event) {
        if (!is_array($event)) {
            continue;
        }
        $at = trim((string) ($event['recorded_at'] ?? ''));
        if ($at === '') {
            continue;
        }
        $label = (string) ($event['stage'] ?? 'audit');
        if (($event['approval_event'] ?? '') !== '') {
            $label = 'approval:' . (string) $event['approval_event'];
        } elseif (($event['merge_event'] ?? '') !== '') {
            $label = 'merge:' . (string) $event['merge_event'];
        } elseif (($event['rollback_event'] ?? '') !== '') {
            $label = 'rollback:' . (string) $event['rollback_event'];
        } elseif (($event['e2e_event'] ?? '') !== '') {
            $label = 'e2e:' . (string) $event['e2e_event'];
        }
        $entries[] = [
            'at' => $at,
            'event' => $label,
            'result' => (string) ($event['result'] ?? ''),
            'source' => 'audit',
        ];
    }
    usort($entries, static fn (array $a, array $b): int => strcmp((string) ($a['at'] ?? ''), (string) ($b['at'] ?? '')));

    return $entries;
}

/**
 * @return array{ok:bool,data:?array<string,mixed>,raw_text:?string,errors:list<string>}
 */
function orange_restore_admin_read_job_file(string $workRoot, string $jobId, string $fileName): array
{
    orange_restore_admin_assert_job_allowlisted($workRoot, $jobId);
    if (!in_array($fileName, ORANGE_RESTORE_ADMIN_VIEWABLE_JOB_FILES, true)) {
        throw new RuntimeException('File type is not allowlisted.');
    }

    $jobDir = orange_restore_job_directory($workRoot, $jobId);
    $fullPath = $jobDir . DIRECTORY_SEPARATOR . $fileName;
    $resolvedFile = realpath($fullPath);
    $resolvedJobDir = realpath($jobDir);
    if ($resolvedFile === false || !is_file($resolvedFile) || $resolvedJobDir === false) {
        return ['ok' => false, 'data' => null, 'raw_text' => null, 'errors' => ['File not found: ' . $fileName]];
    }
    if (!str_starts_with(str_replace('\\', '/', $resolvedFile), str_replace('\\', '/', $resolvedJobDir))) {
        throw new RuntimeException('Path traversal blocked.');
    }

    $raw = file_get_contents($resolvedFile);
    if ($raw === false) {
        return ['ok' => false, 'data' => null, 'raw_text' => null, 'errors' => ['Cannot read file.']];
    }

    if (str_ends_with($fileName, '.json')) {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return [
                'ok' => true,
                'data' => orange_restore_admin_redact_secrets($decoded),
                'raw_text' => null,
                'errors' => [],
            ];
        } catch (JsonException) {
            return [
                'ok' => false,
                'data' => null,
                'raw_text' => orange_restore_admin_redact_text($raw),
                'errors' => ['Invalid JSON in job file.'],
            ];
        }
    }

    return [
        'ok' => true,
        'data' => null,
        'raw_text' => orange_restore_admin_redact_text($raw),
        'errors' => [],
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_package_detail(
    string $backupRoot,
    string $packageType,
    string $packageId,
    string $countryCode = ''
): array {
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        $summary = orange_backup_admin_summarize_full_package($packagePath, $packageId);
    } elseif ($packageType === 'country_recovery') {
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
        $summary = orange_backup_admin_summarize_country_package($packagePath, $packageId, $countryCode);
    } else {
        throw new RuntimeException('Invalid package type.');
    }

    return orange_restore_admin_public_package_row($summary, $packageType);
}

function orange_restore_admin_assert_job_type_visible(string $jobType, bool $mayFull, bool $mayCountry): void
{
    if ($jobType === ORANGE_RESTORE_JOB_TYPE_FULL && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($jobType === ORANGE_RESTORE_JOB_TYPE_COUNTRY && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }
}

function orange_restore_admin_assert_package_type_permission(
    array $admin,
    PDO $pdo,
    string $packageType
): void {
    if ($packageType === 'full_disaster') {
        if (!orange_restore_admin_may_view_full($admin, $pdo)) {
            throw new RuntimeException('Operator lacks backup_restore_full permission.');
        }

        return;
    }
    if ($packageType === 'country_recovery') {
        if (!orange_restore_admin_may_view_country($admin, $pdo)) {
            throw new RuntimeException('Operator lacks backup_restore_country permission.');
        }

        return;
    }

    throw new RuntimeException('Invalid package type.');
}

/**
 * @return list<array<string, mixed>>
 */
function orange_restore_admin_fw_list_jobs(string $workRoot, bool $mayFull, bool $mayCountry): array
{
    if ($workRoot === '' || !is_dir($workRoot)) {
        return [];
    }
    $rows = [];
    foreach (orange_restore_fw_list_jobs($workRoot) as $row) {
        $type = (string) ($row['package_type'] ?? '');
        if ($type === 'full_disaster' && !$mayFull) {
            continue;
        }
        if ($type === 'country_recovery' && !$mayCountry) {
            continue;
        }
        $rows[] = $row;
    }

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_view_job(string $workRoot, string $jobId, bool $mayFull, bool $mayCountry): array
{
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $public = orange_restore_fw_public_row($job);
    $public['audit_events'] = orange_restore_admin_sanitize_audit_list(
        array_map(static function (array $event): array {
            return [
                'recorded_at' => (string) ($event['recorded_at'] ?? ''),
                'stage' => (string) ($event['event'] ?? $event['stage'] ?? ''),
                'result' => (string) ($event['result'] ?? ''),
                'job_status' => (string) ($event['status'] ?? ''),
                'operator_username' => (string) ($event['operator_username'] ?? ''),
                'message' => (string) ($event['message'] ?? ''),
            ];
        }, orange_restore_fw_audit_read($workRoot, $jobId))
    );
    $public['read_only_execution'] = true;
    $public['execution_enabled'] = false;

    return $public;
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_create_job(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $packageType,
    string $packageId,
    string $countryCode = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $packageType);
    orange_backup_admin_assert_package_id($packageId);

    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
        $summary = orange_backup_admin_summarize_full_package($packagePath, $packageId);
        $countryCode = '';
    } else {
        orange_backup_admin_assert_country_code($countryCode);
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
        $summary = orange_backup_admin_summarize_country_package($packagePath, $packageId, $countryCode);
    }

    $eligibility = orange_restore_admin_package_eligibility($summary, $packageType);
    if (($eligibility['eligibility_status'] ?? '') !== 'eligible') {
        throw new RuntimeException(
            'Package is not eligible for restore job creation: '
            . (string) ($eligibility['eligibility_reason_code'] ?? 'not_eligible')
        );
    }

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => $packageId,
        'package_type' => $packageType,
        'country_code' => $countryCode !== '' ? $countryCode : null,
        'created_by' => $username !== '' ? $username : 'admin',
        'created_by_admin_id' => (int) ($admin['id'] ?? 0),
    ]);

    return orange_restore_fw_public_row($job);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_cancel_job(
    string $workRoot,
    array $admin,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }
    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $cancelled = orange_restore_fw_cancel($workRoot, $jobId, $username !== '' ? $username : 'admin');

    return orange_restore_fw_public_row($cancelled);
}

/**
 * @param array<string, mixed> $options
 * @return array{job:array<string,mixed>,report:array<string,mixed>}
 */
function orange_restore_admin_fw_dry_run(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId = '',
    string $packageType = '',
    string $packageId = '',
    string $countryCode = '',
    array $options = []
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }

    if ($jobId === '') {
        if ($packageType === '' || $packageId === '') {
            throw new RuntimeException('job_id or package_type+package_id required.');
        }
        $created = orange_restore_admin_fw_create_job(
            $backupRoot,
            $workRoot,
            $admin,
            $pdo,
            $packageType,
            $packageId,
            $countryCode
        );
        $jobId = (string) ($created['job_id'] ?? '');
    }

    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $context = [
        'backup_root' => $backupRoot,
        'operator_username' => $username !== '' ? $username : 'admin',
    ];
    if (array_key_exists('disk_free_bytes_override', $options)) {
        $context['disk_free_bytes_override'] = $options['disk_free_bytes_override'];
    }

    return orange_restore_dry_run_execute($workRoot, $jobId, $context);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_dry_report(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }
    $report = orange_restore_dry_run_read_report($workRoot, $jobId);
    if ($report === null) {
        throw new RuntimeException('Dry run report not found.');
    }

    return $report;
}

/**
 * @return array{job:array<string,mixed>,plan:array<string,mixed>}
 */
function orange_restore_admin_fw_prepare_execution(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));

    return orange_restore_exec_prepare_plan($workRoot, $jobId, [
        'backup_root' => $backupRoot,
        'operator_username' => $username !== '' ? $username : 'admin',
        'operator_admin_id' => (int) ($admin['id'] ?? 0),
    ]);
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_execution_plan(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    return orange_restore_exec_public_plan(orange_restore_exec_read_plan($workRoot, $jobId));
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_cancel_execution_plan(
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId,
    string $reason = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));

    return orange_restore_exec_cancel_plan(
        $workRoot,
        $jobId,
        $username !== '' ? $username : 'admin',
        $reason
    );
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_create_approval_challenge(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    return orange_restore_final_approval_create_challenge($workRoot, $jobId, $backupRoot, $admin, $pdo);
}

/**
 * @return array{job:array<string,mixed>,approval:array<string,mixed>}
 */
function orange_restore_admin_fw_final_approve(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId,
    string $packageId,
    string $confirmationPhrase,
    string $nonce,
    string $password
): array {
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    return orange_restore_final_approval_grant(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        $packageId,
        $confirmationPhrase,
        $nonce,
        $password
    );
}

/**
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_maintenance_status(string $workRoot): array
{
    return orange_restore_maint_fw_public(orange_restore_maint_fw_read($workRoot));
}

/**
 * Request pre-restore Full backup preparation (metadata only; CLI runs the engine).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_pre_restore_backup(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_pre_backup_request($workRoot, $jobId, $backupRoot, $admin);
}

/**
 * Read-only pre-restore backup status/record.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_pre_restore_backup(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $record = orange_restore_pre_backup_load_record($workRoot, $jobId);
    $labels = [
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING => 'بانتظار تشغيل عامل CLI',
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING => 'جارٍ إنشاء النسخة الاحتياطية',
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING => 'جارٍ التحقق',
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY => 'النسخة الاحتياطية جاهزة وآمنة للرجوع',
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED => 'فشل إعداد النسخة الاحتياطية',
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION => 'بانتظار طلب إعداد النسخة الاحتياطية',
    ];

    return [
        'job' => orange_restore_fw_public_row($job),
        'record' => $record !== null ? orange_restore_pre_backup_public_record($record) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'execution_started' => false,
        'read_only' => true,
    ];
}

/**
 * Request shadow DB restore (metadata only; CLI runs import).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_shadow_restore(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_shadow_request($workRoot, $jobId, $backupRoot, $admin);
}

/**
 * Read-only shadow restore status/report.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_shadow_restore(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $labels = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING => 'بانتظار تشغيل عامل CLI لقاعدة الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_RUNNING => 'جارٍ استيراد قاعدة الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_VERIFYING => 'جارٍ التحقق من قاعدة الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY => 'قاعدة الظل جاهزة (الإنتاج لم يُمس)',
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED => 'فشل استعادة قاعدة الظل',
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY => 'بانتظار طلب استعادة قاعدة الظل',
    ];
    $meta = orange_restore_shadow_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_load_report($workRoot, $jobId);

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => $meta !== null ? orange_restore_shadow_public_meta($meta) : null,
        'report' => $report !== null ? orange_restore_shadow_public_report($report) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'execution_started' => false,
        'production_touched' => false,
        'read_only' => true,
    ];
}

/**
 * Read-only shadow verification status/report (HTTP never runs verifier).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_shadow_verification(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $labels = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFYING => 'جارٍ التحقق العميق من قاعدة الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED => 'قاعدة الظل موثّقة وجاهزة (بدون قطع إنتاج)',
        ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY => 'قاعدة الظل غير جاهزة للقطع',
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY => 'بانتظار تشغيل عامل CLI للتحقق',
    ];
    $meta = orange_restore_shadow_verify_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_verify_load_report($workRoot, $jobId);
    $cliNeeded = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY,
    ], true);

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => $meta !== null ? orange_restore_shadow_verify_public_meta($meta) : null,
        'report' => $report !== null ? orange_restore_shadow_verify_public_report($report) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'cli_needed' => $cliNeeded,
        'cli_command' => 'php scripts/backup/restore_shadow_verify.php --job=' . $jobId,
        'execution_started' => false,
        'production_touched' => false,
        'read_only' => true,
    ];
}

/**
 * Request shadow smoke (metadata only; CLI runs tests).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_shadow_smoke(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_shadow_smoke_request($workRoot, $jobId, $backupRoot, $admin);
}

/**
 * Read-only shadow smoke status/report (HTTP never runs smoke).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_shadow_smoke(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $labels = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING => 'بانتظار تشغيل اختبار CLI',
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_RUNNING => 'جارٍ اختبار قاعدة البيانات والملفات المعزولة',
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_READY => 'البيئة المعزولة جاهزة',
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING => 'تحتاج مراجعة يدوية',
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED => 'البيئة غير جاهزة',
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY => 'البيئة المعزولة جاهزة',
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW => 'تحتاج مراجعة يدوية',
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED => 'البيئة غير جاهزة',
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY => 'بانتظار تشغيل اختبار CLI',
    ];
    $meta = orange_restore_shadow_smoke_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_smoke_load_report($workRoot, $jobId);
    $decision = orange_restore_cutover_readiness_load($workRoot, $jobId);
    $cliNeeded = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_PENDING,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_FAILED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_SMOKE_WARNING,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED,
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW,
    ], true);

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => $meta !== null ? orange_restore_shadow_smoke_public_meta($meta) : null,
        'report' => $report !== null ? orange_restore_shadow_smoke_public_report($report) : null,
        'cutover_readiness' => $decision !== null ? orange_restore_cutover_readiness_public($decision) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'cli_needed' => $cliNeeded,
        'cli_command' => 'php scripts/backup/restore_shadow_smoke.php --job=' . $jobId,
        'execution_started' => false,
        'production_touched' => false,
        'production_cutover_allowed' => false,
        'read_only' => true,
    ];
}

/**
 * Read-only cutover readiness decision (never allows production cutover).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_cutover_readiness(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $labels = [
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_READY => 'البيئة المعزولة جاهزة',
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_MANUAL_REVIEW => 'تحتاج مراجعة يدوية',
        ORANGE_RESTORE_FW_STATUS_CUTOVER_READINESS_BLOCKED => 'البيئة غير جاهزة',
    ];
    $decision = orange_restore_cutover_readiness_load($workRoot, $jobId);
    $report = orange_restore_shadow_smoke_load_report($workRoot, $jobId);

    return [
        'job' => orange_restore_fw_public_row($job),
        'cutover_readiness' => $decision !== null ? orange_restore_cutover_readiness_public($decision) : null,
        'smoke_report' => $report !== null ? orange_restore_shadow_smoke_public_report($report) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'execution_started' => false,
        'production_touched' => false,
        'production_cutover_allowed' => false,
        'read_only' => true,
        'warning' => 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج، ولا يزال التحويل إلى الإنتاج غير مسموح.',
    ];
}

/**
 * Read-only shadow files status/report (HTTP never runs extractor).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_shadow_files(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $status = (string) ($job['status'] ?? '');
    $labels = [
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_RUNNING => 'جارٍ استخراج ملفات الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_VERIFYING => 'جارٍ التحقق من ملفات الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY => 'ملفات الظل جاهزة (الإنتاج لم يُمس)',
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED => 'فشل استخراج ملفات الظل',
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED => 'بانتظار تشغيل عامل CLI لملفات الظل',
    ];
    $meta = orange_restore_shadow_files_load_meta($workRoot, $jobId);
    $report = orange_restore_shadow_files_load_report($workRoot, $jobId);
    $cliNeeded = in_array($status, [
        ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED,
        ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_FAILED,
    ], true);

    return [
        'job' => orange_restore_fw_public_row($job),
        'meta' => $meta !== null ? orange_restore_shadow_files_public_meta($meta) : null,
        'report' => $report !== null ? orange_restore_shadow_files_public_report($report) : null,
        'status_label_ar' => $labels[$status] ?? '',
        'cli_needed' => $cliNeeded,
        'cli_command' => 'php scripts/backup/restore_shadow_files.php --job=' . $jobId,
        'execution_started' => false,
        'production_touched' => false,
        'read_only' => true,
    ];
}

/**
 * Read-only execution contract for an approved framework job.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_execution_contract(
    string $workRoot,
    string $backupRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    $contract = orange_restore_load_execution_contract($workRoot, $jobId);
    $validation = orange_restore_validate_execution_contract($workRoot, $jobId, $backupRoot, $contract);

    return [
        'contract' => orange_restore_bridge_public_contract($contract),
        'validation' => [
            'ok' => (bool) ($validation['ok'] ?? false),
            'code' => (string) ($validation['code'] ?? ''),
            'reasons' => is_array($validation['reasons'] ?? null)
                ? array_values($validation['reasons'])
                : [],
        ],
        'execution_started' => false,
        'read_only' => true,
    ];
}

/**
 * Request production maintenance activation (metadata only; no restore).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_maintenance(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_prod_maint_request($workRoot, $jobId, $backupRoot, $admin);
}

/**
 * Activate production maintenance framework only (no restore/cutover/rollback).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_activate_maintenance(
    string $backupRoot,
    string $workRoot,
    array $admin,
    PDO $pdo,
    string $jobId,
    string $password,
    string $nonce
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_prod_maint_activate(
        $workRoot,
        $jobId,
        $backupRoot,
        $admin,
        $pdo,
        $password,
        $nonce
    );
}

/**
 * Read-only production maintenance state.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_maintenance_state(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    if ($jobId !== '') {
        orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
        $job = orange_restore_fw_read($workRoot, $jobId);
        $type = (string) ($job['package_type'] ?? '');
        if ($type === 'full_disaster' && !$mayFull) {
            throw new RuntimeException('Operator lacks backup_restore_full permission.');
        }
        if ($type === 'country_recovery' && !$mayCountry) {
            throw new RuntimeException('Operator lacks backup_restore_country permission.');
        }
    }

    return orange_restore_prod_maint_state($workRoot, $jobId);
}

/**
 * Request production DB import (metadata only; HTTP never imports).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_production_import(
    string $backupRoot,
    string $workRoot,
    string $projectRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_prod_import_request($workRoot, $jobId, $backupRoot, $projectRoot, $admin);
}

/**
 * Read-only production import status/report.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_production_import_status(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    return orange_restore_prod_import_status($workRoot, $jobId);
}

/**
 * Request production uploads cutover (metadata only; HTTP never renames).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_uploads_cutover(
    string $backupRoot,
    string $workRoot,
    string $projectRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_uploads_cutover_request($workRoot, $jobId, $backupRoot, $projectRoot, $admin);
}

/**
 * Read-only uploads cutover status/report.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_uploads_cutover_status(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    return orange_restore_uploads_cutover_status($workRoot, $jobId);
}

/**
 * Request production rollback (metadata only; HTTP never executes rollback).
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_request_rollback(
    string $backupRoot,
    string $workRoot,
    string $projectRoot,
    array $admin,
    PDO $pdo,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    return orange_restore_prod_rollback_request($workRoot, $jobId, $backupRoot, $projectRoot, $admin);
}

/**
 * Read-only production rollback status/report.
 *
 * @return array<string, mixed>
 */
function orange_restore_admin_fw_rollback_status(
    string $workRoot,
    bool $mayFull,
    bool $mayCountry,
    string $jobId
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    if ($type === 'full_disaster' && !$mayFull) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }
    if ($type === 'country_recovery' && !$mayCountry) {
        throw new RuntimeException('Operator lacks backup_restore_country permission.');
    }

    return orange_restore_prod_rollback_status($workRoot, $jobId);
}

function orange_restore_admin_safe_message(Throwable $e): string
{
    $msg = trim($e->getMessage());
    if ($msg === '') {
        return 'تعذر تنفيذ العملية.';
    }
    $passthrough = [
        'restore_job_already_active',
        'execution_orchestration_already_active',
        'execution_plan_cancelled_reset_required',
        'execution_plan_failed_reset_required',
        'package_changed_after_dry_run',
        'dry_run_report_missing',
        'dry_run_failed',
        'dry_run_warning_not_approved_for_package_type',
        'execution_already_performed',
        'package_type_mismatch',
        'package_id_mismatch',
        'country_code_mismatch',
        'package_not_eligible',
        'schema_incompatible',
        'backend_incompatible',
        'country_production_restore_not_enabled',
        'recent_authentication_not_available',
        'recent_authentication_failed',
        'approval_nonce_used',
        'approval_nonce_expired',
        'approval_nonce_invalid',
        'approval_nonce_wrong_operator',
        'approval_nonce_wrong_session',
        'confirmation_phrase_mismatch',
        'plan_changed_after_challenge',
        'already_approved',
        'invalid_status',
        'execution_lock_not_held',
        'execution_plan_missing',
        'version_plan_incompatible',
        'version_drv_unknown',
        'version_drv_incompatible',
        'version_manifest_missing',
        'version_schema_incompatible',
        'version_backend_incompatible',
        'contract_missing',
        'contract_invalid',
        'package_changed',
        'plan_changed',
        'approval_changed',
        'version_mismatch',
        'backend_mismatch',
        'schema_mismatch',
        'execution_started_forbidden',
        'final_approval_missing',
        'final_approval_invalid',
        'pre_restore_backup_lock_active',
        'rollback_anchor_already_exists',
        'backup_engine_failed',
        'verify_failed',
        'drv_failed',
        'health_unacceptable',
        'package_unreadable',
        'retention_pin_failed',
        'cli_only',
        'backup_package_id_invalid',
        'package_fingerprint_unstable',
        'package_already_pinned_for_other_job',
        'shadow_restore_lock_active',
        'pre_restore_backup_not_ready',
        'shadow_db_create_failed',
        'sql_import_failed',
        'shadow_verify_failed',
        'dump_file_missing',
        'package_incompatible',
        'shadow_verify_lock_active',
        'shadow_restore_not_ready',
        'shadow_not_ready',
        'shadow_verification_failed',
        'shadow_files_lock_active',
        'shadow_not_verified',
        'uploads_file_missing',
        'uploads_zip_missing',
        'uploads_checksum_mismatch',
        'shadow_files_verify_failed',
        'shadow_files_failed',
        'shadow_workspace_create_failed',
        'shadow_smoke_lock_active',
        'shadow_files_not_ready',
        'shadow_context_write_blocked',
        'shadow_context_integration_blocked',
        'production_db_identity_rejected',
        'production_file_root_rejected',
        'smoke_pipeline_override_invalid',
        'missing_rollback_anchor',
        'invalid_approval',
        'invalid_execution_contract',
        'invalid_version_lock',
        'invalid_shadow_readiness',
        'invalid_smoke_report',
        'duplicate_maintenance',
        'conflicting_backup_job',
        'conflicting_restore_job',
        'maintenance_auth_stale',
        'not_approved_waiting_execution',
        'maintenance_already_active_or_releasing',
        'maintenance_activate_invalid_state',
        'maintenance_validate_invalid_state',
        'maintenance_not_active',
        'execution_already_started',
        'shadow_verification_not_ready',
        'shadow_smoke_not_ready',
        'cutover_readiness_not_ready',
        'shadow_db_equals_production',
        'shadow_db_rejected_as_production',
        'staging_db_rejected_as_production',
        'production_identity_drift',
        'db_identity_invalid',
        'production_import_not_ready',
        'import_verification_not_passed',
        'shadow_files_not_ready',
        'uploads_missing',
        'uploads_pre_merge_already_exists',
        'uploads_next_already_exists',
        'insufficient_disk',
        'shadow_checksum_report_invalid',
        'shadow_tree_checksum_drift',
        'uploads_permissions_invalid',
        'uploads_cutover_not_ready',
        'uploads_cutover_not_verified',
        'rollback_already_running',
        'rollback_anchor_not_verified',
        'missing_retention_pin',
        'retention_pin_missing',
        'retention_pin_job_mismatch',
        'retention_pin_id_mismatch',
        'invalid_rollback_package_id',
        'rollback_anchor_must_be_full',
        'uploads_pre_merge_missing',
    ];
    if (in_array($msg, $passthrough, true)) {
        return $msg;
    }
    if (str_contains($msg, 'permission') || str_contains($msg, 'Invalid') || str_contains($msg, 'not found')
        || str_contains($msg, 'allowlisted') || str_contains($msg, 'Method not allowed')
        || str_contains($msg, 'CSRF') || str_contains($msg, 'cannot be cancelled')
        || str_contains($msg, 'not eligible') || str_contains($msg, 'dry_completed')
        || str_contains($msg, 'Execution plan')) {
        return $msg;
    }

    return 'تعذر تنفيذ العملية.';
}
