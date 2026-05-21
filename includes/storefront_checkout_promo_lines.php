<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_bogo_promotions.php';

/**
 * بنود الهدايا (مجموع سلة + BOGO) للفاتورة والحجز — نفس المنطق في إنشاء الطلب وتعديل البنود.
 *
 * @param array<string,mixed> $payload جسم الطلب؛ يُتوقع `gift_variant_id` و`bogo_gift_variant_id` عند عروض الاختيار
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return array{
 *   giftLine: ?array,
 *   giftPromoId: ?int,
 *   giftVariantId: ?int,
 *   bogoLine: ?array,
 *   bogoPromoId: ?int,
 *   bogoGiftVariantId: ?int,
 *   linesForStock: list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float,is_gift?:bool,is_bogo_gift?:bool}>
 * }
 */
function orange_storefront_build_promotional_gift_lines(
    PDO $pdo,
    array $payload,
    array $validatedItems,
    float $subtotal,
    bool $buyerRegistered,
    ?int $countryId = null
): array {
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $buyerRegistered, $countryId);
    if ($giftRule !== null) {
        if ($giftRule['gift_kind'] === 'fixed') {
            $fv = (int) ($giftRule['fixed_variant_id'] ?? 0);
            if (
                $fv <= 0
                || count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, true)) === 0
            ) {
                $giftRule = null;
            }
        } else {
            if (
                count(
                    orange_cart_gift_promotion_pool_options(
                        $pdo,
                        $giftRule['pool_variant_ids'],
                        $validatedItems,
                        true
                    )
                ) === 0
            ) {
                $giftRule = null;
            }
        }
    }
    $giftLine = null;
    $giftPromoId = null;
    $giftVariantId = null;
    if ($giftRule !== null) {
        $pickVid = 0;
        if ($giftRule['gift_kind'] === 'fixed') {
            $pickVid = (int) ($giftRule['fixed_variant_id'] ?? 0);
        } else {
            $pickVid = (int) ($payload['gift_variant_id'] ?? 0);
            if ($pickVid <= 0) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_pick_required') : 'Choose your free gift.');
            }
            if (!in_array($pickVid, $giftRule['pool_variant_ids'], true)) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift choice.');
            }
        }
        if ($pickVid > 0) {
            $giftUnit = orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $giftRule, $pickVid);
            $giftLine = orange_storefront_build_gift_order_line($pdo, $pickVid, $validatedItems, true, $giftUnit);
            $giftPromoId = $giftRule['id'];
            $giftVariantId = $pickVid;
        }
    }
    $linesAfterSubtotalGift = $validatedItems;
    if ($giftLine !== null) {
        $linesAfterSubtotalGift[] = $giftLine;
    }

    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerRegistered, $countryId);
    if ($bogoRule !== null) {
        if ($bogoRule['gift_kind'] === 'fixed') {
            $bfv = (int) ($bogoRule['fixed_variant_id'] ?? 0);
            if (
                $bfv <= 0
                || count(orange_cart_gift_promotion_pool_options($pdo, [$bfv], $linesAfterSubtotalGift, true)) === 0
            ) {
                $bogoRule = null;
            }
        } else {
            if (
                count(
                    orange_cart_gift_promotion_pool_options(
                        $pdo,
                        $bogoRule['pool_variant_ids'],
                        $linesAfterSubtotalGift,
                        true
                    )
                ) === 0
            ) {
                $bogoRule = null;
            }
        }
    }
    $bogoLine = null;
    $bogoPromoId = null;
    $bogoGiftVariantId = null;
    if ($bogoRule !== null) {
        $bogoPick = 0;
        if ($bogoRule['gift_kind'] === 'fixed') {
            $bogoPick = (int) ($bogoRule['fixed_variant_id'] ?? 0);
        } else {
            $bogoPick = (int) ($payload['bogo_gift_variant_id'] ?? 0);
            if ($bogoPick <= 0) {
                throw new RuntimeException(function_exists('t') ? t('checkout_bogo_gift_pick_required') : 'Choose BOGO gift.');
            }
            if (!in_array($bogoPick, $bogoRule['pool_variant_ids'], true)) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift choice.');
            }
        }
        if ($bogoPick > 0) {
            $bogoUnit = orange_cart_bogo_resolve_gift_unit_price($pdo, $bogoRule, $bogoPick);
            $bogoLine = orange_storefront_build_gift_order_line($pdo, $bogoPick, $linesAfterSubtotalGift, true, $bogoUnit);
            $bogoLine['is_bogo_gift'] = true;
            $bogoPromoId = $bogoRule['id'];
            $bogoGiftVariantId = $bogoPick;
        }
    }

    $linesForStock = $validatedItems;
    if ($giftLine !== null) {
        $linesForStock[] = $giftLine;
    }
    if ($bogoLine !== null) {
        $linesForStock[] = $bogoLine;
    }

    return [
        'giftLine' => $giftLine,
        'giftPromoId' => $giftPromoId,
        'giftVariantId' => $giftVariantId,
        'bogoLine' => $bogoLine,
        'bogoPromoId' => $bogoPromoId,
        'bogoGiftVariantId' => $bogoGiftVariantId,
        'linesForStock' => $linesForStock,
    ];
}
