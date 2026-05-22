<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/department_countries.php';
require_once __DIR__ . '/countries.php';

/**
 * بيانات التنقل بعد ترحيل التصنيف الموحّد — استعلامات محصورة، لا شجرة عرض ثانية خارج الجاهزية.
 *
 * @return array{
 *   departments: list<array<string,mixed>>,
 *   categories: list<array<string,mixed>>,
 *   subcategoriesByCategory: array<int, list<array<string,mixed>>>,
 *   catsByDept: array<int, list<array<string,mixed>>>,
 *   categoryToDepartment: array<int, int>,
 * }
 */
function orange_storefront_unified_nav_for_home(PDO $pdo, ?int $countryId = null): array
{
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $depActiveD = orange_department_country_active_sql($pdo, 'd', $countryId);
    $depActiveDp = orange_department_country_active_sql($pdo, 'd_p', $countryId);
    $depActiveD2 = orange_department_country_active_sql($pdo, 'd2', $countryId);
    $empty = [
        'departments' => [],
        'categories' => [],
        'subcategoriesByCategory' => [],
        'catsByDept' => [],
        'categoryToDepartment' => [],
    ];

    if (!function_exists('orange_table_exists')) {
        return $empty;
    }

    if (!orange_catalog_nav_use_unified($pdo)) {
        return $empty;
    }

    if (!orange_table_exists($pdo, 'departments') || !orange_table_exists($pdo, 'catalog_sections')) {
        return $empty;
    }

    $categoryProductFilterSql = '
          AND EXISTS (
              SELECT 1
              FROM products p
              INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
              INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id
                AND ucs.is_active = 1
                AND ucs.catalog_category_id = cc.id
              INNER JOIN catalog_sections sec_p ON sec_p.id = cc.catalog_section_id AND sec_p.is_active = 1
              INNER JOIN departments d_p ON d_p.id = sec_p.department_id AND (' . $depActiveDp . ')
              WHERE p.is_active = 1
          )';

    $departmentActiveFilterSql = '
          AND (
              cs.department_id IS NULL
              OR d.id IS NULL
              OR (' . $depActiveD . ')
          )';

    $categoriesSql =
        '
        SELECT cc.*, cs.department_id AS department_id
        FROM catalog_categories cc
        INNER JOIN catalog_sections cs ON cs.id = cc.catalog_section_id AND cs.is_active = 1
        LEFT JOIN departments d ON d.id = cs.department_id
        WHERE cc.is_active = 1'
        . $departmentActiveFilterSql
        . $categoryProductFilterSql
        . '
        ORDER BY cc.sort_order ASC, cc.id ASC
    ';

    $catStmt = $pdo->query($categoriesSql);
    $categories = $catStmt ? $catStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $categories = is_array($categories) ? $categories : [];

    $subcategoriesByCategory = [];

    if (orange_table_exists($pdo, 'catalog_subcategories')) {
        $subsSql =
            '
            SELECT s.*, s.catalog_category_id AS category_id
            FROM catalog_subcategories s
            WHERE s.is_active = 1
              AND EXISTS (
                  SELECT 1
                  FROM product_types pt
                  INNER JOIN products p ON p.product_type_id = pt.id AND pt.is_active = 1 AND p.is_active = 1
                  INNER JOIN catalog_categories cc2 ON cc2.id = s.catalog_category_id AND cc2.is_active = 1
                  INNER JOIN catalog_sections sec2 ON sec2.id = cc2.catalog_section_id AND sec2.is_active = 1
                  INNER JOIN departments d2 ON d2.id = sec2.department_id AND (' . $depActiveD2 . ')
                  WHERE pt.catalog_subcategory_id = s.id
              )
            ORDER BY s.catalog_category_id ASC, s.sort_order ASC, s.id ASC
        ';
        $subStmt = $pdo->query($subsSql);
        $subsRows = $subStmt ? $subStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach (($subsRows ?: []) as $srow) {
            if (!is_array($srow)) {
                continue;
            }
            $scid = (int) ($srow['catalog_category_id'] ?? $srow['category_id'] ?? 0);
            if ($scid <= 0) {
                continue;
            }
            if (!isset($subcategoriesByCategory[$scid])) {
                $subcategoriesByCategory[$scid] = [];
            }
            $subcategoriesByCategory[$scid][] = $srow;
        }
    }

    $categoryToDepartment = [];
    foreach ($categories as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $cid = (int) ($cat['id'] ?? 0);
        if ($cid <= 0) {
            continue;
        }
        $did = isset($cat['department_id']) && $cat['department_id'] !== null
            ? (int) $cat['department_id']
            : 0;
        $categoryToDepartment[$cid] = $did;
    }

    $depListStmt = $pdo->query(
        '
        SELECT d.*
        FROM departments d
        WHERE (' . $depActiveD . ')
          AND EXISTS (
              SELECT 1
              FROM catalog_sections cs
              INNER JOIN catalog_categories cc ON cc.catalog_section_id = cs.id AND cc.is_active = 1
              INNER JOIN catalog_subcategories csub ON csub.catalog_category_id = cc.id AND csub.is_active = 1
              INNER JOIN product_types pt ON pt.catalog_subcategory_id = csub.id AND pt.is_active = 1
              INNER JOIN products p ON p.product_type_id = pt.id AND p.is_active = 1
              WHERE cs.department_id = d.id AND cs.is_active = 1
          )
        ORDER BY d.sort_order ASC, d.id ASC
    '
    );
    $departments = $depListStmt ? $depListStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $catsByDept = [];
    $deptIdsInMenu = array_map(static fn (array $d): int => (int) ($d['id'] ?? 0), is_array($departments) ? $departments : []);
    $deptIdsInMenu = array_values(array_filter($deptIdsInMenu, static fn (int $x): bool => $x > 0));

    foreach ($categories as $cat) {
        if (!is_array($cat)) {
            continue;
        }
        $did = isset($cat['department_id']) && $cat['department_id'] !== null ? (int) $cat['department_id'] : 0;
        if ($did > 0 && !in_array($did, $deptIdsInMenu, true)) {
            $did = 0;
        }
        if (!isset($catsByDept[$did])) {
            $catsByDept[$did] = [];
        }
        $catsByDept[$did][] = $cat;
    }

    return [
        'departments' => is_array($departments) ? $departments : [],
        'categories' => $categories,
        'subcategoriesByCategory' => $subcategoriesByCategory,
        'catsByDept' => $catsByDept,
        'categoryToDepartment' => $categoryToDepartment,
    ];
}
