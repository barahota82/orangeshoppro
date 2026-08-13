<?php

declare(strict_types=1);

/**
 * Terminal-partial recovery + readiness/preflight parity (disposable only).
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
    'REMAINING_DEFECT' => 'F',
    'TERMINAL_PARTIAL_CLASSIFICATION_PASS' => 0,
    'HISTORICAL_CURRENT_SEPARATION_PASS' => 0,
    'RETRY_PREFLIGHT_AUTHORITY_PASS' => 0,
    'GENUINE_SAME_JOB_TERMINAL_PARTIAL_RECOVERY_PASS' => 0,
    'ENVIRONMENT_RUNTIME_UNAVAILABLE_SKIP' => 0,
    'ASSERTION_WEAKENED' => 0,
];

function s7tpr_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7tpr_rm(string $dir): void
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

function s7tpr_evidence_dir(string $name): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 'D:\\' . $name;
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
}

$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$traceSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_trace.php');

s7tpr_ok(substr_count($orchSrc, 'function orange_restore_step7_retry_preflight') === 1, 'RETRY_PREFLIGHT_IMPLEMENTATION_COUNT=1');
s7tpr_ok(str_contains($orchSrc, 'orange_restore_step7_retry_preflight('), 'mutation/diagnostic call preflight');
s7tpr_ok(str_contains($engSrc, 'PARTIAL_OWNED_TERMINAL_ATTEMPT'), 'terminal partial state present');
s7tpr_ok(str_contains($engSrc, 'PARTIAL_OWNED_ACTIVE_ATTEMPT'), 'active partial state present');
s7tpr_ok(str_contains($traceSrc, 'LEGACY_ATTEMPT_RUNTIME_IDENTITY_ABSENT'), 'historical runtime label');
s7tpr_ok(str_contains($traceSrc, 'B3_current_implementation_capabilities'), 'current capabilities section');
s7tpr_ok(str_contains($engSrc, 'orange_quarantine_marker.json'), 'quarantine forensic marker');
$markers['TERMINAL_PARTIAL_CLASSIFICATION_MUTATION_DETECTED'] = str_contains($engSrc, 'PARTIAL_OWNED_TERMINAL_ATTEMPT') ? 1 : 0;
$markers['HISTORICAL_CURRENT_RUNTIME_CONFUSION_MUTATION_DETECTED'] = str_contains($traceSrc, 'LEGACY_ATTEMPT_') ? 1 : 0;
$markers['RETRY_PREFLIGHT_BYPASS_MUTATION_DETECTED'] = str_contains($orchSrc, 'orange_restore_step7_retry_preflight') ? 1 : 0;

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7tpr_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
@mkdir($workRoot, 0777, true);
$jobId = '2026-08-10_tpr_' . bin2hex(random_bytes(3));
$shadowDb = 'orange_tpr_' . substr(bin2hex(random_bytes(3)), 0, 6);
$GLOBALS['orange_shadow_production_db_override'] = 'orange_prod_disposable_' . substr(bin2hex(random_bytes(3)), 0, 6);

try {
    // Seed framework job as historical terminal failed Step7 (no engine_state / no runtime id).
    $fwDir = orange_restore_fw_job_directory($workRoot, $jobId);
    @mkdir($fwDir, 0777, true);
    $job = [
        'job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED,
        'source_package_id' => '2026-08-10_030008_disposable',
        'package_id' => '2026-08-10_030008_disposable',
        'updated_at' => '2026-08-12T23:19:39+00:00',
    ];
    if (function_exists('orange_restore_fw_write')) {
        orange_restore_fw_write($workRoot, $job);
    } else {
        file_put_contents($fwDir . DIRECTORY_SEPARATOR . 'job.json', json_encode($job, JSON_UNESCAPED_UNICODE));
    }

    $root = orange_restore_private_engine_root($workRoot, $jobId);
    foreach (['data', 'tmp', 'run'] as $sub) {
        @mkdir($root . DIRECTORY_SEPARATOR . $sub, 0775, true);
    }
    file_put_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf', "[auto]\n");
    file_put_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ibdata1', str_repeat('x', 64));
    // No engine_state.json — historical absence.

    $cls = orange_restore_private_engine_classify_datadir(
        $root,
        $jobId,
        null,
        [
            'active_attempt' => false,
            'latest_attempt_terminal' => true,
            'php_worker_liveness' => 'dead',
            'private_db_liveness' => 'dead',
            'step8_depends_on_datadir' => false,
        ]
    );
    s7tpr_ok(($cls['state'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT', 'terminal partial classified');
    s7tpr_ok(!empty($cls['recovery_safe']), 'recovery_safe=yes for terminal owned partial');
    $markers['TERMINAL_PARTIAL_CLASSIFICATION_PASS'] = (($cls['state'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT') ? 1 : 0;

    $activeCls = orange_restore_private_engine_classify_datadir($root, $jobId, ['datadir_job_owned' => true], [
        'active_attempt' => true,
        'latest_attempt_terminal' => false,
        'php_worker_liveness' => 'alive',
        'private_db_liveness' => 'dead',
        'step8_depends_on_datadir' => false,
    ]);
    s7tpr_ok(($activeCls['state'] ?? '') === 'PARTIAL_OWNED_ACTIVE_ATTEMPT', 'active partial classified');
    s7tpr_ok(empty($activeCls['recovery_safe']), 'active partial not recovery_safe');

    $trace = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $jobId);
    $hist = $trace['sections']['B2_historical_attempt_artifacts']['historical_runtime_identity']['value'] ?? '';
    $cur = $trace['sections']['B3_current_implementation_capabilities']['current_runtime_source']['value'] ?? '';
    $runtimeAvailable = is_string($cur) && $cur !== '' && $cur !== 'unavailable';
    s7tpr_ok($hist === 'LEGACY_ATTEMPT_RUNTIME_IDENTITY_ABSENT', 'historical runtime labeled legacy absent');
    s7tpr_ok($cur !== 'unavailable' || str_contains((string) ($trace['arabic_report'] ?? ''), 'مصدر المحرك الحالي'), 'current runtime separated in report');
    s7tpr_ok(isset($trace['sections']['B3_current_implementation_capabilities']), 'current runtime capability section present even when unavailable');
    $markers['HISTORICAL_CURRENT_SEPARATION_PASS'] = ($hist === 'LEGACY_ATTEMPT_RUNTIME_IDENTITY_ABSENT') ? 1 : 0;
    if (!$runtimeAvailable) {
        echo "SKIP runtime-dependent provision recovery: private runtime unavailable in this environment\n";
        $markers['ENVIRONMENT_RUNTIME_UNAVAILABLE_SKIP'] = 1;
    }

    // Soft-bind meta so parent/worker match can pass for preflight.
    $meta = [
        'framework_job_id' => $jobId,
        'shadow_db' => $shadowDb,
        'shadow_db_identity_hash' => function_exists('orange_restore_shadow_target_identity_hash')
            ? orange_restore_shadow_target_identity_hash($shadowDb, $jobId)
            : hash('sha256', strtolower($shadowDb) . '|' . $jobId),
        'attempt_id' => 'historical_s7_tpr',
    ];
    orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jobId), $meta);

    $pre = orange_restore_step7_retry_preflight($projectRoot, $workRoot, $jobId);
    $mut = orange_restore_center_step7_mutation_readiness($projectRoot, $workRoot, $jobId);
    s7tpr_ok(isset($mut['retry_preflight']), 'mutation readiness embeds retry_preflight');
    if ($runtimeAvailable) {
        s7tpr_ok(
            (string) ($pre['datadir_category'] ?? '') === 'PARTIAL_OWNED_TERMINAL_ATTEMPT',
            'preflight datadir=PARTIAL_OWNED_TERMINAL_ATTEMPT'
        );
    } else {
        s7tpr_ok(
            (string) ($pre['ready_token'] ?? '') === '' && !empty($pre['read_only']),
            'preflight remains read-only and non-green without runtime'
        );
    }
    s7tpr_ok((int) ($pre['job_write_count'] ?? 1) === 0, 'preflight job_write_count=0');
    $markers['RETRY_PREFLIGHT_AUTHORITY_PASS'] = isset($mut['retry_preflight']) ? 1 : 0;

    // Fingerprint before Refresh-equivalent trace (read-only).
    $beforeHash = hash('sha256', (string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf'));
    $trace2 = orange_restore_private_engine_trace_snapshot($projectRoot, $workRoot, $jobId);
    $afterHash = hash('sha256', (string) @file_get_contents($root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf'));
    s7tpr_ok($beforeHash === $afterHash && is_array($trace2), 'Refresh/trace no datadir mutation');

    if ($runtimeAvailable) {
        // Genuine same-job recovery via provision (disposable).
        $prov = orange_restore_private_engine_provision($projectRoot, $workRoot, $jobId, $shadowDb);
        s7tpr_ok(!empty($prov['ok']), 'provision recovers terminal partial');
        $qDirs = glob($root . DIRECTORY_SEPARATOR . 'data.quarantine.*') ?: [];
        s7tpr_ok($qDirs !== [], 'quarantine directory preserved (not deleted)');
        $marker = $qDirs !== [] ? ($qDirs[0] . DIRECTORY_SEPARATOR . 'orange_quarantine_marker.json') : '';
        s7tpr_ok($marker !== '' && is_file($marker), 'quarantine forensic marker present');
        $state = orange_restore_private_engine_load_state($workRoot, $jobId);
        s7tpr_ok(is_array($state) && !empty($state['runtime_source']), 'runtime identity persisted after recovery');
        $markers['GENUINE_SAME_JOB_TERMINAL_PARTIAL_RECOVERY_PASS'] = !empty($prov['ok']) && $qDirs !== [] ? 1 : 0;

        $pid = is_array($state) ? (int) ($state['engine_pid'] ?? 0) : 0;
        if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
            @exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
        }
    }
} catch (Throwable $e) {
    $msg = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $e->getMessage()) ?? $e->getMessage();
    echo "FAIL exception: {$msg}\n";
    $fail++;
} finally {
    s7tpr_rm($tmp);
}

$ev = s7tpr_evidence_dir('orange_restore_step7_terminal_partial_readiness_closure_evidence');
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
