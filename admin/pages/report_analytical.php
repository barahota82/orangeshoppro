<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';
require_once __DIR__ . '/../../includes/analytical_report.php';
require_once __DIR__ . '/../../includes/fiscal_years.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';

$pdo = orange_admin_page_pdo();
$raCountryLabel = orange_admin_page_country_label($pdo);
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
$printDatetime = orange_format_datetime_dmY_hi(date('Y-m-d H:i:s'));
$raCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$raLogo = (string) ($raCompany['logo_url'] ?? '');
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
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <div class="page-title">
            <h1>التقرير التحليلي</h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($raCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

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
                <div class="gas-acc-stmt-actions" data-export-host>
                    <button type="submit">عرض</button>
                    <button type="button" class="btn-secondary" onclick="<?php echo ($report !== null) ? 'window.print()' : "alert('اعرض التقرير أولاً ثم اضغط طباعة')"; ?>">طباعة</button>
                    <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=analytical_dimensions'), ENT_QUOTES, 'UTF-8'); ?>">الأبعاد التحليلية</a>
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

    <?php if ($ready): ?>
        <div class="card admin-fy-card gl-acc-stmt-body gl-acc-stmt-print">
            <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
            <header class="gl-acc-stmt-print-banner ral-print-banner">
                <div class="pl-month-brand-row">
                    <div class="pl-month-brand">
                        <?php if ($raLogo !== ''): ?>
                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($raLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                        <?php endif; ?>
                        <div class="pl-month-brand-text">
                            <?php if ($companyNameAr !== ''): ?>
                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <?php if (trim((string) ($raCompany['commercial_register'] ?? '')) !== ''): ?>
                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $raCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="pl-month-contact">
                        <?php if (trim((string) ($raCompany['address'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $raCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                        <?php if (trim((string) ($raCompany['phones'] ?? '')) !== ''): ?>
                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $raCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php endif; ?>
                    </div>
                </div>
                <h2 class="gl-acc-stmt-print-title ta-report-print-title"><span class="gl-acc-stmt-print-title-ar" lang="ar">التقرير التحليلي</span></h2>
                <p class="muted" style="margin:8px 0 0;">
                    <?php echo htmlspecialchars($dimLabel, ENT_QUOTES, 'UTF-8'); ?>
                    — <?php echo $method === 'movement' ? 'حركة GL' : 'قائمة دخل'; ?>
                    — سنوات مالية مرحّلة فقط
                </p>
            </header>

            <?php $raColspan = $method === 'pl' ? 7 : 5; ?>
            <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
                <table class="admin-fy-table gl-acc-stmt-table" dir="rtl" data-export-name="التقرير التحليلي" data-export-target=".gas-acc-stmt-actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>" data-export-subtitle="<?php echo htmlspecialchars($dimLabel . ' — ' . ($method === 'movement' ? 'حركة GL' : 'قائمة دخل'), ENT_QUOTES, 'UTF-8'); ?>">
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
                        <?php if ($report === null): ?>
                            <tr class="gl-acc-stmt-no-print">
                                <td colspan="<?php echo $raColspan; ?>" class="muted">اختر السنة المالية والبُعد ثم «عرض» لعرض التقرير التحليلي.</td>
                            </tr>
                        <?php elseif (($report['rows'] ?? []) === []): ?>
                            <tr>
                                <td colspan="<?php echo $raColspan; ?>" class="muted">لا بيانات لهذا البُعد في السنة المحددة (أضف قيم البُعد على أسطر السندات).</td>
                            </tr>
                        <?php else: ?>
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
                        <?php endif; ?>
                    </tbody>
                    <?php if ($report !== null && ($report['rows'] ?? []) !== []): ?>
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
                    <?php endif; ?>
                </table>
            </div>
            <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
            </div>
        </div>
    <?php endif; ?>
</div>
