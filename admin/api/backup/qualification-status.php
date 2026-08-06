<?php

declare(strict_types=1);

/**
 * Stage 4B — read-only per-package qualification status (server-authoritative).
 * Identity: package_type + package_id + country_code (no arbitrary filesystem path).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../includes/backup/backup_qualification.php';

backup_admin_api_require_get();

try {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $admin = backup_admin_api_admin();
    $pdo = backup_admin_api_pdo();
    orange_backup_admin_require_view($admin, $pdo);

    $packageType = trim((string) ($_GET['package_type'] ?? ''));
    $packageId = trim((string) ($_GET['package_id'] ?? ''));
    $countryCode = trim((string) ($_GET['country_code'] ?? ''));

    // Reject path-like client inputs (public identity only).
    if ($packageId === '' || str_contains($packageId, '/') || str_contains($packageId, '\\')
        || str_contains($packageId, '..') || preg_match('#^[a-zA-Z]:#', $packageId)) {
        json_response(['success' => false, 'code' => 'unsafe_package_id', 'message' => 'معرّف الحزمة غير صالح.'], 422);
    }
    if ($countryCode !== '' && (str_contains($countryCode, '/') || str_contains($countryCode, '\\')
        || str_contains($countryCode, '..'))) {
        json_response(['success' => false, 'code' => 'unsafe_country_code', 'message' => 'رمز الدولة غير صالح.'], 422);
    }

    $ctx = orange_backup_admin_context_for_view(backup_admin_api_project_root());
    $backupRoot = (string) $ctx['backup_root'];

    if ($packageType === 'country_recovery') {
        orange_backup_admin_assert_country_package_in_context($pdo, $countryCode);
    } elseif ($packageType !== 'full_disaster') {
        json_response(['success' => false, 'code' => 'unsupported_package_type', 'message' => 'نوع الحزمة غير مدعوم.'], 422);
    }

    $status = orange_backup_qualification_public_status(
        $backupRoot,
        $packageType,
        $packageId,
        $countryCode,
        $admin,
        $pdo
    );

    if (empty($status['ok'])) {
        $code = (string) ($status['code'] ?? 'resolve_failed');
        $http = match ($code) {
            'permission_denied', 'country_scope_denied' => 403,
            'unsafe_package_id', 'unsafe_country_code', 'unsupported_package_type' => 422,
            default => 404,
        };
        json_response([
            'success' => false,
            'code' => $code,
            'message' => (string) ($status['message'] ?? 'تعذر قراءة حالة التأهيل.'),
        ], $http);
    }

    json_response([
        'success' => true,
        'qualification' => [
            'package' => $status['package'],
            'verify' => $status['verify'],
            'drv' => $status['drv'],
        ],
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, backup_admin_api_safe_message($e));
}
