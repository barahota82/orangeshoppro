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
    $result = orange_restore_admin_fw_request_finalize(
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
        'finalize',
        $username,
        $result
    );

    json_response([
        'success' => true,
        'metadata_only' => true,
        'http_never_finalize' => true,
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'scheduled' => !empty($result['scheduled']),
        'detached' => !empty($result['detached']),
        'idempotent' => (bool) ($result['idempotent'] ?? false),
        'path' => (string) ($result['path'] ?? ''),
        'execution_started' => false,
        'database_import_performed' => false,
        'uploads_rename_performed' => false,
        'rollback_executed' => false,
        'shadow_executed' => false,
        'maintenance_released' => (bool) ($result['maintenance_released'] ?? false),
        'restore_completed' => (bool) ($result['restore_completed'] ?? false),
        'rollback_completed' => (bool) ($result['rollback_completed'] ?? false),
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'job' => $result['job'] ?? null,
        'meta' => $result['meta'] ?? null,
        'gates' => $result['gates'] ?? null,
        'pid' => (int) ($result['pid'] ?? 0),
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'تم بدء إنهاء الاسترداد على الخادم.'),
        'warning' => (string) ($result['warning'] ?? 'سيُفرج عن الصيانة عند اكتمال العامل الداخلي. تُحفظ الآثار الجنائية.'),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'finalize_request_failed',
        'message' => $code !== '' && str_starts_with($code, 'restore_center_')
            ? orange_restore_center_operator_reason_ar($code)
            : orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'maintenance_released' => false,
        'restore_completed' => false,
        'rollback_completed' => false,
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
