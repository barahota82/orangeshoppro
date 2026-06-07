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
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';
$companyNameAr = orange_company_settings_name_ar($pdo);
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$prCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$prLogo = (string) ($prCompany['logo_url'] ?? '');
$prPrintDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$includeAging = isset($_GET['aging']) && $_GET['aging'] === '1';
$partnerViewRaw = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
$partnerView = in_array($partnerViewRaw, ['customers', 'suppliers'], true) ? $partnerViewRaw : 'all';
$showPartnerCustomers = $partnerView === 'all' || $partnerView === 'customers';
$showPartnerSuppliers = $partnerView === 'all' || $partnerView === 'suppliers';
$partnerReportsUrl = static function (array $extra = []) use ($partnerView, $includeAging): string {
    $q = ['page' => 'partner_reports'];
    if ($partnerView !== 'all') {
        $q['view'] = $partnerView;
    }
    if ($includeAging) {
        $q['aging'] = '1';
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
    <div class="gl-acc-stmt-print-footer ta-report-print-footer">
        <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($prPrintDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
    </div>
    <?php
};
?>
<div class="admin-fy-shell" dir="rtl">
    <?php if ($partnerView === 'customers'): ?>
        <h1 class="admin-fy-shell__title">أرصدة العملاء (ذمم)</h1>
    <?php elseif ($partnerView === 'suppliers'): ?>
        <h1 class="admin-fy-shell__title">أرصدة الموردين (ذمم)</h1>
    <?php else: ?>
        <h1 class="admin-fy-shell__title">تقارير الذمم الشاملة</h1>
        <p class="admin-fy-shell__lead">
            ملخص أرصدة كل العملاء والموردين، ومطابقة أرصدة الدليل مع دفتر الذمم.
        </p>
    <?php endif; ?>

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
            <?php if ($showPartnerCustomers): ?>
            <button type="button" class="btn-secondary" onclick="backfillOrders()">ربط طلبات آجل بعملاء (هاتف)</button>
            <?php endif; ?>
        </div>
    </div>
    <p class="card-hint muted" style="margin-top:10px;">اعتباراً من <?php echo htmlspecialchars($report['as_of'], ENT_QUOTES, 'UTF-8'); ?></p>

    <?php if ($reconcile !== null): ?>
    <form method="get" class="form-grid orange-doc-header-row" style="max-width:420px;margin-top:14px;">
        <input type="hidden" name="page" value="partner_reports">
        <?php if ($partnerView !== 'all'): ?>
            <input type="hidden" name="view" value="<?php echo htmlspecialchars($partnerView, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
        <?php if ($includeAging): ?><input type="hidden" name="aging" value="1"><?php endif; ?>
        <div>
            <label for="fy_sel">السنة المالية</label>
            <select name="fy" id="fy_sel" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int) $y['id']; ?>"<?php echo (int) $y['id'] === $fyId ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) ($y['label_ar'] ?? $y['id']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
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
    <?php else: ?>
    <p class="muted" style="margin-top:14px;">لا توجد سنة مالية أو السندات غير مفعّلة — عرّف سنة من «السنوات المالية».</p>
    <?php endif; ?>
</div>

<div class="gl-acc-stmt-print">
<?php if ($showPartnerCustomers): ?>
<div class="card admin-fy-card" id="partner-balances-customers">
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
<div class="card admin-fy-card" id="partner-balances-suppliers"<?php echo $partnerView === 'all' ? ' style="break-before:page;"' : ''; ?>>
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
<?php /* gl-acc-stmt-print wrapper end */ ?>

</div>

<script>
function backfillOrders() {
    if (!confirm('ربط الطلبات الآجلة التي بها هاتف وبدون customer_id بجدول العملاء؟')) return;
    postJSON('/admin/api/customers/backfill-orders.php', {}).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
</script>
