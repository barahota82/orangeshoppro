<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/sales_doc_print.php';
require_once __DIR__ . '/../../includes/date_format.php';
require_once __DIR__ . '/../../includes/accounting_report_money.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();
$ralCountryLabel = orange_admin_page_country_label($pdo);
$ralReportTimeIana = '';
try {
    $ralReportDefaults = orange_admin_time_report_default_from_to_for_admin_context($pdo);
    $ralReportTimeIana = (string) $ralReportDefaults['iana'];
} catch (Throwable $e) {
    $ralReportTimeIana = '';
}

$flat = orange_accounts_flat($pdo);
$listRows = orange_accounts_report_list_rows($pdo, $flat);
$companyNameAr = orange_company_settings_name_ar($pdo);
$printDatetime = $ralReportTimeIana !== ''
    ? orange_admin_time_now_display_for_admin_context($pdo, 'ar', 'datetime')
    : orange_format_datetime_dmY_hi(gmdate('Y-m-d H:i:s'));
$ralCompany = orange_sales_doc_print_company($pdo, (int) (function_exists('orange_admin_context_country_id') ? orange_admin_context_country_id($pdo) : 0));
$ralLogo = (string) ($ralCompany['logo_url'] ?? '');
$doPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';
?>
<div class="admin-fy-shell" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <div class="page-title">
            <h1>قائمة الحسابات</h1>
            <p class="card-hint" style="margin:0.35rem 0 0;"><strong>سياق الدولة:</strong> <?php echo htmlspecialchars($ralCountryLabel, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <p class="actions" data-export-host style="margin:0 0 16px;">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=chart_of_accounts'), ENT_QUOTES, 'UTF-8'); ?>">الدليل المحاسبي</a>
        </p>
    </div>

    <div class="card admin-fy-card gl-acc-stmt-print">
        <div class="gl-acc-stmt-print-sheet ta-report-print-sheet">
        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table ral-account-list-table ta-report-print-table" dir="rtl" data-export-name="قائمة الحسابات" data-export-target=".actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <thead class="ta-report-print-thead">
                    <tr class="ta-report-banner-row">
                        <td colspan="5" class="ta-report-banner-cell">
                            <header class="gl-acc-stmt-print-banner ral-print-banner">
                                <div class="pl-month-brand-row">
                                    <div class="pl-month-brand">
                                        <?php if ($ralLogo !== ''): ?>
                                            <img class="pl-month-print-logo" src="<?php echo htmlspecialchars($ralLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="">
                                        <?php endif; ?>
                                        <div class="pl-month-brand-text">
                                            <?php if ($companyNameAr !== ''): ?>
                                                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
                                            <?php endif; ?>
                                            <?php if (trim((string) ($ralCompany['commercial_register'] ?? '')) !== ''): ?>
                                                <p class="pl-month-cr">سجل تجاري: <span dir="ltr"><?php echo htmlspecialchars((string) $ralCompany['commercial_register'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="pl-month-contact">
                                        <?php if (trim((string) ($ralCompany['address'] ?? '')) !== ''): ?>
                                            <p class="pl-month-contact-line"><?php echo htmlspecialchars((string) $ralCompany['address'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <?php endif; ?>
                                        <?php if (trim((string) ($ralCompany['phones'] ?? '')) !== ''): ?>
                                            <p class="pl-month-contact-line"><span dir="ltr"><?php echo htmlspecialchars((string) $ralCompany['phones'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <h2 class="gl-acc-stmt-print-title ta-report-print-title"><span class="gl-acc-stmt-print-title-ar" lang="ar">قائمة الحسابات</span></h2>
                            </header>
                        </td>
                    </tr>
                    <tr class="ta-report-cols-row">
                        <th class="gl-acc-stmt-col-num ral-col-code">كــود الحســاب</th>
                        <th>اســــــم الحســــــاب</th>
                        <th>مستوى الحساب</th>
                        <th>رئيسي / فرعي</th>
                        <th>طبيعة الحساب</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($listRows === []): ?>
                        <tr><td colspan="5" class="muted">لا توجد حسابات في الدليل بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($listRows as $lr): ?>
                            <tr class="<?php echo !empty($lr['is_group']) ? 'ral-row-group' : 'ral-row-leaf'; ?>">
                                <td class="gl-acc-stmt-col-num ral-col-code" dir="ltr" lang="en"><?php echo htmlspecialchars((string) ($lr['code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lr['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lr['level_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lr['group_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($lr['nature_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="card-hint muted" style="margin-top:12px;">عدد الحسابات: <?php echo count($listRows); ?></p>
        <?php echo orange_accounting_report_print_metafoot_markup($printDatetime); ?>
        </div>
    </div>
</div>
<style>
.ral-account-list-table { direction: rtl; }
.ral-account-list-table .ral-col-code { text-align: center; white-space: nowrap; }
.ral-account-list-table th,
.ral-account-list-table td { text-align: center; }
.ral-account-list-table th:nth-child(2),
.ral-account-list-table td:nth-child(2) { text-align: right; }
.ral-row-group td { font-weight: 600; }
.ral-row-leaf td { font-weight: 400; }
@media print {
    .ral-print-banner { margin-bottom: 10px; }
    .ral-print-title { font-size: 1.15rem; margin: 0; }
    .ral-account-list-table { font-size: 0.82rem; }
}
</style>
<?php if ($doPrint): ?>
<script>
window.addEventListener('load', function () {
    setTimeout(function () { window.print(); }, 300);
});
</script>
<?php endif; ?>
