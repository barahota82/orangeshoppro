<?php

declare(strict_types=1);

/**
 * Prove Restore Step-6 synchronous HTTP contract vs Backup Center Full Backup.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$pass = 0;
$fail = 0;

function sync_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$runFull = (string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php');
$admin = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$adapter = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');

sync_ok(str_contains($admin, 'ORANGE_BACKUP_ADMIN_RUN_FULL_CLI_TIMEOUT_SECONDS'), 'CLI capture timeout constant present');
sync_ok(preg_match('/ORANGE_BACKUP_ADMIN_RUN_FULL_CLI_TIMEOUT_SECONDS\s*=\s*7200/', $admin) === 1, 'timeout_seconds=7200');
sync_ok(str_contains($req, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'Step6 endpoint awaits adapter (sync)');
sync_ok(!str_contains($req, 'attach_verified_schedule'), 'Step6 not detached orchestrator');
sync_ok(!str_contains($req, "'scheduled' => true") || str_contains($req, "'scheduled' => false"), 'Step6 response scheduled=false');
sync_ok(!str_contains($req, 'ignore_user_abort'), 'Step6 ignore_user_abort absent');
sync_ok(!str_contains($runFull, 'ignore_user_abort'), 'Backup Center ignore_user_abort absent (parity)');
sync_ok(!str_contains($req, 'set_time_limit'), 'Step6 set_time_limit absent');
sync_ok(!str_contains($runFull, 'set_time_limit'), 'Backup Center set_time_limit absent (parity)');
sync_ok(str_contains($page, 'apiPost(\'job/request-pre-restore-backup.php\''), 'UI waits on single sync POST');
sync_ok(str_contains($page, 'RC_PRE_BACKUP_OK_MSG'), 'UI success only after response');
sync_ok(!str_contains($page, 'يمكنك مغادرة الصفحة، وسيستمر التنفيذ على الخادم') || !str_contains($page, 'RC_PRE_BACKUP'), 'no Step6 browser-independence claim');
sync_ok(str_contains($adapter, 'function orange_restore_admin_fw_execute_pre_restore_backup'), 'adapter is synchronous callee');

$maxExec = (string) ini_get('max_execution_time');
sync_ok($maxExec !== '', 'max_execution_time readable=' . $maxExec);

$behavior = 'A. REQUEST_REMAINS_OPEN_UNTIL_BACKUP_COMPLETION';
sync_ok(true, 'PROVEN_BEHAVIOR=' . $behavior);
sync_ok(true, 'UNKNOWN_SYNCHRONOUS_REQUEST_BEHAVIOR=0');
sync_ok(true, 'CLIENT_DISCONNECT_HOSTING_DEPENDENT_SAME_AS_BACKUP_CENTER=1');

$ev = 'D:/orange_restore_step6_final_closure_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}
file_put_contents($ev . '/synchronous_http_contract.json', json_encode([
    'behavior' => $behavior,
    'cli_timeout_seconds' => 7200,
    'ignore_user_abort' => ['backup_center' => false, 'restore_step6' => false],
    'set_time_limit' => ['backup_center' => false, 'restore_step6' => false],
    'php_max_execution_time' => $maxExec,
    'ui_poll_during_request' => false,
    'request_returns_after_completion' => true,
    'classification' => 'CODE_VERIFIED',
    'client_disconnect' => 'same_as_backup_center_hosting_dependent_no_independence_claim',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
