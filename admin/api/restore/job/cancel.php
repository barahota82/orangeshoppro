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

    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);
    $jobId = trim((string) ($data['job_id'] ?? $data['id'] ?? ''));
    if ($jobId === '') {
        json_response(['success' => false, 'code' => 'invalid_job_id', 'message' => 'Invalid restore job id.'], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $job = orange_restore_admin_fw_cancel_job($ctx['work_root'], $admin, $mayFull, $mayCountry, $jobId);

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'message' => 'Restore job cancelled.',
        'job' => $job,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
