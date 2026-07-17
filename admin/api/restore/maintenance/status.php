<?php

declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $maintenance = orange_restore_admin_fw_maintenance_status($ctx['work_root']);

    json_response([
        'success' => true,
        'read_only' => true,
        'maintenance' => $maintenance,
        'warning' => 'Approval does not start restore or enable maintenance in this phase.',
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
