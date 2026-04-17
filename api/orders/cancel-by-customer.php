<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/order_stock.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    $orderNumber = trim((string)($data['order_number'] ?? ''));
    $phone = trim((string)($data['phone'] ?? ''));

    if ($orderNumber === '' || $phone === '') {
        json_response(['success' => false, 'code' => 'invalid_input'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = ? AND phone = ? LIMIT 1');
    $stmt->execute([$orderNumber, $phone]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        json_response(['success' => false, 'code' => 'not_found'], 404);
    }

    $st = strtolower(trim((string)($order['status'] ?? '')));

    if ($st === 'cancelled') {
        json_response([
            'success' => true,
            'code' => 'already_cancelled',
            'order' => $order,
        ]);
    }

    if (!in_array($st, ['pending', 'approved'], true)) {
        json_response(['success' => false, 'code' => 'cancel_not_allowed'], 403);
    }

    $pdo->beginTransaction();

    orange_order_release_pending_stock_reservation($pdo, $order);

    $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?")
        ->execute([(int)$order['id']]);

    $pdo->commit();

    $order['status'] = 'cancelled';

    json_response([
        'success' => true,
        'code' => 'cancelled',
        'order' => $order,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'code' => 'server_error', 'message' => $e->getMessage()], 500);
}
