<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/admin_nav_tree.php';

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
 * الشاشات (صفحات الأدمن) المشمولة بكل مجموعة صلاحية — للعرض في مصفوفة المستخدمين.
 *
 * @return array<string, string>
 */
function orange_admin_resource_screen_hints(): array
{
    return [
        'dashboard' => 'الرئيسية',
        'catalog' => 'الأقسام، فروع الشجرة، أنواع المنتجات، سمات الكتالوج، قاموس الألوان، أنماط الألوان، قوالب/عائلات المقاسات، دليل المقاس الاسترشادي',
        'products' => 'المنتجات، عروض المنتجات، عروض التوصيل',
        'sales' => 'العملاء (عرض الطلبات)، الطلبات، الطلبات المحجوزة، طابور الطلبات، مناديب التوصيل، تسليم المندوب، فواتير أونلاين/مبيعات، فاتورة مبيعات، مردود المبيعات، إنشاء قيود التسليم',
        'warehouse' => 'المستودع، أرصدة أول المدة المخزنية، تسوية المخزون / الجرد، الموردين، المشتريات، مردود المشتريات، تقارير المخزن، تقارير المشتريات',
        'accounting' => 'الدليل المحاسبي، حسابات القيود، السنوات المالية، أرصدة أول المدة المالية، سندات القبض/الصرف/القيد، قيود الإقفال، إقفال التعديلات، التقارير المالية، ميزان المراجعة، أرباح وخسائر، تسوية البنك، كشف حساب…',
        'partners' => 'العملاء، الموردين، سداد فواتير آجلة، أرصدة ذمم العملاء/الموردين',
        'reports' => 'تقارير المبيعات، تحليل القنوات، سجل النشاط',
        'settings' => 'بيانات الشركة، الدول، قنوات العملاء، مناطق التوصيل، بانر المتجر، عروض السلة (مجموع/هدايا/BOGO/كومبو)، دمج هاتف التسجيل، أرشيف المستندات',
        'admin_users' => 'المستخدمون والصلاحيات (مشرف عام فقط)',
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
        'cart_promo_health' => 'products',
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
    'online_sales_invoice' => 'sales',
    'sales_invoices' => 'sales',
        'order_intake_queue' => 'sales',
        'invoice' => 'sales',
        'manual_order' => 'sales',
        'company_sales_invoice' => 'sales',
        'customers' => 'partners',
        'suppliers' => 'partners',
        'purchases' => 'warehouse',
        'purchase_returns' => 'warehouse',
        'purchase_reports' => 'warehouse',
        'opening_stock_balances' => 'warehouse',
        'stock_reports' => 'warehouse',
        'stock_adjustment_voucher' => 'accounting',
        'loyalty_earn_voucher' => 'accounting',
        'loyalty_expire_voucher' => 'accounting',
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
        'edit_lock' => 'accounting',
        'financial_report' => 'accounting',
        'report_gl_account_monthly' => 'accounting',
        'report_account_list' => 'accounting',
        'report_trading_account' => 'accounting',
        'report_trial_balance' => 'accounting',
        'report_income_statement' => 'accounting',
        'report_balance_sheet' => 'accounting',
        'accounting_reports_index' => 'accounting',
        'warehouse_purchases_index' => 'warehouse',
        'sales_promotions_index' => 'sales',
        'settings_index' => 'settings',
        'report_pl_monthly' => 'accounting',
        'report_pl_compare_years' => 'accounting',
        'report_cash_flow' => 'accounting',
        'report_analytical' => 'accounting',
        'bank_reconciliation' => 'accounting',
        'analytical_dimensions' => 'accounting',
        'inventory_reconciliation' => 'warehouse',
        'gl_account_settings' => 'accounting',
        'invoice_line_presets' => 'accounting',
        'bank_accounts' => 'accounting',
        'payment_review' => 'accounting',
        'partner_account_statement' => 'accounting',
        'partner_customer_receipt' => 'partners',
        'partner_supplier_payment' => 'partners',
        'partner_reports' => 'partners',
        'reports' => 'reports',
        'sales_reports' => 'reports',
        'channel_analytics' => 'reports',
        'logs' => 'reports',
        'company_settings' => 'settings',
        'storefront_hero' => 'settings',
        'storefront_merge_requests' => 'settings',
        'delivery_areas' => 'settings',
        'cart_promotions' => 'settings',
        'delivery_promotions' => 'products',
        'cart_gift_promotions' => 'settings',
        'cart_bogo_promotions' => 'settings',
        'cart_combo_promotions' => 'settings',
        'storefront_promo_messages' => 'settings',
        'channels' => 'settings',
        'countries' => 'settings',
        'country_screen_copy' => 'settings',
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
        'sales-invoices' => 'sales',
        'order_intake' => 'sales',
        'purchases' => 'warehouse',
        'purchase_returns' => 'warehouse',
        'stock' => 'warehouse',
        'journal' => 'accounting',
        'year_end_close' => 'accounting',
        'system' => 'accounting',
        'edit-lock' => 'accounting',
        'fiscal_years' => 'accounting',
        'opening_balances' => 'accounting',
        'bank-reconciliation' => 'accounting',
        'inventory-reconciliation' => 'warehouse',
        'analytical-dimensions' => 'accounting',
        'accounts' => 'accounting',
        'invoice-ancillary' => 'accounting',
        'settings' => 'settings',
        'partners' => 'partners',
        'customers' => 'partners',
        'suppliers' => 'partners',
        'reports' => 'reports',
        'channels' => 'settings',
        'company_documents' => 'settings',
        'delivery_areas' => 'settings',
        'cart_promotions' => 'settings',
        'delivery_promotions' => 'products',
        'cart_gift_promotions' => 'settings',
        'cart_bogo_promotions' => 'settings',
        'cart_combo_promotions' => 'settings',
        'storefront_promo_messages' => 'settings',
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
    if (preg_match('/(?:^|[-_])(delete|remove)(?:\.php)$/i', $base)) {
        return 'delete';
    }
    if (preg_match('/(?:^|[-_])export(?:[-_].*)?\.php$/i', $base)) {
        return 'export';
    }
    if (isset($_GET['export']) && trim((string) $_GET['export']) !== '') {
        return 'export';
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
 * @return array{has_lock:bool,has_unlock:bool,has_print:bool,has_export:bool}
 */
function orange_admin_permissions_cap_columns(PDO $pdo): array
{
    return [
        'has_lock' => orange_table_has_column($pdo, 'admin_permissions', 'can_lock'),
        'has_unlock' => orange_table_has_column($pdo, 'admin_permissions', 'can_unlock'),
        'has_print' => orange_table_has_column($pdo, 'admin_permissions', 'can_print'),
        'has_export' => orange_table_has_column($pdo, 'admin_permissions', 'can_export'),
    ];
}

/**
 * صفحة الأدمن المرتبطة بمسار API (إن وُجد).
 */
function orange_admin_api_page_from_script(): ?string
{
    $path = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

    static $prefixMap = [
        '/admin/api/settings/gl-accounts' => 'gl_account_settings',
    ];
    foreach ($prefixMap as $prefix => $page) {
        if (str_contains($path, $prefix)) {
            return $page;
        }
    }

    static $folderMap = [
        'departments' => 'departments',
        'product_types' => 'product_types',
        'colors' => 'color_dictionary',
        'patterns' => 'pattern_dictionary',
        'size_families' => 'size_families',
        'advisory_sizing_guides' => 'advisory_sizing_guides',
        'advisory_sizing_library' => 'advisory_sizing_guides',
        'size_scheme_templates' => 'size_scheme_templates',
        'sizing_dictionary' => 'sizing_dictionary',
        'catalog_attributes' => 'catalog_attributes',
        'unified_catalog' => 'unified_catalog_branches',
        'translate' => 'departments',
        'products' => 'products',
        'uploads' => 'products',
        'offers' => 'offers',
        'orders' => 'orders',
        'sales_returns' => 'sales_returns',
        'sales-invoices' => 'company_sales_invoice',
        'online-invoices' => 'online_sales_invoice',
        'order_intake' => 'order_intake_queue',
        'purchases' => 'purchases',
        'purchase_returns' => 'purchase_returns',
        'stock' => 'stock',
        'journal' => 'journal_entries',
        'year_end_close' => 'year_end_close_vouchers',
        'system' => 'fiscal_years',
        'edit-lock' => 'edit_lock',
        'fiscal_years' => 'fiscal_years',
        'opening_balances' => 'opening_balances',
        'bank-reconciliation' => 'bank_reconciliation',
        'inventory-reconciliation' => 'inventory_reconciliation',
        'stock-adjustment' => 'stock_adjustment_voucher',
        'opening-stock-voucher' => 'opening_stock_balances',
        'analytical-dimensions' => 'analytical_dimensions',
        'accounts' => 'chart_of_accounts',
        'invoice-ancillary' => 'invoice_line_presets',
        'settings' => 'company_settings',
        'partners' => 'partner_reports',
        'customers' => 'customers',
        'suppliers' => 'suppliers',
        'reports' => 'reports',
        'channels' => 'channels',
        'company_documents' => 'company_documents',
        'delivery_areas' => 'delivery_areas',
        'cart_promotions' => 'cart_promotions',
        'delivery_promotions' => 'delivery_promotions',
        'cart_gift_promotions' => 'cart_gift_promotions',
        'cart_bogo_promotions' => 'cart_bogo_promotions',
        'cart_combo_promotions' => 'cart_combo_promotions',
        'storefront_promo_messages' => 'storefront_promo_messages',
        'storefront' => 'storefront_hero',
        'countries' => 'countries',
        'country-screen-copy' => 'country_screen_copy',
        'admins' => 'admin_users',
    ];

    if (preg_match('#/admin/api/([^/]+)/#', $path, $m)) {
        return $folderMap[$m[1]] ?? null;
    }

    return null;
}

/**
 * @return array<string, array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}>
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
    $cols = orange_admin_permissions_cap_columns($pdo);
    $selectCols = 'resource_key, can_view, can_edit, can_delete';
    if ($cols['has_lock']) {
        $selectCols .= ', can_lock';
    }
    if ($cols['has_unlock']) {
        $selectCols .= ', can_unlock';
    }
    if ($cols['has_print']) {
        $selectCols .= ', can_print';
    }
    if ($cols['has_export']) {
        $selectCols .= ', can_export';
    }
    $st = $pdo->prepare(
        'SELECT ' . $selectCols . ' FROM admin_permissions WHERE admin_id = ?'
    );
    $st->execute([$adminId]);
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $k = (string) $row['resource_key'];
        $out[$k] = [
            'can_view' => (int) $row['can_view'] === 1,
            'can_edit' => (int) $row['can_edit'] === 1,
            'can_delete' => (int) $row['can_delete'] === 1,
            'can_lock' => $cols['has_lock'] && (int) ($row['can_lock'] ?? 0) === 1,
            'can_unlock' => $cols['has_unlock'] && (int) ($row['can_unlock'] ?? 0) === 1,
            'can_print' => $cols['has_print'] && (int) ($row['can_print'] ?? 0) === 1,
            'can_export' => $cols['has_export'] && (int) ($row['can_export'] ?? 0) === 1,
        ];
    }
    $cache[$adminId] = $out;

    return $out;
}

/**
 * @return array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}
 */
function orange_admin_empty_caps(): array
{
    return [
        'can_view' => false,
        'can_edit' => false,
        'can_delete' => false,
        'can_lock' => false,
        'can_unlock' => false,
        'can_print' => false,
        'can_export' => false,
    ];
}

/**
 * @return array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}
 */
function orange_admin_full_caps(): array
{
    return [
        'can_view' => true,
        'can_edit' => true,
        'can_delete' => true,
        'can_lock' => true,
        'can_unlock' => true,
        'can_print' => true,
        'can_export' => true,
    ];
}

/**
 * @param array<string, array{can_view:bool,can_edit:bool,can_delete:bool,can_lock?:bool,can_unlock?:bool,can_print?:bool,can_export?:bool}> $matrix
 * @return array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}|null
 */
function orange_admin_resolve_perm_row(array $matrix, string $page): ?array
{
    $pageKey = orange_admin_perm_storage_key($page);
    if (isset($matrix[$pageKey])) {
        return $matrix[$pageKey];
    }
    if ($page === 'sales_reports') {
        $legacyKey = orange_admin_perm_storage_key('reports');
        if (isset($matrix[$legacyKey])) {
            return $matrix[$legacyKey];
        }
    }
    $group = orange_admin_page_resource($page);
    if (isset($matrix[$group])) {
        return $matrix[$group];
    }

    return null;
}

/**
 * صلاحيات شاشة واحدة (page=…) — المصدر المعتمد للتحقق.
 *
 * @return array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}
 */
function orange_admin_caps_for_page(array $admin, PDO $pdo, string $page): array
{
    if (orange_admin_has_full_access($admin)) {
        return orange_admin_full_caps();
    }
    if ($page === 'admin_users') {
        return orange_admin_is_superuser($admin) ? orange_admin_full_caps() : orange_admin_empty_caps();
    }
    if ($page === 'countries') {
        return orange_admin_can_manage_countries($admin) ? orange_admin_full_caps() : orange_admin_empty_caps();
    }
    $matrix = orange_admin_permissions_matrix($pdo, (int) $admin['id']);
    if ($matrix === []) {
        if ($page === 'dashboard') {
            return [
                'can_view' => true,
                'can_edit' => false,
                'can_delete' => false,
                'can_lock' => false,
                'can_unlock' => false,
                'can_print' => false,
                'can_export' => false,
            ];
        }

        return orange_admin_empty_caps();
    }
    $row = orange_admin_resolve_perm_row($matrix, $page);
    if (!$row) {
        return orange_admin_empty_caps();
    }

    return [
        'can_view' => !empty($row['can_view']),
        'can_edit' => !empty($row['can_edit']),
        'can_delete' => !empty($row['can_delete']),
        'can_lock' => !empty($row['can_lock']),
        'can_unlock' => !empty($row['can_unlock']),
        'can_print' => !empty($row['can_print']),
        'can_export' => !empty($row['can_export']),
    ];
}

/** @alias orange_admin_caps_for_page */
function orange_admin_caps(array $admin, PDO $pdo, string $page): array
{
    return orange_admin_caps_for_page($admin, $pdo, $page);
}

/**
 * @return array<string, array{can_view:bool,can_edit:bool,can_delete:bool,can_lock:bool,can_unlock:bool,can_print:bool,can_export:bool}>
 */
function orange_admin_caps_all_pages(array $admin, PDO $pdo): array
{
    $out = [];
    foreach (orange_admin_permission_all_pages() as $page) {
        $out[$page] = orange_admin_caps_for_page($admin, $pdo, $page);
    }

    return $out;
}

function orange_admin_may_page(array $admin, PDO $pdo, string $page, string $action): bool
{
    $caps = orange_admin_caps_for_page($admin, $pdo, $page);
    if ($action === 'delete') {
        return $caps['can_delete'];
    }
    if ($action === 'edit') {
        return $caps['can_edit'];
    }
    if ($action === 'lock') {
        return $caps['can_lock'];
    }
    if ($action === 'unlock') {
        return $caps['can_unlock'];
    }
    if ($action === 'print') {
        return $caps['can_print'];
    }
    if ($action === 'export') {
        return $caps['can_export'];
    }

    return $caps['can_view'];
}

/** @deprecated استخدم orange_admin_may_page — للتوافق مع مجموعات قديمة */
function orange_admin_may(array $admin, PDO $pdo, string $resource, string $action): bool
{
    if (orange_admin_page_from_perm_key($resource) !== null) {
        return orange_admin_may_page($admin, $pdo, orange_admin_page_from_perm_key($resource), $action);
    }
    $pages = orange_admin_permission_pages_in_legacy_group($resource);
    if ($pages === []) {
        return orange_admin_may_page($admin, $pdo, $resource, $action);
    }
    foreach ($pages as $page) {
        if (orange_admin_may_page($admin, $pdo, $page, $action)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array{has_lock:bool,has_unlock:bool,has_print:bool,has_export:bool} $cols
 */
function orange_admin_permissions_select_tail(array $cols): string
{
    $tail = '';
    if ($cols['has_lock']) {
        $tail .= ', can_lock';
    }
    if ($cols['has_unlock']) {
        $tail .= ', can_unlock';
    }
    if ($cols['has_print']) {
        $tail .= ', can_print';
    }
    if ($cols['has_export']) {
        $tail .= ', can_export';
    }

    return $tail;
}

/**
 * @param array{has_lock:bool,has_unlock:bool,has_print:bool,has_export:bool} $cols
 */
function orange_admin_permissions_prepare_upsert(PDO $pdo, array $cols): PDOStatement
{
    $fields = ['admin_id', 'resource_key', 'can_view', 'can_edit', 'can_delete'];
    $merge = ['can_view', 'can_edit', 'can_delete'];
    if ($cols['has_lock']) {
        $fields[] = 'can_lock';
        $merge[] = 'can_lock';
    }
    if ($cols['has_unlock']) {
        $fields[] = 'can_unlock';
        $merge[] = 'can_unlock';
    }
    if ($cols['has_print']) {
        $fields[] = 'can_print';
        $merge[] = 'can_print';
    }
    if ($cols['has_export']) {
        $fields[] = 'can_export';
        $merge[] = 'can_export';
    }
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $updates = [];
    foreach ($merge as $col) {
        $updates[] = $col . ' = GREATEST(admin_permissions.' . $col . ', VALUES(' . $col . '))';
    }
    $sql = 'INSERT INTO admin_permissions (' . implode(', ', $fields) . ')
             VALUES (' . $placeholders . ')
             ON DUPLICATE KEY UPDATE
               ' . implode(",
               ", $updates);

    return $pdo->prepare($sql);
}

/**
 * @param array<string, mixed> $row
 * @param array{has_lock:bool,has_unlock:bool,has_print:bool,has_export:bool} $cols
 * @return list<int|string>
 */
function orange_admin_permissions_upsert_params(array $row, int $adminId, string $resourceKey, array $cols): array
{
    $params = [
        $adminId,
        $resourceKey,
        (int) ($row['can_view'] ?? 0),
        (int) ($row['can_edit'] ?? 0),
        (int) ($row['can_delete'] ?? 0),
    ];
    if ($cols['has_lock']) {
        $params[] = (int) ($row['can_lock'] ?? 0);
    }
    if ($cols['has_unlock']) {
        $params[] = (int) ($row['can_unlock'] ?? 0);
    }
    if ($cols['has_print']) {
        $params[] = (int) ($row['can_print'] ?? 0);
    }
    if ($cols['has_export']) {
        $params[] = (int) ($row['can_export'] ?? 0);
    }

    return $params;
}

function orange_admin_migrate_permissions_to_pages(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_admin_permissions_page_keys_v78';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        return;
    }
    $groupKeys = array_keys(orange_admin_resource_labels());
    $cols = orange_admin_permissions_cap_columns($pdo);
    $sel = $pdo->query(
        'SELECT admin_id, resource_key, can_view, can_edit, can_delete'
        . orange_admin_permissions_select_tail($cols)
        . ' FROM admin_permissions'
    );
    $rows = $sel ? $sel->fetchAll(PDO::FETCH_ASSOC) : [];
    $ins = orange_admin_permissions_prepare_upsert($pdo, $cols);
    foreach ($rows as $row) {
        $rk = (string) ($row['resource_key'] ?? '');
        if (str_starts_with($rk, 'page:') || !in_array($rk, $groupKeys, true)) {
            continue;
        }
        $pages = orange_admin_permission_pages_in_legacy_group($rk);
        foreach ($pages as $page) {
            $pk = orange_admin_perm_storage_key($page);
            $ins->execute(
                orange_admin_permissions_upsert_params($row, (int) $row['admin_id'], $pk, $cols)
            );
        }
    }
    $delPlace = implode(',', array_fill(0, count($groupKeys), '?'));
    $pdo->prepare('DELETE FROM admin_permissions WHERE resource_key IN (' . $delPlace . ')')->execute($groupKeys);
    try {
        $insMarker = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $insMarker->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] admin_permissions_page_keys_v78 marker: ' . $e->getMessage());
        }
    }
}

function orange_admin_purge_obsolete_page_permissions(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_admin_permissions_drop_obsolete_pages_v79';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        return;
    }
    try {
        $pdo->exec("DELETE FROM admin_permissions WHERE resource_key IN ('page:gl_posting')");
        $insMarker = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $insMarker->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] admin_permissions_drop_obsolete_pages_v79: ' . $e->getMessage());
        }
    }
}

/**
 * GAP-SALE-DOC-01 مرحلة 0 — نسخ صلاحيات page:manual_order إلى page:company_sales_invoice.
 */
function orange_admin_seed_company_sales_invoice_page_permissions(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_admin_permissions_seed_company_sales_invoice_v80';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        return;
    }
    $fromKey = 'page:manual_order';
    $toKey = 'page:company_sales_invoice';
    $cols = orange_admin_permissions_cap_columns($pdo);
    try {
        $sel = $pdo->prepare(
            'SELECT admin_id, can_view, can_edit, can_delete'
            . orange_admin_permissions_select_tail($cols)
            . ' FROM admin_permissions WHERE resource_key = ?'
        );
        $sel->execute([$fromKey]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ins = orange_admin_permissions_prepare_upsert($pdo, $cols);
        foreach ($rows as $row) {
            $ins->execute(
                orange_admin_permissions_upsert_params($row, (int) $row['admin_id'], $toKey, $cols)
            );
        }
        $insMarker = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $insMarker->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] admin_permissions_seed_company_sales_invoice_v80: ' . $e->getMessage());
        }
    }
}

/**
 * GAP-SALE-DOC-01 مرحلة 3 — نسخ صلاحيات page:online_invoices إلى page:online_sales_invoice.
 */
function orange_admin_seed_online_sales_invoice_page_permissions(PDO $pdo): void
{
    require_once __DIR__ . '/schema_migrations.php';
    $marker = 'php_admin_permissions_seed_online_sales_invoice_v81';
    if (orange_schema_migration_already_applied($pdo, $marker)) {
        return;
    }
    if (!orange_table_exists($pdo, 'admin_permissions')) {
        return;
    }
    $fromKey = 'page:online_invoices';
    $toKey = 'page:online_sales_invoice';
    $cols = orange_admin_permissions_cap_columns($pdo);
    try {
        $sel = $pdo->prepare(
            'SELECT admin_id, can_view, can_edit, can_delete'
            . orange_admin_permissions_select_tail($cols)
            . ' FROM admin_permissions WHERE resource_key = ?'
        );
        $sel->execute([$fromKey]);
        $rows = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ins = orange_admin_permissions_prepare_upsert($pdo, $cols);
        foreach ($rows as $row) {
            $ins->execute(
                orange_admin_permissions_upsert_params($row, (int) $row['admin_id'], $toKey, $cols)
            );
        }
        $insMarker = $pdo->prepare('INSERT INTO orange_schema_migrations (filename) VALUES (?)');
        $insMarker->execute([$marker]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] admin_permissions_seed_online_sales_invoice_v81: ' . $e->getMessage());
        }
    }
}

function orange_admin_is_superuser(array $admin): bool
{
    // صراحةً 1 فقط (تجنباً لسلوك PHP empty مع قيم غير متوقعة من PDO)
    return (int) ($admin['is_superuser'] ?? 0) === 1;
}

/**
 * سجل الأدmin الكامل (is_superuser، country_id، …) — لا تستخدم current_admin() للصلاحيات.
 *
 * @return array<string, mixed>|null
 */
function orange_admin_active_record(PDO $pdo): ?array
{
    if (isset($GLOBALS['orange_admin_active_record']) && is_array($GLOBALS['orange_admin_active_record'])) {
        return $GLOBALS['orange_admin_active_record'];
    }
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    try {
        $st = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $GLOBALS['orange_admin_active_record'] = $row;
        orange_admin_sync_session_country_lock($row);

        return $row;
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] orange_admin_active_record: ' . $e->getMessage());
        }

        return null;
    }
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
    if (!orange_admin_may_page($admin, $pdo, $page, 'view')) {
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
    if (str_contains($path, '/admin/api/edit-lock/')) {
        if (!orange_admin_may_page($admin, $pdo, 'edit_lock', 'view')) {
            json_response(['success' => false, 'message' => 'لا تملك صلاحية عرض إقفال التعديلات'], 403);
        }

        return;
    }
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
    $resource = orange_admin_api_page_from_script();
    $action = orange_admin_api_action_from_request();
    if ($resource !== null) {
        if (!orange_admin_may_page($admin, $pdo, $resource, $action)) {
            json_response(['success' => false, 'message' => 'لا تملك صلاحية لهذا الإجراء'], 403);
        }

        return;
    }
    $legacyResource = orange_admin_resolve_api_resource_from_script();
    if (!orange_admin_may($admin, $pdo, $legacyResource, $action)) {
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
    if ($page === 'country_screen_copy') {
        return orange_admin_has_full_access($admin);
    }
    return orange_admin_may_page($admin, $pdo, $page, 'view');
}
