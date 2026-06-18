<?php

declare(strict_types=1);

/**
 * Poll storefront checkout queue by public_token (returned from create-order on timeout or always as intake_token).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';
require_once __DIR__ . '/../../includes/storefront_account.php';

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    if (strlen($token) !== 32 || !ctype_xdigit($token)) {
        json_response(['success' => false, 'code' => 'intake_invalid_token', 'message' => t('intake_invalid_token')], 400);
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'code' => 'intake_queue_unavailable', 'message' => t('intake_queue_unavailable')], 503);
    }

    $st = $pdo->prepare(
        'SELECT id, status, order_id, order_number, whatsapp_url, whatsapp_number, error_message
         FROM order_intake_queue WHERE public_token = ? LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'code' => 'intake_not_found', 'message' => t('intake_not_found')], 404);
    }

    if ($row['status'] === 'pending') {
        for ($i = 0; $i < 40; ++$i) {
            try {
                if (!orange_order_intake_process_next($pdo)) {
                    break;
                }
            } catch (Throwable $e) {
                break;
            }
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['status'] !== 'pending') {
                break;
            }
        }
    }

    if (!$row) {
        json_response(['success' => false, 'code' => 'intake_not_found', 'message' => t('intake_not_found')], 404);
    }

    $out = [
        'success' => true,
        'status' => (string) $row['status'],
        'intake_token' => $token,
    ];

    if ($row['status'] === 'completed') {
        $oid = (int) ($row['order_id'] ?? 0);
        $totalVal = 0.0;
        $promoDisc = 0.0;
        $comboDisc = 0.0;
        $deliveryFee = 0.0;
        if ($oid > 0) {
            $hasPromoC = orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
            $hasComboC = orange_table_has_column($pdo, 'orders', 'cart_combo_discount');
            $hasDeliveryFee = orange_table_has_column($pdo, 'orders', 'delivery_fee');
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
            $totSt = $pdo->prepare('SELECT ' . implode(', ', $selectCols) . ' FROM orders WHERE id = ? LIMIT 1');
            $totSt->execute([$oid]);
            $rowTot = $totSt->fetch(PDO::FETCH_ASSOC);
            if ($rowTot) {
                $totalVal = (float) ($rowTot['total'] ?? 0);
                $promoDisc = (float) ($rowTot['cart_promotion_discount'] ?? 0);
                $comboDisc = (float) ($rowTot['cart_combo_discount'] ?? 0);
                $deliveryFee = (float) ($rowTot['delivery_fee'] ?? 0);
            }
        }
        $out['order_id'] = $oid;
        $out['order_number'] = (string) $row['order_number'];
        $out['total'] = $totalVal;
        $out['promotion_discount'] = $promoDisc;
        $out['combo_discount'] = $comboDisc;
        $out['delivery_fee'] = $deliveryFee;
        $out['whatsapp_number'] = (string) $row['whatsapp_number'];
        $out['whatsapp_url'] = (string) $row['whatsapp_url'];
        if ($oid > 0) {
            $phSt = $pdo->prepare('SELECT phone FROM orders WHERE id = ? LIMIT 1');
            $phSt->execute([$oid]);
            $phRow = $phSt->fetchColumn();
            if ($phRow !== false && $phRow !== null && trim((string) $phRow) !== '') {
                orange_storefront_set_guest_orders_phone((string) $phRow);
            }
        }
    } elseif ($row['status'] === 'failed') {
        $out['success'] = false;
        $out['code'] = 'processing_failed';
        $failMsg = trim((string) ($row['error_message'] ?? ''));
        $out['message'] = $failMsg !== '' ? $failMsg : t('checkout_failed_generic');
    }

    json_response($out);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
