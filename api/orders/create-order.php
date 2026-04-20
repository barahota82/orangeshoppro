<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);

    require_fields($data, ['name', 'phone', 'area', 'address', 'email', 'channel_id', 'items']);

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
            $oid = (int) ($me['order_id'] ?? 0);
            if ($oid > 0) {
                $totSt = $pdo->prepare('SELECT total FROM orders WHERE id = ? LIMIT 1');
                $totSt->execute([$oid]);
                $totalVal = (float) $totSt->fetchColumn();
            }
            json_response([
                'success' => true,
                'order_id' => $oid,
                'order_number' => (string) $me['order_number'],
                'total' => $totalVal,
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
