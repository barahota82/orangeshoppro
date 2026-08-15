<?php

declare(strict_types=1);

/**
 * Restore Center orchestration diagnostics (safe operational view).
 * No absolute paths, secrets, or server internals beyond redacted log tails.
 * Always returns structured JSON (never generic-only Owner message).
 */

// C2 — endpoint-local fatal/timeout origin capture (register BEFORE config / global boundary).
// Marker: STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2 — no root-cause fix; auth-gated; inert until authorized.
$GLOBALS['orange_step7_diag_c2_t0'] = microtime(true);
$GLOBALS['orange_step7_diag_c2_authorized'] = false;
$GLOBALS['orange_step7_diag_c2_completed'] = false;
$GLOBALS['orange_step7_diag_c2_job_id'] = '';

if (!function_exists('orange_step7_diag_c2_shutdown')) {
    /**
     * Runs first among shutdown callbacks registered from this request (before config.php global).
     * Intercepts only post-auth unfinished fatals for this exact script; else inert.
     */
    function orange_step7_diag_c2_shutdown(): void
    {
        $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? '')));
        if ($script === '' || $script === '.' || $script === '..') {
            $script = basename(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        }
        if ($script !== 'orchestrator-diagnostics.php') {
            return;
        }
        if (empty($GLOBALS['orange_step7_diag_c2_authorized'])
            || !empty($GLOBALS['orange_step7_diag_c2_completed'])) {
            return;
        }

        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        $type = (int) ($err['type'] ?? 0);
        if (!in_array($type, $fatalTypes, true)) {
            return;
        }

        $rawMsg = (string) ($err['message'] ?? '');
        $category = 'php_fatal';
        if (stripos($rawMsg, 'Maximum execution time') !== false
            || stripos($rawMsg, 'max_execution_time') !== false) {
            $category = 'php_timeout';
        } elseif (stripos($rawMsg, 'Allowed memory size') !== false
            || stripos($rawMsg, 'Out of memory') !== false
            || stripos($rawMsg, 'memory exhausted') !== false) {
            $category = 'php_memory';
        }

        $typeMap = [
            E_ERROR => 'E_ERROR',
            E_PARSE => 'E_PARSE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_USER_ERROR => 'E_USER_ERROR',
        ];
        $typeName = $typeMap[$type] ?? 'E_ERROR';

        $originFile = basename(str_replace('\\', '/', (string) ($err['file'] ?? '')));
        if ($originFile === '' || $originFile === '.' || $originFile === '..') {
            $originFile = 'unknown';
        }
        $safeOriginFile = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $originFile);
        if ($safeOriginFile === '') {
            $safeOriginFile = 'unknown';
        }
        $originLine = (int) ($err['line'] ?? 0);
        if ($originLine < 0) {
            $originLine = 0;
        }

        $t0 = (float) ($GLOBALS['orange_step7_diag_c2_t0'] ?? microtime(true));
        $elapsedMs = (int) max(0, (int) round((microtime(true) - $t0) * 1000));

        $probe = 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2';
        try {
            $traceId = bin2hex(random_bytes(8));
        } catch (Throwable) {
            $traceId = substr(str_replace('.', '', uniqid('', true)), 0, 16);
        }

        $jobId = (string) ($GLOBALS['orange_step7_diag_c2_job_id'] ?? '');
        $notReadyReason = 'endpoint_fatal_origin_' . $category . '_' . $safeOriginFile . '_L' . $originLine;
        $safeMsg = 'تعذر التشخيص بسبب إيقاف فادح داخلي آمن'
            . ' [probe: ' . $probe . ']'
            . ' [trace: ' . $traceId . ']'
            . ' [category: ' . $category . ']'
            . ' [type: ' . $typeName . ']'
            . ' [origin: ' . $safeOriginFile . ':' . $originLine . ']'
            . ' [elapsed_ms: ' . $elapsedMs . ']';

        $csrf = '';
        if (function_exists('orange_backup_admin_csrf_token')) {
            try {
                $csrf = (string) orange_backup_admin_csrf_token();
            } catch (Throwable) {
                $csrf = '';
            }
        }

        $sentinel = 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1';
        if (defined('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL')) {
            $sentinel = (string) ORANGE_STEP7_DIAG_DEPLOY_SENTINEL;
        }

        $payload = [
            'success' => false,
            'read_only' => true,
            'job_id' => $jobId,
            'stage' => 'shadow_restore',
            'failure_layer' => 'endpoint_fatal_origin',
            'safe_code' => 'step7_diagnostic_endpoint_fatal_origin',
            'code' => 'step7_diagnostic_endpoint_fatal_origin',
            'safe_message_ar' => $safeMsg,
            'message' => $safeMsg,
            'retryable' => false,
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => $notReadyReason,
            'package_certificate_status' => 'unavailable',
            'private_engine_trace_status' => 'unavailable',
            'step7_action_enabled' => false,
            'diagnostic_probe' => $probe,
            'diagnostic_trace_id' => $traceId,
            'diagnostic_shutdown_category' => $category,
            'diagnostic_php_error_type' => $typeName,
            'diagnostic_origin_file' => $safeOriginFile,
            'diagnostic_origin_line' => $originLine,
            'diagnostic_elapsed_ms' => $elapsedMs,
            'csrf_token' => $csrf,
            'deploy_sentinel' => $sentinel,
        ];

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!defined('ORANGE_ERROR_BOUNDARY_CLIENT_SENT')) {
            define('ORANGE_ERROR_BOUNDARY_CLIENT_SENT', true);
        }
        if (!defined('ORANGE_JSON_RESPONSE_EMITTED')) {
            define('ORANGE_JSON_RESPONSE_EMITTED', true);
        }
        if (!headers_sent()) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Orange-Step7-Diagnostic-Sentinel: ' . $sentinel);
        }
        $flags = JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }
        echo json_encode($payload, $flags);
        // Suppress later global / restore JSON shutdown handlers.
        exit;
    }
}

register_shutdown_function('orange_step7_diag_c2_shutdown');
if (ob_get_level() === 0) {
    ob_start();
}

require_once __DIR__ . '/../_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_center_orchestrator.php';

restore_admin_api_require_get();

/** Static auto-deploy identity marker (no Git/env/paths; additive response only). */
if (!defined('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL')) {
    define('ORANGE_STEP7_DIAG_DEPLOY_SENTINEL', 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1');
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
    // Intentional response complete — keep C2 shutdown inert.
    $GLOBALS['orange_step7_diag_c2_completed'] = true;
    if (!headers_sent()) {
        header('X-Orange-Step7-Diagnostic-Sentinel: ' . ORANGE_STEP7_DIAG_DEPLOY_SENTINEL);
    }
    json_response(orange_restore_diagnostic_api_with_deploy_sentinel($payload), $http);
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

    // C2: auth + restore permission + job-access + request/job ID validation succeeded.
    $GLOBALS['orange_step7_diag_c2_authorized'] = true;
    $GLOBALS['orange_step7_diag_c2_job_id'] = $jobId;

    // Gate B: endpoint-local exception origin only (no shared helper / no instrumentation callback).
    // Single genuine diagnostic construction — helper invoked exactly once.
    try {
        $diagnostics = orange_restore_center_diagnostics($workRoot, $jobId);
    } catch (Throwable $e) {
        $probe = 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1';
        $traceId = bin2hex(random_bytes(8));
        $originFile = basename(str_replace('\\', '/', (string) $e->getFile()));
        if ($originFile === '' || $originFile === '.' || $originFile === '..') {
            $originFile = 'unknown';
        }
        $originLine = (int) $e->getLine();
        if ($originLine < 0) {
            $originLine = 0;
        }
        $classRaw = $e::class;
        $classBase = str_contains($classRaw, '\\')
            ? substr($classRaw, (int) strrpos($classRaw, '\\') + 1)
            : $classRaw;
        $classBase = (string) preg_replace('/[^A-Za-z0-9_]/', '', $classBase);
        if ($classBase === '') {
            $classBase = 'Throwable';
        }
        $safeOriginFile = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $originFile);
        if ($safeOriginFile === '') {
            $safeOriginFile = 'unknown';
        }
        $notReadyReason = 'exception_origin_' . $classBase . '_' . $safeOriginFile . '_L' . $originLine;
        $safeMsg = 'تعذر التشخيص بسبب استثناء داخلي آمن'
            . ' [probe: ' . $probe . ']'
            . ' [trace: ' . $traceId . ']'
            . ' [origin: ' . $safeOriginFile . ':' . $originLine . ']'
            . ' [class: ' . $classBase . ']';
        orange_restore_diagnostic_api_emit([
            'success' => false,
            'read_only' => true,
            'job_id' => $jobId,
            'stage' => 'shadow_restore',
            'failure_layer' => 'exception_origin',
            'safe_code' => 'step7_diagnostic_exception_origin',
            'code' => 'step7_diagnostic_exception_origin',
            'safe_message_ar' => $safeMsg,
            'message' => $safeMsg,
            'retryable' => false,
            'final_readiness' => 'NOT_READY',
            'exact_not_ready_reason' => $notReadyReason,
            'package_certificate_status' => 'unavailable',
            'private_engine_trace_status' => 'unavailable',
            'step7_action_enabled' => false,
            'diagnostic_probe' => $probe,
            'diagnostic_trace_id' => $traceId,
            'diagnostic_exception_class' => $classBase,
            'diagnostic_origin_file' => $safeOriginFile,
            'diagnostic_origin_line' => $originLine,
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
