<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $jobId = trim((string) ($_GET['job_id'] ?? $_GET['id'] ?? ''));
    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);

    $result = orange_restore_admin_fw_maintenance_state(
        $ctx['work_root'],
        $mayFull,
        $mayCountry,
        $jobId
    );

    json_response([
        'success' => true,
        'read_only' => true,
        'execution_started' => false,
        'restore_started' => false,
        'production_cutover_allowed' => false,
        'maintenance' => $result['maintenance'] ?? null,
        'job' => $result['job'] ?? null,
        'record' => $result['record'] ?? null,
        'stale' => (bool) ($result['stale'] ?? false),
        'auto_release_forbidden' => true,
        'warning' => 'Production restore has NOT started.',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
