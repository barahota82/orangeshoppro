<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @return list<int>
 */
function orange_cart_gift_parse_pool(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $d = json_decode($json, true);
    if (!is_array($d)) {
        return [];
    }
    $seen = [];
    foreach ($d as $x) {
        $n = (int) $x;
        if ($n > 0) {
            $seen[$n] = true;
        }
    }

    return array_keys($seen);
}

/**
 * @return list<array{id:int,min_subtotal:float,requires_registered_account:int,gift_kind:string,fixed_variant_id:?int,pool_variant_ids:list<int>,sort_order:int,is_active:int}>
 */
function orange_cart_gift_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        return [];
    }
    $st = $pdo->query(
        'SELECT id, min_subtotal, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, sort_order, is_active
         FROM cart_gift_promotions ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return [];
    }
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $fix = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;

        $out[] = [
            'id' => (int) $row['id'],
            'min_subtotal' => (float) ($row['min_subtotal'] ?? 0),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'gift_kind' => (string) ($row['gift_kind'] ?? 'choice'),
            'fixed_variant_id' => $fix > 0 ? $fix : null,
            'pool_variant_ids' => orange_cart_gift_parse_pool($row['pool_variant_ids'] ?? null),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0),
        ];
    }

    return $out;
}

/**
 * أعلى قاعدة تطابق المجموع (مثل عروض الخصم).
 *
 * @return array{id:int,gift_kind:string,fixed_variant_id:?int,pool_variant_ids:list<int>}|null
 */
function orange_cart_gift_promotion_select_rule(PDO $pdo, float $subtotal, bool $buyerRegistered): ?array
{
    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        return null;
    }
    $st = $pdo->query(
        "SELECT id, min_subtotal, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids
         FROM cart_gift_promotions
         WHERE is_active = 1
         ORDER BY min_subtotal DESC, id DESC"
    );
    if (!$st) {
        return null;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $min = (float) ($row['min_subtotal'] ?? 0);
        if ($subtotal + 0.00001 < $min) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        $kindRaw = strtolower(trim((string) ($row['gift_kind'] ?? 'choice')));
        $kind = $kindRaw === 'fixed' ? 'fixed' : 'choice';
        $fixed = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $pool = orange_cart_gift_parse_pool($row['pool_variant_ids'] ?? null);
        if ($kind === 'fixed' && $fixed <= 0) {
            continue;
        }
        if ($kind === 'choice' && count($pool) === 0) {
            continue;
        }

        return [
            'id' => (int) $row['id'],
            'gift_kind' => $kind,
            'fixed_variant_id' => $fixed > 0 ? $fixed : null,
            'pool_variant_ids' => $pool,
        ];
    }

    return null;
}

function orange_cart_gift_variant_usage_in_lines(array $validatedItems, int $variantId): int
{
    $n = 0;
    foreach ($validatedItems as $row) {
        if ((int) ($row['variant_id'] ?? 0) === $variantId) {
            $n += (int) ($row['qty'] ?? 0);
        }
    }

    return $n;
}

/**
 * خيارات الهدية لعرض الواجهة (فقط ما يتوفر له مخزون بعد بند السلة).
 *
 * @param list<int> $poolIds
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return list<array{variant_id:int,product_name:string,color:string,size:string,stock:int}>
 */
function orange_cart_gift_promotion_pool_options(
    PDO $pdo,
    array $poolIds,
    array $validatedItems,
    bool $lockVariants
): array {
    $lockSql = $lockVariants ? ' FOR UPDATE' : '';
    $out = [];
    foreach ($poolIds as $vid) {
        $vid = (int) $vid;
        if ($vid <= 0) {
            continue;
        }
        $vStmt = $pdo->prepare(
            'SELECT v.*, p.name AS product_name, p.is_active AS p_active
             FROM product_variants v
             INNER JOIN products p ON p.id = v.product_id
             WHERE v.id = ?
             LIMIT 1' . $lockSql
        );
        $vStmt->execute([$vid]);
        $v = $vStmt->fetch(PDO::FETCH_ASSOC);
        if (!$v || (int) ($v['p_active'] ?? 0) !== 1) {
            continue;
        }
        $used = orange_cart_gift_variant_usage_in_lines($validatedItems, $vid);
        $stock = (int) ($v['stock_quantity'] ?? 0);
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

    return $out;
}

/**
 * بند هدية بسعر صفر للفاتورة والحجز.
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float,is_gift:bool}
 */
function orange_storefront_build_gift_order_line(PDO $pdo, int $variantId, array $validatedItems, bool $lockVariants): array
{
    $lockSql = $lockVariants ? ' FOR UPDATE' : '';
    $vStmt = $pdo->prepare('SELECT * FROM product_variants WHERE id = ? LIMIT 1' . $lockSql);
    $vStmt->execute([$variantId]);
    $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
    if (!$variant) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift item.');
    }
    $pStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
    $pStmt->execute([(int) $variant['product_id']]);
    $product = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift item.');
    }
    $used = orange_cart_gift_variant_usage_in_lines($validatedItems, $variantId);
    $stock = (int) ($variant['stock_quantity'] ?? 0);
    if ($stock < $used + 1) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_out_of_stock') : 'Gift item out of stock.');
    }

    return [
        'product' => $product,
        'qty' => 1,
        'color' => (string) ($variant['color'] ?? ''),
        'size' => (string) ($variant['size'] ?? ''),
        'variant_id' => $variantId,
        'price' => 0.0,
        'cost' => (float) ($product['cost'] ?? 0),
        'is_gift' => true,
    ];
}

/**
 * للضيف فقط: عرض تحفيزي إن كان بإمكان المسجّل (بريد مؤكد) الحصول على هدية مجموع سلة لا تُطبَّق على الضيف.
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_gift_register_unlock_teaser_applies(
    PDO $pdo,
    float $subtotal,
    bool $buyerIsRegistered,
    array $validatedItems
): bool {
    if ($buyerIsRegistered || $subtotal <= 0) {
        return false;
    }
    $asGuest = orange_cart_gift_promotion_select_rule($pdo, $subtotal, false);
    $asReg = orange_cart_gift_promotion_select_rule($pdo, $subtotal, true);
    if ($asReg === null) {
        return false;
    }
    if ($asGuest !== null && (int) $asGuest['id'] === (int) $asReg['id']) {
        return false;
    }
    if ($asReg['gift_kind'] === 'fixed') {
        $fv = (int) ($asReg['fixed_variant_id'] ?? 0);
        if ($fv <= 0 || count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, false)) === 0) {
            return false;
        }
    } else {
        if (count(orange_cart_gift_promotion_pool_options($pdo, $asReg['pool_variant_ids'], $validatedItems, false)) === 0) {
            return false;
        }
    }

    return true;
}
