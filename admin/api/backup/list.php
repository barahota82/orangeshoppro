<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

backup_admin_api_require_get();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);

    $projectRoot = backup_admin_api_project_root();
    $ctx = orange_backup_admin_context_for_view($projectRoot);
    $rootHealth = $ctx['root_health'];
    $overview = orange_backup_admin_collect_overview($pdo, $projectRoot, $ctx);
    $manualAvailable = !empty($rootHealth['manual_actions_available']);

    json_response([
        'success' => true,
        'backup_root_health' => $rootHealth,
        'permissions' => [
            'can_view' => orange_backup_admin_may_view($admin, $pdo),
            'can_run' => orange_backup_admin_may_run($admin, $pdo) && $manualAvailable,
            'can_verify' => orange_backup_admin_may_verify($admin, $pdo),
            'manual_actions_available' => $manualAvailable,
            'verify_is_read_only' => true,
            'recovery_check_requires_write' => true,
        ],
        'csrf_token' => orange_backup_admin_csrf_token(),
        'overview' => $overview,
        'full_snapshots' => orange_backup_admin_list_full_snapshots($ctx['backup_root'], 20),
        'country_packages' => orange_backup_admin_list_country_packages($pdo, $ctx['backup_root'], 5),
        'logs' => orange_backup_admin_list_logs($ctx['backup_root'], 40),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
