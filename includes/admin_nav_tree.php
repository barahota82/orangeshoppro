<?php

declare(strict_types=1);

/**
 * شجرة صلاحيات لوحة التحكم — مطابقة لتقسيم القائمة العلوية (mega → subgroup → page).
 * مصدر واحد لمصفوفة المستخدمين؛ المجموعات اختصار في الواجهة فقط.
 *
 * @return list<array{id:string,title:string,subgroups:list<array{title:string,pages:list<array{page:string,label:string}>}>|null,page?:string,label?:string}>
 */
function orange_admin_permission_mega_sections(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }

    $tree = [
        [
            'id' => 'dashboard',
            'title' => 'الرئيسية',
            'subgroups' => null,
            'page' => 'dashboard',
            'label' => 'الرئيسية',
        ],
        [
            'id' => 'accounting',
            'title' => 'الحسابات والتقارير',
            'subgroups' => [
                [
                    'title' => 'الإعداد والدليل',
                    'pages' => [
                        ['page' => 'accounting_reports_index', 'label' => 'فهرس الحسابات والتقارير'],
                        ['page' => 'chart_of_accounts', 'label' => 'الدليل المحاسبي'],
                        ['page' => 'journal_types', 'label' => 'أنواع اليوميات'],
                        ['page' => 'gl_account_settings', 'label' => 'حسابات القيود التلقائية'],
                        ['page' => 'fiscal_years', 'label' => 'السنوات المالية'],
                        ['page' => 'edit_lock', 'label' => 'إقفال التعديلات'],
                        ['page' => 'invoice_line_presets', 'label' => 'قائمة بنود الفاتورة'],
                        ['page' => 'analytical_dimensions', 'label' => 'الأبعاد التحليلية'],
                        ['page' => 'opening_balances', 'label' => 'أرصدة أول المدة المالية'],
                    ],
                ],
                [
                    'title' => 'السندات والذمم',
                    'pages' => [
                        ['page' => 'journal_entries', 'label' => 'سند قيد'],
                        ['page' => 'receipt_voucher', 'label' => 'سند قبض'],
                        ['page' => 'payment_voucher', 'label' => 'سند صرف'],
                        ['page' => 'other_vouchers', 'label' => 'سندات أخرى'],
                        ['page' => 'partner_customer_receipt', 'label' => 'سداد فواتير مبيعات آجلة'],
                        ['page' => 'partner_supplier_payment', 'label' => 'سداد فواتير مشتريات آجلة'],
                        ['page' => 'bank_accounts', 'label' => 'الحسابات البنكية (دفع مباشر)'],
                        ['page' => 'payment_review', 'label' => 'مراجعة الدفعات'],
                        ['page' => 'bank_reconciliation', 'label' => 'تسوية البنك'],
                        ['page' => 'year_end_close_vouchers', 'label' => 'قيود الإقفال السنوية'],
                    ],
                ],
                [
                    'title' => 'التقارير',
                    'pages' => [
                        ['page' => 'journal_voucher_reports', 'label' => 'تقارير السندات'],
                        ['page' => 'partner_account_statement', 'label' => 'كشف حساب'],
                        ['page' => 'report_gl_account_monthly', 'label' => 'الحركة الشهرية لحساب'],
                        [
                            'page' => 'partner_reports',
                            'label' => 'أرصدة العملاء (ذمم)',
                            'href' => '/admin/index.php?page=partner_reports&view=customers',
                            'desc' => 'أرصدة عملاء الآجل ومطابقة الدليل مع دفتر الذمم.',
                        ],
                        [
                            'page' => 'partner_reports',
                            'label' => 'أرصدة الموردين (ذمم)',
                            'href' => '/admin/index.php?page=partner_reports&view=suppliers',
                            'desc' => 'أرصدة موردي الآجل ومطابقة الدليل مع دفتر الذمم.',
                        ],
                        ['page' => 'report_pl_monthly', 'label' => 'قائمة إيرادات ومصروفات شهرية'],
                        ['page' => 'report_trading_account', 'label' => 'قائمة حسابات المتاجرة'],
                        ['page' => 'report_cash_flow', 'label' => 'قائمة التدفقات النقدية'],
                        ['page' => 'report_income_statement', 'label' => 'أرباح وخسائر'],
                        ['page' => 'report_pl_compare_years', 'label' => 'أرباح وخسائر مقارنة بين السنوات'],
                        ['page' => 'report_trial_balance', 'label' => 'ميزان المراجعة'],
                        ['page' => 'report_balance_sheet', 'label' => 'الميزانية'],
                        ['page' => 'report_analytical', 'label' => 'التقرير التحليلي'],
                        ['page' => 'report_account_list', 'label' => 'قائمة الحسابات'],
                        ['page' => 'financial_report', 'label' => 'التقارير المالية (الصفحة كاملة)'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'warehouse',
            'title' => 'المخازن والمشتريات',
            'subgroups' => [
                [
                    'title' => 'المخزون',
                    'pages' => [
                        ['page' => 'warehouse_purchases_index', 'label' => 'فهرس المخازن والمشتريات'],
                        ['page' => 'stock', 'label' => 'المستودع'],
                        ['page' => 'stock_reports', 'label' => 'تقارير المخزن'],
                        ['page' => 'opening_stock_balances', 'label' => 'أرصدة أول المدة المخزنية'],
                        ['page' => 'inventory_reconciliation', 'label' => 'تسوية المخزون / الجرد'],
                    ],
                ],
                [
                    'title' => 'المشتريات',
                    'pages' => [
                        ['page' => 'suppliers', 'label' => 'الموردين'],
                        ['page' => 'purchases', 'label' => 'المشتريات'],
                        ['page' => 'purchase_returns', 'label' => 'مردود المشتريات'],
                    ],
                ],
                [
                    'title' => 'هيكل الكتالوج والمنتجات',
                    'pages' => [
                        ['page' => 'departments', 'label' => 'الأقسام الرئيسية'],
                        ['page' => 'unified_catalog_branches', 'label' => 'فروع شجرة المنتجات'],
                        ['page' => 'product_types', 'label' => 'أنواع المنتجات الموحدة'],
                        ['page' => 'catalog_attributes', 'label' => 'سمات الكتالوج'],
                        ['page' => 'color_dictionary', 'label' => 'قاموس الألوان'],
                        ['page' => 'pattern_dictionary', 'label' => 'أنماط الألوان'],
                        ['page' => 'size_scheme_templates', 'label' => 'قوالب المقاسات'],
                        ['page' => 'sizing_dictionary', 'label' => 'قاموس هرم المقاسات (1–2)'],
                        ['page' => 'size_families', 'label' => 'عائلات المقاسات (3–4)'],
                        ['page' => 'advisory_sizing_guides', 'label' => 'دليل المقاس الاسترشادي'],
                        ['page' => 'products', 'label' => 'المنتجات'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'sales',
            'title' => 'المبيعات والعروض',
            'subgroups' => [
                [
                    'title' => 'العملاء والطلبات',
                    'pages' => [
                        ['page' => 'sales_promotions_index', 'label' => 'فهرس المبيعات والعروض'],
                        ['page' => 'customers', 'label' => 'العملاء'],
                        ['page' => 'orders', 'label' => 'الطلبات'],
                        ['page' => 'reserved_orders', 'label' => 'طلبات محجوزة (مخزون)'],
                        ['page' => 'order_intake_queue', 'label' => 'طابور الطلبات'],
                    ],
                ],
                [
                    'title' => 'التوصيل والتسليم',
                    'pages' => [
                        ['page' => 'delivery_agents', 'label' => 'مناديب التوصيل'],
                        ['page' => 'delivery_agent_handover', 'label' => 'تسليم المندوب'],
                        ['page' => 'delivery_order_search', 'label' => 'بحث التسليم'],
                        ['page' => 'delivery_handover_manifest', 'label' => 'ورقة المندوب'],
                        ['page' => 'online_orders_final_posting', 'label' => 'إنشاء قيود التسليم'],
                    ],
                ],
                [
                    'title' => 'الفواتير والمردود',
                    'pages' => [
                        ['page' => 'invoice', 'label' => 'طباعة فاتورة طلب'],
                        ['page' => 'online_sales_invoice', 'label' => 'فواتير أونلاين'],
                        ['page' => 'company_sales_invoice', 'label' => 'فاتورة مبيعات'],
                        ['page' => 'sales_returns', 'label' => 'مردود المبيعات'],
                    ],
                ],
                [
                    'title' => 'العروض',
                    'pages' => [
                        ['page' => 'offers', 'label' => 'عروض المنتجات'],
                        ['page' => 'cart_promotions', 'label' => 'عروض مجموع السلة'],
                        ['page' => 'cart_gift_promotions', 'label' => 'عروض الهدايا (س4)'],
                        ['page' => 'cart_bogo_promotions', 'label' => 'عروض BOGO (س4)'],
                        ['page' => 'cart_combo_promotions', 'label' => 'عروض الكومبو'],
                        ['page' => 'cart_promo_health', 'label' => 'صحة العروض (مخزون)'],
                    ],
                ],
                [
                    'title' => 'تقارير المبيعات',
                    'pages' => [
                        ['page' => 'reports', 'label' => 'تقارير المبيعات'],
                        ['page' => 'channel_analytics', 'label' => 'تحليل القنوات'],
                        ['page' => 'sales_returns_report', 'label' => 'تقرير مردودات المبيعات'],
                    ],
                ],
            ],
        ],
        [
            'id' => 'settings',
            'title' => 'الإعدادات',
            'subgroups' => [
                [
                    'title' => 'الشركة',
                    'pages' => [
                        ['page' => 'settings_index', 'label' => 'فهرس الإعدادات'],
                        ['page' => 'company_settings', 'label' => 'بيانات الشركة'],
                        ['page' => 'company_documents', 'label' => 'أرشيف المستندات'],
                        ['page' => 'logs', 'label' => 'سجل النشاط'],
                    ],
                ],
                [
                    'title' => 'الأسواق (مشرف عام)',
                    'pages' => [
                        ['page' => 'countries', 'label' => 'الدول'],
                        ['page' => 'admin_users', 'label' => 'المستخدمون والصلاحيات'],
                    ],
                ],
                [
                    'title' => 'السوق الحالي',
                    'pages' => [
                        ['page' => 'channels', 'label' => 'قنوات العملاء'],
                        ['page' => 'delivery_areas', 'label' => 'محافظات ومناطق التوصيل'],
                        ['page' => 'storefront_hero', 'label' => 'بانر الصفحة الرئيسية'],
                        ['page' => 'storefront_merge_requests', 'label' => 'دمج هاتف التسجيل (س15)'],
                    ],
                ],
            ],
        ],
    ];

    return $tree;
}

/** @return array<string, string> page => label */
function orange_admin_permission_page_labels(): array
{
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }
    $labels = [];
    foreach (orange_admin_permission_mega_sections() as $mega) {
        if (!empty($mega['page'])) {
            $labels[(string) $mega['page']] = (string) ($mega['label'] ?? $mega['page']);
            continue;
        }
        foreach ($mega['subgroups'] ?? [] as $sg) {
            foreach ($sg['pages'] ?? [] as $p) {
                $pg = (string) ($p['page'] ?? '');
                if ($pg !== '' && !isset($labels[$pg])) {
                    $labels[$pg] = (string) ($p['label'] ?? $pg);
                }
            }
        }
    }

    return $labels;
}

/** @return list<string> */
function orange_admin_permission_all_pages(): array
{
    return array_keys(orange_admin_permission_page_labels());
}

/** @return list<string> */
function orange_admin_permission_pages_in_mega(string $megaId): array
{
    $out = [];
    foreach (orange_admin_permission_mega_sections() as $mega) {
        if ((string) ($mega['id'] ?? '') !== $megaId) {
            continue;
        }
        if (!empty($mega['page'])) {
            return [(string) $mega['page']];
        }
        foreach ($mega['subgroups'] ?? [] as $sg) {
            foreach ($sg['pages'] ?? [] as $p) {
                $pg = (string) ($p['page'] ?? '');
                if ($pg !== '' && !in_array($pg, $out, true)) {
                    $out[] = $pg;
                }
            }
        }

        return $out;
    }

    return [];
}

/** @return list<string> صفحات مجموعة الصلاحيات القديمة (catalog، sales، …) */
function orange_admin_permission_pages_in_legacy_group(string $groupKey): array
{
    $out = [];
    foreach (orange_admin_permission_all_pages() as $page) {
        if (orange_admin_page_resource($page) === $groupKey) {
            $out[] = $page;
        }
    }

    return $out;
}

function orange_admin_perm_storage_key(string $page): string
{
    return 'page:' . $page;
}

function orange_admin_page_from_perm_key(string $key): ?string
{
    if (str_starts_with($key, 'page:')) {
        $page = substr($key, 5);

        return $page !== '' ? $page : null;
    }

    return null;
}

/**
 * أعمدة الصلاحيات المناسبة لكل شاشة — لا تُعرض في المصفوفة إلا ما ينطبق فعلياً.
 *
 * @return list<'view'|'edit'|'delete'|'lock'|'unlock'>
 */
function orange_admin_permission_actions_for_page(string $page): array
{
    static $viewOnly = [
        'dashboard',
        'reports',
        'channel_analytics',
        'sales_returns_report',
        'logs',
        'accounting_reports_index',
        'warehouse_purchases_index',
        'sales_promotions_index',
        'settings_index',
        'journal_voucher_reports',
        'partner_account_statement',
        'partner_reports',
        'report_account_list',
        'report_gl_account_monthly',
        'report_income_statement',
        'report_trading_account',
        'report_pl_monthly',
        'report_pl_compare_years',
        'report_trial_balance',
        'report_cash_flow',
        'report_analytical',
        'financial_report',
        'item_card',
        'delivery_order_search',
        'delivery_handover_manifest',
    ];

    static $editLockScreen = [
        'edit_lock',
    ];

    static $documentPages = [
        'purchases',
        'purchase_returns',
        'company_sales_invoice',
        'online_sales_invoice',
        'sales_returns',
        'journal_entries',
        'year_end_close_vouchers',
        'receipt_voucher',
        'payment_voucher',
        'other_vouchers',
        'opening_balances',
        'opening_stock_balances',
        'partner_customer_receipt',
        'partner_supplier_payment',
    ];

    static $viewEditNoDelete = [
        'company_settings',
        'storefront_hero',
        'storefront_merge_requests',
        'delivery_areas',
        'channels',
        'bank_reconciliation',
        'inventory_reconciliation',
    ];

    if (in_array($page, $viewOnly, true)) {
        return ['view'];
    }
    if (in_array($page, $editLockScreen, true)) {
        return ['view', 'lock', 'unlock'];
    }
    if (in_array($page, $documentPages, true)) {
        return ['view', 'edit', 'delete', 'lock', 'unlock'];
    }
    if (in_array($page, $viewEditNoDelete, true)) {
        return ['view', 'edit'];
    }

    return ['view', 'edit', 'delete'];
}

/** @return array<string, list<string>> */
function orange_admin_permission_page_actions_map(): array
{
    $out = [];
    foreach (orange_admin_permission_all_pages() as $page) {
        $out[$page] = orange_admin_permission_actions_for_page($page);
    }

    return $out;
}
