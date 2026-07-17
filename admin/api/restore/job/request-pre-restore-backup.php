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
    $result = orange_restore_admin_fw_request_pre_restore_backup(
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
        'cli_needed' => (bool) ($result['cli_needed'] ?? true),
        'idempotent' => (bool) ($result['idempotent'] ?? false),
        'job' => $result['job'] ?? null,
        'record' => $result['record'] ?? null,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'message' => (string) ($result['message'] ?? 'Pre-restore backup requested. CLI worker required.'),
        'warning' => 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف.',
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'pre_restore_backup_request_failed',
        'message' => orange_restore_admin_safe_message($e),
        'execution_started' => false,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
