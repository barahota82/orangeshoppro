<?php

declare(strict_types=1);

if (!isset($ppvKind) || !in_array($ppvKind, ['customer_receipt', 'supplier_payment'], true)) {
    throw new RuntimeException('partner_party_voucher_ui: set $ppvKind to customer_receipt or supplier_payment.');
}

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/gl_settings.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/supplier_payable_account.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/edit_lock_ui.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';

$pdo = orange_admin_page_pdo();
$ppvMoney = isset($orangeAdminMoney) && is_array($orangeAdminMoney)
    ? $orangeAdminMoney
    : orange_admin_currency_context($pdo);
$ppvMoneyDecimals = (int) ($ppvMoney['decimals'] ?? 3);
$ppvFmtMoney = static function (float $amount) use ($ppvMoney): string {
    return orange_format_money_for_context($ppvMoney, $amount, false);
};

$ppvPermPage = $ppvKind === 'customer_receipt' ? 'partner_customer_receipt' : 'partner_supplier_payment';
$ppvCaps = orange_admin_caps_for_page($admin, $pdo, $ppvPermPage);

$ppvCountryId = orange_admin_context_country_id($pdo);
$ppvCustomersCountrySql = orange_sql_country_and_fragment($pdo, 'customers', 'customers', $ppvCountryId);
$ppvSuppliersCountrySql = orange_sql_country_and_fragment($pdo, 'suppliers', 'suppliers', $ppvCountryId);

$ppvIsReceipt = $ppvKind === 'customer_receipt';
$ppvTitle = $ppvIsReceipt ? 'سداد فواتير مبيعات آجلة' : 'سداد فواتير مشتريات آجلة';
$ppvCardTitle = $ppvIsReceipt ? 'سداد فواتير مبيعات آجلة (خزينة ↔ عملاء آجل)' : 'سداد فواتير مشتريات آجلة';
$ppvApiUrl = $ppvIsReceipt
    ? '/admin/api/partners/customer-receipt.php'
    : '/admin/api/partners/supplier-payment.php';
$ppvOpenItemsKind = $ppvIsReceipt ? 'customer' : 'supplier';

$partnerUiTodayDmy = orange_format_date_dmY(date('Y-m-d'));
$ppvFormDocumentEnteredDisplay = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$cashAccId = null;
try {
    $cashAccId = orange_gl_account_id_optional($pdo, 'cash');
} catch (Throwable $e) {
    if (function_exists('error_log')) {
        error_log('[orange] partner_party_voucher_ui cash account: ' . $e->getMessage());
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

$ppvPartyDefaultAcc = null;
if ($ppvIsReceipt) {
    $arId = null;
    try {
        $arId = orange_gl_account_id_optional($pdo, 'ar_credit');
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] partner_party_voucher_ui ar_credit account: ' . $e->getMessage());
        }
    }
    if ($arId !== null && $arId > 0) {
        $st = $pdo->prepare('SELECT id, code, name FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([(int) $arId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $ppvPartyDefaultAcc = [
                'id' => (int) $r['id'],
                'code' => (string) ($r['code'] ?? ''),
                'name' => (string) ($r['name'] ?? ''),
            ];
        }
    }
}

$customers = [];
$suppliers = [];
$supplierPayableMap = [];
$ppvSupplierPickRows = [];

if ($ppvIsReceipt && orange_table_exists($pdo, 'customers')) {
    $customers = $pdo->query(
        'SELECT id, name_ar, phone FROM customers WHERE 1=1' . $ppvCustomersCountrySql . ' ORDER BY id DESC'
    )->fetchAll(PDO::FETCH_ASSOC);
} elseif (!$ppvIsReceipt && orange_table_exists($pdo, 'suppliers')) {
    $suppliers = $pdo->query(
        'SELECT id, name, phone FROM suppliers WHERE 1=1' . $ppvSuppliersCountrySql . ' ORDER BY name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($suppliers as $s) {
        $sid = (int) $s['id'];
        try {
            $aid = orange_supplier_payable_account_id($pdo, $sid);
        } catch (Throwable $e) {
            if (function_exists('error_log')) {
                error_log('[orange] partner_party_voucher_ui supplier #' . $sid . ': ' . $e->getMessage());
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

$custBal = [];
foreach ($customers as $c) {
    $custBal[(int) $c['id']] = orange_party_balance_customer($pdo, (int) $c['id']);
}
$supBal = [];
foreach ($suppliers as $s) {
    $supBal[(int) $s['id']] = orange_party_balance_supplier($pdo, (int) $s['id']);
}
if (!$ppvIsReceipt) {
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
        $searchTextRaw = trim($accountCode . ' ' . $accountName . ' ' . $supplierName . ' ' . $supplierPhone);
        $searchText = function_exists('mb_strtolower')
            ? mb_strtolower($searchTextRaw, 'UTF-8')
            : strtolower($searchTextRaw);
        $ppvSupplierPickRows[] = [
            'id' => $sid,
            'name' => $supplierName,
            'phone' => $supplierPhone,
            'balance' => round($balance, $ppvMoneyDecimals),
            'account_id' => $mapAccountId,
            'account_code' => $accountCode,
            'account_name' => $accountName,
            'search_text' => $searchText,
        ];
    }
}

$prefillStmtKind = in_array((string) ($_GET['stmt_party_kind'] ?? ''), ['customer', 'supplier'], true)
    ? (string) $_GET['stmt_party_kind']
    : '';
$prefillStmtId = (int) ($_GET['stmt_party_id'] ?? 0);

$ppvPrefill = ['party_id' => 0];
if ($ppvIsReceipt && $prefillStmtKind === 'customer' && $prefillStmtId > 0) {
    $ppvPrefill['party_id'] = $prefillStmtId;
} elseif (!$ppvIsReceipt && $prefillStmtKind === 'supplier' && $prefillStmtId > 0) {
    $ppvPrefill['party_id'] = $prefillStmtId;
}

$jvGlSettingsUrl = storefront_public_path('/admin/index.php?page=gl_account_settings');
$ppvHeaderLineClass = 'jv-voucher-header-line jv-voucher-header-line--nav';
$ppvReady = $ppvCashLock !== null && (!$ppvIsReceipt || $ppvPartyDefaultAcc !== null);

$ppvPostingLeafCt = 0;
if (orange_journal_vouchers_ready($pdo)) {
    $ppvLw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    try {
        $ppvCountSql = "SELECT COUNT(*) FROM accounts a WHERE $ppvLw";
        $ppvCountParams = [];
        $ppvAcctFilter = orange_accounts_sql_country_filter($pdo, 'a');
        if ($ppvAcctFilter !== null) {
            $ppvCountSql .= $ppvAcctFilter['sql'];
            $ppvCountParams = $ppvAcctFilter['params'];
        }
        if ($ppvCountParams !== []) {
            $ppvCountSt = $pdo->prepare($ppvCountSql);
            $ppvCountSt->execute($ppvCountParams);
            $ppvPostingLeafCt = (int) $ppvCountSt->fetchColumn();
        } else {
            $ppvPostingLeafCt = (int) $pdo->query($ppvCountSql)->fetchColumn();
        }
    } catch (Throwable $e) {
        $ppvPostingLeafCt = 0;
    }
}
?>
<style>
.ppv-supplier-pick-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
    gap: 10px 14px;
}
.ppv-supplier-pick-fields input[readonly] {
    background: #f8fafc;
    cursor: default;
}
.ppv-supplier-pick-fields input[disabled] {
    background: #f8fafc;
    cursor: default;
}
@media (max-width: 600px) {
    .ppv-supplier-pick-fields {
        grid-template-columns: 1fr;
    }
}
</style>
<div class="page-title ppv-print-hide">
    <h1><?php echo htmlspecialchars($ppvTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
    <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars(orange_admin_page_country_label($pdo), ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if (orange_journal_vouchers_ready($pdo) && $ppvPostingLeafCt === 0): ?>
<div class="card ppv-print-hide" style="border:1px solid #fcd34d;background:#fffbeb;margin-bottom:12px;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ القيود المحاسبية للسداد تستهدف الخزينة وحسابات الذمم كأوراق ترحيل. <strong>الشاشة تعمل</strong> بعد ربط الإعدادات — المتوقَّع تأخراً حتى إكمال «الدليل المحاسبي».</p>
</div>
<?php endif; ?>

<div class="card ppv-print-area">
    <h3 class="card-title"><?php echo htmlspecialchars($ppvCardTitle, ENT_QUOTES, 'UTF-8'); ?></h3>
    <?php orange_edit_lock_ui_toolbar(['prefix' => 'ppv', 'doc_kind' => $ppvKind, 'country_id' => $ppvCountryId, 'show_status_badge' => false]); ?>
    <?php if ($ppvCashLock === null): ?>
    <p class="card-hint ppv-print-hide" style="margin:0 0 12px;">اربط حساب <strong>الخزينة / النقدية</strong> من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
    <?php endif; ?>
    <?php if ($ppvIsReceipt && $ppvPartyDefaultAcc === null): ?>
    <p class="card-hint ppv-print-hide" style="margin:0 0 12px;">اربط حساب <strong>عملاء آجل</strong> (<code>ar_credit</code>) من <a href="<?php echo htmlspecialchars($jvGlSettingsUrl, ENT_QUOTES, 'UTF-8'); ?>">حسابات القيود التلقائية</a>.</p>
    <?php endif; ?>

    <div class="form-grid">
        <?php if ($ppvIsReceipt): ?>
        <div style="grid-column:1/-1;">
            <label for="ppv_party">العميل</label>
            <select id="ppv_party" class="admin-inp"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <?php if (!$customers): ?>
                    <option value="0">— لا يوجد عملاء —</option>
                <?php endif; ?>
                <?php foreach ($customers as $c): ?>
                    <option value="<?php echo (int) $c['id']; ?>">
                        <?php echo htmlspecialchars($c['name_ar'] . ' — ' . $c['phone'], ENT_QUOTES, 'UTF-8'); ?>
                        (رصيد <?php echo htmlspecialchars($ppvFmtMoney((float) ($custBal[(int) $c['id']] ?? 0.0)), ENT_QUOTES, 'UTF-8'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php else: ?>
        <input type="hidden" id="ppv_party" value="0">
        <?php endif; ?>

        <div class="<?php echo htmlspecialchars($ppvHeaderLineClass, ENT_QUOTES, 'UTF-8'); ?>" style="grid-column:1/-1;">
            <div>
                <label for="ppv_number_preview">رقم القيد</label>
                <input type="text" id="ppv_number_preview" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;text-align:center;"
                    value="—"
                    title="يُخصَّص بعد الحفظ">
            </div>
            <div>
                <label for="ppv_date">تاريخ السند</label>
                <input type="text" id="ppv_date" class="admin-inp orange-inp-dmy"
                    value="<?php echo htmlspecialchars($partnerUiTodayDmy, ENT_QUOTES, 'UTF-8'); ?>"
                    dir="ltr" lang="en" autocomplete="off"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
            </div>
            <div>
                <label for="ppv_ref">المرجع</label>
                <input type="text" id="ppv_ref" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value=""
                    autocomplete="off">
            </div>
            <div>
                <label for="ppv_document_entered">تاريخ الإدخال</label>
                <input type="text" id="ppv_document_entered" readonly class="admin-inp-readonly" style="background:#f4f4f5;cursor:default;"
                    value="<?php echo htmlspecialchars($ppvFormDocumentEnteredDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                    dir="ltr" lang="en">
            </div>
            <div>
                <label for="ppv_tot_debit">مجموع المدين</label>
                <input type="text" id="ppv_tot_debit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div>
                <label for="ppv_tot_credit">مجموع الدائن</label>
                <input type="text" id="ppv_tot_credit" readonly class="admin-inp-readonly jv-tot-readonly" value="<?php echo htmlspecialchars($orangeAdminMoneyZero ?? '0.000', ENT_QUOTES, 'UTF-8'); ?>" dir="ltr" lang="en">
            </div>
            <div class="jv-voucher-nav-cell jv-print-hide">
                <?php if ($ppvIsReceipt): ?>
                <div class="jv-voucher-nav-btns" role="group" aria-label="تنقل بين السندات">
                    <button type="button" class="btn-secondary jv-nav-btn" id="ppv_nav_first" title="أول سند" aria-label="أول سند">&lt;&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="ppv_nav_prev" title="السند السابق" aria-label="السند السابق">&lt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="ppv_nav_next" title="السند التالي" aria-label="السند التالي">&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-btn" id="ppv_nav_last" title="آخر سند" aria-label="آخر سند">&gt;&gt;</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ppv_btn_search_vouchers" title="بحث عن سند">بحث</button>
                </div>
                <?php endif; ?>
                <div class="jv-voucher-nav-btns ppv-voucher-action-btns" role="group" aria-label="إجراءات السند">
                    <button type="button" id="ppv_btn_save" data-orange-perm="edit" data-orange-page="<?php echo htmlspecialchars($ppvPermPage, ENT_QUOTES, 'UTF-8'); ?>"<?php echo !$ppvReady ? ' disabled' : ''; ?>>حفظ السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ppv_btn_print" title="طباعة">طباعة السند</button>
                    <button type="button" class="btn-secondary jv-nav-search" id="ppv_btn_new" title="سند جديد">سند جديد</button>
                    <?php if ($ppvIsReceipt): ?>
                    <button type="button" class="btn-secondary" id="ppv_btn_delete" title="حذف السند المعروض" disabled>حذف السند</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="grid-column:1/-1;">
            <label for="ppv_desc">البيان</label>
            <input type="text" id="ppv_desc" placeholder="<?php echo $ppvIsReceipt ? 'بيان السداد — مبيعات آجل' : 'بيان السداد — مشتريات آجل'; ?>"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
        </div>

        <div style="grid-column:1/-1;" class="form-check ppv-print-hide">
            <label><input type="checkbox" id="ppv_allow_excess"<?php echo !$ppvReady ? ' disabled' : ''; ?>>
                <?php echo $ppvIsReceipt ? 'السماح بقبض يزيد عن رصيد الذمة (سلفة / دفعة مقدمة)' : 'السماح بدفع يزيد عن الذمة (دفعة مقدمة للمورد)'; ?>
            </label>
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
                        <th class="admin-doc-col-actions" aria-label=""></th>
                    </tr>
                </thead>
                <tbody id="ppv_lines_body"></tbody>
            </table>
        </div>
    </div>

    <div style="grid-column:1/-1; margin-top:12px; padding-top:12px; border-top:1px solid #e4e4e7;" class="ppv-print-hide">
        <button type="button" class="btn-secondary" id="ppv_btn_load_alloc"<?php echo !$ppvReady ? ' disabled' : ''; ?>>تحميل المستندات ذات الرصيد</button>
        <div class="table-wrap" style="margin-top:8px;">
            <table class="admin-table">
                <thead><tr><th>مستند</th><th>متبقي</th><th>تخصيص</th></tr></thead>
                <tbody id="ppv_alloc_tbody"></tbody>
            </table>
        </div>
    </div>

</div>

<?php if (!$ppvIsReceipt): ?>
<div class="gl-pick-modal ppv-print-hide" id="ppv_supplier_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="ppv_supplier_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="ppv_supplier_pick_title">
        <h3 id="ppv_supplier_pick_title" class="gl-pick-modal__title">اختيار المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">دبل كليك للاختيار</p>
        <input type="search" id="ppv_supplier_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بكود الحساب أو اسم الحساب أو اسم المورد…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="ppv_supplier_pick_list"></ul>
        <button type="button" class="btn-secondary" id="ppv_supplier_pick_close">إغلاق</button>
    </div>
</div>
<?php endif; ?>

<?php if ($ppvIsReceipt): ?>
<div id="ppv_search_modal" class="jv-search-modal ppv-print-hide" style="display:none;" aria-hidden="true" role="dialog" aria-labelledby="ppv_search_modal_title">
    <div class="jv-search-modal__backdrop" id="ppv_search_modal_backdrop"></div>
    <div class="jv-search-modal__panel">
        <div class="jv-search-modal__head">
            <h3 id="ppv_search_modal_title" class="jv-search-modal__title">بحث في سندات سداد العملاء</h3>
        </div>
        <div class="jv-search-modal__body">
            <div class="jv-search-modal__form">
                <div class="jv-search-modal__row jv-search-modal__row--fields">
                    <div class="jv-search-field jv-search-field--id">
                        <label for="ppv_search_id_from">رقم القيد — من</label>
                        <input type="number" id="ppv_search_id_from" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--id">
                        <label for="ppv_search_id_to">رقم القيد — إلى</label>
                        <input type="number" id="ppv_search_id_to" class="admin-inp" min="1" step="1" placeholder="" dir="ltr" lang="en">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="ppv_search_date_from">تاريخ السند — من</label>
                        <input type="text" id="ppv_search_date_from" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--date">
                        <label for="ppv_search_date_to">تاريخ السند — إلى</label>
                        <input type="text" id="ppv_search_date_to" class="admin-inp orange-inp-dmy" dir="ltr" lang="en" autocomplete="off">
                    </div>
                    <div class="jv-search-field jv-search-field--ref">
                        <label for="ppv_search_ref">المرجع (يحتوي النص)</label>
                        <input type="text" id="ppv_search_ref" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
                <div class="jv-search-modal__row jv-search-modal__row--desc">
                    <div class="jv-search-field jv-search-field--full">
                        <label for="ppv_search_desc">بيان القيد العام (يحتوي النص)</label>
                        <input type="text" id="ppv_search_desc" class="admin-inp" placeholder="" autocomplete="off" dir="auto">
                    </div>
                </div>
            </div>
            <div class="actions jv-search-modal__actions">
                <button type="button" id="ppv_search_btn">تنفيذ البحث</button>
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
                                <th>المبلغ</th>
                            </tr>
                        </thead>
                        <tbody id="ppv_search_results"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var PPV_IS_RECEIPT = <?php echo $ppvIsReceipt ? 'true' : 'false'; ?>;
var PPV_API = <?php echo json_encode($ppvApiUrl, JSON_UNESCAPED_UNICODE); ?>;
var PPV_OPEN_KIND = <?php echo json_encode($ppvOpenItemsKind, JSON_UNESCAPED_UNICODE); ?>;
var PPV_CASH = <?php echo json_encode($ppvCashLock, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_READY = <?php echo $ppvReady ? 'true' : 'false'; ?>;
var PPV_PARTY_DEFAULT = <?php echo json_encode($ppvPartyDefaultAcc, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_SUPPLIER_PAYABLE = <?php echo json_encode($supplierPayableMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_PREFILL = <?php echo json_encode($ppvPrefill, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_SUPPLIER_PICK_ROWS = <?php echo json_encode($ppvSupplierPickRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
var PPV_BROWSE_ENTRY_TYPE = <?php echo json_encode($ppvIsReceipt ? 'customer_receipt' : 'supplier_payment', JSON_UNESCAPED_UNICODE); ?>;
var ppvSupplierPickTimer = null;
var ppvBrowseId = null;
var PPV_COUNTRY_ID = <?php echo (int) $ppvCountryId; ?>;
var PPV_PERM_PAGE = <?php echo json_encode($ppvPermPage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
var PPV_CAPS = <?php echo json_encode($ppvCaps, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
var ppvEditLockCtl = null;

function ppvEscapeHtml(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function ppvPairSeqRef() {
    window._ppvPairSeq = (window._ppvPairSeq || 0) + 1;
    return 'ppv' + String(window._ppvPairSeq);
}

function ppvMemoRow(mainTr) {
    if (!mainTr) return null;
    var pair = mainTr.getAttribute('data-jv-pair');
    var n = mainTr.nextElementSibling;
    if (n && n.classList.contains('jv-line-memo') && n.getAttribute('data-jv-pair') === pair) {
        return n;
    }
    return null;
}

function ppvPartyMainRow() {
    return document.querySelector('#ppv_lines_body tr.jv-line-main[data-ppv-party="1"]');
}

function ppvCashMainRow() {
    return document.querySelector('#ppv_lines_body tr.jv-line-main[data-jv-cash-locked="1"]');
}

function ppvPartyIdValue() {
    var partyEl = document.getElementById('ppv_party');
    return parseInt(String(partyEl && partyEl.value || '0'), 10) || 0;
}

function ppvSupplierPickRowById(id) {
    var wanted = parseInt(String(id || '0'), 10) || 0;
    if (wanted <= 0) {
        return null;
    }
    for (var i = 0; i < PPV_SUPPLIER_PICK_ROWS.length; i++) {
        var row = PPV_SUPPLIER_PICK_ROWS[i];
        if ((parseInt(String(row.id || '0'), 10) || 0) === wanted) {
            return row;
        }
    }
    return null;
}

function ppvSupplierSyncUiById(id) {
    if (PPV_IS_RECEIPT) {
        return;
    }
    var partyTr = ppvPartyMainRow();
    if (!partyTr) {
        return;
    }
    var codeEl = partyTr.querySelector('.jv-acc-code');
    var nameEl = partyTr.querySelector('.jv-acc-name');
    var idEl = partyTr.querySelector('.jv-acc-id');
    var row = ppvSupplierPickRowById(id);
    if (!row) {
        if (codeEl) codeEl.value = '';
        if (nameEl) nameEl.value = '';
        if (idEl) idEl.value = '';
        return;
    }
    if (codeEl) codeEl.value = String(row.account_code || '');
    if (nameEl) nameEl.value = String(row.account_name || row.name || '');
    if (idEl) idEl.value = String(row.account_id || '');
}

function ppvSupplierPickClose() {
    var modal = document.getElementById('ppv_supplier_pick_modal');
    if (!modal) {
        return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gl-pick-open');
}

function ppvSupplierPickChoose(row) {
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        return;
    }
    var partyEl = document.getElementById('ppv_party');
    if (!partyEl) {
        return;
    }
    partyEl.value = String(parseInt(String(row.id || '0'), 10) || 0);
    ppvSupplierSyncUiById(row.id || 0);
    ppvSupplierPickClose();
    ppvOnPartyChanged();
}

function ppvSupplierPickRender(q) {
    var listEl = document.getElementById('ppv_supplier_pick_list');
    if (!listEl) {
        return;
    }
    var query = String(q || '').trim().toLowerCase();
    var rows = PPV_SUPPLIER_PICK_ROWS.filter(function (row) {
        if (!query) {
            return true;
        }
        return String(row.search_text || '').toLowerCase().indexOf(query) !== -1;
    });
    listEl.innerHTML = '';
    if (!rows.length) {
        listEl.innerHTML = '<li class="gl-pick-empty">لا توجد نتائج</li>';
        return;
    }
    rows.forEach(function (row) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item';
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        var label = String(row.account_code || '').trim();
        var supplierName = String(row.name || '').trim();
        var accountName = String(row.account_name || '').trim();
        var phone = String(row.phone || '').trim();
        var metaParts = [];
        if (accountName !== '') {
            metaParts.push(accountName);
        }
        if (phone !== '') {
            metaParts.push(phone);
        }
        li.textContent = (label !== '' ? label + ' — ' : '') + supplierName + (metaParts.length ? (' (' + metaParts.join(' • ') + ')') : '');
        li.addEventListener('dblclick', function () {
            ppvSupplierPickChoose(row);
        });
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                ppvSupplierPickChoose(row);
            }
        });
        listEl.appendChild(li);
    });
}

function ppvSupplierPickOpen() {
    if (PPV_IS_RECEIPT) {
        return;
    }
    var modal = document.getElementById('ppv_supplier_pick_modal');
    var qEl = document.getElementById('ppv_supplier_pick_q');
    if (!modal || !qEl) {
        return;
    }
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    qEl.value = '';
    ppvSupplierPickRender('');
    qEl.focus();
}

function ppvSyncTreasury() {
    if (!PPV_CASH || !PPV_CASH.id) return;
    var cashTr = ppvCashMainRow();
    var partyTr = ppvPartyMainRow();
    if (!cashTr || !partyTr) return;
    var dEl = cashTr.querySelector('.jv-d');
    var cEl = cashTr.querySelector('.jv-c');
    var pd = partyTr.querySelector('.jv-d');
    var pc = partyTr.querySelector('.jv-c');
    if (!dEl || !cEl || !pd || !pc) return;
    if (PPV_IS_RECEIPT) {
        var cre = parseFloat(String(pc.value || '0').replace(',', '.')) || 0;
        dEl.value = cre > 0 ? orangeFmtMoney(cre) : '';
        cEl.value = orangeMoneyZero();
    } else {
        var deb = parseFloat(String(pd.value || '0').replace(',', '.')) || 0;
        cEl.value = deb > 0 ? orangeFmtMoney(deb) : '';
        dEl.value = orangeMoneyZero();
    }
}

function ppvRecalc() {
    ppvSyncTreasury();
    var sd = 0, sc = 0;
    document.querySelectorAll('#ppv_lines_body tr.jv-line-main').forEach(function (tr) {
        var d = parseFloat(String(tr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
        var c = parseFloat(String(tr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
        sd += d; sc += c;
    });
    var elD = document.getElementById('ppv_tot_debit');
    var elC = document.getElementById('ppv_tot_credit');
    if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
        window.OrangeMoney.setJvTotals(elD, elC, sd, sc);
    } else {
        if (elD) elD.value = orangeFmtMoney(sd);
        if (elC) elC.value = orangeFmtMoney(sc);
    }
}

function ppvApplySupplierAccount() {
    if (PPV_IS_RECEIPT) return;
    var sid = ppvPartyIdValue();
    var partyTr = ppvPartyMainRow();
    if (!partyTr) return;
    if (sid <= 0) {
        partyTr.querySelector('.jv-acc-id').value = '';
        partyTr.querySelector('.jv-acc-code').value = '';
        partyTr.querySelector('.jv-acc-name').value = '';
        return;
    }
    var m = PPV_SUPPLIER_PAYABLE[sid];
    if (!m) {
        partyTr.querySelector('.jv-acc-id').value = '';
        partyTr.querySelector('.jv-acc-code').value = '';
        partyTr.querySelector('.jv-acc-name').value = '';
        return;
    }
    partyTr.querySelector('.jv-acc-id').value = String(m.id);
    partyTr.querySelector('.jv-acc-code').value = m.code || '';
    partyTr.querySelector('.jv-acc-name').value = m.name || '';
}

function ppvBuildLines() {
    var tb = document.getElementById('ppv_lines_body');
    if (!tb || !PPV_READY || !PPV_CASH || !PPV_CASH.id) return;
    tb.innerHTML = '';
    window._ppvPairSeq = 0;

    function addCashPair() {
        var pair = ppvPairSeqRef();
        var trMain = document.createElement('tr');
        trMain.className = 'jv-line-main jv-line-cash-locked';
        trMain.setAttribute('data-jv-pair', pair);
        trMain.setAttribute('data-jv-cash-locked', '1');
        var amtCells;
        if (PPV_IS_RECEIPT) {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="من مبلغ ذمة العميل"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>';
        } else {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="تلقائي" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1" title="من مبلغ ذمة المورد"></td>';
        }
        trMain.innerHTML = '<td class="jv-acc-code-cell">' +
            '<input type="hidden" class="jv-acc-id" value="' + String(PPV_CASH.id) + '">' +
            '<input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(PPV_CASH.code || '') + '" readonly tabindex="-1">' +
            '</td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(PPV_CASH.name || '') + '" readonly tabindex="-1"></td>' +
            amtCells +
            '<td><span class="muted" style="display:inline-block;padding:8px 0;">—</span></td>';
        var trMemo = document.createElement('tr');
        trMemo.className = 'jv-line-memo';
        trMemo.setAttribute('data-jv-pair', pair);
        trMemo.innerHTML = '<td colspan="5"><input type="text" class="jv-m admin-inp admin-inp-readonly" value="" readonly tabindex="-1" placeholder="بيان سطر الخزينة"></td>';
        tb.appendChild(trMain);
        tb.appendChild(trMemo);
    }

    function addPartyPair() {
        var pair = ppvPairSeqRef();
        var trMain = document.createElement('tr');
        trMain.className = 'jv-line-main';
        trMain.setAttribute('data-jv-pair', pair);
        trMain.setAttribute('data-ppv-party', '1');
        var pid = '';
        var pcode = '';
        var pname = '';
        if (PPV_IS_RECEIPT && PPV_PARTY_DEFAULT) {
            pid = String(PPV_PARTY_DEFAULT.id);
            pcode = PPV_PARTY_DEFAULT.code || '';
            pname = PPV_PARTY_DEFAULT.name || '';
        }
        var amtCells;
        if (PPV_IS_RECEIPT) {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="" placeholder="مبلغ القبض" inputmode="decimal" lang="en" dir="ltr"></td>';
        } else {
            amtCells = '<td><input type="number" class="jv-d admin-inp-money" step="any" min="0" value="" placeholder="مبلغ الصرف" inputmode="decimal" lang="en" dir="ltr"></td>' +
                '<td><input type="number" class="jv-c admin-inp-money" step="any" min="0" value="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr" readonly tabindex="-1"></td>';
        }
        trMain.innerHTML = '<td class="jv-acc-code-cell">' +
            '<input type="hidden" class="jv-acc-id" value="' + pid + '">' +
            '<input type="text" class="jv-acc-code admin-inp" value="' + ppvEscapeHtml(pcode) + '" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار" style="cursor:pointer;">' +
            '</td>' +
            '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(pname) + '" readonly tabindex="-1"></td>' +
            amtCells +
            '<td><span class="muted" style="display:inline-block;padding:8px 0;">—</span></td>';
        var trMemo = document.createElement('tr');
        trMemo.className = 'jv-line-memo';
        trMemo.setAttribute('data-jv-pair', pair);
        trMemo.innerHTML = '<td colspan="5"><input type="text" class="jv-m admin-inp admin-inp-readonly" value="" readonly tabindex="-1" placeholder="بيان الذمة"></td>';
        tb.appendChild(trMain);
        tb.appendChild(trMemo);

        var amtInp = PPV_IS_RECEIPT ? trMain.querySelector('.jv-c') : trMain.querySelector('.jv-d');
        if (amtInp) {
            amtInp.addEventListener('input', ppvRecalc);
        }
    }

    if (PPV_IS_RECEIPT) {
        addCashPair();
        addPartyPair();
    } else {
        addPartyPair();
        addCashPair();
        ppvApplySupplierAccount();
        var partyCodeEl = document.querySelector('#ppv_lines_body tr[data-ppv-party="1"] .jv-acc-code');
        if (partyCodeEl) {
            partyCodeEl.addEventListener('dblclick', function (e) {
                e.preventDefault();
                ppvSupplierPickOpen();
            });
            partyCodeEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    ppvSupplierPickOpen();
                }
            });
        }
    }

    var descEl = document.getElementById('ppv_desc');
    function syncMemos() {
        var t = descEl ? descEl.value.trim() : '';
        document.querySelectorAll('#ppv_lines_body .jv-m').forEach(function (inp) {
            inp.value = t;
        });
    }
    if (descEl) {
        descEl.addEventListener('input', function () { syncMemos(); ppvRecalc(); });
    }
    syncMemos();
    ppvRecalc();
}

function ppvCollectAlloc() {
    var tb = document.getElementById('ppv_alloc_tbody');
    if (!tb) return [];
    var out = [];
    tb.querySelectorAll('tr[data-ref-type]').forEach(function (tr) {
        var inp = tr.querySelector('.alloc-amt');
        var amt = parseFloat(inp && inp.value ? inp.value : '0');
        if (amt <= 0) return;
        out.push({
            ref_type: tr.getAttribute('data-ref-type'),
            ref_id: parseInt(tr.getAttribute('data-ref-id'), 10),
            amount: amt
        });
    });
    return out;
}

function ppvLoadAlloc() {
    var id = ppvPartyIdValue();
    var tb = document.getElementById('ppv_alloc_tbody');
    if (id <= 0) { alert(PPV_IS_RECEIPT ? 'اختر عميلاً' : 'اختر مورداً'); return; }
    tb.innerHTML = '<tr><td colspan="3">جاري التحميل…</td></tr>';
    postJSON('/admin/api/partners/open-items.php', { party_kind: PPV_OPEN_KIND, party_id: id }).then(function (r) {
        if (!r.success) {
            tb.innerHTML = '<tr><td colspan="3">' + (r.message || 'فشل') + '</td></tr>';
            return;
        }
        tb.innerHTML = '';
        var items = r.items || [];
        items.forEach(function (it) {
            var tr = document.createElement('tr');
            tr.setAttribute('data-ref-type', it.ref_type);
            tr.setAttribute('data-ref-id', String(it.ref_id));
            tr.innerHTML = '<td>' + ppvEscapeHtml(it.label) + '</td><td>' + orangeFmtMoney(Number(it.open)) + '</td><td><input type="number" class="alloc-amt admin-inp-money" step="any" min="0" placeholder="' + orangeMoneyZero() + '" inputmode="decimal" lang="en" dir="ltr"></td>';
            tb.appendChild(tr);
        });
        if (!items.length) {
            tb.innerHTML = '<tr><td colspan="3" class="muted">لا توجد مستندات مفتوحة.</td></tr>';
        }
    }).catch(function (e) {
        tb.innerHTML = '<tr><td colspan="3">' + (e.message || String(e)) + '</td></tr>';
    });
}

function ppvGetAmount() {
    var partyTr = ppvPartyMainRow();
    if (!partyTr) return 0;
    if (PPV_IS_RECEIPT) {
        return parseFloat(String(partyTr.querySelector('.jv-c').value || '0').replace(',', '.')) || 0;
    }
    return parseFloat(String(partyTr.querySelector('.jv-d').value || '0').replace(',', '.')) || 0;
}

function ppvSave() {
    if (!PPV_CAPS.can_edit) {
        alert('لا تملك صلاحية حفظ هذا السند');
        return;
    }
    if (!PPV_CASH || !PPV_CASH.id) return;
    var partyId = ppvPartyIdValue();
    var amt = ppvGetAmount();
    var dIso = orangeGetDmyValueAsIso(document.getElementById('ppv_date'));
    var desc = document.getElementById('ppv_desc').value.trim();
    if (partyId <= 0 || amt <= 0 || !dIso) {
        alert('أكمل ' + (PPV_IS_RECEIPT ? 'العميل' : 'المورد') + ' والمبلغ والتاريخ (يوم/شهر/سنة)');
        return;
    }
    var allocs = ppvCollectAlloc();
    var sumA = allocs.reduce(function (a, x) { return a + x.amount; }, 0);
    var moneyDecimals = (window.ORANGE_ADMIN_MONEY && typeof window.ORANGE_ADMIN_MONEY.decimals === 'number')
        ? Math.max(0, parseInt(String(window.ORANGE_ADMIN_MONEY.decimals), 10) || 3)
        : 3;
    var allocTolerance = Math.pow(10, -moneyDecimals);
    if (allocs.length && sumA > amt + allocTolerance) {
        alert('مجموع التخصيصات (' + orangeFmtMoney(sumA) + ') يتجاوز مبلغ السند (' + orangeFmtMoney(amt) + ')');
        return;
    }
    var payload;
    if (PPV_IS_RECEIPT) {
        payload = {
            customer_id: partyId,
            amount: amt,
            date: dIso,
            description: desc || 'سداد فواتير مبيعات آجلة',
            allow_excess: document.getElementById('ppv_allow_excess').checked,
            allocations: allocs
        };
    } else {
        payload = {
            supplier_id: partyId,
            amount: amt,
            date: dIso,
            description: desc || 'سداد فواتير مشتريات آجلة',
            allow_excess: document.getElementById('ppv_allow_excess').checked,
            allocations: allocs
        };
    }
    postJSON(PPV_API, payload).then(function (r) {
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

function ppvOnPartyChanged() {
    if (!PPV_IS_RECEIPT) {
        ppvSupplierSyncUiById(ppvPartyIdValue());
        ppvApplySupplierAccount();
    }
    var allocTb = document.getElementById('ppv_alloc_tbody');
    if (allocTb) {
        allocTb.innerHTML = '';
    }
    ppvRecalc();
}

function ppvApplyPrefill() {
    if (!PPV_PREFILL || !(parseInt(String(PPV_PREFILL.party_id || '0'), 10) > 0)) {
        return;
    }
    var wanted = parseInt(String(PPV_PREFILL.party_id || '0'), 10) || 0;
    if (wanted <= 0) {
        return;
    }
    if (PPV_IS_RECEIPT) {
        var sel = document.getElementById('ppv_party');
        if (sel && sel.querySelector('option[value="' + wanted + '"]')) {
            sel.value = String(wanted);
            ppvOnPartyChanged();
        }
        return;
    }
    if (ppvSupplierPickRowById(wanted)) {
        var partyEl = document.getElementById('ppv_party');
        if (partyEl) {
            partyEl.value = String(wanted);
        }
        ppvSupplierSyncUiById(wanted);
        ppvOnPartyChanged();
    }
}

function ppvNav(where) {
    if (!PPV_IS_RECEIPT) {
        return;
    }
    postJSON('/admin/api/journal/manage.php', {
        action: 'nav_manual',
        entry_type: PPV_BROWSE_ENTRY_TYPE,
        where: where,
        current_id: ppvBrowseId || 0
    }).then(function (r) {
        if (!r.success || !r.id) {
            alert(r.message || 'لا توجد سندات من هذا النوع بعد');
            return;
        }
        ppvLoadVoucher(r.id);
    }).catch(function (e) { alert(e.message || String(e)); });
}

function ppvLoadVoucher(id) {
    if (!PPV_IS_RECEIPT) {
        return;
    }
    postJSON('/admin/api/journal/manage.php', {
        action: 'get',
        id: id,
        entry_type: PPV_BROWSE_ENTRY_TYPE
    }).then(function (r) {
        if (!r.success || !r.voucher) {
            alert(r.message || 'تعذر تحميل السند');
            return;
        }
        ppvBrowseId = r.voucher.id;
        ppvDisplayBrowseVoucher(r);
    }).catch(function (e) { alert(e.message || String(e)); });
}

function ppvDisplayBrowseVoucher(r) {
    var v = r.voucher;
    var numEl = document.getElementById('ppv_number_preview');
    if (numEl) {
        numEl.value = String(v.voucher_serial || v.display_voucher_no || v.id || '');
    }
    var refEl = document.getElementById('ppv_ref');
    if (refEl) refEl.value = v.reference || '';
    var dateEl = document.getElementById('ppv_date');
    if (dateEl) dateEl.value = v.voucher_date_dmy || v.voucher_date || '';
    var descEl = document.getElementById('ppv_desc');
    if (descEl) descEl.value = v.description || '';
    if (r.party_customer_id) {
        var sel = document.getElementById('ppv_party');
        if (sel) {
            sel.value = String(parseInt(String(r.party_customer_id), 10) || 0);
        }
    }
    var total = 0;
    (r.lines || []).forEach(function (l) {
        total += parseFloat(String(l.debit || '0')) || 0;
    });
    var dEl = document.getElementById('ppv_tot_debit');
    var cEl = document.getElementById('ppv_tot_credit');
    if (window.OrangeMoney && window.OrangeMoney.setJvTotals) {
        window.OrangeMoney.setJvTotals(dEl, cEl, total, total);
    } else {
        if (dEl) dEl.value = orangeFmtMoney(total);
        if (cEl) cEl.value = orangeFmtMoney(total);
    }
    var tb = document.getElementById('ppv_lines_body');
    if (tb && r.lines) {
        tb.innerHTML = '';
        r.lines.forEach(function (l) {
            var tr = document.createElement('tr');
            tr.className = 'jv-line-main';
            var d = parseFloat(String(l.debit || '0')) || 0;
            var c = parseFloat(String(l.credit || '0')) || 0;
            var memo = l.memo || '';
            var nameTxt = (l.name || '') + (memo ? ' — ' + memo : '');
            tr.innerHTML = '<td><input type="text" class="jv-acc-code admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(l.code || '') + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="jv-acc-name admin-inp admin-inp-readonly" value="' + ppvEscapeHtml(nameTxt) + '" readonly tabindex="-1"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + (d > 0 ? orangeFmtMoney(d) : orangeMoneyZero()) + '" readonly dir="ltr" lang="en"></td>' +
                '<td><input type="text" class="admin-inp-money" value="' + (c > 0 ? orangeFmtMoney(c) : orangeMoneyZero()) + '" readonly dir="ltr" lang="en"></td>' +
                '<td></td>';
            tb.appendChild(tr);
        });
    }
    var allocTb = document.getElementById('ppv_alloc_tbody');
    if (allocTb) {
        allocTb.innerHTML = '';
    }
    var btnDel = document.getElementById('ppv_btn_delete');
    if (btnDel) {
        btnDel.disabled = false;
    }
    if (ppvEditLockCtl) ppvEditLockCtl.refresh();
}

function ppvDeleteVoucher() {
    if (!PPV_IS_RECEIPT || !ppvBrowseId) {
        alert('لا يوجد سند محفوظ للحذف');
        return;
    }
    if (!confirm('تأكيد حذف هذا السند؟ لا يمكن التراجع.')) {
        return;
    }
    postJSON('/admin/api/journal/manage.php', { action: 'delete', id: ppvBrowseId }).then(function (r) {
        if (r.success) {
            alert(r.message || 'تم الحذف');
            location.reload();
            return;
        }
        alert(r.message || 'فشل الحذف');
    }).catch(function (e) { alert(e.message || String(e)); });
}

function ppvSearchOpen() {
    var m = document.getElementById('ppv_search_modal');
    if (m) {
        m.style.display = 'flex';
        m.setAttribute('aria-hidden', 'false');
    }
}

function ppvSearchClose() {
    var m = document.getElementById('ppv_search_modal');
    if (m) {
        m.style.display = 'none';
        m.setAttribute('aria-hidden', 'true');
    }
}

function ppvSearchRun() {
    if (!PPV_IS_RECEIPT) {
        return;
    }
    var idFrom = parseInt(document.getElementById('ppv_search_id_from').value, 10) || 0;
    var idTo = parseInt(document.getElementById('ppv_search_id_to').value, 10) || 0;
    var dateFrom = orangeGetDmyValueAsIso(document.getElementById('ppv_search_date_from')) || '';
    var dateTo = orangeGetDmyValueAsIso(document.getElementById('ppv_search_date_to')) || '';
    var ref = (document.getElementById('ppv_search_ref').value || '').trim();
    var desc = (document.getElementById('ppv_search_desc').value || '').trim();
    var tbody = document.getElementById('ppv_search_results');
    if (!tbody) {
        return;
    }
    tbody.innerHTML = '<tr><td colspan="5">جاري البحث…</td></tr>';
    var payload = {
        action: 'search_manual',
        entry_type: PPV_BROWSE_ENTRY_TYPE
    };
    if (idFrom > 0) payload.id_from = idFrom;
    if (idTo > 0) payload.id_to = idTo;
    if (dateFrom) payload.date_from = dateFrom;
    if (dateTo) payload.date_to = dateTo;
    if (ref) payload.reference = ref;
    if (desc) payload.description = desc;
    postJSON('/admin/api/journal/manage.php', payload).then(function (r) {
        tbody.innerHTML = '';
        var rows = (r.results && r.results.length) ? r.results : (r.rows || []);
        if (!r.success || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">لا نتائج</td></tr>';
            return;
        }
        rows.forEach(function (v) {
            var tr = document.createElement('tr');
            tr.style.cursor = 'pointer';
            var amt = parseFloat(String(v.amount != null ? v.amount : v.total || '0')) || 0;
            tr.innerHTML = '<td>' + ppvEscapeHtml(String(v.display_no || v.voucher_serial || v.id)) + '</td>' +
                '<td>' + ppvEscapeHtml(v.voucher_date_dmy || v.voucher_date || '') + '</td>' +
                '<td>' + ppvEscapeHtml(v.reference || '') + '</td>' +
                '<td>' + ppvEscapeHtml(v.description || '') + '</td>' +
                '<td dir="ltr">' + orangeFmtMoney(amt) + '</td>';
            tr.addEventListener('dblclick', function () {
                ppvLoadVoucher(v.id);
                ppvSearchClose();
            });
            tbody.appendChild(tr);
        });
    }).catch(function (e) {
        tbody.innerHTML = '<tr><td colspan="5">' + ppvEscapeHtml(e.message || String(e)) + '</td></tr>';
    });
}

function ppvBindBrowse() {
    if (!PPV_IS_RECEIPT) {
        return;
    }
    var navFirst = document.getElementById('ppv_nav_first');
    if (navFirst) navFirst.addEventListener('click', function () { ppvNav('first'); });
    var navPrev = document.getElementById('ppv_nav_prev');
    if (navPrev) navPrev.addEventListener('click', function () { ppvNav('prev'); });
    var navNext = document.getElementById('ppv_nav_next');
    if (navNext) navNext.addEventListener('click', function () { ppvNav('next'); });
    var navLast = document.getElementById('ppv_nav_last');
    if (navLast) navLast.addEventListener('click', function () { ppvNav('last'); });
    var btnSearch = document.getElementById('ppv_btn_search_vouchers');
    if (btnSearch) btnSearch.addEventListener('click', ppvSearchOpen);
    var btnSearchRun = document.getElementById('ppv_search_btn');
    if (btnSearchRun) btnSearchRun.addEventListener('click', ppvSearchRun);
    var searchBackdrop = document.getElementById('ppv_search_modal_backdrop');
    if (searchBackdrop) searchBackdrop.addEventListener('click', ppvSearchClose);
    var btnDelete = document.getElementById('ppv_btn_delete');
    if (btnDelete) btnDelete.addEventListener('click', ppvDeleteVoucher);
    document.addEventListener('mousedown', function (ev) {
        var m = document.getElementById('ppv_search_modal');
        if (!m || m.style.display !== 'flex') {
            return;
        }
        var panel = m.querySelector('.jv-search-modal__panel');
        if (panel && (panel === ev.target || panel.contains(ev.target))) {
            return;
        }
        if (ev.target.closest && ev.target.closest('#ppv_btn_search_vouchers')) {
            return;
        }
        ppvSearchClose();
    }, true);
}

function ppvBind() {
    var partySel = document.getElementById('ppv_party');
    if (partySel) {
        if (PPV_IS_RECEIPT) {
            partySel.addEventListener('change', ppvOnPartyChanged);
        }
    }
    if (!PPV_IS_RECEIPT) {
        var supplierPickBackdrop = document.getElementById('ppv_supplier_pick_backdrop');
        if (supplierPickBackdrop) {
            supplierPickBackdrop.addEventListener('click', ppvSupplierPickClose);
        }
        var supplierPickClose = document.getElementById('ppv_supplier_pick_close');
        if (supplierPickClose) {
            supplierPickClose.addEventListener('click', ppvSupplierPickClose);
        }
        var supplierPickQ = document.getElementById('ppv_supplier_pick_q');
        if (supplierPickQ) {
            supplierPickQ.addEventListener('input', function () {
                if (ppvSupplierPickTimer) {
                    clearTimeout(ppvSupplierPickTimer);
                }
                ppvSupplierPickTimer = setTimeout(function () {
                    ppvSupplierPickRender(supplierPickQ.value || '');
                }, 180);
            });
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key !== 'Escape') {
                return;
            }
            var modal = document.getElementById('ppv_supplier_pick_modal');
            if (modal && !modal.hidden) {
                ppvSupplierPickClose();
            }
        });
    }
    var bLoad = document.getElementById('ppv_btn_load_alloc');
    if (bLoad) bLoad.addEventListener('click', ppvLoadAlloc);
    var bSave = document.getElementById('ppv_btn_save');
    if (bSave) bSave.addEventListener('click', ppvSave);
    var bNew = document.getElementById('ppv_btn_new');
    if (bNew) bNew.addEventListener('click', function () { location.reload(); });
    var bPr = document.getElementById('ppv_btn_print');
    if (bPr) bPr.addEventListener('click', function () { window.print(); });
    ppvBindBrowse();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        ppvBuildLines();
        ppvBind();
        ppvApplyPrefill();
        if (window.OrangeEditLock) {
            ppvEditLockCtl = OrangeEditLock.bind({
                prefix: 'ppv',
                docKind: PPV_BROWSE_ENTRY_TYPE,
                page: PPV_PERM_PAGE,
                canLock: !!PPV_CAPS.can_lock,
                canUnlock: !!PPV_CAPS.can_unlock,
                countryId: PPV_COUNTRY_ID,
                getEntityId: function () { return ppvBrowseId || 0; }
            });
        }
    });
} else {
    ppvBuildLines();
    ppvBind();
    ppvApplyPrefill();
    if (window.OrangeEditLock) {
        ppvEditLockCtl = OrangeEditLock.bind({
            prefix: 'ppv',
            docKind: PPV_BROWSE_ENTRY_TYPE,
            page: PPV_PERM_PAGE,
            canLock: !!PPV_CAPS.can_lock,
            canUnlock: !!PPV_CAPS.can_unlock,
            countryId: PPV_COUNTRY_ID,
            getEntityId: function () { return ppvBrowseId || 0; }
        });
    }
}
</script>
