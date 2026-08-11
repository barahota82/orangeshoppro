<?php

declare(strict_types=1);

/**
 * Attempt-3 sibling PHP disposition after Restore-only Step6 adapter Phase 2.
 * RETAIN_RESTORE_WORKER_CONTRACT — Step 6 uses Backup Center Full callable;
 * other Restore workers keep absolute PHP CLI resolver.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_worker_php_cli.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
function a3_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$catalog = orange_restore_center_worker_catalog();
a3_ok(!isset($catalog['pre_restore_backup']), 'Step6 removed from worker catalog');
a3_ok(count($catalog) >= 8, 'other Restore workers remain');
a3_ok(function_exists('orange_backup_admin_resolve_cli_php_binary'), 'Backup Center PHP resolver present');
a3_ok(function_exists('orange_restore_worker_resolve_cli_php_binary'), 'Restore worker PHP resolver present');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
a3_ok(str_contains($preSrc, 'ORANGE_RESTORE_STEP6_PHASE1_FROZEN = false'), 'Step6 Phase1 freeze cleared');
a3_ok(str_contains($preSrc, 'orange_backup_admin_run_full_for_api'), 'Step6 Restore-only adapter uses Backup Full callable');
$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$admin = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
a3_ok(str_contains($req, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'Step6 request uses adapter');
a3_ok(!str_contains($req, 'step6_temporarily_frozen'), 'Step6 request freeze removed');
a3_ok(!str_contains($req, 'resolve_cli_php'), 'Step6 adapter does not call worker PHP resolver');
a3_ok(!str_contains($admin, 'php_cli_binary_unavailable'), 'Restore absolute throw not in Backup Center');

echo "ATTEMPT3_DISPOSITION=RETAIN_RESTORE_WORKER_CONTRACT\n";
echo "BACKUP_CENTER_EXECUTION_CONTRACT_COUNT=1\n";
echo "RESTORE_WORKER_EXECUTION_CONTRACT_COUNT=1\n";
echo "RESTORE_STEP6_RESTORE_ONLY_ADAPTER=1\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
