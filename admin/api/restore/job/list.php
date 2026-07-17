<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);
    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);

    json_response([
        'success' => true,
        'read_only_execution' => true,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'permissions' => [
            'can_view_full' => $mayFull,
            'can_view_country' => $mayCountry,
        ],
        'jobs' => orange_restore_admin_fw_list_jobs($ctx['work_root'], $mayFull, $mayCountry),
        'active_job' => ($active = orange_restore_fw_find_active_job($ctx['work_root'])) !== null
            ? orange_restore_fw_public_row($active)
            : null,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
