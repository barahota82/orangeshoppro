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
    'sales',
    'sales_promotions_index',
    'فهرس المبيعات والعروض',
    '',
    [
        'customers' => 'سجل العملاء وربط الذمم.',
        'orders' => 'متابعة ومعالجة الطلبات.',
        'order_intake_queue' => 'طابور استقبال الطلبات الواردة.',
        'delivery_agents' => 'مناديب التوصيل والتسليم.',
        'online_sales_invoice' => 'فواتير مبيعات أونلاين.',
        'company_sales_invoice' => 'فواتير مبيعات الشركة.',
        'sales_returns' => 'مردودات المبيعات.',
        'offers' => 'عروض على منتجات محددة.',
        'cart_promotions' => 'خصومات على مجموع السلة.',
        'reports' => 'تحليل المبيعات العام.',
        'sales_reports' => 'تقارير المبيعات والإيرادات.',
        'channel_analytics' => 'تحليل أداء قنوات البيع.',
    ]
);
