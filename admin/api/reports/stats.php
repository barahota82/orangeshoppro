<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/stock_alerts.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/countries.php';
require_once __DIR__ . '/../../../includes/warehouses.php';
require_admin_api('GET');

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $lowTh = orange_stock_low_alert_threshold();
    $countryId = orange_admin_context_country_id($pdo);
    $ordersSql = orange_sql_country_and_fragment($pdo, 'orders', 'orders', $countryId);
    $productsSql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);
    $offersSql = orange_sql_country_and_fragment($pdo, 'offers', 'offers', $countryId);

    $ordersToday = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()" . $ordersSql
    )->fetchColumn();
    $salesToday = (float)$pdo->query(
        "SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE()" . $ordersSql
    )->fetchColumn();
    $pendingOrders = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status = 'pending'" . $ordersSql
    )->fetchColumn();
    $productsCount = (int)$pdo->query(
        'SELECT COUNT(*) FROM products WHERE 1=1' . orange_sql_country_and_fragment($pdo, 'products', 'products', $countryId)
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

    $activeOffers = (int)$pdo->query(
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
            'active_offers' => $activeOffers
        ]
    ]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل الإحصائيات');
}
