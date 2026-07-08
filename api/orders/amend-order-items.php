<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/storefront_cart_items.php';
require_once __DIR__ . '/../../includes/cart_promotions.php';
require_once __DIR__ . '/../../includes/cart_combo_promotions.php';
require_once __DIR__ . '/../../includes/storefront_checkout_promo_lines.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/storefront_api_errors.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    $orderNumber = trim((string) ($data['order_number'] ?? ''));
    $phone = trim((string) ($data['phone'] ?? ''));
    $items = $data['items'] ?? null;

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'code' => 'invalid_input', 'message' => t('track_missing_fields')], 422);
    }

    $phoneNorm = orange_normalize_customer_phone($phone, null);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }

    if (!is_array($items) || count($items) === 0) {
        json_response(['success' => false, 'code' => 'cart_items_required', 'message' => t('checkout_cart_items_required')], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !orange_order_phones_match_for_lookup($phoneNorm, (string) ($order['phone'] ?? ''))) {
        json_response(['success' => false, 'code' => 'not_found', 'message' => t('track_order_not_found')], 404);
    }
    $existingDeliveryFee = 0.0;
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
        $existingDeliveryFee = is_numeric($order['delivery_fee'] ?? null) ? (float) $order['delivery_fee'] : 0.0;
        if (!is_finite($existingDeliveryFee) || $existingDeliveryFee < 0) {
            $existingDeliveryFee = 0.0;
        }
        $existingDeliveryFee = round($existingDeliveryFee, 4);
    }

    $accCaller = current_storefront_account($pdo);
    if (!orange_storefront_customer_may_amend_order_items($pdo, $order, $accCaller, $phoneNorm)) {
        json_response(['success' => false, 'code' => 'amend_not_allowed', 'message' => t('customer_amend_not_allowed')], 403);
    }

    $sfaClaim = $accCaller ? (int) ($accCaller['id'] ?? 0) : 0;
    $sfaLink = $sfaClaim > 0 ? orange_storefront_resolve_order_account_link($pdo, $sfaClaim, $phoneNorm) : null;
    $buyerRegistered = $sfaLink !== null && $sfaLink > 0;
    $buyerAccountId = ($sfaLink !== null && $sfaLink > 0) ? (int) $sfaLink : null;
    $buyerPhone = $phoneNorm !== '' ? $phoneNorm : null;

    $pdo->beginTransaction();

    try {
        orange_order_release_pending_stock_reservation($pdo, $order);

        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([(int) $order['id']]);

        [$subtotal, $validatedItems] = orange_storefront_validate_cart_items_core($pdo, $items, true);

        $amendCountryId = (int) ($order['country_id'] ?? 0);
        $amendCountryArg = $amendCountryId > 0 ? $amendCountryId : null;
        // سياسة «العرض بديل» (س4/2): استبعاد البنود ذات عرض المنتج من أساس الكومبو/خصم السلة.
        $offerPartition = orange_product_offer_partition_items($pdo, $validatedItems, $amendCountryArg);
        $nonOfferItems = $offerPartition['non_offer_items'];
        $nonOfferSubtotal = max(0.0, round($subtotal - (float) $offerPartition['offer_items_value'], 4));
        $comboPick = orange_cart_combo_best_match($pdo, $nonOfferItems, $buyerRegistered, $amendCountryArg, $buyerAccountId, $buyerPhone);
        $comboDiscount = $comboPick !== null ? (float) $comboPick['discount'] : 0.0;
        $comboId = $comboPick !== null ? (int) $comboPick['id'] : null;
        $cartPromoBase = max(0.0, round($nonOfferSubtotal - $comboDiscount, 4));
        $promoPick = orange_cart_promotion_resolve($pdo, $cartPromoBase, $buyerRegistered, $amendCountryArg, $buyerAccountId, $buyerPhone);
        $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
        $promoId = $promoPick !== null ? (int) $promoPick['id'] : null;
        $productOfferDiscount = (float) $offerPartition['offer_discount'];
        $maxOfferRoom = max(0.0, round($subtotal - $comboDiscount - $promoDiscount, 4));
        if ($productOfferDiscount > $maxOfferRoom) {
            $productOfferDiscount = $maxOfferRoom;
        }
        $orderTotal = max(0.0, round($subtotal - $comboDiscount - $promoDiscount - $productOfferDiscount, 4));

        $promoBundle = orange_storefront_build_promotional_gift_lines($pdo, $data, $validatedItems, $subtotal, $buyerRegistered, $amendCountryArg, $buyerAccountId, $buyerPhone);
        $giftLines = $promoBundle['giftLines'] ?? [];
        $giftLine = $promoBundle['giftLine'];
        $giftPromoId = $promoBundle['giftPromoId'];
        $giftVariantId = $promoBundle['giftVariantId'];
        $giftSelectionsJson = $promoBundle['giftSelectionsJson'] ?? null;
        $bogoLine = $promoBundle['bogoLine'];
        $bogoPromoId = $promoBundle['bogoPromoId'];
        $bogoGiftVariantId = $promoBundle['bogoGiftVariantId'];
        $giftDiscount = round((float) ($promoBundle['giftDiscount'] ?? 0), 4);
        $bogoDiscount = round((float) ($promoBundle['bogoDiscount'] ?? 0), 4);
        $linesForStock = $promoBundle['linesForStock'];

        $giftLinesCharge = 0.0;
        foreach ($giftLines as $gl) {
            $giftLinesCharge += round((float) ($gl['price'] ?? 0) * (int) ($gl['qty'] ?? 1), 4);
        }
        $giftLinesCharge = round(max(0.0, $giftLinesCharge - $giftDiscount), 4);
        if ($bogoLine !== null) {
            $giftLinesCharge += round((float) ($bogoLine['price'] ?? 0) * (int) ($bogoLine['qty'] ?? 1) - $bogoDiscount, 4);
        }
        $giftLinesCharge = round(max(0.0, $giftLinesCharge), 4);
        $orderTotal = max(0.0, round($orderTotal + $giftLinesCharge + $existingDeliveryFee, 4));

        $orderId = (int) $order['id'];
        $hasCartPromo = orange_table_has_column($pdo, 'orders', 'cart_promotion_id')
            && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
        $hasComboCols = orange_table_has_column($pdo, 'orders', 'cart_combo_promotion_id')
            && orange_table_has_column($pdo, 'orders', 'cart_combo_discount');
        $hasGiftCols = orange_table_has_column($pdo, 'orders', 'cart_gift_promotion_id')
            && orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id');
        $hasBogoCols = orange_table_has_column($pdo, 'orders', 'cart_bogo_promotion_id')
            && orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id');

        $setParts = ['total = ?'];
        $updParams = [$orderTotal];
        if ($hasComboCols) {
            $setParts[] = 'cart_combo_promotion_id = ?';
            $setParts[] = 'cart_combo_discount = ?';
            $updParams[] = $comboId !== null && $comboId > 0 ? $comboId : null;
            $updParams[] = $comboDiscount > 0 ? $comboDiscount : 0.0;
        }
        if ($hasCartPromo) {
            $setParts[] = 'cart_promotion_id = ?';
            $setParts[] = 'cart_promotion_discount = ?';
            $updParams[] = $promoId !== null && $promoId > 0 ? $promoId : null;
            $updParams[] = $promoDiscount > 0 ? $promoDiscount : 0.0;
        }
        if ($hasGiftCols) {
            $setParts[] = 'cart_gift_promotion_id = ?';
            $setParts[] = 'cart_gift_variant_id = ?';
            $updParams[] = $giftPromoId !== null && $giftPromoId > 0 ? $giftPromoId : null;
            $updParams[] = $giftVariantId !== null && $giftVariantId > 0 ? $giftVariantId : null;
        }
        if (orange_table_has_column($pdo, 'orders', 'cart_gift_discount')) {
            $setParts[] = 'cart_gift_discount = ?';
            $updParams[] = $giftDiscount > 0 ? $giftDiscount : 0.0;
        }
        if (orange_table_has_column($pdo, 'orders', 'cart_gift_selections_json')) {
            $setParts[] = 'cart_gift_selections_json = ?';
            $updParams[] = is_string($giftSelectionsJson) && $giftSelectionsJson !== '' ? $giftSelectionsJson : null;
        }
        if (orange_table_has_column($pdo, 'orders', 'cart_bogo_discount')) {
            $setParts[] = 'cart_bogo_discount = ?';
            $updParams[] = $bogoDiscount > 0 ? $bogoDiscount : 0.0;
        }
        if (orange_table_has_column($pdo, 'orders', 'product_offer_discount')) {
            $setParts[] = 'product_offer_discount = ?';
            $updParams[] = $productOfferDiscount > 0 ? $productOfferDiscount : 0.0;
        }
        if ($hasBogoCols) {
            $setParts[] = 'cart_bogo_promotion_id = ?';
            $setParts[] = 'cart_bogo_gift_variant_id = ?';
            $updParams[] = $bogoPromoId !== null && $bogoPromoId > 0 ? $bogoPromoId : null;
            $updParams[] = $bogoGiftVariantId !== null && $bogoGiftVariantId > 0 ? $bogoGiftVariantId : null;
        }
        if (orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
            $setParts[] = 'delivery_fee = ?';
            $updParams[] = $existingDeliveryFee;
        }
        $updParams[] = $orderId;
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $setParts) . ' WHERE id = ?')->execute($updParams);

        if (orange_invoice_ancillary_tables_ready($pdo)) {
            $savedExtra = orange_invoice_ancillary_extra_lines_for_doc(
                $pdo,
                orange_invoice_ancillary_doc_kind_sales(),
                $orderId
            );
            // الحفاظ على بند استبدال نقاط الولاء كما هو (لا يُعاد حسابه عند التعديل — النقاط مُستهلَكة بالفعل).
            $existingLoyaltyRedeem = 0.0;
            foreach ($savedExtra as $savedLine) {
                if (is_array($savedLine) && (string) ($savedLine['system_key'] ?? '') === 'loyalty_points_redemption') {
                    $existingLoyaltyRedeem = round($existingLoyaltyRedeem + (float) ($savedLine['amount'] ?? 0), 4);
                }
            }
            $mergedExtra = orange_invoice_ancillary_merge_auto_promo_lines(
                $pdo,
                $amendCountryId,
                [
                    'promo_combo_discount' => $comboDiscount,
                    'promo_cart_discount' => $promoDiscount,
                    'promo_gift_discount' => $giftDiscount,
                    'promo_bogo_discount' => $bogoDiscount,
                    'product_offer_discount' => $productOfferDiscount,
                    'loyalty_points_redemption' => $existingLoyaltyRedeem,
                ],
                $savedExtra
            );
            orange_invoice_ancillary_extra_lines_replace_for_doc(
                $pdo,
                orange_invoice_ancillary_doc_kind_sales(),
                $orderId,
                $amendCountryId,
                $mergedExtra
            );
        }

        orange_storefront_insert_order_items_for_order($pdo, $orderId, $linesForStock);

        require_once __DIR__ . '/../../includes/countries.php';
        $stockCountryId = isset($order['country_id']) ? (int) $order['country_id'] : 0;
        $stockWarehouseId = isset($order['warehouse_id']) ? (int) $order['warehouse_id'] : 0;
        if ($stockCountryId <= 0 && isset($order['channel_id'])) {
            $stockCountryId = orange_country_id_for_channel($pdo, (int) $order['channel_id']);
        }
        if ($stockCountryId <= 0) {
            $stockCountryId = orange_storefront_current_country_id($pdo);
        }
        if ($stockWarehouseId <= 0) {
            $stockWarehouseId = orange_warehouse_default_id_for_country($pdo, $stockCountryId);
        }
        orange_order_apply_pending_stock_reservation(
            $pdo,
            $orderNumber,
            $linesForStock,
            $stockCountryId > 0 ? $stockCountryId : null,
            $stockWarehouseId > 0 ? $stockWarehouseId : null
        );

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $channelStmt = $pdo->prepare('SELECT * FROM channels WHERE id = ? LIMIT 1');
    $channelStmt->execute([(int) ($order['channel_id'] ?? 0)]);
    $channel = $channelStmt->fetch(PDO::FETCH_ASSOC) ?: ['whatsapp_number' => ''];

    $paymentTerms = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    $payAr = orange_order_payment_terms_label_ar($paymentTerms);
    $payEn = $paymentTerms === 'online' ? 'Online' : ($paymentTerms === 'credit' ? 'Credit' : 'Cash');

    $messageLines = [];
    $messageLines[] = 'Order update / تعديل طلب';
    $messageLines[] = 'Order Number: ' . $orderNumber;
    $messageLines[] = '';
    $messageLines[] = 'Items:';
    foreach ($linesForStock as $idx => $row) {
        $tag = '';
        if (!empty($row['is_bogo_gift'])) {
            $tag = ' (BOGO GIFT)';
        } elseif (!empty($row['is_gift'])) {
            $tag = ' (FREE GIFT)';
        }
        $messageLines[] = ($idx + 1) . ') ' . $row['product']['name'] . $tag;
        if ($row['color'] !== '') {
            $messageLines[] = '   Color: ' . $row['color'];
        }
        if ($row['size'] !== '') {
            $messageLines[] = '   Size: ' . $row['size'];
        }
        $messageLines[] = '   Qty: ' . $row['qty'];
        $messageLines[] = '   Price: ' . number_format($row['price'], 2) . ' KD';
    }
    $messageLines[] = '';
    $messageLines[] = 'Payment: ' . $payEn . ' / ' . $payAr;
    $messageLines[] = 'Subtotal: ' . number_format($subtotal, 2) . ' KD';
    if ($comboDiscount > 0.00001) {
        $messageLines[] = 'Combo bundle: -' . number_format($comboDiscount, 2) . ' KD';
    }
    if ($promoDiscount > 0.00001) {
        $messageLines[] = 'Cart promotion: -' . number_format($promoDiscount, 2) . ' KD';
    }
    if ($existingDeliveryFee > 0.00001) {
        $messageLines[] = 'Delivery fee: +' . number_format($existingDeliveryFee, 2) . ' KD';
    }
    $messageLines[] = 'Total: ' . number_format($orderTotal, 2) . ' KD';

    $whatsAppNumber = clean_whatsapp_number((string) ($channel['whatsapp_number'] ?? ''));
    $whatsAppUrl = 'https://wa.me/' . $whatsAppNumber . '?text=' . rawurlencode(implode("\n", $messageLines));

    orange_storefront_set_guest_orders_phone($phoneNorm);

    json_response([
        'success' => true,
        'order_number' => $orderNumber,
        'total' => $orderTotal,
        'promotion_discount' => $promoDiscount,
        'combo_discount' => $comboDiscount,
        'delivery_fee' => $existingDeliveryFee,
        'lines_subtotal' => round($subtotal, 4),
        'whatsapp_url' => $whatsAppUrl,
        'whatsapp_number' => $whatsAppNumber,
    ]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    orange_storefront_api_json_runtime_error($e, 'amend-order-items', 'amend_failed');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, t('api_request_failed'));
}
