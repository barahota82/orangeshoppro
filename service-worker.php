<?php

declare(strict_types=1);

/**
 * Service worker للواجهة: كاش لملفات /assets/* فقط؛ صفحات PHP تبقى من الشبكة دائماً.
 * يحسّن سرعة التنقل في نافذة PWA (standalone) بعد أول زيارة.
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=120');

$pub = PUBLIC_BASE_PATH === '' ? '' : PUBLIC_BASE_PATH;

$precacheRel = [
    storefront_asset_url('/assets/css/main.css'),
    storefront_asset_url('/assets/css/theme-orange.css'),
    storefront_asset_url('/assets/css/theme-blue.css'),
    storefront_asset_url('/assets/css/theme-black.css'),
    storefront_asset_url('/assets/js/lang.js'),
    storefront_asset_url('/assets/js/app.js'),
    storefront_asset_url('/assets/js/cart.js'),
    storefront_asset_url('/assets/js/product.js'),
];
$precacheUrls = [];
foreach ($precacheRel as $rel) {
    $precacheUrls[] = $pub . $rel;
}

$v = storefront_asset_version();
$swTouch = is_file(__DIR__ . '/service-worker.php') ? (string) filemtime(__DIR__ . '/service-worker.php') : '0';
$cacheKey = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $v . '-' . $swTouch);
if ($cacheKey === '') {
    $cacheKey = '1';
}

$baseJson = json_encode($pub, JSON_UNESCAPED_UNICODE);
$urlsJson = json_encode($precacheUrls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$cacheNameJson = json_encode('orange-sf-' . $cacheKey, JSON_UNESCAPED_UNICODE);

echo <<<JS
/* Orange storefront static cache */
const ORANGE_SW_CACHE = {$cacheNameJson};
const ORANGE_SW_BASE = {$baseJson};
const ORANGE_SW_PRECACHE = {$urlsJson};

function orangeSwAssetPrefix() {
    return ORANGE_SW_BASE ? ORANGE_SW_BASE + '/assets/' : '/assets/';
}

function orangeSwIsStorefrontAssetRequest(url) {
    try {
        var u = new URL(url);
        return u.pathname.indexOf(orangeSwAssetPrefix()) === 0;
    } catch (e) {
        return false;
    }
}

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(ORANGE_SW_CACHE).then(function (cache) {
            return Promise.all(
                ORANGE_SW_PRECACHE.map(function (assetUrl) {
                    return fetch(assetUrl, { credentials: 'same-origin' })
                        .then(function (res) {
                            if (res && res.ok) {
                                return cache.put(assetUrl, res);
                            }
                        })
                        .catch(function () {});
                })
            );
        })
    );
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches
            .keys()
            .then(function (keys) {
                return Promise.all(
                    keys.map(function (key) {
                        if (key.indexOf('orange-sf-') === 0 && key !== ORANGE_SW_CACHE) {
                            return caches.delete(key);
                        }
                    })
                );
            })
            .then(function () {
                return self.clients.claim();
            })
    );
});

self.addEventListener('fetch', function (event) {
    if (event.request.method !== 'GET') {
        return;
    }
    if (event.request.mode === 'navigate') {
        return;
    }
    var url = event.request.url;
    if (!orangeSwIsStorefrontAssetRequest(url)) {
        return;
    }
    event.respondWith(
        caches.open(ORANGE_SW_CACHE).then(function (cache) {
            return cache.match(event.request, { ignoreSearch: false }).then(function (hit) {
                if (hit) {
                    return hit;
                }
                return fetch(event.request).then(function (res) {
                    var copy = res.clone();
                    if (res.ok) {
                        cache.put(event.request, copy);
                    }
                    return res;
                });
            });
        })
    );
});
JS;
