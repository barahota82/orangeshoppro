<?php

declare(strict_types=1);

/**
 * استنتاج دولة الزائر من رؤوس HTTP (Geo) — للدخول عبر جذر الموقع / فقط.
 * لا يُستدعى على مسارات القنوات المباشرة (storefront-dispatch).
 */

/**
 * @return array<string, string> ISO alpha-2 (uppercase) => رمز السوق الداخلي (kw, eg, uae, …)
 */
function orange_storefront_geo_iso_to_market_map(): array
{
    return [
        'KW' => 'kw',
        'EG' => 'eg',
        'AE' => 'uae',
        'SA' => 'ksa',
        'BH' => 'bh',
        'QA' => 'qa',
        'OM' => 'om',
        'JO' => 'jo',
        'LB' => 'lb',
        'IQ' => 'iq',
        'MA' => 'ma',
        'TN' => 'tn',
        'DZ' => 'dz',
        'LY' => 'ly',
        'SD' => 'sd',
        'YE' => 'ye',
        'TR' => 'tr',
    ];
}

function orange_storefront_geo_iso_from_server(): ?string
{
    $candidates = [
        'HTTP_CF_IPCOUNTRY',
        'HTTP_X_APPENGINE_COUNTRY',
        'HTTP_X_COUNTRY_CODE',
        'HTTP_GEOIP_COUNTRY_CODE',
        'HTTP_X_VERCEL_IP_COUNTRY',
    ];
    foreach ($candidates as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $iso = strtoupper(trim((string) $_SERVER[$key]));
        if ($iso === '' || $iso === 'XX' || $iso === 'T1') {
            continue;
        }
        if (preg_match('/^[A-Z]{2}$/', $iso) === 1) {
            return $iso;
        }
    }

    return null;
}

/** اختياري في .env.php: ORANGE_STOREFRONT_GEO_OVERRIDE=kw للتطوير المحلي. */
function orange_storefront_geo_env_override_market_code(): ?string
{
    if (!defined('ORANGE_STOREFRONT_GEO_OVERRIDE')) {
        return null;
    }
    $raw = trim((string) ORANGE_STOREFRONT_GEO_OVERRIDE);
    if ($raw === '') {
        return null;
    }
    require_once __DIR__ . '/countries.php';
    $code = orange_countries_normalize_code($raw);

    return $code !== '' ? $code : null;
}

/**
 * رمز السوق الداخلي من Geo (أو override) — null إن لم يُستنتج.
 */
function orange_storefront_geo_market_code_for_visitor(): ?string
{
    static $memo = null;
    if ($memo !== null) {
        return $memo === '' ? null : $memo;
    }
    $override = orange_storefront_geo_env_override_market_code();
    if ($override !== null) {
        $memo = $override;

        return $memo;
    }
    $iso = orange_storefront_geo_iso_from_server();
    if ($iso === null) {
        $memo = '';

        return null;
    }
    $map = orange_storefront_geo_iso_to_market_map();
    $market = $map[$iso] ?? null;
    if ($market === null) {
        require_once __DIR__ . '/countries.php';
        $try = orange_countries_normalize_code(strtolower($iso));
        $market = $try !== '' ? $try : null;
    }
    $memo = $market ?? '';

    return $market;
}
