<?php

declare(strict_types=1);

/**
 * قائمة منتجات للواجهة/القناة — الشجرة الموحّدة فقط.
 * category_id في الطلب = catalog_categories.id (تصفية حسب فئة الكتالوج الموحّدة).
 * فلترة صفات الكتالوج: معاملات attr_{attribute_key}=value (صفات is_filterable فقط).
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/department_countries.php';
require_once __DIR__ . '/../../includes/product_preview.php';
require_once __DIR__ . '/../../includes/product_colorway_images.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
    $canServe = $unified
        && function_exists('orange_table_exists')
        && orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories')
        && orange_table_has_column($pdo, 'products', 'product_type_id');

    if (!$canServe) {
        json_response([
            'success' => true,
            'products' => [],
            'unified' => false,
        ]);

        return;
    }

    $sfCountryId = orange_storefront_current_country_id($pdo);
    $productsCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $sfCountryId);
    $productsCountrySql .= orange_preview_hide_sql($pdo, 'p');
    $depActiveSql = orange_department_country_active_sql($pdo, 'd', $sfCountryId);

    $sql = '
        SELECT p.*
        FROM products p
        INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
        INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
        INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
        INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
        INNER JOIN departments d ON d.id = ucs2.department_id AND (' . $depActiveSql . ')
        WHERE p.is_active = 1' . $productsCountrySql . '
    ';
    $params = [];
    if ($categoryId > 0) {
        $sql .= ' AND ucc.id = ?';
        $params[] = $categoryId;
    }
    [$sql, $params] = orange_storefront_products_append_attr_filters_sql($pdo, $sql, $params, 'p');
    $sql .= ' ORDER BY p.sort_order ASC, p.id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $products = $stmt->fetchAll();

    /* معاينة موقعية: أظهِر مسودّة الأدمن الحالي (لهذه الدولة) في سياق «الكل» فقط — لا تظهر للعميل. */
    if ($categoryId === 0) {
        $pvCtx = orange_preview_active_context($pdo);
        if ($pvCtx !== null) {
            $pvDraft = orange_preview_draft_card_for_country($pvCtx, $sfCountryId);
            if ($pvDraft !== null) {
                $pvDraft['is_preview_draft_card'] = 1;
                array_unshift($products, $pvDraft);
            }
        }
    }

    /* احتياط صورة الكارت: أوّل صورة لون عند غياب main_image (استعلام واحد، بلا N+1). */
    $imgFallbackIds = [];
    foreach ($products as $fbP) {
        if (trim((string) ($fbP['main_image'] ?? '')) === '') {
            $imgFallbackIds[] = (int) ($fbP['id'] ?? 0);
        }
    }
    if ($imgFallbackIds !== []) {
        $imgFallbackMap = orange_product_first_colorway_image_map($pdo, $imgFallbackIds);
        if ($imgFallbackMap !== []) {
            foreach ($products as $fbI => $fbP) {
                $fbPid = (int) ($fbP['id'] ?? 0);
                if (trim((string) ($fbP['main_image'] ?? '')) === '' && ! empty($imgFallbackMap[$fbPid])) {
                    $products[$fbI]['main_image'] = $imgFallbackMap[$fbPid];
                }
            }
        }
    }

    json_response([
        'success' => true,
        'unified' => true,
        'products' => $products,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
