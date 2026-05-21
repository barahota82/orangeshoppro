<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../../includes/catalog_unified_nav.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_admin_api();

/**
 * بحث عن متغيرات منتجات نشطة لمنتقي عروض السلة في الأدمن (إعدادات / واجهة).
 * - action: filter_tree — أقسام/فئات/فئات فرعية للتصفية الهرمية.
 * - department_id / category_id / subcategory_id — تصفية النتائج (0 = الكل).
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $raw = get_json_input();
    if (!is_array($raw) || count($raw) === 0) {
        $raw = $_POST;
    }

    if (($raw['action'] ?? '') === 'filter_tree') {
        $unifiedFt = orange_catalog_nav_use_unified($pdo)
            && orange_table_exists($pdo, 'departments')
            && orange_table_exists($pdo, 'catalog_sections');

        if ($unifiedFt) {
            $pack = orange_storefront_unified_nav_for_home($pdo);
            $ucats = $pack['categories'] ?? [];
            $departments = $pack['departments'] ?? [];
            $categories = [];
            foreach (is_array($ucats) ? $ucats : [] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                $did = $c['department_id'] ?? null;
                $categories[] = [
                    'id' => (int) ($c['id'] ?? 0),
                    'name_ar' => (string) ($c['name_ar'] ?? ''),
                    'name_en' => (string) ($c['name_en'] ?? ''),
                    'department_id' => $did !== null && $did !== '' ? (int) $did : null,
                ];
            }
            $subcategories = [];
            $subsMap = $pack['subcategoriesByCategory'] ?? [];
            foreach ($subsMap as $cid => $list) {
                if (!is_array($list)) {
                    continue;
                }
                foreach ($list as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    $scid = (int) ($s['catalog_category_id'] ?? $cid);
                    $subcategories[] = [
                        'id' => (int) ($s['id'] ?? 0),
                        'category_id' => $scid,
                        'name_ar' => (string) ($s['name_ar'] ?? ''),
                        'name_en' => (string) ($s['name_en'] ?? ''),
                    ];
                }
            }
            json_response([
                'success' => true,
                'filter_tree_source' => 'unified',
                'departments' => is_array($departments) ? $departments : [],
                'categories' => $categories,
                'subcategories' => $subcategories,
            ]);
        }

        json_response([
            'success' => true,
            'filter_tree_source' => 'none',
            'departments' => [],
            'categories' => [],
            'subcategories' => [],
        ]);
    }

    $q = trim((string) ($raw['q'] ?? ''));
    $limit = (int) ($raw['limit'] ?? 80);
    if ($limit < 1) {
        $limit = 1;
    }
    if ($limit > 120) {
        $limit = 120;
    }

    $departmentId = (int) ($raw['department_id'] ?? 0);
    $categoryId = (int) ($raw['category_id'] ?? 0);
    $subcategoryId = (int) ($raw['subcategory_id'] ?? 0);

    $pickerCountryId = orange_admin_context_country_id($pdo);
    $pickerCountrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $pickerCountryId);
    $wQtyPicker = orange_warehouse_effective_qty_sql($pdo, $pickerCountryId, 'pv', 'wvs_pick');

    $unifiedPicker = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo)
        && orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories');

    if ($unifiedPicker) {
        /* قائمة الشجرة تُحمَّل عبر filter_tree من catalog_*؛ التصفية تستخدم معرفات ucc.id / ucs.id / d.id. */
        $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, ' . $wQtyPicker['expr'] . ' AS stock_quantity,
                       p.id AS product_id, p.name AS product_name, p.name_en AS product_name_en
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id AND p.is_active = 1'
                . $wQtyPicker['join']
                . '
                INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
                INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
                INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
                INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
                INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
                WHERE 1=1' . $pickerCountrySql;
        $params = [];
        if ($subcategoryId > 0) {
            $sql .= ' AND ucs.id = ?';
            $params[] = $subcategoryId;
        } elseif ($categoryId > 0) {
            $sql .= ' AND ucc.id = ?';
            $params[] = $categoryId;
        } elseif ($departmentId > 0) {
            $sql .= ' AND d.id = ?';
            $params[] = $departmentId;
        }
    } else {
        $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, ' . $wQtyPicker['expr'] . ' AS stock_quantity,
                       p.id AS product_id, p.name AS product_name, p.name_en AS product_name_en
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id AND p.is_active = 1'
                . $wQtyPicker['join']
                . '
                WHERE 1=1' . $pickerCountrySql;
        $params = [];
    }

    if ($q !== '') {
        $sql .= ' AND (
            p.name LIKE ? OR p.name_en LIKE ?
            OR CAST(pv.id AS CHAR) = ? OR CAST(p.id AS CHAR) = ?
            OR CONCAT(pv.color, \' \', pv.size) LIKE ?
        )';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $q, $q, $like);
    }
    $sql .= ' ORDER BY p.name ASC, pv.color ASC, pv.size ASC, pv.id ASC LIMIT ' . (string) $limit;

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'variant_id' => (int) ($r['variant_id'] ?? 0),
            'product_id' => (int) ($r['product_id'] ?? 0),
            'product_name' => (string) ($r['product_name'] ?? ''),
            'product_name_en' => (string) ($r['product_name_en'] ?? ''),
            'color' => (string) ($r['color'] ?? ''),
            'size' => (string) ($r['size'] ?? ''),
            'stock_quantity' => (int) ($r['stock_quantity'] ?? 0),
        ];
    }

    json_response(['success' => true, 'variants' => $out]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر البحث عن المتغيرات');
}
