<?php

declare(strict_types=1);

/**
 * Phase C7 — Country Shadow Verification status/report (GET only).
 * No execution.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/restore/restore_country_shadow_verify.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    if (!orange_restore_admin_may_view_country($admin, $pdo)) {
        json_response(['success' => false, 'code' => 'forbidden', 'message' => 'Country restore view not permitted.'], 403);
    }

    $jobId = trim((string) ($_GET['job_id'] ?? $_GET['run_id'] ?? $_GET['id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'job_id required.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $payload = orange_country_shadow_verify_status((string) $ctx['work_root'], $jobId);
    $report = is_array($payload['report'] ?? null) ? $payload['report'] : null;

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'execution_performed' => false,
        'production_db_writes' => 0,
        'production_file_writes' => 0,
        'country_production_restore_enabled' => false,
        'run_id' => $payload['run_id'] ?? $jobId,
        'status' => $payload['status'] ?? '',
        'verify_available' => (bool) ($payload['verify_available'] ?? false),
        'meta' => $payload['meta'] ?? null,
        'report' => $report,
        'summary' => $report === null ? null : [
            'overall_result' => $report['overall_result'] ?? '',
            'readiness_score' => $report['readiness_score'] ?? 0,
            'target_country_integrity' => $report['target_country_integrity'] ?? '',
            'survivor_country_integrity' => $report['survivor_country_integrity'] ?? '',
            'global_state_integrity' => $report['global_state_integrity'] ?? '',
            'accounting_integrity' => $report['accounting_integrity'] ?? '',
            'stock_fifo_integrity' => $report['stock_fifo_integrity'] ?? '',
            'blocking_reason_codes' => $report['blocking_reason_codes'] ?? [],
            'warnings' => $report['warnings'] ?? [],
        ],
        'warning' => 'Country Shadow Verification status only — production was not modified.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    if ($code === 'country_shadow_run_not_found' || $code === 'invalid_country_shadow_run_id') {
        json_response(['success' => false, 'code' => $code, 'message' => $code], 404);
    }
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
