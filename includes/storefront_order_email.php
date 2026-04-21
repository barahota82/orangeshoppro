<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';

/**
 * نص تفاصيل الطلب بلغة واحدة (نص عادي للبريد) — س13.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 */
function orange_storefront_order_details_plain_language(array $order, array $items, string $langCode): string
{
    $tr = get_translations();
    $T = $tr[$langCode] ?? $tr['en'];
    $cur = (string) ($T['currency_kd'] ?? 'KD');
    $lines = [];
    $linesSub = 0.0;
    foreach ($items as $it) {
        $linesSub += orange_order_item_line_net($it);
        $nm = trim((string) ($it['product_name'] ?? ''));
        $co = trim((string) ($it['color'] ?? ''));
        $sz = trim((string) ($it['size'] ?? ''));
        $qty = (int) ($it['qty'] ?? 0);
        $net = orange_order_item_line_net($it);
        $disc = orange_order_item_line_discount($it);
        $parts = array_filter([$nm, $co !== '' ? ($T['color'] ?? 'Color') . ': ' . $co : '', $sz !== '' ? ($T['size'] ?? 'Size') . ': ' . $sz : '']);
        $line = implode(' — ', $parts);
        $line .= ' | ' . ($T['quantity'] ?? 'Qty') . ': ' . $qty;
        if ($disc > 1e-9) {
            $line .= ' | ' . ($T['track_line_discount_label'] ?? '') . ' ' . number_format($disc, 3) . ' ' . $cur;
        }
        $line .= ' | ' . number_format($net, 3) . ' ' . $cur;
        $lines[] = '  • ' . $line;
    }
    $linesSub = round($linesSub, 4);

    $st = strtolower(trim((string) ($order['status'] ?? '')));
    $statusLbl = match ($st) {
        'pending' => (string) ($T['order_status_pending'] ?? $st),
        'approved' => (string) ($T['order_status_approved'] ?? $st),
        'on_the_way' => (string) ($T['order_status_on_the_way'] ?? $st),
        'completed' => (string) ($T['order_status_completed'] ?? $st),
        'rejected' => (string) ($T['order_status_rejected'] ?? $st),
        'cancelled' => (string) ($T['order_status_cancelled'] ?? $st),
        default => (string) ($order['status'] ?? '—'),
    };

    $pt = orange_normalize_payment_terms($order['payment_terms'] ?? 'cash');
    $ptLbl = $pt === 'credit'
        ? (string) ($T['payment_credit'] ?? 'credit')
        : ($pt === 'online' ? (string) ($T['payment_online'] ?? 'online') : (string) ($T['payment_cash'] ?? 'cash'));

    $out = [];
    $out[] = ($T['order_number'] ?? 'Order #') . ': ' . (string) ($order['order_number'] ?? '');
    $out[] = ($T['order_status_label'] ?? 'Status') . ': ' . $statusLbl;
    $out[] = ($T['customer_name'] ?? 'Name') . ': ' . trim((string) ($order['customer_name'] ?? ''));
    $out[] = ($T['phone'] ?? 'Phone') . ': ' . trim((string) ($order['phone'] ?? ''));
    $out[] = ($T['area'] ?? 'Area') . ': ' . trim((string) ($order['area'] ?? ''));
    $out[] = ($T['address'] ?? 'Address') . ': ' . trim((string) ($order['address'] ?? ''));
    $notes = trim((string) ($order['notes'] ?? ''));
    if ($notes !== '') {
        $out[] = ($T['notes'] ?? 'Notes') . ': ' . $notes;
    }
    $out[] = ($T['order_payment_terms_label'] ?? 'Payment') . ': ' . $ptLbl;
    $out[] = '';
    $out[] = ($T['track_order_items'] ?? 'Items') . ':';
    $out[] = $lines === [] ? '  —' : implode("\n", $lines);
    $out[] = '';
    $out[] = ($T['cart_subtotal_label'] ?? 'Subtotal') . ': ' . number_format($linesSub, 3) . ' ' . $cur;
    $promo = isset($order['cart_promotion_discount']) ? (float) $order['cart_promotion_discount'] : 0.0;
    if ($promo > 1e-9) {
        $out[] = ($T['cart_promotion_discount_label'] ?? 'Promo') . ': -' . number_format($promo, 3) . ' ' . $cur;
    }
    $tot = isset($order['total']) ? (float) $order['total'] : 0.0;
    $out[] = ($T['order_total_label'] ?? 'Total') . ': ' . number_format($tot, 3) . ' ' . $cur;

    return implode("\n", $out);
}

/**
 * ملحق بريد ثنائي اللغة (إنجليزي + عربي) بجميع تفاصيل الطلب.
 *
 * @param array<string, mixed> $order
 * @param list<array<string, mixed>> $items
 */
function orange_storefront_order_details_bilingual_email_appendix(array $order, array $items): string
{
    $en = orange_storefront_order_details_plain_language($order, $items, 'en');
    $ar = orange_storefront_order_details_plain_language($order, $items, 'ar');

    return "\n\n---\n\n"
        . "English — your order details\n\n"
        . $en
        . "\n\n---\n\n"
        . "العربية — تفاصيل طلبك\n\n"
        . $ar
        . "\n";
}
