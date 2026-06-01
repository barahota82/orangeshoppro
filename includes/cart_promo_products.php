<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/cart_promotion_country.php';

/**
 * صفوف اختيار منتج للعروض (سطر واحد لكل منتج نشط — أي لون/مقاس).
 *
 * @return list<array{product_id:int,code:string,name:string}>
 */
function orange_cart_promo_admin_product_rows(PDO $pdo, ?int $countryId = null): array
{
    $cid = $countryId > 0 ? $countryId : orange_admin_context_country_id($pdo);
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $cid);
    $cols = 'p.id, p.name';
    if (orange_table_has_column($pdo, 'products', 'item_code')) {
        $cols .= ', p.item_code';
    }
    $st = $pdo->query(
        "SELECT $cols FROM products p WHERE p.is_active = 1" . $countrySql . ' ORDER BY p.name ASC, p.id ASC'
    );
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $pid = (int) ($row['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $code = trim((string) ($row['item_code'] ?? ''));
        if ($code === '') {
            $code = 'P' . $pid;
        }
        $out[] = [
            'product_id' => $pid,
            'code' => $code,
            'name' => trim((string) ($row['name'] ?? '')),
        ];
    }

    return $out;
}

/**
 * يحوّل رقمًا مخزّنًا قديماً (متغير) أو جديداً (منتج) إلى product_id.
 */
function orange_cart_promo_resolve_stored_product_id(PDO $pdo, int $storedId): int
{
    if ($storedId <= 0) {
        return 0;
    }
    if (orange_table_exists($pdo, 'products')) {
        $ps = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $ps->execute([$storedId]);
        if ($ps->fetchColumn()) {
            return $storedId;
        }
    }
    if (orange_table_exists($pdo, 'product_variants')) {
        $vs = $pdo->prepare('SELECT product_id FROM product_variants WHERE id = ? LIMIT 1');
        $vs->execute([$storedId]);
        $pid = (int) $vs->fetchColumn();

        return $pid > 0 ? $pid : 0;
    }

    return 0;
}

/**
 * @param list<array{product_id?:int,variant_id?:int,qty?:int}>|mixed $raw
 *
 * @return list<array{product_id:int,qty:int}>
 */
function orange_cart_promo_parse_components(PDO $pdo, mixed $raw): array
{
    if (!is_array($raw)) {
        return [];
    }
    /** @var array<int, int> $merged */
    $merged = [];
    foreach ($raw as $row) {
        if (!is_array($row)) {
            continue;
        }
        $q = (int) ($row['qty'] ?? 0);
        if ($q <= 0) {
            continue;
        }
        $pid = (int) ($row['product_id'] ?? 0);
        if ($pid <= 0 && isset($row['variant_id'])) {
            $pid = orange_cart_promo_resolve_stored_product_id($pdo, (int) $row['variant_id']);
        }
        if ($pid <= 0) {
            continue;
        }
        $merged[$pid] = ($merged[$pid] ?? 0) + $q;
    }
    $out = [];
    foreach ($merged as $pid => $q) {
        $out[] = ['product_id' => $pid, 'qty' => $q];
    }

    return $out;
}

/**
 * @return list<array{product_id:int,qty:int}>
 */
function orange_cart_promo_parse_components_json(PDO $pdo, ?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $d = json_decode($json, true);

    return orange_cart_promo_parse_components($pdo, is_array($d) ? $d : []);
}

/**
 * @return list<int> product_ids
 */
function orange_cart_promo_parse_product_pool(PDO $pdo, ?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    $seen = [];
    foreach ($d as $x) {
        $stored = (int) $x;
        if ($stored <= 0) {
            continue;
        }
        $pid = orange_cart_promo_resolve_stored_product_id($pdo, $stored);
        if ($pid > 0) {
            $seen[$pid] = true;
        }
    }

    return array_keys($seen);
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array<int, array{qty:int, unit:float}>
 */
function orange_cart_promo_aggregate_product_units(array $validatedItems): array
{
    /** @var array<int, array{sum:float, qty:int}> $agg */
    $agg = [];
    foreach ($validatedItems as $li) {
        $pid = (int) ($li['product']['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $q = max(1, (int) ($li['qty'] ?? 1));
        $pr = (float) ($li['price'] ?? 0);
        if (!isset($agg[$pid])) {
            $agg[$pid] = ['sum' => 0.0, 'qty' => 0];
        }
        $agg[$pid]['sum'] += $pr * $q;
        $agg[$pid]['qty'] += $q;
    }
    $out = [];
    foreach ($agg as $pid => $row) {
        $q = max(1, $row['qty']);
        $out[$pid] = [
            'qty' => $row['qty'],
            'unit' => $row['sum'] > 0 ? $row['sum'] / $q : 0.0,
        ];
    }

    return $out;
}

/**
 * @param list<int> $productIds
 *
 * @return list<array{variant_id:int,product_name:string,color:string,size:string,stock:int}>
 */
function orange_cart_gift_promotion_pool_options_for_products(
    PDO $pdo,
    array $productIds,
    array $validatedItems,
    bool $lockVariants,
    ?int $countryId = null
): array {
    require_once __DIR__ . '/cart_gift_promotions.php';
    require_once __DIR__ . '/catalog_unified_product_helpers.php';
    require_once __DIR__ . '/warehouses.php';

    $stockCountryId = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $lockSql = $lockVariants ? ' FOR UPDATE' : '';
    $out = [];
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0 || !orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            continue;
        }
        $vStmt = $pdo->prepare(
            'SELECT v.id, v.color, v.size, p.name AS product_name, p.is_active AS p_active
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.product_id = ? AND p.is_active = 1
             ORDER BY v.id ASC' . $lockSql
        );
        $vStmt->execute([$pid]);
        while ($v = $vStmt->fetch(PDO::FETCH_ASSOC)) {
            $vid = (int) ($v['id'] ?? 0);
            if ($vid <= 0) {
                continue;
            }
            $used = orange_cart_gift_variant_usage_in_lines($validatedItems, $vid);
            $stock = orange_warehouse_effective_variant_stock($pdo, $vid, $stockCountryId);
            if ($stock < $used + 1) {
                continue;
            }
            $out[] = [
                'variant_id' => $vid,
                'product_name' => (string) ($v['product_name'] ?? ''),
                'color' => (string) ($v['color'] ?? ''),
                'size' => (string) ($v['size'] ?? ''),
                'stock' => $stock - $used,
            ];
        }
    }

    return $out;
}

/**
 * @return list<array{product_id:int,qty:int}>
 */
function orange_cart_promo_parse_components_text(PDO $pdo, string $raw): array
{
    $rows = [];
    $lines = preg_split('/\R/u', trim($raw));
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^(\d+)\s*[,:\s]\s*(\d+)/', $line, $m)) {
            $rows[] = ['product_id' => (int) $m[1], 'qty' => (int) $m[2]];
        } elseif (preg_match('/^(\d+)$/', $line, $m)) {
            $rows[] = ['product_id' => (int) $m[1], 'qty' => 1];
        }
    }

    return orange_cart_promo_parse_components($pdo, $rows);
}

/**
 * @return list<int>
 */
function orange_cart_promo_parse_product_pool_text(PDO $pdo, string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY);
    $rows = [];
    foreach ($parts as $p) {
        $n = (int) preg_replace('/\D+/', '', (string) $p);
        if ($n > 0) {
            $rows[] = $n;
        }
    }
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($rows, $flags);

    return orange_cart_promo_parse_product_pool($pdo, $json !== false ? $json : '[]');
}

function orange_cart_promo_encode_components_json(array $components): string
{
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode(array_values($components), $flags);

    return $json !== false ? $json : '[]';
}

function orange_cart_promo_encode_product_pool_json(array $productIds): string
{
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode(array_values($productIds), $flags);

    return $json !== false ? $json : '[]';
}

/**
 * @param list<int> $productIds
 */
function orange_cart_promo_validate_product_ids(PDO $pdo, array $productIds): ?string
{
    foreach ($productIds as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        try {
            orange_admin_assert_entity_country($pdo, 'products', $pid);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        $ps = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $ps->execute([$pid]);
        if (!$ps->fetchColumn()) {
            return 'منتج غير موجود أو غير نشط: ' . $pid;
        }
    }

    return null;
}

/**
 * @param list<array{product_id:int,qty:int}> $components
 *
 * @return list<array{product_id:int,qty:int,product_name:string,code:string}>
 */
function orange_cart_promo_components_with_labels(PDO $pdo, array $components): array
{
    $out = [];
    foreach ($components as $c) {
        $pid = (int) ($c['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $ps = $pdo->prepare('SELECT name' . (orange_table_has_column($pdo, 'products', 'item_code') ? ', item_code' : '') . ' FROM products WHERE id = ? LIMIT 1');
        $ps->execute([$pid]);
        $row = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
        $code = trim((string) ($row['item_code'] ?? ''));
        if ($code === '') {
            $code = 'P' . $pid;
        }
        $out[] = [
            'product_id' => $pid,
            'qty' => (int) ($c['qty'] ?? 0),
            'product_name' => trim((string) ($row['name'] ?? '')),
            'code' => $code,
        ];
    }

    return $out;
}
