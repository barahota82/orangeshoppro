<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$reservedRows = orange_admin_orders_with_pending_stock_reservations($pdo);
$hasOrderInvoiceCol = orange_table_has_column($pdo, 'orders', 'invoice_number');

$orangeOrderStatusAr = [
    'pending' => 'قيد الانتظار',
    'approved' => 'مقبول',
    'rejected' => 'مرفوض',
    'on_the_way' => 'بالطريق',
    'completed' => 'تم التوصيل',
    'cancelled' => 'ملغي',
];

/**
 * @param array<string, mixed> $o
 */
function orange_reserved_orders_payment_badge(array $o): string
{
    $pl = orange_order_payment_terms_label_ar($o['payment_terms'] ?? 'cash');
    if ($pl === 'آجل') {
        return '<span class="badge" title="مبيعات آجل">آجل</span>';
    }
    if ($pl === 'أونلاين') {
        return '<span class="badge" title="مبيعات أونلاين">أونلاين</span>';
    }

    return '<span class="badge" title="مبيعات نقدي">نقدي</span>';
}

?>
<div class="page-title page-title--stacked">
    <h1>طلبات محجوزة (مخزون)</h1>
    <p class="page-subtitle">
        طلبات ما زال لها <strong>حجز مخزون نشط</strong> (حركات <code>pending_order</code>) حتى التسليم أو رفض/إلغاء يُطلق المخزون.
        لتحرير الحجز يدوياً: حدّث حالة الطلب من
        <a href="/admin/index.php?page=orders">شاشة الطلبات</a>
        (رفض/إلغاء) أو نفّذ التسليم حسب السياسة.
    </p>
</div>

<div class="card">
    <h3>قائمة الطلبات ذات الحجز الفعّال</h3>
    <?php if ($reservedRows === []): ?>
        <p class="page-subtitle" style="margin-top:12px;">لا توجد طلبات محجوزة حالياً.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="admin-orders-list-table">
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <?php if ($hasOrderInvoiceCol): ?><th>رقم الفاتورة</th><?php endif; ?>
                    <th>المصدر</th>
                    <th>البيع</th>
                    <th class="col-orders-customer">العميل</th>
                    <th class="col-orders-phone">الهاتف</th>
                    <th>قناة العملاء</th>
                    <th title="مجموع قطع الحجز (مجموع qty لحركات pending_order)">الحجز (قطع)</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th class="col-orders-actions">إجراءات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservedRows as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php if ($hasOrderInvoiceCol): ?>
                    <td><?php
                        $inv = trim((string) ($o['invoice_number'] ?? ''));
                        echo $inv !== '' ? htmlspecialchars($inv, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                    <?php endif; ?>
                    <td><?php
                        $src = (string) ($o['order_source'] ?? 'website');
                        echo $src === 'company'
                            ? '<span class="badge" title="طلب خارج الموقع">شركة</span>'
                            : '<span class="badge" title="من المتجر">موقع</span>';
                    ?></td>
                    <td><?php echo orange_reserved_orders_payment_badge($o); ?></td>
                    <td class="col-orders-customer"><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="col-orders-phone"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['channel_name'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo (int) ($o['reserved_qty'] ?? 0); ?></td>
                    <td><?php echo number_format((float) ($o['total'] ?? 0), 2); ?> KD</td>
                    <td><?php
                        $stBadge = strtolower(trim((string) ($o['status'] ?? '')));
                        if ($stBadge === '') {
                            $stBadge = 'pending';
                        }
                        $stLabel = $orangeOrderStatusAr[$stBadge] ?? $stBadge;
                        ?><span class="badge <?php echo htmlspecialchars($stBadge, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="actions">
                        <?php
                        $oid = (int) ($o['id'] ?? 0);
                        if ($oid > 0) {
                            $invHref = '/admin/index.php?page=invoice&order_id=' . $oid;
                            echo '<a class="btn btn-secondary" href="' . htmlspecialchars($invHref, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">فاتورة</a> ';
                        }
                        ?>
                        <a class="btn btn-secondary" href="/admin/index.php?page=orders">كل الطلبات</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
