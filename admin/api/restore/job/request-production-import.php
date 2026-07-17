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
    $result = orange_restore_admin_fw_request_production_import(
        $ctx['backup_root'],
        $ctx['work_root'],
        $projectRoot,
        $admin,
        $pdo,
        $jobId
    );

    json_response([
        'success' => true,
        'metadata_only' => true,
        'http_never_imports' => true,
        'cli_needed' => (bool) ($result['cli_needed'] ?? true),
        'cli_command' => (string) ($result['cli_command'] ?? ''),
        'idempotent' => (bool) ($result['idempotent'] ?? false),
        'execution_started' => false,
        'files_switched' => false,
        'rollback_executed' => false,
        'maintenance_released' => false,
        'production_cutover_allowed' => false,
        'job' => $result['job'] ?? null,
        'meta' => $result['meta'] ?? null,
        'gates' => $result['gates'] ?? null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'Production Import Pending.'),
        'warning' => 'Application files have NOT been switched.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'production_import_request_failed',
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'files_switched' => false,
        'production_cutover_allowed' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
