<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_admin.php';
require_once __DIR__ . '/restore/restore_paths.php';
require_once __DIR__ . '/restore/restore_job.php';
require_once __DIR__ . '/restore/restore_lock.php';
require_once __DIR__ . '/restore/restore_merge_maintenance.php';
require_once __DIR__ . '/restore/restore_validation_adapter.php';

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

    $score = null;
    if (array_key_exists('recovery_score', $verification) && $verification['recovery_score'] !== null && is_numeric($verification['recovery_score'])) {
        $score = (int) $verification['recovery_score'];
    } elseif (array_key_exists('recovery_score', $package) && $package['recovery_score'] !== null && is_numeric($package['recovery_score'])) {
        $score = (int) $package['recovery_score'];
    }

    return ['drv_result' => $drvResult, 'drv_score' => $score];
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
        if ($verification !== null && ($drvFields['drv_score'] === null || $drvFields['drv_score'] < 70)) {
            $reasons[] = 'drv_score_below_threshold';
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
    if ($row['recovery_score'] === null) {
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

function orange_restore_admin_safe_message(Throwable $e): string
{
    $msg = trim($e->getMessage());
    if ($msg === '') {
        return 'تعذر تنفيذ العملية.';
    }
    if (str_contains($msg, 'permission') || str_contains($msg, 'Invalid') || str_contains($msg, 'not found')
        || str_contains($msg, 'allowlisted') || str_contains($msg, 'Method not allowed')) {
        return $msg;
    }

    return 'تعذر تنفيذ العملية.';
}
