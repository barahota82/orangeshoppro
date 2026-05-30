<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_multicountry_runtime.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/country_provision.php';

const ORANGE_MC_STOCK_SCOPED_STEP = 'multicountry_stock_scoped_v1';

/**
 * @return array{
 *   active_countries:int,
 *   countries_without_warehouse:int,
 *   products_missing_country_id:int,
 *   orders_missing_country_id:int,
 *   orders_warehouse_mismatch:int,
 *   stock_movements_missing_country:int,
 *   step_applied:bool,
 *   ready:bool
 * }
 */
function orange_multicountry_stock_gap_report(PDO $pdo): array
{
    $active = orange_catalog_active_country_ids($pdo);
    $withoutWh = 0;
    foreach ($active as $cid) {
        $st = orange_country_provision_status($pdo, $cid);
        if (empty($st['warehouse'])) {
            $withoutWh++;
        }
    }

    $productsMissing = 0;
    if (orange_table_exists($pdo, 'products') && orange_table_has_column($pdo, 'products', 'country_id')) {
        $productsMissing = (int) $pdo->query(
            'SELECT COUNT(*) FROM products WHERE country_id IS NULL OR country_id <= 0'
        )->fetchColumn();
    }

    $ordersMissing = 0;
    $ordersMismatch = 0;
    if (orange_table_exists($pdo, 'orders')) {
        if (orange_table_has_column($pdo, 'orders', 'country_id')) {
            $ordersMissing = (int) $pdo->query(
                'SELECT COUNT(*) FROM orders WHERE country_id IS NULL OR country_id <= 0'
            )->fetchColumn();
        }
        if (orange_table_has_column($pdo, 'orders', 'country_id')
            && orange_table_has_column($pdo, 'orders', 'warehouse_id')
            && orange_table_exists($pdo, 'warehouses')) {
            $ordersMismatch = (int) $pdo->query(
                'SELECT COUNT(*) FROM orders o
                 INNER JOIN warehouses w ON w.id = o.warehouse_id
                 WHERE o.warehouse_id IS NOT NULL AND o.warehouse_id > 0
                   AND o.country_id IS NOT NULL AND o.country_id > 0
                   AND w.country_id <> o.country_id'
            )->fetchColumn();
        }
    }

    $movementsMissing = 0;
    if (orange_table_exists($pdo, 'stock_movements')
        && orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
        $movementsMissing = (int) $pdo->query(
            'SELECT COUNT(*) FROM stock_movements WHERE country_id IS NULL OR country_id <= 0'
        )->fetchColumn();
    }

    $ready = count($active) > 0
        && $withoutWh === 0
        && $productsMissing === 0
        && $ordersMissing === 0
        && $ordersMismatch === 0;

    return [
        'active_countries' => count($active),
        'countries_without_warehouse' => $withoutWh,
        'products_missing_country_id' => $productsMissing,
        'orders_missing_country_id' => $ordersMissing,
        'orders_warehouse_mismatch' => $ordersMismatch,
        'stock_movements_missing_country' => $movementsMissing,
        'step_applied' => orange_catalog_migration_step_applied($pdo, ORANGE_MC_STOCK_SCOPED_STEP),
        'ready' => $ready,
    ];
}

/**
 * المرحلة 1 — backfill idempotent: country/warehouse على الطلبات · صفوف warehouse_variant_stock · provision مخازن.
 */
function orange_multicountry_ensure_stock_scoped_phase1(PDO $pdo): void
{
    if (orange_catalog_migration_step_applied($pdo, ORANGE_MC_STOCK_SCOPED_STEP)) {
        return;
    }
    if (!orange_table_exists($pdo, 'warehouses') || !orange_table_exists($pdo, 'products')) {
        return;
    }

    orange_catalog_backfill_products_country_id($pdo);

    foreach (orange_catalog_active_country_ids($pdo) as $countryId) {
        orange_warehouse_ensure_default_for_country($pdo, $countryId);
    }

    if (orange_table_exists($pdo, 'orders') && orange_table_has_column($pdo, 'orders', 'country_id')) {
        if (orange_table_exists($pdo, 'channels') && orange_table_has_column($pdo, 'channels', 'country_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders o
                 INNER JOIN channels c ON c.id = o.channel_id
                 SET o.country_id = c.country_id
                 WHERE (o.country_id IS NULL OR o.country_id = 0)
                   AND c.country_id IS NOT NULL AND c.country_id > 0'
            );
        }
        $defaultKw = orange_countries_default_id($pdo);
        if ($defaultKw > 0) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders SET country_id = ' . (int) $defaultKw . '
                 WHERE country_id IS NULL OR country_id = 0'
            );
        }
        if (orange_table_has_column($pdo, 'orders', 'warehouse_id')) {
            orange_catalog_safe_exec(
                $pdo,
                'UPDATE orders o
                 INNER JOIN warehouses w ON w.country_id = o.country_id AND w.is_default = 1
                 SET o.warehouse_id = w.id
                 WHERE (o.warehouse_id IS NULL OR o.warehouse_id = 0)
                   AND o.country_id IS NOT NULL AND o.country_id > 0'
            );
        }
    }

    if (orange_table_exists($pdo, 'warehouse_variant_stock') && orange_table_exists($pdo, 'product_variants')) {
        orange_catalog_safe_exec(
            $pdo,
            'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
             SELECT w.id, pv.id, 0
             FROM products p
             INNER JOIN product_variants pv ON pv.product_id = p.id
             INNER JOIN warehouses w ON w.country_id = p.country_id AND w.is_default = 1
             LEFT JOIN warehouse_variant_stock wvs
               ON wvs.warehouse_id = w.id AND wvs.variant_id = pv.id
             WHERE p.country_id IS NOT NULL AND p.country_id > 0
               AND wvs.variant_id IS NULL'
        );
    }

    if (orange_table_exists($pdo, 'stock_movements')
        && orange_table_has_column($pdo, 'stock_movements', 'country_id')
        && orange_table_has_column($pdo, 'stock_movements', 'warehouse_id')) {
        orange_catalog_safe_exec(
            $pdo,
            'UPDATE stock_movements sm
             INNER JOIN warehouses w ON w.id = sm.warehouse_id
             SET sm.country_id = w.country_id
             WHERE (sm.country_id IS NULL OR sm.country_id = 0)
               AND sm.warehouse_id IS NOT NULL AND sm.warehouse_id > 0'
        );
    }

    $rep = orange_multicountry_stock_gap_report($pdo);
    if (!empty($rep['ready'])) {
        orange_catalog_migration_step_record($pdo, ORANGE_MC_STOCK_SCOPED_STEP);
        if (function_exists('error_log')) {
            error_log('[orange] multicountry stock scoped phase1 OK');
        }
    }
}
