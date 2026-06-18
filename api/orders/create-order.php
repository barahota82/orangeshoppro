<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/phone_validation.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

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
    // Session-only policy: ignore any storefront_account_id from client payload.
    unset($data['storefront_account_id']);
    unset($data['_buyer_registered']);
    $accSf = current_storefront_account($pdo);
    $sessionAccountId = $accSf && !empty($accSf['id']) ? (int) $accSf['id'] : 0;
    if ($sessionAccountId > 0) {
        $data['storefront_account_id'] = $sessionAccountId;
        $data['_buyer_registered'] = 1;
    } else {
        $data['_buyer_registered'] = 0;
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

    $pcParsed = orange_storefront_parse_api_phone_country((string) ($data['phone_country'] ?? ''));
    if (!$pcParsed['full_intl'] && $pcParsed['dial'] === '') {
        json_response(['success' => false, 'code' => 'phone_country_required', 'message' => t('phone_country_required')], 422);
    }

    $phoneRawIn = trim((string) $data['phone']);
    $dialForNational = $pcParsed['full_intl'] ? null : $pcParsed['dial'];
    $phoneNorm = orange_normalize_customer_phone($phoneRawIn, $dialForNational, $pcParsed['full_intl']);
    if ($phoneNorm === null) {
        json_response(['success' => false, 'code' => 'invalid_phone', 'message' => t('checkout_invalid_phone')], 422);
    }
    $data['phone'] = $phoneNorm;
    $data['phone_country'] = $pcParsed['full_intl'] ? '' : $pcParsed['dial'];
    $phoneParts = orange_storefront_phone_storage_parts($phoneRawIn, $dialForNational);
    $data['phone_country_dial'] = $phoneParts['country_dial'];
    $data['phone_national'] = $phoneParts['national'];

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
            $comboDisc = 0.0;
            $deliveryFee = 0.0;
            $deliveryFeeBase = 0.0;
            $deliveryFeeDiscount = 0.0;
            $deliveryPromotionId = null;
            $oid = (int) ($me['order_id'] ?? 0);
            if ($oid > 0) {
                $hasPromoC = orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
                $hasComboC = orange_table_has_column($pdo, 'orders', 'cart_combo_discount');
                $hasDeliveryFee = orange_table_has_column($pdo, 'orders', 'delivery_fee');
                $hasDeliveryFeeBase = orange_table_has_column($pdo, 'orders', 'delivery_fee_base');
                $hasDeliveryFeeDiscount = orange_table_has_column($pdo, 'orders', 'delivery_fee_discount');
                $hasDeliveryPromotionId = orange_table_has_column($pdo, 'orders', 'delivery_promotion_id');
                $selectCols = ['total'];
                if ($hasPromoC) {
                    $selectCols[] = 'cart_promotion_discount';
                }
                if ($hasComboC) {
                    $selectCols[] = 'cart_combo_discount';
                }
                if ($hasDeliveryFee) {
                    $selectCols[] = 'delivery_fee';
                }
                if ($hasDeliveryFeeBase) {
                    $selectCols[] = 'delivery_fee_base';
                }
                if ($hasDeliveryFeeDiscount) {
                    $selectCols[] = 'delivery_fee_discount';
                }
                if ($hasDeliveryPromotionId) {
                    $selectCols[] = 'delivery_promotion_id';
                }
                $totSt = $pdo->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM orders WHERE id = ? LIMIT 1');
                $totSt->execute([$oid]);
                $rowTot = $totSt->fetch(PDO::FETCH_ASSOC);
                if ($rowTot) {
                    $totalVal = (float) ($rowTot['total'] ?? 0);
                    $promoDisc = (float) ($rowTot['cart_promotion_discount'] ?? 0);
                    $comboDisc = (float) ($rowTot['cart_combo_discount'] ?? 0);
                    $deliveryFee = (float) ($rowTot['delivery_fee'] ?? 0);
                    $deliveryFeeBase = (float) ($rowTot['delivery_fee_base'] ?? 0);
                    $deliveryFeeDiscount = (float) ($rowTot['delivery_fee_discount'] ?? 0);
                    $promoId = isset($rowTot['delivery_promotion_id']) ? (int) $rowTot['delivery_promotion_id'] : 0;
                    $deliveryPromotionId = $promoId > 0 ? $promoId : null;
                }
            }
            orange_storefront_set_guest_orders_phone((string) $data['phone']);

            json_response([
                'success' => true,
                'order_id' => $oid,
                'order_number' => (string) $me['order_number'],
                'total' => $totalVal,
                'promotion_discount' => $promoDisc,
                'combo_discount' => $comboDisc,
                'delivery_fee' => $deliveryFee,
                'delivery_fee_base' => $deliveryFeeBase,
                'delivery_fee_discount' => $deliveryFeeDiscount,
                'delivery_promotion_id' => $deliveryPromotionId,
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
        'intake_status_url' => storefront_public_path('/api/orders/intake-status.php?token=' . rawurlencode($publicToken)),
    ], 503);
} catch (Throwable $e) {
    api_error($e, t('checkout_failed_generic'));
}
