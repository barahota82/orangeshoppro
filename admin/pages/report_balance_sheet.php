<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';
require_once __DIR__ . '/../../includes/accounting_bs_statement_rows.php';
require_once __DIR__ . '/../../includes/financial_report_breakdown.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

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

$ignoreClosingEntries = !isset($_GET['ignore_close']) || (string) $_GET['ignore_close'] === '1';
$bsExcludeEntryTypes = $ignoreClosingEntries ? ['year_end_close'] : [];

$accountsLeaf = orange_financial_report_leaf_accounts_with_mapping($pdo);

$assetLines = [];
$liabilityLines = [];
$equityLines = [];
$totalAssets = 0.0;
$totalLiab = 0.0;
$totalEquity = 0.0;
$bsCheck = 0.0;

if ($useVouchers && $asOfYmd !== '') {
    $tbAsOf = orange_voucher_account_totals_on_or_before_date($pdo, $asOfYmd, $bsExcludeEntryTypes);
    $assetLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'asset');
    $liabilityLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'liability');
    $equityLines = orange_accounts_build_bs_statement_section_lines($pdo, $accountsLeaf, $tbAsOf, 'equity');
    foreach ($assetLines as $ln) {
        $totalAssets += (float) ($ln['balance'] ?? 0);
    }
    foreach ($liabilityLines as $ln) {
        $totalLiab += (float) ($ln['balance'] ?? 0);
    }
    foreach ($equityLines as $ln) {
        $totalEquity += (float) ($ln['balance'] ?? 0);
    }
    $bsCheck = round($totalAssets - ($totalLiab + $totalEquity), 4);
}

$hasBsData = $assetLines !== [] || $liabilityLines !== [] || $equityLines !== [];

$reportFmt = static function (float $v) use ($reportMoney): string {
    return orange_accounting_report_format_amount($v, $reportMoney);
};

$asOfDmY = orange_format_date_dmY($asOfYmd);
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$companyNameAr = orange_company_settings_name_ar($pdo);

$fyLabelAr = $fyRow !== null ? (string) ($fyRow['label_ar'] ?? '') : '';

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">الميزانية العمومية</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <?php if ($years === []): ?>
            <p class="muted">لا توجد سنوات مالية. افتح <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
        <?php else: ?>
        <form method="get" class="gas-acc-stmt-filter-form" id="bs_report_form">
            <input type="hidden" name="page" value="report_balance_sheet">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar gas-acc-stmt-toolbar--main-center">
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
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $asOfYmd !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                    <label class="gas-acc-stmt-field is-ignore-close-field" title="استبعاد سندات الإقفال السنوي (YEC) من الأرصدة المعروضة.">
                        <input type="hidden" name="ignore_close" value="0">
                        <input type="checkbox" name="ignore_close" value="1" id="bs_ignore_close" <?php echo $ignoreClosingEntries ? 'checked' : ''; ?>>
                        <span>تجاهل قيود الإقفال</span>
                    </label>
                </div>
            </div>
        </form>
        <?php endif; ?>
    </div>

<?php if ($useVouchers && $accountsLeaf === []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ التقرير يظهر فارغاً إلى أن تُنشأ حسابات في «الدليل المحاسبي». <strong>الشاشة والنموذج يعملان</strong> — هذا متوقَّع أثناء الإعداد الأول.</p>
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
        <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
            <header class="gl-acc-stmt-print-banner">
                <?php if ($companyNameAr !== ''): ?>
                    <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">الميزانية العمومية في <?php echo htmlspecialchars($asOfDmY, ENT_QUOTES, 'UTF-8'); ?></span>
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
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table ta-report-table--xlsx">
                    <thead>
                        <tr>
                            <th class="gl-acc-stmt-col-num">كــود الحســاب</th>
                            <th>اســــــم الحســــــاب</th>
                            <th class="gl-acc-stmt-col-num">الرصيد</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="ta-report-section">
                            <td colspan="3">الأصول</td>
                        </tr>
                        <?php if ($assetLines === []): ?>
                            <tr><td colspan="3" class="muted">لا أصول بها رصيد عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($assetLines as $ln): ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($ln['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($ln['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($ln['balance'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>إجمالي الأصول</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalAssets); ?></strong></td>
                        </tr>

                        <tr class="ta-report-section">
                            <td colspan="3">الخصوم</td>
                        </tr>
                        <?php if ($liabilityLines === []): ?>
                            <tr><td colspan="3" class="muted">لا خصوم بها رصيد عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($liabilityLines as $ln): ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($ln['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($ln['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($ln['balance'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>إجمالي الخصوم</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalLiab); ?></strong></td>
                        </tr>

                        <tr class="ta-report-section">
                            <td colspan="3">حقوق الملكية</td>
                        </tr>
                        <?php if ($equityLines === []): ?>
                            <tr><td colspan="3" class="muted">لا حقوق ملكية بها رصيد عند هذا التاريخ.</td></tr>
                        <?php else: ?>
                            <?php foreach ($equityLines as $ln): ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo htmlspecialchars((string) ($ln['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($ln['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($ln['balance'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>إجمالي حقوق الملكية</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalEquity); ?></strong></td>
                        </tr>

                        <tr class="ta-report-grand">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>الخصوم + حقوق الملكية</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $reportFmt($totalLiab + $totalEquity); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (! $hasBsData): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">لا توجد أرصدة ميزانية على حسابات فرعية مُصنَّفة ضمن الدليل عند هذا التاريخ.</p>
            <?php endif; ?>

            <?php if ($hasBsData && abs($bsCheck) >= 0.05): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">
                    <strong>فرق محاسبي:</strong> <?php echo $reportFmt($bsCheck); ?> (أصول − خصوم − حقوق) — راجع التصنيف أو القيود.
                </p>
            <?php endif; ?>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>
