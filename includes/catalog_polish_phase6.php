<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';

/**
 * @return array{
 *   seo_backfilled:int,
 *   filterable_attrs:int,
 *   pt_default_guide_col:bool,
 *   products_missing_seo:int
 * }
 */
function orange_catalog_phase6_gap_report(PDO $pdo): array
{
    $filterable = 0;
    $missingSeo = 0;
    if (orange_table_exists($pdo, 'catalog_attributes')) {
        try {
            $filterable = (int) $pdo->query(
                'SELECT COUNT(*) FROM catalog_attributes WHERE is_active = 1 AND is_filterable = 1'
            )->fetchColumn();
        } catch (Throwable $e) {
            $filterable = -1;
        }
    }
    if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'seo_meta_title_ar')) {
        try {
            $missingSeo = (int) $pdo->query(
                "SELECT COUNT(*) FROM products p WHERE p.is_active = 1
                 AND (
                     TRIM(COALESCE(p.seo_meta_title_ar, '')) = ''
                     OR TRIM(COALESCE(p.seo_meta_description_ar, '')) = ''
                 )"
            )->fetchColumn();
        } catch (Throwable $e) {
            $missingSeo = -1;
        }
    }

    return [
        'seo_backfilled' => 0,
        'filterable_attrs' => $filterable,
        'pt_default_guide_col' => orange_table_has_column($pdo, 'product_types', 'default_advisory_sizing_guide_id'),
        'products_missing_seo' => $missingSeo,
    ];
}

/**
 * يملأ seo_meta_* الفارغة من الاسم/الوصف (idempotent — لا يستبدل محتوى موجود).
 */
function orange_catalog_backfill_product_seo_meta(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'products')) {
        return 0;
    }
    $pairs = [
        ['seo_meta_title_ar', 'name', 'seo_meta_description_ar', 'description'],
        ['seo_meta_title_en', 'name_en', 'seo_meta_description_en', 'description_en'],
        ['seo_meta_title_fil', 'name_fil', 'seo_meta_description_fil', 'description_fil'],
        ['seo_meta_title_hi', 'name_hi', 'seo_meta_description_hi', 'description_hi'],
    ];
    $updated = 0;
    foreach ($pairs as [$titleCol, $nameCol, $descCol, $srcDescCol]) {
        if (!orange_table_has_column($pdo, 'products', $titleCol)) {
            continue;
        }
        try {
            if (orange_table_has_column($pdo, 'products', $descCol) && orange_table_has_column($pdo, 'products', $srcDescCol)) {
                $updated += (int) $pdo->exec(
                    'UPDATE products SET '
                    . $titleCol . ' = CASE WHEN TRIM(COALESCE(' . $titleCol . ", '')) = '' AND TRIM(COALESCE(" . $nameCol . ", '')) <> '' THEN LEFT(TRIM(" . $nameCol . '), 191) ELSE ' . $titleCol . ' END, '
                    . $descCol . ' = CASE WHEN TRIM(COALESCE(' . $descCol . ", '')) = '' AND TRIM(COALESCE(" . $srcDescCol . ", '')) <> '' THEN LEFT(TRIM(REPLACE(REPLACE(REPLACE(" . $srcDescCol . ", '<', ''), '>', ''), '&nbsp;', ' ')), 500) ELSE " . $descCol . ' END'
                );
            } else {
                $updated += (int) $pdo->exec(
                    'UPDATE products SET ' . $titleCol . ' = LEFT(TRIM(' . $nameCol . '), 191)
                     WHERE TRIM(COALESCE(' . $titleCol . ", '')) = '' AND TRIM(COALESCE(" . $nameCol . ", '')) <> ''"
                );
            }
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] orange_catalog_backfill_product_seo_meta: ' . $e->getMessage());
            }
        }
    }

    return $updated;
}

/**
 * المرحلة 6 — polish: SEO backfill + probe سريع.
 */
function orange_catalog_ensure_polish_phase6(PDO $pdo): void
{
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return;
    }

    static $probeDone = false;
    if ($probeDone) {
        return;
    }

    if (!orange_table_exists($pdo, 'products') || !orange_table_has_column($pdo, 'products', 'seo_meta_title_ar')) {
        $probeDone = true;

        return;
    }

    try {
        $missing = (int) $pdo->query(
            "SELECT COUNT(*) FROM products WHERE is_active = 1 AND TRIM(COALESCE(seo_meta_title_ar, '')) = '' LIMIT 1"
        )->fetchColumn();
        if ($missing > 0) {
            orange_catalog_backfill_product_seo_meta($pdo);
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_polish_phase6: ' . $e->getMessage());
        }
    }

    $probeDone = true;
}
