<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/phone_validation.php';

try {
    $pdo = db();

    /** س27: تفضيل POST JSON حتى لا يظهر رقم الطلب والهاتف في سلسلة الاستعلام أو سجل الخادم للرابط. */
    $postPayload = [];
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
        $postPayload = get_json_input();
        orange_storefront_apply_lang_from_payload($postPayload);
    }
    $orderNumber = trim((string) ($postPayload['order_number'] ?? $_GET['order_number'] ?? ''));
    $phone = trim((string) ($postPayload['phone'] ?? $_GET['phone'] ?? ''));

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'code' => 'order_lookup_required', 'message' => t('track_missing_fields')], 422);
    }

    $phoneNorm = orange_normalize_customer_phone($phone, null);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !orange_order_phones_match_for_lookup($phoneNorm, (string) ($order['phone'] ?? ''))) {
        json_response(['success' => false, 'code' => 'order_lookup_not_found', 'message' => t('track_order_not_found')], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([(int) $order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'success' => true,
        'order' => $order,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
