<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/country_screen_copy.php';
require_admin_api();

try {
    $pdo = db();
    $admin = orange_admin_active_record($pdo);
    if ($admin === null || !orange_admin_has_full_access($admin)) {
        json_response(['success' => false, 'message' => 'نسخ بين الدول — للمشرف العام (وصول كامل) فقط'], 403);
    }

    orange_catalog_ensure_schema($pdo);

    $screenKey = trim((string) ($_GET['screen_key'] ?? ''));
    $sourceCountryId = orange_admin_settings_effective_country_id($pdo);

    $preview = orange_country_screen_copy_preview($pdo, $screenKey, $sourceCountryId);
    if (empty($preview['success'])) {
        json_response([
            'success' => false,
            'message' => (string) ($preview['message'] ?? 'فشل المعاينة'),
        ], 422);
    }

    json_response($preview);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر معاينة نسخ الشاشة');
}
