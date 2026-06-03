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
require_once __DIR__ . '/../../includes/countries.php';
require_once __DIR__ . '/../../includes/currency.php';
/** @var ?\PDO $pdo — يضبطه admin/index.php قبل تضمين هذا الملف؛ تجنّب استدعاء ensure_schema مرتين لكل صفحة أدمن */
$pdoNav = (isset($pdo) && $pdo instanceof PDO) ? $pdo : db();
if (!isset($pdo) || !$pdo instanceof PDO) {
orange_catalog_ensure_schema($pdoNav);
}

$orangeSchemaDegradedAttr = (defined('ORANGE_SCHEMA_DEGRADED') && ORANGE_SCHEMA_DEGRADED)
    ? ' data-orange-schema-degraded="1"'
    : '';
$orangeAdminCountryIdNav = orange_admin_context_country_id($pdoNav);
$orangeAdminCountryLockedId = orange_admin_session_locked_country_id();
$orangeAdminCountriesNav = orange_countries_admin_list($pdoNav);
$orangeAdminCountryCodeNav = orange_admin_context_country_code($pdoNav);
$orangeAdminCountryLabelNav = $orangeAdminCountryCodeNav;
foreach ($orangeAdminCountriesNav as $ocNav) {
    if ((int) ($ocNav['id'] ?? 0) === $orangeAdminCountryIdNav) {
        $ocLabel = trim((string) ($ocNav['name_ar'] ?? ''));
        if ($ocLabel === '') {
            $ocLabel = trim((string) ($ocNav['name_en'] ?? ''));
        }
        if ($ocLabel !== '') {
            $orangeAdminCountryLabelNav = $ocLabel;
        }
        break;
    }
}
$orangeAdminCountryScopeReady = orange_admin_country_scope_ready($pdoNav);
$orangeAdminPhoneDialNav = orange_admin_context_phone_dial($pdoNav);
$orangeAdminCurrencyNav = orange_admin_context_currency_code($pdoNav);
$orangeAdminCurrencyDecimalsNav = orange_currency_decimals_for_code($orangeAdminCurrencyNav);
$orangeAdminCurrencyUnitNav = orange_currency_display_unit($orangeAdminCurrencyNav);
$orangeAdminCapsNav = orange_admin_caps_all_pages($admin, $pdoNav);
$orangeAdminCapsPageNav = orange_admin_caps_for_page($admin, $pdoNav, $orangeAdminPage);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl"<?php echo $orangeSchemaDegradedAttr; ?>>
<head>
    <meta charset="UTF-8">
    <title>Orange — لوحة التحكم</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="orange-admin-country" content="<?php echo htmlspecialchars($orangeAdminCountryCodeNav, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="orange-admin-country-id" content="<?php echo (int) $orangeAdminCountryIdNav; ?>">
    <meta name="orange-admin-phone-dial" content="<?php echo htmlspecialchars($orangeAdminPhoneDialNav, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="orange-admin-currency" content="<?php echo htmlspecialchars($orangeAdminCurrencyNav, ENT_QUOTES, 'UTF-8'); ?>">
    <meta name="orange-admin-currency-decimals" content="<?php echo (int) $orangeAdminCurrencyDecimalsNav; ?>">
    <meta name="orange-admin-currency-unit" content="<?php echo htmlspecialchars($orangeAdminCurrencyUnitNav, ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Outfit:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin.css')), ENT_QUOTES, 'UTF-8'); ?>">
    <script>
    window.ORANGE_PUBLIC_BASE_PATH = <?php echo json_encode(PUBLIC_BASE_PATH, JSON_UNESCAPED_UNICODE); ?>;
    /** منع تكرار الإرسال: postJSON يعطّل الزر (آخر نقرة أو زر إرسال النموذج) ما لم يُستثنَ بـ data-no-post-guard. */
    window.ORANGE_POSTJSON_INFER_SUBMITTER = true;
    window.ORANGE_ADMIN_MONEY = {
        code: <?php echo json_encode($orangeAdminCurrencyNav, JSON_UNESCAPED_UNICODE); ?>,
        unit: <?php echo json_encode($orangeAdminCurrencyUnitNav, JSON_UNESCAPED_UNICODE); ?>,
        decimals: <?php echo (int) $orangeAdminCurrencyDecimalsNav; ?>,
        zero: <?php echo json_encode(isset($orangeAdminMoneyZero) ? $orangeAdminMoneyZero : orange_admin_money_zero_string((int) $orangeAdminCurrencyDecimalsNav), JSON_UNESCAPED_UNICODE); ?>,
        step: <?php echo json_encode(isset($orangeAdminMoneyStep) ? $orangeAdminMoneyStep : orange_admin_money_input_step((int) $orangeAdminCurrencyDecimalsNav), JSON_UNESCAPED_UNICODE); ?>
    };
    window.ORANGE_ADMIN_PAGE = <?php echo json_encode($orangeAdminPage, JSON_UNESCAPED_UNICODE); ?>;
    window.ORANGE_ADMIN_CAPS = <?php echo json_encode($orangeAdminCapsNav, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    window.ORANGE_ADMIN_CAPS_PAGE = <?php echo json_encode($orangeAdminCapsPageNav, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS); ?>;
    window.orangeAdminSwitchCountry = function (sel) {
        if (!sel || !sel.form) {
            return;
        }
        var code = String(sel.value || '').trim();
        if (code) {
            var secure = location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = 'orange_ad_country=' + encodeURIComponent(code) + '; path=/; max-age=' + (3600 * 24 * 400) + '; SameSite=Lax' + secure;
        }
        sel.form.submit();
    };
    </script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin-date-dmy.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin-money-fields.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
    <script src="<?php echo htmlspecialchars(storefront_public_path(admin_asset_url('/admin/assets/admin-variant-picker.js')), ENT_QUOTES, 'UTF-8'); ?>" defer></script>
</head>
<body>
<?php require __DIR__ . '/variant_picker_modal.php'; ?>
<div class="admin-layout">
    <div class="admin-header-wrap">
    <header class="admin-topbar">
        <div class="admin-topbar-strip">
            <button type="button" class="admin-menu-toggle" id="admin-menu-toggle" data-no-post-guard aria-expanded="false" aria-controls="admin-nav-drawer" aria-label="فتح وإغلاق قائمة لوحة التحكم">
                <span class="admin-menu-toggle__icon" aria-hidden="true">☰</span>
                <span class="admin-menu-toggle__text">القائمة</span>
            </button>
            <div class="admin-topbar-brand" role="banner">
                <div class="admin-sidebar-brand__mark" aria-hidden="true"></div>
                <div class="admin-sidebar-brand__text">
                    <div class="admin-sidebar-brand__title">Orange</div>
                    <div class="admin-sidebar-brand__subtitle">لوحة التحكم المؤسسية</div>
                </div>
            </div>
            <?php
            /**
             * دوال الميجا وجوال تُقيِّم نشاط الرابط بالاعتماد على ?page فقط؛
             * عدة أزرار تشترك نفس القيمة (التقارير المالية والذمم + مراسي #) فيُفعَّل واحد بالمتصفح.
             *
             * @param array<string, mixed> $nl
             */
            $orange_nav_acct_reports_need_client_active = static function (array $nl): bool {
                $p = (string) ($nl['page'] ?? '');

                return $p === 'financial_report' || $p === 'partner_reports';
            };

            /**
             * @param array{page:string,href:string,label:string,class:string,sub:bool} $nl
             */
            $orangeNavLinkActive = static function (array $nl) use ($orangeAdminPage): bool {
                return ($nl['page'] === 'stock' && ($orangeAdminPage === 'stock' || $orangeAdminPage === 'item_card'))
                    || $orangeAdminPage === $nl['page'];
            };

            /** @param array<string, mixed> $nl */
            $orangeRenderNavLinkMega = static function (array $nl, string $megaSectionId = '') use ($admin, $pdoNav, $orangeNavLinkActive, $orange_nav_acct_reports_need_client_active, $orangeAdminCountryCodeNav, $orangeAdminCountryLockedId): void {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    return;
                }
                $pinAcct = ($megaSectionId === 'accounting' && $orange_nav_acct_reports_need_client_active($nl));
                $active = !$pinAcct && $orangeNavLinkActive($nl);
                $cls = trim($nl['class'] . ' admin-mega-link' . ($active ? ' is-active' : ''));
                $pinAttr = $pinAcct ? ' data-orange-admin-nav-pin="acct-reports"' : '';
                echo '<a href="' . htmlspecialchars(orange_admin_public_href_with_country((string) $nl['href'], $orangeAdminCountryCodeNav, $orangeAdminCountryLockedId), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $pinAttr . '>' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            };

            $orangeRenderNavLink = static function (array $nl, string $navSectionId = '') use ($admin, $pdoNav, $orangeNavLinkActive, $orange_nav_acct_reports_need_client_active, $orangeAdminCountryCodeNav, $orangeAdminCountryLockedId): void {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    return;
                }
                $pinAcct = ($navSectionId === 'accounting' && $orange_nav_acct_reports_need_client_active($nl));
                $active = !$pinAcct && $orangeNavLinkActive($nl);
                $cls = trim($nl['class'] . ($active ? ' is-active' : ''));
                $pinAttr = $pinAcct ? ' data-orange-admin-nav-pin="acct-reports"' : '';
                echo '<a href="' . htmlspecialchars(orange_admin_public_href_with_country((string) $nl['href'], $orangeAdminCountryCodeNav, $orangeAdminCountryLockedId), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '"' . $pinAttr . '>' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
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
                                $orangeRenderNavLink($c, $sectionId);
                            }
                        }
                        echo '</div>';
                        continue;
                    }
                    if (is_array($nl)) {
                        $orangeRenderNavLink($nl, $sectionId);
                    }
                }
                echo '</div></div>';
            };

            /** مجموعة داخل لوحة الميجا */
            $orangeRenderNavMegaGroup = static function (array $group, string $megaSectionId = '') use ($admin, $pdoNav, $orangeRenderNavLinkMega): void {
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
                        $orangeRenderNavLinkMega($c, $megaSectionId);
                    }
                }
                echo '</div></div>';
            };

            $navDashboard = [
                ['page' => 'dashboard', 'href' => '/admin/index.php?page=dashboard', 'label' => 'الرئيسية', 'class' => '', 'sub' => false],
            ];


            /* 4 قوائم منسدلة + إعدادات — مجموعات فرعية داخل كل قائمة */
            $navWarehousePurchasing = [
                [
                    'group' => true,
                    'title' => 'المخزون',
                    'items' => [
                        ['page' => 'stock', 'href' => '/admin/index.php?page=stock', 'label' => 'المستودع', 'class' => '', 'sub' => false],
                        ['page' => 'stock_reports', 'href' => '/admin/index.php?page=stock_reports', 'label' => 'تقارير المخزن', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'opening_stock_balances', 'href' => '/admin/index.php?page=opening_stock_balances', 'label' => 'أرصدة أول المدة المخزنية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'inventory_reconciliation', 'href' => '/admin/index.php?page=inventory_reconciliation', 'label' => 'تسوية المخزون / الجرد', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'المشتريات',
                    'items' => [
                        ['page' => 'suppliers', 'href' => '/admin/index.php?page=suppliers', 'label' => 'الموردين', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'purchases', 'href' => '/admin/index.php?page=purchases', 'label' => 'المشتريات', 'class' => '', 'sub' => false],
                        ['page' => 'purchase_returns', 'href' => '/admin/index.php?page=purchase_returns', 'label' => 'مردود المشتريات', 'class' => '', 'sub' => false],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'هيكل الكتالوج والمنتجات',
                    'items' => [
                        ['page' => 'departments', 'href' => '/admin/index.php?page=departments', 'label' => 'الأقسام الرئيسية', 'class' => '', 'sub' => false],
                        ['page' => 'unified_catalog_branches', 'href' => '/admin/index.php?page=unified_catalog_branches', 'label' => 'فروع شجرة المنتجات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'product_types', 'href' => '/admin/index.php?page=product_types', 'label' => 'أنواع المنتجات الموحدة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'catalog_attributes', 'href' => '/admin/index.php?page=catalog_attributes', 'label' => 'سمات الكتالوج', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'color_dictionary', 'href' => '/admin/index.php?page=color_dictionary', 'label' => 'قاموس الألوان', 'class' => '', 'sub' => false],
                        ['page' => 'pattern_dictionary', 'href' => '/admin/index.php?page=pattern_dictionary', 'label' => 'أنماط الألوان', 'class' => '', 'sub' => false],
                        ['page' => 'size_scheme_templates', 'href' => '/admin/index.php?page=size_scheme_templates', 'label' => 'قوالب المقاسات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'sizing_dictionary', 'href' => '/admin/index.php?page=sizing_dictionary', 'label' => 'قاموس هرم المقاسات (1–2)', 'class' => '', 'sub' => false],
                        ['page' => 'size_families', 'href' => '/admin/index.php?page=size_families', 'label' => 'عائلات المقاسات (3–4)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'advisory_sizing_guides', 'href' => '/admin/index.php?page=advisory_sizing_guides', 'label' => 'دليل المقاس الاسترشادي', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'products', 'href' => '/admin/index.php?page=products', 'label' => 'المنتجات', 'class' => '', 'sub' => false],
                    ],
                ],
            ];

            $navSalesPromotions = [
                [
                    'group' => true,
                    'title' => 'العملاء والطلبات',
                    'items' => [
                        ['page' => 'customers', 'href' => '/admin/index.php?page=customers', 'label' => 'العملاء', 'class' => '', 'sub' => false],
                        ['page' => 'orders', 'href' => '/admin/index.php?page=orders', 'label' => 'الطلبات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'reserved_orders', 'href' => '/admin/index.php?page=reserved_orders', 'label' => 'طلبات محجوزة (مخزون)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'order_intake_queue', 'href' => '/admin/index.php?page=order_intake_queue', 'label' => 'طابور الطلبات', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'التوصيل والتسليم',
                    'items' => [
                        ['page' => 'delivery_agents', 'href' => '/admin/index.php?page=delivery_agents', 'label' => 'مناديب التوصيل', 'class' => '', 'sub' => false],
                        ['page' => 'delivery_agent_handover', 'href' => '/admin/index.php?page=delivery_agent_handover', 'label' => 'تسليم المندوب', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'delivery_order_search', 'href' => '/admin/index.php?page=delivery_order_search', 'label' => 'بحث التسليم', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'delivery_handover_manifest', 'href' => '/admin/index.php?page=delivery_handover_manifest', 'label' => 'ورقة المندوب', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'online_orders_final_posting', 'href' => '/admin/index.php?page=online_orders_final_posting', 'label' => 'إنشاء قيود التسليم', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'الفواتير والمردود',
                    'items' => [
                        ['page' => 'invoice', 'href' => '/admin/index.php?page=invoice', 'label' => 'فاتورة أونلاين', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'online_sales_invoice', 'href' => '/admin/index.php?page=online_sales_invoice', 'label' => 'فواتير أونلاين', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'company_sales_invoice', 'href' => '/admin/index.php?page=company_sales_invoice', 'label' => 'فاتورة مبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'sales_returns', 'href' => '/admin/index.php?page=sales_returns', 'label' => 'مردود المبيعات', 'class' => '', 'sub' => false],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'العروض',
                    'items' => [
                        ['page' => 'offers', 'href' => '/admin/index.php?page=offers', 'label' => 'عروض المنتجات', 'class' => '', 'sub' => false],
                        ['page' => 'cart_promotions', 'href' => '/admin/index.php?page=cart_promotions', 'label' => 'عروض مجموع السلة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'cart_gift_promotions', 'href' => '/admin/index.php?page=cart_gift_promotions', 'label' => 'عروض الهدايا (س4)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'cart_bogo_promotions', 'href' => '/admin/index.php?page=cart_bogo_promotions', 'label' => 'عروض BOGO (س4)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'cart_combo_promotions', 'href' => '/admin/index.php?page=cart_combo_promotions', 'label' => 'عروض الكومبو', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'cart_promo_health', 'href' => '/admin/index.php?page=cart_promo_health', 'label' => 'صحة العروض (مخزون)', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'تقارير المبيعات',
                    'items' => [
                        ['page' => 'reports', 'href' => '/admin/index.php?page=reports', 'label' => 'تقارير المبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'channel_analytics', 'href' => '/admin/index.php?page=channel_analytics', 'label' => 'تحليل القنوات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'sales_returns_report', 'href' => '/admin/index.php?page=sales_returns_report', 'label' => 'تقرير مردودات المبيعات', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
            ];

            $navAccountingAll = [
                [
                    'group' => true,
                    'title' => 'الإعداد والدليل',
                    'items' => [
                        ['page' => 'chart_of_accounts', 'href' => '/admin/index.php?page=chart_of_accounts', 'label' => 'الدليل المحاسبي', 'class' => '', 'sub' => false],
                        ['page' => 'gl_account_settings', 'href' => '/admin/index.php?page=gl_account_settings', 'label' => 'حسابات القيود التلقائية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'invoice_line_presets', 'href' => '/admin/index.php?page=invoice_line_presets', 'label' => 'قائمة بنود الفاتورة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'analytical_dimensions', 'href' => '/admin/index.php?page=analytical_dimensions', 'label' => 'الأبعاد التحليلية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'journal_types', 'href' => '/admin/index.php?page=journal_types', 'label' => 'أنواع اليوميات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'fiscal_years', 'href' => '/admin/index.php?page=fiscal_years', 'label' => 'السنوات المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'edit_lock', 'href' => '/admin/index.php?page=edit_lock', 'label' => 'إقفال التعديلات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'opening_balances', 'href' => '/admin/index.php?page=opening_balances', 'label' => 'أرصدة أول المدة المالية', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'السندات والذمم',
                    'items' => [
                        ['page' => 'journal_entries', 'href' => '/admin/index.php?page=journal_entries', 'label' => 'سند قيد', 'class' => '', 'sub' => false],
                        ['page' => 'receipt_voucher', 'href' => '/admin/index.php?page=receipt_voucher', 'label' => 'سند قبض', 'class' => '', 'sub' => false],
                        ['page' => 'payment_voucher', 'href' => '/admin/index.php?page=payment_voucher', 'label' => 'سند صرف', 'class' => '', 'sub' => false],
                        ['page' => 'other_vouchers', 'href' => '/admin/index.php?page=other_vouchers', 'label' => 'سندات أخرى', 'class' => '', 'sub' => false],
                        ['page' => 'partner_customer_receipt', 'href' => '/admin/index.php?page=partner_customer_receipt', 'label' => 'سداد فواتير مبيعات آجلة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'partner_supplier_payment', 'href' => '/admin/index.php?page=partner_supplier_payment', 'label' => 'سداد فواتير مشتريات آجلة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'bank_reconciliation', 'href' => '/admin/index.php?page=bank_reconciliation', 'label' => 'تسوية البنك', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'year_end_close_vouchers', 'href' => '/admin/index.php?page=year_end_close_vouchers', 'label' => 'قيود الإقفال السنوية', 'class' => '', 'sub' => false],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'التقارير',
                    'items' => [
                        ['page' => 'journal_voucher_reports', 'href' => '/admin/index.php?page=journal_voucher_reports', 'label' => 'تقارير السندات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'partner_account_statement', 'href' => '/admin/index.php?page=partner_account_statement', 'label' => 'كشف حساب', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_account_list', 'href' => '/admin/index.php?page=report_account_list', 'label' => 'قائمة الحسابات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_gl_account_monthly', 'href' => '/admin/index.php?page=report_gl_account_monthly', 'label' => 'الحركة الشهرية لحساب', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'partner_reports', 'href' => '/admin/index.php?page=partner_reports&view=customers', 'label' => 'أرصدة العملاء (ذمم)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'partner_reports', 'href' => '/admin/index.php?page=partner_reports&view=suppliers', 'label' => 'أرصدة الموردين (ذمم)', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_income_statement', 'href' => '/admin/index.php?page=report_income_statement', 'label' => 'أرباح وخسائر', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_trading_account', 'href' => '/admin/index.php?page=report_trading_account', 'label' => 'قائمة حسابات المتاجرة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_pl_monthly', 'href' => '/admin/index.php?page=report_pl_monthly', 'label' => 'قائمة إيرادات ومصروفات شهرية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_pl_compare_years', 'href' => '/admin/index.php?page=report_pl_compare_years', 'label' => 'أرباح وخسائر مقارنة بين السنوات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_trial_balance', 'href' => '/admin/index.php?page=report_trial_balance', 'label' => 'ميزان المراجعة', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_cash_flow', 'href' => '/admin/index.php?page=report_cash_flow', 'label' => 'قائمة التدفقات النقدية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_analytical', 'href' => '/admin/index.php?page=report_analytical', 'label' => 'التقرير التحليلي', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'report_balance_sheet', 'href' => '/admin/index.php?page=report_balance_sheet', 'label' => 'الميزانية', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'financial_report', 'href' => '/admin/index.php?page=financial_report', 'label' => 'التقارير المالية (الصفحة كاملة)', 'class' => '', 'sub' => false],
                    ],
                ],
            ];

            $navSettings = [
                [
                    'group' => true,
                    'title' => 'الشركة',
                    'items' => [
                        ['page' => 'company_settings', 'href' => '/admin/index.php?page=company_settings', 'label' => 'بيانات الشركة', 'class' => '', 'sub' => false],
                        ['page' => 'company_documents', 'href' => '/admin/index.php?page=company_documents', 'label' => 'أرشيف المستندات', 'class' => 'admin-nav-sub', 'sub' => true],
                        ['page' => 'logs', 'href' => '/admin/index.php?page=logs', 'label' => 'سجل النشاط', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'الأسواق (مشرف عام)',
                    'items' => [
                        ['page' => 'countries', 'href' => '/admin/index.php?page=countries', 'label' => 'الدول', 'class' => '', 'sub' => false],
                        ['page' => 'admin_users', 'href' => '/admin/index.php?page=admin_users', 'label' => 'المستخدمون والصلاحيات', 'class' => 'admin-nav-sub', 'sub' => true],
                    ],
                ],
                [
                    'group' => true,
                    'title' => 'السوق الحالي',
                    'items' => [
                        ['page' => 'channels', 'href' => '/admin/index.php?page=channels', 'label' => 'قنوات العملاء', 'class' => '', 'sub' => false],
                        ['page' => 'delivery_areas', 'href' => '/admin/index.php?page=delivery_areas', 'label' => 'محافظات ومناطق التوصيل', 'class' => '', 'sub' => false],
                        ['page' => 'storefront_hero', 'href' => '/admin/index.php?page=storefront_hero', 'label' => 'بانر الصفحة الرئيسية', 'class' => '', 'sub' => false],
                        ['page' => 'storefront_merge_requests', 'href' => '/admin/index.php?page=storefront_merge_requests', 'label' => 'دمج هاتف التسجيل (س15)', 'class' => '', 'sub' => false],
                    ],
                ],
            ];

            $orangeNavMegaSections = [
                ['id' => 'accounting', 'title' => 'الحسابات والتقارير', 'muted' => false, 'items' => $navAccountingAll],
                ['id' => 'warehouse', 'title' => 'المخازن والمشتريات', 'muted' => false, 'items' => $navWarehousePurchasing],
                ['id' => 'sales', 'title' => 'المبيعات والعروض', 'muted' => false, 'items' => $navSalesPromotions],
                ['id' => 'settings', 'title' => 'الإعدادات', 'muted' => true, 'items' => $navSettings],
            ];

            echo '<nav class="admin-topbar-mega" aria-label="التنقل السريع">';
            foreach ($navDashboard as $nl) {
                if (!orange_admin_nav_visible($admin, $pdoNav, $nl['page'])) {
                    continue;
                }
                $active = $orangeNavLinkActive($nl);
                $cls = 'admin-topbar-mega-home' . ($active ? ' is-active' : '');
                echo '<a href="' . htmlspecialchars(orange_admin_public_href_with_country((string) $nl['href'], $orangeAdminCountryCodeNav, $orangeAdminCountryLockedId), ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($nl['label'], ENT_QUOTES, 'UTF-8') . '</a>';
            }
            foreach ($orangeNavMegaSections as $sec) {
                [$anyVis, ] = $orangeNavSectionMeta($sec['items']);
                if (!$anyVis) {
                    continue;
                }
                $sid = htmlspecialchars((string) $sec['id'], ENT_QUOTES, 'UTF-8');
                $pid = 'mega-panel-' . $sid;
                $bid = $pid . '-btn';
                $tcls = 'admin-mega-trigger' . (!empty($sec['muted']) ? ' admin-mega-trigger--muted' : '');
                echo '<div class="admin-mega-dropdown">';
                echo '<button type="button" class="' . htmlspecialchars($tcls, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($bid, ENT_QUOTES, 'UTF-8') . '" data-mega-panel="' . $sid . '" aria-expanded="false" aria-controls="' . htmlspecialchars($pid, ENT_QUOTES, 'UTF-8') . '">';
                echo '<span class="admin-mega-trigger__label">' . htmlspecialchars((string) $sec['title'], ENT_QUOTES, 'UTF-8') . '</span>';
                echo '<span class="admin-mega-trigger__chev" aria-hidden="true">▼</span>';
                echo '</button></div>';
            }
            echo '</nav>';
            if (orange_admin_show_country_switcher($admin) && count($orangeAdminCountriesNav) >= 1):
                $orangeCountrySelectDisabled = count($orangeAdminCountriesNav) <= 1;
                ?>
            <form method="get" action="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="admin-topbar-country-mega">
                <input type="hidden" name="page" value="<?php echo htmlspecialchars($orangeAdminPage, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="admin-mega-dropdown admin-mega-dropdown--country">
                    <label for="admin_topbar_country" class="admin-topbar-country-mega__label">الدولة</label>
                    <select id="admin_topbar_country" name="admin_country" class="admin-topbar-country-select admin-mega-trigger admin-mega-trigger--country"
                        aria-label="اختيار الدولة — سياق شاشات الأدمن"
                        <?php echo $orangeCountrySelectDisabled ? 'disabled title="دولة واحدة مسجّلة حالياً"' : ''; ?>
                        onchange="orangeAdminSwitchCountry(this)">
                        <?php foreach ($orangeAdminCountriesNav as $ocOpt):
                            $ocCode = orange_countries_normalize_code((string) ($ocOpt['code'] ?? ''));
                            if ($ocCode === '') {
                                continue;
                            }
                            $ocLabel = trim((string) ($ocOpt['name_ar'] ?? ''));
                            if ($ocLabel === '') {
                                $ocLabel = (string) ($ocOpt['name_en'] ?? $ocCode);
                            }
                            if ((int) ($ocOpt['is_active'] ?? 0) !== 1) {
                                $ocLabel .= ' (غير نشطة)';
                            }
                            ?>
                        <option value="<?php echo htmlspecialchars($ocCode, ENT_QUOTES, 'UTF-8'); ?>"
                            <?php echo $ocCode === $orangeAdminCountryCodeNav ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($ocLabel, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
            <?php endif; ?>
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
                $sidMegaRaw = (string) $sec['id'];
                $sidMega = htmlspecialchars($sidMegaRaw, ENT_QUOTES, 'UTF-8');
                $pidMega = 'mega-panel-' . $sidMega;
                $bidMega = $pidMega . '-btn';
                echo '<div id="' . $pidMega . '" class="admin-mega-panel" role="region" hidden aria-labelledby="' . $bidMega . '">';
                echo '<div class="admin-mega-grid">';
                foreach ($sec['items'] as $nlMega) {
                    if (!empty($nlMega['group']) && !empty($nlMega['items']) && is_array($nlMega['items'])) {
                        $orangeRenderNavMegaGroup($nlMega, $sidMegaRaw);
                        continue;
                    }
                    if (is_array($nlMega)) {
                        $orangeRenderNavLinkMega($nlMega, $sidMegaRaw);
                    }
                }
                echo '</div></div>';
            }
            ?>
        </div>
    </header>
    <?php if (!$orangeAdminCountryScopeReady): ?>
    <div class="admin-country-scope-warn" role="status" style="margin:0;padding:10px 16px;background:#fef3c7;color:#92400e;font-size:13px;line-height:1.5;border-bottom:1px solid #fcd34d;">
        تنبيه: أعمدة <code dir="ltr">country_id</code> غير مكتملة على جداول الموردين/العملاء/الحسابات — فصل الدول معطّل. حدّث الملفات ثم أعد تحميل هذه الصفحة؛ إن استمر التنبيه فتحقق من صلاحيات مستخدم قاعدة البيانات على <code dir="ltr">ALTER TABLE</code> أو راجع سجل أخطاء PHP على السيرفر.
    </div>
    <?php endif; ?>
    <div id="admin-nav-drawer" class="admin-nav-drawer" hidden>
        <div class="admin-nav-drawer-inner">
            <nav class="admin-sidebar-nav" aria-label="القائمة — جوال">
                <?php
                foreach ($navDashboard as $nl) {
                    $orangeRenderNavLink($nl);
                }
                $orangeRenderNavSection('accounting', 'الحسابات والتقارير', $navAccountingAll);
                $orangeRenderNavSection('warehouse', 'المخازن والمشتريات', $navWarehousePurchasing);
                $orangeRenderNavSection('sales', 'المبيعات والعروض', $navSalesPromotions);
                $orangeRenderNavSection('settings', 'الإعدادات', $navSettings);
                if (orange_admin_show_country_switcher($admin) && count($orangeAdminCountriesNav) >= 1):
                    ?>
                <div class="admin-nav-country-drawer">
                    <form method="get" action="<?php echo htmlspecialchars(storefront_public_path('/admin/index.php'), ENT_QUOTES, 'UTF-8'); ?>" class="admin-nav-country-drawer__form">
                        <input type="hidden" name="page" value="<?php echo htmlspecialchars($orangeAdminPage, ENT_QUOTES, 'UTF-8'); ?>">
                        <label for="admin_drawer_country" class="admin-nav-country-drawer__label">الدولة — سياق الأدمن</label>
                        <select id="admin_drawer_country" name="admin_country" class="admin-nav-country-drawer__select"
                            <?php echo count($orangeAdminCountriesNav) <= 1 ? 'disabled' : ''; ?>
                            onchange="orangeAdminSwitchCountry(this)">
                            <?php foreach ($orangeAdminCountriesNav as $ocOpt):
                                $ocCode = orange_countries_normalize_code((string) ($ocOpt['code'] ?? ''));
                                if ($ocCode === '') {
                                    continue;
                                }
                                $ocLabel = trim((string) ($ocOpt['name_ar'] ?? ''));
                                if ($ocLabel === '') {
                                    $ocLabel = (string) ($ocOpt['name_en'] ?? $ocCode);
                                }
                                if ((int) ($ocOpt['is_active'] ?? 0) !== 1) {
                                    $ocLabel .= ' (غير نشطة)';
                                }
                                ?>
                            <option value="<?php echo htmlspecialchars($ocCode, ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $ocCode === $orangeAdminCountryCodeNav ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ocLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php endif;
                ?>
        </nav>
        </div>
    </div>
    </div>
    <div class="admin-nav-backdrop" id="admin-nav-backdrop" hidden aria-hidden="true"></div>
    <main class="admin-main">
