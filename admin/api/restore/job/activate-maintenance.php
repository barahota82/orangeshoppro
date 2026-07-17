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

    $password = (string) ($data['password'] ?? '');
    $nonce = trim((string) ($data['nonce'] ?? ''));
    if ($nonce === '') {
        json_response([
            'success' => false,
            'code' => 'maintenance_auth_stale',
            'message' => 'maintenance_auth_stale',
            'execution_started' => false,
            'restore_started' => false,
            'csrf_token' => orange_backup_admin_csrf_token(),
        ], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $result = orange_restore_admin_fw_activate_maintenance(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId,
        $password,
        $nonce
    );

    json_response([
        'success' => true,
        'framework_activation_only' => true,
        'execution_started' => false,
        'restore_started' => false,
        'production_touched' => false,
        'production_cutover_allowed' => false,
        'job' => $result['job'] ?? null,
        'maintenance' => $result['maintenance'] ?? null,
        'record' => $result['record'] ?? null,
        'gates' => $result['gates'] ?? null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'Maintenance Active.'),
        'warning' => 'Production restore has NOT started.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'maintenance_activate_failed',
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'restore_started' => false,
        'production_cutover_allowed' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
