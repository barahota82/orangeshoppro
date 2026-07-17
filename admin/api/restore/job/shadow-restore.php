<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $jobId = trim((string) ($_GET['id'] ?? $_GET['job_id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'Invalid restore job id.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);
    $payload = orange_restore_admin_fw_shadow_restore(
        $ctx['work_root'],
        $mayFull,
        $mayCountry,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'production_touched' => false,
        'job' => $payload['job'] ?? null,
        'meta' => $payload['meta'] ?? null,
        'report' => $payload['report'] ?? null,
        'status_label_ar' => (string) ($payload['status_label_ar'] ?? ''),
        'warning' => 'Shadow restore only — production database was not modified.',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
