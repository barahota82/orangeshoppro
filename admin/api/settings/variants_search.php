<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
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
        $departments = [];
        if (orange_table_exists($pdo, 'departments')) {
            $departments = $pdo->query(
                'SELECT id, name_ar, name_en FROM departments WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        $categories = [];
        if (orange_table_exists($pdo, 'categories')) {
            $hasDept = orange_table_has_column($pdo, 'categories', 'department_id');
            $hasCatActive = orange_table_has_column($pdo, 'categories', 'is_active');
            $deptCol = $hasDept ? 'department_id' : 'NULL AS department_id';
            $catWhere = $hasCatActive ? ' WHERE is_active = 1' : '';
            $categories = $pdo->query(
                "SELECT id, name_ar, name_en, {$deptCol} FROM categories{$catWhere} ORDER BY sort_order ASC, id ASC"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($categories as &$c) {
                $c['department_id'] = isset($c['department_id']) && $c['department_id'] !== null
                    ? (int) $c['department_id']
                    : null;
            }
            unset($c);
        }
        $subcategories = [];
        if (orange_table_exists($pdo, 'subcategories')) {
            $hasSubActive = orange_table_has_column($pdo, 'subcategories', 'is_active');
            $subWhere = $hasSubActive ? ' WHERE is_active = 1' : '';
            $subcategories = $pdo->query(
                'SELECT id, category_id, name_ar, name_en FROM subcategories' . $subWhere . ' ORDER BY sort_order ASC, id ASC'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        json_response([
            'success' => true,
            'departments' => $departments,
            'categories' => $categories,
            'subcategories' => $subcategories,
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

    $unifiedPicker = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo)
        && orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories');

    if ($unifiedPicker) {
        /* التصفية الهرمية في الواجهة ما زالت تعتمد أرقام الشجرة القديمة؛ لا تُربَط بـ catalog_* حتى يُحدَّث منتقي الأدمن.
           هنا نُقيّد النتائج بـ «سلسلة كتالوج نشطة» فقط + نص البحث. */
        $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, pv.stock_quantity,
                       p.id AS product_id, p.name AS product_name, p.name_en AS product_name_en
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id AND p.is_active = 1
                INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
                INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
                INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
                INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
                INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
                WHERE 1=1';
        $params = [];
    } else {
        $sql = 'SELECT pv.id AS variant_id, pv.color, pv.size, pv.stock_quantity,
                       p.id AS product_id, p.name AS product_name, p.name_en AS product_name_en
                FROM product_variants pv
                INNER JOIN products p ON p.id = pv.product_id';
        $params = [];

        $hasCat = orange_table_exists($pdo, 'categories');
        $joinCatForDept = $hasCat && $departmentId > 0 && orange_table_has_column($pdo, 'categories', 'department_id');
        if ($joinCatForDept) {
            $sql .= ' LEFT JOIN categories c ON c.id = p.category_id';
        }

        $sql .= ' WHERE p.is_active = 1';

        if ($joinCatForDept) {
            $sql .= ' AND c.department_id = ?';
            $params[] = $departmentId;
        }
        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        if ($subcategoryId > 0 && orange_table_has_column($pdo, 'products', 'subcategory_id')) {
            $sql .= ' AND p.subcategory_id = ?';
            $params[] = $subcategoryId;
        }
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
