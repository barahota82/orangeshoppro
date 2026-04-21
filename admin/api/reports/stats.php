<?php
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/stock_alerts.php';
require_admin_api('GET');

try {
    $pdo = db();
    $lowTh = orange_stock_low_alert_threshold();

    $ordersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $salesToday = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $productsCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $stLow = $pdo->prepare(
        'SELECT COUNT(*) FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         WHERE p.is_active = 1 AND pv.stock_quantity <= ?'
    );
    $stLow->execute([$lowTh]);
    $lowStockVariants = (int) $stLow->fetchColumn();
    $activeOffers = (int)$pdo->query("SELECT COUNT(*) FROM offers WHERE is_active = 1")->fetchColumn();

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
