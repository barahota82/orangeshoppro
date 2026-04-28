<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

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

$tbRange = [];
if (
    $useVouchers && $periodLabel !== ''
    && $periodDateFrom !== '' && $periodDateTo !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $tbRange = orange_voucher_account_totals_by_voucher_date_range($pdo, $periodDateFrom, $periodDateTo, []);
}

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$accountsLeaf = $pdo->query(
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/**
 * @return list<array{label: string, amount: float}>
 */
$buildTradingLines = static function (
    PDO $pdo,
    array $accountsLeaf,
    array $tbRange,
    string $plClass
): array {
    $out = [];
    foreach ($accountsLeaf as $a) {
        $aid = (int) ($a['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $pr = orange_accounts_account_pl_role($pdo, $aid);
        if ($pr !== $plClass) {
            continue;
        }
        $deb = 0.0;
        $cred = 0.0;
        if (isset($tbRange[$aid])) {
            $deb = (float) $tbRange[$aid]['debit'];
            $cred = (float) $tbRange[$aid]['credit'];
        }
        if ($plClass === 'revenue') {
            $net = $cred - $deb;
        } else {
            $net = $deb - $cred;
        }
        if (abs($net) < 0.0001) {
            continue;
        }
        $code = trim((string) ($a['code'] ?? ''));
        $nm = (string) ($a['name'] ?? '');
        $label = ($code !== '' ? $code . ' — ' : '') . $nm;
        $out[] = ['label' => $label, 'amount' => $net];
    }

    return $out;
};

$revenueLines = $useVouchers ? $buildTradingLines($pdo, $accountsLeaf, $tbRange, 'revenue') : [];
$cogsLines = $useVouchers ? $buildTradingLines($pdo, $accountsLeaf, $tbRange, 'cogs') : [];

$totalRevenue = 0.0;
foreach ($revenueLines as $rl) {
    $totalRevenue += (float) ($rl['amount'] ?? 0);
}
$totalCogs = 0.0;
foreach ($cogsLines as $cl) {
    $totalCogs += (float) ($cl['amount'] ?? 0);
}
$grossProfit = $totalRevenue - $totalCogs;

$reportDateFromDmY = orange_format_date_dmY($periodDateFrom);
$reportDateToDmY = orange_format_date_dmY($periodDateTo);
$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$companyNameAr = '';
if (orange_table_exists($pdo, 'company_settings')) {
    $cs = $pdo->query('SELECT company_name_ar FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cs)) {
        $companyNameAr = trim((string) ($cs['company_name_ar'] ?? ''));
    }
}

$hasTradingData = $revenueLines !== [] || $cogsLines !== [];

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة حساب المتاجرة</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="ta_report_form">
            <input type="hidden" name="page" value="report_trading_account">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="ta_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="ta_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="نفس منتقي شهر «الحركة الشهرية لحساب»: أول يوم وأخر يوم من الشهرين المختارين."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="ta_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="ta_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="نفس منتقي شهر «الحركة الشهرية لحساب»."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $periodLabel !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        <p class="card-hint" style="margin-top:12px;margin-bottom:0;">
            تقرير تقويمي لحسابات <strong>الإيرادات</strong> و<strong>تكلفة المبيعات</strong> (من جذور دليلكم كما في قائمة الدخل) ضمن مدى<strong>تاريخ السند</strong>؛
            <strong>مجمل الربح</strong> = إجمالي إيرادات المدى − إجمالي تكلفة المبيعات للمدى.
            <span class="muted" style="display:block;margin-top:8px;font-size:0.9rem;">ينطبق ضبط الأشهر مثل تقرير «الحركة الشهرية لحساب» (من شهر / إلى شهر).</span>
        </p>
    </div>

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
                <?php if ($companyNameAr !== ''): ?>
                    <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة حساب المتاجرة عن الفترة من <?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلى&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
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

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table">
                    <thead>
                        <tr>
                            <th>البيان</th>
                            <th class="gl-acc-stmt-col-num">المبلغ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="ta-report-section">
                            <td colspan="2">المبيعات / الإيرادات</td>
                        </tr>
                        <?php if ($revenueLines === []): ?>
                            <tr><td colspan="2" class="muted">لا بيانات إيرادات بحسب الحسابات الفرعية في المدى.</td></tr>
                        <?php else: ?>
                            <?php foreach ($revenueLines as $rl): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($rl['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($rl['amount'] ?? 0), 4); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal">
                            <td>إجمالي المبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($totalRevenue, 4); ?></td>
                        </tr>

                        <tr class="ta-report-section">
                            <td colspan="2">تكلفة المبيعات</td>
                        </tr>
                        <?php if ($cogsLines === []): ?>
                            <tr><td colspan="2" class="muted">لا بيانات تكلفة مبيعات بحسب الحسابات الفرعية في المدى.</td></tr>
                        <?php else: ?>
                            <?php foreach ($cogsLines as $cl): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($cl['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo number_format((float) ($cl['amount'] ?? 0), 4); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal">
                            <td>إجمالي تكلفة المبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo number_format($totalCogs, 4); ?></td>
                        </tr>

                        <tr class="ta-report-grand">
                            <td><strong>مجمل الربح (الخسارة)</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo number_format($grossProfit, 4); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (! $hasTradingData): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">لا توجد حركة إيرادات أو تكلفة مبيعات على حسابات فرعية لهذه القطاعات خلال المدى المختار (بحسب تاريخ السند).</p>
            <?php endif; ?>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
        <p class="card-hint gl-acc-stmt-no-print" style="margin-top:12px;margin-bottom:0;">
            مدى الأرقام: <span dir="ltr"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            — نفس سياسة «الحركة الشهرية لحساب» (تجميع وفقًا ل<strong>تاريخ السند</strong> بين أول وآخر يوم للشهرين).
        </p>
    </div>
<?php endif; ?>

</div>
