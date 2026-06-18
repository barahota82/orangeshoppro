<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_cart_items.php';
require_once __DIR__ . '/../../includes/cart_promotions.php';
require_once __DIR__ . '/../../includes/cart_gift_promotions.php';
require_once __DIR__ . '/../../includes/cart_bogo_promotions.php';
require_once __DIR__ . '/../../includes/cart_combo_promotions.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

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
    $comboPick = orange_cart_combo_best_match($pdo, $validatedItems, $buyerReg, $storefrontCountryId);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
    $netAfterCombo = max(0.0, round($subtotal - $comboDiscount, 4));
    $promo = orange_cart_promotion_resolve($pdo, $netAfterCombo, $buyerReg, $storefrontCountryId);
    $promoDiscount = $promo !== null ? (float) $promo['discount'] : 0.0;
    $total = max(0.0, round($netAfterCombo - $promoDiscount, 4));
    $regTeaser = orange_cart_promotion_register_incentive_teaser($pdo, $netAfterCombo, $buyerReg, $storefrontCountryId);
    $giftRegUnlock = orange_cart_gift_register_unlock_teaser_applies($pdo, $subtotal, $buyerReg, $validatedItems, $storefrontCountryId);
    $bogoRegUnlock = orange_cart_bogo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg, $storefrontCountryId);
    $comboRegUnlock = orange_cart_combo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg, $storefrontCountryId);

    $giftPayload = null;
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $buyerReg, $storefrontCountryId);
    if ($giftRule !== null) {
        if ($giftRule['gift_kind'] === 'fixed') {
            $fv = (int) ($giftRule['fixed_variant_id'] ?? 0);
            $opts = $fv > 0
                ? orange_cart_gift_promotion_pool_options($pdo, [$fv], $validatedItems, false)
                : [];
            if (count($opts) > 0) {
                $giftPayload = [
                    'id' => $giftRule['id'],
                    'gift_kind' => 'fixed',
                    'fixed_variant_id' => $fv,
                    'pool' => [],
                ];
            }
        } else {
            $pool = orange_cart_gift_promotion_pool_options(
                $pdo,
                $giftRule['pool_variant_ids'],
                $validatedItems,
                false
            );
            if (count($pool) > 0) {
                $giftPayload = [
                    'id' => $giftRule['id'],
                    'gift_kind' => 'choice',
                    'fixed_variant_id' => null,
                    'pool' => $pool,
                ];
            }
        }
    }

    $bogoPayload = null;
    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerReg, $storefrontCountryId);
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
        $thresholdGiftChargePreview = orange_cart_promo_preview_gift_max_unit_charge($pdo, $giftRule, $validatedItems);
        $giftPayload['gift_unit_charge_kind'] = (string) ($giftRule['gift_unit_charge_kind'] ?? 'free');
        $giftPayload['gift_unit_charge_value'] = (float) ($giftRule['gift_unit_charge_value'] ?? 0);
        $giftPayload['preview_max_gift_unit_charge'] = $thresholdGiftChargePreview;
    }

    $bogoGiftChargePreview = 0.0;
    if ($bogoRule !== null && $bogoPayload !== null) {
        $bogoGiftChargePreview = orange_cart_bogo_preview_gift_charge_upper_bound($pdo, $bogoRule, $bogoCtx);
        $bogoPayload['gift_unit_charge_kind'] = (string) ($bogoRule['gift_unit_charge_kind'] ?? 'free');
        $bogoPayload['gift_unit_charge_value'] = (float) ($bogoRule['gift_unit_charge_value'] ?? 0);
        $bogoPayload['preview_max_gift_unit_charge'] = $bogoGiftChargePreview;
    }
    $deliveryAreaId = (int) ($data['delivery_area_id'] ?? 0);
    $deliveryFeeBase = 0.0;
    $deliveryFeeDiscount = 0.0;
    $deliveryFee = 0.0;
    $deliveryPromotionPayload = null;
    if ($deliveryAreaId > 0) {
        $deliveryBundle = orange_delivery_resolve_checkout_fee_bundle(
            $pdo,
            $deliveryAreaId,
            $buyerReg,
            $storefrontCountryId
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
                'discount_type' => (string) ($deliveryPromotion['discount_type'] ?? 'amount'),
                'discount_value' => (float) ($deliveryPromotion['discount_value'] ?? 0.0),
                'discount_amount' => (float) ($deliveryPromotion['discount_amount'] ?? 0.0),
            ];
        }
    }
    $total = max(0.0, round($total + $thresholdGiftChargePreview + $bogoGiftChargePreview + $deliveryFee, 4));

    json_response([
        'success' => true,
        'subtotal' => $subtotal,
        'combo_promotion_id' => $comboId,
        'combo_discount' => $comboDiscount,
        'promotion_id' => $promo !== null ? (int) $promo['id'] : null,
        'promotion_discount' => $promoDiscount,
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
    ]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'code' => 'preview_failed', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
