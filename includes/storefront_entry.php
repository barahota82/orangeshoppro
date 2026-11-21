<?php

declare(strict_types=1);

require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/storefront_country_store.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/storefront_account.php';
require_once __DIR__ . '/orange_language_catalog_data.php';

/**
 * دخول الزائر من جذر الموقع (/): دولة نشطة (تلقائي إن واحدة / كوكي صالح / وإلا اختيار) — بلا Geo وبلا قناة.
 * مسارات الدولة المباشرة (storefront-dispatch) لا تمرّ من هنا.
 */
function orange_storefront_handle_bare_root(PDO $pdo): void
{
    orange_catalog_ensure_storefront_read_bootstrap($pdo);
    $resolved = orange_storefront_resolve_public_entry_country($pdo);
    if ($resolved === null) {
        orange_storefront_render_region_unavailable();
    }
    require_once __DIR__ . '/orange_storefront_customer_lang.php';
    $candidates = [];
    if (function_exists('current_lang')) {
        $candidates[] = (string) current_lang();
    }
    if (!empty($resolved['needs_picker'])) {
        $langPick = $candidates[0] ?? 'ar';
        if (!in_array($langPick, ['ar', 'en', 'fil', 'hi'], true)) {
            $langPick = 'ar';
        }
        orange_storefront_render_country_picker($langPick, 'home', []);
    }
    orange_storefront_apply_entry_country($resolved['country_code']);
    $lang = orange_storefront_entry_select_public_lang($pdo, (int) $resolved['country_id'], $candidates);
    header('Location: ' . storefront_url('home', $resolved['country_code'], $lang));
    exit;
}

/**
 * دخول بلغة فقط: /ar أو /en … — بلا Geo؛ دولة عبر resolve_public_entry_country.
 *
 * @return never
 */
function orange_storefront_handle_lang_only_entry(PDO $pdo, string $lang, string $tail): void
{
    orange_catalog_ensure_storefront_read_bootstrap($pdo);
    $lang = orange_storefront_normalize_lang_only_code($lang);
    if ($lang === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $pageInfo = orange_storefront_page_from_short_tail($tail);
    if ($pageInfo === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    [$page, $extra] = $pageInfo;
    $resolved = orange_storefront_resolve_public_entry_country($pdo);
    if ($resolved === null) {
        orange_storefront_render_region_unavailable();
    }
    if (!empty($resolved['needs_picker'])) {
        // احتفظ باللغة والصفحة وid/token الآمنين عبر شاشة الاختيار (س32).
        orange_storefront_render_country_picker($lang, $page, $extra);
    }
    orange_storefront_apply_entry_country($resolved['country_code']);
    require_once __DIR__ . '/orange_storefront_customer_lang.php';
    $lang = orange_storefront_entry_select_public_lang($pdo, (int) $resolved['country_id'], [$lang]);
    header('Location: ' . storefront_url($page, $resolved['country_code'], $lang, $extra));
    exit;
}

function orange_storefront_normalize_lang_only_code(string $raw): ?string
{
    $code = strtolower(trim($raw));
    if ($code === 'ph' || $code === 'tl') {
        $code = 'fil';
    }
    if ($code === '' || preg_match('/^[a-z]{2,3}$/', $code) !== 1) {
        return null;
    }
    if (function_exists('orange_storefront_reserved_path_segments')) {
        foreach (orange_storefront_reserved_path_segments() as $rs) {
            if ($code === strtolower((string) $rs)) {
                return null;
            }
        }
    }
    if (!orange_language_catalog_code_operational($code)) {
        return null;
    }

    return $code;
}

/** @deprecated Geo entry removed (S32); kept as alias for any leftover callers. */
function orange_storefront_resolve_geo_entry_channel(PDO $pdo): ?array
{
    $resolved = orange_storefront_resolve_public_entry_country($pdo);
    if ($resolved === null || !empty($resolved['needs_picker'])) {
        return null;
    }

    return [
        'country_code' => $resolved['country_code'],
        'country_id' => $resolved['country_id'],
        'channel_slug' => $resolved['country_code'],
    ];
}

function orange_storefront_apply_entry_country(string $marketCode): void
{
    orange_storefront_set_country_override($marketCode);
    if (function_exists('orange_storefront_customer_lang_reset_request_memo')) {
        orange_storefront_customer_lang_reset_request_memo();
    } elseif (is_file(__DIR__ . '/orange_storefront_customer_lang.php')) {
        require_once __DIR__ . '/orange_storefront_customer_lang.php';
        if (function_exists('orange_storefront_customer_lang_reset_request_memo')) {
            orange_storefront_customer_lang_reset_request_memo();
        }
    }
    if (!headers_sent()) {
        orange_storefront_send_country_cookie($marketCode);
    }
}

/**
 * @return array{0:'home'|'cart'|'track'|'product'|'register'|'verify_email',1:array<string,mixed>}|null
 */
function orange_storefront_page_from_short_tail(string $tail): ?array
{
    $tail = trim(str_replace('\\', '/', $tail), '/');
    if ($tail === '' || $tail === '/') {
        return ['home', []];
    }
    if ($tail === 'cart') {
        return ['cart', []];
    }
    if ($tail === 'track') {
        return ['track', []];
    }
    if ($tail === 'signup' || $tail === 'signin') {
        return ['register', []];
    }
    if ($tail === 'verify-email') {
        $extra = [];
        $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
        if ($token !== '') {
            $extra['token'] = $token;
        }

        return ['verify_email', $extra];
    }
    if (preg_match('#^product/(\d+)$#', $tail, $m) === 1) {
        return ['product', ['id' => (int) $m[1]]];
    }

    return null;
}

function orange_storefront_render_region_unavailable(): never
{
    orange_send_html_no_cache_headers();
    require __DIR__ . '/../pages/region-unavailable.php';
    exit;
}

/**
 * @param 'home'|'cart'|'track'|'product'|'register'|'verify_email' $page
 * @param array<string, mixed> $extra
 */
function orange_storefront_render_country_picker(string $lang = 'ar', string $page = 'home', array $extra = []): never
{
    $GLOBALS['orange_sf_picker_lang'] = $lang;
    $GLOBALS['orange_sf_picker_page'] = $page;
    $GLOBALS['orange_sf_picker_extra'] = $extra;
    orange_send_html_no_cache_headers();
    require __DIR__ . '/../pages/country-select.php';
    exit;
}
