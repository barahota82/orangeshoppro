<?php

require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';

$pdo = db();
$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$hasOrderInvoiceCol = orange_table_has_column($pdo, 'orders', 'invoice_number');
$hasDeliveryAgentCol = orange_table_has_column($pdo, 'orders', 'delivery_agent_id');
$hasCartPromoDiscountCol = orange_table_has_column($pdo, 'orders', 'cart_promotion_discount');
$hasCartComboDiscountCol = orange_table_has_column($pdo, 'orders', 'cart_combo_discount');

$agentFilterId = isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : 0;
$deliveryAgents = ($hasDeliveryAgentCol && orange_table_exists($pdo, 'delivery_agents'))
    ? orange_delivery_agents_admin_list($pdo, $adminCountryId > 0 ? $adminCountryId : null)
    : [];

$sourceFilter = isset($_GET['source']) ? trim((string)$_GET['source']) : 'all';
if (!in_array($sourceFilter, ['all', 'website', 'company'], true)) {
    $sourceFilter = 'all';
}

$payFilter = isset($_GET['pay']) ? trim((string)$_GET['pay']) : 'all';
if (!in_array($payFilter, ['all', 'cash', 'credit', 'online'], true)) {
    $payFilter = 'all';
}

// س15 + شاشة العملاء: فلتر `?customer_id=ID` (prefill من زر «طلباته»).
$customerFilterId = isset($_GET['customer_id']) ? (int) $_GET['customer_id'] : 0;
$customerFilterName = '';
$hasOrdersCustomerCol = orange_table_has_column($pdo, 'orders', 'customer_id');
if ($customerFilterId > 0 && $hasOrdersCustomerCol && orange_table_exists($pdo, 'customers')) {
    $stCust = $pdo->prepare('SELECT name_ar FROM customers WHERE id = ? LIMIT 1');
    $stCust->execute([$customerFilterId]);
    $custRow = $stCust->fetch(PDO::FETCH_ASSOC);
    if ($custRow) {
        $customerFilterName = (string) ($custRow['name_ar'] ?? '');
    } else {
        $customerFilterId = 0;
    }
}

$sql = '
    SELECT o.*, c.name AS channel_name';
if ($hasDeliveryAgentCol && orange_table_exists($pdo, 'delivery_agents')) {
    $sql .= ', da.name_ar AS delivery_agent_name';
}
$sql .= '
    FROM orders o
    LEFT JOIN channels c ON c.id = o.channel_id';
if ($hasDeliveryAgentCol && orange_table_exists($pdo, 'delivery_agents')) {
    $sql .= ' LEFT JOIN delivery_agents da ON da.id = o.delivery_agent_id';
}
$sql .= '
    WHERE o.status <> \'completed\'
';
$sqlParams = [];
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
if ($customerFilterId > 0 && $hasOrdersCustomerCol) {
    $sql .= ' AND o.customer_id = ?';
    $sqlParams[] = $customerFilterId;
}
if ($agentFilterId > 0 && $hasDeliveryAgentCol) {
    $sql .= ' AND o.delivery_agent_id = ?';
    $sqlParams[] = $agentFilterId;
}
$ordersCountryFilter = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
if ($ordersCountryFilter !== null) {
    $sql .= $ordersCountryFilter['sql'];
    $sqlParams[] = $ordersCountryFilter['param'];
}

$sql .= ' ORDER BY o.id DESC';

try {
    if ($sqlParams === []) {
        $orders = $pdo->query($sql)->fetchAll();
    } else {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($sqlParams);
        $orders = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    if ($sourceFilter !== 'all' || $payFilter !== 'all' || $customerFilterId > 0) {
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
    : storefront_public_path('/admin/index.php');

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
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">الطلبات</h1>
    <p class="admin-fy-shell__lead">المخزن <strong>موحّد للشركة</strong> — الطلب من أي قناة يخصم نفس المخزون لتفادي البيع رغم النفاد. عمود «قناة العملاء» لتتبّع المصدر وتجميع العملاء (تيك توك، واتساب، …) وليس لمخزون منفصل.
        <?php
        if (orange_admin_may($admin, $pdo, 'sales', 'view')) {
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=reserved_orders'), ENT_QUOTES, 'UTF-8') . '">طلبات محجوزة (مخزون)</a>';
        }
        if (orange_admin_may($admin, $pdo, 'sales', 'view') && orange_table_exists($pdo, 'order_intake_queue')) {
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=order_intake_queue'), ENT_QUOTES, 'UTF-8') . '">طابور طلبات الموقع (قبل إنشاء الطلب)</a>';
        }
        if (orange_admin_may($admin, $pdo, 'sales', 'view')) {
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=online_orders_final_posting'), ENT_QUOTES, 'UTF-8') . '">طلبات أونلاين — إنشاء القيود</a>';
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_agent_handover'), ENT_QUOTES, 'UTF-8') . '">تسليم المندوب</a>';
            echo ' — <a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_order_search'), ENT_QUOTES, 'UTF-8') . '">بحث التسليم</a>';
        }
        ?>
    </p>

<div class="card admin-fy-card">
    <h3 class="card-title">قائمة الطلبات</h3>
    <?php if ($customerFilterId > 0): ?>
    <div class="card-hint" style="margin:0 0 12px;padding:8px 12px;border:1px solid #93c5fd;background:#eff6ff;color:#1e3a8a;border-radius:8px;">
        <strong>عرض طلبات العميل:</strong>
        <?php echo htmlspecialchars($customerFilterName !== '' ? $customerFilterName : ('#' . $customerFilterId), ENT_QUOTES, 'UTF-8'); ?>
        — <a href="<?php echo htmlspecialchars($ordersIndex, ENT_QUOTES, 'UTF-8'); ?>?page=orders">إزالة الفلتر</a>
    </div>
    <?php endif; ?>
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
        <?php if ($deliveryAgents !== []): ?>
        <label class="admin-toolbar__field">
            <span>مندوب التوصيل</span>
            <select id="orders-agent-filter" aria-label="فلتر مندوب">
                <option value="0">الكل</option>
                <?php foreach ($deliveryAgents as $dag): ?>
                <?php $daid = (int) ($dag['id'] ?? 0); ?>
                <option value="<?php echo $daid; ?>"<?php echo $daid === $agentFilterId ? ' selected' : ''; ?>><?php echo htmlspecialchars(orange_delivery_agent_display_name($dag), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php if ($agentFilterId > 0): ?>
        <button type="button" class="btn-secondary" id="orders-bulk-on-way">بالطريق للكل</button>
        <button type="button" class="btn-success" id="orders-bulk-completed">تم التوصيل للكل</button>
        <?php endif; ?>
        <?php endif; ?>
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
                    <?php if ($hasDeliveryAgentCol): ?><th>المندوب</th><?php endif; ?>
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
                    <?php if ($hasDeliveryAgentCol): ?>
                    <td><?php
                        $dan = trim((string) ($o['delivery_agent_name'] ?? ''));
                        echo $dan !== '' ? htmlspecialchars($dan, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                    <?php endif; ?>
                    <td><?php
                        echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0));
                        if ($hasCartComboDiscountCol) {
                            $cd = (float)($o['cart_combo_discount'] ?? 0);
                            if ($cd > 0.00001) {
                                echo '<br><span class="small" title="خصم كومبو">كومبو: −' . htmlspecialchars(number_format($cd, 2), ENT_QUOTES, 'UTF-8') . '</span>';
                            }
                        }
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

</div>

<script>
(function () {
    var srcSel = document.getElementById('orders-source-filter');
    var paySel = document.getElementById('orders-pay-filter');
    var agentSel = document.getElementById('orders-agent-filter');
    if (!srcSel || !paySel) return;
    var base = <?php echo json_encode($ordersIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function go() {
        var src = srcSel.value;
        var pay = paySel.value;
        var q = 'page=orders';
        if (src !== 'all') q += '&source=' + encodeURIComponent(src);
        if (pay !== 'all') q += '&pay=' + encodeURIComponent(pay);
        if (agentSel && parseInt(agentSel.value, 10) > 0) {
            q += '&agent_id=' + encodeURIComponent(agentSel.value);
        }
        window.location.href = base + (base.indexOf('?') === -1 ? '?' : '&') + q;
    }
    srcSel.addEventListener('change', go);
    paySel.addEventListener('change', go);
    if (agentSel) agentSel.addEventListener('change', go);

    var agentId = <?php echo (int) $agentFilterId; ?>;
    var bulkOnWay = document.getElementById('orders-bulk-on-way');
    var bulkDone = document.getElementById('orders-bulk-completed');
    if (bulkOnWay && agentId > 0) {
        bulkOnWay.addEventListener('click', function () {
            if (!confirm('تغيير كل طلبات هذا المندوب المؤهّلة (approved) إلى «بالطريق»؟\n\nملاحظة: يشمل كل المؤهّل في النظام — وليس الصفوف الظاهرة فقط إن كان هناك فلتر مصدر/دفع/عميل.')) return;
            postJSON('/admin/api/orders/bulk-update-status.php', { agent_id: agentId, status: 'on_the_way' })
                .then(function (res) {
                    alert(res.message || (res.success ? 'تم' : 'فشل'));
                    if (res.success) location.reload();
                });
        });
    }
    if (bulkDone && agentId > 0) {
        bulkDone.addEventListener('click', function () {
            if (!confirm('تأكيد «تم التوصيل للكل» لكل طلبات هذا المندوب المؤهّلة (on_the_way) — مخزون فقط بدون قيود؟\n\nملاحظة: يشمل كل المؤهّل في النظام — وليس الصفوف الظاهرة فقط إن كان هناك فلتر مصدر/دفع/عميل.')) return;
            postJSON('/admin/api/orders/bulk-update-status.php', { agent_id: agentId, status: 'completed' })
                .then(function (res) {
                    alert(res.message || (res.success ? 'تم' : 'فشل'));
                    if (res.success) location.reload();
                });
        });
    }
})();
async function updateOrderStatus(orderId, status, currentStatus) {
    if (currentStatus === undefined || currentStatus === null) {
        currentStatus = '';
    }
    if (status === 'rejected') {
        if (currentStatus === 'completed') {
            alert('لا يمكن رفض طلب مُسلَّم — استخدم مردود المبيعات.');
            return;
        }
        if (!confirm('تأكيد رفض هذا الطلب؟')) {
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
