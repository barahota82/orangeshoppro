<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/account_tree.php';
require_once __DIR__ . '/../../includes/company_settings.php';
require_once __DIR__ . '/../../includes/admin_page_bootstrap.php';

$pdo = orange_admin_page_pdo();

$flat = orange_accounts_flat($pdo);
$listRows = orange_accounts_report_list_rows($pdo, $flat);
$companyNameAr = orange_company_settings_name_ar($pdo);
$doPrint = isset($_GET['print']) && (string) $_GET['print'] === '1';
?>
<div class="admin-fy-shell gl-acc-stmt-print" dir="rtl">
    <div class="gl-acc-stmt-no-print">
        <h1 class="admin-fy-shell__title">قائمة الحسابات</h1>
        <p class="actions" style="margin:0 0 16px;">
            <button type="button" class="btn-secondary" onclick="window.print()">طباعة</button>
            <a class="btn-secondary" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=chart_of_accounts'), ENT_QUOTES, 'UTF-8'); ?>">الدليل المحاسبي</a>
        </p>
    </div>

    <div class="card admin-fy-card">
        <div class="gl-acc-stmt-print-sheet ral-print-sheet">
        <header class="gl-acc-stmt-print-banner ral-print-banner">
            <?php if ($companyNameAr !== ''): ?>
                <p class="gl-acc-stmt-print-company"><?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <h2 class="gl-acc-stmt-print-title ral-print-title">قائمة الحسابات</h2>
        </header>
        <div class="table-wrap admin-fy-table-wrap gl-acc-stmt-table-wrap">
            <table class="admin-fy-table gl-acc-stmt-table ral-account-list-table" dir="rtl" data-export-name="قائمة الحسابات" data-export-target=".actions" data-export-company="<?php echo htmlspecialchars($companyNameAr, ENT_QUOTES, 'UTF-8'); ?>">
                <thead>
                    <tr>
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
        <p class="card-hint gl-acc-stmt-print-metafoot muted" style="margin-top:12px;">عدد الحسابات: <?php echo count($listRows); ?></p>
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
