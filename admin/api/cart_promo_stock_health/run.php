<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/cart_promo_stock_health.php';
require_once __DIR__ . '/../../../includes/cart_promotion_country.php';
require_once __DIR__ . '/../../../includes/admin_permissions.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    /** @var array<string,mixed> $admin */
    $admin = $_SESSION['admin'] ?? [];
    if (!orange_admin_may($admin, $pdo, 'products', 'view')) {
        json_response(['success' => false, 'message' => 'غير مصرح'], 403);
    }

    $data = get_json_input();
    $allCountries = !empty($data['all_countries']);
    $countryId = $allCountries ? null : orange_cart_promotion_admin_country_id($pdo);

    $report = orange_cart_promo_run_stock_health($pdo, $countryId, null);

    $pausedNow = count($report['paused']);
    $resumedNow = (int) ($report['resumed'] ?? 0);
    $parts = ['تم فحص ' . (int) $report['checked'] . ' قاعدة'];
    if ($resumedNow > 0) {
        $parts[] = 'أُعيد تفعيل ' . $resumedNow . ' عرضاً (عودة المخزون)';
    }
    if ($pausedNow > 0) {
        $parts[] = 'أُوقف ' . $pausedNow . ' عرضاً بسبب المخزون';
    } elseif ($resumedNow === 0) {
        $parts[] = 'لا إيقاف ولا إعادة تفعيل جديدة';
    }
    $msg = implode(' — ', $parts) . '.';

    json_response([
        'success' => true,
        'message' => $msg,
        'report' => $report,
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر فحص مخزون العروض');
}
