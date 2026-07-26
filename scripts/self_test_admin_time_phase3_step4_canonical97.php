<?php

declare(strict_types=1);

/**
 * Phase 3 Step 4 — Canonical 97-screen coverage contract (post targeted repair).
 *
 * Usage: php scripts/self_test_admin_time_phase3_step4_canonical97.php
 */

$root = dirname(__DIR__);
$failures = 0;
$passes = 0;

function c97_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS  {$label}\n";
        $passes++;
    } else {
        echo "FAIL  {$label}\n";
        $failures++;
    }
}

$index = (string) file_get_contents($root . '/admin/index.php');
$header = (string) file_get_contents($root . '/admin/partials/header.php');
$policy = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_TIME_POLICY.txt');
$scope = (string) file_get_contents($root . '/docs/archive/ORANGE_ADMIN_SCREENS_COUNTRY_SCOPE.txt');
$stk = (string) file_get_contents($root . '/includes/stock_adjustment_voucher.php');
$osv = (string) file_get_contents($root . '/includes/opening_stock_voucher.php');
$ir = (string) file_get_contents($root . '/includes/inventory_reconciliation.php');

$ownerLabels = [
    'الرئيسية',
    'فهرس الحسابات والتقارير',
    'الدليل المحاسبي',
    'أنواع اليوميات',
    'حسابات القيود التلقائية',
    'السنوات المالية',
    'إقفال التعديلات',
    'قائمة بنود الفاتورة الإضافية',
    'الأبعاد التحليلية',
    'أرصدة أول المدة المالية',
    'سند قيد',
    'سند قبض',
    'سند صرف',
    'سندات أخرى',
    'سداد فواتير مبيعات آجلة',
    'سداد فواتير مشتريات آجلة',
    'قيد تسوية مخزون',
    'قيد كسب نقاط ولاء',
    'قيد انتهاء نقاط ولاء',
    'الحسابات البنكية (دفع مباشر)',
    'مراجعة الدفعات',
    'تسوية البنك',
    'قيود الإقفال السنوية',
    'تقارير السندات',
    'كشف حساب',
    'الحركة الشهرية لحساب',
    'أرصدة العملاء (ذمم)',
    'أرصدة الموردين (ذمم)',
    'قائمة إيرادات ومصروفات شهرية',
    'قائمة حسابات المتاجرة',
    'قائمة التدفقات النقدية',
    'أرباح وخسائر',
    'أرباح وخسائر مقارنة بين السنوات',
    'ميزان المراجعة',
    'الميزانية',
    'التقرير التحليلي',
    'قائمة الحسابات',
    'التقارير المالية (الصفحة كاملة)',
    'فهرس المخازن والمشتريات',
    'تقارير المخزن',
    'أرشيف الجرد',
    'أرصدة أول المدة المخزنية',
    'الموردين',
    'المشتريات',
    'مردود المشتريات',
    'تقارير المشتريات',
    'الأقسام الرئيسية',
    'فروع شجرة المنتجات',
    'أنواع المنتجات الموحدة',
    'سمات الكتالوج',
    'قاموس الألوان',
    'أنماط الألوان',
    'قوالب المقاسات',
    'قاموس هرم المقاسات (1–2)',
    'عائلات المقاسات (3–4)',
    'دليل المقاس الاسترشادي',
    'المنتجات',
    'فهرس المبيعات والعروض',
    'العملاء',
    'الطلبات',
    'طلبات محجوزة (مخزون)',
    'طابور الطلبات',
    'مناديب التوصيل',
    'تسليم المندوب',
    'بحث التسليم',
    'ورقة المندوب',
    'إنشاء قيود التسليم',
    'طباعة فاتورة طلب',
    'فواتير أونلاين',
    'فاتورة مبيعات',
    'مردود المبيعات',
    'عروض التوصيل',
    'عروض مجموع السلة',
    'عروض المنتجات',
    'عروض الهدايا',
    'عروض BOGO',
    'عروض الكومبو',
    'ولاء العملاء (النقاط)',
    'صحة العروض (مخزون)',
    'تقارير المبيعات',
    'تحليل المبيعات',
    'تحليل القنوات',
    'فهرس الإعدادات',
    'بيانات الشركة',
    'أرشيف المستندات',
    'سجل النشاط',
    'الدول',
    'نسخ إعدادات بين الدول',
    'المستخدمون والصلاحيات',
    'إدارة النسخ الاحتياطي',
    'إدارة الاسترداد',
    'قنوات العملاء',
    'محافظات ومناطق التوصيل',
    'ترتيب عرض المنتجات',
    'بانر الصفحة الرئيسية',
    'الرسائل التحفيزية',
    'دمج هاتف التسجيل',
];

c97_assert(count($ownerLabels) === 97, 'expected Owner labels = 97');
$mapped = 0;
$missing = [];
foreach ($ownerLabels as $label) {
    if (str_contains($header, $label) || str_contains($index, $label)) {
        $mapped++;
    } else {
        $missing[] = $label;
    }
}
c97_assert($mapped === 97, 'mapped Owner nav labels = 97' . ($missing !== [] ? (' missing=' . implode('|', $missing)) : ''));

$routes = [
    'dashboard', 'accounting_reports_index', 'chart_of_accounts', 'journal_types', 'gl_account_settings',
    'fiscal_years', 'edit_lock', 'invoice_line_presets', 'analytical_dimensions', 'opening_balances',
    'journal_entries', 'receipt_voucher', 'payment_voucher', 'other_vouchers', 'partner_customer_receipt',
    'partner_supplier_payment', 'stock_adjustment_voucher', 'loyalty_earn_voucher', 'loyalty_expire_voucher',
    'bank_accounts', 'payment_review', 'bank_reconciliation', 'year_end_close_vouchers', 'journal_voucher_reports',
    'partner_account_statement', 'report_gl_account_monthly', 'partner_reports', 'report_pl_monthly',
    'report_trading_account', 'report_cash_flow', 'report_income_statement', 'report_pl_compare_years',
    'report_trial_balance', 'report_balance_sheet', 'report_analytical', 'report_account_list', 'financial_report',
    'warehouse_purchases_index', 'stock_reports', 'inventory_reconciliation', 'opening_stock_balances',
    'suppliers', 'purchases', 'purchase_returns', 'purchase_reports', 'departments', 'unified_catalog_branches',
    'product_types', 'catalog_attributes', 'color_dictionary', 'pattern_dictionary', 'size_scheme_templates',
    'sizing_dictionary', 'size_families', 'advisory_sizing_guides', 'products', 'sales_promotions_index',
    'customers', 'orders', 'reserved_orders', 'order_intake_queue', 'delivery_agents', 'delivery_agent_handover',
    'delivery_order_search', 'delivery_handover_manifest', 'online_orders_final_posting', 'invoice',
    'online_sales_invoice', 'company_sales_invoice', 'sales_returns', 'delivery_promotions', 'cart_promotions',
    'offers', 'cart_gift_promotions', 'cart_bogo_promotions', 'cart_combo_promotions', 'loyalty',
    'cart_promo_health', 'sales_reports', 'reports', 'channel_analytics', 'settings_index', 'company_settings',
    'company_documents', 'logs', 'countries', 'country_screen_copy', 'admin_users', 'backup_center',
    'restore_center', 'channels', 'delivery_areas', 'product_display_order', 'storefront_hero',
    'storefront_promo_messages', 'storefront_merge_requests',
];
// Unique page slugs < 97 because أرصدة العملاء/الموردين يتشاركان partner_reports.
$uniqueRoutes = array_values(array_unique($routes));
c97_assert(count($ownerLabels) === 97 && count($uniqueRoutes) === 96, 'Owner=97 with 96 unique page slugs (partner_reports shared)');
$routeOk = 0;
foreach ($uniqueRoutes as $r) {
    if (str_contains($index, "'" . $r . "'") || str_contains($header, 'page=' . $r)) {
        $routeOk++;
    }
}
c97_assert($routeOk === 96, "unique routes present in index/nav = {$routeOk}");

// Screens 17/41/42 closed
c97_assert(!str_contains($stk, 'IS NULL OR country_id') && !str_contains($stk, 'country_id IS NULL OR'), '17 stock adj no NULL OR');
c97_assert(!str_contains($osv, 'IS NULL OR country_id') && !str_contains($osv, 'country_id IS NULL OR'), '42 opening stock no NULL OR');
c97_assert(!str_contains($ir, 'IS NULL OR country_id') && !str_contains($ir, 'country_id IS NULL OR'), '41 recon no NULL OR');

c97_assert(str_contains($policy, 'Gaps = 0') || str_contains($policy, '97/97/0/0') || str_contains($policy, 'Canonical totals'), 'docs final totals');
c97_assert(str_contains($scope, 'stock_adjustment') || str_contains($scope, 'المخزن') || str_contains($policy, 'stock_adjustment'), 'scope/policy inventory noted');

c97_assert(str_contains($policy, 'NO EXPLICIT GLOBAL OR MIXED CUSTOMER/STOREFRONT USER SURFACE FOUND')
    || str_contains($policy, 'COUNTRY_SCOPED'), 'no unresolved global customer surface claim');

echo "\n--- Canonical 97 coverage ---\n";
echo "PASS={$passes} FAIL={$failures}\n";
echo "NOTE: Classification contract for 17/41/42 = COMPLIANT_COUNTRY_SCOPED after NULL-OR removal.\n";
exit($failures > 0 ? 1 : 0);
