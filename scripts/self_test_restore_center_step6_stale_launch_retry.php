<?php

declare(strict_types=1);

/**
 * Legacy Step-6 stale-launch path removal proof (Owner 2026-08-10).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
function sl_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$orch = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

sl_ok(!isset(orange_restore_center_worker_catalog()['pre_restore_backup']), 'no Step6 catalog → no stale-launch path');
sl_ok(!str_contains($req, 'discard_stale_launch'), 'Step6 request has no stale-launch caller');
sl_ok(!str_contains($req, 'spawn_detached'), 'Step6 request has no spawn');
sl_ok(!str_contains($req, '_launch.cmd'), 'Step6 request has no launch.cmd');
sl_ok(!str_contains($page, 'RC_PRE_BACKUP_SCHEDULED_MSG'), 'scheduled retry copy removed');
// Generic stale-launch helper may remain for other workers — Step6 must not reach it.
sl_ok(str_contains($orch, 'function orange_restore_center_discard_stale_launch_artifact'), 'stale helper retained for other workers');
sl_ok(!str_contains($page, "data-worker': 'pre_restore_backup'"), 'UI cannot invoke Step6 stale path');

echo "STEP6_LEGACY_STALE_LAUNCH_CALLER_COUNT=0\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
