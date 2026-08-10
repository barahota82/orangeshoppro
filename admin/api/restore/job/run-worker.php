<?php

declare(strict_types=1);

/**
 * Restore Center orchestration: schedule an approved internal worker (detached).
 * HTTP returns immediately — does not wait for the worker.
 * Server-side stage/status fence + atomic claim (orchestrator layer only).
 * Operator must never receive CLI/Plesk/Terminal handoff.
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
            'scheduled' => false,
            'diagnostics' => [
                'code' => 'invalid_worker_request',
                'reason_ar' => 'معرّف المهمة أو اسم العامل مفقود.',
            ],
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
    $result = orange_restore_center_request_and_schedule(
        $projectRoot,
        $workRoot,
        $jobId,
        $worker,
        $username !== '' ? $username : 'admin'
    );

    if (empty($result['scheduled']) || empty($result['ok']) || (int) ($result['pid'] ?? 0) <= 0) {
        throw new RuntimeException('restore_center_spawn_failed');
    }

    $fresh = orange_restore_fw_public_row(orange_restore_fw_read($workRoot, $jobId));

    json_response([
        'success' => true,
        'orchestrated' => true,
        'detached' => true,
        'scheduled' => true,
        'http_waits_for_worker' => false,
        'cli_needed' => false,
        'cli_command' => '',
        'operator_action_required' => false,
        'worker' => (string) ($result['worker'] ?? $worker),
        'pid' => (int) ($result['pid'] ?? 0),
        'message' => (string) ($result['message'] ?? 'Worker scheduled.'),
        'job' => $fresh,
        'diagnostics' => is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [
            'code' => 'ok',
            'reason_ar' => 'تم بدء التنفيذ على الخادم. يمكنك مغادرة الصفحة، وسيستمر التنفيذ.',
        ],
        'csrf_token' => orange_backup_admin_csrf_token(),
    ]);
} catch (Throwable $e) {
    $code = trim($e->getMessage());
    $status = 422;
    if ($code === 'country_production_restore_not_enabled') {
        $status = 403;
    }

    $jobStatus = '';
    $diagFull = null;
    try {
        if (isset($workRoot, $jobId) && is_string($workRoot) && $workRoot !== '' && is_string($jobId) && $jobId !== '') {
            $jobRow = orange_restore_fw_read($workRoot, $jobId);
            $jobStatus = (string) ($jobRow['status'] ?? '');
            $diagFull = orange_restore_center_diagnostics($workRoot, $jobId);
        }
    } catch (Throwable $ignored) {
        $diagFull = null;
    }

    $code = function_exists('orange_restore_center_normalize_failure_code')
        ? orange_restore_center_normalize_failure_code($code)
        : $code;

    $messages = [
        'restore_center_invalid_stage' => orange_restore_center_operator_reason_ar(
            'restore_center_invalid_stage',
            $jobStatus,
            isset($worker) ? (string) $worker : ''
        ),
        'restore_center_worker_already_running' => orange_restore_center_operator_reason_ar(
            'restore_center_worker_already_running',
            $jobStatus,
            isset($worker) ? (string) $worker : ''
        ),
        'restore_center_spawn_failed' => orange_restore_center_operator_reason_ar('restore_center_spawn_failed', $jobStatus),
        'restore_center_worker_executable_unavailable' => orange_restore_center_operator_reason_ar(
            'restore_center_worker_executable_unavailable',
            $jobStatus
        ),
        'php_cli_binary_unavailable' => orange_restore_center_operator_reason_ar(
            'restore_center_worker_executable_unavailable',
            $jobStatus
        ),
        'restore_center_mutex_open_failed' => orange_restore_center_operator_reason_ar('restore_center_mutex_open_failed'),
        'restore_center_spawn_launch_cmd_failed' => orange_restore_center_operator_reason_ar('restore_center_spawn_launch_cmd_failed'),
    ];

    json_response([
        'success' => false,
        'code' => $code !== '' ? $code : 'restore_center_worker_failed',
        'message' => $messages[$code] ?? orange_restore_admin_safe_message($e),
        'detached' => false,
        'scheduled' => false,
        'http_waits_for_worker' => false,
        'diagnostics' => [
            'code' => $code !== '' ? $code : 'restore_center_worker_failed',
            'reason_ar' => $messages[$code] ?? orange_restore_center_operator_reason_ar(
                $code !== '' ? $code : 'restore_center_spawn_failed',
                $jobStatus,
                isset($worker) ? (string) $worker : ''
            ),
            'job_status' => $jobStatus,
            'worker' => isset($worker) ? (string) $worker : '',
            'detail' => $diagFull,
        ],
        'csrf_token' => orange_backup_admin_csrf_token(),
    ], $status);
}
