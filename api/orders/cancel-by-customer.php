<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/loyalty.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    $orderNumber = trim((string)($data['order_number'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'code' => 'invalid_input', 'message' => t('track_missing_fields')], 422);
    }

    $phoneNorm = orange_normalize_customer_phone($phone, null);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !orange_order_phones_match_for_lookup($phoneNorm, (string) ($order['phone'] ?? ''))) {
        json_response(['success' => false, 'code' => 'not_found', 'message' => t('track_order_not_found')], 404);
    }

    $st = strtolower(trim((string)($order['status'] ?? '')));

    if ($st === 'cancelled') {
        json_response([
            'success' => true,
            'code' => 'already_cancelled',
            'message' => t('customer_cancel_ok'),
            'order' => $order,
        ]);
    }

    $accCaller = current_storefront_account($pdo);
    if (!orange_storefront_customer_may_cancel_order($pdo, $order, $accCaller, $phoneNorm)) {
        json_response(['success' => false, 'code' => 'cancel_not_allowed', 'message' => t('customer_cancel_not_allowed')], 403);
    }

    $pdo->beginTransaction();

    orange_order_release_pending_stock_reservation($pdo, $order);
    if (in_array($st, ['pending', 'approved', 'on_the_way'], true)) {
        orange_loyalty_restore_for_order($pdo, (int) $order['id']);
    }

    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")
        ->execute([(int)$order['id']]);

    $pdo->commit();

    $order['status'] = 'cancelled';

    json_response([
        'success' => true,
        'code' => 'cancelled',
        'message' => t('customer_cancel_ok'),
        'order' => $order,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    api_error($e, t('customer_cancel_err'));
}
