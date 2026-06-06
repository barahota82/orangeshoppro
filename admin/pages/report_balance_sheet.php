<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';
require_once __DIR__ . '/../../includes/accounting_bs_statement_rows.php';
require_once __DIR__ . '/../../includes/financial_report_breakdown.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$bsCountryId = function_exists('orange_admin_context_country_id') ? (int) orange_admin_context_country_id($pdo) : 0;

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}

$fyRow = null;
foreach ($years as $y) {
    if ((int) $y['id'] === $fyId) {
        $fyRow = $y;
        break;
    }
}

/* السنة المالية السابقة: أكبر start_date أقدم من بداية السنة الحالية (مقارنة عمودية). */
$prevFyRow = null;
if ($fyRow !== null) {
    $curStart = (string) ($fyRow['start_date'] ?? '');
    foreach ($years as $y) {
        $yStart = (string) ($y['start_date'] ?? '');
        if ($curStart !== '' && $yStart !== '' && strcmp($yStart, $curStart) < 0) {
            if ($prevFyRow === null || strcmp($yStart, (string) ($prevFyRow['start_date'] ?? '')) > 0) {
                $prevFyRow = $y;
            }
        }
    }
}

$useVouchers = orange_journal_vouchers_ready($pdo);

$asOfYmd = isset($_GET['as_of']) ? trim((string) $_GET['as_of']) : '';
if ($fyRow !== null) {
    $fyEnd = trim((string) ($fyRow['end_date'] ?? ''));
    $fyStart = trim((string) ($fyRow['start_date'] ?? ''));
    if ($asOfYmd === '' && $fyEnd !== '') {
        $asOfYmd = $fyEnd;
    }
    if ($asOfYmd !== '' && $fyStart !== '' && strcmp($asOfYmd, $fyStart) < 0) {
        $asOfYmd = $fyStart;
    }
    if ($asOfYmd !== '' && $fyEnd !== '' && strcmp($asOfYmd, $fyEnd) > 0) {
        $asOfYmd = $fyEnd;
    }
}
if ($asOfYmd === '') {
    $asOfYmd = date('Y-m-d');
}

$prevAsOfYmd = $prevFyRow !== null ? trim((string) ($prevFyRow['end_date'] ?? '')) : '';
$showCompare = $prevAsOfYmd !== '';

$ignoreClosingEntries = !isset($_GET['ignore_close']) || (string) $_GET['ignore_close'] === '1';
$bsExcludeEntryTypes = $ignoreClosingEntries ? ['year_end_close'] : [];

$accountsLeaf = orange_financial_report_leaf_accounts_with_mapping($pdo);

$assetLines = [];
$liabilityLines = [];
$equityLines = [];
$totalAssets = 0.0;
$totalAssetsPrev = 0.0;
$totalLiab = 0.0;
$totalLiabPrev = 0.0;
$totalEquity = 0.0;
$totalEquityPrev = 0.0;
$bsCheck = 0.0;

if ($useVouchers && $asOfYmd !== '') {
    $tbAsOf = orange_voucher_account_totals_on_or_before_date($pdo, $asOfYmd, $bsExcludeEntryTypes);
    $tbPrev = $showCompare
        ? orange_voucher_account_totals_on_or_before_date($pdo, $prevAsOfYmd, $bsExcludeEntryTypes)
        : null;
    $assetLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'asset', $tbPrev);
    $liabilityLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'liability', $tbPrev);
    $equityLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'equity', $tbPrev);
    foreach ($assetLines as $ln) {
        $totalAssets += (float) ($ln['balance'] ?? 0);
        $totalAssetsPrev += (float) ($ln['balance_prev'] ?? 0);
    }
    foreach ($liabilityLines as $ln) {
        $totalLiab += (float) ($ln['balance'] ?? 0);
        $totalLiabPrev += (float) ($ln['balance_prev'] ?? 0);
    }
    foreach ($equityLines as $ln) {
        $totalEquity += (float) ($ln['balance'] ?? 0);
        $totalEquityPrev += (float) ($ln['balance_prev'] ?? 0);
    }
    $bsCheck = round($totalAssets - ($totalLiab + $totalEquity), 4);
}

/* تقسيم الأصول: متداولة (نقد + مخزون + أكواد مرجعية متداولة) / غير متداولة. */
$currentAssets = [];
$nonCurrentAssets = [];
foreach ($assetLines as $ln) {
    if (!empty($ln['is_current'])) {
        $currentAssets[] = $ln;
    } else {
        $nonCurrentAssets[] = $ln;
    }
}
$totalCurrentAssets = 0.0;
$totalCurrentAssetsPrev = 0.0;
foreach ($currentAssets as $ln) {
    $totalCurrentAssets += (float) ($ln['balance'] ?? 0);
    $totalCurrentAssetsPrev += (float) ($ln['balance_prev'] ?? 0);
}
$totalNonCurrentAssets = $totalAssets - $totalCurrentAssets;
$totalNonCurrentAssetsPrev = $totalAssetsPrev - $totalCurrentAssetsPrev;

$hasBsData = $assetLines !== [] || $liabilityLines !== [] || $equityLines !== [];

$reportFmt = static function (float $v) use ($reportMoney): string {
    return orange_accounting_report_format_amount($v, $reportMoney);
};

$asOfDmY = orange_format_date_dmY($asOfYmd);
$prevAsOfDmY = $showCompare ? orange_format_date_dmY($prevAsOfYmd) : '';
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$company = orange_sales_doc_print_company($pdo, $bsCountryId);
$companyNameAr = $company['company_name_ar'];
$companyLogo = $company['logo_url'];
$companyCr = $company['commercial_register'];
$companyFooter = $company['invoice_footer'];

$fyLabelAr = $fyRow !== null ? (string) ($fyRow['label_ar'] ?? '') : '';
$prevFyLabelAr = $prevFyRow !== null ? (string) ($prevFyRow['label_ar'] ?? '') : '';

$colCount = $showCompare ? 4 : 3;

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة المركز المالي (الميزانية العمومية)</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <?php if ($years === []): ?>
            <p class="muted">لا توجد سنوات مالية. افتح <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
        <?php else: ?>
        <form method="get" class="gas-acc-stmt-filter-form" id="bs_report_form">
            <input type="hidden" name="page" value="report_balance_sheet">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar ta-report-toolbar--is-buttons-left ta-report-toolbar--bs-wide-fy gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field">
                        <label for="bs_fy">السنة المالية</label>
                        <select id="bs_fy" name="fy" class="admin-inp" onchange="this.form.submit()">
                            <?php foreach ($years as $y): ?>
                                <option value="<?php echo (int) $y['id']; ?>" <?php echo ((int) $y['id'] === $fyId) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($y['label_ar'] . ' (' . $y['start_date'] . ' — ' . $y['end_date'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="bs_as_of">حتى تاريخ</label>
                        <input type="date" name="as_of" id="bs_as_of" class="admin-inp" lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($asOfYmd, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php if ($fyRow !== null): ?>
                            min="<?php echo htmlspecialchars((string) ($fyRow['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars((string) ($fyRow['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                            <?php endif; ?>
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field is-toolbar-spacer" aria-hidden="true"></div>
                    <label class="gas-acc-stmt-field is-ignore-close-field" title="استبعاد سندات الإقفال السنوي (YEC) من الأرصدة المعروضة.">
                        <input type="hidden" name="ignore_close" value="0">
                        <input type="checkbox" name="ignore_close" value="1" id="bs_ignore_close" <?php echo $ignoreClosingEntries ? 'checked' : ''; ?>>
                        <span>تجاهل قيود الإقفال</span>
                    </label>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $asOfYmd !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($useVouchers): ?>
                <p class="muted gl-acc-stmt-no-print" style="margin:8px 0 0;font-size:12px;text-align:center;">
                    <?php if ($showCompare): ?>
                        مقارنة تلقائية مع نهاية السنة السابقة: <strong><?php echo htmlspecialchars($prevFyLabelAr !== '' ? $prevFyLabelAr : $prevAsOfDmY, ENT_QUOTES, 'UTF-8'); ?></strong>.
                    <?php else: ?>
                        لا توجد سنة مالية سابقة للمقارنة — يُعرض عمود السنة الحالية فقط.
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

<?php if ($useVouchers && $accountsLeaf === []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ التقرير يظهر فارغاً إلى أن تُنشأ حسابات في «الدليل المحاسبي». <strong>الشاشة والنموذج يعملان</strong> — هذا متوقَّع أثناء الإعداد الأول.</p>
    </div>
<?php endif; ?>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card">
        <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض الميزانية.</p>
    </div>
<?php elseif ($years === []): ?>
    <div class="card admin-fy-card"><p class="muted">أضف سنة مالية أولاً.</p></div>
<?php else: ?>
    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet ta-report-print-sheet bs-report-sheet">
            <header class="gl-acc-stmt-print-banner bs-report-banner">
                <div class="bs-report-brand">
                    <?php if ($companyLogo !== ''): ?>
                        <img class="bs-report-logo" src="<?php echo htmlspecialchars($companyLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                    <?php endif; ?>
                    <div class="bs-report-brand-text">
                        <?php if ($companyNameAr !== ''): ?>
                            <p class="gl-acc-stmt-print-company bs-report-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if ($companyCr !== ''): ?>
                            <p class="bs-report-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars($companyCr, ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title bs-report-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة المركز المالي</span>
                    <span class="bs-report-asof" lang="ar">كما في <span dir="ltr"><?php echo htmlspecialchars($asOfDmY, ENT_QUOTES, 'UTF-8'); ?></span></span>
                </h2>
            </header>
            <div class="gl-acc-stmt-print-grid">
                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                    <?php if ($fyLabelAr !== ''): ?>
                        <span class="gl-acc-stmt-print-k">السنة المالية</span>
                        <span class="gl-acc-stmt-print-v"><?php echo htmlspecialchars($fyLabelAr, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <span class="gl-acc-stmt-print-k">حتى تاريخ</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($asOfDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">تاريخ الكشف</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap ta-report-table-scroll">
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table ta-report-table--xlsx" data-export-name="قائمة المركز المالي" data-export-target=".gas-acc-stmt-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars('كما في ' . $asOfDmY, ENT_QUOTES, 'UTF-8'); ?>">
                    <thead>
                        <tr>
                            <th class="gl-acc-stmt-col-num">كــود الحســاب</th>
                            <th>اســــــم الحســــــاب</th>
                            <th class="gl-acc-stmt-col-num"><?php echo htmlspecialchars($asOfDmY, ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php if ($showCompare): ?>
                                <th class="gl-acc-stmt-col-num"><?php echo htmlspecialchars($prevAsOfDmY, ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $renderLine = static function (array $ln) use ($reportFmt, $showCompare): void {
                            ?>
                            <tr>
                                <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($ln['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($ln['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($ln['balance'] ?? 0)); ?></td>
                                <?php if ($showCompare): ?>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($ln['balance_prev'] ?? 0)); ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php
                        };
                        $renderSubtotal = static function (string $label, float $cur, float $prev) use ($reportFmt, $showCompare, $colCount): void {
                            ?>
                            <tr class="ta-report-subtotal">
                                <td class="gl-acc-stmt-col-num muted">—</td>
                                <td><strong><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($cur); ?></strong></td>
                                <?php if ($showCompare): ?>
                                    <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($prev); ?></strong></td>
                                <?php endif; ?>
                            </tr>
                            <?php
                        };
                        ?>

                        <tr class="ta-report-section"><td colspan="<?php echo $colCount; ?>">الأصول</td></tr>

                        <tr class="ta-report-section ta-report-section--sub"><td colspan="<?php echo $colCount; ?>">أصول متداولة</td></tr>
                        <?php if ($currentAssets === []): ?>
                            <tr><td colspan="<?php echo $colCount; ?>" class="muted">لا أصول متداولة عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($currentAssets as $ln) { $renderLine($ln); } ?>
                        <?php endif; ?>
                        <?php $renderSubtotal('إجمالي الأصول المتداولة', $totalCurrentAssets, $totalCurrentAssetsPrev); ?>

                        <tr class="ta-report-section ta-report-section--sub"><td colspan="<?php echo $colCount; ?>">أصول غير متداولة</td></tr>
                        <?php if ($nonCurrentAssets === []): ?>
                            <tr><td colspan="<?php echo $colCount; ?>" class="muted">لا أصول غير متداولة عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($nonCurrentAssets as $ln) { $renderLine($ln); } ?>
                        <?php endif; ?>
                        <?php $renderSubtotal('إجمالي الأصول غير المتداولة', $totalNonCurrentAssets, $totalNonCurrentAssetsPrev); ?>

                        <tr class="ta-report-grand"><td class="gl-acc-stmt-col-num muted">—</td><td><strong>إجمالي الأصول</strong></td><td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalAssets); ?></strong></td><?php if ($showCompare): ?><td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalAssetsPrev); ?></strong></td><?php endif; ?></tr>

                        <tr class="ta-report-section"><td colspan="<?php echo $colCount; ?>">الخصوم</td></tr>
                        <?php if ($liabilityLines === []): ?>
                            <tr><td colspan="<?php echo $colCount; ?>" class="muted">لا خصوم بها رصيد عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($liabilityLines as $ln) { $renderLine($ln); } ?>
                        <?php endif; ?>
                        <?php $renderSubtotal('إجمالي الخصوم', $totalLiab, $totalLiabPrev); ?>

                        <tr class="ta-report-section"><td colspan="<?php echo $colCount; ?>">حقوق الملكية</td></tr>
                        <?php if ($equityLines === []): ?>
                            <tr><td colspan="<?php echo $colCount; ?>" class="muted">لا حقوق ملكية بها رصيد عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($equityLines as $ln) { $renderLine($ln); } ?>
                        <?php endif; ?>
                        <?php $renderSubtotal('إجمالي حقوق الملكية', $totalEquity, $totalEquityPrev); ?>

                        <tr class="ta-report-grand"><td class="gl-acc-stmt-col-num muted">—</td><td><strong>إجمالي الخصوم وحقوق الملكية</strong></td><td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalLiab + $totalEquity); ?></strong></td><?php if ($showCompare): ?><td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalLiabPrev + $totalEquityPrev); ?></strong></td><?php endif; ?></tr>
                    </tbody>
                </table>
            </div>

            <?php if (! $hasBsData): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">لا توجد أرصدة ميزانية على حسابات فرعية مُصنَّفة ضمن الدليل عند هذا التاريخ.</p>
            <?php endif; ?>

            <?php if ($hasBsData && abs($bsCheck) >= 0.05): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">
                    <strong>فرق محاسبي:</strong> <?php echo $reportFmt($bsCheck); ?> (أصول − خصوم − حقوق) — راجع التصنيف أو القيود.
                </p>
            <?php endif; ?>

            <?php if ($companyFooter !== ''): ?>
                <p class="bs-report-legal-footer"><?php echo htmlspecialchars($companyFooter, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>
