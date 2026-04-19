<?php

declare(strict_types=1);

/**
 * Poll storefront checkout queue by public_token (returned from create-order on timeout or always as intake_token).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_intake_queue.php';

try {
    $token = trim((string) ($_GET['token'] ?? ''));
    if (strlen($token) !== 32 || !ctype_xdigit($token)) {
        json_response(['success' => false, 'message' => 'Invalid token'], 400);
    }

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (!orange_table_exists($pdo, 'order_intake_queue')) {
        json_response(['success' => false, 'message' => 'Queue not available'], 503);
    }

    $st = $pdo->prepare(
        'SELECT id, status, order_id, order_number, whatsapp_url, whatsapp_number, error_message
         FROM order_intake_queue WHERE public_token = ? LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        json_response(['success' => false, 'message' => 'Not found'], 404);
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
        json_response(['success' => false, 'message' => 'Not found'], 404);
    }

    $out = [
        'success' => true,
        'status' => (string) $row['status'],
        'intake_token' => $token,
    ];

    if ($row['status'] === 'completed') {
        $oid = (int) ($row['order_id'] ?? 0);
        $totalVal = 0.0;
        if ($oid > 0) {
            $totSt = $pdo->prepare('SELECT total FROM orders WHERE id = ? LIMIT 1');
            $totSt->execute([$oid]);
            $totalVal = (float) $totSt->fetchColumn();
        }
        $out['order_id'] = $oid;
        $out['order_number'] = (string) $row['order_number'];
        $out['total'] = $totalVal;
        $out['whatsapp_number'] = (string) $row['whatsapp_number'];
        $out['whatsapp_url'] = (string) $row['whatsapp_url'];
    } elseif ($row['status'] === 'failed') {
        $out['success'] = false;
        $out['message'] = (string) ($row['error_message'] ?? 'Checkout failed');
    }

    json_response($out);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
