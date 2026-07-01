<?php

declare(strict_types=1);

/*
 * أداة إعادة ترتيب المنتجات (عرض المتجر) — قائمة منتجات فئة واحدة مرتبة بـ sort_order.
 * خفيفة: تحمّل منتجات الفئة المختارة فقط (النشطة، حسب دولة الأدمن، مستبعِدة مسودّات المعاينة).
 * الترتيب داخل الفئة هو ما يراه العميل (المتجر يجمّع حسب الفئة ويفرز بـ sort_order).
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/product_preview.php';
require_admin_api('GET');

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;
    if ($categoryId <= 0) {
        json_response(['success' => false, 'message' => 'E_CATEGORY'], 422);
    }

    if (
        !orange_table_exists($pdo, 'catalog_categories')
        || !orange_table_exists($pdo, 'catalog_subcategories')
        || !orange_table_exists($pdo, 'product_types')
        || !orange_table_has_column($pdo, 'products', 'product_type_id')
    ) {
        json_response(['success' => false, 'message' => 'E_SCHEMA'], 422);
    }

    $adminCountryId = orange_admin_context_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $adminCountryId);
    $countrySql .= orange_preview_hide_sql($pdo, 'p');

    $nameEnSel = orange_table_has_column($pdo, 'products', 'name_en') ? 'p.name_en' : "'' AS name_en";
    $codeSel = orange_table_has_column($pdo, 'products', 'item_code') ? 'p.item_code' : "'' AS item_code";
    $imgSel = orange_table_has_column($pdo, 'products', 'main_image') ? 'p.main_image' : "'' AS main_image";

    $sql = 'SELECT p.id, p.name, ' . $nameEnSel . ', ' . $codeSel . ', ' . $imgSel . ', p.sort_order
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id
        WHERE ucc.id = :cat AND p.is_active = 1' . $countrySql . '
        ORDER BY p.sort_order ASC, p.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':cat' => $categoryId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id' => (int) $r['id'],
            'name' => (string) ($r['name'] ?? ''),
            'name_en' => (string) ($r['name_en'] ?? ''),
            'item_code' => (string) ($r['item_code'] ?? ''),
            'main_image' => (string) ($r['main_image'] ?? ''),
            'sort_order' => (int) ($r['sort_order'] ?? 0),
        ];
    }

    json_response(['success' => true, 'items' => $items, 'count' => count($items)]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل منتجات الفئة');
}
