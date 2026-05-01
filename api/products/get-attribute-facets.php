<?php

declare(strict_types=1);

/**
 * واجهة عامة: قيم «واجهات» (facets) لصفات قابلة للفلترة ضمن نطاق فئة (وفق المسار الموحّد أو القديم).
 *
 * GET:
 * - category_id — اختياري: عند المتجر الموحّد = catalog_categories.id؛ عند القديم = categories.id على المنتج.
 */
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_taxonomy_migrate.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $categoryId = isset($_GET['category_id']) ? (int) $_GET['category_id'] : 0;

    if (!orange_table_exists($pdo, 'catalog_attributes') || !orange_table_exists($pdo, 'product_attribute_values')) {
        json_response(['success' => true, 'facets' => [], 'unified' => false]);

        return;
    }

    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo)
        && orange_table_exists($pdo, 'product_types')
        && orange_table_exists($pdo, 'catalog_subcategories');

    $facets = [];

    if ($unified) {
        $sql = '
            SELECT ca.attribute_key AS k, ca.label_ar, ca.label_en, ca.label_fil, ca.label_hi,
                   pav.value_raw AS v,
                   COUNT(DISTINCT p.id) AS cnt
            FROM catalog_attributes ca
            INNER JOIN product_attribute_values pav ON pav.catalog_attribute_id = ca.id
            INNER JOIN products p ON p.id = pav.product_id AND p.is_active = 1
            INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
            INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
            INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
            INNER JOIN catalog_sections ucs2 ON ucs2.id = ucc.catalog_section_id AND ucs2.is_active = 1
            INNER JOIN departments d ON d.id = ucs2.department_id AND d.is_active = 1
            WHERE ca.is_active = 1 AND ca.is_filterable = 1
        ';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND ucc.id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' GROUP BY ca.id, ca.attribute_key, ca.label_ar, ca.label_en, ca.label_fil, ca.label_hi, pav.value_raw
                  ORDER BY ca.sort_order ASC, ca.id ASC, cnt DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $k = (string) ($r['k'] ?? '');
            if ($k === '') {
                continue;
            }
            $v = trim((string) ($r['v'] ?? ''));
            if ($v === '') {
                continue;
            }
            if (! isset($byKey[$k])) {
                $byKey[$k] = [
                    'attribute_key' => $k,
                    'label_ar' => (string) ($r['label_ar'] ?? ''),
                    'label_en' => (string) ($r['label_en'] ?? ''),
                    'label_fil' => (string) ($r['label_fil'] ?? ''),
                    'label_hi' => (string) ($r['label_hi'] ?? ''),
                    'values' => [],
                ];
            }
            $byKey[$k]['values'][] = ['value' => $v, 'count' => (int) ($r['cnt'] ?? 0)];
        }
        $facets = array_values($byKey);
    } else {
        $sql = '
            SELECT ca.attribute_key AS k, ca.label_ar, ca.label_en, ca.label_fil, ca.label_hi,
                   pav.value_raw AS v,
                   COUNT(DISTINCT p.id) AS cnt
            FROM catalog_attributes ca
            INNER JOIN product_attribute_values pav ON pav.catalog_attribute_id = ca.id
            INNER JOIN products p ON p.id = pav.product_id AND p.is_active = 1
            INNER JOIN categories c ON c.id = p.category_id AND c.is_active = 1
            WHERE ca.is_active = 1 AND ca.is_filterable = 1
        ';
        $params = [];
        if ($categoryId > 0) {
            $sql .= ' AND p.category_id = ?';
            $params[] = $categoryId;
        }
        $sql .= ' GROUP BY ca.id, ca.attribute_key, ca.label_ar, ca.label_en, ca.label_fil, ca.label_hi, pav.value_raw
                  ORDER BY ca.sort_order ASC, ca.id ASC, cnt DESC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $byKey = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (! is_array($r)) {
                continue;
            }
            $k = (string) ($r['k'] ?? '');
            if ($k === '') {
                continue;
            }
            $v = trim((string) ($r['v'] ?? ''));
            if ($v === '') {
                continue;
            }
            if (! isset($byKey[$k])) {
                $byKey[$k] = [
                    'attribute_key' => $k,
                    'label_ar' => (string) ($r['label_ar'] ?? ''),
                    'label_en' => (string) ($r['label_en'] ?? ''),
                    'label_fil' => (string) ($r['label_fil'] ?? ''),
                    'label_hi' => (string) ($r['label_hi'] ?? ''),
                    'values' => [],
                ];
            }
            $byKey[$k]['values'][] = ['value' => $v, 'count' => (int) ($r['cnt'] ?? 0)];
        }
        $facets = array_values($byKey);
    }

    json_response([
        'success' => true,
        'unified' => $unified,
        'facets' => $facets,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
