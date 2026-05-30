<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/upload_paths.php';

/**
 * موارد الصلاحيات — مفتاح ثابت => عنوان عربي.
 *
 * @return array<string, string>
 */
function orange_admin_resource_labels(): array
{
    return [
        'dashboard' => 'الرئيسية',
        'catalog' => 'الهيكل (أقسام، فئات، ألوان، مقاسات)',
        'products' => 'المنتجات والعروض',
        'sales' => 'المبيعات والطلبات والفواتير',
        'warehouse' => 'المشتريات والمخزون',
        'accounting' => 'المحاسبة (دليل، قيود، سنوات، تقارير مالية)',
        'partners' => 'ذمم العملاء والموردين',
        'reports' => 'التقارير وسجل النشاط',
        'settings' => 'إعدادات الشركة والواجهات',
        'admin_users' => 'المستخدمون والصلاحيات',
    ];
}

/**
 * صفحة لوحة الإدارة => مورد صلاحية.
 */
function orange_admin_page_resource(string $page): string
{
    static $map = [
        'dashboard' => 'dashboard',
        'departments' => 'catalog',
        'unified_catalog_branches' => 'catalog',
        'product_types' => 'catalog',
        'color_dictionary' => 'catalog',
        'pattern_dictionary' => 'catalog',
        'size_families' => 'catalog',
        'advisory_sizing_guides' => 'catalog',
        'size_scheme_templates' => 'catalog',
        'sizing_dictionary' => 'catalog',
        'catalog_attributes' => 'catalog',
        'products' => 'products',
        'offers' => 'products',
    'orders' => 'sales',
    'sales_returns' => 'sales',
    'reserved_orders' => 'sales',
    'online_orders_final_posting' => 'sales',
    'delivery_agent_handover' => 'sales',
    'delivery_order_search' => 'sales',
    'invoice_edit' => 'sales',
    'delivery_handover_manifest' => 'sales',
    'delivery_agents' => 'sales',
    'online_invoices' => 'sales',
    'sales_invoices' => 'sales',
        'order_intake_queue' => 'sales',
        'invoice' => 'sales',
        'manual_order' => 'sales',
        'customers' => 'partners',
        'suppliers' => 'partners',
        'purchases' => 'warehouse',
        'purchase_returns' => 'warehouse',
        'stock' => 'warehouse',
        'opening_stock_balances' => 'warehouse',
        'item_card' => 'warehouse',
        'chart_of_accounts' => 'accounting',
        'fiscal_years' => 'accounting',
        'opening_balances' => 'accounting',
        'journal_entries' => 'accounting',
        'year_end_close_vouchers' => 'accounting',
        'receipt_voucher' => 'accounting',
        'payment_voucher' => 'accounting',
        'other_vouchers' => 'accounting',
        'journal_voucher_reports' => 'accounting',
        'journal_types' => 'accounting',
        'gl_posting' => 'accounting',
        'financial_report' => 'accounting',
        'accounting_reports_index' => 'accounting',
        'report_gl_account_monthly' => 'accounting',
        'report_account_list' => 'accounting',
        'report_trading_account' => 'accounting',
        'report_trial_balance' => 'accounting',
        'report_income_statement' => 'accounting',
        'report_pl_monthly' => 'accounting',
        'report_pl_compare_years' => 'accounting',
        'report_cash_flow' => 'accounting',
        'report_analytical' => 'accounting',
        'bank_reconciliation' => 'accounting',
        'analytical_dimensions' => 'accounting',
        'inventory_reconciliation' => 'warehouse',
        'gl_account_settings' => 'accounting',
        'partner_account_statement' => 'accounting',
        'partner_customer_receipt' => 'partners',
        'partner_supplier_payment' => 'partners',
        'partner_reports' => 'partners',
        'reports' => 'reports',
        'channel_analytics' => 'reports',
        'logs' => 'reports',
        'company_settings' => 'settings',
        'storefront_hero' => 'settings',
        'storefront_merge_requests' => 'settings',
        'delivery_areas' => 'settings',
        'cart_promotions' => 'settings',
        'cart_gift_promotions' => 'settings',
        'cart_bogo_promotions' => 'settings',
        'cart_combo_promotions' => 'settings',
        'channels' => 'settings',
        'countries' => 'settings',
        'company_documents' => 'settings',
        'admin_users' => 'admin_users',
    ];

    return $map[$page] ?? 'dashboard';
}

function orange_admin_api_folder_resource(string $folder): string
{
    static $map = [
        'departments' => 'catalog',
        'product_types' => 'catalog',
        'colors' => 'catalog',
        'patterns' => 'catalog',
        'size_families' => 'catalog',
        'advisory_sizing_guides' => 'catalog',
        'advisory_sizing_library' => 'catalog',
        'size_scheme_templates' => 'catalog',
        'sizing_dictionary' => 'catalog',
        'catalog_attributes' => 'catalog',
        'unified_catalog' => 'catalog',
        'translate' => 'catalog',
        'products' => 'products',
        'uploads' => 'products',
        'offers' => 'products',
        'orders' => 'sales',
        'sales_returns' => 'sales',
        'order_intake' => 'sales',
        'purchases' => 'warehouse',
        'purchase_returns' => 'warehouse',
        'stock' => 'warehouse',
        'journal' => 'accounting',
        'year_end_close' => 'accounting',
        'system' => 'accounting',
        'gl' => 'accounting',
        'fiscal_years' => 'accounting',
        'opening_balances' => 'accounting',
        'bank-reconciliation' => 'accounting',
        'inventory-reconciliation' => 'warehouse',
        'analytical-dimensions' => 'accounting',
        'accounts' => 'accounting',
        'settings' => 'settings',
        'partners' => 'partners',
        'customers' => 'partners',
        'suppliers' => 'partners',
        'reports' => 'reports',
        'channels' => 'settings',
        'company_documents' => 'settings',
        'delivery_areas' => 'settings',
        'cart_promotions' => 'settings',
        'cart_gift_promotions' => 'settings',
        'cart_bogo_promotions' => 'settings',
        'cart_combo_promotions' => 'settings',
        'storefront' => 'settings',
        'countries' => 'settings',
        'admins' => 'admin_users',
    ];

    return $map[$folder] ?? 'catalog';
}

function orange_admin_resolve_api_resource_from_script(): string
{
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (str_contains($path, '/admin/api/settings/gl-accounts')) {
        return 'accounting';
    }
    if (preg_match('#/admin/api/([^/]+)/#', $path, $m)) {
        return orange_admin_api_folder_resource($m[1]);
    }

    return 'catalog';
}

function orange_admin_api_action_from_request(): string
{
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $base = basename($path);
    if ($base === 'list.php') {
        return 'view';
    }
    if ($base === 'delete.php' || $base === 'remove.php') {
        return 'delete';
    }
    $m = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($m === 'DELETE') {
        return 'delete';
    }
    if (in_array($m, ['POST', 'PUT', 'PATCH'], true)) {
        return 'edit';
    }

    return 'view';
}

/**
 * @return array<string, array{can_view:bool,can_edit:bool,can_delete:bool}>
 */
function orange_admin_permissions_matrix(PDO $pdo, int $adminId): array
{
    static $cache = [];
    if (isset($cache[$adminId])) {
        return $cache[$adminId];
    }
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        $cache[$adminId] = [];

        return [];
    }
    $st = $pdo->prepare(
        'SELECT resource_key, can_view, can_edit, can_delete FROM admin_permissions WHERE admin_id = ?'
    );
    $st->execute([$adminId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $k = (string) $row['resource_key'];
        $out[$k] = [
            'can_view' => (int) $row['can_view'] === 1,
            'can_edit' => (int) $row['can_edit'] === 1,
            'can_delete' => (int) $row['can_delete'] === 1,
        ];
    }
    $cache[$adminId] = $out;

    return $out;
}

function orange_admin_is_superuser(array $admin): bool
{
    // صراحةً 1 فقط (تجنباً لسلوك PHP empty مع قيم غير متوقعة من PDO)
    return (int) ($admin['is_superuser'] ?? 0) === 1;
}

/**
 * مشرف عام — وصول كامل: superuser أو admins.country_id فارغ (بند 13.8).
 * فريق دولة (country_id محدد + غير superuser) لا يُعامَل كمشرف عام.
 * يعتمد على سجل admins فقط — لا على admin_country_lock في الجلسة.
 */
function orange_admin_has_full_access(array $admin): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }

    return (int) ($admin['country_id'] ?? 0) <= 0;
}

/**
 * يزامن قفل سياق الدولة: فريق دولة فقط؛ المشرف العام يختار من المبدّل (كوكي/GET).
 */
function orange_admin_sync_session_country_lock(array $admin): void
{
    if (orange_admin_has_full_access($admin)) {
        unset($_SESSION['admin_country_lock']);

        return;
    }
    $cid = (int) ($admin['country_id'] ?? 0);
    if ($cid > 0) {
        $_SESSION['admin_country_lock'] = $cid;
    } else {
        unset($_SESSION['admin_country_lock']);
    }
}

/** إدارة الدول والتهيئة الكاملة — للمشرف العام فقط. */
function orange_admin_can_manage_countries(array $admin): bool
{
    return orange_admin_has_full_access($admin);
}

function orange_admin_may(array $admin, PDO $pdo, string $resource, string $action): bool
{
    if (orange_admin_has_full_access($admin)) {
        return true;
    }
    $matrix = orange_admin_permissions_matrix($pdo, (int) $admin['id']);
    if ($matrix === []) {
        // بدون صفوف في admin_permissions: السماح بعرض الرئيسية فقط حتى يضيف المشرف العام صلاحيات
        return $resource === 'dashboard' && $action === 'view';
    }
    $row = $matrix[$resource] ?? null;
    if (!$row) {
        return false;
    }
    if ($action === 'delete') {
        return $row['can_delete'];
    }
    if ($action === 'edit') {
        return $row['can_edit'];
    }

    return $row['can_view'];
}

function orange_admin_require_page(array $admin, PDO $pdo, string $page): void
{
    if ($page === 'admin_users' && !orange_admin_is_superuser($admin)) {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ممنوع</title></head><body style="font-family:Cairo,sans-serif;padding:2rem;">'
            . '<h1>إدارة المستخدمين للمشرف العام فقط</h1><p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=dashboard'), ENT_QUOTES, 'UTF-8') . '">الرئيسية</a></p></body></html>';
        exit;
    }
    if ($page === 'countries') {
        if (!orange_admin_can_manage_countries($admin)) {
            header('Content-Type: text/html; charset=UTF-8');
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ممنوع</title></head><body style="font-family:Cairo,sans-serif;padding:2rem;">'
                . '<h1>إدارة الدول والتهيئة الكاملة للمشرف العام فقط</h1><p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=dashboard'), ENT_QUOTES, 'UTF-8') . '">الرئيسية</a></p></body></html>';
            exit;
        }
    }
    $res = orange_admin_page_resource($page);
    if (!orange_admin_may($admin, $pdo, $res, 'view')) {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ممنوع</title></head><body style="font-family:Cairo,sans-serif;padding:2rem;">'
            . '<h1>لا تملك صلاحية عرض هذه الصفحة</h1><p><a href="' . htmlspecialchars(storefront_public_path('/admin/index.php?page=dashboard'), ENT_QUOTES, 'UTF-8') . '">الرئيسية</a></p></body></html>';
        exit;
    }
}

function orange_admin_enforce_api(array $admin, PDO $pdo): void
{
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if (str_contains($path, '/admin/api/admins/')) {
        if (!orange_admin_is_superuser($admin)) {
            json_response(['success' => false, 'message' => 'إدارة المستخدمين متاحة للمشرف العام فقط'], 403);
        }

        return;
    }
    if (str_contains($path, '/admin/api/countries/')) {
        if (!orange_admin_can_manage_countries($admin)) {
            json_response(['success' => false, 'message' => 'إدارة الدول للمشرف العام فقط'], 403);
        }

        return;
    }
    $resource = orange_admin_resolve_api_resource_from_script();
    $action = orange_admin_api_action_from_request();
    if (!orange_admin_may($admin, $pdo, $resource, $action)) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية لهذا الإجراء'], 403);
    }
}

/** مبدّل سياق الدولة في الشريط — للمشرف العام فقط. */
function orange_admin_show_country_switcher(array $admin): bool
{
    return orange_admin_has_full_access($admin);
}

function orange_admin_nav_visible(array $admin, PDO $pdo, string $page): bool
{
    if ($page === 'admin_users') {
        return orange_admin_is_superuser($admin);
    }
    if ($page === 'countries') {
        return orange_admin_can_manage_countries($admin);
    }
    $res = orange_admin_page_resource($page);

    return orange_admin_may($admin, $pdo, $res, 'view');
}
