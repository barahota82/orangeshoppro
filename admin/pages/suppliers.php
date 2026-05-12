<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/party_subledger.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/storefront_phone_country_select.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

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
            إدارة موردي المشتريات: <strong>كود المورد</strong> (فريد اختياري)، الذمم الدائنة، وعدد مستندات الشراء.
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

<div class="card" id="supChecklistCard">
    <h3>شاشة مراجعة جاهزية الموردين للمشتريات</h3>
    <p class="card-hint" style="margin-top:0;">
        حدّد حالة كل بند بصرياً: <strong>موجود</strong> / <strong>ناقص</strong> / <strong>يحتاج تعديل</strong>.
        التقييم يُحفَظ محلياً في المتصفح ليسهّل المراجعة قبل الانتقال لمحور المشتريات.
    </p>
    <style>
        .sup-checklist-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 10px 0 12px;
        }
        .sup-checklist-chip {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 12px;
            color: #334155;
        }
        .sup-checklist-chip strong {
            font-variant-numeric: tabular-nums;
        }
        .sup-checklist-priority {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 11px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .sup-checklist-priority--required {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .sup-checklist-priority--important {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .sup-checklist-priority--operational {
            background: #f8fafc;
            border-color: #e2e8f0;
            color: #334155;
        }
        .sup-checklist-group-row td {
            background: #f8fafc;
            color: #334155;
            font-weight: 700;
        }
        .sup-checklist-status {
            min-width: 125px;
        }
        .sup-checklist-note {
            min-width: 180px;
        }
        .sup-checklist-auto {
            display: inline-block;
            border-radius: 999px;
            padding: 2px 8px;
            font-size: 11px;
            border: 1px solid transparent;
        }
        .sup-checklist-auto--ok {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #065f46;
        }
        .sup-checklist-auto--missing {
            background: #fef2f2;
            border-color: #fecaca;
            color: #991b1b;
        }
        .sup-checklist-auto--fix {
            background: #fff7ed;
            border-color: #fed7aa;
            color: #9a3412;
        }
        .sup-checklist-auto--todo {
            background: #f8fafc;
            border-color: #e2e8f0;
            color: #475569;
        }
    </style>
    <div class="sup-checklist-summary">
        <span class="sup-checklist-chip">إلزامي مكتمل: <strong id="supChecklistReqDone">0/0</strong></span>
        <span class="sup-checklist-chip">جاهزية إلزامي: <strong id="supChecklistReqPct">0%</strong></span>
        <span class="sup-checklist-chip">موجود: <strong id="supChecklistCountOk">0</strong></span>
        <span class="sup-checklist-chip">ناقص: <strong id="supChecklistCountMissing">0</strong></span>
        <span class="sup-checklist-chip">يحتاج تعديل: <strong id="supChecklistCountFix">0</strong></span>
        <span class="sup-checklist-chip">غير مراجع: <strong id="supChecklistCountTodo">0</strong></span>
    </div>
    <div class="actions" style="margin-bottom:10px;">
        <button type="button" class="btn-secondary" id="supChecklistApplyAutoBtn">تطبيق الرصد التلقائي على غير المراجع</button>
        <button type="button" class="btn-secondary" id="supChecklistCopyBtn">نسخ ملخص المراجعة</button>
        <button type="button" class="btn-secondary" id="supChecklistResetBtn">تفريغ التقييم</button>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>البند</th>
                    <th>الأولوية</th>
                    <th>الحالة</th>
                    <th>ملاحظة مراجعة</th>
                    <th>رصد تلقائي من الشاشة الحالية</th>
                </tr>
            </thead>
            <tbody id="supChecklistBody"></tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>مورد جديد أو تعديل</h3>
    <p class="card-hint" style="margin-top:0;">الاسم إلزامي. <strong>حساب ذمة المورد</strong> إلزامي عند الحفظ. يمكن إيقاف المورد (غير نشط) بدل الحذف، وفاتورة الشراء لا تُحفظ لمورد غير نشط.</p>
    <input type="hidden" id="sup_id" value="0">
    <div class="form-grid">
        <div>
            <label for="sup_code">كود المورد (اختياري)</label>
            <input type="text" id="sup_code" maxlength="32" autocomplete="off" dir="ltr" lang="en" placeholder="مثال: V-2001">
        </div>
        <div>
            <label for="sup_name">اسم المورد</label>
            <input type="text" id="sup_name" autocomplete="off" placeholder="اسم المورد أو الشركة">
        </div>
        <?php if ($hasSupplierIsActiveCol): ?>
        <div>
            <label for="sup_is_active">حالة المورد</label>
            <select id="sup_is_active">
                <option value="1" selected>نشط</option>
                <option value="0">غير نشط</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierPhoneCountryDialCol): ?>
        <div>
            <label for="sup_phone_country">كود الدولة (اختياري)</label>
            <?php orange_storefront_render_phone_country_select('sup_phone_country'); ?>
        </div>
        <?php endif; ?>
        <div>
            <label for="sup_phone">الهاتف (اختياري)</label>
            <input type="text" id="sup_phone" class="js-orange-phone-input" autocomplete="off" dir="ltr" lang="en" placeholder="+965… أو 00… أو رقم وطني مع اختيار الدولة">
        </div>
        <?php if ($hasSupplierCurrencyCol): ?>
        <div>
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
        <?php if ($hasSupplierTaxProfileCol): ?>
        <div>
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
        <?php if ($hasSupplierPaymentModeCol): ?>
        <div>
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
        <?php if ($hasSupplierPaymentTermsCol): ?>
        <div>
            <label for="sup_payment_terms_days">أيام السداد (تنبيه اختياري)</label>
            <input type="number" id="sup_payment_terms_days" min="0" step="1" inputmode="numeric" lang="en" dir="ltr" placeholder="مثال: 30">
        </div>
        <?php endif; ?>
        <?php if ($hasSupplierTaxNumberCol): ?>
        <div>
            <label for="sup_tax_number">الرقم الضريبي (اختياري)</label>
            <input type="text" id="sup_tax_number" maxlength="64" autocomplete="off" dir="ltr" lang="en" placeholder="اختياري">
        </div>
        <?php endif; ?>
        <div style="grid-column:1/-1;">
            <label for="sup_notes">ملاحظات</label>
            <input type="text" id="sup_notes" autocomplete="off" placeholder="اختياري">
        </div>
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
            <p class="card-hint" style="margin:6px 0 0;">أنشئ حساباً فرعياً تحت جذر الخصوم (مثلاً باسم المورد) من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=chart_of_accounts'), ENT_QUOTES, 'UTF-8'); ?>">دليل الحسابات</a> ثم اختره هنا.</p>
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
                        $pAcc = $hasSupplierPayableCol ? (int) ($s['payable_account_id'] ?? 0) : 0;
                        $pAccLabel = $pAcc > 0 ? $payableAccountLabel($pAcc) : '';
                        $hayRaw = trim((string) ($s['code'] ?? '') . ' ' . ($s['name'] ?? '') . ' ' . $phone . ' ' . ($s['notes'] ?? '') . ' ' . $pAccLabel . ' ' . $statusDisp . ' ' . $currencyCode . ' ' . $taxProfileCode);
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
function supResetForm() {
    document.getElementById('sup_id').value = '0';
    document.getElementById('sup_code').value = '';
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
        phone_country: phoneCountry !== '' ? phoneCountry : null,
        code: (document.getElementById('sup_code') && document.getElementById('sup_code').value.trim()) || null
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
            alert(r.message || (r.success ? 'تم' : 'فشل'));
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

var SUP_CHECKLIST_ITEMS = [
    { id: 'supplier_code_unique', group: 'أولاً: بنود إلزامية', label: 'كود المورد (supplier_code) فريد', help: 'يفضل أن يكون توليد/تنسيق واضح وعدم السماح بالتكرار.', priority: 'required' },
    { id: 'supplier_name_ar', group: 'أولاً: بنود إلزامية', label: 'اسم المورد العربي (name_ar)', help: 'الاسم الإجباري الأساسي للمورد.', priority: 'required' },
    { id: 'supplier_is_active', group: 'أولاً: بنود إلزامية', label: 'حالة المورد (is_active)', help: 'نشط/موقوف بدل الحذف المباشر.', priority: 'required' },
    { id: 'supplier_phone_country_phone', group: 'أولاً: بنود إلزامية', label: 'كود دولة + هاتف (phone_country + phone)', help: 'اتصال موحد وفق سياسة الهاتف.', priority: 'required' },
    { id: 'supplier_currency', group: 'أولاً: بنود إلزامية', label: 'العملة الافتراضية للمورد (currency_id)', help: 'تحدد عملة الشراء الافتراضية.', priority: 'required' },
    { id: 'supplier_payment_mode', group: 'أولاً: بنود إلزامية', label: 'طريقة السداد الافتراضية (payment_mode)', help: 'نقدي/آجل/تحويل.', priority: 'required' },
    { id: 'supplier_terms_days', group: 'ثانياً: بنود مهمة جداً', label: 'شروط السداد بالأيام (payment_terms_days)', help: 'تنبيه تشغيلي عند الآجل (اختياري وليس شرط حفظ).', priority: 'important' },
    { id: 'supplier_ap_account', group: 'أولاً: بنود إلزامية', label: 'حساب ذمة المورد (ap_account_id/payable_account_id)', help: 'حساب ترحيل إلزامي للمورد في الدليل.', priority: 'required' },
    { id: 'supplier_tax_profile', group: 'أولاً: بنود إلزامية', label: 'المعاملة الضريبية (tax_profile)', help: 'خاضع/معفى/صفر + إعداد افتراضي.', priority: 'required' },
    { id: 'supplier_contact_person', group: 'ثانياً: بنود مهمة جداً', label: 'اسم مسؤول التواصل (contact_person)', help: 'اسم شخص التواصل الرئيسي لدى المورد.', priority: 'important' },
    { id: 'supplier_email', group: 'ثانياً: بنود مهمة جداً', label: 'البريد الإلكتروني (email)', help: 'للتواصل وإرسال الوثائق.', priority: 'important' },
    { id: 'supplier_tax_number', group: 'ثانياً: بنود مهمة جداً', label: 'الرقم الضريبي (tax_number)', help: 'مهم للتقارير/الفواتير الضريبية.', priority: 'important' },
    { id: 'supplier_commercial_reg', group: 'ثانياً: بنود مهمة جداً', label: 'السجل التجاري (commercial_reg)', help: 'للشركات والموردين الرسميين.', priority: 'important' },
    { id: 'supplier_address', group: 'ثانياً: بنود مهمة جداً', label: 'العنوان (address_line + city/area)', help: 'عنوان إداري واضح للمورد.', priority: 'important' },
    { id: 'supplier_opening_balance', group: 'ثانياً: بنود مهمة جداً', label: 'الرصيد الافتتاحي (opening_balance)', help: 'مهم عند ترحيل بيانات قديمة.', priority: 'important' },
    { id: 'supplier_bank_info', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'بيانات البنك (IBAN/Bank/Account Holder)', help: 'مفيدة للتحويل البنكي.', priority: 'operational' },
    { id: 'supplier_credit_limit', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'الحد الائتماني (credit_limit)', help: 'للتحكم في الدين على المورد.', priority: 'operational' },
    { id: 'supplier_default_warehouse', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'المخزن الافتراضي للاستلام (preferred_warehouse_id)', help: 'يسهل مسار إدخال المشتريات.', priority: 'operational' },
    { id: 'supplier_internal_notes', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'ملاحظات داخلية (notes_internal)', help: 'تعليمات تشغيلية للفريق.', priority: 'operational' },
    { id: 'supplier_blocking', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'حظر التعامل + السبب (is_blocked/block_reason)', help: 'عند إيقاف التعامل مؤقتاً.', priority: 'operational' },
    { id: 'supplier_attachments', group: 'ثالثاً: بنود تشغيلية اختيارية', label: 'مرفقات المورد', help: 'مثل عقد/شهادة ضريبية/مستندات.', priority: 'operational' },
    { id: 'rule_duplicate_protection', group: 'رابعاً: قواعد تحقق لازمة', label: 'منع التكرار (الكود/الرقم الضريبي)', help: 'التحقق عند الحفظ يمنع الإدخال المكرر.', priority: 'required' },
    { id: 'rule_no_delete_with_docs', group: 'رابعاً: قواعد تحقق لازمة', label: 'عدم حذف مورد عليه مستندات', help: 'تعطيل بدل حذف إذا له مشتريات/حركات.', priority: 'required' },
    { id: 'rule_terms_with_credit', group: 'رابعاً: قواعد تحقق لازمة', label: 'عند آجل: حساب ذمة إلزامي (أيام السداد تنبيه اختياري)', help: 'منع حفظ آجل بدون حساب ذمة، مع إبقاء أيام السداد كتنبيه.', priority: 'required' },
    { id: 'rule_active_supplier_on_purchase', group: 'رابعاً: قواعد تحقق لازمة', label: 'فواتير الشراء لمورد نشط فقط', help: 'supplier_id صالح ونشط أثناء الحفظ.', priority: 'required' }
];

function supChecklistStorageKey() {
    return 'orange_suppliers_readiness_checklist_v1';
}
function supChecklistStatusLabel(v) {
    if (v === 'ok') return 'موجود';
    if (v === 'missing') return 'ناقص';
    if (v === 'fix') return 'يحتاج تعديل';
    return 'غير مراجع';
}
function supChecklistEsc(s) {
    return String(s || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}
function supChecklistLoad() {
    try {
        var raw = localStorage.getItem(supChecklistStorageKey());
        if (!raw) {
            return {};
        }
        var parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (e) {
        return {};
    }
}
function supChecklistSave(state) {
    try {
        localStorage.setItem(supChecklistStorageKey(), JSON.stringify(state || {}));
    } catch (e) {}
}
function supChecklistAutoStatus(itemId) {
    var has = function (id) { return !!document.getElementById(id); };
    if (itemId === 'supplier_code_unique') return has('sup_code') ? 'ok' : 'missing';
    if (itemId === 'supplier_name_ar') return has('sup_name') ? 'ok' : 'missing';
    if (itemId === 'supplier_is_active') return has('sup_is_active') ? 'ok' : 'missing';
    if (itemId === 'supplier_phone_country_phone') {
        if (!has('sup_phone')) return 'missing';
        return has('sup_phone_country') ? 'ok' : 'fix';
    }
    if (itemId === 'supplier_currency') return has('sup_currency_code') ? 'ok' : 'missing';
    if (itemId === 'supplier_payment_mode') return has('sup_payment_mode') ? 'ok' : 'missing';
    if (itemId === 'supplier_terms_days') return has('sup_payment_terms_days') ? 'ok' : 'missing';
    if (itemId === 'supplier_ap_account') return has('sup_payable_account_id') ? 'ok' : 'missing';
    if (itemId === 'supplier_tax_profile') return has('sup_tax_profile') ? 'ok' : 'missing';
    if (itemId === 'supplier_contact_person') return 'missing';
    if (itemId === 'supplier_email') return 'missing';
    if (itemId === 'supplier_tax_number') return has('sup_tax_number') ? 'ok' : 'missing';
    if (itemId === 'supplier_commercial_reg') return 'missing';
    if (itemId === 'supplier_address') return 'missing';
    if (itemId === 'supplier_opening_balance') return 'missing';
    if (itemId === 'supplier_bank_info') return 'missing';
    if (itemId === 'supplier_credit_limit') return 'missing';
    if (itemId === 'supplier_default_warehouse') return 'missing';
    if (itemId === 'supplier_internal_notes') return has('sup_notes') ? 'ok' : 'missing';
    if (itemId === 'supplier_blocking') return 'missing';
    if (itemId === 'supplier_attachments') return 'missing';
    if (itemId === 'rule_duplicate_protection') return has('sup_code') && has('sup_tax_number') ? 'ok' : 'fix';
    if (itemId === 'rule_no_delete_with_docs') return 'todo';
    if (itemId === 'rule_terms_with_credit') return has('sup_payable_account_id') ? 'ok' : 'missing';
    if (itemId === 'rule_active_supplier_on_purchase') return has('sup_is_active') ? 'ok' : 'missing';
    return 'todo';
}
function supChecklistRender() {
    var body = document.getElementById('supChecklistBody');
    if (!body) {
        return;
    }
    var state = supChecklistLoad();
    var html = '';
    var group = '';
    var idx = 0;
    SUP_CHECKLIST_ITEMS.forEach(function (item) {
        if (item.group !== group) {
            group = item.group;
            html += '<tr class="sup-checklist-group-row"><td colspan="6">' + supChecklistEsc(group) + '</td></tr>';
        }
        idx += 1;
        var row = state[item.id] || {};
        var st = row.status || 'todo';
        var note = row.note || '';
        var auto = supChecklistAutoStatus(item.id);
        html += '' +
            '<tr data-sup-check-id="' + supChecklistEsc(item.id) + '">' +
                '<td>' + idx + '</td>' +
                '<td><strong>' + supChecklistEsc(item.label) + '</strong><div class="card-hint" style="margin:4px 0 0;">' + supChecklistEsc(item.help) + '</div></td>' +
                '<td><span class="sup-checklist-priority sup-checklist-priority--' + supChecklistEsc(item.priority) + '">' + (item.priority === 'required' ? 'إلزامي' : (item.priority === 'important' ? 'مهم' : 'تشغيلي')) + '</span></td>' +
                '<td>' +
                    '<select class="sup-checklist-status" data-sup-check-field="status">' +
                        '<option value="todo"' + (st === 'todo' ? ' selected' : '') + '>غير مراجع</option>' +
                        '<option value="ok"' + (st === 'ok' ? ' selected' : '') + '>موجود</option>' +
                        '<option value="missing"' + (st === 'missing' ? ' selected' : '') + '>ناقص</option>' +
                        '<option value="fix"' + (st === 'fix' ? ' selected' : '') + '>يحتاج تعديل</option>' +
                    '</select>' +
                '</td>' +
                '<td><input type="text" class="sup-checklist-note" data-sup-check-field="note" value="' + supChecklistEsc(note) + '" placeholder="تعليق سريع (اختياري)"></td>' +
                '<td><span class="sup-checklist-auto sup-checklist-auto--' + auto + '">' + supChecklistStatusLabel(auto) + '</span></td>' +
            '</tr>';
    });
    body.innerHTML = html;
    body.querySelectorAll('tr[data-sup-check-id]').forEach(function (tr) {
        var id = tr.getAttribute('data-sup-check-id') || '';
        var stEl = tr.querySelector('select[data-sup-check-field="status"]');
        var noteEl = tr.querySelector('input[data-sup-check-field="note"]');
        var onSync = function () {
            var s = supChecklistLoad();
            s[id] = {
                status: stEl ? String(stEl.value || 'todo') : 'todo',
                note: noteEl ? String(noteEl.value || '').trim() : ''
            };
            supChecklistSave(s);
            supChecklistUpdateSummary();
        };
        if (stEl) stEl.addEventListener('change', onSync);
        if (noteEl) noteEl.addEventListener('input', onSync);
    });
    supChecklistUpdateSummary();
}
function supChecklistUpdateSummary() {
    var s = supChecklistLoad();
    var cOk = 0, cMissing = 0, cFix = 0, cTodo = 0, reqTotal = 0, reqDone = 0;
    SUP_CHECKLIST_ITEMS.forEach(function (item) {
        var st = (s[item.id] && s[item.id].status) ? String(s[item.id].status) : 'todo';
        if (st === 'ok') cOk += 1;
        else if (st === 'missing') cMissing += 1;
        else if (st === 'fix') cFix += 1;
        else cTodo += 1;
        if (item.priority === 'required') {
            reqTotal += 1;
            if (st === 'ok') {
                reqDone += 1;
            }
        }
    });
    var pct = reqTotal > 0 ? Math.round((reqDone / reqTotal) * 100) : 0;
    var setTxt = function (id, txt) {
        var el = document.getElementById(id);
        if (el) el.textContent = txt;
    };
    setTxt('supChecklistReqDone', reqDone + '/' + reqTotal);
    setTxt('supChecklistReqPct', pct + '%');
    setTxt('supChecklistCountOk', String(cOk));
    setTxt('supChecklistCountMissing', String(cMissing));
    setTxt('supChecklistCountFix', String(cFix));
    setTxt('supChecklistCountTodo', String(cTodo));
}
function supChecklistApplyAuto() {
    var s = supChecklistLoad();
    SUP_CHECKLIST_ITEMS.forEach(function (item) {
        var row = s[item.id] || {};
        var cur = String(row.status || 'todo');
        if (cur === 'todo') {
            row.status = supChecklistAutoStatus(item.id);
            s[item.id] = row;
        }
    });
    supChecklistSave(s);
    supChecklistRender();
}
function supChecklistCopySummary() {
    var s = supChecklistLoad();
    var lines = ['مراجعة شاشة الموردين:'];
    SUP_CHECKLIST_ITEMS.forEach(function (item) {
        var row = s[item.id] || {};
        var st = String(row.status || 'todo');
        var note = String(row.note || '').trim();
        lines.push('- [' + supChecklistStatusLabel(st) + '] ' + item.label + (note ? ' — ' + note : ''));
    });
    var txt = lines.join('\n');
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(function () {
            alert('تم نسخ ملخص المراجعة.');
        }).catch(function () {
            alert('تعذر النسخ التلقائي. انسخ يدوياً من وحدة التحكم.');
        });
        return;
    }
    alert('المتصفح لا يدعم النسخ التلقائي.');
}
function supChecklistReset() {
    if (!confirm('مسح كل تقييمات مراجعة الموردين المحفوظة محلياً؟')) {
        return;
    }
    try {
        localStorage.removeItem(supChecklistStorageKey());
    } catch (e) {}
    supChecklistRender();
}
(function initSupChecklistCard() {
    var body = document.getElementById('supChecklistBody');
    if (!body) {
        return;
    }
    var btnAuto = document.getElementById('supChecklistApplyAutoBtn');
    var btnCopy = document.getElementById('supChecklistCopyBtn');
    var btnReset = document.getElementById('supChecklistResetBtn');
    if (btnAuto) btnAuto.addEventListener('click', supChecklistApplyAuto);
    if (btnCopy) btnCopy.addEventListener('click', supChecklistCopySummary);
    if (btnReset) btnReset.addEventListener('click', supChecklistReset);
    supChecklistRender();
})();
</script>
