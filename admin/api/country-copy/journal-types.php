<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/country_selective_copy.php';
require_admin_api();

try {
    $admin = current_admin();
    if ($admin === null || !orange_admin_has_full_access($admin)) {
        json_response(['success' => false, 'message' => 'نسخ بين الدول — للمشرف العام (وصول كامل / مبدّل الدول) فقط'], 403);
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    $targetCountryId = (int) ($data['target_country_id'] ?? 0);
    $sourceCountryId = orange_admin_settings_effective_country_id($pdo);
    $ids = $data['journal_type_ids'] ?? [];
    if (!is_array($ids)) {
        json_response(['success' => false, 'message' => 'قائمة المعرفات غير صالحة'], 422);
    }

    $result = orange_country_copy_journal_types_selective($pdo, $sourceCountryId, $targetCountryId, $ids);
    $status = $result['success'] ? 200 : 422;
    json_response(array_merge(['success' => $result['success']], $result), $status);
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] country-copy journal-types: ' . $e->getMessage());
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
