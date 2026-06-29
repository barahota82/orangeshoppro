<?php

declare(strict_types=1);

require_once __DIR__ . '/cart_promotions.php';
require_once __DIR__ . '/cart_gift_promotions.php';
require_once __DIR__ . '/cart_bogo_promotions.php';
require_once __DIR__ . '/cart_combo_promotions.php';
require_once __DIR__ . '/cart_promo_gift_charge.php';
require_once __DIR__ . '/product_offers.php';
require_once __DIR__ . '/loyalty.php';

/**
 * فاتورة الشركة (INV-C) — منطق مشترك بين شاشة الكشف (preview-company-offers.php)
 * والتطبيق الفعلي (create-manual.php) لضمان تطابق الأرقام المالية حرفياً مع مسار الأونلاين.
 *
 * سلطة فاتورة الشركة: يُكشَف عن العروض دائماً بـ detectReg=true (تجاوز شرط «للمسجّلين فقط»)؛
 * المحاسب هو من يقرّر الصرف لكل عرض، ويُعرض له هل العميل مسجّل بالموقع فعلاً.
 *
 * أساس الحساب = سعر التجزئة (price × qty) كما في الأونلاين، بصرف النظر عن خصم البند اليدوي.
 */

/**
 * اختيارات الخصومات (كومبو + خصم سلة + عرض منتج) على أساس التجزئة — نفس صيَغ order_intake_queue.php.
 *
 * @param list<array{product:array<string,mixed>,qty:int,price:float,variant_id:int}> $validatedItems
 * @return array{
 *   non_offer_items: list<array<string,mixed>>,
 *   non_offer_subtotal: float,
 *   combo_pick: ?array<string,mixed>,
 *   combo_id: ?int,
 *   combo_discount: float,
 *   promo: ?array<string,mixed>,
 *   promo_id: ?int,
 *   promo_discount: float,
 *   product_offer_discount: float,
 *   payable_after_item_discounts: float
 * }
 */
function orange_company_invoice_offer_picks(
    PDO $pdo,
    array $validatedItems,
    float $retailSubtotal,
    int $countryId
): array {
    $detectReg = true;
    $retailSubtotal = round($retailSubtotal, 4);

    // سياسة «العرض بديل» (س4/2): استبعاد بنود عرض المنتج من أساس الكومبو/خصم السلة.
    $offerPartition = orange_product_offer_partition_items($pdo, $validatedItems, $countryId);
    $nonOfferItems = $offerPartition['non_offer_items'];
    $nonOfferSubtotal = max(0.0, round($retailSubtotal - (float) $offerPartition['offer_items_value'], 4));

    $comboPick = orange_cart_combo_best_match($pdo, $nonOfferItems, $detectReg, $countryId);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;

    $cartPromoBase = max(0.0, round($nonOfferSubtotal - $comboDiscount, 4));
    $promo = orange_cart_promotion_resolve($pdo, $cartPromoBase, $detectReg, $countryId);
    $promoDiscount = $promo !== null ? (float) $promo['discount'] : 0.0;
    $promoId = $promo !== null ? (int) $promo['id'] : null;

    $productOfferDiscount = (float) $offerPartition['offer_discount'];
    $maxOfferRoom = max(0.0, round($retailSubtotal - $comboDiscount - $promoDiscount, 4));
    if ($productOfferDiscount > $maxOfferRoom) {
        $productOfferDiscount = $maxOfferRoom;
    }
    $payableAfterItemDiscounts = max(
        0.0,
        round($retailSubtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4)
    );

    return [
        'non_offer_items' => $nonOfferItems,
        'non_offer_subtotal' => $nonOfferSubtotal,
        'combo_pick' => $comboPick,
        'combo_id' => $comboId,
        'combo_discount' => round($comboDiscount, 4),
        'promo' => $promo,
        'promo_id' => $promoId,
        'promo_discount' => round($promoDiscount, 4),
        'product_offer_discount' => round($productOfferDiscount, 4),
        'payable_after_item_discounts' => $payableAfterItemDiscounts,
    ];
}

/**
 * يحلّ سطر هدية «مجموع السلة» لفاتورة الشركة (سلطة التجاوز: مسجّل=true)، عند تأكيد المحاسب فقط.
 * يبني سطر هدية مجاني/مخفّض ويعيد خصمه الصريح؛ يرمي استثناءً عند اختيار هدية غير صالح.
 *
 * @param list<array<string,mixed>> $validatedItems
 * @return array{line: array<string,mixed>, promo_id: int, variant_id: int, discount: float}|null
 */
function orange_company_invoice_resolve_gift_line(
    PDO $pdo,
    array $validatedItems,
    float $retailSubtotal,
    int $countryId,
    int $pickVariantId
): ?array {
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $retailSubtotal, true, $countryId);
    if ($giftRule === null) {
        return null;
    }
    $pickVid = 0;
    if (($giftRule['gift_kind'] ?? '') === 'fixed') {
        // المخزَّن رقم منتج؛ نحلّه إلى متغيّر تمثيلي حقيقي قبل التسعير/البناء.
        $pickVid = orange_cart_promo_fixed_gift_resolve_variant_id($pdo, (int) ($giftRule['fixed_variant_id'] ?? 0), $validatedItems, $countryId);
        if ($pickVid <= 0) {
            return null;
        }
    } else {
        $pickVid = $pickVariantId;
        if ($pickVid <= 0) {
            throw new RuntimeException('اختر صنف الهدية المجانية المؤهَّلة لعرض مجموع السلة.');
        }
        $allowed = [];
        foreach (orange_cart_gift_promotion_pool_options($pdo, $giftRule['pool_variant_ids'], $validatedItems, true, $countryId) as $opt) {
            $allowed[(int) ($opt['variant_id'] ?? 0)] = true;
        }
        if (!isset($allowed[$pickVid])) {
            throw new RuntimeException('صنف الهدية المختار غير صالح لهذا العرض.');
        }
    }
    if ($pickVid <= 0) {
        return null;
    }

    $giftUnit = orange_cart_promo_resolve_gift_unit_price_from_rule($pdo, $giftRule, $pickVid);
    $giftRetail = orange_cart_promo_gift_variant_retail_unit($pdo, $pickVid);
    if ($giftRetail < $giftUnit) {
        $giftRetail = $giftUnit;
    }
    $line = orange_storefront_build_gift_order_line($pdo, $pickVid, $validatedItems, true, $giftRetail, $countryId);
    $discount = round(max(0.0, $giftRetail - $giftUnit) * (int) ($line['qty'] ?? 1), 4);

    return [
        'line' => $line,
        'promo_id' => (int) $giftRule['id'],
        'variant_id' => $pickVid,
        'discount' => $discount,
    ];
}

/**
 * يحلّ سطر هدية BOGO لفاتورة الشركة (سلطة التجاوز: مسجّل=true)، عند تأكيد المحاسب فقط.
 *
 * @param list<array<string,mixed>> $linesIncludingGift البنود + سطر هدية مجموع السلة إن وُجد
 * @return array{line: array<string,mixed>, promo_id: int, variant_id: int, discount: float}|null
 */
function orange_company_invoice_resolve_bogo_line(
    PDO $pdo,
    array $linesIncludingGift,
    int $countryId,
    int $pickVariantId
): ?array {
    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $linesIncludingGift, true, $countryId);
    if ($bogoRule === null) {
        return null;
    }
    $pickVid = 0;
    if (($bogoRule['gift_kind'] ?? '') === 'fixed') {
        // المخزَّن رقم منتج؛ نحلّه إلى متغيّر تمثيلي حقيقي قبل التسعير/البناء.
        $pickVid = orange_cart_promo_fixed_gift_resolve_variant_id($pdo, (int) ($bogoRule['fixed_variant_id'] ?? 0), $linesIncludingGift, $countryId);
        if ($pickVid <= 0) {
            return null;
        }
    } else {
        $pickVid = $pickVariantId;
        if ($pickVid <= 0) {
            throw new RuntimeException('اختر صنف هدية عرض BOGO المؤهَّل.');
        }
        $allowed = [];
        foreach (orange_cart_gift_promotion_pool_options($pdo, $bogoRule['pool_variant_ids'], $linesIncludingGift, true, $countryId) as $opt) {
            $allowed[(int) ($opt['variant_id'] ?? 0)] = true;
        }
        if (!isset($allowed[$pickVid])) {
            throw new RuntimeException('صنف هدية BOGO المختار غير صالح لهذا العرض.');
        }
    }
    if ($pickVid <= 0) {
        return null;
    }

    $bogoUnit = orange_cart_bogo_resolve_gift_unit_price($pdo, $bogoRule, $pickVid);
    $bogoRetail = orange_cart_promo_gift_variant_retail_unit($pdo, $pickVid);
    if ($bogoRetail < $bogoUnit) {
        $bogoRetail = $bogoUnit;
    }
    $line = orange_storefront_build_gift_order_line($pdo, $pickVid, $linesIncludingGift, true, $bogoRetail, $countryId);
    $line['is_bogo_gift'] = true;
    $discount = round(max(0.0, $bogoRetail - $bogoUnit) * (int) ($line['qty'] ?? 1), 4);

    return [
        'line' => $line,
        'promo_id' => (int) $bogoRule['id'],
        'variant_id' => $pickVid,
        'discount' => $discount,
    ];
}
