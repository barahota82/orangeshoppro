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
 *   giftDiscount: float,
 *   bogoLine: ?array,
 *   bogoPromoId: ?int,
 *   bogoGiftVariantId: ?int,
 *   bogoDiscount: float,
 *   linesForStock: list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float,is_gift?:bool,is_bogo_gift?:bool}>
 * }
 */
function orange_storefront_build_promotional_gift_lines(
    PDO $pdo,
    array $payload,
    array $validatedItems,
    float $subtotal,
    bool $buyerRegistered,
    ?int $countryId = null,
    ?int $buyerAccountId = null,
    ?string $buyerPhone = null
): array {
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $buyerRegistered, $countryId, $buyerAccountId, $buyerPhone);
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
    $giftDiscount = 0.0;
    if ($giftRule !== null) {
        $pickVid = 0;
        if ($giftRule['gift_kind'] === 'fixed') {
            // المخزَّن رقم منتج؛ نحلّه إلى متغيّر تمثيلي حقيقي قبل التسعير/البناء.
            $pickVid = orange_cart_promo_fixed_gift_resolve_variant_id(
                $pdo,
                (int) ($giftRule['fixed_variant_id'] ?? 0),
                $validatedItems,
                $countryId
            );
        } else {
            $pickVid = (int) ($payload['gift_variant_id'] ?? 0);
            if ($pickVid <= 0) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_pick_required') : 'Choose your free gift.');
            }
            $allowedGiftVids = [];
            foreach (
                orange_cart_gift_promotion_pool_options(
                    $pdo,
                    $giftRule['pool_variant_ids'],
                    $validatedItems,
                    true,
                    $countryId
                ) as $opt
            ) {
                $allowedGiftVids[(int) ($opt['variant_id'] ?? 0)] = true;
            }
            if ($pickVid <= 0 || !isset($allowedGiftVids[$pickVid])) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift choice.');
            }
        }
        if ($pickVid > 0) {
            $giftUnit = orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $giftRule, $pickVid);
            $giftRetail = orange_cart_promo_gift_variant_retail_unit($pdo, $pickVid);
            if ($giftRetail < $giftUnit) {
                $giftRetail = $giftUnit;
            }
            // الهدية تُسعَّر بالتجزئة على البند، والفارق (تجزئة − ما يدفعه العميل) يظهر كبند خصم صريح.
            $giftLine = orange_storefront_build_gift_order_line($pdo, $pickVid, $validatedItems, true, $giftRetail);
            $giftDiscount = round(max(0.0, $giftRetail - $giftUnit) * (int) ($giftLine['qty'] ?? 1), 4);
            $giftPromoId = $giftRule['id'];
            $giftVariantId = $pickVid;
        }
    }
    $linesAfterSubtotalGift = $validatedItems;
    if ($giftLine !== null) {
        $linesAfterSubtotalGift[] = $giftLine;
    }

    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerRegistered, $countryId, $buyerAccountId, $buyerPhone);
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
    $bogoDiscount = 0.0;
    if ($bogoRule !== null) {
        $bogoPick = 0;
        if ($bogoRule['gift_kind'] === 'fixed') {
            // المخزَّن رقم منتج؛ نحلّه إلى متغيّر تمثيلي حقيقي قبل التسعير/البناء.
            $bogoPick = orange_cart_promo_fixed_gift_resolve_variant_id(
                $pdo,
                (int) ($bogoRule['fixed_variant_id'] ?? 0),
                $linesAfterSubtotalGift,
                $countryId
            );
        } else {
            $bogoPick = (int) ($payload['bogo_gift_variant_id'] ?? 0);
            if ($bogoPick <= 0) {
                throw new RuntimeException(function_exists('t') ? t('checkout_bogo_gift_pick_required') : 'Choose BOGO gift.');
            }
            $allowedBogoVids = [];
            foreach (
                orange_cart_gift_promotion_pool_options(
                    $pdo,
                    $bogoRule['pool_variant_ids'],
                    $linesAfterSubtotalGift,
                    true,
                    $countryId
                ) as $opt
            ) {
                $allowedBogoVids[(int) ($opt['variant_id'] ?? 0)] = true;
            }
            if ($bogoPick <= 0 || !isset($allowedBogoVids[$bogoPick])) {
                throw new RuntimeException(function_exists('t') ? t('checkout_gift_variant_invalid') : 'Invalid gift choice.');
            }
        }
        if ($bogoPick > 0) {
            $bogoUnit = orange_cart_bogo_resolve_gift_unit_price($pdo, $bogoRule, $bogoPick);
            $bogoRetail = orange_cart_promo_gift_variant_retail_unit($pdo, $bogoPick);
            if ($bogoRetail < $bogoUnit) {
                $bogoRetail = $bogoUnit;
            }
            $bogoLine = orange_storefront_build_gift_order_line($pdo, $bogoPick, $linesAfterSubtotalGift, true, $bogoRetail);
            $bogoLine['is_bogo_gift'] = true;
            $bogoDiscount = round(max(0.0, $bogoRetail - $bogoUnit) * (int) ($bogoLine['qty'] ?? 1), 4);
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
        'giftDiscount' => round((float) $giftDiscount, 4),
        'bogoLine' => $bogoLine,
        'bogoPromoId' => $bogoPromoId,
        'bogoGiftVariantId' => $bogoGiftVariantId,
        'bogoDiscount' => round((float) $bogoDiscount, 4),
        'linesForStock' => $linesForStock,
    ];
}
