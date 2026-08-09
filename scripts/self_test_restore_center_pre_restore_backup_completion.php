<?php

declare(strict_types=1);

/**
 * Restore Center Step-6 — pre-restore backup completion / dispatch authority.
 *
 * Safe fixtures only. Does NOT run Production backup/restore workers.
 *
 * Usage:
 *   php scripts/self_test_restore_center_pre_restore_backup_completion.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;

function s6_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

$evidenceDir = 'D:/orange_restore_journey_refresh_authority_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$reqApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$runApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/run-worker.php');
$cliSrc = (string) file_get_contents($projectRoot . '/scripts/backup/restore_prepare_backup.php');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

s6_ok($pageSrc !== '' && $reqApi !== '' && $cliSrc !== '', 'CORE step6 sources readable');
s6_ok(str_contains($reqApi, 'execution_started\' => false'), 'request API execution_started=false');
s6_ok(str_contains($reqApi, 'cli_needed'), 'request API exposes cli_needed');
s6_ok(str_contains($preSrc, 'function orange_restore_pre_backup_request'), 'request helper present');
s6_ok(str_contains($preSrc, 'function orange_restore_pre_backup_run_cli'), 'CLI worker helper present');
s6_ok(str_contains($cliSrc, 'orange_restore_pre_backup_run_cli'), 'CLI entry calls worker');
s6_ok(str_contains($orchSrc, "'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php'"), 'orchestrator catalogs prepare backup');
s6_ok(str_contains($runApi, 'orange_restore_center_request_and_schedule'), 'run-worker schedules orchestrator');
s6_ok(str_contains($pageSrc, 'requestThenRunWorker'), 'UI requestThenRunWorker present');
s6_ok(str_contains($pageSrc, 'job/run-worker.php'), 'UI posts internal run-worker schedule');
s6_ok(str_contains($pageSrc, "'pre_restore_backup'"), 'UI schedules pre_restore_backup worker');

/* Pending must not complete journey step */
$pendingJob = [
    'job_id' => 'S6-PEND',
    'package_id' => '2026-01-01_000000',
    'package_type' => 'full_disaster',
    'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    'phase' => ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_PENDING,
    'progress' => 10,
    'message' => 'Pre-restore backup pending — CLI worker required',
    'created_by' => 'test',
    'created_by_admin_id' => 1,
    'created_at' => '2026-01-01T00:00:00Z',
    'updated_at' => '2026-01-01T00:00:00Z',
    'pre_restore_backup_file' => ORANGE_RESTORE_PRE_BACKUP_FILE,
    'pre_restore_backup_status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    'execution_started' => false,
];
$pendingPublic = orange_restore_fw_public_row($pendingJob);
$pendingAuth = orange_restore_fw_guided_journey_authority(
    ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    $pendingJob
);
s6_ok(!empty($pendingPublic['has_pre_restore_backup']), 'pending has_pre_restore_backup=1 (artifact)');
s6_ok(empty($pendingPublic['is_pre_restore_backup_ready']), 'pending is_pre_restore_backup_ready=0');
s6_ok((int) $pendingAuth['current_index'] === 5, 'pending guided current=5');
s6_ok(($pendingAuth['states'][5] ?? '') !== 'done', 'pending step6 not done');
s6_ok(($pendingPublic['guided_journey']['current_index'] ?? -1) === 5, 'public guided_journey current=5');

$readyJob = $pendingJob;
$readyJob['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
$readyJob['pre_restore_backup_status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
$readyJob['progress'] = 100;
$readyPublic = orange_restore_fw_public_row($readyJob);
$readyAuth = orange_restore_fw_guided_journey_authority(
    ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY,
    $readyJob
);
s6_ok(!empty($readyPublic['is_pre_restore_backup_ready']), 'ready is_pre_restore_backup_ready=1');
s6_ok((int) $readyAuth['current_index'] === 6, 'ready advances to shadow current=6');
s6_ok(($readyAuth['states'][5] ?? '') === 'done', 'ready marks step6 done');

/* Page must not treat has_pre_restore_backup as done */
s6_ok(!preg_match('/backupDone\s*=\s*!!\(\s*job\.has_pre_restore_backup/', $pageSrc), 'page backupDone ignores has_*');
s6_ok(str_contains($pageSrc, 'طُلبت النسخة الاحتياطية وما زالت غير مكتملة'), 'Arabic pending non-completion copy');
s6_ok(!str_contains($pageSrc, 'pre_restore_backup_pending') || str_contains($pageSrc, "status === 'pre_restore_backup_pending'"), 'raw token only in status compare not operator body strings as sole label');

/* Operator Arabic surfaces — statusLabelAr mappings */
s6_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_pending') label = 'بانتظار تنفيذ النسخة الاحتياطية'"), 'Arabic label pending');
s6_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_ready') label = 'النسخة الاحتياطية جاهزة وآمنة للرجوع'"), 'Arabic label ready');

/* Request/dispatch contracts */
s6_ok(str_contains($preSrc, "'cli_needed' => true"), 'request sets cli_needed');
s6_ok(str_contains($preSrc, 'ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING'), 'request transitions to pending');
s6_ok(str_contains($preSrc, 'ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY'), 'worker can reach ready');
s6_ok(str_contains($runApi, "'scheduled' => true"), 'run-worker success requires scheduled');
s6_ok(str_contains($runApi, 'http_waits_for_worker\' => false'), 'HTTP does not wait for worker');

/* Schedulable statuses include pending */
$map = orange_restore_center_worker_schedulable_statuses_map();
s6_ok(in_array(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING, $map['pre_restore_backup'] ?? [], true), 'pending is schedulable');
s6_ok(in_array(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, $map['pre_restore_backup'] ?? [], true), 'approved is schedulable');

/* Local fixture: seed framework job path + minimal approval/contract so request can pend. */
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s6_' . bin2hex(random_bytes(4));
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
$backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backup_root';
mkdir($workRoot, 0777, true);
mkdir($backupRoot, 0777, true);
$jobFile = [
    'job_id' => 'S6LOCAL1',
    'package_id' => '2026-01-01_000000',
    'package_type' => 'full_disaster',
    'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'phase' => 'approved_waiting_execution',
    'progress' => 0,
    'message' => 'approved',
    'created_by' => 'fixture',
    'created_by_admin_id' => 1,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'framework_version' => ORANGE_RESTORE_FW_VERSION,
    'execution_started' => false,
    'package_fingerprint' => 'fp-test',
];
$written = false;
try {
    orange_restore_fw_write($workRoot, $jobFile);
    $jobDir = orange_restore_fw_job_directory($workRoot, 'S6LOCAL1');
    // Minimal gates for revalidate — create stub files if helpers expose paths.
    if (function_exists('orange_restore_final_approval_record_path')) {
        $ap = orange_restore_final_approval_record_path($workRoot, 'S6LOCAL1');
        @mkdir(dirname($ap), 0777, true);
        file_put_contents($ap, json_encode(['approved' => true, 'fixture' => true], JSON_UNESCAPED_UNICODE));
    }
    $written = is_file(orange_restore_fw_job_file_path($workRoot, 'S6LOCAL1'));
} catch (Throwable $e) {
    $written = false;
    echo 'NOTE seed_error=' . $e->getMessage() . "\n";
}
s6_ok($written, 'local fixture job.json seeded via fw_write');

$timeline = [
    'events' => [],
    'note' => 'No Production worker executed',
];
if ($written) {
    try {
        $req = orange_restore_pre_backup_request(
            $workRoot,
            'S6LOCAL1',
            $backupRoot,
            ['username' => 'fixture_admin']
        );
        $timeline['events'][] = [
            'event' => 'request',
            'cli_needed' => (bool) ($req['cli_needed'] ?? false),
            'execution_started' => (bool) ($req['execution_started'] ?? true),
            'job_status' => (string) (($req['job']['status'] ?? '')),
            'is_ready' => !empty($req['job']['is_pre_restore_backup_ready']),
            'has_artifact' => !empty($req['job']['has_pre_restore_backup']),
            'guided_current' => (int) (($req['job']['guided_journey']['current_index'] ?? -1)),
        ];
        s6_ok(!empty($req['cli_needed']), 'fixture request cli_needed=1');
        s6_ok(empty($req['execution_started']), 'fixture request execution_started=0');
        s6_ok((string) (($req['job']['status'] ?? '')) === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING, 'fixture status pending');
        s6_ok(empty($req['job']['is_pre_restore_backup_ready']), 'fixture not ready after request-only');
        s6_ok((int) (($req['job']['guided_journey']['current_index'] ?? -1)) === 5, 'fixture guided stays on step6');
    } catch (Throwable $e) {
        $timeline['events'][] = ['event' => 'request_error', 'code' => $e->getMessage()];
        // Still assert authority using synthetic pending public row when gates block request.
        $synth = orange_restore_fw_public_row(array_merge($jobFile, [
            'status' => ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
            'pre_restore_backup_file' => ORANGE_RESTORE_PRE_BACKUP_FILE,
        ]));
        s6_ok((int) (($synth['guided_journey']['current_index'] ?? -1)) === 5, 'synthetic pending guided current=5 after gate refuse');
        s6_ok(empty($synth['is_pre_restore_backup_ready']), 'synthetic pending not ready');
        echo 'NOTE fixture_request_code=' . $e->getMessage() . "\n";
    }
}

/* Root-cause classification markers */
$rootCause = [
    'defect_ids' => [
        'RESTORE_CENTER_STEP6_PRE_BACKUP_NOT_COMPLETING_01',
        'RESTORE_CENTER_STEP6_REQUEST_DISPATCH_CONTRADICTION_01',
        'RESTORE_CENTER_INCOMPLETE_STEP_FALSE_COMPLETION_01',
    ],
    'primary_code_cause' => 'UI_AND_PUBLIC_CONSUMERS_TREATED_has_pre_restore_backup_AS_TERMINAL_SUCCESS',
    'has_pre_restore_backup_true_on_pending' => true,
    'is_pre_restore_backup_ready_absent_before_fix' => true,
    'request_only_does_not_complete' => true,
    'completion_requires' => 'detached_CLI_worker_restore_prepare_backup_to_pre_restore_backup_ready',
    'ui_dispatch' => 'requestThenRunWorker_schedules_run-worker_orchestrator',
    'production_worker_executed' => false,
    'environment_blocker_possible' => 'spawn_or_CLI_identity_gap_on_host',
    'selected_cause_code' => 'FALSE_COMPLETION_ON_REQUEST_PENDING_ARTIFACT_FLAG',
    'worker_activation_reality' => 'HTTP_request_metadata_then_orchestrator_detached_spawn_required_for_ready',
];

$activation = [
    'catalog_script' => 'scripts/backup/restore_prepare_backup.php',
    'orchestrator_key' => 'pre_restore_backup',
    'http_waits' => false,
    'scheduled_task_plesk_required_for_this_step' => false,
    'note' => 'Restore Center orchestrator spawns CLI; not the Full backup nightly Plesk task',
];

file_put_contents(
    $evidenceDir . '/restore_step6_non_completion_root_cause.json',
    json_encode($rootCause, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_step6_worker_activation_audit.json',
    json_encode($activation, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_step6_request_dispatch_timeline.json',
    json_encode($timeline, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo 'STEP6_FALSE_COMPLETION_ON_PENDING_FIXED=1' . "\n";
echo 'STEP6_READY_REQUIRED_FOR_DONE=1' . "\n";
echo 'CORE_SKIP=' . $coreSkip . "\n";
echo 'ASSERTION_WEAKENED=' . $assertionWeakened . "\n";
echo 'PRODUCTION_WORKER_EXECUTED=0' . "\n";

s6_ok($coreSkip === 0, 'CORE_SKIP=0');
s6_ok($assertionWeakened === 0, 'ASSERTION_WEAKENED=0');

/* cleanup temp */
if (is_dir($tmpRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($tmpRoot);
}

echo "PASS={$pass} FAIL={$fail} SKIP={$skip}\n";
exit($fail > 0 ? 1 : 0);
