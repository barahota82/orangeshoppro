<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    if (!\array_key_exists('email', $data)) {
        $data['email'] = '';
    }
    $data['email'] = trim((string) $data['email']);

    $langCo = isset($data['lang']) ? (string) $data['lang'] : (function_exists('current_lang') ? current_lang() : 'en');
    if (!preg_match('/^(ar|en|fil|hi)$/', $langCo)) {
        $langCo = 'en';
    }
    try {
        orange_storefront_normalize_delivery_area_payload($pdo, $data, $langCo);
    } catch (RuntimeException $e) {
        json_response([
            'success' => false,
            'code' => 'invalid_delivery_area',
            'message' => $e->getMessage(),
        ], 422);
    }

    require_fields($data, ['name', 'phone', 'area', 'address', 'channel_id', 'items']);

    $phoneRawIn = trim((string) $data['phone']);
    $phoneCc = trim((string) ($data['phone_country'] ?? ''));
    $phoneCc = $phoneCc === '' ? null : $phoneCc;
    $phoneParts = orange_storefront_phone_storage_parts($phoneRawIn, $phoneCc);
    $phoneNorm = orange_normalize_customer_phone($phoneRawIn, $phoneCc);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }
    $data['phone'] = $phoneNorm;
    $data['phone_country_dial'] = $phoneParts['country_dial'];
    $data['phone_national'] = $phoneParts['national'];

    $accSf = current_storefront_account($pdo);
    if ($accSf && !empty($accSf['customer_phone'])) {
        if (orange_order_phones_match_for_lookup($data['phone'], (string) $accSf['customer_phone'])) {
            $data['storefront_account_id'] = (int) $accSf['id'];
        }
    }

    if (!is_array($data['items']) || count($data['items']) === 0) {
        json_response(['success' => false, 'code' => 'cart_items_required', 'message' => t('checkout_cart_items_required')], 422);
    }

    $channelStmt = $pdo->prepare('SELECT * FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $channelStmt->execute([(int) $data['channel_id']]);
    $channel = $channelStmt->fetch(PDO::FETCH_ASSOC);

    if (!$channel) {
        json_response(['success' => false, 'code' => 'invalid_channel', 'message' => t('checkout_invalid_channel')], 422);
    }

    $enq = orange_order_intake_enqueue($pdo, $data);
    $queueId = $enq['id'];
    $publicToken = $enq['public_token'];

    $maxIters = 500;
    for ($i = 0; $i < $maxIters; ++$i) {
        $st = $pdo->prepare(
            'SELECT status, order_id, order_number, whatsapp_url, whatsapp_number, error_message
             FROM order_intake_queue WHERE id = ? LIMIT 1'
        );
        $st->execute([$queueId]);
        $me = $st->fetch(PDO::FETCH_ASSOC);
        if (!$me) {
            json_response(['success' => false, 'code' => 'queue_row_missing', 'message' => t('checkout_internal_error')], 500);
        }
        if ($me['status'] === 'completed') {
            $totalVal = 0.0;
            $promoDisc = 0.0;
            $oid = (int) ($me['order_id'] ?? 0);
            if ($oid > 0) {
                if (orange_table_has_column($pdo, 'orders', 'cart_promotion_discount')) {
                    $totSt = $pdo->prepare('SELECT total, cart_promotion_discount FROM orders WHERE id = ? LIMIT 1');
                } else {
                    $totSt = $pdo->prepare('SELECT total FROM orders WHERE id = ? LIMIT 1');
                }
                $totSt->execute([$oid]);
                $rowTot = $totSt->fetch(PDO::FETCH_ASSOC);
                if ($rowTot) {
                    $totalVal = (float) ($rowTot['total'] ?? 0);
                    $promoDisc = (float) ($rowTot['cart_promotion_discount'] ?? 0);
                }
            }
            orange_storefront_set_guest_orders_phone((string) $data['phone']);

            json_response([
                'success' => true,
                'order_id' => $oid,
                'order_number' => (string) $me['order_number'],
                'total' => $totalVal,
                'promotion_discount' => $promoDisc,
                'whatsapp_number' => (string) $me['whatsapp_number'],
                'whatsapp_url' => (string) $me['whatsapp_url'],
                'intake_token' => $publicToken,
            ]);
        }
        if ($me['status'] === 'failed') {
            $failMsg = trim((string) ($me['error_message'] ?? ''));
            json_response([
                'success' => false,
                'code' => 'processing_failed',
                'message' => $failMsg !== '' ? $failMsg : t('checkout_failed_generic'),
                'intake_token' => $publicToken,
            ], 500);
        }

        try {
            $did = orange_order_intake_process_next($pdo);
        } catch (Throwable $e) {
            usleep(50000);
            continue;
        }
        if (!$did) {
            usleep(10000);
        }
    }

    json_response([
        'success' => false,
        'code' => 'checkout_busy',
        'message' => t('checkout_queue_busy'),
        'intake_token' => $publicToken,
        'intake_status_url' => '/api/orders/intake-status.php?token=' . rawurlencode($publicToken),
    ], 503);
} catch (Throwable $e) {
    api_error($e, t('checkout_failed_generic'));
}
