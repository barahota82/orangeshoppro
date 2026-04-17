<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    if (!isset($data['items']) || !is_array($data['items'])) {
        json_response(['success' => false, 'message' => 'items required'], 422);
    }

    $limits = [];
    foreach ($data['items'] as $item) {
        $pid = (int)($item['id'] ?? 0);
        if ($pid <= 0) {
            $limits[] = 0;
            continue;
        }

        $pStmt = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $pStmt->execute([$pid]);
        if (!$pStmt->fetch()) {
            $limits[] = 0;
            continue;
        }

        $vid = (int)($item['variant_id'] ?? 0);
        $stock = 0;

        if ($vid > 0) {
            $stmt = $pdo->prepare(
                'SELECT stock_quantity FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
            );
            $stmt->execute([$vid, $pid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stock = $row ? max(0, (int)$row['stock_quantity']) : 0;
        } else {
            $color = isset($item['color']) ? trim((string)$item['color']) : '';
            $size = isset($item['size']) ? trim((string)$item['size']) : '';
            $stmt = $pdo->prepare(
                'SELECT stock_quantity FROM product_variants
                 WHERE product_id = ? AND color = ? AND size = ?
                 LIMIT 1'
            );
            $stmt->execute([$pid, $color, $size]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stock = max(0, (int)$row['stock_quantity']);
            } else {
                $one = $pdo->prepare(
                    'SELECT stock_quantity FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1'
                );
                $one->execute([$pid]);
                $r2 = $one->fetch(PDO::FETCH_ASSOC);
                $stock = $r2 ? max(0, (int)$r2['stock_quantity']) : 0;
            }
        }

        $limits[] = $stock;
    }

    json_response(['success' => true, 'limits' => $limits]);
} catch (Throwable $e) {
    json_response([
        'success' => false,
        'message' => $e->getMessage(),
    ], 500);
}
