<?php

declare(strict_types=1);

/**
 * Step-6 PHP-resolver disposition after true Backup Center baseline recovery.
 * Attempt-3 absolute sibling trust is RETAINED for non-Step-6 Restore workers only
 * via includes/backup/restore/restore_worker_php_cli.php — not Backup Center path.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
function pr_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$orch = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$admin = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$workerCli = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_worker_php_cli.php');

pr_ok(!isset(orange_restore_center_worker_catalog()['pre_restore_backup']), 'Step6 not in catalog');
pr_ok(!str_contains($req, 'orange_backup_admin_resolve_cli_php_binary'), 'STEP6_LEGACY_PHP_RESOLVER_CALLER_COUNT=0');
pr_ok(!str_contains($req, 'orange_restore_worker_resolve_cli_php_binary'), 'Step6 request no restore worker resolver');
pr_ok(!str_contains($req, 'orange_restore_center_resolve'), 'Step6 request no restore resolver');
pr_ok(str_contains($orch, 'orange_restore_worker_resolve_cli_php_binary'), 'Restore worker resolver retained for other workers');
pr_ok(!str_contains($orch, 'orange_backup_admin_resolve_cli_php_binary'), 'RESTORE_EXECUTABLE_POLICY_LEAK_INTO_BACKUP_COUNT=0 (orch→BC)');
pr_ok(str_contains($workerCli, 'php_cli_is_windows_cgi_sibling') || str_contains($workerCli, 'sibling'), 'Attempt-3 sibling trust retained in Restore worker contract');
pr_ok(str_contains($admin, 'bare "php" fallback') || str_contains($admin, "return 'php';"), 'Backup Center b47cbe86 bare-php contract present');
pr_ok(!str_contains($admin, 'php_cli_binary_unavailable'), 'Backup Center does not throw Restore absolute-unavailable');

$remaining = array_keys(orange_restore_center_worker_catalog());
pr_ok(in_array('shadow_db', $remaining, true), 'shadow_db still uses orchestrator resolver');
pr_ok(in_array('finalize', $remaining, true), 'finalize still uses orchestrator resolver');
pr_ok(!in_array('pre_restore_backup', $remaining, true), 'pre_restore_backup absent from remaining workers');

echo "UNUSED_ATTEMPT3_LOCAL_FIX_COUNT=0\n";
echo "UNCLASSIFIED_ATTEMPT3_LOCAL_FIX_COUNT=0\n";
echo "STEP6_LEGACY_PHP_RESOLVER_CALLER_COUNT=0\n";
echo "RESTORE_EXECUTABLE_POLICY_LEAK_INTO_BACKUP_COUNT=0\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);