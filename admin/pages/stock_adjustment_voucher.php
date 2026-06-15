<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/stock_adjustment_voucher.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_stock_adjustment_voucher_ready($pdo);
$useVouchers = orange_journal_vouchers_ready($pdo);
$nextNo = $ready ? orange_stock_adjustment_voucher_next_no($pdo, $ctxCountryId) : 1;

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editSv = ($editId > 0 && $ready) ? orange_stock_adjustment_voucher_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/stock-adjustment');
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
$documentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$voucherDateDisplay = orange_format_date_dmY($initial['document_date']);
$stkNumberDisplay = $initial['id'] > 0 ? (int) $initial['id'] : $nextNo;
$stkRef = $initial['id'] > 0 ? ('STK-ADJ-' . (int) $initial['id']) : ('STK-ADJ-' . (int) $nextNo);
if ($editSv !== null) {
    $h = $editSv['header'];
    $docAt = trim((string) ($h['created_at'] ?? ''));
    if ($docAt !== '') {
        $documentEnteredDisplay = orange_format_datetime_dmY_hi($docAt);
    }
}
?>
<div class="page-title jv-print-hide">
    <h1>سند تعديل الرصيد</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (! $ready || ! $useVouchers): ?>
    <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p style="margin:0;">جدول السندات أو سند تعديل الرصيد غير جاهز — حدّث المخطط.</p>
    </div>
<?php else: ?>

<p class="card-hint jv-print-hide" style="margin:0 0 12px;">
    سند بنمط القيد لكن للكميات: لكل سطر اختر <strong>الصنف</strong> (نقرتان على خانة الكود)، وسجّل كمية في
    <strong>إضافة (+)</strong> أو <strong>خصم (−)</strong>؛ تُحتسب قيمة الفرق من تكلفة الصنف، واختر لكل سطر
    <strong>حساب المعالجة</strong> (نقرتان): أرباح/خسائر أو ذمة موظف من شجرة الحسابات.
</p>

<div class="card jv-print-area" id="stk_adj_app">
    <h3 class="card-title">سند تعديل رصيد مخزون</h3>

    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ctxCountryId, [
            'title_ar' => 'سند تعديل رصيد مخزون',
            'title_span_id' => 'stk_voucher_print_title_ar',
        ]); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">
    <div class="form-grid">
        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="grid-column:1/-1;">
            <div>
                <label for="stk_number">رقم السند</label>
                <input type="text" id="stk_number" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $stkNumberDisplay; ?>" title="يُخصَّص تلقائياً عند الحفظ">
            </div>
            <div>
                <label for="stk_document_date">تاريخ السند</label>
                <input type="text" id="stk_document_date" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"
                    value="<?php echo htmlspecialchars($voucherDateDisplay, ENT_QUOTES, 'UTF-8'); ?>" title="تاريخ السند — يوم/شهر/سنة">
            </div>
            <div>
                <label for="stk_ref">المرجع</label>
                <input type="text" id="stk_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" tabindex="-1"
                    value="<?php echo htmlspecialchars($stkRef, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُولَّد تلقائياً: STK-ADJ-رقم السند" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="stk_entered">تاريخ الإدخال</label>
                <input type="text" id="stk_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($documentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="stk_tot_lines">عدد الأسطر</label>
                <input type="text" id="stk_tot_lines" readonly class="admin-inp-readonly stk-tot-int" value="0"
                    title="عدد أسطر السند" dir="ltr" lang="en" inputmode="numeric">
            </div>
            <div>
                <label for="stk_tot_value">صافي قيمة الفرق</label>
                <input type="text" id="stk_tot_value" readonly class="admin-inp-readonly jv-tot-readonly" value="0.00"
                    title="مجموع قيم الفروق" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
                    <button type="button" class="btn-secondary jv-nav-btn" id="stk_nav_first" title="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="stk_nav_prev" title="السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="stk_nav_next" title="التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="stk_nav_last" title="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="stk_btn_search" title="بحث عن سند">بحث</button>
                </div>
            </div>
        </div>
        <div style="grid-column:1/-1;">
            <label for="stk_desc">البيان</label>
            <input type="text" id="stk_desc" placeholder="وصف السند" value="<?php echo htmlspecialchars($initial['notes'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
    </div>

    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table jv-lines-table stk-lines-table" id="stk_lines_table">
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>لون/مقاس</th>
                        <th>الرصيد الحالي</th>
                        <th>إضافة (+)</th>
                        <th>خصم (−)</th>
                        <th>تكلفة الوحدة</th>
                        <th>قيمة الفرق</th>
                        <th>حساب المعالجة</th>
                        <th>ملاحظة</th>
                        <th class="admin-doc-col-actions" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="stk_lines_body"></tbody>
            </table>
        </div>
    </div>
    <p id="stk_lines_empty" class="card-hint jv-print-hide">لا أصناف — اضغط «+ سطر يدوي» ثم انقر نقرتين على خانة الكود لاختيار الصنف.</p>

                </td>
            </tr>
        </tbody>
    </table>
    <?php orange_voucher_print_metafoot(); ?>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide">
        <button type="button" class="btn-secondary" id="stk_btn_add">+ سطر يدوي</button>
        <div class="jv-toolbar-primary-group">
            <button type="button" id="stk_btn_new">سند جديد</button>
            <button type="button" class="btn-secondary" id="stk_delete_btn" data-orange-perm="delete">حذف السند</button>
            <button type="button" class="btn-secondary" id="stk_btn_print" disabled title="اعتمد السند أولاً">طباعة السند</button>
            <button type="button" id="stk_save_btn" data-orange-perm="edit">حفظ السند</button>
            <button type="button" id="stk_approve_btn">اعتماد وترحيل</button>
        </div>
    </div>

    <p id="stk_status_badge" class="card-hint jv-print-hide" style="margin:8px 0 0;"></p>
    <p id="stk_msg" class="card-hint jv-print-hide" style="margin-top:8px;color:#166534;display:none;"></p>
    <p id="stk_err" class="card-hint jv-print-hide" style="margin-top:8px;color:#b91c1c;display:none;"></p>
</div>

    <div id="stk_search_modal" class="stk-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog">
        <div class="stk-search-modal__backdrop" id="stk_search_backdrop"></div>
        <div class="stk-search-modal__panel">
            <div class="stk-search-modal__head"><h3 class="stk-search-modal__title">بحث في سندات تعديل الرصيد</h3></div>
            <div class="stk-search-modal__body">
                <div class="stk-search-fields">
                    <div><label for="stk_s_id_from">رقم — من</label><input type="number" id="stk_s_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en"></div>
                    <div><label for="stk_s_id_to">رقم — إلى</label><input type="number" id="stk_s_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en"></div>
                    <div><label for="stk_s_date_from">تاريخ — من</label><input type="text" id="stk_s_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"></div>
                    <div><label for="stk_s_date_to">تاريخ — إلى</label><input type="text" id="stk_s_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"></div>
                    <div style="flex:1 1 12rem;"><label for="stk_s_notes">ملاحظة (تحتوي)</label><input type="text" id="stk_s_notes" class="admin-inp" autocomplete="off"></div>
                </div>
                <div class="actions" style="margin:12px 0;"><button type="button" id="stk_s_run">تنفيذ البحث</button></div>
                <div class="table-wrap" style="max-height:24rem;overflow:auto;border:1px solid #e4e4e7;border-radius:8px;">
                    <table class="admin-table" style="font-size:0.9rem;">
                        <thead><tr><th>رقم</th><th>التاريخ</th><th>أسطر</th><th>الحالة</th></tr></thead>
                        <tbody id="stk_s_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="gl-pick-modal jv-print-hide" id="stk_acc_modal" hidden aria-hidden="true">
        <div class="gl-pick-modal__backdrop" id="stk_acc_backdrop"></div>
        <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true">
            <h3 class="gl-pick-modal__title">اختيار حساب المعالجة</h3>
            <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">أرباح/خسائر أو ذمة موظف — نقرة للاختيار</p>
            <input type="search" id="stk_acc_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
            <ul class="gl-pick-modal__list" id="stk_acc_list"></ul>
            <button type="button" class="btn-secondary" id="stk_acc_close">إغلاق</button>
        </div>
    </div>

    <?php require __DIR__ . '/../partials/product_pick_modal.php'; ?>

    <?php endif; ?>
</div>

<style>
.stk-lines-table .stk-code { cursor: pointer; width: 100%; box-sizing: border-box; }
.stk-lines-table .stk-name, .stk-lines-table .stk-vlbl, .stk-lines-table .stk-acc, .stk-lines-table .stk-sys, .stk-lines-table .stk-cost, .stk-lines-table .stk-val { background: #f4f4f5; width: 100%; box-sizing: border-box; }
.stk-lines-table .stk-acc { cursor: pointer; }
.stk-lines-table .stk-sys, .stk-lines-table .stk-cost, .stk-lines-table .stk-val { text-align: center; }
.stk-lines-table input { box-sizing: border-box; }
.stk-lines-table .stk-add, .stk-lines-table .stk-deduct { width: 100%; }
.stk-val-neg { color: #b91c1c; font-weight: 700; }
.stk-val-pos { color: #15803d; font-weight: 700; }
.jv-voucher-header-line .stk-tot-int {
    text-align: center;
    background: #f4f4f5;
    cursor: default;
}
.gl-pick-modal#stk_acc_modal { z-index: 12100; }
.stk-search-modal { position: fixed; inset: 0; z-index: 10060; display: none; align-items: center; justify-content: center; padding: 16px; direction: rtl; }
.stk-search-modal__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.45); }
.stk-search-modal__panel { position: relative; z-index: 1; width: 100%; max-width: min(96vw, 54rem); max-height: calc(100vh - 32px); overflow: auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 10px; box-shadow: 0 20px 50px rgba(0,0,0,.18); }
.stk-search-modal__head { padding: 14px 16px; border-bottom: 1px solid #e4e4e7; text-align: center; }
.stk-search-modal__title { margin: 0; font-size: 1.05rem; }
.stk-search-modal__body { padding: 14px 16px 18px; }
.stk-search-fields { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.stk-search-fields > div { display: flex; flex-direction: column; gap: 4px; }
.stk-search-fields label { font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
.stk-search-fields input { width: 9rem; }
#stk_s_results tr { cursor: pointer; }
#stk_s_results tr:hover { background: #f4f4f5; }
</style>

<script>
(function () {
    var API = {
        save: <?php echo json_encode($apiBase . '/save.php', JSON_UNESCAPED_UNICODE); ?>,
        get: <?php echo json_encode($apiBase . '/get.php', JSON_UNESCAPED_UNICODE); ?>,
        approve: <?php echo json_encode($apiBase . '/approve.php', JSON_UNESCAPED_UNICODE); ?>,
        accounts: <?php echo json_encode($accountsSearchUrl, JSON_UNESCAPED_UNICODE); ?>
    };
    var NEXT_NO = <?php echo (int) $nextNo; ?>;
    var state = <?php echo $initialJson; ?>;
    if (!state.lines) { state.lines = []; }
    var browseId = state.id > 0 ? state.id : 0;

    function el(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function showErr(m) { var e = el('stk_err'); if (!e) return; e.textContent = m || ''; e.style.display = m ? 'block' : 'none'; if (m) { var o = el('stk_msg'); if (o) o.style.display = 'none'; } }
    function showOk(m) { var o = el('stk_msg'); if (!o) return; o.textContent = m || ''; o.style.display = m ? 'block' : 'none'; if (m) { var e = el('stk_err'); if (e) e.style.display = 'none'; } }
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

    function vlbl(ln) { return [ln.color, ln.size].filter(function (x) { return x; }).join(' / ') || '—'; }

    function rowHtml(ln, idx) {
        var v = lineValue(ln);
        var vCls = v < 0 ? 'stk-val-neg' : (v > 0 ? 'stk-val-pos' : '');
        var dis = isApproved() ? ' disabled' : '';
        var ro = isApproved() ? ' readonly tabindex="-1"' : '';
        var code = ln.item_code || (ln.variant_id ? ('#' + ln.variant_id) : '');
        return '<tr data-idx="' + idx + '">'
            + '<td><input type="text" class="admin-inp stk-code" value="' + esc(code) + '" placeholder="نقرتان للاختيار" readonly title="نقرتان للاختيار"></td>'
            + '<td><input type="text" class="admin-inp stk-name" value="' + esc(ln.product_name || '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-vlbl" value="' + esc(vlbl(ln)) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-sys" value="' + (ln.qty_system != null ? ln.qty_system : 0) + '" readonly tabindex="-1"></td>'
            + '<td><input type="number" class="admin-inp-qty stk-add" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" value="' + (ln.qty_add || 0) + '"' + dis + '></td>'
            + '<td><input type="number" class="admin-inp-qty stk-deduct" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" value="' + (ln.qty_deduct || 0) + '"' + dis + '></td>'
            + '<td><input type="text" class="admin-inp stk-cost" value="' + fmt(ln.unit_cost) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-val ' + vCls + '" value="' + fmt(v) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-acc" value="' + esc(ln.treatment_account_label || '') + '" placeholder="نقرتان للاختيار" readonly title="نقرتان للاختيار"></td>'
            + '<td><input type="text" class="admin-inp stk-note" value="' + esc(ln.note || '') + '"' + ro + '></td>'
            + '<td>' + (isApproved() ? '' : '<button type="button" class="btn-secondary stk-remove">حذف</button>') + '</td>'
            + '</tr>';
    }

    function recompute() {
        var total = 0;
        state.lines.forEach(function (ln) { total += lineValue(ln); });
        state.total_value = total;
        el('stk_tot_lines').value = String(state.lines.length);
        el('stk_tot_value').value = fmt(total);
    }

    function render() {
        var tb = el('stk_lines_body');
        if (!tb) { return; }
        tb.innerHTML = state.lines.map(rowHtml).join('');
        var emptyEl = el('stk_lines_empty');
        if (emptyEl) { emptyEl.style.display = state.lines.length ? 'none' : 'block'; }
        el('stk_number').value = state.id > 0 ? state.id : NEXT_NO;
        el('stk_ref').value = state.id > 0 ? ('STK-ADJ-' + state.id) : ('STK-ADJ-' + NEXT_NO);
        el('stk_desc').value = state.notes || '';
        el('stk_status_badge').textContent = state.id > 0
            ? ('سند #' + state.id + ' — ' + (isApproved() ? ('معتمد' + (state.journal_voucher_id ? ' (قيد #' + state.journal_voucher_id + ')' : '')) : 'مسودة'))
            : 'سند جديد (غير محفوظ)';
        bindRows();
        applyMode();
        recompute();
    }

    function applyMode() {
        var ro = isApproved();
        el('stk_save_btn').disabled = ro;
        el('stk_approve_btn').disabled = ro || state.id <= 0;
        el('stk_delete_btn').disabled = ro || state.id <= 0;
        el('stk_btn_add').disabled = ro;
        var pb = el('stk_btn_print');
        pb.disabled = !isApproved();
        pb.title = isApproved() ? 'طباعة' : 'اعتمد السند أولاً — الطباعة بعد الاعتماد';
        el('stk_document_date').readOnly = ro;
        el('stk_desc').readOnly = ro;
    }

    function syncFromInputs() {
        state.notes = el('stk_desc').value;
        var tb = el('stk_lines_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-idx'), 10);
            if (isNaN(idx) || !state.lines[idx]) { return; }
            var a = tr.querySelector('.stk-add');
            var d = tr.querySelector('.stk-deduct');
            var n = tr.querySelector('.stk-note');
            state.lines[idx].qty_add = a ? (parseInt(a.value, 10) || 0) : 0;
            state.lines[idx].qty_deduct = d ? (parseInt(d.value, 10) || 0) : 0;
            state.lines[idx].note = n ? n.value : '';
        });
    }

    function bindRows() {
        var tb = el('stk_lines_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-idx'), 10);
            if (!isApproved()) {
                var codeInp = tr.querySelector('.stk-code');
                if (codeInp) {
                    codeInp.addEventListener('dblclick', function (e) { e.preventDefault(); OrangeProductPicker.open(function (vv) { onPickProduct(idx, vv); }); });
                }
                var accInp = tr.querySelector('.stk-acc');
                if (accInp) {
                    accInp.addEventListener('dblclick', function (e) { e.preventDefault(); openAccPicker(idx); });
                }
            }
            var rm = tr.querySelector('.stk-remove');
            if (rm) { rm.addEventListener('click', function () { syncFromInputs(); state.lines.splice(idx, 1); render(); }); }
            var a = tr.querySelector('.stk-add');
            var d = tr.querySelector('.stk-deduct');
            function onQty() {
                state.lines[idx].qty_add = parseInt(a.value, 10) || 0;
                state.lines[idx].qty_deduct = parseInt(d.value, 10) || 0;
                var v = lineValue(state.lines[idx]);
                var valCell = tr.querySelector('.stk-val');
                if (valCell) { valCell.value = fmt(v); valCell.className = 'admin-inp stk-val ' + (v < 0 ? 'stk-val-neg' : (v > 0 ? 'stk-val-pos' : '')); }
                recompute();
            }
            if (a) { a.addEventListener('input', onQty); }
            if (d) { d.addEventListener('input', onQty); }
            var n = tr.querySelector('.stk-note');
            if (n) { n.addEventListener('input', function () { state.lines[idx].note = n.value; }); }
        });
    }

    function onPickProduct(idx, v) {
        if (!state.lines[idx]) { return; }
        var vid = parseInt(v.variant_id, 10) || 0;
        if (!vid) { return; }
        var dup = state.lines.some(function (l, i) { return i !== idx && (parseInt(l.variant_id, 10) || 0) === vid; });
        if (dup) { showErr('الصنف مضاف بالفعل في سطر آخر'); return; }
        showErr('');
        postJson(API.get, { action: 'variant_info', variant_id: vid }).then(function (r) {
            if (!r.success || !r.variant) { showErr(r.message || 'تعذّر جلب بيانات الصنف'); return; }
            var info = r.variant;
            var ln = state.lines[idx];
            ln.variant_id = vid;
            ln.product_name = info.product_name;
            ln.color = info.color;
            ln.size = info.size;
            ln.item_code = info.item_code;
            ln.qty_system = info.qty_system;
            ln.unit_cost = info.unit_cost;
            render();
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function addRow() {
        if (isApproved()) { return; }
        syncFromInputs();
        state.lines.push({ variant_id: 0, product_name: '', color: '', size: '', item_code: '', qty_system: 0, unit_cost: 0, qty_add: 0, qty_deduct: 0, treatment_account_id: 0, treatment_account_label: '', note: '' });
        render();
    }

    // ===== منتقي حساب المعالجة (نقرتان) =====
    var accIdx = -1;
    var accSeq = 0;
    var accTimer = null;
    function accModal() { return el('stk_acc_modal'); }
    function openAccPicker(idx) {
        accIdx = idx;
        var m = accModal();
        var q = el('stk_acc_q');
        if (!m || !q) { return; }
        q.value = '';
        m.hidden = false;
        m.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        loadAcc('');
        q.focus();
    }
    function closeAccPicker() {
        var m = accModal();
        if (m) { m.hidden = true; m.setAttribute('aria-hidden', 'true'); }
        document.body.classList.remove('gl-pick-open');
        accIdx = -1;
    }
    function loadAcc(q) {
        var mySeq = ++accSeq;
        var ul = el('stk_acc_list');
        if (ul) { ul.innerHTML = '<li class="gl-pick-empty">جارٍ التحميل…</li>'; }
        fetch(API.accounts + '?q=' + encodeURIComponent(q || ''), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (mySeq !== accSeq) { return; }
                var accs = (d && d.accounts) ? d.accounts : [];
                if (!accs.length) { ul.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
                ul.innerHTML = '';
                accs.forEach(function (a) {
                    var label = (a.code ? a.code + ' — ' : '') + (a.name || '');
                    var li = document.createElement('li');
                    li.className = 'gl-pick-item';
                    li.setAttribute('role', 'button');
                    li.tabIndex = 0;
                    li.textContent = label;
                    function choose() {
                        if (accIdx >= 0 && state.lines[accIdx]) {
                            state.lines[accIdx].treatment_account_id = a.id;
                            state.lines[accIdx].treatment_account_label = label;
                        }
                        closeAccPicker();
                        render();
                    }
                    li.addEventListener('click', choose);
                    li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); choose(); } });
                    ul.appendChild(li);
                });
            }).catch(function (e) { if (ul) { ul.innerHTML = '<li class="gl-pick-empty">' + esc(e.message || String(e)) + '</li>'; } });
    }
    (function bindAcc() {
        var q = el('stk_acc_q');
        if (q) { q.addEventListener('input', function () { if (accTimer) clearTimeout(accTimer); accTimer = setTimeout(function () { loadAcc(q.value.trim()); }, 280); }); }
        var bd = el('stk_acc_backdrop');
        if (bd) { bd.addEventListener('click', closeAccPicker); }
        var cb = el('stk_acc_close');
        if (cb) { cb.addEventListener('click', closeAccPicker); }
    })();

    // ===== الحفظ / الاعتماد / الحذف =====
    function payload() {
        syncFromInputs();
        return {
            id: state.id || 0,
            document_date: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('stk_document_date')) : '',
            notes: el('stk_desc').value.trim(),
            lines: state.lines.filter(function (l) { return (parseInt(l.variant_id, 10) || 0) > 0; }).map(function (ln) {
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
        if (!sv || !sv.header) { return; }
        var h = sv.header;
        state.id = parseInt(h.id, 10) || 0;
        state.document_date = (h.document_date || '').substring(0, 10) || state.document_date;
        state.notes = h.notes || '';
        state.status = h.status || 'draft';
        state.journal_voucher_id = parseInt(h.journal_voucher_id, 10) || 0;
        state.lines = sv.lines || [];
        state.total_value = parseFloat(sv.total_value) || 0;
        browseId = state.id;
        if (typeof orangeIsoDateToDmy === 'function') { el('stk_document_date').value = state.document_date ? orangeIsoDateToDmy(state.document_date) : ''; }
        render();
    }

    function doSave(cb) {
        showErr('');
        var p = payload();
        if (!p.document_date) { showErr('التاريخ مطلوب (يوم/شهر/سنة)'); return; }
        if (!p.lines.length) { showErr('أضف صنفاً واحداً على الأقل'); return; }
        postJson(API.save, p).then(function (d) {
            if (!d.success) { showErr(d.message || 'فشل الحفظ'); return; }
            showOk(d.message || 'تم الحفظ');
            applyVoucher(d.voucher);
            if (typeof cb === 'function') { cb(); }
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function doApprove() {
        if (state.id <= 0) { showErr('احفظ المسودة أولاً'); return; }
        if (!confirm('اعتماد السند وتطبيق التعديل على المخزون وترحيل القيد؟ لا يمكن التراجع.')) { return; }
        postJson(API.approve, { id: state.id }).then(function (d) {
            if (!d.success) { showErr(d.message || 'فشل الاعتماد'); return; }
            showOk(d.message || 'تم الاعتماد');
            applyVoucher(d.voucher);
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function doDelete() {
        if (state.id <= 0) { return; }
        if (!confirm('حذف هذه المسودة؟')) { return; }
        postJson(API.save, { action: 'delete', id: state.id }).then(function (d) {
            if (!d.success) { showErr(d.message || 'تعذّر الحذف'); return; }
            newSheet();
            showOk('تم حذف المسودة');
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function newSheet() {
        state = { id: 0, document_date: '<?php echo $initial['document_date']; ?>', notes: '', status: 'draft', journal_voucher_id: 0, lines: [], total_value: 0 };
        browseId = 0;
        if (typeof orangeIsoDateToDmy === 'function') { el('stk_document_date').value = orangeIsoDateToDmy(state.document_date); }
        showErr(''); showOk('');
        render();
    }

    function loadId(id) {
        postJson(API.get, { action: 'get', id: id }).then(function (r) {
            if (!r.success || !r.voucher) { showErr(r.message || 'تعذّر العرض'); return; }
            showErr('');
            applyVoucher(r.voucher);
            searchClose();
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function nav(where) {
        postJson(API.get, { action: 'nav', where: where, current_id: browseId || 0 }).then(function (r) {
            if (!r.success || !r.id) { showErr(r.message || 'لا يوجد سند في هذا الاتجاه'); return; }
            loadId(r.id);
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function searchOpen() { var m = el('stk_search_modal'); if (m) { m.style.display = 'flex'; el('stk_s_results').innerHTML = ''; } }
    function searchClose() { var m = el('stk_search_modal'); if (m) { m.style.display = 'none'; } }
    function searchRun() {
        var payloadS = {
            action: 'search',
            id_from: parseInt(el('stk_s_id_from').value || '0', 10) || 0,
            id_to: parseInt(el('stk_s_id_to').value || '0', 10) || 0,
            date_from: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('stk_s_date_from')) : '',
            date_to: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('stk_s_date_to')) : '',
            notes: el('stk_s_notes').value.trim()
        };
        postJson(API.get, payloadS).then(function (r) {
            var tb = el('stk_s_results');
            tb.innerHTML = '';
            if (!r.success) { showErr(r.message || 'فشل البحث'); return; }
            (r.rows || []).forEach(function (row) {
                var tr = document.createElement('tr');
                var dd = row.document_date ? (typeof orangeIsoDateToDmy === 'function' ? orangeIsoDateToDmy(String(row.document_date).substr(0, 10)) : row.document_date) : '—';
                tr.innerHTML = '<td>' + esc(row.id) + '</td><td dir="ltr">' + esc(dd) + '</td><td>' + esc(row.line_count || 0) + '</td><td>' + (row.status === 'approved' ? 'معتمد' : 'مسودة') + '</td>';
                tr.addEventListener('click', function () { loadId(parseInt(row.id, 10) || 0); });
                tb.appendChild(tr);
            });
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function printVoucher() {
        if (!isApproved()) { showErr('اعتمد السند أولاً قبل الطباعة'); return; }
        if (typeof orangeAdminOpenPrintDialog === 'function') {
            orangeAdminOpenPrintDialog(orangeAdminBuildVoucherPrintDocTitle(null, 'stk_number', 'سند تعديل رصيد مخزون'));
        } else {
            window.print();
        }
    }

    el('stk_btn_add').addEventListener('click', addRow);
    el('stk_btn_new').addEventListener('click', function () { if (confirm('بدء سند جديد؟ سيُمسح غير المحفوظ.')) { newSheet(); } });
    el('stk_delete_btn').addEventListener('click', doDelete);
    el('stk_btn_print').addEventListener('click', printVoucher);
    el('stk_save_btn').addEventListener('click', function () { doSave(); });
    el('stk_approve_btn').addEventListener('click', doApprove);
    el('stk_nav_first').addEventListener('click', function () { nav('first'); });
    el('stk_nav_prev').addEventListener('click', function () { nav('prev'); });
    el('stk_nav_next').addEventListener('click', function () { nav('next'); });
    el('stk_nav_last').addEventListener('click', function () { nav('last'); });
    el('stk_btn_search').addEventListener('click', searchOpen);
    el('stk_s_run').addEventListener('click', searchRun);
    el('stk_search_backdrop').addEventListener('click', searchClose);
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') { return; }
        var am = el('stk_acc_modal');
        if (am && !am.hidden) { closeAccPicker(); return; }
        var sm = el('stk_search_modal');
        if (sm && sm.style.display === 'flex') { searchClose(); }
    }, true);

    render();
})();
</script>
