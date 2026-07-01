<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_gift_storefront.php';
require_once __DIR__ . '/cart_bogo_promotions.php';

/**
 * بنود هدية مجموع السلة من اختيارات العميل (pool متعدد + per-product charge).
 *
 * @param array<string,mixed> $giftRule
 * @param array<string,mixed> $payload
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 *
 * @return array{
 *   giftLines: list<array>,
 *   giftLine: ?array,
 *   giftPromoId: ?int,
 *   giftVariantId: ?int,
 *   giftDiscount: float,
 *   giftSelectionsJson: ?string
 * }
 */
function orange_storefront_build_threshold_gift_bundle(
    PDO $pdo,
    array $giftRule,
    array $payload,
    array $validatedItems,
    ?int $countryId = null
): array {
    $giftRule['promo_type'] = 'threshold_gift';
    $rawSelections = orange_cart_gift_storefront_parse_request_selections($payload);
    $selections = orange_cart_gift_storefront_resolve_selections(
        $pdo,
        $giftRule,
        $validatedItems,
        $rawSelections,
        $countryId
    );
    orange_cart_gift_storefront_assert_checkout_ready($giftRule, $rawSelections, $selections);

    $giftLines = [];
    $ctxItems = $validatedItems;
    $giftDiscount = 0.0;
    $firstVid = null;
    foreach ($selections as $sel) {
        if (empty($sel['valid']) || empty($sel['accepted']) || !empty($sel['declined'])) {
            continue;
        }
        $vid = (int) ($sel['variant_id'] ?? 0);
        if ($vid <= 0) {
            continue;
        }
        $retail = (float) ($sel['retail_unit'] ?? 0);
        $charge = (float) ($sel['charge_unit'] ?? 0);
        if ($retail < $charge) {
            $retail = $charge;
        }
        $line = orange_storefront_build_gift_order_line($pdo, $vid, $ctxItems, true, $retail, $countryId);
        $giftDiscount = round(
            $giftDiscount + max(0.0, $retail - $charge) * (int) ($line['qty'] ?? 1),
            4
        );
        $giftLines[] = $line;
        $ctxItems[] = $line;
        if ($firstVid === null) {
            $firstVid = $vid;
        }
    }

    $promoId = (int) ($giftRule['id'] ?? 0);
    $selectionsJson = null;
    if ($selections !== []) {
        $encoded = json_encode(
            orange_cart_gift_storefront_selections_for_response($selections),
            JSON_UNESCAPED_UNICODE
        );
        $selectionsJson = is_string($encoded) ? $encoded : null;
    }

    return [
        'giftLines' => $giftLines,
        'giftLine' => $giftLines[0] ?? null,
        'giftPromoId' => $giftLines !== [] && $promoId > 0 ? $promoId : null,
        'giftVariantId' => $firstVid,
        'giftDiscount' => round($giftDiscount, 4),
        'giftSelectionsJson' => $selectionsJson,
    ];
}

/**
 * بنود الهدايا (مجموع سلة + BOGO) للفاتورة والحجز — نفس المنطق في إنشاء الطلب وتعديل البنود.
 *
 * @param array<string,mixed> $payload جسم الطلب؛ `gift_selections` / `gift_variant_id` و`bogo_gift_variant_id`
 * @param list<array{product:array<string,mixed>,qty:int,color:string,size:string,variant_id:int,price:float,cost:float}> $validatedItems
 * @return array{
 *   giftLines: list<array>,
 *   giftLine: ?array,
 *   giftPromoId: ?int,
 *   giftVariantId: ?int,
 *   giftDiscount: float,
 *   giftSelectionsJson: ?string,
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
                || count(orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, true, $countryId)) === 0
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
                        true,
                        $countryId
                    )
                ) === 0
            ) {
                $giftRule = null;
            }
        }
    }

    $giftLines = [];
    $giftLine = null;
    $giftPromoId = null;
    $giftVariantId = null;
    $giftDiscount = 0.0;
    $giftSelectionsJson = null;
    if ($giftRule !== null) {
        $bundle = orange_storefront_build_threshold_gift_bundle($pdo, $giftRule, $payload, $validatedItems, $countryId);
        $giftLines = $bundle['giftLines'];
        $giftLine = $bundle['giftLine'];
        $giftPromoId = $bundle['giftPromoId'];
        $giftVariantId = $bundle['giftVariantId'];
        $giftDiscount = (float) $bundle['giftDiscount'];
        $giftSelectionsJson = $bundle['giftSelectionsJson'];
    }

    $linesAfterSubtotalGift = $validatedItems;
    foreach ($giftLines as $gl) {
        $linesAfterSubtotalGift[] = $gl;
    }

    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerRegistered, $countryId, $buyerAccountId, $buyerPhone);
    if ($bogoRule !== null) {
        if ($bogoRule['gift_kind'] === 'fixed') {
            $bfv = (int) ($bogoRule['fixed_variant_id'] ?? 0);
            if (
                $bfv <= 0
                || count(orange_cart_gift_promotion_pool_options($pdo, [$bfv], $linesAfterSubtotalGift, true, $countryId)) === 0
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
                        true,
                        $countryId
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
            $bogoLine = orange_storefront_build_gift_order_line($pdo, $bogoPick, $linesAfterSubtotalGift, true, $bogoRetail, $countryId);
            $bogoLine['is_bogo_gift'] = true;
            $bogoDiscount = round(max(0.0, $bogoRetail - $bogoUnit) * (int) ($bogoLine['qty'] ?? 1), 4);
            $bogoPromoId = $bogoRule['id'];
            $bogoGiftVariantId = $bogoPick;
        }
    }

    $linesForStock = $validatedItems;
    foreach ($giftLines as $gl) {
        $linesForStock[] = $gl;
    }
    if ($bogoLine !== null) {
        $linesForStock[] = $bogoLine;
    }

    return [
        'giftLines' => $giftLines,
        'giftLine' => $giftLine,
        'giftPromoId' => $giftPromoId,
        'giftVariantId' => $giftVariantId,
        'giftDiscount' => round((float) $giftDiscount, 4),
        'giftSelectionsJson' => $giftSelectionsJson,
        'bogoLine' => $bogoLine,
        'bogoPromoId' => $bogoPromoId,
        'bogoGiftVariantId' => $bogoGiftVariantId,
        'bogoDiscount' => round((float) $bogoDiscount, 4),
        'linesForStock' => $linesForStock,
    ];
}
