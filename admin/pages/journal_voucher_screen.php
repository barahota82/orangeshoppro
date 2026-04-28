<?php

declare(strict_types=1);

if (!isset($jvPageEntryType, $jvPageTitle, $jvPageCardTitle, $jvSearchModalTitle)) {
    throw new RuntimeException('journal_voucher_screen: missing $jvPage* variables.');
}

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$jvCashLock = null;
$jvPageEt = (string) ($jvPageEntryType ?? '');
if ($jvPageEt === 'receipt_voucher' || $jvPageEt === 'payment_voucher') {
    $cashAccId = orange_gl_account_id_optional($pdo, 'cash');
    if ($cashAccId !== null && $cashAccId > 0) {
        $stCash = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $stCash->execute([(int) $cashAccId]);
        $cashRow = $stCash->fetch(PDO::FETCH_ASSOC);
        if ($cashRow) {
            $jvCashLock = [
                'id' => (int) $cashRow['id'],
                'code' => (string) ($cashRow['code'] ?? ''),
                'name' => (string) ($cashRow['name'] ?? ''),
                'placement' => $jvPageEt === 'receipt_voucher' ? 'first' : 'last',
            ];
        }
    }
}
$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');

$jvPostingLinkedJournalTypes = [];
if ($jvPageEt === 'other_voucher') {
    $jvPostingLinkedJournalTypes = orange_gl_posting_linked_journal_types($pdo);
}

$nextJournalVoucherNo = 1;
if (orange_journal_vouchers_ready($pdo)) {
    orange_journal_types_sync_canonical_defaults($pdo);
    $fyPeek = orange_fiscal_find_for_date($pdo, date('Y-m-d'));
    $fyPeekId = $fyPeek ? (int) $fyPeek['id'] : 0;
    $etPeek = isset($jvPageEntryType) ? (string) $jvPageEntryType : '';
    if (
        $fyPeekId > 0
        && $etPeek !== ''
        && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
    ) {
        if ($etPeek === 'other_voucher') {
            $nextJournalVoucherNo = (int) $pdo->query(
                'SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers'
            )->fetchColumn();
        } else {
            $jm = orange_journal_voucher_resolve_serial_meta($pdo, $etPeek, null);
            $nextJournalVoucherNo = orange_journal_voucher_next_serial($pdo, $fyPeekId, $jm['journal_serial_bucket']);
        }
    } else {
        $nextJournalVoucherNo = (int) $pdo->query(
            'SELECT COALESCE(MAX(id),0) + 1 FROM journal_vouchers'
        )->fetchColumn();
    }
}
$jvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$jvFormVoucherDateDisplay = orange_format_date_dmY(date('Y-m-d'));
$jvNavReady = orange_journal_vouchers_ready($pdo);
$jvHeaderLineClass = 'jv-voucher-header-line' . ($jvNavReady ? ' jv-voucher-header-line--nav' : '');
?>
<div class="page-title page-title--stacked jv-print-hide">
    <div>
        <h1><?php echo htmlspecialchars($jvPageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>
</div>

<div class="card jv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($jvPageCardTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php if ((($jvPageEntryType ?? '') === 'receipt_voucher' || ($jvPageEntryType ?? '') === 'payment_voucher') && $jvCashLock === null): ?>
    <p class="card-hint jv-print-hide" style="margin:0 0 12px;">اربط حساب <strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a> (بند النقدية) لاستخدام سند القبض أو سند الصرف بسطر خزينة ثابت.</p>
    <?php endif; ?>
    <div class="form-grid">
        <?php if ($jvPageEt === 'other_voucher'): ?>
        <div class="jv-other-voucher-filter-row jv-print-hide" style="grid-column:1/-1;display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;margin-bottom:6px;">
            <div style="display:flex;align-items:center;gap:8px;">
                <label for="jv_journal_type_filter" style="margin:0;font-weight:600;white-space:nowrap;">نوع القيد (اليومية)</label>
                <select id="jv_journal_type_filter" class="admin-inp" style="min-width:15rem;"<?php echo $jvPostingLinkedJournalTypes === [] ? ' disabled' : ''; ?>>
                    <option value=""><?php echo $jvPostingLinkedJournalTypes === [] ? '— لا توجد أنواع يومية في الإعدادات —' : '— اختر نوع اليومية —'; ?></option>
                    <?php foreach ($jvPostingLinkedJournalTypes as $jt): ?>
                    <option value="<?php echo (int) ($jt['id'] ?? 0); ?>"><?php echo htmlspecialchars(trim((string) ($jt['name_ar'] ?? $jt['code'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($jvPostingLinkedJournalTypes === []): ?>
        <p class="card-hint jv-print-hide" style="grid-column:1/-1;margin:0 0 8px;">لا توجد أنواع يومية مستخرجة من قواعد الترحيل — راجع قسم ربط نوع اليومية في <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
        <?php endif; ?>
        <?php endif; ?>
        <div class="<?php echo htmlspecialchars($jvHeaderLineClass, ENT_QUOTES, 'UTF-8'); ?>" style="grid-column:1/-1;">
            <div>
                <label for="jv_number_preview">رقم القيد</label>
                <input type="text" id="jv_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="<?php echo (int) $nextJournalVoucherNo; ?>"
                    title="يُخصَّص تلقائياً من النظام عند الحفظ (تسلسل قاعدة البيانات)">
            </div>
            <div>
                <label for="jv_date">تاريخ السند</label>
                <input type="text" id="jv_date" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($jvFormVoucherDateDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                    title="تاريخ محاسبي للسند — يوم/شهر/سنة" dir="ltr" lang="en" autocomplete="off">
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
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
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

<div class="gl-pick-modal jv-print-hide" id="jv_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="jv_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="jv_pick_title">
        <h3 id="jv_pick_title" class="gl-pick-modal__title">اختيار حساب فرعي</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان على حقل كود الحساب لفتح هذه القائمة — انقر صفاً للاختيار — Esc للإغلاق</p>
        <input type="search" id="jv_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="jv_pick_list"></ul>
        <button type="button" class="btn-secondary" id="jv_pick_close">إغلاق</button>
    </div>
</div>

<?php if ($jvNavReady): ?>
<div id="jv_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="jv_search_modal_title">
    <div class="jv-search-modal__backdrop" id="jv_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="jv_search_modal_title" class="jv-search-modal__title"><?php echo htmlspecialchars($jvSearchModalTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="jv_search_id_from">رقم القيد — من</label>
                        <input type="number" id="jv_search_id_from" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="jv_search_id_to">رقم القيد — إلى</label>
                        <input type="number" id="jv_search_id_to" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="jv_search_date_from">تاريخ السند — من</label>
                        <input type="text" id="jv_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="jv_search_date_to">تاريخ السند — إلى</label>
                        <input type="text" id="jv_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
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
                                <?php if ($jvPageEt === 'other_voucher'): ?>
                                <th>نوع القيد</th>
                                <?php endif; ?>
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
/* أعمدة الجدول المشتركة مع أرصدة أول المدة: admin/assets/admin.css (.jv-lines-table) */
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
.gl-pick-modal#jv_pick_modal {
    z-index: 12100;
}
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
    flex: 0 0 7rem;
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
var JV_ENTRY_TYPE = <?php echo json_encode($jvPageEntryType, JSON_UNESCAPED_UNICODE); ?>;
var JV_OTHER_VOUCHER_BROWSE = <?php echo $jvPageEt === 'other_voucher' ? 'true' : 'false'; ?>;
var JV_CASH_LOCK = <?php echo json_encode($jvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;

function jvCashLockActive() {
    return !!(JV_CASH_LOCK && JV_CASH_LOCK.id);
}

function jvCashLockPlacement() {
    return (JV_CASH_LOCK && JV_CASH_LOCK.placement) ? String(JV_CASH_LOCK.placement) : '';
}

function jvCashLockIsReceipt() {
    return jvCashLockActive() && jvCashLockPlacement() === 'first';
}

function jvCashLockIsPayment() {
    return jvCashLockActive() && jvCashLockPlacement() === 'last';
}

function jvLastManualMainRow(mains) {
    for (var i = mains.length - 1; i >= 0; i--) {
        if (mains[i].getAttribute('data-jv-cash-locked') !== '1') {
            return mains[i];
        }
    }
    return null;
}

/** قبض: مدين الخزينة = مجموع دائن الآخرين. صرف: دائن الخزينة = مجموع مدين الآخرين. */
function jvCashLockSyncTreasuryAmount() {
    if (!jvCashLockActive()) {
        return;
    }
    var tb = document.getElementById('jv_lines_body');
    if (!tb) {
        return;
    }
    var cashTr = tb.querySelector('tr.jv-line-main[data-jv-cash-locked="1"]');
    if (!cashTr) {
        return;
    }
    var dEl = cashTr.querySelector('.jv-d');
    var cEl = cashTr.querySelector('.jv-c');
    if (!dEl || !cEl) {
        return;
    }
    if (jvCashLockIsReceipt()) {
        var sumCre = 0;
        jvAllMainRows(tb).forEach(function (tr) {
            if (tr.getAttribute('data-jv-cash-locked') === '1') {
                return;
            }
            sumCre += parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
        });
        dEl.value = sumCre > 0 ? sumCre.toFixed(3) : '';
        cEl.value = '0.000';
    } else {
        var sumDeb = 0;
        jvAllMainRows(tb).forEach(function (tr) {
            if (tr.getAttribute('data-jv-cash-locked') === '1') {
                return;
            }
            sumDeb += parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
        });
        dEl.value = '0.000';
        cEl.value = sumDeb > 0 ? sumDeb.toFixed(3) : '';
    }
}

function jvCashLockApplyLineAmountUi(mainTr) {
    if (!mainTr || !jvCashLockActive()) {
        return;
    }
    var dEl = mainTr.querySelector('.jv-d');
    var cEl = mainTr.querySelector('.jv-c');
    if (!dEl || !cEl) {
        return;
    }
    if (mainTr.getAttribute('data-jv-cash-locked') === '1') {
        if (jvCashLockIsReceipt()) {
            dEl.readOnly = true;
            dEl.setAttribute('tabindex', '-1');
            dEl.classList.add('admin-inp-readonly');
            dEl.title = 'يُحسب تلقائياً من مجموع دائن الحسابات الأخرى';
            cEl.readOnly = true;
            cEl.setAttribute('tabindex', '-1');
            cEl.value = '0.000';
        } else {
            cEl.readOnly = true;
            cEl.setAttribute('tabindex', '-1');
            cEl.classList.add('admin-inp-readonly');
            cEl.title = 'يُحسب تلقائياً من مجموع مدين الحسابات الأخرى';
            dEl.readOnly = true;
            dEl.setAttribute('tabindex', '-1');
            dEl.classList.add('admin-inp-readonly');
            dEl.value = '0.000';
        }
        return;
    }
    if (jvCashLockIsReceipt()) {
        dEl.value = '';
        dEl.readOnly = true;
        dEl.setAttribute('tabindex', '-1');
        dEl.classList.add('admin-inp-readonly');
        dEl.title = 'في سند القبض يُسجَّل الدائن فقط';
        cEl.readOnly = false;
        cEl.removeAttribute('tabindex');
        cEl.classList.remove('admin-inp-readonly');
        return;
    }
    cEl.value = '';
    cEl.readOnly = true;
    cEl.setAttribute('tabindex', '-1');
    cEl.classList.add('admin-inp-readonly');
    cEl.title = 'في سند الصرف يُسجَّل المدين فقط';
    dEl.readOnly = false;
    dEl.removeAttribute('tabindex');
    dEl.classList.remove('admin-inp-readonly');
}

var jvAcctPickerAnchor = null;
var jvPickSeq = 0;
var jvPickSearchTimer = null;
var jvPairSeq = 0;
var jvViewMode = false;
var jvBrowseId = null;
var jvBrowseEntryType = null;

function jvJournalTypeFilterId() {
    var s = document.getElementById('jv_journal_type_filter');
    if (!s) {
        return 0;
    }
    return parseInt(String(s.value || '0'), 10) || 0;
}

function jvOtherVoucherBrowseBlockedMsg() {
    return 'اختر نوع اليومية من الفلتر أولاً. لا يُسمح بعرض أو بحث كل القيود دفعة واحدة.';
}

function jvOtherVoucherBrowseOk() {
    if (!JV_OTHER_VOUCHER_BROWSE) {
        return true;
    }
    return jvJournalTypeFilterId() > 0;
}

function jvApplyOtherVoucherBrowseGateUi() {
    if (!JV_OTHER_VOUCHER_BROWSE) {
        return;
    }
    var ok = jvOtherVoucherBrowseOk();
    ['jv_nav_first', 'jv_nav_prev', 'jv_nav_next', 'jv_nav_last', 'jv_btn_open_search'].forEach(function (id) {
        var b = document.getElementById(id);
        if (b) {
            b.disabled = !ok;
        }
    });
}

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
    if (mainTr && mainTr.getAttribute('data-jv-cash-locked') === '1') {
        return;
    }
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

function jvAcctPickerExcludeCashId(acc) {
    if (!jvCashLockActive() || !JV_CASH_LOCK || !JV_CASH_LOCK.id) {
        return false;
    }
    var cid = parseInt(String(JV_CASH_LOCK.id), 10) || 0;
    var aid = parseInt(String(acc.id), 10) || 0;
    return cid > 0 && aid === cid;
}

function jvAcctPickerLoadIntoList(q) {
    var mySeq = ++jvPickSeq;
    var url = '/admin/api/accounts/search-leaves.php?q=' + encodeURIComponent(q || '');
    var pickList = document.getElementById('jv_pick_list');
    if (!pickList) {
        return;
    }
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (mySeq !== jvPickSeq) {
                return;
            }
            if (!data.success) {
                pickList.innerHTML = '<li class="gl-pick-empty">' + (data.message || 'تعذر التحميل') + '</li>';
                return;
            }
            var accs = (data.accounts || []).filter(function (a) { return !jvAcctPickerExcludeCashId(a); });
            if (accs.length === 0) {
                pickList.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
                return;
            }
            pickList.innerHTML = '';
            accs.forEach(function (a) {
                var li = document.createElement('li');
                li.className = 'gl-pick-item';
                var code = a.code || '';
                li.textContent = (code ? code + ' — ' : '') + (a.name || '');
                li.setAttribute('role', 'button');
                li.tabIndex = 0;
                function jvAcctPickerChooseFromList() {
                    jvAcctPickerApply(a);
                }
                li.addEventListener('click', jvAcctPickerChooseFromList);
                li.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter' || ev.key === ' ') {
                        ev.preventDefault();
                        jvAcctPickerChooseFromList();
                    }
                });
                pickList.appendChild(li);
            });
        })
        .catch(function (e) {
            pickList.innerHTML = '<li class="gl-pick-empty">' + (e.message || String(e)) + '</li>';
        });
}

function jvAcctPickerClose() {
    var pm = document.getElementById('jv_pick_modal');
    if (pm) {
        pm.hidden = true;
        pm.setAttribute('aria-hidden', 'true');
    }
    document.body.classList.remove('gl-pick-open');
    jvAcctPickerAnchor = null;
}

function jvAcctPickerApply(a) {
    if (!jvAcctPickerAnchor || !a) {
        jvAcctPickerClose();
        return;
    }
    var tr = jvAcctPickerAnchor.closest('tr');
    if (tr && tr.classList.contains('jv-line-main') && tr.getAttribute('data-jv-cash-locked') === '1') {
        jvAcctPickerClose();
        return;
    }
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
    var trLock = anchorInput && anchorInput.closest ? anchorInput.closest('tr.jv-line-main') : null;
    if (trLock && trLock.getAttribute('data-jv-cash-locked') === '1') {
        return;
    }
    var pm = document.getElementById('jv_pick_modal');
    var pickQ = document.getElementById('jv_pick_q');
    var pickList = document.getElementById('jv_pick_list');
    if (!pm || !pickQ || !pickList) {
        return;
    }
    jvAcctPickerAnchor = anchorInput;
    pickQ.value = '';
    pickList.innerHTML = '';
    jvAcctPickerLoadIntoList('');
    pm.hidden = false;
    pm.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    pickQ.focus();
}

function jvAcctPickerOnKey(ev) {
    if (ev.key !== 'Escape') {
        return;
    }
    var pmEsc = document.getElementById('jv_pick_modal');
    if (pmEsc && !pmEsc.hidden) {
        jvAcctPickerClose();
        return;
    }
    var sm = document.getElementById('jv_search_modal');
    if (sm && sm.style.display === 'flex') {
        jvSearchModalClose();
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
    var elDf = document.getElementById('jv_search_date_from');
    var elDt = document.getElementById('jv_search_date_to');
    var p = {
        action: 'search_manual',
        entry_type: JV_ENTRY_TYPE,
        id_from: parseInt(String(document.getElementById('jv_search_id_from').value || '0'), 10) || 0,
        id_to: parseInt(String(document.getElementById('jv_search_id_to').value || '0'), 10) || 0,
        date_from: orangeGetDmyValueAsIso(elDf),
        date_to: orangeGetDmyValueAsIso(elDt),
        reference: document.getElementById('jv_search_ref').value.trim(),
        description: document.getElementById('jv_search_desc').value.trim()
    };
    if (JV_OTHER_VOUCHER_BROWSE) {
        p.journal_type_id = jvJournalTypeFilterId();
    }
    return p;
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
        var etCell = '';
        if (JV_OTHER_VOUCHER_BROWSE) {
            etCell = '<td><small>' + jvEscapeHtml(r.entry_type_label || r.entry_type || '') + '</small></td>';
        }
        tr.innerHTML = '<td>' + jvEscapeHtml(r.display_no != null ? r.display_no : r.id) + '</td>' +
            '<td dir="ltr">' + jvEscapeHtml(r.voucher_date) + '</td>' +
            etCell +
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
    if (JV_OTHER_VOUCHER_BROWSE && !jvOtherVoucherBrowseOk()) {
        alert(jvOtherVoucherBrowseBlockedMsg());
        return;
    }
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
}
jvSearchModalBind();

(function jvOtherVoucherFilterBind() {
    var sel = document.getElementById('jv_journal_type_filter');
    if (sel && !sel.getAttribute('data-jv-bound')) {
        sel.setAttribute('data-jv-bound', '1');
        sel.addEventListener('change', jvApplyOtherVoucherBrowseGateUi);
    }
})();

(function jvAcctPickerModalBind() {
    var pickQ = document.getElementById('jv_pick_q');
    var pickBackdrop = document.getElementById('jv_pick_backdrop');
    var pickClose = document.getElementById('jv_pick_close');
    if (!pickQ || pickQ.getAttribute('data-jv-bound')) {
        return;
    }
    pickQ.setAttribute('data-jv-bound', '1');
    pickQ.addEventListener('input', function () {
        if (jvPickSearchTimer) {
            clearTimeout(jvPickSearchTimer);
        }
        jvPickSearchTimer = setTimeout(function () {
            jvAcctPickerLoadIntoList(pickQ.value.trim());
        }, 280);
    });
    if (pickBackdrop) {
        pickBackdrop.addEventListener('click', jvAcctPickerClose);
    }
    if (pickClose) {
        pickClose.addEventListener('click', jvAcctPickerClose);
    }
})();

function jvAddCashLockedRow() {
    if (!jvCashLockActive()) {
        return;
    }
    var tb = document.getElementById('jv_lines_body');
    var pair = 'jv' + String(++jvPairSeq);
    var trMain = document.createElement('tr');
    trMain.className = 'jv-line-main jv-line-cash-locked';
    trMain.setAttribute('data-jv-pair', pair);
    trMain.setAttribute('data-jv-cash-locked', '1');
    var amtCells;
    if (jvCashLockIsReceipt()) {
        amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" title="يُملأ تلقائياً من مجموع الدائن"></td>' +
            '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="0.000" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="دائن الخزينة في القبض = صفر"></td>';
    } else {
        amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="0.000" placeholder="0.000" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="مدين الخزينة في الصرف = صفر"></td>' +
            '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" title="يُملأ تلقائياً من مجموع المدين"></td>';
    }
    trMain.innerHTML = '<td class="jv-acc-code-cell">' +
        '<input type="hidden" class="jv-acc-id" value="' + String(JV_CASH_LOCK.id) + '">' +
        '<input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + jvEscapeHtml(JV_CASH_LOCK.code || '') + '" readonly tabindex="-1" autocomplete="off" title="حساب الخزينة — ثابت">' +
        '</td>' +
        '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + jvEscapeHtml(JV_CASH_LOCK.name || '') + '" readonly tabindex="-1" title="حساب الخزينة — ثابت"></td>' +
        amtCells +
        '<td><span class="muted" style="display:inline-block;padding:8px 0;" aria-hidden="true">—</span></td>';
    var trMemo = document.createElement('tr');
    trMemo.className = 'jv-line-memo';
    trMemo.setAttribute('data-jv-pair', pair);
    trMemo.innerHTML = '<td colspan="5">' +
        '<input type="text" id="jv_m_' + pair + '" class="jv-m admin-inp" value="" placeholder="بيان سطر الخزينة" autocomplete="off">' +
        '</td>';
    tb.appendChild(trMain);
    tb.appendChild(trMemo);
    jvCashLockApplyLineAmountUi(trMain);
    jvRecalc();
}

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
    var cashAnchor = tb.querySelector('tr.jv-line-main[data-jv-cash-locked="1"]');
    if (jvCashLockIsPayment() && cashAnchor) {
        tb.insertBefore(trMain, cashAnchor);
        tb.insertBefore(trMemo, cashAnchor);
    } else {
        tb.appendChild(trMain);
        tb.appendChild(trMemo);
    }
    jvCashLockApplyLineAmountUi(trMain);
    jvRecalc();
}

function jvRemoveRow(btn) {
    var tb = document.getElementById('jv_lines_body');
    var main = btn.closest('tr.jv-line-main');
    if (!main) {
        return;
    }
    if (main.getAttribute('data-jv-cash-locked') === '1') {
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
    if (mainTr.getAttribute('data-jv-cash-locked') === '1') {
        return false;
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
    if (jvCashLockIsPayment()) {
        for (;;) {
            var mainsP = jvAllMainRows(tb);
            var manual = mainsP.filter(function (m) { return m.getAttribute('data-jv-cash-locked') !== '1'; });
            if (manual.length < 2) {
                return;
            }
            var a = manual[manual.length - 2];
            var b = manual[manual.length - 1];
            if (jvRowIsBlank(a) && jvRowIsBlank(b)) {
                jvRemovePair(a);
            } else {
                return;
            }
        }
    }
    for (;;) {
        var mains = jvAllMainRows(tb);
        if (mains.length < 2) {
            return;
        }
        var a2 = mains[mains.length - 2];
        var b2 = mains[mains.length - 1];
        if (jvRowIsBlank(a2) && jvRowIsBlank(b2)) {
            jvRemovePair(a2);
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
        if (jvCashLockIsReceipt()) {
            jvAddCashLockedRow();
            jvAddRow();
        } else if (jvCashLockIsPayment()) {
            jvAddRow();
            jvAddCashLockedRow();
        } else {
            jvAddRow();
        }
        return;
    }
    if (jvCashLockIsPayment()) {
        var cashEl = tb.querySelector('tr.jv-line-main[data-jv-cash-locked="1"]');
        if (!cashEl) {
            jvAddCashLockedRow();
        }
        mains = jvAllMainRows(tb);
        var manualMains = mains.filter(function (m) { return m.getAttribute('data-jv-cash-locked') !== '1'; });
        if (manualMains.length === 0) {
            jvAddRow();
            return;
        }
        var lastManual = manualMains[manualMains.length - 1];
        if (!jvRowIsBlank(lastManual)) {
            jvAddRow();
        }
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
        var lastMain = jvCashLockIsPayment() ? jvLastManualMainRow(mains) : mains[mains.length - 1];
        var lastMemo = lastMain ? jvMemoRow(lastMain) : null;
        if (!lastMemo || tr !== lastMemo) {
            return;
        }
        e.preventDefault();
        jvSyncTrailingRows();
        var mains2 = jvAllMainRows(tb);
        var nextMain = jvCashLockIsPayment() ? jvLastManualMainRow(mains2) : mains2[mains2.length - 1];
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

function jvFillMainFromLine(main, ln) {
    if (!main || !ln) {
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
    if (main.getAttribute('data-jv-cash-locked') === '1') {
        if (jvCashLockIsPayment()) {
            main.querySelector('.jv-d').value = '0.000';
        } else {
            main.querySelector('.jv-c').value = '0.000';
        }
    }
    if (memo) {
        memo.querySelector('.jv-m').value = ln.memo || '';
    }
    jvCashLockApplyLineAmountUi(main);
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
    var canEdit = r.voucher.editable === true;
    jvViewMode = !canEdit;
    jvBrowseId = r.voucher.id;
    jvBrowseEntryType = r.voucher.entry_type ? String(r.voucher.entry_type) : null;
    document.getElementById('jv_number_preview').value = String(r.voucher.display_voucher_no != null ? r.voucher.display_voucher_no : r.voucher.id);
    document.getElementById('jv_date').value = orangeIsoDateToDmy(r.voucher.date || '');
    document.getElementById('jv_ref').value = r.voucher.reference || '';
    document.getElementById('jv_desc').value = r.voucher.description || '';
    document.getElementById('jv_document_entered').value = r.voucher.document_entered_display || '';
    jvClearLinesBody();
    var lines = r.lines || [];
    var tb = document.getElementById('jv_lines_body');
    var cashLockId = (JV_CASH_LOCK && JV_CASH_LOCK.id) ? parseInt(String(JV_CASH_LOCK.id), 10) : 0;
    if (cashLockId > 0) {
        var cashLines = [];
        var otherLines = [];
        lines.forEach(function (ln) {
            if ((parseInt(String(ln.account_id), 10) || 0) === cashLockId) {
                cashLines.push(ln);
            } else {
                otherLines.push(ln);
            }
        });
        if (cashLines.length > 0) {
            if (jvCashLockIsReceipt()) {
                jvAddCashLockedRow();
                jvFillMainFromLine(jvAllMainRows(tb)[0], cashLines[0]);
                for (var ci = 1; ci < cashLines.length; ci++) {
                    jvAddRow();
                    jvFillMainFromLine(jvAllMainRows(tb)[jvAllMainRows(tb).length - 1], cashLines[ci]);
                }
                otherLines.forEach(function (ln) {
                    jvAddRow();
                    jvFillMainFromLine(jvAllMainRows(tb)[jvAllMainRows(tb).length - 1], ln);
                });
            } else {
                jvAddCashLockedRow();
                otherLines.forEach(function (ln) {
                    jvAddRow();
                    jvFillMainFromLine(jvLastManualMainRow(jvAllMainRows(tb)), ln);
                });
                jvFillMainFromLine(jvAllMainRows(tb)[jvAllMainRows(tb).length - 1], cashLines[0]);
                for (var cj = 1; cj < cashLines.length; cj++) {
                    jvAddRow();
                    jvFillMainFromLine(jvLastManualMainRow(jvAllMainRows(tb)), cashLines[cj]);
                }
            }
        } else {
            lines.forEach(function (ln) {
                jvAddRow();
                jvFillMainFromLine(jvAllMainRows(tb)[jvAllMainRows(tb).length - 1], ln);
            });
        }
    } else {
        lines.forEach(function (ln) {
            jvAddRow();
            jvFillMainFromLine(jvAllMainRows(tb)[jvAllMainRows(tb).length - 1], ln);
        });
    }
    jvApplyViewModeUi();
    jvRecalc();
    jvSearchModalClose();
}

function jvLoadVoucherFromApi(id) {
    if (JV_OTHER_VOUCHER_BROWSE && !jvOtherVoucherBrowseOk()) {
        alert(jvOtherVoucherBrowseBlockedMsg());
        return;
    }
    var getPayload = { action: 'get', id: id, entry_type: JV_ENTRY_TYPE };
    if (JV_OTHER_VOUCHER_BROWSE) {
        getPayload.journal_type_id = jvJournalTypeFilterId();
    }
    postJSON('/admin/api/journal/manage.php', getPayload).then(function (r) {
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
    if (JV_OTHER_VOUCHER_BROWSE && !jvOtherVoucherBrowseOk()) {
        alert(jvOtherVoucherBrowseBlockedMsg());
        return;
    }
    var navPayload = {
        action: 'nav_manual',
        entry_type: JV_ENTRY_TYPE,
        where: where,
        current_id: jvBrowseId || 0
    };
    if (JV_OTHER_VOUCHER_BROWSE) {
        navPayload.journal_type_id = jvJournalTypeFilterId();
    }
    postJSON('/admin/api/journal/manage.php', navPayload).then(function (r) {
        if (!r.success || !r.id) {
            alert(r.message || 'لا يمكن التنقل');
            return;
        }
        jvLoadVoucherFromApi(r.id);
    }).catch(function (e) { alert(e.message || String(e)); });
}

function jvRecalc() {
    jvCashLockSyncTreasuryAmount();
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
    if (JV_OTHER_VOUCHER_BROWSE && !jvOtherVoucherBrowseOk()) {
        alert(jvOtherVoucherBrowseBlockedMsg() + ' — مطلوب أيضاً قبل حفظ سند جديد.');
        return;
    }
    jvCashLockSyncTreasuryAmount();
    var dIso = orangeGetDmyValueAsIso(document.getElementById('jv_date'));
    var ref = document.getElementById('jv_ref').value.trim();
    var desc = document.getElementById('jv_desc').value.trim();
    if (!dIso || !desc) {
        alert('التاريخ والبيان مطلوبان (التاريخ بصيغة يوم/شهر/سنة)');
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
    if (JV_ENTRY_TYPE === 'receipt_voucher' && JV_CASH_LOCK && JV_CASH_LOCK.id) {
        var cidR = parseInt(String(JV_CASH_LOCK.id), 10);
        if (lines.length < 1 || (parseInt(String(lines[0].account_id), 10) || 0) !== cidR) {
            alert('سند القبض يجب أن يبدأ بسطر الخزينة (النقدية) كما في الشاشة');
            return;
        }
        if (lines[0].debit <= 0 || lines[0].credit > 0) {
            alert('سند القبض: سطر الخزينة مدين فقط (يُجمع من دائن الحسابات الأخرى)');
            return;
        }
    }
    if (JV_ENTRY_TYPE === 'payment_voucher' && JV_CASH_LOCK && JV_CASH_LOCK.id) {
        var cidP = parseInt(String(JV_CASH_LOCK.id), 10);
        if (lines.length < 1 || (parseInt(String(lines[lines.length - 1].account_id), 10) || 0) !== cidP) {
            alert('سند الصرف يجب أن ينتهي بسطر الخزينة (النقدية) كما في الشاشة');
            return;
        }
        var lastLn = lines[lines.length - 1];
        if (lastLn.credit <= 0 || lastLn.debit > 0) {
            alert('سند الصرف: سطر الخزينة دائن فقط (يُجمع من مدين الحسابات الأخرى)');
            return;
        }
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
    var savePayload = {
        action: (jvBrowseId && !jvViewMode) ? 'update' : 'create',
        date: dIso,
        reference: ref,
        description: desc,
        entry_type: JV_ENTRY_TYPE,
        lines: lines
    };
    if (jvBrowseId && !jvViewMode) {
        savePayload.id = jvBrowseId;
    }
    if (JV_OTHER_VOUCHER_BROWSE) {
        savePayload.journal_type_id = jvJournalTypeFilterId();
    }
    postJSON('/admin/api/journal/manage.php', savePayload).then(function (r) {
        if (r.success) {
            alert(r.message || 'تم');
            if (savePayload.action === 'update' && jvBrowseId) {
                jvLoadVoucherFromApi(jvBrowseId);
            } else {
                location.reload();
            }
            return;
        }
        if (!orangeAdminOfferSuggestOnFailure(r, 'فشل')) {
            alert(r.message || 'فشل');
        }
    }).catch(function (e) { alert(e.message || String(e)); });
}

jvBindLinesBody();
if (jvCashLockIsReceipt()) {
    jvAddCashLockedRow();
    jvAddRow();
} else if (jvCashLockIsPayment()) {
    jvAddRow();
    jvAddCashLockedRow();
} else {
    jvAddRow();
}
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
    jvApplyOtherVoucherBrowseGateUi();
})();
</script>
