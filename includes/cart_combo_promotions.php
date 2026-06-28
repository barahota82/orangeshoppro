<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/cart_promo_schedule.php';
require_once __DIR__ . '/cart_promo_stock_health.php';

/**
 * @return list<array{product_id:int,qty:int}>
 */
function orange_cart_combo_parse_components_json(PDO $pdo, ?string $json): array
{
    return orange_cart_promo_parse_components_json($pdo, $json);
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array<int, array{qty:int, unit:float}>
 */
function orange_cart_combo_aggregate_variant_units(array $validatedItems): array
{
    return orange_cart_promo_aggregate_product_units($validatedItems);
}

/**
 * أفضل عرض كومبو واحد: أقصى توفير يحققه المخزون الحالي في السلة (منتج كامل — أي لون/مقاس).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array{id:int, discount:float, bundles:int}|null
 */
function orange_cart_combo_best_match(
    PDO $pdo,
    array $validatedItems,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): ?array {
    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        return null;
    }
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_combo_promotions', '', $cid);
    $st = $pdo->prepare(
        'SELECT id, title_ar, title_en, show_name_to_customer, components_json, combo_price, requires_registered_account, first_delivered_order_only, is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_combo_promotions
         WHERE 1=1' . orange_cart_promo_schedule_sql('cart_combo_promotions') . $bind['sql'] . '
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($bind['params']);
    $byP = orange_cart_promo_aggregate_product_units($validatedItems);
    $best = null;
    $bestDisc = 0.0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!orange_cart_promo_row_is_customer_effective($row)) {
            continue;
        }
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        if ((int) ($row['first_delivered_order_only'] ?? 0) === 1
            && !orange_cart_promo_buyer_first_delivered_ok($pdo, $buyerAccountId, $buyerPhone, $cid)) {
            continue;
        }
        $comps = orange_cart_promo_parse_components_json($pdo, $row['components_json'] ?? null);
        if (count($comps) < 2) {
            continue;
        }
        if (!orange_cart_promo_apply_stock_pause_for_rule($pdo, 'cart_combo_promotions', $row, $cid)) {
            continue;
        }
        $comboPrice = round((float) ($row['combo_price'] ?? 0), 4);
        if ($comboPrice <= 0) {
            continue;
        }
        $bundles = PHP_INT_MAX;
        $sumPerBundle = 0.0;
        $ok = true;
        foreach ($comps as $c) {
            $pid = (int) $c['product_id'];
            $need = (int) $c['qty'];
            if (!isset($byP[$pid]) || $byP[$pid]['qty'] < $need) {
                $ok = false;
                break;
            }
            $bundles = min($bundles, intdiv($byP[$pid]['qty'], $need));
            $sumPerBundle += $byP[$pid]['unit'] * $need;
        }
        if (!$ok || $bundles < 1 || $bundles === PHP_INT_MAX) {
            continue;
        }
        if ($sumPerBundle <= $comboPrice + 1e-6) {
            continue;
        }
        $savings = round($bundles * ($sumPerBundle - $comboPrice), 4);
        if ($savings > $bestDisc + 1e-6) {
            $bestDisc = $savings;
            $best = [
                'id' => (int) $row['id'],
                'discount' => $savings,
                'bundles' => $bundles,
                'display_name' => orange_promo_customer_display_name($row, null, 'title_ar', 'title_en'),
            ];
        }
    }

    return $best;
}

/**
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_combo_register_unlock_teaser_applies(
    PDO $pdo,
    array $validatedItems,
    bool $buyerIsRegistered,
    ?int $countryId = null
): bool {
    if ($buyerIsRegistered || count($validatedItems) === 0) {
        return false;
    }
    $asGuest = orange_cart_combo_best_match($pdo, $validatedItems, false, $countryId);
    $asReg = orange_cart_combo_best_match($pdo, $validatedItems, true, $countryId);
    if ($asReg === null || $asReg['discount'] <= 1e-6) {
        return false;
    }
    $gd = $asGuest !== null ? (float) $asGuest['discount'] : 0.0;

    return $asReg['discount'] > $gd + 1e-6;
}

/**
 * @return list<array{id:int,title_ar:string,title_en:string,components:list<array{product_id:int,qty:int,product_name?:string,code?:string}>,combo_price:float,requires_registered_account:int,sort_order:int,is_active:int}>
 */
function orange_cart_combo_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_combo_promotions')) {
        return [];
    }
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_combo_promotions', '', $cid);
    $hasPromoText = orange_table_has_column($pdo, 'cart_combo_promotions', 'promo_text_ar');
    $promoCols = $hasPromoText ? ', promo_text_ar, promo_text_en, promo_text_fil, promo_text_hi' : '';
    $st = $pdo->prepare(
        'SELECT id, title_ar, title_en, show_name_to_customer, show_old_price_to_customer, components_json, combo_price, requires_registered_account,
                first_delivered_order_only, sort_order, is_active,
                is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason' . $promoCols . '
         FROM cart_combo_promotions WHERE 1=1' . $bind['sql'] . ' ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($bind['params']);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $comps = orange_cart_promo_parse_components_json($pdo, $row['components_json'] ?? null);
        $out[] = [
            'id' => (int) $row['id'],
            'title_ar' => (string) ($row['title_ar'] ?? ''),
            'title_en' => (string) ($row['title_en'] ?? ''),
            'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
            'show_old_price_to_customer' => (int) ($row['show_old_price_to_customer'] ?? 0),
            'promo_text_ar' => (string) ($row['promo_text_ar'] ?? ''),
            'promo_text_en' => (string) ($row['promo_text_en'] ?? ''),
            'promo_text_fil' => (string) ($row['promo_text_fil'] ?? ''),
            'promo_text_hi' => (string) ($row['promo_text_hi'] ?? ''),
            'components' => orange_cart_promo_components_with_labels($pdo, $comps),
            'combo_price' => (float) ($row['combo_price'] ?? 0),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'first_delivered_order_only' => (int) ($row['first_delivered_order_only'] ?? 0),
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
