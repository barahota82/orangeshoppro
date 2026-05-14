<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/storefront_phone_country_select.php';
require_once __DIR__ . '/../../includes/delivery_areas.php';

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
$payableAccountMeta = static function (int $id) use ($leafAccountOptions, $supplierPayablePickAccounts): array {
    foreach ($supplierPayablePickAccounts as $a) {
        if ((int) $a['id'] === $id) {
            $code = trim((string) ($a['code'] ?? ''));
            $name = (string) ($a['name'] ?? '');
            return [
                'id' => (int) $id,
                'code' => $code,
                'name' => $name,
                'label' => ($code !== '' ? $code . ' — ' : '') . $name,
            ];
        }
    }
    foreach ($leafAccountOptions as $a) {
        if ((int) $a['id'] === $id) {
            $code = trim((string) ($a['code'] ?? ''));
            $name = (string) ($a['name'] ?? '');
            return [
                'id' => (int) $id,
                'code' => $code,
                'name' => $name,
                'label' => ($code !== '' ? $code . ' — ' : '') . $name,
            ];
        }
    }

    return [
        'id' => (int) $id,
        'code' => '',
        'name' => '#' . $id,
        'label' => '#' . $id,
    ];
};
$supplierPayablePickAccountsPayload = [];
foreach ($supplierPayablePickAccounts as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $acode = trim((string) ($a['code'] ?? ''));
    $aname = trim((string) ($a['name'] ?? ''));
    $supplierPayablePickAccountsPayload[] = [
        'id' => $aid,
        'code' => $acode,
        'name' => $aname,
        'label' => ($acode !== '' ? $acode . ' — ' : '') . $aname,
    ];
}
/**
 * معاينة الكود التالي للمورد في النموذج (للعرض فقط).
 * الحفظ الفعلي يظل عبر منطق API الذي يولّد/يثبّت الكود تلقائياً.
 */
$nextSupplierCodePreview = '1';
if (orange_table_exists($pdo, 'suppliers') && orange_table_has_column($pdo, 'suppliers', 'code')) {
    $codeRows = $pdo->query(
        'SELECT code FROM suppliers WHERE code IS NOT NULL AND TRIM(code) <> \'\' ORDER BY id DESC LIMIT 5000'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $maxCodeNum = 0;
    foreach ($codeRows as $rawCode) {
        $code = trim((string) $rawCode);
        if ($code === '') {
            continue;
        }
        if (preg_match_all('/\d+/', $code, $m) && isset($m[0]) && is_array($m[0])) {
            foreach ($m[0] as $chunk) {
                $num = (int) $chunk;
                if ($num > $maxCodeNum) {
                    $maxCodeNum = $num;
                }
            }
        }
    }
    $nextSupplierCodePreview = (string) max(1, $maxCodeNum + 1);
}
$hasSupplierPayableCol = orange_table_has_column($pdo, 'suppliers', 'payable_account_id');
$hasSupplierStatusCol = orange_table_has_column($pdo, 'suppliers', 'status');
$hasSupplierCurrencyCol = orange_table_has_column($pdo, 'suppliers', 'currency_code');
$hasSupplierTaxProfileCol = orange_table_has_column($pdo, 'suppliers', 'tax_profile');
$hasSupplierPaymentModeCol = orange_table_has_column($pdo, 'suppliers', 'payment_mode');
$hasSupplierPaymentTermsCol = orange_table_has_column($pdo, 'suppliers', 'payment_terms_days');
$hasSupplierTaxNumberCol = orange_table_has_column($pdo, 'suppliers', 'tax_number');
$hasSupplierPhoneCountryDialCol = orange_table_has_column($pdo, 'suppliers', 'phone_country_dial');
$hasSupplierPhoneNationalCol = orange_table_has_column($pdo, 'suppliers', 'phone_national');
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
$hasSupplierBlockReasonCol = orange_table_has_column($pdo, 'suppliers', 'block_reason');
$hasSupplierAttachmentsCol = orange_table_has_column($pdo, 'suppliers', 'attachments_json');
$supplierAreaOptions = [];
$deliveryAreaRows = function_exists('orange_delivery_areas_admin_list') ? orange_delivery_areas_admin_list($pdo) : [];
$seenSupplierAreas = [];
foreach ($deliveryAreaRows as $daRow) {
    if (!is_array($daRow)) {
        continue;
    }
    $nameAr = trim((string) ($daRow['name_ar'] ?? ''));
    $nameEn = trim((string) ($daRow['name_en'] ?? ''));
    $areaValue = $nameAr !== '' ? $nameAr : $nameEn;
    if ($areaValue === '') {
        continue;
    }
    $areaKey = function_exists('mb_strtolower') ? mb_strtolower($areaValue, 'UTF-8') : strtolower($areaValue);
    if (isset($seenSupplierAreas[$areaKey])) {
        continue;
    }
    $seenSupplierAreas[$areaKey] = true;
    $display = $areaValue;
    if ($nameAr !== '' && $nameEn !== '' && strcasecmp($nameAr, $nameEn) !== 0) {
        $display = $nameAr . ' — ' . $nameEn;
    }
    if ((int) ($daRow['is_active'] ?? 0) !== 1) {
        $display .= ' (غير منطقة توصيل حالياً)';
    }
    $supplierAreaOptions[] = [
        'value' => $areaValue,
        'label' => $display,
    ];
}
$supplierSchemaMissingCols = [];
$supplierSchemaMap = [
    'payable_account_id' => $hasSupplierPayableCol,
    'status' => $hasSupplierStatusCol,
    'phone_country_dial' => $hasSupplierPhoneCountryDialCol,
    'phone_national' => $hasSupplierPhoneNationalCol,
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
$hasSupplierStatusCol = true;
$hasSupplierCurrencyCol = true;
$hasSupplierTaxProfileCol = true;
$hasSupplierPaymentModeCol = true;
$hasSupplierPaymentTermsCol = true;
$hasSupplierTaxNumberCol = true;
$hasSupplierPhoneCountryDialCol = true;
$hasSupplierPhoneNationalCol = true;
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

$rows = [];
$supplierSearchRowsPayload = [];
$totalBalance = 0.0;
if (orange_table_exists($pdo, 'suppliers')) {
    $sql = 'SELECT s.* FROM suppliers s ORDER BY s.name ASC, s.id ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $sid = (int) ($r['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $bal = orange_party_balance_supplier($pdo, $sid);
        $totalBalance += $bal;
        $statusRaw = strtolower(trim((string) ($r['status'] ?? 'active')));
        if (!in_array($statusRaw, ['active', 'inactive', 'blocked'], true)) {
            $statusRaw = 'active';
        }
        $currencyCode = strtoupper(trim((string) ($r['currency_code'] ?? 'KWD')));
        $taxProfileCode = trim((string) ($r['tax_profile'] ?? 'exempt'));
        $phone = (string) ($r['phone'] ?? '');
        $contactPerson = (string) ($r['contact_person'] ?? '');
        $email = (string) ($r['email'] ?? '');
        $commercialReg = (string) ($r['commercial_reg'] ?? '');
        $addressLine = (string) ($r['address_line'] ?? '');
        $cityArea = (string) ($r['city_area'] ?? '');
        $bankName = (string) ($r['bank_name'] ?? '');
        $bankIban = (string) ($r['bank_iban'] ?? '');
        $bankAccountHolder = (string) ($r['bank_account_holder'] ?? '');
        $blockReason = (string) ($r['block_reason'] ?? '');
        $attachmentsJson = (string) ($r['attachments_json'] ?? '');
        $pAcc = (int) ($r['payable_account_id'] ?? 0);
        $pAccMeta = $pAcc > 0 ? $payableAccountMeta($pAcc) : ['id' => 0, 'code' => '', 'name' => '', 'label' => ''];
        $pAccLabel = (string) ($pAccMeta['label'] ?? '');
        $searchHayRaw = trim(
            (string) ($r['code'] ?? '') . ' ' .
            (string) ($r['name'] ?? '') . ' ' .
            $phone . ' ' .
            (string) ($r['notes'] ?? '') . ' ' .
            $pAccLabel . ' ' .
            $statusRaw . ' ' .
            $currencyCode . ' ' .
            $taxProfileCode . ' ' .
            $contactPerson . ' ' .
            $email . ' ' .
            $commercialReg . ' ' .
            $addressLine . ' ' .
            $cityArea . ' ' .
            $bankName . ' ' .
            $bankIban . ' ' .
            $bankAccountHolder . ' ' .
            $blockReason
        );
        $searchHay = function_exists('mb_strtolower') ? mb_strtolower($searchHayRaw, 'UTF-8') : strtolower($searchHayRaw);
        $supplierSearchRowsPayload[] = [
            'id' => $sid,
            'code' => (string) ($r['code'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'phone' => $phone,
            'phone_country_dial' => (string) ($r['phone_country_dial'] ?? ''),
            'phone_national' => (string) ($r['phone_national'] ?? ''),
            'notes' => (string) ($r['notes'] ?? ''),
            'payable_account_id' => $pAcc > 0 ? $pAcc : null,
            'payable_account_code' => (string) ($pAccMeta['code'] ?? ''),
            'payable_account_name' => (string) ($pAccMeta['name'] ?? ''),
            'payable_account_label' => $pAccLabel,
            'status' => $statusRaw,
            'currency_code' => $currencyCode !== '' ? $currencyCode : 'KWD',
            'payment_mode' => (string) ($r['payment_mode'] ?? 'cash'),
            'payment_terms_days' => isset($r['payment_terms_days']) && $r['payment_terms_days'] !== null ? (int) $r['payment_terms_days'] : null,
            'tax_profile' => $taxProfileCode !== '' ? $taxProfileCode : 'exempt',
            'tax_number' => (string) ($r['tax_number'] ?? ''),
            'contact_person' => $contactPerson,
            'email' => $email,
            'commercial_reg' => $commercialReg,
            'address_line' => $addressLine,
            'city_area' => $cityArea,
            'credit_limit' => isset($r['credit_limit']) && $r['credit_limit'] !== null ? (float) $r['credit_limit'] : null,
            'bank_name' => $bankName,
            'bank_iban' => $bankIban,
            'bank_account_holder' => $bankAccountHolder,
            'preferred_warehouse_id' => isset($r['preferred_warehouse_id']) && (int) $r['preferred_warehouse_id'] > 0 ? (int) $r['preferred_warehouse_id'] : null,
            'block_reason' => $blockReason,
            'attachments_json' => $attachmentsJson,
            'current_balance' => round((float) $bal, 3),
            'search_text' => $searchHay,
        ];
    }
}
$count = count($rows);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>الموردين</h1>
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
    row-gap: 12px;
    column-gap: 12px;
    grid-template-areas:
        "sup_r1_code . . ."
        "sup_r2_row sup_r2_row sup_r2_row sup_r2_row"
        "sup_r3_block_reason sup_r3_block_reason sup_r3_block_reason sup_r3_block_reason"
        "sup_r4_city sup_r4_address sup_r4_address sup_r4_address"
        "sup_r5_country sup_r5_phone sup_r5_email sup_r5_contact"
        "sup_r6_credit sup_r6_terms sup_r6_payment sup_r6_currency"
        "sup_r7_notes sup_r7_notes sup_r7_notes sup_r7_notes"
        "sup_r8_bank_name sup_r8_iban sup_r8_iban sup_r8_bank_holder"
        "sup_r9_tax_profile sup_r9_tax_number sup_r9_commercial sup_r9_attachments";
}
#sup_form_grid.suppliers-form-grid.suppliers-form-grid--block-hidden {
    grid-template-areas:
        "sup_r1_code . . ."
        "sup_r2_row sup_r2_row sup_r2_row sup_r2_row"
        "sup_r4_city sup_r4_address sup_r4_address sup_r4_address"
        "sup_r5_country sup_r5_phone sup_r5_email sup_r5_contact"
        "sup_r6_credit sup_r6_terms sup_r6_payment sup_r6_currency"
        "sup_r7_notes sup_r7_notes sup_r7_notes sup_r7_notes"
        "sup_r8_bank_name sup_r8_iban sup_r8_iban sup_r8_bank_holder"
        "sup_r9_tax_profile sup_r9_tax_number sup_r9_commercial sup_r9_attachments";
}
#sup_form_grid.suppliers-form-grid input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
#sup_form_grid.suppliers-form-grid select {
    height: 42px;
    min-height: 42px;
    box-sizing: border-box;
}
#sup_form_grid.suppliers-form-grid textarea {
    min-height: 96px;
    box-sizing: border-box;
}
#sup_form_grid .sup-payable-fields {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 2fr);
    gap: 10px 14px;
}
#sup_form_grid .sup-payable-fields input[readonly] {
    background: #f8fafc;
    cursor: default;
}
#sup_form_grid .sup-payable-fields input[disabled] {
    background: #f8fafc;
    cursor: default;
}
#sup_form_grid .sup-grid-r1-code { grid-area: sup_r1_code; }
#sup_form_grid .sup-grid-r2-row {
    grid-area: sup_r2_row;
    display: grid;
    grid-template-columns: minmax(0, 1.12fr) minmax(0, 1.12fr) minmax(0, 1.12fr) minmax(0, 0.82fr) minmax(0, 0.82fr);
    gap: 12px;
}
#sup_form_grid .sup-grid-r2-row > div {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
#sup_form_grid .sup-grid-r2-name { grid-column: 1 / span 3; }
#sup_form_grid .sup-grid-r2-balance { grid-column: 4 / span 1; }
#sup_form_grid .sup-grid-r2-status { grid-column: 5 / span 1; }
#sup_form_grid .sup-grid-r2-balance #sup_current_balance {
    max-width: 100%;
}
#sup_form_grid .sup-grid-r2-status #sup_status {
    max-width: 100%;
}
#sup_form_grid .sup-grid-r3-block-reason { grid-area: sup_r3_block_reason; }
#sup_form_grid .sup-grid-r3-city { grid-area: sup_r4_city; }
#sup_form_grid .sup-grid-r3-address { grid-area: sup_r4_address; }
#sup_form_grid .sup-grid-r4-country { grid-area: sup_r5_country; }
#sup_form_grid .sup-grid-r4-phone { grid-area: sup_r5_phone; }
#sup_form_grid .sup-grid-r4-email { grid-area: sup_r5_email; }
#sup_form_grid .sup-grid-r4-contact { grid-area: sup_r5_contact; }
#sup_form_grid .sup-grid-r5-credit { grid-area: sup_r6_credit; }
#sup_form_grid .sup-grid-r5-terms { grid-area: sup_r6_terms; }
#sup_form_grid .sup-grid-r5-payment { grid-area: sup_r6_payment; }
#sup_form_grid .sup-grid-r5-currency { grid-area: sup_r6_currency; }
#sup_form_grid .sup-grid-r6-notes { grid-area: sup_r7_notes; }
#sup_form_grid .sup-grid-r7-tax-profile { grid-area: sup_r9_tax_profile; }
#sup_form_grid .sup-grid-r7-tax-number { grid-area: sup_r9_tax_number; }
#sup_form_grid .sup-grid-r7-commercial { grid-area: sup_r9_commercial; }
#sup_form_grid .sup-grid-r8-bank-name { grid-area: sup_r8_bank_name; }
#sup_form_grid .sup-grid-r8-iban { grid-area: sup_r8_iban; }
#sup_form_grid .sup-grid-r8-bank-holder { grid-area: sup_r8_bank_holder; }
#sup_form_grid .sup-grid-r10-attachments-summary {
    grid-area: sup_r9_attachments;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
#sup_form_grid .sup-attachments-inline {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 10px;
    width: 100%;
}
#sup_form_grid #sup_attachments_count {
    max-width: none;
    width: 100%;
    text-align: center;
}
#sup_form_grid #sup_attachments_manage_btn {
    width: 100%;
    height: 42px;
}
.sup-attachments-modal__dialog {
    width: min(920px, calc(100vw - 24px));
    max-height: calc(100vh - 24px);
    overflow: auto;
}
.sup-attachments-toolbar {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    gap: 10px;
    align-items: end;
    margin-bottom: 10px;
}
.sup-attachments-toolbar button {
    height: 42px;
}
.sup-attachments-list {
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #ffffff;
    padding: 8px;
    min-height: 54px;
}
.sup-attachment-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 8px 6px;
    border-bottom: 1px solid #f1f5f9;
}
.sup-attachment-row:last-child {
    border-bottom: 0;
}
.sup-attachment-main {
    min-width: 0;
    flex: 1 1 auto;
}
.sup-attachment-title {
    font-weight: 600;
    color: #0f172a;
    line-height: 1.35;
}
.sup-attachment-meta {
    margin-top: 3px;
    font-size: 12px;
    color: #64748b;
    line-height: 1.4;
}
.sup-attachment-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 0 0 auto;
}
.sup-search-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.sup-search-item__name {
    font-weight: 600;
    color: #0f172a;
    line-height: 1.35;
}
.sup-search-item__meta {
    font-size: 12px;
    color: #64748b;
    line-height: 1.35;
}
@media (max-width: 1200px) {
    #sup_form_grid.suppliers-form-grid {
        grid-template-areas: none !important;
    }
    #sup_form_grid .sup-grid-r1-code,
    #sup_form_grid .sup-grid-r2-row,
    #sup_form_grid .sup-grid-r2-name,
    #sup_form_grid .sup-grid-r2-balance,
    #sup_form_grid .sup-grid-r2-status,
    #sup_form_grid .sup-grid-r3-city,
    #sup_form_grid .sup-grid-r3-address,
    #sup_form_grid .sup-grid-r3-block-reason,
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
    #sup_form_grid .sup-grid-r8-bank-holder,
    #sup_form_grid .sup-grid-r10-attachments-summary {
        grid-area: auto !important;
    }
    #sup_form_grid .sup-payable-fields {
        grid-template-columns: 1fr;
    }
    #sup_form_grid .sup-grid-r2-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }
    #sup_form_grid .sup-grid-r2-name {
        grid-column: 1 / -1;
    }
    #sup_form_grid .sup-grid-r2-balance,
    #sup_form_grid .sup-grid-r2-status {
        grid-column: auto;
    }
    #sup_form_grid .sup-attachments-inline {
        grid-template-columns: 1fr;
    }
    .sup-attachments-toolbar {
        grid-template-columns: 1fr;
    }
    .sup-attachment-row {
        flex-direction: column;
        align-items: stretch;
    }
    .sup-attachment-actions {
        justify-content: flex-start;
    }
}
</style>

<div class="card" id="sup_form_card">
    <input type="hidden" id="sup_id" value="0">
    <?php if ($hasSupplierPreferredWarehouseCol): ?>
    <input type="hidden" id="sup_preferred_warehouse_id" value="1">
    <?php endif; ?>
    <div class="form-grid suppliers-form-grid" id="sup_form_grid">
        <div class="sup-grid-r1-code">
            <label for="sup_code">كود المورد</label>
            <input type="text" id="sup_code" class="admin-sort-field admin-sort-field--muted" maxlength="32" autocomplete="off" dir="ltr" lang="en" value="<?php echo htmlspecialchars($nextSupplierCodePreview, ENT_QUOTES, 'UTF-8'); ?>" readonly>
        </div>
        <div class="sup-grid-r2-row">
            <div class="sup-grid-r2-name">
                <label for="sup_name">اسم المورد</label>
                <input type="text" id="sup_name" autocomplete="off" placeholder="اسم المورد أو الشركة">
            </div>
            <div class="sup-grid-r2-balance">
                <label for="sup_current_balance">الرصيد الحالي المستحق للمورد</label>
                <input type="text" id="sup_current_balance" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0.000" readonly>
            </div>
            <?php if ($hasSupplierStatusCol): ?>
            <div class="sup-grid-r2-status">
                <label for="sup_status">حالة المورد</label>
                <select id="sup_status" class="admin-sort-field">
                    <option value="active" selected>نشط</option>
                    <option value="inactive">غير نشط</option>
                    <option value="blocked">محظور مؤقتاً</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($hasSupplierPhoneCountryDialCol): ?>
        <div class="sup-grid-r4-country">
            <label for="sup_phone_country">كود الدولة</label>
            <input type="search" id="sup_phone_country" list="sup_phone_country_list" autocomplete="off" dir="ltr" lang="en" placeholder="اكتب اسم الدولة أو +965 أو 965">
            <datalist id="sup_phone_country_list"></datalist>
        </div>
        <?php endif; ?>
        <div class="sup-grid-r4-phone">
            <label for="sup_phone">الهاتف</label>
            <input type="text" id="sup_phone" class="js-orange-phone-input" autocomplete="off" dir="ltr" lang="en" placeholder="اكتب الرقم المحلي فقط بدون كود الدولة">
        </div>
        <?php if ($hasSupplierContactPersonCol): ?>
        <div class="sup-grid-r4-contact">
            <label for="sup_contact_person">مسؤول التواصل</label>
            <input type="text" id="sup_contact_person" maxlength="160" autocomplete="off" placeholder="اسم مسؤول التواصل">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierEmailCol): ?>
        <div class="sup-grid-r4-email">
            <label for="sup_email">البريد الإلكتروني</label>
            <input type="email" id="sup_email" maxlength="255" autocomplete="off" dir="ltr" lang="en" placeholder="name@example.com">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCreditLimitCol): ?>
        <div class="sup-grid-r5-credit">
            <label for="sup_credit_limit">الحد الائتماني</label>
            <input type="number" id="sup_credit_limit" class="admin-inp-money" step="any" min="0" inputmode="decimal" lang="en" dir="ltr" placeholder="فارغ = بلا حد">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPaymentTermsCol): ?>
        <div class="sup-grid-r5-terms">
            <label for="sup_payment_terms_days">أيام السداد</label>
            <input type="text" id="sup_payment_terms_days" inputmode="numeric" lang="en" dir="ltr" placeholder="مثال: 30">
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
            <label for="sup_tax_number">الرقم الضريبي</label>
            <input type="text" id="sup_tax_number" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCommercialRegCol): ?>
        <div class="sup-grid-r7-commercial">
            <label for="sup_commercial_reg">السجل التجاري</label>
            <input type="text" id="sup_commercial_reg" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierAddressLineCol): ?>
        <div class="sup-grid-r3-address">
            <label for="sup_address_line">العنوان</label>
            <input type="text" id="sup_address_line" maxlength="255" autocomplete="off" placeholder="عنوان المورد">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierCityAreaCol): ?>
        <div class="sup-grid-r3-city">
            <label for="sup_city_area">المنطقة</label>
            <select id="sup_city_area" autocomplete="address-level1">
                <option value="">اختياري</option>
                <?php foreach ($supplierAreaOptions as $areaOpt): ?>
                    <option value="<?php echo htmlspecialchars((string) $areaOpt['value'], ENT_QUOTES, 'UTF-8'); ?>">
                        <?php echo htmlspecialchars((string) $areaOpt['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankNameCol): ?>
        <div class="sup-grid-r8-bank-name">
            <label for="sup_bank_name">اسم البنك</label>
            <input type="text" id="sup_bank_name" maxlength="160" autocomplete="off" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankIbanCol): ?>
        <div class="sup-grid-r8-iban">
            <label for="sup_bank_iban">IBAN</label>
            <input type="text" id="sup_bank_iban" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="KW..">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBankHolderCol): ?>
        <div class="sup-grid-r8-bank-holder">
            <label for="sup_bank_account_holder">صاحب الحساب البنكي</label>
            <input type="text" id="sup_bank_account_holder" maxlength="160" autocomplete="off" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierBlockReasonCol): ?>
        <div id="sup_block_reason_wrap" class="sup-grid-r3-block-reason" style="display:none;">
            <label for="sup_block_reason">سبب الحظر (عند الحظر)</label>
            <input type="text" id="sup_block_reason" maxlength="255" autocomplete="off" placeholder="اختياري إذا المورد غير محظور">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierAttachmentsCol): ?>
        <div class="sup-grid-r10-attachments-summary" id="sup_attachments_wrap">
            <label for="sup_attachments_count">عدد المرفقات</label>
            <div class="sup-attachments-inline">
                <input type="text" id="sup_attachments_count" class="admin-sort-field admin-sort-field--muted" dir="ltr" lang="en" value="0" readonly>
                <button type="button" class="btn-secondary" id="sup_attachments_manage_btn">إدارة المرفقات</button>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPayableCol): ?>
        <div style="grid-column:1/-1;">
            <label>ربط حساب المورد بالدليل المحاسبي</label>
            <input type="hidden" id="sup_payable_account_id" value="">
            <div class="sup-payable-fields">
                <div>
                    <label for="sup_payable_account_code">كود الحساب</label>
                    <input type="text" id="sup_payable_account_code" autocomplete="off" dir="ltr" lang="en" readonly placeholder="نقرتان للاختيار" title="نقرتان للاختيار">
                </div>
                <div>
                    <label for="sup_payable_account_name">اسم الحساب</label>
                    <input type="text" id="sup_payable_account_name" class="admin-inp-readonly" autocomplete="off" readonly disabled tabindex="-1" placeholder="يُعبأ تلقائياً" title="يُعبأ تلقائياً">
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <div class="actions admin-actions--start" style="margin-top:12px;">
        <button type="button" onclick="supSave()">حفظ</button>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchases'), ENT_QUOTES, 'UTF-8'); ?>">فاتورة مشتريات</a>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=purchase_returns'), ENT_QUOTES, 'UTF-8'); ?>">مردود مشتريات</a>
        <button type="button" class="btn-secondary" onclick="supResetForm()">اضافة مورد</button>
        <a class="btn btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=partner_supplier_payment'), ENT_QUOTES, 'UTF-8'); ?>">سداد فواتير</a>
        <button type="button" class="btn-secondary" id="sup_open_statement_btn">كشف حساب</button>
        <button type="button" class="btn-secondary" id="sup_open_search_btn">بحث مورد</button>
    </div>
</div>

<?php if ($hasSupplierPayableCol): ?>
<div class="gl-pick-modal" id="sup_payable_pick_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sup_payable_pick_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sup_payable_pick_title">
        <h3 id="sup_payable_pick_title" class="gl-pick-modal__title">اختيار حساب ذمة المورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">نقرتان للاختيار</p>
        <input type="search" id="sup_payable_pick_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sup_payable_pick_list"></ul>
        <button type="button" class="btn-secondary" id="sup_payable_pick_close">إغلاق</button>
    </div>
</div>
<?php endif; ?>

<?php if ($hasSupplierAttachmentsCol): ?>
<div class="gl-pick-modal" id="sup_attachments_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sup_attachments_backdrop"></div>
    <div class="gl-pick-modal__dialog sup-attachments-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sup_attachments_title">
        <h3 id="sup_attachments_title" class="gl-pick-modal__title">مرفقات المورد</h3>
        <p class="gl-pick-modal__hint muted" id="sup_attachments_hint" style="margin:0 0 10px;font-size:0.9rem;">
            PDF وصور فقط — حد أقصى 5 مرفقات لكل مورد (حتى 20MB للملف قبل الضغط).
        </p>
        <div class="sup-attachments-toolbar">
            <div>
                <label for="sup_attachment_file">اختر ملف</label>
                <input type="file" id="sup_attachment_file" accept=".pdf,image/*">
            </div>
            <div>
                <label for="sup_attachment_name">اسم الملف</label>
                <input type="text" id="sup_attachment_name" maxlength="191" autocomplete="off" placeholder="اختياري (يؤخذ من اسم الملف)">
            </div>
            <div class="actions" style="margin:0;">
                <button type="button" class="btn-secondary" id="sup_attachment_upload_btn">رفع مرفق</button>
            </div>
        </div>
        <div class="sup-attachments-list" id="sup_attachments_list"></div>
        <div class="actions" style="margin-top:12px;">
            <button type="button" class="btn-secondary" id="sup_attachments_close">إغلاق</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="gl-pick-modal" id="sup_search_modal" hidden aria-hidden="true">
    <div class="gl-pick-modal__backdrop" id="sup_search_backdrop"></div>
    <div class="gl-pick-modal__dialog" dir="rtl" role="dialog" aria-modal="true" aria-labelledby="sup_search_title">
        <h3 id="sup_search_title" class="gl-pick-modal__title">بحث مورد</h3>
        <p class="gl-pick-modal__hint muted" style="margin:0 0 8px;font-size:0.9rem;">دبل كليك لاختيار المورد وتعبئة النموذج</p>
        <input type="search" id="sup_search_q" class="gl-pick-modal__search admin-inp" placeholder="ابحث بالكود أو الاسم أو الهاتف…" autocomplete="off" dir="rtl">
        <ul class="gl-pick-modal__list" id="sup_search_list"></ul>
        <button type="button" class="btn-secondary" id="sup_search_close">إغلاق</button>
    </div>
</div>

<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/country-codes.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="<?php echo htmlspecialchars(storefront_public_path(storefront_asset_url('/assets/js/input-constraints.js')), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
var SUP_NEXT_AUTO_CODE = <?php echo json_encode($nextSupplierCodePreview, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var SUP_PAYABLE_PICK_ACCOUNTS = <?php echo json_encode($supplierPayablePickAccountsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var SUP_SEARCH_ROWS = <?php echo json_encode($supplierSearchRowsPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var SUP_PARTNER_STATEMENT_URL = <?php echo json_encode(storefront_public_path('/admin/index.php?page=partner_account_statement'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
var supPayablePickSeq = 0;
var supPayablePickSearchTimer = null;
var supSearchTimer = null;

function supPayableFields() {
    return {
        idEl: document.getElementById('sup_payable_account_id'),
        codeEl: document.getElementById('sup_payable_account_code'),
        nameEl: document.getElementById('sup_payable_account_name')
    };
}
function supPayableFindById(id) {
    var wanted = parseInt(String(id || '0'), 10) || 0;
    if (wanted <= 0) {
        return null;
    }
    for (var i = 0; i < SUP_PAYABLE_PICK_ACCOUNTS.length; i++) {
        var a = SUP_PAYABLE_PICK_ACCOUNTS[i];
        if ((parseInt(String(a.id || '0'), 10) || 0) === wanted) {
            return a;
        }
    }
    return null;
}
function supPayableSetAccount(acc) {
    var f = supPayableFields();
    if (!f.idEl || !f.codeEl || !f.nameEl) {
        return;
    }
    if (!acc || (parseInt(String(acc.id || '0'), 10) || 0) <= 0) {
        f.idEl.value = '';
        f.codeEl.value = '';
        f.nameEl.value = '';
        return;
    }
    f.idEl.value = String(parseInt(String(acc.id || '0'), 10) || 0);
    f.codeEl.value = String(acc.code || '');
    f.nameEl.value = String(acc.name || '');
}
function supPayablePickerClose() {
    var modal = document.getElementById('sup_payable_pick_modal');
    if (!modal) {
        return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gl-pick-open');
}
function supPayablePickerRender(q) {
    var listEl = document.getElementById('sup_payable_pick_list');
    if (!listEl) {
        return;
    }
    var query = String(q || '').trim().toLowerCase();
    var rows = SUP_PAYABLE_PICK_ACCOUNTS.filter(function (a) {
        if (!query) {
            return true;
        }
        var code = String(a.code || '').toLowerCase();
        var name = String(a.name || '').toLowerCase();
        var label = String(a.label || '').toLowerCase();
        return code.indexOf(query) !== -1 || name.indexOf(query) !== -1 || label.indexOf(query) !== -1;
    });
    listEl.innerHTML = '';
    if (rows.length === 0) {
        listEl.innerHTML = '<li class="gl-pick-empty">لا نتائج</li>';
        return;
    }
    rows.forEach(function (a) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item';
        li.textContent = String(a.label || '');
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        function chooseAccount() {
            supPayableSetAccount(a);
            supPayablePickerClose();
        }
        li.addEventListener('click', chooseAccount);
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                chooseAccount();
            }
        });
        listEl.appendChild(li);
    });
}
function supPayablePickerOpen() {
    var modal = document.getElementById('sup_payable_pick_modal');
    var qEl = document.getElementById('sup_payable_pick_q');
    var listEl = document.getElementById('sup_payable_pick_list');
    if (!modal || !qEl || !listEl) {
        return;
    }
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    qEl.value = '';
    listEl.innerHTML = '';
    supPayablePickSeq += 1;
    supPayablePickerRender('');
    qEl.focus();
}
function supPhoneCountryEl() {
    return document.getElementById('sup_phone_country');
}
function supPhoneCountryListEl() {
    return document.getElementById('sup_phone_country_list');
}
function supFlagToRegion(flagValue) {
    var symbols = Array.from(String(flagValue || ''));
    if (symbols.length < 2) {
        return '';
    }
    var region = '';
    for (var i = 0; i < 2; i++) {
        var cp = symbols[i].codePointAt(0);
        if (typeof cp !== 'number' || cp < 0x1F1E6 || cp > 0x1F1FF) {
            return '';
        }
        region += String.fromCharCode(65 + (cp - 0x1F1E6));
    }
    return region;
}
function supCountryNameArabic(item) {
    if (!item) {
        return '';
    }
    var explicit = String(item.country_ar || '').trim();
    if (explicit !== '') {
        return explicit;
    }
    if (typeof Intl === 'undefined' || typeof Intl.DisplayNames !== 'function') {
        return '';
    }
    var region = supFlagToRegion(item.flag || '');
    if (!region) {
        return '';
    }
    try {
        if (!window.__supCountryDisplayNameAr) {
            window.__supCountryDisplayNameAr = new Intl.DisplayNames(['ar'], { type: 'region' });
        }
        var translated = window.__supCountryDisplayNameAr.of(region);
        if (translated && translated !== region) {
            return String(translated).trim();
        }
    } catch (eDisplayName) {
        return '';
    }
    return '';
}
function supCountryCodeRows() {
    if (Array.isArray(window.__supCountryCodeRowsCache)) {
        return window.__supCountryCodeRowsCache;
    }
    var src = Array.isArray(window.COUNTRY_CODES) ? window.COUNTRY_CODES : [];
    var rows = [];
    src.forEach(function (item) {
        if (!item) {
            return;
        }
        var dial = String(item.code || '').replace(/\D/g, '');
        if (!dial) {
            return;
        }
        var countryAr = supCountryNameArabic(item);
        var country = countryAr !== '' ? countryAr : String(item.country || '').trim();
        var label = country !== '' ? country + ' (+' + dial + ')' : '+' + dial;
        rows.push({ dial: dial, name: country, label: label });
    });
    window.__supCountryCodeRowsCache = rows;
    return rows;
}
function supCountryRowMatchesQuery(row, queryRaw) {
    var q = String(queryRaw || '').trim();
    if (q === '') {
        return true;
    }
    var qLower = q.toLowerCase();
    var qDigits = q.replace(/\D/g, '');
    var label = String(row && row.label || '').toLowerCase();
    if (label.indexOf(qLower) !== -1) {
        return true;
    }
    if (qDigits !== '') {
        var dial = String(row && row.dial || '');
        if (dial.indexOf(qDigits) !== -1) {
            return true;
        }
        if (('+' + dial).indexOf('+' + qDigits) !== -1) {
            return true;
        }
    }
    return false;
}
function supCountryLabelByDial(dialRaw) {
    var dial = String(dialRaw || '').replace(/\D/g, '');
    if (dial === '') {
        return '';
    }
    var rows = supCountryCodeRows();
    for (var i = 0; i < rows.length; i++) {
        if (String(rows[i].dial || '') === dial) {
            return String(rows[i].label || '');
        }
    }
    return '';
}
function supCountryDialFromText(rawValue) {
    var raw = String(rawValue || '').trim();
    if (raw === '') {
        return null;
    }
    var rows = supCountryCodeRows();
    var digits = raw.replace(/\D/g, '');
    if (digits !== '') {
        for (var i = 0; i < rows.length; i++) {
            if (String(rows[i].dial || '') === digits) {
                return String(rows[i].dial || '');
            }
        }
        var byDial = rows.filter(function (row) {
            return String(row.dial || '').indexOf(digits) !== -1;
        });
        if (byDial.length === 1) {
            return String(byDial[0].dial || '');
        }
    }
    var lower = raw.toLowerCase();
    var exact = rows.find(function (row) {
        return String(row.label || '').toLowerCase() === lower;
    });
    if (exact) {
        return String(exact.dial || '');
    }
    var byLabel = rows.filter(function (row) {
        return String(row.label || '').toLowerCase().indexOf(lower) !== -1;
    });
    if (byLabel.length === 1) {
        return String(byLabel[0].dial || '');
    }
    return null;
}
function supSetPhoneCountryByDial(dialRaw) {
    var el = supPhoneCountryEl();
    if (!el) {
        return;
    }
    var dial = String(dialRaw || '').replace(/\D/g, '');
    if (dial === '') {
        el.value = '';
        return;
    }
    var label = supCountryLabelByDial(dial);
    el.value = label !== '' ? label : ('+' + dial);
}
function supDefaultCountryDial() {
    var rows = supCountryCodeRows();
    for (var i = 0; i < rows.length; i++) {
        if (String(rows[i].dial || '') === '965') {
            return '965';
        }
    }
    return rows.length ? String(rows[0].dial || '') : '';
}
function supPopulateCountryCodes(searchQuery) {
    var el = supPhoneCountryEl();
    var listEl = supPhoneCountryListEl();
    if (!el || !listEl) {
        return;
    }
    var query = String(searchQuery != null ? searchQuery : (el.value || '')).trim();
    var queryDigits = query.replace(/\D/g, '');
    var queryHasPlus = /^\s*\+/.test(query);
    var allRows = supCountryCodeRows();
    var rows = allRows.filter(function (row) {
        return supCountryRowMatchesQuery(row, query);
    });
    listEl.innerHTML = '';
    rows.forEach(function (row) {
        var opt = document.createElement('option');
        if (queryDigits !== '') {
            var codePrefix = queryHasPlus ? ('+' + String(row.dial || '')) : String(row.dial || '');
            var countryName = String(row.name || '').trim();
            opt.value = countryName !== '' ? (codePrefix + ' — ' + countryName) : codePrefix;
        } else {
            opt.value = row.label;
        }
        listEl.appendChild(opt);
    });
}
function supPhoneCountryForApi(selectEl) {
    if (!selectEl) {
        return null;
    }
    return supCountryDialFromText(selectEl.value || '');
}
function supSplitPhoneForForm(stored, preferredDial, preferredNational) {
    var raw = String(stored || '').trim();
    var pref = String(preferredDial || '').trim();
    var prefNational = String(preferredNational || '').replace(/\D/g, '');
    if (prefNational !== '') {
        return { country: pref || '', phone: prefNational };
    }
    if (!raw) {
        return { country: pref || '', phone: '' };
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
        return { country: pref || '', phone: raw };
    }
    var normDigits = norm.replace(/\D/g, '');
    var uniq = Object.create(null);
    var prefs = [];
    supCountryCodeRows().forEach(function (row) {
        var cc = String(row.dial || '');
        if (!cc || uniq[cc]) {
            return;
        }
        uniq[cc] = true;
        prefs.push(cc);
    });
    prefs.sort(function (a, b) {
        return b.length - a.length;
    });
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
    return { country: '', phone: norm.charAt(0) === '+' ? norm.slice(1) : norm };
}
function supSetValue(id, value) {
    var el = document.getElementById(id);
    if (!el) {
        return;
    }
    el.value = value == null ? '' : String(value);
}
function supAttachmentSupplierId() {
    return parseInt((document.getElementById('sup_id') || {}).value || '0', 10) || 0;
}
function supAttachmentRows() {
    if (!Array.isArray(window.__supAttachmentRows)) {
        window.__supAttachmentRows = [];
    }
    return window.__supAttachmentRows;
}
function supAttachmentParseJson(raw) {
    var txt = String(raw || '').trim();
    if (!txt) {
        return [];
    }
    var arr = [];
    try {
        arr = JSON.parse(txt);
    } catch (eJson) {
        return [];
    }
    if (!Array.isArray(arr)) {
        return [];
    }
    return arr.filter(function (item) {
        return item && typeof item === 'object' && String(item.id || '').trim() !== '';
    }).map(function (item) {
        return {
            id: String(item.id || '').trim(),
            name: String(item.name || '').trim(),
            path: String(item.path || '').trim(),
            mime: String(item.mime || '').trim(),
            size: parseInt(item.size, 10) || 0,
            uploaded_at: String(item.uploaded_at || '').trim(),
            original_name: String(item.original_name || '').trim()
        };
    });
}
function supAttachmentEscapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
}
function supAttachmentFmtBytes(bytes) {
    var n = parseInt(bytes, 10) || 0;
    if (n < 1024) return n + ' B';
    if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
    return (n / 1048576).toFixed(2) + ' MB';
}
function supAttachmentPublicBase() {
    var base = typeof window.ORANGE_PUBLIC_BASE_PATH === 'string' ? window.ORANGE_PUBLIC_BASE_PATH : '';
    return base.replace(/\/+$/, '');
}
function supAttachmentDownloadUrl(supplierId, attachmentId) {
    return supAttachmentPublicBase()
        + '/admin/api/suppliers/attachment-download.php?supplier_id=' + encodeURIComponent(String(supplierId))
        + '&attachment_id=' + encodeURIComponent(String(attachmentId));
}
function supAttachmentSetRows(rows) {
    window.__supAttachmentRows = Array.isArray(rows) ? rows.slice() : [];
    supAttachmentRender();
}
function supAttachmentLoadFromJson(raw) {
    supAttachmentSetRows(supAttachmentParseJson(raw));
}
function supAttachmentModalEl() {
    return document.getElementById('sup_attachments_modal');
}
function supAttachmentModalOpen() {
    var supplierId = supAttachmentSupplierId();
    if (!(supplierId > 0)) {
        alert('احفظ المورد أولاً ثم أدر المرفقات');
        return;
    }
    var modal = supAttachmentModalEl();
    if (!modal) {
        return;
    }
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    supAttachmentRender();
    var fileEl = document.getElementById('sup_attachment_file');
    if (fileEl) {
        fileEl.focus();
    }
}
function supAttachmentModalClose() {
    var modal = supAttachmentModalEl();
    if (!modal) {
        return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
}
function supAttachmentRender() {
    var list = document.getElementById('sup_attachments_list');
    var hint = document.getElementById('sup_attachments_hint');
    var upBtn = document.getElementById('sup_attachment_upload_btn');
    var countEl = document.getElementById('sup_attachments_count');
    var manageBtn = document.getElementById('sup_attachments_manage_btn');
    var supplierId = supAttachmentSupplierId();
    var rows = supAttachmentRows();
    var max = 5;
    if (countEl) {
        countEl.value = String(rows.length);
    }
    if (manageBtn) {
        manageBtn.disabled = !(supplierId > 0);
        manageBtn.textContent = rows.length > 0 ? ('إدارة المرفقات (' + rows.length + ')') : 'إدارة المرفقات';
    }
    if (upBtn) {
        upBtn.disabled = !(supplierId > 0) || rows.length >= max;
    }
    if (hint) {
        if (!(supplierId > 0)) {
            hint.textContent = 'احفظ المورد أولاً ثم افتح إدارة المرفقات.';
        } else {
            hint.textContent = 'عدد المرفقات: ' + rows.length + ' / ' + max + ' — PDF وصور فقط، حتى 20MB قبل الضغط.';
        }
    }
    if (!list) {
        return;
    }
    if (rows.length === 0) {
        list.innerHTML = '<div class="card-hint">لا توجد مرفقات حالياً.</div>';
        return;
    }
    list.innerHTML = rows.map(function (item) {
        var title = String(item.name || item.original_name || 'مرفق').trim();
        var metaBits = [];
        if (item.size > 0) {
            metaBits.push(supAttachmentFmtBytes(item.size));
        }
        if (item.mime) {
            metaBits.push(String(item.mime));
        }
        if (item.uploaded_at) {
            metaBits.push(String(item.uploaded_at).replace('T', ' '));
        }
        return ''
            + '<div class="sup-attachment-row">'
            + '  <div class="sup-attachment-main">'
            + '    <div class="sup-attachment-title">' + supAttachmentEscapeHtml(title) + '</div>'
            + '    <div class="sup-attachment-meta">' + supAttachmentEscapeHtml(metaBits.join(' — ')) + '</div>'
            + '  </div>'
            + '  <div class="sup-attachment-actions">'
            + '    <a class="btn btn-secondary" href="' + supAttachmentDownloadUrl(supplierId, item.id) + '">تحميل</a>'
            + '    <button type="button" class="btn-danger" data-sup-att-del="' + supAttachmentEscapeHtml(item.id) + '">حذف</button>'
            + '  </div>'
            + '</div>';
    }).join('');
    list.querySelectorAll('[data-sup-att-del]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var attId = String(btn.getAttribute('data-sup-att-del') || '').trim();
            if (attId) {
                supAttachmentDelete(attId);
            }
        });
    });
}
function supAttachmentDelete(attachmentId) {
    var supplierId = supAttachmentSupplierId();
    if (!(supplierId > 0)) {
        alert('احفظ المورد أولاً');
        return;
    }
    if (!confirm('سيتم حذف المرفق نهائياً. هل تريد المتابعة؟')) {
        return;
    }
    postJSON('/admin/api/suppliers/attachment-delete.php', {
        supplier_id: supplierId,
        attachment_id: attachmentId
    }).then(function (res) {
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'تعذر حذف المرفق');
            return;
        }
        supAttachmentSetRows(Array.isArray(res.attachments) ? res.attachments : []);
        alert(res.message || 'تم حذف المرفق');
    }).catch(function (err) {
        alert(err && err.message ? err.message : String(err));
    });
}
async function supAttachmentUpload() {
    var supplierId = supAttachmentSupplierId();
    if (!(supplierId > 0)) {
        alert('احفظ المورد أولاً ثم أضف المرفقات');
        return;
    }
    var rows = supAttachmentRows();
    if (rows.length >= 5) {
        alert('الحد الأقصى لمرفقات المورد هو 5 ملفات');
        return;
    }
    var fileEl = document.getElementById('sup_attachment_file');
    var nameEl = document.getElementById('sup_attachment_name');
    var btn = document.getElementById('sup_attachment_upload_btn');
    var file = fileEl && fileEl.files && fileEl.files[0] ? fileEl.files[0] : null;
    if (!file) {
        alert('اختر ملفاً للرفع');
        return;
    }
    var fd = new FormData();
    fd.append('supplier_id', String(supplierId));
    fd.append('file', file);
    if (nameEl) {
        fd.append('attachment_name', String(nameEl.value || '').trim());
    }
    if (btn) {
        btn.disabled = true;
    }
    try {
        var resp = await fetch('/admin/api/suppliers/attachment-upload.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        });
        var res = {};
        try {
            res = await resp.json();
        } catch (eJson) {
            res = {};
        }
        if (!res || !res.success) {
            alert((res && res.message) ? res.message : 'تعذر رفع المرفق');
            return;
        }
        supAttachmentSetRows(Array.isArray(res.attachments) ? res.attachments : []);
        if (fileEl) {
            fileEl.value = '';
        }
        if (nameEl) {
            nameEl.value = '';
        }
        alert(res.message || 'تم رفع المرفق');
    } catch (eReq) {
        alert(eReq && eReq.message ? eReq.message : String(eReq));
    } finally {
        if (btn) {
            btn.disabled = false;
        }
        supAttachmentRender();
    }
}
function supEnsureCityAreaOption(value) {
    var el = document.getElementById('sup_city_area');
    if (!el || el.tagName !== 'SELECT') {
        return;
    }
    var v = String(value || '').trim();
    if (!v) {
        return;
    }
    var found = false;
    Array.prototype.forEach.call(el.options, function (opt) {
        if (String(opt.value || '').trim() === v) {
            found = true;
        }
    });
    if (found) {
        return;
    }
    var opt = document.createElement('option');
    opt.value = v;
    opt.textContent = v;
    el.appendChild(opt);
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
function supCurrencyDisplayName(code) {
    var cur = String(code || '').trim().toUpperCase();
    if (cur === '') {
        return '';
    }
    if (typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function') {
        try {
            if (!window.__supCurrencyDisplayNames) {
                window.__supCurrencyDisplayNames = new Intl.DisplayNames(['ar', 'en'], { type: 'currency' });
            }
            var name = window.__supCurrencyDisplayNames.of(cur);
            if (typeof name === 'string' && name.trim() !== '' && name.toUpperCase() !== cur) {
                return name.trim();
            }
        } catch (eName) {}
    }
    return cur;
}
function supSetCurrencyValue(code) {
    var sel = document.getElementById('sup_currency_code');
    if (!sel) {
        return;
    }
    var wanted = String(code || '').trim().toUpperCase();
    if (wanted === '') {
        wanted = 'KWD';
    }
    var has = false;
    Array.prototype.forEach.call(sel.options, function (opt) {
        if (String(opt.value || '').trim().toUpperCase() === wanted) {
            has = true;
        }
    });
    if (!has) {
        var opt = document.createElement('option');
        opt.value = wanted;
        var label = supCurrencyDisplayName(wanted);
        opt.textContent = (label && label !== wanted ? label + ' (' + wanted + ')' : wanted);
        sel.appendChild(opt);
    }
    sel.value = wanted;
}
function supPopulateCurrencyOptions() {
    var sel = document.getElementById('sup_currency_code');
    if (!sel || sel.tagName !== 'SELECT') {
        return;
    }
    var selected = String(sel.value || 'KWD').trim().toUpperCase();
    var codes = [];
    if (typeof Intl !== 'undefined' && typeof Intl.supportedValuesOf === 'function') {
        try {
            codes = Intl.supportedValuesOf('currency') || [];
        } catch (eCodes) {
            codes = [];
        }
    }
    if (!codes.length) {
        supSetCurrencyValue(selected);
        return;
    }
    var uniq = Object.create(null);
    var normalized = [];
    codes.forEach(function (code) {
        var up = String(code || '').trim().toUpperCase();
        if (!/^[A-Z]{3}$/.test(up) || uniq[up]) {
            return;
        }
        uniq[up] = true;
        normalized.push(up);
    });
    if (!uniq.KWD) {
        uniq.KWD = true;
        normalized.push('KWD');
    }
    if (selected && /^[A-Z]{3}$/.test(selected) && !uniq[selected]) {
        uniq[selected] = true;
        normalized.push(selected);
    }
    normalized.sort();
    sel.innerHTML = '';
    normalized.forEach(function (code) {
        var opt = document.createElement('option');
        opt.value = code;
        var label = supCurrencyDisplayName(code);
        opt.textContent = (label && label !== code ? label + ' (' + code + ')' : code);
        sel.appendChild(opt);
    });
    supSetCurrencyValue(selected);
}
function supToggleBlockReasonField() {
    var statusEl = document.getElementById('sup_status');
    var reasonEl = document.getElementById('sup_block_reason');
    var wrapEl = document.getElementById('sup_block_reason_wrap');
    var gridEl = document.getElementById('sup_form_grid');
    if (!statusEl || !reasonEl) {
        return;
    }
    var isBlocked = String(statusEl.value || 'active') === 'blocked';
    if (gridEl && gridEl.classList) {
        gridEl.classList.toggle('suppliers-form-grid--block-hidden', !isBlocked);
    }
    if (wrapEl) {
        wrapEl.style.setProperty('display', isBlocked ? 'flex' : 'none', 'important');
        wrapEl.style.setProperty('flex-direction', 'column', 'important');
        wrapEl.style.setProperty('gap', '6px', 'important');
    }
    reasonEl.required = isBlocked;
    reasonEl.placeholder = isBlocked ? 'سبب الحظر مطلوب' : 'اختياري إذا المورد غير محظور';
    if (!isBlocked) {
        reasonEl.value = '';
    }
}
function supEnforceFormVisibility() {
    var grid = document.getElementById('sup_form_grid');
    if (!grid) {
        return;
    }
    var card = document.getElementById('sup_form_card');
    var isMobile = !!(window.matchMedia && window.matchMedia('(max-width: 991px)').matches);
    var isTablet = !!(window.matchMedia && window.matchMedia('(max-width: 1200px)').matches);
    var cols = isMobile
        ? '1fr'
        : (isTablet ? '1fr 1fr' : 'minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr)');
    grid.style.setProperty('display', 'grid', 'important');
    grid.style.setProperty('grid-template-columns', cols, 'important');
    grid.style.setProperty('gap', '12px 12px', 'important');
    grid.style.setProperty('overflow', 'visible', 'important');
    grid.style.setProperty('height', 'auto', 'important');
    grid.style.setProperty('max-height', 'none', 'important');
    if (card) {
        card.style.setProperty('height', 'auto', 'important');
        card.style.setProperty('max-height', 'none', 'important');
        card.style.setProperty('overflow', 'visible', 'important');
    }
    var statusEl = document.getElementById('sup_status');
    var isBlocked = !!statusEl && String(statusEl.value || 'active') === 'blocked';
    if (grid.classList) {
        grid.classList.toggle('suppliers-form-grid--block-hidden', !isBlocked);
    }
    Array.prototype.forEach.call(grid.children, function (child) {
        if (!child || child.nodeType !== 1) {
            return;
        }
        if (child.id === 'sup_block_reason_wrap' && !isBlocked) {
            child.style.setProperty('display', 'none', 'important');
        } else if (child.classList && child.classList.contains('sup-grid-r2-row')) {
            var row2Cols = isMobile
                ? '1fr'
                : (isTablet
                    ? 'minmax(0, 1fr) minmax(0, 1fr)'
                    : 'minmax(0, 1.12fr) minmax(0, 1.12fr) minmax(0, 1.12fr) minmax(0, 0.82fr) minmax(0, 0.82fr)');
            child.style.setProperty('display', 'grid', 'important');
            child.style.setProperty('grid-template-columns', row2Cols, 'important');
            child.style.setProperty('gap', '12px 12px', 'important');
            Array.prototype.forEach.call(child.children, function (rowChild) {
                if (!rowChild || rowChild.nodeType !== 1) {
                    return;
                }
                rowChild.style.setProperty('display', 'flex', 'important');
                rowChild.style.setProperty('flex-direction', 'column', 'important');
                rowChild.style.setProperty('gap', '6px', 'important');
            });
        } else {
            child.style.setProperty('display', 'flex', 'important');
            child.style.setProperty('flex-direction', 'column', 'important');
            child.style.setProperty('gap', '6px', 'important');
        }
        child.style.setProperty('visibility', 'visible', 'important');
        child.style.setProperty('opacity', '1', 'important');
        child.style.setProperty('height', 'auto', 'important');
        child.style.setProperty('max-height', 'none', 'important');
        child.style.setProperty('overflow', 'visible', 'important');
    });
}
function supResetForm() {
    document.getElementById('sup_id').value = '0';
    document.getElementById('sup_code').value = String(SUP_NEXT_AUTO_CODE || '1');
    supSetCurrentBalance(0);
    document.getElementById('sup_name').value = '';
    document.getElementById('sup_phone').value = '';
    var cc = supPhoneCountryEl();
    if (cc) {
        supPopulateCountryCodes('');
        supSetPhoneCountryByDial(supDefaultCountryDial());
    }
    var statusEl = document.getElementById('sup_status');
    if (statusEl) {
        statusEl.value = 'active';
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        supSetCurrencyValue('KWD');
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
    supSetValue('sup_block_reason', '');
    supAttachmentSetRows([]);
    supAttachmentModalClose();
    supSetValue('sup_attachment_name', '');
    var attFile = document.getElementById('sup_attachment_file');
    if (attFile) {
        attFile.value = '';
    }
    supToggleBlockReasonField();
    document.getElementById('sup_notes').value = '';
    supPayableSetAccount(null);
}
function supSearchFindById(id) {
    var wanted = parseInt(String(id || '0'), 10) || 0;
    if (wanted <= 0) {
        return null;
    }
    for (var i = 0; i < SUP_SEARCH_ROWS.length; i++) {
        var row = SUP_SEARCH_ROWS[i];
        if ((parseInt(String(row.id || '0'), 10) || 0) === wanted) {
            return row;
        }
    }
    return null;
}
function supCurrentSupplierRow() {
    var idEl = document.getElementById('sup_id');
    var sid = parseInt(String(idEl && idEl.value || '0'), 10) || 0;
    if (sid <= 0) {
        return null;
    }
    return supSearchFindById(sid);
}
function supSearchModalClose() {
    var modal = document.getElementById('sup_search_modal');
    if (!modal) {
        return;
    }
    modal.hidden = true;
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('gl-pick-open');
}
function supSearchSelect(row) {
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        return;
    }
    supEdit(row);
    supSearchModalClose();
}
function supSearchRender(q) {
    var listEl = document.getElementById('sup_search_list');
    if (!listEl) {
        return;
    }
    var query = String(q || '').trim().toLowerCase();
    var rows = SUP_SEARCH_ROWS.filter(function (row) {
        if (!query) {
            return true;
        }
        var hay = String(row.search_text || '').toLowerCase();
        if (hay === '') {
            hay = (String(row.code || '') + ' ' + String(row.name || '') + ' ' + String(row.phone || '') + ' ' + String(row.notes || '')).toLowerCase();
        }
        return hay.indexOf(query) !== -1;
    });
    listEl.innerHTML = '';
    if (rows.length === 0) {
        listEl.innerHTML = '<li class="gl-pick-empty">لا توجد نتائج</li>';
        return;
    }
    rows.forEach(function (row) {
        var li = document.createElement('li');
        li.className = 'gl-pick-item sup-search-item';
        li.setAttribute('role', 'button');
        li.tabIndex = 0;
        var nameEl = document.createElement('span');
        nameEl.className = 'sup-search-item__name';
        var code = String(row.code || '').trim();
        var name = String(row.name || '').trim();
        nameEl.textContent = (code !== '' ? code + ' — ' : '') + (name !== '' ? name : ('#' + String(row.id || '0')));
        var metaEl = document.createElement('span');
        metaEl.className = 'sup-search-item__meta';
        var phone = String(row.phone || '').trim();
        var st = String(row.status || 'active').toLowerCase();
        var statusLabel = st === 'inactive' ? 'غير نشط' : (st === 'blocked' ? 'محظور مؤقتاً' : 'نشط');
        metaEl.textContent = (phone !== '' ? phone : 'بدون هاتف') + ' • ' + statusLabel;
        li.appendChild(nameEl);
        li.appendChild(metaEl);
        li.addEventListener('dblclick', function () {
            supSearchSelect(row);
        });
        li.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                supSearchSelect(row);
            }
        });
        listEl.appendChild(li);
    });
}
function supSearchModalOpen() {
    var modal = document.getElementById('sup_search_modal');
    var qEl = document.getElementById('sup_search_q');
    var listEl = document.getElementById('sup_search_list');
    if (!modal || !qEl || !listEl) {
        return;
    }
    modal.hidden = false;
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('gl-pick-open');
    qEl.value = '';
    listEl.innerHTML = '';
    supSearchRender('');
    qEl.focus();
}
function supOpenCurrentSupplierStatement() {
    var row = supCurrentSupplierRow();
    if (!row || (parseInt(String(row.id || '0'), 10) || 0) <= 0) {
        alert('اختر المورد أولاً من زر بحث مورد');
        return;
    }
    var accId = parseInt(String(row.payable_account_id || '0'), 10) || 0;
    if (accId <= 0) {
        alert('المورد الحالي بلا حساب ذمة مربوط في الدليل');
        return;
    }
    window.location.href = SUP_PARTNER_STATEMENT_URL + '&account=' + encodeURIComponent(String(accId));
}
function supEdit(row) {
    document.getElementById('sup_id').value = String(row.id || 0);
    document.getElementById('sup_code').value = row.code || '';
    document.getElementById('sup_name').value = row.name || '';
    var split = supSplitPhoneForForm(row.phone || '', row.phone_country_dial || '', row.phone_national || '');
    var cc = supPhoneCountryEl();
    if (cc) {
        supPopulateCountryCodes('');
        supSetPhoneCountryByDial(split.country && split.country !== '' ? split.country : supDefaultCountryDial());
    }
    document.getElementById('sup_phone').value = split.phone || '';
    var statusEl = document.getElementById('sup_status');
    if (statusEl) {
        var st = String(row.status || 'active').toLowerCase();
        if (st !== 'active' && st !== 'inactive' && st !== 'blocked') {
            st = 'active';
        }
        statusEl.value = st;
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        supSetCurrencyValue(row.currency_code || 'KWD');
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
    supEnsureCityAreaOption(row.city_area || '');
    supSetValue('sup_city_area', row.city_area || '');
    supSetValue('sup_credit_limit', row.credit_limit != null ? row.credit_limit : '');
    supSetValue('sup_bank_name', row.bank_name || '');
    supSetValue('sup_bank_iban', row.bank_iban || '');
    supSetValue('sup_bank_account_holder', row.bank_account_holder || '');
    supSetValue('sup_preferred_warehouse_id', row.preferred_warehouse_id != null ? row.preferred_warehouse_id : '1');
    supSetValue('sup_block_reason', row.block_reason || '');
    supAttachmentLoadFromJson(row.attachments_json || '');
    supAttachmentModalClose();
    supSetValue('sup_attachment_name', '');
    var attFile = document.getElementById('sup_attachment_file');
    if (attFile) {
        attFile.value = '';
    }
    supToggleBlockReasonField();
    supSetCurrentBalance(row.current_balance != null ? row.current_balance : 0);
    document.getElementById('sup_notes').value = row.notes || '';
    var pId = row.payable_account_id != null ? (parseInt(String(row.payable_account_id), 10) || 0) : 0;
    if (pId > 0) {
        var fromPick = supPayableFindById(pId);
        if (fromPick) {
            supPayableSetAccount(fromPick);
        } else {
            supPayableSetAccount({
                id: pId,
                code: row.payable_account_code || '',
                name: row.payable_account_name || row.payable_account_label || ('#' + String(pId))
            });
            alert('حساب الذمة الحالي غير ضمن قائمة الخصوم — راجع الدليل أو اختر حساباً صالحاً');
        }
    } else {
        supPayableSetAccount(null);
    }
    document.getElementById('sup_name').closest('.card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function supSave() {
    var id = parseInt(document.getElementById('sup_id').value, 10) || 0;
    var name = document.getElementById('sup_name').value.trim();
    var phone = document.getElementById('sup_phone').value.trim();
    var ccEl = supPhoneCountryEl();
    var phoneCountry = ccEl ? supPhoneCountryForApi(ccEl) : null;
    var ccForNorm = phoneCountry ? String(phoneCountry) : null;
    var notes = document.getElementById('sup_notes').value.trim();
    if (!name) {
        alert('اسم المورد مطلوب');
        return;
    }
    if (phone) {
        if (!ccForNorm) {
            alert('اختيار كود الدولة إلزامي عند إدخال رقم الهاتف.');
            return;
        }
        if (/^\s*(\+|00)/.test(phone)) {
            alert('اكتب الهاتف كرقم محلي فقط بدون + أو 00؛ كود الدولة يُؤخذ من القائمة.');
            return;
        }
        var phoneDigits = phone.replace(/\D+/g, '');
        if (phoneDigits !== '' && phoneDigits.indexOf(ccForNorm) === 0 && phoneDigits.length > ccForNorm.length + 3) {
            alert('لا تكرر كود الدولة داخل خانة الهاتف؛ اكتب الرقم المحلي فقط.');
            return;
        }
    }
    if (phone && window.orangeNormalizeCustomerPhone) {
        var ok = window.orangeNormalizeCustomerPhone(phone, ccForNorm, false);
        if (!ok) {
            alert('رقم الهاتف غير صالح. اكتب الرقم المحلي فقط بعد اختيار كود الدولة.');
            return;
        }
    }
    var payload = {
        name: name,
        phone: phone || null,
        notes: notes || null,
        phone_country: phoneCountry !== null ? phoneCountry : null
    };
    var statusEl = document.getElementById('sup_status');
    if (statusEl) {
        var statusVal = String(statusEl.value || 'active').toLowerCase();
        if (statusVal !== 'active' && statusVal !== 'inactive' && statusVal !== 'blocked') {
            alert('حالة المورد غير صالحة');
            return;
        }
        payload.status = statusVal;
    }
    var ccy = document.getElementById('sup_currency_code');
    if (ccy) {
        var cVal = String(ccy.value || '').trim().toUpperCase();
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
    var br = document.getElementById('sup_block_reason');
    if (br) {
        var brVal = String(br.value || '').trim();
        var statusNow = String(payload.status || 'active');
        if (statusNow === 'blocked' && brVal === '') {
            alert('سبب الحظر مطلوب عند اختيار مورد محظور');
            return;
        }
        payload.block_reason = statusNow === 'blocked' ? (brVal || null) : null;
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
(function initSuppliersPage() {
    var payableCodeEl = document.getElementById('sup_payable_account_code');
    if (payableCodeEl) {
        payableCodeEl.addEventListener('dblclick', function (e) {
            e.preventDefault();
            supPayablePickerOpen();
        });
        payableCodeEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                supPayablePickerOpen();
            }
        });
    }
    var pickQ = document.getElementById('sup_payable_pick_q');
    if (pickQ && !pickQ.getAttribute('data-sup-payable-bound')) {
        pickQ.setAttribute('data-sup-payable-bound', '1');
        pickQ.addEventListener('input', function () {
            if (supPayablePickSearchTimer) {
                clearTimeout(supPayablePickSearchTimer);
            }
            supPayablePickSearchTimer = setTimeout(function () {
                supPayablePickSeq += 1;
                supPayablePickerRender(pickQ.value || '');
            }, 220);
        });
    }
    var pickBackdrop = document.getElementById('sup_payable_pick_backdrop');
    if (pickBackdrop) {
        pickBackdrop.addEventListener('click', supPayablePickerClose);
    }
    var pickClose = document.getElementById('sup_payable_pick_close');
    if (pickClose) {
        pickClose.addEventListener('click', supPayablePickerClose);
    }
    var searchOpenBtn = document.getElementById('sup_open_search_btn');
    if (searchOpenBtn) {
        searchOpenBtn.addEventListener('click', function (e) {
            e.preventDefault();
            supSearchModalOpen();
        });
    }
    var statementBtn = document.getElementById('sup_open_statement_btn');
    if (statementBtn) {
        statementBtn.addEventListener('click', function (e) {
            e.preventDefault();
            supOpenCurrentSupplierStatement();
        });
    }
    var searchBackdrop = document.getElementById('sup_search_backdrop');
    if (searchBackdrop) {
        searchBackdrop.addEventListener('click', supSearchModalClose);
    }
    var searchClose = document.getElementById('sup_search_close');
    if (searchClose) {
        searchClose.addEventListener('click', supSearchModalClose);
    }
    var searchQ = document.getElementById('sup_search_q');
    if (searchQ && !searchQ.getAttribute('data-sup-search-bound')) {
        searchQ.setAttribute('data-sup-search-bound', '1');
        searchQ.addEventListener('input', function () {
            if (supSearchTimer) {
                clearTimeout(supSearchTimer);
            }
            supSearchTimer = setTimeout(function () {
                supSearchRender(searchQ.value || '');
            }, 180);
        });
    }
    document.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Escape') {
            return;
        }
        var searchModal = document.getElementById('sup_search_modal');
        if (searchModal && !searchModal.hidden) {
            supSearchModalClose();
            return;
        }
        var attModal = document.getElementById('sup_attachments_modal');
        if (attModal && !attModal.hidden) {
            supAttachmentModalClose();
            return;
        }
        var modal = document.getElementById('sup_payable_pick_modal');
        if (modal && !modal.hidden) {
            supPayablePickerClose();
        }
    });
    var attManageBtn = document.getElementById('sup_attachments_manage_btn');
    if (attManageBtn) {
        attManageBtn.addEventListener('click', function (e) {
            e.preventDefault();
            supAttachmentModalOpen();
        });
    }
    var attBackdrop = document.getElementById('sup_attachments_backdrop');
    if (attBackdrop) {
        attBackdrop.addEventListener('click', supAttachmentModalClose);
    }
    var attCloseBtn = document.getElementById('sup_attachments_close');
    if (attCloseBtn) {
        attCloseBtn.addEventListener('click', supAttachmentModalClose);
    }
    var attFile = document.getElementById('sup_attachment_file');
    var attName = document.getElementById('sup_attachment_name');
    if (attFile) {
        attFile.addEventListener('change', function () {
            var f = attFile.files && attFile.files[0] ? attFile.files[0] : null;
            if (!f || !attName) {
                return;
            }
            var current = String(attName.value || '').trim();
            if (current !== '') {
                return;
            }
            var raw = String(f.name || '');
            var dot = raw.lastIndexOf('.');
            attName.value = dot > 0 ? raw.slice(0, dot) : raw;
        });
    }
    var attUploadBtn = document.getElementById('sup_attachment_upload_btn');
    if (attUploadBtn) {
        attUploadBtn.addEventListener('click', function () {
            supAttachmentUpload();
        });
    }
    var statusEl = document.getElementById('sup_status');
    if (statusEl) {
        statusEl.addEventListener('change', supToggleBlockReasonField);
    }
    var countryEl = supPhoneCountryEl();
    if (countryEl && !countryEl.getAttribute('data-sup-country-bound')) {
        countryEl.setAttribute('data-sup-country-bound', '1');
        countryEl.addEventListener('input', function () {
            supPopulateCountryCodes(countryEl.value || '');
        });
        countryEl.addEventListener('focus', function () {
            supPopulateCountryCodes(countryEl.value || '');
        });
        countryEl.addEventListener('blur', function () {
            var dial = supCountryDialFromText(countryEl.value || '');
            if (dial) {
                supSetPhoneCountryByDial(dial);
            }
        });
    }
    supPopulateCountryCodes();
    if (countryEl && String(countryEl.value || '').trim() === '') {
        supSetPhoneCountryByDial(supDefaultCountryDial());
    }
    supPopulateCurrencyOptions();
    supAttachmentSetRows([]);
    supToggleBlockReasonField();
    supEnforceFormVisibility();
    window.addEventListener('resize', supEnforceFormVisibility);
})();
</script>
