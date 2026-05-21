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
