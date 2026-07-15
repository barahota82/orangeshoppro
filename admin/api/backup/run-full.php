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
    $blockMessage = orange_backup_admin_manual_actions_block_message($projectRoot);
    if ($blockMessage !== null) {
        orange_backup_admin_audit(
            'run_full',
            'full_disaster',
            '',
            $startedAt,
            gmdate('c'),
            false,
            $blockMessage
        );
        json_response(['success' => false, 'message' => $blockMessage], 422);
    }

    $result = orange_backup_admin_run_full($projectRoot);
    $finishedAt = (string) ($result['finished_at'] ?? gmdate('c'));
    $ok = (bool) ($result['ok'] ?? false);

    orange_backup_admin_audit(
        'run_full',
        'full_disaster',
        (string) ($result['snapshot'] ?? ''),
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : (string) ($result['message'] ?? '')
    );

    json_response([
        'success' => $ok,
        'message' => $ok ? 'اكتمل النسخ الاحتياطي الكامل.' : (string) ($result['message'] ?? 'فشل النسخ الاحتياطي.'),
        'result' => orange_backup_admin_redact_secrets($result),
    ], $ok ? 200 : 409);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
