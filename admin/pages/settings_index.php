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
    'settings',
    'settings_index',
    'فهرس الإعدادات',
    '',
    [
        'company_settings' => 'بيانات الشركة والشعار للطباعة.',
        'company_documents' => 'أرشيف مستندات الشركة.',
        'logs' => 'سجل نشاط المستخدمين.',
        'backup_center' => 'إدارة النسخ الاحتياطي — شامل + نسخ الدول.',
        'countries' => 'إدارة الدول والأسواق.',
        'admin_users' => 'المستخدمون وصلاحيات الشاشات.',
        'channels' => 'قنوات البيع للعملاء.',
        'delivery_areas' => 'محافظات ومناطق التوصيل.',
        'product_display_order' => 'ترتيب عرض المنتجات في المتجر (حسب الفئة).',
        'storefront_hero' => 'بانر الصفحة الرئيسية للمتجر.',
        'storefront_promo_messages' => 'الرسائل التحفيزية في واجهة المتجر.',
        'storefront_merge_requests' => 'طلبات دمج هاتف التسجيل.',
    ]
);
