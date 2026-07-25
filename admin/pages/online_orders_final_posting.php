<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/order_fulfillment.php';
require_once __DIR__ . '/../../includes/gl_pending_movements.php';
require_once __DIR__ . '/../../includes/delivery_agents.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_time.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$adminCountryId = orange_admin_context_country_id($pdo);
$ordersMoney = orange_admin_currency_context($pdo);
$agentFilter = isset($_GET['agent_id']) ? (int) $_GET['agent_id'] : 0;
$dateFrom = orange_parse_admin_date_to_ymd(trim((string) ($_GET['date_from'] ?? '')));
$dateTo = orange_parse_admin_date_to_ymd(trim((string) ($_GET['date_to'] ?? '')));
$dateFromDisplay = $dateFrom !== '' ? orange_format_date_dmY($dateFrom) : '';
$dateToDisplay = $dateTo !== '' ? orange_format_date_dmY($dateTo) : '';
$agents = orange_table_exists($pdo, 'delivery_agents')
    ? orange_delivery_agents_admin_list($pdo, $adminCountryId > 0 ? $adminCountryId : null)
    : [];

$filterTz = '';
try {
    $filterTz = orange_admin_time_timezone_for_admin_context($pdo);
} catch (OrangeAdminTimeConfigException $e) {
    $filterTz = '';
}

$rows = [];
$sql = "
    SELECT o.*
    FROM orders o
    WHERE o.status = 'completed'
      AND (o.order_source IS NULL OR o.order_source = '' OR o.order_source = 'website')
";
$params = [];
$cf = orange_sql_filter_country_id($pdo, 'orders', 'o', $adminCountryId);
if ($cf !== null) {
    $sql .= $cf['sql'];
    $params[] = $cf['param'];
}
if ($agentFilter > 0 && orange_table_has_column($pdo, 'orders', 'delivery_agent_id')) {
    $sql .= ' AND o.delivery_agent_id = ?';
    $params[] = $agentFilter;
}
if ($filterTz !== '' && orange_table_has_column($pdo, 'orders', 'completed_at')) {
    try {
        $range = orange_admin_time_filter_range_mysql_utc($dateFrom, $dateTo, $filterTz);
        if ($range !== null) {
            $sql .= ' AND o.completed_at >= ? AND o.completed_at < ?';
            $params[] = $range['start_utc_mysql'];
            $params[] = $range['end_exclusive_utc_mysql'];
        }
    } catch (OrangeAdminTimeConfigException $e) {
        // Keep unfiltered list rather than inventing server-midnight bounds.
    }
}
$sql .= ' ORDER BY o.completed_at DESC, o.id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($candidates as $o) {
    $on = (string) ($o['order_number'] ?? '');
    if ($on === '') {
        continue;
    }
    $cid = (int) ($o['country_id'] ?? 0);
    if (orange_order_forward_delivery_accounting_exists($pdo, $on, $cid > 0 ? $cid : null)) {
        continue;
    }
    $rows[] = $o;
}
?>
<div class="admin-fy-shell" dir="rtl">
    <div class="page-title">
        <h1>إنشاء قيود التسليم</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<div class="card admin-fy-card">
    <div class="admin-toolbar" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;margin-bottom:12px;">
        <label class="admin-toolbar__field">
            <span>من تاريخ التسليم</span>
            <input type="text" id="ofp_date_from" class="orange-inp-dmy" dir="ltr" lang="en" value="<?php echo htmlspecialchars($dateFromDisplay, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <label class="admin-toolbar__field">
            <span>إلى تاريخ التسليم</span>
            <input type="text" id="ofp_date_to" class="orange-inp-dmy" dir="ltr" lang="en" value="<?php echo htmlspecialchars($dateToDisplay, ENT_QUOTES, 'UTF-8'); ?>">
        </label>
        <button type="button" class="btn-secondary" onclick="ofpApplyFilters()">تطبيق الفلتر</button>
        <?php if ($agents !== []): ?>
        <label class="admin-toolbar__field">
            <span>فلتر مندوب</span>
            <select id="ofp_agent_filter" onchange="ofpApplyAgentFilter(this.value)">
                <option value="0">الكل</option>
                <?php foreach ($agents as $ag): ?>
                <?php $aid = (int) ($ag['id'] ?? 0); ?>
                <option value="<?php echo $aid; ?>"<?php echo $aid === $agentFilter ? ' selected' : ''; ?>><?php echo htmlspecialchars(orange_delivery_agent_display_name($ag), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <button type="button" onclick="ofpCreateVouchers()">إنشاء القيود</button>
        <label style="display:flex;align-items:center;gap:6px;">
            <input type="checkbox" id="ofp_select_all"> تحديد الكل
        </label>
    </div>

    <?php if ($rows === []): ?>
    <p class="muted">لا توجد طلبات موقع مُسلَّمة بانتظار القيود.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" id="ofp_head_chk"></th>
                    <th>رقم الطلب</th>
                    <th>العميل</th>
                    <th>الهاتف</th>
                    <th>الإجمالي</th>
                    <th>تاريخ التسليم</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $o): ?>
                <?php $oid = (int) ($o['id'] ?? 0); ?>
                <tr>
                    <td><input type="checkbox" class="ofp-row-chk" value="<?php echo $oid; ?>"></td>
                    <td><?php echo htmlspecialchars((string) ($o['order_number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string) ($o['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td dir="ltr"><?php echo htmlspecialchars((string) ($o['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo orange_format_money_for_context($ordersMoney, (float) ($o['total'] ?? 0)); ?></td>
                    <td dir="ltr"><?php
                        $ca = (string) ($o['completed_at'] ?? $o['updated_at'] ?? '');
                        $rowCid = (int) ($o['country_id'] ?? 0);
                        echo htmlspecialchars(
                            $rowCid > 0
                                ? orange_admin_time_display_mysql_utc_for_record($pdo, $ca, $rowCid)
                                : orange_admin_time_display_mysql_utc_or_dash($pdo, $ca, (int) $adminCountryId),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<script>
(function () {
    var head = document.getElementById('ofp_head_chk');
    var all = document.getElementById('ofp_select_all');
    function toggleAll(checked) {
        document.querySelectorAll('.ofp-row-chk').forEach(function (c) { c.checked = checked; });
    }
    if (head) head.addEventListener('change', function () { toggleAll(head.checked); });
    if (all) all.addEventListener('change', function () { toggleAll(all.checked); });
})();

function ofpApplyAgentFilter(val) {
    ofpNavigate({ agent_id: val });
}

function ofpApplyFilters() {
    ofpNavigate({
        agent_id: document.getElementById('ofp_agent_filter') ? document.getElementById('ofp_agent_filter').value : '0',
        date_from: document.getElementById('ofp_date_from') ? (orangeGetDmyValueAsIso(document.getElementById('ofp_date_from')) || '') : '',
        date_to: document.getElementById('ofp_date_to') ? (orangeGetDmyValueAsIso(document.getElementById('ofp_date_to')) || '') : ''
    });
}

function ofpNavigate(opts) {
    var base = <?php echo json_encode(storefront_public_path('/admin/index.php'), JSON_UNESCAPED_UNICODE); ?>;
    var q = 'page=online_orders_final_posting';
    if (parseInt(opts.agent_id, 10) > 0) q += '&agent_id=' + encodeURIComponent(opts.agent_id);
    if (opts.date_from) q += '&date_from=' + encodeURIComponent(opts.date_from);
    if (opts.date_to) q += '&date_to=' + encodeURIComponent(opts.date_to);
    window.location.href = base + (base.indexOf('?') >= 0 ? '&' : '?') + q;
}

function ofpSelectedIds() {
    var ids = [];
    document.querySelectorAll('.ofp-row-chk:checked').forEach(function (c) {
        var v = parseInt(c.value, 10);
        if (v > 0) ids.push(v);
    });
    return ids;
}

async function ofpCreateVouchers() {
    var ids = ofpSelectedIds();
    if (!ids.length) { alert('حدّد طلباً واحداً على الأقل'); return; }
    if (!confirm('إنشاء القيود لـ ' + ids.length + ' طلب/طلبات؟')) return;
    var res = await postJSON('/admin/api/orders/final-posting-create.php', { order_ids: ids });
    alert(res.message || (res.success ? 'تم' : 'فشل'));
    if (res.success) location.reload();
}
</script>
