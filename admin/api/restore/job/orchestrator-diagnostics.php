<?php

declare(strict_types=1);

/**
 * Restore Center orchestration diagnostics (safe operational view).
 * No absolute paths, secrets, or server internals beyond redacted log tails.
 * Always returns structured JSON (never generic-only Owner message).
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_center_orchestrator.php';

restore_admin_api_require_get();

/** Static auto-deploy identity marker (no Git/env/paths; additive response only). */
if (!defined('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL')) {
    define('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL', 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1');
}

/** Visible shared-helper probe marker (instrumentation only; no execution change). */
if (!defined('ORANGE_STEP7_SHARED_HELPER_PROBE_A2')) {
    define('ORANGE_STEP7_SHARED_HELPER_PROBE_A2', 'STEP7_SHARED_HELPER_PROBE_A2');
}

/**
 * Attach deploy sentinel to an already-built diagnostic payload (no recalculation).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function orange_restore_diagnostic_api_with_deploy_sentinel(array $payload): array
{
    $payload['deploy_sentinel'] = ORANGE_STEP7_DIAG_DEPLOY_SENTINEL;

    return $payload;
}

/**
 * Emit diagnostic JSON with deploy sentinel header + field (exclusive to this route).
 *
 * @param array<string, mixed> $payload
 */
function orange_restore_diagnostic_api_emit(array $payload, int $http = 200): void
{
    if (!headers_sent()) {
        header('X-Orange-Step7-Diagnostic-Sentinel: ' . ORANGE_STEP7_DIAG_DEPLOY_SENTINEL);
    }
    json_response(orange_restore_diagnostic_api_with_deploy_sentinel($payload), $http);
}

/**
 * Stage-specific NOT_READY reason for probe failure reports (safe codes only).
 */
function orange_restore_diagnostic_probe_stage_reason(string $stage): string
{
    return match ($stage) {
        'diagnostics_entry' => 'step7_diagnostic_entry_failed',
        'job_read' => 'step7_diagnostic_job_read_failed',
        'restore_record_read' => 'step7_diagnostic_restore_record_read_failed',
        'sql_compatibility_certificate_build' => 'step7_diagnostic_sql_certificate_build_failed',
        'private_engine_trace_build' => 'step7_diagnostic_private_engine_trace_build_failed',
        'result_aggregation' => 'step7_diagnostic_result_aggregation_failed',
        'diagnostics_complete' => 'step7_diagnostic_complete_failed',
        default => 'step7_diagnostic_stage_failure',
    };
}

/**
 * @return array<string, mixed>
 */
function orange_restore_diagnostic_api_structured_failure(
    string $safeCode,
    string $safeMessageAr,
    string $failureLayer,
    string $jobId = '',
    int $http = 422
): void {
    if (!defined('ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT')
        && is_file(dirname(__DIR__, 4) . '/includes/backup/restore/restore_sql_compat_engine.php')) {
        require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_sql_compat_engine.php';
    }
    orange_restore_diagnostic_api_emit([
        'success' => false,
        'read_only' => true,
        'job_id' => $jobId,
        'stage' => 'shadow_restore',
        'failure_layer' => $failureLayer,
        'safe_code' => $safeCode,
        'code' => $safeCode,
        'safe_message_ar' => $safeMessageAr,
        'message' => $safeMessageAr,
        'retryable' => false,
        'final_readiness' => 'NOT_READY',
        'exact_not_ready_reason' => $safeCode,
        'package_certificate_status' => 'unavailable',
        'private_engine_trace_status' => 'unavailable',
        'step7_action_enabled' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $http);
}

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $jobId = trim((string) ($_GET['id'] ?? $_GET['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        orange_restore_diagnostic_api_structured_failure(
            'STEP7_DIAGNOSTIC_JOB_NOT_FOUND',
            'معرّف مهمة الاسترداد غير صالح.',
            'job_resolution',
            '',
            422
        );
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    if ($workRoot === '') {
        orange_restore_diagnostic_api_structured_failure(
            'STEP7_DIAGNOSTIC_UNKNOWN_SAFE_FAILURE',
            'تعذر تهيئة مسار عمل الاسترداد بأمان.',
            'work_root',
            $jobId,
            422
        );
    }

    try {
        orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
        $job = orange_restore_fw_read($workRoot, $jobId);
    } catch (Throwable) {
        orange_restore_diagnostic_api_structured_failure(
            'STEP7_DIAGNOSTIC_JOB_NOT_FOUND',
            'تعذر العثور على مهمة الاسترداد أو غير مسموح بعرضها.',
            'job_resolution',
            $jobId,
            404
        );
    }

    $type = (string) ($job['package_type'] ?? '');
    try {
        orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
        if ($type !== 'full_disaster') {
            throw new RuntimeException('country_production_restore_not_enabled');
        }
        if (!orange_restore_admin_may_view_full($admin, $pdo)) {
            throw new RuntimeException('Operator lacks backup_restore_full permission.');
        }
    } catch (Throwable $e) {
        $msg = trim($e->getMessage());
        $code = ($msg === 'country_production_restore_not_enabled'
            || str_contains($msg, 'permission')
            || str_contains($msg, 'lacks'))
            ? 'STEP7_DIAGNOSTIC_AUTHORIZATION_FAILED'
            : 'STEP7_DIAGNOSTIC_AUTHORIZATION_FAILED';
        orange_restore_diagnostic_api_structured_failure(
            $code,
            'لا تتوفر صلاحية عرض تشخيص هذه المهمة.',
            'authorization',
            $jobId,
            403
        );
    }

    // Request-local probe collector (memory only; never persisted / global / session).
    $probeTraceId = bin2hex(random_bytes(8));
    $probeState = [
        'last_stage' => '',
        'checkpoints' => [],
        'exception_class' => '',
        'trace_id' => $probeTraceId,
    ];
    $diagnosticProbe = static function (array $event) use (&$probeState): void {
        $ev = (string) ($event['event'] ?? '');
        if ($ev === 'checkpoint') {
            $stage = trim((string) ($event['stage'] ?? ''));
            if ($stage === '') {
                return;
            }
            $probeState['last_stage'] = $stage;
            $probeState['checkpoints'][] = [
                'stage' => $stage,
                'elapsed_ms' => (int) ($event['elapsed_ms'] ?? 0),
            ];

            return;
        }
        if ($ev === 'exception') {
            $cls = trim((string) ($event['class'] ?? ''));
            if ($cls !== '' && preg_match('/^[A-Za-z0-9_\\\\]+$/', $cls) === 1) {
                $probeState['exception_class'] = $cls;
            }
        }
    };

    try {
        // DIAGNOSTIC_HELPER_CALL_COUNT=1 — single helper invocation with probe.
        $diagnostics = orange_restore_center_diagnostics($workRoot, $jobId, $diagnosticProbe);
    } catch (Throwable $e) {
        $lastStage = trim((string) ($probeState['last_stage'] ?? ''));
        if ($lastStage === '') {
            $lastStage = 'diagnostics_entry';
        }
        $safeClass = $probeState['exception_class'] !== ''
            ? $probeState['exception_class']
            : $e::class;
        if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $safeClass) !== 1) {
            $safeClass = 'Throwable';
        }
        $stageReason = orange_restore_diagnostic_probe_stage_reason($lastStage);
        $safeMsg = '[probe: ' . ORANGE_STEP7_SHARED_HELPER_PROBE_A2 . ']'
            . ' تعذر إكمال تشخيص التشغيل بأمان. الخطوة غير جاهزة.'
            . ' طبقة=' . $lastStage
            . ' استثناء=' . $safeClass
            . ' أثر=' . $probeTraceId
            . ' سبب=' . $stageReason;
        // Map into existing visible fields; remain NOT_READY; do not fix the exception.
        if (!defined('ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT')
            && is_file(dirname(__DIR__, 4) . '/includes/backup/restore/restore_sql_compat_engine.php')) {
            require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_sql_compat_engine.php';
        }
        orange_restore_diagnostic_api_emit([
            'success' => false,
            'read_only' => true,
            'job_id' => $jobId,
            'stage' => 'shadow_restore',
            'failure_layer' => $lastStage,
            'safe_code' => 'step7_diagnostic_stage_failure',
            'code' => 'step7_diagnostic_stage_failure',
            'safe_message_ar' => $safeMsg,
            'message' => $safeMsg,
            'retryable' => false,
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => $stageReason,
            'package_certificate_status' => 'unavailable',
            'private_engine_trace_status' => 'unavailable',
            'step7_action_enabled' => false,
            'csrf_token' => orange_backup_admin_csrf_token(),
        ], 422);
    }

    $readiness = is_array($diagnostics['step7_shadow_target_readiness'] ?? null)
        ? $diagnostics['step7_shadow_target_readiness']
        : [];
    $pre = is_array($readiness['retry_preflight'] ?? null) ? $readiness['retry_preflight'] : [];
    $cert = is_array($pre['sql_package_compatibility'] ?? null) ? $pre['sql_package_compatibility'] : [];
    $trace = is_array($diagnostics['private_engine_live_trace'] ?? null)
        ? $diagnostics['private_engine_live_trace']
        : [];
    $certStatus = $cert === []
        ? 'absent'
        : (!empty($cert['package_scan_complete'])
            ? (!empty($cert['compatible']) ? 'compatible' : 'incompatible_or_not_ready')
            : ((string) ($cert['exact_not_ready_reason'] ?? '') === 'STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT'
                ? 'resource_limit'
                : 'incomplete'));
    $traceStatus = $trace === []
        ? 'absent'
        : (string) ($trace['classification'] ?? 'present');

    $payload = [
        'success' => true,
        'read_only' => true,
        'job_id' => $jobId,
        'stage' => 'shadow_restore',
        'failure_layer' => '',
        'safe_code' => 'ok',
        'safe_message_ar' => '',
        'retryable' => false,
        'final_readiness' => (string) ($readiness['final_readiness']
            ?? $pre['final_readiness']
            ?? ($diagnostics['ready_token'] ?? 'NOT_READY')),
        'exact_not_ready_reason' => (string) ($readiness['exact_not_ready_reason']
            ?? $pre['exact_not_ready_reason']
            ?? ''),
        'package_certificate_status' => $certStatus,
        'private_engine_trace_status' => $traceStatus,
        'step7_action_enabled' => !empty($diagnostics['step7_action_enabled'])
            || !empty($readiness['step7_action_enabled']),
        'package_sql_certificate' => $cert,
        'diagnostics' => $diagnostics,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ];

    // Fail-closed: unreadable certificate ⇒ never enable Step 7 in this response.
    if ($certStatus === 'absent' || $certStatus === 'incomplete' || $certStatus === 'resource_limit') {
        $payload['step7_action_enabled'] = false;
        if (($payload['final_readiness'] ?? '') !== 'NOT_READY'
            && !str_starts_with((string) ($payload['final_readiness'] ?? ''), 'READY_')) {
            $payload['final_readiness'] = 'NOT_READY';
        }
        if ($payload['exact_not_ready_reason'] === '' || $payload['exact_not_ready_reason'] === 'ok') {
            $payload['exact_not_ready_reason'] = $certStatus === 'resource_limit'
                ? 'STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT'
                : 'STEP7_DIAGNOSTIC_SQL_SCAN_FAILED';
        }
        if (is_array($payload['diagnostics'])) {
            $payload['diagnostics']['step7_action_enabled'] = false;
        }
    }

    $jsonFlags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $jsonFlags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $encoded = json_encode($payload, $jsonFlags);
    if ($encoded === false) {
        orange_restore_diagnostic_api_structured_failure(
            'STEP7_DIAGNOSTIC_RESPONSE_SERIALIZATION_FAILED',
            'تعذر تجهيز تقرير التشخيص للعرض بأمان.',
            'json_serialization',
            $jobId,
            500
        );
    }

    orange_restore_diagnostic_api_emit($payload);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $safe = 'STEP7_DIAGNOSTIC_UNKNOWN_SAFE_FAILURE';
    $http = 422;
    if ($code === 'country_production_restore_not_enabled'
        || str_contains($code, 'permission')
        || str_contains($code, 'lacks')) {
        $safe = 'STEP7_DIAGNOSTIC_AUTHORIZATION_FAILED';
        $http = 403;
    } elseif (str_starts_with($code, 'STEP7_DIAGNOSTIC_')) {
        $safe = $code;
    }
    orange_restore_diagnostic_api_structured_failure(
        $safe,
        'تعذر فتح تشخيص التشغيل بأمان.',
        'api_catch',
        '',
        $http
    );
}
