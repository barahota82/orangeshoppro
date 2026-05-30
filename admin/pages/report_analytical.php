<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/analytical_report.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/company_settings.php';

$pdo = orange_admin_page_pdo();
$reportMoney = orange_accounting_report_money($pdo, isset($orangeAdminMoney) ? $orangeAdminMoney : null);
$ctxCountryId = orange_admin_settings_effective_country_id($pdo);

$ready = orange_analytical_dimensions_ready($pdo) && orange_journal_vouchers_ready($pdo);
$years = orange_fiscal_years_list($pdo, $ctxCountryId);
$dims = $ready ? orange_analytical_dimensions_list($pdo, $ctxCountryId, true) : [];

$fyId = isset($_GET['fy']) ? (int) $_GET['fy'] : 0;
if ($fyId <= 0 && $years !== []) {
    $fyId = (int) ($years[0]['id'] ?? 0);
}
$dimensionId = isset($_GET['dim']) ? (int) $_GET['dim'] : 0;
if ($dimensionId <= 0 && $dims !== []) {
    $dimensionId = (int) ($dims[0]['id'] ?? 0);
}
$methodRaw = isset($_GET['method']) ? strtolower(trim((string) $_GET['method'])) : 'pl';
$method = $methodRaw === 'movement' ? 'movement' : 'pl';
$submitted = isset($_GET['run']) && (string) $_GET['run'] === '1';

$report = null;
if ($ready && $submitted && $fyId > 0 && $dimensionId > 0) {
    try {
        $report = orange_analytical_report_build($pdo, $fyId, $dimensionId, $method, $ctxCountryId);
    } catch (InvalidArgumentException $e) {
        $reportError = $e->getMessage();
    }
}

$companyNameAr = orange_company_settings_name_ar($pdo);
$fmt = static fn (float $n): string => orange_accounting_report_format_amount($n, $reportMoney);

$dimLabel = '';
foreach ($dims as $d) {
    if ((int) ($d['id'] ?? 0) === $dimensionId) {
        $dimLabel = trim((string) ($d['label_ar'] ?? ''));
        if ($dimLabel === '') {
            $dimLabel = trim((string) ($d['label_en'] ?? ''));
        }
        break;
    }
}

?>
<div class="admin-fy-shell gl-acc-stmt-print" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">التقرير التحليلي</h1>
        <p class="actions" style="margin:0 0 16px;">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=analytical_dimensions'), ENT_QUOTES, 'UTF-8'); ?>">الأبعاد التحليلية</a>
        </p>

        <?php if (! $ready): ?>
            <div class="card" style="border:1px solid #fcd34d;background:#fffbeb;">
                <p style="margin:0;">السندات أو الأبعاد غير جاهزة — حدّث المخطط (ACC-10).</p>
            </div>
        <?php else: ?>

        <form method="get" class="card admin-fy-card" style="margin-bottom:16px;">
            <input type="hidden" name="page" value="report_analytical">
            <input type="hidden" name="run" value="1">
            <div class="admin-fy-form-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));align-items:end;">
                <div>
                    <label for="ra_fy">السنة المالية</label>
                    <select id="ra_fy" name="fy" required>
                        <?php foreach ($years as $yr): ?>
                            <?php $id = (int) ($yr['id'] ?? 0); ?>
                            <option value="<?php echo $id; ?>"<?php echo $id === $fyId ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim((string) ($yr['label_ar'] ?? '')) !== '' ? (string) $yr['label_ar'] : ('#' . $id), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="ra_dim">البُعد</label>
                    <select id="ra_dim" name="dim" required>
                        <?php foreach ($dims as $d): ?>
                            <?php $id = (int) ($d['id'] ?? 0); ?>
                            <option value="<?php echo $id; ?>"<?php echo $id === $dimensionId ? ' selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim((string) ($d['label_ar'] ?? '')) !== '' ? (string) $d['label_ar'] : (string) ($d['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <span class="coa-field__label">نوع التقرير</span>
                    <div class="coa-radio-row">
                        <label class="coa-radio">
                            <input type="radio" name="method" value="pl"<?php echo $method === 'pl' ? ' checked' : ''; ?>> قائمة دخل (P&amp;L)
                        </label>
                        <label class="coa-radio">
                            <input type="radio" name="method" value="movement"<?php echo $method === 'movement' ? ' checked' : ''; ?>> حركة GL
                        </label>
                    </div>
                </div>
                <div>
                    <button type="submit">عرض</button>
                </div>
            </div>
        </form>

        <?php if (isset($reportError)): ?>
            <div class="card" style="border:1px solid #fecaca;background:#fef2f2;">
                <p style="margin:0;"><?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>

    <?php if ($report !== null): ?>
        <div class="card gl-acc-stmt-body">
            <h2 style="margin:0 0 8px;font-size:1.1rem;"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="card-hint" style="margin:0 0 16px;">
                تقرير تحليلي — <?php echo htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8'); ?>
                — <?php echo $method === 'movement' ? 'حركة GL' : 'قائمة دخل'; ?>
                — سنوات مالية مرحّلة فقط
            </p>

            <?php if (($report['rows'] ?? []) === []): ?>
                <p class="muted">لا بيانات لهذا البُعد في السنة المحددة (أضف قيم البُعد على أسطر السندات).</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="admin-fy-table">
                        <thead>
                            <tr>
                                <th>القيمة</th>
                                <th>كود</th>
                                <?php if ($method === 'pl'): ?>
                                    <th>إيراد</th>
                                    <th>تكلفة مبيعات</th>
                                    <th>مجمل</th>
                                    <th>مصروف</th>
                                    <th>صافي</th>
                                <?php else: ?>
                                    <th>مدين</th>
                                    <th>دائن</th>
                                    <th>صافي حركة</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report['rows'] as $row): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) ($row['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars((string) ($row['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php if ($method === 'pl'): ?>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['revenue'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['cogs'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['gross_profit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['expense'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><strong><?php echo htmlspecialchars($fmt((float) ($row['net_profit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                    <?php else: ?>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['debit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['credit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($row['net_movement'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php $tot = $report['totals'] ?? []; ?>
                        <tfoot>
                            <tr style="font-weight:600;background:#f8fafc;">
                                <td colspan="2">المجموع</td>
                                <?php if ($method === 'pl'): ?>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['revenue'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['cogs'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['gross_profit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['expense'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['net_profit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php else: ?>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['debit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['credit'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td dir="ltr"><?php echo htmlspecialchars($fmt((float) ($tot['net_movement'] ?? 0)), ENT_QUOTES, 'UTF-8'); ?></td>
                                <?php endif; ?>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
