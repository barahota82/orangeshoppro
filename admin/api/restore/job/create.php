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

    $packageType = trim((string) ($data['package_type'] ?? ''));
    $packageId = trim((string) ($data['package_id'] ?? ''));
    $countryCode = trim((string) ($data['country_code'] ?? ''));

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);

    $job = orange_restore_admin_fw_create_job(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $packageType,
        $packageId,
        $countryCode
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'message' => 'Restore job created and waiting confirmation.',
        'job' => $job,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage()) === 'restore_job_already_active' ? 'restore_job_already_active' : 'restore_job_create_failed';
    $status = $code === 'restore_job_already_active' ? 409 : 422;
    json_response([
        'success' => false,
        'code' => $code,
        'message' => orange_restore_admin_safe_message($e),
    ], $status);
}
