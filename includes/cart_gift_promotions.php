<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_unified_product_helpers.php';
require_once __DIR__ . '/cart_promo_gift_charge.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/cart_promo_schedule.php';
require_once __DIR__ . '/variant_pricing.php';
require_once __DIR__ . '/cart_gift_pool_config.php';

/**
 * أعلى سعر وحدة ممكن لهدية ترويجية في معاينة العربة (هدية مجموع سلة أو BOGO).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $ctxValidatedItems
 */
function orange_cart_promo_preview_gift_max_unit_charge(PDO $pdo, array $rule, array $ctxValidatedItems): float
{
    if (($rule['promo_type'] ?? '') === 'threshold_gift') {
        require_once __DIR__ . '/cart_gift_storefront.php';

        return orange_cart_gift_threshold_preview_max_unit_charge($pdo, $rule, $ctxValidatedItems);
    }

    $kind = strtolower(trim((string) ($rule['gift_unit_charge_kind'] ?? 'free')));
    if ($kind === '' || $kind === 'free') {
        return 0.0;
    }
    $gKind = strtolower(trim((string) ($rule['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
    if ($gKind === 'fixed') {
        $fv = (int) ($rule['fixed_variant_id'] ?? 0);
        if ($fv <= 0) {
            return 0.0;
        }
        if (count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $ctxValidatedItems, false)) === 0) {
            return 0.0;
        }

        return orange_cart_gift_resolve_product_charge($pdo, $rule, 0, $fv);
    }
    $pool = $rule['pool_variant_ids'] ?? [];
    if (!is_array($pool) || count($pool) === 0) {
        return 0.0;
    }
    $opts = orange_cart_gift_promotion_pool_options($pdo, $pool, $ctxValidatedItems, false);
    if (count($opts) === 0) {
        return 0.0;
    }
    $max = 0.0;
    foreach ($opts as $opt) {
        $vid = (int) ($opt['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $p = orange_cart_gift_resolve_product_charge($pdo, $rule, 0, $vid);
        if ($p > $max) {
            $max = $p;
        }
    }

    return $max;
}

/**
 * @return list<int>
 */
/** @return list<int> product_ids (أي لون/مقاس) */
function orange_cart_gift_parse_pool(PDO $pdo, ?string $json): array
{
    return orange_cart_promo_parse_product_pool($pdo, $json);
}

/**
 * @return list<array{id:int,min_subtotal:float,requires_registered_account:int,gift_kind:string,fixed_variant_id:?int,pool_variant_ids:list<int>,sort_order:int,is_active:int}>
 */
function orange_cart_gift_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        return [];
    }
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_gift_promotions', '', $cid);
    $extraCols = '';
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'show_old_price_to_customer')) {
        $extraCols .= ', show_old_price_to_customer';
    }
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'max_gifts_pickable')) {
        $extraCols .= ', max_gifts_pickable';
    }
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'gift_pool_config')) {
        $extraCols .= ', gift_pool_config';
    }
    $st = $pdo->prepare(
        'SELECT id, name_ar, name_en, show_name_to_customer, min_subtotal, requires_registered_account, first_delivered_order_only, gift_kind, fixed_variant_id, pool_variant_ids,
                gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active, is_always_on,
                valid_from, valid_to, auto_paused_at, auto_paused_reason' . $extraCols . '
         FROM cart_gift_promotions WHERE 1=1' . $bind['sql'] . ' ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($bind['params']);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $fixStored = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $fixPid = $fixStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixStored) : 0;
        $poolPids = orange_cart_gift_parse_pool($pdo, $row['pool_variant_ids'] ?? null);

        $out[] = [
            'id' => (int) $row['id'],
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
            'min_subtotal' => (float) ($row['min_subtotal'] ?? 0),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'first_delivered_order_only' => (int) ($row['first_delivered_order_only'] ?? 0),
            'gift_kind' => (string) ($row['gift_kind'] ?? 'choice'),
            'fixed_product_id' => $fixPid > 0 ? $fixPid : null,
            'fixed_variant_id' => $fixPid > 0 ? $fixPid : null,
            'pool_product_ids' => $poolPids,
            'pool_variant_ids' => $poolPids,
            'gift_unit_charge_kind' => (string) ($row['gift_unit_charge_kind'] ?? 'free'),
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
            'show_old_price_to_customer' => (int) ($row['show_old_price_to_customer'] ?? 0),
            'max_gifts_pickable' => max(1, (int) ($row['max_gifts_pickable'] ?? 1)),
            'gift_pool_config' => isset($row['gift_pool_config']) ? (string) $row['gift_pool_config'] : null,
            'gift_pool_items' => orange_cart_gift_pool_config_decode(isset($row['gift_pool_config']) ? (string) $row['gift_pool_config'] : null)['items'],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0),
            'is_always_on' => (int) ($row['is_always_on'] ?? 0),
            'valid_from' => (string) ($row['valid_from'] ?? ''),
            'valid_to' => (string) ($row['valid_to'] ?? ''),
            'auto_paused_at' => $row['auto_paused_at'] ?? null,
            'auto_paused_reason' => $row['auto_paused_reason'] ?? null,
        ];
    }

    return $out;
}

/**
 * أعلى قاعدة تطابق المجموع (مثل عروض الخصم).
 *
 * @return array{id:int,gift_kind:string,fixed_variant_id:?int,pool_variant_ids:list<int>}|null
 */
function orange_cart_gift_promotion_select_rule(
    PDO $pdo,
    float $subtotal,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): ?array {
    if (!orange_table_exists($pdo, 'cart_gift_promotions')) {
        return null;
    }
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_gift_promotions', '', $cid);
    $extraCols = '';
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'show_old_price_to_customer')) {
        $extraCols .= ', show_old_price_to_customer';
    }
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'max_gifts_pickable')) {
        $extraCols .= ', max_gifts_pickable';
    }
    if (orange_table_has_column($pdo, 'cart_gift_promotions', 'gift_pool_config')) {
        $extraCols .= ', gift_pool_config';
    }
    $st = $pdo->prepare(
        "SELECT id, name_ar, name_en, show_name_to_customer, min_subtotal, requires_registered_account, first_delivered_order_only, gift_kind, fixed_variant_id, pool_variant_ids,
                gift_unit_charge_kind, gift_unit_charge_value, is_active, is_always_on, valid_from, valid_to,
                auto_paused_at, auto_paused_reason" . $extraCols . "
         FROM cart_gift_promotions
         WHERE 1=1" . orange_cart_promo_schedule_sql('cart_gift_promotions') . $bind['sql'] . "
         ORDER BY min_subtotal DESC, id DESC"
    );
    $st->execute($bind['params']);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!orange_cart_promo_row_is_customer_effective($row)) {
            continue;
        }
        $min = (float) ($row['min_subtotal'] ?? 0);
        if ($subtotal + 0.00001 < $min) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        if ((int) ($row['first_delivered_order_only'] ?? 0) === 1
            && !orange_cart_promo_buyer_first_delivered_ok($pdo, $buyerAccountId, $buyerPhone, $cid)) {
            continue;
        }
        $kindRaw = strtolower(trim((string) ($row['gift_kind'] ?? 'choice')));
        $kind = $kindRaw === 'fixed' ? 'fixed' : 'choice';
        $fixedStored = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $fixedPid = $fixedStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixedStored) : 0;
        $pool = orange_cart_gift_parse_pool($pdo, $row['pool_variant_ids'] ?? null);
        if ($kind === 'fixed' && $fixedPid <= 0) {
            continue;
        }
        if ($kind === 'choice' && count($pool) === 0) {
            continue;
        }

        $gcKind = strtolower(trim((string) ($row['gift_unit_charge_kind'] ?? 'free')));
        if (!in_array($gcKind, ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'], true)) {
            $gcKind = 'free';
        }

        $candidate = [
            'id' => (int) $row['id'],
            'promo_type' => 'threshold_gift',
            'gift_kind' => $kind,
            'fixed_product_id' => $fixedPid > 0 ? $fixedPid : null,
            'fixed_variant_id' => $fixedPid > 0 ? $fixedPid : null,
            'pool_product_ids' => $pool,
            'pool_variant_ids' => $pool,
            'gift_unit_charge_kind' => $gcKind,
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
            'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
            'show_old_price_to_customer' => (int) ($row['show_old_price_to_customer'] ?? 0),
            'max_gifts_pickable' => max(1, (int) ($row['max_gifts_pickable'] ?? 1)),
            'gift_pool_config' => isset($row['gift_pool_config']) ? (string) $row['gift_pool_config'] : null,
            'display_name' => orange_promo_customer_display_name($row),
        ];
        if (!orange_cart_promo_maybe_pause_rule_if_no_stock($pdo, 'cart_gift_promotions', $candidate, [], $countryId)) {
            continue;
        }

        return $candidate;
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
    bool $lockVariants,
    ?int $countryId = null
): array {
    $productIds = [];
    foreach ($poolIds as $sid) {
        $pid = orange_cart_promo_resolve_stored_product_id($pdo, (int) $sid);
        if ($pid > 0) {
            $productIds[$pid] = true;
        }
    }

    return orange_cart_gift_promotion_pool_options_for_products(
        $pdo,
        array_keys($productIds),
        $validatedItems,
        $lockVariants,
        $countryId
    );
}

/**
 * يحلّ هدية «ثابتة» (مخزَّنة كرقم منتج بعد قرار 2026-05-20) إلى رقم متغيّر تمثيلي
 * فعلي (أول خيار متاح في البركة). يُعيد 0 إن لم يتوفر أي متغيّر صالح/بمخزون.
 * ضروري لأن باني سطر الهدية يتوقّع رقم متغيّر لا رقم منتج (تداخل المعرّفات يسبب صنفاً خاطئاً).
 *
 * @param list<array<string,mixed>> $validatedItems
 */
function orange_cart_promo_fixed_gift_resolve_variant_id(
    PDO $pdo,
    int $fixedProductId,
    array $validatedItems,
    ?int $countryId = null
): int {
    if ($fixedProductId <= 0) {
        return 0;
    }
    foreach (
        orange_cart_gift_promotion_pool_options($pdo, [$fixedProductId], $validatedItems, true, $countryId) as $opt
    ) {
        $vid = (int) ($opt['variant_id'] ?? 0);
        if ($vid > 0) {
            return $vid;
        }
    }

    return 0;
}

/**
 * بند هدية للفاتورة والحجز (مجانية افتراضياً؛ يُمرَّر سعر الوحدة لعروض مثل BOGO الجزئية).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float,is_gift:bool}
 */
function orange_storefront_build_gift_order_line(
    PDO $pdo,
    int $variantId,
    array $validatedItems,
    bool $lockVariants,
    ?float $forcedUnitPrice = null,
    ?int $countryId = null
): array {
    $stockCountryId = orange_cart_promotion_storefront_country_id($pdo, $countryId);
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
    if (!orange_storefront_product_in_active_unified_chain($pdo, (int) $product['id'])) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift item.');
    }
    $used = orange_cart_gift_variant_usage_in_lines($validatedItems, $variantId);
    $stock = orange_warehouse_effective_variant_stock($pdo, $variantId, $stockCountryId);
    if ($stock < $used + 1) {
        throw new RuntimeException(function_exists('t') ? t('checkout_gift_out_of_stock') : 'Gift item out of stock.');
    }

    $unit = 0.0;
    if ($forcedUnitPrice !== null) {
        $unit = max(0.0, round((float) $forcedUnitPrice, 4));
    }

    return [
        'product' => $product,
        'qty' => 1,
        'color' => (string) ($variant['color'] ?? ''),
        'size' => (string) ($variant['size'] ?? ''),
        'variant_id' => $variantId,
        'price' => $unit,
        'cost' => orange_variant_effective_cost($product, $variant),
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
    array $validatedItems,
    ?int $countryId = null
): bool {
    if ($buyerIsRegistered || $subtotal <= 0) {
        return false;
    }
    $asGuest = orange_cart_gift_promotion_select_rule($pdo, $subtotal, false, $countryId);
    $asReg = orange_cart_gift_promotion_select_rule($pdo, $subtotal, true, $countryId);
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
