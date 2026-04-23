<?php
if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}
if (!isset($admin)) {
    $admin = require_admin_page();
}
$orangeAdminPage = isset($page) ? (string) $page : 'dashboard';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/admin_permissions.php';
require_once __DIR__ . '/../../includes/upload_paths.php';
$pdoNav = db();
orange_catalog_ensure_schema($pdoNav);

$orangeAdminCompanyTitle = '';
try {
    if (orange_table_exists($pdoNav, 'company_settings')) {
        $br = $pdoNav->query('SELECT company_name_ar, company_name_en FROM company_settings ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($br) {
            $orangeAdminCompanyTitle = trim((string) ($br['company_name_ar'] ?? ''));
            if ($orangeAdminCompanyTitle === '') {
                $orangeAdminCompanyTitle = trim((string) ($br['company_name_en'] ?? ''));
            }
        }
    }
} catch (Throwable $e) {
    $orangeAdminCompanyTitle = '';
}
$orangeSchemaDegradedAttr = (defined('ORANGE_SCHEMA_DEGRADED') && ORANGE_SCHEMA_DEGRADED)
    ? ' data-orange-schema-degraded="1"'
    : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl"<?php echo $orangeSchemaDegradedAttr; ?>>
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($orangeAdminCompanyTitle !== '' ? $orangeAdminCompanyTitle . ' — لوحة التحكم' : 'لوحة التحكم', ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin.css')), ENT_QUOTES, 'UTF-8'); ?>">
    <script>window.ORANGE_PUBLIC_BASE_PATH = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin-money-fields.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body>
<div class="admin-layout">
    <div class="admin-header-wrap">
    <header class="admin-topbar">
        <div class="admin-topbar-strip">
            <button type="button" class="admin-menu-toggle" id="admin-menu-toggle" aria-expanded="false" aria-controls="admin-nav-drawer" aria-label="فتح وإغلاق قائمة لوحة التحكم">
                <span class="admin-menu-toggle__icon" aria-hidden="true">☰</span>
                <span class="admin-menu-toggle__text">القائمة</span>
            </button>
            <div class="admin-topbar-brand" role="banner">
                <div class="admin-sidebar-brand__mark" aria-hidden="true"></div>
                <div class="admin-sidebar-brand__text">
                    <div class="admin-sidebar-brand__title"><?php echo htmlspecialchars($orangeAdminCompanyTitle !== '' ? $orangeAdminCompanyTitle : 'Orange', ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="admin-sidebar-brand__subtitle">لوحة التحكم المؤسسية</div>
                </div>
            </div>
            <?php
            /**
             * @param array{page:string,href:string,label:string,class:string,sub:bool} $nl
             */
            $orangeNavLinkActive = static function (array $nl) use ($orangeAdminPage): bool {
                return ($nl['page'] === 'stock' && ($orangeAdminPage === 'stock' || $orangeAdminPage === 'item_card'))
                    || $orangeAdminPage === $nl['page'];
            };

            $orangeRenderNavLink = static function (array $nl) use ($admin, $pdoNav, $orangeNavLinkActive): void {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    return;
                }
                $active = $orangeNavLinkActive($nl);
                $cls = trim($nl['class'] . ($active ? ' is-active' : ''));
                echo '<a href="' . htmlspecialchars(storefront_public_path((string) $nl['href']), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            };

            $orangeRenderNavLinkMega = static function (array $nl) use ($admin, $pdoNav, $orangeNavLinkActive): void {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    return;
                }
                $active = $orangeNavLinkActive($nl);
                $cls = trim($nl['class'] . ' admin-mega-link' . ($active ? ' is-active' : ''));
                echo '<a href="' . htmlspecialchars(storefront_public_path((string) $nl['href']), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            };

            /**
             * @param array<int, array<string, mixed>> $items روابط أو مجموعات ['group'=>true,'title'=>'...','items'=>[...]]
             * @return array{0:bool,1:bool}
             */
            $orangeNavSectionMeta = static function (array $items) use ($admin, $pdoNav, $orangeNavLinkActive): array {
                $scan = null;
                $scan = static function (array $nodes) use (&$scan, $admin, $pdoNav, $orangeNavLinkActive): array {
                    $anyVisible = false;
                    $hasActive = false;
                    foreach ($nodes as $nl) {
                        if (!empty($nl['group']) && !empty($nl['items']) && is_array($nl['items'])) {
                            [$a, $b] = $scan($nl['items']);
                            $anyVisible = $anyVisible || $a;
                            $hasActive = $hasActive || $b;
                            continue;
                        }
                        if (!is_array($nl) || !isset($nl['page'])) {
                            continue;
                        }
                        if (!orange_admin_nav_visible($admin, $pdoNav, (string) $nl['page'])) {
                            continue;
                        }
                        $anyVisible = true;
                        if ($orangeNavLinkActive($nl)) {
                            $hasActive = true;
                        }
                    }

                    return [$anyVisible, $hasActive];
                };

                return $scan($items);
            };

            /** @param array<int, array<string, mixed>> $items */
            $orangeRenderNavSection = static function (string $sectionId, string $title, array $items) use ($admin, $pdoNav, $orangeRenderNavLink, $orangeNavLinkActive, $orangeNavSectionMeta): void {
                [$anyVisible, $hasActive] = $orangeNavSectionMeta($items);
                if (!$anyVisible) {
                    return;
                }
                $sid = htmlspecialchars($sectionId, ENT_QUOTES, 'UTF-8');
                $panelId = 'nav-section-' . $sid;
                $btnId = $panelId . '-btn';
                $wrapClass = 'admin-nav-section';
                if ($sectionId === 'settings') {
                    $wrapClass .= ' admin-nav-section--muted';
                }
                echo '<div class="' . $wrapClass . '" data-nav-section="' . $sid . '" data-default-open="' . ($hasActive ? '1' : '0') . '">';
                echo '<button type="button" class="admin-nav-section-toggle" id="' . $btnId . '" aria-expanded="true" aria-controls="' . $panelId . '">';
                echo '<span class="admin-nav-section-toggle-label">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<span class="admin-nav-section-chevron" aria-hidden="true">▼</span>';
                echo '</button>';
                echo '<div class="admin-nav-section-panel" id="' . $panelId . '" role="region" aria-labelledby="' . $btnId . '">';
                foreach ($items as $nl) {
                    if (!empty($nl['group']) && !empty($nl['items']) && is_array($nl['items'])) {
                        $subAny = false;
                        foreach ($nl['items'] as $c) {
                            if (is_array($c) && isset($c['page']) && orange_admin_nav_visible($admin, $pdoNav, (string) $c['page'])) {
                                $subAny = true;
                                break;
                            }
                        }
                        if (!$subAny) {
                            continue;
                        }
                        $gtitle = htmlspecialchars((string) ($nl['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                        echo '<div class="admin-nav-subgroup" role="group" aria-label="' . $gtitle . '">';
                        echo '<div class="admin-nav-subgroup__title">' . $gtitle . '</div>';
                        foreach ($nl['items'] as $c) {
                            if (is_array($c)) {
                                $orangeRenderNavLink($c);
                            }
                        }
                        echo '</div>';
                        continue;
                    }
                    if (is_array($nl)) {
                        $orangeRenderNavLink($nl);
                    }
                }
                echo '</div></div>';
            };

            /** مجموعة داخل لوحة الميجا */
            $orangeRenderNavMegaGroup = static function (array $group) use ($admin, $pdoNav, $orangeRenderNavLinkMega): void {
                if (empty($group['items']) || !is_array($group['items'])) {
                    return;
                }
                $subAny = false;
                foreach ($group['items'] as $c) {
                    if (is_array($c) && isset($c['page']) && orange_admin_nav_visible($admin, $pdoNav, (string) $c['page'])) {
                        $subAny = true;
                        break;
                    }
                }
                if (!$subAny) {
                    return;
                }
                $gtitle = htmlspecialchars((string) ($group['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                echo '<div class="admin-mega-group" role="group" aria-label="' . $gtitle . '">';
                echo '<div class="admin-mega-group__title">' . $gtitle . '</div>';
                echo '<div class="admin-mega-group__links">';
                foreach ($group['items'] as $c) {
                    if (is_array($c)) {
                        $orangeRenderNavLinkMega($c);
                    }
                }
                echo '</div></div>';
            };

            $navDashboard = [
                ['page' => 'dashboard', 'href' => '/admin/index.php?page=dashboard', 'label' => 'الرئيسية', 'class' => '', 'sub' => false],
            ];

            $navAccountingVouchers = [
                ['page' => 'journal_entries', 'href' => '/admin/index.php?page=journal_entries', 'label' => 'سند قيد', 'class' => '', 'sub' => false],
                ['page' => 'partner_ledger', 'href' => '/admin/index.php?page=partner_ledger#partner-receipt-voucher', 'label' => 'سند قبض', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'partner_ledger', 'href' => '/admin/index.php?page=partner_ledger#partner-payment-voucher', 'label' => 'سند صرف', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'expenses', 'href' => '/admin/index.php?page=expenses', 'label' => 'المصروفات', 'class' => '', 'sub' => false],
                ['page' => 'partner_ledger', 'href' => '/admin/index.php?page=partner_ledger', 'label' => 'ذمم العملاء', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navAccountingReports = [
                ['page' => 'partner_ledger', 'href' => '/admin/index.php?page=partner_ledger#partner-account-statement', 'label' => 'كشف حساب', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'partner_reports', 'href' => '/admin/index.php?page=partner_reports', 'label' => 'تقارير الذمم المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'financial_report', 'href' => '/admin/index.php?page=financial_report', 'label' => 'التقارير المالية', 'class' => '', 'sub' => false],
            ];

            /* الحسابات العامة: الدليل والسنوات والترحيل — سجل النشاط تحت الإعدادات العامة */
            $navAccounting = [
                ['page' => 'chart_of_accounts', 'href' => '/admin/index.php?page=chart_of_accounts', 'label' => 'الدليل المحاسبي', 'class' => '', 'sub' => false],
                ['page' => 'journal_types', 'href' => '/admin/index.php?page=journal_types', 'label' => 'أنواع اليوميات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'fiscal_years', 'href' => '/admin/index.php?page=fiscal_years', 'label' => 'السنوات المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'gl_account_settings', 'href' => '/admin/index.php?page=gl_account_settings', 'label' => 'حسابات القيود التلقائية', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'gl_posting', 'href' => '/admin/index.php?page=gl_posting', 'label' => 'إقفال الحركات (ترحيل)', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'opening_balances', 'href' => '/admin/index.php?page=opening_balances', 'label' => 'أرصدة أول المدة المالية', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navOps = [
                ['page' => 'departments', 'href' => '/admin/index.php?page=departments', 'label' => 'الأقسام', 'class' => '', 'sub' => false],
                ['page' => 'categories', 'href' => '/admin/index.php?page=categories', 'label' => 'الفئات', 'class' => '', 'sub' => false],
                ['page' => 'subcategories', 'href' => '/admin/index.php?page=subcategories', 'label' => 'فئات فرعية', 'class' => '', 'sub' => false],
                ['page' => 'color_dictionary', 'href' => '/admin/index.php?page=color_dictionary', 'label' => 'قاموس الألوان', 'class' => '', 'sub' => false],
                ['page' => 'size_families', 'href' => '/admin/index.php?page=size_families', 'label' => 'عائلات المقاسات', 'class' => '', 'sub' => false],
                ['page' => 'products', 'href' => '/admin/index.php?page=products', 'label' => 'المنتجات', 'class' => '', 'sub' => false],
                ['page' => 'offers', 'href' => '/admin/index.php?page=offers', 'label' => 'العروض', 'class' => '', 'sub' => false],
                ['page' => 'stock', 'href' => '/admin/index.php?page=stock', 'label' => 'المستودع', 'class' => '', 'sub' => false],
                ['page' => 'opening_stock_balances', 'href' => '/admin/index.php?page=opening_stock_balances', 'label' => 'أرصدة أول المدة المخزنية', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navPurchasing = [
                ['page' => 'suppliers', 'href' => '/admin/index.php?page=suppliers', 'label' => 'الموردين', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'purchases', 'href' => '/admin/index.php?page=purchases', 'label' => 'المشتريات', 'class' => '', 'sub' => false],
            ];

            $navSales = [
                ['page' => 'customers', 'href' => '/admin/index.php?page=customers', 'label' => 'العملاء', 'class' => '', 'sub' => false],
                ['page' => 'orders', 'href' => '/admin/index.php?page=orders', 'label' => 'الطلبات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'reserved_orders', 'href' => '/admin/index.php?page=reserved_orders', 'label' => 'طلبات محجوزة (مخزون)', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'order_intake_queue', 'href' => '/admin/index.php?page=order_intake_queue', 'label' => 'طابور الطلبات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'invoice', 'href' => '/admin/index.php?page=invoice', 'label' => 'فاتورة أونلاين', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'manual_order', 'href' => '/admin/index.php?page=manual_order', 'label' => 'فاتورة مبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'reports', 'href' => '/admin/index.php?page=reports', 'label' => 'تقارير المبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'channel_analytics', 'href' => '/admin/index.php?page=channel_analytics', 'label' => 'تحليل القنوات', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $navSettings = [
                ['page' => 'company_settings', 'href' => '/admin/index.php?page=company_settings', 'label' => 'بيانات الشركة', 'class' => '', 'sub' => false],
                ['page' => 'storefront_hero', 'href' => '/admin/index.php?page=storefront_hero', 'label' => 'بانر الصفحة الرئيسية', 'class' => '', 'sub' => false],
                ['page' => 'storefront_merge_requests', 'href' => '/admin/index.php?page=storefront_merge_requests', 'label' => 'دمج هاتف التسجيل (س15)', 'class' => '', 'sub' => false],
                ['page' => 'delivery_areas', 'href' => '/admin/index.php?page=delivery_areas', 'label' => 'مناطق التوصيل', 'class' => '', 'sub' => false],
                ['page' => 'cart_promotions', 'href' => '/admin/index.php?page=cart_promotions', 'label' => 'عروض مجموع السلة', 'class' => '', 'sub' => false],
                ['page' => 'cart_gift_promotions', 'href' => '/admin/index.php?page=cart_gift_promotions', 'label' => 'عروض الهدايا (س4)', 'class' => '', 'sub' => false],
                ['page' => 'cart_bogo_promotions', 'href' => '/admin/index.php?page=cart_bogo_promotions', 'label' => 'عروض BOGO (س4)', 'class' => '', 'sub' => false],
                ['page' => 'cart_combo_promotions', 'href' => '/admin/index.php?page=cart_combo_promotions', 'label' => 'عروض الكومبو', 'class' => '', 'sub' => false],
                ['page' => 'channels', 'href' => '/admin/index.php?page=channels', 'label' => 'قنوات العملاء', 'class' => '', 'sub' => false],
                ['page' => 'company_documents', 'href' => '/admin/index.php?page=company_documents', 'label' => 'أرشيف المستندات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'admin_users', 'href' => '/admin/index.php?page=admin_users', 'label' => 'المستخدمون والصلاحيات', 'class' => 'admin-nav-sub', 'sub' => true],
                ['page' => 'logs', 'href' => '/admin/index.php?page=logs', 'label' => 'سجل النشاط', 'class' => 'admin-nav-sub', 'sub' => true],
            ];

            $orangeNavMegaSections = [
                ['id' => 'accounting', 'title' => 'الحسابات العامة', 'muted' => false, 'items' => $navAccounting],
                ['id' => 'acct_vouchers', 'title' => 'القيود المحاسبية', 'muted' => false, 'items' => $navAccountingVouchers],
                ['id' => 'acct_reports', 'title' => 'التقارير', 'muted' => false, 'items' => $navAccountingReports],
                ['id' => 'ops', 'title' => 'المخازن', 'muted' => false, 'items' => $navOps],
                ['id' => 'purchasing', 'title' => 'المشتريات', 'muted' => false, 'items' => $navPurchasing],
                ['id' => 'sales', 'title' => 'المبيعات', 'muted' => false, 'items' => $navSales],
                ['id' => 'settings', 'title' => 'الإعدادات العامة', 'muted' => true, 'items' => $navSettings],
            ];

            echo '<nav class="admin-topbar-mega" aria-label="التنقل السريع">';
            foreach ($navDashboard as $nl) {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    continue;
                }
                $active = $orangeNavLinkActive($nl);
                $cls = 'admin-topbar-mega-home' . ($active ? ' is-active' : '');
                echo '<a href="' . htmlspecialchars(storefront_public_path((string) $nl['href']), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            foreach ($orangeNavMegaSections as $sec) {
                [$anyVis, $hasAct] = $orangeNavSectionMeta($sec['items']);
                if (!$anyVis) {
                    continue;
                }
                $sid = htmlspecialchars((string) $sec['id'], ENT_QUOTES, 'UTF-8');
                $pid = 'mega-panel-' . $sid;
                $bid = $pid . '-btn';
                $tcls = 'admin-mega-trigger' . ($hasAct ? ' is-active' : '') . (!empty($sec['muted']) ? ' admin-mega-trigger--muted' : '');
                echo '<div class="admin-mega-dropdown">';
                echo '<button type="button" class="' . htmlspecialchars($tcls, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($bid, ENT_QUOTES, 'UTF-8') . '" data-mega-panel="' . $sid . '" aria-expanded="false" aria-controls="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '">';
                echo '<span class="admin-mega-trigger__label">' . htmlspecialchars((string) $sec['title'], ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<span class="admin-mega-trigger__chev" aria-hidden="true">▼</span>';
                echo '</button></div>';
            }
            echo '</nav>';
            ?>
            <div class="admin-topbar-actions">
                <div class="admin-user">
                    <span class="admin-user__label">المستخدم</span>
                    <span class="admin-user__name"><?php echo htmlspecialchars($admin['display_name'] ?: $admin['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <a href="<?php echo htmlspecialchars(storefront_public_path('/admin/logout.php'), ENT_QUOTES, 'UTF-8'); ?>" class="admin-topbar-logout"><?php echo htmlspecialchars('تسجيل الخروج', ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
        </div>
        <div class="admin-mega-layer">
            <div class="admin-mega-backdrop" id="admin-mega-backdrop" hidden aria-hidden="true"></div>
            <?php
            foreach ($orangeNavMegaSections as $sec) {
                [$anyVisMega, ] = $orangeNavSectionMeta($sec['items']);
                if (!$anyVisMega) {
                    continue;
                }
                $sidMega = htmlspecialchars((string) $sec['id'], ENT_QUOTES, 'UTF-8');
                $pidMega = 'mega-panel-' . $sidMega;
                $bidMega = $pidMega . '-btn';
                echo '<div id="' . $pidMega . '" class="admin-mega-panel" role="region" hidden aria-labelledby="' . $bidMega . '">';
                echo '<div class="admin-mega-grid">';
                foreach ($sec['items'] as $nlMega) {
                    if (!empty($nlMega['group']) && !empty($nlMega['items']) && is_array($nlMega['items'])) {
                        $orangeRenderNavMegaGroup($nlMega);
                        continue;
                    }
                    if (is_array($nlMega)) {
                        $orangeRenderNavLinkMega($nlMega);
                    }
                }
                echo '</div></div>';
            }
            ?>
        </div>
    </header>
    <div id="admin-nav-drawer" class="admin-nav-drawer" hidden>
        <div class="admin-nav-drawer-inner">
            <nav class="admin-sidebar-nav" aria-label="القائمة — جوال">
                <?php
                foreach ($navDashboard as $nl) {
                    $orangeRenderNavLink($nl);
                }
                $orangeRenderNavSection('accounting', 'الحسابات العامة', $navAccounting);
                $orangeRenderNavSection('acct_vouchers', 'القيود المحاسبية', $navAccountingVouchers);
                $orangeRenderNavSection('acct_reports', 'التقارير', $navAccountingReports);
                $orangeRenderNavSection('ops', 'المخازن', $navOps);
                $orangeRenderNavSection('purchasing', 'المشتريات', $navPurchasing);
                $orangeRenderNavSection('sales', 'المبيعات', $navSales);
                $orangeRenderNavSection('settings', 'الإعدادات العامة', $navSettings);
                ?>
            </nav>
        </div>
    </div>
    </div>
    <div class="admin-nav-backdrop" id="admin-nav-backdrop" hidden aria-hidden="true"></div>
    <main class="admin-main">
