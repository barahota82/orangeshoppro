<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_multicountry_runtime.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/country_provision.php';

const ORANGE_MC_STOCK_SCOPED_STEP = 'multicountry_stock_scoped_v1';
const ORANGE_MC_STOCK_OPERATIONAL_STEP = 'multicountry_stock_operational_v1';

/** أسواق المرحلة 2 — مصر → الإمارات → السعودية (§10). */
function orange_multicountry_phase2_market_codes(): array
{
    return ['eg', 'uae', 'ksa'];
}

/**
 * @return list<string>
 */
function orange_multicountry_phase2_country_lookup_codes(string $marketCode): array
{
    $marketCode = orange_countries_normalize_code($marketCode);
    $legacy = [
        'uae' => ['uae', 'ae'],
        'ksa' => ['ksa', 'sa'],
    ];

    return $legacy[$marketCode] ?? [$marketCode];
}

function orange_multicountry_normalize_legacy_country_codes(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'countries')) {
        return;
    }
    foreach ([['ae', 'uae'], ['sa', 'ksa']] as $pair) {
        [$legacy, $canonical] = $pair;
        $stLegacy = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stLegacy->execute([$legacy]);
        $legacyId = (int) ($stLegacy->fetchColumn() ?: 0);
        if ($legacyId <= 0) {
            continue;
        }
        $stCan = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
        $stCan->execute([$canonical]);
        $canId = (int) ($stCan->fetchColumn() ?: 0);
        if ($canId > 0 && $canId !== $legacyId) {
            continue;
        }
        $pdo->prepare('UPDATE countries SET code = ? WHERE id = ?')->execute([$canonical, $legacyId]);
    }
}

function orange_multicountry_country_id_for_market(PDO $pdo, string $marketCode): int
{
    foreach (orange_multicountry_phase2_country_lookup_codes($marketCode) as $code) {
        $row = orange_country_row_by_code($pdo, $code, false);
        if ($row !== null) {
            return (int) ($row['id'] ?? 0);
        }
    }

    return 0;
}

function orange_multicountry_ensure_phase2_market_countries(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'countries')) {
        return 0;
    }
    $registry = orange_countries_catalog_registry();
    $sort = [
        'eg' => 2,
        'uae' => 3,
        'ksa' => 4,
    ];
    $inserted = 0;
    foreach (orange_multicountry_phase2_market_codes() as $code) {
        if (orange_multicountry_country_id_for_market($pdo, $code) > 0) {
            continue;
        }
        $meta = $registry[$code] ?? null;
        if (!is_array($meta)) {
            continue;
        }
        $ins = $pdo->prepare(
            'INSERT INTO countries (code, name_ar, name_en, currency_code, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $ins->execute([
            $code,
            (string) ($meta['name_ar'] ?? ''),
            (string) ($meta['name_en'] ?? ''),
            strtoupper((string) ($meta['currency'] ?? '')),
            (int) ($sort[$code] ?? 99),
        ]);
        if ($ins->rowCount() > 0) {
            $inserted++;
        }
    }

    return $inserted;
}

function orange_multicountry_activate_phase2_markets(PDO $pdo): int
{
    if (!orange_table_exists($pdo, 'countries')) {
        return 0;
    }
    $activated = 0;
    foreach (orange_multicountry_phase2_market_codes() as $code) {
        $countryId = orange_multicountry_country_id_for_market($pdo, $code);
        if ($countryId <= 0) {
            continue;
        }
        $st = $pdo->prepare('UPDATE countries SET is_active = 1 WHERE id = ? AND is_active = 0');
        $st->execute([$countryId]);
        $activated += $st->rowCount();
    }

    return $activated;
}

function orange_multicountry_backfill_warehouse_variant_stock_rows(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'warehouse_variant_stock') || !orange_table_exists($pdo, 'product_variants')) {
        return;
    }
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

function orange_multicountry_market_variants_missing_wvs(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0
        || !orange_table_exists($pdo, 'warehouse_variant_stock')
        || !orange_table_exists($pdo, 'product_variants')
        || !orange_table_exists($pdo, 'warehouses')) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*)
         FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         INNER JOIN warehouses w ON w.country_id = p.country_id AND w.is_default = 1
         LEFT JOIN warehouse_variant_stock wvs
           ON wvs.warehouse_id = w.id AND wvs.variant_id = pv.id
         WHERE p.country_id = ?
           AND wvs.variant_id IS NULL'
    );
    $st->execute([$countryId]);

    return (int) $st->fetchColumn();
}

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
    require_once __DIR__ . '/catalog_multicountry_stock_schema.php';
    orange_catalog_multicountry_stock_ensure_schema($pdo);

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
        orange_multicountry_backfill_warehouse_variant_stock_rows($pdo);
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

/**
 * @return array{
 *   markets: array<string, array{
 *     country_id:int,
 *     is_active:bool,
 *     warehouse:bool,
 *     channels_ok:bool,
 *     products_count:int,
 *     variants_missing_wvs:int,
 *     provision_ready:bool
 *   }>,
 *   markets_active:int,
 *   markets_provision_ready:int,
 *   step_applied:bool,
 *   ready:bool
 * }
 */
function orange_multicountry_stock_phase2_gap_report(PDO $pdo): array
{
    $markets = [];
    $active = 0;
    $provisionReady = 0;
    foreach (orange_multicountry_phase2_market_codes() as $code) {
        $row = null;
        foreach (orange_multicountry_phase2_country_lookup_codes($code) as $lookupCode) {
            $row = orange_country_row_by_code($pdo, $lookupCode, false);
            if ($row !== null) {
                break;
            }
        }
        $countryId = $row !== null ? (int) ($row['id'] ?? 0) : 0;
        $isActive = $row !== null && !empty($row['is_active']);
        if ($isActive) {
            $active++;
        }
        $status = $countryId > 0 ? orange_country_provision_status($pdo, $countryId) : [
            'warehouse' => false,
            'channels_count' => 0,
            'products_count' => 0,
        ];
        $warehouse = !empty($status['warehouse']);
        $channelsOk = (int) ($status['channels_count'] ?? 0) > 0;
        $productsCount = (int) ($status['products_count'] ?? 0);
        $missingWvs = orange_multicountry_market_variants_missing_wvs($pdo, $countryId);
        $marketReady = $isActive
            && $warehouse
            && $channelsOk
            && $productsCount > 0
            && $missingWvs === 0;
        if ($marketReady) {
            $provisionReady++;
        }
        $markets[$code] = [
            'country_id' => $countryId,
            'is_active' => $isActive,
            'warehouse' => $warehouse,
            'channels_ok' => $channelsOk,
            'products_count' => $productsCount,
            'variants_missing_wvs' => $missingWvs,
            'provision_ready' => $marketReady,
        ];
    }

    $expected = count(orange_multicountry_phase2_market_codes());
    $ready = $active === $expected && $provisionReady === $expected;

    return [
        'markets' => $markets,
        'markets_active' => $active,
        'markets_provision_ready' => $provisionReady,
        'step_applied' => orange_catalog_migration_step_applied($pdo, ORANGE_MC_STOCK_OPERATIONAL_STEP),
        'ready' => $ready,
    ];
}

/**
 * المرحلة 2 — تفعيل EG/UAE/KSA + provision idempotent + صفوف مخزن · **لا** نسخ كميات KW.
 */
function orange_multicountry_ensure_operational_phase2(PDO $pdo): void
{
    require_once __DIR__ . '/catalog_multicountry_stock_schema.php';
    orange_catalog_multicountry_stock_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'countries') || !orange_table_exists($pdo, 'warehouses')) {
        return;
    }

    orange_multicountry_normalize_legacy_country_codes($pdo);
    orange_multicountry_ensure_phase2_market_countries($pdo);
    orange_multicountry_activate_phase2_markets($pdo);

    require_once __DIR__ . '/catalog_multicountry_runtime.php';
    if (function_exists('orange_catalog_ensure_department_countries_scaffold')) {
        orange_catalog_ensure_department_countries_scaffold($pdo);
    }

    $sourceId = orange_countries_default_id($pdo);
    foreach (orange_multicountry_phase2_market_codes() as $code) {
        $countryId = orange_multicountry_country_id_for_market($pdo, $code);
        if ($countryId <= 0) {
            continue;
        }
        try {
            orange_country_provision_full($pdo, $countryId, $sourceId > 0 ? $sourceId : null);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] multicountry phase2 provision ' . $code . ': ' . $e->getMessage());
            }
        }
        orange_warehouse_ensure_default_for_country($pdo, $countryId);
    }

    require_once __DIR__ . '/product_channels.php';
    orange_product_channels_ensure_missing_links($pdo);
    orange_multicountry_backfill_warehouse_variant_stock_rows($pdo);

    if (orange_catalog_migration_step_applied($pdo, ORANGE_MC_STOCK_OPERATIONAL_STEP)) {
        return;
    }

    $rep = orange_multicountry_stock_phase2_gap_report($pdo);
    if (!empty($rep['ready'])) {
        orange_catalog_migration_step_record($pdo, ORANGE_MC_STOCK_OPERATIONAL_STEP);
        if (function_exists('error_log')) {
            error_log('[orange] multicountry stock operational phase2 OK');
        }
    }
}
