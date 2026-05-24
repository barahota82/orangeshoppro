<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$hasAgentCol = orange_table_has_column($pdo, 'orders', 'delivery_agent_id');
$q = trim((string) ($_GET['q'] ?? ''));
$results = [];

if ($q !== '') {
    $agentJoin = $hasAgentCol ? 'LEFT JOIN delivery_agents da ON da.id = o.delivery_agent_id' : '';
    $agentSel = $hasAgentCol ? ', da.name_ar AS agent_name_ar' : '';
    $sql = "
        SELECT o.*{$agentSel}
        FROM orders o
        {$agentJoin}
        WHERE o.status IN ('on_the_way', 'approved')
          AND (o.order_source IS NULL OR o.order_source = '' OR o.order_source = 'website')
    ";
    $params = [];
    $cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
    if ($cf !== null) {
        $sql .= $cf['sql'];
        $params[] = $cf['param'];
    }
    $like = '%' . $q . '%';
    $sql .= ' AND (o.order_number LIKE ? OR o.phone LIKE ? OR o.customer_name LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $sql .= ' ORDER BY o.id DESC LIMIT 50';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$indexBase = storefront_public_path('/admin/index.php');
$multiResults = count($results) > 1;
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">بحث التسليم</h1>
    <p class="admin-fy-shell__lead">
        بحث عن طلب قبل إغلاق دفعة المندوب — <strong>مرتجع كامل</strong> → إلغاء؛ <strong>جزئي</strong> → تعديل الفاتورة.
    </p>

<div class="card admin-fy-card">
    <form method="get" action="<?php echo htmlspecialchars($indexBase, ENT_QUOTES, 'UTF-8'); ?>" class="admin-toolbar" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="page" value="delivery_order_search">
        <label class="admin-toolbar__field" style="flex:1;min-width:240px;">
            <span>رقم الطلب / الهاتف / الاسم</span>
            <input type="search" name="q" value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
        </label>
        <button type="submit">بحث</button>
    </form>

    <?php if ($q !== ''): ?>
    <h3 class="card-title" style="margin-top:16px;">نتائج البحث (<?php echo count($results); ?>)</h3>
    <?php if ($results === []): ?>
    <p class="muted">لا توجد نتائج.</p>
    <?php elseif ($multiResults): ?>
    <p class="card-hint">عدة نتائج — اختر الطلب الصحيح من القائمة (§13.11.9.7.5 O-16).</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>رقم الطلب</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>الإجمالي</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $o): ?>
                <?php $oid = (int) ($o['id'] ?? 0); ?>
                <tr>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                    <td><button type="button" class="btn-secondary" onclick="dosOpenPick(<?php echo $oid; ?>)">اختيار</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div id="dos_pick_modal" hidden style="position:fixed;inset:0;background:rgba(15,23,42,0.45);z-index:9000;display:none;align-items:center;justify-content:center;padding:16px;">
        <div class="card" style="max-width:560px;width:100%;max-height:90vh;overflow:auto;" dir="rtl">
            <h3 class="card-title" style="margin-top:0;">تأكيد الطلب</h3>
            <div id="dos_pick_body"></div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;">
                <button type="button" class="btn-secondary" onclick="dosClosePick()">إغلاق</button>
            </div>
        </div>
    </div>
    <script type="application/json" id="dos_results_json"><?php
        echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?></script>
    <?php else: ?>
    <?php $o = $results[0]; ?>
    <?php $oid = (int) ($o['id'] ?? 0); ?>
    <?php include __DIR__ . '/delivery_order_search_single.inc.php'; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
</div>

<script>
var dosResultsById = {};
(function () {
    var el = document.getElementById('dos_results_json');
    if (!el) return;
    try {
        var rows = JSON.parse(el.textContent || '[]');
        rows.forEach(function (r) {
            var id = parseInt(r.id, 10);
            if (id > 0) dosResultsById[id] = r;
        });
    } catch (e) { /* ignore */ }
})();

function dosClosePick() {
    var m = document.getElementById('dos_pick_modal');
    if (m) { m.style.display = 'none'; m.hidden = true; }
}

function dosOpenPick(orderId) {
    var o = dosResultsById[orderId];
    if (!o) return;
    var body = document.getElementById('dos_pick_body');
    var agent = (o.agent_name_ar || '').trim();
    var html = '<dl style="margin:0;display:grid;grid-template-columns:auto 1fr;gap:6px 12px;">';
    html += '<dt>رقم الطلب</dt><dd>' + (o.order_number || '') + '</dd>';
    html += '<dt>العميل</dt><dd>' + (o.customer_name || '') + '</dd>';
    html += '<dt>الهاتف</dt><dd dir="ltr">' + (o.phone || '') + '</dd>';
    html += '<dt>المنطقة</dt><dd>' + (o.area || '—') + '</dd>';
    html += '<dt>العنوان</dt><dd>' + (o.address || '—') + '</dd>';
    if (agent) html += '<dt>المندوب</dt><dd>' + agent + '</dd>';
    html += '<dt>الحالة</dt><dd>' + (o.status || '') + '</dd>';
    html += '<dt>الإجمالي</dt><dd>' + (o.total || '') + '</dd>';
    html += '</dl>';
    html += '<div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;">';
    html += '<a class="btn" href="<?php echo htmlspecialchars($indexBase, ENT_QUOTES, 'UTF-8'); ?>?page=invoice_edit&order_id=' + orderId + '">تعديل جزئي</a>';
    html += '<button type="button" class="btn-danger" onclick="dosCancelOrder(' + orderId + ')">إلغاء (مرتجع كامل)</button>';
    html += '<a class="btn-secondary" href="<?php echo htmlspecialchars($indexBase, ENT_QUOTES, 'UTF-8'); ?>?page=invoice&order_id=' + orderId + '" target="_blank" rel="noopener">طباعة</a>';
    html += '</div>';
    body.innerHTML = html;
    var m = document.getElementById('dos_pick_modal');
    m.style.display = 'flex';
    m.hidden = false;
}

async function dosCancelOrder(orderId) {
    if (!confirm('إلغاء الطلب (مرتجع كامل) — cancelled؟')) return;
    var res = await postJSON('/admin/api/orders/update-status.php', { order_id: orderId, status: 'cancelled' });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}
</script>
