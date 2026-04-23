<?php

require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = db();
$hasOrderInvoiceCol = orange_table_has_column($pdo, 'orders', 'invoice_number');
$hasCartPromoDiscountCol = orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');

$sourceFilter = isset($_GET['source']) ? trim((string)$_GET['source']) : 'all';
if (!in_array($sourceFilter, ['all', 'website', 'company'], true)) {
    $sourceFilter = 'all';
}

$payFilter = isset($_GET['pay']) ? trim((string)$_GET['pay']) : 'all';
if (!in_array($payFilter, ['all', 'cash', 'credit', 'online'], true)) {
    $payFilter = 'all';
}

$sql = '
    SELECT o.*, c.name AS channel_name
    FROM orders o
    LEFT JOIN channels c ON c.id = o.channel_id
    WHERE 1=1
';
if ($sourceFilter === 'website') {
    $sql .= " AND (o.order_source IS NULL OR o.order_source = '' OR o.order_source = 'website')";
} elseif ($sourceFilter === 'company') {
    $sql .= " AND o.order_source = 'company'";
}
if ($payFilter === 'cash') {
    $sql .= " AND (o.payment_terms IS NULL OR TRIM(o.payment_terms) = '' OR o.payment_terms IN ('cash', 'online'))";
} elseif ($payFilter === 'credit') {
    $sql .= " AND o.payment_terms = 'credit'";
} elseif ($payFilter === 'online') {
    $sql .= " AND o.payment_terms = 'online'";
}

$sql .= ' ORDER BY o.id DESC';

try {
    $orders = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    if ($sourceFilter !== 'all' || $payFilter !== 'all') {
        $sql = '
            SELECT o.*, c.name AS channel_name
            FROM orders o
            LEFT JOIN channels c ON c.id = o.channel_id
            WHERE 1=1
            ORDER BY o.id DESC
        ';
        $orders = $pdo->query($sql)->fetchAll();
    } else {
        throw $e;
    }
}

$ordersIndex = (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '')
    ? $_SERVER['SCRIPT_NAME']
    : '/admin/index.php';

/**
 * @param array<string, mixed> $o
 */
function orange_admin_order_payment_label(array $o): string
{
    return orange_order_payment_terms_label_ar($o['payment_terms'] ?? 'cash');
}

$orangeOrderStatusAr = [
    'pending' => 'قيد الانتظار',
    'approved' => 'مقبول',
    'rejected' => 'مرفوض',
    'on_the_way' => 'بالطريق',
    'completed' => 'تم التوصيل',
    'cancelled' => 'ملغي',
];

/**
 * كل أزرار التحكم ظاهرة دائماً؛ السيرفر ما زال يحدّث الحالة حسب المنطق في update-status.php.
 *
 * @param array<string, mixed> $o
 */
function orange_admin_orders_action_buttons(array $o): void
{
    $id = (int) ($o['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $stNow = strtolower(trim((string) ($o['status'] ?? '')));
    $stJs = json_encode($stNow, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $invoicePath = '/admin/index.php?page=invoice&order_id=' . $id;
    $invoiceHref = htmlspecialchars(storefront_public_path($invoicePath), ENT_QUOTES, 'UTF-8');

    /* ترتيب التنفيذ: قبول → فاتورة → بالطريق → تم التوصيل → رفض — onclick بعلامات اقتباس مفردة حول السمة لتمرير json_encode بأمان */
    echo '<button type="button" onclick=\'updateOrderStatus(' . $id . ', "approved", ' . $stJs . ')\'>قبول</button>';
    echo '<a class="btn btn-secondary" href="' . $invoiceHref . '" target="_blank" rel="noopener">فاتورة</a>';
    echo '<button type="button" class="btn-secondary" onclick=\'updateOrderStatus(' . $id . ', "on_the_way", ' . $stJs . ')\'>بالطريق</button>';
    echo '<button type="button" class="btn-success" onclick=\'updateOrderStatus(' . $id . ', "completed", ' . $stJs . ')\'>تم التوصيل</button>';
    echo '<button type="button" class="btn-danger" onclick=\'updateOrderStatus(' . $id . ', "rejected", ' . $stJs . ')\'>رفض</button>';
}
?>
<div class="page-title page-title--stacked">
    <h1>الطلبات</h1>
    <p class="page-subtitle">المخزن <strong>موحّد للشركة</strong> — الطلب من أي قناة يخصم نفس المخزون لتفادي البيع رغم النفاد. عمود «قناة العملاء» لتتبّع المصدر وتجميع العملاء (تيك توك، واتساب، …) وليس لمخزون منفصل.
        <?php
        if (orange_admin_may($admin, $pdo, 'sales', 'view')) {
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=reserved_orders'), ENT_QUOTES, 'UTF-8') . '">طلبات محجوزة (مخزون)</a>';
        }
        if (orange_admin_may($admin, $pdo, 'sales', 'view') && orange_table_exists($pdo, 'order_intake_queue')) {
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=order_intake_queue'), ENT_QUOTES, 'UTF-8') . '">طابور طلبات الموقع (قبل إنشاء الطلب)</a>';
        }
        ?>
    </p>
</div>

<div class="card">
    <h3>قائمة الطلبات</h3>
    <div class="admin-toolbar" role="region" aria-label="تصفية الطلبات">
        <label class="admin-toolbar__field">
            <span>تصفية حسب المصدر</span>
            <select id="orders-source-filter" aria-label="تصفية حسب المصدر">
                <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                <option value="website" <?php echo $sourceFilter === 'website' ? 'selected' : ''; ?>>من الموقع</option>
                <option value="company" <?php echo $sourceFilter === 'company' ? 'selected' : ''; ?>>شركة (خارج الموقع)</option>
            </select>
        </label>
        <label class="admin-toolbar__field">
            <span>نوع البيع</span>
            <select id="orders-pay-filter" aria-label="تصفية نوع البيع">
                <option value="all" <?php echo $payFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                <option value="cash" <?php echo $payFilter === 'cash' ? 'selected' : ''; ?> title="يشمل نقدي وأونلاين — يستثني الآجل">نقدي</option>
                <option value="credit" <?php echo $payFilter === 'credit' ? 'selected' : ''; ?>>آجل</option>
                <option value="online" <?php echo $payFilter === 'online' ? 'selected' : ''; ?>>أونلاين</option>
            </select>
        </label>
    </div>
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
                    <th title="تتبّع مصدر الطلب فقط — المخزون للشركة واحد">قناة العملاء</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th class="col-orders-actions">التحكم</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php if ($hasOrderInvoiceCol): ?>
                    <td><?php
                        $inv = trim((string)($o['invoice_number'] ?? ''));
                        echo $inv !== '' ? htmlspecialchars($inv, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                    <?php endif; ?>
                    <td><?php
                        $src = (string)($o['order_source'] ?? 'website');
                        echo $src === 'company'
                            ? '<span class="badge" title="طلب خارج الموقع">شركة</span>'
                            : '<span class="badge" title="من المتجر">موقع</span>';
                    ?></td>
                    <td><?php
                        $pl = orange_admin_order_payment_label($o);
                        if ($pl === 'آجل') {
                            echo '<span class="badge" title="مبيعات آجل">آجل</span>';
                        } elseif ($pl === 'أونلاين') {
                            echo '<span class="badge" title="مبيعات أونلاين">أونلاين</span>';
                        } else {
                            echo '<span class="badge" title="مبيعات نقدي">نقدي</span>';
                        }
                    ?></td>
                    <td class="col-orders-customer"><?php echo htmlspecialchars((string)($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="col-orders-phone"><?php echo htmlspecialchars((string)($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($o['channel_name'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php
                        echo number_format((float)($o['total'] ?? 0), 2) . ' KD';
                        if ($hasCartPromoDiscountCol) {
                            $pd = (float)($o['cart_promotion_discount'] ?? 0);
                            if ($pd > 0.00001) {
                                echo '<br><span class="small" title="خصم عرض مجموع السلة">عرض: −' . htmlspecialchars(number_format($pd, 2), ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        }
                    ?></td>
                    <td><?php
                        $stBadge = strtolower(trim((string)($o['status'] ?? '')));
                        if ($stBadge === '') {
                            $stBadge = 'pending';
                        }
                        $stLabel = $orangeOrderStatusAr[$stBadge] ?? $stBadge;
                    ?><span class="badge <?php echo htmlspecialchars($stBadge, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($stLabel, ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="actions">
                        <?php orange_admin_orders_action_buttons($o); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var srcSel = document.getElementById('orders-source-filter');
    var paySel = document.getElementById('orders-pay-filter');
    if (!srcSel || !paySel) return;
    var base = <?php echo json_encode($ordersIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function go() {
        var src = srcSel.value;
        var pay = paySel.value;
        var q = 'page=orders';
        if (src !== 'all') q += '&source=' + encodeURIComponent(src);
        if (pay !== 'all') q += '&pay=' + encodeURIComponent(pay);
        window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + q;
    }
    srcSel.addEventListener('change', go);
    paySel.addEventListener('change', go);
})();
async function updateOrderStatus(orderId, status, currentStatus) {
    if (currentStatus === undefined || currentStatus === null) {
        currentStatus = '';
    }
    if (status === 'rejected') {
        if (currentStatus === 'completed') {
            if (!confirm('الطلب مكتمل: سيتم إرجاع المخزون وعكس قيود التسليم المرحّلة أو حذف المعلّق، وتعليم حجز الويب/التسليم كملغى عند الانطباق. المتابعة؟')) {
                return;
            }
        } else if (!confirm('تأكيد رفض هذا الطلب؟')) {
            return;
        }
    }
    if (status === 'completed' && !confirm('تأكيد تم التوصيل؟')) {
        return;
    }
    const res = await postJSON('/admin/api/orders/update-status.php', {
        order_id: orderId,
        status: status
    });
    alert(res.message || (res.success ? 'تم تحديث الحالة' : 'فشل تحديث الحالة'));
    if (res.success) location.reload();
}
</script>
