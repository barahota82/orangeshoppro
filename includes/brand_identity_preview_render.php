<?php

declare(strict_types=1);

/**
 * Renders the actual candidate identity surfaces using the real CSS
 * and the same markup contracts as login / storefront header / admin topbar.
 */

require_once __DIR__ . '/brand_identity_admin.php';

function orange_brand_identity_preview_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array{mark:string,word:string,slogan:string}
 */
function orange_brand_identity_preview_assets(string $surface, string $locale = 'ar'): array
{
    if ($surface === 'storefront') {
        return [
            'mark' => orange_brand_identity_runtime_consume_slot_url('STOREFRONT_BRAND_MARK', ''),
            'word' => orange_brand_identity_runtime_consume_slot_url('STOREFRONT_COMPANY_WORDMARK', ''),
            'slogan' => (string) (orange_brand_identity_runtime_slogan_text($locale) ?? ''),
        ];
    }

    return [
        'mark' => orange_brand_identity_runtime_consume_slot_url('ADMIN_BRAND_MARK', ''),
        'word' => $surface === 'login'
            ? ''
            : orange_brand_identity_runtime_consume_slot_url('ADMIN_COMPANY_WORDMARK', ''),
        'slogan' => '',
    ];
}

function orange_brand_identity_preview_css_href(string $rel): string
{
    if (function_exists('storefront_public_path')) {
        try {
            $p = trim((string) storefront_public_path($rel));
            if ($p !== '') {
                return $p;
            }
        } catch (Throwable $e) {
            /* keep relative */
        }
    }

    return $rel;
}

/**
 * @param array<string, string> $assets
 */
function orange_brand_identity_preview_login_inner(array $assets): string
{
    $has = $assets['mark'] !== '';
    $html = '<div class="login-card' . ($has ? ' has-identity-content' : '') . '" id="biMeasureCard">';
    if ($has) {
        $html .= '<span class="login-card__identity" aria-hidden="true">';
        $html .= '<img class="login-card__identity-mark" src="' . orange_brand_identity_preview_h($assets['mark']) . '" alt="" width="48" height="48" decoding="async">';
        $html .= '</span>';
    }
    $html .= '<h1>تسجيل الدخول</h1>';
    $html .= '<p class="login-card__hint">لوحة التحكم المؤسسية — مساحة آمنة للفريق الداخلي فقط.</p>';
    $html .= '<form method="post" action="" autocomplete="off">';
    $html .= '<label>اسم المستخدم</label><input type="text" name="username" required autocomplete="username">';
    $html .= '<label>كلمة المرور</label><input type="password" name="password" required autocomplete="current-password">';
    $html .= '<button type="submit" name="admin_login" value="1">دخول</button>';
    $html .= '</form></div>';

    return $html;
}

/**
 * @param array<string, string> $assets
 */
function orange_brand_identity_preview_storefront_inner(array $assets): string
{
    $mark = $assets['mark'] !== '' ? $assets['mark'] : '/assets/images/logo.webp';
    $word = $assets['word'] !== '' ? $assets['word'] : '/assets/images/orange-company.webp';
    $slogan = $assets['slogan'];

    return '<header class="site-header" dir="ltr" id="biMeasureCard">'
        . '<div class="container header-inner">'
        . '<div class="brand-wrap">'
        . '<img class="logo" src="' . orange_brand_identity_preview_h($mark) . '" alt="" width="52" height="52" decoding="async" role="presentation">'
        . '<div class="brand-text"><div class="brand-stack"><div class="brand-wordmark-anchor">'
        . '<h1 class="brand-title-heading"><img class="brand-wordmark" src="' . orange_brand_identity_preview_h($word) . '" alt="Orange" width="248" height="32" decoding="async"></h1>'
        . '<small class="brand-tagline brand-tagline--cycle" data-static="1" aria-live="polite">'
        . '<span class="brand-tagline__text" id="brandTaglineText" dir="auto">' . orange_brand_identity_preview_h($slogan) . '</span>'
        . '</small></div></div></div></div></div></header>';
}

/**
 * @param array<string, string> $assets
 */
function orange_brand_identity_preview_admin_inner(array $assets): string
{
    $html = '<header class="admin-topbar" id="biMeasureCard"><div class="admin-topbar-strip">';
    $html .= '<div class="admin-topbar-brand" role="banner">';
    if ($assets['mark'] !== '') {
        $html .= '<div class="admin-sidebar-brand__mark admin-sidebar-brand__mark--slot">'
            . '<img src="' . orange_brand_identity_preview_h($assets['mark']) . '" alt="" width="40" height="40" decoding="async"></div>';
    } else {
        $html .= '<div class="admin-sidebar-brand__mark" aria-hidden="true"></div>';
    }
    $html .= '<div class="admin-sidebar-brand__text">';
    if ($assets['word'] !== '') {
        $html .= '<div class="admin-sidebar-brand__title admin-sidebar-brand__title--slot">'
            . '<img src="' . orange_brand_identity_preview_h($assets['word']) . '" alt="Orange" height="22" decoding="async"></div>';
    } else {
        $html .= '<div class="admin-sidebar-brand__title">Orange</div>';
    }
    $html .= '<div class="admin-sidebar-brand__subtitle">لوحة التحكم المؤسسية</div>';
    $html .= '</div></div></div></header>';

    return $html;
}

function orange_brand_identity_preview_document(string $surface, string $viewport, string $locale = 'ar'): string
{
    $assets = orange_brand_identity_preview_assets($surface, $locale);
    $widths = orange_brand_identity_preview_viewport_widths();
    $width = $widths[$viewport] ?? 1280;
    $adminCss = orange_brand_identity_preview_css_href('/admin/assets/admin.css');
    $sfCss = orange_brand_identity_preview_css_href('/assets/css/main.css');
    $bodyClass = $surface === 'login' ? 'admin-login-page' : 'bi-preview-' . $surface;
    $inner = match ($surface) {
        'login' => orange_brand_identity_preview_login_inner($assets),
        'admin' => orange_brand_identity_preview_admin_inner($assets),
        default => orange_brand_identity_preview_storefront_inner($assets),
    };
    $cssLinks = $surface === 'storefront'
        ? '<link rel="stylesheet" href="' . orange_brand_identity_preview_h($sfCss) . '">'
        : '<link rel="stylesheet" href="' . orange_brand_identity_preview_h($adminCss) . '">';

    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>معاينة هوية — ' . orange_brand_identity_preview_h($surface . ' ' . $viewport) . '</title>'
        . $cssLinks
        . '<style>html,body{margin:0;} .bi-preview-admin{background:#f8fafc;min-height:100vh;}</style>'
        . '</head><body class="' . orange_brand_identity_preview_h($bodyClass) . '" data-surface="'
        . orange_brand_identity_preview_h($surface) . '" data-viewport="'
        . orange_brand_identity_preview_h($viewport) . '" data-width="' . (int) $width . '">'
        . $inner
        . '<script>(function(){function report(){var el=document.getElementById("biMeasureCard");'
        . 'var r=el?el.getBoundingClientRect():{width:0,height:0};'
        . 'var payload={kind:"orange_brand_preview_measure",surface:' . json_encode($surface)
        . ',viewport:' . json_encode($viewport)
        . ',width:Math.round(r.width),height:Math.round(r.height),'
        . 'innerWidth:window.innerWidth,innerHeight:window.innerHeight};'
        . 'try{if(window.parent&&window.parent!==window){window.parent.postMessage(payload,"*");}}catch(e){}'
        . 'window.ORANGE_BI_PREVIEW_MEASURE=payload;}'
        . 'window.addEventListener("load",report);setTimeout(report,300);})();</script>'
        . '</body></html>';
}
