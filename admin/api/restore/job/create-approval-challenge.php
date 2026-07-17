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
    $challenge = orange_restore_admin_fw_create_approval_challenge(
        $ctx['backup_root'],
        $ctx['work_root'],
        $admin,
        $pdo,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'execution_started' => false,
        'maintenance_enabled' => false,
        'challenge' => $challenge,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => 'Approval challenge created. No restore started. Maintenance not enabled.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'challenge_failed',
        'message' => orange_restore_admin_safe_message($e),
    ], $status);
}
