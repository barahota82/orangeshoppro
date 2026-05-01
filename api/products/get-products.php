<?php

declare(strict_types=1);

/**
 * قائمة منتجات للواجهة/القناة.
 * - عند تفعيل التصنيف الموحّد: category_id يشير إلى catalog_categories.id (مثل تصفية الصفحة الرئيسية الموحّدة).
 * - في المسار القديم: category_id يشير إلى categories.id على المنتج.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);

    if (
        $unified
        && function_exists('orange_table_exists')
        && orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories')
    ) {
        $sql = '
        SELECT p.*
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
        INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
        INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
        WHERE p.is_active = 1
    ';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND ucc.id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $sql = '
        SELECT p.*
        FROM products p
        INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
        WHERE p.is_active = 1
    ';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    $products = $stmt->fetchAll();

    json_response([
        'success' => true,
        'products' => $products,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
