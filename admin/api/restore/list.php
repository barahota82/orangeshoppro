<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

restore_admin_api_require_get();

try {
    $admin = restore_admin_api_admin();
    $pdo = restore_admin_api_pdo();
    orange_restore_admin_require_view($admin, $pdo);

    $mayFull = orange_restore_admin_may_view_full($admin, $pdo);
    $mayCountry = orange_restore_admin_may_view_country($admin, $pdo);
    $projectRoot = restore_admin_api_project_root();
    $ctx = orange_restore_admin_context($projectRoot);
    $backupRoot = $ctx['backup_root'];
    $workRoot = $ctx['work_root'];

    $fullPackages = [];
    if ($mayFull) {
        foreach (orange_backup_admin_list_full_snapshots($backupRoot, 50) as $pkg) {
            $fullPackages[] = orange_restore_admin_public_package_row($pkg, 'full_disaster');
        }
    }

    $countryPackages = [];
    if ($mayCountry) {
        foreach (orange_backup_admin_list_country_packages($pdo, $backupRoot, 10) as $pkg) {
            $countryPackages[] = orange_restore_admin_public_package_row($pkg, 'country_recovery');
        }
    }

    json_response([
        'success' => true,
        'read_only' => true,
        'read_only_execution' => true,
        'csrf_token' => orange_backup_admin_csrf_token(),
        'permissions' => [
            'can_view_full' => $mayFull,
            'can_view_country' => $mayCountry,
            'is_superuser' => orange_admin_is_superuser($admin),
            'can_create_job' => $mayFull || $mayCountry,
            'can_cancel_job' => $mayFull || $mayCountry,
        ],
        'overview' => orange_restore_admin_collect_overview($workRoot),
        'full_packages' => $fullPackages,
        'country_packages' => $countryPackages,
        'framework_jobs' => orange_restore_admin_fw_list_jobs($workRoot, $mayFull, $mayCountry),
        'jobs' => orange_restore_admin_fw_list_jobs($workRoot, $mayFull, $mayCountry),
        'legacy_engine_jobs' => orange_restore_admin_list_jobs($workRoot, $mayFull, $mayCountry),
        'maintenance' => orange_restore_admin_fw_maintenance_status($workRoot),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, orange_restore_admin_safe_message($e));
}
