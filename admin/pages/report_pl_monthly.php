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
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
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

/** «شهر     يناير» وفق المرجع */
$monthLabelExcel = static function (string $ym): string {
    static $months = [
        '01' => 'يناير', '02' => 'فبراير', '03' => 'مارس', '04' => 'ابريل',
        '05' => 'مايو', '06' => 'يونيو', '07' => 'يوليو', '08' => 'أغسطس',
        '09' => 'سبتمبر', '10' => 'أكتوبر', '11' => 'نوفمبر', '12' => 'ديسمبر',
    ];
    if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return $ym;
    }

    return 'شهر     ' . ($months[$m[2]] ?? $m[2]);
};

$useVouchers = orange_journal_vouchers_ready($pdo);
$plmJvCountryBind = orange_gl_voucher_country_bind($pdo, 'jv');

$periodYmFrom = '';
$periodYmTo = '';
$periodDateFrom = '';
$periodDateTo = '';
$periodLabel = '';

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

/** عند التفعيل: استبعاد سندات entry_type = year_end_close من حركة الفترة. */
$ignoreClosingEntries = !isset($_GET['ignore_close']) || (string) $_GET['ignore_close'] === '1';

$leafWhere = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$accountsLeaf = orange_accounts_fetch(
    $pdo,
    "SELECT a.id, a.name, a.code FROM accounts a WHERE $leafWhere ORDER BY COALESCE(a.code, ''), a.name",
    [],
    'a'
);

$leafIdsPl = [];
foreach ($accountsLeaf as $al) {
    $lid = (int) ($al['id'] ?? 0);
    if ($lid > 0) {
        $leafIdsPl[] = $lid;
    }
}
$mapLeafPl = orange_accounts_report_mapping_by_ids($pdo, $leafIdsPl);

$revAccounts = [];
$outAccounts = [];

foreach ($accountsLeaf as $a) {
    $aid = (int) ($a['id'] ?? 0);
    if ($aid <= 0) {
        continue;
    }
    $bucket = orange_accounts_pnl_bucket_for_report($pdo, $aid, $mapLeafPl[$aid] ?? null);
    if ($bucket === 'revenue') {
        $revAccounts[] = [
            'id' => $aid,
            'code' => trim((string) ($a['code'] ?? '')),
            'name' => (string) ($a['name'] ?? ''),
        ];
    } elseif ($bucket === 'cogs' || $bucket === 'expense') {
        $outAccounts[] = [
            'id' => $aid,
            'code' => trim((string) ($a['code'] ?? '')),
            'name' => (string) ($a['name'] ?? ''),
        ];
    }
}

$signedNature = static function (string $role, float $deb, float $cred): float {
    if ($role === 'revenue') {
        return round($cred - $deb, 4);
    }

    return round($deb - $cred, 4);
};

/** aid => ym => dc */
$dcByAccountYm = [];

/** @var array<string, string> */
$idRoleMap = [];

if (
    $useVouchers
    && $periodLabel !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
    && $monthList !== []
    && ($revAccounts !== [] || $outAccounts !== [])
) {
    $ids = [];
    foreach (array_merge($revAccounts, $outAccounts) as $pa) {
        $ids[] = (int) $pa['id'];
    }
    $ids = array_values(array_unique($ids));

    foreach ($ids as $ida) {
        if ($ida > 0) {
            $idRoleMap[(string) $ida] = orange_accounts_pnl_bucket_for_report($pdo, $ida, $mapLeafPl[$ida] ?? null);
        }
    }

    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $entryTypeExcludeSql = $ignoreClosingEntries ? " AND jv.entry_type NOT IN ('year_end_close')" : '';
        $sql = "SELECT jl.account_id,
                DATE_FORMAT(jv.voucher_date, '%Y-%m') AS ym,
                COALESCE(SUM(jl.debit), 0) AS d,
                COALESCE(SUM(jl.credit), 0) AS c
             FROM journal_lines jl
             INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
             WHERE jl.account_id IN ($ph)
               AND DATE(jv.voucher_date) >= ?
               AND DATE(jv.voucher_date) <= ?" . $entryTypeExcludeSql . $plmJvCountryBind['sql'] . '
             GROUP BY jl.account_id, ym';
        $params = array_merge($ids, [$periodDateFrom, $periodDateTo], $plmJvCountryBind['params']);
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
}

/**
 * @return array{rev: list, out: list, totals: array{rabeh: float, sum_rev: float, sum_out: float, profit_check: float}}
 */
$buildMonthSheet = static function (
    string $ym,
    array $revAccounts,
    array $outAccounts,
    array $dcByAccountYm,
    array $idRoleMapParam,
    bool $showZeros,
    callable $signedNature
): array {
    $left = [];
    $right = [];

    foreach ($revAccounts as $pa) {
        $aid = (int) $pa['id'];
        $dc = $dcByAccountYm[$aid][$ym] ?? ['d' => 0.0, 'c' => 0.0];
        $d = (float) $dc['d'];
        $c = (float) $dc['c'];
        $net = $signedNature('revenue', $d, $c);
        if (! $showZeros && abs($d) < 0.0001 && abs($c) < 0.0001) {
            continue;
        }
        $left[] = ['code' => (string) $pa['code'], 'name' => (string) $pa['name'], 'cell' => $net];
    }

    foreach ($outAccounts as $pa) {
        $aid = (int) $pa['id'];
        $roleMap = $idRoleMapParam[(string) $aid] ?? 'expense';
        $dc = $dcByAccountYm[$aid][$ym] ?? ['d' => 0.0, 'c' => 0.0];
        $d = (float) $dc['d'];
        $c = (float) $dc['c'];
        $role = ($roleMap === 'cogs') ? 'cogs' : 'expense';
        $net = $signedNature($role, $d, $c);
        if (! $showZeros && abs($d) < 0.0001 && abs($c) < 0.0001) {
            continue;
        }
        $right[] = ['code' => (string) $pa['code'], 'name' => (string) $pa['name'], 'cell' => $net];
    }

    $sumRev = 0.0;
    foreach ($left as $x) {
        $sumRev += (float) $x['cell'];
    }
    $sumOut = 0.0;
    foreach ($right as $x) {
        $sumOut += (float) $x['cell'];
    }
    $allNet = $sumRev - $sumOut;

    $profitCheck = 0.0;
    foreach (array_merge($revAccounts, $outAccounts) as $pa) {
        $aid = (int) $pa['id'];
        $dc = $dcByAccountYm[$aid][$ym] ?? ['d' => 0.0, 'c' => 0.0];
        $r = $idRoleMapParam[(string) $aid] ?? 'expense';
        $roleEff = ($r === 'revenue') ? 'revenue' : (($r === 'cogs') ? 'cogs' : 'expense');
        if (! isset($dcByAccountYm[$aid][$ym]) && ! $showZeros) {
            continue;
        }
        $profitCheck += $signedNature(
            $roleEff,
            (float) $dc['d'],
            (float) $dc['c']
        );
    }

    return [
        'rev' => $left,
        'out' => $right,
        'totals' => [
            'rabeh' => round($allNet, 4),
            'sum_rev' => round($sumRev, 4),
            'sum_out' => round($sumOut, 4),
            'profit_check' => round($profitCheck, 4),
        ],
    ];
};

$monthSheetsBuilt = [];

if (
    $useVouchers
    && $periodLabel !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
    && $monthList !== []
    && ($revAccounts !== [] || $outAccounts !== [])
) {
    foreach ($monthList as $ym) {
        $built = $buildMonthSheet(
            $ym,
            $revAccounts,
            $outAccounts,
            $dcByAccountYm,
            $idRoleMap,
            $showZeros,
            static fn (string $role, float $d, float $cr): float => $signedNature($role, $d, $cr)
        );

        $df = $firstDayOfYm($ym);
        $dt = $lastDayOfYm($ym);
        $monthSheetsBuilt[] = array_merge([
            'ym' => $ym,
            'date_from' => $df,
            'date_to' => $dt,
        ], $built);
    }
}

$reportTitleLine = static function (string $dfDmY, string $dtDmY): string {
    return 'قائمة ايرادات ومصروفات شهرية عن الفترة   من   ' . $dfDmY . ' إلـى  ' . $dtDmY;
};
$subtitleLine = static fn (string $dfDmY, string $dtDmY): string => 'عن الفترة  من   ' . $dfDmY . ' إلـى  ' . $dtDmY;

$todayDmY = orange_format_date_dmY(date('Y-m-d'));
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));

$companyNameAr = orange_company_settings_name_ar($pdo);

$reportFmt = static fn (float $v): string => orange_accounting_report_format_amount($v, $reportMoney);

$monthSheetsLastIdx = count($monthSheetsBuilt) > 0 ? count($monthSheetsBuilt) - 1 : 0;

/* تصدير Excel مخصّص: لكل شهر رأسه + الإيرادات بجانب المصروفات (يطابق الشاشة). */
if (isset($_GET['export']) && (string) $_GET['export'] === 'xls' && $useVouchers && $periodLabel !== '') {
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    $plName = 'قائمة الإيرادات والمصروفات الشهرية-' . date('Y-m-d');
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $plName . '.xls"');
    echo "\xEF\xBB\xBF";
    $plE = static fn ($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" '
        . 'xmlns="http://www.w3.org/TR/REC-html40"><head><meta charset="utf-8">'
        . '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>'
        . '<x:Name>إيرادات ومصروفات</x:Name><x:WorksheetOptions><x:DisplayRightToLeft/>'
        . '</x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->'
        . '<style>table{border-collapse:collapse;}td,th{border:0.5pt solid #999999;padding:3px 7px;white-space:nowrap;}'
        . 'th{background:#e8eef5;font-weight:bold;text-align:center;}.num{mso-number-format:"#,##0.###";}</style></head><body dir="rtl">';
    foreach ($monthSheetsBuilt as $ms) {
        $ymMs = (string) $ms['ym'];
        $dmFrom = orange_format_date_dmY((string) $ms['date_from']);
        $dmTo = orange_format_date_dmY((string) $ms['date_to']);
        $tot = $ms['totals'];
        $lr = $ms['rev'] ?? [];
        $rr = $ms['out'] ?? [];
        preg_match('/^(\d{4})-/u', $ymMs, $ymY);
        $yearStr = $ymY[1] ?? '';
        if ($companyNameAr !== '') {
            echo '<div style="font-size:14pt;font-weight:bold;">' . $plE($companyNameAr) . '</div>';
        }
        echo '<div style="font-size:13pt;font-weight:bold;">' . $plE($reportTitleLine($dmFrom, $dmTo)) . '</div>';
        echo '<div style="font-size:11pt;">' . $plE($subtitleLine($dmFrom, $dmTo)) . '</div>';
        echo '<div style="font-size:11pt;">' . $plE($monthLabelExcel($ymMs)) . ($yearStr !== '' ? ' — السنة ' . $plE($yearStr) : '')
            . ' — ربح: ' . $plE($reportFmt((float) ($tot['rabeh'] ?? 0))) . '</div><br>';
        echo '<table><thead><tr><th colspan="3">ايرادات</th><th colspan="3">مصروفات</th></tr>'
            . '<tr><th>الرصيد</th><th>اسم الحساب</th><th>كود الحساب</th>'
            . '<th>الرصيد</th><th>اسم الحساب</th><th>كود الحساب</th></tr></thead><tbody>';
        $plRows = max(count($lr), count($rr), 1);
        for ($i = 0; $i < $plRows; $i++) {
            $lv = $lr[$i] ?? null;
            $rv = $rr[$i] ?? null;
            echo '<tr>';
            if ($lv !== null) {
                echo '<td class="num">' . $plE((float) $lv['cell']) . '</td><td>' . $plE((string) $lv['name']) . '</td><td>' . $plE((string) $lv['code']) . '</td>';
            } else {
                echo '<td></td><td></td><td></td>';
            }
            if ($rv !== null) {
                echo '<td class="num">' . $plE((float) $rv['cell']) . '</td><td>' . $plE((string) $rv['name']) . '</td><td>' . $plE((string) $rv['code']) . '</td>';
            } else {
                echo '<td></td><td></td><td></td>';
            }
            echo '</tr>';
        }
        echo '</tbody><tfoot><tr><td class="num">' . $plE((float) ($tot['sum_rev'] ?? 0)) . '</td><th colspan="2">إجمالي الإيرادات</th>'
            . '<td class="num">' . $plE((float) ($tot['sum_out'] ?? 0)) . '</td><th colspan="2">إجمالي المصروفات</th></tr></tfoot></table><br><br>';
    }
    echo '</body></html>';
    exit;
}

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة إيرادات ومصروفات شهرية</h1>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print gas-acc-stmt-search-card">
        <form method="get" class="gas-acc-stmt-filter-form" id="pl_m_form">
            <input type="hidden" name="page" value="report_pl_monthly">
            <div class="gas-acc-stmt-toolbar-wrap">
                <div class="gas-acc-stmt-toolbar ta-report-toolbar ta-report-toolbar--pl-monthly-filters gas-acc-stmt-toolbar--main-center">
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="pl_m_month_from">من شهر</label>
                        <input type="month" name="m_from" id="pl_m_month_from" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmFrom, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <div class="gas-acc-stmt-field gl-m-stmt-field--month">
                        <label for="pl_m_month_to">إلى شهر</label>
                        <input type="month" name="m_to" id="pl_m_month_to" class="admin-inp"
                            lang="en" dir="ltr"
                            value="<?php echo htmlspecialchars($periodYmTo, ENT_QUOTES, 'UTF-8'); ?>"
                            min="<?php echo htmlspecialchars($calYmMinBound, ENT_QUOTES, 'UTF-8'); ?>"
                            max="<?php echo htmlspecialchars($calYmMaxBound, ENT_QUOTES, 'UTF-8'); ?>"
                            title="انقر الحقل؛ في منتقي المتصفّح انقر سنة الشهر أو استخدم الأسهم لتغيير السنة (2000–2100)."
                            autocomplete="off">
                    </div>
                    <label class="gas-acc-stmt-field pl-monthly-show-zeros-field" style="align-items:flex-start;margin-top:0.15rem;">
                        <input type="checkbox" name="show_zeros" value="1" <?php echo $showZeros ? 'checked' : ''; ?> style="margin-top:6px;margin-left:6px;">
                        <span>عرض كل الحسابات حتى بدون حركة في ذلك الشهر</span>
                    </label>
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $periodLabel !== ''): ?>
                            <?php
                            $plXlsQ = $_GET;
                            $plXlsQ['page'] = 'report_pl_monthly';
                            $plXlsQ['export'] = 'xls';
                            $plXlsHref = storefront_public_path('/admin/index.php') . '?' . http_build_query($plXlsQ);
                            ?>
                            <a class="btn-secondary" href="<?php echo htmlspecialchars($plXlsHref, ENT_QUOTES, 'UTF-8'); ?>">تصدير Excel</a>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                    <label class="gas-acc-stmt-field is-ignore-close-field" title="قيود الإقفال السنوي (YEC) تُصفّر الإيرادات والمصروفات — فعِّل هذا الخيار لاستبعادها من أرقام التقرير إذا كان المدى الزمني يشمل تاريخ الإقفال.">
                        <input type="hidden" name="ignore_close" value="0">
                        <input type="checkbox" name="ignore_close" value="1" id="pl_m_ignore_close" <?php echo $ignoreClosingEntries ? 'checked' : ''; ?>>
                        <span>تجاهل قيود الإقفال</span>
                    </label>
                </div>
            </div>
        </form>
    </div>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card"><p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض القائمة.</p></div>
<?php elseif ($periodLabel === ''): ?>
    <div class="card admin-fy-card"><p class="muted">تعذّر تحديد مدى التقويم.</p></div>
<?php elseif ($revAccounts === [] && $outAccounts === []): ?>
    <div class="card admin-fy-card<?php echo $accountsLeaf === [] ? ' gl-acc-stmt-no-print' : ''; ?>" <?php echo $accountsLeaf === [] ? 'style="border:1px solid #fcd34d;background:#fffbeb;"' : ''; ?>>
        <p class="muted" style="margin:0;line-height:1.55;">
            <?php if ($accountsLeaf === []): ?>
                <strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ القائمة تظهر بدون أسطر حساب إلى أن تُضاف أوراق في «الدليل المحاسبي». <strong>الشاشة والنموذج يعملان</strong> — متوقَّع أثناء الإعداد الأول.
            <?php else: ?>
                لا توجد حسابات فرعية تصنَّف وفق دليل الخريطة أو جذر الشجرة كإيراد أو تكلفة مبيعات/مصروف.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <?php foreach ($monthSheetsBuilt as $si => $ms): ?>
        <?php
        /** @var string $ymMs */
        $ymMs = (string) $ms['ym'];
        $dfMs = (string) $ms['date_from'];
        $dtMs = (string) $ms['date_to'];
        $dmFrom = orange_format_date_dmY($dfMs);
        $dmTo = orange_format_date_dmY($dtMs);

        /** @phpstan-ignore-next-line */
        $tot = $ms['totals'];
        /** @phpstan-ignore-next-line */
        $lr = $ms['rev'] ?? [];
        /** @phpstan-ignore-next-line */
        $rr = $ms['out'] ?? [];
        /** @phpstan-ignore-next-line */
        $rabeh = (float) ($tot['rabeh'] ?? 0);

        $sheetRows = max(count($lr), count($rr), 1);
        $monthLabelEscaped = htmlspecialchars($monthLabelExcel($ymMs), ENT_QUOTES, 'UTF-8');
        preg_match('/^(\d{4})-/u', $ymMs, $ymYearM);
        $yearStr = htmlspecialchars($ymYearM[1] ?? '', ENT_QUOTES, 'UTF-8');

        $isLastSheet = ($si === $monthSheetsLastIdx);
        $pageCls = $isLastSheet ? ' pl-month-print-page pl-month-print-page--last' : ' pl-month-print-page';
        ?>
    <section class="card admin-fy-card pl-month-print-inner gl-acc-stmt-print pl-month-sheet<?php echo $pageCls; ?>">
        <header class="pl-month-print-banner gl-acc-stmt-print-banner">
            <?php if ($companyNameAr !== ''): ?>
                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <h2 class="pl-month-print-title gl-acc-stmt-print-title">
                <span class="gl-acc-stmt-print-title-ar"><?php echo htmlspecialchars($reportTitleLine($dmFrom, $dmTo), ENT_QUOTES, 'UTF-8'); ?></span>
            </h2>
            <p class="pl-month-pl-profit" lang="ar">ربح &nbsp;&nbsp;<?php echo htmlspecialchars($reportFmt($rabeh), ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="pl-month-subtitle" lang="ar"><?php echo htmlspecialchars($subtitleLine($dmFrom, $dmTo), ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="pl-month-meta-row" lang="ar">
                <span><?php echo $monthLabelEscaped; ?></span>
                <?php if ($yearStr !== ''): ?>
                    <span>السنة &nbsp;&nbsp;<?php echo $yearStr; ?></span>
                <?php endif; ?>
            </div>
        </header>

        <div class="pl-month-single-table-wrap">
            <div class="pl-print-dual-wrap">
                <div class="pl-print-dual-col">
                    <h3 class="pl-print-dual-h" lang="ar">ايرادات</h3>
                    <div class="admin-fy-table-wrap pl-print-side-table-wrap">
                        <table class="admin-fy-table pl-month-table">
                            <thead>
                                <tr>
                                    <th class="gl-acc-stmt-col-num">الرصيد</th>
                                    <th>اســم الحساب</th>
                                    <th>كــود الحساب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($ri = 0; $ri < $sheetRows; $ri++): ?>
                                    <?php
                                    $lv = $lr[$ri] ?? null;
                                    ?>
                                    <tr>
                                        <?php if ($lv !== null): ?>
                                            <td class="gl-acc-stmt-col-num pl-m-num"><?php echo htmlspecialchars($reportFmt((float) $lv['cell']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) $lv['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td dir="ltr" class="tb-col-code"><?php echo htmlspecialchars((string) $lv['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <?php else: ?>
                                            <td class="gl-acc-stmt-col-num pl-m-num">—</td>
                                            <td></td>
                                            <td dir="ltr" class="tb-col-code"></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                            <tfoot>
                                <tr class="pl-month-tfoot-totals">
                                    <?php /** @phpstan-ignore-next-line */ ?>
                                    <td class="gl-acc-stmt-col-num pl-m-num"><?php echo htmlspecialchars($reportFmt((float) $tot['sum_rev']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <th colspan="2" style="text-align:center;">الإجمالي</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <div class="pl-print-dual-col">
                    <h3 class="pl-print-dual-h" lang="ar">مصروفات</h3>
                    <div class="admin-fy-table-wrap pl-print-side-table-wrap">
                        <table class="admin-fy-table pl-month-table">
                            <thead>
                                <tr>
                                    <th class="gl-acc-stmt-col-num">الرصيد</th>
                                    <th>اســم الحساب</th>
                                    <th>كــود الحساب</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php for ($ri = 0; $ri < $sheetRows; $ri++): ?>
                                    <?php
                                    $rv = $rr[$ri] ?? null;
                                    ?>
                                    <tr>
                                        <?php if ($rv !== null): ?>
                                            <td class="gl-acc-stmt-col-num pl-m-num"><?php echo htmlspecialchars($reportFmt((float) $rv['cell']), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars((string) $rv['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td dir="ltr" class="tb-col-code"><?php echo htmlspecialchars((string) $rv['code'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <?php else: ?>
                                            <td class="gl-acc-stmt-col-num pl-m-num">—</td>
                                            <td></td>
                                            <td dir="ltr" class="tb-col-code"></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endfor; ?>
                            </tbody>
                            <tfoot>
                                <tr class="pl-month-tfoot-totals">
                                    <?php /** @phpstan-ignore-next-line */ ?>
                                    <td class="gl-acc-stmt-col-num pl-m-num"><?php echo htmlspecialchars($reportFmt((float) $tot['sum_out']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <th colspan="2" style="text-align:center;">الإجمالي</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <footer class="pl-month-print-footer muted" style="margin-top:0.75rem;font-size:0.85rem;text-align:center;">
            تاريخ الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?>
        </footer>
    </section>
    <?php endforeach; ?>
<?php endif; ?>

</div>
