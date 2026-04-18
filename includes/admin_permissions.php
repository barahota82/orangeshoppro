<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

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
        'expenses' => 'المصروفات',
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
        'categories' => 'catalog',
        'subcategories' => 'catalog',
        'color_dictionary' => 'catalog',
        'size_families' => 'catalog',
        'products' => 'products',
        'offers' => 'products',
        'orders' => 'sales',
        'invoice' => 'sales',
        'manual_order' => 'sales',
        'purchases' => 'warehouse',
        'stock' => 'warehouse',
        'item_card' => 'warehouse',
        'chart_of_accounts' => 'accounting',
        'fiscal_years' => 'accounting',
        'opening_balances' => 'accounting',
        'journal_entries' => 'accounting',
        'financial_report' => 'accounting',
        'gl_account_settings' => 'accounting',
        'partner_ledger' => 'partners',
        'partner_reports' => 'partners',
        'expenses' => 'expenses',
        'reports' => 'reports',
        'logs' => 'reports',
        'company_settings' => 'settings',
        'channels' => 'settings',
        'admin_users' => 'admin_users',
    ];

    return $map[$page] ?? 'dashboard';
}

function orange_admin_api_folder_resource(string $folder): string
{
    static $map = [
        'departments' => 'catalog',
        'categories' => 'catalog',
        'subcategories' => 'catalog',
        'colors' => 'catalog',
        'size_families' => 'catalog',
        'translate' => 'catalog',
        'products' => 'products',
        'uploads' => 'products',
        'offers' => 'products',
        'orders' => 'sales',
        'purchases' => 'warehouse',
        'stock' => 'warehouse',
        'journal' => 'accounting',
        'fiscal_years' => 'accounting',
        'opening_balances' => 'accounting',
        'accounts' => 'accounting',
        'settings' => 'settings',
        'partners' => 'partners',
        'customers' => 'partners',
        'expenses' => 'expenses',
        'reports' => 'reports',
        'channels' => 'settings',
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

function orange_admin_may(array $admin, PDO $pdo, string $resource, string $action): bool
{
    if (orange_admin_is_superuser($admin)) {
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
            . '<h1>إدارة المستخدمين للمشرف العام فقط</h1><p><a href="/admin/index.php?page=dashboard">الرئيسية</a></p></body></html>';
        exit;
    }
    $res = orange_admin_page_resource($page);
    if (!orange_admin_may($admin, $pdo, $res, 'view')) {
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>ممنوع</title></head><body style="font-family:Cairo,sans-serif;padding:2rem;">'
            . '<h1>لا تملك صلاحية عرض هذه الصفحة</h1><p><a href="/admin/index.php?page=dashboard">الرئيسية</a></p></body></html>';
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
    $resource = orange_admin_resolve_api_resource_from_script();
    $action = orange_admin_api_action_from_request();
    if (!orange_admin_may($admin, $pdo, $resource, $action)) {
        json_response(['success' => false, 'message' => 'لا تملك صلاحية لهذا الإجراء'], 403);
    }
}

function orange_admin_nav_visible(array $admin, PDO $pdo, string $page): bool
{
    if ($page === 'admin_users') {
        return orange_admin_is_superuser($admin);
    }
    $res = orange_admin_page_resource($page);

    return orange_admin_may($admin, $pdo, $res, 'view');
}
