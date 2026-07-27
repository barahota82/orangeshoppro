<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/stock_alerts.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_once __DIR__ . '/../../../includes/product_preview.php';
require_once __DIR__ . '/../../../includes/admin_time.php';
require_admin_api('GET');

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $countryId = orange_admin_context_country_id($pdo);
    $lowTh = orange_stock_low_alert_threshold($pdo, $countryId);
    $ordersSql = orange_sql_country_and_fragment($pdo, 'orders', 'orders', $countryId);
    $productsSql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $offersSql = orange_sql_country_and_fragment($pdo, 'offers', 'offers', $countryId);

    // Absolute Moment Today: Current Country Context IANA civil day (half-open UTC).
    // Matches admin/pages/dashboard.php bounds contract.
    $ordersToday = 0;
    $salesToday = 0.0;
    if ($countryId > 0) {
        $todayYmd = orange_admin_time_document_date_today_for_country_id($pdo, $countryId);
        $dayBounds = orange_admin_time_day_bounds_mysql_utc(
            $todayYmd,
            orange_admin_time_timezone_for_country_id($pdo, $countryId)
        );
        $stOrdToday = $pdo->prepare(
            'SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ?' . $ordersSql
        );
        $stOrdToday->execute([
            $dayBounds['start_utc_mysql'],
            $dayBounds['end_exclusive_utc_mysql'],
        ]);
        $ordersToday = (int) $stOrdToday->fetchColumn();
        $stSalesToday = $pdo->prepare(
            'SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at >= ? AND created_at < ?' . $ordersSql
        );
        $stSalesToday->execute([
            $dayBounds['start_utc_mysql'],
            $dayBounds['end_exclusive_utc_mysql'],
        ]);
        $salesToday = (float) $stSalesToday->fetchColumn();
    }

    $pendingOrders = (int) $pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'pending'" . $ordersSql
    )->fetchColumn();
    $productsCount = (int) $pdo->query(
        'SELECT COUNT(*) FROM products WHERE 1=1'
        . orange_sql_country_and_fragment($pdo, 'products', 'products', $countryId)
        . orange_preview_hide_sql($pdo, 'products')
    )->fetchColumn();

    $wQty = orange_warehouse_effective_qty_sql($pdo, $countryId, 'pv', 'wvs_rep');
    $stLow = $pdo->prepare(
        'SELECT COUNT(*) FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id'
        . $wQty['join']
        . ' WHERE p.is_active = 1 AND ' . $wQty['expr'] . ' <= ?' . $productsSql
    );
    $stLow->execute([$lowTh]);
    $lowStockVariants = (int) $stLow->fetchColumn();

    $activeOffers = (int) $pdo->query(
        'SELECT COUNT(*) FROM offers WHERE is_active = 1' . $offersSql
    )->fetchColumn();

    json_response([
        'success' => true,
        'stats' => [
            'orders_today' => $ordersToday,
            'sales_today' => round($salesToday, 2),
            'pending_orders' => $pendingOrders,
            'products_count' => $productsCount,
            'low_stock_variants' => $lowStockVariants,
            'active_offers' => $activeOffers,
        ],
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل الإحصائيات');
}
