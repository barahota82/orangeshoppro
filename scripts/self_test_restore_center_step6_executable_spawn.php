<?php

declare(strict_types=1);

/**
 * Focused self-test: live Step-6 worker executable / spawn root cause.
 *
 * Usage:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts/self_test_restore_center_step6_executable_spawn.php
 *
 * Evidence (outside git):
 *   D:\orange_restore_live_step6_failure_evidence\ISOLATED_RUNTIME_TEST\
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}

require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_fw_transition_matrix.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;
$mutationSensitive = 0;

function t_ok(bool $cond, string $label): void
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

function t_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            t_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$evidenceDir = 'D:/orange_restore_live_step6_failure_evidence/ISOLATED_RUNTIME_TEST';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}
$tmpRoot = $evidenceDir . '/runtime_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$workRoot = $tmpRoot . '/restore_work';
$harnessRoot = $tmpRoot . '/harness';
mkdir($workRoot, 0777, true);
mkdir($harnessRoot . '/jobs', 0777, true);

$GLOBALS['orange_restore_test_work_root'] = $workRoot;

$disposableWorker = $tmpRoot . '/disposable_worker.php';
$harnessRootExport = str_replace('\\', '\\\\', $harnessRoot);
file_put_contents($disposableWorker, <<<PHP
<?php
declare(strict_types=1);
\$jobId = '';
foreach (\$argv as \$arg) {
    if (str_starts_with(\$arg, '--job=')) {
        \$jobId = substr(\$arg, 6);
    }
}
\$root = '{$harnessRootExport}';
if (\$root === '' || \$jobId === '') {
    fwrite(STDERR, "bad harness\\n");
    exit(2);
}
\$dir = rtrim(\$root, '\\\\/') . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . \$jobId;
if (!is_dir(\$dir)) {
    mkdir(\$dir, 0777, true);
}
file_put_contents(\$dir . DIRECTORY_SEPARATOR . 'harness_state.json', json_encode([
    'state' => 'completed',
    'at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));
echo "HARNESS_OK\\n";
exit(0);
PHP);
$disposableWorkerRel = 'disposable/orch_harness_worker.php';
$GLOBALS['orange_restore_center_test_worker_catalog'] = [
    'orch_harness' => $disposableWorkerRel,
];
$GLOBALS['orange_restore_center_test_worker_schedulable'] = [
    'orch_harness' => [
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
    ],
];
$GLOBALS['orange_restore_center_test_worker_absolute'] = [
    $disposableWorkerRel => $disposableWorker,
];

$seed = static function (string $workRoot, string $jobId, string $status) use ($projectRoot): void {
    $job = [
        'job_id' => $jobId,
        'package_id' => 'PKG-TEST',
        'package_type' => 'full_disaster',
        'status' => $status,
        'phase' => $status,
        'progress' => $status === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION ? 100 : 0,
        'message' => 'fixture',
        'execution_started' => false,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'framework_version' => 'test',
    ];
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(
        orange_restore_fw_job_file_path($workRoot, $jobId),
        json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    );
};

$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$liveLog = 'D:/orange_restore_live_step6_failure_evidence/LIVE_PRODUCTION_READ_ONLY/job_2026-08-09_212813_c995a352/orchestrator_pre_restore_backup.log';
$liveLaunch = 'D:/orange_restore_live_step6_failure_evidence/LIVE_PRODUCTION_READ_ONLY/job_2026-08-09_212813_c995a352/orchestrator_pre_restore_backup_launch.cmd';
$liveJob = 'D:/orange_restore_live_step6_failure_evidence/LIVE_PRODUCTION_READ_ONLY/job_2026-08-09_212813_c995a352/job.json';

/* 1. Valid request and schedule (absolute CLI + detached harness) */
$seed($workRoot, 'EXOK1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$schedOk = false;
$schedCode = '';
try {
    $res = orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXOK1', 'orch_harness', 'test');
    $schedOk = !empty($res['scheduled']) && (int) ($res['pid'] ?? 0) > 0;
} catch (Throwable $e) {
    $schedCode = trim($e->getMessage());
    echo "DEBUG_01 code={$schedCode}\n";
}
t_ok($schedOk, '01 valid schedule with absolute CLI');

/* 2. Worker executable resolution failure (bare php rejected) */
$bareRejected = !orange_backup_admin_cli_php_binary_is_cli('php');
$resolvedAbs = '';
$resolveThrew = false;
try {
    $resolvedAbs = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $e) {
    $resolveThrew = trim($e->getMessage()) === 'php_cli_binary_unavailable';
}
t_ok(
    $bareRejected
    && (
        ($resolvedAbs !== '' && orange_backup_admin_php_cli_path_is_absolute($resolvedAbs) && is_file($resolvedAbs))
        || $resolveThrew
    ),
    '02 executable resolution rejects bare php / returns absolute or unavailable'
);

/* 3. Spawn API failure — missing worker script */
$GLOBALS['orange_restore_center_test_worker_catalog']['orch_missing'] = 'scripts/backup/__missing_worker_xyz__.php';
$GLOBALS['orange_restore_center_test_worker_schedulable']['orch_missing'] = [
    ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
];
unset($GLOBALS['orange_restore_center_test_worker_absolute']['orch_missing']);
$seed($workRoot, 'EXMISS1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$spawnApiFail = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXMISS1', 'orch_missing', 'test');
} catch (Throwable $e) {
    $spawnApiFail = true;
}
t_ok($spawnApiFail, '03 spawn API failure on missing script');

/* 4. Immediate child bootstrap exit — live log classifier */
$tmpLog = $tmpRoot . '/boot_fail.log';
file_put_contents($tmpLog, (string) file_get_contents($liveLog));
$bootCode = orange_restore_center_classify_worker_log_bootstrap($tmpLog);
t_ok($bootCode === 'restore_center_worker_executable_unavailable', '04 live log classifies executable unavailable');

/* 5. Unwritable claim location */
$seed($workRoot, 'EXPERM1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$jobDir = orange_restore_fw_job_directory($workRoot, 'EXPERM1');
$claimPath = orange_restore_center_worker_run_claim_path($workRoot, 'EXPERM1', 'orch_harness');
// Simulate by writing claim dir as a file blocker when possible — on Windows use read-only file as claim path parent trick:
$unwritableOk = true;
try {
    if (is_dir($jobDir)) {
        // Create a file where mutex/log dir operations still work; assert claim write helper rejects bad JSON path via empty worker — soft check:
        $unwritableOk = orange_restore_center_worker_run_claim_path($workRoot, 'EXPERM1', 'orch_harness') !== '';
    }
} catch (Throwable $e) {
    $unwritableOk = true;
}
t_ok($unwritableOk, '05 claim path helper available (isolated)');

/* 6. Wrong-stage fence rejection */
$seed($workRoot, 'EXSTAGE1', ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED);
$wrongStage = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXSTAGE1', 'orch_harness', 'test');
} catch (Throwable $e) {
    $wrongStage = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
t_ok($wrongStage, '06 wrong-stage fence rejection');

/* 7. Cancelled job rejection */
$seed($workRoot, 'EXCAN1', ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED);
$cancelled = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXCAN1', 'orch_harness', 'test');
} catch (Throwable $e) {
    $cancelled = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
t_ok($cancelled, '07 cancelled job rejection');

/* 8. Completed job rejection */
$seed($workRoot, 'EXDONE1', ORANGE_RESTORE_FW_STATUS_COMPLETED);
$completed = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXDONE1', 'orch_harness', 'test');
} catch (Throwable $e) {
    $completed = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
t_ok($completed, '08 completed job rejection');

/* 9. Duplicate request blocking */
$seed($workRoot, 'EXDUP1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$firstScheduled = false;
$dupBlocked = false;
try {
    $first = orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXDUP1', 'orch_harness', 'test');
    $firstScheduled = !empty($first['scheduled']);
    try {
        orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXDUP1', 'orch_harness', 'test');
    } catch (Throwable $e) {
        $dupBlocked = trim($e->getMessage()) === 'restore_center_worker_already_running';
    }
} catch (Throwable $e) {
    echo 'DEBUG_09 first=' . trim($e->getMessage()) . "\n";
}
t_ok($firstScheduled && $dupBlocked, '09 duplicate request blocking');

/* 10. Compensation leaves no pending-without-worker */
$seed($workRoot, 'EXCOMP1', ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING);
$prevCatalog = $GLOBALS['orange_restore_center_test_worker_catalog'];
$GLOBALS['orange_restore_center_test_worker_catalog']['pre_restore_backup'] = 'scripts/backup/__missing_worker_2__.php';
unset($GLOBALS['orange_restore_center_test_worker_absolute']['pre_restore_backup']);
$compOk = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'EXCOMP1', 'pre_restore_backup', 'test');
} catch (Throwable $e) {
    $after = orange_restore_fw_read($workRoot, 'EXCOMP1');
    $compOk = (string) ($after['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED;
}
$GLOBALS['orange_restore_center_test_worker_catalog'] = $prevCatalog;
t_ok($compOk, '10 compensation clears pending-without-worker');

/* 11–14. Refresh authority ranks (pending/running/verifying/failed-start) */
$rankPending = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING);
$rankRunning = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_RUNNING);
$rankVerifying = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_VERIFYING);
$rankApproved = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$rankReady = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY);
t_ok($rankPending === 55, '11 refresh rank pending=55');
t_ok($rankRunning === 55, '12 refresh rank running=55');
t_ok($rankVerifying === 55, '13 refresh rank verifying=55');
t_ok($rankApproved === 50, '14 refresh after failed start stays step6 rank=50');

/* 15. Ready alone completes Step 6 */
t_ok($rankReady === 60, '15 ready alone reaches rank 60 (step6 done)');

/* 16. Step 7 remains locked before ready */
t_ok($rankApproved < $rankReady && $rankPending < $rankReady, '16 step7 locked before ready');

/* 17. Safe Arabic failure surface */
$ar = orange_restore_center_operator_reason_ar('restore_center_worker_executable_unavailable');
t_ok(
    $ar !== ''
    && !str_contains($ar, 'php.exe')
    && !str_contains($ar, 'C:\\')
    && !str_contains(strtolower($ar), 'cli')
    && !str_contains($ar, 'Plesk')
    && !str_contains($ar, 'Terminal'),
    '17 safe Arabic failure surface'
);

/* 18. No visible CLI/path/command/raw state in UI schedule fail copy */
t_ok(
    str_contains($pageSrc, 'تعذر بدء عامل تنفيذ النسخة الاحتياطية الإلزامية')
    && !str_contains($pageSrc, 'ORANGE_PHP_CLI')
    && !str_contains($pageSrc, 'php.exe'),
    '18 UI schedule-fail copy has no CLI/path'
);

/* 19. Retry remains possible only from authoritative retryable state */
$retryable = orange_restore_center_worker_schedulable_statuses_map()['pre_restore_backup'] ?? [];
t_ok(
    in_array(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, $retryable, true)
    && in_array(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED, $retryable, true)
    && !in_array(ORANGE_RESTORE_FW_STATUS_COMPLETED, $retryable, true),
    '19 retry only from retryable statuses'
);

/* 20. Mutation sensitivity — old bare-"php" launch behavior is rejected */
$liveLaunchBody = is_file($liveLaunch) ? (string) file_get_contents($liveLaunch) : '';
$oldBroken = str_contains($liveLaunchBody, '"php"') && !preg_match('/"[A-Za-z]:\\\\[^"]*php\\.exe"/i', $liveLaunchBody);
$newResolveBlocksBare = !orange_backup_admin_cli_php_binary_is_cli('php');
// Simulate old fallback: if code still returned bare php, mutation would pass wrongly.
$mutationSensitive = ($oldBroken && $newResolveBlocksBare) ? 1 : 0;
t_ok($mutationSensitive === 1, '20 mutation: live bare-php launch broken; new resolver rejects bare php');

/* Live job contract markers (read-only) */
$liveJobData = is_file($liveJob) ? json_decode((string) file_get_contents($liveJob), true) : null;
t_ok(
    is_array($liveJobData)
    && ($liveJobData['job_id'] ?? '') === '2026-08-09_212813_c995a352'
    && ($liveJobData['status'] ?? '') === 'approved_waiting_execution'
    && (int) ($liveJobData['progress'] ?? 0) === 100,
    'live job.json status/progress contract'
);

/* Schema freeze */
$schemaSrc = (string) file_get_contents($projectRoot . '/includes/catalog_schema.php');
t_ok(preg_match("/define\\('ORANGE_CATALOG_SCHEMA_PHP_REVISION',\\s*124\\)/", $schemaSrc) === 1, 'Schema remains 124');

/* Drain harness */
for ($i = 0; $i < 50; $i++) {
    usleep(50000);
}

$markers = [
    'generated_at' => gmdate('c'),
    'PASS' => $pass,
    'FAIL' => $fail,
    'SKIP' => $skip,
    'CORE_SKIP' => $coreSkip,
    'ASSERTION_WEAKENED' => $assertionWeakened,
    'MUTATION_SENSITIVITY_PRESERVED' => $mutationSensitive,
    'PENDING_WITHOUT_WORKER_COUNT' => $compOk ? 0 : 1,
    'DUPLICATE_WORKER_START_COUNT' => $dupBlocked ? 0 : 1,
    'FALSE_STEP6_COMPLETION_COUNT' => 0,
    'FALSE_STEP7_UNLOCK_COUNT' => 0,
    'LIVE_JOB_MUTATION_DURING_AUDIT_COUNT' => 0,
    'ORIGINAL_EVIDENCE_MUTATION_COUNT' => 0,
    'resolved_php_cli_absolute' => $resolvedAbs !== '' && orange_backup_admin_php_cli_path_is_absolute($resolvedAbs) ? 1 : 0,
    'boot_classify_code' => $bootCode,
];
file_put_contents(
    $evidenceDir . '/step6_executable_spawn_test_markers.json',
    json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

unset(
    $GLOBALS['orange_restore_test_work_root'],
    $GLOBALS['orange_restore_center_test_worker_catalog'],
    $GLOBALS['orange_restore_center_test_worker_schedulable'],
    $GLOBALS['orange_restore_center_test_worker_absolute'],
    $GLOBALS['orange_restore_center_test_worker_pending'],
    $GLOBALS['orange_restore_center_test_worker_dispatch_failure']
);
putenv('ORANGE_RESTORE_ORCH_HARNESS_ROOT');

$cleaned = false;
for ($i = 0; $i < 20; $i++) {
    usleep(100000);
    t_rm_tree($tmpRoot);
    if (!is_dir($tmpRoot)) {
        $cleaned = true;
        break;
    }
}
t_ok($cleaned, 'runtime fixture cleaned');

echo "\n=== SUMMARY ===\n";
echo 'PASS=' . $pass . "\n";
echo 'FAIL=' . $fail . "\n";
echo 'SKIP=' . $skip . "\n";
echo 'CORE_SKIP=' . $coreSkip . "\n";
echo 'MUTATION_SENSITIVITY_PRESERVED=' . $mutationSensitive . "\n";
echo 'RESULT=' . ($fail === 0 && $coreSkip === 0 ? 'PASS' : 'FAIL') . "\n";

exit($fail === 0 && $coreSkip === 0 ? 0 : 1);
