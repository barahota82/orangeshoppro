<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/cash_flow_report.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) ($years[0]['id'] ?? 0);
}

$methodRaw = isset($_GET['method']) ? strtolower(trim((string) $_GET['method'])) : 'indirect';
$method = $methodRaw === 'direct' ? 'direct' : 'indirect';

$fyRow = null;
foreach ($years as $y) {
    if ((int) ($y['id'] ?? 0) === $fyId) {
        $fyRow = $y;
        break;
    }
}

$useVouchers = orange_journal_vouchers_ready($pdo);
$postingLeafCt = orange_accounts_count_posting_leaves($pdo);
$submitted = isset($_GET['run']) && (string) $_GET['run'] === '1';

$report = null;
if ($useVouchers && $fyId > 0 && $submitted) {
    $report = orange_cash_flow_build_report($pdo, $fyId, $method, $fyRow);
}

$companyNameAr = orange_company_settings_name_ar($pdo);
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$cfCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$cfLogo = (string) ($cfCompany['logo_url'] ?? '');
$fmt = static function (float $amt) use ($reportMoney): string {
    return orange_accounting_report_format_amount($amt, $reportMoney);
};

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة التدفقات النقدية</h1>

        <form method="get" class="card admin-fy-card gas-acc-stmt-search-card" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="report_cash_flow">
            <input type="hidden" name="run" value="1">
            <div class="admin-fy-form-grid" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;">
                <div>
                    <label for="cf_fy">السنة المالية</label>
                    <select id="cf_fy" name="fy" required>
                        <?php if ($years === []): ?>
                            <option value="">— لا توجد سنوات —</option>
                        <?php else: ?>
                            <?php foreach ($years as $yr): ?>
                                <?php $id = (int) ($yr['id'] ?? 0); ?>
                                <option value="<?php echo $id; ?>"<?php echo $id === $fyId ? ' selected' : ''; ?>>
                                    <?php echo htmlspecialchars(trim((string) ($yr['label_ar'] ?? '')) !== '' ? (string) $yr['label_ar'] : ('#' . $id), ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <span class="coa-field__label">طريقة العرض</span>
                    <div class="coa-radio-row">
                        <label class="coa-radio">
                            <input type="radio" name="method" value="indirect"<?php echo $method === 'indirect' ? ' checked' : ''; ?>> غير مباشرة
                        </label>
                        <label class="coa-radio">
                            <input type="radio" name="method" value="direct"<?php echo $method === 'direct' ? ' checked' : ''; ?>> مباشرة
                        </label>
                    </div>
                </div>
                <div class="gas-acc-stmt-actions" data-export-host style="margin-inline-start:auto;align-self:flex-end;">
                    <button type="submit">عرض</button>
                    <button type="button" class="btn-secondary" onclick="<?php echo ($report !== null) ? 'window.print()' : "alert('اعرض التقرير أولاً ثم اضغط طباعة')"; ?>">طباعة</button>
                </div>
            </div>
        </form>
    </div>

    <?php if (! $useVouchers): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">جدول السندات غير جاهز بعد — أكمل إعداد المحاسبة أولاً.</p>
        </div>
    <?php elseif ($postingLeafCt === 0): ?>
        <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
            <p style="margin:0;">لا توجد حسابات ترحيل في الدليل — صنّف الحسابات (بما فيها <code>cashflow_section</code> وحسابات النقد) ثم أعد العرض.</p>
        </div>
    <?php else: ?>
        <?php
        /* هيكل التقرير (إطار + ترويسة + جدول) يظهر دائماً؛ البيانات تُملأ بعد «عرض». */
        $cfFyLabel = $report !== null ? (string) $report['fy_label'] : trim((string) ($fyRow['label_ar'] ?? ''));
        $cfPeriod = $report !== null ? (string) $report['period'] : '';
        ?>
        <div class="card admin-fy-card gl-acc-stmt-print">
            <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
            <header class="gl-acc-stmt-print-banner ral-print-banner">
                <div class="pl-month-brand-row">
                    <div class="pl-month-brand">
                        <?php if ($cfLogo !== ''): ?>
                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($cfLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php endif; ?>
                        <div class="pl-month-brand-text">
                            <?php if ($companyNameAr !== ''): ?>
                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($cfCompany['commercial_register'] ?? '')) !== ''): ?>
                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $cfCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pl-month-contact">
                        <?php if (trim((string) ($cfCompany['address'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $cfCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($cfCompany['phones'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $cfCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title"><span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة التدفقات النقدية</span></h2>
                <p class="muted" style="margin:8px 0 0;">
                    <?php if ($cfFyLabel !== ''): ?><?php echo htmlspecialchars($cfFyLabel, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    <?php if ($cfPeriod !== ''): ?> — <?php echo htmlspecialchars($cfPeriod, ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    — <?php echo $method === 'direct' ? 'الطريقة المباشرة' : 'الطريقة غير المباشرة'; ?>
                </p>
            </header>

            <?php if ($report !== null && ($report['cash_account_ids'] ?? []) === []): ?>
                <div class="card-hint" style="margin:12px 0;border:1px solid #fcd34d;background:#fffbeb;padding:10px;border-radius:6px;">
                    <strong>تنبيه:</strong> لم يُعرَف حساب نقد (ربط «cash» في حسابات القيود التلقائية أو سطر «النقد وما في حكمه»). أرقام النقد أول/آخر المدة قد تكون صفراً.
                </div>
            <?php endif; ?>

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
                <table class="admin-fy-table gl-acc-stmt-table cf-report-table" dir="rtl" data-export-name="قائمة التدفق النقدي" data-export-target=".gas-acc-stmt-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                    <thead>
                        <tr>
                            <th>البند</th>
                            <th class="cf-col-amount">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($report === null): ?>
                            <tr class="gl-acc-stmt-no-print">
                                <td colspan="2" class="muted"><?php echo $submitted ? 'تعذّر بناء التقرير للسنة المحددة.' : 'اختر السنة المالية والطريقة ثم «عرض» لعرض التدفقات النقدية.'; ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($report['rows'] as $row): ?>
                                <?php
                                $kind = (string) ($row['kind'] ?? 'line');
                                $indent = (int) ($row['indent'] ?? 0);
                                $bold = ! empty($row['bold']);
                                $muted = ! empty($row['muted']);
                                $cls = 'cf-row-' . $kind;
                                if ($bold) {
                                    $cls .= ' cf-row-bold';
                                }
                                if ($muted) {
                                    $cls .= ' muted';
                                }
                                $pad = $indent > 0 ? 'padding-right:' . (12 + $indent * 16) . 'px;' : '';
                                ?>
                                <tr class="<?php echo htmlspecialchars($cls, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td style="<?php echo $pad; ?>"><?php echo htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="cf-col-amount" dir="ltr" lang="en">
                                        <?php if ($row['amount'] !== null): ?>
                                            <?php echo htmlspecialchars($fmt((float) $row['amount']), ENT_QUOTES, 'UTF-8'); ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php
            if ($report !== null):
                $diff = round((float) $report['net_change_computed'] - (float) $report['net_change_actual'], 4);
                if (abs($diff) > 0.02):
                    ?>
                    <p class="card-hint muted" style="margin-top:12px;">
                        فرق مطابقة: <?php echo htmlspecialchars($fmt($diff), ENT_QUOTES, 'UTF-8'); ?>
                        (محسوب <?php echo htmlspecialchars($fmt((float) $report['net_change_computed']), ENT_QUOTES, 'UTF-8'); ?>
                        مقابل تغيّر نقد فعلي <?php echo htmlspecialchars($fmt((float) $report['net_change_actual']), ENT_QUOTES, 'UTF-8'); ?>).
                        راجع تصنيف <code>cashflow_section</code> وحسابات النقد.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
            </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<style>
.cf-report-table .cf-col-amount { text-align: left; white-space: nowrap; width: 140px; }
.cf-report-table .cf-row-section td { font-weight: 700; padding-top: 14px; }
.cf-report-table .cf-row-subtotal td,
.cf-report-table .cf-row-total td { font-weight: 600; border-top: 1px solid #e5e7eb; }
.cf-report-table .cf-row-bold td { font-weight: 600; }
@media print {
    .cf-report-table { font-size: 0.85rem; }
}
</style>
