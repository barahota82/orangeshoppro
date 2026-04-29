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
$showZeros = isset($_GET['show_zeros']) && (string) $_GET['show_zeros'] === '1';

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

/** account_id => ym => ['d'=>,'c'=>] */
$dcByAccountYm = [];

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
        if (! isset($dcByAccountYm[$aid])) {
            $dcByAccountYm[$aid] = [];
        }
        $dcByAccountYm[$aid][$ym] = [
            'd' => (float) ($rw['d'] ?? 0),
            'c' => (float) ($rw['c'] ?? 0),
        ];
    }
}

/** @return list<array{ym:string,date_from:string,date_to:string,rows:list,sum_d:float,sum_c:float,sum_net:float}> */
$monthSheets = [];
foreach ($monthList as $ym) {
    $df = $firstDayOfYm($ym);
    $dt = $lastDayOfYm($ym);
    $rows = [];
    foreach ($plAccounts as $pa) {
        $aid = (int) $pa['id'];
        $role = (string) $pa['role'];
        $dc = $dcByAccountYm[$aid][$ym] ?? ['d' => 0.0, 'c' => 0.0];
        $d = (float) $dc['d'];
        $c = (float) $dc['c'];
        $net = $signedNature($role, $d, $c);
        $hasMovement = abs($d) >= 0.0001 || abs($c) >= 0.0001;
        if (! $showZeros && ! $hasMovement) {
            continue;
        }
        $rows[] = [
            'code' => (string) $pa['code'],
            'name' => (string) $pa['name'],
            'debit' => round($d, 4),
            'credit' => round($c, 4),
            'net' => $net,
        ];
    }
    $sumD = 0.0;
    $sumC = 0.0;
    $sumNet = 0.0;
    foreach ($rows as $r) {
        $sumD += (float) $r['debit'];
        $sumC += (float) $r['credit'];
        $sumNet += (float) $r['net'];
    }
    $monthSheets[] = [
        'ym' => $ym,
        'date_from' => $df,
        'date_to' => $dt,
        'rows' => $rows,
        'sum_d' => round($sumD, 4),
        'sum_c' => round($sumC, 4),
        'sum_net' => round($sumNet, 4),
    ];
}

$reportRangeFromDmY = orange_format_date_dmY($periodDateFrom);
$reportRangeToDmY = orange_format_date_dmY($periodDateTo);
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
                            title="المدّة تشمل عدّة تقارير—كل شهر في صفحة طباعة منفردة؛ حسب تاريخ السند."
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
                        <span>عرض كل الحسابات حتى بدون حركة في ذلك الشهر</span>
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
            شكل كالملف المحاسبي المرجعي: <strong>تقرير لكل شهر وحده</strong> ضمن مدى الشهر الأول/الأخير الذي اخترته؛ عند الطباعة تُفصل الأشهر <strong>صفحة بصفحة</strong> بحيث لا تُكمَّن الأشهر كأعمدة عرضية والصف يبقى سهل القراءة والطباعة.
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

    <?php foreach ($monthSheets as $si => $sheet): ?>
        <?php
        $ym = (string) $sheet['ym'];
        $df = (string) $sheet['date_from'];
        $dt = (string) $sheet['date_to'];
        $rows = $sheet['rows'];
        $sheetTitleYm = htmlspecialchars($monthLabelAr($ym), ENT_QUOTES, 'UTF-8');
        $fromDm = orange_format_date_dmY($df);
        $toDm = orange_format_date_dmY($dt);
        ?>
        <section class="pl-month-print-page <?php echo $si === count($monthSheets) - 1 ? 'pl-month-print-page--last' : ''; ?>" dir="rtl">
            <div class="card admin-fy-card gl-acc-stmt-print pl-month-print-inner">
                <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
                    <header class="gl-acc-stmt-print-banner">
                        <?php if ($companyNameAr !== ''): ?>
                            <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <h2 class="gl-acc-stmt-print-title ta-report-print-title pl-month-print-title">
                            <span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة إيرادات ومصروفات شهرية — <?php echo $sheetTitleYm; ?></span>
                        </h2>
                        <p class="gl-acc-stmt-print-subtitle pl-month-subtitle" lang="ar">عن&nbsp;الشهر&nbsp;من&nbsp;<?php echo htmlspecialchars($fromDm, ENT_QUOTES, 'UTF-8'); ?>&nbsp;إلـى&nbsp;<?php echo htmlspecialchars($toDm, ENT_QUOTES, 'UTF-8'); ?></p>
                        <p class="pl-month-range-muted muted gl-acc-stmt-no-print" style="margin:0.35rem 0 0;text-align:center;font-size:0.9rem;">
                            إن شملت قائمة الواجهة أكثر من شهر، يظهر هنا جزء ذلك الشهر فقط؛ المدّة الكاملة: <span dir="ltr"><?php echo htmlspecialchars($reportRangeFromDmY, ENT_QUOTES, 'UTF-8'); ?></span> — <span dir="ltr"><?php echo htmlspecialchars($reportRangeToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                        </p>
                    </header>
                    <div class="gl-acc-stmt-print-grid">
                        <div class="gl-acc-stmt-print-row gl-acc-stmt-print-row--dates">
                            <span class="gl-acc-stmt-print-k">تاريخ الكشف</span>
                            <span class="gl-acc-stmt-print-v" dir="ltr"><?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>

                    <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap pl-month-single-table-wrap ta-report-table-scroll">
                        <table class="admin-fy-table gl-acc-stmt-table ta-report-table ta-report-table--xlsx pl-month-table">
                            <thead>
                                <tr>
                                    <th class="gl-acc-stmt-col-num">كــود الحســاب</th>
                                    <th>اســــــم الحســــــاب</th>
                                    <th class="gl-acc-stmt-col-num">مدين</th>
                                    <th class="gl-acc-stmt-col-num">دائن</th>
                                    <th class="gl-acc-stmt-col-num">صافي القائمة</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($rows === []): ?>
                                    <tr><td colspan="5" class="muted">لا حركة في هذا الشهر على حسابات قائمة الدخل (فعّل «عرض كل الحسابات» إن احتجت الصفوف الصفرية).</td></tr>
                                <?php else: ?>
                                    <?php foreach ($rows as $r): ?>
                                        <tr>
                                            <td class="gl-acc-stmt-col-num pl-m-num" dir="ltr"><?php echo htmlspecialchars((string) $r['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) $r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="gl-acc-stmt-col-num"><?php echo $fmt((float) $r['debit']); ?></td>
                                            <td class="gl-acc-stmt-col-num"><?php echo $fmt((float) $r['credit']); ?></td>
                                            <td class="gl-acc-stmt-col-num"><?php echo $fmt((float) $r['net']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if ($rows !== []): ?>
                                <tfoot>
                                    <tr class="pl-month-tfoot-totals">
                                        <th colspan="2">الإجمالي</th>
                                        <th class="gl-acc-stmt-col-num"><?php echo $fmt((float) $sheet['sum_d']); ?></th>
                                        <th class="gl-acc-stmt-col-num"><?php echo $fmt((float) $sheet['sum_c']); ?></th>
                                        <th class="gl-acc-stmt-col-num"><?php echo $fmt((float) $sheet['sum_net']); ?></th>
                                    </tr>
                                </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                        <p class="gl-acc-stmt-print-metafoot" dir="ltr">طباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة شهر <?php echo $sheetTitleYm; ?> ( <?php echo (int) $si + 1; ?> من <?php echo count($monthSheets); ?>)</p>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>

<?php endif; ?>

</div>
