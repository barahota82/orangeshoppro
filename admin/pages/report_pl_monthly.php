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

$iterateYmRange = static function (string $ymFrom, string $ymTo): array {
    if (strcmp($ymFrom, $ymTo) > 0) {
        return [];
    }
    $out = [];
    $cur = strtotime($ymFrom . '-01 12:00:00');
    $endLim = strtotime($ymTo . '-01 12:00:00');
    if ($cur === false || $endLim === false) {
        return [$ymFrom];
    }
    while ($cur <= $endLim) {
        $out[] = date('Y-m', $cur);
        $n = strtotime('first day of next month', $cur);
        if ($n === false) {
            break;
        }
        $cur = $n;
        if (count($out) > 600) {
            break;
        }
    }

    return $out;
};

$monthLabelAr = static function (string $ym): string {
    static $months = [
        '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'إبريل',
        '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];
    if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }
    $mo = $m[2];

    return ($months[$mo] ?? $mo) . ' ' . $m[1];
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

$monthList = $iterateYmRange($periodYmFrom, $periodYmTo);

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$accountsLeaf = $pdo->query(
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$plAccounts = [];

foreach ($accountsLeaf as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $role = orange_accounts_account_pl_role($pdo, $aid);
    if (! in_array($role, ['revenue', 'cogs', 'expense'], true)) {
        continue;
    }
    $plAccounts[] = [
        'id' => $aid,
        'code' => trim((string) ($a['code'] ?? '')),
        'name' => (string) ($a['name'] ?? ''),
        'role' => $role,
    ];
}

$idToRole = [];
foreach ($plAccounts as $pa) {
    $idToRole[(int) $pa['id']] = (string) $pa['role'];
}

$signedNature = static function (string $role, float $deb, float $cred): float {
    if ($role === 'revenue') {
        return round($cred - $deb, 4);
    }

    return round($deb - $cred, 4);
};

/** @var array<int, array<string, float>> */
$amtByAccountYm = [];

if (
    $useVouchers
    && $periodLabel !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
    && $monthList !== []
    && $plAccounts !== []
) {
    $ids = [];
    foreach ($plAccounts as $pa) {
        $ids[] = (int) $pa['id'];
    }
    $ids = array_values(array_unique($ids));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT jl.account_id,
            DATE_FORMAT(jv.voucher_date, '%Y-%m') AS ym,
            COALESCE(SUM(jl.debit), 0) AS d,
            COALESCE(SUM(jl.credit), 0) AS c
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id IN ($ph)
           AND jv.voucher_date >= ?
           AND jv.voucher_date <= ?
         GROUP BY jl.account_id, ym";
    $params = array_merge($ids, [$periodDateFrom, $periodDateTo]);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $rw) {
        $aid = (int) ($rw['account_id'] ?? 0);
        $ym = (string) ($rw['ym'] ?? '');
        if ($aid <= 0 || $ym === '') {
            continue;
        }
        $role = $idToRole[$aid] ?? 'other';
        if ($role === 'other') {
            continue;
        }
        $amt = $signedNature(
            $role,
            (float) ($rw['d'] ?? 0),
            (float) ($rw['c'] ?? 0)
        );
        if (! isset($amtByAccountYm[$aid])) {
            $amtByAccountYm[$aid] = [];
        }
        $amtByAccountYm[$aid][$ym] = $amt;
    }
}

$showZeros = isset($_GET['show_zeros']) && (string) $_GET['show_zeros'] === '1';

$rowTotals = [];
$tableRows = [];
foreach ($plAccounts as $pa) {
    $aid = (int) $pa['id'];
    $accTot = 0.0;
    foreach ($monthList as $ym) {
        $v = (float) ($amtByAccountYm[$aid][$ym] ?? 0.0);
        $accTot += $v;
    }
    if (abs($accTot) < 0.0001 && ! $showZeros) {
        /** تُخفَى الصفرية إلا عند الطلب؛ صف واحد فارغ لا نملؤه بحسابات بلا أي حركة في المدى */
        continue;
    }
    $tableRows[] = [
        'id' => $aid,
        'code' => (string) $pa['code'],
        'name' => (string) $pa['name'],
        'by_month' => array_fill_keys($monthList, 0.0),
        'row_total' => round($accTot, 4),
    ];
    $nr = count($tableRows) - 1;
    foreach ($monthList as $ym) {
        $v = (float) ($amtByAccountYm[$aid][$ym] ?? 0.0);
        $tableRows[$nr]['by_month'][$ym] = $v;
    }
    $rowTotals[$aid] = $tableRows[$nr]['row_total'];
}

/** إجمالي عمود شهر = مجاميع تأثير الحسابات على قطاع قائمة الدخل بتوقيعها */
$colTotals = [];
foreach ($monthList as $ym) {
    $colTotals[$ym] = 0.0;
}

foreach ($tableRows as $tr) {
    foreach ($monthList as $ym) {
        $colTotals[$ym] += (float) ($tr['by_month'][$ym] ?? 0.0);
    }
}

$grandTot = array_sum($rowTotals);
$grandTot = round($grandTot, 4);

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

$fmt = static fn (float $v): string => number_format($v, 4);

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة إيرادات ومصروفات شهرية</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="pl_m_form">
            <input type="hidden" name="page" value="report_pl_monthly">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="pl_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="pl_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="حدود اليوم الأول/الأخير بالشهر (مثل الحركة الشهرية لحساب)، حسب تاريخ السند."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="pl_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="pl_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            autocomplete="off">
                    </div>
                    <label class="gas-acc-stmt-field" style="align-items:flex-start;margin-top:0.15rem;">
                        <input type="checkbox" name="show_zeros" value="1" <?php echo $showZeros ? 'checked' : ''; ?> style="margin-top:6px;margin-left:6px;">
                        <span>عرض كل الحسابات حتى بدون حركة</span>
                    </label>
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
            مصفوفة شهرية بحسب قطاع قائمة الدخل على جذور الدليل: قيمة الشهر للإيراد = (دائن − مدين)، وللتكلفة/المصروف = (مدين − دائن)، ثم مجموع أفقي لكل حساب؛ صفوف التذييل تُظهر مجموع عمود شهر كمحصّلة تقريبية لأثر الفترة.
        </p>
    </div>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card">
        <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض القائمة.</p>
    </div>
<?php elseif ($periodLabel === ''): ?>
    <div class="card admin-fy-card">
        <p class="muted">تعذّر تحديد مدى التقويم.</p>
    </div>
<?php elseif ($plAccounts === []): ?>
    <div class="card admin-fy-card">
        <p class="muted">لا توجد حسابات فرعية مصنَّفة كإيراد / تكلفة مبيعات / مصروف في الدليل.</p>
    </div>
<?php else: ?>
    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
            <header class="gl-acc-stmt-print-banner">
                <?php if ($companyNameAr !== ''): ?>
                    <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title">
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة إيرادات ومصروفات شهرية عن الفترة من&nbsp;<?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلـى&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
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

            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap pl-monthly-matrix-wrap ta-report-table-scroll">
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table pl-monthly-matrix-table ta-report-table--xlsx">
                    <thead>
                        <tr>
                            <th class="gl-acc-stmt-col-num">كــود الحســاب</th>
                            <th>اســــــم الحســــــاب</th>
                            <?php foreach ($monthList as $ym): ?>
                                <th class="gl-acc-stmt-col-num month-col"><?php echo htmlspecialchars($monthLabelAr($ym), ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php endforeach; ?>
                            <th class="gl-acc-stmt-col-num">الإجمالي</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($tableRows === []): ?>
                            <tr><td colspan="<?php echo count($monthList) + 3; ?>" class="muted">لا توجد حركة في المدّة على حسابات قائمة الدخل المصنّفة (فعِّل «عرض كل الحسابات» لمقارنتها مع الدليل).</td></tr>
                        <?php else: ?>
                            <?php foreach ($tableRows as $tr): ?>
                                <tr>
                                    <td class="gl-acc-stmt-col-num pl-m-num" dir="ltr"><?php echo htmlspecialchars((string) ($tr['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($tr['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php foreach ($monthList as $ym): ?>
                                        <td class="gl-acc-stmt-col-num pl-m-num"><?php echo $fmt((float) ($tr['by_month'][$ym] ?? 0)); ?></td>
                                    <?php endforeach; ?>
                                    <td class="gl-acc-stmt-col-num pl-m-num"><?php echo $fmt((float) ($tr['row_total'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if ($tableRows !== [] && $monthList !== []): ?>
                        <tfoot>
                            <tr class="pl-monthly-foot">
                                <th colspan="2">صافى أثر الفترة (تقريب للعمود)</th>
                                <?php foreach ($monthList as $ym): ?>
                                    <th class="gl-acc-stmt-col-num pl-m-num"><?php echo $fmt((float) ($colTotals[$ym] ?? 0)); ?></th>
                                <?php endforeach; ?>
                                <th class="gl-acc-stmt-col-num pl-m-num"><?php echo $fmt($grandTot); ?></th>
                            </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
        <p class="card-hint gl-acc-stmt-no-print" style="margin-top:12px;margin-bottom:0;">
            مدى التاريخ: <span dir="ltr"><?php echo htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8'); ?></span>
            — عدد الأشهر: <?php echo count($monthList); ?>.
            يُكمِّن على تقرير مفصول لكل شهر مثل المرجع الورقي؛ هذا العرض شبكة واحدة بكل الأشهر عمودًا.
        </p>
    </div>
<?php endif; ?>

</div>
