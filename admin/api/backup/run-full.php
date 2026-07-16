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
        json_response([
            'success' => false,
            'message' => 'تعذر إنشاء النسخة الاحتياطية الشاملة.',
            'error' => $blockMessage,
            'exit_code' => 1,
        ], 422);
    }

    $result = orange_backup_admin_run_full_for_api($projectRoot);
    $finishedAt = (string) ($result['finished_at'] ?? gmdate('c'));
    $ok = (bool) ($result['ok'] ?? false);
    $exitCode = (int) ($result['exit_code'] ?? ($ok ? 0 : 1));
    $errorSummary = $ok ? null : (string) ($result['error'] ?? orange_backup_admin_sanitize_cli_excerpt((string) ($result['message'] ?? ''), 400));

    orange_backup_admin_audit(
        'run_full',
        'full_disaster',
        (string) ($result['snapshot'] ?? ''),
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : (string) ($errorSummary ?? '')
    );

    $payload = [
        'success' => $ok,
        'message' => $ok ? 'اكتمل إنشاء النسخة الاحتياطية الشاملة.' : 'تعذر إنشاء النسخة الاحتياطية الشاملة.',
        'exit_code' => $exitCode,
    ];
    if ($ok && !empty($result['snapshot'])) {
        $payload['snapshot'] = (string) $result['snapshot'];
    }
    if (!$ok && $errorSummary !== null && $errorSummary !== '') {
        $payload['error'] = $errorSummary;
    }

    json_response($payload, $ok ? 200 : 409);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
