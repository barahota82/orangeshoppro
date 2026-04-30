<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';

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
$tbBefore = [];
if (
    $useVouchers && $periodLabel !== ''
    && $periodDateFrom !== '' && $periodDateTo !== ''
    && strcmp($periodDateFrom, $periodDateTo) <= 0
) {
    $tbRange = orange_voucher_account_totals_by_voucher_date_range($pdo, $periodDateFrom, $periodDateTo, []);
    $tbBefore = orange_voucher_account_totals_strictly_before_date($pdo, $periodDateFrom, []);
}

$accountsLeaf = orange_financial_report_leaf_accounts_with_mapping($pdo);

/**
 * أسطر المتاجرة: تصنيف الحسابات عبر account_type المحفوظ ثم دور الشجرة؛ تجميع عناوين عبر سطر المرجع حيث وُجد.
 *
 * @return list<array<string, mixed>>
 */
$buildTradingSection = static function (
    PDO $pdo,
    array $accountsLeaf,
    array $tbRange,
    array $tbBefore,
    string $plClass
): array {
    $out = [];
    $hasSec = orange_table_has_column($pdo, 'accounts', 'report_section');
    $legacyHeading = $plClass === 'revenue'
        ? 'إيرادات / مبيعات — تصنيف افتراضي (دورة الحساب في الشجرة عند تعذّر سطر المرجع)'
        : 'تكلفة مبيعات — تصنيف افتراضي (دورة الحساب في الشجرة عند تعذّر سطر المرجع)';

    foreach ($accountsLeaf as $a) {
        $aid = (int) ($a['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $bucket = orange_accounts_pnl_bucket_for_trading_row($pdo, $aid, orange_accounts_map_row_from_leaf_account_row($a));
        if ($bucket !== $plClass) {
            continue;
        }
        if ($hasSec) {
            $sec = orange_accounts_normalize_report_section_value(
                isset($a['report_section']) ? (string) $a['report_section'] : ''
            );
            /*
             * قطاع المتاجرة: يُفترض أن يكون فارغ أو trading أو pnl أو none؛
             * إذا وُسمت إيراد/تكم ب balance_sheet أو cashflow بالخطأ لا نُسقط السطر ما دام دور الشجرة يطابق قسم المتاجرة.
             */
            if ($sec !== '') {
                $treePlRole = orange_accounts_account_pl_role($pdo, $aid);
                $ambiguousPlSec = ['balance_sheet', 'cashflow'];
                if (
                    $treePlRole === $plClass
                    && in_array($sec, $ambiguousPlSec, true)
                ) {
                    /* تجاهل وسم المتاجرة؛ الحساب فعلاً من قطاع المتاجرة حسب الشجرة/السلة. */
                } else {
                    $allowedSec = ['', 'none', 'trading', 'pnl'];
                    if (! in_array($sec, $allowedSec, true)) {
                        continue;
                    }
                }
            }
        }
        $d0 = $c0 = $d1 = $c1 = 0.0;
        if (isset($tbBefore[$aid])) {
            $d0 = (float) $tbBefore[$aid]['debit'];
            $c0 = (float) $tbBefore[$aid]['credit'];
        }
        if (isset($tbRange[$aid])) {
            $d1 = (float) $tbRange[$aid]['debit'];
            $c1 = (float) $tbRange[$aid]['credit'];
        }
        if ($plClass === 'revenue') {
            $open = $c0 - $d0;
            $period = $c1 - $d1;
        } else {
            $open = $d0 - $c0;
            $period = $d1 - $c1;
        }
        $closing = $open + $period;
        if (abs($open) < 0.0001 && abs($period) < 0.0001 && abs($closing) < 0.0001) {
            continue;
        }
        $mappedHeading = trim((string) ($a['report_line_heading_ar'] ?? ''));
        $sortKey = 500000;
        if ($mappedHeading !== '') {
            $sortKey = (int) ($a['report_line_sort'] ?? 0);
        } elseif ((int) ($a['report_line_id'] ?? 0) > 0 && $mappedHeading === '') {
            $mappedHeading = $legacyHeading;
            $sortKey = ($plClass === 'revenue') ? 400000 : 400001;
        } else {
            $mappedHeading = $legacyHeading;
            $sortKey = ($plClass === 'revenue') ? 300000 : 300001;
        }

        $code = trim((string) ($a['code'] ?? ''));
        $nm = (string) ($a['name'] ?? '');
        $out[] = [
            'code' => $code,
            'name' => $nm,
            'opening' => $open,
            'period' => $period,
            'closing' => $closing,
            '_section_heading' => $mappedHeading,
            '_section_sort' => $sortKey,
        ];
    }

    usort($out, static function (array $x, array $y): int {
        $sx = (int) ($x['_section_sort'] ?? 0);
        $sy = (int) ($y['_section_sort'] ?? 0);
        if ($sx !== $sy) {
            return $sx <=> $sy;
        }
        return strcmp((string) ($x['code'] ?? ''), (string) ($y['code'] ?? ''));
    });

    return $out;
};

$revenueLines = $useVouchers ? $buildTradingSection($pdo, $accountsLeaf, $tbRange, $tbBefore, 'revenue') : [];
$cogsLines = $useVouchers ? $buildTradingSection($pdo, $accountsLeaf, $tbRange, $tbBefore, 'cogs') : [];

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

$companyNameAr = '';
if (orange_table_exists($pdo, 'company_settings')) {
    $cs = $pdo->query('SELECT company_name_ar FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cs)) {
        $companyNameAr = trim((string) ($cs['company_name_ar'] ?? ''));
    }
}

$hasTradingData = $revenueLines !== [] || $cogsLines !== [];

$fmt5 = static function (float $v): string {
    return number_format($v, 4);
};

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة حسابات المتاجرة</h1>
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
                    <div class="gas-acc-stmt-actions">
                        <button type="submit">عرض</button>
                        <?php if ($useVouchers && $periodLabel !== ''): ?>
                            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
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
                    <span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة حسابات المتاجرة عن الفترة من <?php echo htmlspecialchars($reportDateFromDmY, ENT_QUOTES, 'UTF-8'); ?> إلـى&nbsp;<?php echo htmlspecialchars($reportDateToDmY, ENT_QUOTES, 'UTF-8'); ?></span>
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
                <table class="admin-fy-table gl-acc-stmt-table ta-report-table ta-report-table--xlsx">
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
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($rl['opening'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($rl['period'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($rl['closing'] ?? 0)); ?></td>
                                </tr>
                            <?php
endforeach;
?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal ta-report-subtotal--sales">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td>اجمالى مبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalRevOpening); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalRevPeriod); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalRevClosing); ?></td>
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
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($cl['opening'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($cl['period'] ?? 0)); ?></td>
                                    <td class="gl-acc-stmt-col-num"><?php echo $fmt5((float) ($cl['closing'] ?? 0)); ?></td>
                                </tr>
                            <?php
endforeach;
?>
                        <?php endif; ?>
                        <tr class="ta-report-subtotal ta-report-subtotal--cogs">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td>اجمالى تكلفة مبيعات</td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalCogsOpening); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalCogsPeriod); ?></td>
                            <td class="gl-acc-stmt-col-num"><?php echo $fmt5($totalCogsClosing); ?></td>
                        </tr>

                        <tr class="ta-report-grand">
                            <td class="gl-acc-stmt-col-num muted">—</td>
                            <td><strong>مجمل ربح</strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $fmt5($grossOpening); ?></strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $fmt5($grossPeriod); ?></strong></td>
                            <td class="gl-acc-stmt-col-num"><strong><?php echo $fmt5($grossClosing); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if (! $hasTradingData): ?>
                <p class="card-hint ta-report-empty-msg" style="margin-top:10px;margin-bottom:0;">لا توجد حركة إيرادات أو تكلفة مبيعات على حسابات فرعية ضمن قطاع المتاجرة في المدى (بحسب تاريخ السند).</p>
            <?php endif; ?>

            <div class="gl-acc-stmt-print-footer ta-report-print-footer">
                <p class="gl-acc-stmt-print-metafoot" dir="ltr">تاريخ ووقت الطباعة: <?php echo htmlspecialchars($printDatetime, ENT_QUOTES, 'UTF-8'); ?> — صفحة 1 من 1</p>
            </div>
        </div>
    </div>
<?php endif; ?>

</div>
