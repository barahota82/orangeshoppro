<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/storefront_phone_country_select.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);
$supplierSchemaBootstrapError = '';
if (function_exists('orange_catalog_ensure_schema_core')) {
    try {
        orange_catalog_ensure_schema_core($pdo);
    } catch (Throwable $e) {
        $supplierSchemaBootstrapError = trim((string) $e->getMessage());
    }
}

$leafAccountOptions = [];
$supplierPayablePickAccounts = [];
if (orange_table_exists($pdo, 'accounts')) {
    $lw = orange_accounts_posting_leaf_where_sql($pdo, 'a');
    $leafAccountOptions = $pdo->query(
        'SELECT a.id, a.code, a.name FROM accounts a WHERE ' . $lw . ' ORDER BY COALESCE(a.code, \'\'), a.name'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($leafAccountOptions as $a) {
        $aid = (int) $a['id'];
        if (orange_accounts_account_pl_role($pdo, $aid) === 'liability') {
            $supplierPayablePickAccounts[] = $a;
        }
    }
}
$payableAccountLabel = static function (int $id) use ($leafAccountOptions, $supplierPayablePickAccounts): string {
    foreach ($supplierPayablePickAccounts as $a) {
        if ((int) $a['id'] === $id) {
            return (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . ($a['name'] ?? '');
        }
    }
    foreach ($leafAccountOptions as $a) {
        if ((int) $a['id'] === $id) {
            return (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . ($a['name'] ?? '');
        }
    }

    return '#' . $id;
};
$hasSupplierPayableCol = orange_table_has_column($pdo, 'suppliers', 'payable_account_id');
$hasSupplierIsActiveCol = orange_table_has_column($pdo, 'suppliers', 'is_active');
$hasSupplierCurrencyCol = orange_table_has_column($pdo, 'suppliers', 'currency_code');
$hasSupplierTaxProfileCol = orange_table_has_column($pdo, 'suppliers', 'tax_profile');
$hasSupplierPaymentModeCol = orange_table_has_column($pdo, 'suppliers', 'payment_mode');
$hasSupplierPaymentTermsCol = orange_table_has_column($pdo, 'suppliers', 'payment_terms_days');
$hasSupplierTaxNumberCol = orange_table_has_column($pdo, 'suppliers', 'tax_number');
$hasSupplierPhoneCountryDialCol = orange_table_has_column($pdo, 'suppliers', 'phone_country_dial');
$hasSupplierContactPersonCol = orange_table_has_column($pdo, 'suppliers', 'contact_person');
$hasSupplierEmailCol = orange_table_has_column($pdo, 'suppliers', 'email');
$hasSupplierCommercialRegCol = orange_table_has_column($pdo, 'suppliers', 'commercial_reg');
$hasSupplierAddressLineCol = orange_table_has_column($pdo, 'suppliers', 'address_line');
$hasSupplierCityAreaCol = orange_table_has_column($pdo, 'suppliers', 'city_area');
$hasSupplierOpeningBalanceCol = orange_table_has_column($pdo, 'suppliers', 'opening_balance');
$hasSupplierCreditLimitCol = orange_table_has_column($pdo, 'suppliers', 'credit_limit');
$hasSupplierBankNameCol = orange_table_has_column($pdo, 'suppliers', 'bank_name');
$hasSupplierBankIbanCol = orange_table_has_column($pdo, 'suppliers', 'bank_iban');
$hasSupplierBankHolderCol = orange_table_has_column($pdo, 'suppliers', 'bank_account_holder');
$hasSupplierPreferredWarehouseCol = orange_table_has_column($pdo, 'suppliers', 'preferred_warehouse_id');
$hasSupplierIsBlockedCol = orange_table_has_column($pdo, 'suppliers', 'is_blocked');
$hasSupplierBlockReasonCol = orange_table_has_column($pdo, 'suppliers', 'block_reason');
$hasSupplierAttachmentsCol = orange_table_has_column($pdo, 'suppliers', 'attachments_json');
$supplierSchemaMissingCols = [];
$supplierSchemaMap = [
    'payable_account_id' => $hasSupplierPayableCol,
    'is_active' => $hasSupplierIsActiveCol,
    'phone_country_dial' => $hasSupplierPhoneCountryDialCol,
    'currency_code' => $hasSupplierCurrencyCol,
    'tax_profile' => $hasSupplierTaxProfileCol,
    'payment_mode' => $hasSupplierPaymentModeCol,
    'payment_terms_days' => $hasSupplierPaymentTermsCol,
    'tax_number' => $hasSupplierTaxNumberCol,
    'contact_person' => $hasSupplierContactPersonCol,
    'email' => $hasSupplierEmailCol,
    'commercial_reg' => $hasSupplierCommercialRegCol,
    'address_line' => $hasSupplierAddressLineCol,
    'city_area' => $hasSupplierCityAreaCol,
    'opening_balance' => $hasSupplierOpeningBalanceCol,
    'credit_limit' => $hasSupplierCreditLimitCol,
    'bank_name' => $hasSupplierBankNameCol,
    'bank_iban' => $hasSupplierBankIbanCol,
    'bank_account_holder' => $hasSupplierBankHolderCol,
    'preferred_warehouse_id' => $hasSupplierPreferredWarehouseCol,
    'is_blocked' => $hasSupplierIsBlockedCol,
    'block_reason' => $hasSupplierBlockReasonCol,
    'attachments_json' => $hasSupplierAttachmentsCol,
];
foreach ($supplierSchemaMap as $colName => $isAvailable) {
    if (!$isAvailable) {
        $supplierSchemaMissingCols[] = $colName;
    }
}
/*
 * واجهة الموردين يجب أن تُظهر كل الحقول دائماً للمراجعة/الإدخال،
 * حتى لو كان عمود القاعدة ناقصاً. سبب نقص الحقول كان اقتران العرض بوجود العمود.
 * نبقي فحص المخطط للتشخيص، لكن لا نخفي الحقول عن المستخدم.
 */
$hasSupplierPayableCol = true;
$hasSupplierIsActiveCol = true;
$hasSupplierCurrencyCol = true;
$hasSupplierTaxProfileCol = true;
$hasSupplierPaymentModeCol = true;
$hasSupplierPaymentTermsCol = true;
$hasSupplierTaxNumberCol = true;
$hasSupplierPhoneCountryDialCol = true;
$hasSupplierContactPersonCol = true;
$hasSupplierEmailCol = true;
$hasSupplierCommercialRegCol = true;
$hasSupplierAddressLineCol = true;
$hasSupplierCityAreaCol = true;
$hasSupplierOpeningBalanceCol = true;
$hasSupplierCreditLimitCol = true;
$hasSupplierBankNameCol = true;
$hasSupplierBankIbanCol = true;
$hasSupplierBankHolderCol = true;
$hasSupplierPreferredWarehouseCol = true;
$hasSupplierIsBlockedCol = true;
$hasSupplierBlockReasonCol = true;
$hasSupplierAttachmentsCol = true;
$currencyOptions = [
    'KWD' => 'دينار كويتي (KWD)',
    'USD' => 'دولار أمريكي (USD)',
    'SAR' => 'ريال سعودي (SAR)',
    'AED' => 'درهم إماراتي (AED)',
    'QAR' => 'ريال قطري (QAR)',
    'BHD' => 'دينار بحريني (BHD)',
    'OMR' => 'ريال عُماني (OMR)',
];
$paymentModeOptions = [
    'cash' => 'نقدي',
    'credit' => 'آجل',
    'transfer' => 'تحويل',
];
$taxProfileOptions = [
    'exempt' => 'معفى',
    'taxable' => 'خاضع',
    'zero' => 'صفر ضريبي',
];
$currencyLabel = static function (string $code) use ($currencyOptions): string {
    return $currencyOptions[$code] ?? $code;
};
$taxProfileLabel = static function (string $code) use ($taxProfileOptions): string {
    return $taxProfileOptions[$code] ?? $code;
};

$rows = [];
$totalBalance = 0.0;
if (orange_table_exists($pdo, 'suppliers')) {
    $sql = 'SELECT s.*, (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.id) AS purchase_cnt FROM suppliers s ORDER BY s.name ASC, s.id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $totalBalance += orange_party_balance_supplier($pdo, (int) $r['id']);
    }
}
$count = count($rows);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>الموردين</h1>
        <p class="page-subtitle">
            إدارة موردي المشتريات: <strong>كود المورد</strong> (فريد تلقائي)، الذمم الدائنة، وعدد مستندات الشراء.
            مستقبلاً: ربط <strong>مردود المشتريات</strong> بجدول <code dir="ltr">purchase_returns</code> (المورد + مستند الشراء الأصلي).
            المشتريات من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">المشتريات</a>؛ السداد والكشوف من
            <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>">سداد فواتير مشتريات آجلة</a>
            — <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports'), ENT_QUOTES, 'UTF-8'); ?>">تقارير الذمم المالية</a>.
            <?php if ($hasSupplierPayableCol): ?>
                <strong>حساب ذمة فرعي</strong> (تحت الخصوم) <strong>إلزامي لكل مورد</strong> — تُرحَّل مشتريات الآجل والسداد على ذلك الحساب فقط، دون استخدام حساب مجمع للموردين في القيود التلقائية.
            <?php endif; ?>
        </p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">مستند شراء</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>">سداد فواتير مشتريات آجلة</a>
    </div>
</div>

<?php if ($supplierSchemaBootstrapError !== '' || $supplierSchemaMissingCols): ?>
<div class="card" style="border:1px solid #fbbf24; background:#fffbeb; color:#92400e;">
    <h3 style="margin-top:0;">تنبيه مخطط الموردين</h3>
    <p class="card-hint" style="margin:6px 0 0; color:#92400e;">
        تم تشغيل ترقية مخطط الموردين تلقائياً عند فتح الصفحة. إذا استمرت خانات ناقصة، فغالباً ترحيل القاعدة لم يكتمل (صلاحيات ALTER أو كاش PHP).
    </p>
    <?php if ($supplierSchemaBootstrapError !== ''): ?>
        <p class="card-hint" style="margin:6px 0 0; color:#7c2d12;">
            رسالة النظام: <code dir="ltr"><?php echo htmlspecialchars($supplierSchemaBootstrapError, ENT_QUOTES, 'UTF-8'); ?></code>
        </p>
    <?php endif; ?>
    <?php if ($supplierSchemaMissingCols): ?>
        <p class="card-hint" style="margin:6px 0 0; color:#7c2d12;">
            أعمدة لم تظهر بعد في القاعدة:
            <code dir="ltr"><?php echo htmlspecialchars(implode(', ', $supplierSchemaMissingCols), ENT_QUOTES, 'UTF-8'); ?></code>
        </p>
        <p class="card-hint" style="margin:6px 0 0; color:#7c2d12;">
            تم إظهار جميع خانات الموردين في الشاشة رغم ذلك. إذا كان عمود ناقصاً، فلن يُحفظ هذا الحقل حتى يكتمل ترحيل المخطط.
        </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="party-registry-stats">
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">عدد الموردين</span>
        <span class="party-registry-stat__val"><?php echo (int) $count; ?></span>
    </div>
    <div class="party-registry-stat">
        <span class="party-registry-stat__label">مجموع ذمم الموردين</span>
        <span class="party-registry-stat__val" dir="ltr"><?php echo number_format($totalBalance, 3); ?></span>
        <span class="party-registry-stat__unit">KD</span>
    </div>
</div>

<style>
#sup_form_grid.suppliers-form-grid {
    grid-template-areas:
        "sup_r1_code . . ."
        "sup_r2_name sup_r2_name sup_r2_balance sup_r2_status"
        "sup_r3_city sup_r3_address sup_r3_address sup_r3_address"
        "sup_r4_country sup_r4_phone sup_r4_email sup_r4_contact"
        "sup_r5_credit sup_r5_terms sup_r5_payment sup_r5_currency"
        "sup_r6_notes sup_r6_notes sup_r6_notes sup_r6_notes"
        "sup_r7_tax_profile sup_r7_tax_number sup_r7_commercial ."
        "sup_r8_bank_name sup_r8_iban sup_r8_iban sup_r8_bank_holder";
}
#sup_form_grid .sup-grid-r1-code { grid-area: sup_r1_code; }
#sup_form_grid .sup-grid-r2-name { grid-area: sup_r2_name; }
#sup_form_grid .sup-grid-r2-balance { grid-area: sup_r2_balance; }
#sup_form_grid .sup-grid-r2-status { grid-area: sup_r2_status; }
#sup_form_grid .sup-grid-r3-city { grid-area: sup_r3_city; }
#sup_form_grid .sup-grid-r3-address { grid-area: sup_r3_address; }
#sup_form_grid .sup-grid-r4-country { grid-area: sup_r4_country; }
#sup_form_grid .sup-grid-r4-phone { grid-area: sup_r4_phone; }
#sup_form_grid .sup-grid-r4-email { grid-area: sup_r4_email; }
#sup_form_grid .sup-grid-r4-contact { grid-area: sup_r4_contact; }
#sup_form_grid .sup-grid-r5-credit { grid-area: sup_r5_credit; }
#sup_form_grid .sup-grid-r5-terms { grid-area: sup_r5_terms; }
#sup_form_grid .sup-grid-r5-payment { grid-area: sup_r5_payment; }
#sup_form_grid .sup-grid-r5-currency { grid-area: sup_r5_currency; }
#sup_form_grid .sup-grid-r6-notes { grid-area: sup_r6_notes; }
#sup_form_grid .sup-grid-r7-tax-profile { grid-area: sup_r7_tax_profile; }
#sup_form_grid .sup-grid-r7-tax-number { grid-area: sup_r7_tax_number; }
#sup_form_grid .sup-grid-r7-commercial { grid-area: sup_r7_commercial; }
#sup_form_grid .sup-grid-r8-bank-name { grid-area: sup_r8_bank_name; }
#sup_form_grid .sup-grid-r8-iban { grid-area: sup_r8_iban; }
#sup_form_grid .sup-grid-r8-bank-holder { grid-area: sup_r8_bank_holder; }
@media (max-width: 1200px) {
    #sup_form_grid.suppliers-form-grid {
        grid-template-areas: none !important;
    }
    #sup_form_grid .sup-grid-r1-code,
    #sup_form_grid .sup-grid-r2-name,
    #sup_form_grid .sup-grid-r2-balance,
    #sup_form_grid .sup-grid-r2-status,
    #sup_form_grid .sup-grid-r3-city,
    #sup_form_grid .sup-grid-r3-address,
    #sup_form_grid .sup-grid-r4-country,
    #sup_form_grid .sup-grid-r4-phone,
    #sup_form_grid .sup-grid-r4-email,
    #sup_form_grid .sup-grid-r4-contact,
    #sup_form_grid .sup-grid-r5-credit,
    #sup_form_grid .sup-grid-r5-terms,
    #sup_form_grid .sup-grid-r5-payment,
    #sup_form_grid .sup-grid-r5-currency,
    #sup_form_grid .sup-grid-r6-notes,
    #sup_form_grid .sup-grid-r7-tax-profile,
    #sup_form_grid .sup-grid-r7-tax-number,
    #sup_form_grid .sup-grid-r7-commercial,
    #sup_form_grid .sup-grid-r8-bank-name,
    #sup_form_grid .sup-grid-r8-iban,
    #sup_form_grid .sup-grid-r8-bank-holder {
        grid-area: auto !important;
    }
}
</style>

<div class="card" id="sup_form_card">
    <h3>مورد جديد أو تعديل</h3>
    <p class="card-hint" style="margin-top:0;">الاسم إلزامي. <strong>حساب ذمة المورد</strong> إلزامي عند الحفظ. يمكن إيقاف المورد (غير نشط) بدل الحذف، وفاتورة الشراء لا تُحفظ لمورد غير نشط.</p>
    <input type="hidden" id="sup_id" value="0">
    <div class="form-grid suppliers-form-grid" id="sup_form_grid">
        <div class="sup-grid-r1-code">
            <label for="sup_code">كود المورد (تلقائي فقط)</label>
            <input type="text" id="sup_code" class="admin-sort-field admin-sort-field--muted" maxlength="32" autocomplete="off" dir="ltr" lang="en" placeholder="يُولَّد تلقائياً عند الحفظ" readonly>
            <p class="card-hint" style="margin:6px 0 0;">هذا الحقل تلقائي فقط ولا يقبل إدخالاً يدوياً.</p>
        </div>
        <div class="sup-grid-r2-name">
            <label for="sup_name">اسم المورد</label>
            <input type="text" id="sup_name" autocomplete="off" placeholder="اسم المورد أو الشركة">
        </div>
        <div class="sup-grid-r2-balance">
            <label for="sup_current_balance">الرصيد الحالي المستحق للمورد</label>
            <input type="text" id="sup_current_balance" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0.000" readonly>
            <p class="card-hint" style="margin:6px 0 0;">قراءة فقط — يُحتسب تلقائياً من المشتريات والمردود والسداد وأي حركة تؤثر على ذمة المورد.</p>
        </div>
        <?php if ($hasSupplierIsActiveCol): ?>
        <div class="sup-grid-r2-status">
            <label for="sup_is_active">حالة المورد</label>
            <select id="sup_is_active">
                <option value="1" selected>نشط</option>
                <option value="0">غير نشط</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPhoneCountryDialCol): ?>
        <div class="sup-grid-r4-country">
            <label for="sup_phone_country">كود الدولة (اختياري)</label>
            <?php orange_storefront_render_phone_country_select('sup_phone_country'); ?>
        </div>
        <?php endif; ?>
        <div class="sup-grid-r4-phone">
            <label for="sup_phone">الهاتف (اختياري)</label>
            <input type="text" id="sup_phone" class="js-orange-phone-input" autocomplete="off" dir="ltr" lang="en" placeholder="+965… أو 00… أو رقم وطني مع اختيار الدولة">
        </div>
        <?php if ($hasSupplierContactPersonCol): ?>
        <div class="sup-grid-r4-contact">
            <label for="sup_contact_person">مسؤول التواصل (اختياري)</label>
            <input type="text" id="sup_contact_person" maxlength="160" autocomplete="off" placeholder="اسم مسؤول التواصل">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierEmailCol): ?>
        <div class="sup-grid-r4-email">
            <label for="sup_email">البريد الإلكتروني (اختياري)</label>
            <input type="email" id="sup_email" maxlength="255" autocomplete="off" dir="ltr" lang="en" placeholder="name@example.com">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCreditLimitCol): ?>
        <div class="sup-grid-r5-credit">
            <label for="sup_credit_limit">الحد الائتماني (اختياري)</label>
            <input type="number" id="sup_credit_limit" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr" placeholder="فارغ = بلا حد">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPaymentTermsCol): ?>
        <div class="sup-grid-r5-terms">
            <label for="sup_payment_terms_days">أيام السداد (تنبيه اختياري)</label>
            <input type="number" id="sup_payment_terms_days" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" placeholder="مثال: 30">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPaymentModeCol): ?>
        <div class="sup-grid-r5-payment">
            <label for="sup_payment_mode">طريقة السداد الافتراضية</label>
            <select id="sup_payment_mode">
                <?php foreach ($paymentModeOptions as $modeCode => $modeLabel): ?>
                    <option value="<?php echo htmlspecialchars($modeCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $modeCode === 'cash' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($modeLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCurrencyCol): ?>
        <div class="sup-grid-r5-currency">
            <label for="sup_currency_code">العملة الافتراضية <span style="color:#b45309;">*</span></label>
            <select id="sup_currency_code" required>
                <?php foreach ($currencyOptions as $curCode => $curLabel): ?>
                    <option value="<?php echo htmlspecialchars($curCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $curCode === 'KWD' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($curLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="sup-grid-r6-notes">
            <label for="sup_notes">ملاحظات</label>
            <input type="text" id="sup_notes" autocomplete="off" placeholder="اختياري">
        </div>
        <?php if ($hasSupplierTaxProfileCol): ?>
        <div class="sup-grid-r7-tax-profile">
            <label for="sup_tax_profile">المعاملة الضريبية <span style="color:#b45309;">*</span></label>
            <select id="sup_tax_profile" required>
                <?php foreach ($taxProfileOptions as $taxCode => $taxLabel): ?>
                    <option value="<?php echo htmlspecialchars($taxCode, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $taxCode === 'exempt' ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($taxLabel, ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierTaxNumberCol): ?>
        <div class="sup-grid-r7-tax-number">
            <label for="sup_tax_number">الرقم الضريبي (اختياري)</label>
            <input type="text" id="sup_tax_number" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCommercialRegCol): ?>
        <div class="sup-grid-r7-commercial">
            <label for="sup_commercial_reg">السجل التجاري (اختياري)</label>
            <input type="text" id="sup_commercial_reg" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierAddressLineCol): ?>
        <div class="sup-grid-r3-address">
            <label for="sup_address_line">العنوان الإداري (اختياري)</label>
            <input type="text" id="sup_address_line" maxlength="255" autocomplete="off" placeholder="عنوان المورد">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCityAreaCol): ?>
        <div class="sup-grid-r3-city">
            <label for="sup_city_area">المدينة / المنطقة (اختياري)</label>
            <input type="text" id="sup_city_area" maxlength="160" autocomplete="off" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankNameCol): ?>
        <div class="sup-grid-r8-bank-name">
            <label for="sup_bank_name">اسم البنك (اختياري)</label>
            <input type="text" id="sup_bank_name" maxlength="160" autocomplete="off" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankIbanCol): ?>
        <div class="sup-grid-r8-iban">
            <label for="sup_bank_iban">IBAN (اختياري)</label>
            <input type="text" id="sup_bank_iban" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="KW..">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankHolderCol): ?>
        <div class="sup-grid-r8-bank-holder">
            <label for="sup_bank_account_holder">صاحب الحساب البنكي (اختياري)</label>
            <input type="text" id="sup_bank_account_holder" maxlength="160" autocomplete="off" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPreferredWarehouseCol): ?>
        <div>
            <label for="sup_preferred_warehouse_id">المخزن الافتراضي للاستلام</label>
            <input type="number" id="sup_preferred_warehouse_id" value="1" min="1" step="1" inputmode="numeric" lang="en" dir="ltr" readonly>
            <p class="card-hint" style="margin:6px 0 0;">حالياً النظام يعمل بمخزن شركة موحّد؛ القيمة الافتراضية 1.</p>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierIsBlockedCol): ?>
        <div>
            <label for="sup_is_blocked">حالة التعامل</label>
            <select id="sup_is_blocked">
                <option value="0" selected>نشط للتعامل</option>
                <option value="1">محظور مؤقتاً</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBlockReasonCol): ?>
        <div style="grid-column:1/-1;">
            <label for="sup_block_reason">سبب الحظر (عند الحظر)</label>
            <input type="text" id="sup_block_reason" maxlength="255" autocomplete="off" placeholder="اختياري إذا المورد نشط">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierAttachmentsCol): ?>
        <div style="grid-column:1/-1;">
            <label for="sup_attachments_json">مرفقات المورد (اختياري)</label>
            <textarea id="sup_attachments_json" rows="3" autocomplete="off" placeholder="ضع روابط/أسماء الملفات أو JSON مبسط للمرفقات"></textarea>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPayableCol): ?>
        <div style="grid-column:1/-1;">
            <label for="sup_payable_account_id">حساب ذمة المورد في الدليل (إلزامي)</label>
            <select id="sup_payable_account_id" required>
                <option value="" disabled selected>— اختر حساب خصوم (ورقة ترحيل) —</option>
                <?php foreach ($supplierPayablePickAccounts as $acc): ?>
                    <?php
                    $lid = (int) $acc['id'];
                    $lab = (trim((string) ($acc['code'] ?? '')) !== '' ? $acc['code'] . ' — ' : '') . ($acc['name'] ?? '');
                    ?>
                    <option value="<?php echo $lid; ?>"><?php echo htmlspecialchars($lab, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="actions" style="margin-top:8px;">
                <button type="button" class="btn-secondary" onclick="supOpenChartOfAccounts()">فتح دليل الحسابات</button>
            </div>
            <p class="card-hint" style="margin:6px 0 0;">أنشئ حساباً فرعياً تحت جذر الخصوم (مثلاً باسم المورد) من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=chart_of_accounts'), ENT_QUOTES, 'UTF-8'); ?>">دليل الحسابات</a> ثم اختره هنا. ويمكنك النقر المزدوج على الحقل لفتح الدليل سريعاً.</p>
        </div>
        <?php endif; ?>
    </div>
    <div class="actions" style="margin-top:12px;">
        <button type="button" onclick="supSave()">حفظ</button>
        <button type="button" class="btn-secondary" onclick="supResetForm()">تفريغ النموذج</button>
    </div>
</div>

<div class="card">
    <h3>سجل الموردين</h3>
    <div class="party-registry-toolbar">
        <div class="party-registry-search-wrap">
            <label for="sup_filter" class="party-registry-search-label">بحث</label>
            <input type="search" id="sup_filter" class="party-registry-search" placeholder="اسم، هاتف، ملاحظات…" autocomplete="off" lang="ar" dir="rtl" oninput="supFilterRows()">
        </div>
    </div>
    <?php if ($rows === []): ?>
        <p class="card-hint">لا يوجد موردون بعد — أضف مورداً من النموذج أعلاه ليظهر في قوائم المشتريات.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table class="admin-table party-registry-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الكود</th>
                        <th>الاسم</th>
                        <?php if ($hasSupplierIsActiveCol): ?><th>الحالة</th><?php endif; ?>
                        <th>الهاتف</th>
                        <?php if ($hasSupplierCurrencyCol): ?><th>العملة</th><?php endif; ?>
                        <?php if ($hasSupplierTaxProfileCol): ?><th>الضريبة</th><?php endif; ?>
                        <th>ذمة المورد</th>
                        <?php if ($hasSupplierPayableCol): ?><th>حساب الذمة (دليل)</th><?php endif; ?>
                        <th>مشتريات</th>
                        <th class="party-registry-col-actions">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="sup_tbody">
                    <?php foreach ($rows as $s): ?>
                        <?php
                        $sid = (int) $s['id'];
                        $bal = orange_party_balance_supplier($pdo, $sid);
                        $phone = (string) ($s['phone'] ?? '');
                        $phoneCountryDial = (string) ($s['phone_country_dial'] ?? '');
                        $codeDisp = isset($s['code']) && (string) $s['code'] !== '' ? (string) $s['code'] : '—';
                        $isActive = $hasSupplierIsActiveCol ? (int) ($s['is_active'] ?? 1) : 1;
                        $statusDisp = $isActive === 1 ? 'نشط' : 'غير نشط';
                        $currencyCode = $hasSupplierCurrencyCol ? strtoupper(trim((string) ($s['currency_code'] ?? 'KWD'))) : '';
                        $taxProfileCode = $hasSupplierTaxProfileCol ? trim((string) ($s['tax_profile'] ?? 'exempt')) : '';
                        $contactPerson = $hasSupplierContactPersonCol ? (string) ($s['contact_person'] ?? '') : '';
                        $email = $hasSupplierEmailCol ? (string) ($s['email'] ?? '') : '';
                        $commercialReg = $hasSupplierCommercialRegCol ? (string) ($s['commercial_reg'] ?? '') : '';
                        $addressLine = $hasSupplierAddressLineCol ? (string) ($s['address_line'] ?? '') : '';
                        $cityArea = $hasSupplierCityAreaCol ? (string) ($s['city_area'] ?? '') : '';
                        $creditLimit = $hasSupplierCreditLimitCol ? ($s['credit_limit'] ?? null) : null;
                        $bankName = $hasSupplierBankNameCol ? (string) ($s['bank_name'] ?? '') : '';
                        $bankIban = $hasSupplierBankIbanCol ? (string) ($s['bank_iban'] ?? '') : '';
                        $bankAccountHolder = $hasSupplierBankHolderCol ? (string) ($s['bank_account_holder'] ?? '') : '';
                        $preferredWarehouseId = $hasSupplierPreferredWarehouseCol ? (int) ($s['preferred_warehouse_id'] ?? 0) : 0;
                        $isBlocked = $hasSupplierIsBlockedCol ? (int) ($s['is_blocked'] ?? 0) : 0;
                        $blockReason = $hasSupplierBlockReasonCol ? (string) ($s['block_reason'] ?? '') : '';
                        $attachmentsJson = $hasSupplierAttachmentsCol ? (string) ($s['attachments_json'] ?? '') : '';
                        $pAcc = $hasSupplierPayableCol ? (int) ($s['payable_account_id'] ?? 0) : 0;
                        $pAccLabel = $pAcc > 0 ? $payableAccountLabel($pAcc) : '';
                        $hayRaw = trim((string) ($s['code'] ?? '') . ' ' . ($s['name'] ?? '') . ' ' . $phone . ' ' . ($s['notes'] ?? '') . ' ' . $pAccLabel . ' ' . $statusDisp . ' ' . $currencyCode . ' ' . $taxProfileCode . ' ' . $contactPerson . ' ' . $email . ' ' . $commercialReg . ' ' . $addressLine . ' ' . $cityArea . ' ' . $bankName . ' ' . $bankIban . ' ' . $bankAccountHolder . ' ' . $blockReason);
                        $hay = function_exists('mb_strtolower') ? mb_strtolower($hayRaw, 'UTF-8') : strtolower($hayRaw);
                        ?>
                        <tr data-sup-search="<?php echo htmlspecialchars($hay, ENT_QUOTES, 'UTF-8'); ?>">
                            <td><?php echo $sid; ?></td>
                            <td dir="ltr"><?php echo htmlspecialchars($codeDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($s['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php if ($hasSupplierIsActiveCol): ?>
                                <td><?php echo $isActive === 1 ? '<span class="badge ok">نشط</span>' : '<span class="badge cancelled">غير نشط</span>'; ?></td>
                            <?php endif; ?>
                            <td dir="ltr"><?php echo $phone !== '' ? htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
                            <?php if ($hasSupplierCurrencyCol): ?>
                                <td dir="ltr"><?php echo htmlspecialchars($currencyLabel($currencyCode), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endif; ?>
                            <?php if ($hasSupplierTaxProfileCol): ?>
                                <td><?php echo htmlspecialchars($taxProfileLabel($taxProfileCode), ENT_QUOTES, 'UTF-8'); ?></td>
                            <?php endif; ?>
                            <td dir="ltr"><?php echo number_format($bal, 3); ?></td>
                            <?php if ($hasSupplierPayableCol): ?>
                                <td><small><?php
                                    echo $pAcc > 0
                                        ? htmlspecialchars($pAccLabel, ENT_QUOTES, 'UTF-8')
                                        : '<span class="badge cancelled">بلا حساب ذمة — حدّث المورد</span>';
                                ?></small></td>
                            <?php endif; ?>
                            <td><?php echo (int) ($s['purchase_cnt'] ?? 0); ?></td>
                            <td class="party-registry-actions">
                                <button type="button" class="btn-secondary party-registry-btn" onclick='supEdit(<?php echo json_encode([
                                    'id' => $sid,
                                    'code' => (string) ($s['code'] ?? ''),
                                    'name' => (string) ($s['name'] ?? ''),
                                    'phone' => $phone,
                                    'phone_country_dial' => $phoneCountryDial,
                                    'notes' => (string) ($s['notes'] ?? ''),
                                    'payable_account_id' => $pAcc > 0 ? $pAcc : null,
                                    'is_active' => $isActive,
                                    'currency_code' => $currencyCode !== '' ? $currencyCode : 'KWD',
                                    'payment_mode' => (string) ($s['payment_mode'] ?? 'cash'),
                                    'payment_terms_days' => isset($s['payment_terms_days']) && $s['payment_terms_days'] !== null ? (int) $s['payment_terms_days'] : null,
                                    'tax_profile' => $taxProfileCode !== '' ? $taxProfileCode : 'exempt',
                                    'tax_number' => (string) ($s['tax_number'] ?? ''),
                                    'contact_person' => $contactPerson,
                                    'email' => $email,
                                    'commercial_reg' => $commercialReg,
                                    'address_line' => $addressLine,
                                    'city_area' => $cityArea,
                                    'credit_limit' => $creditLimit,
                                    'bank_name' => $bankName,
                                    'bank_iban' => $bankIban,
                                    'bank_account_holder' => $bankAccountHolder,
                                    'preferred_warehouse_id' => $preferredWarehouseId > 0 ? $preferredWarehouseId : null,
                                    'is_blocked' => $isBlocked,
                                    'block_reason' => $blockReason,
                                    'attachments_json' => $attachmentsJson,
                                    'current_balance' => round((float) $bal, 3),
                                ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>تعديل</button>
                                <a class="btn btn-secondary party-registry-btn" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_reports#partner-balances-suppliers'), ENT_QUOTES, 'UTF-8'); ?>">ذمة المورد</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/input-constraints.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
function supOpenChartOfAccounts() {
    window.open('/admin/index.php?page=chart_of_accounts', '_blank');
}
function supPhoneCountryEl() {
    return document.getElementById('sup_phone_country');
}
function supSplitPhoneForForm(stored, preferredDial) {
    var raw = String(stored || '').trim();
    var pref = String(preferredDial || '').trim();
    if (!raw) {
        return { country: pref || '__intl__', phone: '' };
    }
    var digits = raw.replace(/\D/g, '');
    if (pref && digits.indexOf(pref) === 0) {
        var byPref = digits.slice(pref.length);
        if (byPref !== '') {
            return { country: pref, phone: byPref };
        }
    }
    var normFn = window.orangeNormalizeCustomerPhone;
    var norm = normFn ? normFn(raw, null) : null;
    if (!norm) {
        return { country: pref || '__intl__', phone: raw };
    }
    var normDigits = norm.replace(/\D/g, '');
    var prefs = ['965', '92', '91', '63'];
    for (var i = 0; i < prefs.length; i++) {
        var cc = prefs[i];
        if (normDigits.indexOf(cc) !== 0) {
            continue;
        }
        var nat = normDigits.slice(cc.length);
        if (nat.length < 4) {
            continue;
        }
        if (normFn && normFn(nat, cc) === norm) {
            return { country: cc, phone: nat };
        }
    }
    return { country: '__intl__', phone: norm.charAt(0) === '+' ? norm.slice(1) : norm };
}
function supSetValue(id, value) {
    var el = document.getElementById(id);
    if (!el) {
        return;
    }
    el.value = value == null ? '' : String(value);
}
function supFormatBalanceValue(value) {
    var n = Number(value);
    if (!isFinite(n)) {
        n = 0;
    }
    return n.toLocaleString('en-US', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
}
function supSetCurrentBalance(value) {
    var el = document.getElementById('sup_current_balance');
    if (!el) {
        return;
    }
    el.value = supFormatBalanceValue(value);
}
function supToggleBlockReasonField() {
    var blockEl = document.getElementById('sup_is_blocked');
    var reasonEl = document.getElementById('sup_block_reason');
    if (!blockEl || !reasonEl) {
        return;
    }
    var isBlocked = parseInt(String(blockEl.value || '0'), 10) === 1;
    reasonEl.required = isBlocked;
    reasonEl.placeholder = isBlocked ? 'سبب الحظر مطلوب' : 'اختياري إذا المورد نشط';
}
function supEnforceFormVisibility() {
    var grid = document.getElementById('sup_form_grid');
    if (!grid) {
        return;
    }
    var card = document.getElementById('sup_form_card');
    var isMobile = !!(window.matchMedia && window.matchMedia('(max-width: 991px)').matches);
    var isTablet = !!(window.matchMedia && window.matchMedia('(max-width: 1200px)').matches);
    var cols = isMobile ? '1fr' : (isTablet ? '1fr 1fr' : 'repeat(4, minmax(0, 1fr))');
    grid.style.setProperty('display', 'grid', 'important');
    grid.style.setProperty('grid-template-columns', cols, 'important');
    grid.style.setProperty('gap', '12px 18px', 'important');
    grid.style.setProperty('overflow', 'visible', 'important');
    grid.style.setProperty('height', 'auto', 'important');
    grid.style.setProperty('max-height', 'none', 'important');
    if (card) {
        card.style.setProperty('height', 'auto', 'important');
        card.style.setProperty('max-height', 'none', 'important');
        card.style.setProperty('overflow', 'visible', 'important');
    }
    Array.prototype.forEach.call(grid.children, function (child) {
        if (!child || child.nodeType !== 1) {
            return;
        }
        child.style.setProperty('display', 'block', 'important');
        child.style.setProperty('visibility', 'visible', 'important');
        child.style.setProperty('opacity', '1', 'important');
        child.style.setProperty('height', 'auto', 'important');
        child.style.setProperty('max-height', 'none', 'important');
        child.style.setProperty('overflow', 'visible', 'important');
    });
}
function supResetForm() {
    document.getElementById('sup_id').value = '0';
    document.getElementById('sup_code').value = '';
    supSetCurrentBalance(0);
    document.getElementById('sup_name').value = '';
    document.getElementById('sup_phone').value = '';
    var cc = supPhoneCountryEl();
    if (cc) {
        cc.value = '__intl__';
    }
    var ia = document.getElementById('sup_is_active');
    if (ia) {
        ia.value = '1';
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        ccy.value = 'KWD';
    }
    var txp = document.getElementById('sup_tax_profile');
    if (txp) {
        txp.value = 'exempt';
    }
    var pm = document.getElementById('sup_payment_mode');
    if (pm) {
        pm.value = 'cash';
    }
    var td = document.getElementById('sup_payment_terms_days');
    if (td) {
        td.value = '';
    }
    var txno = document.getElementById('sup_tax_number');
    if (txno) {
        txno.value = '';
    }
    supSetValue('sup_contact_person', '');
    supSetValue('sup_email', '');
    supSetValue('sup_commercial_reg', '');
    supSetValue('sup_address_line', '');
    supSetValue('sup_city_area', '');
    supSetValue('sup_credit_limit', '');
    supSetValue('sup_bank_name', '');
    supSetValue('sup_bank_iban', '');
    supSetValue('sup_bank_account_holder', '');
    supSetValue('sup_preferred_warehouse_id', '1');
    supSetValue('sup_is_blocked', '0');
    supSetValue('sup_block_reason', '');
    supSetValue('sup_attachments_json', '');
    supToggleBlockReasonField();
    document.getElementById('sup_notes').value = '';
    var ps = document.getElementById('sup_payable_account_id');
    if (ps) {
        ps.value = '';
        if (ps.options.length && ps.options[0].disabled) {
            ps.selectedIndex = 0;
        }
    }
}
function supEdit(row) {
    document.getElementById('sup_id').value = String(row.id || 0);
    document.getElementById('sup_code').value = row.code || '';
    document.getElementById('sup_name').value = row.name || '';
    var split = supSplitPhoneForForm(row.phone || '', row.phone_country_dial || '');
    var cc = supPhoneCountryEl();
    if (cc) {
        cc.value = split.country && split.country !== '' ? split.country : '__intl__';
    }
    document.getElementById('sup_phone').value = split.phone || '';
    var ia = document.getElementById('sup_is_active');
    if (ia) {
        ia.value = String((row.is_active || 0) === 1 ? 1 : 0);
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        ccy.value = row.currency_code || 'KWD';
    }
    var txp = document.getElementById('sup_tax_profile');
    if (txp) {
        txp.value = row.tax_profile || 'exempt';
    }
    var pm = document.getElementById('sup_payment_mode');
    if (pm) {
        pm.value = row.payment_mode || 'cash';
    }
    var td = document.getElementById('sup_payment_terms_days');
    if (td) {
        td.value = row.payment_terms_days != null ? String(row.payment_terms_days) : '';
    }
    var txno = document.getElementById('sup_tax_number');
    if (txno) {
        txno.value = row.tax_number || '';
    }
    supSetValue('sup_contact_person', row.contact_person || '');
    supSetValue('sup_email', row.email || '');
    supSetValue('sup_commercial_reg', row.commercial_reg || '');
    supSetValue('sup_address_line', row.address_line || '');
    supSetValue('sup_city_area', row.city_area || '');
    supSetValue('sup_credit_limit', row.credit_limit != null ? row.credit_limit : '');
    supSetValue('sup_bank_name', row.bank_name || '');
    supSetValue('sup_bank_iban', row.bank_iban || '');
    supSetValue('sup_bank_account_holder', row.bank_account_holder || '');
    supSetValue('sup_preferred_warehouse_id', row.preferred_warehouse_id != null ? row.preferred_warehouse_id : '1');
    supSetValue('sup_is_blocked', (row.is_blocked || 0) === 1 ? '1' : '0');
    supSetValue('sup_block_reason', row.block_reason || '');
    supSetValue('sup_attachments_json', row.attachments_json || '');
    supToggleBlockReasonField();
    supSetCurrentBalance(row.current_balance != null ? row.current_balance : 0);
    document.getElementById('sup_notes').value = row.notes || '';
    var ps = document.getElementById('sup_payable_account_id');
    if (ps) {
        var p = row.payable_account_id != null && row.payable_account_id > 0 ? String(row.payable_account_id) : '';
        ps.value = p;
        if (p && ps.value !== p) {
            alert('حساب الذمة الحالي غير ضمن قائمة الخصوم — راجع الدليل أو اختر حساباً صالحاً');
        }
    }
    document.getElementById('sup_name').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function supSave() {
    var id = parseInt(document.getElementById('sup_id').value, 10) || 0;
    var name = document.getElementById('sup_name').value.trim();
    var phone = document.getElementById('sup_phone').value.trim();
    var ccEl = supPhoneCountryEl();
    var phoneCountry = ccEl ? String(ccEl.value || '').trim() : '';
    var intlSel = ccEl && ccEl.tagName === 'SELECT' && phoneCountry === '__intl__';
    var ccForNorm = intlSel ? null : phoneCountry && phoneCountry !== '__intl__' ? phoneCountry : null;
    var notes = document.getElementById('sup_notes').value.trim();
    if (!name) {
        alert('اسم المورد مطلوب');
        return;
    }
    if (phone && window.orangeNormalizeCustomerPhone) {
        var ok = window.orangeNormalizeCustomerPhone(phone, ccForNorm, intlSel);
        if (!ok) {
            alert('رقم الهاتف غير صالح. استخدم + أو 00 مع كود الدولة، أو اختر الدولة وأدخل الرقم الوطني.');
            return;
        }
    }
    var payload = {
        name: name,
        phone: phone || null,
        notes: notes || null,
        phone_country: phoneCountry !== '' ? phoneCountry : null
    };
    var ia = document.getElementById('sup_is_active');
    if (ia) {
        payload.is_active = parseInt(String(ia.value || '1'), 10) === 1 ? 1 : 0;
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        var cVal = String(ccy.value || '').trim();
        if (!cVal) {
            alert('العملة الافتراضية للمورد مطلوبة');
            return;
        }
        payload.currency_code = cVal;
    }
    var txp = document.getElementById('sup_tax_profile');
    if (txp) {
        var tVal = String(txp.value || '').trim();
        if (!tVal) {
            alert('المعاملة الضريبية مطلوبة');
            return;
        }
        payload.tax_profile = tVal;
    }
    var pm = document.getElementById('sup_payment_mode');
    if (pm) {
        payload.payment_mode = String(pm.value || 'cash');
    }
    var td = document.getElementById('sup_payment_terms_days');
    if (td) {
        var termsRaw = String(td.value || '').trim();
        if (termsRaw === '') {
            payload.payment_terms_days = null;
        } else {
            var termsVal = parseInt(termsRaw, 10);
            if (isNaN(termsVal) || termsVal < 0) {
                alert('أيام السداد يجب أن تكون رقماً صحيحاً >= 0');
                return;
            }
            payload.payment_terms_days = termsVal;
        }
    }
    var txno = document.getElementById('sup_tax_number');
    if (txno) {
        payload.tax_number = String(txno.value || '').trim() || null;
    }
    var cp = document.getElementById('sup_contact_person');
    if (cp) {
        payload.contact_person = String(cp.value || '').trim() || null;
    }
    var em = document.getElementById('sup_email');
    if (em) {
        var emVal = String(em.value || '').trim();
        if (emVal !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emVal)) {
            alert('البريد الإلكتروني غير صالح');
            return;
        }
        payload.email = emVal || null;
    }
    var cr = document.getElementById('sup_commercial_reg');
    if (cr) {
        payload.commercial_reg = String(cr.value || '').trim() || null;
    }
    var al = document.getElementById('sup_address_line');
    if (al) {
        payload.address_line = String(al.value || '').trim() || null;
    }
    var ca = document.getElementById('sup_city_area');
    if (ca) {
        payload.city_area = String(ca.value || '').trim() || null;
    }
    var cl = document.getElementById('sup_credit_limit');
    if (cl) {
        var clVal = String(cl.value || '').trim();
        if (clVal === '') {
            payload.credit_limit = null;
        } else {
            var clNum = parseFloat(clVal);
            if (isNaN(clNum) || clNum < 0) {
                alert('الحد الائتماني يجب أن يكون رقماً موجباً');
                return;
            }
            payload.credit_limit = clNum;
        }
    }
    var bn = document.getElementById('sup_bank_name');
    if (bn) {
        payload.bank_name = String(bn.value || '').trim() || null;
    }
    var bi = document.getElementById('sup_bank_iban');
    if (bi) {
        payload.bank_iban = String(bi.value || '').trim() || null;
    }
    var bah = document.getElementById('sup_bank_account_holder');
    if (bah) {
        payload.bank_account_holder = String(bah.value || '').trim() || null;
    }
    var pwh = document.getElementById('sup_preferred_warehouse_id');
    if (pwh) {
        var pwhNum = parseInt(String(pwh.value || '1'), 10);
        payload.preferred_warehouse_id = pwhNum > 0 ? pwhNum : 1;
    }
    var ib = document.getElementById('sup_is_blocked');
    if (ib) {
        payload.is_blocked = parseInt(String(ib.value || '0'), 10) === 1 ? 1 : 0;
    }
    var br = document.getElementById('sup_block_reason');
    if (br) {
        var brVal = String(br.value || '').trim();
        if ((payload.is_blocked || 0) === 1 && brVal === '') {
            alert('سبب الحظر مطلوب عند اختيار مورد محظور');
            return;
        }
        payload.block_reason = brVal || null;
    }
    var at = document.getElementById('sup_attachments_json');
    if (at) {
        payload.attachments_json = String(at.value || '').trim() || null;
    }
    var ps = document.getElementById('sup_payable_account_id');
    if (ps) {
        var pv = parseInt(String(ps.value || '0'), 10);
        if (!(pv > 0)) {
            alert('اختر حساب ذمة المورد من الدليل — إلزامي');
            return;
        }
        payload.payable_account_id = pv;
    }
    if (id > 0) {
        payload.id = id;
    }
    postJSON('/admin/api/suppliers/save.php', payload)
        .then(function (r) {
            var msg = r.message || (r.success ? 'تم' : 'فشل');
            if (r && r.success && r.code) {
                msg += '\nكود المورد: ' + r.code;
            }
            alert(msg);
            if (r.success) {
                location.reload();
            }
        })
        .catch(function (e) {
            alert(e.message || String(e));
        });
}
function supFilterRows() {
    var q = (document.getElementById('sup_filter') && document.getElementById('sup_filter').value || '')
        .trim()
        .toLowerCase();
    document.querySelectorAll('#sup_tbody tr[data-sup-search]').forEach(function (tr) {
        var hay = (tr.getAttribute('data-sup-search') || '').toLowerCase();
        tr.style.display = !q || hay.indexOf(q) !== -1 ? '' : 'none';
    });
}
(function initSuppliersPage() {
    var payableEl = document.getElementById('sup_payable_account_id');
    if (payableEl) {
        payableEl.addEventListener('dblclick', function () {
            supOpenChartOfAccounts();
        });
    }
    var blockEl = document.getElementById('sup_is_blocked');
    if (blockEl) {
        blockEl.addEventListener('change', supToggleBlockReasonField);
    }
    supToggleBlockReasonField();
    supEnforceFormVisibility();
    window.addEventListener('resize', supEnforceFormVisibility);
})();
</script>
