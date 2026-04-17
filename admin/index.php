<?php
require_once __DIR__ . '/../config.php';
orange_send_html_no_cache_headers();
$admin = require_admin_page();
$page = $_GET['page'] ?? 'dashboard';

$allowed = [
    'dashboard',
    'company_settings',
    'departments',
    'categories',
    'color_dictionary',
    'size_families',
    'products',
    'offers',
    'orders',
    'manual_order',
    'purchases',
    'stock',
    'chart_of_accounts',
    'expenses',
    'journal_entries',
    'reports',
    'financial_report',
    'logs',
    'channels',
    'invoice'
];
if (!in_array($page, $allowed, true)) {
    $page = 'dashboard';
}

include __DIR__ . '/partials/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/partials/footer.php';
