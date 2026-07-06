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
    $data = get_json_input();

    $screenKey = trim((string) ($data['screen_key'] ?? ''));
    $targetCountryId = (int) ($data['target_country_id'] ?? 0);
    $sourceCountryId = orange_admin_settings_effective_country_id($pdo);
    $adminId = (int) ($admin['id'] ?? 0);

    $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
    if (isset($data['account_ids']) && is_array($data['account_ids'])) {
        $payload['account_ids'] = $data['account_ids'];
    }
    if (isset($data['journal_type_ids']) && is_array($data['journal_type_ids'])) {
        $payload['journal_type_ids'] = $data['journal_type_ids'];
    }

    $result = orange_country_screen_copy_run(
        $pdo,
        $screenKey,
        $sourceCountryId,
        $targetCountryId,
        $payload,
        $adminId > 0 ? $adminId : null
    );
    $status = !empty($result['success']) ? 200 : 422;
    json_response($result, $status);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تنفيذ نسخ الشاشة');
}
