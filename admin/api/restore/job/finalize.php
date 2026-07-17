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

    $result = orange_restore_admin_fw_finalize_status(
        $ctx['work_root'],
        $mayFull,
        $mayCountry,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only' => true,
        'http_never_finalize' => true,
        'execution_started' => false,
        'maintenance_released' => (bool) ($result['maintenance_released'] ?? false),
        'restore_completed' => (bool) ($result['restore_completed'] ?? false),
        'rollback_completed' => (bool) ($result['rollback_completed'] ?? false),
        'execution_finished' => (bool) ($result['execution_finished'] ?? false),
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'job' => $result['job'] ?? null,
        'meta' => $result['meta'] ?? null,
        'report' => $result['report'] ?? null,
        'artifacts' => $result['artifacts'] ?? null,
        'status_label' => $result['status_label'] ?? '',
        'warning' => (string) ($result['warning'] ?? ''),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
