<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_cart_items.php';
require_once __DIR__ . '/../../includes/cart_promotions.php';
require_once __DIR__ . '/../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../includes/cart_gift_storefront.php';
require_once __DIR__ . '/../../includes/cart_bogo_promotions.php';
require_once __DIR__ . '/../../includes/cart_combo_promotions.php';
require_once __DIR__ . '/../../includes/product_offers.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';
require_once __DIR__ . '/../../includes/storefront_api_errors.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0) {
        json_response(['success' => false, 'code' => 'cart_items_required', 'message' => t('checkout_cart_items_required')], 422);
    }

    [$subtotal, $validatedItems] = orange_storefront_validate_cart_items_core($pdo, $items, false);
    $storefrontCountryId = orange_storefront_current_country_id($pdo);

    $acc = current_storefront_account($pdo);
    $buyerReg = $acc !== null;
    // هوية المشتري لأهليّة «أول طلب مُسلَّم» (مثل التوصيل): الحساب أساس، والهاتف من ملف الحساب.
    // الزائر بلا هاتف في المعاينة → عرض «أول طلب مُسلَّم» قد لا يظهر هنا لكنه يُطبَّق عند إنشاء الطلب إن كان مؤهَّلاً.
    $buyerAccountId = ($acc !== null && (int) ($acc['id'] ?? 0) > 0) ? (int) $acc['id'] : null;
    $buyerPhone = ($acc !== null && isset($acc['customer_phone']) && trim((string) $acc['customer_phone']) !== '')
        ? trim((string) $acc['customer_phone'])
        : null;
    // سياسة «العرض بديل» (س4/2): استبعاد البنود ذات عرض المنتج من أساس الكومبو/خصم السلة.
    $offerPartition = orange_product_offer_partition_items($pdo, $validatedItems, $storefrontCountryId);
    $nonOfferItems = $offerPartition['non_offer_items'];
    $nonOfferSubtotal = max(0.0, round($subtotal - (float) $offerPartition['offer_items_value'], 4));
    $comboPick = orange_cart_combo_best_match($pdo, $nonOfferItems, $buyerReg, $storefrontCountryId, $buyerAccountId, $buyerPhone);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
    $cartPromoBase = max(0.0, round($nonOfferSubtotal - $comboDiscount, 4));
    $promo = orange_cart_promotion_resolve($pdo, $cartPromoBase, $buyerReg, $storefrontCountryId, $buyerAccountId, $buyerPhone);
    $promoDiscount = $promo !== null ? (float) $promo['discount'] : 0.0;
    $productOfferDiscount = (float) $offerPartition['offer_discount'];
    $maxOfferRoom = max(0.0, round($subtotal - $comboDiscount - $promoDiscount, 4));
    if ($productOfferDiscount > $maxOfferRoom) {
        $productOfferDiscount = $maxOfferRoom;
    }
    $total = max(0.0, round($subtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4));
    $regTeaser = orange_cart_promotion_register_incentive_teaser($pdo, $cartPromoBase, $buyerReg, $storefrontCountryId);
    $giftRegUnlock = orange_cart_gift_register_unlock_teaser_applies($pdo, $subtotal, $buyerReg, $validatedItems, $storefrontCountryId);
    $bogoRegUnlock = orange_cart_bogo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg, $storefrontCountryId);
    $comboRegUnlock = orange_cart_combo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg, $storefrontCountryId);

    $giftPayload = null;
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $buyerReg, $storefrontCountryId, $buyerAccountId, $buyerPhone);
    if ($giftRule !== null) {
        $giftPayload = orange_cart_gift_storefront_payload($pdo, $giftRule, $validatedItems, $storefrontCountryId, $data);
    }

    $bogoPayload = null;
    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerReg, $storefrontCountryId, $buyerAccountId, $buyerPhone);
    $bogoCtx = $validatedItems;
    if ($bogoRule !== null) {
        $bogoCtx = $validatedItems;
        if ($giftPayload !== null && ($giftPayload['gift_kind'] ?? '') === 'fixed' && !empty($giftPayload['fixed_variant_id'])) {
            try {
                $gv = (int) $giftPayload['fixed_variant_id'];
                if ($gv > 0) {
                    $fake = orange_storefront_build_gift_order_line($pdo, $gv, $validatedItems, false);
                    $bogoCtx = array_merge($validatedItems, [$fake]);
                }
            } catch (Throwable $eB) {
                $bogoCtx = $validatedItems;
            }
        }
        if ($bogoRule['gift_kind'] === 'fixed') {
            $bfv = (int) ($bogoRule['fixed_variant_id'] ?? 0);
            $bopts = $bfv > 0
                ? orange_cart_gift_promotion_pool_options($pdo, [$bfv], $bogoCtx, false)
                : [];
            if (count($bopts) > 0) {
                $bogoPayload = [
                    'id' => $bogoRule['id'],
                    'bogo_kind' => $bogoRule['bogo_kind'],
                    'gift_kind' => 'fixed',
                    'fixed_variant_id' => $bfv,
                    'pool' => [],
                ];
            }
        } else {
            $bp = orange_cart_gift_promotion_pool_options(
                $pdo,
                $bogoRule['pool_variant_ids'],
                $bogoCtx,
                false
            );
            if (count($bp) > 0) {
                $bogoPayload = [
                    'id' => $bogoRule['id'],
                    'bogo_kind' => $bogoRule['bogo_kind'],
                    'gift_kind' => 'choice',
                    'fixed_variant_id' => null,
                    'pool' => $bp,
                ];
            }
        }
    }

    $thresholdGiftChargePreview = 0.0;
    if ($giftRule !== null && $giftPayload !== null) {
        $thresholdGiftChargePreview = (float) (
            $giftPayload['gift_charge_preview']
            ?? $giftPayload['preview_max_gift_unit_charge']
            ?? 0
        );
    }

    $bogoGiftChargePreview = 0.0;
    if ($bogoRule !== null && $bogoPayload !== null) {
        $bogoGiftChargePreview = orange_cart_bogo_preview_gift_charge_upper_bound($pdo, $bogoRule, $bogoCtx);
        $bogoPayload['gift_unit_charge_kind'] = (string) ($bogoRule['gift_unit_charge_kind'] ?? 'free');
        $bogoPayload['gift_unit_charge_value'] = (float) ($bogoRule['gift_unit_charge_value'] ?? 0);
        $bogoPayload['preview_max_gift_unit_charge'] = $bogoGiftChargePreview;
        $bogoPayload['display_name'] = (string) ($bogoRule['display_name'] ?? '');
    }
    $deliveryAreaId = (int) ($data['delivery_area_id'] ?? 0);
    $deliveryFeeBase = 0.0;
    $deliveryFeeDiscount = 0.0;
    $deliveryFee = 0.0;
    $deliveryPromotionPayload = null;
    if ($deliveryAreaId > 0) {
        $previewPhone = trim((string) ($data['phone'] ?? ''));
        if ($previewPhone === '' && $acc !== null && isset($acc['customer_phone'])) {
            $previewPhone = trim((string) $acc['customer_phone']);
        }
        $deliveryBundle = orange_delivery_resolve_checkout_fee_bundle(
            $pdo,
            $deliveryAreaId,
            $buyerReg,
            $storefrontCountryId,
            null,
            ($acc !== null && isset($acc['id'])) ? (int) $acc['id'] : null,
            $previewPhone !== '' ? $previewPhone : null
        );
        $deliveryFeeBase = (float) ($deliveryBundle['base_fee'] ?? 0.0);
        $deliveryFeeDiscount = (float) ($deliveryBundle['discount_fee'] ?? 0.0);
        $deliveryFee = (float) ($deliveryBundle['fee'] ?? 0.0);
        $deliveryPromotion = $deliveryBundle['promotion'] ?? null;
        if (is_array($deliveryPromotion) && (int) ($deliveryPromotion['id'] ?? 0) > 0) {
            $deliveryPromotionPayload = [
                'id' => (int) ($deliveryPromotion['id'] ?? 0),
                'name_ar' => (string) ($deliveryPromotion['name_ar'] ?? ''),
                'name_en' => (string) ($deliveryPromotion['name_en'] ?? ''),
                'display_name' => orange_promo_customer_display_name($deliveryPromotion),
                'discount_type' => (string) ($deliveryPromotion['discount_type'] ?? 'amount'),
                'discount_value' => (float) ($deliveryPromotion['discount_value'] ?? 0.0),
                'discount_amount' => (float) ($deliveryPromotion['discount_amount'] ?? 0.0),
            ];
        }
    }
    $total = max(0.0, round($total + $thresholdGiftChargePreview + $bogoGiftChargePreview + $deliveryFee, 4));

    // نظام الولاء: عرض الرصيد القابل للاستخدام وتطبيق الاستبدال المطلوب (للحساب المسجَّل فقط).
    require_once __DIR__ . '/../../includes/loyalty.php';
    $loyalty = ['active' => false];
    if ($buyerReg && orange_loyalty_is_active($pdo, $storefrontCountryId)) {
        $accPhone = trim((string) ($acc['customer_phone'] ?? ''));
        $custId = 0;
        if ($accPhone !== '') {
            if (orange_table_has_country_id($pdo, 'customers') && $storefrontCountryId > 0) {
                $cs = $pdo->prepare('SELECT id FROM customers WHERE phone = ? AND country_id = ? LIMIT 1');
                $cs->execute([$accPhone, $storefrontCountryId]);
            } else {
                $cs = $pdo->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
                $cs->execute([$accPhone]);
            }
            $custId = (int) ($cs->fetchColumn() ?: 0);
        }
        if ($custId > 0) {
            $payableBefore = $total;
            $info = orange_loyalty_redeemable($pdo, $custId, $storefrontCountryId, $payableBefore);
            $sLoy = orange_loyalty_settings($pdo, $storefrontCountryId);
            $pv = $sLoy !== null ? (float) $sLoy['point_value'] : 0.0;
            $reqPts = (int) ($data['redeem_points'] ?? 0);
            $appliedPts = $reqPts > 0 ? min($reqPts, (int) $info['points']) : 0;
            $appliedVal = 0.0;
            if ($appliedPts > 0 && $pv > 0) {
                $appliedVal = round(min($appliedPts * $pv, $payableBefore), 4);
                $total = max(0.0, round($total - $appliedVal, 4));
            }
            $loyalty = [
                'active' => true,
                'balance' => (int) $info['balance'],
                'redeemable_points' => (int) $info['points'],
                'redeemable_value' => (float) $info['value'],
                'point_value' => $pv,
                'min_redeem_points' => $sLoy !== null ? (int) $sLoy['min_redeem_points'] : 0,
                'redeem_points' => $appliedPts,
                'redeem_value' => $appliedVal,
            ];
        }
    }

    json_response([
        'success' => true,
        'subtotal' => $subtotal,
        'combo_promotion_id' => $comboId,
        'combo_discount' => $comboDiscount,
        'combo_display_name' => $comboPick !== null ? (string) ($comboPick['display_name'] ?? '') : '',
        'promotion_id' => $promo !== null ? (int) $promo['id'] : null,
        'promotion_discount' => $promoDiscount,
        'promotion_display_name' => $promo !== null ? (string) ($promo['display_name'] ?? '') : '',
        'product_offer_discount' => $productOfferDiscount,
        'delivery_area_id' => $deliveryAreaId > 0 ? $deliveryAreaId : null,
        'delivery_fee_base' => $deliveryFeeBase,
        'delivery_fee_discount' => $deliveryFeeDiscount,
        'delivery_fee' => $deliveryFee,
        'delivery_promotion' => $deliveryPromotionPayload,
        'total' => $total,
        'register_promo_teaser' => $regTeaser,
        'gift_register_unlock_teaser' => $giftRegUnlock,
        'bogo_register_unlock_teaser' => $bogoRegUnlock,
        'combo_register_unlock_teaser' => $comboRegUnlock,
        'gift_promotion' => $giftPayload,
        'bogo_promotion' => $bogoPayload,
        'loyalty' => $loyalty,
    ]);
} catch (RuntimeException $e) {
    orange_storefront_api_json_runtime_error($e, 'checkout-preview', 'preview_failed');
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
