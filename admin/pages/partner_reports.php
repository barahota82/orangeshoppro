<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/party_allocations.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$prCountryLabel = orange_admin_page_country_label($pdo);
$prReportTimeIana = '';
try {
    $prReportDefaults = orange_admin_time_report_default_from_to_for_admin_context($pdo);
    $prReportTimeIana = (string) $prReportDefaults['iana'];
} catch (Throwable $e) {
    $prReportTimeIana = '';
}
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';
$companyNameAr = orange_company_settings_name_ar($pdo);
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$prCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$prLogo = (string) ($prCompany['logo_url'] ?? '');
$prPrintDatetime = $prReportTimeIana !== ''
    ? orange_admin_time_now_display_for_admin_context($pdo, 'ar', 'datetime')
    : orange_format_datetime_dmY_hi(gmdate('Y-m-d H:i:s'));

$includeAging = isset($_GET['aging']) && $_GET['aging'] === '1';
$prHideZero = isset($_GET['hide_zero']) && $_GET['hide_zero'] === '1';
$prAbnormalOnly = isset($_GET['abnormal_only']) && $_GET['abnormal_only'] === '1';
$partnerViewRaw = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
if (!in_array($partnerViewRaw, ['customers', 'suppliers'], true)) {
    $redirectQ = $_GET;
    $redirectQ['page'] = 'partner_reports';
    $redirectQ['view'] = 'customers';
    header('Location: ' . storefront_public_path('/admin/index.php?' . http_build_query($redirectQ)));
    exit;
}
$partnerView = $partnerViewRaw;
$showPartnerCustomers = $partnerView === 'customers';
$showPartnerSuppliers = $partnerView === 'suppliers';
$partnerReportsUrl = static function (array $extra = []) use ($partnerView, $includeAging, $prHideZero, $prAbnormalOnly): string {
    $q = ['page' => 'partner_reports', 'view' => $partnerView];
    if ($includeAging) {
        $q['aging'] = '1';
    }
    if ($prHideZero) {
        $q['hide_zero'] = '1';
    }
    if ($prAbnormalOnly) {
        $q['abnormal_only'] = '1';
    }
    foreach ($extra as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return storefront_public_path('/admin/index.php?' . http_build_query($q));
};
$report = orange_partner_summary_report($pdo, $includeAging);

$prBalanceIsZero = static function (float $balance): bool {
    return abs($balance) <= 0.0001;
};
$prCustomerRowVisible = static function (array $row) use ($prHideZero, $prAbnormalOnly, $prBalanceIsZero): bool {
    $balance = (float) ($row['balance'] ?? 0);
    if ($prHideZero && $prBalanceIsZero($balance)) {
        return false;
    }
    if ($prAbnormalOnly && $balance >= -0.0001) {
        return false;
    }

    return true;
};
$prSupplierRowVisible = static function (array $row) use ($prHideZero, $prAbnormalOnly, $prBalanceIsZero): bool {
    $balance = (float) ($row['balance'] ?? 0);
    if ($prHideZero && $prBalanceIsZero($balance)) {
        return false;
    }
    if ($prAbnormalOnly && $balance >= -0.0001) {
        return false;
    }

    return true;
};

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}
$reconcile = orange_partner_gl_reconcile($pdo, $fyId);

$prLeafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$prPostingLeafCt = 0;
try {
    $prPostingLeafCt = (int) $pdo->query("SELECT COUNT(*) FROM accounts a WHERE $prLeafWhere")->fetchColumn();
} catch (Throwable $e) {
    $prPostingLeafCt = 0;
}

/* رأس موحّد (شعار + شركة + سجل + عنوان + أرقام) مثل باقي التقارير. */
$prRenderHeader = static function (string $title) use ($companyNameAr, $prCompany, $prLogo, $report): void {
    ?>
    <header class="gl-acc-stmt-print-banner">
        <div class="pl-month-brand-row">
            <div class="pl-month-brand">
                <?php if ($prLogo !== ''): ?>
                    <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($prLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                <?php endif; ?>
                <div class="pl-month-brand-text">
                    <?php if ($companyNameAr !== ''): ?>
                        <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if (trim((string) ($prCompany['commercial_register'] ?? '')) !== ''): ?>
                        <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $prCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pl-month-contact">
                <?php if (trim((string) ($prCompany['address'] ?? '')) !== ''): ?>
                    <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $prCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if (trim((string) ($prCompany['phones'] ?? '')) !== ''): ?>
                    <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $prCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                <?php endif; ?>
            </div>
        </div>
        <h2 class="gl-acc-stmt-print-title ta-report-print-title"><span class="gl-acc-stmt-print-title-ar" lang="ar"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></span></h2>
        <p class="muted" style="margin:8px 0 0;">اعتباراً من <?php echo htmlspecialchars((string) $report['as_of'], ENT_QUOTES, 'UTF-8'); ?></p>
    </header>
    <?php
};
$prRenderFooter = static function () use ($prPrintDatetime): void {
    ?>
    <?php echo orange_accounting_report_print_metafoot_markup($prPrintDatetime); ?>
    <?php
};
?>
<div class="admin-fy-shell" dir="rtl">
    <div class="page-title">
        <?php if ($partnerView === 'customers'): ?>
        <h1>أرصدة العملاء (ذمم)</h1>
        <?php else: ?>
        <h1>أرصدة الموردين (ذمم)</h1>
        <?php endif; ?>
        <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($prCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
    </div>

<?php if (orange_journal_vouchers_ready($pdo) && $prPostingLeafCt === 0): ?>
<div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
    <p class="card-hint" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ مطابقة الدليل مع دفتر الذمم وسطور التقرير تعتمد على دليل GL مكتملاً. أكمل الدليل من «الدليل المحاسبي» واربط حسابات الذمم (عملاء آجل، موردون، ...) قبل الاعتماد على الأرقام.</p>
</div>
<?php endif; ?>

<div class="card admin-fy-card gl-acc-stmt-no-print">
    <div class="pr-reconcile-toolbar-head" style="display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
        <h3 class="card-title" style="margin:0;">خيارات العرض ومطابقة الدليل مع دفتر الذمم</h3>
        <div class="actions gas-acc-stmt-actions" data-export-host style="display:flex;flex-wrap:wrap;gap:8px;margin-inline-start:auto;">
            <a class="btn-secondary" href="<?php echo htmlspecialchars($partnerReportsUrl(['aging' => $includeAging ? null : '1']), ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo $includeAging ? 'إخفاء أعمار الذمم (أسرع)' : 'إظهار أعمار الذمم (أبطأ)'; ?>
            </a>
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
        </div>
    </div>
    <p class="card-hint muted" style="margin-top:10px;">اعتباراً من <?php echo htmlspecialchars($report['as_of'], ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($years !== []): ?>
    <form method="get" class="orange-doc-header-row" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px;margin-top:14px;">
        <input type="hidden" name="page" value="partner_reports">
        <input type="hidden" name="view" value="<?php echo htmlspecialchars($partnerView, ENT_QUOTES, 'UTF-8'); ?>">
        <?php if ($includeAging): ?><input type="hidden" name="aging" value="1"><?php endif; ?>
        <div>
            <label for="fy_sel">السنة المالية</label>
            <select name="fy" id="fy_sel" class="admin-inp" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int) $y['id']; ?>"<?php echo (int) $y['id'] === $fyId ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) ($y['label_ar'] ?? $y['id']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;margin:0;">
            <input type="checkbox" name="hide_zero" value="1"<?php echo $prHideZero ? ' checked' : ''; ?> onchange="this.form.submit()">
            إخفاء الأرصدة الصفرية
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;white-space:nowrap;margin:0;">
            <input type="checkbox" name="abnormal_only" value="1"<?php echo $prAbnormalOnly ? ' checked' : ''; ?> onchange="this.form.submit()">
            إظهار الأرصدة غير الطبيعية فقط
        </label>
    </form>
    <?php endif; ?>
    <?php if ($reconcile !== null): ?>
    <div class="table-wrap admin-fy-table-wrap" style="margin-top:12px;">
        <table class="admin-fy-table">
            <thead>
                <tr><th>البند</th><th>دفتر الأستاذ (سنة مالية)</th><th>دفتر الذمم</th><th>الفرق</th></tr>
            </thead>
            <tbody>
                <?php if ($showPartnerCustomers): ?>
                <tr>
                    <td>عملاء آجل (مدين − دائن)</td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['gl']['ar_net_dr_minus_cr'], $reportMoney); ?></td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['subledger']['customers_dr_minus_cr'], $reportMoney); ?></td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['variance']['ar'], $reportMoney); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($showPartnerSuppliers): ?>
                <tr>
                    <td>موردين (دائن − مدين)</td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['gl']['ap_net_cr_minus_dr'], $reportMoney); ?></td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['subledger']['suppliers_cr_minus_dr'], $reportMoney); ?></td>
                    <td><?php echo orange_accounting_report_format_amount((float) $reconcile['variance']['ap'], $reportMoney); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($years === []): ?>
    <p class="muted" style="margin-top:14px;">لا توجد سنة مالية أو السندات غير مفعّلة — عرّف سنة من «السنوات المالية».</p>
    <?php endif; ?>
</div>

<?php if ($showPartnerCustomers): ?>
<div class="card admin-fy-card gl-acc-stmt-print" id="partner-balances-customers">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
    <?php $prRenderHeader('أرصدة العملاء (ذمم)'); ?>
    <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
        <table class="admin-fy-table gl-acc-stmt-table" data-export-name="أرصدة العملاء" data-export-group="partner_balances" data-export-label="أرصدة العملاء" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars('اعتباراً من ' . $report['as_of'], ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
                <tr>
                    <th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد</th><th>حد ائتمان</th><th>تجاوز</th>
                    <?php if ($includeAging): ?><th>أكثر من 90 يوم</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['customers'] as $c): ?>
                    <?php if (!$prCustomerRowVisible($c)) { continue; } ?>
                    <tr>
                        <td><?php echo (int) $c['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $c['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo orange_accounting_report_format_amount((float) $c['balance'], $reportMoney); ?></td>
                        <td><?php echo $c['credit_limit'] !== null ? orange_accounting_report_format_amount((float) $c['credit_limit'], $reportMoney) : '—'; ?></td>
                        <td><?php echo !empty($c['over_limit']) ? 'نعم' : ''; ?></td>
                        <?php if ($includeAging && isset($c['aging']['buckets'])): ?>
                            <td><?php echo orange_accounting_report_format_amount((float) ($c['aging']['buckets']['days_91_plus'] ?? 0), $reportMoney); ?></td>
                        <?php elseif ($includeAging): ?>
                            <td>—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php $prRenderFooter(); ?>
    </div>
</div>
<?php endif; ?>

<?php if ($showPartnerSuppliers): ?>
<div class="card admin-fy-card gl-acc-stmt-print" id="partner-balances-suppliers">
    <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
    <?php $prRenderHeader('أرصدة الموردين (ذمم)'); ?>
    <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
        <table class="admin-fy-table gl-acc-stmt-table" data-export-name="أرصدة الموردين" data-export-group="partner_balances" data-export-label="أرصدة الموردين" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars('اعتباراً من ' . $report['as_of'], ENT_QUOTES, 'UTF-8'); ?>">
            <thead>
                <tr>
                    <th>#</th><th>الاسم</th><th>الهاتف</th><th>الذمة</th>
                    <?php if ($includeAging): ?><th>أكثر من 90 يوم</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['suppliers'] as $s): ?>
                    <?php if (!$prSupplierRowVisible($s)) { continue; } ?>
                    <tr>
                        <td><?php echo (int) $s['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo orange_accounting_report_format_amount((float) $s['balance'], $reportMoney); ?></td>
                        <?php if ($includeAging && isset($s['aging']['buckets'])): ?>
                            <td><?php echo orange_accounting_report_format_amount((float) ($s['aging']['buckets']['days_91_plus'] ?? 0), $reportMoney); ?></td>
                        <?php elseif ($includeAging): ?>
                            <td>—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php $prRenderFooter(); ?>
    </div>
</div>
<?php endif; ?>

</div>
