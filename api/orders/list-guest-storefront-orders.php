<?php

declare(strict_types=1);

/**
 * س13: طلبات الضيف في تاب «طلباتي» — حالات pending و approved فقط، مرتبطة بهاتف آخر طلب
 * في جلسة المتصفح (بعد إتمام checkout أو استعلام intake).
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/storefront_account.php';
require_once __DIR__ . '/../../includes/storefront_time.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    if (current_storefront_account($pdo) !== null) {
        json_response(['success' => true, 'orders' => []]);
    }

    $phone = orange_storefront_guest_orders_phone_from_session();
    if ($phone === null || $phone === '') {
        json_response(['success' => true, 'orders' => []]);
    }

    if (!orange_table_exists($pdo, 'orders')) {
        json_response(['success' => true, 'orders' => []]);
    }

    $sfCountryId = orange_storefront_current_country_id($pdo);
    $lang = current_lang();

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
    $countrySel = orange_table_has_column($pdo, 'orders', 'country_id') ? ', o.country_id' : '';
    if (orange_table_has_column($pdo, 'order_items', 'line_discount')) {
        $linesSubQ = '(SELECT COALESCE(SUM(GREATEST(0, (oi.qty * CAST(oi.price AS DECIMAL(18,4))) - COALESCE(oi.line_discount, 0))), 0) FROM order_items oi WHERE oi.order_id = o.id)';
    } else {
        $linesSubQ = '(SELECT COALESCE(SUM(oi.qty * CAST(oi.price AS DECIMAL(18,4))), 0) FROM order_items oi WHERE oi.order_id = o.id)';
    }

    $sql = "SELECT o.id, o.order_number, o.status, o.total, o.phone, o.created_at, o.payment_terms{$promoSel}{$countrySel},
            {$linesSubQ} AS lines_subtotal
            FROM orders o
            WHERE o.phone = ? AND o.status IN ('pending','approved')";
    $params = [$phone];
    if ($sfCountryId > 0 && orange_table_has_column($pdo, 'orders', 'country_id')) {
        $sql .= ' AND o.country_id = ?';
        $params[] = $sfCountryId;
    }
    $sql .= ' ORDER BY o.created_at DESC, o.id DESC LIMIT 80';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($sfCountryId > 0 && !isset($r['country_id'])) {
            $r['country_id'] = $sfCountryId;
        }
        $rows[] = orange_storefront_time_enrich_order_row($pdo, $r, $lang);
    }

    json_response(['success' => true, 'orders' => $rows]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
