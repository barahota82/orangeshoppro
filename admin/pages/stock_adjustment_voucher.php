<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/stock_adjustment_voucher.php';
require_once __DIR__ . '/../../includes/admin_settings_country.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';

$pdo = orange_admin_page_pdo();
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);
$ready = orange_stock_adjustment_voucher_ready($pdo);
$useVouchers = orange_journal_vouchers_ready($pdo);
$nextNo = $ready ? orange_stock_adjustment_voucher_next_no($pdo, $ctxCountryId) : 1;

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$editSv = ($editId > 0 && $ready) ? orange_stock_adjustment_voucher_get($pdo, $editId, $ctxCountryId) : null;

$apiBase = storefront_public_path('/admin/api/stock-adjustment');
$accountsSearchUrl = storefront_public_path('/admin/api/accounts/search-leaves.php');

$invAccId = $ready ? orange_gl_account_id($pdo, 'inventory', $ctxCountryId) : 0;
$invAccCN = orange_stock_adjustment_account_code_name($pdo, $invAccId);
$invAccJson = json_encode([
    'id' => (int) $invAccId,
    'code' => (string) $invAccCN['code'],
    'name' => (string) $invAccCN['name'],
], JSON_UNESCAPED_UNICODE) ?: '{"id":0,"code":"","name":""}';

$initial = [
    'id' => 0,
    'document_date' => date('Y-m-d'),
    'notes' => '',
    'status' => 'draft',
    'journal_voucher_id' => 0,
    'lines' => [],
    'gl_lines' => [],
    'reference' => '',
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
        'gl_lines' => $editSv['gl_lines'] ?? [],
        'total_value' => (float) ($editSv['total_value'] ?? 0),
        'reference' => (string) ($editSv['reference'] ?? ''),
    ];
}
$initialJson = json_encode($initial, JSON_UNESCAPED_UNICODE);
if ($initialJson === false) {
    $initialJson = '{}';
}
$documentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$voucherDateDisplay = orange_format_date_dmY($initial['document_date']);
$stkNumberDisplay = $initial['id'] > 0 ? (int) $initial['id'] : $nextNo;
$stkRefPreview = $ready
    ? orange_stock_adjustment_voucher_reference_preview($pdo, $initial['document_date'], $ctxCountryId > 0 ? $ctxCountryId : null)
    : '';
$stkRef = ($initial['reference'] ?? '') !== '' ? (string) $initial['reference'] : $stkRefPreview;
if ($editSv !== null) {
    $h = $editSv['header'];
    $docAt = trim((string) ($h['created_at'] ?? ''));
    if ($docAt !== '') {
        $documentEnteredDisplay = orange_format_datetime_dmY_hi($docAt);
    }
}
?>
<div class="page-title jv-print-hide">
    <h1>قيد تسوية مخزون</h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (! $ready || ! $useVouchers): ?>
    <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p style="margin:0;">جدول السندات أو قيد تسوية المخزون غير جاهز — حدّث المخطط.</p>
    </div>
<?php else: ?>

<div class="card jv-print-area" id="stk_adj_app">
    <h3 class="card-title">قيد تسوية مخزون</h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'stk', 'doc_kind' => 'stock_adjustment', 'country_id' => $ctxCountryId, 'show_status_badge' => false]); ?>

    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ctxCountryId, [
            'title_ar' => 'قيد تسوية مخزون',
            'title_span_id' => 'stk_voucher_print_title_ar',
        ]); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">
    <div class="form-grid">
        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="grid-column:1/-1;">
            <div>
                <label for="stk_number">رقم القيد</label>
                <input type="text" id="stk_number" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $stkNumberDisplay; ?>" title="يُخصَّص تلقائياً عند الحفظ">
            </div>
            <div>
                <label for="stk_document_date">تاريخ القيد</label>
                <input type="text" id="stk_document_date" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off"
                    value="<?php echo htmlspecialchars($voucherDateDisplay, ENT_QUOTES, 'UTF-8'); ?>" title="تاريخ القيد — يوم/شهر/سنة">
            </div>
            <div>
                <label for="stk_ref">المرجع</label>
                <input type="text" id="stk_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" tabindex="-1"
                    value="<?php echo htmlspecialchars($stkRef, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُولَّد تلقائياً: STK-ADJ-رقم القيد" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="stk_entered">تاريخ الإدخال</label>
                <input type="text" id="stk_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($documentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="stk_tot_debit">مدين</label>
                <input type="text" id="stk_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.00"
                    title="صافي الفرق مدين (زيادة مخزون)" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div>
                <label for="stk_tot_credit">دائن</label>
                <input type="text" id="stk_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.00"
                    title="صافي الفرق دائن (نقص مخزون)" dir="ltr" lang="en" inputmode="decimal">
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

    <h4 class="stk-treat-title">أصناف التعديل (الكميات)</h4>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table jv-lines-table stk-lines-table" id="stk_lines_table">
                <colgroup>
                    <col class="stk-col-code">
                    <col class="stk-col-name">
                    <col class="stk-col-vlbl">
                    <col class="stk-col-sys">
                    <col class="stk-col-add">
                    <col class="stk-col-deduct">
                    <col class="stk-col-cost">
                    <col class="stk-col-val">
                    <col class="stk-col-act">
                </colgroup>
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
                        <th class="admin-doc-col-actions" aria-label="حذف"></th>
                    </tr>
                </thead>
                <tbody id="stk_lines_body"></tbody>
            </table>
        </div>
    </div>
    <p id="stk_lines_empty" class="card-hint jv-print-hide">لا أصناف — اضغط «+ سطر صنف» ثم انقر نقرتين على خانة الكود لاختيار الصنف.</p>
    <div class="actions jv-print-hide stk-qty-toolbar">
        <button type="button" class="btn-secondary" id="stk_btn_add">+ سطر صنف</button>
    </div>

    <div class="stk-treat-card">
        <h4 class="stk-treat-title">المعالجة المحاسبية (قيد التسوية)</h4>
        <p class="stk-treat-info" id="stk_treat_info">—</p>
        <div class="admin-doc-frame">
            <div class="table-wrap">
                <table class="admin-table admin-doc-lines-table jv-lines-table stk-treat-table" id="stk_treat_table">
                    <colgroup>
                        <col class="stk-tcol-code">
                        <col class="stk-tcol-name">
                        <col class="stk-tcol-debit">
                        <col class="stk-tcol-credit">
                        <col class="stk-tcol-act">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين</th>
                            <th>دائن</th>
                            <th class="admin-doc-col-actions" aria-label="حذف"></th>
                        </tr>
                    </thead>
                    <tbody id="stk_treat_body"></tbody>
                    <tfoot>
                        <tr class="stk-treat-foot">
                            <th colspan="2">الإجمالي</th>
                            <th><span id="stk_treat_debit">0.00</span></th>
                            <th><span id="stk_treat_credit">0.00</span></th>
                            <th><span id="stk_treat_balance" class="stk-balance"></span></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <div class="actions jv-print-hide" style="margin-top:8px;gap:8px;">
            <button type="button" class="btn-secondary" id="stk_treat_add">+ سطر معالجة</button>
            <button type="button" class="btn-secondary" id="stk_treat_balance_btn" title="ضبط سطر معالجة واحد ليوازن قيمة المخزون">موازنة تلقائية</button>
        </div>
    </div>

                </td>
            </tr>
        </tbody>
    </table>
    <?php orange_voucher_print_metafoot(); ?>

    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide">
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
            <div class="stk-search-modal__head"><h3 class="stk-search-modal__title">بحث في قيود تسوية المخزون</h3></div>
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
.stk-lines-table { table-layout: fixed; width: 100%; }
.stk-lines-table col.stk-col-code { width: 10rem; }
.stk-lines-table col.stk-col-name { width: auto; }
.stk-lines-table col.stk-col-vlbl { width: 9rem; }
.stk-lines-table col.stk-col-sys { width: 6rem; }
.stk-lines-table col.stk-col-add { width: 5.5rem; }
.stk-lines-table col.stk-col-deduct { width: 5.5rem; }
.stk-lines-table col.stk-col-cost { width: 7rem; }
.stk-lines-table col.stk-col-val { width: 7rem; }
.stk-lines-table col.stk-col-acc { width: 11rem; }
.stk-lines-table col.stk-col-act { width: 5rem; }
.stk-lines-table .stk-code { cursor: pointer; width: 100%; box-sizing: border-box; }
.stk-lines-table .stk-name, .stk-lines-table .stk-vlbl, .stk-lines-table .stk-acc, .stk-lines-table .stk-sys, .stk-lines-table .stk-cost, .stk-lines-table .stk-val { background: #f4f4f5; width: 100%; box-sizing: border-box; }
.stk-lines-table .stk-acc { cursor: pointer; }
.stk-lines-table .stk-sys, .stk-lines-table .stk-cost, .stk-lines-table .stk-val { text-align: center; }
.stk-lines-table input { box-sizing: border-box; }
.stk-lines-table .stk-add, .stk-lines-table .stk-deduct { width: 100%; }
.stk-val-neg { color: #b91c1c; font-weight: 700; }
.stk-val-pos { color: #15803d; font-weight: 700; }
.stk-treat-card { margin-top: 16px; border-top: 2px solid #e4e4e7; padding-top: 12px; }
.stk-treat-title { margin: 0 0 6px; font-size: 1rem; }
.stk-treat-info { margin: 0 0 10px; font-size: 0.9rem; color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px 10px; }
.stk-treat-table { table-layout: fixed; width: 100%; }
.stk-treat-table col.stk-tcol-code { width: 12rem; }
.stk-treat-table col.stk-tcol-name { width: auto; }
.stk-treat-table col.stk-tcol-debit { width: 8rem; }
.stk-treat-table col.stk-tcol-credit { width: 8rem; }
.stk-treat-table col.stk-tcol-act { width: 5rem; }
.stk-treat-table input { box-sizing: border-box; width: 100%; }
.stk-treat-table .stk-tcode { cursor: pointer; }
.stk-treat-table .stk-tname { background: #f4f4f5; }
.stk-treat-table .stk-tdebit, .stk-treat-table .stk-tcredit { text-align: center; }
.stk-treat-table .stk-inv-row input { background: #eef2ff; font-weight: 600; cursor: default; }
.stk-treat-foot th { text-align: center; background: #f8fafc; }
.stk-qty-toolbar { margin: 8px 0 0; }
.stk-balance { font-weight: 700; }
.stk-balance.ok { color: #15803d; }
.stk-balance.bad { color: #b91c1c; }
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
    var INV_ACC = <?php echo $invAccJson; ?>;
    var STK_REF_PREVIEW = <?php echo json_encode($stkRefPreview, JSON_UNESCAPED_UNICODE) ?: '""'; ?>;
    var state = <?php echo $initialJson; ?>;
    if (!state.lines) { state.lines = []; }
    if (!state.gl_lines) { state.gl_lines = []; }
    var browseId = state.id > 0 ? state.id : 0;
    var stkEditLockCtl = null;
    function round4(n) { return Math.round((Number(n) || 0) * 10000) / 10000; }

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
        var code = ln.item_code || (ln.variant_id ? ('#' + ln.variant_id) : '');
        return '<tr data-idx="' + idx + '">'
            + '<td class="jv-acc-code-cell"><input type="text" class="admin-inp stk-code" value="' + esc(code) + '" placeholder="نقرتان للاختيار" readonly title="نقرتان للاختيار"></td>'
            + '<td><input type="text" class="admin-inp stk-name admin-inp-readonly" value="' + esc(ln.product_name || '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-vlbl admin-inp-readonly" value="' + esc(vlbl(ln)) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-sys admin-inp-readonly" value="' + (ln.qty_system != null ? ln.qty_system : 0) + '" readonly tabindex="-1"></td>'
            + '<td><input type="number" class="admin-inp-qty stk-add" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" value="' + (ln.qty_add || 0) + '"' + dis + '></td>'
            + '<td><input type="number" class="admin-inp-qty stk-deduct" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" value="' + (ln.qty_deduct || 0) + '"' + dis + '></td>'
            + '<td><input type="text" class="admin-inp stk-cost admin-inp-readonly" value="' + fmt(ln.unit_cost) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp stk-val admin-inp-readonly ' + vCls + '" value="' + fmt(v) + '" readonly tabindex="-1"></td>'
            + '<td>' + (isApproved() ? '' : '<button type="button" class="btn-secondary stk-remove">حذف</button>') + '</td>'
            + '</tr>';
    }

    function recompute() {
        var total = 0;
        state.lines.forEach(function (ln) { total += lineValue(ln); });
        state.total_value = total;
        // صافي الفرق: موجب = مدين (زيادة مخزون)، سالب = دائن (نقص مخزون).
        var net = round4(total);
        var de = el('stk_tot_debit'); if (de) { de.value = fmt(net > 0 ? net : 0); }
        var ce = el('stk_tot_credit'); if (ce) { ce.value = fmt(net < 0 ? -net : 0); }
        refreshTreat();
    }

    // ===== المعالجة المحاسبية (الكارت السفلي) =====
    function invMovement() {
        var inc = 0, dec = 0;
        state.lines.forEach(function (ln) {
            var v = lineValue(ln);
            if (v > 0) { inc += v; } else if (v < 0) { dec += -v; }
        });
        inc = round4(inc); dec = round4(dec);
        return { inc: inc, dec: dec, net: round4(inc - dec) };
    }

    function treatSums() {
        var d = 0, c = 0;
        state.gl_lines.forEach(function (g) { d += parseFloat(g.debit) || 0; c += parseFloat(g.credit) || 0; });
        return { debit: round4(d), credit: round4(c), net: round4(c - d) };
    }

    // سطر المخزون التلقائي (عرض فقط — يُولَّد من الكميات، ولا يُحفظ ضمن سطور المعالجة).
    function invRowHtml(debit, credit) {
        var codeTxt = INV_ACC.code || '';
        var nameTxt = INV_ACC.name || 'المخزون';
        return '<tr class="stk-inv-row">'
            + '<td><input type="text" class="admin-inp" value="' + esc(codeTxt) + '" readonly tabindex="-1" title="حساب المخزون (تلقائي)"></td>'
            + '<td><input type="text" class="admin-inp" value="' + esc(nameTxt) + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp" value="' + (debit > 0 ? fmt(debit) : '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="text" class="admin-inp" value="' + (credit > 0 ? fmt(credit) : '') + '" readonly tabindex="-1"></td>'
            + '<td></td>'
            + '</tr>';
    }

    function treatRowHtml(g, idx) {
        var dis = isApproved() ? ' disabled' : '';
        var roCls = isApproved() ? ' admin-inp-readonly' : '';
        return '<tr data-tidx="' + idx + '">'
            + '<td class="jv-acc-code-cell"><input type="text" class="admin-inp stk-tcode" value="' + esc(g.account_code || '') + '" placeholder="نقرتان للاختيار" readonly title="نقرتان لاختيار الحساب"></td>'
            + '<td><input type="text" class="admin-inp stk-tname admin-inp-readonly" value="' + esc(g.account_name || '') + '" readonly tabindex="-1"></td>'
            + '<td><input type="number" class="admin-inp stk-tdebit' + roCls + '" min="0" step="0.0001" inputmode="decimal" lang="en" dir="ltr" value="' + (g.debit ? g.debit : '') + '"' + dis + '></td>'
            + '<td><input type="number" class="admin-inp stk-tcredit' + roCls + '" min="0" step="0.0001" inputmode="decimal" lang="en" dir="ltr" value="' + (g.credit ? g.credit : '') + '"' + dis + '></td>'
            + '<td>' + (isApproved() ? '' : '<button type="button" class="btn-secondary stk-tremove">حذف</button>') + '</td>'
            + '</tr>';
    }

    function renderTreat() {
        var tb = el('stk_treat_body');
        if (!tb) { return; }
        var mv = invMovement();
        var html = '';
        // سطر المخزون التلقائي (مدين عند الزيادة / دائن عند النقص) — للعرض ليبدو القيد كاملاً متوازناً.
        if (mv.inc > 0) { html += invRowHtml(mv.inc, 0); }
        if (mv.dec > 0) { html += invRowHtml(0, mv.dec); }
        html += state.gl_lines.map(treatRowHtml).join('');
        tb.innerHTML = html;
        bindTreatRows();
    }

    // مزامنة + إعادة رسم (تُستدعى عند تغيّر الكميات لتحديث سطر المخزون).
    function refreshTreat() {
        syncTreatFromInputs();
        renderTreat();
        updateTreatBalance();
    }

    function syncTreatFromInputs() {
        var tb = el('stk_treat_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr[data-tidx]'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-tidx'), 10);
            if (isNaN(idx) || !state.gl_lines[idx]) { return; }
            var d = tr.querySelector('.stk-tdebit');
            var c = tr.querySelector('.stk-tcredit');
            state.gl_lines[idx].debit = d ? (parseFloat(d.value) || 0) : 0;
            state.gl_lines[idx].credit = c ? (parseFloat(c.value) || 0) : 0;
        });
    }

    function bindTreatRows() {
        var tb = el('stk_treat_body');
        if (!tb) { return; }
        Array.prototype.forEach.call(tb.querySelectorAll('tr[data-tidx]'), function (tr) {
            var idx = parseInt(tr.getAttribute('data-tidx'), 10);
            if (!isApproved()) {
                var codeInp = tr.querySelector('.stk-tcode');
                if (codeInp) { codeInp.addEventListener('dblclick', function (e) { e.preventDefault(); openAccPicker(idx); }); }
            }
            var rm = tr.querySelector('.stk-tremove');
            if (rm) { rm.addEventListener('click', function () { syncTreatFromInputs(); state.gl_lines.splice(idx, 1); renderTreat(); updateTreatBalance(); }); }
            var d = tr.querySelector('.stk-tdebit');
            var c = tr.querySelector('.stk-tcredit');
            function onAmt(which) {
                return function () {
                    state.gl_lines[idx].debit = d ? (parseFloat(d.value) || 0) : 0;
                    state.gl_lines[idx].credit = c ? (parseFloat(c.value) || 0) : 0;
                    // مدين ودائن متعارضان — عند الكتابة في أحدهما يُفرَّغ الآخر.
                    if (which === 'd' && state.gl_lines[idx].debit > 0 && c) { c.value = ''; state.gl_lines[idx].credit = 0; }
                    if (which === 'c' && state.gl_lines[idx].credit > 0 && d) { d.value = ''; state.gl_lines[idx].debit = 0; }
                    updateTreatBalance();
                };
            }
            if (d) { d.addEventListener('input', onAmt('d')); }
            if (c) { c.addEventListener('input', onAmt('c')); }
        });
    }

    function addTreatRow() {
        if (isApproved()) { return; }
        syncTreatFromInputs();
        state.gl_lines.push({ account_id: 0, account_code: '', account_name: '', debit: 0, credit: 0 });
        renderTreat();
        updateTreatBalance();
    }

    function autoBalance() {
        if (isApproved()) { return; }
        syncTreatFromInputs();
        var mv = invMovement();
        var sums = treatSums();
        // الفرق المطلوب سدّه في صافي المعالجة (دائن − مدين) = mv.net
        var diff = round4(mv.net - sums.net); // المتبقي
        if (Math.abs(diff) < 0.0001) { updateTreatBalance(); return; }
        // أضف/اضبط سطراً واحداً يحمل الفرق على الجهة الصحيحة.
        var target = null;
        for (var i = 0; i < state.gl_lines.length; i++) {
            var g = state.gl_lines[i];
            if ((parseFloat(g.debit) || 0) === 0 && (parseFloat(g.credit) || 0) === 0) { target = g; break; }
        }
        if (!target) { target = { account_id: 0, account_code: '', account_name: '', debit: 0, credit: 0 }; state.gl_lines.push(target); }
        if (diff > 0) { target.credit = round4((parseFloat(target.credit) || 0) + diff); target.debit = 0; }
        else { target.debit = round4((parseFloat(target.debit) || 0) - diff); target.credit = 0; }
        renderTreat();
        updateTreatBalance();
    }

    function updateTreatBalance() {
        var mv = invMovement();
        var sums = treatSums();
        // الإجماليات تشمل سطر المخزون التلقائي ليبدو القيد كاملاً مثل سند القيد.
        var totalDebit = round4(mv.inc + sums.debit);
        var totalCredit = round4(mv.dec + sums.credit);
        var info = el('stk_treat_info');
        if (info) {
            var dirTxt = mv.net > 0 ? ('مدين حساب المخزون بصافي ' + fmt(mv.net))
                : (mv.net < 0 ? ('دائن حساب المخزون بصافي ' + fmt(-mv.net)) : 'لا صافي قيمة');
            info.innerHTML = 'حركة المخزون التلقائية: <strong>' + esc(dirTxt) + '</strong>'
                + '<br>أكمل الطرف المقابل (أرباح/خسائر أو ذمة موظف) حتى يتوازن القيد (مجموع المدين = مجموع الدائن).';
        }
        var de = el('stk_treat_debit'); if (de) { de.textContent = fmt(totalDebit); }
        var ce = el('stk_treat_credit'); if (ce) { ce.textContent = fmt(totalCredit); }
        var be = el('stk_treat_balance');
        if (be) {
            var missingAcc = state.gl_lines.some(function (g) {
                var has = (parseFloat(g.debit) || 0) > 0 || (parseFloat(g.credit) || 0) > 0;
                return has && !((parseInt(g.account_id, 10) || 0) > 0);
            });
            var balanced = Math.abs(round4(totalDebit - totalCredit)) < 0.005;
            var ok = balanced && !missingAcc && Math.abs(mv.net) >= 0.0001;
            if (Math.abs(mv.net) < 0.0001 && Math.abs(sums.net) < 0.0001) {
                be.textContent = ''; be.className = 'stk-balance';
            } else if (missingAcc) {
                be.textContent = 'سطر معالجة بلا حساب'; be.className = 'stk-balance bad';
            } else if (ok) {
                be.textContent = '✔ القيد متوازن'; be.className = 'stk-balance ok';
            } else {
                be.textContent = '✖ غير متوازن (الفرق ' + fmt(round4(totalDebit - totalCredit)) + ')'; be.className = 'stk-balance bad';
            }
        }
    }

    function render() {
        var tb = el('stk_lines_body');
        if (!tb) { return; }
        tb.innerHTML = state.lines.map(rowHtml).join('');
        var emptyEl = el('stk_lines_empty');
        if (emptyEl) { emptyEl.style.display = state.lines.length ? 'none' : 'block'; }
        el('stk_number').value = state.id > 0 ? state.id : NEXT_NO;
        el('stk_ref').value = state.reference || STK_REF_PREVIEW;
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
        var ta = el('stk_treat_add'); if (ta) { ta.disabled = ro; }
        var tb = el('stk_treat_balance_btn'); if (tb) { tb.disabled = ro; }
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
            state.lines[idx].qty_add = a ? (parseInt(a.value, 10) || 0) : 0;
            state.lines[idx].qty_deduct = d ? (parseInt(d.value, 10) || 0) : 0;
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
        state.lines.push({ variant_id: 0, product_name: '', color: '', size: '', item_code: '', qty_system: 0, unit_cost: 0, qty_add: 0, qty_deduct: 0, treatment_account_id: 0, treatment_account_label: '' });
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
                        syncTreatFromInputs();
                        if (accIdx >= 0 && state.gl_lines[accIdx]) {
                            state.gl_lines[accIdx].account_id = a.id;
                            state.gl_lines[accIdx].account_code = a.code || '';
                            state.gl_lines[accIdx].account_name = a.name || '';
                        }
                        closeAccPicker();
                        renderTreat();
                        updateTreatBalance();
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
        syncTreatFromInputs();
        return {
            id: state.id || 0,
            document_date: (typeof orangeGetDmyValueAsIso === 'function') ? orangeGetDmyValueAsIso(el('stk_document_date')) : '',
            notes: el('stk_desc').value.trim(),
            lines: state.lines.filter(function (l) { return (parseInt(l.variant_id, 10) || 0) > 0; }).map(function (ln) {
                return {
                    variant_id: parseInt(ln.variant_id, 10) || 0,
                    qty_add: parseInt(ln.qty_add, 10) || 0,
                    qty_deduct: parseInt(ln.qty_deduct, 10) || 0
                };
            }),
            gl_lines: state.gl_lines.filter(function (g) {
                return (parseInt(g.account_id, 10) || 0) > 0 || (parseFloat(g.debit) || 0) > 0 || (parseFloat(g.credit) || 0) > 0;
            }).map(function (g) {
                return {
                    account_id: parseInt(g.account_id, 10) || 0,
                    debit: parseFloat(g.debit) || 0,
                    credit: parseFloat(g.credit) || 0,
                    memo: g.memo || ''
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
        state.reference = sv.reference || '';
        state.lines = sv.lines || [];
        state.gl_lines = sv.gl_lines || [];
        state.total_value = parseFloat(sv.total_value) || 0;
        browseId = state.id;
        if (typeof orangeIsoDateToDmy === 'function') { el('stk_document_date').value = state.document_date ? orangeIsoDateToDmy(state.document_date) : ''; }
        render();
        if (stkEditLockCtl && stkEditLockCtl.refresh) { stkEditLockCtl.refresh(); }
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
        syncTreatFromInputs();
        var mv = invMovement();
        var sums = treatSums();
        if (!state.gl_lines.length) { showErr('أضف أسطر المعالجة المحاسبية (مدين/دائن) في الكارت السفلي'); return; }
        if (state.gl_lines.some(function (g) {
            var has = (parseFloat(g.debit) || 0) > 0 || (parseFloat(g.credit) || 0) > 0;
            return has && !((parseInt(g.account_id, 10) || 0) > 0);
        })) { showErr('سطر معالجة بلا حساب — انقر نقرتين لاختياره'); return; }
        if (Math.abs(round4(sums.net - mv.net)) > 0.005) {
            showErr('أسطر المعالجة غير متوازنة مع قيمة المخزون — استخدم «موازنة تلقائية» أو عدّل المبالغ'); return;
        }
        if (!confirm('اعتماد السند وتطبيق التعديل على المخزون وترحيل قيد التسوية؟ لا يمكن التراجع.')) { return; }
        // احفظ آخر التعديلات أولاً ثم اعتمد (يضمن مطابقة أسطر المعالجة المخزّنة لما يراه المستخدم).
        doSave(function () {
            if (state.id <= 0) { return; }
            postJson(API.approve, { id: state.id }).then(function (d) {
                if (!d.success) { showErr(d.message || 'فشل الاعتماد'); return; }
                showOk(d.message || 'تم الاعتماد');
                applyVoucher(d.voucher);
            }).catch(function (e) { showErr(e.message || String(e)); });
        });
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
        state = { id: 0, document_date: '<?php echo $initial['document_date']; ?>', notes: '', status: 'draft', journal_voucher_id: 0, reference: '', lines: [], gl_lines: [], total_value: 0 };
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
            orangeAdminOpenPrintDialog(orangeAdminBuildVoucherPrintDocTitle(null, 'stk_number', 'قيد تسوية مخزون'));
        } else {
            window.print();
        }
    }

    el('stk_btn_add').addEventListener('click', addRow);
    el('stk_treat_add').addEventListener('click', addTreatRow);
    el('stk_treat_balance_btn').addEventListener('click', autoBalance);
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

    if (window.OrangeEditLock) {
        stkEditLockCtl = OrangeEditLock.bind({
            prefix: 'stk',
            docKind: 'stock_adjustment',
            page: 'stock_adjustment_voucher',
            countryId: <?php echo (int) $ctxCountryId; ?>,
            getEntityId: function () { return state.journal_voucher_id || 0; }
        });
    }

    render();
})();
</script>
