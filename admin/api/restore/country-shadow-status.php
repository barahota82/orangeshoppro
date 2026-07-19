<?php

declare(strict_types=1);

/**
 * Phase C6/C7 — Country Shadow Restore + Verification status (GET only).
 * No execution, no production restore.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/restore/restore_country_shadow.php';
require_once __DIR__ . '/../../../includes/backup/restore/restore_country_shadow_verify.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    if (!orange_restore_admin_may_view_country($admin, $pdo)) {
        json_response(['success' => false, 'code' => 'forbidden', 'message' => 'Country restore view not permitted.'], 403);
    }

    $runId = trim((string) ($_GET['run_id'] ?? $_GET['job_id'] ?? $_GET['id'] ?? ''));
    if ($runId === '') {
        json_response(['success' => false, 'code' => 'invalid_run_id', 'message' => 'run_id required.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $payload = orange_country_shadow_status((string) $ctx['work_root'], $runId);
    $report = is_array($payload['report'] ?? null) ? $payload['report'] : null;
    $verify = orange_country_shadow_verify_status((string) $ctx['work_root'], $runId);
    $verifyReport = is_array($verify['report'] ?? null) ? $verify['report'] : null;

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'execution_performed' => false,
        'production_db_writes' => 0,
        'production_file_writes' => 0,
        'production_touched' => false,
        'country_production_restore_enabled' => false,
        'run_id' => $payload['run_id'] ?? $runId,
        'status' => $payload['status'] ?? '',
        'meta' => $payload['meta'] ?? null,
        'report' => $report,
        'verify_available' => (bool) ($verify['verify_available'] ?? false),
        'verify_report' => $verifyReport,
        'summary' => $report === null ? null : [
            'overall_result' => $report['overall_result'] ?? '',
            'shadow_db_name' => $report['shadow_db_name'] ?? '',
            'tables_mutated' => $report['tables_mutated'] ?? 0,
            'rows_imported' => $report['rows_imported'] ?? 0,
            'blocking_reason_codes' => $report['blocking_reason_codes'] ?? [],
            'warnings' => $report['warnings'] ?? [],
        ],
        'verify_summary' => $verifyReport === null ? null : [
            'overall_result' => $verifyReport['overall_result'] ?? '',
            'readiness_score' => $verifyReport['readiness_score'] ?? 0,
            'target_country_integrity' => $verifyReport['target_country_integrity'] ?? '',
            'survivor_country_integrity' => $verifyReport['survivor_country_integrity'] ?? '',
            'global_state_integrity' => $verifyReport['global_state_integrity'] ?? '',
            'accounting_integrity' => $verifyReport['accounting_integrity'] ?? '',
            'stock_fifo_integrity' => $verifyReport['stock_fifo_integrity'] ?? '',
            'blocking_reason_codes' => $verifyReport['blocking_reason_codes'] ?? [],
            'warnings' => $verifyReport['warnings'] ?? [],
        ],
        'warning' => 'Country Shadow status only — production was not modified. No Import/Restore/Execute controls.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    if ($code === 'country_shadow_run_not_found' || $code === 'invalid_country_shadow_run_id') {
        json_response(['success' => false, 'code' => $code, 'message' => $code], 404);
    }
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
