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
    $status = orange_restore_admin_fw_cutover_authorization_status(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only' => true,
        'http_never_imports' => true,
        'execution_started' => false,
        'status' => $status,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'cutover_authorization_status_failed',
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], 422);
}
