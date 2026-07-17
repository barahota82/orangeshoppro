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

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);

    $result = orange_restore_admin_fw_dry_run(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        trim((string) ($data['job_id'] ?? $data['id'] ?? '')),
        trim((string) ($data['package_type'] ?? '')),
        trim((string) ($data['package_id'] ?? '')),
        trim((string) ($data['country_code'] ?? ''))
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'dry_run' => true,
        'message' => 'Dry validation completed.',
        'job' => $result['job'],
        'report' => $result['report'],
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $msg = orange_restore_admin_safe_message($e);
    $code = trim($e->getMessage()) === 'restore_job_already_active' ? 'restore_job_already_active' : 'dry_run_failed';
    $status = $code === 'restore_job_already_active' ? 409 : 422;
    json_response([
        'success' => false,
        'code' => $code,
        'message' => $msg,
    ], $status);
}
