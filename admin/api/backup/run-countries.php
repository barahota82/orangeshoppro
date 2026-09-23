<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/backup/backup_provenance.php';

backup_admin_api_require_post();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_run($admin, $pdo);

    $data = backup_admin_api_json_body();
    backup_admin_api_require_csrf($data);

    $projectRoot = backup_admin_api_project_root();
    $startedAt = gmdate('c');
    $blockMessage = orange_backup_admin_manual_actions_block_message($projectRoot);
    if ($blockMessage !== null) {
        orange_backup_admin_audit(
            'run_country_batch',
            'country_recovery',
            'batch',
            $startedAt,
            gmdate('c'),
            false,
            $blockMessage
        );
        json_response(['success' => false, 'message' => $blockMessage], 422);
    }

    $viewCtx = orange_backup_admin_context_for_view($projectRoot);
    $backupRoot = (string) ($viewCtx['backup_root'] ?? '');
    $executionId = null;
    if ($backupRoot !== '' && is_dir($backupRoot)) {
        $begun = orange_backup_provenance_begin_manual_admin_execution(
            $backupRoot,
            $admin,
            $pdo,
            'all_recoverable_countries'
        );
        $executionId = $begun['execution_id'] ?? null;
    }

    try {
        $result = orange_backup_admin_run_country_batch($projectRoot);
    } finally {
        orange_backup_provenance_clear_cli_context();
    }

    $finishedAt = (string) ($result['finished_at'] ?? gmdate('c'));
    $ok = (bool) ($result['ok'] ?? false);
    if (!$ok && is_string($executionId) && $executionId !== '' && $backupRoot !== '') {
        $errorSummary = trim((string) ($result['stderr'] ?? $result['message'] ?? 'country_batch_failed'));
        orange_backup_provenance_finish_execution($backupRoot, $executionId, [
            'overall_status' => 'failed',
            'completed_at_utc' => $finishedAt,
            'error_summary' => substr($errorSummary, 0, 240),
        ]);
    }

    orange_backup_admin_audit(
        'run_country_batch',
        'country_recovery',
        is_string($executionId) && $executionId !== '' ? $executionId : 'batch',
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : trim((string) ($result['stderr'] ?? $result['message'] ?? '')),
        is_string($executionId) ? $executionId : null
    );

    json_response([
        'success' => $ok,
        'message' => $ok ? 'اكتمل تصدير حزم الدول.' : (string) ($result['message'] ?? 'فشل تصدير حزم الدول.'),
        'result' => orange_backup_admin_redact_secrets([
            'exit_code' => (int) ($result['exit_code'] ?? 1),
            'stdout_excerpt' => orange_backup_admin_sanitize_cli_excerpt((string) ($result['stdout'] ?? ''), 4000),
            'stderr_excerpt' => orange_backup_admin_sanitize_cli_excerpt((string) ($result['stderr'] ?? ''), 2000),
        ]),
    ], $ok ? 200 : 409);
} catch (Throwable $e) {
    orange_backup_provenance_clear_cli_context();
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
