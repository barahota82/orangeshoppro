<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_post();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $data = restore_admin_api_json_body();
    restore_admin_api_require_csrf($data);

    $jobId = trim((string) ($data['job_id'] ?? $data['id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'Invalid restore job id.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $result = orange_restore_admin_fw_prepare_execution(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'requires_final_approval' => true,
        'message' => 'Execution plan prepared. Awaiting final approval. No restore has been executed.',
        'job' => $result['job'],
        'plan' => $result['plan'],
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $raw = trim($e->getMessage());
    $code = 'prepare_execution_failed';
    $status = 422;
    if ($raw === 'execution_orchestration_already_active') {
        $code = 'execution_orchestration_already_active';
        $status = 409;
    } elseif (in_array($raw, [
        'package_changed_after_dry_run',
        'execution_plan_cancelled_reset_required',
        'execution_plan_failed_reset_required',
        'dry_run_report_missing',
        'dry_run_failed',
        'dry_run_warning_not_approved_for_package_type',
        'execution_already_performed',
        'package_type_mismatch',
        'country_code_mismatch',
        'schema_incompatible',
        'backend_incompatible',
    ], true)) {
        $code = $raw;
    }
    json_response([
        'success' => false,
        'code' => $code,
        'message' => orange_restore_admin_safe_message($e),
    ], $status);
}
