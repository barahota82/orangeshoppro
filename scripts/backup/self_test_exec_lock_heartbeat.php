<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup'
    . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_orchestrator.php';

$passes = 0;
$failures = 0;
function hb_test(bool $c, string $l): void
{
    global $passes, $failures;
    if ($c) {
        echo "PASS: {$l}\n";
        $passes++;
    } else {
        echo "FAIL: {$l}\n";
        $failures++;
    }
}

echo "=== self_test_exec_lock_heartbeat ===\n";
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_hb_' . bin2hex(random_bytes(4));
@mkdir($tmp . DIRECTORY_SEPARATOR . 'framework', 0775, true);
$work = $tmp;
$job = 'job_hb_1';
$acq = orange_restore_exec_acquire_lock($work, $job);
hb_test(!empty($acq['ok']), 'acquire lock');
$hb1 = orange_restore_exec_lock_heartbeat($work, $job);
hb_test(!empty($hb1['ok']), 'heartbeat ok');
$status = orange_restore_exec_lock_status($work);
$payload = is_array($status['payload'] ?? null) ? $status['payload'] : [];
hb_test(isset($payload['heartbeat_at']) && (string) $payload['heartbeat_at'] !== '', 'heartbeat_at present');
hb_test(empty($status['stale']), 'fresh heartbeat not stale');

// Active statuses include rollback/finalize.
$active = orange_restore_exec_active_statuses();
hb_test(in_array('rollback_pending', $active, true), 'active includes rollback_pending');
hb_test(in_array('restore_finalizing', $active, true), 'active includes restore_finalizing');

orange_restore_exec_release_lock($work, $job);
hb_test(!is_file(orange_restore_exec_lock_path($work)), 'lock released');

echo "\nRESULT: {$passes} passed, {$failures} failed\n";
exit($failures > 0 ? 1 : 0);
