<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/opening_stock_lock.php';
require_once __DIR__ . '/../../includes/opening_stock_voucher.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_opening_stock_voucher_ready($pdo);
$openingStockLocked = orange_opening_stock_is_locked($pdo, $ctxCountryId);
$nextNo = $ready ? orange_opening_stock_voucher_next_no($pdo, $ctxCountryId) : 1;

$editSv = ($ready) ? orange_opening_stock_voucher_get_singleton($pdo, $ctxCountryId) : null;
$osvId = 0;

$apiUrl = storefront_public_path('/admin/api/opening-stock-voucher/manage.php');
$lockApiUrl = storefront_public_path('/admin/api/stock/opening-stock-lock.php');

$initial = [
    'id' => 0,
    'document_date' => date('Y-m-d'),
    'notes' => '',
    'status' => 'draft',
    'lines' => [],
    'total_qty' => 0,
];
if ($editSv !== null) {
    $h = $editSv['header'];
    $osvId = (int) ($h['id'] ?? 0);
    $initial = [
        'id' => $osvId,
        'document_date' => substr((string) ($h['document_date'] ?? ''), 0, 10) ?: date('Y-m-d'),
        'notes' => (string) ($h['notes'] ?? ''),
        'status' => (string) ($h['status'] ?? 'draft'),
        'lines' => $editSv['lines'],
        'total_qty' => (int) ($editSv['total_qty'] ?? 0),
    ];
}
$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}

$documentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
if ($editSv !== null) {
    $h = $editSv['header'];
    $docAt = trim((string) ($h['created_at'] ?? ''));
    if ($docAt !== '') {
        $documentEnteredDisplay = orange_format_datetime_dmY_hi($docAt);
    }
}

$voucherDateDisplay = orange_format_date_dmY($initial['document_date']);
$osvNumberDisplay = $osvId > 0 ? $osvId : $nextNo;
$osvRef = $osvId > 0
    ? orange_opening_stock_voucher_reference($pdo, $osvId, $ctxCountryId)
    : orange_opening_stock_voucher_reference_preview($pdo, $ctxCountryId);
?>
<div class="page-title jv-print-hide">
    <h1>أرصدة أول المدة المخزنية</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (! $ready): ?>
    <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p style="margin:0;">جداول سند أرصدة أول المدة غير جاهزة — حدّث المخطط.</p>
    </div>
<?php else: ?>

<div class="card jv-print-area osv-opening-card" id="osv_app">
    <h3 class="card-title">سند رصيد افتتاحي مخزني</h3>
    <label class="jv-print-hide" style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0 0 12px;">
        <input type="checkbox" id="osbLockToggle" <?php echo $openingStockLocked ? 'checked' : ''; ?>>
        <span><strong>سند مغلق</strong></span>
    </label>

    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ctxCountryId, [
            'title_ar' => 'سند رصيد افتتاحي مخزني',
            'title_span_id' => 'osv_voucher_print_title_ar',
        ]); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">
    <div class="form-grid">
        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="grid-column:1/-1;">
            <div>
                <label for="osv_number">رقم السند</label>
                <input type="text" id="osv_number" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $osvNumberDisplay; ?>"
                    title="سند رصيد افتتاحي واحد لكل دولة">
            </div>
            <div>
                <label for="osv_date">تاريخ السند</label>
                <input type="text" id="osv_date" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"
                    value="<?php echo htmlspecialchars($voucherDateDisplay, ENT_QUOTES, 'UTF-8'); ?>" title="تاريخ السند — يوم/شهر/سنة">
            </div>
            <div>
                <label for="osv_ref">المرجع</label>
                <input type="text" id="osv_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" tabindex="-1"
                    value="<?php echo htmlspecialchars($osvRef, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُولَّد تلقائياً: OSV-رمز الدولة-رقم السند" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="osv_entered">تاريخ الإدخال</label>
                <input type="text" id="osv_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($documentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="osv_tot_lines">عدد الأسطر</label>
                <input type="text" id="osv_tot_lines" readonly class="admin-inp-readonly osv-tot-int" value="0"
                    title="عدد أسطر السند" dir="ltr" lang="en" inputmode="numeric">
            </div>
            <div>
                <label for="osv_tot_qty">إجمالي الكمية</label>
                <input type="text" id="osv_tot_qty" readonly class="admin-inp-readonly osv-tot-int" value="0"
                    title="مجموع الكميات الافتتاحية" dir="ltr" lang="en" inputmode="numeric">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns osv-voucher-action-btns" role="group" aria-label="إجراءات سند الرصيد الافتتاحي">
                    <button type="button" id="osv_btn_save">حفظ السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="osv_btn_print" title="معاينة الطباعة">طباعة السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="osv_btn_delete"<?php echo $osvId <= 0 ? ' disabled' : ''; ?>>حذف السند</button>
                </div>
            </div>
        </div>
        <div style="grid-column:1/-1;">
            <label for="osv_statement">البيان</label>
            <input type="text" id="osv_statement" placeholder="وصف السند" value="<?php echo htmlspecialchars($initial['notes'], ENT_QUOTES, 'UTF-8'); ?>">
        </div>
    </div>

    <div class="admin-doc-frame">
        <div class="table-wrap osv-opening-table-wrap">
            <table class="admin-table admin-doc-lines-table jv-lines-table osv-lines-table" id="osv_lines_table">
                <colgroup>
                    <col class="osv-col-code">
                    <col class="osv-col-name">
                    <col class="osv-col-vlbl">
                    <col class="osv-col-qty">
                    <col class="osv-col-act">
                </colgroup>
                <thead>
                    <tr>
                        <th>كود الصنف</th>
                        <th>اسم الصنف</th>
                        <th>لون/مقاس</th>
                        <th>الكمية (افتتاحي)</th>
                        <th class="admin-doc-col-actions" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="osv_lines_body"></tbody>
            </table>
        </div>
    </div>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:10px;">
        <button type="button" class="btn-secondary" id="osv_btn_add">+ سطر يدوي</button>
    </div>

                </td>
            </tr>
        </tbody>
    </table>
    <?php orange_voucher_print_metafoot(); ?>

    <p id="osv_status_badge" class="card-hint jv-print-hide" style="margin:8px 0 0;"></p>
    <p id="osv_msg" class="card-hint jv-print-hide" style="margin-top:8px;color:#166534;display:none;"></p>
    <p id="osv_err" class="card-hint jv-print-hide" style="margin-top:8px;color:#b91c1c;display:none;"></p>
</div>

<?php require __DIR__ . '/../partials/product_pick_modal.php'; ?>

<?php endif; ?>

<style>
.osv-lines-table { table-layout: fixed; width: 100%; }
.osv-lines-table col.osv-col-code { width: 12.5rem; }
.osv-lines-table col.osv-col-name { width: auto; }
.osv-lines-table col.osv-col-vlbl { width: 12.5rem; }
.osv-lines-table col.osv-col-qty { width: 8.5rem; }
.osv-lines-table col.osv-col-act { width: 5rem; }
.osv-lines-table .osv-code { cursor: pointer; width: 100%; box-sizing: border-box; }
.osv-lines-table .osv-name,
.osv-lines-table .osv-vlbl { background: #f4f4f5; cursor: default; width: 100%; box-sizing: border-box; }
.osv-lines-table .osv-qty { width: 100%; box-sizing: border-box; }
.osv-lines-table input { box-sizing: border-box; }
.jv-voucher-header-line .osv-tot-int {
    text-align: center;
    background: #f4f4f5;
    cursor: default;
}

/* ===== الطباعة: ملاءمة الأعمدة وإخفاء عمود الإجراء ===== */
@media print {
    .jv-print-area .osv-lines-table {
        table-layout: fixed !important;
        width: 100% !important;
        direction: rtl !important;
    }
    /* نِسَب مئوية: اسم الصنف يأخذ الأوسع، وباقي الأعمدة أضيق */
    .jv-print-area .osv-lines-table col.osv-col-code { width: 16% !important; }
    .jv-print-area .osv-lines-table col.osv-col-name { width: auto !important; }
    .jv-print-area .osv-lines-table col.osv-col-vlbl { width: 18% !important; }
    .jv-print-area .osv-lines-table col.osv-col-qty { width: 14% !important; }
    .jv-print-area .osv-lines-table col.osv-col-act { width: 0 !important; }
    /* إخفاء عمود الإجراء (زر حذف) رأساً وجسماً في الطباعة */
    .jv-print-area .osv-lines-table thead th.admin-doc-col-actions,
    .jv-print-area .osv-lines-table tbody td:last-child {
        display: none !important;
        width: 0 !important;
        padding: 0 !important;
        border: none !important;
    }
    .jv-print-area .osv-lines-table th,
    .jv-print-area .osv-lines-table td {
        font-size: 8pt !important;
        padding: 2px 4px !important;
    }
    .jv-print-area .osv-lines-table input {
        font-size: 8pt !important;
        padding: 1px 3px !important;
        height: auto !important;
        min-height: 0 !important;
        border: 1px solid #cbd5e1 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    /* اسم الصنف محاذاة لليمين (نص)، الكمية وسط */
    .jv-print-area .osv-lines-table .osv-name { text-align: right !important; }
    .jv-print-area .osv-lines-table .osv-qty { text-align: center !important; }
}
</style>

<script>
(function () {
    var API = <?php echo json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var LOCK_API = <?php echo json_encode($lockApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var LOCKED = <?php echo $openingStockLocked ? 'true' : 'false'; ?>;
    var NEXT_NO = <?php echo (int) $nextNo; ?>;
    var SAVED_ID = <?php echo (int) $osvId; ?>;
    var state = <?php echo $initialJson; ?>;
    if (!state.lines) { state.lines = []; }

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function showErr(m) { var e = el('osv_err'); if (!e) return; e.textContent = m || ''; e.style.display = m ? 'block' : 'none'; if (m) { var o = el('osv_msg'); if (o) o.style.display = 'none'; } }
    function showOk(m) { var o = el('osv_msg'); if (!o) return; o.textContent = m || ''; o.style.display = m ? 'block' : 'none'; if (m) { var e = el('osv_err'); if (e) e.style.display = 'none'; } }
    function isApproved() { return state.status === 'approved'; }

    async function postJson(url, body) {
        var res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin', body: JSON.stringify(body || {}) });
        return res.json();
    }

    function vlbl(ln) { return [ln.color, ln.size].filter(function (x) { return x; }).join(' / ') || '—'; }

    function emptyLine() { return { variant_id: 0, product_name: '', color: '', size: '', item_code: '', quantity: 0 }; }

    function rowHtml(ln, idx) {
        var ro = isApproved() ? ' readonly tabindex="-1"' : '';
        var dis = isApproved() ? ' disabled' : '';
        var code = ln.item_code || (ln.variant_id ? ('#' + ln.variant_id) : '');
        return '<tr data-idx="' + idx + '">'
            + '<td class="jv-acc-code-cell"><input type="text" class="admin-inp osv-code" value="' + esc(code) + '" placeholder="نقرتان للاختيار" readonly title="نقرتان للاختيار"></td>'
            + '<td><input type="text" class="admin-inp osv-name admin-inp-readonly" value="' + esc(ln.product_name || '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp osv-vlbl admin-inp-readonly" value="' + esc(vlbl(ln)) + '" readonly tabindex="-1"></td>'
            + '<td><input type="number" class="admin-inp-qty osv-qty" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" placeholder="0" value="' + ((parseInt(ln.quantity, 10) || 0) > 0 ? ln.quantity : '') + '"' + ro + dis + '></td>'
            + '<td>' + (isApproved() ? '' : '<button type="button" class="btn-secondary osv-remove">حذف</button>') + '</td>'
            + '</tr>';
    }

    function recalc() {
        var totalQty = 0, cnt = 0;
        state.lines.forEach(function (l) {
            if ((parseInt(l.variant_id, 10) || 0) > 0) { cnt++; totalQty += (parseInt(l.quantity, 10) || 0); }
        });
        el('osv_tot_lines').value = String(cnt);
        el('osv_tot_qty').value = String(totalQty);
    }

    function render() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        if (!isApproved() && !LOCKED && state.lines.length === 0) { state.lines.push(emptyLine()); }
        tb.innerHTML = state.lines.map(rowHtml).join('');
        el('osv_number').value = state.id > 0 ? state.id : NEXT_NO;
        el('osv_statement').value = state.notes || '';
        var badgeTxt = state.id > 0
            ? ('سند #' + state.id + ' — ' + (isApproved() ? 'معتمد' : 'مسودة'))
            : '';
        var badge = el('osv_status_badge');
        if (badge) {
            badge.textContent = badgeTxt;
            badge.style.display = badgeTxt ? 'block' : 'none';
        }
        SAVED_ID = state.id > 0 ? state.id : 0;
        bindRows();
        applyMode();
        recalc();
    }

    function applyMode() {
        var ro = isApproved() || LOCKED;
        var saveBtn = el('osv_btn_save');
        saveBtn.disabled = ro;
        saveBtn.title = LOCKED && !isApproved() ? 'الرصيد الافتتاحي مقفول' : '';
        el('osv_btn_delete').disabled = ro || state.id <= 0;
        el('osv_btn_add').disabled = ro;
        var pb = el('osv_btn_print');
        pb.disabled = false;
        pb.title = isApproved() ? 'طباعة السند' : 'معاينة الطباعة (متاحة قبل الحفظ لضبط التنسيق)';
        el('osv_date').readOnly = ro;
        el('osv_statement').readOnly = ro;
    }

    function syncFromInputs() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        state.notes = el('osv_statement').value;
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-idx'), 10);
            if (isNaN(idx) || !state.lines[idx]) { return; }
            var q = tr.querySelector('.osv-qty');
            state.lines[idx].quantity = q ? (parseInt(q.value, 10) || 0) : 0;
        });
    }

    function bindRows() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), bindRowAt);
    }

    function bindRowAt(tr) {
        var idx = parseInt(tr.getAttribute('data-idx'), 10);
        var codeInp = tr.querySelector('.osv-code');
        if (codeInp && !isApproved() && !LOCKED) {
            codeInp.addEventListener('dblclick', function (e) {
                e.preventDefault();
                OrangeProductPicker.open(function (v) { onPick(idx, v); });
            });
        }
        var rm = tr.querySelector('.osv-remove');
        if (rm) {
            rm.addEventListener('click', function () {
                syncFromInputs();
                state.lines.splice(idx, 1);
                render();
            });
        }
        var q = tr.querySelector('.osv-qty');
        if (q) {
            q.addEventListener('input', function () { state.lines[idx].quantity = parseInt(q.value, 10) || 0; maybeAppendRow(idx); recalc(); });
            q.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); maybeAppendRow(idx); focusRowQty(idx + 1); } });
        }
    }

    // إضافة سطر تالٍ تلقائياً عند إدخال كمية على السطر الأخير الذي يحمل صنفاً
    function maybeAppendRow(idx) {
        if (isApproved() || LOCKED) { return; }
        if (idx !== state.lines.length - 1) { return; }
        var ln = state.lines[idx];
        if ((parseInt(ln.variant_id, 10) || 0) <= 0) { return; }
        if ((parseInt(ln.quantity, 10) || 0) <= 0) { return; }
        appendEmptyRow();
    }

    function appendEmptyRow() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        var idx = state.lines.length;
        state.lines.push(emptyLine());
        tb.insertAdjacentHTML('beforeend', rowHtml(state.lines[idx], idx));
        var tr = tb.querySelector('tr[data-idx="' + idx + '"]');
        if (tr) { bindRowAt(tr); }
        recalc();
    }

    function focusRowQty(idx) {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        var tr = tb.querySelector('tr[data-idx="' + idx + '"]');
        if (!tr) { return; }
        var q = tr.querySelector('.osv-qty');
        if (q) { q.focus(); q.select && q.select(); }
    }

    function onPick(idx, v) {
        if (!state.lines[idx]) { return; }
        var dup = state.lines.some(function (l, i) { return i !== idx && (parseInt(l.variant_id, 10) || 0) === (parseInt(v.variant_id, 10) || 0); });
        if (dup) { showErr('الصنف مضاف بالفعل في سطر آخر'); return; }
        showErr('');
        postJson(API, { action: 'variant_info', variant_id: v.variant_id }).then(function (r) {
            var info = (r && r.success && r.variant) ? r.variant : null;
            state.lines[idx].variant_id = v.variant_id;
            state.lines[idx].product_name = v.product_name || (info ? info.product_name : '');
            state.lines[idx].color = v.color || (info ? info.color : '');
            state.lines[idx].size = v.size || (info ? info.size : '');
            state.lines[idx].item_code = info ? info.item_code : '';
            render();
        }).catch(function () {
            state.lines[idx].variant_id = v.variant_id;
            state.lines[idx].product_name = v.product_name || '';
            state.lines[idx].color = v.color || '';
            state.lines[idx].size = v.size || '';
            render();
        });
    }

    function addRow() {
        if (isApproved() || LOCKED) { return; }
        syncFromInputs();
        state.lines.push(emptyLine());
        render();
    }

    function collectLines() {
        syncFromInputs();
        return state.lines.filter(function (l) { return (parseInt(l.variant_id, 10) || 0) > 0; })
            .map(function (l) { return { variant_id: parseInt(l.variant_id, 10) || 0, quantity: parseInt(l.quantity, 10) || 0 }; });
    }

    function applyVoucher(v) {
        if (!v || !v.header) { return; }
        state.id = parseInt(v.header.id, 10) || 0;
        state.status = v.header.status || 'draft';
        state.document_date = (v.header.document_date || '').substr(0, 10) || state.document_date;
        state.notes = v.header.notes || '';
        state.lines = (v.lines || []).map(function (l) { return l; });
        if (typeof orangeIsoDateToDmy === 'function') { el('osv_date').value = orangeIsoDateToDmy(state.document_date); }
        if (v.reference) { el('osv_ref').value = v.reference; }
        render();
    }

    function saveVoucher() {
        if (isApproved() || LOCKED) { return; }
        if (LOCKED) { showErr('الرصيد الافتتاحي مقفول'); return; }
        var dIso = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('osv_date')) : '';
        if (!dIso) { showErr('التاريخ مطلوب (يوم/شهر/سنة)'); return; }
        var lines = collectLines();
        if (!lines.length) { showErr('أضف صنفاً واحداً على الأقل'); return; }
        if (!confirm('حفظ السند وتطبيق الأرصدة الافتتاحية على المخزون؟ لا يمكن التراجع.')) { return; }
        postJson(API, { action: 'save', id: state.id || 0, document_date: dIso, notes: el('osv_statement').value.trim(), lines: lines }).then(function (r) {
            if (!r.success) { showErr(r.message || 'فشل الحفظ'); return; }
            applyVoucher(r.voucher);
            var vid = state.id;
            if (vid <= 0) { showErr('تعذّر تحديد رقم السند بعد الحفظ'); return; }
            return postJson(API, { action: 'approve', id: vid });
        }).then(function (r) {
            if (!r) { return; }
            if (!r.success) { showErr(r.message || 'فشل اعتماد السند'); return; }
            applyVoucher(r.voucher);
            showOk(r.message || 'تم حفظ السند وتطبيق الأرصدة الافتتاحية');
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function removeDraft() {
        if (state.id <= 0 || isApproved()) { return; }
        if (!confirm('حذف سند الرصيد الافتتاحي؟')) { return; }
        postJson(API, { action: 'delete', id: state.id }).then(function (r) {
            if (!r.success) { showErr(r.message || 'تعذّر الحذف'); return; }
            state = { id: 0, document_date: state.document_date, notes: '', status: 'draft', lines: [], total_qty: 0 };
            el('osv_statement').value = '';
            showOk('تم حذف السند');
            render();
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function printVoucher() {
        if (typeof orangeAdminOpenPrintDialog === 'function') {
            orangeAdminOpenPrintDialog(orangeAdminBuildVoucherPrintDocTitle(null, 'osv_number', 'سند رصيد افتتاحي مخزني'));
        } else {
            window.print();
        }
    }

    var lockToggle = el('osbLockToggle');
    if (lockToggle) {
        lockToggle.addEventListener('change', function () {
            var locked = this.checked;
            var self = this;
            if (!confirm(locked ? 'إقفال رصيد المخزون الافتتاحي؟ لن يُسمح باعتماد السند.' : 'فك إقفال رصيد المخزون الافتتاحي؟')) {
                self.checked = !locked; return;
            }
            postJson(LOCK_API, { locked: locked }).then(function (res) {
                alert(res.message || (res.success ? 'تم' : 'فشل'));
                if (res.success) { location.reload(); } else { self.checked = !locked; }
            }).catch(function (e) { alert(e.message || String(e)); self.checked = !locked; });
        });
    }

    el('osv_btn_add').addEventListener('click', addRow);
    el('osv_btn_delete').addEventListener('click', removeDraft);
    el('osv_btn_print').addEventListener('click', printVoucher);
    el('osv_btn_save').addEventListener('click', saveVoucher);

    render();
})();
</script>
