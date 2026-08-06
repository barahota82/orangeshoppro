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
    $backupRoot = $ctx['backup_root'];

    require_once dirname(__DIR__, 3) . '/includes/countries.php';
    // Country Backup views follow Admin Country Context; Full Backup stays global.
    $countryContextCode = orange_admin_context_country_code($pdo);

    // Single-pass package loads shared with overview (avoids duplicate JSON/FS inspections).
    // Full: shared snapshots/ — never filtered by Country Context; every recognized finalized package
    // (no silent row cap — Backup Center Last 5 remains a client-side slice of this payload).
    // Country: uncapped package ids for the selected context country only (no cross-country leakage).
    $fullSnapshots = orange_backup_admin_list_full_snapshots($backupRoot);
    $countryPackages = orange_backup_admin_list_country_packages($pdo, $backupRoot, null, $countryContextCode);
    $inventoryScoped = orange_backup_admin_package_inventory_counts($backupRoot, $countryContextCode);
    // Storage KPIs remain shared BackupRoot totals (global disk), not context-scoped.
    $inventoryGlobal = orange_backup_admin_package_inventory_counts($backupRoot, null);
    $storage = orange_backup_admin_collect_storage_totals($backupRoot, $inventoryGlobal);

    $overview = orange_backup_admin_collect_overview($pdo, $projectRoot, $ctx, [
        'full_snapshots' => $fullSnapshots,
        'country_packages' => $countryPackages,
        'inventory' => $inventoryScoped,
        'storage' => $storage,
    ]);
    $overview['country_context_code'] = orange_countries_display_code($countryContextCode);
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
        'country_context_code' => orange_countries_display_code($countryContextCode),
        'overview' => $overview,
        'full_snapshots' => $fullSnapshots,
        'country_packages' => $countryPackages,
        'logs' => orange_backup_admin_list_logs($backupRoot, 40),
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
