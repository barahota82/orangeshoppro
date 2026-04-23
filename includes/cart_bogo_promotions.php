<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';

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
function orange_cart_bogo_rule_matches_cart(array $validatedItems, array $row): bool
{
    $kindRaw = strtolower(trim((string) ($row['bogo_kind'] ?? '')));
    if ($kindRaw === 'buy_bundle') {
        $comps = orange_cart_combo_parse_components_json($row['buy_components_json'] ?? null);
        if (count($comps) < 2) {
            return false;
        }
        $uniq = [];
        foreach ($comps as $c) {
            $uniq[(int) $c['variant_id']] = true;
        }
        if (count($uniq) < 2) {
            return false;
        }
        $byV = orange_cart_combo_aggregate_variant_units($validatedItems);
        foreach ($comps as $c) {
            $vid = (int) $c['variant_id'];
            $need = (int) $c['qty'];
            if (!isset($byV[$vid]) || $byV[$vid]['qty'] < $need) {
                return false;
            }
        }

        return true;
    }

    $kind = $kindRaw === 'same_category' ? 'same_category' : 'same_variant';
    $minQ = max(2, (int) ($row['min_buy_qty'] ?? 2));

    if ($kind === 'same_variant') {
        foreach ($validatedItems as $line) {
            if ((int) ($line['qty'] ?? 0) >= $minQ) {
                return true;
            }
        }

        return false;
    }

    $catId = (int) ($row['category_id'] ?? 0);
    if ($catId <= 0) {
        return false;
    }
    $distinct = [];
    foreach ($validatedItems as $line) {
        $pid = (int) ($line['product']['id'] ?? 0);
        $pcat = (int) ($line['product']['category_id'] ?? 0);
        if ($pid > 0 && $pcat === $catId) {
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
    $st = $pdo->query(
        'SELECT id, bogo_kind, category_id, min_buy_qty, buy_components_json, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, gift_unit_charge_kind, gift_unit_charge_value, sort_order, is_active
         FROM cart_bogo_promotions ORDER BY sort_order ASC, id ASC'
    );
    if (!$st) {
        return [];
    }
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $fix = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;

        $out[] = [
            'id' => (int) $row['id'],
            'bogo_kind' => (string) ($row['bogo_kind'] ?? 'same_variant'),
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'min_buy_qty' => (int) ($row['min_buy_qty'] ?? 2),
            'buy_components' => orange_cart_combo_parse_components_json($row['buy_components_json'] ?? null),
            'requires_registered_account' => (int) ($row['requires_registered_account'] ?? 0),
            'gift_kind' => (string) ($row['gift_kind'] ?? 'choice'),
            'fixed_variant_id' => $fix > 0 ? $fix : null,
            'pool_variant_ids' => orange_cart_gift_parse_pool($row['pool_variant_ids'] ?? null),
            'gift_unit_charge_kind' => (string) ($row['gift_unit_charge_kind'] ?? 'free'),
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'is_active' => (int) ($row['is_active'] ?? 0),
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
function orange_cart_bogo_promotion_select_rule(PDO $pdo, array $validatedItems, bool $buyerRegistered): ?array
{
    if (!orange_table_exists($pdo, 'cart_bogo_promotions') || count($validatedItems) === 0) {
        return null;
    }
    $st = $pdo->query(
        "SELECT id, bogo_kind, category_id, min_buy_qty, buy_components_json, requires_registered_account, gift_kind, fixed_variant_id, pool_variant_ids, gift_unit_charge_kind, gift_unit_charge_value
         FROM cart_bogo_promotions
         WHERE is_active = 1
         ORDER BY sort_order ASC, id ASC"
    );
    if (!$st) {
        return null;
    }
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        if ((int) ($row['requires_registered_account'] ?? 0) === 1 && !$buyerRegistered) {
            continue;
        }
        if (!orange_cart_bogo_rule_matches_cart($validatedItems, $row)) {
            continue;
        }
        $bogoKindNorm = strtolower(trim((string) ($row['bogo_kind'] ?? ''))) === 'buy_bundle' ? 'buy_bundle' : (strtolower(trim((string) ($row['bogo_kind'] ?? ''))) === 'same_category' ? 'same_category' : 'same_variant');
        $gKind = strtolower(trim((string) ($row['gift_kind'] ?? 'choice'))) === 'fixed' ? 'fixed' : 'choice';
        $fixed = isset($row['fixed_variant_id']) ? (int) $row['fixed_variant_id'] : 0;
        $pool = orange_cart_gift_parse_pool($row['pool_variant_ids'] ?? null);
        if ($gKind === 'fixed' && $fixed <= 0) {
            continue;
        }
        if ($gKind === 'choice' && count($pool) === 0) {
            continue;
        }

        $gcKind = strtolower(trim((string) ($row['gift_unit_charge_kind'] ?? 'free')));
        if (!in_array($gcKind, ['free', 'percent_off', 'fixed_unit', 'amount_off_unit'], true)) {
            $gcKind = 'free';
        }

        return [
            'id' => (int) $row['id'],
            'bogo_kind' => $bogoKindNorm,
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'min_buy_qty' => (int) ($row['min_buy_qty'] ?? 2),
            'gift_kind' => $gKind,
            'fixed_variant_id' => $fixed > 0 ? $fixed : null,
            'pool_variant_ids' => $pool,
            'gift_unit_charge_kind' => $gcKind,
            'gift_unit_charge_value' => (float) ($row['gift_unit_charge_value'] ?? 0),
        ];
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
    bool $buyerIsRegistered
): bool {
    if ($buyerIsRegistered || count($validatedItems) === 0) {
        return false;
    }
    $asGuest = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, false);
    $asReg = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, true);
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
