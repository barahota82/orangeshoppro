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
    $nonce = trim((string) ($data['nonce'] ?? $data['authorization_nonce'] ?? ''));
    $password = (string) ($data['password'] ?? $data['reauth_password'] ?? '');
    $reason = (string) ($data['authorization_reason'] ?? $data['reason'] ?? '');

    if ($jobId === '' || $packageId === '' || $nonce === '') {
        json_response([
            'success' => false,
            'code' => 'invalid_authorization_input',
            'message' => 'job_id, package_id, and nonce are required.',
            'execution_started' => false,
            'cutover_started' => false,
        ], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $result = orange_restore_admin_fw_finalize_cutover_authorization(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId,
        $packageId,
        $phrase,
        $nonce,
        $password,
        $reason
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'cutover_started' => false,
        'http_never_imports' => true,
        'cli_invoked' => false,
        'authorization' => $result['public'] ?? null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => 'Production cutover authorized. Import still requires approved CLI worker.',
        'warning' => 'Authorization does not start wipe, import, or uploads cutover.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if (in_array($code, ['country_production_restore_not_enabled', 'recent_authentication_failed'], true)) {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'finalize_cutover_authorization_failed',
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'cutover_started' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
