<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';

$pdo = orange_admin_page_pdo();

$ppvPrintTuningMode = orange_admin_voucher_print_tuning_mode();

$ppvCountryId = orange_admin_context_country_id($pdo);
$ppvCustomersCountrySql = orange_sql_country_and_fragment($pdo, 'customers', 'customers', $ppvCountryId);

$ppvTitle = 'سداد فواتير مبيعات آجلة';
$ppvApiUrl = '/admin/api/partners/customer-receipt.php';

$partnerUiTodayDmy = orange_format_date_dmY(date('Y-m-d'));
$ppvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$cashAccId = null;
try {
    $cashAccId = orange_gl_account_id_optional($pdo, 'cash');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] partner_customer_receipt cash account: ' . $e->getMessage());
    }
}
$ppvCashLock = null;
if ($cashAccId !== null && $cashAccId > 0) {
    $stCash = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
    $stCash->execute([(int) $cashAccId]);
    $cashRow = $stCash->fetch(PDO::FETCH_ASSOC);
    if ($cashRow) {
        $ppvCashLock = [
            'id' => (int) $cashRow['id'],
            'code' => (string) ($cashRow['code'] ?? ''),
            'name' => (string) ($cashRow['name'] ?? ''),
        ];
    }
}

$arAccId = null;
try {
    $arAccId = orange_gl_account_id_optional($pdo, 'ar_credit');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] partner_customer_receipt ar_credit account: ' . $e->getMessage());
    }
}
$ppvArLock = null;
if ($arAccId !== null && $arAccId > 0) {
    $stAr = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
    $stAr->execute([(int) $arAccId]);
    $arRow = $stAr->fetch(PDO::FETCH_ASSOC);
    if ($arRow) {
        $ppvArLock = [
            'id' => (int) $arRow['id'],
            'code' => (string) ($arRow['code'] ?? ''),
            'name' => (string) ($arRow['name'] ?? ''),
        ];
    }
}

$customers = [];
$ppvCustomerPickRows = [];
if (orange_table_exists($pdo, 'customers')) {
    $codeCol = orange_table_has_column($pdo, 'customers', 'code') ? 'code' : 'id';
    $customers = $pdo->query(
        'SELECT id, name_ar, phone, ' . $codeCol . ' AS customer_code FROM customers WHERE 1=1'
        . $ppvCustomersCountrySql . ' ORDER BY name_ar ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
}

$custBal = [];
foreach ($customers as $c) {
    $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
}

$arCode = $ppvArLock ? (string) ($ppvArLock['code'] ?? '') : '';
$arName = $ppvArLock ? (string) ($ppvArLock['name'] ?? '') : '';

foreach ($customers as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid <= 0) {
        continue;
    }
    $customerName = trim((string) ($c['name_ar'] ?? ''));
    $customerPhone = trim((string) ($c['phone'] ?? ''));
    $customerCode = trim((string) ($c['customer_code'] ?? ''));
    if ($customerCode === '') {
        $customerCode = (string) $cid;
    }
    $balance = (float) ($custBal[$cid] ?? 0.0);
    $ppvCustomerPickRows[] = [
        'id' => $cid,
        'name' => $customerName,
        'phone' => $customerPhone,
        'balance' => round($balance, 3),
        'customer_code' => $customerCode,
        'account_code' => $arCode,
        'account_name' => $arName,
    ];
}

$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$ppvPrefillCustomerId = ($prefillStmtKind === 'customer' && $prefillStmtId > 0) ? $prefillStmtId : 0;

$nextVoucherNo = 0;
$crecRefPreview = '';
if (orange_journal_vouchers_ready($pdo)) {
    orange_journal_types_sync_canonical_defaults($pdo, $ppvCountryId > 0 ? $ppvCountryId : null);
    $fyPeek = orange_fiscal_find_for_date($pdo, date('Y-m-d'), $ppvCountryId > 0 ? $ppvCountryId : null);
    $fyPeekId = $fyPeek ? (int) $fyPeek['id'] : 0;
    if (
        $fyPeekId > 0
        && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
    ) {
        $crecMeta = orange_journal_voucher_resolve_serial_meta($pdo, 'customer_receipt', null, $ppvCountryId > 0 ? $ppvCountryId : null);
        $nextVoucherNo = orange_journal_voucher_next_serial($pdo, $fyPeekId, $crecMeta['journal_serial_bucket']);
        $crecRefPreview = orange_voucher_auto_reference_preview(
            $pdo,
            'customer_receipt',
            $fyPeekId,
            $ppvCountryId > 0 ? $ppvCountryId : null
        );
    } else {
        $nextVoucherNo = orange_gl_voucher_next_id_preview($pdo, $ppvCountryId);
    }
}

$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$ppvReady = $ppvCashLock !== null && $ppvArLock !== null;
?>
<style>
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
.jv-search-field input { width: 100%; box-sizing: border-box; }
.jv-search-field--id { flex: 0 0 7rem; }
.jv-search-field--date { flex: 0 0 11rem; }
.jv-search-field--ref { flex: 1 1 0; min-width: 7rem; }
.jv-search-field--full { width: 100%; }
.jv-search-modal__row--desc { width: 100%; }
.jv-search-modal__actions { margin: 0 0 16px; }
.jv-search-table-wrap { max-height: min(40vh, 22rem); overflow: auto; border: 1px solid #e4e4e7; border-radius: 8px; }
.jv-search-results-table { margin: 0; font-size: 0.9rem; }
.jv-search-results-table tbody tr { cursor: pointer; }
.jv-search-results-table tbody tr:hover { background: #f4f4f5; }
</style>
<div class="page-title page-title--stacked jv-print-hide">
    <div><h1><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h1></div>
</div>

<?php if ($ppvCashLock === null): ?>
<div class="card jv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>
<?php if ($ppvArLock === null): ?>
<div class="card jv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;">اربط حساب <strong>عملاء آجل</strong> (<code>ar_credit</code>) من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
</div>
<?php endif; ?>

<div class="card jv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ppvCountryId, ['title_ar' => $ppvTitle]); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">

    <!-- ١ — العميل + خيار الدفعة المقدمة -->
    <div class="form-grid" style="margin-bottom:16px;">
        <div style="grid-column:1/-1;">
            <label for="crec_customer_code">العميل</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="crec_customer_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <input type="text" id="crec_customer_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="crec_customer_id" value="0">
        </div>
        <div style="grid-column:1/-1;" class="form-check jv-print-hide">
            <label><input type="checkbox" id="crec_advance_mode"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                السماح بقبض يزيد عن رصيد الذمة (سلفة / دفعة مقدمة)
            </label>
        </div>
    </div>

    <!-- ٢ — جدول الفواتير -->
    <div id="crec_invoices_section">
        <div class="table-wrap">
            <table class="admin-table" id="crec_invoices_table">
                <thead>
                    <tr>
                        <th>الفاتورة</th>
                        <th>المبلغ الأصلي</th>
                        <th>المتبقي</th>
                        <th>مبلغ القبض</th>
                    </tr>
                </thead>
                <tbody id="crec_invoices_body">
                    <tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">اختر العميل أولاً</td></tr>
                </tbody>
                <tfoot id="crec_invoices_foot" hidden>
                    <tr style="font-weight:700;background:#f9fafb;border-top:2px solid #e2e8f0;">
                        <td colspan="3">المجموع</td>
                        <td id="crec_invoices_total" dir="ltr" lang="en">0.000</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ٣ — القيد المحاسبي -->
    <div style="margin-top:20px;padding-top:14px;border-top:2px solid #e2e8f0;">
        <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">القيد المحاسبي</h4>
        <?php orange_edit_lock_ui_toolbar(['prefix' => 'crec', 'doc_kind' => 'customer_receipt', 'country_id' => $ppvCountryId]); ?>

        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="margin-bottom:12px;">
            <div>
                <label for="crec_number_preview">رقم القيد</label>
                <input type="text" id="crec_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;" value="<?php echo $nextVoucherNo > 0 ? (int) $nextVoucherNo : ''; ?>" title="التسلسل ضمن نوع سند قبض العميل والسنة المالية">
            </div>
            <div>
                <label for="crec_date">تاريخ السند</label>
                <input type="text" id="crec_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="crec_ref">المرجع</label>
                <input type="text" id="crec_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($crecRefPreview, ENT_QUOTES, 'UTF-8'); ?>" title="يُولَّد تلقائياً: CRR-رمز الدولة-رقم القيد" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="crec_document_entered">تاريخ الإدخال</label>
                <input type="text" id="crec_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($ppvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="crec_tot_debit">مجموع المدين</label>
                <input type="text" id="crec_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="crec_tot_credit">مجموع الدائن</label>
                <input type="text" id="crec_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
                    <button type="button" class="btn-secondary jv-nav-btn" id="crec_nav_first" title="أول سند" aria-label="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="crec_nav_prev" title="السند السابق" aria-label="السند السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="crec_nav_next" title="السند التالي" aria-label="السند التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="crec_nav_last" title="آخر سند" aria-label="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="crec_btn_search" title="بحث عن سند">بحث</button>
                </div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="crec_desc">البيان</label>
            <input type="text" id="crec_desc" placeholder="بيان القبض" value=""<?php echo !$ppvReady ? ' disabled' : ''; ?>>
        </div>

        <div class="admin-doc-frame">
            <div class="table-wrap">
                <table class="admin-table admin-doc-lines-table jv-lines-table">
                    <colgroup>
                        <col class="jv-col-code">
                        <col class="jv-col-name">
                        <col class="jv-col-amt">
                        <col class="jv-col-amt">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>كود الحساب</th>
                            <th>اسم الحساب</th>
                            <th>مدين</th>
                            <th>دائن</th>
                        </tr>
                    </thead>
                    <tbody id="crec_jv_body"></tbody>
                </table>
            </div>
        </div>
    </div>

                </td>
            </tr>
        </tbody>
    </table>
    <?php orange_voucher_print_metafoot(); ?>

    <!-- ٤ — أزرار -->
    <div class="actions admin-doc-lines-toolbar jv-doc-toolbar jv-print-hide" style="margin-top:16px;">
        <span></span>
        <div class="jv-toolbar-primary-group">
            <button type="button" id="crec_btn_new" title="إدخال سند جديد">سند جديد</button>
            <button type="button" class="btn-secondary" id="crec_btn_delete" title="حذف السند المعروض" disabled>حذف السند</button>
            <button type="button" class="btn-secondary" id="crec_btn_print" onclick="crecPrintVoucher(); return false;" title="<?php echo $ppvPrintTuningMode ? 'طباعة السند' : 'احفظ السند أولاً — الطباعة بعد الحفظ فقط'; ?>"<?php echo $ppvPrintTuningMode ? '' : ' disabled'; ?>>طباعة السند</button>
            <button type="button" id="crec_btn_save"<?php echo !$ppvReady ? ' disabled' : ''; ?>>حفظ السند</button>
        </div>
    </div>
</div>

<!-- Customer Picker Modal -->
<div class="gl-pick-modal jv-print-hide" id="crec_customer_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="crec_customer_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="crec_customer_pick_title">
        <h3 id="crec_customer_pick_title" class="gl-pick-modal__title">اختيار العميل</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="crec_customer_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود العميل أو الاسم أو الهاتف…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="crec_customer_pick_list"></ul>
        <button type="button" class="btn-secondary" id="crec_customer_pick_close">إغلاق</button>
    </div>
</div>

<!-- Search Modal -->
<div id="crec_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="crec_search_modal_title">
    <div class="jv-search-modal__backdrop" id="crec_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="crec_search_modal_title" class="jv-search-modal__title">بحث في سندات سداد العملاء</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="crec_search_id_from">رقم القيد — من</label>
                        <input type="number" id="crec_search_id_from" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="crec_search_id_to">رقم القيد — إلى</label>
                        <input type="number" id="crec_search_id_to" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="crec_search_date_from">تاريخ السند — من</label>
                        <input type="text" id="crec_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="crec_search_date_to">تاريخ السند — إلى</label>
                        <input type="text" id="crec_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="crec_search_ref">المرجع (يحتوي النص)</label>
                        <input type="text" id="crec_search_ref" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--desc">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="crec_search_desc">بيان القيد العام (يحتوي النص)</label>
                        <input type="text" id="crec_search_desc" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="crec_search_btn">تنفيذ البحث</button>
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
                        <tbody id="crec_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var ORANGE_VOUCHER_PRINT_TUNING = <?php echo $ppvPrintTuningMode ? 'true' : 'false'; ?>;
var crecBrowseId = null;
var crecEditLockCtl = null;
var CREC_COUNTRY_ID = <?php echo (int) $ppvCountryId; ?>;

function crecPrintVoucher() {
    if (!ORANGE_VOUCHER_PRINT_TUNING && !crecBrowseId) {
        alert('احفظ السند أولاً قبل الطباعة.');
        return false;
    }
    return orangeAdminOpenPrintDialog(
        orangeAdminBuildVoucherPrintDocTitle(null, 'crec_number_preview', 'سند')
    );
}

(function () {
    var CREC_API = <?php echo json_encode($ppvApiUrl, JSON_UNESCAPED_UNICODE); ?>;
    var CREC_CASH = <?php echo json_encode($ppvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var CREC_READY = <?php echo $ppvReady ? 'true' : 'false'; ?>;
    var CREC_AR = <?php echo json_encode($ppvArLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var CREC_CUSTOMER_PICK_ROWS = <?php echo json_encode($ppvCustomerPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var CREC_PREFILL_CUSTOMER = <?php echo (int) $ppvPrefillCustomerId; ?>;

    var currentCustomerId = 0;
    var currentInvoices = [];

    function crecSyncRefPreview() {
        if (crecBrowseId) {
            return;
        }
        var refEl = document.getElementById('crec_ref');
        var numEl = document.getElementById('crec_number_preview');
        var dateEl = document.getElementById('crec_date');
        var dIso = dateEl && typeof orangeGetDmyValueAsIso === 'function' ? orangeGetDmyValueAsIso(dateEl) : '';
        postJSON('/admin/api/journal/manage.php', {
            action: 'reference_preview',
            date: dIso || undefined,
            entry_type: 'customer_receipt'
        }).then(function (r) {
            if (!r || !r.success) {
                return;
            }
            if (refEl) {
                refEl.value = r.reference || '';
            }
            if (numEl) {
                numEl.value = r.voucher_serial > 0 ? String(r.voucher_serial) : '';
            }
        }).catch(function () {});
    }

    function esc(s) { return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function customerById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < CREC_CUSTOMER_PICK_ROWS.length; i++) {
            if ((parseInt(String(CREC_CUSTOMER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return CREC_CUSTOMER_PICK_ROWS[i];
        }
        return null;
    }

    function isAdvanceMode() {
        var cb = document.getElementById('crec_advance_mode');
        return cb && cb.checked;
    }

    function selectCustomer(id) {
        var row = customerById(id);
        var codeEl = document.getElementById('crec_customer_code');
        var nameEl = document.getElementById('crec_customer_name');
        var idEl = document.getElementById('crec_customer_id');
        if (!row) {
            currentCustomerId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (idEl) idEl.value = '0';
            currentInvoices = [];
            renderInvoices();
            renderJournal();
            return;
        }
        currentCustomerId = parseInt(String(row.id), 10) || 0;
        if (codeEl) codeEl.value = row.customer_code || '';
        if (nameEl) nameEl.value = row.name || '';
        if (idEl) idEl.value = String(currentCustomerId);
        loadInvoices();
    }

    function loadInvoices() {
        if (currentCustomerId <= 0) {
            currentInvoices = [];
            renderInvoices();
            renderJournal();
            return;
        }
        postJSON('/admin/api/partners/open-items.php', { party_kind: 'customer', party_id: currentCustomerId }).then(function (r) {
            if (!r.success) { currentInvoices = []; }
            else {
                currentInvoices = (r.items || []).map(function (it) {
                    return { ref_type: it.ref_type, ref_id: it.ref_id, label: it.label, original: parseFloat(String(it.original || it.open || '0')) || 0, open: parseFloat(String(it.open || '0')) || 0, amount: 0 };
                });
            }
            renderInvoices();
            renderJournal();
        }).catch(function () {
            currentInvoices = [];
            renderInvoices();
            renderJournal();
        });
    }

    function renderInvoices() {
        var section = document.getElementById('crec_invoices_section');
        var tbody = document.getElementById('crec_invoices_body');
        var tfoot = document.getElementById('crec_invoices_foot');
        if (!section || !tbody || !tfoot) return;

        if (isAdvanceMode()) {
            section.hidden = true;
            return;
        }
        section.hidden = false;

        if (currentCustomerId <= 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">اختر العميل أولاً</td></tr>';
            tfoot.hidden = true;
            return;
        }
        if (currentInvoices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">لا توجد فواتير آجلة مفتوحة لهذا العميل</td></tr>';
            tfoot.hidden = true;
            return;
        }

        tbody.innerHTML = '';
        tfoot.hidden = false;
        currentInvoices.forEach(function (inv, idx) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + esc(inv.label) + '</td>' +
                '<td dir="ltr" lang="en">' + orangeFmtMoney(inv.original) + '</td>' +
                '<td dir="ltr" lang="en">' + orangeFmtMoney(inv.open) + '</td>' +
                '<td><input type="number" class="crec-inv-amt admin-inp-money" step="any" min="0" max="' + orangeFmtMoney(inv.open) + '" placeholder="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" data-idx="' + idx + '"></td>';
            tbody.appendChild(tr);
            tr.querySelector('.crec-inv-amt').addEventListener('input', function () {
                currentInvoices[idx].amount = parseFloat(this.value) || 0;
                updateInvoiceTotal();
                renderJournal();
            });
        });
        updateInvoiceTotal();
    }

    function updateInvoiceTotal() {
        var total = 0;
        currentInvoices.forEach(function (inv) { total += inv.amount; });
        var el = document.getElementById('crec_invoices_total');
        if (el) el.textContent = orangeFmtMoney(total);
    }

    function getTotal() {
        if (isAdvanceMode()) {
            var inp = document.querySelector('#crec_jv_body [data-crec-advance="1"] .crec-adv-amt');
            return parseFloat(inp ? inp.value : '0') || 0;
        }
        var t = 0;
        currentInvoices.forEach(function (inv) { t += inv.amount; });
        return t;
    }

    function renderJournal() {
        var tb = document.getElementById('crec_jv_body');
        if (!tb || !CREC_READY || !CREC_CASH || !CREC_AR) return;
        tb.innerHTML = '';

        var accCode = CREC_AR.code || '';
        var accName = CREC_AR.name || '';
        var customerRow = customerById(currentCustomerId);
        var customerName = customerRow ? customerRow.name : '';

        if (isAdvanceMode()) {
            var tr = document.createElement('tr');
            tr.className = 'jv-line-main';
            tr.setAttribute('data-crec-advance', '1');
            tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(accName) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                '<td><input type="number" class="crec-adv-amt admin-inp-money" step="any" min="0" placeholder="مبلغ القبض" inputmode="decimal" lang="en" dir="ltr"></td>';
            tb.appendChild(tr);
            tr.querySelector('.crec-adv-amt').addEventListener('input', function () { recalcTotals(); });
        } else {
            currentInvoices.forEach(function (inv) {
                if (inv.amount <= 0) return;
                var tr = document.createElement('tr');
                tr.className = 'jv-line-main';
                tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(customerName + ' — ' + inv.label) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + orangeFmtMoney(inv.amount) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
                tb.appendChild(tr);
            });
        }

        var total = getTotal();
        var cashTr = document.createElement('tr');
        cashTr.className = 'jv-line-main jv-line-cash-locked';
        cashTr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(CREC_CASH.code || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(CREC_CASH.name || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="text" class="admin-inp-money" value="' + orangeFmtMoney(total) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
        tb.appendChild(cashTr);
        recalcTotals();
    }

    function recalcTotals() {
        var total = getTotal();
        var dEl = document.getElementById('crec_tot_debit');
        var cEl = document.getElementById('crec_tot_credit');
        if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
            window.OrangeMoney.setJvTotals(dEl, cEl, total, total);
        } else {
            if (dEl) dEl.value = orangeFmtMoney(total);
            if (cEl) cEl.value = orangeFmtMoney(total);
        }
        var cashTr = document.querySelector('#crec_jv_body .jv-line-cash-locked');
        if (cashTr) {
            var cells = cashTr.querySelectorAll('input.admin-inp-money');
            if (cells.length >= 2) {
                cells[0].value = orangeFmtMoney(total);
                cells[1].value = orangeMoneyZero();
            }
        }
    }

    // Picker
    function pickerOpen() {
        var modal = document.getElementById('crec_customer_pick_modal');
        var qEl = document.getElementById('crec_customer_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('crec_customer_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('crec_customer_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = CREC_CUSTOMER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.customer_code + ' ' + r.name + ' ' + r.phone + ' ' + r.account_code).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.customer_code ? r.customer_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + orangeFmtMoney(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectCustomer(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectCustomer(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    // Save
    function save() {
        if (!CREC_CASH || !CREC_CASH.id) return;
        var cid = currentCustomerId;
        if (cid <= 0) { alert('اختر العميل أولاً'); return; }
        var dIso = orangeGetDmyValueAsIso(document.getElementById('crec_date'));
        if (!dIso) { alert('أدخل تاريخ السند (يوم/شهر/سنة)'); return; }
        var desc = (document.getElementById('crec_desc').value || '').trim() || 'سداد فواتير مبيعات آجلة';
        var advance = isAdvanceMode();
        var totalAmt = 0;
        var allocations = [];

        if (advance) {
            var advInp = document.querySelector('#crec_jv_body [data-crec-advance="1"] .crec-adv-amt');
            totalAmt = parseFloat(advInp ? advInp.value : '0') || 0;
            if (totalAmt <= 0) { alert('أدخل مبلغ القبض'); return; }
        } else {
            currentInvoices.forEach(function (inv) {
                if (inv.amount > 0) {
                    totalAmt += inv.amount;
                    allocations.push({ ref_type: inv.ref_type, ref_id: inv.ref_id, amount: inv.amount });
                }
            });
            if (totalAmt <= 0) { alert('حدد مبلغ القبض لفاتورة واحدة على الأقل'); return; }
        }

        var payload = {
            customer_id: cid,
            amount: totalAmt,
            date: dIso,
            description: desc,
            allow_excess: advance,
            allocations: allocations
        };
        postJSON(CREC_API, payload).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم');
                location.reload();
            } else {
                alert(r.message || 'فشل');
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    // Navigation & Search — uses /admin/api/journal/manage.php like سند الصرف

    function crecSyncPrintButton() {
        var pb = document.getElementById('crec_btn_print');
        if (!pb) {
            return;
        }
        if (ORANGE_VOUCHER_PRINT_TUNING) {
            pb.disabled = false;
            pb.title = 'طباعة السند';
            return;
        }
        var ok = !!crecBrowseId;
        pb.disabled = !ok;
        pb.title = ok ? 'طباعة السند' : 'احفظ السند أولاً — الطباعة بعد الحفظ فقط (§10)';
    }

    function crecNav(where) {
        var payload = {
            action: 'nav_manual',
            entry_type: 'customer_receipt',
            where: where,
            current_id: crecBrowseId || 0
        };
        postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا توجد سندات من هذا النوع بعد');
                return;
            }
            crecLoadVoucher(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function crecLoadVoucher(id) {
        postJSON('/admin/api/journal/manage.php', { action: 'get', id: id, entry_type: 'customer_receipt' }).then(function (r) {
            if (!r.success || !r.voucher) {
                alert(r.message || 'تعذر تحميل السند');
                return;
            }
            crecBrowseId = r.voucher.id;
            crecDisplayVoucher(r);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function crecDisplayVoucher(r) {
        var v = r.voucher;
        document.getElementById('crec_number_preview').value = String(v.voucher_serial || v.id || '');
        document.getElementById('crec_ref').value = v.reference || '';
        document.getElementById('crec_date').value = v.voucher_date_dmy || v.voucher_date || '';
        document.getElementById('crec_desc').value = v.description || '';
        var total = 0;
        (r.lines || []).forEach(function (l) { total += parseFloat(String(l.debit || '0')) || 0; });
        if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
            window.OrangeMoney.setJvTotals('crec_tot_debit', 'crec_tot_credit', total, total);
        } else {
            document.getElementById('crec_tot_debit').value = orangeFmtMoney(total);
            document.getElementById('crec_tot_credit').value = orangeFmtMoney(total);
        }
        document.getElementById('crec_btn_delete').disabled = false;
        crecSyncPrintButton();
        if (crecEditLockCtl && crecEditLockCtl.refresh) {
            crecEditLockCtl.refresh();
        }

        // Load customer from subledger
        if (r.party_customer_id) {
            selectCustomer(parseInt(String(r.party_customer_id), 10) || 0);
        }

        // Rebuild journal lines from loaded voucher
        var tb = document.getElementById('crec_jv_body');
        if (tb && r.lines) {
            tb.innerHTML = '';
            r.lines.forEach(function (l) {
                var tr = document.createElement('tr');
                tr.className = 'jv-line-main';
                var accCode = l.code || '';
                var accName = l.name || '';
                if ((!accCode || !accName) && r.accounts_by_id) {
                    var accId = parseInt(String(l.account_id || '0'), 10) || 0;
                    var byId = r.accounts_by_id[String(accId)];
                    if (byId) {
                        accCode = accCode || byId.code || '';
                        accName = accName || byId.name || '';
                    }
                }
                var d = parseFloat(String(l.debit || '0')) || 0;
                var c = parseFloat(String(l.credit || '0')) || 0;
                tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(accName + (l.memo ? ' — ' + l.memo : '')) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + (d > 0 ? orangeFmtMoney(d) : orangeMoneyZero()) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + (c > 0 ? orangeFmtMoney(c) : orangeMoneyZero()) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
                tb.appendChild(tr);
            });
        }
    }

    function crecDeleteVoucher() {
        if (!crecBrowseId) {
            alert('لا يوجد سند محفوظ للحذف');
            return;
        }
        if (!confirm('تأكيد حذف هذا السند؟ لا يمكن التراجع.')) {
            return;
        }
        postJSON('/admin/api/journal/manage.php', { action: 'delete', id: crecBrowseId }).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم الحذف');
                location.reload();
                return;
            }
            alert(r.message || 'فشل الحذف');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function crecSearchOpen() {
        var m = document.getElementById('crec_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function crecSearchClose() {
        var m = document.getElementById('crec_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function crecSearchRun() {
        var idFrom = parseInt(document.getElementById('crec_search_id_from').value) || 0;
        var idTo = parseInt(document.getElementById('crec_search_id_to').value) || 0;
        var dateFrom = orangeGetDmyValueAsIso(document.getElementById('crec_search_date_from')) || '';
        var dateTo = orangeGetDmyValueAsIso(document.getElementById('crec_search_date_to')) || '';
        var ref = (document.getElementById('crec_search_ref').value || '').trim();
        var desc = (document.getElementById('crec_search_desc').value || '').trim();
        var tbody = document.getElementById('crec_search_results');
        tbody.innerHTML = '<tr><td colspan="5">جاري البحث…</td></tr>';
        var payload = {
            action: 'search',
            entry_type: 'customer_receipt'
        };
        if (idFrom > 0) payload.id_from = idFrom;
        if (idTo > 0) payload.id_to = idTo;
        if (dateFrom) payload.date_from = dateFrom;
        if (dateTo) payload.date_to = dateTo;
        if (ref) payload.reference = ref;
        if (desc) payload.description = desc;
        postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
            tbody.innerHTML = '';
            if (!r.success || !r.results || !r.results.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="muted">لا نتائج</td></tr>';
                return;
            }
            r.results.forEach(function (v) {
                var tr = document.createElement('tr');
                tr.style.cursor = 'pointer';
                tr.innerHTML = '<td>' + esc(String(v.voucher_serial || v.id)) + '</td><td>' + esc(v.voucher_date_dmy || v.voucher_date || '') + '</td><td>' + esc(v.reference || '') + '</td><td>' + esc(v.description || '') + '</td><td dir="ltr">' + orangeFmtMoney(parseFloat(v.total || '0') || 0) + '</td>';
                tr.addEventListener('dblclick', function () { crecLoadVoucher(v.id); crecSearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="5">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    document.addEventListener('mousedown', function (ev) {
        var m = document.getElementById('crec_search_modal');
        if (!m || m.style.display !== 'flex') return;
        var panel = m.querySelector('.jv-search-modal__panel');
        if (panel && (panel === ev.target || panel.contains(ev.target))) return;
        if (ev.target.closest && ev.target.closest('#crec_btn_search')) return;
        crecSearchClose();
    }, true);

    // Init
    function init() {
        var codeEl = document.getElementById('crec_customer_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('crec_customer_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('crec_customer_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('crec_customer_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        var crecAdvanceMode = document.getElementById('crec_advance_mode');
        if (crecAdvanceMode) {
            crecAdvanceMode.addEventListener('change', function () {
                renderInvoices();
                renderJournal();
            });
        }

        document.getElementById('crec_btn_save').addEventListener('click', save);
        document.getElementById('crec_btn_new').addEventListener('click', function () { location.reload(); });
        document.getElementById('crec_btn_delete').addEventListener('click', crecDeleteVoucher);

        if (window.OrangeEditLock) {
            crecEditLockCtl = OrangeEditLock.bind({
                prefix: 'crec',
                docKind: 'customer_receipt',
                page: 'partner_customer_receipt',
                countryId: CREC_COUNTRY_ID,
                getEntityId: function () { return crecBrowseId || 0; }
            });
        }

        document.getElementById('crec_nav_first').addEventListener('click', function () { crecNav('first'); });
        document.getElementById('crec_nav_prev').addEventListener('click', function () { crecNav('prev'); });
        document.getElementById('crec_nav_next').addEventListener('click', function () { crecNav('next'); });
        document.getElementById('crec_nav_last').addEventListener('click', function () { crecNav('last'); });
        document.getElementById('crec_btn_search').addEventListener('click', crecSearchOpen);

        document.getElementById('crec_search_btn').addEventListener('click', crecSearchRun);
        document.getElementById('crec_search_modal_backdrop').addEventListener('click', crecSearchClose);

        var crecDateEl = document.getElementById('crec_date');
        if (crecDateEl && !crecDateEl.getAttribute('data-crec-ref-bound')) {
            crecDateEl.setAttribute('data-crec-ref-bound', '1');
            crecDateEl.addEventListener('blur', function () {
                if (typeof orangeNormalizeDmyInput === 'function') {
                    orangeNormalizeDmyInput(crecDateEl);
                }
                crecSyncRefPreview();
            });
        }

        if (CREC_PREFILL_CUSTOMER > 0) {
            selectCustomer(CREC_PREFILL_CUSTOMER);
        } else {
            renderInvoices();
            renderJournal();
        }
        crecSyncPrintButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
