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
    'warehouse',
    'warehouse_purchases_index',
    'فهرس المخازن والمشتريات',
    '',
    [
        'stock_reports' => 'تقارير المخزون والأصناف.',
        'opening_stock_balances' => 'أرصدة افتتاحية للمخزون.',
        'inventory_reconciliation' => 'جرد وتسوية كميات المخزون.',
        'suppliers' => 'بيانات الموردين وربط الذمم.',
        'purchases' => 'فواتير ومستندات المشتريات.',
        'purchase_returns' => 'مردودات المشتريات.',
        'products' => 'إدارة المنتجات والمتغيرات.',
    ]
);
