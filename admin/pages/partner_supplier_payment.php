<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/admin_voucher_print_tuning.php';
require_once __DIR__ . '/../../includes/voucher_print_banner.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';

$pdo = orange_admin_page_pdo();

$ppvPrintTuningMode = orange_admin_voucher_print_tuning_mode();

$ppvCountryId = orange_admin_context_country_id($pdo);
$ppvSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $ppvCountryId);

$ppvTitle = 'سداد فواتير مشتريات آجلة';
$ppvApiUrl = '/admin/api/partners/supplier-payment.php';

$partnerUiTodayDmy = orange_format_date_dmY(date('Y-m-d'));
$ppvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$cashAccId = null;
try {
    $cashAccId = orange_gl_account_id_optional($pdo, 'cash');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] partner_supplier_payment cash account: ' . $e->getMessage());
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

$suppliers = [];
$supplierPayableMap = [];
$ppvSupplierPickRows = [];
if (orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query(
        'SELECT id, name, phone FROM suppliers WHERE 1=1' . $ppvSuppliersCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        try {
            $aid = orange_supplier_payable_account_id($pdo, $sid);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] partner_supplier_payment supplier #' . $sid . ': ' . $e->getMessage());
            }
            $aid = 0;
        }
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$aid]);
        $arow = $st->fetch(PDO::FETCH_ASSOC);
        $supplierPayableMap[$sid] = [
            'id' => $arow ? (int) $arow['id'] : $aid,
            'code' => $arow ? (string) ($arow['code'] ?? '') : '',
            'name' => $arow ? (string) ($arow['name'] ?? '') : ('#' . $aid),
        ];
    }
}

$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}

$ppvApParentId = orange_gl_supplier_parent_account_id($pdo);
$ppvApDescendantSet = [];
if ($ppvApParentId !== null && $ppvApParentId > 0 && orange_table_has_column($pdo, 'accounts', 'parent_id')) {
    $ppvApDescIds = [$ppvApParentId];
    for ($depth = 0; $depth < 10; ++$depth) {
        $ph = implode(',', array_fill(0, count($ppvApDescIds), '?'));
        $chSt = $pdo->prepare("SELECT id FROM accounts WHERE parent_id IN ($ph) AND id NOT IN ($ph)");
        $chSt->execute(array_merge($ppvApDescIds, $ppvApDescIds));
        $newIds = $chSt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($newIds === []) {
            break;
        }
        foreach ($newIds as $nid) {
            $ppvApDescIds[] = (int) $nid;
        }
    }
    $ppvApDescendantSet = array_flip($ppvApDescIds);
}

foreach ($suppliers as $s) {
    $sid = (int) ($s['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $map = $supplierPayableMap[$sid] ?? ['id' => 0, 'code' => '', 'name' => ''];
    $mapAccountId = (int) ($map['id'] ?? 0);
    if ($ppvApDescendantSet !== [] && ($mapAccountId <= 0 || !isset($ppvApDescendantSet[$mapAccountId]))) {
        continue;
    }
    $supplierName = trim((string) ($s['name'] ?? ''));
    $supplierPhone = trim((string) ($s['phone'] ?? ''));
    $accountCode = trim((string) ($map['code'] ?? ''));
    $accountName = trim((string) ($map['name'] ?? ''));
    $balance = (float) ($supBal[$sid] ?? 0.0);
    $ppvSupplierPickRows[] = [
        'id' => $sid,
        'name' => $supplierName,
        'phone' => $supplierPhone,
        'balance' => round($balance, 3),
        'account_id' => $mapAccountId,
        'account_code' => $accountCode,
        'account_name' => $accountName,
    ];
}

$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);
$prefillStmtKind = trim((string) ($_GET['stmt_party_kind'] ?? ''));
$ppvPrefillSupplierId = ($prefillStmtKind === 'supplier' && $prefillStmtId > 0) ? $prefillStmtId : 0;

$nextVoucherNo = 0;
$spayRefPreview = '';
if (orange_journal_vouchers_ready($pdo)) {
    orange_journal_types_sync_canonical_defaults($pdo, $ppvCountryId > 0 ? $ppvCountryId : null);
    $fyPeek = orange_fiscal_find_for_date($pdo, date('Y-m-d'), $ppvCountryId > 0 ? $ppvCountryId : null);
    $fyPeekId = $fyPeek ? (int) $fyPeek['id'] : 0;
    if (
        $fyPeekId > 0
        && orange_table_has_column($pdo, 'journal_vouchers', 'voucher_serial')
    ) {
        $spayMeta = orange_journal_voucher_resolve_serial_meta($pdo, 'supplier_payment', null, $ppvCountryId > 0 ? $ppvCountryId : null);
        $nextVoucherNo = orange_journal_voucher_next_serial($pdo, $fyPeekId, $spayMeta['journal_serial_bucket']);
        $spayRefPreview = orange_voucher_auto_reference_preview(
            $pdo,
            'supplier_payment',
            $fyPeekId,
            $ppvCountryId > 0 ? $ppvCountryId : null
        );
    } else {
        $nextVoucherNo = orange_gl_voucher_next_id_preview($pdo, $ppvCountryId);
    }
}

$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$ppvReady = $ppvCashLock !== null;
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

<div class="card jv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <table class="jv-voucher-print-sheet ta-report-print-table" dir="rtl">
        <?php orange_voucher_print_banner_thead($pdo, $ppvCountryId, ['title_ar' => $ppvTitle]); ?>
        <tbody>
            <tr>
                <td class="jv-voucher-print-body-cell">

    <!-- ١ — المورد + خيار الدفعة المقدمة -->
    <div class="form-grid" style="margin-bottom:16px;">
        <div style="grid-column:1/-1;">
            <label for="spay_supplier_code">المورد</label>
            <div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:10px 14px;">
                <input type="text" id="spay_supplier_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <input type="text" id="spay_supplier_name" class="admin-inp-readonly" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً">
            </div>
            <input type="hidden" id="spay_supplier_id" value="0">
        </div>
        <div style="grid-column:1/-1;" class="form-check jv-print-hide">
            <label><input type="checkbox" id="spay_advance_mode"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                السماح بدفع يزيد عن الذمة (دفعة مقدمة للمورد)
            </label>
        </div>
    </div>

    <!-- ٢ — جدول الفواتير -->
    <div id="spay_invoices_section">
        <div class="table-wrap">
            <table class="admin-table" id="spay_invoices_table">
                <thead>
                    <tr>
                        <th>الفاتورة</th>
                        <th>المبلغ الأصلي</th>
                        <th>المتبقي</th>
                        <th>مبلغ السداد</th>
                    </tr>
                </thead>
                <tbody id="spay_invoices_body">
                    <tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">اختر المورد أولاً</td></tr>
                </tbody>
                <tfoot id="spay_invoices_foot" hidden>
                    <tr style="font-weight:700;background:#f9fafb;border-top:2px solid #e2e8f0;">
                        <td colspan="3">المجموع</td>
                        <td id="spay_invoices_total" dir="ltr" lang="en">0.000</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ٣ — القيد المحاسبي -->
    <div style="margin-top:20px;padding-top:14px;border-top:2px solid #e2e8f0;">
        <h4 style="font-size:0.9rem;font-weight:600;color:#444;margin:0 0 10px;">القيد المحاسبي</h4>
        <?php orange_edit_lock_ui_toolbar(['prefix' => 'spay', 'doc_kind' => 'supplier_payment', 'country_id' => $ppvCountryId]); ?>

        <div class="jv-voucher-header-line jv-voucher-header-line--nav" style="margin-bottom:12px;">
            <div>
                <label for="spay_number_preview">رقم القيد</label>
                <input type="text" id="spay_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;" value="<?php echo $nextVoucherNo > 0 ? (int) $nextVoucherNo : ''; ?>" title="التسلسل ضمن نوع سند صرف المورد والسنة المالية">
            </div>
            <div>
                <label for="spay_date">تاريخ السند</label>
                <input type="text" id="spay_date" class="admin-inp orange-inp-dmy" value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en" autocomplete="off"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="spay_ref">المرجع</label>
                <input type="text" id="spay_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($spayRefPreview, ENT_QUOTES, 'UTF-8'); ?>" title="يُولَّد تلقائياً: SPR-رمز الدولة-رقم القيد" dir="ltr" lang="en" autocomplete="off">
            </div>
            <div>
                <label for="spay_document_entered">تاريخ الإدخال</label>
                <input type="text" id="spay_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;" value="<?php echo htmlspecialchars($ppvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="spay_tot_debit">مجموع المدين</label>
                <input type="text" id="spay_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="spay_tot_credit">مجموع الدائن</label>
                <input type="text" id="spay_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
                    <button type="button" class="btn-secondary jv-nav-btn" id="spay_nav_first" title="أول سند" aria-label="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="spay_nav_prev" title="السند السابق" aria-label="السند السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="spay_nav_next" title="السند التالي" aria-label="السند التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="spay_nav_last" title="آخر سند" aria-label="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="spay_btn_search" title="بحث عن سند">بحث</button>
                </div>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label for="spay_desc">البيان</label>
            <input type="text" id="spay_desc" placeholder="بيان السداد" value=""<?php echo !$ppvReady ? ' disabled' : ''; ?>>
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
                    <tbody id="spay_jv_body"></tbody>
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
            <button type="button" id="spay_btn_new" title="إدخال سند جديد">سند جديد</button>
            <button type="button" class="btn-secondary" id="spay_btn_delete" title="حذف السند المعروض" disabled>حذف السند</button>
            <button type="button" class="btn-secondary" id="spay_btn_print" onclick="spayPrintVoucher(); return false;" title="<?php echo $ppvPrintTuningMode ? 'طباعة السند' : 'احفظ السند أولاً — الطباعة بعد الحفظ فقط'; ?>"<?php echo $ppvPrintTuningMode ? '' : ' disabled'; ?>>طباعة السند</button>
            <button type="button" id="spay_btn_save"<?php echo !$ppvReady ? ' disabled' : ''; ?>>حفظ السند</button>
        </div>
    </div>
</div>

<!-- Supplier Picker Modal -->
<div class="gl-pick-modal jv-print-hide" id="spay_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="spay_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="spay_supplier_pick_title">
        <h3 id="spay_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="spay_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="spay_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="spay_supplier_pick_close">إغلاق</button>
    </div>
</div>

<!-- Search Modal -->
<div id="spay_search_modal" class="jv-search-modal jv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="spay_search_modal_title">
    <div class="jv-search-modal__backdrop" id="spay_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="spay_search_modal_title" class="jv-search-modal__title">بحث في سندات سداد الموردين</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="spay_search_id_from">رقم القيد — من</label>
                        <input type="number" id="spay_search_id_from" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="spay_search_id_to">رقم القيد — إلى</label>
                        <input type="number" id="spay_search_id_to" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="spay_search_date_from">تاريخ السند — من</label>
                        <input type="text" id="spay_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="spay_search_date_to">تاريخ السند — إلى</label>
                        <input type="text" id="spay_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="spay_search_ref">المرجع (يحتوي النص)</label>
                        <input type="text" id="spay_search_ref" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--desc">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="spay_search_desc">بيان القيد العام (يحتوي النص)</label>
                        <input type="text" id="spay_search_desc" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="spay_search_btn">تنفيذ البحث</button>
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
                        <tbody id="spay_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var ORANGE_VOUCHER_PRINT_TUNING = <?php echo $ppvPrintTuningMode ? 'true' : 'false'; ?>;
var spayBrowseId = null;
var spayEditLockCtl = null;
var SPAY_COUNTRY_ID = <?php echo (int) $ppvCountryId; ?>;

function spayPrintVoucher() {
    if (!ORANGE_VOUCHER_PRINT_TUNING && !spayBrowseId) {
        alert('احفظ السند أولاً قبل الطباعة.');
        return false;
    }
    return orangeAdminOpenPrintDialog(
        orangeAdminBuildVoucherPrintDocTitle(null, 'spay_number_preview', 'سند')
    );
}

(function () {
    var SPAY_API = <?php echo json_encode($ppvApiUrl, JSON_UNESCAPED_UNICODE); ?>;
    var SPAY_CASH = <?php echo json_encode($ppvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_READY = <?php echo $ppvReady ? 'true' : 'false'; ?>;
    var SPAY_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_SUPPLIER_PICK_ROWS = <?php echo json_encode($ppvSupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var SPAY_PREFILL_SUPPLIER = <?php echo (int) $ppvPrefillSupplierId; ?>;

    var currentSupplierId = 0;
    var currentInvoices = [];

    function spaySyncRefPreview() {
        if (spayBrowseId) {
            return;
        }
        var refEl = document.getElementById('spay_ref');
        var numEl = document.getElementById('spay_number_preview');
        var dateEl = document.getElementById('spay_date');
        var dIso = dateEl && typeof orangeGetDmyValueAsIso === 'function' ? orangeGetDmyValueAsIso(dateEl) : '';
        postJSON('/admin/api/journal/manage.php', {
            action: 'reference_preview',
            date: dIso || undefined,
            entry_type: 'supplier_payment'
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

    function supplierById(id) {
        id = parseInt(String(id || '0'), 10) || 0;
        for (var i = 0; i < SPAY_SUPPLIER_PICK_ROWS.length; i++) {
            if ((parseInt(String(SPAY_SUPPLIER_PICK_ROWS[i].id || '0'), 10) || 0) === id) return SPAY_SUPPLIER_PICK_ROWS[i];
        }
        return null;
    }

    function isAdvanceMode() {
        var cb = document.getElementById('spay_advance_mode');
        return cb && cb.checked;
    }

    function selectSupplier(id) {
        var row = supplierById(id);
        var codeEl = document.getElementById('spay_supplier_code');
        var nameEl = document.getElementById('spay_supplier_name');
        var idEl = document.getElementById('spay_supplier_id');
        if (!row) {
            currentSupplierId = 0;
            if (codeEl) codeEl.value = '';
            if (nameEl) nameEl.value = '';
            if (idEl) idEl.value = '0';
            currentInvoices = [];
            renderInvoices();
            renderJournal();
            return;
        }
        currentSupplierId = parseInt(String(row.id), 10) || 0;
        if (codeEl) codeEl.value = row.account_code || '';
        if (nameEl) nameEl.value = row.name || '';
        if (idEl) idEl.value = String(currentSupplierId);
        loadInvoices();
    }

    function loadInvoices() {
        if (currentSupplierId <= 0) {
            currentInvoices = [];
            renderInvoices();
            renderJournal();
            return;
        }
        postJSON('/admin/api/partners/open-items.php', { party_kind: 'supplier', party_id: currentSupplierId }).then(function (r) {
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
        var section = document.getElementById('spay_invoices_section');
        var tbody = document.getElementById('spay_invoices_body');
        var tfoot = document.getElementById('spay_invoices_foot');
        if (!section || !tbody || !tfoot) return;

        if (isAdvanceMode()) {
            section.hidden = true;
            return;
        }
        section.hidden = false;

        if (currentSupplierId <= 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">اختر المورد أولاً</td></tr>';
            tfoot.hidden = true;
            return;
        }
        if (currentInvoices.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:12px;">لا توجد فواتير آجلة مفتوحة لهذا المورد</td></tr>';
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
                '<td><input type="number" class="spay-inv-amt admin-inp-money" step="any" min="0" max="' + orangeFmtMoney(inv.open) + '" placeholder="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" data-idx="' + idx + '"></td>';
            tbody.appendChild(tr);
            tr.querySelector('.spay-inv-amt').addEventListener('input', function () {
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
        var el = document.getElementById('spay_invoices_total');
        if (el) el.textContent = orangeFmtMoney(total);
    }

    function getTotal() {
        if (isAdvanceMode()) {
            var inp = document.querySelector('#spay_jv_body [data-spay-advance="1"] .spay-adv-amt');
            return parseFloat(inp ? inp.value : '0') || 0;
        }
        var t = 0;
        currentInvoices.forEach(function (inv) { t += inv.amount; });
        return t;
    }

    function renderJournal() {
        var tb = document.getElementById('spay_jv_body');
        if (!tb || !SPAY_READY || !SPAY_CASH) return;
        tb.innerHTML = '';

        var map = SPAY_SUPPLIER_PAYABLE[String(currentSupplierId)] || { id: 0, code: '', name: '' };
        var accCode = map.code || '';
        var accName = map.name || '';
        var supplierRow = supplierById(currentSupplierId);
        var supplierName = supplierRow ? supplierRow.name : '';

        if (isAdvanceMode()) {
            var tr = document.createElement('tr');
            tr.className = 'jv-line-main';
            tr.setAttribute('data-spay-advance', '1');
            tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(accName) + '" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="spay-adv-amt admin-inp-money" step="any" min="0" placeholder="مبلغ الدفعة" inputmode="decimal" lang="en" dir="ltr"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
            tb.appendChild(tr);
            tr.querySelector('.spay-adv-amt').addEventListener('input', function () { recalcTotals(); });
        } else {
            currentInvoices.forEach(function (inv) {
                if (inv.amount <= 0) return;
                var tr = document.createElement('tr');
                tr.className = 'jv-line-main';
                tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(accCode) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(supplierName + ' — ' + inv.label) + '" readonly tabindex="-1"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + orangeFmtMoney(inv.amount) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
                    '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
                tb.appendChild(tr);
            });
        }

        var total = getTotal();
        var cashTr = document.createElement('tr');
        cashTr.className = 'jv-line-main jv-line-cash-locked';
        cashTr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + esc(SPAY_CASH.code || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + esc(SPAY_CASH.name || '') + '" readonly tabindex="-1"></td>' +
            '<td><input type="text" class="admin-inp-money" value="' + orangeMoneyZero() + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>' +
            '<td><input type="text" class="admin-inp-money" value="' + orangeFmtMoney(total) + '" readonly data-money-allow-zero tabindex="-1" dir="ltr" lang="en"></td>';
        tb.appendChild(cashTr);
        recalcTotals();
    }

    function recalcTotals() {
        var total = getTotal();
        var dEl = document.getElementById('spay_tot_debit');
        var cEl = document.getElementById('spay_tot_credit');
        if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
            window.OrangeMoney.setJvTotals(dEl, cEl, total, total);
        } else {
            if (dEl) dEl.value = orangeFmtMoney(total);
            if (cEl) cEl.value = orangeFmtMoney(total);
        }
        // Update cash line
        var cashTr = document.querySelector('#spay_jv_body .jv-line-cash-locked');
        if (cashTr) {
            var cells = cashTr.querySelectorAll('input.admin-inp-money');
            if (cells.length >= 2) {
                cells[0].value = orangeMoneyZero();
                cells[1].value = orangeFmtMoney(total);
            }
        }
    }

    // Picker
    function pickerOpen() {
        var modal = document.getElementById('spay_supplier_pick_modal');
        var qEl = document.getElementById('spay_supplier_pick_q');
        if (!modal || !qEl) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('gl-pick-open');
        qEl.value = '';
        pickerRender('');
        qEl.focus();
    }
    function pickerClose() {
        var modal = document.getElementById('spay_supplier_pick_modal');
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('gl-pick-open');
    }
    function pickerRender(q) {
        var listEl = document.getElementById('spay_supplier_pick_list');
        if (!listEl) return;
        var query = String(q || '').trim().toLowerCase();
        var rows = SPAY_SUPPLIER_PICK_ROWS.filter(function (r) {
            if (!query) return true;
            var hay = (r.account_code + ' ' + r.account_name + ' ' + r.name + ' ' + r.phone).toLowerCase();
            return hay.indexOf(query) !== -1;
        });
        listEl.innerHTML = '';
        if (!rows.length) { listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>'; return; }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'gl-pick-item';
            li.setAttribute('role', 'button');
            li.tabIndex = 0;
            li.textContent = (r.account_code ? r.account_code + ' — ' : '') + r.name + (r.phone ? ' (' + r.phone + ')' : '') + ' [رصيد ' + orangeFmtMoney(r.balance) + ']';
            li.addEventListener('dblclick', function () { selectSupplier(r.id); pickerClose(); });
            li.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { selectSupplier(r.id); pickerClose(); } });
            listEl.appendChild(li);
        });
    }

    // Save
    function save() {
        if (!SPAY_CASH || !SPAY_CASH.id) return;
        var sid = currentSupplierId;
        if (sid <= 0) { alert('اختر المورد أولاً'); return; }
        var dIso = orangeGetDmyValueAsIso(document.getElementById('spay_date'));
        if (!dIso) { alert('أدخل تاريخ السند (يوم/شهر/سنة)'); return; }
        var desc = (document.getElementById('spay_desc').value || '').trim() || 'سداد فواتير مشتريات آجلة';
        var advance = isAdvanceMode();
        var totalAmt = 0;
        var allocations = [];

        if (advance) {
            var advInp = document.querySelector('#spay_jv_body [data-spay-advance="1"] .spay-adv-amt');
            totalAmt = parseFloat(advInp ? advInp.value : '0') || 0;
            if (totalAmt <= 0) { alert('أدخل مبلغ الدفعة المقدمة'); return; }
        } else {
            currentInvoices.forEach(function (inv) {
                if (inv.amount > 0) {
                    totalAmt += inv.amount;
                    allocations.push({ ref_type: inv.ref_type, ref_id: inv.ref_id, amount: inv.amount });
                }
            });
            if (totalAmt <= 0) { alert('حدد مبلغ السداد لفاتورة واحدة على الأقل'); return; }
        }

        var payload = {
            supplier_id: sid,
            amount: totalAmt,
            date: dIso,
            description: desc,
            allow_excess: advance,
            allocations: allocations
        };
        postJSON(SPAY_API, payload).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم');
                location.reload();
            } else {
                alert(r.message || 'فشل');
            }
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    // Navigation & Search — uses /admin/api/journal/manage.php like سند الصرف

    function spaySyncPrintButton() {
        var pb = document.getElementById('spay_btn_print');
        if (!pb) {
            return;
        }
        if (ORANGE_VOUCHER_PRINT_TUNING) {
            pb.disabled = false;
            pb.title = 'طباعة السند';
            return;
        }
        var ok = !!spayBrowseId;
        pb.disabled = !ok;
        pb.title = ok ? 'طباعة السند' : 'احفظ السند أولاً — الطباعة بعد الحفظ فقط (§10)';
    }

    function spayNav(where) {
        var payload = {
            action: 'nav_manual',
            entry_type: 'supplier_payment',
            where: where,
            current_id: spayBrowseId || 0
        };
        postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
            if (!r.success || !r.id) {
                alert(r.message || 'لا توجد سندات من هذا النوع بعد');
                return;
            }
            spayLoadVoucher(r.id);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function spayLoadVoucher(id) {
        postJSON('/admin/api/journal/manage.php', { action: 'get', id: id, entry_type: 'supplier_payment' }).then(function (r) {
            if (!r.success || !r.voucher) {
                alert(r.message || 'تعذر تحميل السند');
                return;
            }
            spayBrowseId = r.voucher.id;
            spayDisplayVoucher(r);
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function spayDisplayVoucher(r) {
        var v = r.voucher;
        document.getElementById('spay_number_preview').value = String(v.voucher_serial || v.id || '');
        document.getElementById('spay_ref').value = v.reference || '';
        document.getElementById('spay_date').value = v.voucher_date_dmy || v.voucher_date || '';
        document.getElementById('spay_desc').value = v.description || '';
        var total = 0;
        (r.lines || []).forEach(function (l) { total += parseFloat(String(l.debit || '0')) || 0; });
        if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
            window.OrangeMoney.setJvTotals('spay_tot_debit', 'spay_tot_credit', total, total);
        } else {
            document.getElementById('spay_tot_debit').value = orangeFmtMoney(total);
            document.getElementById('spay_tot_credit').value = orangeFmtMoney(total);
        }
        document.getElementById('spay_btn_delete').disabled = false;
        spaySyncPrintButton();
        if (spayEditLockCtl && spayEditLockCtl.refresh) {
            spayEditLockCtl.refresh();
        }

        // Load supplier from subledger
        if (r.party_supplier_id) {
            selectSupplier(parseInt(String(r.party_supplier_id), 10) || 0);
        }

        // Rebuild journal lines from loaded voucher
        var tb = document.getElementById('spay_jv_body');
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

    function spayDeleteVoucher() {
        if (!spayBrowseId) {
            alert('لا يوجد سند محفوظ للحذف');
            return;
        }
        if (!confirm('تأكيد حذف هذا السند؟ لا يمكن التراجع.')) {
            return;
        }
        postJSON('/admin/api/journal/manage.php', { action: 'delete', id: spayBrowseId }).then(function (r) {
            if (r.success) {
                alert(r.message || 'تم الحذف');
                location.reload();
                return;
            }
            alert(r.message || 'فشل الحذف');
        }).catch(function (e) { alert(e.message || String(e)); });
    }

    function spaySearchOpen() {
        var m = document.getElementById('spay_search_modal');
        if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); }
    }
    function spaySearchClose() {
        var m = document.getElementById('spay_search_modal');
        if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); }
    }
    function spaySearchRun() {
        var idFrom = parseInt(document.getElementById('spay_search_id_from').value) || 0;
        var idTo = parseInt(document.getElementById('spay_search_id_to').value) || 0;
        var dateFrom = orangeGetDmyValueAsIso(document.getElementById('spay_search_date_from')) || '';
        var dateTo = orangeGetDmyValueAsIso(document.getElementById('spay_search_date_to')) || '';
        var ref = (document.getElementById('spay_search_ref').value || '').trim();
        var desc = (document.getElementById('spay_search_desc').value || '').trim();
        var tbody = document.getElementById('spay_search_results');
        tbody.innerHTML = '<tr><td colspan="5">جاري البحث…</td></tr>';
        var payload = {
            action: 'search',
            entry_type: 'supplier_payment'
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
                tr.addEventListener('dblclick', function () { spayLoadVoucher(v.id); spaySearchClose(); });
                tbody.appendChild(tr);
            });
        }).catch(function (e) {
            tbody.innerHTML = '<tr><td colspan="5">' + esc(e.message || String(e)) + '</td></tr>';
        });
    }

    document.addEventListener('mousedown', function (ev) {
        var m = document.getElementById('spay_search_modal');
        if (!m || m.style.display !== 'flex') return;
        var panel = m.querySelector('.jv-search-modal__panel');
        if (panel && (panel === ev.target || panel.contains(ev.target))) return;
        if (ev.target.closest && ev.target.closest('#spay_btn_search')) return;
        spaySearchClose();
    }, true);

    // Init
    function init() {
        var codeEl = document.getElementById('spay_supplier_code');
        if (codeEl) {
            codeEl.addEventListener('dblclick', function (e) { e.preventDefault(); pickerOpen(); });
            codeEl.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pickerOpen(); } });
        }
        document.getElementById('spay_supplier_pick_backdrop').addEventListener('click', pickerClose);
        document.getElementById('spay_supplier_pick_close').addEventListener('click', pickerClose);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') pickerClose(); });

        var pickQ = document.getElementById('spay_supplier_pick_q');
        var pickTimer = null;
        if (pickQ) {
            pickQ.addEventListener('input', function () {
                if (pickTimer) clearTimeout(pickTimer);
                pickTimer = setTimeout(function () { pickerRender(pickQ.value || ''); }, 180);
            });
        }

        var spayAdvanceMode = document.getElementById('spay_advance_mode');
        if (spayAdvanceMode) {
            spayAdvanceMode.addEventListener('change', function () {
                renderInvoices();
                renderJournal();
            });
        }

        document.getElementById('spay_btn_save').addEventListener('click', save);
        document.getElementById('spay_btn_new').addEventListener('click', function () { location.reload(); });
        document.getElementById('spay_btn_delete').addEventListener('click', spayDeleteVoucher);

        if (window.OrangeEditLock) {
            spayEditLockCtl = OrangeEditLock.bind({
                prefix: 'spay',
                docKind: 'supplier_payment',
                page: 'partner_supplier_payment',
                countryId: SPAY_COUNTRY_ID,
                getEntityId: function () { return spayBrowseId || 0; }
            });
        }

        document.getElementById('spay_nav_first').addEventListener('click', function () { spayNav('first'); });
        document.getElementById('spay_nav_prev').addEventListener('click', function () { spayNav('prev'); });
        document.getElementById('spay_nav_next').addEventListener('click', function () { spayNav('next'); });
        document.getElementById('spay_nav_last').addEventListener('click', function () { spayNav('last'); });
        document.getElementById('spay_btn_search').addEventListener('click', spaySearchOpen);

        document.getElementById('spay_search_btn').addEventListener('click', spaySearchRun);
        document.getElementById('spay_search_modal_backdrop').addEventListener('click', spaySearchClose);

        var spayDateEl = document.getElementById('spay_date');
        if (spayDateEl && !spayDateEl.getAttribute('data-spay-ref-bound')) {
            spayDateEl.setAttribute('data-spay-ref-bound', '1');
            spayDateEl.addEventListener('blur', function () {
                if (typeof orangeNormalizeDmyInput === 'function') {
                    orangeNormalizeDmyInput(spayDateEl);
                }
                spaySyncRefPreview();
            });
        }

        if (SPAY_PREFILL_SUPPLIER > 0) {
            selectSupplier(SPAY_PREFILL_SUPPLIER);
        } else {
            renderInvoices();
            renderJournal();
        }
        spaySyncPrintButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
