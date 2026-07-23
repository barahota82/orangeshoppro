<?php

declare(strict_types=1);

/**
 * Restore Center orchestration diagnostics (safe operational view).
 * No absolute paths, secrets, or server internals beyond redacted log tails.
 */

require_once __DIR__ . '/../_bootstrap.php';
require_once dirname(__DIR__, 4) . '/includes/backup/restore/restore_center_orchestrator.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $jobId = trim((string) ($_GET['id'] ?? $_GET['job_id'] ?? ''));
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        json_response([
            'success' => false,
            'code' => 'invalid_job_id',
            'message' => 'Invalid restore job id.',
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

    json_response([
        'success' => true,
        'diagnostics' => orange_restore_center_diagnostics($workRoot, $jobId),
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }
    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'orchestrator_diagnostics_failed',
        'message' => orange_restore_admin_safe_message($e),
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
