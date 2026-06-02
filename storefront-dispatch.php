<?php

declare(strict_types=1);

/**
 * موجّه المسارات القصيرة للواجهة (مثل /tiktok /instagram-ar/cart).
 * يستدعيه IIS عبر web.config؛ يحل path_segment من جدول channels ثم يحمّل pages/*.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/catalog_schema.php';

$pdo = db();
/* كل صفحة تحت pages/* تستدعي orange_catalog_ensure_storefront_page()؛ هنا bootstrap خفيف فقط لحل القناة/path_segment */
orange_catalog_ensure_storefront_read_bootstrap($pdo);

$first = isset($_GET['sf_first'])
    ? strtolower((string) preg_replace('/[^a-z0-9\-]/i', '', (string) $_GET['sf_first']))
    : '';
$langToken = isset($_GET['sf_langx']) ? strtolower(trim((string) $_GET['sf_langx'])) : '';
$tail = isset($_GET['sf_tail']) ? trim(str_replace('\\', '/', (string) $_GET['sf_tail']), '/') : '';

$previewQ = isset($_GET['sf_preview']) ? (string) $_GET['sf_preview'] : '';
$tokenPreview = ORANGE_STOREFRONT_PREVIEW_TOKEN !== ''
    && $previewQ !== ''
    && hash_equals(ORANGE_STOREFRONT_PREVIEW_TOKEN, $previewQ);
$sessionPreview = orange_storefront_preview_session_valid_for_segment($first);
$previewOk = $tokenPreview || $sessionPreview;

$channelSlug = orange_channel_slug_for_path_segment($pdo, $first, !$previewOk);
/*
 * إذا وصل sf_first مدمجاً مع لاحقة اللغة (مثل tiktok-ar) فلا يوجد path_segment بهذا الاسم — فيظهر 404 للعربية/الهندية/الفلبينية.
 * نفصل القاعدة عن -ar|-hi|-ph عندما تطابق القاعدة قناة، ونتحقق أن sf_langx إن وُجد لا يتعارض مع اللاحقة في المسار.
 */
if (($channelSlug === null || $channelSlug === '') && $first !== '' && preg_match('/^(.+)-(ar|hi|ph)$/', $first, $fm)) {
    $baseSeg = $fm[1];
    $suf = $fm[2];
    $slugTry = orange_channel_slug_for_path_segment($pdo, $baseSeg, !$previewOk);
    if ($slugTry !== null && $slugTry !== '') {
        $expectedFromPath = '-' . $suf;
        $ltRaw = strtolower(trim($langToken));
        $compatible = $ltRaw === ''
            || $ltRaw === $expectedFromPath
            || $ltRaw === $suf
            || ($ltRaw === 'ar' && $suf === 'ar')
            || ($ltRaw === 'hi' && $suf === 'hi')
            || (in_array($ltRaw, ['ph', 'fil'], true) && $suf === 'ph');
        if ($compatible) {
            $first = $baseSeg;
            $langToken = $expectedFromPath;
            $channelSlug = $slugTry;
            $sessionPreview = orange_storefront_preview_session_valid_for_segment($first);
            $previewOk = $tokenPreview || $sessionPreview;
        }
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
        if (in_array($raw, ['en', 'ar', 'fil', 'hi'], true)) {
            $lang = $raw;
        }
    }
}
/*
 * مسار قصير بلا لاحقة ‎-ar/-hi/-ph‎ يُعرّف الإنجليزية (مثل ‎/web‎). لا نستخدم كوكي قديم هنا —
 * وإلا يبقى العرض عربي/هندي بعد اختيار الإنجليزية من القائمة.
 */
if ($lang === '') {
    $lang = 'en';
}

if ($first === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';

    exit;
}

/* للزوار: قناة غير نشطة = 404. معاينة الإدمن (جلسة أو ?sf_preview=) تسمح بفتح المسار رغم الإيقاف */
if ($channelSlug === null || $channelSlug === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';

    exit;
}

$_GET['channel'] = $channelSlug;
$_GET['lang'] = $lang;
$_REQUEST['channel'] = $channelSlug;
$_REQUEST['lang'] = $lang;

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
