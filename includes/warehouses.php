<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';

function orange_warehouses_table_exists(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'warehouses')
        && orange_table_exists($pdo, 'warehouse_variant_stock');
}

/**
 * @return array{id:int, country_id:int, name_ar:string, name_en:string, is_default:int, is_active:int}|null
 */
function orange_warehouse_row_by_id(PDO $pdo, int $warehouseId): ?array
{
    if ($warehouseId <= 0 || !orange_table_exists($pdo, 'warehouses')) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, country_id, name_ar, name_en, is_default, is_active
         FROM warehouses WHERE id = ? LIMIT 1'
    );
    $st->execute([$warehouseId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * المخزن الافتراضي لدولة — يُنشأ تلقائياً إن وُجدت جداول المخازن.
 */
function orange_warehouse_default_id_for_country(PDO $pdo, int $countryId): int
{
    static $memo = [];
    if ($countryId <= 0) {
        return 0;
    }
    if (isset($memo[$countryId])) {
        return $memo[$countryId];
    }
    if (!orange_table_exists($pdo, 'warehouses')) {
        $memo[$countryId] = 0;

        return 0;
    }
    $st = $pdo->prepare(
        'SELECT id FROM warehouses WHERE country_id = ? AND is_active = 1
         ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1'
    );
    $st->execute([$countryId]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id <= 0) {
        orange_warehouse_ensure_default_for_country($pdo, $countryId);
        $st->execute([$countryId]);
        $id = (int) ($st->fetchColumn() ?: 0);
    }
    $memo[$countryId] = $id;

    return $id;
}

function orange_warehouse_default_id_for_country_code(PDO $pdo, string $countryCode): int
{
    $row = orange_country_row_by_code($pdo, $countryCode, false);

    return $row !== null ? orange_warehouse_default_id_for_country($pdo, (int) $row['id']) : 0;
}

function orange_warehouse_ensure_default_for_country(PDO $pdo, int $countryId): int
{
    if ($countryId <= 0 || !orange_table_exists($pdo, 'warehouses')) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT id FROM warehouses WHERE country_id = ? ORDER BY is_default DESC, sort_order ASC, id ASC LIMIT 1'
    );
    $st->execute([$countryId]);
    $existing = (int) ($st->fetchColumn() ?: 0);
    if ($existing > 0) {
        return $existing;
    }
    $stC = $pdo->prepare('SELECT name_ar, name_en, code FROM countries WHERE id = ? LIMIT 1');
    $stC->execute([$countryId]);
    $cRow = $stC->fetch(PDO::FETCH_ASSOC);
    if (!is_array($cRow)) {
        return 0;
    }
    $nameAr = trim((string) ($cRow['name_ar'] ?? ''));
    $nameEn = trim((string) ($cRow['name_en'] ?? ''));
    if ($nameAr === '') {
        $nameAr = 'المخزن الرئيسي';
    }
    if ($nameEn === '') {
        $nameEn = 'Main warehouse';
    }
    $ins = $pdo->prepare(
        'INSERT INTO warehouses (country_id, name_ar, name_en, is_default, is_active, sort_order)
         VALUES (?, ?, ?, 1, 1, 1)'
    );
    $ins->execute([$countryId, $nameAr . ' — مخزن رئيسي', $nameEn . ' — main']);
    $wid = (int) $pdo->lastInsertId();

    return $wid > 0 ? $wid : 0;
}

function orange_warehouse_variant_stock_quantity(PDO $pdo, int $warehouseId, int $variantId): int
{
    if ($warehouseId <= 0 || $variantId <= 0 || !orange_warehouses_table_exists($pdo)) {
        return 0;
    }
    $st = $pdo->prepare(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1'
    );
    $st->execute([$warehouseId, $variantId]);
    $q = $st->fetchColumn();

    return $q !== false && $q !== null ? (int) $q : 0;
}

/**
 * يقرأ من warehouse_variant_stock إن وُجد؛ وإلا product_variants.stock_quantity (انتقال).
 */
function orange_warehouse_effective_variant_stock(PDO $pdo, int $variantId, ?int $countryId = null): int
{
    if ($variantId <= 0) {
        return 0;
    }
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId > 0 && orange_warehouses_table_exists($pdo)) {
        $st = $pdo->prepare(
            'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1'
        );
        $st->execute([$warehouseId, $variantId]);
        $q = $st->fetchColumn();
        if ($q !== false && $q !== null) {
            return (int) $q;
        }
    }
    if (!orange_table_exists($pdo, 'product_variants')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1');
    $st->execute([$variantId]);
    $legacy = $st->fetchColumn();

    return $legacy !== false && $legacy !== null ? (int) $legacy : 0;
}

/**
 * @return array{old:int, new:int}
 */
function orange_warehouse_apply_variant_delta(
    PDO $pdo,
    int $warehouseId,
    int $variantId,
    int $delta,
    int $minQty = 0
): array {
    if ($warehouseId <= 0 || $variantId <= 0) {
        throw new RuntimeException('Invalid warehouse stock target');
    }
    if (!orange_warehouses_table_exists($pdo)) {
        $st = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$variantId]);
        $old = (int) ($st->fetchColumn() ?: 0);
        $new = $old + $delta;
        if ($new < $minQty) {
            throw new RuntimeException('Insufficient stock');
        }
        $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ?')->execute([$new, $variantId]);

        return ['old' => $old, 'new' => $new];
    }

    $sel = $pdo->prepare(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1 FOR UPDATE'
    );
    $sel->execute([$warehouseId, $variantId]);
    $old = (int) ($sel->fetchColumn() ?: 0);
    $new = $old + $delta;
    if ($new < $minQty) {
        throw new RuntimeException('Insufficient stock');
    }
    $pdo->prepare(
        'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$warehouseId, $variantId, $new]);
    orange_warehouse_sync_legacy_variant_quantity($pdo, $warehouseId, $variantId, $new);

    return ['old' => $old, 'new' => $new];
}

function orange_warehouse_set_variant_quantity(PDO $pdo, int $warehouseId, int $variantId, int $newQty): array
{
    if ($warehouseId <= 0 || $variantId <= 0) {
        throw new RuntimeException('Invalid warehouse stock target');
    }
    if ($newQty < 0) {
        $newQty = 0;
    }
    if (!orange_warehouses_table_exists($pdo)) {
        $st = $pdo->prepare('SELECT stock_quantity FROM product_variants WHERE id = ? LIMIT 1 FOR UPDATE');
        $st->execute([$variantId]);
        $old = (int) ($st->fetchColumn() ?: 0);
        $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ?')->execute([$newQty, $variantId]);

        return ['old' => $old, 'new' => $newQty];
    }

    $sel = $pdo->prepare(
        'SELECT quantity FROM warehouse_variant_stock WHERE warehouse_id = ? AND variant_id = ? LIMIT 1 FOR UPDATE'
    );
    $sel->execute([$warehouseId, $variantId]);
    $old = (int) ($sel->fetchColumn() ?: 0);
    $pdo->prepare(
        'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$warehouseId, $variantId, $newQty]);
    orange_warehouse_sync_legacy_variant_quantity($pdo, $warehouseId, $variantId, $newQty);

    return ['old' => $old, 'new' => $newQty];
}

/** مرحلة انتقالية: مزامنة product_variants مع مخزن الكويت الافتراضي (بند 13.1). */
function orange_warehouse_sync_legacy_variant_quantity(PDO $pdo, int $warehouseId, int $variantId, int $qty): void
{
    if (!orange_table_exists($pdo, 'product_variants') || !orange_table_exists($pdo, 'warehouses')) {
        return;
    }
    $wh = orange_warehouse_row_by_id($pdo, $warehouseId);
    if ($wh === null || (int) ($wh['is_default'] ?? 0) !== 1) {
        return;
    }
    $kwId = orange_countries_default_id($pdo);
    if ((int) ($wh['country_id'] ?? 0) !== $kwId) {
        return;
    }
    $pdo->prepare('UPDATE product_variants SET stock_quantity = ? WHERE id = ? LIMIT 1')
        ->execute([$qty, $variantId]);
}

/**
 * @param array<string, mixed> $fields product_id, variant_id, type, qty, old_stock, new_stock, reason, reference?, country_id?, warehouse_id?
 */
function orange_stock_movement_insert(PDO $pdo, array $fields): void
{
    if (!orange_table_exists($pdo, 'stock_movements')) {
        return;
    }
    $cols = ['product_id', 'variant_id', 'type', 'qty', 'old_stock', 'new_stock', 'reason', 'created_at'];
    $vals = [
        (int) ($fields['product_id'] ?? 0),
        (int) ($fields['variant_id'] ?? 0),
        (string) ($fields['type'] ?? ''),
        (int) ($fields['qty'] ?? 0),
        (int) ($fields['old_stock'] ?? 0),
        (int) ($fields['new_stock'] ?? 0),
        (string) ($fields['reason'] ?? ''),
    ];
    $ph = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
    if (orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        $cols[] = 'reference';
        $vals[] = isset($fields['reference']) ? (string) $fields['reference'] : null;
        $ph[] = '?';
    }
    if (orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
        $cols[] = 'country_id';
        $vals[] = isset($fields['country_id']) ? (int) $fields['country_id'] : null;
        $ph[] = '?';
    }
    if (orange_table_has_column($pdo, 'stock_movements', 'warehouse_id')) {
        $cols[] = 'warehouse_id';
        $vals[] = isset($fields['warehouse_id']) ? (int) $fields['warehouse_id'] : null;
        $ph[] = '?';
    }
    $sql = 'INSERT INTO stock_movements (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $ph) . ')';
    $pdo->prepare($sql)->execute($vals);
}

function orange_warehouse_context_for_country(PDO $pdo, int $countryId): array
{
    if ($countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);

    return ['country_id' => $countryId, 'warehouse_id' => $warehouseId];
}

/**
 * JOIN + تعبير SQL للرصيد الفعلي (warehouse_variant_stock مع fallback legacy — §13.1).
 *
 * @return array{join:string, expr:string, warehouse_id:int}
 */
function orange_warehouse_effective_qty_sql(
    PDO $pdo,
    int $countryId,
    string $pvAlias = 'pv',
    string $wvsAlias = 'wvs_country_stock'
): array {
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    if ($warehouseId <= 0 || !orange_warehouses_table_exists($pdo)) {
        return [
            'join' => '',
            'expr' => $pvAlias . '.stock_quantity',
            'warehouse_id' => 0,
        ];
    }
    $wid = (int) $warehouseId;
    $join = ' LEFT JOIN warehouse_variant_stock ' . $wvsAlias
        . ' ON ' . $wvsAlias . '.warehouse_id = ' . $wid
        . ' AND ' . $wvsAlias . '.variant_id = ' . $pvAlias . '.id ';

    return [
        'join' => $join,
        'expr' => 'COALESCE(' . $wvsAlias . '.quantity, ' . $pvAlias . '.stock_quantity)',
        'warehouse_id' => $wid,
    ];
}

/**
 * @param array<string, mixed> $order
 * @return array{country_id:int, warehouse_id:int}
 */
function orange_warehouse_context_for_order(PDO $pdo, array $order): array
{
    $countryId = (int) ($order['country_id'] ?? 0);
    if ($countryId <= 0) {
        $countryId = orange_countries_default_id($pdo);
    }
    $warehouseId = (int) ($order['warehouse_id'] ?? 0);
    if ($warehouseId <= 0) {
        $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    }

    return ['country_id' => $countryId, 'warehouse_id' => $warehouseId];
}
