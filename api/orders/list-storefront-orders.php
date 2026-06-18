<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_account.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $acc = current_storefront_account($pdo);
    if (!$acc) {
        json_response(['success' => false, 'code' => 'auth_required', 'message' => t('cart_account_auth_required')], 401);
    }

    $bucket = isset($_GET['bucket']) ? strtolower(trim((string) $_GET['bucket'])) : 'active';
    if ($bucket !== 'active' && $bucket !== 'delivered') {
        json_response(['success' => false, 'code' => 'invalid_bucket', 'message' => t('api_request_failed')], 422);
    }

    if (!orange_table_has_column($pdo, 'orders', 'storefront_account_id')) {
        json_response(['success' => true, 'orders' => []]);
    }

    $aid = (int) $acc['id'];
    if ($bucket === 'delivered') {
        $statusClause = "o.status = 'completed'";
    } else {
        $statusClause = "o.status NOT IN ('completed','cancelled','rejected')";
    }

    $promoSel = '';
    if (orange_table_has_column($pdo, 'orders', 'cart_promotion_discount')) {
        $promoSel .= ', o.cart_promotion_discount';
    }
    if (orange_table_has_column($pdo, 'orders', 'cart_combo_discount')) {
        $promoSel .= ', o.cart_combo_discount';
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee')) {
        $promoSel .= ', o.delivery_fee';
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee_base')) {
        $promoSel .= ', o.delivery_fee_base';
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_fee_discount')) {
        $promoSel .= ', o.delivery_fee_discount';
    }
    if (orange_table_has_column($pdo, 'orders', 'delivery_promotion_id')) {
        $promoSel .= ', o.delivery_promotion_id';
    }
    if (orange_table_has_column($pdo, 'order_items', 'line_discount')) {
        $linesSubQ = '(SELECT COALESCE(SUM(GREATEST(0, (oi.qty * CAST(oi.price AS DECIMAL(18,4))) - COALESCE(oi.line_discount, 0))), 0) FROM order_items oi WHERE oi.order_id = o.id)';
    } else {
        $linesSubQ = '(SELECT COALESCE(SUM(oi.qty * CAST(oi.price AS DECIMAL(18,4))), 0) FROM order_items oi WHERE oi.order_id = o.id)';
    }
    $sql = "SELECT o.id, o.order_number, o.status, o.total, o.phone, o.created_at, o.payment_terms{$promoSel},
            {$linesSubQ} AS lines_subtotal
            FROM orders o
            WHERE o.storefront_account_id = ? AND {$statusClause}
            ORDER BY o.created_at DESC
            LIMIT 80";
    $st = $pdo->prepare($sql);
    $st->execute([$aid]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    json_response(['success' => true, 'orders' => $rows]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
