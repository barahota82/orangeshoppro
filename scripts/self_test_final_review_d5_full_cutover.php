<?php

declare(strict_types=1);

/**
 * FSR D5 — Full Restore approval → merge precheck → DB cutover on disposable orange_db.
 *
 * Usage: php scripts/self_test_final_review_d5_full_cutover.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$mainRoot = dirname(__DIR__);
require_once $mainRoot . '/scripts/lib/final_review_d5_runtime.php';
require_once $mainRoot . '/includes/backup/backup_full.php';

$passes = 0;
$failures = 0;
$skips = 0;
$started = microtime(true);

function d5cut_assert(bool $ok, string $label): void
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

echo 'NOTE  suite=d5_full_cutover start=' . gmdate('c') . "\n";

$ctx = orange_d5_bootstrap($mainRoot);
if (empty($ctx['ok'])) {
    echo 'ENVIRONMENT_BLOCKED: ' . (string) ($ctx['error'] ?? '') . "\n";
    echo "RESULT=FSR_D5_ENVIRONMENT_BLOCKER\n";
    echo "PASS=0 FAIL=0 SKIP=1\n";
    exit(2);
}

$cleanup = $ctx['cleanup'];
try {
    d5cut_assert((int) ($ctx['admin_id'] ?? 0) > 0, 'disposable restore admin seeded');
    d5cut_assert((string) ($ctx['merge_user'] ?? '') !== '', 'merge user created');

    $bak = orange_d5_run_full_backup_pdo($ctx);
    d5cut_assert(!empty($bak['ok']), 'full php_pdo backup for cutover package');
    $pkg = (string) ($bak['package_path'] ?? '');
    d5cut_assert($pkg !== '' && is_dir($pkg), 'cutover package dir exists');

    $runtimeRoot = (string) $ctx['runtime_root'];
    $mainRootReal = (string) $ctx['main_root'];
    orange_d5_mirror_dir($mainRootReal . '/includes/backup', $runtimeRoot . '/includes/backup');
    @copy($mainRootReal . '/includes/admin_permissions.php', $runtimeRoot . '/includes/admin_permissions.php');

    $stgWorkerSrc = $mainRootReal . '/scripts/lib/final_review_d5_staging_worker.php';
    $stgWorkerDst = $runtimeRoot . '/scripts/lib/final_review_d5_staging_worker.php';
    if (!is_dir(dirname($stgWorkerDst))) {
        @mkdir(dirname($stgWorkerDst), 0775, true);
    }
    @copy($stgWorkerSrc, $stgWorkerDst);

    $stgResultPath = sys_get_temp_dir() . '/orange_d5_stg_cut_' . bin2hex(random_bytes(4)) . '.json';
    $phpBin = PHP_BINARY !== '' ? PHP_BINARY : 'php';
    $stgCmd = [$phpBin, $stgWorkerDst, $runtimeRoot, $pkg, $stgResultPath];
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $stgProc = proc_open($stgCmd, $desc, $stgPipes, $runtimeRoot, null, ['bypass_shell' => true]);
    $stgStderr = '';
    if (is_resource($stgProc)) {
        fclose($stgPipes[0]);
        stream_get_contents($stgPipes[1]);
        $stgStderr = (string) stream_get_contents($stgPipes[2]);
        fclose($stgPipes[1]);
        fclose($stgPipes[2]);
        proc_close($stgProc);
    }
    $stgDecoded = is_file($stgResultPath) ? json_decode((string) file_get_contents($stgResultPath), true) : null;
    @unlink($stgResultPath);
    echo 'NOTE  staging ok=' . (!empty($stgDecoded['ok']) ? '1' : '0')
        . ' job=' . (string) ($stgDecoded['job_id'] ?? '')
        . ' err=' . (string) ($stgDecoded['error'] ?? '')
        . ' stderr=' . substr(trim($stgStderr), 0, 180) . "\n";
    d5cut_assert(is_array($stgDecoded) && !empty($stgDecoded['ok']), 'staging before cutover succeeded');
    $jobId = (string) ($stgDecoded['job_id'] ?? '');
    d5cut_assert($jobId !== '', 'staging job_id present');

    $cutWorkerSrc = $mainRootReal . '/scripts/lib/final_review_d5_cutover_worker.php';
    $cutWorkerDst = $runtimeRoot . '/scripts/lib/final_review_d5_cutover_worker.php';
    @copy($cutWorkerSrc, $cutWorkerDst);
    $cutResultPath = sys_get_temp_dir() . '/orange_d5_cut_' . bin2hex(random_bytes(4)) . '.json';
    $cutCmd = [$phpBin, $cutWorkerDst, $runtimeRoot, $jobId, $cutResultPath];
    $cutProc = proc_open($cutCmd, $desc, $cutPipes, $runtimeRoot, null, ['bypass_shell' => true]);
    $cutStderr = '';
    if (is_resource($cutProc)) {
        fclose($cutPipes[0]);
        stream_get_contents($cutPipes[1]);
        $cutStderr = (string) stream_get_contents($cutPipes[2]);
        fclose($cutPipes[1]);
        fclose($cutPipes[2]);
        proc_close($cutProc);
    }
    $cutDecoded = is_file($cutResultPath) ? json_decode((string) file_get_contents($cutResultPath), true) : null;
    @unlink($cutResultPath);
    echo 'NOTE  cutover ok=' . (!empty($cutDecoded['ok']) ? '1' : '0')
        . ' err=' . (string) ($cutDecoded['error'] ?? '')
        . ' steps=' . json_encode($cutDecoded['steps'] ?? [], JSON_UNESCAPED_SLASHES)
        . ' stderr=' . substr(trim($cutStderr), 0, 200) . "\n";

    d5cut_assert(is_array($cutDecoded) && !empty($cutDecoded['ok']), 'full DB cutover succeeded');
    d5cut_assert(
        (string) ($cutDecoded['cutover']['status'] ?? '') === 'database_cutover_complete'
        || !empty($cutDecoded['ok']),
        'cutover status database_cutover_complete or ok'
    );
    d5cut_assert((int) ($cutDecoded['production_table_count'] ?? 0) > 50, 'production tables present after cutover');

    // Negative: privilege fence still present in Production source
    $fenceSrc = (string) file_get_contents($mainRootReal . '/includes/backup/restore/restore_staging_target.php');
    d5cut_assert(
        str_contains($fenceSrc, 'function orange_restore_staging_is_neutral_usage_grant'),
        'STG-01 repair still present after cutover path'
    );
    $gateSrc = (string) file_get_contents($mainRootReal . '/includes/backup/restore/restore_fresh_backup_gate.php');
    d5cut_assert(
        str_contains($gateSrc, 'function orange_restore_fresh_backup_resolve_package_dir'),
        'FULL-01 repair still present after cutover path'
    );
} finally {
    if (is_callable($cleanup)) {
        $cleanup();
    }
}

$dur = round(microtime(true) - $started, 3);
echo "\nPASS={$passes} FAIL={$failures} SKIP={$skips}\n";
echo "DURATION_SEC={$dur}\n";
if ($failures > 0) {
    echo "RESULT=FSR_D5_PROVEN_BACKUP_RESTORE_GAPS_FOUND\n";
    exit(1);
}
echo "RESULT=FSR_D5_FULL_CUTOVER_OK\n";
exit(0);
