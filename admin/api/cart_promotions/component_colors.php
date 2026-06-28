<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/storefront_variant_picker.php';
require_admin_api();

/*
 * يُعيد ألوان منتج (مفتاح اللون كما تستخدمه الواجهة + تسمية عرض) لاستخدامه في
 * منتقي «الألوان المسموحة لكل مكوّن» بشاشتي الكومبو وBOGO. تقييد عرض فقط؛
 * لا يُغيّر أهلية الخصم (المطابقة تبقى بالمنتج والكمية).
 */
try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $productId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($productId <= 0) {
        json_response(['success' => false, 'message' => 'معرّف منتج غير صالح'], 422);
    }

    try {
        orange_admin_assert_entity_country($pdo, 'products', $productId);
    } catch (RuntimeException $e) {
        json_response(['success' => false, 'message' => $e->getMessage()], 403);
    }

    $countryId = orange_cart_promotion_admin_country_id($pdo);
    $view = orange_storefront_product_variant_view($pdo, $productId, (int) ($countryId ?? 0), 'ar');

    $colors = [];
    foreach (($view['colors'] ?? []) as $col) {
        $key = (string) ($col['key'] ?? '');
        if ($key === '') {
            continue;
        }
        $label = trim((string) ($col['color'] ?? ''));
        $pattern = trim((string) ($col['pattern'] ?? ''));
        if ($pattern !== '') {
            $label = $label !== '' ? ($label . ' — ' . $pattern) : $pattern;
        }
        if ($label === '') {
            $label = $key;
        }
        $colors[] = ['key' => $key, 'label' => $label];
    }

    json_response([
        'success' => true,
        'product_id' => $productId,
        'has_colors' => (int) ($view['has_colors'] ?? 0),
        'colors' => $colors,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل ألوان المنتج');
}
