<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/backup_qualification.php';

backup_admin_api_require_post();

try {
    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_verify($admin, $pdo);

    $data = backup_admin_api_json_body();
    backup_admin_api_require_csrf($data);

    $packageType = trim((string) ($data['package_type'] ?? ''));
    $packageId = trim((string) ($data['package_id'] ?? ''));
    $countryCode = trim((string) ($data['country_code'] ?? ''));

    // Path resolve remains view-context (package discovery). Locks/Full Verify sibling need write;
    // run_verify fails closed for heavy work when BackupRoot is not writable.
    $ctx = orange_backup_admin_context_for_view(backup_admin_api_project_root());
    $backupRoot = (string) $ctx['backup_root'];
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($backupRoot, $packageId);
    } elseif ($packageType === 'country_recovery') {
        orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
        $packagePath = orange_backup_admin_resolve_country_package_path($backupRoot, $countryCode, $packageId);
    } else {
        json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
    }

    $operator = [
        'kind' => 'admin',
        'admin_id' => (int) ($admin['id'] ?? 0),
    ];
    $run = orange_backup_qualification_endpoint_verify(
        $backupRoot,
        $packageType,
        $packagePath,
        $packageId,
        $countryCode,
        $operator
    );

    if (!empty($run['in_progress'])) {
        json_response([
            'success' => false,
            'code' => 'qualification_in_progress',
            'message' => (string) ($run['message'] ?? 'عملية التحقق قيد التنفيذ حالياً.'),
            'in_progress' => true,
        ], 409);
    }

    $ok = (bool) ($run['success'] ?? false);
    json_response([
        'success' => $ok,
        'message' => (string) ($run['message'] ?? ($ok ? 'تم التحقق من الحزمة بنجاح.' : 'فشل التحقق من الحزمة.')),
        'short_circuited' => (bool) ($run['short_circuited'] ?? false),
        'heavy_executed' => (bool) ($run['heavy_executed'] ?? false),
        'result' => $run['result'] ?? null,
    ], $ok ? 200 : 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
