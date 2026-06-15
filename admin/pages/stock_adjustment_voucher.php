<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/stock_adjustment_voucher.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_stock_adjustment_voucher_ready($pdo);
$useVouchers = orange_journal_vouchers_ready($pdo);

$list = $ready ? orange_stock_adjustment_voucher_list($pdo, $ctxCountryId) : [];

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editSv = ($editId > 0 && $ready) ? orange_stock_adjustment_voucher_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/stock-adjustment');
$variantsSearchUrl = storefront_public_path('/admin/api/settings/variants_search.php');
$accountsSearchUrl = storefront_public_path('/admin/api/accounts/search-leaves.php');

$initial = [
    'id' => 0,
    'document_date' => date('Y-m-d'),
    'notes' => '',
    'status' => 'draft',
    'journal_voucher_id' => 0,
    'lines' => [],
    'total_value' => 0,
];
if ($editSv !== null) {
    $h = $editSv['header'];
    $initial = [
        'id' => (int) ($h['id'] ?? 0),
        'document_date' => substr((string) ($h['document_date'] ?? ''), 0, 10) ?: date('Y-m-d'),
        'notes' => (string) ($h['notes'] ?? ''),
        'status' => (string) ($h['status'] ?? 'draft'),
        'journal_voucher_id' => (int) ($h['journal_voucher_id'] ?? 0),
        'lines' => $editSv['lines'],
        'total_value' => (float) ($editSv['total_value'] ?? 0),
    ];
}
$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}
?>
<div class="admin-fy-shell" dir="rtl" id="stk_adj_app">
    <div class="page-title">
        <h1>سند تعديل الرصيد</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if (! $ready || ! $useVouchers): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جدول السندات أو سند تعديل الرصيد غير جاهز — حدّث المخطط.</p>
        </div>
    <?php else: ?>

    <p class="card-hint" style="margin:0 0 12px;">
        يُحرَّر هذا السند بعد رفع تقرير الجرد للإدارة واتخاذ القرار. سجّل لكل صنف كمية في خانة
        <strong>إضافة</strong> أو <strong>خصم</strong>، وتُحتسب قيمة الفرق من تكلفة الصنف، واختر لكل سطر
        المعالجة المحاسبية: حساب <strong>أرباح/خسائر</strong> أو <strong>ذمة موظف</strong> من شجرة الحسابات.
    </p>

    <p class="actions gl-acc-stmt-no-print" style="margin:0 0 16px;">
        <button type="button" class="btn-secondary" id="stk_btn_new">سند جديد</button>
        <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=inventory_reconciliation'), ENT_QUOTES, 'UTF-8'); ?>">تقرير الجرد</a>
    </p>

    <div class="card" style="margin-bottom:16px;">
        <h3 class="card-title">سندات تعديل الرصيد</h3>
        <div class="table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>التاريخ</th>
                        <th>أسطر</th>
                        <th>الحالة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($list === []): ?>
                        <tr><td colspan="5" class="muted">لا سندات بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($list as $row): ?>
                            <?php
                            $rid = (int) ($row['id'] ?? 0);
                            $st = (string) ($row['status'] ?? '');
                            $dd = substr((string) ($row['document_date'] ?? ''), 0, 10);
                            ?>
                            <tr>
                                <td><?php echo $rid; ?></td>
                                <td dir="ltr"><?php echo htmlspecialchars($dd !== '' ? orange_format_date_dmY($dd) : '—', ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo (int) ($row['line_count'] ?? 0); ?></td>
                                <td><?php echo $st === 'approved' ? 'معتمد' : 'مسودة'; ?></td>
                                <td><a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=stock_adjustment_voucher&id=' . $rid), ENT_QUOTES, 'UTF-8'); ?>">فتح</a></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card" id="stk_editor_card">
        <h3 class="card-title">تحرير السند</h3>
        <p id="stk_status_badge" class="card-hint" style="margin-top:0;"></p>

        <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:16px;">
            <div>
                <label for="stk_document_date">تاريخ السند</label>
                <input type="text" id="stk_document_date" class="orange-inp-dmy" dir="ltr" lang="en" required>
            </div>
            <div>
                <label for="stk_notes">ملاحظات</label>
                <input type="text" id="stk_notes" style="width:100%;">
            </div>
        </div>

        <h4>أصناف السند</h4>
        <div class="gl-acc-stmt-no-print" style="position:relative;max-width:520px;margin-bottom:12px;">
            <label for="stk_item_search">إضافة صنف (ابحث بالاسم أو الكود)</label>
            <input type="text" id="stk_item_search" autocomplete="off" placeholder="اكتب اسم/كود الصنف ثم اختر…">
            <div id="stk_item_results" class="stk-dropdown" style="display:none;"></div>
        </div>

        <div class="table-wrap" style="max-height:520px;overflow:auto;">
            <table class="admin-fy-table" id="stk_lines_table">
                <thead>
                    <tr>
                        <th>الصنف</th>
                        <th>لون/مقاس</th>
                        <th>الرصيد الحالي</th>
                        <th>إضافة (+)</th>
                        <th>خصم (−)</th>
                        <th>تكلفة الوحدة</th>
                        <th>قيمة الفرق</th>
                        <th>المعالجة المحاسبية</th>
                        <th>ملاحظة</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="stk_lines_body"></tbody>
            </table>
        </div>
        <p id="stk_lines_empty" class="card-hint">لا أصناف — ابحث وأضف صنفاً.</p>

        <div class="card-hint" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:12px 0;padding:12px;background:#f8fafc;border-radius:8px;">
            <div><strong>عدد الأسطر:</strong> <span id="stk_lines_disp">0</span></div>
            <div><strong>صافي قيمة الفرق:</strong> <span id="stk_value_disp" dir="ltr">0</span></div>
        </div>

        <p class="actions gl-acc-stmt-no-print" style="margin-top:16px;">
            <button type="button" id="stk_save_btn">حفظ المسودة</button>
            <button type="button" class="btn-danger" id="stk_delete_btn">حذف المسودة</button>
            <button type="button" id="stk_approve_btn">اعتماد وترحيل</button>
        </p>

        <p id="stk_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="stk_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>
    </div>

    <?php endif; ?>
</div>

<style>
.stk-dropdown { position:absolute; z-index:40; left:0; right:0; background:#fff; border:1px solid #cbd5e1; border-radius:8px; box-shadow:0 6px 18px rgba(15,23,42,0.12); max-height:280px; overflow:auto; }
.stk-dropdown .stk-opt { padding:8px 12px; cursor:pointer; border-bottom:1px solid #f1f5f9; font-size:0.9rem; }
.stk-dropdown .stk-opt:hover { background:#eff6ff; }
.stk-acc-wrap { position:relative; min-width:200px; }
.stk-acc-input { width:100%; }
.stk-val-neg { color:#b91c1c; font-weight:700; }
.stk-val-pos { color:#15803d; font-weight:700; }
</style>

<script>
(function () {
    var API = {
        save: <?php echo json_encode($apiBase . '/save.php', JSON_UNESCAPED_UNICODE); ?>,
        get: <?php echo json_encode($apiBase . '/get.php', JSON_UNESCAPED_UNICODE); ?>,
        approve: <?php echo json_encode($apiBase . '/approve.php', JSON_UNESCAPED_UNICODE); ?>,
        variants: <?php echo json_encode($variantsSearchUrl, JSON_UNESCAPED_UNICODE); ?>,
        accounts: <?php echo json_encode($accountsSearchUrl, JSON_UNESCAPED_UNICODE); ?>
    };
    var state = <?php echo $initialJson; ?>;
    if (!state.lines) { state.lines = []; }

    function el(id) { return document.getElementById(id); }
    function showErr(m) { if (!el('stk_err')) return; el('stk_err').textContent = m || ''; el('stk_err').style.display = m ? 'block' : 'none'; if (m && el('stk_msg')) el('stk_msg').style.display = 'none'; }
    function showOk(m) { if (!el('stk_msg')) return; el('stk_msg').textContent = m || ''; el('stk_msg').style.display = m ? 'block' : 'none'; if (m && el('stk_err')) el('stk_err').style.display = 'none'; }

    function fmt(n) { var x = Number(n) || 0; return x.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function isApproved() { return state.status === 'approved'; }

    async function postJson(url, body) {
        var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body || {}) });
        return res.json();
    }

    function lineValue(ln) {
        var delta = (parseInt(ln.qty_add, 10) || 0) - (parseInt(ln.qty_deduct, 10) || 0);
        return delta * (parseFloat(ln.unit_cost) || 0);
    }

    function recomputeTotals() {
        var total = 0;
        (state.lines || []).forEach(function (ln) { total += lineValue(ln); });
        state.total_value = total;
        if (el('stk_lines_disp')) el('stk_lines_disp').textContent = String((state.lines || []).length);
        if (el('stk_value_disp')) el('stk_value_disp').textContent = fmt(total);
    }

    function renderLines() {
        var tb = el('stk_lines_body');
        if (!tb) return;
        tb.innerHTML = '';
        var lines = state.lines || [];
        var approved = isApproved();
        lines.forEach(function (ln, idx) {
            var v = lineValue(ln);
            var vCls = v < 0 ? 'stk-val-neg' : (v > 0 ? 'stk-val-pos' : '');
            var tr = document.createElement('tr');
            var vlbl = (ln.color || '') + (ln.size ? ' / ' + ln.size : '');
            tr.innerHTML =
                '<td>' + (ln.product_name || '—') + '</td>' +
                '<td>' + (vlbl.trim() || '—') + '</td>' +
                '<td dir="ltr">' + (ln.qty_system != null ? ln.qty_system : 0) + '</td>' +
                '<td><input type="number" min="0" step="1" data-idx="' + idx + '" data-fld="qty_add" value="' + (ln.qty_add || 0) + '" dir="ltr" style="width:72px"' + (approved ? ' disabled' : '') + '></td>' +
                '<td><input type="number" min="0" step="1" data-idx="' + idx + '" data-fld="qty_deduct" value="' + (ln.qty_deduct || 0) + '" dir="ltr" style="width:72px"' + (approved ? ' disabled' : '') + '></td>' +
                '<td dir="ltr">' + fmt(ln.unit_cost) + '</td>' +
                '<td dir="ltr" class="' + vCls + '">' + fmt(v) + '</td>' +
                '<td><div class="stk-acc-wrap"><input type="text" class="stk-acc-input" data-idx="' + idx + '" autocomplete="off" placeholder="ابحث حساب…" value="' + (ln.treatment_account_label || '').replace(/"/g, '&quot;') + '"' + (approved ? ' disabled' : '') + '><div class="stk-dropdown stk-acc-results" data-idx="' + idx + '" style="display:none;"></div></div></td>' +
                '<td><input type="text" data-idx="' + idx + '" data-fld="note" value="' + (ln.note || '').replace(/"/g, '&quot;') + '" style="width:120px"' + (approved ? ' disabled' : '') + '></td>' +
                '<td>' + (approved ? '' : '<button type="button" class="btn-danger" data-del="' + idx + '">حذف</button>') + '</td>';
            tb.appendChild(tr);
        });
        el('stk_lines_empty').style.display = lines.length === 0 ? 'block' : 'none';

        tb.querySelectorAll('input[data-fld]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var i = parseInt(inp.getAttribute('data-idx'), 10);
                var fld = inp.getAttribute('data-fld');
                if (!state.lines[i]) return;
                if (fld === 'note') { state.lines[i].note = inp.value; return; }
                var val = parseInt(inp.value, 10); if (isNaN(val) || val < 0) val = 0;
                state.lines[i][fld] = val;
                recomputeTotals();
            });
        });
        tb.querySelectorAll('button[data-del]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var i = parseInt(btn.getAttribute('data-del'), 10);
                state.lines.splice(i, 1);
                renderLines(); recomputeTotals();
            });
        });
        tb.querySelectorAll('.stk-acc-input').forEach(function (inp) {
            attachAccountSearch(inp);
        });
        recomputeTotals();
    }

    var accTimer = null;
    function attachAccountSearch(inp) {
        var idx = parseInt(inp.getAttribute('data-idx'), 10);
        var box = inp.parentNode.querySelector('.stk-acc-results');
        inp.addEventListener('input', function () {
            var q = inp.value.trim();
            if (accTimer) clearTimeout(accTimer);
            accTimer = setTimeout(function () {
                fetch(API.accounts + '?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        var accs = (d && d.accounts) ? d.accounts : [];
                        box.innerHTML = '';
                        accs.slice(0, 30).forEach(function (a) {
                            var label = (a.code ? a.code + ' — ' : '') + a.name;
                            var opt = document.createElement('div');
                            opt.className = 'stk-opt';
                            opt.textContent = label;
                            opt.addEventListener('click', function () {
                                state.lines[idx].treatment_account_id = a.id;
                                state.lines[idx].treatment_account_label = label;
                                inp.value = label;
                                box.style.display = 'none';
                            });
                            box.appendChild(opt);
                        });
                        box.style.display = accs.length ? 'block' : 'none';
                    });
            }, 300);
        });
        inp.addEventListener('blur', function () { setTimeout(function () { box.style.display = 'none'; }, 200); });
    }

    var itemTimer = null;
    function setupItemSearch() {
        var inp = el('stk_item_search');
        var box = el('stk_item_results');
        if (!inp) return;
        inp.addEventListener('input', function () {
            var q = inp.value.trim();
            if (itemTimer) clearTimeout(itemTimer);
            itemTimer = setTimeout(function () {
                postJson(API.variants, { q: q, limit: 40 }).then(function (d) {
                    var vs = (d && d.variants) ? d.variants : [];
                    box.innerHTML = '';
                    vs.forEach(function (v) {
                        var vlbl = (v.color || '') + (v.size ? ' / ' + v.size : '');
                        var label = v.product_name + (vlbl.trim() ? ' — ' + vlbl : '') + ' (رصيد ' + v.stock_quantity + ')';
                        var opt = document.createElement('div');
                        opt.className = 'stk-opt';
                        opt.textContent = label;
                        opt.addEventListener('click', function () { addVariant(v); box.style.display = 'none'; inp.value = ''; });
                        box.appendChild(opt);
                    });
                    box.style.display = vs.length ? 'block' : 'none';
                });
            }, 300);
        });
        inp.addEventListener('blur', function () { setTimeout(function () { box.style.display = 'none'; }, 200); });
    }

    function addVariant(v) {
        var vid = parseInt(v.variant_id, 10) || 0;
        if (!vid) return;
        if ((state.lines || []).some(function (ln) { return parseInt(ln.variant_id, 10) === vid; })) {
            showErr('الصنف مضاف بالفعل في السند.');
            return;
        }
        showErr('');
        postJson(API.get, { action: 'variant_info', variant_id: vid }).then(function (d) {
            if (!d.success) { showErr(d.message || 'تعذّر جلب بيانات الصنف'); return; }
            var info = d.variant;
            state.lines.push({
                variant_id: vid,
                product_name: info.product_name,
                color: info.color,
                size: info.size,
                qty_system: info.qty_system,
                unit_cost: info.unit_cost,
                qty_add: 0,
                qty_deduct: 0,
                treatment_account_id: 0,
                treatment_account_label: '',
                note: ''
            });
            renderLines();
        });
    }

    function payload() {
        return {
            id: state.id || 0,
            document_date: orangeGetDmyValueAsIso(el('stk_document_date')) || '',
            notes: el('stk_notes').value || '',
            lines: (state.lines || []).map(function (ln) {
                return {
                    variant_id: parseInt(ln.variant_id, 10) || 0,
                    qty_add: parseInt(ln.qty_add, 10) || 0,
                    qty_deduct: parseInt(ln.qty_deduct, 10) || 0,
                    treatment_account_id: parseInt(ln.treatment_account_id, 10) || 0,
                    note: ln.note || ''
                };
            })
        };
    }

    function applyVoucher(sv) {
        if (!sv || !sv.header) return;
        var h = sv.header;
        state.id = parseInt(h.id, 10) || 0;
        state.document_date = (h.document_date || '').substring(0, 10);
        state.notes = h.notes || '';
        state.status = h.status || 'draft';
        state.journal_voucher_id = parseInt(h.journal_voucher_id, 10) || 0;
        state.lines = sv.lines || [];
        state.total_value = parseFloat(sv.total_value) || 0;
        syncForm();
    }

    function syncForm() {
        if (el('stk_document_date')) el('stk_document_date').value = state.document_date ? orangeIsoDateToDmy(state.document_date) : '';
        if (el('stk_notes')) el('stk_notes').value = state.notes || '';
        var approved = isApproved();
        if (el('stk_status_badge')) el('stk_status_badge').textContent = approved
            ? ('معتمد' + (state.journal_voucher_id ? (' — سند #' + state.journal_voucher_id) : ''))
            : 'مسودة';
        ['stk_document_date','stk_notes','stk_save_btn','stk_delete_btn','stk_approve_btn','stk_item_search'].forEach(function (id) {
            var n = el(id); if (n) n.disabled = approved;
        });
        renderLines();
        if (window.orangeInitDmyInputs) { orangeInitDmyInputs(); }
    }

    if (el('stk_save_btn')) el('stk_save_btn').addEventListener('click', async function () {
        showErr('');
        var d = await postJson(API.save, payload());
        if (!d.success) { showErr(d.message || 'فشل الحفظ'); return; }
        showOk(d.message || 'تم الحفظ');
        applyVoucher(d.voucher);
        if (state.id && !window.location.search.includes('id=')) {
            history.replaceState(null, '', '?page=stock_adjustment_voucher&id=' + state.id);
        }
    });

    if (el('stk_delete_btn')) el('stk_delete_btn').addEventListener('click', async function () {
        if (!state.id || !confirm('حذف المسودة؟')) return;
        var d = await postJson(API.save, { action: 'delete', id: state.id });
        if (!d.success) { showErr(d.message); return; }
        window.location.href = '?page=stock_adjustment_voucher';
    });

    if (el('stk_approve_btn')) el('stk_approve_btn').addEventListener('click', async function () {
        if (!state.id) { showErr('احفظ المسودة أولاً'); return; }
        if (!confirm('اعتماد السند وتطبيق التعديل على المخزون وترحيل القيد؟ لا يمكن التراجع.')) return;
        var d = await postJson(API.approve, { id: state.id });
        if (!d.success) { showErr(d.message); return; }
        showOk(d.message);
        applyVoucher(d.voucher);
    });

    if (el('stk_btn_new')) el('stk_btn_new').addEventListener('click', function () {
        window.location.href = '?page=stock_adjustment_voucher';
    });

    setupItemSearch();
    syncForm();
})();
</script>
