<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/inventory_reconciliation.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);

$ready = orange_inventory_reconciliation_ready($pdo);
$useVouchers = orange_journal_vouchers_ready($pdo);
$warehouses = $ready ? orange_inventory_reconciliation_warehouse_options($pdo, $ctxCountryId) : [];
$allLeaves = orange_accounts_fetch($pdo, 'SELECT a.id, a.code, a.name FROM accounts a WHERE 1=1 ORDER BY COALESCE(a.code,\'\'), a.name', [], 'a');
$adjustAccounts = [];
foreach ($allLeaves as $al) {
    $aid = (int) ($al['id'] ?? 0);
    if ($aid > 0 && orange_accounts_account_is_posting_leaf($pdo, $aid)) {
        $code = trim((string) ($al['code'] ?? ''));
        $name = trim((string) ($al['name'] ?? ''));
        $adjustAccounts[] = [
            'id' => $aid,
            'label' => ($code !== '' ? $code . ' — ' : '') . $name,
        ];
    }
}

$list = $ready ? orange_inventory_reconciliation_list($pdo, $ctxCountryId, 40) : [];

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editRec = ($editId > 0 && $ready) ? orange_inventory_reconciliation_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/inventory-reconciliation');
$fmtAmt = static fn (float $n): string => orange_accounting_report_format_amount($n, $reportMoney);

$defaultWarehouseId = 0;
foreach ($warehouses as $wh) {
    if ((int) ($wh['is_default'] ?? 0) === 1) {
        $defaultWarehouseId = (int) ($wh['id'] ?? 0);
        break;
    }
}
if ($defaultWarehouseId <= 0 && $warehouses !== []) {
    $defaultWarehouseId = (int) ($warehouses[0]['id'] ?? 0);
}

$initial = [
    'id' => 0,
    'warehouse_id' => $defaultWarehouseId,
    'counted_at' => date('Y-m-d'),
    'notes' => '',
    'lines' => [],
    'total_qty_variance' => 0,
    'total_value_variance' => 0,
    'lines_with_variance' => 0,
    'status' => 'draft',
];

if ($editRec !== null) {
    $h = $editRec['header'];
    $initial = [
        'id' => (int) ($h['id'] ?? 0),
        'warehouse_id' => (int) ($h['warehouse_id'] ?? 0),
        'counted_at' => substr((string) ($h['counted_at'] ?? ''), 0, 10),
        'notes' => (string) ($h['notes'] ?? ''),
        'lines' => $editRec['lines'],
        'total_qty_variance' => (int) ($editRec['total_qty_variance'] ?? 0),
        'total_value_variance' => (float) ($editRec['total_value_variance'] ?? 0),
        'lines_with_variance' => (int) ($editRec['lines_with_variance'] ?? 0),
        'status' => (string) ($h['status'] ?? 'draft'),
        'journal_voucher_id' => (int) ($h['journal_voucher_id'] ?? 0),
        'warehouse_label' => (string) ($editRec['warehouse_label'] ?? ''),
    ];
}

$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}

?>
<div class="admin-fy-shell" dir="rtl" id="inv_recon_app">
    <div class="page-title">
        <h1>تسوية المخزون / الجرد</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if (! $ready || ! $useVouchers): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جدول السندات أو تسوية المخزون غير جاهز — حدّث المخطط (ACC-10 مرحلة 0).</p>
        </div>
    <?php else: ?>

    <p class="actions gl-acc-stmt-no-print" style="margin:0 0 16px;">
        <button type="button" class="btn-secondary" id="ir_btn_new">جلسة جديدة</button>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=edit_lock'), ENT_QUOTES, 'UTF-8'); ?>">إقفال التعديلات</a>
    </p>

    <div class="card" style="margin-bottom:16px;">
        <h3 class="card-title">جلسات الجرد</h3>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>المستودع</th>
                        <th>تاريخ الجرد</th>
                        <th>أسطر</th>
                        <th>فرق كمية</th>
                        <th>الحالة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="7" class="muted">لا جلسات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $rid = (int) ($row['id'] ?? 0);
                            $st = (string) ($row['status'] ?? '');
                            $whName = trim((string) ($row['warehouse_name_ar'] ?? ''));
                            if ($whName === '') {
                                $whName = trim((string) ($row['warehouse_name_en'] ?? ''));
                            }
                            ?>
                            <tr>
                                <td><?php echo $rid; ?></td>
                                <td><?php echo htmlspecialchars($whName !== '' ? $whName : ('#' . (int) ($row['warehouse_id'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars(substr((string) ($row['counted_at'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) ($row['line_count'] ?? 0); ?></td>
                                <td dir="ltr"><?php echo (int) ($row['total_qty_variance'] ?? 0); ?></td>
                                <td><?php echo $st === 'approved' ? 'معتمد' : 'مسودة'; ?></td>
                                <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=inventory_reconciliation&id=' . $rid), ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="ir_editor_card">
        <h3 class="card-title">تحرير الجلسة</h3>
        <p id="ir_status_badge" class="card-hint" style="margin-top:0;"></p>

        <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:16px;">
            <div>
                <label for="ir_warehouse">المستودع</label>
                <select id="ir_warehouse">
                    <option value="">— اختر —</option>
                    <?php foreach ($warehouses as $wh): ?>
                        <option value="<?php echo (int) $wh['id']; ?>"><?php echo htmlspecialchars($wh['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="ir_counted_at">تاريخ الجرد</label>
                <input type="date" id="ir_counted_at" required>
            </div>
        </div>

        <div class="card-hint" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;margin-bottom:16px;padding:12px;background:#f8fafc;border-radius:8px;">
            <div><strong>أسطر بفرق:</strong> <span id="ir_var_lines_disp">0</span></div>
            <div><strong>مجموع فرق الكمية:</strong> <span id="ir_qty_var_disp" dir="ltr">0</span></div>
            <div><strong>فرق القيمة (تقديري):</strong> <span id="ir_val_var_disp" dir="ltr">0</span></div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="ir_notes">ملاحظات</label>
            <input type="text" id="ir_notes" style="width:100%;max-width:640px;">
        </div>

        <h4>أسطر الجرد</h4>
        <p class="card-hint">حمّل كل متغيرات المنتجات للمستودع ثم عدّل «الكمية الفعلية». عند الحفظ تُثبَّت كمية النظام من المخزن.</p>
        <p class="gl-acc-stmt-no-print">
            <button type="button" class="btn-secondary" id="ir_load_btn">تحميل من المستودع</button>
            <label style="margin-right:12px;"><input type="checkbox" id="ir_show_variance_only"> إظهار الأسطر ذات الفرق فقط</label>
        </p>

        <div class="table-wrap" style="max-height:480px;overflow:auto;">
            <table class="admin-fy-table" id="ir_lines_table">
                <thead>
                    <tr>
                        <th>المنتج</th>
                        <th>لون</th>
                        <th>مقاس</th>
                        <th>كمية النظام</th>
                        <th>الكمية الفعلية</th>
                        <th>الفرق</th>
                        <th>تكلفة</th>
                        <th>فرق القيمة</th>
                    </tr>
                </thead>
                <tbody id="ir_lines_body"></tbody>
            </table>
        </div>
        <p id="ir_lines_empty" class="card-hint" style="display:none;">لا أسطر — اختر مستودعاً و«تحميل من المستودع».</p>

        <p class="actions gl-acc-stmt-no-print" style="margin-top:16px;">
            <button type="button" id="ir_save_btn">حفظ المسودة</button>
            <button type="button" class="btn-danger" id="ir_delete_btn">حذف المسودة</button>
        </p>

        <div id="ir_approve_panel" class="gl-acc-stmt-no-print" style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;">
            <h4>اعتماد الجرد</h4>
            <p class="card-hint">يُطبَّق فرق الكمية على المخزون ويُنشأ قيد GL تلقائياً عند وجود فرق قيمة (تكلفة × فرق الكمية).</p>
            <div style="display:grid;gap:12px;grid-template-columns:1fr auto;max-width:720px;align-items:end;">
                <div>
                    <label for="ir_adj_account">حساب تسوية فرق الجرد (مصروف/إيراد/…)</label>
                    <select id="ir_adj_account">
                        <option value="">— اختر عند وجود فرق قيمة —</option>
                        <?php foreach ($adjustAccounts as $aa): ?>
                            <option value="<?php echo (int) $aa['id']; ?>"><?php echo htmlspecialchars($aa['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" id="ir_approve_btn">اعتماد</button>
            </div>
        </div>

        <p id="ir_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="ir_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>
    </div>

    <?php endif; ?>
</div>
<script>
(function () {
    var API = <?php echo json_encode([
        'save' => $apiBase . '/save.php',
        'get' => $apiBase . '/get.php',
        'approve' => $apiBase . '/approve.php',
    ], JSON_UNESCAPED_UNICODE); ?>;
    var state = <?php echo $initialJson; ?>;
    var decimals = <?php echo (int) $reportMoney['decimals']; ?>;

    function fmt(n) {
        var x = Number(n) || 0;
        return x.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function el(id) { return document.getElementById(id); }

    function showErr(msg) {
        el('ir_err').textContent = msg || '';
        el('ir_err').style.display = msg ? 'block' : 'none';
        if (msg) el('ir_msg').style.display = 'none';
    }
    function showOk(msg) {
        el('ir_msg').textContent = msg || '';
        el('ir_msg').style.display = msg ? 'block' : 'none';
        if (msg) el('ir_err').style.display = 'none';
    }

    function recomputeTotals() {
        var lines = state.lines || [];
        var qtyVar = 0;
        var valVar = 0;
        var withVar = 0;
        lines.forEach(function (ln) {
            var qv = (parseInt(ln.qty_counted, 10) || 0) - (parseInt(ln.qty_system, 10) || 0);
            ln.qty_variance = qv;
            var uc = parseFloat(ln.unit_cost);
            if (isNaN(uc)) uc = 0;
            var lv = qv * uc;
            ln.value_variance = lv;
            if (qv !== 0) withVar++;
            qtyVar += qv;
            valVar += lv;
        });
        state.total_qty_variance = qtyVar;
        state.total_value_variance = valVar;
        state.lines_with_variance = withVar;
        el('ir_var_lines_disp').textContent = String(withVar);
        el('ir_qty_var_disp').textContent = String(qtyVar);
        el('ir_val_var_disp').textContent = fmt(valVar);
    }

    function syncFormFromState() {
        el('ir_warehouse').value = String(state.warehouse_id || '');
        el('ir_counted_at').value = state.counted_at || '';
        el('ir_notes').value = state.notes || '';
        recomputeTotals();
        var approved = state.status === 'approved';
        el('ir_status_badge').textContent = approved
            ? ('معتمد' + (state.journal_voucher_id ? (' — سند #' + state.journal_voucher_id) : ''))
            : 'مسودة';
        ['ir_warehouse','ir_counted_at','ir_notes','ir_save_btn','ir_delete_btn','ir_load_btn','ir_approve_panel','ir_show_variance_only'].forEach(function (id) {
            var node = el(id);
            if (!node) return;
            if (node.tagName === 'BUTTON' || node.type === 'checkbox' || node.type === 'date' || node.tagName === 'SELECT' || node.tagName === 'INPUT') {
                node.disabled = approved;
            }
        });
        renderLines();
    }

    function lineVisible(ln) {
        if (!el('ir_show_variance_only') || !el('ir_show_variance_only').checked) return true;
        return (parseInt(ln.qty_variance, 10) || 0) !== 0;
    }

    function renderLines() {
        var tb = el('ir_lines_body');
        tb.innerHTML = '';
        var lines = state.lines || [];
        var shown = 0;
        lines.forEach(function (ln, idx) {
            if (!lineVisible(ln)) return;
            shown++;
            var qv = (parseInt(ln.qty_counted, 10) || 0) - (parseInt(ln.qty_system, 10) || 0);
            var tr = document.createElement('tr');
            var approved = state.status === 'approved';
            tr.innerHTML =
                '<td>' + (ln.product_name || '—') + '</td>' +
                '<td>' + (ln.color || '—') + '</td>' +
                '<td>' + (ln.size || '—') + '</td>' +
                '<td dir="ltr">' + (ln.qty_system != null ? ln.qty_system : 0) + '</td>' +
                '<td><input type="number" min="0" step="1" data-idx="' + idx + '" value="' + (ln.qty_counted != null ? ln.qty_counted : 0) + '" dir="ltr" style="width:80px"' + (approved ? ' disabled' : '') + '></td>' +
                '<td dir="ltr">' + qv + '</td>' +
                '<td dir="ltr">' + fmt(parseFloat(ln.unit_cost) || 0) + '</td>' +
                '<td dir="ltr">' + fmt(qv * (parseFloat(ln.unit_cost) || 0)) + '</td>';
            tb.appendChild(tr);
        });
        el('ir_lines_empty').style.display = shown === 0 ? 'block' : 'none';
        tb.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('change', onLineEdit);
            inp.addEventListener('input', onLineEdit);
        });
    }

    function onLineEdit(ev) {
        var inp = ev.target;
        var i = parseInt(inp.getAttribute('data-idx'), 10);
        if (!state.lines[i]) return;
        var v = parseInt(inp.value, 10);
        if (isNaN(v) || v < 0) v = 0;
        state.lines[i].qty_counted = v;
        recomputeTotals();
        renderLines();
    }

    function payloadFromForm() {
        return {
            id: state.id || 0,
            warehouse_id: parseInt(el('ir_warehouse').value, 10) || 0,
            counted_at: el('ir_counted_at').value || '',
            notes: el('ir_notes').value || '',
            lines: (state.lines || []).map(function (ln) {
                return {
                    variant_id: parseInt(ln.variant_id, 10) || 0,
                    qty_system: parseInt(ln.qty_system, 10) || 0,
                    qty_counted: parseInt(ln.qty_counted, 10) || 0
                };
            })
        };
    }

    function applyRec(rec) {
        if (!rec || !rec.header) return;
        var h = rec.header;
        state.id = parseInt(h.id, 10) || 0;
        state.warehouse_id = parseInt(h.warehouse_id, 10) || 0;
        state.counted_at = (h.counted_at || '').substring(0, 10);
        state.notes = h.notes || '';
        state.lines = rec.lines || [];
        state.total_qty_variance = parseInt(rec.total_qty_variance, 10) || 0;
        state.total_value_variance = parseFloat(rec.total_value_variance) || 0;
        state.lines_with_variance = parseInt(rec.lines_with_variance, 10) || 0;
        state.status = h.status || 'draft';
        state.journal_voucher_id = parseInt(h.journal_voucher_id, 10) || 0;
        state.warehouse_label = rec.warehouse_label || '';
        syncFormFromState();
    }

    async function postJson(url, body) {
        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body || {})
        });
        return res.json();
    }

    el('ir_save_btn') && el('ir_save_btn').addEventListener('click', async function () {
        showErr('');
        var data = await postJson(API.save, payloadFromForm());
        if (!data.success) { showErr(data.message || 'فشل الحفظ'); return; }
        showOk(data.message || 'تم الحفظ');
        applyRec(data.reconciliation);
        if (state.id && !window.location.search.includes('id=')) {
            history.replaceState(null, '', '?page=inventory_reconciliation&id=' + state.id);
        }
    });

    el('ir_delete_btn') && el('ir_delete_btn').addEventListener('click', async function () {
        if (!state.id || !confirm('حذف المسودة؟')) return;
        var data = await postJson(API.save, { action: 'delete', id: state.id });
        if (!data.success) { showErr(data.message); return; }
        window.location.href = '?page=inventory_reconciliation';
    });

    el('ir_approve_btn') && el('ir_approve_btn').addEventListener('click', async function () {
        if (!state.id) { showErr('احفظ المسودة أولاً'); return; }
        if (!confirm('اعتماد الجرد وتطبيق فروق الكمية؟')) return;
        var data = await postJson(API.approve, {
            id: state.id,
            adjustment_account_id: parseInt(el('ir_adj_account').value, 10) || 0
        });
        if (!data.success) { showErr(data.message); return; }
        showOk(data.message);
        applyRec(data.reconciliation);
    });

    el('ir_load_btn') && el('ir_load_btn').addEventListener('click', async function () {
        var wid = parseInt(el('ir_warehouse').value, 10) || 0;
        if (!wid) { showErr('اختر المستودع'); return; }
        if ((state.lines || []).length && !confirm('استبدال الأسطر الحالية بمحتوى المستودع؟')) return;
        showErr('');
        var data = await postJson(API.get, { action: 'stock_snapshot', warehouse_id: wid });
        if (!data.success) { showErr(data.message); return; }
        state.lines = data.lines || [];
        state.warehouse_id = wid;
        showOk('تم تحميل ' + state.lines.length + ' صنف');
        syncFormFromState();
    });

    el('ir_show_variance_only') && el('ir_show_variance_only').addEventListener('change', renderLines);

    el('ir_btn_new') && el('ir_btn_new').addEventListener('click', function () {
        window.location.href = '?page=inventory_reconciliation';
    });

    syncFormFromState();
})();
</script>
