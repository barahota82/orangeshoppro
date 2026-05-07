<?php

declare(strict_types=1);

/**
 * قائمة فئات للواجهة/القناة — الشجرة الموحّدة فقط (catalog_categories كما في الصفحة الرئيسية).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_nav.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $pack = orange_storefront_unified_nav_for_home($pdo);
    $unifiedCategories = $pack['categories'] ?? [];

    json_response([
        'success' => true,
        'unified' => true,
        'categories' => is_array($unifiedCategories) ? $unifiedCategories : [],
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
