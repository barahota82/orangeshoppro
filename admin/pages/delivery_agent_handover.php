<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$hasAgentCol = orange_table_has_column($pdo, 'orders', 'delivery_agent_id');
$hasAgentsTable = orange_table_exists($pdo, 'delivery_agents');
$agents = ($hasAgentsTable && $adminCountryId > 0)
    ? orange_delivery_agents_dropdown($pdo, $adminCountryId, true)
    : [];

$showUnassignedOnly = isset($_GET['unassigned']) && (string) $_GET['unassigned'] === '1';

$orders = [];
if ($hasAgentCol) {
    $sql = "
        SELECT o.*, da.name_ar AS agent_name_ar
        FROM orders o
        LEFT JOIN delivery_agents da ON da.id = o.delivery_agent_id
        WHERE o.status IN ('approved', 'on_the_way')
          AND (o.order_source IS NULL OR o.order_source = '' OR o.order_source = 'website')
    ";
    $params = [];
    $cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($cf !== null) {
        $sql .= $cf['sql'];
        $params[] = $cf['param'];
    }
    if ($showUnassignedOnly) {
        $sql .= ' AND (o.delivery_agent_id IS NULL OR o.delivery_agent_id = 0)';
    }
    $sql .= ' ORDER BY o.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $orders = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$statusAr = [
    'approved' => 'مقبول',
    'on_the_way' => 'بالطريق',
];
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">تسليم المندوب</h1>
    <p class="admin-fy-shell__lead">
        اختر مندوباً ثم حدّد الطلبات — <strong>حفظ</strong> يُسجّل <code>delivery_agent_id</code> فقط (بدون «بالطريق»).
        <strong>تغيير المندوب مسموح</strong> حتى بعد «بالطريق» — قبل <code>completed</code>.
        «بالطريق» و«تم التوصيل» من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=orders'), ENT_QUOTES, 'UTF-8'); ?>">شاشة الطلبات</a>.
    </p>

<?php if (!$hasAgentCol || !$hasAgentsTable): ?>
<div class="card"><div class="alert-error">جداول المناديب غير جاهزة — حدّث المخطط.</div></div>
<?php elseif ($agents === []): ?>
<div class="card"><div class="alert-error">لا يوجد مناديب <strong>نشطون</strong> — أضف من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_agents'), ENT_QUOTES, 'UTF-8'); ?>">مناديب التوصيل</a>.</div></div>
<?php else: ?>

<div class="card admin-fy-card">
    <div class="admin-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px;">
        <label class="admin-toolbar__field">
            <span>المندوب</span>
            <select id="ho_agent_id" style="min-width:220px;">
                <option value="">— اختر مندوب —</option>
                <?php foreach ($agents as $ag): ?>
                <option value="<?php echo (int) ($ag['id'] ?? 0); ?>"><?php echo htmlspecialchars(orange_delivery_agent_display_name($ag), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="button" onclick="handoverSave()">حفظ التوزيع</button>
        <button type="button" class="btn-secondary" onclick="handoverPrintManifest()">طباعة الورقة</button>
        <button type="button" class="btn-secondary" onclick="handoverPrintInvoices()">طباعة فواتير الدفعة</button>
        <button type="button" class="btn-secondary" onclick="handoverShowRemaining()">توزيع الباقي</button>
        <label style="display:flex;align-items:center;gap:6px;">
            <input type="checkbox" id="ho_select_all"> تحديد الكل
        </label>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_agent_handover&unassigned=1'), ENT_QUOTES, 'UTF-8'); ?>">غير موزّع فقط</a>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=delivery_agent_handover'), ENT_QUOTES, 'UTF-8'); ?>">الكل</a>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="ho_head_chk" aria-label="تحديد الكل"></th>
                    <th>رقم الطلب</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>الإجمالي</th>
                    <th>الحالة</th>
                    <th>المندوب</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <?php $oid = (int) ($o['id'] ?? 0); ?>
                <tr data-order-id="<?php echo $oid; ?>">
                    <td><input type="checkbox" class="ho-row-chk" value="<?php echo $oid; ?>"></td>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                    <td><?php
                        $st = (string) ($o['status'] ?? '');
                        echo htmlspecialchars($statusAr[$st] ?? $st, ENT_QUOTES, 'UTF-8');
                    ?></td>
                    <td><?php
                        $an = trim((string) ($o['agent_name_ar'] ?? ''));
                        echo $an !== '' ? htmlspecialchars($an, ENT_QUOTES, 'UTF-8') : '—';
                    ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($orders === []): ?>
                <tr><td colspan="7" class="muted">لا توجد طلبات مؤهلة<?php echo $showUnassignedOnly ? ' (غير موزّعة)' : ''; ?>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var head = document.getElementById('ho_head_chk');
    var all = document.getElementById('ho_select_all');
    function toggleAll(checked) {
        document.querySelectorAll('.ho-row-chk').forEach(function (c) { c.checked = checked; });
    }
    if (head) head.addEventListener('change', function () { toggleAll(head.checked); });
    if (all) all.addEventListener('change', function () { toggleAll(all.checked); });
})();

function handoverSelectedIds() {
    var ids = [];
    document.querySelectorAll('.ho-row-chk:checked').forEach(function (c) {
        var v = parseInt(c.value, 10);
        if (v > 0) ids.push(v);
    });
    return ids;
}

function handoverShowRemaining() {
    window.location.href = <?php echo json_encode(storefront_public_path('/admin/index.php?page=delivery_agent_handover&unassigned=1'), JSON_UNESCAPED_UNICODE); ?>;
}

async function handoverSave() {
    var agentId = parseInt(document.getElementById('ho_agent_id').value, 10) || 0;
    var ids = handoverSelectedIds();
    if (!agentId) { alert('اختر مندوباً'); return; }
    if (!ids.length) { alert('حدّد طلباً واحداً على الأقل'); return; }
    if (!confirm('توزيع ' + ids.length + ' طلب/طلبات على المندوب المختار؟')) return;
    var res = await postJSON('/admin/api/orders/handover-to-agent.php', { agent_id: agentId, order_ids: ids });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}

function handoverPrintManifest() {
    var agentId = parseInt(document.getElementById('ho_agent_id').value, 10) || 0;
    var ids = handoverSelectedIds();
    if (!ids.length) { alert('حدّد طلباً واحداً على الأقل'); return; }
    var base = <?php echo json_encode(storefront_public_path('/admin/index.php'), JSON_UNESCAPED_UNICODE); ?>;
    var q = 'page=delivery_handover_manifest&order_ids=' + encodeURIComponent(ids.join(','));
    if (agentId > 0) q += '&agent_id=' + encodeURIComponent(String(agentId));
    window.open(base + (base.indexOf('?') >= 0 ? '&' : '?') + q, '_blank', 'noopener');
}

function handoverPrintInvoices() {
    var ids = handoverSelectedIds();
    if (!ids.length) { alert('حدّد طلباً واحداً على الأقل'); return; }
    var base = <?php echo json_encode(storefront_public_path('/admin/index.php'), JSON_UNESCAPED_UNICODE); ?>;
    ids.forEach(function (id, idx) {
        setTimeout(function () {
            window.open(base + (base.indexOf('?') >= 0 ? '&' : '?') + 'page=invoice&order_id=' + encodeURIComponent(String(id)) + '&copy=customer', '_blank', 'noopener');
            window.open(base + (base.indexOf('?') >= 0 ? '&' : '?') + 'page=invoice&order_id=' + encodeURIComponent(String(id)) + '&copy=receipt', '_blank', 'noopener');
        }, idx * 400);
    });
}
</script>
<?php endif; ?>
</div>
