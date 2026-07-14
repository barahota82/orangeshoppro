<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

backup_admin_api_require_post();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_run($admin, $pdo);

    $data = backup_admin_api_json_body();
    backup_admin_api_require_csrf($data);

    $projectRoot = backup_admin_api_project_root();
    $startedAt = gmdate('c');
    $result = orange_backup_admin_run_country_batch($projectRoot);
    $finishedAt = (string) ($result['finished_at'] ?? gmdate('c'));
    $ok = (bool) ($result['ok'] ?? false);

    orange_backup_admin_audit(
        'run_country_batch',
        'country_recovery',
        'batch',
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : trim((string) ($result['stderr'] ?? $result['message'] ?? ''))
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
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
