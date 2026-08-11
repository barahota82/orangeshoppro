<?php

declare(strict_types=1);

/**
 * Read-only Backup runtime diagnostic API (POST + CSRF).
 * Never starts Full/Countries, never mutates locks/packages/Restore.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/backup/backup_runtime_diagnostic.php';

backup_admin_api_require_post();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);

    $data = backup_admin_api_json_body();
    backup_admin_api_require_csrf($data);

    // Reject any user-controlled path/filename parameters.
    foreach (['path', 'file', 'filename', 'backup_root', 'lock_path', 'package_path', 'script'] as $forbidden) {
        if (array_key_exists($forbidden, $data)) {
            json_response([
                'success' => false,
                'code' => 'user_controlled_path_forbidden',
                'message' => 'معلمة غير مسموحة.',
            ], 422);
        }
    }

    $projectRoot = backup_admin_api_project_root();
    $report = orange_backup_runtime_diagnostic_run($projectRoot, $pdo);

    json_response([
        'success' => true,
        'message' => 'اكتمل التشخيص للقراءة فقط.',
        'classification' => (string) ($report['classification'] ?? 'UNKNOWN_RUNTIME_BLOCKER'),
        'owner_report_ar' => (string) ($report['owner_report_ar'] ?? ''),
        'diagnostic' => $report,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
