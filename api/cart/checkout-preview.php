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

    $acc = current_storefront_account($pdo);
    $buyerReg = $acc !== null;
    $comboPick = orange_cart_combo_best_match($pdo, $validatedItems, $buyerReg);
    $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
    $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
    $netAfterCombo = max(0.0, round($subtotal - $comboDiscount, 4));
    $promo = orange_cart_promotion_resolve($pdo, $netAfterCombo, $buyerReg);
    $promoDiscount = $promo !== null ? (float) $promo['discount'] : 0.0;
    $total = max(0.0, round($netAfterCombo - $promoDiscount, 4));
    $regTeaser = orange_cart_promotion_register_incentive_teaser($pdo, $netAfterCombo, $buyerReg);
    $giftRegUnlock = orange_cart_gift_register_unlock_teaser_applies($pdo, $subtotal, $buyerReg, $validatedItems);
    $bogoRegUnlock = orange_cart_bogo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg);
    $comboRegUnlock = orange_cart_combo_register_unlock_teaser_applies($pdo, $validatedItems, $buyerReg);

    $giftPayload = null;
    $giftRule = orange_cart_gift_promotion_select_rule($pdo, $subtotal, $buyerReg);
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
    $bogoRule = orange_cart_bogo_promotion_select_rule($pdo, $validatedItems, $buyerReg);
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

    json_response([
        'success' => true,
        'subtotal' => $subtotal,
        'combo_promotion_id' => $comboId,
        'combo_discount' => $comboDiscount,
        'promotion_id' => $promo !== null ? (int) $promo['id'] : null,
        'promotion_discount' => $promoDiscount,
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
