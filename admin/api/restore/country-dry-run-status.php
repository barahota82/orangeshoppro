<?php

declare(strict_types=1);

/**
 * Phase C8 — Country Dry Run status/report (GET only).
 * No execution. No production/shadow writes.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/restore/restore_country_dry_run.php';

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
    $payload = orange_country_dry_run_status((string) $ctx['work_root'], $jobId);
    $report = is_array($payload['report'] ?? null) ? $payload['report'] : null;

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'execution_performed' => false,
        'production_db_writes' => 0,
        'production_file_writes' => 0,
        'shadow_db_writes' => 0,
        'country_production_restore_enabled' => false,
        'run_id' => $payload['run_id'] ?? $jobId,
        'status' => $payload['status'] ?? '',
        'dry_run_available' => (bool) ($payload['dry_run_available'] ?? false),
        'meta' => $payload['meta'] ?? null,
        'report' => $report,
        'summary' => $report === null ? null : [
            'overall_result' => $report['overall_result'] ?? '',
            'tables_affected_count' => $report['tables_affected_count'] ?? 0,
            'rows_to_insert' => $report['rows_to_insert'] ?? 0,
            'rows_to_delete' => $report['rows_to_delete'] ?? 0,
            'rows_to_replace' => $report['rows_to_replace'] ?? 0,
            'uploads_to_add' => $report['uploads_to_add'] ?? 0,
            'uploads_to_replace' => $report['uploads_to_replace'] ?? 0,
            'survivor_country_impact' => $report['survivor_country_impact'] ?? 0,
            'global_impact' => $report['global_impact'] ?? 0,
            'estimated_duration' => $report['estimated_duration'] ?? '',
            'blocking_reason_codes' => $report['blocking_reason_codes'] ?? [],
            'warnings' => $report['warnings'] ?? [],
        ],
        'warning' => 'Country Dry Run status only — simulation; production and shadow were not modified.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    if ($code === 'country_shadow_run_not_found' || $code === 'invalid_country_shadow_run_id') {
        json_response(['success' => false, 'code' => $code, 'message' => $code], 404);
    }
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
