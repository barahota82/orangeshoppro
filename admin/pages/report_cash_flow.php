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
<div class="admin-fy-shell gl-acc-stmt-print" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة التدفقات النقدية</h1>

        <form method="get" class="card admin-fy-card" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="report_cash_flow">
            <input type="hidden" name="run" value="1">
            <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));align-items:end;">
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
                <div class="gas-acc-stmt-actions">
                    <button type="submit">عرض</button>
                    <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                    <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=chart_of_accounts'), ENT_QUOTES, 'UTF-8'); ?>">الدليل المحاسبي</a>
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
    <?php elseif (! $submitted): ?>
        <div class="card">
            <p class="card-hint" style="margin:0;">اختر السنة المالية والطريقة ثم «عرض التقرير». التجميع من قيود **مرحّلة** فقط (بدون قيود إقفال YEC في تعديلات غير المباشرة).</p>
        </div>
    <?php elseif ($report === null): ?>
        <div class="card" style="border:1px solid #dc2626;background:#fef2f2;">
            <p style="margin:0;">تعذّر بناء التقرير للسنة المحددة.</p>
        </div>
    <?php else: ?>
        <div class="card admin-fy-card">
            <div class="gl-acc-stmt-print-sheet ral-print-sheet">
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
                <h2 class="gl-acc-stmt-print-title ral-print-title">قائمة التدفقات النقدية</h2>
                <p class="muted" style="margin:8px 0 0;">
                    <?php echo htmlspecialchars((string) $report['fy_label'], ENT_QUOTES, 'UTF-8'); ?>
                    — <?php echo htmlspecialchars((string) $report['period'], ENT_QUOTES, 'UTF-8'); ?>
                    — <?php echo $method === 'direct' ? 'الطريقة المباشرة' : 'الطريقة غير المباشرة'; ?>
                </p>
            </header>

            <?php if (($report['cash_account_ids'] ?? []) === []): ?>
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
                    </tbody>
                </table>
            </div>

            <?php
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

            <p class="card-hint muted" style="margin-top:12px;font-size:0.85rem;">
                مرجع: قيود مرحّلة؛ تصنيف التدفقات من <code>accounts.cashflow_section</code> (تشغيل / استثمار / تمويل) مع fallback للذمم المتداولة.
            </p>
            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
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
