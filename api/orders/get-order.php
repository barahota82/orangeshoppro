<?php
require_once __DIR__ . '/../../config.php';

try {
    $pdo = db();

    $orderNumber = isset($_GET['order_number']) ? trim((string)$_GET['order_number']) : '';
    $phone = isset($_GET['phone']) ? trim((string)$_GET['phone']) : '';

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'message' => 'order_number and phone are required'], 422);
    }

    $stmt = $pdo->prepare("
        SELECT * FROM orders
        WHERE order_number = ? AND phone = ?
        LIMIT 1
    ");
    $stmt->execute([$orderNumber, $phone]);
    $order = $stmt->fetch();

    if (!$order) {
        json_response(['success' => false, 'message' => 'Order not found'], 404);
    }

    $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
    $itemsStmt->execute([(int)$order['id']]);
    $items = $itemsStmt->fetchAll();

    json_response([
        'success' => true,
        'order' => $order,
        'items' => $items
    ]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
