<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';

/**
 * @return bool true when product_type_id column is NOT NULL in schema
 */
function orange_catalog_phase5_product_type_id_not_null_applied(PDO $pdo): bool
{
    if (!orange_table_exists($pdo, 'products') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT IS_NULLABLE
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?'
        );
        $st->execute(['products', 'product_type_id']);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && strtoupper((string) ($row['IS_NULLABLE'] ?? '')) === 'NO';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array{
 *   unified_nav:bool,
 *   bad_product_type:int,
 *   legacy_category_column:bool,
 *   legacy_subcategory_column:bool,
 *   product_type_id_not_null:bool
 * }
 */
function orange_catalog_phase5_gap_report(PDO $pdo): array
{
    $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
    $bad = 0;
    if ($unified && orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'product_type_id')) {
        try {
            $bad = (int) $pdo->query(
                'SELECT COUNT(*) FROM products p WHERE p.product_type_id IS NULL OR p.product_type_id <= 0 OR NOT EXISTS (
                    SELECT 1 FROM product_types pt WHERE pt.id = p.product_type_id
                )'
            )->fetchColumn();
        } catch (Throwable $e) {
            $bad = -1;
        }
    }

    return [
        'unified_nav' => $unified,
        'bad_product_type' => $bad,
        'legacy_category_column' => orange_table_has_column($pdo, 'products', 'category_id'),
        'legacy_subcategory_column' => orange_table_has_column($pdo, 'products', 'subcategory_id'),
        'product_type_id_not_null' => orange_catalog_phase5_product_type_id_not_null_applied($pdo),
    ];
}

/**
 * المرحلة 5 — إغلاق legacy على المنتجات: NOT NULL على product_type_id ثم إسقاط category_id/subcategory_id.
 * idempotent؛ probe سريع ثم خروج عند اكتمال الإغلاق.
 */
function orange_catalog_ensure_legacy_closure_phase5(PDO $pdo): void
{
    if (!function_exists('orange_catalog_nav_use_unified') || !orange_catalog_nav_use_unified($pdo)) {
        return;
    }
    if (!orange_table_exists($pdo, 'products') || !orange_table_has_column($pdo, 'products', 'product_type_id')) {
        return;
    }

    $hasLegacyCols = orange_table_has_column($pdo, 'products', 'category_id')
        || orange_table_has_column($pdo, 'products', 'subcategory_id');
    $needsNotNull = ! orange_catalog_phase5_product_type_id_not_null_applied($pdo);

    if (! $hasLegacyCols && ! $needsNotNull) {
        return;
    }

    try {
        orange_catalog_ensure_products_product_type_id_not_null($pdo);
        orange_catalog_ensure_products_drop_legacy_classification_columns($pdo);
        orange_catalog_ensure_products_product_type_id_not_null($pdo);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_catalog_ensure_legacy_closure_phase5: ' . $e->getMessage());
        }
    }
}
