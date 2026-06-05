<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_nav_tree.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

/** @var array<string,mixed> $admin — من admin/index.php */
$pdo = db();

/* أوصاف مختصرة لكل تقرير (عرض فقط). */
$ariDesc = [
    'journal_voucher_reports'   => 'كل السندات المرحّلة — بحث وتصفّح.',
    'partner_account_statement' => 'كشف حساب موحّد: عميل / مورد / أي حساب بالشجرة.',
    'report_account_list'       => 'قائمة حسابات الدليل ومستوياتها.',
    'report_gl_account_monthly' => 'الحركة الشهرية لحساب ترحيل واحد.',
    'partner_reports'           => 'أرصدة العملاء والموردين (ذمم) + مطابقة الدليل.',
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

/* أقسام الحسابات من شجرة الصلاحيات (نفس تقسيم القائمة). */
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
        <h1>فهرس التقارير المحاسبية</h1>
        <p class="page-subtitle">روابط سريعة لكل تقارير وأدوات الحسابات — تظهر حسب صلاحياتك.</p>
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
                <?php $pg = (string) $p['page']; ?>
                <a class="ari-card" href="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php?page=' . rawurlencode($pg)), ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="ari-card__title"><?php echo htmlspecialchars((string) ($p['label'] ?? $pg), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (isset($ariDesc[$pg])): ?>
                        <span class="ari-card__desc"><?php echo htmlspecialchars($ariDesc[$pg], ENT_QUOTES, 'UTF-8'); ?></span>
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
