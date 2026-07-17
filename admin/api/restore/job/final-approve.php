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
    $packageId = trim((string) ($data['package_id'] ?? ''));
    $phrase = (string) ($data['confirmation_phrase'] ?? $data['phrase'] ?? '');
    $nonce = trim((string) ($data['nonce'] ?? $data['approval_nonce'] ?? ''));
    $password = (string) ($data['password'] ?? $data['reauth_password'] ?? '');

    if ($jobId === '' || $packageId === '' || $nonce === '') {
        json_response([
            'success' => false,
            'code' => 'invalid_approval_input',
            'message' => 'job_id, package_id, and nonce are required.',
        ], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $result = orange_restore_admin_fw_final_approve(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId,
        $packageId,
        $phrase,
        $nonce,
        $password
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'maintenance_enabled' => false,
        'cli_invoked' => false,
        'message' => 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة.',
        'job' => $result['job'],
        'approval' => $result['approval'],
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if (in_array($code, ['country_production_restore_not_enabled', 'recent_authentication_failed'], true)) {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'final_approve_failed',
        'message' => orange_restore_admin_safe_message($e),
    ], $status);
}
