<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_nav_tree.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

/** @var array<string,mixed> $admin — من admin/index.php */
$pdo = db();

/* أوصاف مختصرة (عرض فقط) — تُكمَّل بـ desc داخل شجرة التنقل عند الحاجة. */
$ariDesc = [
    'chart_of_accounts'         => 'شجرة الدليل المحاسبي وإدارته.',
    'journal_types'             => 'أنواع اليوميات وربطها بأنواع القيود.',
    'gl_account_settings'       => 'ربط حسابات القيود التلقائية والذمم.',
    'fiscal_years'              => 'السنوات المالية وفترات التقارير.',
    'edit_lock'                 => 'إقفال التعديل على فترات محاسبية.',
    'invoice_line_presets'      => 'بنود إضافية محفوظة لفواتير المبيعات.',
    'analytical_dimensions'     => 'أبعاد التحليل (فرع، قناة، …).',
    'opening_balances'          => 'أرصدة افتتاحية لبداية السنة المالية.',
    'journal_entries'           => 'إنشاء وتعديل سند قيد يدوي.',
    'receipt_voucher'           => 'سند قبض نقدي أو بنكي.',
    'payment_voucher'           => 'سند صرف نقدي أو بنكي.',
    'other_vouchers'            => 'سندات أخرى حسب الإعداد.',
    'partner_customer_receipt'  => 'تخصيص سداد على فواتير مبيعات آجلة.',
    'partner_supplier_payment'  => 'تخصيص سداد على فواتير مشتريات آجلة.',
    'bank_accounts'             => 'حسابات بنكية للدفع المباشر.',
    'payment_review'            => 'مراجعة وتأكيد الدفعات.',
    'bank_reconciliation'       => 'مطابقة كشف البنك مع الدفاتر.',
    'year_end_close_vouchers'   => 'قيود الإقفال السنوي.',
    'journal_voucher_reports'   => 'كل السندات المرحّلة — بحث وتصفّح.',
    'partner_account_statement' => 'كشف حساب موحّد: عميل / مورد / أي حساب بالشجرة.',
    'report_account_list'       => 'قائمة حسابات الدليل ومستوياتها.',
    'report_gl_account_monthly' => 'الحركة الشهرية لحساب ترحيل واحد.',
    'report_income_statement'   => 'أرباح وخسائر عن فترة (مع استبعاد الإقفال).',
    'report_balance_sheet'      => 'قائمة المركز المالي (الميزانية) — حتى تاريخ + مقارنة سنة سابقة.',
    'report_trading_account'    => 'قائمة حسابات المتاجرة (إيراد − تكلفة).',
    'report_pl_monthly'         => 'إيرادات ومصروفات شهرية.',
    'report_pl_compare_years'   => 'مقارنة أرباح/خسائر بين سنتين ماليتين.',
    'report_trial_balance'      => 'ميزان المراجعة — أرصدة الحسابات المرحّلة.',
    'report_cash_flow'          => 'قائمة التدفقات النقدية (مباشر / غير مباشر).',
    'report_analytical'         => 'تقرير تحليلي حسب البُعد (فرع / قناة).',
    'financial_report'          => 'صفحة مالية مدمجة (P&L + ميزانية + ميزان).',
];

/* أقسام الحسابات من شجرة التنقل (مطابقة للقائمة المنسدلة). */
$ariSection = null;
foreach (orange_admin_permission_mega_sections() as $mega) {
    if ((string) ($mega['id'] ?? '') === 'accounting') {
        $ariSection = $mega;
        break;
    }
}
$ariSubgroups = is_array($ariSection) ? ($ariSection['subgroups'] ?? []) : [];

?>
<div class="page-title page-title--stacked">
    <div>
        <h1>فهرس الحسابات والتقارير</h1>
        <p class="page-subtitle">روابط سريعة لكل تقارير وأدوات الحسابات — بنفس ترتيب القائمة المنسدلة.</p>
    </div>
</div>

<?php foreach ($ariSubgroups as $sg): ?>
    <?php
    $visible = [];
    foreach ($sg['pages'] ?? [] as $p) {
        $pg = (string) ($p['page'] ?? '');
        if ($pg === '' || $pg === 'accounting_reports_index') {
            continue;
        }
        if (!orange_admin_nav_visible($admin, $pdo, $pg)) {
            continue;
        }
        $visible[] = $p;
    }
    if ($visible === []) {
        continue;
    }
    ?>
    <div class="card">
        <h2 class="card-title"><?php echo htmlspecialchars((string) ($sg['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="ari-grid">
            <?php foreach ($visible as $p): ?>
                <?php
                $pg = (string) ($p['page'] ?? '');
                $rawHref = trim((string) ($p['href'] ?? ''));
                $cardHref = $rawHref !== ''
                    ? storefront_public_path($rawHref)
                    : storefront_public_path('/admin/index.php?page=' . rawurlencode($pg));
                $desc = trim((string) ($p['desc'] ?? ''));
                if ($desc === '' && isset($ariDesc[$pg])) {
                    $desc = $ariDesc[$pg];
                }
                ?>
                <a class="ari-card" href="<?php echo htmlspecialchars($cardHref, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="ari-card__title"><?php echo htmlspecialchars((string) ($p['label'] ?? $pg), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if ($desc !== ''): ?>
                        <span class="ari-card__desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>

<style>
.ari-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(15rem, 1fr)); gap:12px; }
.ari-card { display:flex; flex-direction:column; gap:4px; padding:12px 14px; border:1px solid #e2e8f0; border-radius:10px; background:#f8fafc; text-decoration:none; color:#0f172a; }
.ari-card:hover { border-color:#0f172a; }
.ari-card__title { font-weight:700; font-size:0.95rem; }
.ari-card__desc { font-size:0.82rem; color:#64748b; line-height:1.5; }
</style>
