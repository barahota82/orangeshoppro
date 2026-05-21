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

/** ترحيل المخطط: سلسلة صارمة 001.sql…NNN.sql تُحدّث orange_schema_meta خطوة بخطوة (كل DDL في SQL) — الافتراضي false (آمن مع جسم PHP). */
$__strictNum = $env['ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS'] ?? false;
define(
    'ORANGE_STRICT_NUMBERED_SQL_MIGRATIONS',
    $__strictNum === true
    || $__strictNum === 1
    || $__strictNum === '1'
    || strtolower((string) $__strictNum) === 'true'
);

/** عند true: فشل orange_schema_check_and_bootstrap يُسجَّل ويُعرّف ORANGE_SCHEMA_DEGRADED بدلاً من إيقاف الطلب (راجع ROLLOUT). */
$__catchBoot = $env['ORANGE_SCHEMA_CATCH_BOOTSTRAP_FAILURE'] ?? false;
define(
    'ORANGE_SCHEMA_CATCH_BOOTSTRAP_FAILURE',
    $__catchBoot === true
    || $__catchBoot === 1
    || $__catchBoot === '1'
    || strtolower((string) $__catchBoot) === 'true'
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
    /** @var ?string يميّز الطلب الحالي داخل worker الـ FPM — static يبقى بين الطلبات لذا نعيد فحص الاتصال مرة لكل HTTP request */
    static $requestDbChecked = null;

    $requestId = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (string) $_SERVER['REQUEST_TIME_FLOAT']
        : (string) microtime(true);

    if ($pdo instanceof PDO && $requestDbChecked !== $requestId) {
        try {
            $pdo->query('SELECT 1');
            $requestDbChecked = $requestId;
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            $msg = $e->getMessage();
            $stale = $driverCode === 2006
                || $driverCode === 2013
                || stripos($msg, 'gone away') !== false
                || stripos($msg, 'Lost connection') !== false;
            if ($stale) {
                $pdo = null;
            } else {
                throw $e;
            }
        }
    }

    if ($pdo === null) {
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
        $requestDbChecked = $requestId;
    }

    return $pdo;
}

/**
 * Preferred storefront language from Accept-Language (first supported tag by q-value).
 * Unsupported tags fall through to en.
 */
function orange_lang_from_accept_language_header(?string $header): string
{
    if ($header === null || trim($header) === '') {
        return 'en';
    }
    $parsed = [];
    foreach (preg_split('/\s*,\s*/', $header) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $q = 1.0;
        if (preg_match('/;\s*q\s*=\s*([0-9.]+)/i', $part, $qm)) {
            $q = (float) $qm[1];
        }
        $tag = strtolower(trim(preg_replace('/;\s*q\s*=\s*[0-9.]+/i', '', $part)));
        if ($tag === '' || $tag === '*') {
            continue;
        }
        $parsed[] = ['tag' => str_replace('_', '-', $tag), 'q' => $q];
    }
    usort($parsed, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
    foreach ($parsed as $row) {
        $primary = explode('-', $row['tag'])[0];
        if ($primary === 'ar') {
            return 'ar';
        }
        if ($primary === 'hi') {
            return 'hi';
        }
        if (in_array($primary, ['fil', 'tl'], true)) {
            return 'fil';
        }
        if ($primary === 'en') {
            return 'en';
        }
    }

    return 'en';
}

/** True when request should not use browser language (admin UI, CLI). */
function orange_is_admin_request_context(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $script = strtolower((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, '/admin/') || str_contains($uri, '/admin/')) {
        return true;
    }

    return false;
}

function current_lang(): string
{
    $allowed = ['en', 'ar', 'fil', 'hi'];
    if (isset($_GET['lang'])) {
        $raw = strtolower(trim((string) $_GET['lang']));
        if ($raw !== '' && in_array($raw, $allowed, true)) {
            return $raw;
        }
        if ($raw !== '') {
            return 'en';
        }
    }
    if (orange_is_admin_request_context()) {
        return 'en';
    }
    $fromShort = orange_storefront_lang_from_short_url_path();
    if ($fromShort !== null && in_array($fromShort, $allowed, true)) {
        return $fromShort;
    }

    $fromCookie = orange_storefront_read_saved_lang();
    if ($fromCookie !== null) {
        return $fromCookie;
    }

    return orange_lang_from_accept_language_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
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
        'signup', 'signin', 'verify-email',
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

/** أول قناة نشطة حسب id — افتراضي للمتجر عند غياب كوكي/قناة (ضمن الدولة الحالية إن وُجد country_id). */
function orange_storefront_default_channel_slug(PDO $pdo, ?int $countryId = null): string
{
    require_once __DIR__ . '/includes/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $cacheKey = 'c' . (int) $countryId;
    static $memoByCountry = [];
    if (isset($memoByCountry[$cacheKey])) {
        return $memoByCountry[$cacheKey];
    }
    try {
        if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
            $st = $pdo->prepare('SELECT slug FROM channels WHERE is_active = 1 AND country_id = ? ORDER BY id ASC LIMIT 1');
            $st->execute([$countryId]);
            $v = $st->fetchColumn();
        } else {
            $v = $pdo->query('SELECT slug FROM channels WHERE is_active = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        if ($v !== false && $v !== null && (string) $v !== '') {
            $memoByCountry[$cacheKey] = (string) $v;

            return $memoByCountry[$cacheKey];
        }
    } catch (Throwable $e) {
    }
    $memoByCountry[$cacheKey] = 'tiktok';

    return $memoByCountry[$cacheKey];
}

/**
 * يضمن slug نشطاً في الجدول؛ يطبّق aliases القديمة ثم الافتراضي.
 */
function orange_storefront_normalize_channel_slug(PDO $pdo, string $raw, ?int $countryId = null): string
{
    require_once __DIR__ . '/includes/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', trim($raw)) ?? ''));
    if ($slug === '') {
        return orange_storefront_default_channel_slug($pdo, $countryId);
    }
    try {
        if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
            $st = $pdo->prepare('SELECT slug FROM channels WHERE slug = ? AND is_active = 1 AND country_id = ? LIMIT 1');
            $st->execute([$slug, $countryId]);
        } else {
            $st = $pdo->prepare('SELECT slug FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
            $st->execute([$slug]);
        }
        $found = $st->fetchColumn();
        if ($found !== false && $found !== null && (string) $found !== '') {
            return (string) $found;
        }
        $aliases = orange_storefront_legacy_slug_aliases();
        if (isset($aliases[$slug])) {
            $cand = $aliases[$slug];
            if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
                $st->execute([$cand, $countryId]);
            } else {
                $st->execute([$cand]);
            }
            $found2 = $st->fetchColumn();
            if ($found2 !== false && $found2 !== null && (string) $found2 !== '') {
                return (string) $found2;
            }
        }
    } catch (Throwable $e) {
    }

    return orange_storefront_default_channel_slug($pdo, $countryId);
}

function orange_storefront_channel_slug_is_active(PDO $pdo, string $slug, ?int $countryId = null): bool
{
    if ($slug === '') {
        return false;
    }
    require_once __DIR__ . '/includes/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    try {
        if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
            $st = $pdo->prepare('SELECT 1 FROM channels WHERE slug = ? AND is_active = 1 AND country_id = ? LIMIT 1');
            $st->execute([$slug, $countryId]);
        } else {
            $st = $pdo->prepare('SELECT 1 FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
            $st->execute([$slug]);
        }

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * قناة من أول مقطع مسار قصير (مثل web من ‎/web-ar/cart‎) — بلا كوكي؛ يُستثنى المسار المحجوز (pages، api، …).
 */
function orange_storefront_channel_slug_from_request_path(PDO $pdo): ?string
{
    $primary = orange_storefront_request_primary_path_segment();
    if ($primary === null || $primary === '') {
        return null;
    }
    $seg = strtolower($primary);
    foreach (orange_storefront_reserved_path_segments() as $rs) {
        if ($seg === strtolower((string) $rs)) {
            return null;
        }
    }

    return orange_channel_slug_for_path_segment($pdo, $seg, true);
}

/**
 * عند تعدد قنوات بنفس path_segment/slug في دول مختلفة: يفضّل كوكي الدولة ثم الافتراضي.
 *
 * @param list<int> $countryIds
 */
function orange_storefront_disambiguate_channel_country_ids(PDO $pdo, array $countryIds): int
{
    $countryIds = array_values(array_unique(array_filter(
        array_map(static fn ($id): int => (int) $id, $countryIds),
        static fn (int $id): bool => $id > 0
    )));
    if ($countryIds === []) {
        return 0;
    }
    if (count($countryIds) === 1) {
        return $countryIds[0];
    }
    require_once __DIR__ . '/includes/countries.php';
    $fromCookie = orange_storefront_read_saved_country_code();
    if ($fromCookie !== null) {
        $row = orange_country_row_by_code($pdo, $fromCookie, true);
        if ($row !== null && in_array((int) $row['id'], $countryIds, true)) {
            return (int) $row['id'];
        }
    }

    return orange_countries_default_id($pdo);
}

/**
 * §13.7 — استنتاج دولة الواجهة من رابط القناة (مسار قصير أو ?channel=) بلا فلترة country_id مسبقة.
 */
function orange_storefront_country_code_from_request_channel(PDO $pdo): ?string
{
    require_once __DIR__ . '/includes/countries.php';
    if (!orange_channels_has_country_column($pdo)) {
        return null;
    }
    $countryId = 0;

    if (isset($_GET['channel']) && (string) $_GET['channel'] !== '') {
        $slug = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['channel']) ?? ''));
        if ($slug !== '') {
            $st = $pdo->prepare('SELECT country_id FROM channels WHERE slug = ? AND is_active = 1');
            $st->execute([$slug]);
            $ids = [];
            while ($cid = $st->fetchColumn()) {
                $ids[] = (int) $cid;
            }
            $countryId = orange_storefront_disambiguate_channel_country_ids($pdo, $ids);
        }
    }

    if ($countryId <= 0) {
        $primary = orange_storefront_request_primary_path_segment();
        if ($primary !== null && $primary !== '') {
            $seg = strtolower($primary);
            foreach (orange_storefront_reserved_path_segments() as $rs) {
                if ($seg === strtolower((string) $rs)) {
                    return null;
                }
            }
            $st = $pdo->prepare('SELECT country_id FROM channels WHERE path_segment = ? AND is_active = 1');
            $st->execute([$seg]);
            $ids = [];
            while ($cid = $st->fetchColumn()) {
                $ids[] = (int) $cid;
            }
            if ($ids !== []) {
                $countryId = orange_storefront_disambiguate_channel_country_ids($pdo, $ids);
            }
        }
    }

    if ($countryId <= 0) {
        return null;
    }
    $row = orange_country_row_by_id($pdo, $countryId, true);
    if ($row === null) {
        return null;
    }

    return orange_countries_normalize_code((string) ($row['code'] ?? ''));
}

function orange_channel_slug_for_path_segment(PDO $pdo, string $pathSegment, bool $requireActive = true, ?int $countryId = null): ?string
{
    require_once __DIR__ . '/includes/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $s = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $pathSegment) ?? ''));
    if ($s === '') {
        return null;
    }
    $sql = 'SELECT slug FROM channels WHERE path_segment = ?';
    $params = [$s];
    if ($requireActive) {
        $sql .= ' AND is_active = 1';
    }
    if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
        $sql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $sql .= ' LIMIT 1';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetchColumn();
            if ($row !== false && $row !== null && (string) $row !== '') {
                return (string) $row;
            }

            return null;
        } catch (Throwable $e) {
            if ($attempt === 0) {
                if (function_exists('error_log')) {
                    error_log('[orange] channel_slug_for_path_segment transient (will retry): ' . $e->getMessage());
                }
                usleep(50000);

                continue;
            }
            if (function_exists('error_log')) {
                error_log('[orange] channel_slug_for_path_segment: ' . $e->getMessage());
            }

            return null;
        }
    }

    return null;
}

function orange_channel_path_segment_for_slug(PDO $pdo, string $slug, ?int $countryId = null): ?string
{
    require_once __DIR__ . '/includes/countries.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_storefront_current_country_id($pdo);
    }
    $s = strtolower((string) (preg_replace('/[^a-z0-9\-]/i', '', $slug) ?? ''));
    if ($s === '') {
        return null;
    }
    $slugSql =
        'SELECT path_segment FROM channels WHERE slug = ? AND is_active = 1 AND path_segment IS NOT NULL AND path_segment <> \'\'';
    $params = [$s];
    if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
        $slugSql .= ' AND country_id = ?';
        $params[] = $countryId;
    }
    $slugSql .= ' LIMIT 1';
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try {
            $st = $pdo->prepare($slugSql);
            $st->execute($params);
            $row = $st->fetchColumn();
            if ($row !== false && $row !== null && (string) $row !== '') {
                return (string) $row;
            }

            return null;
        } catch (Throwable $e) {
            if ($attempt === 0) {
                if (function_exists('error_log')) {
                    error_log('[orange] channel_path_segment_for_slug transient (will retry): ' . $e->getMessage());
                }
                usleep(50000);

                continue;
            }
            if (function_exists('error_log')) {
                error_log('[orange] channel_path_segment_for_slug: ' . $e->getMessage());
            }

            return null;
        }
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
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $pathToSlug = [];
    $slugToPath = [];
    $validSlugs = [];
    require_once __DIR__ . '/includes/countries.php';
    $countryId = orange_storefront_current_country_id($pdo);
    try {
        if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
            $q = $pdo->prepare('SELECT slug, path_segment FROM channels WHERE is_active = 1 AND country_id = ?');
            $q->execute([$countryId]);
        } else {
            $q = $pdo->query('SELECT slug, path_segment FROM channels WHERE is_active = 1');
        }
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

    $memo = [$pathToSlug, $slugToPath, $validSlugs, $alt];

    return $memo;
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

/**
 * آخر لغة واجهة للمتجر — كوكي يُكمّل ‎?lang=‎ والمسار القصير (قبل ‎Accept-Language‎ فقط).
 */
function orange_storefront_lang_cookie_name(): string
{
    return 'orange_sf_lang';
}

/** @return 'en'|'ar'|'fil'|'hi'|null */
function orange_storefront_read_saved_lang(): ?string
{
    $allowed = ['en', 'ar', 'fil', 'hi'];
    $raw = isset($_COOKIE[orange_storefront_lang_cookie_name()])
        ? strtolower(trim((string) $_COOKIE[orange_storefront_lang_cookie_name()]))
        : '';
    if ($raw === '' || ! in_array($raw, $allowed, true)) {
        return null;
    }

    return $raw;
}

function orange_storefront_send_lang_cookie(string $lang): void
{
    if (headers_sent()) {
        return;
    }
    $allowed = ['en', 'ar', 'fil', 'hi'];
    $lang = strtolower(trim($lang));
    if (! in_array($lang, $allowed, true)) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    setcookie(orange_storefront_lang_cookie_name(), $lang, [
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
    require_once __DIR__ . '/includes/countries.php';
    $countryId = orange_storefront_current_country_id($pdo);
    $def = orange_storefront_default_channel_slug($pdo, $countryId);
    if (isset($_GET['channel']) && (string) $_GET['channel'] !== '') {
        $s = preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['channel']);

        return $s !== '' ? orange_storefront_normalize_channel_slug($pdo, $s, $countryId) : $def;
    }
    $fromPath = orange_storefront_channel_slug_from_request_path($pdo);
    if ($fromPath !== null && $fromPath !== '') {
        return orange_storefront_normalize_channel_slug($pdo, $fromPath, $countryId);
    }
    $fromCookie = orange_storefront_read_saved_channel_slug();
    if ($fromCookie !== null) {
        return orange_storefront_normalize_channel_slug($pdo, $fromCookie, $countryId);
    }

    return $def;
}

function get_channel_by_slug(string $slug): ?array {
    $pdo = db();
    require_once __DIR__ . '/includes/countries.php';
    $countryId = orange_storefront_current_country_id($pdo);
    if ($countryId > 0 && orange_channels_has_country_column($pdo)) {
        $stmt = $pdo->prepare('SELECT * FROM channels WHERE slug = ? AND is_active = 1 AND country_id = ? LIMIT 1');
        $stmt->execute([$slug, $countryId]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$slug]);
    }
    $row = $stmt->fetch();

    return $row ?: null;
}

/** مسار الطلب العام بعد ‎PUBLIC_BASE_PATH‎ وبادئة ‎/‎. */
function orange_storefront_normalized_public_path(): string
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

    return $path;
}

/**
 * لغة الواجهة من المسار القصير: ‎-ar / -hi / -ph‎ أو غياب اللاحقة مع مقطع قناة صالح (= ‎en‎، بدون ‎?lang=‎).
 *
 * @return 'ar'|'en'|'fil'|'hi'|null
 */
function orange_storefront_lang_from_short_url_path(): ?string
{
    if (orange_is_admin_request_context()) {
        return null;
    }
    $path = orange_storefront_normalized_public_path();
    if (!preg_match('#^/([a-z0-9\-]+)(?:-(ar|hi|ph))?(?:/|$)#i', $path, $m)) {
        return null;
    }
    $first = strtolower($m[1]);
    foreach (orange_storefront_reserved_path_segments() as $rs) {
        if ($first === strtolower((string) $rs)) {
            return null;
        }
    }
    if (empty($m[2])) {
        try {
            $pdo = db();
            $slug = orange_channel_slug_for_path_segment($pdo, $first, true);
            if ($slug !== null && $slug !== '') {
                return 'en';
            }
        } catch (Throwable $e) {
        }

        return null;
    }
    $suf = strtolower((string) $m[2]);

    return match ($suf) {
        'ar' => 'ar',
        'hi' => 'hi',
        'ph' => 'fil',
        default => null,
    };
}

/**
 * أول مقطع مسار قصير للواجهة من الطلب الحالي (مثل tiktok من ‎/tiktok-ar/cart‎) أو null.
 */
function orange_storefront_request_primary_path_segment(): ?string
{
    $path = orange_storefront_normalized_public_path();
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
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    require_once __DIR__ . '/includes/countries.php';
    $pdoTb = db();
    $lang = current_lang();
    $countryId = orange_storefront_current_country_id($pdoTb);
    $countryCode = orange_storefront_current_country_code($pdoTb);
    $countryRow = orange_country_row_by_id($pdoTb, $countryId, false)
        ?? orange_country_row_by_code($pdoTb, $countryCode, false);
    $slug = current_channel_slug();
    $channel = get_channel_by_slug($slug);
    $defSlug = orange_storefront_default_channel_slug($pdoTb, $countryId);
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
    $countryOptions = orange_countries_storefront_active($pdoTb, $lang);
    $countryLabel = $countryRow !== null ? orange_country_label_from_row($countryRow, $lang) : $countryCode;
    $countryCurrency = $countryRow !== null ? strtoupper(trim((string) ($countryRow['currency_code'] ?? ''))) : '';

    $memo = [
        'channel' => $channel,
        'lang' => $lang,
        'channelSlug' => $channelSlug,
        'pageKind' => $pageKind,
        'storefrontExtra' => $storefrontExtra,
        'langOpts' => $langOpts,
        'currentLangLabel' => $currentLangLabel,
        'countryId' => $countryId,
        'countryCode' => $countryCode,
        'countryLabel' => $countryLabel,
        'countryCurrency' => $countryCurrency,
        'countryOptions' => $countryOptions,
    ];

    return $memo;
}

/**
 * رابط تبديل الدولة مع الحفاظ على مسار الصفحة الحالي.
 */
function storefront_country_switch_href(string $countryCode, ?string $lang = null): string
{
    $code = function_exists('orange_countries_normalize_code')
        ? orange_countries_normalize_code($countryCode)
        : strtolower(trim($countryCode));
    if ($code === '') {
        return '';
    }
    $params = $_GET;
    $params['country'] = $code;
    if ($lang !== null && $lang !== '') {
        $params['lang'] = $lang;
    }
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = '/';
    }

    return $path . '?' . http_build_query($params);
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
            'register' => $seg . '/signup',
            'verify_email' => !empty($extra['token']) ? $seg . '/verify-email' : null,
            default => $seg,
        };
        if ($tail !== null) {
            $base = ($pathPrefix === '' ? '' : $pathPrefix) . '/' . $tail;
            if ($page === 'verify_email' && !empty($extra['token'])) {
                return $base . '?' . http_build_query(['token' => (string) $extra['token']]);
            }

            return $base;
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
 * تسمية صف مقاس في جدول عائلة المقاسات حسب لغة الواجهة (label_ar/en/fil/hi عند توفر الأعمدة).
 *
 * @param array<string,mixed> $row
 */
function storefront_size_chart_cell_label(array $row): string
{
    if (function_exists('orange_size_display_label')) {
        return orange_size_display_label($row, current_lang());
    }
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
            'sizing_guide' => 'Size Guide',
            'sizing_guide_section_upper' => 'Upper body',
            'sizing_guide_section_lower' => 'Lower body',
            'sizing_adv_toolbar_system' => 'Size labels',
            'sizing_adv_toolbar_unit' => 'Unit',
            'sizing_unit_cm_short' => 'cm',
            'sizing_unit_inch_short' => 'in',
            'sizing_display_system_eu' => 'EU',
            'sizing_display_system_uk' => 'UK',
            'sizing_display_system_us' => 'US',
            'sizing_guide_close' => 'Close',
            'sizing_col_size' => 'Size',
            'sizing_col_foot_cm' => 'Foot length (cm)',
            'sizing_hint_upper' => 'This product uses the upper-body size chart (indicative).',
            'sizing_hint_lower' => 'This product uses the lower-body size chart (indicative).',
            'sizing_hint_both' => 'This product uses both upper- and lower-body size charts (indicative).',
            'sizing_hint_single' => 'This product uses a single size chart (indicative).',
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
            'track_another_order' => 'Track another order',
            'track_result_region_aria' => 'Order tracking result',
            'track_line_discount_label' => 'Line discount',
            'track_email_summary_intro' => 'Email yourself a full copy of this order (same details as on screen).',
            'track_email_summary_placeholder' => 'Your email address',
            'track_email_summary_send' => 'Send copy to email',
            'track_email_summary_ok' => 'Sent. Check your inbox.',
            'track_email_summary_err' => 'Could not send. Try again later or contact us on WhatsApp.',
            'track_email_summary_rate_limit' => 'Please wait before requesting another email.',
            'track_share_reference_title' => 'Share this order reference (text only — not a link)',
            'track_share_reference_hint' =>
                'Tracking on this site still requires this order number and the phone used when ordering. Pasting only this text in the browser address bar will not open your order.',
            'track_share_reference_copy' => 'Copy reference',
            'track_share_reference_copied' => 'Copied.',
            'track_missing_fields' => 'Please enter the order number and phone.',
            'track_order_not_found' => 'No order matches these details. Check the number and phone.',
            'track_signup_placeholder_email' => 'Email address to receive the confirmation link.',
            'track_signup_placeholder_order_number' => 'As shown on your order confirmation (e.g. ORD-…)',
            'track_signup_verify_phone_label' => 'Order phone (for verification)',
            'track_signup_placeholder_verify_phone' => 'Order phone (national digits, no country code)',
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
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'Your name as you want it saved',
            'register_placeholder_phone' => 'Enter your phone number (national digits only)',
            'register_placeholder_area' => 'Area or region',
            'checkout_select_area' => 'Select area',
            'delivery_area_open_list' => 'Open delivery area list',
            'checkout_delivery_area_required' => 'Please select a delivery area.',
            'checkout_delivery_areas_unavailable' => 'Delivery areas are not active yet. Please contact the store or try again later.',
            'checkout_delivery_areas_unavailable_note' => 'Online checkout needs at least one active delivery area. An administrator can add them under Admin → Delivery areas.',
            'register_placeholder_address' => 'Full delivery address',
            'register_placeholder_notes' => 'Notes (optional)',
            'checkout_required_fields' => 'Please fill in all required fields (name, phone, email, area, address).',
            'checkout_invalid_email' => 'Please enter a valid email address.',
            'checkout_invalid_phone' => 'Enter your national phone number (digits only, no + or country code — choose the country in the field beside this one).',
            'phone_country_label' => 'Country code (phone)',
            'phone_country_select_placeholder' => 'Select country code',
            'phone_country_open_list' => 'Open country list',
            'phone_country_required' => 'Please select a country code for the phone number.',
            'validation_value_missing' => 'Please fill out this field.',
            'phone_country_full_international' => 'International — full number with + or 00',
            'phone_country_kw' => 'Kuwait +965 (8-digit mobile)',
            'phone_country_ph' => 'Philippines +63',
            'phone_country_in' => 'India +91',
            'phone_country_pk' => 'Pakistan +92',
            'phone_field_hint' => 'Enter your phone number without country code (digits only).',
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
            'storefront_country' => 'Country',
            'storefront_country_select' => 'Select country',
            'storefront_brand' => 'Orange Company',
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
            'cart_tab_delivered' => 'Delivered',
            'cart_account_orders_heading_active' => 'Your current orders',
            'cart_account_orders_heading_delivered' => 'Delivered orders',
            'cart_account_orders_empty' => 'No orders in this list yet.',
            'cart_guest_orders_heading' => 'Your orders (guest)',
            'cart_guest_orders_intro' => 'Here you can see orders that are still pending or approved. For full status details and history, use Track order.',
            'cart_guest_orders_empty' => 'No pending or approved orders to show here yet. After you place an order from this browser, it will appear in this list. Use Track order for all statuses.',
            'cart_account_auth_required' => 'Please sign in again to view your orders.',
            'cart_proceed_to_order' => 'Place order',
            'cart_checkout_title' => 'Your details',
            'cart_checkout_intro' => 'Enter your details below, then tap Send order. WhatsApp will open to confirm with the store.',
            'cart_total_label' => 'Total',
            'cart_subtotal_label' => 'Subtotal',
            'cart_promotion_discount_label' => 'Cart offer',
            'cart_combo_discount_label' => 'Combo bundle',
            'cart_gift_promo_title' => 'Free gift with this order',
            'cart_gift_pick_label' => 'Choose your free gift',
            'cart_gift_included_fixed' => 'A free gift will be added to this order automatically.',
            'checkout_gift_pick_required' => 'Please choose your free gift before sending the order.',
            'checkout_gift_variant_invalid' => 'The selected gift is not valid for this promotion.',
            'checkout_gift_out_of_stock' => 'The gift item is out of stock.',
            'cart_bogo_promo_title' => 'Buy-more gift (BOGO)',
            'cart_bogo_pick_label' => 'Choose your BOGO gift',
            'cart_bogo_included_fixed' => 'A BOGO gift will be added automatically (see cart conditions).',
            'checkout_bogo_gift_pick_required' => 'Please choose your BOGO gift before sending the order.',
            'cart_gift_register_unlock_teaser' => 'Sign in with a verified email to unlock an extra free gift on this cart (registered-only offer).',
            'cart_bogo_register_unlock_teaser' => 'Sign in with a verified email to unlock a registered-only BOGO gift on this cart.',
            'cart_combo_register_unlock_teaser' => 'Sign in with a verified email to unlock a registered-only combo bundle price on this cart.',
            'cart_register_promo_teaser' => 'You can save an extra {extra} KD on this order by registering with email.',
            'cart_register_promo_teaser_action' => 'Register',
            'cart_items_count' => '{n} items',
            'cart_unit_price' => 'Unit price',
            'cart_line_subtotal' => 'Line total',
            'cart_max_available_short' => 'Max {n} in stock',
            'cart_continue_shopping' => 'Continue shopping',
            'cart_mini_summary_title' => 'Order summary',
            'cart_mini_more' => '+{n} more',
            'cart_basket_tab_intro' => 'Use this tab to choose products and tap Place order to send them to the store. If you have more than one item, tick which lines to include in this order; anything left unticked stays in your cart.',
            'cart_orders_tab_intro' => 'My orders shows requests you already sent to the store. When you are ready to send a new order, use the Basket tab, then return here to fill in your details.',
            'cart_order_line_choice_hint' => 'Choose lines for this order:',
            'cart_include_in_this_order' => 'Include in this order',
            'cart_select_all_lines' => 'Select all',
            'cart_deselect_all_lines' => 'Clear selection',
            'cart_no_lines_selected_for_order' => 'Select at least one product to include in this order.',
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
            'customer_amend_order' => 'Edit items',
            'customer_amend_loaded' => 'Cart loaded with this order. Review and tap Send order to confirm changes.',
            'customer_amend_not_allowed' => 'You cannot change items for this order from the site at this stage. Contact us on WhatsApp.',
            'customer_amend_phone_mismatch' => 'Enter the same phone number as on the order to confirm changes.',
            'customer_amend_mode_banner' => 'Updating order {order}. Tap Send order to confirm (WhatsApp).',
            'customer_amend_send_order' => 'Confirm changes (WhatsApp)',
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
            'storefront_register_phone_merge_intro' => 'This phone is already linked to a verified account. Send the WhatsApp message below from the same number, wait for the store to confirm, then apply your profile update. Your login email stays the verified one (masked below) — only name, area, and address are updated.',
            'storefront_register_phone_merge_wa_body' => "Orange — profile update / same phone\nToken: {token}\nEmail I am trying to add: {email}\n{site}",
            'storefront_register_phone_merge_steps' => '1) Open WhatsApp and send the prefilled message. 2) After the store confirms, tap «Apply profile update».',
            'storefront_register_phone_merge_apply_btn' => 'Apply profile update',
            'storefront_register_phone_merge_wa_cta' => 'Open WhatsApp',
            'storefront_register_phone_merge_token_label' => 'Merge token (copy if needed)',
            'storefront_register_phone_merge_masked_label' => 'Verified login email (unchanged):',
            'storefront_merge_wa_not_confirmed' => 'The store has not confirmed your WhatsApp yet. Send the message first, then try again after they confirm.',
            'storefront_merge_email_mismatch' => 'This email does not match the merge request.',
            'storefront_merge_invalid_token' => 'Invalid or expired token. Submit the sign-up form again.',
            'storefront_merge_apply_ok' => 'Your profile was updated on your existing account. Sign in with your original verified email.',
            'storefront_merge_apply_err' => 'Could not apply the update. Try again or contact support.',
            'api_request_failed' => 'We could not complete this request. Please try again later.',
            'api_post_only' => 'Order lookup must be submitted via the tracking form (POST only).',
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
            'sizing_guide' => 'دليل المقاسات',
            'sizing_guide_section_upper' => 'مقاسات علوية',
            'sizing_guide_section_lower' => 'مقاسات سفلية',
            'sizing_adv_toolbar_system' => 'نظام المقاس (عرض)',
            'sizing_adv_toolbar_unit' => 'وحدة الطول (عرض)',
            'sizing_unit_cm_short' => 'سم',
            'sizing_unit_inch_short' => 'إنش',
            'sizing_display_system_eu' => 'EU',
            'sizing_display_system_uk' => 'UK',
            'sizing_display_system_us' => 'US',
            'sizing_guide_close' => 'إغلاق',
            'sizing_col_size' => 'المقاس',
            'sizing_col_foot_cm' => 'طول القدم (سم)',
            'sizing_hint_upper' => 'هذا المنتج: جدول مقاسات علوية (إرشادي).',
            'sizing_hint_lower' => 'هذا المنتج: جدول مقاسات سفلية (إرشادي).',
            'sizing_hint_both' => 'هذا المنتج: جداول علوية وسفلية (إرشادي).',
            'sizing_hint_single' => 'هذا المنتج: جدول مقاسات واحد (إرشادي).',
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
            'track_another_order' => 'تتبع طلب آخر',
            'track_result_region_aria' => 'نتيجة تتبع الطلب',
            'track_line_discount_label' => 'خصم على البند',
            'track_email_summary_intro' => 'أرسل إلى بريدي نسخة كاملة من تفاصيل هذا الطلب (نفس ما يظهر على الشاشة).',
            'track_email_summary_placeholder' => 'عنوان بريدك',
            'track_email_summary_send' => 'إرسال النسخة إلى البريد',
            'track_email_summary_ok' => 'تم الإرسال. راجع صندوق الوارد.',
            'track_email_summary_err' => 'تعذّر الإرسال. حاول لاحقاً أو تواصل عبر واتساب.',
            'track_email_summary_rate_limit' => 'يرجى الانتظار قبل طلب بريد آخر.',
            'track_share_reference_title' => 'شارك مرجع الطلب (نص فقط — ليس رابطاً)',
            'track_share_reference_hint' =>
                'التتبع من الموقع ما زال يتطلّب رقم الطلب هذا ورقم الهاتف المستخدم عند الطلب. لصق هذا النص وحده في شريط العناوين لا يكشف حالة الطلب.',
            'track_share_reference_copy' => 'نسخ المرجع',
            'track_share_reference_copied' => 'تم النسخ.',
            'track_missing_fields' => 'يرجى إدخال رقم الطلب ورقم الهاتف.',
            'track_order_not_found' => 'لا يوجد طلب بهذه البيانات. تحقق من الرقم والهاتف.',
            'track_signup_placeholder_email' => 'البريد الذي تريد استلام رابط التأكيد عليه.',
            'track_signup_placeholder_order_number' => 'كما في رسالة أو تأكيد الطلب (مثال ORD-…)',
            'track_signup_verify_phone_label' => 'هاتف الطلب (للتحقق)',
            'track_signup_placeholder_verify_phone' => 'هاتف الطلب (أرقام وطنية دون كود الدولة)',
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
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'اسمك كما تريد حفظه',
            'register_placeholder_phone' => 'سجّل رقم الهاتف (الأرقام الوطنية فقط)',
            'register_placeholder_area' => 'المنطقة',
            'checkout_select_area' => 'اختر المنطقة',
            'delivery_area_open_list' => 'فتح قائمة المناطق',
            'checkout_delivery_area_required' => 'يرجى اختيار منطقة التوصيل.',
            'checkout_delivery_areas_unavailable' => 'لا توجد مناطق توصيل مفعّلة بعد. يُرجى التواصل مع المتجر أو المحاولة لاحقاً.',
            'checkout_delivery_areas_unavailable_note' => 'إتمام الطلب عبر الموقع يتطلّب منطقة توصيل واحدة على الأقل مفعّلة في الأدمن (مناطق التوصيل).',
            'register_placeholder_address' => 'عنوان التوصيل كاملاً',
            'register_placeholder_notes' => 'ملاحظات (اختياري)',
            'checkout_required_fields' => 'يرجى تعبئة جميع الحقول المطلوبة (الاسم، الهاتف، البريد، المنطقة، العنوان).',
            'checkout_invalid_email' => 'يرجى إدخال بريد إلكتروني صالح.',
            'checkout_invalid_phone' => 'أدخل الرقم الوطني فقط (أرقام، دون + أو كود الدولة — اختر الدولة في الحقل المجاور).',
            'phone_country_label' => 'كود الدولة للهاتف',
            'phone_country_select_placeholder' => 'اختر كود الدولة',
            'phone_country_open_list' => 'فتح قائمة الدول',
            'phone_country_required' => 'يرجى اختيار كود الدولة لرقم الهاتف.',
            'validation_value_missing' => 'يرجى تعبئة هذا الحقل.',
            'phone_country_full_international' => 'دولي — الرقم كاملاً بـ + أو 00',
            'phone_country_kw' => 'الكويت +965 (جوال 8 أرقام)',
            'phone_country_ph' => 'الفلبين +63',
            'phone_country_in' => 'الهند +91',
            'phone_country_pk' => 'باكستان +92',
            'phone_field_hint' => 'سجّل رقم الهاتف دون كود الدولة (أرقام فقط).',
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
            'storefront_country' => 'الدولة',
            'storefront_country_select' => 'اختر الدولة',
            'storefront_brand' => 'Orange Company',
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
            'cart_tab_delivered' => 'تم التسليم',
            'cart_account_orders_heading_active' => 'طلباتك الحالية',
            'cart_account_orders_heading_delivered' => 'طلبات تم تسليمها',
            'cart_account_orders_empty' => 'لا توجد طلبات في هذه القائمة بعد.',
            'cart_guest_orders_heading' => 'طلباتك (بدون تسجيل)',
            'cart_guest_orders_intro' => 'تظهر هنا الطلبات بحالتي «قيد المراجعة» أو «موافق» فقط. لبقية الحالات والتفاصيل الكاملة استخدم صفحة تتبع الطلب.',
            'cart_guest_orders_empty' => 'لا توجد طلبات هنا بعد. بعد إتمام طلب من هذا المتصفح يظهر هنا ما هو قيد المراجعة أو الموافقة. لجميع الحالات استخدم تتبع الطلب.',
            'cart_account_auth_required' => 'يرجى تسجيل الدخول مجدداً لعرض طلباتك.',
            'cart_proceed_to_order' => 'تنفيذ الطلب',
            'cart_checkout_title' => 'بيانات الطلب',
            'cart_checkout_intro' => 'عند تنفيذ الطلب تظهر هذه الخطوة: املأ بياناتك ثم اضغط «إرسال الطلب»، وسيُفتح واتساب لتأكيد الطلب مع المتجر.',
            'cart_total_label' => 'الإجمالي',
            'cart_subtotal_label' => 'المجموع الفرعي',
            'cart_promotion_discount_label' => 'عرض السلة',
            'cart_combo_discount_label' => 'عرض كومبو',
            'cart_gift_promo_title' => 'هدية مع هذا الطلب',
            'cart_gift_pick_label' => 'اختر هديتك المجانية',
            'cart_gift_included_fixed' => 'ستُضاف هدية مجانية لهذا الطلب تلقائياً.',
            'checkout_gift_pick_required' => 'يرجى اختيار الهدية المجانية قبل إرسال الطلب.',
            'checkout_gift_variant_invalid' => 'الهدية المختارة غير صالحة لهذا العرض.',
            'checkout_gift_out_of_stock' => 'الهدية غير متوفرة بالمخزون.',
            'cart_bogo_promo_title' => 'هدية شراء (عرض BOGO)',
            'cart_bogo_pick_label' => 'اختر هدية العرض',
            'cart_bogo_included_fixed' => 'ستُضاف هدية العرض تلقائياً عند استيفاء شروط السلة.',
            'checkout_bogo_gift_pick_required' => 'يرجى اختيار هدية عرض الشراء (BOGO) قبل إرسال الطلب.',
            'cart_gift_register_unlock_teaser' => 'سجّل دخولك بحساب مفعّل (بريد مؤكد) للاستفادة من هدية مجانية إضافية على هذه العربة — العرض للمسجّلين فقط.',
            'cart_bogo_register_unlock_teaser' => 'سجّل دخولك بحساب مفعّل للاستفادة من هدية BOGO للمسجّلين فقط على هذه العربة.',
            'cart_combo_register_unlock_teaser' => 'سجّل دخولك بحساب مفعّل (بريد مؤكد) للاستفادة من سعر حزمة الكومبو للمسجّلين فقط على هذه العربة.',
            'cart_register_promo_teaser' => 'يمكنك توفير {extra} د.ك إضافية على هذا الطلب بالتسجيل وتأكيد البريد.',
            'cart_register_promo_teaser_action' => 'التسجيل',
            'cart_items_count' => '{n} منتج',
            'cart_unit_price' => 'سعر الوحدة',
            'cart_line_subtotal' => 'المجموع',
            'cart_max_available_short' => 'حتى {n} متوفر',
            'cart_continue_shopping' => 'متابعة التسوق',
            'cart_mini_summary_title' => 'ملخص الطلب',
            'cart_mini_more' => '+{n} منتجات أخرى',
            'cart_basket_tab_intro' => 'من هذا التاب تختار المنتجات ثم «تنفيذ الطلب» لإرسالها للمتجر. إن كان لديك أكثر من منتج، حدّد البنود المضمّنة في هذا الطلب؛ ما لم يُحدَّد يبقى في العربة لطلب لاحق.',
            'cart_orders_tab_intro' => '«طلباتي» تعرض ما سبق إرساله للشركة. لإرسال طلب جديد استخدم تاب العربة ثم عد هنا لإكمال بياناتك.',
            'cart_order_line_choice_hint' => 'اختر البنود لهذا الطلب:',
            'cart_include_in_this_order' => 'تضمين في هذا الطلب',
            'cart_select_all_lines' => 'تحديد الكل',
            'cart_deselect_all_lines' => 'إلغاء التحديد',
            'cart_no_lines_selected_for_order' => 'اختر منتجاً واحداً على الأقل لتضمينه في هذا الطلب.',
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
            'customer_amend_order' => 'تعديل المنتجات',
            'customer_amend_loaded' => 'تم تحميل السلة من هذا الطلب. راجع ثم اضغط إرسال الطلب لتأكيد التعديل.',
            'customer_amend_not_allowed' => 'لا يمكن تعديل بنود هذا الطلب من الموقع في هذه المرحلة. تواصل معنا على واتساب.',
            'customer_amend_phone_mismatch' => 'أدخل نفس رقم الهاتف المسجّل على الطلب لتأكيد التعديل.',
            'customer_amend_mode_banner' => 'تعديل الطلب {order}. اضغط «إرسال الطلب» للتأكيد (واتساب).',
            'customer_amend_send_order' => 'تأكيد التعديل (واتساب)',
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
            'storefront_register_phone_merge_intro' => 'هذا الهاتف مرتبط بحساب مفعّل مسبقاً. أرسل رسالة واتساب من نفس الرقم كما بالزر أدناه، وبعد أن يؤكّد المتجر استلامها اضغط «تطبيق تحديث بيانات الملف». بريد تسجيل الدخول المؤكَّد <strong>لا يتغيّر</strong> — يُحدَّث الاسم والمنطقة والعنوان فقط.',
            'storefront_register_phone_merge_wa_body' => "Orange — تحديث بيانات / نفس الهاتف\nالرمز: {token}\nالبريد الذي أحاول التسجيل به: {email}\n{site}",
            'storefront_register_phone_merge_steps' => '١) افتح واتساب وأرسل الرسالة الجاهزة. ٢) بعد تأكيد المتجر من لوحة الإدارة، اضغط «تطبيق تحديث بيانات الملف».',
            'storefront_register_phone_merge_apply_btn' => 'تطبيق تحديث بيانات الملف',
            'storefront_register_phone_merge_wa_cta' => 'فتح واتساب',
            'storefront_register_phone_merge_token_label' => 'رمز الدمج (للنسخ اليدوي)',
            'storefront_register_phone_merge_masked_label' => 'البريد المؤكَّد للدخول (دون تغيير):',
            'storefront_merge_wa_not_confirmed' => 'لم يُؤكَّد واتسابك من المتجر بعد. أرسل الرسالة أولاً ثم أعد المحاولة بعد التأكيد.',
            'storefront_merge_email_mismatch' => 'البريد لا يطابق طلب الدمج.',
            'storefront_merge_invalid_token' => 'رمز غير صالح أو منتهٍ. أعد إرسال نموذج التسجيل.',
            'storefront_merge_apply_ok' => 'تم تحديث بيانات ملفك على حسابك الحالي. سجّل الدخول بالبريد المؤكَّد الأصلي.',
            'storefront_merge_apply_err' => 'تعذّر تطبيق التحديث. حاول لاحقاً أو تواصل مع الدعم.',
            'api_request_failed' => 'تعذّر إكمال الطلب. حاول لاحقاً.',
            'api_post_only' => 'يجب الاستعلام عن الطلب عبر نموذج التتبع في الموقع (إرسال آمن فقط).',
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
            'sizing_guide' => 'Size Guide',
            'sizing_guide_section_upper' => 'Sukat (itaas ng katawan)',
            'sizing_guide_section_lower' => 'Sukat (ibaba ng katawan)',
            'sizing_adv_toolbar_system' => 'Size labels',
            'sizing_adv_toolbar_unit' => 'Unit',
            'sizing_unit_cm_short' => 'cm',
            'sizing_unit_inch_short' => 'in',
            'sizing_display_system_eu' => 'EU',
            'sizing_display_system_uk' => 'UK',
            'sizing_display_system_us' => 'US',
            'sizing_guide_close' => 'Isara',
            'sizing_col_size' => 'Sukat',
            'sizing_col_foot_cm' => 'Haba ng paa (cm)',
            'sizing_hint_upper' => 'Ang produktong ito ay gumagamit ng tsart ng sukat sa itaas ng katawan (pang-reference).',
            'sizing_hint_lower' => 'Ang produktong ito ay gumagamit ng tsart ng sukat sa ibaba ng katawan (pang-reference).',
            'sizing_hint_both' => 'Ang produktong ito ay gumagamit ng tsart sa itaas at ibaba ng katawan (pang-reference).',
            'sizing_hint_single' => 'Ang produktong ito ay gumagamit ng isang tsart ng sukat (pang-reference).',
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
            'track_another_order' => 'Mag-track ng ibang order',
            'track_result_region_aria' => 'Resulta ng pag-track ng order',
            'track_line_discount_label' => 'Discount sa item',
            'track_email_summary_intro' => 'I-email sa sarili mo ang buong kopya ng order na ito (pareho ng nasa screen).',
            'track_email_summary_placeholder' => 'Email address mo',
            'track_email_summary_send' => 'Ipadala ang kopya sa email',
            'track_email_summary_ok' => 'Naipadala na. Tingnan ang inbox.',
            'track_email_summary_err' => 'Hindi naipadala. Subukan mamaya o mag-WhatsApp.',
            'track_email_summary_rate_limit' => 'Maghintay muna bago humingi ng panibagong email.',
            'track_share_reference_title' => 'I-share ang reference ng order (text lang — hindi link)',
            'track_share_reference_hint' =>
                'Kailangan pa rin ang order number na ito at ang teleponong ginamit nang mag-order para mag-track. Ang pag-paste lang sa address bar ay hindi magbubukas ng order.',
            'track_share_reference_copy' => 'Kopyahin',
            'track_share_reference_copied' => 'Nakopya.',
            'track_missing_fields' => 'Ilagay ang order number at telepono.',
            'track_order_not_found' => 'Walang tumutugmang order. Suriin ang numero at telepono.',
            'track_signup_placeholder_email' => 'Email para sa confirmation link.',
            'track_signup_placeholder_order_number' => 'Gaya sa order confirmation (hal. ORD-…)',
            'track_signup_verify_phone_label' => 'Telepono sa order (beripikasyon)',
            'track_signup_placeholder_verify_phone' => 'Telepono sa order (pambansang digit, walang country code)',
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
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'Pangalan mo',
            'register_placeholder_phone' => 'Ilagay ang numero (pambansang digit lang)',
            'register_placeholder_area' => 'Lugar',
            'checkout_select_area' => 'Pumili ng lugar',
            'delivery_area_open_list' => 'Buksan ang listahan ng lugar ng delivery',
            'checkout_delivery_area_required' => 'Mangyaring pumili ng lugar ng delivery.',
            'checkout_delivery_areas_unavailable' => 'Wala pang aktibong delivery area. Makipag-ugnayan sa tindahan o subukan muli mamaya.',
            'checkout_delivery_areas_unavailable_note' => 'Kailangan ng online checkout ng kahit isang aktibong delivery area. Maidaragdag ito ng admin sa Admin → Delivery areas.',
            'register_placeholder_address' => 'Buong address',
            'register_placeholder_notes' => 'Mga tala (opsyonal)',
            'checkout_required_fields' => 'Punan ang lahat ng kinakailangang field (pangalan, telepono, email, lugar, address).',
            'checkout_invalid_email' => 'Maglagay ng wastong email.',
            'checkout_invalid_phone' => 'Ilagay ang pambansang numero lamang (mga digit, walang + o country code — pumili ng bansa sa katabing field).',
            'phone_country_label' => 'Country code ng telepono',
            'phone_country_select_placeholder' => 'Pumili ng country code',
            'phone_country_open_list' => 'Buksan ang listahan ng bansa',
            'phone_country_required' => 'Mangyaring pumili ng country code para sa numero.',
            'validation_value_missing' => 'Pakipunan ang field na ito.',
            'phone_country_full_international' => 'International — buong numero na may + o 00',
            'phone_country_kw' => 'Kuwait +965 (8 digit mobile)',
            'phone_country_ph' => 'Pilipinas +63',
            'phone_country_in' => 'India +91',
            'phone_country_pk' => 'Pakistan +92',
            'phone_field_hint' => 'Ilagay ang numero nang walang country code (mga digit lamang).',
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
            'storefront_country' => 'Bansa',
            'storefront_country_select' => 'Pumili ng bansa',
            'storefront_brand' => 'Orange Company',
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
            'cart_tab_delivered' => 'Naihatid na',
            'cart_account_orders_heading_active' => 'Kasalukuyang order mo',
            'cart_account_orders_heading_delivered' => 'Mga order na naihatid',
            'cart_account_orders_empty' => 'Wala pang order sa listahang ito.',
            'cart_guest_orders_heading' => 'Mga order mo (bisita)',
            'cart_guest_orders_intro' => 'Makikita dito ang pending o approved. Para sa buong status, gamitin ang Track order.',
            'cart_guest_orders_empty' => 'Walang pending o approved na ipapakita. Pagkatapos mag-order mula sa browser na ito, lalabas dito. Gamitin ang Track order para sa lahat ng status.',
            'cart_account_auth_required' => 'Mag-sign in ulit para makita ang mga order mo.',
            'cart_proceed_to_order' => 'Mag-order na',
            'cart_checkout_title' => 'Iyong detalye',
            'cart_checkout_intro' => 'Ilagay ang iyong impormasyon, tapos Ipadala ang order. Bubukas ang WhatsApp para kumpirmahin sa tindahan.',
            'cart_total_label' => 'Kabuuan',
            'cart_subtotal_label' => 'Subtotal',
            'cart_promotion_discount_label' => 'Promo sa cart',
            'cart_combo_discount_label' => 'Combo bundle',
            'cart_gift_promo_title' => 'Libreng regalo sa order na ito',
            'cart_gift_pick_label' => 'Piliin ang libreng regalo',
            'cart_gift_included_fixed' => 'May libreng regalong idaragdag sa order na ito.',
            'checkout_gift_pick_required' => 'Pumili muna ng libreng regalo bago ipadala ang order.',
            'checkout_gift_variant_invalid' => 'Hindi wasto ang napiling regalo para sa promo.',
            'checkout_gift_out_of_stock' => 'Walang stock ang regalo.',
            'cart_bogo_promo_title' => 'Regalo sa BOGO',
            'cart_bogo_pick_label' => 'Piliin ang regalo sa BOGO',
            'cart_bogo_included_fixed' => 'May idaragdag na BOGO regalo (batay sa cart).',
            'checkout_bogo_gift_pick_required' => 'Pumili muna ng BOGO regalo bago ipadala ang order.',
            'cart_gift_register_unlock_teaser' => 'Mag-sign in gamit ang beripikadong email para sa karagdagang libreng regalo (alok para sa naka-register lang).',
            'cart_bogo_register_unlock_teaser' => 'Mag-sign in para mabuksan ang BOGO regalo na para sa naka-register lang sa cart na ito.',
            'cart_combo_register_unlock_teaser' => 'Mag-sign in gamit ang beripikadong email para sa registered-only combo price sa cart na ito.',
            'cart_register_promo_teaser' => 'Makakatipid ka pa ng {extra} KD sa order na ito kung magre-register ka gamit ang email.',
            'cart_register_promo_teaser_action' => 'Mag-register',
            'cart_items_count' => '{n} item',
            'cart_unit_price' => 'Presyo bawat isa',
            'cart_line_subtotal' => 'Subtotal',
            'cart_max_available_short' => 'Hanggang {n} available',
            'cart_continue_shopping' => 'Mag-shopping pa',
            'cart_mini_summary_title' => 'Buod ng order',
            'cart_mini_more' => '+{n} pa',
            'cart_basket_tab_intro' => 'Sa tab na ito piliin ang produkto at tap Place order para ipadala sa tindahan. Kung higit sa isang item, i-tick kung aling linya kasama sa order na ito; ang hindi napili ay mananatili sa cart.',
            'cart_orders_tab_intro' => 'Ang My orders ay mga naipadala na sa tindahan. Para sa bagong order, gamitin ang Cart tab, tapos bumalik dito para sa detalye.',
            'cart_order_line_choice_hint' => 'Piliin para sa order na ito:',
            'cart_include_in_this_order' => 'Isama sa order na ito',
            'cart_select_all_lines' => 'Piliin lahat',
            'cart_deselect_all_lines' => 'Alisin ang pagpili',
            'cart_no_lines_selected_for_order' => 'Pumili ng kahit isang produkto na isasama sa order.',
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
            'customer_amend_order' => 'Baguhin ang items',
            'customer_amend_loaded' => 'Na-load ang cart mula sa order na ito. Suriin at i-tap ang Send order para kumpirmahin.',
            'customer_amend_not_allowed' => 'Hindi puwedeng baguhin ang items sa stage na ito. Mag-WhatsApp.',
            'customer_amend_phone_mismatch' => 'Ilagay ang parehong phone number sa order para kumpirmahin.',
            'customer_amend_mode_banner' => 'Ina-update ang order {order}. I-tap ang Send order para kumpirmahin (WhatsApp).',
            'customer_amend_send_order' => 'Kumpirmahin ang pagbabago (WhatsApp)',
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
            'storefront_register_phone_merge_intro' => 'Naka-link na ang numerong ito sa isang verified account. Ipadala ang WhatsApp mula sa parehong numero, hintayin ang kumpirmasyon ng tindahan, tapos i-tap ang «Apply profile update». Ang login email ay hindi magbabago — pangalan, lugar, at address lang ang ia-update.',
            'storefront_register_phone_merge_wa_body' => "Orange — profile update / same phone\nToken: {token}\nEmail na sinusubukan kong idagdag: {email}\n{site}",
            'storefront_register_phone_merge_steps' => '1) Buksan ang WhatsApp at ipadala ang mensahe. 2) Pagkatapos kumpirmahin ng tindahan, i-tap ang «Apply profile update».',
            'storefront_register_phone_merge_apply_btn' => 'Apply profile update',
            'storefront_register_phone_merge_wa_cta' => 'Buksan ang WhatsApp',
            'storefront_register_phone_merge_token_label' => 'Merge token (kopyahin kung kailangan)',
            'storefront_register_phone_merge_masked_label' => 'Verified login email (hindi magbabago):',
            'storefront_merge_wa_not_confirmed' => 'Hindi pa kinukumpirma ng tindahan ang WhatsApp. Ipadala muna ang mensahe.',
            'storefront_merge_email_mismatch' => 'Hindi tumutugma ang email sa merge request.',
            'storefront_merge_invalid_token' => 'Hindi wasto o expired ang token. Ulitin ang sign-up form.',
            'storefront_merge_apply_ok' => 'Na-update ang profile sa existing account. Mag-sign in gamit ang orihinal na verified email.',
            'storefront_merge_apply_err' => 'Hindi na-apply ang update. Subukan ulit o makipag-ugnayan.',
            'api_request_failed' => 'Hindi natapos ang hiling. Subukan ulit mamaya.',
            'api_post_only' => 'Kailangan ang POST para sa paghahanap ng order (gamitin ang form sa site).',
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
            'sizing_guide' => 'साइज गाइड',
            'sizing_guide_section_upper' => 'ऊपरी शरीर के माप',
            'sizing_guide_section_lower' => 'निचले शरीर के माप',
            'sizing_adv_toolbar_system' => 'साइज़ लेबल',
            'sizing_adv_toolbar_unit' => 'लंबाई की इकाई',
            'sizing_unit_cm_short' => 'से.मी.',
            'sizing_unit_inch_short' => 'इंच',
            'sizing_display_system_eu' => 'EU',
            'sizing_display_system_uk' => 'UK',
            'sizing_display_system_us' => 'US',
            'sizing_guide_close' => 'बंद करें',
            'sizing_col_size' => 'साइज़',
            'sizing_col_foot_cm' => 'पैर की लंबाई (सेमी)',
            'sizing_hint_upper' => 'यह उत्पाद ऊपरी शरीर के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'sizing_hint_lower' => 'यह उत्पाद निचले शरीर के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'sizing_hint_both' => 'यह उत्पाद ऊपरी और निचले शरीर दोनों के साइज़ चार्ट का उपयोग करता है (संकेतक)।',
            'sizing_hint_single' => 'यह उत्पाद एक साइज़ चार्ट का उपयोग करता है (संकेतक)।',
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
            'track_another_order' => 'दूसरा ऑर्डर ट्रैक करें',
            'track_result_region_aria' => 'ऑर्डर ट्रैकिंग परिणाम',
            'track_line_discount_label' => 'लाइन छूट',
            'track_email_summary_intro' => 'इस ऑर्डर की पूरी प्रति अपने ईमेल पर भेजें (स्क्रीन जैसी ही विवरण)।',
            'track_email_summary_placeholder' => 'आपका ईमेल',
            'track_email_summary_send' => 'ईमेल पर प्रति भेजें',
            'track_email_summary_ok' => 'भेज दिया गया। इनबॉक्स देखें।',
            'track_email_summary_err' => 'नहीं भेजा जा सका। बाद में कोशिश करें या व्हाट्सऐप करें।',
            'track_email_summary_rate_limit' => 'दूसरा ईमेल मांगने से पहले प्रतीक्षा करें।',
            'track_share_reference_title' => 'ऑर्डर संदर्भ साझा करें (केवल टेक्स्ट — लिंक नहीं)',
            'track_share_reference_hint' =>
                'साइट पर ट्रैक करने के लिए यह ऑर्डर नंबर और ऑर्डर के समय वाला फ़ोन अभी भी चाहिए। केवल यह टेक्स्ट पता पट्टी में चिपकाने से ऑर्डर नहीं खुलेगा।',
            'track_share_reference_copy' => 'कॉपी करें',
            'track_share_reference_copied' => 'कॉपी हो गया।',
            'track_missing_fields' => 'कृपया ऑर्डर नंबर और फ़ोन दर्ज करें।',
            'track_order_not_found' => 'इन विवरणों से कोई ऑर्डर नहीं मिला। नंबर और फ़ोन जाँचें।',
            'track_signup_placeholder_email' => 'पुष्टिकरण लिंक के लिए ईमेल।',
            'track_signup_placeholder_order_number' => 'जैसा ऑर्डर कन्फ़र्मेशन में (जैसे ORD-…)',
            'track_signup_verify_phone_label' => 'ऑर्डर वाला फ़ोन (सत्यापन)',
            'track_signup_placeholder_verify_phone' => 'ऑर्डर फ़ोन (राष्ट्रीय अंक, बिना देश कोड)',
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
            'register_placeholder_email' => 'your@email.com',
            'register_placeholder_name' => 'आपका नाम',
            'register_placeholder_phone' => 'फ़ोन नंबर दर्ज करें (केवल राष्ट्रीय अंक)',
            'register_placeholder_area' => 'क्षेत्र',
            'checkout_select_area' => 'क्षेत्र चुनें',
            'delivery_area_open_list' => 'डिलीवरी क्षेत्रों की सूची खोलें',
            'checkout_delivery_area_required' => 'कृपया डिलीवरी क्षेत्र चुनें।',
            'checkout_delivery_areas_unavailable' => 'अभी कोई सक्रिय डिलीवरी क्षेत्र नहीं है। कृपया दुकान से संपर्क करें या बाद में प्रयास करें।',
            'checkout_delivery_areas_unavailable_note' => 'ऑनलाइन चेकआउट के लिए कम से कम एक सक्रिय डिलीवरी क्षेत्र चाहिए। एडमिन इसे Admin → Delivery areas में जोड़ सकता है।',
            'register_placeholder_address' => 'पूरा पता',
            'register_placeholder_notes' => 'नोट्स (वैकल्पिक)',
            'checkout_required_fields' => 'कृपया सभी आवश्यक फ़ील्ड भरें (नाम, फ़ोन, ईमेल, क्षेत्र, पता)।',
            'checkout_invalid_email' => 'कृपया वैध ईमेल दर्ज करें।',
            'checkout_invalid_phone' => 'केवल राष्ट्रीय नंबर दर्ज करें (अंक, बिना + या देश कोड — देश बगल वाले फ़ील्ड में चुनें)।',
            'phone_country_label' => 'फ़ोन के लिए देश कोड',
            'phone_country_select_placeholder' => 'देश कोड चुनें',
            'phone_country_open_list' => 'देशों की सूची खोलें',
            'phone_country_required' => 'कृपया फ़ोन नंबर के लिए देश कोड चुनें।',
            'validation_value_missing' => 'कृपया यह फ़ील्ड भरें।',
            'phone_country_full_international' => 'अंतर्राष्ट्रीय — + या 00 के साथ पूरा नंबर',
            'phone_country_kw' => 'कुवैत +965 (8 अंक मोबाइल)',
            'phone_country_ph' => 'फ़िलीपीन्स +63',
            'phone_country_in' => 'भारत +91',
            'phone_country_pk' => 'पाकिस्तान +92',
            'phone_field_hint' => 'देश कोड के बिना फ़ोन नंबर दर्ज करें (केवल अंक)।',
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
            'storefront_country' => 'देश',
            'storefront_country_select' => 'देश चुनें',
            'storefront_brand' => 'Orange Company',
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
            'cart_tab_delivered' => 'डिलिवर हो चुके',
            'cart_account_orders_heading_active' => 'आपके चालू ऑर्डर',
            'cart_account_orders_heading_delivered' => 'डिलिवर किए गए ऑर्डर',
            'cart_account_orders_empty' => 'इस सूची में अभी कोई ऑर्डर नहीं है।',
            'cart_guest_orders_heading' => 'आपके ऑर्डर (अतिथि)',
            'cart_guest_orders_intro' => 'यहाँ केवल लंबित या स्वीकृत ऑर्डर दिखते हैं। पूरी स्थिति के लिए «ट्रैक ऑर्डर» उपयोग करें।',
            'cart_guest_orders_empty' => 'यहाँ अभी कोई लंबित/स्वीकृत ऑर्डर नहीं। इस ब्राउज़र से ऑर्डर करने के बाद वे यहाँ दिखेंगे। सभी स्थितियों के लिए ट्रैक ऑर्डर उपयोग करें।',
            'cart_account_auth_required' => 'अपने ऑर्डर देखने के लिए कृपया फिर साइन इन करें।',
            'cart_proceed_to_order' => 'ऑर्डर करें',
            'cart_checkout_title' => 'आपका विवरण',
            'cart_checkout_intro' => 'अपनी जानकारी भरें, फिर ऑर्डर भेजें। व्हाट्सऐप से स्टोर पर पुष्टि होगी।',
            'cart_total_label' => 'कुल',
            'cart_subtotal_label' => 'उप-योग',
            'cart_promotion_discount_label' => 'कार्ट ऑफ़र',
            'cart_combo_discount_label' => 'कॉम्बो बंडल',
            'cart_gift_promo_title' => 'इस ऑर्डर के साथ मुफ़्त उपहार',
            'cart_gift_pick_label' => 'अपना मुफ़्त उपहार चुनें',
            'cart_gift_included_fixed' => 'इस ऑर्डर में स्वतः एक मुफ़्त उपहार जोड़ा जाएगा।',
            'checkout_gift_pick_required' => 'ऑर्डर भेजने से पहले उपहार चुनें।',
            'checkout_gift_variant_invalid' => 'चुना हुआ उपहार इस ऑफ़र के लिए मान्य नहीं है।',
            'checkout_gift_out_of_stock' => 'उपहार स्टॉक में नहीं है।',
            'cart_bogo_promo_title' => 'BOGO उपहार',
            'cart_bogo_pick_label' => 'अपना BOGO उपहार चुनें',
            'cart_bogo_included_fixed' => 'कार्ट की शर्तें पूरी होने पर BOGO उपहार स्वतः जुड़ेगा।',
            'checkout_bogo_gift_pick_required' => 'ऑर्डर भेजने से पहले BOGO उपहार चुनें।',
            'cart_gift_register_unlock_teaser' => 'पंजीकृत उपयोगकर्ताओं के लिए अतिरिक्त मुफ़्त उपहार पाने के लिए पुष्ट ईमेल वाले खाते से साइन इन करें।',
            'cart_bogo_register_unlock_teaser' => 'पंजीकृत उपयोगकर्ताओं के लिए BOGO उपहार अनलॉक करने के लिए साइन इन करें।',
            'cart_combo_register_unlock_teaser' => 'पंजीकृत-केवल कॉम्बो मूल्य के लिए पुष्ट ईमेल वाले खाते से साइन इन करें।',
            'cart_register_promo_teaser' => 'ईमेल से पंजीकरण करने पर इस ऑर्डर पर अतिरिक्त {extra} KD बचा सकते हैं।',
            'cart_register_promo_teaser_action' => 'पंजीकरण',
            'cart_items_count' => '{n} आइटम',
            'cart_unit_price' => 'यूनिट मूल्य',
            'cart_line_subtotal' => 'पंक्ति योग',
            'cart_max_available_short' => 'अधिकतम {n} उपलब्ध',
            'cart_continue_shopping' => 'खरीदारी जारी रखें',
            'cart_mini_summary_title' => 'ऑर्डर सारांश',
            'cart_mini_more' => '+{n} और',
            'cart_basket_tab_intro' => 'इस टैब में उत्पाद चुनें और स्टोर को भेजने के लिए «ऑर्डर करें» दबाएँ। एक से अधिक आइटम पर इस ऑर्डर में शामिल लाइनें चुनें; बिना चुने आइटम कार्ट में रहेंगे।',
            'cart_orders_tab_intro' => '«मेरे ऑर्डर» में वे ऑर्डर दिखते हैं जो आप पहले भेज चुके हैं। नया ऑर्डर कार्ट टैब से भेजें, फिर यहाँ विवरण भरें।',
            'cart_order_line_choice_hint' => 'इस ऑर्डर के लिए चुनें:',
            'cart_include_in_this_order' => 'इस ऑर्डर में शामिल करें',
            'cart_select_all_lines' => 'सभी चुनें',
            'cart_deselect_all_lines' => 'चयन हटाएँ',
            'cart_no_lines_selected_for_order' => 'कम से कम एक उत्पाद इस ऑर्डर में शामिल करें।',
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
            'customer_amend_order' => 'आइटम बदलें',
            'customer_amend_loaded' => 'कार्ट इस ऑर्डर से भर दी गई। जाँच करें और बदलाव के लिए ऑर्डर भेजें दबाएँ।',
            'customer_amend_not_allowed' => 'इस चरण में साइट से आइटम नहीं बदल सकते। व्हाट्सऐप पर लिखें।',
            'customer_amend_phone_mismatch' => 'पुष्टि के लिए वही फ़ोन नंबर दर्ज करें जो ऑर्डर पर है।',
            'customer_amend_mode_banner' => 'ऑर्डर {order} अपडेट कर रहे हैं। पुष्टि के लिए ऑर्डर भेजें दबाएँ (व्हाट्सऐप)।',
            'customer_amend_send_order' => 'बदलाव की पुष्टि (व्हाट्सऐप)',
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
            'storefront_register_phone_merge_intro' => 'यह फ़ोन पहले से एक पुष्ट खाते से जुड़ा है। उसी नंबर से WhatsApp संदेश भेजें, स्टोर की पुष्टि की प्रतीक्षा करें, फिर «प्रोफ़ाइल अपडेट लागू करें» टैप करें। लॉगिन ईमेल नहीं बदलेगा — केवल नाम, क्षेत्र और पता अपडेट होंगे।',
            'storefront_register_phone_merge_wa_body' => "Orange — प्रोफ़ाइल अपडेट / वही फ़ोन\nटोकन: {token}\nजिस ईमेल से मैं पंजीकरण कर रहा हूँ: {email}\n{site}",
            'storefront_register_phone_merge_steps' => '1) WhatsApp खोलें और तैयार संदेश भेजें। 2) स्टोर की पुष्टि के बाद «प्रोफ़ाइल अपडेट लागू करें» टैप करें।',
            'storefront_register_phone_merge_apply_btn' => 'प्रोफ़ाइल अपडेट लागू करें',
            'storefront_register_phone_merge_wa_cta' => 'WhatsApp खोलें',
            'storefront_register_phone_merge_token_label' => 'मर्ज टोकन (आवश्यक हो तो कॉपी करें)',
            'storefront_register_phone_merge_masked_label' => 'पुष्ट लॉगिन ईमेल (अपरिवर्तित):',
            'storefront_merge_wa_not_confirmed' => 'स्टोर ने अभी तक WhatsApp पुष्ट नहीं की। पहले संदेश भेजें।',
            'storefront_merge_email_mismatch' => 'ईमेल मर्ज अनुरोध से मेल नहीं खाता।',
            'storefront_merge_invalid_token' => 'अमान्य या समाप्त टोकन। साइन अप फ़ॉर्म फिर भेजें।',
            'storefront_merge_apply_ok' => 'आपकी प्रोफ़ाइल मौजूदा खाते पर अपडेट हो गई। मूल पुष्ट ईमेल से साइन इन करें।',
            'storefront_merge_apply_err' => 'अपडेट लागू नहीं हो सका। बाद में कोशिश करें या सहायता लें।',
            'api_request_failed' => 'अनुरोध पूरा नहीं हो सका। बाद में कोशिश करें।',
            'api_post_only' => 'ऑर्डर खोजने के लिए साइट पर ट्रैकिंग फ़ॉर्म से भेजना आवश्यक है (केवल POST)।',
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
 * جمل الـ hero في الصفحة الرئيسية (بالتناوب حسب لغة الزائر؛ عدد غير محدود من الأدمن).
 *
 * @return list<string>
 */
function storefront_home_hero_lines(): array {
    require_once __DIR__ . '/includes/storefront_hero.php';

    return orange_storefront_home_hero_lines_resolved(db(), current_lang());
}

/**
 * جمل التناوب تحت الشعار في الهيدر: لكل صف نشط، الترتيب عربي → إنجليزي → فلبيني → هندي (غير الفارغ فقط).
 *
 * @return list<string>
 */
function storefront_tagline_cycle_messages(): array {
    require_once __DIR__ . '/includes/storefront_hero.php';

    return orange_storefront_header_tagline_cycle_resolved(db());
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
    unset($_SESSION['admin_country_lock']);
    try {
        $pdo = db();
        require_once __DIR__ . '/includes/catalog_schema.php';
        if (orange_table_exists($pdo, 'admins') && orange_table_has_column($pdo, 'admins', 'country_id')) {
            $st = $pdo->prepare('SELECT country_id FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
            $st->execute([$adminId]);
            $cid = (int) ($st->fetchColumn() ?: 0);
            if ($cid > 0) {
                $_SESSION['admin_country_lock'] = $cid;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('[orange] admin_login country_lock: ' . $e->getMessage());
        }
    }
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
        require_once __DIR__ . '/includes/upload_paths.php';
        header('Location: ' . storefront_public_path('/admin/login.php'));
        exit;
    }
    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM admins WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        admin_logout();
        require_once __DIR__ . '/includes/upload_paths.php';
        header('Location: ' . storefront_public_path('/admin/login.php'));
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
