<?php

declare(strict_types=1);

/**
 * Restore Center orchestration layer (Owner 2026-07-24).
 *
 * Schedules already-approved restore CLI workers from Admin HTTP.
 * HTTP must return immediately — workers run as detached OS processes and
 * must not depend on browser / HTTP connection lifetime.
 * Does not implement restore logic, alter gates, or rewrite workers.
 */

require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_cli_policy.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v2-detached';

/**
 * Allowlisted Restore Center worker keys → approved repo-relative CLI scripts.
 *
 * @return array<string, string>
 */
function orange_restore_center_worker_catalog(): array
{
    return [
        'pre_restore_backup' => 'scripts/backup/restore_prepare_backup.php',
        'shadow_db' => 'scripts/backup/restore_shadow_db.php',
        'shadow_verify' => 'scripts/backup/restore_shadow_verify.php',
        'shadow_files' => 'scripts/backup/restore_shadow_files.php',
        'shadow_smoke' => 'scripts/backup/restore_shadow_smoke.php',
        'production_import' => 'scripts/backup/restore_import_production.php',
        'uploads_cutover' => 'scripts/backup/restore_uploads_cutover.php',
        'rollback' => 'scripts/backup/restore_rollback.php',
        'finalize' => 'scripts/backup/restore_finalize.php',
    ];
}

/**
 * @return list<string>
 */
function orange_restore_center_approved_script_paths(): array
{
    return array_values(array_unique(array_merge(
        orange_restore_approved_production_mutation_cli_workers(),
        orange_restore_approved_non_mutation_restore_clis()
    )));
}

function orange_restore_center_assert_worker_key(string $workerKey): string
{
    $catalog = orange_restore_center_worker_catalog();
    if (!isset($catalog[$workerKey])) {
        throw new RuntimeException('restore_center_unknown_worker');
    }
    $rel = $catalog[$workerKey];
    $approved = orange_restore_center_approved_script_paths();
    if (!in_array($rel, $approved, true)) {
        throw new RuntimeException('restore_center_worker_not_allowlisted');
    }

    return $rel;
}

/**
 * Resolve absolute path to an allowlisted restore CLI script under project root.
 */
function orange_restore_center_resolve_worker_script(string $projectRoot, string $relativeScript): string
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $script = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeScript);
    if (!is_file($script)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $realScript = realpath($script);
    if ($realScript === false || !is_file($realScript)) {
        throw new RuntimeException('restore_center_worker_script_missing');
    }
    $normalized = str_replace('\\', '/', $realScript);
    $suffix = str_replace('\\', '/', $relativeScript);
    if (!str_ends_with($normalized, $suffix)) {
        throw new RuntimeException('restore_center_worker_script_path_rejected');
    }

    return $realScript;
}

function orange_restore_center_worker_log_path(string $workRoot, string $jobId, string $workerKey): string
{
    $safeWorker = preg_replace('/[^a-z0-9_]+/i', '_', $workerKey) ?: 'worker';
    $dir = orange_restore_fw_job_directory($workRoot, $jobId);

    return $dir . DIRECTORY_SEPARATOR . 'orchestrator_' . $safeWorker . '.log';
}

/**
 * Launch CLI worker detached from the PHP HTTP request (Windows + Unix).
 * Does not wait for completion. Does not supervise the child.
 *
 * @param list<string> $command Absolute php binary + script + --job=…
 */
function orange_restore_center_spawn_detached(array $command, string $logPath): void
{
    if (count($command) < 3) {
        throw new RuntimeException('restore_center_spawn_invalid_command');
    }

    $logDir = dirname($logPath);
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        throw new RuntimeException('restore_center_spawn_log_dir_failed');
    }

    $phpBinary = (string) $command[0];
    $script = (string) $command[1];
    $jobArg = (string) $command[2];
    if (!str_starts_with($jobArg, '--job=')) {
        throw new RuntimeException('restore_center_spawn_job_arg_rejected');
    }

    if (PHP_OS_FAMILY === 'Windows') {
        // start /B returns immediately; child outlives the HTTP PHP process.
        // Empty "" title is required so the first quoted path is not treated as window title.
        $cmdline = 'start /B "" '
            . escapeshellarg($phpBinary) . ' '
            . escapeshellarg($script) . ' '
            . escapeshellarg($jobArg)
            . ' >> ' . escapeshellarg($logPath) . ' 2>&1';
        $handle = @popen($cmdline, 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException('restore_center_spawn_failed');
        }
        pclose($handle);

        return;
    }

    $cmdline = escapeshellarg($phpBinary) . ' '
        . escapeshellarg($script) . ' '
        . escapeshellarg($jobArg)
        . ' >> ' . escapeshellarg($logPath) . ' 2>&1 &';
    exec('nohup ' . $cmdline);
}

/**
 * Schedule an approved restore CLI worker (--job= only). Returns immediately.
 *
 * @return array{
 *   ok:bool,
 *   detached:bool,
 *   scheduled:bool,
 *   worker:string,
 *   script:string,
 *   log_path:string,
 *   message:string
 * }
 */
function orange_restore_center_run_worker(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $workerKey,
    string $operatorUsername = ''
): array {
    if ($workRoot === '') {
        throw new RuntimeException('Restore work root unavailable.');
    }
    if ($jobId === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $jobId)) {
        throw new RuntimeException('invalid_job_id');
    }
    if (!function_exists('orange_restore_admin_assert_fw_job_allowlisted')) {
        require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'restore_admin.php';
    }
    orange_restore_admin_assert_fw_job_allowlisted($workRoot, $jobId);

    $relative = orange_restore_center_assert_worker_key($workerKey);
    $script = orange_restore_center_resolve_worker_script($projectRoot, $relative);
    $phpBinary = orange_backup_admin_resolve_cli_php_binary($projectRoot);
    $logPath = orange_restore_center_worker_log_path($workRoot, $jobId, $workerKey);

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_worker_scheduled',
        'result' => 'ok',
        'worker' => $workerKey,
        'script' => $relative,
        'detached' => true,
        'operator_username' => $operatorUsername,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
    ]);

    orange_restore_center_spawn_detached(
        [$phpBinary, $script, '--job=' . $jobId],
        $logPath
    );

    return [
        'ok' => true,
        'detached' => true,
        'scheduled' => true,
        'worker' => $workerKey,
        'script' => $relative,
        'log_path' => $logPath,
        'message' => 'Worker scheduled on server. Continues independently of the browser.',
    ];
}
