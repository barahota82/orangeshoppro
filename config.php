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
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('Asia/Kuwait');

require_once __DIR__ . '/includes/date_format.php';

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

/** اختياري: أصل الموقع العام بدون شرطة مثلاً `https://example.com` — لروابط canonical وOpen Graph عند غياب Host في CLI */
$__siteOrigin = trim((string)($env['SITE_PUBLIC_URL'] ?? ''), " \t\n\r\0\x0B/");
define('ORANGE_SITE_PUBLIC_ORIGIN', $__siteOrigin);

/** مرسل البريد (تسجيل اختياري بالإيميل). فارغ = لا يُرسل بريد حتى يُضبط في .env.php */
define('ORANGE_MAIL_FROM', trim((string)($env['MAIL_FROM'] ?? '')));
define('ORANGE_MAIL_FROM_NAME', trim((string)($env['MAIL_FROM_NAME'] ?? 'Orange')));

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

/**
 * اختياري: نفس القيمة في query ‎?sf_preview=…‎ لتعطيل إعادة توجيه «الواجهة المثبتة» في المتجر (روابط المعاينة من شاشة الواجهات في الأدمن).
 * فارغ = لا يوجد تجاوز (أكثر أماناً).
 */
define('ORANGE_STOREFRONT_PREVIEW_TOKEN', trim((string) ($env['ORANGE_STOREFRONT_PREVIEW_TOKEN'] ?? '')));

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
        __DIR__ . '/assets/css/theme-store-unified.css',
        __DIR__ . '/assets/js/app.js',
        __DIR__ . '/assets/js/cart.js',
        __DIR__ . '/assets/js/lang.js',
        __DIR__ . '/assets/js/product.js',
        __DIR__ . '/assets/js/input-constraints.js',
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

/**
 * يضبط $_GET['lang'] من حقل lang في جسم طلب JSON حتى تُترجم رسائل t() حتى بدون ?lang= في الرابط.
 */
function orange_storefront_apply_lang_from_payload(array $data): void
{
    $lb = isset($data['lang']) ? strtolower(trim((string) $data['lang'])) : '';
    if (in_array($lb, ['en', 'ar', 'fil', 'hi'], true)) {
        $_GET['lang'] = $lb;
    }
}

/** Storefront language picker labels (اسم كامل يظهر في القائمة المنسدلة وفي زر اللغة على سطح المكتب). */
function storefront_lang_options(): array {
    return [
        'ar' => ['label' => 'العربية'],
        'en' => ['label' => 'English'],
        'fil' => ['label' => 'Filipino'],
        'hi' => ['label' => 'हिन्दी'],
    ];
}

/**
 * آخر قناة واجهة — كوكي يُكمّل localStorage ليعمل إعادة التوجيه من index.php وروابط بدون ?channel=.
 */
function orange_storefront_channel_cookie_name(): string
{
    return 'orange_sf_ch';
}

function orange_storefront_channel_cookie_path(): string
{
    $p = PUBLIC_BASE_PATH;
    if ($p === '' || $p === '/') {
        return '/';
    }

    return rtrim($p, '/') . '/';
}

/**
 * مقاطع لا تُستخدم كاختصار URL لقناة (تعارض مسارات التطبيق).
 *
 * @return list<string>
 */
function orange_storefront_reserved_path_segments(): array
{
    return [
        'admin', 'api', 'assets', 'pages', 'includes', 'vendor', 'scripts',
        'storefront-dispatch', 'index', 'manifest', 'robots', 'favicon',
        'cgi-bin', 'pwa', 'webhook', 'hooks', 'static', 'uploads', 'well-known',
    ];
}

/**
 * بعد ترحيل القنوات: slug يطابق path_segment (tiktok / online / web).
 * يُحوَّل طلبات الكوكي أو ?channel= القديمة (orange / blue / black) للقيم الجديدة إن وُجدت في الجدول.
 *
 * @return array<string, string>
 */
function orange_storefront_legacy_slug_aliases(): array
{
    return ['orange' => 'tiktok', 'blue' => 'online', 'black' => 'web'];
}

/** أول قناة نشطة حسب id — افتراضي للمتجر عند غياب كوكي/قناة. */
function orange_storefront_default_channel_slug(PDO $pdo): string
{
    try {
        $v = $pdo->query('SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($v !== false && $v !== null && (string) $v !== '') {
            return (string) $v;
        }
    } catch (Throwable $e) {
    }

    return 'tiktok';
}

/**
 * يضمن slug نشطاً في الجدول؛ يطبّق aliases القديمة ثم الافتراضي.
 */
function orange_storefront_normalize_channel_slug(PDO $pdo, string $raw): string
{
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', trim($raw)) ?? ''));
    if ($slug === '') {
        return orange_storefront_default_channel_slug($pdo);
    }
    try {
        $st = $pdo->prepare('SELECT slug FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
        $st->execute([$slug]);
        $found = $st->fetchColumn();
        if ($found !== false && $found !== null && (string) $found !== '') {
            return (string) $found;
        }
        $aliases = orange_storefront_legacy_slug_aliases();
        if (isset($aliases[$slug])) {
            $cand = $aliases[$slug];
            $st->execute([$cand]);
            $found2 = $st->fetchColumn();
            if ($found2 !== false && $found2 !== null && (string) $found2 !== '') {
                return (string) $found2;
            }
        }
    } catch (Throwable $e) {
    }

    return orange_storefront_default_channel_slug($pdo);
}

function orange_storefront_channel_slug_is_active(PDO $pdo, string $slug): bool
{
    if ($slug === '') {
        return false;
    }
    try {
        $st = $pdo->prepare('SELECT 1 FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
        $st->execute([$slug]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function orange_channel_slug_for_path_segment(PDO $pdo, string $pathSegment, bool $requireActive = true): ?string
{
    $s = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $pathSegment) ?? ''));
    if ($s === '') {
        return null;
    }
    try {
        $sql = 'SELECT slug FROM channels WHERE path_segment = ?';
        if ($requireActive) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute([$s]);
        $row = $st->fetchColumn();
        if ($row !== false && $row !== null && (string) $row !== '') {
            return (string) $row;
        }
    } catch (Throwable $e) {
    }

    return null;
}

function orange_channel_path_segment_for_slug(PDO $pdo, string $slug): ?string
{
    $s = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $slug) ?? ''));
    if ($s === '') {
        return null;
    }
    try {
        $st = $pdo->prepare(
            "SELECT path_segment FROM channels WHERE slug = ? AND is_active = 1 AND path_segment IS NOT NULL AND path_segment <> '' LIMIT 1"
        );
        $st->execute([$s]);
        $row = $st->fetchColumn();
        if ($row !== false && $row !== null && (string) $row !== '') {
            return (string) $row;
        }
    } catch (Throwable $e) {
    }

    return null;
}

/**
 * خرائط مسار الاختصار ↔ slug للواجهة + قائمة slugs النشطة + نمط regex للمسار القصير.
 *
 * @return array{0: array<string,string>, 1: array<string,string>, 2: array<string,bool>, 3: non-falsy-string}
 */
function orange_storefront_path_maps_for_js(PDO $pdo): array
{
    $pathToSlug = [];
    $slugToPath = [];
    $validSlugs = [];
    try {
        $q = $pdo->query('SELECT slug, path_segment FROM channels WHERE is_active = 1');
        if ($q) {
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $sl = strtolower((string) ($row['slug'] ?? ''));
                if ($sl !== '') {
                    $validSlugs[$sl] = true;
                }
                $ps = strtolower(trim((string) ($row['path_segment'] ?? '')));
                if ($ps !== '' && $sl !== '') {
                    $pathToSlug[$ps] = $sl;
                    $slugToPath[$sl] = $ps;
                }
            }
        }
    } catch (Throwable $e) {
    }
    if ($pathToSlug === []) {
        $pathToSlug = ['tiktok' => 'tiktok', 'online' => 'online', 'web' => 'web'];
        $slugToPath = ['tiktok' => 'tiktok', 'online' => 'online', 'web' => 'web'];
    }
    foreach (orange_storefront_legacy_slug_aliases() as $legacy => $_) {
        $validSlugs[$legacy] = true;
    }
    $keys = array_keys($pathToSlug);
    usort($keys, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
    $alt = $keys === [] ? 'web|online|tiktok' : implode('|', array_map(static fn ($k) => preg_quote((string) $k, '/'), $keys));

    return [$pathToSlug, $slugToPath, $validSlugs, $alt];
}

function orange_storefront_read_saved_channel_slug(): ?string
{
    $name = orange_storefront_channel_cookie_name();
    $raw = isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : '';
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $raw) ?? ''));
    if ($slug === '') {
        return null;
    }
    try {
        $pdo = db();
        $resolved = orange_storefront_normalize_channel_slug($pdo, $slug);
        if (orange_storefront_channel_slug_is_active($pdo, $resolved)) {
            return $resolved;
        }
    } catch (Throwable $e) {
    }

    return null;
}

function orange_storefront_send_channel_cookie(string $slug): void
{
    if (headers_sent()) {
        return;
    }
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $slug) ?? ''));
    try {
        $pdo = db();
        if (!orange_storefront_channel_slug_is_active($pdo, $slug)) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    setcookie(orange_storefront_channel_cookie_name(), $slug, [
        'expires' => time() + 3600 * 24 * 400,
        'path' => orange_storefront_channel_cookie_path(),
        'secure' => $https,
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function current_channel_slug(): string
{
    $pdo = db();
    $def = orange_storefront_default_channel_slug($pdo);
    if (isset($_GET['channel']) && (string) $_GET['channel'] !== '') {
        $s = preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['channel']);

        return $s !== '' ? orange_storefront_normalize_channel_slug($pdo, $s) : $def;
    }
    $fromCookie = orange_storefront_read_saved_channel_slug();
    if ($fromCookie !== null) {
        return $fromCookie;
    }

    return $def;
}

function get_channel_by_slug(string $slug): ?array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * أول مقطع مسار قصير للواجهة من الطلب الحالي (مثل tiktok من ‎/tiktok-ar/cart‎) أو null.
 */
function orange_storefront_request_primary_path_segment(): ?string
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = parse_url($uri, PHP_URL_PATH) ?? '';
    $path = str_replace('\\', '/', $path);
    $base = PUBLIC_BASE_PATH;
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base)) ?: '/';
    }
    if ($path === '' || ($path[0] ?? '') !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    if (preg_match('#^/([a-z0-9\-]+)(?:-(ar|hi|ph))?(?:/|$)#i', $path, $m)) {
        return strtolower($m[1]);
    }

    return null;
}

/**
 * معاينة عبر جلسة الأدمن: لا يُعرض سر في رابط المتجر (يُضبط من ‎admin/preview-storefront.php‎).
 */
function orange_storefront_preview_session_valid_for_segment(string $pathSegmentFirst): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    $pv = $_SESSION['orange_sf_preview'] ?? null;
    if (!is_array($pv)) {
        return false;
    }
    if ((int) ($pv['exp'] ?? 0) < time()) {
        unset($_SESSION['orange_sf_preview']);

        return false;
    }
    $want = strtolower((string) ($pv['path_segment'] ?? ''));
    $got = strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', $pathSegmentFirst));

    return $want !== '' && $got !== '' && $want === $got;
}

function orange_storefront_preview_session_matches_request(): bool
{
    $seg = orange_storefront_request_primary_path_segment();
    if ($seg === null) {
        return false;
    }

    return orange_storefront_preview_session_valid_for_segment($seg);
}

/**
 * رابط «فتح الرئيسية» من شاشة الواجهات: يمرّ بالأدمن ويضبط جلسة معاينة قصيرة ثم يوجّه لمسار نظيف بلا ‎sf_preview‎.
 */
function orange_storefront_admin_preview_home_url(string $pathSegment): string
{
    $seg = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $pathSegment) ?? ''));
    if ($seg === '') {
        return '';
    }
    $pathPrefix = PUBLIC_BASE_PATH === '' ? '' : rtrim(PUBLIC_BASE_PATH, '/');
    $rel = ($pathPrefix === '' ? '' : $pathPrefix) . '/admin/preview-storefront.php?' . http_build_query(['ps' => $seg]);
    if (ORANGE_SITE_PUBLIC_ORIGIN !== '') {
        return rtrim(ORANGE_SITE_PUBLIC_ORIGIN, '/') . $rel;
    }

    return $rel;
}

/**
 * الاسم الظاهر للقناة فقط (يختلف بين WEB / ONLINE / TIKTOK): من حقل name أو من slug إن كان فارغاً.
 *
 * @param array<string,mixed> $channel
 */
function storefront_channel_display_name(array $channel, string $channelSlug): string {
    $n = trim((string) ($channel['name'] ?? ''));
    if ($n !== '') {
        return $n;
    }
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $channelSlug) ?? ''));
    return match ($slug) {
        'black', 'web' => 'WEB',
        'blue', 'online' => 'ONLINE',
        'orange', 'tiktok' => 'TIKTOK',
        default => t('storefront_brand'),
    };
}

/**
 * مسار الواجهة القصير (مثل tiktok-ar) من جدول channels.path_segment أو القيم الافتراضية القديمة.
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
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $channelSlug) ?? ''));
    try {
        $pdo = db();
        $base = orange_channel_path_segment_for_slug($pdo, $slug);
        if ($base !== null && $base !== '') {
            return $base . $suffix;
        }
    } catch (Throwable $e) {
    }
    $base = match ($slug) {
        'black', 'web' => 'web',
        'blue', 'online' => 'online',
        'orange', 'tiktok' => 'tiktok',
        default => null,
    };
    if ($base === null) {
        return null;
    }

    return $base . $suffix;
}

/** @return 'home'|'cart'|'track'|'product'|'register'|'verify_email' */
function storefront_current_page_kind(): string {
    $base = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    return match ($base) {
        'cart.php' => 'cart',
        'track.php' => 'track',
        'product.php' => 'product',
        'register.php' => 'register',
        'verify-email.php' => 'verify_email',
        default => 'home',
    };
}

/**
 * Shared state for storefront header toolbar + mobile app dock.
 *
 * @return array{channel: array<string, mixed>, lang: string, channelSlug: string, pageKind: 'home'|'cart'|'track'|'product'|'register'|'verify_email', storefrontExtra: array, langOpts: array, currentLangLabel: string}
 */
function storefront_toolbar_state(): array {
    $lang = current_lang();
    $slug = current_channel_slug();
    $channel = get_channel_by_slug($slug);
    $pdoTb = db();
    $defSlug = orange_storefront_default_channel_slug($pdoTb);
    if (!$channel) {
        $channel = [
            'id' => 0,
            'name' => 'Orange',
            'slug' => $slug !== '' ? $slug : $defSlug,
            'logo' => 'logo-orange.png',
            'whatsapp_number' => '',
        ];
    }
    $channelSlug = (string) ($channel['slug'] ?? $defSlug);
    $pageKind = storefront_current_page_kind();
    $storefrontExtra = [];
    if ($pageKind === 'product' && isset($_GET['id'])) {
        $storefrontExtra['id'] = (int)$_GET['id'];
    }
    if ($pageKind === 'verify_email' && isset($_GET['token']) && (string) $_GET['token'] !== '') {
        $storefrontExtra['token'] = (string) $_GET['token'];
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
 * @param 'home'|'cart'|'track'|'product'|'register'|'verify_email' $page
 * @param array<string, mixed> $extra merged into query for long URLs (e.g. id for product, token for verify)
 */
/**
 * أصل الموقع (https://النطاق) — من SITE_PUBLIC_URL أو من طلب HTTP الحالي.
 */
function orange_site_public_origin(): string
{
    if (ORANGE_SITE_PUBLIC_ORIGIN !== '') {
        return rtrim(ORANGE_SITE_PUBLIC_ORIGIN, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $scheme . '://' . $host;
}

/**
 * رابط مطلق لمسار الواجهة (كما يعيده storefront_url أو مسار يبدأ بـ /).
 */
function storefront_absolute_url(string $relativePath): string
{
    $path = $relativePath;
    if ($path !== '' && $path[0] !== '/') {
        $path = '/' . $path;
    }

    return orange_site_public_origin() . $path;
}

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
            'register', 'verify_email' => null,
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
        'register' => '/pages/register.php',
        'verify_email' => '/pages/verify-email.php',
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

/**
 * تسمية صف مقاس في جدول عائلة المقاسات حسب لغة الواجهة (أعمدة label_ar / label_en).
 *
 * @param array<string,mixed> $row
 */
function storefront_size_chart_cell_label(array $row): string
{
    $lang = current_lang();
    $ar = trim((string) ($row['label_ar'] ?? ''));
    $en = trim((string) ($row['label_en'] ?? ''));
    if ($lang === 'ar') {
        return $ar !== '' ? $ar : $en;
    }
    if ($lang === 'en') {
        return $en !== '' ? $en : $ar;
    }

    return $en !== '' ? $en : $ar;
}

/**
 * عنوان SEO للمنتج بلغة الواجهة الحالية؛ إن وُجد حقل seo_meta_title_* يُستخدم وإلا اسم العرض.
 */
function storefront_product_seo_meta_title(array $product): string
{
    $lang = current_lang();
    $key = match ($lang) {
        'ar' => 'seo_meta_title_ar',
        'en' => 'seo_meta_title_en',
        'fil' => 'seo_meta_title_fil',
        'hi' => 'seo_meta_title_hi',
        default => 'seo_meta_title_en',
    };
    $v = trim((string) ($product[$key] ?? ''));
    if ($v !== '') {
        return $v;
    }

    return storefront_product_display_name($product);
}

/**
 * وصف SEO (ميتا) — من seo_meta_description_* أو اقتطاع من وصف المنتج بعد إزالة وسوم HTML.
 */
function storefront_product_seo_meta_description(array $product): string
{
    $lang = current_lang();
    $key = match ($lang) {
        'ar' => 'seo_meta_description_ar',
        'en' => 'seo_meta_description_en',
        'fil' => 'seo_meta_description_fil',
        'hi' => 'seo_meta_description_hi',
        default => 'seo_meta_description_en',
    };
    $v = trim((string) ($product[$key] ?? ''));
    if ($v !== '') {
        return $v;
    }
    $plain = storefront_product_display_description($product);
    $plain = preg_replace('/\s+/u', ' ', strip_tags($plain));
    $plain = trim((string) $plain);
    if ($plain === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plain) > 160 ? mb_substr($plain, 0, 157) . '...' : $plain;
    }

    return strlen($plain) > 160 ? substr($plain, 0, 157) . '...' : $plain;
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
            'product_invalid_id' => 'This product link is not valid.',
            'product_back_to_shop' => 'Back to shop',
            'product_gallery_prev' => 'Previous image',
            'product_gallery_next' => 'Next image',
            'product_gallery_dots' => 'Gallery images',
            'sizing_guide' => 'Size guide (indicative)',
            'sizing_guide_close' => 'Close',
            'sizing_col_size' => 'Size',
            'sizing_col_foot_cm' => 'Foot length (cm)',
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
            'field_optional_short' => 'optional',
            'track_order' => 'Track Order',
            'track_order_howto' => 'To track your order, please enter the order number and the phone number you used when placing the order.',
            'track_form_section_title' => 'Track your order without signing up',
            'track_signup_cta_title' => 'Register your email',
            'track_signup_cta_text' => 'Sign up now to get updates on your order status and the latest offers and discounts… Stay informed of everything new.',
            'track_signup_cta_aria' => 'Sign up with email',
            'track_signup_close' => 'Close',
            'track_order_items' => 'Order items',
            'track_missing_fields' => 'Please enter the order number and phone.',
            'track_order_not_found' => 'No order matches these details. Check the number and phone.',
            'track_signup_placeholder_email' => 'Email address to receive the confirmation link.',
            'track_signup_placeholder_order_number' => 'As shown on your order confirmation (e.g. ORD-…)',
            'track_signup_verify_phone_label' => 'Order phone (for verification)',
            'track_signup_placeholder_verify_phone' => 'Same phone you used when placing this order',
            'track_signup_placeholder_name' => 'Same name as on your order (add or correct if needed).',
            'track_signup_placeholder_phone' => 'Same phone number you used when you placed this order.',
            'track_signup_placeholder_area' => 'Area as on the order.',
            'track_signup_placeholder_address' => 'Address as on the order.',
            'track_signup_placeholder_notes' => 'Notes (optional)',
            'track_signup_order_required' => 'Enter your order number and order phone in this form.',
            'track_signup_order_mismatch' => 'These details do not match an order. Check the order number and order phone in this form.',
            'track_signup_identity_note' => 'Enter your order number and the phone registered with us. If the details match our records, we will send the email confirmation link.',
            'track_signup_verify_order_btn' => 'Verify order details',
            'track_signup_verify_ok' => 'Order found — we filled in your details below. You can edit them if needed.',
            'track_signup_verify_fail' => 'No order matches these details. Check the order number and phone.',
            'track_signup_nudge_after_track' => 'We recommend registering with your email to track all your orders in one place and get updates on offers and discounts. Tap the button below to open sign-up at the top of this page.',
            'track_signup_nudge_open_btn' => 'Open sign-up above',
            'track_signup_nudge_merge' => '',
            'track_tracked_ok_below' => 'Order found. Review your order and finish email sign-up in the panel above.',
            'track_track_another' => 'Track another order',
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'Your name as you want it saved',
            'register_placeholder_phone' => '+country code and number, or 00…',
            'register_placeholder_area' => 'Area or region',
            'register_placeholder_address' => 'Full delivery address',
            'register_placeholder_notes' => 'Notes (optional)',
            'checkout_required_fields' => 'Please fill in all required fields (name, phone, email, area, address).',
            'checkout_invalid_email' => 'Please enter a valid email address.',
            'checkout_invalid_phone' => 'Enter a valid phone: international with + or 00 and country code, or choose your country and enter the national number (8–14 digits including country code).',
            'phone_country_label' => 'Country (for national number)',
            'phone_country_full_international' => 'International — full number with + or 00',
            'phone_country_kw' => 'Kuwait +965 (8-digit mobile)',
            'phone_country_ph' => 'Philippines +63',
            'phone_country_in' => 'India +91',
            'phone_country_pk' => 'Pakistan +92',
            'phone_field_hint' => 'Example: +96551234567, 0096512345678, or pick country and type national digits.',
            'customer_email' => 'Email',
            'order_number' => 'Order Number',
            'checkout_queue_wait' => 'Processing your order…',
            'checkout_queue_timeout' => 'Order queue timeout. Try again.',
            'checkout_cart_items_required' => 'Your cart has no items to order.',
            'checkout_invalid_channel' => 'This store channel is not available. Refresh the page and try again.',
            'checkout_internal_error' => 'We could not process your order. Please try again or contact the store.',
            'checkout_queue_busy' => 'The order queue is busy. Please wait…',
            'checkout_failed_generic' => 'We could not complete your order. Please try again or contact the store.',
            'intake_invalid_token' => 'This order link is invalid.',
            'intake_queue_unavailable' => 'Order processing is temporarily unavailable. Try again later.',
            'intake_not_found' => 'This order could not be found. Start a new checkout or contact the store.',
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
            'storefront_tagline' => 'Everything you wish for ... in one place.',
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
            'payment_online' => 'Online',
            'order_payment_terms_label' => 'Payment type',
            'checkout_online_cash_only' => 'Choose cash (e.g. pay on delivery) or online above. Credit (pay later) is only available through the admin company invoice.',
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
            'storefront_register' => 'Sign up',
            'storefront_register_title' => 'Email sign-up',
            'storefront_register_intro' => 'Sign up with your email for order status updates, offers, and discounts. We will send a confirmation link.',
            'storefront_guest_checkout_note' => 'You do not need an account to place an order — guest checkout is available if you prefer.',
            'checkout_enrich_data_hint' => 'After ordering, use Track order on this page to add your email and complete or correct your details. We save updates on your order and customer record so the store can serve you accurately.',
            'storefront_register_email_label' => 'Email',
            'storefront_register_submit' => 'Send confirmation link',
            'storefront_register_sent' => 'Check your inbox and open the confirmation link (check spam too).',
            'storefront_register_cooldown' => 'You requested a link just now. Wait about a minute before trying again, or check your inbox and spam folder.',
            'storefront_register_already_verified' => 'This email is already confirmed. You can continue shopping.',
            'storefront_register_mail_failed' => 'The confirmation email could not be sent. The store may need to configure outgoing mail. Try again later or contact support.',
            'storefront_register_service_unavailable' => 'Sign-up is temporarily unavailable. Try again later.',
            'storefront_register_invalid_phone' => 'Enter a valid phone number.',
            'storefront_register_error' => 'Something went wrong. Try again later.',
            'api_request_failed' => 'We could not complete this request. Please try again later.',
            'api_ok' => 'OK',
            'storefront_account_signed_in' => 'Signed in as',
            'storefront_logout' => 'Sign out',
            'storefront_verify_title' => 'Email confirmation',
            'storefront_verify_ok' => 'Your email is confirmed. You are signed in.',
            'storefront_verify_already' => 'This email was already confirmed.',
            'storefront_verify_bad_token' => 'This confirmation link is invalid or was already used.',
            'storefront_verify_expired' => 'This link expired. Request a new link from the sign-up page.',
            'storefront_your_channel' => 'Your registered store channel:',
            'storefront_pwa_install_hint' => 'On your phone: use the browser menu «Add to Home Screen» to add this store to your home screen.',
            'storefront_install_app_btn' => 'Install',
            'storefront_install_app_aria' => 'Add this store to your home screen',
            'storefront_install_modal_title' => 'Add to home screen',
            'storefront_install_modal_intro' => 'The phone will ask you to confirm. The option may be called «Add to Home Screen» or «Install app».',
            'storefront_install_ios_steps' => "1. Tap Share in Safari.\n2. Choose «Add to Home Screen».\n3. Tap Add — the icon opens this store.",
            'storefront_install_other_steps' => "1. Open the browser menu (⋮).\n2. Tap «Install app» or «Add to Home screen».\n3. If you do not see it, look for «Add to Home screen» in the menu.",
            'storefront_install_close' => 'Got it',
        ],
        'ar' => [
            'home' => 'الرئيسية',
            'cart' => 'السلة',
            'offers' => 'العروض',
            'all' => 'الكل',
            'currency_kd' => 'د.ك',
            'product_not_found' => 'تعذّر العثور على هذا المنتج.',
            'product_invalid_id' => 'رابط المنتج غير صالح.',
            'product_back_to_shop' => 'العودة إلى المتجر',
            'product_gallery_prev' => 'الصورة السابقة',
            'product_gallery_next' => 'الصورة التالية',
            'product_gallery_dots' => 'صور المنتج',
            'sizing_guide' => 'جدول المقاسات (إرشادي)',
            'sizing_guide_close' => 'إغلاق',
            'sizing_col_size' => 'المقاس',
            'sizing_col_foot_cm' => 'طول القدم (سم)',
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
            'field_optional_short' => 'اختياري',
            'track_order' => 'تتبع الطلب',
            'track_order_howto' => 'لتتبع الطلب، الرجاء إدخال رقم الطلب ورقم الهاتف المسجّل أثناء الطلب.',
            'track_form_section_title' => 'متابعة الطلب بدون تسجيل',
            'track_signup_cta_title' => 'سجّل بريدك',
            'track_signup_cta_text' => 'سجّل الآن ليصلك تحديثات حالة طلباتك ويصلك آخر العروض والتخفيضات … كن على اطلاع دائماً بما هو جديد.',
            'track_signup_cta_aria' => 'تسجيل بالبريد الإلكتروني',
            'track_signup_close' => 'إغلاق',
            'track_order_items' => 'محتويات الطلب',
            'track_missing_fields' => 'يرجى إدخال رقم الطلب ورقم الهاتف.',
            'track_order_not_found' => 'لا يوجد طلب بهذه البيانات. تحقق من الرقم والهاتف.',
            'track_signup_placeholder_email' => 'البريد الذي تريد استلام رابط التأكيد عليه.',
            'track_signup_placeholder_order_number' => 'كما في رسالة أو تأكيد الطلب (مثال ORD-…)',
            'track_signup_verify_phone_label' => 'هاتف الطلب (للتحقق)',
            'track_signup_placeholder_verify_phone' => 'نفس رقم الهاتف المستخدم عند تنفيذ الطلب',
            'track_signup_placeholder_name' => 'اسمك كما في الطلب (يمكنك التصحيح أو الإكمال).',
            'track_signup_placeholder_phone' => 'رقم الهاتف الذي أُدخل عند إنشاء هذا الطلب.',
            'track_signup_placeholder_area' => 'المنطقة كما في الطلب.',
            'track_signup_placeholder_address' => 'العنوان كما في الطلب.',
            'track_signup_placeholder_notes' => 'ملاحظات (اختياري)',
            'track_signup_order_required' => 'أدخل رقم الطلب وهاتف الطلب في هذا النموذج.',
            'track_signup_order_mismatch' => 'البيانات لا تطابق طلباً. تحقق من رقم الطلب وهاتف الطلب في النموذج.',
            'track_signup_identity_note' => 'أدخل رقم الطلب والهاتف المسجّل لدينا. في حال صحة البيانات المسجّلة يتم إرسال رابط البريد.',
            'track_signup_verify_order_btn' => 'التحقق من بيانات الطلب',
            'track_signup_verify_ok' => 'تم التحقق — عُبئت بيانات الطلب في الحقول أدناه ويمكنك تعديلها.',
            'track_signup_verify_fail' => 'لا يوجد طلب بهذه البيانات. تحقق من الرقم والهاتف.',
            'track_signup_nudge_after_track' => 'ننصحك بالتسجيل بالبريد الإلكتروني لمتابعة جميع طلباتك من مكان واحد، وليصلك تحديث العروض والتخفيضات. اضغط الزر أدناه لفتح «التسجيل» أعلى الصفحة.',
            'track_signup_nudge_open_btn' => 'فتح التسجيل أعلى الصفحة',
            'track_signup_nudge_merge' => '',
            'track_tracked_ok_below' => 'تم العثور على طلبك. راجع التفاصيل وأكمل التسجيل بالبريد في الصندوق أعلاه.',
            'track_track_another' => 'تتبع رقم آخر',
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'اسمك كما تريد حفظه',
            'register_placeholder_phone' => '+كود الدولة والرقم أو 00…',
            'register_placeholder_area' => 'المنطقة',
            'register_placeholder_address' => 'عنوان التوصيل كاملاً',
            'register_placeholder_notes' => 'ملاحظات (اختياري)',
            'checkout_required_fields' => 'يرجى تعبئة جميع الحقول المطلوبة (الاسم، الهاتف، البريد، المنطقة، العنوان).',
            'checkout_invalid_email' => 'يرجى إدخال بريد إلكتروني صالح.',
            'checkout_invalid_phone' => 'أدخل هاتفاً صالحاً: دولي بـ + أو 00 مع كود الدولة، أو اختر الدولة ثم الرقم الوطني (من 8 إلى 14 رقماً مع كود الدولة).',
            'phone_country_label' => 'الدولة (للرقم الوطني)',
            'phone_country_full_international' => 'دولي — الرقم كاملاً بـ + أو 00',
            'phone_country_kw' => 'الكويت +965 (جوال 8 أرقام)',
            'phone_country_ph' => 'الفلبين +63',
            'phone_country_in' => 'الهند +91',
            'phone_country_pk' => 'باكستان +92',
            'phone_field_hint' => 'مثال: +96551234567 أو 0096512345678 أو اختر الدولة ثم أدخل الرقم.',
            'customer_email' => 'البريد الإلكتروني',
            'order_number' => 'رقم الطلب',
            'checkout_queue_wait' => 'جاري معالجة طلبك…',
            'checkout_queue_timeout' => 'انتهت مهلة طابور الطلبات. أعد المحاولة.',
            'checkout_cart_items_required' => 'لا توجد أصناف في السلة لإتمام الطلب.',
            'checkout_invalid_channel' => 'قناة المتجر غير متاحة. حدّث الصفحة وأعد المحاولة.',
            'checkout_internal_error' => 'تعذّر معالجة الطلب. أعد المحاولة أو تواصل مع المتجر.',
            'checkout_queue_busy' => 'طابور الطلبات مشغول. يرجى الانتظار…',
            'checkout_failed_generic' => 'تعذّر إتمام الطلب. أعد المحاولة أو تواصل مع المتجر.',
            'intake_invalid_token' => 'رابط الطلب غير صالح.',
            'intake_queue_unavailable' => 'معالجة الطلبات غير متاحة مؤقتاً. حاول لاحقاً.',
            'intake_not_found' => 'تعذّر العثور على هذا الطلب. أعد الطلب أو تواصل مع المتجر.',
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
            'storefront_tagline' => 'كل ما تتمناه ... في مكان واحد.',
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
            'payment_online' => 'أونلاين',
            'order_payment_terms_label' => 'نوع البيع',
            'checkout_online_cash_only' => 'اختر أعلاه نقدي (مثل الدفع عند الاستلام) أو أونلاين. البيع الآجل يُسجَّل من لوحة الإدارة فقط (فاتورة مبيعات).',
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
            'storefront_register' => 'تسجيل',
            'storefront_register_title' => 'تسجيل بالبريد',
            'storefront_register_intro' => 'سجّل ببريدك لتحديثات حالة الطلبات والعروض والتخفيضات؛ سنرسل رابط تأكيد.',
            'storefront_guest_checkout_note' => 'الشراء لا يتطلّب حساباً — يمكنك الطلب كضيف إذا رغبت، دون التسجيل بالبريد.',
            'checkout_enrich_data_hint' => 'بعد تنفيذ الطلب يمكنك من قسم «تتبع الطلب» في هذه الصفحة إضافة بريدك وإكمال أو تصحيح بياناتك؛ نحدّث الطلب وسجل العميل ليتمكن المتجر من التوصيل والمتابعة بدقة.',
            'storefront_register_email_label' => 'البريد الإلكتروني',
            'storefront_register_submit' => 'إرسال رابط التأكيد',
            'storefront_register_sent' => 'تحقّق من بريدك وافتح رابط التأكيد (وراجع البريد غير الهام).',
            'storefront_register_cooldown' => 'طلبت الرابط للتو. انتظر نحو دقيقة ثم أعد المحاولة، أو راجع بريدك والبريد غير الهام.',
            'storefront_register_already_verified' => 'هذا البريد مؤكَّد مسبقاً — يمكنك متابعة التسوق.',
            'storefront_register_mail_failed' => 'تعذّر إرسال بريد التأكيد. قد يحتاج المتجر لضبط البريد الصادر. حاول لاحقاً أو تواصل مع الدعم.',
            'storefront_register_service_unavailable' => 'التسجيل غير متاح مؤقتاً. حاول لاحقاً.',
            'storefront_register_invalid_phone' => 'أدخل رقم هاتف صالحاً.',
            'storefront_register_error' => 'تعذّر الإكمال. حاول لاحقاً.',
            'api_request_failed' => 'تعذّر إكمال الطلب. حاول لاحقاً.',
            'storefront_account_signed_in' => 'مسجّل الدخول كـ',
            'storefront_logout' => 'تسجيل الخروج',
            'storefront_verify_title' => 'تأكيد البريد',
            'storefront_verify_ok' => 'تم تأكيد بريدك وتم تسجيل دخولك.',
            'storefront_verify_already' => 'هذا البريد مؤكَّد مسبقاً.',
            'storefront_verify_bad_token' => 'رابط التأكيد غير صالح أو تم استخدامه.',
            'storefront_verify_expired' => 'انتهت صلاحية الرابط. اطلب رابطاً جديداً من صفحة التسجيل.',
            'storefront_your_channel' => 'قناة المتجر المسجّلة لحسابك:',
            'storefront_pwa_install_hint' => 'على الجوال: من قائمة المتصفح اختر «إضافة إلى الشاشة الرئيسية» لإضافة نفس واجهة المتجر إلى الشاشة الرئيسية.',
            'storefront_install_app_btn' => 'تثبيت',
            'storefront_install_app_aria' => 'إضافة المتجر إلى الشاشة الرئيسية',
            'storefront_install_modal_title' => 'إضافة إلى الشاشة الرئيسية',
            'storefront_install_modal_intro' => 'سيظهر لك تأكيد من النظام. قد يُسمّى الخيار «إضافة إلى الشاشة الرئيسية» أو «تثبيت التطبيق» حسب المتصفح.',
            'storefront_install_ios_steps' => "1. اضغط «مشاركة» (□↑) في سفاري.\n2. اختر «إضافة إلى الشاشة الرئيسية».\n3. اضغط إضافة — الأيقونة تفتح نفس المتجر.",
            'storefront_install_other_steps' => "1. افتح قائمة المتصفح (⋮).\n2. اختر «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».\n3. إن لم يظهر، ابحث عن «إضافة إلى الشاشة الرئيسية» في القائمة.",
            'storefront_install_close' => 'فهمت',
        ],
        'fil' => [
            'home' => 'Home',
            'cart' => 'Cart',
            'offers' => 'Offers',
            'all' => 'Lahat',
            'currency_kd' => 'KD',
            'product_not_found' => 'Hindi mahanap ang produktong ito.',
            'product_invalid_id' => 'Hindi wasto ang link ng produkto.',
            'product_back_to_shop' => 'Bumalik sa tindahan',
            'product_gallery_prev' => 'Nakaraang larawan',
            'product_gallery_next' => 'Susunod na larawan',
            'product_gallery_dots' => 'Mga larawan ng produkto',
            'sizing_guide' => 'Gabay sa sukat (pang-reference)',
            'sizing_guide_close' => 'Isara',
            'sizing_col_size' => 'Sukat',
            'sizing_col_foot_cm' => 'Haba ng paa (cm)',
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
            'field_optional_short' => 'opsyonal',
            'track_order' => 'I-track ang Order',
            'track_order_howto' => 'Para i-track ang order mo, ilagay ang order number at ang telepono na ginamit mo nung nag-order.',
            'track_form_section_title' => 'I-track ang order nang walang sign-up',
            'track_signup_cta_title' => 'I-register ang email mo',
            'track_signup_cta_text' => 'Mag-sign up na para sa updates sa status ng order mo, pinakabagong offers at discounts… Laging alam ang bago.',
            'track_signup_cta_aria' => 'Mag-sign up gamit ang email',
            'track_signup_close' => 'Isara',
            'track_order_items' => 'Mga item sa order',
            'track_missing_fields' => 'Ilagay ang order number at telepono.',
            'track_order_not_found' => 'Walang tumutugmang order. Suriin ang numero at telepono.',
            'track_signup_placeholder_email' => 'Email para sa confirmation link.',
            'track_signup_placeholder_order_number' => 'Gaya sa order confirmation (hal. ORD-…)',
            'track_signup_verify_phone_label' => 'Telepono sa order (beripikasyon)',
            'track_signup_placeholder_verify_phone' => 'Parehong telepono nung nag-order',
            'track_signup_placeholder_name' => 'Parehong pangalan sa order (i-edit kung kailangan).',
            'track_signup_placeholder_phone' => 'Parehong telepono na ginamit nung nag-order.',
            'track_signup_placeholder_area' => 'Lugar gaya sa order.',
            'track_signup_placeholder_address' => 'Address gaya sa order.',
            'track_signup_placeholder_notes' => 'Mga tala (opsyonal)',
            'track_signup_order_required' => 'Ilagay ang order number at telepono ng order sa form na ito.',
            'track_signup_order_mismatch' => 'Hindi tumutugma. Suriin ang order number at telepono sa form.',
            'track_signup_identity_note' => 'Ilagay ang order number at telepono na naka-record sa amin. Kapag tumugma ang detalye, ipapadala ang email confirmation link.',
            'track_signup_verify_order_btn' => 'Beripikahin ang detalye ng order',
            'track_signup_verify_ok' => 'Nahanap ang order — napunan ang mga field sa ibaba. Pwede mong i-edit.',
            'track_signup_verify_fail' => 'Walang tumutugmang order. Suriin ang numero at telepono.',
            'track_signup_nudge_after_track' => 'Mag-register gamit ang email para masubaybayan ang lahat ng orders at makatanggap ng updates sa offers. I-tap ang button para buksan ang sign-up sa itaas.',
            'track_signup_nudge_open_btn' => 'Buksan ang sign-up sa itaas',
            'track_signup_nudge_merge' => '',
            'track_tracked_ok_below' => 'Nahanap ang order. Tingnan ang detalye at tapusin ang sign-up sa panel sa itaas.',
            'track_track_another' => 'Mag-track ng ibang order',
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'Pangalan mo',
            'register_placeholder_phone' => '+country code o 00…',
            'register_placeholder_area' => 'Lugar',
            'register_placeholder_address' => 'Buong address',
            'register_placeholder_notes' => 'Mga tala (opsyonal)',
            'checkout_required_fields' => 'Punan ang lahat ng kinakailangang field (pangalan, telepono, email, lugar, address).',
            'checkout_invalid_email' => 'Maglagay ng wastong email.',
            'checkout_invalid_phone' => 'Maglagay ng wastong numero: international gamit ang + o 00 at country code, o piliin ang bansa at ang pambansang numero (8–14 digit kasama ang country code).',
            'phone_country_label' => 'Bansa (para sa pambansang numero)',
            'phone_country_full_international' => 'International — buong numero na may + o 00',
            'phone_country_kw' => 'Kuwait +965 (8 digit mobile)',
            'phone_country_ph' => 'Pilipinas +63',
            'phone_country_in' => 'India +91',
            'phone_country_pk' => 'Pakistan +92',
            'phone_field_hint' => 'Halimbawa: +96551234567 o pumili ng bansa at ilagay ang numero.',
            'customer_email' => 'Email',
            'order_number' => 'Order Number',
            'checkout_queue_wait' => 'Pinoproseso ang order…',
            'checkout_queue_timeout' => 'Timeout sa pila ng order. Subukan ulit.',
            'checkout_cart_items_required' => 'Walang item sa cart para i-order.',
            'checkout_invalid_channel' => 'Hindi available ang channel ng tindahan. I-refresh ang page at subukan ulit.',
            'checkout_internal_error' => 'Hindi naproseso ang order. Subukan ulit o makipag-ugnayan sa tindahan.',
            'checkout_queue_busy' => 'Abala ang pila ng order. Maghintay…',
            'checkout_failed_generic' => 'Hindi natapos ang order. Subukan ulit o makipag-ugnayan sa tindahan.',
            'intake_invalid_token' => 'Hindi wasto ang link ng order.',
            'intake_queue_unavailable' => 'Hindi pansamantala available ang pagproseso ng order. Subukan mamaya.',
            'intake_not_found' => 'Hindi mahanap ang order. Magsimula ng bagong checkout o makipag-ugnayan.',
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
            'storefront_tagline' => 'Lahat ng gusto mo ... sa isang lugar.',
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
            'payment_online' => 'Online',
            'order_payment_terms_label' => 'Uri ng bayad',
            'checkout_online_cash_only' => 'Pumili ng cash o online sa itaas. Ang credit (utang) ay sa admin (company invoice) lang.',
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
            'storefront_register' => 'Sign up',
            'storefront_register_title' => 'Email sign-up',
            'storefront_register_intro' => 'Mag-register gamit ang email para sa order status, offers, at discounts. Magpapadala kami ng confirmation link.',
            'storefront_guest_checkout_note' => 'Hindi kailangan ng account para bumili — available ang guest checkout kung gusto mo.',
            'checkout_enrich_data_hint' => 'Pagkatapos mag-order, gamitin ang Track order sa page na ito para magdagdag ng email at kumpletuhin o itama ang detalye. Nai-save sa order at customer record para mas tumpak ang serbisyo.',
            'storefront_register_email_label' => 'Email',
            'storefront_register_submit' => 'Send confirmation link',
            'storefront_register_sent' => 'Tingnan ang inbox mo at buksan ang link (spam folder rin).',
            'storefront_register_cooldown' => 'Kaka-request mo lang ng link. Maghintay ng halos isang minuto bago ulitin, o tingnan ang inbox at spam.',
            'storefront_register_already_verified' => 'Na-confirm na ang email na ito. Puwede ka nang mag-shopping.',
            'storefront_register_mail_failed' => 'Hindi naipadala ang confirmation email. Baka kailangan i-set up ng tindahan ang outgoing mail. Subukan mamaya o makipag-ugnayan.',
            'storefront_register_service_unavailable' => 'Hindi pansamantala available ang sign-up. Subukan mamaya.',
            'storefront_register_invalid_phone' => 'Maglagay ng wastong numero ng telepono.',
            'storefront_register_error' => 'May mali. Subukan ulit mamaya.',
            'api_request_failed' => 'Hindi natapos ang hiling. Subukan ulit mamaya.',
            'api_ok' => 'OK',
            'storefront_account_signed_in' => 'Naka-sign in bilang',
            'storefront_logout' => 'Sign out',
            'storefront_verify_title' => 'Email confirmation',
            'storefront_verify_ok' => 'Na-confirm ang email. Naka-sign in ka na.',
            'storefront_verify_already' => 'Na-confirm na ang email na ito.',
            'storefront_verify_bad_token' => 'Hindi wasto ang link o nagamit na.',
            'storefront_verify_expired' => 'Expired ang link. Humingi ng bago sa sign-up page.',
            'storefront_your_channel' => 'Ang naka-register na channel ng tindahan:',
            'storefront_pwa_install_hint' => 'Sa phone: gamitin ang browser menu → «Add to Home Screen» para idagdag ang tindahan sa home screen.',
            'storefront_install_app_btn' => 'Install',
            'storefront_install_app_aria' => 'Idagdag ang tindahan sa home screen',
            'storefront_install_modal_title' => 'Idagdag sa home screen',
            'storefront_install_modal_intro' => 'May magpapakita na kumpirmasyon. Puwedeng «Add to Home Screen» o «Install app» depende sa browser.',
            'storefront_install_ios_steps' => "1. I-tap ang Share (□↑) sa Safari.\n2. Piliin ang «Add to Home Screen».\n3. I-tap Add — ang icon ay bubuksan ang tindahang ito.",
            'storefront_install_other_steps' => "1. Buksan ang browser menu (⋮).\n2. Hanapin ang «Install app» o «Add to Home screen».\n3. Kung wala, hanapin ang «Add to Home screen» sa menu.",
            'storefront_install_close' => 'Okay',
        ],
        'hi' => [
            'home' => 'होम',
            'cart' => 'कार्ट',
            'offers' => 'ऑफ़र',
            'all' => 'सभी',
            'currency_kd' => 'KD',
            'product_not_found' => 'यह उत्पाद नहीं मिला।',
            'product_invalid_id' => 'उत्पाद लिंक अमान्य है।',
            'product_back_to_shop' => 'दुकान पर वापस जाएँ',
            'product_gallery_prev' => 'पिछली छवि',
            'product_gallery_next' => 'अगली छवि',
            'product_gallery_dots' => 'उत्पाद छवियाँ',
            'sizing_guide' => 'साइज़ गाइड (संकेतक)',
            'sizing_guide_close' => 'बंद करें',
            'sizing_col_size' => 'साइज़',
            'sizing_col_foot_cm' => 'पैर की लंबाई (सेमी)',
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
            'field_optional_short' => 'वैकल्पिक',
            'track_order' => 'ऑर्डर ट्रैक करें',
            'track_order_howto' => 'ऑर्डर ट्रैक करने के लिए कृपया ऑर्डर नंबर और वह फ़ोन नंबर दर्ज करें जो ऑर्डर करते समय दिया था।',
            'track_form_section_title' => 'बिना साइन अप ऑर्डर ट्रैक करें',
            'track_signup_cta_title' => 'अपना ईमेल पंजीकृत करें',
            'track_signup_cta_text' => 'अभी साइन अप करें — ऑर्डर की स्थिति, नवीनतम ऑफ़र और छूट पर अपडेट… हमेशा नई जानकारी पाएँ।',
            'track_signup_cta_aria' => 'ईमेल से साइन अप',
            'track_signup_close' => 'बंद करें',
            'track_order_items' => 'ऑर्डर की वस्तुएँ',
            'track_missing_fields' => 'कृपया ऑर्डर नंबर और फ़ोन दर्ज करें।',
            'track_order_not_found' => 'इन विवरणों से कोई ऑर्डर नहीं मिला। नंबर और फ़ोन जाँचें।',
            'track_signup_placeholder_email' => 'पुष्टिकरण लिंक के लिए ईमेल।',
            'track_signup_placeholder_order_number' => 'जैसा ऑर्डर कन्फ़र्मेशन में (जैसे ORD-…)',
            'track_signup_verify_phone_label' => 'ऑर्डर वाला फ़ोन (सत्यापन)',
            'track_signup_placeholder_verify_phone' => 'वही फ़ोन जो ऑर्डर करते समय दिया था',
            'track_signup_placeholder_name' => 'ऑर्डर पर जैसा नाम (ज़रूरत हो तो सुधारें)।',
            'track_signup_placeholder_phone' => 'वही फ़ोन जो ऑर्डर करते समय दिया था।',
            'track_signup_placeholder_area' => 'ऑर्डर जैसा क्षेत्र।',
            'track_signup_placeholder_address' => 'ऑर्डर जैसा पता।',
            'track_signup_placeholder_notes' => 'नोट्स (वैकल्पिक)',
            'track_signup_order_required' => 'इस फ़ॉर्म में ऑर्डर नंबर और ऑर्डर फ़ोन भरें।',
            'track_signup_order_mismatch' => 'मेल नहीं खाता। फ़ॉर्म में ऑर्डर नंबर और फ़ोन जाँचें।',
            'track_signup_identity_note' => 'वह ऑर्डर नंबर और फ़ोन दें जो हमारे पास दर्ज है। विवरण सही मिलने पर ईमेल पुष्टि लिंक भेजा जाएगा।',
            'track_signup_verify_order_btn' => 'ऑर्डर विवरण सत्यापित करें',
            'track_signup_verify_ok' => 'ऑर्डर मिला — नीचे के फ़ील्ड भर दिए गए। आप बदलाव कर सकते हैं।',
            'track_signup_verify_fail' => 'कोई ऑर्डर नहीं मिला। नंबर और फ़ोन जाँचें।',
            'track_signup_nudge_after_track' => 'ईमेल से पंजीकरण करें — सभी ऑर्डर एक जगह ट्रैक करें और ऑफ़र अपडेट पाएं। ऊपर साइन अप खोलने के लिए बटन दबाएँ।',
            'track_signup_nudge_open_btn' => 'ऊपर साइन अप खोलें',
            'track_signup_nudge_merge' => '',
            'track_tracked_ok_below' => 'ऑर्डर मिल गया। विवरण ऊपर वाले पैनल में देखें और ईमेल साइन अप पूरा करें।',
            'track_track_another' => 'दूसरा ऑर्डर ट्रैक करें',
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'आपका नाम',
            'register_placeholder_phone' => '+देश कोड या 00…',
            'register_placeholder_area' => 'क्षेत्र',
            'register_placeholder_address' => 'पूरा पता',
            'register_placeholder_notes' => 'नोट्स (वैकल्पिक)',
            'checkout_required_fields' => 'कृपया सभी आवश्यक फ़ील्ड भरें (नाम, फ़ोन, ईमेल, क्षेत्र, पता)।',
            'checkout_invalid_email' => 'कृपया वैध ईमेल दर्ज करें।',
            'checkout_invalid_phone' => 'वैध फ़ोन दर्ज करें: + या 00 के साथ अंतर्राष्ट्रीय, या देश चुनकर राष्ट्रीय नंबर (देश कोड सहित 8–14 अंक)।',
            'phone_country_label' => 'देश (राष्ट्रीय नंबर के लिए)',
            'phone_country_full_international' => 'अंतर्राष्ट्रीय — + या 00 के साथ पूरा नंबर',
            'phone_country_kw' => 'कुवैत +965 (8 अंक मोबाइल)',
            'phone_country_ph' => 'फ़िलीपीन्स +63',
            'phone_country_in' => 'भारत +91',
            'phone_country_pk' => 'पाकिस्तान +92',
            'phone_field_hint' => 'उदाहरण: +96551234567 या देश चुनकर नंबर दर्ज करें।',
            'customer_email' => 'ईमेल',
            'order_number' => 'ऑर्डर नंबर',
            'checkout_queue_wait' => 'ऑर्डर प्रोसेस हो रहा है…',
            'checkout_queue_timeout' => 'ऑर्डर कतार का समय समाप्त। पुनः प्रयास करें।',
            'checkout_cart_items_required' => 'कार्ट में ऑर्डर के लिए कोई वस्तु नहीं है।',
            'checkout_invalid_channel' => 'यह स्टोर चैनल उपलब्ध नहीं है। पेज रीफ़्रेश करके पुनः प्रयास करें।',
            'checkout_internal_error' => 'ऑर्डर प्रोसेस नहीं हो सका। पुनः प्रयास करें या स्टोर से संपर्क करें।',
            'checkout_queue_busy' => 'ऑर्डर कतार व्यस्त है। कृपया प्रतीक्षा करें…',
            'checkout_failed_generic' => 'ऑर्डर पूरा नहीं हो सका। पुनः प्रयास करें या संपर्क करें।',
            'intake_invalid_token' => 'ऑर्डर लिंक अमान्य है।',
            'intake_queue_unavailable' => 'ऑर्डर प्रोसेसिंग अभी उपलब्ध नहीं है। बाद में कोशिश करें।',
            'intake_not_found' => 'यह ऑर्डर नहीं मिला। नया चेकआउट शुरू करें या संपर्क करें।',
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
            'storefront_tagline' => 'जो कुछ भी आप चाहें ... एक ही जगह पर।',
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
            'payment_online' => 'ऑनलाइन',
            'order_payment_terms_label' => 'भुगतान प्रकार',
            'checkout_online_cash_only' => 'ऊपर नकद या ऑनलाइन चुनें। उधार (क्रेडिट) केवल एडमिन (कंपनी इनवॉइस) से।',
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
            'storefront_register' => 'साइन अप',
            'storefront_register_title' => 'ईमेल से साइन अप',
            'storefront_register_intro' => 'ऑर्डर स्थिति, ऑफ़र और छूट के अपडेट के लिए ईमेल से पंजीकरण करें; हम पुष्टि लिंक भेजेंगे।',
            'storefront_guest_checkout_note' => 'खरीदारी के लिए खाता ज़रूरी नहीं — चाहें तो अतिथि चेकआउट उपलब्ध है।',
            'checkout_enrich_data_hint' => 'ऑर्डर के बाद इसी पेज पर «ऑर्डर ट्रैक करें» से ईमेल जोड़ें और विवरण पूरा या सुधारें। अपडेट ऑर्डर और ग्राहक रिकॉर्ड में सहेजे जाते हैं ताकि स्टोर सटीक सेवा दे सके।',
            'storefront_register_email_label' => 'ईमेल',
            'storefront_register_submit' => 'पुष्टि लिंक भेजें',
            'storefront_register_sent' => 'अपना इनबॉक्स देखें और लिंक खोलें (स्पैम भी देखें)।',
            'storefront_register_cooldown' => 'अभी-अभी आपने लिंक माँगा है। लगभग एक मिनट बाद फिर कोशिश करें, या इनबॉक्स/स्पैम देखें।',
            'storefront_register_already_verified' => 'यह ईमेल पहले से पुष्ट है — खरीदारी जारी रख सकते हैं।',
            'storefront_register_mail_failed' => 'पुष्टि ईमेल नहीं भेजा जा सका। स्टोर को आउटगोइंग मेल सेट करना पड़ सकता है। बाद में कोशिश करें या संपर्क करें।',
            'storefront_register_service_unavailable' => 'साइन-अप अभी उपलब्ध नहीं है। बाद में कोशिश करें।',
            'storefront_register_invalid_phone' => 'वैध फ़ोन नंबर दर्ज करें।',
            'storefront_register_error' => 'कुछ गलत हुआ। बाद में कोशिश करें।',
            'api_request_failed' => 'अनुरोध पूरा नहीं हो सका। बाद में कोशिश करें।',
            'api_ok' => 'ठीक',
            'storefront_account_signed_in' => 'साइन इन:',
            'storefront_logout' => 'साइन आउट',
            'storefront_verify_title' => 'ईमेल पुष्टि',
            'storefront_verify_ok' => 'आपका ईमेल पुष्ट हो गया। आप साइन इन हैं।',
            'storefront_verify_already' => 'यह ईमेल पहले ही पुष्ट है।',
            'storefront_verify_bad_token' => 'लिंक अमान्य है या उपयोग हो चुका है।',
            'storefront_verify_expired' => 'लिंक की समय सीमा समाप्त। साइन अप पेज से नया लिंक माँगें।',
            'storefront_your_channel' => 'आपका पंजीकृत स्टोर चैनल:',
            'storefront_pwa_install_hint' => 'फ़ोन पर: ब्राउज़र मेनू से «Add to Home Screen» चुनकर यही स्टोर होम स्क्रीन पर जोड़ें।',
            'storefront_install_app_btn' => 'इंस्टॉल',
            'storefront_install_app_aria' => 'स्टोर को होम स्क्रीन पर जोड़ें',
            'storefront_install_modal_title' => 'होम स्क्रीन पर जोड़ें',
            'storefront_install_modal_intro' => 'फ़ोन पुष्टि माँगेगा। विकल्प «Add to Home Screen» या «Install app» जैसा हो सकता है।',
            'storefront_install_ios_steps' => "1. Safari में Share (□↑) टैप करें।\n2. «Add to Home Screen» चुनें।\n3. Add टैप करें — आइकन यही स्टोर खोलेगा।",
            'storefront_install_other_steps' => "1. ब्राउज़र मेनू (⋮) खोलें।\n2. «Install app» या «Add to Home screen» टैप करें।\n3. न दिखे तो मेनू में «Add to Home screen» खोजें।",
            'storefront_install_close' => 'समझ गया',
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
 * تسجيل نشاط إداري في الجدول orange_admin_audit_log (يُنشأ تلقائياً مع المخطط).
 * لا يرمي استثناءً للأعلى حتى لا تتعطل عمليات الـ API.
 *
 * @param int|string|null $entityId
 */
function audit_log(string $action, string $message, string $entityTable = '', $entityId = null): void
{
    if (function_exists('error_log') && filter_var(getenv('ORANGE_AUDIT_LOG') ?: '', FILTER_VALIDATE_BOOLEAN)) {
        error_log('[orange audit] ' . $action . ' | ' . $message . ' | ' . $entityTable . ' | ' . (string) $entityId);
    }
    try {
        if (!function_exists('db')) {
            return;
        }
        $pdo = db();
        require_once __DIR__ . '/includes/catalog_schema.php';
        orange_catalog_ensure_schema($pdo);
        if (!orange_table_exists($pdo, 'orange_admin_audit_log')) {
            return;
        }
        $adminId = null;
        if (function_exists('current_admin')) {
            $c = current_admin();
            if ($c && !empty($c['id'])) {
                $adminId = (int) $c['id'];
                if ($adminId <= 0) {
                    $adminId = null;
                }
            }
        }
        $eid = $entityId === null || $entityId === '' ? '' : (string) $entityId;
        $st = $pdo->prepare(
            'INSERT INTO orange_admin_audit_log (admin_id, action, message, entity_table, entity_id) VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            $adminId,
            $action,
            $message,
            $entityTable,
            $eid,
        ]);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange audit_log] ' . $e->getMessage());
        }
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
        'code' => 'server_error',
        'message' => $userMessage,
    ];
    $debug = getenv('ORANGE_API_DEBUG');
    if ($debug === '1' || $debug === 'true') {
        $payload['debug'] = $e->getMessage();
    }
    json_response($payload, 500);
}

/**
 * واجهات الأدمن: أخطاء منطق الأعمال (RuntimeException) تُعرض للمستخدم؛ غير ذلك api_error (بدون تسريب تقني).
 */
function orange_admin_api_catch(Throwable $e, string $genericMessage, int $businessHttpCode = 422): void
{
    if ($e instanceof RuntimeException) {
        json_response(['success' => false, 'message' => $e->getMessage()], $businessHttpCode);
    }
    api_error($e, $genericMessage);
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
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
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
