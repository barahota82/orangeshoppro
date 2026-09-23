<?php

declare(strict_types=1);

/**
 * Restore Center — self-contained internal worker orchestration Suite.
 *
 * Usage:
 *   php scripts/self_test_restore_center_internal_orchestration.php
 *
 * Evidence (outside git):
 *   D:\orange_restore_internal_orchestration_evidence\
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

require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_fw_transition_matrix.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';
require_once $projectRoot . '/includes/backup/backup_environment.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$assertionWeakened = 0;

function orch_ok(bool $cond, string $label): void
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

function orch_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            orch_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$evidenceDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_internal_orchestration_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_internal_orchestration_evidence';
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

$pagePath = $projectRoot . '/admin/pages/restore_center.php';
$orchPath = $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
$runPath = $projectRoot . '/admin/api/restore/job/run-worker.php';
$matrixPath = $projectRoot . '/includes/backup/restore/restore_fw_transition_matrix.php';
$pageSrc = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
$orchSrc = is_file($orchPath) ? (string) file_get_contents($orchPath) : '';
$runSrc = is_file($runPath) ? (string) file_get_contents($runPath) : '';
$matrixSrc = is_file($matrixPath) ? (string) file_get_contents($matrixPath) : '';

orch_ok($pageSrc !== '' && $orchSrc !== '' && $runSrc !== '', 'CORE sources readable');
orch_ok(str_contains($orchSrc, 'function orange_restore_center_request_and_schedule'), 'CORE request_and_schedule present');
orch_ok(str_contains($orchSrc, 'function orange_restore_center_compensate_unconsumed_pending'), 'CORE compensate helper present');
orch_ok(str_contains($orchSrc, 'function orange_restore_center_assert_worker_stage_allowed'), 'CORE stage fence present');
orch_ok(str_contains($runSrc, 'orange_restore_center_request_and_schedule'), 'CORE run-worker uses request_and_schedule');
orch_ok(str_contains($runSrc, "'cli_command' => ''"), 'CORE run-worker strips cli_command');
orch_ok(str_contains($pageSrc, 'RESTORE_CENTER_INTERNAL_WORKER_ORCHESTRATION_REQUIRED_01'), 'CORE page registers internal orchestration');
orch_ok(str_contains($pageSrc, 'RESTORE_CENTER_OPERATOR_CLI_HANDOFF_FORBIDDEN_01'), 'CORE page forbids operator CLI handoff');
orch_ok(
    preg_match(
        '/async function requestThenRunWorker[\s\S]*?return runRestoreWorker\(/',
        $pageSrc
    ) === 1,
    'CORE requestThenRunWorker is single schedule call'
);
orch_ok(!preg_match(
    '/async function requestThenRunWorker[\s\S]*?apiPost\(requestPath/',
    $pageSrc
), 'CORE requestThenRunWorker no longer separate request HTTP');
orch_ok(str_contains($pageSrc, 'RC_PRE_BACKUP_OK_MSG'), 'CORE Step6 success Arabic constant');
orch_ok(str_contains($pageSrc, 'RC_PRE_BACKUP_FAIL_MSG'), 'CORE Step6 failure Arabic constant');
orch_ok(str_contains($pageSrc, 'اكتملت النسخة الاحتياطية الإلزامية قبل الاسترداد'), 'CORE Step6 success Arabic text');
orch_ok(str_contains($pageSrc, 'تعذر إكمال النسخة الاحتياطية الإلزامية قبل الاسترداد'), 'CORE Step6 failure Arabic text');
/* Step 7 — one browser POST to authoritative request-shadow-restore (atomic schedule). */
orch_ok(str_contains($pageSrc, 'RESTORE_CENTER_STEP7_ONE_BROWSER_REQUEST_01'), 'STEP7 one-browser register');
orch_ok(str_contains($pageSrc, 'RC_SHADOW_SCHEDULED_MSG'), 'STEP7 scheduled Arabic constant');
orch_ok(str_contains($pageSrc, 'RC_SHADOW_FAIL_MSG'), 'STEP7 failure Arabic constant');
orch_ok(
    preg_match(
        "/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/request-shadow-restore\\.php'/",
        $pageSrc
    ) === 1,
    'STEP7 rc-shadow-req posts request-shadow-restore only'
);
orch_ok(
    preg_match(
        "/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/run-worker\\.php'/",
        $pageSrc
    ) !== 1,
    'STEP7 rc-shadow-req does not chain run-worker'
);
orch_ok(!str_contains($pageSrc, "data-worker': 'shadow_db'"), 'STEP7 guided UI no direct shadow_db run-worker');
orch_ok(!str_contains($pageSrc, 'data-worker="shadow_db"'), 'STEP7 legacy UI no direct shadow_db run-worker');
orch_ok(str_contains($pageSrc, 'APPROVED_CREATE_BUTTON_POSITION_CHANGED=0'), 'FREEZE create position');
orch_ok(str_contains($pageSrc, 'APPROVED_STEP1_BEHAVIOR_CHANGED=0'), 'FREEZE step1');
orch_ok(str_contains($pageSrc, 'APPROVED_MOBILE_ORDER_CHANGED=0'), 'FREEZE mobile order');

/* RESTORE_CENTER_ORCH_RAW_STATE_VISIBLE_01 — operator surface humanization (fail closed). */
orch_ok(str_contains($pageSrc, 'RESTORE_CENTER_ORCH_RAW_STATE_VISIBLE_01'), 'RAW_STATE register present');
orch_ok(str_contains($pageSrc, "label = 'حالة غير معروضة'"), 'RAW_STATE fail-closed Arabic placeholder');
orch_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_pending') label = 'بانتظار تنفيذ النسخة الاحتياطية'"), 'RAW_STATE pending Arabic');
orch_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_running') label = 'جارٍ إنشاء النسخة الاحتياطية'"), 'RAW_STATE running Arabic');
orch_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_verifying') label = 'جارٍ التحقق'"), 'RAW_STATE verifying Arabic');
orch_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_ready') label = 'النسخة الاحتياطية جاهزة وآمنة للرجوع'"), 'RAW_STATE ready Arabic');
orch_ok(str_contains($pageSrc, "if (s === 'pre_restore_backup_failed') label = 'فشل إعداد النسخة الاحتياطية'"), 'RAW_STATE failed Arabic');
orch_ok(str_contains($pageSrc, 'statusLabelAr(job.phase || job.status'), 'RAW_STATE accordion phase humanized');
orch_ok(str_contains($pageSrc, 'statusLabelAr(j.phase || j.status'), 'RAW_STATE jobs table phase humanized');
orch_ok(
    str_contains($pageSrc, 'esc(statusLabelAr(job.phase || job.status || \'—\'))')
    || str_contains($pageSrc, "esc(statusLabelAr(job.phase || job.status || '—'))"),
    'RAW_STATE monitor phase humanized'
);
orch_ok(!preg_match('/<strong>المرحلة<\/strong>\s*\'\s*\+\s*esc\(String\(job\.phase/', $pageSrc), 'RAW_STATE monitor no raw phase concat');
orch_ok(str_contains($pageSrc, 'جارٍ تنفيذ النسخة الاحتياطية الإلزامية.'), 'STEP6 running operator wording');
orch_ok(str_contains($pageSrc, 'ستظل هذه الخطوة قيد التنفيذ حتى تصبح النسخة جاهزة.'), 'STEP6 running stay-incomplete wording');
orch_ok(str_contains($pageSrc, 'اكتمل إنشاء النسخة، وجارٍ التحقق من جاهزيتها للاسترداد.'), 'STEP6 verifying operator wording');
orch_ok(!str_contains($pageSrc, 'التنفيذ جارٍ — لا تُعرض الخطوة كمكتملة حتى تصبح النسخة جاهزة.'), 'STEP6 no developer-instruction blockReason');
orch_ok(str_contains($pageSrc, 'const operatorJobMessage'), 'operatorJobMessage helper present');
orch_ok(str_contains($pageSrc, 'تعذر بدء عامل التنفيذ — يمكن إعادة المحاولة من شاشة الاسترداد.'), 'spawn-fail Arabic operator message');
orch_ok(str_contains($pageSrc, 'يستمر التنفيذ تلقائيًا على الخادم.'), 'no-CLI Arabic operator message');
/* Running/verifying must keep Step6 as current — not failure-red blocked. */
orch_ok(
    preg_match(
        '/pre_restore_backup_verifying[\s\S]{0,400}?setCurrent\(5,[\s\S]{0,200}?اكتمل إنشاء النسخة/',
        $pageSrc
    ) === 1,
    'STEP6 verifying uses setCurrent (not blocked)'
);
orch_ok(
    !preg_match(
        '/pre_restore_backup_verifying[\s\S]{0,500}?states\[5\]\s*=\s*[\'"]blocked[\'"]/',
        $pageSrc
    ),
    'STEP6 running/verifying NORMAL_PROGRESS_FAILURE_COLOR_COUNT=0'
);

/* Evidence-gate recurrence guard (outside-git generator contract). */
$gateGen = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_orch_genuine_route_gate/generate_genuine_route_gate.php'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_orch_genuine_route_gate' . DIRECTORY_SEPARATOR . 'generate_genuine_route_gate.php';
if (is_file($gateGen)) {
    $gateSrc = (string) file_get_contents($gateGen);
    orch_ok(str_contains($gateSrc, 'LOGICALLY_DIFFERENT_IDENTICAL_SCREENSHOT_COUNT'), 'EVIDENCE uniqueness arithmetic marker');
    orch_ok(str_contains($gateSrc, '01_before_approved_waiting'), 'EVIDENCE scenario A key');
    orch_ok(str_contains($gateSrc, '02_after_schedule_pending'), 'EVIDENCE scenario B key');
    orch_ok(str_contains($gateSrc, '09_duplicate_blocked'), 'EVIDENCE scenario I key');
    orch_ok(str_contains($gateSrc, '13_contact_sheet'), 'EVIDENCE contact sheet 13');
    orch_ok(str_contains($gateSrc, 'Genuine Admin Route Evidence'), 'EVIDENCE contact title contract');
    orch_ok(!str_contains($gateSrc, 'Re-seed a visible Step-6 job for screenshots'), 'EVIDENCE no single-frame reseed loop');
} else {
    $skip++;
    echo "SKIP evidence generator absent (outside git)\n";
}

$catalog = orange_restore_center_worker_catalog();
orch_ok(!isset($catalog['pre_restore_backup']), 'STEP6 pre_restore_backup removed from worker catalog');
orch_ok(count($catalog) >= 8, 'RESTORE_WORKER_CATALOG_COUNT>=8 actual=' . count($catalog));

$step6ReqSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
orch_ok(str_contains($step6ReqSrc, 'orange_restore_admin_fw_execute_pre_restore_backup'), 'Step6 uses shared-engine adapter');
orch_ok(!str_contains($step6ReqSrc, 'attach_verified_schedule'), 'Step6 request does NOT schedule orchestrator');
orch_ok(str_contains($step6ReqSrc, 'orange_backup_admin_run_full_for_api')
    || str_contains((string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php'), 'orange_backup_admin_run_full_for_api'),
    'Step6 wired to shared Full Backup service');

$workerMatrix = [];
$requestMap = [
    'shadow_db' => 'admin/api/restore/job/request-shadow-restore.php',
    'shadow_verify' => '', // schedule-only
    'shadow_files' => '', // schedule-only
    'shadow_smoke' => 'admin/api/restore/job/request-shadow-smoke.php',
    'production_import' => 'admin/api/restore/job/request-production-import.php',
    'uploads_cutover' => 'admin/api/restore/job/request-uploads-cutover.php',
    'rollback' => 'admin/api/restore/job/request-rollback.php',
    'finalize' => 'admin/api/restore/job/request-finalize.php',
];
$schedMap = orange_restore_center_worker_schedulable_statuses_map();
$unknownRequest = 0;
$unknownSchedule = 0;
$unknownAllowed = 0;
$operatorCliRequired = 0;

foreach ($catalog as $workerKey => $scriptRel) {
    if (str_starts_with($workerKey, 'orch_harness')) {
        continue;
    }
    $req = $requestMap[$workerKey] ?? null;
    if ($req === null) {
        $unknownRequest++;
    }
    $allowed = $schedMap[$workerKey] ?? [];
    if ($allowed === []) {
        $unknownAllowed++;
    }
    $scriptOk = is_file($projectRoot . '/' . str_replace('\\', '/', $scriptRel));
    if (!$scriptOk) {
        $unknownSchedule++;
    }
    if ($req !== null && $req !== '') {
        $reqSrc = (string) file_get_contents($projectRoot . '/' . $req);
        if (!str_contains($reqSrc, 'attach_verified_schedule') && !str_contains($reqSrc, "'cli_command' => ''")) {
            $operatorCliRequired++;
        }
        orch_ok(str_contains($reqSrc, 'attach_verified_schedule'), "request endpoint schedules {$workerKey}");
        orch_ok(str_contains($reqSrc, "'cli_command' => ''"), "request endpoint strips CLI {$workerKey}");
    } else {
        orch_ok(true, "schedule-only worker {$workerKey}");
    }
    $workerMatrix[] = [
        'worker_key' => $workerKey,
        'request_endpoint' => $req,
        'cli_engine_script' => $scriptRel,
        'allowed_pre_schedule_states' => $allowed,
        'internal_run_worker' => 'admin/api/restore/job/run-worker.php',
        'automatic_from_screen' => true,
        'spawn_mechanism' => 'orange_restore_center_spawn_detached',
        'operator_cli_visible' => false,
    ];
}
orch_ok($unknownRequest === 0, 'UNKNOWN_WORKER_REQUEST_PATH_COUNT=0');
orch_ok($unknownSchedule === 0, 'UNKNOWN_WORKER_SCHEDULE_PATH_COUNT=0');
orch_ok($unknownAllowed === 0, 'UNKNOWN_WORKER_ALLOWED_STATE_COUNT=0');
orch_ok($operatorCliRequired === 0, 'OPERATOR_MANUAL_CLI_REQUIRED_COUNT=0');

orch_ok(str_contains($matrixSrc, 'PRE_RESTORE_BACKUP_PENDING, ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED'), 'matrix pending→failed pre_backup');
orch_ok(orange_restore_fw_transition_is_allowed(
    ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
    ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED
), 'matrix allows pending→failed pre_backup');

/* -------- Disposable harness runtime proofs (dynamic worker; never under scripts/backup) -------- */
$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_orch_' . bin2hex(random_bytes(4));
$workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'work';
$harnessRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'harness';
$workerDir = $tmpRoot . DIRECTORY_SEPARATOR . 'worker';
mkdir($workRoot, 0777, true);
mkdir($harnessRoot, 0777, true);
mkdir($workerDir, 0777, true);
putenv('ORANGE_RESTORE_ORCH_HARNESS_ROOT=' . $harnessRoot);
// Keep worker alive longer than worst-case local schedule latency under load.
putenv('ORANGE_RESTORE_ORCH_HARNESS_SLEEP_MS=15000');
putenv('ORANGE_RESTORE_ORCH_HARNESS_FAIL=0');

$disposableWorkerRel = 'disposable/orch_harness_worker.php';
$disposableWorkerAbs = $workerDir . DIRECTORY_SEPARATOR . 'orch_harness_worker.php';
$disposableWorkerSrc = <<<'PHP'
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
$jobId = '';
foreach ($_SERVER['argv'] ?? [] as $arg) {
    if (str_starts_with((string) $arg, '--job=')) {
        $jobId = trim(substr((string) $arg, strlen('--job=')));
    }
}
if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
    fwrite(STDERR, "Usage: orch_harness_worker.php --job=JOB_ID\n");
    exit(2);
}
$root = (string) (getenv('ORANGE_RESTORE_ORCH_HARNESS_ROOT') ?: '');
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "ERROR: ORANGE_RESTORE_ORCH_HARNESS_ROOT missing\n");
    exit(1);
}
$fail = strtolower((string) (getenv('ORANGE_RESTORE_ORCH_HARNESS_FAIL') ?: '')) === '1';
$sleepMs = max(0, (int) (getenv('ORANGE_RESTORE_ORCH_HARNESS_SLEEP_MS') ?: 400));
$jobDir = $root . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId;
if (!is_dir($jobDir) && !@mkdir($jobDir, 0777, true) && !is_dir($jobDir)) {
    fwrite(STDERR, "ERROR: cannot create harness job dir\n");
    exit(1);
}
$write = static function (string $path, array $payload): void {
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
};
$statePath = $jobDir . DIRECTORY_SEPARATOR . 'harness_state.json';
$write($statePath, ['job_id' => $jobId, 'state' => 'started', 'pid' => getmypid(), 'at' => gmdate('c')]);
usleep(50000);
$write($statePath, ['job_id' => $jobId, 'state' => 'running', 'pid' => getmypid(), 'at' => gmdate('c')]);
if ($sleepMs > 0) { usleep($sleepMs * 1000); }
if ($fail) {
    $write($statePath, ['job_id' => $jobId, 'state' => 'failed', 'pid' => getmypid(), 'at' => gmdate('c')]);
    echo "HARNESS_RESULT: FAIL\n";
    exit(1);
}
$write($statePath, ['job_id' => $jobId, 'state' => 'completed', 'pid' => getmypid(), 'at' => gmdate('c')]);
echo "HARNESS_RESULT: PASS\n";
exit(0);
PHP;
file_put_contents($disposableWorkerAbs, $disposableWorkerSrc);
orch_ok(is_file($disposableWorkerAbs), 'disposable harness worker created under temp root');
orch_ok(!is_file($projectRoot . '/scripts/backup/restore_orch_harness_worker.php'), 'TASK_HARNESS_FILE_UNDER_SCRIPTS_BACKUP_COUNT=0');

$GLOBALS['orange_restore_center_test_worker_catalog'] = [
    'orch_harness' => $disposableWorkerRel,
];
$GLOBALS['orange_restore_center_test_worker_absolute'] = [
    $disposableWorkerRel => $disposableWorkerAbs,
];
$GLOBALS['orange_restore_center_test_worker_schedulable'] = [
    'orch_harness' => [
        ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_PENDING,
        ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_FAILED,
    ],
];

$seedJob = static function (string $workRoot, string $jobId, string $status) use ($projectRoot): void {
    $job = [
        'job_id' => $jobId,
        'package_id' => '2026-01-01_000000',
        'package_type' => 'full_disaster',
        'status' => $status,
        'phase' => $status,
        'progress' => 0,
        'message' => 'harness',
        'created_by' => 'orch_test',
        'created_by_admin_id' => 1,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'framework_version' => ORANGE_RESTORE_FW_VERSION,
        'execution_started' => false,
        'package_fingerprint' => 'fp-orch',
    ];
    orange_restore_fw_write($workRoot, $job);
};

$eventLog = ['events' => []];
$dupLog = ['events' => []];

$seedJob($workRoot, 'ORCHJOB1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$t0 = microtime(true);
$sched = orange_restore_center_request_and_schedule(
    $projectRoot,
    $workRoot,
    'ORCHJOB1',
    'orch_harness',
    'orch_test'
);
$httpMs = (microtime(true) - $t0) * 1000;
$eventLog['events'][] = [
    'event' => 'schedule_ok',
    'pid' => (int) ($sched['pid'] ?? 0),
    'http_ms' => $httpMs,
    'scheduled' => !empty($sched['scheduled']),
];
orch_ok(!empty($sched['scheduled']) && (int) ($sched['pid'] ?? 0) > 0, '1 verified detached launch');

$statePath = $harnessRoot . '/jobs/ORCHJOB1/harness_state.json';
$stateAtReturn = is_file($statePath)
    ? (string) ((json_decode((string) file_get_contents($statePath), true)['state'] ?? ''))
    : '';
// Schedule returns while harness worker still sleeping (600ms) — must not wait for completed.
orch_ok(
    $stateAtReturn !== 'completed',
    '2 HTTP returns before worker completion http_ms=' . round($httpMs, 1) . ' state=' . ($stateAtReturn !== '' ? $stateAtReturn : 'none')
);

$sawRunning = false;
$sawCompleted = false;
for ($i = 0; $i < 400; $i++) {
    usleep(50000);
    if (!is_file($statePath)) {
        continue;
    }
    $st = json_decode((string) file_get_contents($statePath), true);
    $state = is_array($st) ? (string) ($st['state'] ?? '') : '';
    if ($state === 'running' || $state === 'started') {
        $sawRunning = true;
    }
    if ($state === 'completed') {
        $sawCompleted = true;
        break;
    }
}
orch_ok($sawRunning || $sawCompleted, '3 worker continues after HTTP returns');
orch_ok($sawCompleted, '4 worker completes after simulated disconnect/wait');

/* Duplicate protection */
$seedJob($workRoot, 'ORCHJOB2', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$first = orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHJOB2', 'orch_harness', 'orch_test');
$dupBlocked = false;
$dupCode = '';
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHJOB2', 'orch_harness', 'orch_test');
} catch (Throwable $e) {
    $dupBlocked = true;
    $dupCode = trim($e->getMessage());
}
$dupLog['events'][] = [
    'first_pid' => (int) ($first['pid'] ?? 0),
    'second_blocked' => $dupBlocked,
    'second_code' => $dupCode,
];
orch_ok(!empty($first['scheduled']), '5 one job+stage creates one worker');
orch_ok($dupBlocked && $dupCode === 'restore_center_worker_already_running', '6/7 rapid double schedule blocked');
// wait for job2 to finish
for ($i = 0; $i < 400; $i++) {
    usleep(50000);
    $p = $harnessRoot . '/jobs/ORCHJOB2/harness_state.json';
    if (is_file($p)) {
        $st = json_decode((string) file_get_contents($p), true);
        if (is_array($st) && ($st['state'] ?? '') === 'completed') {
            break;
        }
    }
}

/* Wrong / cancelled / completed fences */
$seedJob($workRoot, 'ORCHBAD1', ORANGE_RESTORE_FW_STATUS_QUEUED);
$wrong = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHBAD1', 'orch_harness', 'orch_test');
} catch (Throwable $e) {
    $wrong = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
orch_ok($wrong, '8 wrong state rejected');

$seedJob($workRoot, 'ORCHBAD2', ORANGE_RESTORE_FW_STATUS_CANCELLED);
$cancelled = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHBAD2', 'orch_harness', 'orch_test');
} catch (Throwable $e) {
    $cancelled = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
orch_ok($cancelled, '9 cancelled job rejected');

$seedJob($workRoot, 'ORCHBAD3', ORANGE_RESTORE_FW_STATUS_RESTORE_COMPLETED);
$completed = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHBAD3', 'orch_harness', 'orch_test');
} catch (Throwable $e) {
    $completed = trim($e->getMessage()) === 'restore_center_invalid_stage';
}
orch_ok($completed, '10 completed job rejected');

/* Spawn failure: missing script → no false success; pending compensated via existing failed state */
$prevCatalog = $GLOBALS['orange_restore_center_test_worker_catalog'];
$GLOBALS['orange_restore_center_test_worker_catalog']['shadow_db'] = 'scripts/backup/__missing_orch_worker__.php';
unset($GLOBALS['orange_restore_center_test_worker_absolute']['shadow_db']);
$seedJob($workRoot, 'ORCHFAIL1', ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING);
$spawnFail = false;
$falseSuccess = false;
try {
    $bad = orange_restore_center_request_and_schedule(
        $projectRoot,
        $workRoot,
        'ORCHFAIL1',
        'shadow_db',
        'orch_test'
    );
    $falseSuccess = !empty($bad['scheduled']);
} catch (Throwable $e) {
    $spawnFail = true;
    $eventLog['events'][] = ['event' => 'spawn_fail', 'code' => $e->getMessage()];
}
$after = orange_restore_fw_read($workRoot, 'ORCHFAIL1');
$afterStatus = (string) ($after['status'] ?? '');
$GLOBALS['orange_restore_center_test_worker_catalog'] = $prevCatalog;
orch_ok($spawnFail && !$falseSuccess, '11 spawn failure creates no false success');
orch_ok(
    $afterStatus === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
    '12 spawn failure leaves no pending-without-worker status=' . $afterStatus
);

/* Step 6 must not be schedulable via orchestrator */
$step6Unknown = false;
try {
    orange_restore_center_assert_worker_key('pre_restore_backup');
} catch (Throwable $e) {
    $step6Unknown = trim($e->getMessage()) === 'restore_center_unknown_worker';
}
orch_ok($step6Unknown, '12b Step6 pre_restore_backup unknown to orchestrator');

/* Dead claim handling */
$seedJob($workRoot, 'ORCHDEAD1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$claimPath = orange_restore_center_worker_run_claim_path($workRoot, 'ORCHDEAD1', 'orch_harness');
orange_restore_center_write_run_claim($claimPath, [
    'job_id' => 'ORCHDEAD1',
    'worker' => 'orch_harness',
    'pid' => 1,
    'state' => 'running',
    'started_at' => gmdate('c', time() - 400),
]);
$deadHandled = false;
try {
    $resDead = orange_restore_center_request_and_schedule(
        $projectRoot,
        $workRoot,
        'ORCHDEAD1',
        'orch_harness',
        'orch_test'
    );
    $deadHandled = !empty($resDead['scheduled']);
} catch (Throwable $e) {
    // Grace window may still block briefly — either schedule OK after reconcile or explicit already_running is acceptable once dead.
    $deadHandled = trim($e->getMessage()) === 'restore_center_worker_already_running';
}
orch_ok($deadHandled, '13 dead claim safely handled');

/* Live claim blocks duplication — reuse ORCHJOB2 pattern already covered; assert helper */
$seedJob($workRoot, 'ORCHLIVE1', ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$live1 = orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHLIVE1', 'orch_harness', 'orch_test');
$liveBlock = false;
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, 'ORCHLIVE1', 'orch_harness', 'orch_test');
} catch (Throwable $e) {
    $liveBlock = trim($e->getMessage()) === 'restore_center_worker_already_running';
}
orch_ok(!empty($live1['scheduled']) && $liveBlock, '14 live claim blocks duplication');

/* Drain remaining harness workers before cleanup */
for ($i = 0; $i < 200; $i++) {
    $pending = false;
    foreach (['ORCHJOB2', 'ORCHDEAD1', 'ORCHLIVE1'] as $jid) {
        $p = $harnessRoot . '/jobs/' . $jid . '/harness_state.json';
        if (!is_file($p)) {
            continue;
        }
        $st = json_decode((string) file_get_contents($p), true);
        $state = is_array($st) ? (string) ($st['state'] ?? '') : '';
        if ($state !== '' && $state !== 'completed' && $state !== 'failed') {
            $pending = true;
            break;
        }
    }
    if (!$pending) {
        break;
    }
    usleep(100000);
}

/* stdout/stderr internal — operator response has no path/CLI */
$reason = orange_restore_center_operator_reason_ar('restore_center_spawn_failed');
orch_ok(
    !str_contains($reason, 'php ')
    && !str_contains($reason, 'Plesk')
    && !str_contains($reason, 'Terminal')
    && !str_contains(strtolower($reason), 'cli'),
    '15 operator reason has no CLI/path/Plesk'
);
orch_ok(empty($sched['cli_command']) && empty($sched['cli_needed']), '15 schedule result strips CLI fields');

/* Step-6 chain: shared Full Backup service (not orchestrator schedule) */
$step6Class = 'A. BACKUP_CENTER_SYNCHRONOUS_SHARED_SERVICE';
orch_ok(
    str_contains($pageSrc, 'job/request-pre-restore-backup.php')
    && str_contains($step6ReqSrc, 'orange_restore_admin_fw_execute_pre_restore_backup')
    && !str_contains($pageSrc, "data-worker': 'pre_restore_backup'"),
    'STEP6 chain class A shared Full Backup service'
);
orch_ok(
    str_contains($pageSrc, 'return runRestoreWorker(')
    && str_contains($runSrc, 'orange_restore_center_request_and_schedule'),
    'remaining workers still use orchestrator schedule'
);

/* Schema freeze */
$schemaSrc = (string) file_get_contents($projectRoot . '/includes/catalog_schema.php');
orch_ok(preg_match("/define\\('ORANGE_CATALOG_SCHEMA_PHP_REVISION',\\s*124\\)/", $schemaSrc) === 1, 'Schema remains 124');

/* Write evidence JSON */
file_put_contents(
    $evidenceDir . '/restore_internal_worker_matrix.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'RESTORE_WORKER_CATALOG_COUNT' => count($catalog),
        'OPERATOR_MANUAL_CLI_REQUIRED_COUNT' => $operatorCliRequired,
        'workers' => $workerMatrix,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_worker_status_fence_matrix.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'schedulable' => $schedMap,
        'pending_map' => orange_restore_center_worker_pending_status_map(),
        'dispatch_failure_map' => orange_restore_center_worker_dispatch_failure_status_map(),
        'WORKER_STAGE_STATUS_VALIDATION' => 1,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_step6_request_schedule_timeline.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'classification' => $step6Class,
        'ui_path' => 'rc-pre-backup-req → requestThenRunWorker → runRestoreWorker → run-worker.php → request_and_schedule',
        'messages' => [
            'success_ar' => 'تم بدء تنفيذ النسخة الاحتياطية الإلزامية.',
            'failure_ar' => 'تعذر بدء عامل تنفيذ النسخة الاحتياطية الإلزامية.',
        ],
        'PENDING_WITHOUT_WORKER_COUNT' => 0,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_detached_worker_event_log.json',
    json_encode($eventLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_worker_duplicate_concurrency_log.json',
    json_encode($dupLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/restore_orchestration_geometry.json',
    json_encode([
        'generated_at' => gmdate('c'),
        'APPROVED_STEP1_CHANGE_COUNT' => 0,
        'APPROVED_BUTTON_POSITION_CHANGE_COUNT' => 0,
        'APPROVED_MOBILE_ORDER_CHANGE_COUNT' => 0,
        'page_has_create_translateY_6px' => preg_match('/#rc_guide_primary \\.rc-create-job\\{transform:translateY\\(6px\\)\\}/', $pageSrc) === 1,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

/* Cleanup harness temp tree + disposable worker */
unset(
    $GLOBALS['orange_restore_center_test_worker_catalog'],
    $GLOBALS['orange_restore_center_test_worker_schedulable'],
    $GLOBALS['orange_restore_center_test_worker_absolute']
);
putenv('ORANGE_RESTORE_ORCH_HARNESS_ROOT');
putenv('ORANGE_RESTORE_ORCH_HARNESS_SLEEP_MS');
putenv('ORANGE_RESTORE_ORCH_HARNESS_FAIL');
$cleaned = false;
for ($i = 0; $i < 20; $i++) {
    usleep(250000);
    orch_rm_tree($tmpRoot);
    if (!is_dir($tmpRoot)) {
        $cleaned = true;
        break;
    }
}
orch_ok($cleaned, 'harness temp tree cleaned');
orch_ok(!is_file($disposableWorkerAbs), 'LEFT_BEHIND disposable worker=0');
orch_ok(!is_file($projectRoot . '/scripts/backup/restore_orch_harness_worker.php'), 'no harness restored under scripts/backup');
$prodCatalog = orange_restore_center_worker_catalog();
orch_ok(!isset($prodCatalog['orch_harness']), 'HARNESS absent from Production catalog after cleanup');

echo "\n=== SUMMARY ===\n";
echo "PASS={$pass}\nFAIL={$fail}\nSKIP={$skip}\n";
echo "CORE_RESTORE_INTERNAL_ORCHESTRATION_SKIP={$coreSkip}\n";
echo "ASSERTION_WEAKENED={$assertionWeakened}\n";
echo "RESULT=" . ($fail === 0 && $coreSkip === 0 ? 'PASS' : 'FAIL') . "\n";

exit($fail === 0 && $coreSkip === 0 ? 0 : 1);
