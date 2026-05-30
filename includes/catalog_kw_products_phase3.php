<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/catalog_unified_product_helpers.php';
require_once __DIR__ . '/department_countries.php';
require_once __DIR__ . '/countries.php';

/**
 * @return array{missing_product_type:int,missing_variants:int,missing_attributes:int}
 */
function orange_catalog_kw_products_phase3_gap_counts(PDO $pdo, int $countryId): array
{
    $out = ['missing_product_type' => 0, 'missing_variants' => 0, 'missing_attributes' => 0];
    if ($countryId <= 0 || !orange_table_exists($pdo, 'products')) {
        return $out;
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $depSql = orange_department_country_active_sql($pdo, 'd', $countryId);

    try {
        if (orange_table_has_column($pdo, 'products', 'product_type_id') && orange_table_exists($pdo, 'product_types')) {
            $out['missing_product_type'] = (int) $pdo->query(
                'SELECT COUNT(*) FROM products p
                 WHERE p.is_active = 1' . $countrySql . '
                   AND (
                       p.product_type_id IS NULL OR p.product_type_id <= 0
                       OR NOT EXISTS (
                           SELECT 1 FROM product_types pt
                           INNER JOIN catalog_subcategories ucs ON ucs.id = pt.catalog_subcategory_id AND ucs.is_active = 1
                           INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
                           INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
                           INNER JOIN departments d ON d.id = cs.department_id AND (' . $depSql . ')
                           WHERE pt.id = p.product_type_id AND pt.is_active = 1
                       )
                   )'
            )->fetchColumn();
        }

        if (orange_table_exists($pdo, 'product_variants')) {
            $out['missing_variants'] = (int) $pdo->query(
                'SELECT COUNT(*) FROM products p
                 WHERE p.is_active = 1' . $countrySql . '
                   AND (COALESCE(p.has_colors, 0) = 0 AND COALESCE(p.has_sizes, 0) = 0)
                   AND NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id)'
            )->fetchColumn();
        }

        if (
            orange_table_exists($pdo, 'catalog_attributes')
            && orange_table_exists($pdo, 'product_attribute_values')
            && (int) $pdo->query('SELECT COUNT(*) FROM catalog_attributes WHERE is_active = 1')->fetchColumn() > 0
        ) {
            $out['missing_attributes'] = (int) $pdo->query(
                'SELECT COUNT(*) FROM products p
                 WHERE p.is_active = 1' . $countrySql . '
                   AND p.product_type_id IS NOT NULL AND p.product_type_id > 0
                   AND NOT EXISTS (SELECT 1 FROM product_attribute_values pav WHERE pav.product_id = p.id)
                   AND EXISTS (
                       SELECT 1 FROM products p2
                       INNER JOIN product_attribute_values pav2 ON pav2.product_id = p2.id
                       WHERE p2.product_type_id = p.product_type_id AND p2.id <> p.id
                   )'
            )->fetchColumn();
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_kw_products_phase3_gap_counts: ' . $e->getMessage());
        }
    }

    return $out;
}

function orange_catalog_backfill_kw_product_type_ids(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return 0;
    }

    orange_catalog_backfill_product_type_ids_only($pdo);

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $updated = 0;

    try {
        if (orange_table_has_column($pdo, 'products', 'subcategory_id')) {
            $updated += (int) $pdo->exec(
                'UPDATE products p
                 INNER JOIN catalog_subcategories ucs ON ucs.slug = CONCAT(\'legacy-sub-\', p.subcategory_id) AND ucs.is_active = 1
                 INNER JOIN (
                     SELECT catalog_subcategory_id, MIN(id) AS pt_id
                     FROM product_types WHERE is_active = 1 GROUP BY catalog_subcategory_id
                 ) pick ON pick.catalog_subcategory_id = ucs.id
                 SET p.product_type_id = pick.pt_id
                 WHERE p.is_active = 1' . $countrySql . '
                   AND p.subcategory_id IS NOT NULL AND p.subcategory_id > 0
                   AND (p.product_type_id IS NULL OR p.product_type_id <= 0)'
            );
        }

        if (orange_table_has_column($pdo, 'products', 'category_id')) {
            $subNull = orange_table_has_column($pdo, 'products', 'subcategory_id')
                ? ' AND (p.subcategory_id IS NULL OR p.subcategory_id = 0)'
                : '';
            $updated += (int) $pdo->exec(
                'UPDATE products p
                 INNER JOIN product_types pt ON pt.slug = CONCAT(\'legacy-ptype-cat-\', p.category_id) AND pt.is_active = 1
                 SET p.product_type_id = pt.id
                 WHERE p.is_active = 1' . $countrySql . $subNull . '
                   AND p.category_id IS NOT NULL AND p.category_id > 0
                   AND (p.product_type_id IS NULL OR p.product_type_id <= 0)'
            );
        }

        $depSql = orange_department_country_active_sql($pdo, 'd', $countryId);
        $updated += (int) $pdo->exec(
            'UPDATE products p
             INNER JOIN product_types pt_bad ON pt_bad.id = p.product_type_id
             INNER JOIN (
                 SELECT catalog_subcategory_id, MIN(id) AS pt_id
                 FROM product_types WHERE is_active = 1 GROUP BY catalog_subcategory_id
             ) pick ON pick.catalog_subcategory_id = pt_bad.catalog_subcategory_id
             SET p.product_type_id = pick.pt_id
             WHERE p.is_active = 1' . $countrySql . '
               AND p.product_type_id > 0 AND pick.pt_id <> p.product_type_id
               AND (
                   pt_bad.is_active = 0
                   OR NOT EXISTS (
                       SELECT 1 FROM catalog_subcategories ucs
                       INNER JOIN catalog_categories ucc ON ucc.id = ucs.catalog_category_id AND ucc.is_active = 1
                       INNER JOIN catalog_sections cs ON cs.id = ucc.catalog_section_id AND cs.is_active = 1
                       INNER JOIN departments d ON d.id = cs.department_id AND (' . $depSql . ')
                       WHERE ucs.id = pt_bad.catalog_subcategory_id AND ucs.is_active = 1
                   )
               )'
        );
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_backfill_kw_product_type_ids: ' . $e->getMessage());
        }
    }

    return $updated;
}

function orange_catalog_backfill_kw_product_size_families(PDO $pdo, int $countryId): int
{
    if (
        $countryId <= 0
        || !orange_table_exists($pdo, 'size_families')
        || !orange_table_has_column($pdo, 'products', 'size_family_id')
        || !orange_table_has_column($pdo, 'product_types', 'expected_commercial_kind_key')
    ) {
        return 0;
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $updated = 0;

    try {
        $rows = $pdo->query(
            'SELECT p.id AS pid, pt.expected_commercial_kind_key AS ck, pt.expected_sizing_category_key AS sk
             FROM products p
             INNER JOIN product_types pt ON pt.id = p.product_type_id AND pt.is_active = 1
             WHERE p.is_active = 1' . $countrySql . '
               AND COALESCE(p.has_sizes, 0) = 1
               AND (p.size_family_id IS NULL OR p.size_family_id <= 0)
               AND pt.expected_commercial_kind_key <> \'\'
               AND pt.expected_sizing_category_key <> \'\''
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $findFam = $pdo->prepare(
            'SELECT id FROM size_families
             WHERE is_active = 1 AND commercial_kind_key = ? AND sizing_category_key = ?
             ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $up = $pdo->prepare('UPDATE products SET size_family_id = ? WHERE id = ? LIMIT 1');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['pid'] ?? 0);
            $ck = trim((string) ($row['ck'] ?? ''));
            $sk = trim((string) ($row['sk'] ?? ''));
            if ($pid <= 0 || $ck === '' || $sk === '') {
                continue;
            }
            $findFam->execute([$ck, $sk]);
            $fid = (int) ($findFam->fetchColumn() ?: 0);
            if ($fid <= 0) {
                continue;
            }
            $up->execute([$fid, $pid]);
            $updated++;
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_backfill_kw_product_size_families: ' . $e->getMessage());
        }
    }

    return $updated;
}

function orange_catalog_ensure_kw_default_product_variants(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'product_variants')) {
        return 0;
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $inserted = 0;

    try {
        $rows = $pdo->query(
            'SELECT p.id FROM products p
             WHERE p.is_active = 1' . $countrySql . '
               AND COALESCE(p.has_colors, 0) = 0 AND COALESCE(p.has_sizes, 0) = 0
               AND NOT EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id)
             ORDER BY p.id ASC'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $ins = $pdo->prepare(
            'INSERT INTO product_variants (product_id, product_colorway_id, size_family_size_id, size, color, stock_quantity)
             VALUES (?, NULL, NULL, \'\', \'\', 0)'
        );

        foreach ($rows as $pidRaw) {
            $pid = (int) $pidRaw;
            if ($pid <= 0) {
                continue;
            }
            $ins->execute([$pid]);
            $inserted++;
            if (function_exists('orange_catalog_refresh_product_barcodes')) {
                try {
                    orange_catalog_refresh_product_barcodes($pdo, $pid);
                } catch (Throwable $e) {
                    // non-fatal
                }
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_kw_default_product_variants: ' . $e->getMessage());
        }
    }

    return $inserted;
}

function orange_catalog_copy_kw_product_attributes_from_type_template(PDO $pdo, int $countryId): int
{
    if (
        $countryId <= 0
        || !orange_table_exists($pdo, 'product_attribute_values')
        || !orange_table_exists($pdo, 'catalog_attributes')
    ) {
        return 0;
    }

    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $copied = 0;

    try {
        $targets = $pdo->query(
            'SELECT p.id AS pid, p.product_type_id AS pt_id
             FROM products p
             WHERE p.is_active = 1' . $countrySql . '
               AND p.product_type_id IS NOT NULL AND p.product_type_id > 0
               AND NOT EXISTS (SELECT 1 FROM product_attribute_values pav WHERE pav.product_id = p.id)
               AND EXISTS (
                   SELECT 1 FROM products p2
                   INNER JOIN product_attribute_values pav2 ON pav2.product_id = p2.id
                   WHERE p2.product_type_id = p.product_type_id AND p2.id <> p.id
               )
             ORDER BY p.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $donorSt = $pdo->prepare(
            'SELECT p2.id AS donor_id
             FROM products p2
             INNER JOIN product_attribute_values pav2 ON pav2.product_id = p2.id
             WHERE p2.product_type_id = ? AND p2.id <> ?
             GROUP BY p2.id
             ORDER BY COUNT(pav2.id) DESC, p2.id ASC
             LIMIT 1'
        );
        $copySt = $pdo->prepare(
            'INSERT IGNORE INTO product_attribute_values (product_id, catalog_attribute_id, value_raw)
             SELECT ?, pav.catalog_attribute_id, pav.value_raw
             FROM product_attribute_values pav
             INNER JOIN catalog_attributes ca ON ca.id = pav.catalog_attribute_id AND ca.is_active = 1
             WHERE pav.product_id = ?'
        );

        foreach ($targets as $t) {
            if (!is_array($t)) {
                continue;
            }
            $pid = (int) ($t['pid'] ?? 0);
            $ptId = (int) ($t['pt_id'] ?? 0);
            if ($pid <= 0 || $ptId <= 0) {
                continue;
            }
            $donorSt->execute([$ptId, $pid]);
            $donorId = (int) ($donorSt->fetchColumn() ?: 0);
            if ($donorId <= 0) {
                continue;
            }
            $copySt->execute([$pid, $donorId]);
            if ($copySt->rowCount() > 0) {
                $copied++;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_copy_kw_product_attributes_from_type_template: ' . $e->getMessage());
        }
    }

    return $copied;
}

/**
 * مرحلة 3: product_type + variants + صفات (من قالب نفس النوع) + size_family — idempotent.
 *
 * @return array{product_type_fixed:int,size_families:int,variants:int,attributes_copied:int,gaps:array<string,int>}
 */
function orange_catalog_ensure_kw_products_phase3(PDO $pdo): array
{
    $stats = [
        'product_type_fixed' => 0,
        'size_families' => 0,
        'variants' => 0,
        'attributes_copied' => 0,
        'gaps' => ['missing_product_type' => 0, 'missing_variants' => 0, 'missing_attributes' => 0],
    ];

    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return $stats;
    }

    $countryId = orange_countries_default_id($pdo);
    if ($countryId <= 0) {
        return $stats;
    }

    $gapsBefore = orange_catalog_kw_products_phase3_gap_counts($pdo, $countryId);
    $stats['gaps'] = $gapsBefore;

    if ($gapsBefore['missing_product_type'] > 0) {
        $stats['product_type_fixed'] = orange_catalog_backfill_kw_product_type_ids($pdo, $countryId);
    }
    $stats['size_families'] = orange_catalog_backfill_kw_product_size_families($pdo, $countryId);
    if ($gapsBefore['missing_variants'] > 0) {
        $stats['variants'] = orange_catalog_ensure_kw_default_product_variants($pdo, $countryId);
    }
    if ($gapsBefore['missing_attributes'] > 0) {
        $stats['attributes_copied'] = orange_catalog_copy_kw_product_attributes_from_type_template($pdo, $countryId);
    }

    $stats['gaps'] = orange_catalog_kw_products_phase3_gap_counts($pdo, $countryId);

    if ($stats['product_type_fixed'] > 0 || $stats['variants'] > 0 || $stats['attributes_copied'] > 0) {
        if (function_exists('error_log')) {
            error_log(
                '[orange] kw products phase3: pt=' . $stats['product_type_fixed']
                . ' sf=' . $stats['size_families']
                . ' var=' . $stats['variants']
                . ' attr=' . $stats['attributes_copied']
            );
        }
    }

    return $stats;
}
