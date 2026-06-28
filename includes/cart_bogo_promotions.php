<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_taxonomy_migrate.php';
require_once __DIR__ . '/catalog_unified_product_helpers.php';
require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';
require_once __DIR__ . '/cart_promo_products.php';
require_once __DIR__ . '/cart_promotion_country.php';
require_once __DIR__ . '/cart_promo_schedule.php';

/**
 * سعر وحدة بند هدية BOGO بعد تطبيق سياسة التسعير الجزئي (مجاني / نسبة من التجزئة / سعر ثابت / خصم مبلغ من التجزئة).
 *
 * @param array<string,mixed> $rule صف قاعدة أو مصفوفة مُرجعة من orange_cart_bogo_promotion_select_rule
 */
function orange_cart_bogo_resolve_gift_unit_price(PDO $pdo, array $rule, int $variantId): float
{
    return orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $rule, $variantId);
}

/**
 * أعلى سعر وحدة ممكن لهدية BOGO في معاينة العربة (للدمج في الإجمالي عند تسعير جزئي).
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $bogoCtxValidatedItems
 */
function orange_cart_bogo_preview_gift_charge_upper_bound(PDO $pdo, array $rule, array $bogoCtxValidatedItems): float
{
    return orange_cart_promo_preview_gift_max_unit_charge($pdo, $rule, $bogoCtxValidatedItems);
}

/**
 * @param array<string,mixed> $row صف من cart_bogo_promotions
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_bogo_rule_matches_cart(PDO $pdo, array $validatedItems, array $row): bool
{
    $kindRaw = strtolower(trim((string) ($row['bogo_kind'] ?? '')));
    if ($kindRaw === 'buy_bundle') {
        $comps = orange_cart_promo_parse_components_json($pdo, $row['buy_components_json'] ?? null);
        if (count($comps) < 2) {
            return false;
        }
        $uniq = [];
        foreach ($comps as $c) {
            $uniq[(int) $c['product_id']] = true;
        }
        if (count($uniq) < 2) {
            return false;
        }
        $byP = orange_cart_promo_aggregate_product_units($validatedItems);
        foreach ($comps as $c) {
            $pid = (int) $c['product_id'];
            $need = (int) $c['qty'];
            if (!isset($byP[$pid]) || $byP[$pid]['qty'] < $need) {
                return false;
            }
        }

        return true;
    }

    $kind = $kindRaw === 'same_category' ? 'same_category' : 'same_variant';
    $minQ = max(2, (int) ($row['min_buy_qty'] ?? 2));

    if ($kind === 'same_variant') {
        $byP = orange_cart_promo_aggregate_product_units($validatedItems);
        foreach ($byP as $rowAgg) {
            if ((int) ($rowAgg['qty'] ?? 0) >= $minQ) {
                return true;
            }
        }

        return false;
    }

    $unifiedCat = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo)
        && function_exists('orange_table_exists')
        && orange_table_exists($pdo, 'catalog_categories')
        && orange_table_exists($pdo, 'catalog_subcategories');

    if (!$unifiedCat) {
        /* قواعد «نفس الفئة» تُطابق فقط عبر الشجرة الموحّدة (catalog_categories.id) — لا مسار تراثي. */
        return false;
    }

    $catId = (int) ($row['category_id'] ?? 0);
    if ($catId <= 0) {
        return false;
    }
    $distinct = [];
    foreach ($validatedItems as $line) {
        $pid = (int) ($line['product']['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $uCat = orange_catalog_product_catalog_category_id($pdo, $pid);
        if ($uCat > 0 && $uCat === $catId) {
            $distinct[$pid] = true;
        }
    }

    return count($distinct) >= $minQ;
}

/**
 * @return list<array<string,mixed>>
 */
function orange_cart_bogo_promotions_admin_list(PDO $pdo): array
{
    if (!orange_table_exists($pdo, 'cart_bogo_promotions')) {
        return [];
    }
    $cid = orange_cart_promotion_admin_country_id($pdo);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_bogo_promotions', '', $cid);
    $st = $pdo->prepare(
        'SELECT id, name_ar, name_en, show_name_to_customer, show_old_price_to_customer, bogo_kind, category_id, min_buy_qty, buy_components_json, requires_registered_account, first_delivered_order_only, gift_kind, fixed_variant_id, pool_variant_ids,
                gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active, is_always_on,
                valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_bogo_promotions WHERE 1=1' . $bind['sql'] . ' ORDER BY sort_order ASC, id ASC'
    );
    $st->execute($bind['params']);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $fixStored = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $fixPid = $fixStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixStored) : 0;

        $out[] = [
            'id' => (int) $row['id'],
            'name_ar' => (string) ($row['name_ar'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'show_name_to_customer' => (int) ($row['show_name_to_customer'] ?? 0),
            'show_old_price_to_customer' => (int) ($row['show_old_price_to_customer'] ?? 0),
            'bogo_kind' => (string) ($row['bogo_kind'] ?? 'same_variant'),
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'min_buy_qty' => (int) ($row['min_buy_qty'] ?? 2),
            'buy_components' => orange_cart_promo_components_with_labels(
                $pdo,
                orange_cart_promo_parse_components_json($pdo, $row['buy_components_json'] ?? null)
            ),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'first_delivered_order_only' => (int) ($row['first_delivered_order_only'] ?? 0),
            'gift_kind' => (string) ($row['gift_kind'] ?? 'choice'),
            'fixed_product_id' => $fixPid > 0 ? $fixPid : null,
            'fixed_variant_id' => $fixPid > 0 ? $fixPid : null,
            'pool_product_ids' => orange_cart_promo_parse_product_pool($pdo, $row['pool_variant_ids'] ?? null),
            'pool_variant_ids' => orange_cart_promo_parse_product_pool($pdo, $row['pool_variant_ids'] ?? null),
            'gift_unit_charge_kind' => (string) ($row['gift_unit_charge_kind'] ?? 'free'),
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
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
 * أول قاعدة نشطة بالترتيب تطابق السلة.
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return array<string,mixed>|null
 */
function orange_cart_bogo_promotion_select_rule(
    PDO $pdo,
    array $validatedItems,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): ?array {
    if (!orange_table_exists($pdo, 'cart_bogo_promotions') || count($validatedItems) === 0) {
        return null;
    }
    $cid = orange_cart_promotion_storefront_country_id($pdo, $countryId);
    $bind = orange_cart_promotion_sql_bind($pdo, 'cart_bogo_promotions', '', $cid);
    $st = $pdo->prepare(
        "SELECT id, name_ar, name_en, show_name_to_customer, bogo_kind, category_id, min_buy_qty, buy_components_json, requires_registered_account, first_delivered_order_only, gift_kind, fixed_variant_id, pool_variant_ids,
                gift_unit_charge_kind, gift_unit_charge_value, is_active, is_always_on, valid_from, valid_to, auto_paused_at, auto_paused_reason
         FROM cart_bogo_promotions
         WHERE 1=1" . orange_cart_promo_schedule_sql('cart_bogo_promotions') . $bind['sql'] . "
         ORDER BY sort_order ASC, id ASC"
    );
    $st->execute($bind['params']);
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
        if (!orange_cart_bogo_rule_matches_cart($pdo, $validatedItems, $row)) {
            continue;
        }
        $bogoKindNorm = strtolower(trim((string) ($row['bogo_kind'] ?? ''))) === 'buy_bundle' ? 'buy_bundle' : (strtolower(trim((string) ($row['bogo_kind'] ?? ''))) === 'same_category' ? 'same_category' : 'same_variant');
        $gKind = strtolower(trim((string) ($row['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
        $fixedStored = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $fixedPid = $fixedStored > 0 ? orange_cart_promo_resolve_stored_product_id($pdo, $fixedStored) : 0;
        $pool = orange_cart_gift_parse_pool($pdo, $row['pool_variant_ids'] ?? null);
        if ($gKind === 'fixed' && $fixedPid <= 0) {
            continue;
        }
        if ($gKind === 'choice' && count($pool) === 0) {
            continue;
        }

        $gcKind = strtolower(trim((string) ($row['gift_unit_charge_kind'] ?? 'free')));
        if (!in_array($gcKind, ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'], true)) {
            $gcKind = 'free';
        }

        $candidate = [
            'id' => (int) $row['id'],
            'bogo_kind' => $bogoKindNorm,
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'min_buy_qty' => (int) ($row['min_buy_qty'] ?? 2),
            'gift_kind' => $gKind,
            'fixed_product_id' => $fixedPid > 0 ? $fixedPid : null,
            'fixed_variant_id' => $fixedPid > 0 ? $fixedPid : null,
            'pool_product_ids' => $pool,
            'pool_variant_ids' => $pool,
            'gift_unit_charge_kind' => $gcKind,
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
            'display_name' => orange_promo_customer_display_name($row),
        ];
        $pauseRow = array_merge($row, ['id' => (int) $row['id']]);
        if (!orange_cart_promo_maybe_pause_rule_if_no_stock($pdo, 'cart_bogo_promotions', $pauseRow, $validatedItems, $countryId)) {
            continue;
        }

        return $candidate;
    }

    return null;
}

/**
 * للضيف فقط: تحفيز بالتسجيل إن كان عرض BOGO للمسجّلين يطابق السلة ولا يُطبَّق على الضيف.
 *
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 */
function orange_cart_bogo_register_unlock_teaser_applies(
    PDO $pdo,
    array $validatedItems,
    bool $buyerIsRegistered,
    ?int $countryId = null
): bool {
    if ($buyerIsRegistered || count($validatedItems) === 0) {
        return false;
    }
    $asGuest = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, false, $countryId);
    $asReg = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, true, $countryId);
    if ($asReg === null) {
        return false;
    }
    if ($asGuest !== null && (int) $asGuest['id'] === (int) $asReg['id']) {
        return false;
    }
    if ($asReg['gift_kind'] === 'fixed') {
        $bfv = (int) ($asReg['fixed_variant_id'] ?? 0);
        if ($bfv <= 0 || count(orange_cart_gift_promotion_pool_options($pdo, [$bfv], $validatedItems, false)) === 0) {
            return false;
        }
    } else {
        if (count(orange_cart_gift_promotion_pool_options($pdo, $asReg['pool_variant_ids'], $validatedItems, false)) === 0) {
            return false;
        }
    }

    return true;
}
