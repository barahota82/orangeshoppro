<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/admin_section_index.php';

/** @var array<string,mixed> $admin — من admin/index.php */
$pdo = db();

orange_admin_render_mega_section_index(
    $admin,
    $pdo,
    'accounting',
    'accounting_reports_index',
    'فهرس الحسابات والتقارير',
    'روابط سريعة لكل تقارير وأدوات الحسابات — بنفس ترتيب القائمة المنسدلة.',
    [
        'chart_of_accounts' => 'شجرة الدليل المحاسبي وإدارته.',
        'journal_types' => 'أنواع اليوميات وربطها بأنواع القيود.',
        'gl_account_settings' => 'ربط حسابات القيود التلقائية والذمم.',
        'fiscal_years' => 'السنوات المالية وفترات التقارير.',
        'edit_lock' => 'إقفال التعديل على فترات محاسبية.',
        'invoice_line_presets' => 'بنود إضافية محفوظة لفواتير المبيعات.',
        'analytical_dimensions' => 'أبعاد التحليل (فرع، قناة، …).',
        'opening_balances' => 'أرصدة افتتاحية لبداية السنة المالية.',
        'journal_entries' => 'إنشاء وتعديل سند قيد يدوي.',
        'receipt_voucher' => 'سند قبض نقدي أو بنكي.',
        'payment_voucher' => 'سند صرف نقدي أو بنكي.',
        'other_vouchers' => 'سندات أخرى حسب الإعداد.',
        'partner_customer_receipt' => 'تخصيص سداد على فواتير مبيعات آجلة.',
        'partner_supplier_payment' => 'تخصيص سداد على فواتير مشتريات آجلة.',
        'bank_accounts' => 'حسابات بنكية للدفع المباشر.',
        'payment_review' => 'مراجعة وتأكيد الدفعات.',
        'bank_reconciliation' => 'مطابقة كشف البنك مع الدفاتر.',
        'year_end_close_vouchers' => 'قيود الإقفال السنوي.',
        'journal_voucher_reports' => 'كل السندات المرحّلة — بحث وتصفّح.',
        'partner_account_statement' => 'كشف حساب موحّد: عميل / مورد / أي حساب بالشجرة.',
        'report_account_list' => 'قائمة حسابات الدليل ومستوياتها.',
        'report_gl_account_monthly' => 'الحركة الشهرية لحساب ترحيل واحد.',
        'report_income_statement' => 'أرباح وخسائر عن فترة (مع استبعاد الإقفال).',
        'report_balance_sheet' => 'قائمة المركز المالي (الميزانية) — حتى تاريخ + مقارنة سنة سابقة.',
        'report_trading_account' => 'قائمة حسابات المتاجرة (إيراد − تكلفة).',
        'report_pl_monthly' => 'إيرادات ومصروفات شهرية.',
        'report_pl_compare_years' => 'مقارنة أرباح/خسائر بين سنتين ماليتين.',
        'report_trial_balance' => 'ميزان المراجعة — أرصدة الحسابات المرحّلة.',
        'report_cash_flow' => 'قائمة التدفقات النقدية (مباشر / غير مباشر).',
        'report_analytical' => 'تقرير تحليلي حسب البُعد (فرع / قناة).',
        'financial_report' => 'صفحة مالية مدمجة (P&L + ميزانية + ميزان).',
    ]
);
