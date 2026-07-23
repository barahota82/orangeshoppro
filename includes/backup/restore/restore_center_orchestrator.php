<?php

declare(strict_types=1);

/**
 * Restore Center orchestration layer (Owner 2026-07-23).
 *
 * Invokes already-approved restore CLI workers from Admin HTTP using the same
 * scripts/backup entrypoints operators previously ran manually.
 * Does not implement restore logic, alter gates, or rewrite workers.
 */

require_once __DIR__ . '/../backup_admin.php';
require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_job_framework.php';
require_once __DIR__ . '/restore_production_cli_policy.php';

const ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION = '3B.4-rc-orchestrator-v1';
const ORANGE_RESTORE_CENTER_WORKER_TIMEOUT_SECONDS = 7200;

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

/**
 * Spawn an approved restore CLI worker with --job= only (no paths/SQL/passwords).
 *
 * @return array{
 *   ok:bool,
 *   worker:string,
 *   script:string,
 *   exit_code:int,
 *   stdout:string,
 *   stderr:string,
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

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_worker_invoke_started',
        'result' => 'ok',
        'worker' => $workerKey,
        'script' => $relative,
        'operator_username' => $operatorUsername,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
    ]);

    if (function_exists('set_time_limit')) {
        @set_time_limit(ORANGE_RESTORE_CENTER_WORKER_TIMEOUT_SECONDS + 60);
    }

    $capture = orange_backup_run_command_capture(
        [$phpBinary, $script, '--job=' . $jobId],
        ORANGE_RESTORE_CENTER_WORKER_TIMEOUT_SECONDS
    );
    $exitCode = (int) ($capture['exit_code'] ?? 1);
    $stdout = (string) ($capture['stdout'] ?? '');
    $stderr = (string) ($capture['stderr'] ?? '');
    $ok = $exitCode === 0;

    orange_restore_fw_audit_append($workRoot, $jobId, [
        'event' => 'restore_center_worker_invoke_finished',
        'result' => $ok ? 'ok' : 'fail',
        'worker' => $workerKey,
        'script' => $relative,
        'exit_code' => $exitCode,
        'operator_username' => $operatorUsername,
        'orchestrator_version' => ORANGE_RESTORE_CENTER_ORCHESTRATOR_VERSION,
    ]);

    if (!$ok) {
        $excerpt = trim($stderr !== '' ? $stderr : $stdout);
        if ($excerpt !== '') {
            error_log(
                '[orange restore center orchestrator] worker=' . $workerKey
                . ' job=' . $jobId
                . ' exit=' . $exitCode
                . ' ' . orange_backup_admin_sanitize_cli_excerpt($excerpt, 800)
            );
        }
    }

    return [
        'ok' => $ok,
        'worker' => $workerKey,
        'script' => $relative,
        'exit_code' => $exitCode,
        'stdout' => orange_backup_admin_sanitize_cli_excerpt($stdout, 4000),
        'stderr' => orange_backup_admin_sanitize_cli_excerpt($stderr, 2000),
        'message' => $ok
            ? 'Worker completed from Restore Center.'
            : 'Worker failed (exit ' . $exitCode . ').',
    ];
}
