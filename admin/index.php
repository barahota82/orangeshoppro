<?php
require_once __DIR__ . '/../config.php';
orange_send_html_no_cache_headers();
require_once __DIR__ . '/../includes/catalog_schema.php';
require_once __DIR__ . '/../includes/admin_permissions.php';
$admin = require_admin_page();
$page = $_GET['page'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'admin_users',
    'company_settings',
    'storefront_hero',
    'storefront_merge_requests',
    'delivery_areas',
    'cart_promotions',
    'cart_gift_promotions',
    'cart_bogo_promotions',
    'cart_combo_promotions',
    'departments',
    'categories',
    'subcategories',
    'color_dictionary',
    'size_families',
    'products',
    'offers',
    'orders',
    'reserved_orders',
    'sales_returns',
    'manual_order',
    'customers',
    'suppliers',
    'purchases',
    'purchase_returns',
    'stock',
    'item_card',
    'chart_of_accounts',
    'fiscal_years',
    'opening_balances',
    'opening_stock_balances',
    'partner_ledger',
    'partner_customer_receipt',
    'partner_supplier_payment',
    'partner_reports',
    'gl_account_settings',
    'expenses',
    'journal_entries',
    'journal_voucher_reports',
    'journal_types',
    'gl_posting',
    'reports',
    'financial_report',
    'logs',
    'channels',
    'company_documents',
    'channel_analytics',
    'invoice',
    'order_intake_queue'
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

$pdo = db();
orange_catalog_ensure_schema($pdo);
orange_admin_require_page($admin, $pdo, $page);

include __DIR__ . '/partials/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/partials/footer.php';
