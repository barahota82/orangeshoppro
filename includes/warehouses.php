<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/admin_time.php';

function orange_warehouses_table_exists(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'warehouses')
        && orange_table_exists($pdo, 'warehouse_variant_stock');
}

/**
 * دولة المستودع — مرجع السلطة للسجلات المخزنية المرتبطة بمستودع.
 *
 * @throws RuntimeException missing warehouse or country_id
 */
function orange_warehouse_authority_country_id(PDO $pdo, int $warehouseId): int
{
    $row = orange_warehouse_row_by_id($pdo, $warehouseId);
    if ($row === null) {
        throw new RuntimeException('admin_time_warehouse_not_found');
    }
    $countryId = (int) ($row['country_id'] ?? 0);
    if ($countryId <= 0) {
        throw new RuntimeException('admin_time_warehouse_country_required');
    }

    return $countryId;
}

/**
 * Fail closed when stock movement country_id conflicts with warehouse country.
 *
 * @throws RuntimeException
 */
function orange_stock_movement_assert_country_matches_warehouse(
    PDO $pdo,
    ?int $countryId,
    ?int $warehouseId
): void {
    if ($warehouseId === null || $warehouseId <= 0) {
        return;
    }
    $whCountry = orange_warehouse_authority_country_id($pdo, $warehouseId);
    if ($countryId !== null && $countryId > 0 && $countryId !== $whCountry) {
        throw new RuntimeException('admin_time_warehouse_document_country_mismatch');
    }
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
    $hasCreatedAt = orange_table_has_column($pdo, 'warehouses', 'created_at');
    if ($hasCreatedAt) {
        $ins = $pdo->prepare(
            'INSERT INTO warehouses (country_id, name_ar, name_en, is_default, is_active, sort_order, created_at)
             VALUES (?, ?, ?, 1, 1, 1, ' . orange_admin_time_sql_from_unix() . ')'
        );
        $ins->execute([
            $countryId,
            $nameAr . ' — مخزن رئيسي',
            $nameEn . ' — main',
            orange_admin_time_unix_now(),
        ]);
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO warehouses (country_id, name_ar, name_en, is_default, is_active, sort_order)
             VALUES (?, ?, ?, 1, 1, 1)'
        );
        $ins->execute([$countryId, $nameAr . ' — مخزن رئيسي', $nameEn . ' — main']);
    }
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
 * §13.1 مرحلة 19: fallback إلى product_variants.stock_quantity للكويت (مرآة legacy) فقط.
 */
function orange_warehouse_legacy_stock_fallback_enabled(PDO $pdo, int $countryId): bool
{
    if ($countryId <= 0) {
        $countryId = orange_countries_default_id($pdo);
    }

    return $countryId > 0 && $countryId === orange_countries_default_id($pdo);
}

/**
 * يقرأ من warehouse_variant_stock (مصدر الحقيقة)؛ fallback legacy للكويت فقط عند غياب صف المخزن.
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
        if (!orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)) {
            return 0;
        }
    }
    if (!orange_table_exists($pdo, 'product_variants')) {
        return 0;
    }
    if (!orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)) {
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
    orange_warehouse_variant_stock_upsert_quantity($pdo, $warehouseId, $variantId, $new);
    orange_warehouse_sync_legacy_variant_quantity($pdo, $warehouseId, $variantId, $new);

    return ['old' => $old, 'new' => $new];
}

/**
 * Upsert quantity and set updated_at explicitly as UTC MySQL wall (DATETIME).
 * Avoids ON UPDATE CURRENT_TIMESTAMP writing session +03:00 into a UTC Absolute Moment column.
 */
function orange_warehouse_variant_stock_upsert_quantity(
    PDO $pdo,
    int $warehouseId,
    int $variantId,
    int $quantity
): void {
    $hasUpdatedAt = orange_table_has_column($pdo, 'warehouse_variant_stock', 'updated_at');
    if ($hasUpdatedAt) {
        $utc = orange_admin_time_utc_now_mysql();
        $pdo->prepare(
            'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity, updated_at)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), updated_at = VALUES(updated_at)'
        )->execute([$warehouseId, $variantId, $quantity, $utc]);

        return;
    }
    $pdo->prepare(
        'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
    )->execute([$warehouseId, $variantId, $quantity]);
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
    orange_warehouse_variant_stock_upsert_quantity($pdo, $warehouseId, $variantId, $newQty);
    orange_warehouse_sync_legacy_variant_quantity($pdo, $warehouseId, $variantId, $newQty);

    return ['old' => $old, 'new' => $newQty];
}

/**
 * مرآة legacy: product_variants.stock_quantity تُحدَّث فقط من مخزن الكويت الافتراضي (§13.1 مرحلة 19).
 * دول أخرى لا تلمس العمود — مصدر الحقيقة warehouse_variant_stock.
 */
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
    $countryId = isset($fields['country_id']) ? (int) $fields['country_id'] : null;
    if ($countryId !== null && $countryId <= 0) {
        $countryId = null;
    }
    $warehouseId = isset($fields['warehouse_id']) ? (int) $fields['warehouse_id'] : null;
    if ($warehouseId !== null && $warehouseId <= 0) {
        $warehouseId = null;
    }
    if ($warehouseId !== null) {
        orange_stock_movement_assert_country_matches_warehouse($pdo, $countryId, $warehouseId);
        if ($countryId === null && orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
            $countryId = orange_warehouse_authority_country_id($pdo, $warehouseId);
        }
    }
    $createdAt = trim((string) ($fields['created_at'] ?? ''));
    if ($createdAt === '') {
        $createdAt = orange_admin_time_utc_now_mysql();
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
        $createdAt,
    ];
    $ph = ['?', '?', '?', '?', '?', '?', '?', '?'];
    if (orange_table_has_column($pdo, 'stock_movements', 'reference')) {
        $cols[] = 'reference';
        $vals[] = isset($fields['reference']) ? (string) $fields['reference'] : null;
        $ph[] = '?';
    }
    if (orange_table_has_column($pdo, 'stock_movements', 'country_id')) {
        $cols[] = 'country_id';
        $vals[] = $countryId;
        $ph[] = '?';
    }
    if (orange_table_has_column($pdo, 'stock_movements', 'warehouse_id')) {
        $cols[] = 'warehouse_id';
        $vals[] = $warehouseId;
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
 * JOIN + تعبير SQL للرصيد الفعلي — warehouse_variant_stock؛ fallback legacy للكويت فقط (§13.1).
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
        $legacyOnly = orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId);

        return [
            'join' => '',
            'expr' => $legacyOnly ? ($pvAlias . '.stock_quantity') : '0',
            'warehouse_id' => 0,
        ];
    }
    $wid = (int) $warehouseId;
    $join = ' LEFT JOIN warehouse_variant_stock ' . $wvsAlias
        . ' ON ' . $wvsAlias . '.warehouse_id = ' . $wid
        . ' AND ' . $wvsAlias . '.variant_id = ' . $pvAlias . '.id ';
    $expr = orange_warehouse_legacy_stock_fallback_enabled($pdo, $countryId)
        ? 'COALESCE(' . $wvsAlias . '.quantity, ' . $pvAlias . '.stock_quantity)'
        : 'COALESCE(' . $wvsAlias . '.quantity, 0)';

    return [
        'join' => $join,
        'expr' => $expr,
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

/**
 * تطبيع صفوف مخزن بلا country_id (setup/test): Parent = warehouse.country_id ثم دولة Active واحدة.
 * Idempotent؛ NULL ≠ Global ولن تظهر في القائمة بعد فلتر country_id = ?.
 *
 * @param 'stock_adjustment_voucher'|'opening_stock_voucher'|'inventory_reconciliation' $table
 * @return array{null_before:int,normalized:int,from_warehouse:int,active_countries:int,blocked_ambiguous:bool}
 */
function orange_inventory_normalize_null_country_ids(PDO $pdo, string $table): array
{
    $out = [
        'null_before' => 0,
        'normalized' => 0,
        'from_warehouse' => 0,
        'active_countries' => 0,
        'blocked_ambiguous' => false,
    ];
    $allowed = [
        'stock_adjustment_voucher' => true,
        'opening_stock_voucher' => true,
        'inventory_reconciliation' => true,
    ];
    if (!isset($allowed[$table])
        || !orange_table_exists($pdo, $table)
        || !orange_table_has_column($pdo, $table, 'country_id')
        || !orange_table_exists($pdo, 'warehouses')
        || !orange_table_has_column($pdo, 'warehouses', 'country_id')) {
        return $out;
    }
    $out['null_before'] = (int) $pdo->query(
        'SELECT COUNT(*) FROM `' . $table . '` WHERE country_id IS NULL OR country_id = 0'
    )->fetchColumn();
    if ($out['null_before'] <= 0) {
        return $out;
    }
    $stWh = $pdo->prepare(
        'UPDATE `' . $table . '` t
         INNER JOIN warehouses w ON w.id = t.warehouse_id
         SET t.country_id = w.country_id
         WHERE (t.country_id IS NULL OR t.country_id = 0)
           AND w.country_id IS NOT NULL AND w.country_id > 0'
    );
    $stWh->execute();
    $out['from_warehouse'] = (int) $stWh->rowCount();
    $remaining = (int) $pdo->query(
        'SELECT COUNT(*) FROM `' . $table . '` WHERE country_id IS NULL OR country_id = 0'
    )->fetchColumn();
    if ($remaining <= 0) {
        $out['normalized'] = $out['from_warehouse'];

        return $out;
    }
    if (!orange_table_exists($pdo, 'countries')) {
        $out['blocked_ambiguous'] = true;
        $out['normalized'] = $out['from_warehouse'];

        return $out;
    }
    $ids = $pdo->query(
        'SELECT id FROM countries WHERE is_active = 1 ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_COLUMN);
    $active = [];
    foreach ($ids ?: [] as $id) {
        $cid = (int) $id;
        if ($cid > 0) {
            $active[] = $cid;
        }
    }
    $out['active_countries'] = count($active);
    if ($out['active_countries'] === 1) {
        $only = $active[0];
        $st = $pdo->prepare(
            'UPDATE `' . $table . '` SET country_id = ? WHERE country_id IS NULL OR country_id = 0'
        );
        $st->execute([$only]);
        $out['normalized'] = $out['from_warehouse'] + (int) $st->rowCount();

        return $out;
    }
    if ($out['active_countries'] > 1) {
        $out['blocked_ambiguous'] = true;
    }
    $out['normalized'] = $out['from_warehouse'];

    return $out;
}
