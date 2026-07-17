<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $jobId = trim((string) ($_GET['job_id'] ?? $_GET['id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'Invalid restore job id.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);

    $result = orange_restore_admin_fw_uploads_cutover_status(
        $ctx['work_root'],
        $mayFull,
        $mayCountry,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only' => true,
        'http_never_cutover' => true,
        'execution_started' => false,
        'database_import_performed' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
        'restore_completed' => false,
        'production_cutover_allowed' => false,
        'job' => $result['job'] ?? null,
        'meta' => $result['meta'] ?? null,
        'report' => $result['report'] ?? null,
        'checkpoint_history' => $result['checkpoint_history'] ?? [],
        'highest_checkpoint' => $result['highest_checkpoint'] ?? '',
        'status_label' => $result['status_label'] ?? '',
        'warning' => 'Maintenance remains active. Restore is NOT completed. Rollback was NOT executed.',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
