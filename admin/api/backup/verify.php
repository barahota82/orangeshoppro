<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

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

    // Verify is read-only (checksum/manifest validation only) — view context is sufficient.
    $ctx = orange_backup_admin_context_for_view(backup_admin_api_project_root());
    if ($packageType === 'full_disaster') {
        $packagePath = orange_backup_admin_resolve_full_package_path($ctx['backup_root'], $packageId);
    } elseif ($packageType === 'country_recovery') {
        orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
        $packagePath = orange_backup_admin_resolve_country_package_path($ctx['backup_root'], $countryCode, $packageId);
    } else {
        json_response(['success' => false, 'message' => 'نوع الحزمة غير مدعوم'], 422);
    }

    $startedAt = gmdate('c');
    $result = orange_backup_admin_verify_package($packageType, $packagePath);
    $finishedAt = gmdate('c');
    $ok = (bool) ($result['ok'] ?? false);

    orange_backup_admin_audit(
        'verify',
        $packageType,
        $packageType === 'country_recovery' ? strtoupper($countryCode) . '/' . $packageId : $packageId,
        $startedAt,
        $finishedAt,
        $ok,
        $ok ? '' : implode('; ', $result['errors'] ?? [])
    );

    json_response([
        'success' => $ok,
        'message' => $ok ? 'تم التحقق من الحزمة بنجاح.' : 'فشل التحقق من الحزمة.',
        'result' => $result,
    ], $ok ? 200 : 422);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
