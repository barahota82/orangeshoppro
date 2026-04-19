<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';

try {
    $pdo = db();

    $orderNumber = isset($_GET['order_number']) ? trim((string) $_GET['order_number']) : '';
    $phone = isset($_GET['phone']) ? trim((string) $_GET['phone']) : '';

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'message' => 'order_number and phone are required'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? LIMIT 1');
    $stmt->execute([$orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order || !orange_order_phones_match_for_lookup($phone, (string) ($order['phone'] ?? ''))) {
        json_response(['success' => false, 'message' => 'Order not found'], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC');
    $itemsStmt->execute([(int) $order['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    json_response([
        'success' => true,
        'order' => $order,
        'items' => $items,
    ]);
} catch (Throwable $e) {
    api_error($e, 'Lookup failed');
}
