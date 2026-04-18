<?php
$pdo = db();

$sourceFilter = isset($_GET['source']) ? trim((string)$_GET['source']) : 'all';
if (!in_array($sourceFilter, ['all', 'website', 'company'], true)) {
    $sourceFilter = 'all';
}

$payFilter = isset($_GET['pay']) ? trim((string)$_GET['pay']) : 'all';
if (!in_array($payFilter, ['all', 'cash', 'credit'], true)) {
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
    $sql .= " AND (o.payment_terms IS NULL OR o.payment_terms = '' OR o.payment_terms = 'cash')";
} elseif ($payFilter === 'credit') {
    $sql .= " AND o.payment_terms = 'credit'";
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
    $pt = strtolower(trim((string)($o['payment_terms'] ?? 'cash')));
    if ($pt === 'credit') {
        return 'آجل';
    }

    return 'نقدي';
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
    $invoicePath = '/admin/index.php?page=invoice&order_id=' . $id;
    $invoiceHref = htmlspecialchars($invoicePath, ENT_QUOTES, 'UTF-8');

    /* ترتيب التنفيذ: قبول → فاتورة → بالطريق → تم التوصيل → رفض */
    echo '<button type="button" onclick="updateOrderStatus(' . $id . ',\'approved\')">قبول</button>';
    echo '<a class="btn btn-secondary" href="' . $invoiceHref . '" target="_blank" rel="noopener">فاتورة</a>';
    echo '<button type="button" class="btn-secondary" onclick="updateOrderStatus(' . $id . ',\'on_the_way\')">بالطريق</button>';
    echo '<button type="button" class="btn-success" onclick="updateOrderStatus(' . $id . ',\'completed\')">تم التوصيل</button>';
    echo '<button type="button" class="btn-danger" onclick="updateOrderStatus(' . $id . ',\'rejected\')">رفض</button>';
}
?>
<div class="page-title">
    <h1>الطلبات</h1>
</div>

<div class="card">
    <h3>قائمة الطلبات</h3>
    <div style="margin-bottom:14px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
        <label style="display:flex;align-items:center;gap:8px;margin:0;">
            <span>تصفية حسب المصدر</span>
            <select id="orders-source-filter" aria-label="تصفية حسب المصدر">
                <option value="all" <?php echo $sourceFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                <option value="website" <?php echo $sourceFilter === 'website' ? 'selected' : ''; ?>>من الموقع</option>
                <option value="company" <?php echo $sourceFilter === 'company' ? 'selected' : ''; ?>>شركة (خارج الموقع)</option>
            </select>
        </label>
        <label style="display:flex;align-items:center;gap:8px;margin:0;">
            <span>نوع البيع</span>
            <select id="orders-pay-filter" aria-label="تصفية نقدي أو آجل">
                <option value="all" <?php echo $payFilter === 'all' ? 'selected' : ''; ?>>الكل</option>
                <option value="cash" <?php echo $payFilter === 'cash' ? 'selected' : ''; ?>>نقدي</option>
                <option value="credit" <?php echo $payFilter === 'credit' ? 'selected' : ''; ?>>آجل</option>
            </select>
        </label>
    </div>
    <div class="table-wrap">
        <table class="admin-orders-list-table">
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>المصدر</th>
                    <th>البيع</th>
                    <th class="col-orders-customer">العميل</th>
                    <th class="col-orders-phone">الهاتف</th>
                    <th>القناة</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th class="col-orders-actions">التحكم</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php
                        $src = (string)($o['order_source'] ?? 'website');
                        echo $src === 'company'
                            ? '<span class="badge" title="طلب خارج الموقع">شركة</span>'
                            : '<span class="badge" title="من المتجر">موقع</span>';
                    ?></td>
                    <td><?php
                        $pl = orange_admin_order_payment_label($o);
                        echo $pl === 'آجل'
                            ? '<span class="badge" title="مبيعات آجل">آجل</span>'
                            : '<span class="badge" title="مبيعات نقدي">نقدي</span>';
                    ?></td>
                    <td class="col-orders-customer"><?php echo htmlspecialchars((string)($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="col-orders-phone"><?php echo htmlspecialchars((string)($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($o['channel_name'] ?: '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((float)($o['total'] ?? 0), 2); ?> KD</td>
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
async function updateOrderStatus(orderId, status) {
    if (status === 'rejected' && !confirm('تأكيد رفض هذا الطلب؟')) {
        return;
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
