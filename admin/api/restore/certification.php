<?php

declare(strict_types=1);

/**
 * Read-only DR certification status for Restore Center.
 * HTTP never executes the drill.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dr_drill.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $projectRoot = restore_admin_api_project_root();
    $cert = orange_restore_dr_drill_read_certification_report($projectRoot);

    json_response([
        'success' => true,
        'read_only' => true,
        'http_never_runs_drill' => true,
        'certification' => $cert,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
