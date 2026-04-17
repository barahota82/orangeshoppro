<?php
require_once __DIR__ . '/../../../config.php';
require_admin_api('GET');

try {
    $pdo = db();

    $ordersToday = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $salesToday = (float)$pdo->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
    $productsCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    $lowStockVariants = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE stock_quantity <= 3")->fetchColumn();
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
    api_error($e, 'تعذر تحميل الإحصائيات');
}
