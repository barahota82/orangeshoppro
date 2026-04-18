<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int)$_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int)$years[0]['id'];
}

$fyRow = null;
foreach ($years as $y) {
    if ((int)$y['id'] === $fyId) {
        $fyRow = $y;
        break;
    }
}

$accounts = $pdo->query('SELECT id, name, code, account_class FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
$classById = [];
foreach ($accounts as $a) {
    $classById[(int)$a['id']] = strtolower(trim((string)($a['account_class'] ?? 'unclassified')));
}

$useVouchers = orange_journal_vouchers_ready($pdo);

$tbAll = [];
$sumDebit = 0.0;
$sumCredit = 0.0;
$rows = [];

if ($useVouchers && $fyId > 0) {
    $tbAll = orange_voucher_account_totals($pdo, $fyId, []);
    foreach ($accounts as $a) {
        $aid = (int)$a['id'];
        $t = $tbAll[$aid] ?? ['debit' => 0.0, 'credit' => 0.0];
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($deb > 0.0001 || $cred > 0.0001) {
            $label = (trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
            $rows[] = ['label' => $label, 'debit' => $deb, 'credit' => $cred, 'net' => $deb - $cred];
            $sumDebit += $deb;
            $sumCredit += $cred;
        }
    }
} elseif ($fyId > 0 && orange_table_has_column($pdo, 'journal_entries', 'fiscal_year_id')) {
    foreach ($accounts as $a) {
        $aid = (int)$a['id'];
        $stD = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE fiscal_year_id = ? AND account_debit = ?');
        $stD->execute([$fyId, $aid]);
        $deb = (float)$stD->fetchColumn();
        $stC = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM journal_entries WHERE fiscal_year_id = ? AND account_credit = ?');
        $stC->execute([$fyId, $aid]);
        $cred = (float)$stC->fetchColumn();
        if ($deb > 0.0001 || $cred > 0.0001) {
            $label = (trim((string)($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
            $rows[] = ['label' => $label, 'debit' => $deb, 'credit' => $cred, 'net' => $deb - $cred];
            $sumDebit += $deb;
            $sumCredit += $cred;
        }
    }
}

$balanced = abs($sumDebit - $sumCredit) < 0.02;

/* قائمة الدخل — بدون أرصدة افتتاح ولا إقفال */
$plRevenue = 0.0;
$plExpense = 0.0;
if ($useVouchers && $fyId > 0) {
    $tbPl = orange_voucher_account_totals($pdo, $fyId, ['opening_balance', 'year_end_close']);
    foreach ($tbPl as $aid => $t) {
        $cls = $classById[$aid] ?? 'unclassified';
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($cls === 'revenue') {
            $plRevenue += ($cred - $deb);
        } elseif ($cls === 'expense' || $cls === 'cogs') {
            $plExpense += ($deb - $cred);
        }
    }
}
$netIncome = round($plRevenue - $plExpense, 2);

/* ميزانية عمومية مبسطة */
$bsAssets = 0.0;
$bsLiab = 0.0;
$bsEquity = 0.0;
if ($useVouchers && $fyId > 0) {
    foreach ($tbAll as $aid => $t) {
        $cls = $classById[$aid] ?? 'unclassified';
        $deb = (float)$t['debit'];
        $cred = (float)$t['credit'];
        if ($cls === 'asset') {
            $bsAssets += ($deb - $cred);
        } elseif ($cls === 'liability') {
            $bsLiab += ($cred - $deb);
        } elseif ($cls === 'equity') {
            $bsEquity += ($cred - $deb);
        }
    }
}
$bsCheck = round($bsAssets - ($bsLiab + $bsEquity), 2);
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>التقارير المالية</h1>
        <p class="page-subtitle">
            ميزان مراجعة، قائمة دخل، وميزانية عمومية مبسطة حسب <strong>تصنيف الحساب</strong> في
            <a href="/admin/index.php?page=chart_of_accounts">الدليل المحاسبي</a>.
            بدون تصنيف صحيح لن تُحسب الميزانية والدخل بمعنىها المحاسبي.
        </p>
    </div>
</div>

<div class="card">
    <?php if ($years === []): ?>
        <p class="card-hint">لا توجد سنوات مالية معرفة. افتح <a href="/admin/index.php?page=fiscal_years">السنوات المالية</a>.</p>
    <?php else: ?>
    <form method="get" action="" class="form-grid" style="align-items:end;">
        <input type="hidden" name="page" value="financial_report">
        <div>
            <label for="fy_rep">السنة المالية</label>
            <select id="fy_rep" name="fy" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int)$y['id']; ?>" <?php echo ((int)$y['id'] === $fyId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($y['label_ar'] . ' (' . $y['start_date'] . ' — ' . $y['end_date'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <?php endif; ?>
    <?php if ($fyRow): ?>
        <p class="card-hint" style="margin-top:12px;">
            الفترة: <?php echo htmlspecialchars($fyRow['start_date'] . ' — ' . $fyRow['end_date'], ENT_QUOTES, 'UTF-8'); ?>
            — الحالة: <?php echo (int)$fyRow['is_closed'] === 1 ? 'مغلقة' : 'مفتوحة'; ?>
        </p>
    <?php endif; ?>
</div>

<?php if (!$useVouchers): ?>
<div class="card">
    <p class="card-hint">جاري تهيئة سندات متعددة الأسطر — أعد تحميل الصفحة بعد ثوانٍ.</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">قائمة الدخل (تقريبية)</h3>
    <p class="card-hint">استبعاد أرصدة الافتتاح وقيود إقفال السنة. حسابات بلا تصنيف «إيراد/مصروف/تكلفة» لا تُدخل هنا.</p>
    <div class="grid-2">
        <div class="stat-card"><h3>إجمالي الإيرادات (طبيعة دائنة)</h3><div class="value"><?php echo number_format($plRevenue, 2); ?></div></div>
        <div class="stat-card"><h3>إجمالي المصروفات والتكلفة</h3><div class="value"><?php echo number_format($plExpense, 2); ?></div></div>
    </div>
    <p style="margin:14px 0 0;font-size:1.1rem;"><strong>صافي الدخل:</strong> <?php echo number_format($netIncome, 2); ?> KD</p>
</div>

<div class="card">
    <h3 class="card-title">ميزانية عمومية (مبسطة)</h3>
    <p class="card-hint">أصول = مدين − دائن | خصوم وحقوق = دائن − مدين (للحسابات المصنفة فقط).</p>
    <div class="grid-2">
        <div class="stat-card"><h3>الأصول</h3><div class="value"><?php echo number_format($bsAssets, 2); ?></div></div>
        <div class="stat-card"><h3>الخصوم</h3><div class="value"><?php echo number_format($bsLiab, 2); ?></div></div>
    </div>
    <div class="stat-card" style="margin-top:14px;"><h3>حقوق الملكية</h3><div class="value"><?php echo number_format($bsEquity, 2); ?></div></div>
    <p class="card-hint" style="margin-top:12px;">
        <?php if (abs($bsCheck) < 0.05): ?>
            <span class="badge approved">أصول ≈ خصوم + حقوق (فرق <?php echo number_format($bsCheck, 2); ?>)</span>
        <?php else: ?>
            <span class="badge cancelled">فرق محاسبي: <?php echo number_format($bsCheck, 2); ?> — راجع التصنيف أو أرصدة الافتتاح أو القيود غير الموزونة.</span>
        <?php endif; ?>
    </p>
</div>

<div class="card">
    <h3 class="card-title">ميزان المراجعة</h3>
    <p class="card-hint">
        <?php if ($balanced): ?>
            <span class="badge approved">المدين والدائن متطابقان (<?php echo number_format($sumDebit, 2); ?>)</span>
        <?php else: ?>
            <span class="badge cancelled">فرق: <?php echo number_format($sumDebit - $sumCredit, 2); ?></span>
        <?php endif; ?>
    </p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>الحساب</th>
                    <th>إجمالي مدين</th>
                    <th>إجمالي دائن</th>
                    <th>صافي (مدين − دائن)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format($r['debit'], 2); ?></td>
                        <td><?php echo number_format($r['credit'], 2); ?></td>
                        <td><?php echo number_format($r['net'], 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>الإجمالي</th>
                    <th><?php echo number_format($sumDebit, 2); ?></th>
                    <th><?php echo number_format($sumCredit, 2); ?></th>
                    <th><?php echo number_format($sumDebit - $sumCredit, 2); ?></th>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php if ($rows === []): ?>
        <p class="page-subtitle">لا حركة في هذه السنة.</p>
    <?php endif; ?>
</div>
