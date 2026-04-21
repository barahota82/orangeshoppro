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
require_once __DIR__ . '/../../includes/order_intake_queue.php';

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

    $accCaller = current_storefront_account($pdo);
    if (!orange_storefront_customer_may_amend_order_items($pdo, $order, $accCaller, $phoneNorm)) {
        json_response(['success' => false, 'code' => 'amend_not_allowed', 'message' => t('customer_amend_not_allowed')], 403);
    }

    $sfaClaim = $accCaller ? (int) ($accCaller['id'] ?? 0) : 0;
    $sfaLink = $sfaClaim > 0 ? orange_storefront_resolve_order_account_link($pdo, $sfaClaim, $phoneNorm) : null;
    $buyerRegistered = $sfaLink !== null && $sfaLink > 0;

    $pdo->beginTransaction();

    try {
        orange_order_release_pending_stock_reservation($pdo, $order);

        $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([(int) $order['id']]);

        [$subtotal, $validatedItems] = orange_storefront_validate_cart_items_core($pdo, $items, true);

        $promoPick = orange_cart_promotion_resolve($pdo, $subtotal, $buyerRegistered);
        $promoDiscount = $promoPick !== null ? (float) $promoPick['discount'] : 0.0;
        $promoId = $promoPick !== null ? (int) $promoPick['id'] : null;
        $orderTotal = max(0.0, round($subtotal - $promoDiscount, 4));

        $orderId = (int) $order['id'];
        $hasCartPromo = orange_table_has_column($pdo, 'orders', 'cart_promotion_id')
            && orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');

        if ($hasCartPromo) {
            $pdo->prepare(
                'UPDATE orders SET total = ?, cart_promotion_id = ?, cart_promotion_discount = ? WHERE id = ?'
            )->execute([
                $orderTotal,
                $promoId !== null && $promoId > 0 ? $promoId : null,
                $promoDiscount > 0 ? $promoDiscount : 0.0,
                $orderId,
            ]);
        } else {
            $pdo->prepare('UPDATE orders SET total = ? WHERE id = ?')->execute([$orderTotal, $orderId]);
        }

        orange_storefront_insert_order_items_for_order($pdo, $orderId, $validatedItems);

        orange_order_apply_pending_stock_reservation($pdo, $orderNumber, $validatedItems);

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
    foreach ($validatedItems as $idx => $row) {
        $messageLines[] = ($idx + 1) . ') ' . $row['product']['name'];
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
    if ($promoDiscount > 0.00001) {
        $messageLines[] = 'Cart promotion: -' . number_format($promoDiscount, 2) . ' KD';
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
        'lines_subtotal' => round($subtotal, 4),
        'whatsapp_url' => $whatsAppUrl,
        'whatsapp_number' => $whatsAppNumber,
    ]);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'code' => 'amend_failed', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, t('api_request_failed'));
}
