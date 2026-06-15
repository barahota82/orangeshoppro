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

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_opening_stock_voucher_ready($pdo);
$openingStockLocked = orange_opening_stock_is_locked($pdo, $ctxCountryId);
$nextNo = $ready ? orange_opening_stock_voucher_next_no($pdo, $ctxCountryId) : 1;

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editSv = ($editId > 0 && $ready) ? orange_opening_stock_voucher_get($pdo, $editId, $ctxCountryId) : null;

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
    $initial = [
        'id' => (int) ($h['id'] ?? 0),
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
$voucherDateDisplay = orange_format_date_dmY($initial['document_date']);
?>
<div class="admin-fy-shell" dir="rtl" id="osv_app">
    <div class="page-title osv-print-hide">
        <h1>أرصدة أول المدة المخزنية</h1>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

    <?php if (! $ready): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جداول سند أرصدة أول المدة غير جاهزة — حدّث المخطط.</p>
        </div>
    <?php else: ?>

    <div class="card osv-print-hide" style="margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
            <input type="checkbox" id="osbLockToggle" <?php echo $openingStockLocked ? 'checked' : ''; ?>>
            <span><strong>مقفول</strong> — منع اعتماد أي سند رصيد افتتاحي جديد (حسب دولة الأدمن الحالية)</span>
        </label>
    </div>

    <p class="card-hint osv-print-hide" style="margin:0 0 12px;">
        سند بنمط القيد لكن للكميات: لكل سطر اختر <strong>الصنف</strong> (نقرتان على خانة الكود) وأدخل
        <strong>الكمية الافتتاحية</strong>. عند الاعتماد تُضبَط كمية كل صنف في المستودع الافتراضي للدولة
        كرصيد افتتاحي (كميات فقط بلا قيمة محاسبية).
    </p>

    <div class="card">
        <h3 class="card-title">سند رصيد افتتاحي مخزني</h3>

        <div class="form-grid">
            <div class="osv-header-line" style="grid-column:1/-1;display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));align-items:end;">
                <div>
                    <label for="osv_number">رقم السند</label>
                    <input type="text" id="osv_number" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                        value="<?php echo $nextNo > 0 ? (int) $nextNo : ''; ?>" title="يُخصَّص تلقائياً عند الحفظ">
                </div>
                <div>
                    <label for="osv_date">تاريخ السند</label>
                    <input type="text" id="osv_date" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"
                        value="<?php echo htmlspecialchars($voucherDateDisplay, ENT_QUOTES, 'UTF-8'); ?>" title="تاريخ السند — يوم/شهر/سنة">
                </div>
                <div>
                    <label for="osv_entered">تاريخ الإدخال</label>
                    <input type="text" id="osv_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                        value="<?php echo htmlspecialchars($documentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
                </div>
                <div>
                    <label for="osv_notes">ملاحظات</label>
                    <input type="text" id="osv_notes" placeholder="ملاحظة السند (اختياري)">
                </div>
                <div class="osv-nav-cell osv-print-hide">
                    <div class="osv-nav-btns" role="group" aria-label="تنقل بين السندات">
                        <button type="button" class="btn-secondary osv-nav-btn" id="osv_nav_first" title="أول سند">&lt;&lt;</button>
                        <button type="button" class="btn-secondary osv-nav-btn" id="osv_nav_prev" title="السابق">&lt;</button>
                        <button type="button" class="btn-secondary osv-nav-btn" id="osv_nav_next" title="التالي">&gt;</button>
                        <button type="button" class="btn-secondary osv-nav-btn" id="osv_nav_last" title="آخر سند">&gt;&gt;</button>
                        <button type="button" class="btn-secondary" id="osv_btn_search" title="بحث عن سند">بحث</button>
                    </div>
                </div>
            </div>
        </div>

        <p id="osv_status_badge" class="card-hint" style="margin:6px 0 0;"></p>

        <div class="admin-doc-frame" style="margin-top:12px;">
            <div class="table-wrap">
                <table class="admin-table jv-lines-table osv-lines-table" id="osv_lines_table">
                    <thead>
                        <tr>
                            <th style="width:9rem;">كود الصنف</th>
                            <th>اسم الصنف</th>
                            <th style="width:9rem;">لون/مقاس</th>
                            <th style="width:8rem;">الرصيد الحالي</th>
                            <th style="width:9rem;">الكمية (افتتاحي)</th>
                            <th>ملاحظة</th>
                            <th class="admin-doc-col-actions" aria-label="حذف"></th>
                        </tr>
                    </thead>
                    <tbody id="osv_lines_body"></tbody>
                </table>
            </div>
        </div>
        <p id="osv_lines_empty" class="card-hint">لا أصناف — اضغط «+ سطر» ثم انقر نقرتين على خانة الكود لاختيار الصنف.</p>

        <div class="card-hint" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:12px 0;padding:12px;background:#f8fafc;border-radius:8px;">
            <div><strong>عدد الأسطر:</strong> <span id="osv_lines_disp">0</span></div>
            <div><strong>إجمالي الكمية:</strong> <span id="osv_qty_disp" dir="ltr">0</span></div>
        </div>

        <div class="actions osv-print-hide" style="margin-top:16px;">
            <button type="button" class="btn-secondary" id="osv_btn_add">+ سطر</button>
            <button type="button" class="btn-secondary" id="osv_btn_new">سند جديد</button>
            <button type="button" class="btn-secondary btn-danger" id="osv_btn_delete">حذف المسودة</button>
            <button type="button" class="btn-secondary" id="osv_btn_print" disabled title="احفظ السند أولاً">طباعة</button>
            <button type="button" id="osv_btn_save">حفظ المسودة</button>
            <button type="button" id="osv_btn_approve">اعتماد وتطبيق</button>
        </div>

        <p id="osv_msg" class="card-hint" style="margin-top:12px;color:#166534;display:none;"></p>
        <p id="osv_err" class="card-hint" style="margin-top:12px;color:#b91c1c;display:none;"></p>
    </div>

    <div id="osv_search_modal" class="osv-search-modal osv-print-hide" style="display:none;" aria-hidden="true" role="dialog">
        <div class="osv-search-modal__backdrop" id="osv_search_backdrop"></div>
        <div class="osv-search-modal__panel">
            <div class="osv-search-modal__head"><h3 class="osv-search-modal__title">بحث في سندات الرصيد الافتتاحي</h3></div>
            <div class="osv-search-modal__body">
                <div class="osv-search-fields">
                    <div><label for="osv_s_id_from">رقم — من</label><input type="number" id="osv_s_id_from" class="admin-inp" min="1" step="1" dir="ltr" lang="en"></div>
                    <div><label for="osv_s_id_to">رقم — إلى</label><input type="number" id="osv_s_id_to" class="admin-inp" min="1" step="1" dir="ltr" lang="en"></div>
                    <div><label for="osv_s_date_from">تاريخ — من</label><input type="text" id="osv_s_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"></div>
                    <div><label for="osv_s_date_to">تاريخ — إلى</label><input type="text" id="osv_s_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"></div>
                    <div style="flex:1 1 12rem;"><label for="osv_s_notes">ملاحظة (تحتوي)</label><input type="text" id="osv_s_notes" class="admin-inp" autocomplete="off"></div>
                </div>
                <div class="actions" style="margin:12px 0;"><button type="button" id="osv_s_run">تنفيذ البحث</button></div>
                <div class="table-wrap" style="max-height:24rem;overflow:auto;border:1px solid #e4e4e7;border-radius:8px;">
                    <table class="admin-table" style="font-size:0.9rem;">
                        <thead><tr><th>رقم</th><th>التاريخ</th><th>أسطر</th><th>الحالة</th></tr></thead>
                        <tbody id="osv_s_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/../partials/product_pick_modal.php'; ?>

    <?php endif; ?>
</div>

<style>
.osv-lines-table .osv-code { cursor: pointer; width: 100%; box-sizing: border-box; }
.osv-lines-table .osv-name, .osv-lines-table .osv-vlbl { background: #f4f4f5; cursor: default; width: 100%; box-sizing: border-box; }
.osv-lines-table .osv-sys { background: #f4f4f5; text-align: center; }
.osv-lines-table input { box-sizing: border-box; }
.osv-nav-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.osv-nav-btn { min-width: 2.4rem; }
.osv-search-modal { position: fixed; inset: 0; z-index: 10060; display: none; align-items: center; justify-content: center; padding: 16px; direction: rtl; }
.osv-search-modal__backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.45); }
.osv-search-modal__panel { position: relative; z-index: 1; width: 100%; max-width: min(96vw, 54rem); max-height: calc(100vh - 32px); overflow: auto; background: #fff; border: 1px solid #e4e4e7; border-radius: 10px; box-shadow: 0 20px 50px rgba(0,0,0,.18); }
.osv-search-modal__head { padding: 14px 16px; border-bottom: 1px solid #e4e4e7; text-align: center; }
.osv-search-modal__title { margin: 0; font-size: 1.05rem; }
.osv-search-modal__body { padding: 14px 16px 18px; }
.osv-search-fields { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.osv-search-fields > div { display: flex; flex-direction: column; gap: 4px; }
.osv-search-fields label { font-size: 0.78rem; font-weight: 600; white-space: nowrap; }
.osv-search-fields input { width: 9rem; }
#osv_s_results tr { cursor: pointer; }
#osv_s_results tr:hover { background: #f4f4f5; }
@media print {
    .osv-print-hide { display: none !important; }
}
</style>

<script>
(function () {
    var API = <?php echo json_encode($apiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var LOCK_API = <?php echo json_encode($lockApiUrl, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    var LOCKED = <?php echo $openingStockLocked ? 'true' : 'false'; ?>;
    var state = <?php echo $initialJson; ?>;
    if (!state.lines) { state.lines = []; }
    var browseId = state.id > 0 ? state.id : 0;

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

    function rowHtml(ln, idx) {
        var ro = isApproved() ? ' readonly tabindex="-1"' : '';
        var code = ln.item_code || (ln.variant_id ? ('#' + ln.variant_id) : '');
        return '<tr data-idx="' + idx + '">'
            + '<td><input type="text" class="admin-inp osv-code" value="' + esc(code) + '" placeholder="نقرتان للاختيار" readonly title="نقرتان للاختيار"></td>'
            + '<td><input type="text" class="admin-inp osv-name" value="' + esc(ln.product_name || '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp osv-vlbl" value="' + esc(vlbl(ln)) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp osv-sys" value="' + (ln.qty_system != null ? ln.qty_system : 0) + '" readonly tabindex="-1"></td>'
            + '<td><input type="number" class="admin-inp-qty osv-qty" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" value="' + (ln.quantity != null ? ln.quantity : 0) + '"' + ro + '></td>'
            + '<td><input type="text" class="admin-inp osv-note" value="' + esc(ln.note || '') + '"' + ro + '></td>'
            + '<td>' + (isApproved() ? '' : '<button type="button" class="btn-secondary osv-remove">حذف</button>') + '</td>'
            + '</tr>';
    }

    function render() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        tb.innerHTML = state.lines.map(rowHtml).join('');
        el('osv_lines_empty').style.display = state.lines.length ? 'none' : 'block';
        var totalQty = 0;
        state.lines.forEach(function (l) { totalQty += (parseInt(l.quantity, 10) || 0); });
        el('osv_lines_disp').textContent = String(state.lines.length);
        el('osv_qty_disp').textContent = String(totalQty);
        el('osv_number').value = state.id > 0 ? state.id : <?php echo (int) $nextNo; ?>;
        el('osv_notes').value = state.notes || '';
        el('osv_status_badge').textContent = state.id > 0 ? ('سند #' + state.id + ' — ' + (isApproved() ? 'معتمد' : 'مسودة')) : 'سند جديد (غير محفوظ)';
        bindRows();
        applyMode();
    }

    function applyMode() {
        var ro = isApproved();
        el('osv_btn_save').disabled = ro;
        el('osv_btn_approve').disabled = ro || state.id <= 0 || LOCKED;
        el('osv_btn_delete').disabled = ro || state.id <= 0;
        el('osv_btn_add').disabled = ro;
        var pb = el('osv_btn_print');
        pb.disabled = state.id <= 0;
        pb.title = state.id > 0 ? 'طباعة' : 'احفظ السند أولاً';
        if (LOCKED) { el('osv_btn_approve').title = 'الرصيد الافتتاحي مقفول'; }
        el('osv_date').readOnly = ro;
        el('osv_notes').readOnly = ro;
    }

    function syncFromInputs() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-idx'), 10);
            if (isNaN(idx) || !state.lines[idx]) { return; }
            var q = tr.querySelector('.osv-qty');
            var n = tr.querySelector('.osv-note');
            state.lines[idx].quantity = q ? (parseInt(q.value, 10) || 0) : 0;
            state.lines[idx].note = n ? n.value : '';
        });
    }

    function bindRows() {
        var tb = el('osv_lines_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-idx'), 10);
            var codeInp = tr.querySelector('.osv-code');
            if (codeInp && !isApproved()) {
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
            if (q) { q.addEventListener('input', function () { state.lines[idx].quantity = parseInt(q.value, 10) || 0; recalc(); }); }
        });
    }

    function recalc() {
        var totalQty = 0;
        state.lines.forEach(function (l) { totalQty += (parseInt(l.quantity, 10) || 0); });
        el('osv_qty_disp').textContent = String(totalQty);
        el('osv_lines_disp').textContent = String(state.lines.length);
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
            state.lines[idx].qty_system = info ? info.qty_system : (v.stock_quantity || 0);
            render();
        }).catch(function () {
            state.lines[idx].variant_id = v.variant_id;
            state.lines[idx].product_name = v.product_name || '';
            state.lines[idx].color = v.color || '';
            state.lines[idx].size = v.size || '';
            state.lines[idx].qty_system = v.stock_quantity || 0;
            render();
        });
    }

    function addRow() {
        if (isApproved()) { return; }
        syncFromInputs();
        state.lines.push({ variant_id: 0, product_name: '', color: '', size: '', item_code: '', quantity: 0, note: '', qty_system: 0 });
        render();
    }

    function collectLines() {
        syncFromInputs();
        return state.lines.filter(function (l) { return (parseInt(l.variant_id, 10) || 0) > 0; })
            .map(function (l) { return { variant_id: parseInt(l.variant_id, 10) || 0, quantity: parseInt(l.quantity, 10) || 0, note: l.note || '' }; });
    }

    function save(cb) {
        if (isApproved()) { return; }
        var dIso = (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('osv_date')) : '';
        if (!dIso) { showErr('التاريخ مطلوب (يوم/شهر/سنة)'); return; }
        var lines = collectLines();
        if (!lines.length) { showErr('أضف صنفاً واحداً على الأقل'); return; }
        postJson(API, { action: 'save', id: state.id || 0, document_date: dIso, notes: el('osv_notes').value.trim(), lines: lines }).then(function (r) {
            if (!r.success) { showErr(r.message || 'فشل الحفظ'); return; }
            applyVoucher(r.voucher);
            showOk(r.message || 'تم الحفظ');
            if (typeof cb === 'function') { cb(); }
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function approve() {
        if (state.id <= 0) { showErr('احفظ السند أولاً'); return; }
        if (LOCKED) { showErr('الرصيد الافتتاحي مقفول'); return; }
        if (!confirm('اعتماد السند وتطبيق الأرصدة الافتتاحية على المخزون؟ لا يمكن التراجع.')) { return; }
        postJson(API, { action: 'approve', id: state.id }).then(function (r) {
            if (!r.success) { showErr(r.message || 'فشل الاعتماد'); return; }
            applyVoucher(r.voucher);
            showOk(r.message || 'تم الاعتماد');
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function removeDraft() {
        if (state.id <= 0) { return; }
        if (!confirm('حذف هذه المسودة؟')) { return; }
        postJson(API, { action: 'delete', id: state.id }).then(function (r) {
            if (!r.success) { showErr(r.message || 'تعذّر الحذف'); return; }
            newSheet();
            showOk('تم حذف المسودة');
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function applyVoucher(v) {
        if (!v || !v.header) { return; }
        state.id = parseInt(v.header.id, 10) || 0;
        state.status = v.header.status || 'draft';
        state.document_date = (v.header.document_date || '').substr(0, 10) || state.document_date;
        state.notes = v.header.notes || '';
        state.lines = (v.lines || []).map(function (l) { return l; });
        browseId = state.id;
        if (typeof orangeIsoDateToDmy === 'function') { el('osv_date').value = orangeIsoDateToDmy(state.document_date); }
        render();
    }

    function loadId(id) {
        postJson(API, { action: 'get', id: id }).then(function (r) {
            if (!r.success || !r.voucher) { showErr(r.message || 'تعذّر العرض'); return; }
            showErr('');
            applyVoucher(r.voucher);
            searchClose();
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function newSheet() {
        state = { id: 0, document_date: '<?php echo $initial['document_date']; ?>', notes: '', status: 'draft', lines: [], total_qty: 0 };
        browseId = 0;
        if (typeof orangeIsoDateToDmy === 'function') { el('osv_date').value = orangeIsoDateToDmy(state.document_date); }
        showErr(''); showOk('');
        render();
    }

    function nav(where) {
        postJson(API, { action: 'nav', where: where, current_id: browseId || 0 }).then(function (r) {
            if (!r.success || !r.id) { showErr(r.message || 'لا يوجد سند في هذا الاتجاه'); return; }
            loadId(r.id);
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function searchOpen() { var m = el('osv_search_modal'); if (m) { m.style.display = 'flex'; el('osv_s_results').innerHTML = ''; } }
    function searchClose() { var m = el('osv_search_modal'); if (m) { m.style.display = 'none'; } }
    function searchRun() {
        var payload = {
            action: 'search',
            id_from: parseInt(el('osv_s_id_from').value || '0', 10) || 0,
            id_to: parseInt(el('osv_s_id_to').value || '0', 10) || 0,
            date_from: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('osv_s_date_from')) : '',
            date_to: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('osv_s_date_to')) : '',
            notes: el('osv_s_notes').value.trim()
        };
        postJson(API, payload).then(function (r) {
            var tb = el('osv_s_results');
            tb.innerHTML = '';
            if (!r.success) { showErr(r.message || 'فشل البحث'); return; }
            (r.rows || []).forEach(function (row) {
                var tr = document.createElement('tr');
                var dd = row.document_date ? (typeof orangeIsoDateToDmy === 'function' ? orangeIsoDateToDmy(String(row.document_date).substr(0,10)) : row.document_date) : '—';
                tr.innerHTML = '<td>' + esc(row.id) + '</td><td dir="ltr">' + esc(dd) + '</td><td>' + esc(row.line_count || 0) + '</td><td>' + (row.status === 'approved' ? 'معتمد' : 'مسودة') + '</td>';
                tr.addEventListener('dblclick', function () { loadId(parseInt(row.id, 10) || 0); });
                tr.addEventListener('click', function () { loadId(parseInt(row.id, 10) || 0); });
                tb.appendChild(tr);
            });
        }).catch(function (e) { showErr(e.message || String(e)); });
    }

    function printVoucher() {
        if (state.id <= 0) { showErr('احفظ السند أولاً قبل الطباعة'); return; }
        window.print();
    }

    // الإقفال
    var lockToggle = el('osbLockToggle');
    if (lockToggle) {
        lockToggle.addEventListener('change', function () {
            var locked = this.checked;
            var self = this;
            if (!confirm(locked ? 'إقفال رصيد المخزون الافتتاحي؟ لن يُسمح باعتماد سندات جديدة.' : 'فك إقفال رصيد المخزون الافتتاحي؟')) {
                self.checked = !locked; return;
            }
            postJson(LOCK_API, { locked: locked }).then(function (res) {
                alert(res.message || (res.success ? 'تم' : 'فشل'));
                if (res.success) { location.reload(); } else { self.checked = !locked; }
            }).catch(function (e) { alert(e.message || String(e)); self.checked = !locked; });
        });
    }

    el('osv_btn_add').addEventListener('click', addRow);
    el('osv_btn_new').addEventListener('click', function () { if (confirm('بدء سند جديد؟ سيُمسح غير المحفوظ.')) { newSheet(); } });
    el('osv_btn_delete').addEventListener('click', removeDraft);
    el('osv_btn_print').addEventListener('click', printVoucher);
    el('osv_btn_save').addEventListener('click', function () { save(); });
    el('osv_btn_approve').addEventListener('click', approve);
    el('osv_nav_first').addEventListener('click', function () { nav('first'); });
    el('osv_nav_prev').addEventListener('click', function () { nav('prev'); });
    el('osv_nav_next').addEventListener('click', function () { nav('next'); });
    el('osv_nav_last').addEventListener('click', function () { nav('last'); });
    el('osv_btn_search').addEventListener('click', searchOpen);
    el('osv_s_run').addEventListener('click', searchRun);
    el('osv_search_backdrop').addEventListener('click', searchClose);

    render();
})();
</script>
