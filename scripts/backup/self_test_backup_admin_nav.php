<?php

declare(strict_types=1);

/**
 * Phase 3A — Admin nav regression: backup engine must not load during header/nav checks.
 *
 * Usage:
 *   php scripts/backup/self_test_backup_admin_nav.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function backup_nav_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

/** @return list<string> */
function backup_nav_engine_markers(): array
{
    return [
        'backup_admin.php',
        'backup_runner.php',
        'backup_environment.php',
        'backup_full.php',
        'country_batch_export.php',
        'recovery_validation.php',
        'restore_orchestrator.php',
        'restore_e2e_orchestrator.php',
        'restore_admin.php',
    ];
}

function backup_nav_included_engine_file(): ?string
{
    foreach (get_included_files() as $path) {
        $base = basename(str_replace('\\', '/', $path));
        foreach (backup_nav_engine_markers() as $marker) {
            if ($base === $marker) {
                return $path;
            }
        }
    }

    return null;
}

function backup_nav_test_pdo(string $permKey, bool $canEdit, int $adminId = 2): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $pdo->exec('INSERT INTO admins VALUES (' . $adminId . ', \'op\', 1, 0, \'Op\', \'\')');
    if ($permKey !== '') {
        $pdo->exec('INSERT INTO admin_permissions VALUES (' . $adminId . ', ' . $pdo->quote($permKey) . ', 1, ' . ($canEdit ? '1' : '0') . ', 0)');
    }
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

$superAdmin = ['id' => 1, 'is_superuser' => 1, 'is_active' => 1];
$viewPdo = backup_nav_test_pdo('backup_view', false, 3);
$viewAdmin = ['id' => 3, 'is_superuser' => 0, 'is_active' => 1];
$dashboardPdo = backup_nav_test_pdo('', false, 4);
$dashboardAdmin = ['id' => 4, 'is_superuser' => 0, 'is_active' => 1];

$settingsNavPages = ['countries', 'country_screen_copy', 'admin_users', 'backup_center', 'restore_center'];

foreach ($settingsNavPages as $page) {
    $before = count(get_included_files());
    $visible = orange_admin_nav_visible($superAdmin, $viewPdo, $page);
    $engine = backup_nav_included_engine_file();
    backup_nav_self_test($engine === null, 'nav: orange_admin_nav_visible(' . $page . ') does not load backup engine');
    backup_nav_self_test($before <= count(get_included_files()), 'nav: included file count stable for ' . $page);
    if ($page === 'backup_center') {
        backup_nav_self_test($visible === true, 'nav: superuser sees backup_center');
    }
    if ($page === 'restore_center') {
        backup_nav_self_test($visible === true, 'nav: superuser sees restore_center');
    }
}

backup_nav_self_test(
    orange_admin_nav_visible($viewAdmin, $viewPdo, 'backup_center') === true,
    'nav: backup_view permission shows backup_center'
);
backup_nav_self_test(
    backup_nav_included_engine_file() === null,
    'nav: backup_view visibility check does not load backup engine'
);

$caps = orange_admin_caps_for_page($superAdmin, $dashboardPdo, 'dashboard');
backup_nav_self_test(is_array($caps) && !empty($caps['can_view']), 'nav: dashboard caps resolve');
backup_nav_self_test(
    backup_nav_included_engine_file() === null,
    'nav: dashboard caps_for_page does not load backup engine'
);

$capsBackupPdo = backup_nav_test_pdo('backup_view', false, 99);
$capsBackupAdmin = ['id' => 99, 'is_superuser' => 0, 'is_active' => 1, 'country_id' => 1];
$capsBackup = orange_admin_caps_for_page($capsBackupAdmin, $capsBackupPdo, 'backup_center');
backup_nav_self_test(
    !empty($capsBackup['can_view']) && ($capsBackup['can_edit'] ?? false) === false,
    'nav: backup_center caps use lightweight backup_view only'
);
backup_nav_self_test(
    backup_nav_included_engine_file() === null,
    'nav: backup_center caps_for_page does not load backup engine'
);

$restoreFullPdo = backup_nav_test_pdo('backup_restore_full', false, 5);
$restoreFullAdmin = ['id' => 5, 'is_superuser' => 0, 'is_active' => 1, 'country_id' => 1];
$restoreCaps = orange_admin_caps_for_page($restoreFullAdmin, $restoreFullPdo, 'restore_center');
backup_nav_self_test(
    !empty($restoreCaps['can_view']) && ($restoreCaps['can_edit'] ?? false) === false,
    'nav: restore_center caps use lightweight restore permission only'
);
backup_nav_self_test(
    backup_nav_included_engine_file() === null,
    'nav: restore_center caps_for_page does not load restore engine'
);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';
backup_nav_self_test(
    backup_nav_included_engine_file() !== null,
    'nav: explicit backup_admin require loads engine (lazy-load path)'
);

exit($failures > 0 ? 1 : 0);
