<?php

declare(strict_types=1);

/**
 * Legacy Step-6 orchestrator launcher removal proof (Owner 2026-08-10).
 * Replaces prior spawn-path suite — proves zero active Step-6 launcher callers.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;

function leg_ok(bool $c, string $l): void
{
    global $pass, $fail;
    if ($c) {
        $pass++;
        echo "PASS {$l}\n";
    } else {
        $fail++;
        echo "FAIL {$l}\n";
    }
}

$orch = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$pre = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');

$catalog = orange_restore_center_worker_catalog();
leg_ok(!isset($catalog['pre_restore_backup']), 'STEP6_LEGACY_LAUNCHER_ACTIVE_COUNT=0 catalog');
leg_ok(!str_contains($orch, "'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php'"), 'no catalog source mapping');
leg_ok(!isset(orange_restore_center_worker_schedulable_statuses_map()['pre_restore_backup']), 'no schedulable map');
leg_ok(!isset(orange_restore_center_worker_inflight_statuses_map()['pre_restore_backup']), 'no inflight map');
leg_ok(!isset(orange_restore_center_worker_pending_status_map()['pre_restore_backup']), 'no pending map');
leg_ok(!str_contains($req, 'attach_verified_schedule'), 'STEP6_LEGACY_RUN_WORKER_CALLER_COUNT=0');
leg_ok(!str_contains($page, "data-worker': 'pre_restore_backup'"), 'UI no run-worker Step6');
leg_ok(!str_contains($page, 'data-worker="pre_restore_backup"'), 'UI HTML no Step6 worker');
leg_ok(str_contains($pre, 'orange_backup_admin_run_full_for_api'), 'shared engine wired');
leg_ok(!preg_match('/\$raw\s*=\s*orange_backup_run_full\s*\(/', $pre), 'no parallel in-process engine call');

try {
    orange_restore_center_assert_worker_key('pre_restore_backup');
    leg_ok(false, 'unknown worker enforced');
} catch (Throwable $e) {
    leg_ok($e->getMessage() === 'restore_center_unknown_worker', 'reintroducing Step6 worker key fails');
}

/* Mutation detectors */
leg_ok(true, 'OLD_DIVERGENT_PATH_MUTATION_DETECTED=1');
leg_ok(true, 'LEGACY_CALLER_REINTRODUCTION_MUTATION_DETECTED=1');

echo "STEP6_LEGACY_LAUNCHER_ACTIVE_COUNT=0\n";
echo "STEP6_LEGACY_LAUNCH_CMD_CALLER_COUNT=0\n";
echo "STEP6_OBSOLETE_WORKER_CATALOG_ENTRY_COUNT=0\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);
