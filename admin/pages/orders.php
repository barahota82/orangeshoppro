<?php
$pdo = db();

$sourceFilter = isset($_GET['source']) ? trim((string)$_GET['source']) : 'all';
if (!in_array($sourceFilter, ['all', 'website', 'company'], true)) {
    $sourceFilter = 'all';
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

$sql .= ' ORDER BY o.id DESC';

try {
    $orders = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    if ($sourceFilter !== 'all') {
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

$orangeOrderStatusAr = [
    'pending' => 'قيد الانتظار',
    'approved' => 'مقبول',
    'rejected' => 'مرفوض',
    'on_the_way' => 'بالطريق',
    'completed' => 'تم التوصيل',
    'cancelled' => 'ملغي',
];

/**
 * أزرار التحكم: زر «رفض» يظهر من قيد الانتظار حتى بالطريق، ويُخفى بعد «تم التوصيل» أو «ملغي». مرفوض → قبول فقط.
 *
 * @param array<string, mixed> $o
 */
function orange_admin_orders_action_buttons(array $o): void
{
    $id = (int) ($o['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $st = strtolower(trim((string) ($o['status'] ?? '')));
    if ($st === '') {
        $st = 'pending';
    }
    $invoicePath = '/admin/index.php?page=invoice&order_id=' . $id;
    $invoiceHref = htmlspecialchars($invoicePath, ENT_QUOTES, 'UTF-8');

    if ($st === 'pending') {
        echo '<button type="button" class="btn-success" onclick="updateOrderStatus(' . $id . ',\'approved\')">قبول</button>';
        echo '<button type="button" class="btn-danger" onclick="updateOrderStatus(' . $id . ',\'rejected\')">رفض</button>';

        return;
    }
    if ($st === 'rejected') {
        echo '<button type="button" class="btn-success" onclick="updateOrderStatus(' . $id . ',\'approved\')">قبول</button>';

        return;
    }
    if ($st === 'approved') {
        echo '<a class="btn btn-secondary" href="' . $invoiceHref . '" target="_blank" rel="noopener">فاتورة</a>';
        echo '<button type="button" class="btn-danger" onclick="updateOrderStatus(' . $id . ',\'rejected\')">رفض</button>';
        echo '<button type="button" class="btn-secondary" onclick="updateOrderStatus(' . $id . ',\'on_the_way\')">بالطريق</button>';

        return;
    }
    if ($st === 'on_the_way') {
        echo '<a class="btn btn-secondary" href="' . $invoiceHref . '" target="_blank" rel="noopener">فاتورة</a>';
        echo '<button type="button" class="btn-danger" onclick="updateOrderStatus(' . $id . ',\'rejected\')">رفض</button>';
        echo '<button type="button" class="btn-success" onclick="updateOrderStatus(' . $id . ',\'completed\')">تم التوصيل</button>';

        return;
    }
    if ($st === 'completed' || $st === 'cancelled') {
        echo '<a class="btn btn-secondary" href="' . $invoiceHref . '" target="_blank" rel="noopener">فاتورة</a>';

        return;
    }
    echo '<button type="button" class="btn-success" onclick="updateOrderStatus(' . $id . ',\'approved\')">قبول</button>';
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
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>المصدر</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>القناة</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th>التحكم</th>
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
                    <td><?php echo htmlspecialchars((string)($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
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
    var sel = document.getElementById('orders-source-filter');
    if (!sel) return;
    var base = <?php echo json_encode($ordersIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    sel.addEventListener('change', function () {
        var v = this.value;
        var q = 'page=orders' + (v === 'all' ? '' : '&source=' + encodeURIComponent(v));
        window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + q;
    });
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
