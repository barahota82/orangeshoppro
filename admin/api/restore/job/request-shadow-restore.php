<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_center_orchestrator.php';

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
    $result = orange_restore_admin_fw_request_shadow_restore(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin')) ?: 'admin';
    $result = orange_restore_center_attach_verified_schedule(
        $projectRoot,
        (string) $ctx['work_root'],
        $jobId,
        'shadow_db',
        $username,
        $result
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'production_touched' => false,
        'scheduled' => !empty($result['scheduled']),
        'detached' => !empty($result['detached']),
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'idempotent' => (bool) ($result['idempotent'] ?? false),
        'job' => $result['job'] ?? null,
        'meta' => $result['meta'] ?? null,
        'pid' => (int) ($result['pid'] ?? 0),
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'تم بدء استعادة قاعدة الظل على الخادم.'),
        'warning' => 'Shadow restore only — production database will not be modified.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'shadow_restore_request_failed',
        'message' => $code !== '' && str_starts_with($code, 'restore_center_')
            ? orange_restore_center_operator_reason_ar($code)
            : orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'production_touched' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
