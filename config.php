<?php
declare(strict_types=1);

$envPath = __DIR__ . '/.env.php';
if (!file_exists($envPath)) {
    die('Missing .env.php configuration file');
}

$env = require $envPath;
if (!is_array($env)) {
    $env = [];
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Kuwait');

/*
|--------------------------------------------------------------------------
| Database Config
|--------------------------------------------------------------------------
*/
const DB_HOST = 'localhost';
const DB_NAME = 'orange_db';

define('DB_USER', (string)($env['DB_USER'] ?? ''));
define('DB_PASS', (string)($env['DB_PASS'] ?? ''));

/*
|--------------------------------------------------------------------------
| Public URL prefix (storefront)
|--------------------------------------------------------------------------
| If the site is not at domain root (e.g. https://domain.com/shop/), set
| PUBLIC_BASE_PATH in .env.php to the folder URL segment: "/shop" or "shop".
| IIS rewrite rules live in that folder's web.config.
|
| If short URLs 404, set STOREFRONT_FORCE_LONG_URLS to true until URL Rewrite
| is fixed on the server — links will use /pages/...?channel=&lang=
*/
$__pub = trim((string)($env['PUBLIC_BASE_PATH'] ?? ''), '/');
define('PUBLIC_BASE_PATH', $__pub === '' ? '' : '/' . $__pub);
$__long = $env['STOREFRONT_FORCE_LONG_URLS'] ?? false;
define(
    'STOREFRONT_FORCE_LONG_URLS',
    $__long === true || $__long === 1 || $__long === '1'
);

/** اختياري في .env.php: `ASSET_VERSION` نص ثابت لكل الأصول؛ فارغ = تلقائي (انظر asset_url) */
define('STOREFRONT_ASSET_VERSION', trim((string)($env['ASSET_VERSION'] ?? '')));

/** اختياري في .env.php: `DISABLE_HTML_CACHE` = 1 يمنع كاش المتصفح/بروكسي لصفحات HTML (واجهة + أدمن) */
$__noHtmlCache = $env['DISABLE_HTML_CACHE'] ?? false;
define(
    'ORANGE_HTML_NO_CACHE',
    $__noHtmlCache === true
    || $__noHtmlCache === 1
    || $__noHtmlCache === '1'
    || strtolower((string) $__noHtmlCache) === 'true'
);

/*
|--------------------------------------------------------------------------
| Storefront static assets (cache bust)
|--------------------------------------------------------------------------
*/

/**
 * رؤوس تمنع تخزين صفحات PHP في الكاش عند تفعيل DISABLE_HTML_CACHE في .env.php (على السيرفر فقط).
 */
function orange_send_html_no_cache_headers(): void
{
    if (!ORANGE_HTML_NO_CACHE || headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
}

/**
 * نسخة ?v= لمسار ويب واحد: إن لم يُضبط ASSET_VERSION يُستخدم filemtime لذلك الملف فوراً عند كل تعديل.
 *
 * @param callable(): string $fallback أقصى filemtime لمجموعة معروفة إذا الملف غير موجود على القرص
 */
function orange_asset_url_version(string $normalizedWebPath, callable $fallback): string
{
    if (STOREFRONT_ASSET_VERSION !== '') {
        return STOREFRONT_ASSET_VERSION;
    }
    $full = __DIR__ . $normalizedWebPath;
    if (is_file($full)) {
        return (string) filemtime($full);
    }

    return $fallback();
}

/**
 * أقصى filemtime لملفات الواجهة المعروفة (احتياطي إذا المسار لا يطابق ملفاً).
 */
function storefront_asset_version(): string
{
    if (STOREFRONT_ASSET_VERSION !== '') {
        return STOREFRONT_ASSET_VERSION;
    }
    static $computed = null;
    if ($computed !== null) {
        return $computed;
    }
    $files = [
        __DIR__ . '/assets/css/main.css',
        __DIR__ . '/assets/css/theme-orange.css',
        __DIR__ . '/assets/css/theme-blue.css',
        __DIR__ . '/assets/css/theme-black.css',
        __DIR__ . '/assets/js/app.js',
        __DIR__ . '/assets/js/cart.js',
        __DIR__ . '/assets/js/lang.js',
        __DIR__ . '/assets/js/product.js',
    ];
    $mt = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $mt = max($mt, (int) filemtime($f));
        }
    }
    $computed = $mt > 0 ? (string) $mt : '1';

    return $computed;
}

/** مسار أصل مع ?v= (مثال: storefront_asset_url('/assets/css/main.css')) */
function storefront_asset_url(string $path): string
{
    $path = ($path !== '' && $path[0] === '/') ? $path : '/' . ltrim($path, '/');
    $v = orange_asset_url_version($path, static fn (): string => storefront_asset_version());

    return $path . '?v=' . rawurlencode($v);
}

/*
|--------------------------------------------------------------------------
| Admin panel assets (cache bust — نفس ASSET_VERSION أو filemtime لكل ملف)
|--------------------------------------------------------------------------
*/

/**
 * أقصى filemtime لملفات الأدمن المعروفة (احتياطي).
 */
function admin_asset_version(): string
{
    if (STOREFRONT_ASSET_VERSION !== '') {
        return STOREFRONT_ASSET_VERSION;
    }
    static $computed = null;
    if ($computed !== null) {
        return $computed;
    }
    $files = [
        __DIR__ . '/admin/assets/admin.css',
        __DIR__ . '/admin/assets/admin.js',
    ];
    $mt = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            $mt = max($mt, (int) filemtime($f));
        }
    }
    $computed = $mt > 0 ? (string) $mt : '1';

    return $computed;
}

/** مسار أصل الأدمن مع ?v= (مثال: admin_asset_url('/admin/assets/admin.css')) */
function admin_asset_url(string $path): string
{
    $path = ($path !== '' && $path[0] === '/') ? $path : '/' . ltrim($path, '/');
    $v = orange_asset_url_version($path, static fn (): string => admin_asset_version());

    return $path . '?v=' . rawurlencode($v);
}

/*
|--------------------------------------------------------------------------
| PDO Connection
|--------------------------------------------------------------------------
*/
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Optional: align timezone + charset with Click
    try {
        $pdo->exec("SET time_zone = '+03:00'");
        $pdo->exec("SET NAMES utf8mb4");
    } catch (Throwable $e) {
        // ignore if permissions restrict
    }

    return $pdo;
}

function current_lang(): string {
    $allowed = ['en', 'ar', 'fil', 'hi'];
    $lang = isset($_GET['lang']) ? strtolower(trim((string)$_GET['lang'])) : 'en';
    return in_array($lang, $allowed, true) ? $lang : 'en';
}

/** Storefront language picker labels (native name per language). */
function storefront_lang_options(): array {
    return [
        'ar' => ['label' => 'العربية'],
        'en' => ['label' => 'English'],
        'fil' => ['label' => 'PH'],
        'hi' => ['label' => 'हिन्दी'],
    ];
}

function current_channel_slug(): string {
    return isset($_GET['channel']) && $_GET['channel'] !== ''
        ? preg_replace('/[^a-z0-9\-]/i', '', (string)$_GET['channel'])
        : 'orange';
}

function get_channel_by_slug(string $slug): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * IIS short path segment for black/blue/orange + lang (see web.config.example rewrite rules).
 */
function storefront_short_segment(string $channelSlug, string $lang): ?string {
    $suffix = match ($lang) {
        'en' => '',
        'ar' => '-ar',
        'hi' => '-hi',
        'fil' => '-ph',
        default => null,
    };
    if ($suffix === null) {
        return null;
    }
    $base = match ($channelSlug) {
        'black' => 'web',
        'blue' => 'online',
        'orange' => 'tiktok',
        default => null,
    };
    if ($base === null) {
        return null;
    }
    return $base . $suffix;
}

/** @return 'home'|'cart'|'track'|'product' */
function storefront_current_page_kind(): string {
    $base = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return match ($base) {
        'cart.php' => 'cart',
        'track.php' => 'track',
        'product.php' => 'product',
        default => 'home',
    };
}

/**
 * Shared state for storefront header toolbar + mobile app dock.
 *
 * @return array{channel: array<string, mixed>, lang: string, channelSlug: string, pageKind: string, storefrontExtra: array, langOpts: array, currentLangLabel: string}
 */
function storefront_toolbar_state(): array {
    $lang = current_lang();
    $slug = current_channel_slug();
    $channel = get_channel_by_slug($slug);
    if (!$channel) {
        $channel = [
            'id' => 0,
            'name' => 'Orange',
            'slug' => $slug !== '' ? $slug : 'orange',
            'logo' => 'logo-orange.png',
            'whatsapp_number' => '',
        ];
    }
    $channelSlug = (string)($channel['slug'] ?? 'orange');
    $pageKind = storefront_current_page_kind();
    $storefrontExtra = [];
    if ($pageKind === 'product' && isset($_GET['id'])) {
        $storefrontExtra['id'] = (int)$_GET['id'];
    }
    $langOpts = storefront_lang_options();
    $currentLangLabel = (string)($langOpts[$lang]['label'] ?? $lang);

    return [
        'channel' => $channel,
        'lang' => $lang,
        'channelSlug' => $channelSlug,
        'pageKind' => $pageKind,
        'storefrontExtra' => $storefrontExtra,
        'langOpts' => $langOpts,
        'currentLangLabel' => $currentLangLabel,
    ];
}

/** wa.me link for channel WhatsApp (digits only). */
function storefront_whatsapp_href(array $channel, string $prefillText = ''): ?string {
    $raw = trim((string)($channel['whatsapp_number'] ?? ''));
    if ($raw === '') {
        return null;
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === '') {
        return null;
    }
    $url = 'https://wa.me/' . $digits;
    if ($prefillText !== '') {
        $url .= '?text=' . rawurlencode($prefillText);
    }

    return $url;
}

/**
 * Storefront URL: short path when channel+lang match IIS rewrite (web.config on server), else query string.
 *
 * @param 'home'|'cart'|'track'|'product' $page
 * @param array<string, mixed> $extra merged into query for long URLs (e.g. id for product)
 */
function storefront_url(string $page, string $channelSlug, string $lang, array $extra = []): string {
    $prefix = PUBLIC_BASE_PATH;
    $pathPrefix = ($prefix === '' ? '' : $prefix);

    $seg = storefront_short_segment($channelSlug, $lang);
    if (!STOREFRONT_FORCE_LONG_URLS && $seg !== null) {
        $tail = match ($page) {
            'home' => $seg,
            'cart' => $seg . '/cart',
            'track' => $seg . '/track',
            'product' => !empty($extra['id']) ? $seg . '/product/' . (int)$extra['id'] : null,
            default => $seg,
        };
        if ($tail !== null) {
            return ($pathPrefix === '' ? '' : $pathPrefix) . '/' . $tail;
        }
    }

    $q = array_merge(['channel' => $channelSlug, 'lang' => $lang], $extra);
    $path = match ($page) {
        'home' => '/pages/home.php',
        'cart' => '/pages/cart.php',
        'track' => '/pages/track.php',
        'product' => '/pages/product.php',
        default => '/pages/home.php',
    };
    return $pathPrefix . $path . '?' . http_build_query($q);
}

/**
 * Product title for current storefront language (DB: Arabic baseline in name/description; en/fil/hi in *_en etc.).
 */
function storefront_product_display_name(array $product): string
{
    $lang = current_lang();
    if ($lang === 'ar') {
        $v = trim((string)($product['name'] ?? ''));
        if ($v !== '') {
            return $v;
        }
        foreach (['name_en', 'name_fil', 'name_hi'] as $k) {
            $v = trim((string)($product[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
    $try = match ($lang) {
        'en' => ['name_en', 'name'],
        'fil' => ['name_fil', 'name'],
        'hi' => ['name_hi', 'name'],
        default => ['name'],
    };
    foreach ($try as $k) {
        $v = trim((string)($product[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }

    return trim((string)($product['name'] ?? ''));
}

/**
 * Product description for current storefront language.
 */
function storefront_product_display_description(array $product): string
{
    $lang = current_lang();
    if ($lang === 'ar') {
        $v = trim((string)($product['description'] ?? ''));
        if ($v !== '') {
            return $v;
        }
        foreach (['description_en', 'description_fil', 'description_hi'] as $k) {
            $v = trim((string)($product[$k] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }
    $try = match ($lang) {
        'en' => ['description_en', 'description'],
        'fil' => ['description_fil', 'description'],
        'hi' => ['description_hi', 'description'],
        default => ['description'],
    };
    foreach ($try as $k) {
        $v = trim((string)($product[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }

    return trim((string)($product['description'] ?? ''));
}

function get_translations(): array {
    return [
        'en' => [
            'home' => 'Home',
            'cart' => 'Cart',
            'offers' => 'Offers',
            'all' => 'All',
            'currency_kd' => 'KD',
            'product_not_found' => 'This product could not be found.',
            'product_back_to_shop' => 'Back to shop',
            'product_gallery_prev' => 'Previous image',
            'product_gallery_next' => 'Next image',
            'product_gallery_dots' => 'Gallery images',
            'sizing_guide' => 'Size guide (indicative)',
            'sizing_guide_close' => 'Close',
            'sizing_hint_upper' => 'This product uses the upper-body size chart (indicative).',
            'sizing_hint_lower' => 'This product uses the lower-body size chart (indicative).',
            'sizing_hint_both' => 'This product uses both upper- and lower-body size charts (indicative).',
            'out_of_stock' => 'Out of Stock',
            'low_stock' => 'Low stock — almost sold out',
            'available_max_qty' => 'Maximum you can add for this option: {n}',
            'no_more_stock_for_cart' => 'No more units available for this option (already in your cart).',
            'add_to_cart' => 'Add to Cart',
            'view_product' => 'View Product',
            'quantity' => 'Quantity',
            'color' => 'Color',
            'size' => 'Size',
            'send_order' => 'Send Order',
            'customer_name' => 'Customer Name',
            'phone' => 'Phone',
            'area' => 'Area',
            'address' => 'Address',
            'notes' => 'Notes',
            'track_order' => 'Track Order',
            'track_missing_fields' => 'Please enter the order number and phone.',
            'checkout_required_fields' => 'Please fill in all required fields (name, phone, area, address).',
            'order_number' => 'Order Number',
            'empty_cart' => 'Cart is empty.',
            'cart_empty_subtitle' => 'Browse the store and add products to place your order.',
            'cart_remove_confirm' => 'Remove this product from your cart?',
            'item_removed_from_cart' => 'Item removed',
            'cart_close' => 'Close cart',
            'cart_remove' => 'Remove',
            'select_size' => 'Please select a size',
            'select_color' => 'Please select a color',
            'added' => 'Added to cart',
            'category_products' => 'Products',
            'language' => 'Language',
            'storefront_brand' => 'Orange Company',
            'storefront_tagline' => 'Everything you wish for—in one place.',
            'home_hero_line_1' => "Everything you're looking for ... in one place",
            'home_hero_line_2' => 'Shop with Peace of Mind • COD • Easy Returns',
            'home_hero_line_3' => 'Save More • Best Price • Fast Delivery',
            'whatsapp' => 'WhatsApp',
            'tabs_scroll_prev' => 'Scroll categories left',
            'tabs_scroll_next' => 'Scroll categories right',
            'storefront_menu' => 'Browse',
            'storefront_menu_close' => 'Close',
            'storefront_menu_all_products' => 'All products',
            'storefront_menu_whole_department' => 'Entire department',
            'storefront_menu_whole_category' => 'Entire category',
            'storefront_menu_other_categories' => 'Other categories',
            'cart_tab_basket' => 'Cart',
            'cart_tab_my_orders' => 'My orders',
            'cart_proceed_to_order' => 'Place order',
            'cart_checkout_title' => 'Your details',
            'cart_checkout_intro' => 'Enter your details below, then tap Send order. WhatsApp will open to confirm with the store.',
            'cart_track_section_title' => 'Track an order',
            'cart_total_label' => 'Total',
            'cart_items_count' => '{n} items',
            'cart_unit_price' => 'Unit price',
            'cart_line_subtotal' => 'Line total',
            'cart_max_available_short' => 'Max {n} in stock',
            'cart_continue_shopping' => 'Continue shopping',
            'cart_mini_summary_title' => 'Order summary',
            'cart_mini_more' => '+{n} more',
            'payment_terms_label' => 'Payment',
            'payment_cash' => 'Cash',
            'payment_credit' => 'Credit (pay later)',
            'order_payment_terms_label' => 'Payment type',
            'checkout_online_cash_only' => 'Online orders are recorded as cash sales. Credit sales are entered from the admin panel (company invoice).',
            'my_orders_intro' => 'Enter your order number and phone (same as when you placed the order).',
            'order_status_label' => 'Status',
            'order_status_pending' => 'Pending',
            'order_status_approved' => 'Approved',
            'order_status_on_the_way' => 'On the way',
            'order_status_completed' => 'Delivered',
            'order_status_rejected' => 'Rejected',
            'order_status_cancelled' => 'Cancelled',
            'customer_cancel_order' => 'Cancel order',
            'customer_cancel_confirm' => 'Cancel this order? This cannot be undone.',
            'customer_cancel_ok' => 'Your order has been cancelled.',
            'customer_cancel_err' => 'Could not cancel. Try again or contact us on WhatsApp.',
            'customer_cancel_not_allowed' => 'Online cancellation is not available after the order is on the way. Contact us on WhatsApp to request changes.',
            'customer_whatsapp_help' => 'WhatsApp — questions or cancel request',
            'whatsapp_order_prefill' => 'Hello, I need help with order {order}.',
            'order_total_label' => 'Total',
        ],
        'ar' => [
            'home' => 'الرئيسية',
            'cart' => 'السلة',
            'offers' => 'العروض',
            'all' => 'الكل',
            'currency_kd' => 'د.ك',
            'product_not_found' => 'تعذّر العثور على هذا المنتج.',
            'product_back_to_shop' => 'العودة إلى المتجر',
            'product_gallery_prev' => 'الصورة السابقة',
            'product_gallery_next' => 'الصورة التالية',
            'product_gallery_dots' => 'صور المنتج',
            'sizing_guide' => 'جدول المقاسات (إرشادي)',
            'sizing_guide_close' => 'إغلاق',
            'sizing_hint_upper' => 'هذا المنتج: جدول مقاسات علوية (إرشادي).',
            'sizing_hint_lower' => 'هذا المنتج: جدول مقاسات سفلية (إرشادي).',
            'sizing_hint_both' => 'هذا المنتج: جداول علوية وسفلية (إرشادي).',
            'out_of_stock' => 'نفد المخزون',
            'low_stock' => 'قارب على النفاذ',
            'available_max_qty' => 'أقصى كمية يمكن إضافتها لهذا الخيار: {n}',
            'no_more_stock_for_cart' => 'لا توجد كمية إضافية لهذا الخيار (موجودة بالفعل في السلة).',
            'add_to_cart' => 'أضف إلى السلة',
            'view_product' => 'عرض المنتج',
            'quantity' => 'الكمية',
            'color' => 'اللون',
            'size' => 'المقاس',
            'send_order' => 'إرسال الطلب',
            'customer_name' => 'اسم العميل',
            'phone' => 'الهاتف',
            'area' => 'المنطقة',
            'address' => 'العنوان',
            'notes' => 'ملاحظات',
            'track_order' => 'تتبع الطلب',
            'track_missing_fields' => 'يرجى إدخال رقم الطلب ورقم الهاتف.',
            'checkout_required_fields' => 'يرجى تعبئة جميع الحقول المطلوبة (الاسم، الهاتف، المنطقة، العنوان).',
            'order_number' => 'رقم الطلب',
            'empty_cart' => 'السلة فارغة',
            'cart_empty_subtitle' => 'تصفّح المتجر وأضف منتجاتك ثم أرسل الطلب.',
            'cart_remove_confirm' => 'إزالة هذا المنتج من السلة؟',
            'item_removed_from_cart' => 'تمت إزالة المنتج',
            'cart_close' => 'إغلاق السلة',
            'cart_remove' => 'حذف',
            'select_size' => 'يرجى اختيار المقاس',
            'select_color' => 'يرجى اختيار اللون',
            'added' => 'تمت الإضافة إلى السلة',
            'category_products' => 'المنتجات',
            'language' => 'اللغة',
            'storefront_brand' => 'Orange Company',
            'storefront_tagline' => 'كل ما تتمناه.. في مكان واحد.',
            'home_hero_line_1' => 'كل ما تبحث عنه ... في مكان واحد',
            'home_hero_line_2' => 'تسوق براحة بال • دفع عند الاستلام • إرجاع سهل',
            'home_hero_line_3' => 'وفر أكثر • أقل سعر • أسرع توصيل',
            'whatsapp' => 'واتساب',
            'tabs_scroll_prev' => 'تحريك أقسام التصنيف لليسار',
            'tabs_scroll_next' => 'تحريك أقسام التصنيف لليمين',
            'storefront_menu' => 'القائمة',
            'storefront_menu_close' => 'إغلاق',
            'storefront_menu_all_products' => 'كل المنتجات',
            'storefront_menu_whole_department' => 'القسم بالكامل',
            'storefront_menu_whole_category' => 'الفئة بالكامل',
            'storefront_menu_other_categories' => 'فئات أخرى',
            'cart_tab_basket' => 'العربة',
            'cart_tab_my_orders' => 'طلباتي',
            'cart_proceed_to_order' => 'تنفيذ الطلب',
            'cart_checkout_title' => 'بيانات الطلب',
            'cart_checkout_intro' => 'عند تنفيذ الطلب تظهر هذه الخطوة: املأ بياناتك ثم اضغط «إرسال الطلب»، وسيُفتح واتساب لتأكيد الطلب مع المتجر.',
            'cart_track_section_title' => 'تتبع طلب سابق',
            'cart_total_label' => 'الإجمالي',
            'cart_items_count' => '{n} منتج',
            'cart_unit_price' => 'سعر الوحدة',
            'cart_line_subtotal' => 'المجموع',
            'cart_max_available_short' => 'حتى {n} متوفر',
            'cart_continue_shopping' => 'متابعة التسوق',
            'cart_mini_summary_title' => 'ملخص الطلب',
            'cart_mini_more' => '+{n} منتجات أخرى',
            'payment_terms_label' => 'نوع البيع',
            'payment_cash' => 'نقدي',
            'payment_credit' => 'آجل',
            'order_payment_terms_label' => 'نوع البيع',
            'checkout_online_cash_only' => 'طلبات الموقع تُسجَّل كمبيعات نقدي. البيع الآجل يُدخل من لوحة الإدارة (فاتورة شركة).',
            'my_orders_intro' => 'أدخل رقم الطلب ورقم الهاتف اللي استخدمتهم عند تأكيد الطلب.',
            'order_status_label' => 'الحالة',
            'order_status_pending' => 'قيد الانتظار',
            'order_status_approved' => 'مقبول',
            'order_status_on_the_way' => 'بالطريق',
            'order_status_completed' => 'تم التوصيل',
            'order_status_rejected' => 'مرفوض',
            'order_status_cancelled' => 'ملغي',
            'customer_cancel_order' => 'إلغاء الطلب',
            'customer_cancel_confirm' => 'تأكيد إلغاء الطلب؟ لا يمكن التراجع.',
            'customer_cancel_ok' => 'تم إلغاء طلبك.',
            'customer_cancel_err' => 'تعذّر الإلغاء. حاول مرة أخرى أو تواصل عبر واتساب.',
            'customer_cancel_not_allowed' => 'لا يمكن الإلغاء من الموقع بعد خروج الطلب «بالطريق». للاستفسار أو طلب إلغاء تواصل معنا على واتساب.',
            'customer_whatsapp_help' => 'واتساب — استفسار أو طلب إلغاء',
            'whatsapp_order_prefill' => 'السلام عليكم، أستفسر عن الطلب رقم {order}.',
            'order_total_label' => 'الإجمالي',
        ],
        'fil' => [
            'home' => 'Home',
            'cart' => 'Cart',
            'offers' => 'Offers',
            'all' => 'Lahat',
            'currency_kd' => 'KD',
            'product_not_found' => 'Hindi mahanap ang produktong ito.',
            'product_back_to_shop' => 'Bumalik sa tindahan',
            'product_gallery_prev' => 'Nakaraang larawan',
            'product_gallery_next' => 'Susunod na larawan',
            'product_gallery_dots' => 'Mga larawan ng produkto',
            'sizing_guide' => 'Gabay sa sukat (pang-reference)',
            'sizing_guide_close' => 'Isara',
            'sizing_hint_upper' => 'Ang produktong ito ay gumagamit ng tsart ng sukat sa itaas ng katawan (pang-reference).',
            'sizing_hint_lower' => 'Ang produktong ito ay gumagamit ng tsart ng sukat sa ibaba ng katawan (pang-reference).',
            'sizing_hint_both' => 'Ang produktong ito ay gumagamit ng tsart sa itaas at ibaba ng katawan (pang-reference).',
            'out_of_stock' => 'Ubos na ang stock',
            'low_stock' => 'Konti na lang ang stock',
            'available_max_qty' => 'Pinakamaraming maidadagdag para sa opsyong ito: {n}',
            'no_more_stock_for_cart' => 'Wala nang maidadagdag para sa opsyong ito (nasa cart mo na).',
            'add_to_cart' => 'Idagdag sa Cart',
            'view_product' => 'Tingnan ang Produkto',
            'quantity' => 'Dami',
            'color' => 'Kulay',
            'size' => 'Sukat',
            'send_order' => 'Ipadala ang Order',
            'customer_name' => 'Pangalan ng Customer',
            'phone' => 'Telepono',
            'area' => 'Lugar',
            'address' => 'Address',
            'notes' => 'Mga Tala',
            'track_order' => 'I-track ang Order',
            'track_missing_fields' => 'Ilagay ang order number at telepono.',
            'checkout_required_fields' => 'Punan ang lahat ng kinakailangang field (pangalan, telepono, lugar, address).',
            'order_number' => 'Order Number',
            'empty_cart' => 'Walang laman ang cart.',
            'cart_empty_subtitle' => 'Mag-browse at magdagdag ng produkto para mag-order.',
            'cart_remove_confirm' => 'Alisin ang produktong ito sa cart?',
            'item_removed_from_cart' => 'Naalis ang item',
            'cart_close' => 'Isara ang cart',
            'cart_remove' => 'Alisin',
            'select_size' => 'Pumili ng sukat',
            'select_color' => 'Pumili ng kulay',
            'added' => 'Naidagdag sa cart',
            'category_products' => 'Mga Produkto',
            'language' => 'Wika',
            'storefront_brand' => 'Orange Company',
            'storefront_tagline' => 'Lahat ng gusto mo… sa isang lugar.',
            'home_hero_line_1' => 'Lahat ng iyong hinahanap ... sa iisang lugar',
            'home_hero_line_2' => 'Kampanteng Pagbili • COD • Madaling Return',
            'home_hero_line_3' => 'Makatipid Pa • Murang Presyo • Mabilis na Delivery',
            'whatsapp' => 'WhatsApp',
            'tabs_scroll_prev' => 'I-scroll ang mga kategorya pakaliwa',
            'tabs_scroll_next' => 'I-scroll ang mga kategorya pakanan',
            'storefront_menu' => 'Menu',
            'storefront_menu_close' => 'Isara',
            'storefront_menu_all_products' => 'Lahat ng produkto',
            'storefront_menu_whole_department' => 'Buong department',
            'storefront_menu_whole_category' => 'Buong kategorya',
            'storefront_menu_other_categories' => 'Iba pang kategorya',
            'cart_tab_basket' => 'Cart',
            'cart_tab_my_orders' => 'Mga order ko',
            'cart_proceed_to_order' => 'Mag-order na',
            'cart_checkout_title' => 'Iyong detalye',
            'cart_checkout_intro' => 'Ilagay ang iyong impormasyon, tapos Ipadala ang order. Bubukas ang WhatsApp para kumpirmahin sa tindahan.',
            'cart_track_section_title' => 'I-track ang order',
            'cart_total_label' => 'Kabuuan',
            'cart_items_count' => '{n} item',
            'cart_unit_price' => 'Presyo bawat isa',
            'cart_line_subtotal' => 'Subtotal',
            'cart_max_available_short' => 'Hanggang {n} available',
            'cart_continue_shopping' => 'Mag-shopping pa',
            'cart_mini_summary_title' => 'Buod ng order',
            'cart_mini_more' => '+{n} pa',
            'payment_terms_label' => 'Bayad',
            'payment_cash' => 'Cash',
            'payment_credit' => 'Utang / hulugan',
            'order_payment_terms_label' => 'Uri ng bayad',
            'checkout_online_cash_only' => 'Ang online order ay cash sale. Ang credit sale ay sa admin (company invoice).',
            'my_orders_intro' => 'Ilagay ang order number at telepono na ginamit mo nung nag-order.',
            'order_status_label' => 'Status',
            'order_status_pending' => 'Pending',
            'order_status_approved' => 'Approved',
            'order_status_on_the_way' => 'On the way',
            'order_status_completed' => 'Delivered',
            'order_status_rejected' => 'Rejected',
            'order_status_cancelled' => 'Cancelled',
            'customer_cancel_order' => 'Kanselahin ang order',
            'customer_cancel_confirm' => 'Kanselahin ang order na ito?',
            'customer_cancel_ok' => 'Nakansela na ang order.',
            'customer_cancel_err' => 'Hindi nakansela. Subukan ulit o mag-WhatsApp.',
            'customer_cancel_not_allowed' => 'Hindi na puwedeng kanselahin online kapag on the way na. Mag-WhatsApp para humingi ng tulong.',
            'customer_whatsapp_help' => 'WhatsApp — tanong o cancel',
            'whatsapp_order_prefill' => 'Hello, tungkol sa order {order}.',
            'order_total_label' => 'Total',
        ],
        'hi' => [
            'home' => 'होम',
            'cart' => 'कार्ट',
            'offers' => 'ऑफ़र',
            'all' => 'सभी',
            'currency_kd' => 'KD',
            'product_not_found' => 'यह उत्पाद नहीं मिला।',
            'product_back_to_shop' => 'दुकान पर वापस जाएँ',
            'product_gallery_prev' => 'पिछली छवि',
            'product_gallery_next' => 'अगली छवि',
            'product_gallery_dots' => 'उत्पाद छवियाँ',
            'sizing_guide' => 'साइज़ गाइड (संकेतक)',
            'sizing_guide_close' => 'बंद करें',
            'sizing_hint_upper' => 'यह उत्पाद ऊपरी शरीर के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'sizing_hint_lower' => 'यह उत्पाद निचले शरीर के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'sizing_hint_both' => 'यह उत्पाद ऊपरी और निचले शरीर दोनों के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'out_of_stock' => 'स्टॉक समाप्त',
            'low_stock' => 'स्टॉक कम — जल्द खत्म हो सकता है',
            'available_max_qty' => 'इस विकल्प के लिए अधिकतम मात्रा: {n}',
            'no_more_stock_for_cart' => 'इस विकल्प के लिए और मात्रा उपलब्ध नहीं (पहले से कार्ट में है)।',
            'add_to_cart' => 'कार्ट में जोड़ें',
            'view_product' => 'उत्पाद देखें',
            'quantity' => 'मात्रा',
            'color' => 'रंग',
            'size' => 'साइज़',
            'send_order' => 'ऑर्डर भेजें',
            'customer_name' => 'ग्राहक का नाम',
            'phone' => 'फ़ोन',
            'area' => 'क्षेत्र',
            'address' => 'पता',
            'notes' => 'नोट्स',
            'track_order' => 'ऑर्डर ट्रैक करें',
            'track_missing_fields' => 'कृपया ऑर्डर नंबर और फ़ोन दर्ज करें।',
            'checkout_required_fields' => 'कृपया सभी आवश्यक फ़ील्ड भरें (नाम, फ़ोन, क्षेत्र, पता)।',
            'order_number' => 'ऑर्डर नंबर',
            'empty_cart' => 'कार्ट खाली है।',
            'cart_empty_subtitle' => 'स्टोर ब्राउज़ करें और ऑर्डर के लिए उत्पाद जोड़ें।',
            'cart_remove_confirm' => 'इस उत्पाद को कार्ट से हटाएं?',
            'item_removed_from_cart' => 'आइटम हटा दिया गया',
            'cart_close' => 'कार्ट बंद करें',
            'cart_remove' => 'हटाएँ',
            'select_size' => 'कृपया साइज़ चुनें',
            'select_color' => 'कृपया रंग चुनें',
            'added' => 'कार्ट में जोड़ा गया',
            'category_products' => 'उत्पाद',
            'language' => 'भाषा',
            'storefront_brand' => 'Orange Company',
            'storefront_tagline' => 'जो कुछ भी आप चाहें… एक ही जगह पर।',
            'home_hero_line_1' => 'वह सब कुछ जो आप ढूंढ रहे हैं ... एक ही जगह पर।',
            'home_hero_line_2' => 'निश्चिंत होकर खरीदारी • कैश ऑन डिलीवरी • आसान रिटर्न',
            'home_hero_line_3' => 'अधिक बचत • सबसे कम दाम • तेज़ डिलीवरी',
            'whatsapp' => 'WhatsApp',
            'tabs_scroll_prev' => 'श्रेणियाँ बाईं ओर स्क्रॉल करें',
            'tabs_scroll_next' => 'श्रेणियाँ दाईं ओर स्क्रॉल करें',
            'storefront_menu' => 'मेनू',
            'storefront_menu_close' => 'बंद करें',
            'storefront_menu_all_products' => 'सभी उत्पाद',
            'storefront_menu_whole_department' => 'पूरा विभाग',
            'storefront_menu_whole_category' => 'पूरी श्रेणी',
            'storefront_menu_other_categories' => 'अन्य श्रेणियाँ',
            'cart_tab_basket' => 'कार्ट',
            'cart_tab_my_orders' => 'मेरे ऑर्डर',
            'cart_proceed_to_order' => 'ऑर्डर करें',
            'cart_checkout_title' => 'आपका विवरण',
            'cart_checkout_intro' => 'अपनी जानकारी भरें, फिर ऑर्डर भेजें। व्हाट्सऐप से स्टोर पर पुष्टि होगी।',
            'cart_track_section_title' => 'ऑर्डर ट्रैक करें',
            'cart_total_label' => 'कुल',
            'cart_items_count' => '{n} आइटम',
            'cart_unit_price' => 'यूनिट मूल्य',
            'cart_line_subtotal' => 'पंक्ति योग',
            'cart_max_available_short' => 'अधिकतम {n} उपलब्ध',
            'cart_continue_shopping' => 'खरीदारी जारी रखें',
            'cart_mini_summary_title' => 'ऑर्डर सारांश',
            'cart_mini_more' => '+{n} और',
            'payment_terms_label' => 'भुगतान',
            'payment_cash' => 'नकद',
            'payment_credit' => 'उधार / बाद में',
            'order_payment_terms_label' => 'भुगतान प्रकार',
            'checkout_online_cash_only' => 'ऑनलाइन ऑर्डर नकद बिक्री के रूप में दर्ज होते हैं। उधार बिक्री एडमिन (कंपनी इनवॉइस) से।',
            'my_orders_intro' => 'ऑर्डर नंबर और वह फ़ोन दर्ज करें जो ऑर्डर करते समय दिया था।',
            'order_status_label' => 'स्थिति',
            'order_status_pending' => 'लंबित',
            'order_status_approved' => 'स्वीकृत',
            'order_status_on_the_way' => 'रास्ते में',
            'order_status_completed' => 'डिलीवर',
            'order_status_rejected' => 'अस्वीकृत',
            'order_status_cancelled' => 'रद्द',
            'customer_cancel_order' => 'ऑर्डर रद्द करें',
            'customer_cancel_confirm' => 'यह ऑर्डर रद्द करें?',
            'customer_cancel_ok' => 'आपका ऑर्डर रद्द हो गया।',
            'customer_cancel_err' => 'रद्द नहीं हो सका। व्हाट्सऐप से संपर्क करें।',
            'customer_cancel_not_allowed' => 'ऑर्डर रास्ते में होने के बाद साइट से रद्द नहीं। व्हाट्सऐप पर संपर्क करें।',
            'customer_whatsapp_help' => 'व्हाट्सऐप — सवाल या रद्द अनुरोध',
            'whatsapp_order_prefill' => 'नमस्ते, ऑर्डर {order} के बारे में।',
            'order_total_label' => 'कुल',
        ],
    ];
}

function t(string $key): string {
    $lang = current_lang();
    $translations = get_translations();
    return $translations[$lang][$key] ?? $key;
}

/**
 * جمل الـ hero في الصفحة الرئيسية (3 جمل بالتناوب حسب لغة الواجهة).
 *
 * @return list<string>
 */
function storefront_home_hero_lines(): array {
    $lang = current_lang();
    $translations = get_translations();
    $b = $translations[$lang] ?? $translations['en'];
    return [
        (string)($b['home_hero_line_1'] ?? ''),
        (string)($b['home_hero_line_2'] ?? ''),
        (string)($b['home_hero_line_3'] ?? ''),
    ];
}

/**
 * Taglines under brand name for the rotating header (ثابتة؛ لا تتبع لغة الواجهة).
 * الترتيب: عربي → إنجليزي → فلبيني → هندي.
 *
 * @return list<string>
 */
function storefront_tagline_cycle_messages(): array {
    $order = ['ar', 'en', 'fil', 'hi'];
    $tr = get_translations();
    $out = [];
    foreach ($order as $code) {
        $out[] = (string)($tr[$code]['storefront_tagline'] ?? '');
    }
    return $out;
}

function json_response($data, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    $flags = JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    echo json_encode($data, $flags);
    exit;
}

/**
 * تسجيل نشاط إداري (اختياري). لا يُعطّل العملية إن لم يُربط بجدول لاحقاً.
 *
 * @param int|string|null $entityId
 */
function audit_log(string $action, string $message, string $entityTable = '', $entityId = null): void
{
    // يمكن لاحقاً: INSERT في جدول سجل. تجنّب fatal إن الدالة غير مُعرّفة على نسخ قديمة.
    if (function_exists('error_log') && filter_var(getenv('ORANGE_AUDIT_LOG') ?: '', FILTER_VALIDATE_BOOLEAN)) {
        error_log('[orange audit] ' . $action . ' | ' . $message . ' | ' . $entityTable . ' | ' . (string) $entityId);
    }
}

/**
 * رد موحّد للأخطاء في واجهات JSON (لا يترك جسم الاستجابة فارغاً).
 */
function api_error(Throwable $e, string $userMessage): void
{
    if (function_exists('error_log')) {
        error_log(
            '[orange] API: ' . $userMessage . ' | ' . $e->getMessage()
            . ' @ ' . $e->getFile() . ':' . $e->getLine()
        );
    }
    $payload = [
        'success' => false,
        'message' => $userMessage,
    ];
    $debug = getenv('ORANGE_API_DEBUG');
    if ($debug === '1' || $debug === 'true') {
        $payload['debug'] = $e->getMessage();
    }
    json_response($payload, 500);
}

function get_json_input(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function current_admin(): ?array {
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return ['id' => (int)$_SESSION['admin_id']];
}

function admin_login(int $adminId): void {
    $_SESSION['admin_id'] = $adminId;
}

function admin_logout(): void {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function require_admin_page(): array {
    if (!current_admin()) {
        header('Location: /admin/login.php');
        exit;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        admin_logout();
        header('Location: /admin/login.php');
        exit;
    }
    return $admin;
}

function require_admin_api(): void {
    if (!current_admin()) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
    require_once __DIR__ . '/includes/catalog_schema.php';
    require_once __DIR__ . '/includes/admin_permissions.php';
    orange_catalog_ensure_schema($pdo);
    orange_admin_enforce_api($admin, $pdo);
}
