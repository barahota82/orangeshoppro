<?php

declare(strict_types=1);

/**
 * D5 isolated worker: Full Restore approval → merge precheck → DB cutover (disposable).
 *
 * Args: runtime_root job_id result_json_path
 * Admin credentials are read from runtime .env.php keys written by the suite
 * (ORANGE_D5_ADMIN_ID / ORANGE_D5_ADMIN_PASS) — disposable only.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$runtimeRoot = (string) ($argv[1] ?? '');
$jobId = (string) ($argv[2] ?? '');
$resultPath = (string) ($argv[3] ?? '');
if ($runtimeRoot === '' || $jobId === '' || $resultPath === '') {
    fwrite(STDERR, "Usage: cutover_worker runtime job_id result.json\n");
    exit(2);
}

require_once $runtimeRoot . '/config.php';
require_once $runtimeRoot . '/includes/catalog_schema.php';
require_once $runtimeRoot . '/includes/admin_permissions.php';
require_once $runtimeRoot . '/includes/backup/backup_environment.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_paths.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_job.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_lock.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_orchestrator.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_merge_precheck.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_merge_maintenance.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_merge_db_cutover.php';
require_once $runtimeRoot . '/includes/backup/restore/restore_merge_rollback.php';

$out = ['ok' => false, 'error' => 'unknown', 'steps' => []];

try {
    $flagPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d5_schema_ok_cut_' . getmypid() . '.flag';
    file_put_contents($flagPath, '124');
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flagPath);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flagPath;

    $env = orange_backup_load_env_array($runtimeRoot);
    $workRoot = orange_restore_resolve_work_root($env);
    $adminId = (int) ($env['ORANGE_D5_ADMIN_ID'] ?? 0);
    $adminPass = (string) ($env['ORANGE_D5_ADMIN_PASS'] ?? '');
    if ($adminId <= 0 || $adminPass === '') {
        throw new RuntimeException('D5 admin credentials missing from runtime env');
    }

    $pdo = db();
    $job = orange_restore_job_read($workRoot, $jobId);
    $out['steps'][] = 'job_status=' . (string) ($job['status'] ?? '');

    $lock = orange_restore_acquire_lock($workRoot, $jobId);
    if (empty($lock['ok'])) {
        throw new RuntimeException('Cannot acquire restore lock: ' . (string) ($lock['message'] ?? ''));
    }

    try {
        if ((string) ($job['status'] ?? '') === ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL) {
            $approved = orange_restore_orchestrator_approve_for_merge($pdo, [
                'project_root' => $runtimeRoot,
                'work_root' => $workRoot,
                'job_id' => $jobId,
                'admin_id' => $adminId,
                'password' => $adminPass,
                'confirmation_phrase' => 'RESTORE',
            ]);
            $out['steps'][] = 'approved status=' . (string) ($approved['status'] ?? $approved['job']['status'] ?? '');
        }

        $pre = orange_restore_merge_precheck_run([
            'project_root' => $runtimeRoot,
            'work_root' => $workRoot,
            'job_id' => $jobId,
        ]);
        $out['steps'][] = 'precheck ok=' . (!empty($pre['ok']) ? '1' : '0');

        $maint = orange_restore_merge_maintenance_enable($workRoot, $jobId, [
            'operator_admin_id' => $adminId,
        ]);
        $out['steps'][] = 'maintenance active=' . (!empty($maint['active']) ? '1' : '0');

        $cut = orange_restore_merge_db_cutover_run([
            'project_root' => $runtimeRoot,
            'work_root' => $workRoot,
            'job_id' => $jobId,
            'admin_id' => $adminId,
            'password' => $adminPass,
            'confirmation_phrase' => 'RESTORE',
        ]);
        $out['steps'][] = 'db_cutover ok=' . (!empty($cut['ok']) ? '1' : '0')
            . ' status=' . (string) ($cut['status'] ?? '');

        $tblCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE='BASE TABLE'"
        )->fetchColumn();

        $out['ok'] = !empty($cut['ok']);
        $out['job_id'] = $jobId;
        $out['cutover'] = [
            'ok' => !empty($cut['ok']),
            'status' => (string) ($cut['status'] ?? ''),
            'production_writes' => (bool) ($cut['production_writes'] ?? false),
        ];
        $out['production_table_count'] = $tblCount;
        $out['error'] = $out['ok'] ? '' : (string) ($cut['error'] ?? 'cutover_failed');
    } finally {
        // Keep maintenance + job state for optional rollback suite; release lock.
        orange_restore_release_lock($workRoot);
    }
} catch (Throwable $e) {
    $out = [
        'ok' => false,
        'error' => $e->getMessage(),
        'steps' => $out['steps'] ?? [],
        'job_id' => $jobId,
    ];
    try {
        if (isset($workRoot) && is_string($workRoot) && $workRoot !== '') {
            orange_restore_release_lock($workRoot);
        }
    } catch (Throwable) {
    }
}

file_put_contents($resultPath, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(!empty($out['ok']) ? 0 : 1);
