<?php

declare(strict_types=1);

/**
 * Restore Center orchestration: invoke an approved CLI worker for a job.
 * Does not rewrite restore logic — spawns existing scripts/backup workers only.
 */

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
    $worker = trim((string) ($data['worker'] ?? $data['worker_key'] ?? ''));
    if ($jobId === '' || $worker === '') {
        json_response([
            'success' => false,
            'code' => 'invalid_worker_request',
            'message' => 'job_id and worker are required.',
        ], 422);
    }

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $workRoot = (string) ($ctx['work_root'] ?? '');
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }

    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);
    $job = orange_restore_fw_read($workRoot, $jobId);
    $type = (string) ($job['package_type'] ?? '');
    orange_restore_admin_assert_package_type_permission($admin, $pdo, $type);
    if ($type !== 'full_disaster') {
        throw new RuntimeException('country_production_restore_not_enabled');
    }
    if (!orange_restore_admin_may_view_full($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_restore_full permission.');
    }

    $username = trim((string) ($admin['username'] ?? $admin['display_name'] ?? 'admin'));
    $result = orange_restore_center_run_worker(
        $projectRoot,
        $workRoot,
        $jobId,
        $worker,
        $username !== '' ? $username : 'admin'
    );

    $fresh = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));

    json_response([
        'success' => (bool) ($result['ok'] ?? false),
        'orchestrated' => true,
        'worker' => (string) ($result['worker'] ?? $worker),
        'exit_code' => (int) ($result['exit_code'] ?? 1),
        'message' => (string) ($result['message'] ?? ''),
        'job' => $fresh,
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], !empty($result['ok']) ? 200 : 409);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'restore_center_worker_failed',
        'message' => orange_restore_admin_safe_message($e),
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
