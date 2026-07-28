<?php

declare(strict_types=1);

/**
 * FSR Batch D2 — disposable MySQL inventory/FIFO fixture helpers (test-only).
 * Reuses D1 bootstrap; never touches Production .env.php / Production data.
 */

require_once __DIR__ . '/final_review_d1_fixture.php';

/**
 * Prevent disposable-DB schema catch-up / id-renumber from mutating the dump mid-test.
 * Uses Production gate hooks only (ok-flag + meta version) — no Production code edits.
 */
function orange_d2_seal_schema_gate(PDO $pdo, string $dbName): void
{
    if (!preg_match('/^orange_d1_[a-zA-Z0-9_]+$/', $dbName)) {
        return;
    }
    $flag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_d2_schema_ok_' . $dbName . '.flag';
    $rev = 124;
    if (defined('ORANGE_CATALOG_SCHEMA_PHP_REVISION')) {
        $rev = (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION;
    }
    file_put_contents($flag, (string) $rev . "\n");
    putenv('ORANGE_SCHEMA_OK_FLAG_PATH=' . $flag);
    $_ENV['ORANGE_SCHEMA_OK_FLAG_PATH'] = $flag;

    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS orange_schema_meta (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                version INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->prepare(
            'INSERT INTO orange_schema_meta (id, version) VALUES (1, ?)
             ON DUPLICATE KEY UPDATE version = VALUES(version)'
        )->execute([$rev]);
    } catch (Throwable) {
        // best-effort
    }
}

/**
 * Load inventory-focused production helpers without config.php / .env.php.
 */
function orange_d2_load_production_helpers(string $projectRoot): void
{
    orange_d1_load_production_helpers($projectRoot);
    require_once $projectRoot . '/includes/warehouses.php';
    require_once $projectRoot . '/includes/inventory_cost_layers.php';
    require_once $projectRoot . '/includes/order_stock.php';
    require_once $projectRoot . '/includes/order_fulfillment.php';
    require_once $projectRoot . '/includes/opening_stock_voucher.php';
    require_once $projectRoot . '/includes/opening_stock_lock.php';
    require_once $projectRoot . '/includes/inventory_reconciliation.php';
    require_once $projectRoot . '/includes/stock_adjustment_voucher.php';
}

/**
 * @return array{
 *   ok:bool,
 *   pdo?:PDO,
 *   db_name?:string,
 *   cleanup?:callable,
 *   ids?:array<string,int|string>,
 *   error?:string,
 *   env?:string
 * }
 */
function orange_d2_bootstrap_isolated_db(string $projectRoot): array
{
    $boot = orange_d1_bootstrap_isolated_db($projectRoot);
    if (empty($boot['ok'])) {
        return $boot;
    }

    /** @var PDO $pdo */
    $pdo = $boot['pdo'];
    /** @var array<string,int|string> $ids */
    $ids = $boot['ids'] ?? [];

    try {
        $ids = orange_d2_enrich_inventory_fixture($pdo, $ids);
        orange_d2_seal_schema_gate($pdo, (string) ($boot['db_name'] ?? 'orange_d2'));
        $boot['ids'] = $ids;
        // Rename disposable DB marker in return only — physical name stays orange_d1_* from bootstrap.
        $boot['env'] = 'MYSQL_DISPOSABLE_D2';

        return $boot;
    } catch (Throwable $e) {
        if (isset($boot['cleanup']) && is_callable($boot['cleanup'])) {
            ($boot['cleanup'])();
        }

        return [
            'ok' => false,
            'error' => 'D2 fixture enrich failed: ' . $e->getMessage(),
            'env' => 'ENVIRONMENT_BLOCKED',
        ];
    }
}

/**
 * @param array<string,int|string> $ids
 * @return array<string,int|string>
 */
function orange_d2_enrich_inventory_fixture(PDO $pdo, array $ids): array
{
    $now = gmdate('Y-m-d H:i:s');

    // Country Configuration timezones required by FIFO layer wall→UTC conversion.
    // Local orange_db.sql may predate countries.timezone — add on disposable DB only (not Production).
    if (!orange_d1_has_column($pdo, 'countries', 'timezone')) {
        $pdo->exec(
            'ALTER TABLE countries ADD COLUMN timezone VARCHAR(64) NULL DEFAULT NULL'
        );
    }
    $pdo->prepare('UPDATE countries SET timezone = ? WHERE id = ?')->execute(['Asia/Kuwait', (int) $ids['kw_country_id']]);
    $pdo->prepare('UPDATE countries SET timezone = ? WHERE id = ?')->execute(['Africa/Cairo', (int) $ids['eg_country_id']]);

    // Second warehouse in KW for cross-warehouse isolation tests.
    orange_d1_insert_if_table($pdo, 'warehouses', [
        [
            'id' => 11,
            'country_id' => (int) $ids['kw_country_id'],
            'name_ar' => 'مخزن كويت فرعي',
            'name_en' => 'KW WH B',
            'is_default' => 0,
            'is_active' => 1,
            'created_at' => $now,
        ],
    ], true);

    // Extra variants: zero-stock + last-unit.
    orange_d1_insert_if_table($pdo, 'product_variants', [
        [
            'id' => 603,
            'product_id' => (int) $ids['kw_product_id'],
            'item_code' => 'KW-500-ZERO',
            'price' => 10.0000,
            'cost' => 4.0000,
            'stock_quantity' => 0,
        ],
        [
            'id' => 604,
            'product_id' => (int) $ids['kw_product_id'],
            'item_code' => 'KW-500-LAST',
            'price' => 10.0000,
            'cost' => 4.0000,
            'stock_quantity' => 1,
        ],
    ], true);

    $kwWh = (int) $ids['kw_warehouse_id'];
    $egWh = (int) $ids['eg_warehouse_id'];
    $kwWhB = 11;
    $kwVar = (int) $ids['kw_variant_id'];
    $egVar = (int) $ids['eg_variant_id'];

    // Authoritative on-hand rows (WVS). Legacy stock_quantity is KW mirror only.
    orange_d2_upsert_wvs($pdo, $kwWh, $kwVar, 100);
    orange_d2_upsert_wvs($pdo, $kwWh, 603, 0);
    orange_d2_upsert_wvs($pdo, $kwWh, 604, 1);
    orange_d2_upsert_wvs($pdo, $kwWhB, $kwVar, 25);
    orange_d2_upsert_wvs($pdo, $egWh, $egVar, 50);

    $ids['kw_warehouse_b_id'] = $kwWhB;
    $ids['kw_variant_zero_id'] = 603;
    $ids['kw_variant_last_id'] = 604;

    return $ids;
}

function orange_d2_set_admin_country(int $countryId, string $code = ''): void
{
    $GLOBALS['orange_admin_ctx_country_id'] = $countryId;
    if ($code !== '') {
        $GLOBALS['orange_admin_ctx_country_code'] = strtolower($code);
    }
}

function orange_d2_upsert_wvs(PDO $pdo, int $warehouseId, int $variantId, int $qty): void
{
    if ($warehouseId <= 0 || $variantId <= 0) {
        return;
    }
    $utc = gmdate('Y-m-d H:i:s');
    if (orange_d1_has_column($pdo, 'warehouse_variant_stock', 'updated_at')) {
        $pdo->prepare(
            'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = VALUES(updated_at)'
        )->execute([$warehouseId, $variantId, $qty, $utc]);

        return;
    }
    $pdo->prepare(
        'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$warehouseId, $variantId, $qty]);
}

function orange_d2_wvs_qty(PDO $pdo, int $warehouseId, int $variantId): int
{
    $st = $pdo->prepare(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1'
    );
    $st->execute([$warehouseId, $variantId]);
    $q = $st->fetchColumn();

    return $q !== false && $q !== null ? (int) $q : 0;
}

/**
 * @return list<array<string,mixed>>
 */
function orange_d2_layers(PDO $pdo, int $warehouseId, int $variantId): array
{
    $st = $pdo->prepare(
        'SELECT * FROM inventory_cost_layers
         WHERE warehouse_id = ? AND variant_id = ?
         ORDER BY layer_date ASC, id ASC'
    );
    $st->execute([$warehouseId, $variantId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function orange_d2_layer_remaining_sum(PDO $pdo, int $warehouseId, int $variantId): int
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(qty_remaining), 0) FROM inventory_cost_layers
         WHERE warehouse_id = ? AND variant_id = ?'
    );
    $st->execute([$warehouseId, $variantId]);

    return (int) $st->fetchColumn();
}

function orange_d2_consumption_qty(PDO $pdo, string $saleSourceType, int $saleSourceId): int
{
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(consumed_qty), 0) FROM inventory_cost_consumptions
         WHERE sale_source_type = ? AND sale_source_id = ?'
    );
    $st->execute([$saleSourceType, $saleSourceId]);

    return (int) $st->fetchColumn();
}

function orange_d2_movement_count(PDO $pdo, string $reference, ?string $type = null): int
{
    if ($type === null) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE reference = ?');
        $st->execute([$reference]);
    } else {
        $st = $pdo->prepare('SELECT COUNT(*) FROM stock_movements WHERE reference = ? AND type = ?');
        $st->execute([$reference, $type]);
    }

    return (int) $st->fetchColumn();
}

/**
 * Seed two distinct FIFO layers (older cheaper, newer dearer) via production helper.
 *
 * @return array{layer_old_id:int, layer_new_id:int}
 */
function orange_d2_seed_two_fifo_layers(
    PDO $pdo,
    int $warehouseId,
    int $variantId,
    int $countryId,
    int $qtyOld,
    float $costOld,
    int $qtyNew,
    float $costNew,
    string $sourceType = 'purchase',
    int $sourceId = 9001
): array {
    $oldId = orange_inventory_cost_layer_add(
        $pdo,
        $warehouseId,
        $variantId,
        $qtyOld,
        $costOld,
        $sourceType,
        $sourceId,
        $countryId,
        '2026-01-01 10:00:00',
        'D2 old layer'
    );
    $newId = orange_inventory_cost_layer_add(
        $pdo,
        $warehouseId,
        $variantId,
        $qtyNew,
        $costNew,
        $sourceType,
        $sourceId + 1,
        $countryId,
        '2026-06-01 10:00:00',
        'D2 new layer'
    );

    return ['layer_old_id' => $oldId, 'layer_new_id' => $newId];
}

/**
 * MySQL DSN pieces for concurrency worker processes.
 *
 * @return array{host:string,port:int,user:string,pass:string}
 */
function orange_d2_mysql_connect_meta(): array
{
    return ['host' => '127.0.0.1', 'port' => 3306, 'user' => 'root', 'pass' => ''];
}

function orange_d2_php_bin(): string
{
    $candidates = [
        'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe',
        'C:\\laragon\\bin\\php\\php-8.3.16-Win32-vs16-x64\\php.exe',
        'C:\\laragon\\bin\\php\\php-8.2.27-Win32-vs16-x64\\php.exe',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            return $c;
        }
    }

    return 'php';
}
