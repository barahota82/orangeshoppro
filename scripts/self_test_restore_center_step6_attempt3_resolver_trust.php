<?php

declare(strict_types=1);

/**
 * Attempt-3 sibling PHP disposition after Step-6 single-engine cutover.
 * RETAIN_SHARED_OTHER_RESTORE_WORKERS — Step 6 has zero callers.
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
function a3_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$catalog = orange_restore_center_worker_catalog();
a3_ok(!isset($catalog['pre_restore_backup']), 'Step6 removed — Attempt-3 not used by Step6');
a3_ok(count($catalog) >= 8, 'other Restore workers remain');
a3_ok(function_exists('orange_backup_admin_resolve_cli_php_binary'), 'shared PHP resolver retained');
a3_ok(function_exists('orange_backup_execute_full_authoritative'), 'shared Full Backup service present');

$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
a3_ok(str_contains($req, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'Step6 uses execute adapter');
a3_ok(!str_contains($req, 'resolve_cli_php'), 'Step6 adapter does not call worker PHP resolver');

echo "ATTEMPT3_DISPOSITION=RETAIN_SHARED_OTHER_RESTORE_WORKERS\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
