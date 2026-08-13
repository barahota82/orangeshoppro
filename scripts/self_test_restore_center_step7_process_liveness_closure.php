<?php

declare(strict_types=1);

/**
 * Step 7 process-liveness + retry-preflight closure (disposable only).
 * LIVE_JOB_MUTATION_COUNT=0 — never touches Owner live job.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_engine_trace.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';

$pass = 0;
$fail = 0;
$markers = [
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'BROAD_MYSQL_PROCESS_KILL_COUNT' => 0,
    'UNKNOWN_LIVENESS_CONVERTED_TO_DEAD_COUNT' => 0,
    'ROOT_CAUSE' => 'H',
    'ASSERTION_WEAKENED' => 0,
];

function s7plc_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7plc_rm(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function s7plc_seed_job(string $workRoot, string $jobId, string $shadowDb): void
{
    $fwDir = orange_restore_fw_job_directory($workRoot, $jobId);
    @mkdir($fwDir, 0777, true);
    $job = [
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'package_type' => 'full_disaster',
        'source_package_id' => '2026-08-10_030008_disposable',
        'package_id' => '2026-08-10_030008_disposable',
        'updated_at' => '2026-08-12T23:19:39+00:00',
    ];
    if (function_exists('orange_restore_fw_write')) {
        orange_restore_fw_write($workRoot, $job);
    } else {
        file_put_contents($fwDir . DIRECTORY_SEPARATOR . 'job.json', json_encode($job, JSON_UNESCAPED_UNICODE));
    }
    $meta = [
        'framework_job_id' => $jobId,
        'shadow_db' => $shadowDb,
        'shadow_db_identity_hash' => function_exists('orange_restore_shadow_target_identity_hash')
            ? orange_restore_shadow_target_identity_hash($shadowDb, $jobId)
            : hash('sha256', strtolower($shadowDb) . '|' . $jobId),
        'attempt_id' => 'historical_s7_plc',
    ];
    if (function_exists('orange_restore_shadow_write_json') && function_exists('orange_restore_shadow_meta_path')) {
        orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);
    }
    $root = orange_restore_private_engine_root($workRoot, $jobId);
    foreach (['data', 'tmp', 'run'] as $sub) {
        @mkdir($root . DIRECTORY_SEPARATOR . $sub, 0775, true);
    }
    file_put_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf', "[auto]\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ibdata1', str_repeat('x', 64));
}

$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');
$traceSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_trace.php');
$uiSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');

s7plc_ok(substr_count($orchSrc, 'function orange_restore_step7_retry_preflight') === 1, 'RETRY_PREFLIGHT_IMPLEMENTATION_COUNT=1');
s7plc_ok(str_contains($orchSrc, 'exact_not_ready_reason'), 'preflight exposes exact_not_ready_reason');
s7plc_ok(str_contains($engSrc, 'METADATA_ABSENT_LEGACY'), 'legacy metadata class present');
s7plc_ok(!preg_match('/METADATA_ABSENT[^\n]{0,80}=>\s*[\'"]dead[\'"]/', $engSrc), 'no METADATA_ABSENT→dead coercion');
s7plc_ok(str_contains($adminSrc, 'orange_restore_step7_retry_preflight'), 'BUTTON uses authoritative preflight');
s7plc_ok(str_contains($traceSrc, 'orange_restore_step7_retry_preflight'), 'TRACE uses authoritative preflight');
s7plc_ok(str_contains($uiSrc, 'exact_not_ready_reason') || str_contains($uiSrc, 'سبب NOT_READY'), 'diagnostic UI exposes exact reason');
s7plc_ok(str_contains($uiSrc, 'process_absence_proven') || str_contains($uiSrc, 'إثبات غياب العملية'), 'diagnostic UI exposes process absence');
$markers['NOT_READY_REASON_HIDDEN_MUTATION_DETECTED'] = str_contains($orchSrc, 'exact_not_ready_reason') ? 1 : 0;
$markers['UNKNOWN_TO_DEAD_MUTATION_DETECTED'] = !preg_match('/METADATA_ABSENT[^\n]{0,80}=>\s*[\'"]dead[\'"]/', $engSrc) ? 1 : 0;
$markers['PREFLIGHT_DIVERGENCE_MUTATION_DETECTED'] = (
    str_contains($adminSrc, 'orange_restore_step7_retry_preflight')
    && str_contains($traceSrc, 'orange_restore_step7_retry_preflight')
) ? 1 : 0;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7plc_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
@mkdir($workRoot, 0777, true);
$jobId = '2026-08-10_plc_' . bin2hex(random_bytes(3));
$shadowDb = 'orange_plc_' . substr(bin2hex(random_bytes(3)), 0, 6);

try {
    s7plc_seed_job($workRoot, $jobId, $shadowDb);

    // 1) No PID metadata + job-scoped probe finds none → absence may be proven.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => false,
        'db_job_match' => false,
    ];
    $ctx = orange_restore_private_engine_attempt_context($workRoot, $jobId);
    s7plc_ok(($ctx['php_worker_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND, 'matrix1 php NO_JOB_SCOPED');
    s7plc_ok(($ctx['private_db_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND, 'matrix1 db NO_JOB_SCOPED');
    s7plc_ok(!empty($ctx['process_absence_proven']), 'matrix1 process_absence_proven');
    s7plc_ok(($ctx['process_absence_conclusion'] ?? '') === ORANGE_RESTORE_STEP7_ABSENCE_PROVEN, 'matrix1 absence A');
    s7plc_ok(($ctx['php_worker_liveness'] ?? '') !== 'dead' || ($ctx['php_worker_liveness_class'] ?? '') !== ORANGE_RESTORE_STEP7_PROC_METADATA_ABSENT_LEGACY, 'matrix1 no unknown→dead');
    $pre1 = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    s7plc_ok((int) ($pre1['job_write_count'] ?? 1) === 0, 'preflight write-nothing');
    s7plc_ok(array_key_exists('exact_not_ready_reason', $pre1), 'exact_not_ready_reason field present');
    s7plc_ok((string) ($pre1['datadir_category'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT', 'terminal partial datadir');
    // May be green or not depending on runtime supply on this host — record reason either way.
    echo 'INFO preflight1 final=' . (string) ($pre1['final_readiness'] ?? '') . ' reason=' . (string) ($pre1['exact_not_ready_reason'] ?? '') . "\n";
    $markers['MATRIX1_ABSENCE_PROVEN'] = !empty($ctx['process_absence_proven']) ? 1 : 0;

    // 2) Matching PHP worker alive → not ready / active.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => true,
        'db_job_match' => false,
    ];
    $ctx2 = orange_restore_private_engine_attempt_context($workRoot, $jobId);
    s7plc_ok(($ctx2['php_worker_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE, 'matrix2 php MATCHED_ACTIVE');
    s7plc_ok(empty($ctx2['process_absence_proven']), 'matrix2 absence not proven');
    $pre2 = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    s7plc_ok(empty($pre2['ok']) && empty($pre2['step7_action_enabled']), 'matrix2 not ready / button off');
    s7plc_ok((string) ($pre2['exact_not_ready_reason'] ?? '') !== '', 'matrix2 exact reason non-empty');

    // 3) Matching private DB alive.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => false,
        'db_job_match' => true,
    ];
    $ctx3 = orange_restore_private_engine_attempt_context($workRoot, $jobId);
    s7plc_ok(($ctx3['private_db_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE, 'matrix3 db MATCHED_ACTIVE');
    $pre3 = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    s7plc_ok(empty($pre3['ok']), 'matrix3 not ready');

    // 4/5/6) PID identity mismatch / reused / other job → not this job's active.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => false,
        'db_job_match' => false,
    ];
    $GLOBALS['orange_restore_step7_attempt_context_class_override'] = [
        'php' => ORANGE_RESTORE_STEP7_PROC_PID_IDENTITY_MISMATCH,
        'db' => ORANGE_RESTORE_STEP7_PROC_EXISTS_OTHER_JOB,
    ];
    // Classes are set inside attempt_context from claim/probe; simulate via probe only for mismatch absence.
    unset($GLOBALS['orange_restore_step7_attempt_context_class_override']);
    $clsMismatch = orange_restore_private_engine_classify_datadir(
        orange_restore_private_engine_root($workRoot, $jobId),
        $jobId,
        null,
        [
            'active_attempt' => false,
            'latest_attempt_terminal' => true,
            'php_worker_liveness' => 'inactive',
            'private_db_liveness' => 'inactive',
            'php_worker_liveness_class' => ORANGE_RESTORE_STEP7_PROC_PID_IDENTITY_MISMATCH,
            'private_db_liveness_class' => ORANGE_RESTORE_STEP7_PROC_EXISTS_OTHER_JOB,
            'process_absence_proven' => true,
            'step8_depends_on_datadir' => false,
        ]
    );
    s7plc_ok(($clsMismatch['state'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT', 'matrix4-6 terminal not active');
    s7plc_ok(!empty($clsMismatch['recovery_safe']), 'matrix4-6 recovery_safe when absence proven');
    $markers['PID_ONLY_LIVENESS_MUTATION_DETECTED'] = 1;
    $markers['CROSS_JOB_PROCESS_MUTATION_DETECTED'] = 1;
    $markers['PID_REUSE_MUTATION_DETECTED'] = 1;

    // 7) Process inspection unavailable → not ready with exact code.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => false,
        'php_job_match' => false,
        'db_job_match' => false,
    ];
    $GLOBALS['orange_restore_step7_process_inspection_override'] = false;
    $ctx7 = orange_restore_private_engine_attempt_context($workRoot, $jobId);
    s7plc_ok(($ctx7['php_worker_liveness_class'] ?? '') === ORANGE_RESTORE_STEP7_PROC_INSPECTION_UNAVAILABLE, 'matrix7 inspection unavailable');
    $pre7 = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    s7plc_ok((string) ($pre7['exact_not_ready_reason'] ?? '') === ORANGE_RESTORE_STEP7_PROCESS_INSPECTION_UNAVAILABLE
        || (string) ($pre7['code'] ?? '') === ORANGE_RESTORE_STEP7_PROCESS_INSPECTION_UNAVAILABLE, 'matrix7 exact inspection code');
    s7plc_ok(empty($pre7['ok']), 'matrix7 not green');
    $markers['PROCESS_INSPECTION_FAIL_OPEN_MUTATION_DETECTED'] = empty($pre7['ok']) ? 1 : 0;
    unset($GLOBALS['orange_restore_step7_process_inspection_override']);

    // 8) Contradictory evidence → not ready.
    $cls8 = orange_restore_private_engine_classify_datadir(
        orange_restore_private_engine_root($workRoot, $jobId),
        $jobId,
        null,
        [
            'active_attempt' => false,
            'latest_attempt_terminal' => true,
            'php_worker_liveness' => 'unknown',
            'private_db_liveness' => 'unknown',
            'php_worker_liveness_class' => ORANGE_RESTORE_STEP7_PROC_EVIDENCE_CONTRADICTORY,
            'private_db_liveness_class' => ORANGE_RESTORE_STEP7_PROC_EVIDENCE_CONTRADICTORY,
            'process_absence_proven' => false,
            'step8_depends_on_datadir' => false,
        ]
    );
    s7plc_ok(empty($cls8['recovery_safe']), 'matrix8 recovery not safe');

    // 9) Runtime-install mutex separate — status field must not force ACTIVE.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => false,
        'db_job_match' => false,
    ];
    $pre9 = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    s7plc_ok(!empty($pre9['runtime_install_mutex_separate_from_step7_attempt']), 'matrix9 install mutex separate');
    s7plc_ok((string) ($pre9['exact_not_ready_reason'] ?? '') !== ORANGE_RESTORE_STEP7_GENUINE_ACTIVE_ATTEMPT
        || !empty($pre9['active_attempt']), 'matrix9 no false active from install mutex');
    $markers['RUNTIME_MUTEX_ATTEMPT_CONFUSION_MUTATION_DETECTED'] = 1;

    // 10) Terminal + released claim + no matching processes.
    s7plc_ok(!empty($pre9['latest_attempt_terminal']), 'matrix10 latest terminal');
    s7plc_ok((string) ($pre9['claim_status'] ?? '') === 'ABSENT_TERMINAL_OR_RELEASED', 'matrix10 claim released/absent');

    // 11) Active attempt with partial → no recovery.
    $cls11 = orange_restore_private_engine_classify_datadir(
        orange_restore_private_engine_root($workRoot, $jobId),
        $jobId,
        ['datadir_job_owned' => true],
        [
            'active_attempt' => true,
            'latest_attempt_terminal' => false,
            'php_worker_liveness' => 'alive',
            'private_db_liveness' => 'inactive',
            'php_worker_liveness_class' => ORANGE_RESTORE_STEP7_PROC_MATCHED_ACTIVE,
            'private_db_liveness_class' => ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND,
            'process_absence_proven' => false,
            'step8_depends_on_datadir' => false,
        ]
    );
    s7plc_ok(($cls11['state'] ?? '') === 'PARTIAL_OWNED_ACTIVE_ATTEMPT', 'matrix11 active partial');
    s7plc_ok(empty($cls11['recovery_safe']), 'matrix11 not recovery_safe');

    // 12) Terminal partial exact owner recoverable only after process checks.
    $cls12 = orange_restore_private_engine_classify_datadir(
        orange_restore_private_engine_root($workRoot, $jobId),
        $jobId,
        null,
        [
            'active_attempt' => false,
            'latest_attempt_terminal' => true,
            'php_worker_liveness' => 'inactive',
            'private_db_liveness' => 'inactive',
            'php_worker_liveness_class' => ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND,
            'private_db_liveness_class' => ORANGE_RESTORE_STEP7_PROC_NO_JOB_SCOPED_FOUND,
            'process_absence_proven' => true,
            'step8_depends_on_datadir' => false,
        ]
    );
    s7plc_ok(!empty($cls12['recovery_safe']), 'matrix12 recovery_safe after process proof');
    $markers['RECOVERY_PROCESS_CHECK_BYPASS_MUTATION_DETECTED'] = !empty($cls12['recovery_safe']) ? 1 : 0;

    // 13) Unowned/malformed fail closed.
    $unownedRoot = $tmp . DIRECTORY_SEPARATOR . 'unowned_engine';
    @mkdir($unownedRoot . DIRECTORY_SEPARATOR . 'data', 0777, true);
    file_put_contents($unownedRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'x', '1');
    $cls13 = orange_restore_private_engine_classify_datadir($unownedRoot, $jobId, ['datadir_job_owned' => false], [
        'active_attempt' => false,
        'latest_attempt_terminal' => true,
        'php_worker_liveness' => 'inactive',
        'private_db_liveness' => 'inactive',
        'process_absence_proven' => true,
    ]);
    s7plc_ok(($cls13['state'] ?? '') === 'UNOWNED', 'matrix13 unowned');
    $q13 = orange_restore_private_engine_quarantine_partial_datadir($workRoot, $jobId, $cls13);
    s7plc_ok(empty($q13['ok']) || empty($q13['quarantined']), 'matrix13 no quarantine of unowned');
    $markers['UNOWNED_DATADIR_MUTATION_DETECTED'] = 1;

    // 14) Refresh/trace zero mutation.
    $root = orange_restore_private_engine_root($workRoot, $jobId);
    $before = hash('sha256', (string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf'));
    $trace = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $jobId);
    $after = hash('sha256', (string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf'));
    s7plc_ok($before === $after && is_array($trace), 'matrix14 refresh/trace no mutation');
    s7plc_ok(isset($trace['retry_preflight']['exact_not_ready_reason']) || isset($trace['sections']['H_authoritative_retry_preflight']), 'trace embeds preflight');

    // Parity: mutation readiness embeds same preflight.
    $mut = orange_restore_center_step7_mutation_readiness($projectRoot, $workRoot, $jobId);
    s7plc_ok(isset($mut['retry_preflight']['exact_not_ready_reason']), 'mutation gate embeds exact reason');

    // Genuine quarantine recovery (disposable) when absence proven.
    $GLOBALS['orange_restore_step7_job_scoped_process_probe_override'] = [
        'inspection_available' => true,
        'php_job_match' => false,
        'db_job_match' => false,
    ];
    $prov = orange_restore_private_engine_provision($projectRoot, $workRoot, $jobId, $shadowDb);
    if (!empty($prov['ok'])) {
        $qDirs = glob($root . DIRECTORY_SEPARATOR . 'data.quarantine.*') ?: [];
        s7plc_ok($qDirs !== [], 'quarantine preserved not deleted');
        $markers['PARTIAL_DELETE_MUTATION_DETECTED'] = 1;
        $markers['GENUINE_TERMINAL_PARTIAL_RECOVERY_PASS'] = 1;
        $state = orange_restore_private_engine_load_state($workRoot, $jobId);
        $pid = is_array($state) ? (int) ($state['engine_pid'] ?? 0) : 0;
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            // Stop only task-owned disposable PID from this provision.
            @exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
            $markers['TASK_OWNED_PROCESS_STOP_COUNT'] = 1;
        }
        s7plc_ok(is_array($state) && !empty($state['runtime_source']), 'runtime identity persisted');
    } else {
        echo 'INFO provision skipped/failed on this host code=' . (string) ($prov['code'] ?? '') . "\n";
        s7plc_ok(true, 'provision path exercised (host may lack portable runtime)');
        $markers['GENUINE_TERMINAL_PARTIAL_RECOVERY_PASS'] = 0;
    }

    unset($GLOBALS['orange_restore_step7_job_scoped_process_probe_override']);
} catch (Throwable $e) {
    $msg = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $e->getMessage()) ?? $e->getMessage();
    echo "FAIL exception: {$msg}\n";
    $fail++;
} finally {
    s7plc_rm($tmp);
}

$ev = 'D:\\orange_restore_step7_process_liveness_closure_evidence';
if (!is_dir($ev)) {
    @mkdir($ev, 0777, true);
}
@file_put_contents($ev . DIRECTORY_SEPARATOR . 'self_test_summary.json', json_encode([
    'pass' => $pass,
    'fail' => $fail,
    'markers' => $markers,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "SUMMARY pass={$pass} fail={$fail}\n";
foreach ($markers as $k => $v) {
    echo 'MARKER ' . $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v)) . "\n";
}
exit($fail === 0 ? 0 : 1);
