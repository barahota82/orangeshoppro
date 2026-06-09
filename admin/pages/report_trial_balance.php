<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$tbCountryLabel = orange_admin_page_country_label($pdo);
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);

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
$tbExcludeEntryTypes = $ignoreClosingEntries ? ['year_end_close'] : [];

$tbBefore = [];
$tbPeriod = [];
if (
    $useVouchers && $periodLabel !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $tbBefore = orange_voucher_account_totals_strictly_before_date($pdo, $periodDateFrom, $tbExcludeEntryTypes);
    $tbPeriod = orange_voucher_account_totals_by_voucher_date_range($pdo, $periodDateFrom, $periodDateTo, $tbExcludeEntryTypes);
}

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$tbAcctSql = "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere";
$tbAcctParams = [];
$tbAcctFilter = orange_accounts_sql_country_filter($pdo, 'a');
if ($tbAcctFilter !== null) {
    $tbAcctSql .= $tbAcctFilter['sql'];
    $tbAcctParams = $tbAcctFilter['params'];
}
$tbAcctSql .= " ORDER BY COALESCE(a.code, ''), a.name";
if ($tbAcctParams !== []) {
    $tbAcctSt = $pdo->prepare($tbAcctSql);
    $tbAcctSt->execute($tbAcctParams);
    $accountsLeaf = $tbAcctSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
    $accountsLeaf = $pdo->query($tbAcctSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$leafIdsForMap = [];
foreach ($accountsLeaf as $al) {
    $lid = (int) ($al['id'] ?? 0);
    if ($lid > 0) {
        $leafIdsForMap[] = $lid;
    }
}
$mapLeaf = orange_accounts_report_mapping_by_ids($pdo, $leafIdsForMap);

$rowsTb = [];

$sum_od = $sum_oc = $sum_pd = $sum_pc = $sum_ed = $sum_ec = 0.0;

foreach ($accountsLeaf as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $ob = ['debit' => 0.0, 'credit' => 0.0];
    $pr = ['debit' => 0.0, 'credit' => 0.0];
    if (isset($tbBefore[$aid])) {
        $ob = $tbBefore[$aid];
    }
    if (isset($tbPeriod[$aid])) {
        $pr = $tbPeriod[$aid];
    }
    $opd = (float) $ob['debit'];
    $opc = (float) $ob['credit'];
    $pperd = (float) $pr['debit'];
    $pperc = (float) $pr['credit'];
    $endDeb = $opd + $pperd;
    $endCre = $opc + $pperc;

    if (
        $opd <= 0.0001 && $opc <= 0.0001
        && $pperd <= 0.0001 && $pperc <= 0.0001
        && $endDeb <= 0.0001 && $endCre <= 0.0001
    ) {
        continue;
    }

    $code = trim((string) ($a['code'] ?? ''));
    $name = (string) ($a['name'] ?? '');

    $rowBase = orange_accounts_report_display_and_sort_meta($mapLeaf[$aid] ?? null);

    $rowsTb[] = array_merge([
        'aid' => $aid,
        'code' => $code,
        'name' => $name,
        'op_deb' => $opd,
        'op_cred' => $opc,
        'per_deb' => $pperd,
        'per_cred' => $pperc,
        'end_deb' => $endDeb,
        'end_cred' => $endCre,
    ], $rowBase);
    $sum_od += $opd;
    $sum_oc += $opc;
    $sum_pd += $pperd;
    $sum_pc += $pperc;
    $sum_ed += $endDeb;
    $sum_ec += $endCre;
}

usort($rowsTb, 'orange_accounts_report_tb_rows_compare');

$reportDateFromDmY = orange_format_date_dmY($periodDateFrom);
$reportDateToDmY = orange_format_date_dmY($periodDateTo);
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$companyNameAr = orange_company_settings_name_ar($pdo);
$tbCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$tbLogo = (string) ($tbCompany['logo_url'] ?? '');

$reportFmt = static function (float $v) use ($reportMoney): string {
    return orange_accounting_report_format_amount($v, $reportMoney);
};

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <div class="page-title">
            <h1>ميزان المراجعة</h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($tbCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="tb_report_form">
            <input type="hidden" name="page" value="report_trial_balance">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar ta-report-toolbar--is-buttons-left gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="tb_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="tb_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="tb_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="tb_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field is-toolbar-spacer" aria-hidden="true"></div>
                    <label class="gas-acc-stmt-field is-ignore-close-field" title="قيود الإقفال السنوي (YEC) تُصفّر الإيرادات والمصروفات — فعِّل هذا الخيار لاستبعادها من أرقام التقرير إذا كان المدى الزمني يشمل تاريخ الإقفال.">
                        <input type="hidden" name="ignore_close" value="0">
                        <input type="checkbox" name="ignore_close" value="1" id="tb_ignore_close" <?php echo $ignoreClosingEntries ? 'checked' : ''; ?>>
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
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ ميزان المراجعة لا يعرض أسطر حساب حتى الإنشاء في «الدليل المحاسبي». <strong>الشاشة والفترات تعملان</strong> — المتوقَّع أثناء الإعداد الأول.</p>
    </div>
<?php endif; ?>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card">
        <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض ميزان المراجعة.</p>
    </div>
<?php elseif ($periodLabel === ''): ?>
    <div class="card admin-fy-card">
        <p class="muted">تعذّر تحديد مدى التقويم.</p>
    </div>
<?php else: ?>
    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet tb-report-print-sheet">
            <header class="gl-acc-stmt-print-banner">
                <div class="pl-month-brand-row">
                    <div class="pl-month-brand">
                        <?php if ($tbLogo !== ''): ?>
                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($tbLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php endif; ?>
                        <div class="pl-month-brand-text">
                            <?php if ($companyNameAr !== ''): ?>
                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($tbCompany['commercial_register'] ?? '')) !== ''): ?>
                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $tbCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pl-month-contact">
                        <?php if (trim((string) ($tbCompany['address'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $tbCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($tbCompany['phones'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $tbCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <h2 class="gl-acc-stmt-print-title tb-report-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">تقــــــرير ميــــــــزان المراجــــــــة عن الفترة من&nbsp;<?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلـى&nbsp;&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </h2>
                <p class="tb-report-subbanner muted" lang="ar" style="text-align:center;margin:0 0 0.5rem;font-size:0.95rem;">
                    ميـــزان المــراجعة بالأرصــدة
                </p>
            </header>
            <div class="gl-acc-stmt-print-grid">
                <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                    <span class="gl-acc-stmt-print-k">من تاريخ</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">إلى تاريخ</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="gl-acc-stmt-print-k">تاريخ الكشف</span>
                    <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            </div>

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap ta-report-table-scroll">
                <table class="admin-fy-table gl-acc-stmt-table tb-report-table" data-export-name="ميزان المراجعة" data-export-target=".gas-acc-stmt-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars('عن الفترة من ' . $reportDateFromDmY . ' إلى ' . $reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?>">
                    <thead>
                        <tr>
                            <th class="gl-acc-stmt-col-num tb-col-code">كــود الحســاب</th>
                            <th class="tb-col-name">اســــــم الحســــــاب</th>
                            <th class="tb-col-map" lang="ar">قســــم التقريــر</th>
                            <th class="tb-col-map" lang="ar">سطر المرجع</th>
                            <th class="tb-grouphead" colspan="2">رصيد أول الفترة</th>
                            <th class="tb-grouphead" colspan="2">قيود الفترة</th>
                            <th class="tb-grouphead" colspan="2">رصيد نهاية الفترة</th>
                        </tr>
                        <tr class="tb-subhead">
                            <th colspan="4"></th>
                            <th class="gl-acc-stmt-col-num">مديـــــــن</th>
                            <th class="gl-acc-stmt-col-num">دائــــــــن</th>
                            <th class="gl-acc-stmt-col-num">مديـــــــن</th>
                            <th class="gl-acc-stmt-col-num">دائــــــــن</th>
                            <th class="gl-acc-stmt-col-num">مديـــــــن</th>
                            <th class="gl-acc-stmt-col-num">دائــــــــن</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rowsTb === []): ?>
                            <tr><td colspan="10" class="muted">لا أرصدة أو حركات على حسابات فرعية في المدى المحدد.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rowsTb as $r): ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num tb-col-code" dir="ltr"><?php echo htmlspecialchars((string) ($r['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="tb-col-map muted" lang="ar"><?php echo htmlspecialchars((string) ($r['sec_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="tb-col-map muted" lang="ar"><?php echo htmlspecialchars((string) ($r['line_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['op_deb'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['op_cred'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['per_deb'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['per_cred'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['end_deb'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $reportFmt((float) ($r['end_cred'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($rowsTb !== []): ?>
                        <tfoot>
                            <tr class="tb-report-total">
                                <td class="muted" colspan="4">الإجمــــــــــــــــــــــــــــــــــــــــالى</td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_od); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_oc); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_pd); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_pc); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_ed); ?></td>
                                <td class="gl-acc-stmt-col-num"><?php echo $reportFmt($sum_ec); ?></td>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($rowsTb !== []): ?>
                <p class="card-hint tb-diff-hint muted" style="margin:10px 0 4px;font-size:0.88rem;text-align:right;">
                    فرق نهاية المدى (مدين − دائن): <strong dir="ltr"><?php echo $reportFmt($sum_ed - $sum_ec); ?></strong>
                    — يجب أن يقترب من الصفر مع اكتمال القيود.
                </p>
            <?php endif; ?>
            <?php echo orange_accounting_report_print_metafoot_markup($printDatetime, 'تاريخ الطباعة'); ?>
        </div>
    </div>
<?php endif; ?>

</div>
