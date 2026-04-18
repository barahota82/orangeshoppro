<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/party_allocations.php';

$pdo = db();
orange_catalog_ensure_schema($pdo);

$includeAging = isset($_GET['aging']) && $_GET['aging'] === '1';
$report = orange_partner_summary_report($pdo, $includeAging);

$years = orange_fiscal_years_list($pdo);
$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) $years[0]['id'];
}
$reconcile = orange_partner_gl_reconcile($pdo, $fyId);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="partner-balances-' . $report['as_of'] . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['نوع', 'المعرّف', 'الاسم', 'الهاتف', 'الرصيد', 'حد ائتمان', 'تجاوز حد']);
    foreach ($report['customers'] as $c) {
        fputcsv($out, [
            'عميل',
            $c['id'],
            $c['name_ar'],
            $c['phone'],
            number_format((float) $c['balance'], 4, '.', ''),
            $c['credit_limit'] !== null ? number_format((float) $c['credit_limit'], 4, '.', '') : '',
            !empty($c['over_limit']) ? 'نعم' : '',
        ]);
    }
    foreach ($report['suppliers'] as $s) {
        fputcsv($out, [
            'مورد',
            $s['id'],
            $s['name'],
            $s['phone'],
            number_format((float) $s['balance'], 4, '.', ''),
            '',
            '',
        ]);
    }
    fclose($out);
    exit;
}
?>
<div class="page-title page-title--stacked">
    <div>
        <h1>تقارير الذمم الشاملة</h1>
        <p class="page-subtitle">
            ملخص أرصدة كل العملاء والموردين، مطابقة أرصدة الدليل مع دفتر الذمم، وتصدير CSV.
            <a href="/admin/index.php?page=partner_ledger">العودة لحركات القبض والدفع</a>
        </p>
    </div>
</div>

<div class="card">
    <h3 class="card-title">خيارات العرض</h3>
    <div class="actions" style="flex-wrap:wrap; gap:8px;">
        <a class="btn-secondary" href="/admin/index.php?page=partner_reports<?php echo $includeAging ? '' : '&amp;aging=1'; ?>">
            <?php echo $includeAging ? 'إخفاء أعمار الذمم (أسرع)' : 'إظهار أعمار الذمم (أبطأ)'; ?>
        </a>
        <a class="btn-secondary" href="/admin/index.php?page=partner_reports&amp;export=csv<?php echo $includeAging ? '&amp;aging=1' : ''; ?>">تنزيل CSV</a>
        <button type="button" class="btn-secondary" onclick="backfillOrders()">ربط طلبات آجل بعملاء (هاتف)</button>
    </div>
    <p class="card-hint muted" style="margin-top:10px;">اعتباراً من <?php echo htmlspecialchars($report['as_of'], ENT_QUOTES, 'UTF-8'); ?></p>
</div>

<?php if ($reconcile !== null): ?>
<div class="card">
    <h3 class="card-title">مطابقة الدليل مع دفتر الذمم</h3>
    <form method="get" class="form-grid" style="max-width:420px;">
        <input type="hidden" name="page" value="partner_reports">
        <?php if ($includeAging): ?><input type="hidden" name="aging" value="1"><?php endif; ?>
        <div>
            <label for="fy_sel">السنة المالية</label>
            <select name="fy" id="fy_sel" onchange="this.form.submit()">
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo (int) $y['id']; ?>"<?php echo (int) $y['id'] === $fyId ? ' selected' : ''; ?>>
                        <?php echo htmlspecialchars((string) ($y['label_ar'] ?? $y['id']), ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
    <div class="table-wrap" style="margin-top:12px;">
        <table>
            <thead>
                <tr><th>البند</th><th>دفتر الأستاذ (سنة مالية)</th><th>دفتر الذمم</th><th>الفرق</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>عملاء آجل (مدين − دائن)</td>
                    <td><?php echo number_format((float) $reconcile['gl']['ar_net_dr_minus_cr'], 3); ?></td>
                    <td><?php echo number_format((float) $reconcile['subledger']['customers_dr_minus_cr'], 3); ?></td>
                    <td><?php echo number_format((float) $reconcile['variance']['ar'], 3); ?></td>
                </tr>
                <tr>
                    <td>موردين (دائن − مدين)</td>
                    <td><?php echo number_format((float) $reconcile['gl']['ap_net_cr_minus_dr'], 3); ?></td>
                    <td><?php echo number_format((float) $reconcile['subledger']['suppliers_cr_minus_dr'], 3); ?></td>
                    <td><?php echo number_format((float) $reconcile['variance']['ap'], 3); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="card-hint">فرق غير صفر يعني قيوداً على الحسابات بدون تسجيل في الذمم الفرعية أو العكس — راجع القيود اليدوية.</p>
</div>
<?php else: ?>
<div class="card">
    <p class="muted">لا توجد سنة مالية أو السندات غير مفعّلة — عرّف سنة من «السنوات المالية».</p>
</div>
<?php endif; ?>

<div class="card">
    <h3 class="card-title">أرصدة العملاء</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>الاسم</th><th>الهاتف</th><th>الرصيد</th><th>حد ائتمان</th><th>تجاوز</th>
                    <?php if ($includeAging): ?><th>أكثر من 90 يوم</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['customers'] as $c): ?>
                    <tr>
                        <td><?php echo (int) $c['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $c['name_ar'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $c['phone'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float) $c['balance'], 3); ?></td>
                        <td><?php echo $c['credit_limit'] !== null ? number_format((float) $c['credit_limit'], 3) : '—'; ?></td>
                        <td><?php echo !empty($c['over_limit']) ? 'نعم' : ''; ?></td>
                        <?php if ($includeAging && isset($c['aging']['buckets'])): ?>
                            <td><?php echo number_format((float) ($c['aging']['buckets']['days_91_plus'] ?? 0), 3); ?></td>
                        <?php elseif ($includeAging): ?>
                            <td>—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3 class="card-title">أرصدة الموردين</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th><th>الاسم</th><th>الهاتف</th><th>الذمة</th>
                    <?php if ($includeAging): ?><th>أكثر من 90 يوم</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['suppliers'] as $s): ?>
                    <tr>
                        <td><?php echo (int) $s['id']; ?></td>
                        <td><?php echo htmlspecialchars((string) $s['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($s['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo number_format((float) $s['balance'], 3); ?></td>
                        <?php if ($includeAging && isset($s['aging']['buckets'])): ?>
                            <td><?php echo number_format((float) ($s['aging']['buckets']['days_91_plus'] ?? 0), 3); ?></td>
                        <?php elseif ($includeAging): ?>
                            <td>—</td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function backfillOrders() {
    if (!confirm('ربط الطلبات الآجلة التي بها هاتف وبدون customer_id بجدول العملاء؟')) return;
    postJSON('/admin/api/customers/backfill-orders.php', {}).then(function (r) {
        alert(r.message || (r.success ? 'تم' : 'فشل'));
        if (r.success) location.reload();
    }).catch(function (e) { alert(e.message || String(e)); });
}
</script>
