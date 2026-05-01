<?php

declare(strict_types=1);

/**
 * قائمة فئات للواجهة/القناة.
 * - عند الشجرة الموحّدة: صفوف من catalog_categories (نفس المعرفات التي يتوقعها get-products الموحَّد كـ category_id).
 * - في المسار القديم: الجدول categories كما كان سابقًا.
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_nav.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $pack = orange_storefront_unified_nav_for_home($pdo);
    $unifiedCategories = $pack['categories'] ?? [];
    if ($unifiedCategories !== []) {
        json_response([
            'success' => true,
            'unified' => true,
            'categories' => $unifiedCategories,
        ]);
    }

    if (!orange_table_has_column($pdo, 'products', 'category_id')) {
        json_response([
            'success' => true,
            'unified' => true,
            'categories' => [],
        ]);
    }

    $sql = "
        SELECT c.*
        FROM categories c
        WHERE c.is_active = 1
          AND EXISTS (
              SELECT 1
              FROM products p
              WHERE p.category_id = c.id
                AND p.is_active = 1
          )
        ORDER BY c.sort_order ASC, c.id ASC
    ";

    $categories = $pdo->query($sql)->fetchAll();

    json_response([
        'success' => true,
        'unified' => false,
        'categories' => $categories,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
