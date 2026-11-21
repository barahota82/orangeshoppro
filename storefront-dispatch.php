<?php

declare(strict_types=1);

/**
 * موجّه المسارات القصيرة للواجهة — مقطع الدولة (مثل /kw /eg-ar/cart).
 * روابط القنوات القديمة تُحوَّل GET إلى مسار الدولة المكافئ عند الإمكان.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/catalog_schema.php';
require_once __DIR__ . '/includes/countries.php';
require_once __DIR__ . '/includes/storefront_country_store.php';
require_once __DIR__ . '/includes/storefront_entry.php';

$pdo = db();
orange_catalog_ensure_storefront_read_bootstrap($pdo);

$first = isset($_GET['sf_first'])
    ? strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['sf_first']))
    : '';
$langToken = isset($_GET['sf_langx']) ? strtolower(trim((string) $_GET['sf_langx'])) : '';
$tail = isset($_GET['sf_tail']) ? trim(str_replace('\\', '/', (string) $_GET['sf_tail']), '/') : '';

if (($first === '' || $first === null) && $langToken === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// Unpack lang suffix embedded in sf_first (kw-ar)
if ($first !== '' && preg_match('/^(.+)-(ar|hi|ph)$/', $first, $fm)) {
    $first = $fm[1];
    if ($langToken === '') {
        $langToken = '-' . $fm[2];
    }
}

$langTokNorm = $langToken;
if ($langTokNorm !== '' && ($langTokNorm[0] ?? '') !== '-') {
    $langTokNorm = match ($langTokNorm) {
        'ar' => '-ar',
        'hi' => '-hi',
        'ph', 'fil' => '-ph',
        default => $langTokNorm,
    };
}
$lang = match ($langTokNorm) {
    '-ar' => 'ar',
    '-hi' => 'hi',
    '-ph' => 'fil',
    default => '',
};
if ($lang === '') {
    if (isset($_GET['lang'])) {
        $raw = strtolower(trim((string) $_GET['lang']));
        if ($raw === 'ph' || $raw === 'tl') {
            $raw = 'fil';
        }
        require_once __DIR__ . '/includes/orange_language_catalog_data.php';
        if (function_exists('orange_language_catalog_code_operational')
            && orange_language_catalog_code_operational($raw)) {
            $lang = $raw;
        } elseif (in_array($raw, ['en', 'ar', 'fil', 'hi'], true)) {
            $lang = $raw;
        }
    }
}
if ($lang === '') {
    $lang = 'en';
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$isGetLike = in_array($method, ['GET', 'HEAD'], true);

// 1) Country code path
$countryCode = orange_countries_normalize_code($first);
$countryRow = $countryCode !== '' ? orange_country_row_by_code($pdo, $countryCode, true) : null;
if ($countryRow !== null) {
    orange_storefront_apply_entry_country($countryCode);
    $_GET['country'] = $countryCode;
    $_REQUEST['country'] = $countryCode;
    $_GET['lang'] = $lang;
    $_REQUEST['lang'] = $lang;
    // Keep legacy key empty so channel authority is not revived
    unset($_GET['channel'], $_REQUEST['channel']);

    if ($tail === '' || $tail === '/') {
        require __DIR__ . '/pages/home.php';
        exit;
    }
    if ($tail === 'cart') {
        require __DIR__ . '/pages/cart.php';
        exit;
    }
    if ($tail === 'track') {
        require __DIR__ . '/pages/track.php';
        exit;
    }
    if ($tail === 'signup' || $tail === 'signin') {
        require __DIR__ . '/pages/register.php';
        exit;
    }
    if ($tail === 'verify-email') {
        require __DIR__ . '/pages/verify-email.php';
        exit;
    }
    if (preg_match('#^product/(\d+)$#', $tail, $m)) {
        $_GET['id'] = (int) $m[1];
        $_REQUEST['id'] = (int) $m[1];
        require __DIR__ . '/pages/product.php';
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

// 2) Legacy channel path_segment → redirect to country (GET only)
$legacySlug = orange_channel_slug_for_path_segment($pdo, $first, true);
if ($legacySlug !== null && $legacySlug !== '' && $isGetLike) {
    $st = $pdo->prepare('SELECT country_id FROM channels WHERE slug = ? AND is_active = 1 LIMIT 1');
    $st->execute([$legacySlug]);
    $cid = (int) ($st->fetchColumn() ?: 0);
    $crow = $cid > 0 ? orange_country_row_by_id($pdo, $cid, true) : null;
    if ($crow !== null) {
        $cc = orange_countries_normalize_code((string) ($crow['code'] ?? ''));
        $pageInfo = orange_storefront_page_from_short_tail($tail);
        if ($pageInfo !== null && $cc !== '') {
            [$page, $extra] = $pageInfo;
            header('Location: ' . storefront_url($page, $cc, $lang, $extra), true, 302);
            exit;
        }
    }
}

// 3) Lang-only entry (/ar …)
$langOnly = orange_storefront_normalize_lang_only_code($first);
if ($langOnly !== null && $langToken === '') {
    orange_storefront_handle_lang_only_entry($pdo, $langOnly, $tail);
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'Not found';
exit;
