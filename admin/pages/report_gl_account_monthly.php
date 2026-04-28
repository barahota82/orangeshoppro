<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/journal_voucher.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}

$accountId = isset($_GET['account']) ? (int) $_GET['account'] : 0;

$fyRow = null;
foreach ($years as $y) {
    if ((int) $y['id'] === $fyId) {
        $fyRow = $y;
        break;
    }
}

$accounts = $pdo->query('SELECT id, name, code FROM accounts ORDER BY COALESCE(code, \'\'), name')->fetchAll(PDO::FETCH_ASSOC);
$accLabel = '';
foreach ($accounts as $a) {
    if ((int) $a['id'] === $accountId) {
        $accLabel = (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'];
        break;
    }
}

$useVouchers = orange_journal_vouchers_ready($pdo);
$monthlyRows = [];

if ($useVouchers && $fyId > 0 && $accountId > 0) {
    $st = $pdo->prepare(
        'SELECT DATE_FORMAT(jv.voucher_date, \'%Y-%m\') AS ym,
                COALESCE(SUM(jl.debit), 0) AS sum_debit,
                COALESCE(SUM(jl.credit), 0) AS sum_credit
         FROM journal_lines jl
         INNER JOIN journal_vouchers jv ON jv.id = jl.voucher_id
         WHERE jl.account_id = ? AND jv.fiscal_year_id = ?
         GROUP BY ym
         ORDER BY ym ASC'
    );
    $st->execute([$accountId, $fyId]);
    $monthlyRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $running = 0.0;
    foreach ($monthlyRows as &$mr) {
        $d = (float) $mr['sum_debit'];
        $c = (float) $mr['sum_credit'];
        $running += ($d - $c);
        $mr['net_month'] = $d - $c;
        $mr['balance_eom'] = $running;
    }
    unset($mr);
}

$base = htmlspecialchars(storefront_public_path('/admin/index.php'), ENT_QUOTES, 'UTF-8');
?>
<div class="admin-fy-shell" dir="rtl">
    <h1 class="admin-fy-shell__title">الحركة الشهرية لحساب (GL)</h1>
    <p class="admin-fy-shell__lead">
        تجميع مدين ودائن لكل شهر ضمن السنة المالية المختارة — من سندات اليومية الموحّدة.
        <a href="<?php echo $base; ?>?page=accounting_reports_index">العودة لفهرس التقارير</a>
    </p>

<div class="card admin-fy-card">
    <h3 class="card-title">اختيار السنة والحساب</h3>
    <form method="get" class="form-grid" style="max-width:560px;">
        <input type="hidden" name="page" value="report_gl_account_monthly">
        <div>
            <label for="fy_gl_m">السنة المالية</label>
            <select name="fy" id="fy_gl_m">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int) $y['id']; ?>"<?php echo (int) $y['id'] === $fyId ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) ($y['label_ar'] ?? $y['id']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="acct_gl_m">الحساب</label>
            <select name="account" id="acct_gl_m" required>
                <option value="">— اختر حساباً —</option>
                <?php foreach ($accounts as $a): ?>
                    <option value="<?php echo (int) $a['id']; ?>"<?php echo (int) $a['id'] === $accountId ? ' selected' : ''; ?>>
                        <?php
                        echo htmlspecialchars(
                            (trim((string) ($a['code'] ?? '')) !== '' ? $a['code'] . ' — ' : '') . $a['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="actions" style="align-self:end;">
            <button type="submit">عرض</button>
        </div>
    </form>
</div>

<?php if (!$useVouchers): ?>
<div class="card admin-fy-card">
    <p class="muted">سندات اليومية غير جاهزة بعد — لا يمكن عرض التقرير.</p>
</div>
<?php elseif ($fyId <= 0): ?>
<div class="card admin-fy-card">
    <p class="muted">عرّف سنة مالية من «السنوات المالية».</p>
</div>
<?php elseif ($accountId <= 0): ?>
<div class="card admin-fy-card">
    <p class="card-hint">اختر حساباً ثم اضغط «عرض».</p>
</div>
<?php elseif ($fyRow): ?>
<div class="card admin-fy-card">
    <h3 class="card-title">النتيجة</h3>
    <p class="page-subtitle">
        <?php echo htmlspecialchars($accLabel !== '' ? $accLabel : ('#' . $accountId), ENT_QUOTES, 'UTF-8'); ?>
        —
        الفترة: <?php echo htmlspecialchars(($fyRow['start_date'] ?? '') . ' — ' . ($fyRow['end_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php if ($monthlyRows === []): ?>
        <p class="muted">لا حركة على هذا الحساب في هذه السنة (حسب السندات المرتبطة بالسنة).</p>
    <?php else: ?>
    <div class="table-wrap admin-fy-table-wrap">
        <table class="admin-fy-table">
            <thead>
                <tr>
                    <th>الشهر</th>
                    <th>مجموع مدين</th>
                    <th>مجموع دائن</th>
                    <th>صافي الشهر</th>
                    <th>رصيد متحرّك بعد الشهر</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($monthlyRows as $mr): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($mr['ym'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float) ($mr['sum_debit'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['sum_credit'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['net_month'] ?? 0), 4); ?></td>
                        <td><?php echo number_format((float) ($mr['balance_eom'] ?? 0), 4); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="card-hint" style="margin-top:12px;">
        الرصيد المتحرّك يتراكم حسب أشهر ظهور حركة فقط؛ لكشف سطراً بسطر استخدم «التقارير المالية» مع معامل حساب.
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

</div>
