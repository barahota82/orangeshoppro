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
    $result = orange_restore_admin_fw_request_finalize(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    json_response([
        'success' => true,
        'metadata_only' => true,
        'http_never_finalize' => true,
        'cli_needed' => (bool) ($result['cli_needed'] ?? true),
        'cli_command' => (string) ($result['cli_command'] ?? ''),
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
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'Finalize Pending.'),
        'warning' => (string) ($result['warning'] ?? 'CLI will release maintenance. Forensic artifacts retained.'),
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
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'maintenance_released' => false,
        'restore_completed' => false,
        'rollback_completed' => false,
        'rollback_anchor_deleted' => false,
        'retention_pin_removed' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
