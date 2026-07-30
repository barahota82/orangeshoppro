<?php

declare(strict_types=1);

/**
 * FSR D5 — FSR-D5-APR-01 approval-window contract (repair verification).
 *
 * Usage: php scripts/self_test_final_review_d5_approval_window.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
require_once $mainRoot . '/includes/backup/backup_full.php';
require_once $mainRoot . '/includes/backup/restore/restore_job.php';
require_once $mainRoot . '/includes/backup/restore/restore_paths.php';
require_once $mainRoot . '/includes/backup/backup_environment.php';
require_once $mainRoot . '/includes/backup/restore/restore_full_staging.php';

$passes = 0;
$failures = 0;
$started = microtime(true);

function d5apr_assert(bool $ok, string $label): void
{
    global $passes, $failures;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

echo 'NOTE  suite=d5_approval_window start=' . gmdate('c') . "\n";
echo "NOTE  defect_id=FSR-D5-APR-01 repair verification\n";

// --- Unit: helper normalization ---
d5apr_assert(
    function_exists('orange_restore_full_staging_approval_window_started_at'),
    'helper orange_restore_full_staging_approval_window_started_at present'
);
$nowish = orange_restore_full_staging_approval_window_started_at([]);
d5apr_assert($nowish !== '' && strtotime($nowish) !== false, 'missing key → nonempty UTC timestamp');
$fromNull = orange_restore_full_staging_approval_window_started_at(['owner_approval_window_started_at' => null]);
d5apr_assert($fromNull !== '', 'null → nonempty timestamp');
$fromEmpty = orange_restore_full_staging_approval_window_started_at(['owner_approval_window_started_at' => '']);
d5apr_assert($fromEmpty !== '', 'empty string → nonempty timestamp');
$fromWs = orange_restore_full_staging_approval_window_started_at(['owner_approval_window_started_at' => "  \t  "]);
d5apr_assert($fromWs !== '', 'whitespace → nonempty timestamp');
$fixed = '2026-01-15T12:00:00+00:00';
$kept = orange_restore_full_staging_approval_window_started_at(['owner_approval_window_started_at' => $fixed]);
d5apr_assert($kept === $fixed, 'existing valid timestamp preserved exactly');

$src = (string) file_get_contents($mainRoot . '/includes/backup/restore/restore_full_staging.php');
d5apr_assert(
    str_contains($src, 'orange_restore_full_staging_approval_window_started_at'),
    'finalize uses approval-window helper'
);
d5apr_assert(
    !str_contains($src, "owner_approval_window_started_at'] ?? gmdate('c')"),
    'finalize no longer uses bare ?? on empty-string field'
);

$ctx = orange_d5_bootstrap($mainRoot);
if (empty($ctx['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($ctx['error'] ?? '') . "\n";
    echo "RESULT=FSR_D5_ENVIRONMENT_BLOCKER\n";
    exit(2);
}

$cleanup = $ctx['cleanup'];
try {
    $bak = orange_d5_run_full_backup_pdo($ctx);
    d5apr_assert(!empty($bak['ok']), 'backup for approval-window behavioral proof');
    $pkg = (string) ($bak['package_path'] ?? '');

    $runtimeRoot = (string) $ctx['runtime_root'];
    $mainRootReal = (string) $ctx['main_root'];
    orange_d5_mirror_dir($mainRootReal . '/includes/backup', $runtimeRoot . '/includes/backup');
    $stgWorkerSrc = $mainRootReal . '/scripts/lib/final_review_d5_staging_worker.php';
    $stgWorkerDst = $runtimeRoot . '/scripts/lib/final_review_d5_staging_worker.php';
    if (!is_dir(dirname($stgWorkerDst))) {
        @mkdir(dirname($stgWorkerDst), 0775, true);
    }
    @copy($stgWorkerSrc, $stgWorkerDst);

    $stgResultPath = sys_get_temp_dir() . '/orange_d5_apr_' . bin2hex(random_bytes(4)) . '.json';
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$phpBin, $stgWorkerDst, $runtimeRoot, $pkg, $stgResultPath], $desc, $pipes, $runtimeRoot, null, ['bypass_shell' => true]);
    if (is_resource($proc)) {
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    $stg = is_file($stgResultPath) ? json_decode((string) file_get_contents($stgResultPath), true) : null;
    @unlink($stgResultPath);
    d5apr_assert(is_array($stg) && !empty($stg['ok']), 'staging completed');
    $jobId = (string) ($stg['job_id'] ?? '');
    d5apr_assert($jobId !== '', 'job_id present');

    $env = [
        'ORANGE_BACKUP_ROOT' => (string) $ctx['backup_root'],
        'ORANGE_RESTORE_WORK_DIR' => (string) $ctx['restore_root'],
    ];
    $workRoot = orange_restore_resolve_work_root($env);
    $job = orange_restore_job_read($workRoot, $jobId);
    $status = (string) ($job['status'] ?? '');
    $window = trim((string) ($job['owner_approval_window_started_at'] ?? ''));
    echo 'NOTE  job_status=' . $status . ' window=' . $window . "\n";

    d5apr_assert($status === 'awaiting_owner_approval', 'job status awaiting_owner_approval');
    d5apr_assert($window !== '' && strtotime($window) !== false, 'owner_approval_window_started_at nonempty after finalize');

    // Repeated finalize (via helper path) — preserve timestamp
    $job2 = orange_restore_full_staging_finalize_approval($workRoot, $jobId);
    d5apr_assert(
        trim((string) ($job2['owner_approval_window_started_at'] ?? '')) === $window,
        'repeated finalize preserves approval window'
    );

    // Approve must succeed (no longer "window has not started")
    $approveWorker = <<<'PHP'
<?php
declare(strict_types=1);
$runtime=$argv[1]; $jobId=$argv[2]; $out=$argv[3];
require_once $runtime.'/config.php';
require_once $runtime.'/includes/admin_permissions.php';
require_once $runtime.'/includes/backup/restore/restore_orchestrator.php';
require_once $runtime.'/includes/backup/restore/restore_paths.php';
$env=orange_backup_load_env_array($runtime);
$work=orange_restore_resolve_work_root($env);
$adminId=(int)($env['ORANGE_D5_ADMIN_ID']??0);
$pass=(string)($env['ORANGE_D5_ADMIN_PASS']??'');
try {
  $r=orange_restore_orchestrator_approve_for_merge(db(),[
    'project_root'=>$runtime,
    'work_root'=>$work,
    'job_id'=>$jobId,
    'admin_id'=>$adminId,
    'password'=>$pass,
    'confirmation_phrase'=>'RESTORE',
  ]);
  file_put_contents($out,json_encode(['ok'=>!empty($r['ok']),'status'=>(string)($r['status']??''),'error'=>'']));
} catch (Throwable $e) {
  file_put_contents($out,json_encode(['ok'=>false,'error'=>$e->getMessage()]));
  exit(1);
}
PHP;
    $awPath = (string) $ctx['data_root'] . '/d5_approve_worker.php';
    file_put_contents($awPath, $approveWorker);
    $aOut = sys_get_temp_dir() . '/orange_d5_apr_out_' . bin2hex(random_bytes(3)) . '.json';
    $ap = proc_open([$phpBin, $awPath, $runtimeRoot, $jobId, $aOut], $desc, $apipes, $runtimeRoot, null, ['bypass_shell' => true]);
    if (is_resource($ap)) {
        fclose($apipes[0]);
        stream_get_contents($apipes[1]);
        stream_get_contents($apipes[2]);
        fclose($apipes[1]);
        fclose($apipes[2]);
        proc_close($ap);
    }
    $aDec = is_file($aOut) ? json_decode((string) file_get_contents($aOut), true) : null;
    @unlink($aOut);
    echo 'NOTE  approve ok=' . (!empty($aDec['ok']) ? '1' : '0') . ' status=' . (string) ($aDec['status'] ?? '')
        . ' err=' . (string) ($aDec['error'] ?? '') . "\n";
    d5apr_assert(!empty($aDec['ok']), 'Owner approval succeeds after valid finalization');
    d5apr_assert(
        !str_contains((string) ($aDec['error'] ?? ''), 'Owner approval window has not started'),
        'empty-window approval error no longer occurs'
    );
    d5apr_assert(
        (string) ($aDec['status'] ?? '') === 'approved_for_merge',
        'status approved_for_merge after approval'
    );

    // Expiry unit: assert_window_open rejects expired
    require_once $mainRoot . '/includes/backup/restore/restore_orchestrator.php';
    $expiredJob = [
        'owner_approval_window_started_at' => gmdate('c', time() - ORANGE_RESTORE_APPROVAL_WINDOW_SECONDS - 10),
    ];
    $expErr = '';
    try {
        orange_restore_orchestrator_assert_approval_window_open($expiredJob);
    } catch (Throwable $e) {
        $expErr = $e->getMessage();
    }
    d5apr_assert(str_contains($expErr, 'expired'), 'expired window fails closed');
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP=0\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_APR01_REPAIR_FAIL\n";
    exit(1);
}
echo "RESULT=FSR_D5_APR01_REPAIRED\n";
exit(0);
