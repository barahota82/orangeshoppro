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

/** اختياري في .env.php: `ASSET_VERSION` نص ثابت؛ فارغ = تلقائي من تاريخ تعديل الملفات */
define('STOREFRONT_ASSET_VERSION', trim((string)($env['ASSET_VERSION'] ?? '')));

/*
|--------------------------------------------------------------------------
| Storefront static assets (cache bust)
|--------------------------------------------------------------------------
*/

/**
 * نسخة لاستعلام ?v= على CSS/JS: يدوية عبر ASSET_VERSION أو أقصى filemtime لملفات الواجهة.
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

    return $path . '?v=' . rawurlencode(storefront_asset_version());
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

function get_translations(): array {
    return [
        'en' => [
            'home' => 'Home',
            'cart' => 'Cart',
            'offers' => 'Offers',
            'all' => 'All',
            'out_of_stock' => 'Out of Stock',
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
            'order_number' => 'Order Number',
            'empty_cart' => 'Cart is empty.',
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
        ],
        'ar' => [
            'home' => 'الرئيسية',
            'cart' => 'السلة',
            'offers' => 'العروض',
            'all' => 'الكل',
            'out_of_stock' => 'نفد المخزون',
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
            'order_number' => 'رقم الطلب',
            'empty_cart' => 'السلة فارغة',
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
        ],
        'fil' => [
            'home' => 'Home',
            'cart' => 'Cart',
            'offers' => 'Offers',
            'all' => 'Lahat',
            'out_of_stock' => 'Ubos na ang stock',
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
            'order_number' => 'Order Number',
            'empty_cart' => 'Walang laman ang cart.',
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
        ],
        'hi' => [
            'home' => 'होम',
            'cart' => 'कार्ट',
            'offers' => 'ऑफ़र',
            'all' => 'सभी',
            'out_of_stock' => 'स्टॉक समाप्त',
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
            'order_number' => 'ऑर्डर नंबर',
            'empty_cart' => 'कार्ट खाली है।',
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
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
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
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
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
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([(int)$_SESSION['admin_id']]);
    if (!$stmt->fetch()) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 401);
    }
}
