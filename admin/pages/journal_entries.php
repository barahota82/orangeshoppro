<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$nextJournalVoucherNo = 1;
if (orange_journal_vouchers_ready($pdo)) {
    $nextJournalVoucherNo = (int) $pdo->query('SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers')->fetchColumn();
}
$jvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$jvNavReady = orange_journal_vouchers_ready($pdo);
$jvHeaderLineClass = 'jv-voucher-header-line' . ($jvNavReady ? ' jv-voucher-header-line--nav' : '');
/** بادئة عرض رقم القيد اليدوي (القيمة المخزّنة في القاعدة تبقى رقماً صحيحاً فقط). */
$jvManualVoucherNoPrefix = 'M-';

$hasGrp = orange_table_has_column($pdo, 'accounts', 'is_group');
$accCols = $hasGrp ? 'id, name, code, is_group' : 'id, name, code';
$accounts = $pdo->query('SELECT ' . $accCols . ' FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);

$jvAccountsLeaf = [];
foreach ($accounts as $a) {
    if ($hasGrp && !empty($a['is_group'])) {
        continue;
    }
    $jvAccountsLeaf[] = [
        'id' => (int) $a['id'],
        'code' => (string) ($a['code'] ?? ''),
        'name' => (string) ($a['name'] ?? ''),
    ];
}
?>
<div class="page-title page-title--stacked jv-print-hide">
    <div>
        <h1>سند قيد</h1>
    </div>
</div>

<div class="card jv-print-area">
    <h3 class="card-title">سند قيد</h3>
    <div class="form-grid">
        <div class="<?php echo htmlspecialchars($jvHeaderLineClass, ENT_QUOTES, 'UTF-8'); ?>" style="grid-column:1/-1;">
            <div>
                <label for="jv_number_preview">رقم القيد</label>
                <input type="text" id="jv_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo htmlspecialchars($jvManualVoucherNoPrefix . (int) $nextJournalVoucherNo, ENT_QUOTES, 'UTF-8'); ?>"
                    title="يُخصَّص تلقائياً من النظام عند الحفظ (تسلسل قاعدة البيانات)"
                    dir="ltr" lang="en">
            </div>
            <div>
                <label for="jv_date">تاريخ السند</label>
                <input type="date" id="jv_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>"
                    title="تاريخ محاسبي للسند (يختاره المستخدم)" dir="ltr" lang="en">
            </div>
            <div>
                <label for="jv_ref">المرجع <span class="muted" style="font-weight:normal;">(اختياري)</span></label>
                <input type="text" id="jv_ref" autocomplete="off">
            </div>
            <div>
                <label for="jv_document_entered">تاريخ المستند</label>
                <input type="text" id="jv_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($jvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                    title="وقت تسجيل إدخال القيد في النظام — يُثبت عند الحفظ ولا يُقبل من المتصفح" dir="ltr" lang="en">
            </div>
            <div>
                <label for="jv_tot_debit">مجموع المدين</label>
                <input type="text" id="jv_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000"
                    title="إجمالي المدين من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <div>
                <label for="jv_tot_credit">مجموع الدائن</label>
                <input type="text" id="jv_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="0.000"
                    title="إجمالي الدائن من أسطر السند" dir="ltr" lang="en" inputmode="decimal">
            </div>
            <?php if ($jvNavReady): ?>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين سندات القيد اليدوية">
                    <button type="button" class="btn-secondary jv-nav-btn" id="jv_nav_first" title="أول سند" aria-label="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="jv_nav_prev" title="السند السابق (تنازلي)" aria-label="السند السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="jv_nav_next" title="السند التالي (تصاعدي)" aria-label="السند التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="jv_nav_last" title="آخر سند" aria-label="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="jv_btn_open_search" title="بحث عن سند داخل الشاشة">بحث</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div style="grid-column:1/-1;">
            <label for="jv_desc">البيان</label>
            <input type="text" id="jv_desc" placeholder="وصف السند">
        </div>
    </div>
    <div class="admin-doc-frame">
        <div class="table-wrap">
            <table class="admin-table admin-doc-lines-table jv-lines-table">
                <colgroup>
                    <col class="jv-col-code">
                    <col class="jv-col-name">
                    <col class="jv-col-amt">
                    <col class="jv-col-amt">
                    <col class="jv-col-act">
                </colgroup>
                <thead>
                    <tr>
                        <th>كود الحساب</th>
                        <th>اسم الحساب</th>
                        <th>مدين</th>
                        <th>دائن</th>
                        <th class="admin-doc-col-actions" aria-label="حذف السطر"></th>
                    </tr>
                </thead>
                <tbody id="jv_lines_body"></tbody>
            </table>
        </div>
    </div>
    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide">
        <button type="button" class="btn-secondary" id="jv_btn_add_line" onclick="jvAddRow()">+ سطر يدوي</button>
        <div class="jv-toolbar-primary-group">
            <button type="button" id="jv_btn_new_sheet" title="إدخال سند جديد">سند جديد</button>
            <button type="button" class="btn-secondary" id="jv_btn_delete_voucher" title="حذف السند المعروض" disabled>حذف السند</button>
            <button type="button" class="btn-secondary" id="jv_btn_print_voucher" title="طباعة السند">طباعة السند</button>
            <button type="button" id="jv_btn_save" onclick="jvSubmit()">حفظ السند</button>
        </div>
    </div>
</div>

<div id="jv_acct_picker" class="jv-acct-picker jv-print-hide" style="display:none;" aria-hidden="true">
    <label class="jv-acct-picker-label" for="jv_acct_picker_search">بحث</label>
    <input type="search" id="jv_acct_picker_search" class="jv-acct-picker-search admin-inp" placeholder="اكتب كلمات من الاسم أو الكود…" autocomplete="off" dir="auto">
    <div class="jv-acct-picker-scroll">
        <table class="admin-table jv-acct-picker-table">
            <thead>
                <tr>
                    <th>كود الحساب</th>
                    <th>اسم الحساب</th>
                </tr>
            </thead>
            <tbody id="jv_acct_picker_tbody"></tbody>
        </table>
    </div>
    <p class="jv-acct-picker-hint muted">نقرتان على صف لاختيار الحساب — Esc للإغلاق</p>
</div>

<?php if ($jvNavReady): ?>
<div id="jv_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="jv_search_modal_title">
    <div class="jv-search-modal__backdrop" id="jv_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="jv_search_modal_title" class="jv-search-modal__title">بحث في سندات القيد اليدوية</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="jv_search_id_from">رقم القيد — من</label>
                        <div class="jv-id-input-wrap" dir="ltr" lang="en">
                            <span class="jv-id-prefix"><?php echo htmlspecialchars($jvManualVoucherNoPrefix, ENT_QUOTES, 'UTF-8'); ?></span>
                            <input type="text" id="jv_search_id_from" class="admin-inp jv-search-id-num" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="" aria-label="رقم القيد — من (بدون البادئة)">
                        </div>
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="jv_search_id_to">رقم القيد — إلى</label>
                        <div class="jv-id-input-wrap" dir="ltr" lang="en">
                            <span class="jv-id-prefix"><?php echo htmlspecialchars($jvManualVoucherNoPrefix, ENT_QUOTES, 'UTF-8'); ?></span>
                            <input type="text" id="jv_search_id_to" class="admin-inp jv-search-id-num" inputmode="numeric" pattern="[0-9]*" autocomplete="off" placeholder="" aria-label="رقم القيد — إلى (بدون البادئة)">
                        </div>
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="jv_search_date_from">تاريخ السند — من</label>
                        <input type="date" id="jv_search_date_from" class="admin-inp" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="jv_search_date_to">تاريخ السند — إلى</label>
                        <input type="date" id="jv_search_date_to" class="admin-inp" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="jv_search_ref">المرجع (يحتوي النص)</label>
                        <input type="text" id="jv_search_ref" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--desc">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="jv_search_desc">بيان القيد العام (يحتوي النص)</label>
                        <input type="text" id="jv_search_desc" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="jv_search_run">تنفيذ البحث</button>
            </div>
            <div class="jv-search-modal__results">
                <div class="table-wrap jv-search-table-wrap">
                    <table class="admin-table jv-search-results-table">
                        <thead>
                            <tr>
                                <th>رقم</th>
                                <th>تاريخ السند</th>
                                <th>المرجع</th>
                                <th>البيان</th>
                                <th>مبلغ القيد</th>
                            </tr>
                        </thead>
                        <tbody id="jv_search_results_tbody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* كود الحساب كالسابق (~9rem)؛ اسم الحساب يتمدد؛ مدين/دائن أضيق لكن قابلة للإدخال */
.jv-lines-table { table-layout: fixed; width: 100%; }
.jv-lines-table col.jv-col-code { width: 9rem; }
.jv-lines-table col.jv-col-name { width: auto; }
.jv-lines-table col.jv-col-amt { width: 7rem; }
.jv-lines-table col.jv-col-act { width: 5rem; }
.jv-lines-table thead th { text-align: center; vertical-align: middle; }
.jv-lines-table .jv-acc-code {
    cursor: pointer;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.jv-lines-table .jv-acc-name {
    background: #f4f4f5;
    cursor: default;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.jv-lines-table .jv-line-main td:nth-child(3) .jv-d,
.jv-lines-table .jv-line-main td:nth-child(4) .jv-c {
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}
.jv-acct-picker {
    position: fixed;
    z-index: 10050;
    min-width: min(28rem, calc(100vw - 2rem));
    max-width: calc(100vw - 1rem);
    padding: 10px 12px;
    background: #fff;
    border: 1px solid #d4d4d8;
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0,0,0,.12);
    direction: rtl;
}
.jv-acct-picker-label { display: block; font-size: 0.85rem; margin-bottom: 4px; font-weight: 600; }
.jv-acct-picker-search { width: 100%; box-sizing: border-box; margin-bottom: 8px; }
.jv-acct-picker-scroll { max-height: 16rem; overflow: auto; border: 1px solid #e4e4e7; border-radius: 6px; }
.jv-acct-picker-table { margin: 0; font-size: 0.9rem; }
.jv-acct-picker-table thead th { position: sticky; top: 0; background: #fafafa; z-index: 1; }
.jv-acct-picker-table tbody tr { cursor: pointer; }
.jv-acct-picker-table tbody tr:hover { background: #f4f4f5; }
.jv-acct-picker-hint { margin: 8px 0 0; font-size: 0.8rem; }
.jv-lines-table tr.jv-line-memo td { padding-top: 6px; padding-bottom: 12px; border-bottom: 1px solid #e4e4e7; }
.jv-lines-table tr.jv-line-memo .jv-m { width: 100%; box-sizing: border-box; }
.jv-search-modal {
    position: fixed;
    inset: 0;
    z-index: 10060;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 16px;
    box-sizing: border-box;
    direction: rtl;
}
.jv-search-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.45);
}
.jv-search-modal__panel {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: min(96vw, 58rem);
    max-height: calc(100vh - 32px);
    overflow: auto;
    background: #fff;
    border: 1px solid #e4e4e7;
    border-radius: 10px;
    box-shadow: 0 20px 50px rgba(0,0,0,.18);
}
.jv-search-modal__head {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 14px 16px;
    border-bottom: 1px solid #e4e4e7;
}
.jv-search-modal__title { margin: 0; font-size: 1.05rem; text-align: center; }
.jv-search-modal__body { padding: 14px 16px 18px; }
.jv-search-modal__form {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 12px;
}
.jv-search-modal__row--fields {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    align-items: flex-end;
    gap: 10px;
    width: 100%;
    overflow-x: auto;
    box-sizing: border-box;
    padding-bottom: 2px;
}
.jv-search-modal__row--desc {
    width: 100%;
}
.jv-search-field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}
.jv-search-field label {
    font-size: 0.78rem;
    font-weight: 600;
    white-space: nowrap;
}
.jv-search-field input {
    width: 100%;
    box-sizing: border-box;
}
.jv-search-field--id {
    flex: 0 0 8.75rem;
}
.jv-id-input-wrap {
    display: flex;
    align-items: stretch;
    width: 100%;
    box-sizing: border-box;
    border: 1px solid #d4d4d8;
    border-radius: 6px;
    background: #fff;
    overflow: hidden;
}
.jv-id-input-wrap:focus-within {
    border-color: #a1a1aa;
    box-shadow: 0 0 0 1px rgba(24, 24, 27, 0.08);
}
.jv-id-prefix {
    display: flex;
    align-items: center;
    padding: 0 0.35rem 0 0.45rem;
    font-size: 0.9rem;
    font-weight: 600;
    color: #52525b;
    user-select: none;
    flex-shrink: 0;
    background: #fafafa;
    border-right: 1px solid #e4e4e7;
}
.jv-search-id-num {
    border: none !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    flex: 1 1 auto;
    min-width: 0;
    width: auto !important;
}
.jv-search-id-num:focus {
    outline: none;
}
.jv-search-field--date {
    flex: 0 0 11rem;
}
.jv-search-field--ref {
    flex: 1 1 0;
    min-width: 7rem;
}
.jv-search-field--full {
    width: 100%;
}
.jv-search-modal__actions { margin: 0 0 16px; }
.jv-search-table-wrap { max-height: min(40vh, 22rem); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
.jv-search-results-table { margin: 0; font-size: 0.9rem; }
.jv-search-results-table tbody tr { cursor: pointer; }
.jv-search-results-table tbody tr:hover { background: #f4f4f5; }
</style>

<script>
var JV_ACCOUNTS = <?php echo json_encode($jvAccountsLeaf, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var JV_MANUAL_NO_PREFIX = <?php echo json_encode($jvManualVoucherNoPrefix, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;

function jvFormatManualNo(id) {
    var n = parseInt(String(id), 10);
    if (!isFinite(n) || n <= 0) {
        return JV_MANUAL_NO_PREFIX;
    }
    return JV_MANUAL_NO_PREFIX + String(n);
}

function jvSearchNumericIdValue(el) {
    if (!el) {
        return 0;
    }
    var d = String(el.value || '').replace(/\D/g, '');
    return parseInt(d, 10) || 0;
}

var jvAcctPickerAnchor = null;
var jvPairSeq = 0;
var jvViewMode = false;
var jvBrowseId = null;

function jvMemoRow(mainTr) {
    if (!mainTr) {
        return null;
    }
    var pair = mainTr.getAttribute('data-jv-pair');
    var n = mainTr.nextElementSibling;
    if (n && n.classList.contains('jv-line-memo') && n.getAttribute('data-jv-pair') === pair) {
        return n;
    }
    return null;
}

function jvAllMainRows(tb) {
    return Array.prototype.slice.call(tb.querySelectorAll('tr.jv-line-main'));
}

function jvRemovePair(mainTr) {
    var memo = jvMemoRow(mainTr);
    if (memo) {
        memo.remove();
    }
    mainTr.remove();
}

function jvEscapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function jvAcctTokens(q) {
    return String(q || '').trim().toLowerCase().split(/\s+/).filter(Boolean);
}

/** بحث بالكلمات: كل كلمة يجب أن تظهر في (الكود + الاسم) — غير متطابق حرفي بالكامل */
function jvAcctFilterAccounts(q) {
    var tokens = jvAcctTokens(q);
    if (!tokens.length) {
        return JV_ACCOUNTS.slice();
    }
    return JV_ACCOUNTS.filter(function (a) {
        var hay = ((a.code || '') + ' ' + (a.name || '')).toLowerCase();
        return tokens.every(function (t) { return hay.indexOf(t) !== -1; });
    });
}

function jvAcctPickerPosition(anchorEl) {
    var box = document.getElementById('jv_acct_picker');
    if (!box || !anchorEl) {
        return;
    }
    var r = anchorEl.getBoundingClientRect();
    var margin = 8;
    var w = box.offsetWidth || 320;
    box.style.left = Math.max(margin, Math.min(r.left, window.innerWidth - w - margin)) + 'px';
    box.style.top = (r.top - margin) + 'px';
    box.style.transform = 'translateY(-100%)';
}

function jvAcctPickerRender() {
    var searchEl = document.getElementById('jv_acct_picker_search');
    var tb = document.getElementById('jv_acct_picker_tbody');
    if (!tb) {
        return;
    }
    var q = searchEl ? searchEl.value : '';
    var rows = jvAcctFilterAccounts(q);
    tb.innerHTML = '';
    rows.forEach(function (a) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td>' + jvEscapeHtml(a.code) + '</td><td>' + jvEscapeHtml(a.name) + '</td>';
        tr.addEventListener('dblclick', function () { jvAcctPickerApply(a); });
        tb.appendChild(tr);
    });
}

function jvAcctPickerClose() {
    var box = document.getElementById('jv_acct_picker');
    if (box) {
        box.style.display = 'none';
        box.setAttribute('aria-hidden', 'true');
    }
    jvAcctPickerAnchor = null;
}

function jvAcctPickerApply(a) {
    if (!jvAcctPickerAnchor || !a) {
        jvAcctPickerClose();
        return;
    }
    var tr = jvAcctPickerAnchor.closest('tr');
    if (tr) {
        tr.querySelector('.jv-acc-id').value = String(a.id);
        tr.querySelector('.jv-acc-code').value = a.code || '';
        tr.querySelector('.jv-acc-name').value = a.name || '';
    }
    jvAcctPickerClose();
    jvSyncTrailingRows();
    jvRecalc();
}

function jvAcctPickerOpen(anchorInput) {
    if (jvViewMode) {
        return;
    }
    var box = document.getElementById('jv_acct_picker');
    var searchEl = document.getElementById('jv_acct_picker_search');
    if (!box || !searchEl) {
        return;
    }
    jvAcctPickerAnchor = anchorInput;
    searchEl.value = '';
    jvAcctPickerRender();
    box.style.display = 'block';
    box.setAttribute('aria-hidden', 'false');
    box.style.transform = '';
    jvAcctPickerPosition(anchorInput);
    requestAnimationFrame(function () {
        jvAcctPickerPosition(anchorInput);
        searchEl.focus();
        searchEl.select();
    });
}

function jvAcctPickerOnDocMouseDown(ev) {
    var box = document.getElementById('jv_acct_picker');
    if (!box || box.style.display === 'none') {
        return;
    }
    var t = ev.target;
    if (box.contains(t)) {
        return;
    }
    if (jvAcctPickerAnchor && (t === jvAcctPickerAnchor || jvAcctPickerAnchor.contains(t))) {
        return;
    }
    jvAcctPickerClose();
}

function jvAcctPickerOnKey(ev) {
    if (ev.key === 'Escape') {
        var sm = document.getElementById('jv_search_modal');
        if (sm && sm.style.display === 'flex') {
            jvSearchModalClose();
            return;
        }
        jvAcctPickerClose();
    }
}

function jvSearchModalOnDocMouseDown(ev) {
    var m = document.getElementById('jv_search_modal');
    if (!m || m.style.display !== 'flex') {
        return;
    }
    var t = ev.target;
    if (t.closest && t.closest('#jv_btn_open_search')) {
        return;
    }
    var panel = m.querySelector('.jv-search-modal__panel');
    if (panel && (panel === t || panel.contains(t))) {
        return;
    }
    jvSearchModalClose();
}

document.addEventListener('mousedown', jvSearchModalOnDocMouseDown, true);
document.addEventListener('mousedown', jvAcctPickerOnDocMouseDown, true);
document.addEventListener('keydown', jvAcctPickerOnKey, true);

function jvSearchModalClose() {
    var m = document.getElementById('jv_search_modal');
    if (!m) {
        return;
    }
    m.style.display = 'none';
    m.setAttribute('aria-hidden', 'true');
}

function jvSearchModalOpen() {
    var m = document.getElementById('jv_search_modal');
    if (!m) {
        return;
    }
    m.style.display = 'flex';
    m.setAttribute('aria-hidden', 'false');
    var tb0 = document.getElementById('jv_search_results_tbody');
    if (tb0) {
        tb0.innerHTML = '';
    }
}

function jvSearchCollectPayload() {
    return {
        action: 'search_manual',
        id_from: jvSearchNumericIdValue(document.getElementById('jv_search_id_from')),
        id_to: jvSearchNumericIdValue(document.getElementById('jv_search_id_to')),
        date_from: document.getElementById('jv_search_date_from').value.trim(),
        date_to: document.getElementById('jv_search_date_to').value.trim(),
        reference: document.getElementById('jv_search_ref').value.trim(),
        description: document.getElementById('jv_search_desc').value.trim()
    };
}

function jvSearchRenderRows(rows) {
    var tb = document.getElementById('jv_search_results_tbody');
    if (!tb) {
        return;
    }
    tb.innerHTML = '';
    (rows || []).forEach(function (r) {
        var tr = document.createElement('tr');
        tr.setAttribute('data-vid', String(r.id));
        var amt = typeof r.amount === 'number' ? r.amount : parseFloat(String(r.amount || '0').replace(',', '.')) || 0;
        tr.innerHTML = '<td dir="ltr">' + jvEscapeHtml(jvFormatManualNo(r.id)) + '</td>' +
            '<td dir="ltr">' + jvEscapeHtml(r.voucher_date) + '</td>' +
            '<td>' + jvEscapeHtml(r.reference) + '</td>' +
            '<td class="jv-search-col-desc">' + jvEscapeHtml(r.description) + '</td>' +
            '<td dir="ltr">' + jvEscapeHtml(amt.toFixed(3)) + '</td>';
        tr.addEventListener('dblclick', function () {
            var vid = parseInt(tr.getAttribute('data-vid'), 10) || 0;
            if (vid > 0) {
                jvLoadVoucherFromApi(vid);
            }
        });
        tb.appendChild(tr);
    });
}

function jvSearchRun() {
    postJSON('/admin/api/journal/manage.php', jvSearchCollectPayload()).then(function (r) {
        if (!r.success) {
            if (!orangeAdminOfferSuggestOnFailure(r, 'بحث')) {
                alert(r.message || 'فشل البحث');
            }
            jvSearchRenderRows([]);
            return;
        }
        jvSearchRenderRows(r.rows || []);
    }).catch(function (e) {
        alert(e.message || String(e));
        jvSearchRenderRows([]);
    });
}

function jvSearchModalBind() {
    var openB = document.getElementById('jv_btn_open_search');
    if (openB) {
        openB.addEventListener('click', function () {
            var sm = document.getElementById('jv_search_modal');
            if (sm && sm.style.display === 'flex') {
                jvSearchModalClose();
            } else {
                jvSearchModalOpen();
            }
        });
    }
    var runB = document.getElementById('jv_search_run');
    if (runB) {
        runB.addEventListener('click', jvSearchRun);
    }
    ['jv_search_id_from', 'jv_search_id_to'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el || el.getAttribute('data-jv-digits-only')) {
            return;
        }
        el.setAttribute('data-jv-digits-only', '1');
        el.addEventListener('input', function () {
            var d = String(el.value || '').replace(/\D/g, '');
            if (el.value !== d) {
                el.value = d;
            }
        });
    });
}
jvSearchModalBind();

(function jvAcctPickerSearchBind() {
    var searchEl = document.getElementById('jv_acct_picker_search');
    if (searchEl && !searchEl.getAttribute('data-jv-bound')) {
        searchEl.setAttribute('data-jv-bound', '1');
        searchEl.addEventListener('input', function () { jvAcctPickerRender(); });
    }
})();

function jvAddRow() {
    var tb = document.getElementById('jv_lines_body');
    var pair = 'jv' + String(++jvPairSeq);
    var trMain = document.createElement('tr');
    trMain.className = 'jv-line-main';
    trMain.setAttribute('data-jv-pair', pair);
    trMain.innerHTML = '<td class="jv-acc-code-cell">' +
        '<input type="hidden" class="jv-acc-id" value="">' +
        '<input type="text" class="jv-acc-code admin-inp" value="" placeholder="نقرتان للاختيار" readonly autocomplete="off" title="نقرتان لفتح قائمة الحسابات">' +
        '</td>' +
        '<td><input type="text" class="jv-acc-name admin-inp" value="" readonly tabindex="-1" placeholder="—" title="يُعبأ تلقائياً"></td>' +
        '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr"></td>' +
        '<td><button type="button" class="btn-secondary admin-doc-line-remove" onclick="jvRemoveRow(this)">حذف</button></td>';
    var trMemo = document.createElement('tr');
    trMemo.className = 'jv-line-memo';
    trMemo.setAttribute('data-jv-pair', pair);
    trMemo.innerHTML = '<td colspan="5">' +
        '<input type="text" id="jv_m_' + pair + '" class="jv-m admin-inp" value="" placeholder="البيان" autocomplete="off">' +
        '</td>';
    var codeInp = trMain.querySelector('.jv-acc-code');
    codeInp.addEventListener('dblclick', function (e) { e.preventDefault(); jvAcctPickerOpen(codeInp); });
    tb.appendChild(trMain);
    tb.appendChild(trMemo);
    jvRecalc();
}

function jvRemoveRow(btn) {
    var tb = document.getElementById('jv_lines_body');
    var main = btn.closest('tr.jv-line-main');
    if (!main) {
        return;
    }
    if (jvAllMainRows(tb).length <= 1) {
        var memo = jvMemoRow(main);
        main.querySelector('.jv-acc-id').value = '';
        main.querySelector('.jv-acc-code').value = '';
        main.querySelector('.jv-acc-name').value = '';
        main.querySelectorAll('.jv-d,.jv-c').forEach(function (el) { el.value = ''; });
        if (memo) {
            var mi = memo.querySelector('.jv-m');
            if (mi) {
                mi.value = '';
            }
        }
        jvSyncTrailingRows();
        jvRecalc();
        return;
    }
    jvRemovePair(main);
    jvSyncTrailingRows();
    jvRecalc();
}

function jvRowIsBlank(mainTr) {
    if (!mainTr || !mainTr.classList.contains('jv-line-main')) {
        return true;
    }
    var acc = parseInt(mainTr.querySelector('.jv-acc-id').value, 10) || 0;
    var deb = parseFloat(String(mainTr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
    var cre = parseFloat(String(mainTr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
    var memoTr = jvMemoRow(mainTr);
    var memo = memoTr ? memoTr.querySelector('.jv-m').value.trim() : '';
    return acc <= 0 && deb <= 0 && cre <= 0 && memo === '';
}

function jvTrimExtraTrailingBlanks() {
    var tb = document.getElementById('jv_lines_body');
    for (;;) {
        var mains = jvAllMainRows(tb);
        if (mains.length < 2) {
            return;
        }
        var a = mains[mains.length - 2];
        var b = mains[mains.length - 1];
        if (jvRowIsBlank(a) && jvRowIsBlank(b)) {
            jvRemovePair(a);
        } else {
            return;
        }
    }
}

function jvSyncTrailingRows() {
    if (jvViewMode) {
        return;
    }
    jvTrimExtraTrailingBlanks();
    var tb = document.getElementById('jv_lines_body');
    var mains = jvAllMainRows(tb);
    if (mains.length === 0) {
        jvAddRow();
        return;
    }
    var last = mains[mains.length - 1];
    if (!jvRowIsBlank(last)) {
        jvAddRow();
    }
}

function jvBindLinesBody() {
    var tb = document.getElementById('jv_lines_body');
    if (!tb || tb.getAttribute('data-jv-bound') === '1') {
        return;
    }
    tb.setAttribute('data-jv-bound', '1');
    tb.addEventListener('input', function () {
        jvSyncTrailingRows();
        jvRecalc();
    });
    tb.addEventListener('change', function () {
        jvSyncTrailingRows();
        jvRecalc();
    });
    tb.addEventListener('keydown', function (e) {
        if (e.key !== 'Tab' || e.shiftKey) {
            return;
        }
        var ta = e.target;
        if (!ta || !ta.closest) {
            return;
        }
        var tr = ta.closest('tr');
        if (!tr || tr.parentElement !== tb) {
            return;
        }
        if (!tr.classList.contains('jv-line-memo') || !ta.classList.contains('jv-m')) {
            return;
        }
        var mains = jvAllMainRows(tb);
        var lastMain = mains[mains.length - 1];
        var lastMemo = lastMain ? jvMemoRow(lastMain) : null;
        if (!lastMemo || tr !== lastMemo) {
            return;
        }
        e.preventDefault();
        jvSyncTrailingRows();
        var mains2 = jvAllMainRows(tb);
        var nextMain = mains2[mains2.length - 1];
        var codeInp = nextMain && nextMain.querySelector('.jv-acc-code');
        if (codeInp) {
            codeInp.focus();
        }
    });
}

function jvClearLinesBody() {
    var tb = document.getElementById('jv_lines_body');
    if (tb) {
        tb.innerHTML = '';
    }
    jvPairSeq = 0;
}

function jvApplyViewModeUi() {
    var ro = jvViewMode;
    ['jv_date', 'jv_ref', 'jv_desc'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) {
            el.readOnly = ro;
        }
    });
    var saveBtn = document.getElementById('jv_btn_save');
    if (saveBtn) {
        saveBtn.disabled = ro;
    }
    var addLineBtn = document.getElementById('jv_btn_add_line');
    if (addLineBtn) {
        addLineBtn.disabled = ro;
    }
    var delVBtn = document.getElementById('jv_btn_delete_voucher');
    if (delVBtn) {
        delVBtn.disabled = !jvBrowseId;
    }
    document.querySelectorAll('#jv_lines_body input').forEach(function (inp) {
        inp.readOnly = ro;
    });
    document.querySelectorAll('#jv_lines_body .admin-doc-line-remove').forEach(function (bt) {
        bt.disabled = ro;
        bt.style.visibility = ro ? 'hidden' : '';
    });
}

function jvApplyVoucherPayload(r) {
    if (!r || !r.voucher) {
        return;
    }
    jvViewMode = true;
    jvBrowseId = r.voucher.id;
    document.getElementById('jv_number_preview').value = jvFormatManualNo(r.voucher.id);
    document.getElementById('jv_date').value = r.voucher.date || '';
    document.getElementById('jv_ref').value = r.voucher.reference || '';
    document.getElementById('jv_desc').value = r.voucher.description || '';
    document.getElementById('jv_document_entered').value = r.voucher.document_entered_display || '';
    jvClearLinesBody();
    (r.lines || []).forEach(function (ln) {
        jvAddRow();
        var mains = jvAllMainRows(document.getElementById('jv_lines_body'));
        var main = mains[mains.length - 1];
        if (!main) {
            return;
        }
        var memo = jvMemoRow(main);
        main.querySelector('.jv-acc-id').value = String(ln.account_id);
        main.querySelector('.jv-acc-code').value = ln.code || '';
        main.querySelector('.jv-acc-name').value = ln.name || '';
        var deb = parseFloat(String(ln.debit || 0));
        var cre = parseFloat(String(ln.credit || 0));
        main.querySelector('.jv-d').value = deb > 0 ? deb.toFixed(3) : '';
        main.querySelector('.jv-c').value = cre > 0 ? cre.toFixed(3) : '';
        if (memo) {
            memo.querySelector('.jv-m').value = ln.memo || '';
        }
    });
    jvApplyViewModeUi();
    jvRecalc();
    jvSearchModalClose();
}

function jvLoadVoucherFromApi(id) {
    postJSON('/admin/api/journal/manage.php', { action: 'get', id: id }).then(function (r) {
        if (!r.success || !r.voucher) {
            if (!orangeAdminOfferSuggestOnFailure(r, 'تعذر العرض')) {
                alert(r.message || 'فشل');
            }
            return;
        }
        jvApplyVoucherPayload(r);
    }).catch(function (e) { alert(e.message || String(e)); });
}

function jvDeleteVoucher() {
    if (!jvBrowseId) {
        alert('لا يوجد سند محفوظ للحذف');
        return;
    }
    if (!confirm('تأكيد حذف هذا السند؟ لا يمكن التراجع.')) {
        return;
    }
    postJSON('/admin/api/journal/manage.php', { action: 'delete', id: jvBrowseId }).then(function (r) {
        if (r.success) {
            alert(r.message || 'تم الحذف');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(r, 'فشل الحذف')) {
            alert(r.message || 'فشل');
        }
    }).catch(function (e) { alert(e.message || String(e)); });
}

function jvPrintVoucher() {
    window.print();
}

function jvNav(where) {
    postJSON('/admin/api/journal/manage.php', {
        action: 'nav_manual',
        where: where,
        current_id: jvBrowseId || 0
    }).then(function (r) {
        if (!r.success || !r.id) {
            alert(r.message || 'لا يمكن التنقل');
            return;
        }
        jvLoadVoucherFromApi(r.id);
    }).catch(function (e) { alert(e.message || String(e)); });
}

function jvRecalc() {
    var sd = 0, sc = 0;
    document.querySelectorAll('#jv_lines_body tr.jv-line-main').forEach(function (tr) {
        var d = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var c = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        sd += d; sc += c;
    });
    var elD = document.getElementById('jv_tot_debit');
    var elC = document.getElementById('jv_tot_credit');
    if (elD) {
        elD.value = sd.toFixed(3);
    }
    if (elC) {
        elC.value = sc.toFixed(3);
    }
}

function jvSubmit() {
    if (jvViewMode) {
        return;
    }
    var d = document.getElementById('jv_date').value;
    var ref = document.getElementById('jv_ref').value.trim();
    var desc = document.getElementById('jv_desc').value.trim();
    if (!d || !desc) {
        alert('التاريخ والبيان مطلوبان');
        return;
    }
    var lines = [];
    var memoAbort = false;
    document.querySelectorAll('#jv_lines_body tr.jv-line-main').forEach(function (tr) {
        var acc = parseInt(tr.querySelector('.jv-acc-id').value, 10) || 0;
        var deb = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.'));
        var cre = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.'));
        var memoTr = jvMemoRow(tr);
        var memo = memoTr ? memoTr.querySelector('.jv-m').value.trim() : '';
        if (acc <= 0) return;
        if (deb > 0 && cre > 0) {
            cre = 0;
        }
        if (deb <= 0 && cre <= 0) return;
        if (memo === '') {
            alert('البيان مطلوب لكل سطر يحتوي مبلغاً');
            memoAbort = true;
            return;
        }
        lines.push({ account_id: acc, debit: deb, credit: cre, memo: memo });
    });
    if (memoAbort) {
        return;
    }
    if (lines.length < 2) {
        alert('أضف سطرين على الأقل بمبالغ صحيحة');
        return;
    }
    var sd = lines.reduce(function (a, x) { return a + x.debit; }, 0);
    var sc = lines.reduce(function (a, x) { return a + x.credit; }, 0);
    if (Math.abs(sd - sc) > 0.001) {
        alert('السند غير متوازن');
        return;
    }
    postJSON('/admin/api/journal/manage.php', {
        action: 'create',
        date: d,
        reference: ref,
        description: desc,
        entry_type: 'manual',
        lines: lines
    }).then(function (r) {
        if (r.success) {
            alert(r.message || 'تم');
            location.reload();
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(r, 'فشل')) {
            alert(r.message || 'فشل');
        }
    }).catch(function (e) { alert(e.message || String(e)); });
}

jvAddRow();
jvBindLinesBody();
jvSyncTrailingRows();

(function jvNavBind() {
    var map = [
        ['jv_nav_first', 'first'],
        ['jv_nav_prev', 'prev'],
        ['jv_nav_next', 'next'],
        ['jv_nav_last', 'last']
    ];
    map.forEach(function (pair) {
        var b = document.getElementById(pair[0]);
        if (b) {
            b.addEventListener('click', function () { jvNav(pair[1]); });
        }
    });
    var nb = document.getElementById('jv_btn_new_sheet');
    if (nb) {
        nb.addEventListener('click', function () { location.reload(); });
    }
    var db = document.getElementById('jv_btn_delete_voucher');
    if (db) {
        db.addEventListener('click', jvDeleteVoucher);
    }
    var pb = document.getElementById('jv_btn_print_voucher');
    if (pb) {
        pb.addEventListener('click', jvPrintVoucher);
    }
})();
</script>
