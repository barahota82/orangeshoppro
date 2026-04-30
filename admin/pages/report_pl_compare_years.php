<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/accounting_report_mapping.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = orange_fiscal_years_list($pdo);

$accounts = $pdo->query('SELECT id FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
$ids = [];
foreach ($accounts as $a) {
    $ids[] = (int) ($a['id'] ?? 0);
}
$mapById = orange_accounts_report_mapping_by_ids($pdo, $ids);

$leafWhereCmp = orange_accounts_posting_leaf_where_sql($pdo, 'a');
$cmpPostingLeafCt = 0;
try {
    $cmpPostingLeafCt = (int) $pdo->query("SELECT COUNT(*) FROM accounts a WHERE $leafWhereCmp")->fetchColumn();
} catch (Throwable $e) {
    $cmpPostingLeafCt = 0;
}

$useVouchers = orange_journal_vouchers_ready($pdo);

$submitted = isset($_GET['cmp']) && (string) $_GET['cmp'] === '1';

$selectedFyIds = [];
if (isset($_GET['fy']) && is_array($_GET['fy'])) {
    foreach ($_GET['fy'] as $raw) {
        $fid = (int) $raw;
        if ($fid > 0) {
            $selectedFyIds[] = $fid;
        }
    }
} elseif (isset($_GET['fy'])) {
    $one = (int) $_GET['fy'];
    if ($one > 0) {
        $selectedFyIds[] = $one;
    }
}
$selectedFyIds = array_values(array_unique($selectedFyIds));

/** @var list<int> $allowedIds */
$allowedIds = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $years);
$allowedIds = array_values(array_unique(array_filter($allowedIds, static fn (int $x): bool => $x > 0)));

$selectedFyIds = array_values(array_intersect($selectedFyIds, $allowedIds));

if ($selectedFyIds === [] && $years !== [] && ! $submitted) {
    foreach (array_slice($years, 0, min(4, count($years))) as $row) {
        $selectedFyIds[] = (int) $row['id'];
    }
}

$fyRowsById = [];
foreach ($years as $yr) {
    $iy = (int) ($yr['id'] ?? 0);
    if ($iy > 0) {
        $fyRowsById[$iy] = $yr;
    }
}

$rowSummaries = [];
if ($useVouchers && $selectedFyIds !== []) {
    foreach ($selectedFyIds as $fyId) {
        if (! isset($fyRowsById[$fyId])) {
            continue;
        }
        $sum = orange_accounts_fy_pl_summary_from_vouchers($pdo, $fyId, $mapById);
        $sum['fy_id'] = $fyId;
        $fyRow = $fyRowsById[$fyId];
        $lbl = trim((string) ($fyRow['label_ar'] ?? ''));
        if ($lbl === '') {
            $lbl = 'سنة #' . $fyId;
        }
        $sum['label_ar'] = $lbl;
        $sum['period'] = trim((string) ($fyRow['start_date'] ?? '')) . ' — ' . trim((string) ($fyRow['end_date'] ?? ''));
        $sum['is_closed'] = (int) ($fyRow['is_closed'] ?? 0) === 1;
        $rowSummaries[] = $sum;
    }
}

$companyNameAr = '';
if (orange_table_exists($pdo, 'company_settings')) {
    $cs = $pdo->query('SELECT company_name_ar FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (is_array($cs)) {
        $companyNameAr = trim((string) ($cs['company_name_ar'] ?? ''));
    }
}

$todayDmY = orange_format_date_dmY(date('Y-m-d'));

?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">مقارنة أرباح وخسائر بين السنوات المالية</h1>
        <p class="admin-fy-shell__lead" style="margin-top:8px;line-height:1.55;">
            صافي الدخل وفق حقول الدليل (<code dir="ltr">account_type</code> وسقوط إلى دور الشجرة عند الغياب) —
            بدون قيود افتتاح أو إقفال سنة؛ نفس المنطق الموجود في ملخص التقارير المالية.
        </p>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-no-print">
        <form method="get" action="" id="cmp_pl_form">
            <input type="hidden" name="page" value="report_pl_compare_years">
            <input type="hidden" name="cmp" value="1">
            <p class="card-hint" style="margin:0 0 10px;">اختر واحدة أو أكثر من السنوات المعرّفة:</p>
            <?php if ($years === []): ?>
                <p class="muted">لا توجد سنوات مالية. عرِّفها من <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=fiscal_years'), ENT_QUOTES, 'UTF-8'); ?>">السنوات المالية</a>.</p>
            <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:14px;margin-bottom:12px;">
                    <?php foreach ($years as $yr): ?>
                        <?php
                        $yid = (int) ($yr['id'] ?? 0);
                        if ($yid <= 0) {
                            continue;
                        }
                        $yl = trim((string) ($yr['label_ar'] ?? ''));
                        if ($yl === '') {
                            $yl = '#' . $yid;
                        }
                        $chk = in_array($yid, $selectedFyIds, true);
                        ?>
                        <label style="display:inline-flex;align-items:center;gap:8px;font-weight:normal;">
                            <input type="checkbox" name="fy[]" value="<?php echo $yid; ?>" <?php echo $chk ? 'checked' : ''; ?>>
                            <span><?php echo htmlspecialchars($yl . ' (' . ($yr['start_date'] ?? '') . ' — ' . ($yr['end_date'] ?? '') . ')', ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="gas-acc-stmt-actions">
                    <button type="submit">عرض المقارنة</button>
                    <?php if ($useVouchers && $rowSummaries !== []): ?>
                        <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

<?php if ($useVouchers && $cmpPostingLeafCt === 0 && $years !== []): ?>
    <div class="card admin-fy-card gl-acc-stmt-no-print" style="border:1px solid #fcd34d;background:#fffbeb;">
        <p class="muted" style="margin:0;line-height:1.55;"><strong>تنبيه:</strong> لا توجد حسابات ترحيل (أوراق) في الدليل بعد؛ المقارنة تعتمد على القيود المرتبطة بالحسابات المؤهَّلة؛ قد تظهر أصفار أو لا نتيجة تشخيصية حتى إكمال الدليل. <strong>النموذج يعمل</strong>.</p>
    </div>
<?php endif; ?>

<?php if (! $useVouchers): ?>
    <div class="card admin-fy-card">
        <p class="muted">سندات اليومية غير جاهزة — لا يمكن حساب قائمة الدخل.</p>
    </div>
<?php elseif ($rowSummaries === []): ?>
    <div class="card admin-fy-card">
        <p class="muted">
            <?php
            if ($years === []) {
                echo 'لا توجد سنوات مالية في النظام.';
            } elseif ($submitted && $selectedFyIds === []) {
                echo 'لم تُحدَّد أي سنة — اختر سنة واحدة على الأقل وأعد العرض.';
            } else {
                echo 'لا توجد نتيجة للعرض بعد — تحقق من السنوات المختارة أو من جاهزية القيود.';
            }
            ?>
        </p>
    </div>
<?php else: ?>
    <div class="card admin-fy-card gl-acc-stmt-print">
        <?php if ($companyNameAr !== ''): ?>
            <p class="muted" style="margin:0 0 8px;"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <h3 class="card-title">ملخص بحسب سنة مالية</h3>
        <div class="table-wrap admin-fy-table-wrap">
            <table class="admin-fy-table">
                <thead>
                    <tr>
                        <th>السنة المالية</th>
                        <th>المدى</th>
                        <th class="gl-acc-stmt-col-num">إيرادات (طبيعة دائنة)</th>
                        <th class="gl-acc-stmt-col-num">تكلفة ومصروف</th>
                        <th class="gl-acc-stmt-col-num">صافي الدخل التقريبي</th>
                        <th>الحالة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rowSummaries as $rs): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($rs['label_ar'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><small><?php echo htmlspecialchars((string) ($rs['period'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></small></td>
                            <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo number_format((float) ($rs['revenue'] ?? 0), 2); ?></td>
                            <td class="gl-acc-stmt-col-num" dir="ltr"><?php echo number_format((float) ($rs['cogs_expense'] ?? 0), 2); ?></td>
                            <td class="gl-acc-stmt-col-num" dir="ltr"><strong><?php echo number_format((float) ($rs['net'] ?? 0), 2); ?></strong></td>
                            <td><?php echo ! empty($rs['is_closed']) ? 'مغلقة' : 'مفتوحة'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($todayDmY !== ''): ?>
            <p class="muted" style="margin-top:10px;font-size:0.9rem;" dir="ltr">طباعة: <?php echo htmlspecialchars($todayDmY, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

</div>
