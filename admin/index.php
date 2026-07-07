<?php
require_once __DIR__ . '/../config.php';
orange_send_html_no_cache_headers();
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/admin_permissions.php';
$__orangeAdminProfileReq = orange_admin_profile_request_begin();
$__orangeAdminProfileAuthT0 = orange_admin_profile_hrtime_start();
$admin = require_admin_page();
orange_admin_profile_hrtime_record('auth', $__orangeAdminProfileAuthT0);
$page = $_GET['page'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'admin_users',
    'company_settings',
    'bank_accounts',
    'payment_review',
    'product_display_order',
    'storefront_hero',
    'storefront_merge_requests',
    'countries',
    'country_screen_copy',
    'delivery_areas',
    'cart_promotions',
    'delivery_promotions',
    'cart_gift_promotions',
    'cart_bogo_promotions',
    'cart_combo_promotions',
    'cart_promo_health',
    'storefront_promo_messages',
    'loyalty',
    'departments',
    'unified_catalog_branches',
    'product_types',
    'color_dictionary',
    'pattern_dictionary',
    'sizing_dictionary',
    'size_families',
    'size_scheme_templates',
    'advisory_sizing_guides',
    'catalog_attributes',
    'products',
    'offers',
    'orders',
    'reserved_orders',
    'sales_returns',
    'manual_order',
    'company_sales_invoice',
    'customers',
    'suppliers',
    'purchases',
    'purchase_returns',
    'stock_reports',
    'purchase_reports',
        'stock_adjustment_voucher',
        'loyalty_earn_voucher',
        'loyalty_expire_voucher',
        'chart_of_accounts',
    'fiscal_years',
    'opening_balances',
    'opening_stock_balances',
    'partner_account_statement',
    'partner_customer_receipt',
    'partner_supplier_payment',
    'partner_reports',
    'gl_account_settings',
    'invoice_line_presets',
    'journal_entries',
    'year_end_close_vouchers',
    'receipt_voucher',
    'payment_voucher',
    'other_vouchers',
    'journal_voucher_reports',
    'journal_types',
    'edit_lock',
    'report_gl_account_monthly',
    'report_account_list',
    'report_trading_account',
    'report_trial_balance',
    'report_income_statement',
    'report_balance_sheet',
    'accounting_reports_index',
    'warehouse_purchases_index',
    'sales_promotions_index',
    'settings_index',
    'report_pl_monthly',
    'report_pl_compare_years',
    'report_cash_flow',
    'report_analytical',
    'bank_reconciliation',
    'analytical_dimensions',
    'inventory_reconciliation',
    'reports',
    'sales_reports',
    'financial_report',
    'logs',
    'channels',
    'company_documents',
    'channel_analytics',
    'invoice',
    'order_intake_queue',
    'delivery_agents',
    'delivery_agent_handover',
    'delivery_order_search',
    'invoice_edit',
    'delivery_handover_manifest',
    'online_orders_final_posting',
    'online_invoices',
    'online_sales_invoice',
    'sales_invoices',
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$pdo = db();
$GLOBALS['orangeAdminPdo'] = $pdo;
require_once __DIR__ . '/../includes/countries.php';
if (function_exists('orange_table_exists') && orange_table_exists($pdo, 'countries')) {
    orange_admin_bootstrap_country_context($pdo);
}
orange_catalog_ensure_schema($pdo);
orange_catalog_ensure_country_id_columns_once($pdo);
orange_admin_bootstrap_country_context($pdo);
orange_admin_require_page($admin, $pdo, $page);

require_once __DIR__ . '/../includes/currency.php';
$orangeAdminMoney = orange_admin_currency_context($pdo);
$orangeAdminMoneyZero = orange_admin_money_zero_string((int) $orangeAdminMoney['decimals']);
$orangeAdminMoneyStep = orange_admin_money_input_step((int) $orangeAdminMoney['decimals']);

/*
 * تصدير الملفات (Excel/CSV) قبل إخراج تخطيط الأدمن: صفحات الأدمن تُضمَّن بعد
 * partials/header.php، فلو أرسلت الصفحة ترويسة تنزيل (Content-Disposition) بعد
 * الهيدر فُقدت الترويسات. هنا نُضمّن صفحة التصدير مبكراً (بلا تخطيط) فتُرسل الملف
 * وتُنهي التنفيذ (exit). إن لم تكن الصفحة من نوع تصدير لن تستدعي exit، فنتجاهل أي
 * إخراج مؤقت ونكمل العرض الطبيعي بالتخطيط.
 */
if (isset($_GET['export']) && (string) $_GET['export'] !== '') {
    $exportPageFile = __DIR__ . '/pages/' . $page . '.php';
    if (is_readable($exportPageFile)) {
        ob_start();
        try {
            include $exportPageFile;
        } catch (Throwable $exportErr) {
            if (function_exists('error_log')) {
                error_log('[orange admin export ' . $page . '] ' . $exportErr->getMessage()
                    . ' @ ' . $exportErr->getFile() . ':' . $exportErr->getLine());
            }
        }
        /* لم تُنهِ الصفحة التنفيذ (ليست تصديراً فعلياً) — تجاهل المؤقّت وأكمل العرض. */
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}

$__orangeAdminProfileHeaderT0 = orange_admin_profile_hrtime_start();
include __DIR__ . '/partials/header.php';
orange_admin_profile_hrtime_record('header_render', $__orangeAdminProfileHeaderT0);
$pageFile = __DIR__ . '/pages/' . $page . '.php';
if (!is_readable($pageFile)) {
    echo '<div class="card"><p class="muted">الصفحة غير موجودة: '
        . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p></div>';
} else {
    $__orangeAdminProfilePageT0 = orange_admin_profile_hrtime_start();
    try {
        include $pageFile;
    } catch (Throwable $adminPageError) {
        if (function_exists('error_log')) {
            error_log(
                '[orange admin page ' . $page . '] '
                . $adminPageError->getMessage()
                . ' @ '
                . $adminPageError->getFile()
                . ':'
                . $adminPageError->getLine()
            );
        }
        echo '<div class="card" style="border:1px solid #dc2626;background:#fef2f2;">';
        echo '<h3 style="margin-top:0;">تعذّر تحميل الصفحة</h3>';
        echo '<p>' . htmlspecialchars($adminPageError->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
        echo '<p class="muted" style="font-size:0.85rem;margin-bottom:0;">'
            . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '</p>';
        echo '</div>';
    } finally {
        orange_admin_profile_hrtime_record('page_body', $__orangeAdminProfilePageT0);
    }
}
include __DIR__ . '/partials/footer.php';
$__orangeAdminProfileView = '';
if ($page === 'partner_reports') {
    $__orangeAdminProfileViewRaw = isset($_GET['view']) ? strtolower(trim((string) $_GET['view'])) : '';
    if (in_array($__orangeAdminProfileViewRaw, ['customers', 'suppliers'], true)) {
        $__orangeAdminProfileView = $__orangeAdminProfileViewRaw;
    }
}
orange_admin_profile_request_finish($page, $__orangeAdminProfileView, $__orangeAdminProfileReq);
