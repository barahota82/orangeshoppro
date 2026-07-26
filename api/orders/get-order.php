<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_time.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    /** س27: الاستعلام POST فقط — لا يُكشف الطلب/الهاتف عبر رابط GET قابل للمشاركة أو سجل الخادم للاستعلام. */
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        json_response(['success' => false, 'code' => 'post_required', 'message' => t('api_post_only')], 405);
    }

    $postPayload = get_json_input();
    orange_storefront_apply_lang_from_payload($postPayload);
    $orderNumber = trim((string) ($postPayload['order_number'] ?? ''));
    $phone = trim((string) ($postPayload['phone'] ?? ''));
    $lang = current_lang();

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

    $orderCountryId = (int) ($order['country_id'] ?? 0);
    $sfCountryId = orange_storefront_current_country_id($pdo);
    if ($orderCountryId > 0 && $sfCountryId > 0 && $orderCountryId !== $sfCountryId) {
        json_response(['success' => false, 'code' => 'order_lookup_not_found', 'message' => t('track_order_not_found')], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([(int) $order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $linesSubtotal = 0.0;
    foreach ($items as $it) {
        $linesSubtotal += orange_order_item_line_net($it);
    }

    // Customer-safe payload: business fields + Absolute time contract (no raw MySQL DATETIME).
    $public = [
        'id' => (int) ($order['id'] ?? 0),
        'order_number' => (string) ($order['order_number'] ?? ''),
        'status' => (string) ($order['status'] ?? ''),
        'total' => $order['total'] ?? 0,
        'phone' => (string) ($order['phone'] ?? ''),
        'payment_terms' => (string) ($order['payment_terms'] ?? 'cash'),
        'country_id' => $orderCountryId,
        'lines_subtotal' => round($linesSubtotal, 4),
    ];
    foreach (['cart_promotion_discount', 'cart_combo_discount', 'delivery_fee', 'delivery_fee_base', 'delivery_fee_discount', 'delivery_promotion_id', 'customer_name', 'area', 'address'] as $k) {
        if (array_key_exists($k, $order)) {
            $public[$k] = $order[$k];
        }
    }
    $timePayload = [
        'created_at' => $order['created_at'] ?? null,
        'completed_at' => $order['completed_at'] ?? null,
        'cancelled_at' => $order['cancelled_at'] ?? null,
        'country_id' => $orderCountryId,
    ];
    if (array_key_exists('updated_at', $order)) {
        $timePayload['updated_at'] = $order['updated_at'];
    }
    $public = orange_storefront_time_enrich_order_row($pdo, array_merge($public, $timePayload), $lang);

    $publicItems = [];
    foreach ($items as $it) {
        $publicItems[] = [
            'product_id' => (int) ($it['product_id'] ?? 0),
            'variant_id' => (int) ($it['variant_id'] ?? 0),
            'product_name' => (string) ($it['product_name'] ?? ''),
            'color' => (string) ($it['color'] ?? ''),
            'size' => (string) ($it['size'] ?? ''),
            'qty' => $it['qty'] ?? 0,
            'price' => $it['price'] ?? 0,
            'line_discount' => $it['line_discount'] ?? 0,
        ];
    }

    json_response([
        'success' => true,
        'order' => $public,
        'items' => $publicItems,
    ]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
