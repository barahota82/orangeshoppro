<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_once __DIR__ . '/../../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/country_screen_copy.php';
require_admin_api();

try {
    $pdo = db();
    $admin = orange_admin_active_record($pdo);
    if ($admin === null || !orange_admin_has_full_access($admin)) {
        json_response(['success' => false, 'message' => 'نسخ بين الدول — للمشرف العام (وصول كامل) فقط'], 403);
    }

    orange_catalog_ensure_schema($pdo);
    orange_country_screen_copy_log_ensure($pdo);

    $sourceCountryId = orange_admin_settings_effective_country_id($pdo);
    $sourceLabel = orange_admin_page_country_label($pdo);

    $modules = [];
    foreach (orange_country_screen_copy_modules() as $key => $meta) {
        $modules[] = [
            'key' => $key,
            'label_ar' => (string) ($meta['label_ar'] ?? $key),
            'hint_ar' => (string) ($meta['hint_ar'] ?? ''),
            'selection' => (string) ($meta['selection'] ?? 'none'),
        ];
    }

    $countries = [];
    foreach (orange_countries_admin_list($pdo) as $c) {
        $cid = (int) ($c['id'] ?? 0);
        if ($cid <= 0 || $cid === $sourceCountryId) {
            continue;
        }
        $lbl = trim((string) ($c['name_ar'] ?? ''));
        if ($lbl === '') {
            $lbl = trim((string) ($c['name_en'] ?? ''));
        }
        if ($lbl === '') {
            $lbl = orange_countries_display_code((string) ($c['code'] ?? ''));
        }
        $countries[] = ['id' => $cid, 'label' => $lbl];
    }

    $logRows = orange_country_screen_copy_log_list($pdo, 30);
    $log = [];
    foreach ($logRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sk = (string) ($row['screen_key'] ?? '');
        $log[] = [
            'id' => (int) ($row['id'] ?? 0),
            'screen_key' => $sk,
            'screen_label' => orange_country_screen_copy_module_label($sk),
            'source_name' => (string) ($row['source_name_ar'] ?? ''),
            'target_name' => (string) ($row['target_name_ar'] ?? ''),
            'summary_ar' => (string) ($row['summary_ar'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    json_response([
        'success' => true,
        'source_country_id' => $sourceCountryId,
        'source_label' => $sourceLabel,
        'modules' => $modules,
        'target_countries' => $countries,
        'log' => $log,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تهيئة نسخ الشاشة');
}
