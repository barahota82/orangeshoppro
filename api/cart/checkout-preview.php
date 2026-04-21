<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_cart_items.php';
require_once __DIR__ . '/../../includes/cart_promotions.php';
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

    [$subtotal, ] = orange_storefront_validate_cart_items_core($pdo, $items, false);

    $acc = current_storefront_account($pdo);
    $buyerReg = $acc !== null;
    $promo = orange_cart_promotion_resolve($pdo, $subtotal, $buyerReg);
    $promoDiscount = $promo !== null ? (float) $promo['discount'] : 0.0;
    $total = max(0.0, round($subtotal - $promoDiscount, 4));

    json_response([
        'success' => true,
        'subtotal' => $subtotal,
        'promotion_id' => $promo !== null ? (int) $promo['id'] : null,
        'promotion_discount' => $promoDiscount,
        'total' => $total,
    ]);
} catch (RuntimeException $e) {
    json_response(['success' => false, 'code' => 'preview_failed', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
