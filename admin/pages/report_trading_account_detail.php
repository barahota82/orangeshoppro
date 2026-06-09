<?php

declare(strict_types=1);

/**
 * منطق تقرير «قائمة حسابات المتاجرة» (slug: report_trading_account).
 * تُحمَّل من report_trading_account.php؛ $page يضبطه admin/index.php.
 */

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/financial_report_breakdown.php';
require_once __DIR__ . '/../../includes/accounting_pl_statement_rows.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$taCountryLabel = orange_admin_page_country_label($pdo);
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

$taPageQuery = 'report_trading_account';
$taHeadingAr = 'قائمة حسابات المتاجرة';

$normalizeYm = static function (string $raw): ?string {
    $raw = trim($raw);
    if (! preg_match('/^(\d{4})-(\d{2})$/', $raw, $m)) {
        return null;
    }
    $month = (int) $m[2];
    if ($month < 1 || $month > 12) {
        return null;
    }

    return sprintf('%04d-%02d', (int) $m[1], $month);
};

$ymFromGet = isset($_GET['m_from']) ? $normalizeYm((string) $_GET['m_from']) : null;
$ymToGet = isset($_GET['m_to']) ? $normalizeYm((string) $_GET['m_to']) : null;

$firstDayOfYm = static function (string $ym): string {
    return $ym . '-01';
};

$lastDayOfYm = static function (string $ym): string {
    $d0 = $ym . '-01';
    $t = strtotime($d0 . ' 12:00:00');

    return $t ? date('Y-m-t', $t) : $ym . '-28';
};

$useVouchers = orange_journal_vouchers_ready($pdo);
$periodLabel = '';
$periodYmFrom = '';
$periodYmTo = '';
$periodDateFrom = '';
$periodDateTo = '';

$calYmMinBound = '2000-01';
$calYmMaxBound = '2100-12';

$yNow = (int) date('Y');
$mNow = (int) date('n');
$defaultYmJan = sprintf('%04d-01', $yNow);
$defaultYmToday = sprintf('%04d-%02d', $yNow, $mNow);

$ymFrom = $ymFromGet ?? $defaultYmJan;
$ymTo = $ymToGet ?? $defaultYmToday;
if ($ymFrom < $calYmMinBound) {
    $ymFrom = $calYmMinBound;
}
if ($ymFrom > $calYmMaxBound) {
    $ymFrom = $calYmMaxBound;
}
if ($ymTo < $calYmMinBound) {
    $ymTo = $calYmMinBound;
}
if ($ymTo > $calYmMaxBound) {
    $ymTo = $calYmMaxBound;
}
if ($ymFrom > $ymTo) {
    $swap = $ymFrom;
    $ymFrom = $ymTo;
    $ymTo = $swap;
}
$periodYmFrom = $ymFrom;
$periodYmTo = $ymTo;

$periodDateFrom = $firstDayOfYm($periodYmFrom);
$periodDateTo = $lastDayOfYm($periodYmTo);
if (strcmp($periodDateFrom, $periodDateTo) <= 0) {
    $periodLabel = $periodDateFrom . ' — ' . $periodDateTo;
}

/** عند التفعيل: استبعاد سندات entry_type = year_end_close من الفترة ورصيد أولها. */
$ignoreClosingEntries = !isset($_GET['ignore_close']) || (string) $_GET['ignore_close'] === '1';
$plExcludeEntryTypes = $ignoreClosingEntries ? ['year_end_close'] : [];

$tbRange = [];
$tbBefore = [];
if (
    $useVouchers && $periodLabel !== ''
    && $periodDateFrom !== '' && $periodDateTo !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $tbRange = orange_voucher_account_totals_by_voucher_date_range($pdo, $periodDateFrom, $periodDateTo, $plExcludeEntryTypes);
    $tbBefore = orange_voucher_account_totals_strictly_before_date($pdo, $periodDateFrom, $plExcludeEntryTypes);
}

$accountsLeaf = orange_financial_report_leaf_accounts_with_mapping($pdo);

$revenueLines = $useVouchers ? orange_accounts_build_pl_statement_section_lines($pdo, $accountsLeaf, $tbRange, $tbBefore, 'revenue', 'trading_account') : [];
$cogsLines = $useVouchers ? orange_accounts_build_pl_statement_section_lines($pdo, $accountsLeaf, $tbRange, $tbBefore, 'cogs', 'trading_account') : [];

$sumOpen = static function (array $lines): float {
    $s = 0.0;
    foreach ($lines as $ln) {
        $s += (float) ($ln['opening'] ?? 0);
    }

    return $s;
};
$sumPer = static function (array $lines): float {
    $s = 0.0;
    foreach ($lines as $ln) {
        $s += (float) ($ln['period'] ?? 0);
    }

    return $s;
};
$sumClose = static function (array $lines): float {
    $s = 0.0;
    foreach ($lines as $ln) {
        $s += (float) ($ln['closing'] ?? 0);
    }

    return $s;
};

$totalRevOpening = $sumOpen($revenueLines);
$totalRevPeriod = $sumPer($revenueLines);
$totalRevClosing = $sumClose($revenueLines);
$totalCogsOpening = $sumOpen($cogsLines);
$totalCogsPeriod = $sumPer($cogsLines);
$totalCogsClosing = $sumClose($cogsLines);

$grossOpening = $totalRevOpening + $totalCogsOpening;
$grossPeriod = $totalRevPeriod + $totalCogsPeriod;
$grossClosing = $totalRevClosing + $totalCogsClosing;

$reportDateFromDmY = orange_format_date_dmY($periodDateFrom);
$reportDateToDmY = orange_format_date_dmY($periodDateTo);
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$companyNameAr = orange_company_settings_name_ar($pdo);
$taCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$taLogo = (string) ($taCompany['logo_url'] ?? '');

$hasTradingData = $revenueLines !== [] || $cogsLines !== [];

$reportFmt = static function (float $v) use ($reportMoney): string {
    return orange_accounting_report_format_amount($v, $reportMoney);
};

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <div class="page-title">
            <h1><?php echo htmlspecialchars($taHeadingAr, ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($taCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="ta_report_form">
            <input type="hidden" name="page" value="<?php echo htmlspecialchars($taPageQuery, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar ta-report-toolbar--is-buttons-left gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="ta_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="ta_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="ta_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="ta_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field is-toolbar-spacer" aria-hidden="true"></div>
                    <label class="gas-acc-stmt-field is-ignore-close-field" title="قيود الإقفال السنوي (YEC) تُصفّر الإيرادات والمصروفات — فعِّل هذا الخيار لاستبعادها من أرقام التقرير إذا كان المدى الزمني يشمل تاريخ الإقفال.">
                        <input type="hidden" name="ignore_close" value="0">
                        <input type="checkbox" name="ignore_close" value="1" id="ta_ignore_close" <?php echo $ignoreClosingEntries ? 'checked' : ''; ?>>
                        <span>تجاهل قيود الإقفال</span>
                    </label>
                    <div class="gas-acc-stmt-actions" data-export-host>
                        <button type="submit">عرض</button>
                        <button type="button" class="btn-secondary" onclick="<?php echo ($useVouchers && $periodLabel !== '') ? 'window.print()' : "alert('اعرض التقرير أولاً ثم اضغط طباعة')"; ?>">طباعة</button>
                    </div>
                </div>
            </div>
        </form>
    </div>

<?php if ($useVouchers && $accountsLeaf === []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ التقرير يظهر فارغاً إلى أن تُنشأ حسابات في «الدليل المحاسبي». <strong>الشاشة والنموذج يعملان</strong> — هذا متوقَّع أثناء الإعداد الأول.</p>
    </div>
<?php endif; ?>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card">
        <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض قائمة المتاجرة.</p>
    </div>
<?php elseif ($periodLabel === ''): ?>
    <div class="card admin-fy-card">
        <p class="muted">تعذّر تحديد مدى التقويم.</p>
    </div>
<?php else: ?>
    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
            <header class="gl-acc-stmt-print-banner">
                <div class="pl-month-brand-row">
                    <div class="pl-month-brand">
                        <?php if ($taLogo !== ''): ?>
                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($taLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php endif; ?>
                        <div class="pl-month-brand-text">
                            <?php if ($companyNameAr !== ''): ?>
                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($taCompany['commercial_register'] ?? '')) !== ''): ?>
                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $taCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pl-month-contact">
                        <?php if (trim((string) ($taCompany['address'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $taCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($taCompany['phones'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $taCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar"><?php echo htmlspecialchars($taHeadingAr, ENT_QUOTES, 'UTF-8'); ?> عن الفترة من <?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلـى&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </h2>
            </header>
            <div class="gl-acc-stmt-print-grid">
                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                    <span class="gl-acc-stmt-print-k">من تاريخ</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">الى تاريخ</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">تاريخ الكشف</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap ta-report-table-scroll">
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table ta-report-table--xlsx" data-export-name="<?php echo htmlspecialchars($taHeadingAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-target=".gas-acc-stmt-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars('عن الفترة من ' . $reportDateFromDmY . ' إلى ' . $reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?>">
                    <thead>
                        <tr>
                            <th class="gl-acc-stmt-col-num">كــود الحســاب</th>
                            <th>اســــــم الحســــــاب</th>
                            <th class="gl-acc-stmt-col-num">رصيد اول الفترة</th>
                            <th class="gl-acc-stmt-col-num">رصيد الفترة</th>
                            <th class="gl-acc-stmt-col-num">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($revenueLines === []): ?>
                            <tr><td colspan="5" class="muted">لا بيانات إيرادات / مبيعات بحسب الحسابات الفرعية في المدى.</td></tr>
                        <?php else: ?>
                            <?php
                            $prevRevenueSection = '';
foreach ($revenueLines as $rl):
    $secH = (string) ($rl['_section_heading'] ?? '');
    if ($secH !== '' && $secH !== $prevRevenueSection) {
        $prevRevenueSection = $secH;
        echo '<tr class="ta-report-section"><td colspan="5">' . htmlspecialchars($secH, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($rl['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($rl['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($rl['opening'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($rl['period'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($rl['closing'] ?? 0)); ?></td>
                                </tr>
                            <?php
endforeach;
?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal ta-report-subtotal--sales">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td>اجمالى مبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalRevOpening); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalRevPeriod); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalRevClosing); ?></td>
                        </tr>

                        <?php if ($cogsLines === []): ?>
                            <tr><td colspan="5" class="muted">لا بيانات تكلفة مبيعات بحسب الحسابات الفرعية في المدى.</td></tr>
                        <?php else: ?>
                            <?php
                            $prevCogsSection = '';
foreach ($cogsLines as $cl):
    $secC = (string) ($cl['_section_heading'] ?? '');
    if ($secC !== '' && $secC !== $prevCogsSection) {
        $prevCogsSection = $secC;
        echo '<tr class="ta-report-section"><td colspan="5">' . htmlspecialchars($secC, ENT_QUOTES, 'UTF-8') . '</td></tr>';
    }
    ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($cl['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($cl['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($cl['opening'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($cl['period'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($cl['closing'] ?? 0)); ?></td>
                                </tr>
                            <?php
endforeach;
?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal ta-report-subtotal--cogs">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td>اجمالى تكلفة مبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalCogsOpening); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalCogsPeriod); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($totalCogsClosing); ?></td>
                        </tr>

                        <tr class="ta-report-grand">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>مجمل ربح</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($grossOpening); ?></strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($grossPeriod); ?></strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($grossClosing); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (! $hasTradingData): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">لا توجد حركة إيرادات أو تكلفة مبيعات على حسابات فرعية ضمن قطاع المتاجرة في المدى (بحسب تاريخ السند).</p>
            <?php endif; ?>

            <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
        </div>
    </div>
<?php endif; ?>

</div>
