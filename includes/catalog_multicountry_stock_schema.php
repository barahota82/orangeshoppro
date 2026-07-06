<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/schema_migrations.php';

/**
 * idempotent — يُستدعى قبل backfill/تفعيل MC stock (مرحلة 1 و2).
 */
function orange_catalog_multicountry_stock_ensure_schema(PDO $pdo): void
{
    if (!function_exists('orange_table_exists')) {
        return;
    }

    orange_catalog_migrate_countries_foundation_v40($pdo);
    orange_catalog_migrate_country_market_codes_v42($pdo);
    orange_catalog_migrate_country_warehouses_v44($pdo);
    orange_catalog_migrate_country_scope_v45($pdo);
    orange_catalog_ensure_country_id_columns_once($pdo);
    orange_catalog_multicountry_stock_seed_market_countries($pdo);
    orange_catalog_multicountry_stock_ensure_indexes($pdo);
}

function orange_catalog_multicountry_stock_seed_market_countries(PDO $pdo): void
{
    if (!orange_table_exists($pdo, 'countries')) {
        return;
    }

    $registry = orange_countries_catalog_registry();
    $sort = ['eg' => 2, 'uae' => 3, 'ksa' => 4];
    $lookup = [
        'eg' => ['eg'],
        'uae' => ['uae', 'ae'],
        'ksa' => ['ksa', 'sa'],
    ];

    foreach (['eg', 'uae', 'ksa'] as $code) {
        $exists = false;
        foreach ($lookup[$code] as $lc) {
            $st = $pdo->prepare('SELECT id FROM countries WHERE code = ? LIMIT 1');
            $st->execute([$lc]);
            if ($st->fetch()) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
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

function orange_catalog_multicountry_stock_index_exists(PDO $pdo, string $table, string $indexName): bool
{
    $chk = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    $chk->execute([$table, $indexName]);

    return (bool) $chk->fetchColumn();
}

function orange_catalog_multicountry_stock_ensure_indexes(PDO $pdo): void
{
    $defs = [
        ['orders', 'idx_orders_country_wh', 'CREATE INDEX idx_orders_country_wh ON orders (country_id, warehouse_id)'],
        ['stock_movements', 'idx_stock_movements_country_wh', 'CREATE INDEX idx_stock_movements_country_wh ON stock_movements (country_id, warehouse_id)'],
        ['products', 'idx_products_country_active', 'CREATE INDEX idx_products_country_active ON products (country_id, is_active)'],
        ['warehouse_variant_stock', 'idx_wvs_wh_qty', 'CREATE INDEX idx_wvs_wh_qty ON warehouse_variant_stock (warehouse_id, quantity)'],
    ];
    foreach ($defs as [$table, $indexName, $ddl]) {
        if (!orange_table_exists($pdo, $table)) {
            continue;
        }
        if (orange_catalog_multicountry_stock_index_exists($pdo, $table, $indexName)) {
            continue;
        }
        $needsCountry = str_contains($ddl, 'country_id');
        $needsWarehouse = str_contains($ddl, 'warehouse_id');
        if ($needsCountry && !orange_table_has_column($pdo, $table, 'country_id')) {
            continue;
        }
        if ($needsWarehouse && !orange_table_has_column($pdo, $table, 'warehouse_id')) {
            continue;
        }
        if ($table === 'products' && !orange_table_has_column($pdo, 'products', 'is_active')) {
            continue;
        }
        orange_catalog_safe_exec($pdo, $ddl);
    }
}

/**
 * ترحيل v69 — ضمان مخطط MC stock (مرحلة 1+2)؛ يُسجَّل مرة واحدة، ensure idempotent كل طلب.
 */
function orange_catalog_migrate_multicountry_stock_v69(PDO $pdo): void
{
    orange_catalog_multicountry_stock_ensure_schema($pdo);

    $marker = 'php_multicountry_stock_v69';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }

    try {
        orange_schema_migrations_ensure_table($pdo);
        $ins = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $ins->execute([$marker]);
        if (function_exists('error_log')) {
            error_log('[orange] multicountry stock schema v69 OK');
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] multicountry stock v69 marker: ' . $e->getMessage());
        }
    }
}
