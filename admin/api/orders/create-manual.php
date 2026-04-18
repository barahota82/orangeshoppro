<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/order_helpers.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/order_fulfillment.php';
require_admin_api();

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    require_fields($data, ['customer_name', 'phone', 'channel_id', 'items']);
    if (!is_array($data['items']) || count($data['items']) === 0) {
        json_response(['success' => false, 'message' => 'أضف سطرًا واحدًا على الأقل للمنتجات'], 422);
    }

    $channelStmt = $pdo->prepare('SELECT id FROM channels WHERE id = ? AND is_active = 1 LIMIT 1');
    $channelStmt->execute([(int)$data['channel_id']]);
    if (!$channelStmt->fetchColumn()) {
        json_response(['success' => false, 'message' => 'قناة غير صالحة'], 422);
    }

    $pdo->beginTransaction();

    $orderNumber = generate_order_number();
    $total = 0.0;
    $validatedItems = [];

    foreach ($data['items'] as $item) {
        require_fields($item, ['product_id', 'qty']);
        $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $productStmt->execute([(int)$item['product_id']]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('منتج غير موجود: ' . (int)$item['product_id']);
        }

        $qty = max(1, (int)$item['qty']);
        $variantIdIn = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;
        $color = isset($item['color']) ? trim((string)$item['color']) : '';
        $size = isset($item['size']) ? trim((string)$item['size']) : '';

        if ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1) {
            $variant = null;
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
                );
                $vStmt->execute([$variantIdIn, (int)$product['id']]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants
                    WHERE product_id = ? AND color = ? AND size = ?
                    LIMIT 1'
                );
                $variantStmt->execute([(int)$product['id'], $color, $size]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                throw new RuntimeException('لم يُعثر على متغير للمنتج: ' . $product['name']);
            }
            if ((int)$variant['stock_quantity'] < $qty) {
                throw new RuntimeException('مخزون غير كافٍ: ' . $product['name']);
            }
        } else {
            $variant = null;
        }

        $price = (float)$product['price'];
        $cost = (float)$product['cost'];
        $total += $price * $qty;

        $validatedItems[] = [
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string)$variant['color'] : $color,
            'size' => $variant ? (string)$variant['size'] : $size,
            'variant_id' => $variant ? (int)$variant['id'] : 0,
            'price' => $price,
            'cost' => $cost,
        ];
    }

    $paymentTerms = orange_normalize_payment_terms($data['payment_terms'] ?? 'cash');
    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');

    $cols = 'order_number, customer_name, phone, area, address, notes, channel_id, status, total';
    $ph = '?, ?, ?, ?, ?, ?, ?, \'completed\', ?';
    $params = [
        $orderNumber,
        trim((string)$data['customer_name']),
        trim((string)$data['phone']),
        trim((string)($data['area'] ?? '')),
        trim((string)($data['address'] ?? '')),
        trim((string)($data['notes'] ?? '')),
        (int)$data['channel_id'],
        $total,
    ];
    if ($hasSource) {
        $cols .= ', order_source';
        $ph .= ', ?';
        $params[] = 'company';
    }
    if ($hasPay) {
        $cols .= ', payment_terms';
        $ph .= ', ?';
        $params[] = $paymentTerms;
    }
    $cols .= ', created_at';
    $ph .= ', NOW()';

    $orderStmt = $pdo->prepare("INSERT INTO orders ($cols) VALUES ($ph)");
    $orderStmt->execute($params);

    $orderId = (int)$pdo->lastInsertId();

    $colsStmt = $pdo->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_items'"
    );
    $oiCols = $colsStmt ? $colsStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $oiCols = is_array($oiCols) ? $oiCols : [];
    $hasVariantCol = in_array('variant_id', $oiCols, true);

    if ($hasVariantCol) {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, variant_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?,?)'
        );
    } else {
        $itemStmt = $pdo->prepare(
            'INSERT INTO order_items (
                order_id, product_id, product_name, color, size, qty, price, cost
            ) VALUES (?,?,?,?,?,?,?,?)'
        );
    }

    foreach ($validatedItems as $row) {
        if ($hasVariantCol) {
            $itemStmt->execute([
                $orderId,
                (int)$row['product']['id'],
                (int)($row['variant_id'] ?? 0) ?: null,
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        } else {
            $itemStmt->execute([
                $orderId,
                (int)$row['product']['id'],
                $row['product']['name'],
                $row['color'],
                $row['size'],
                $row['qty'],
                $row['price'],
                $row['cost'],
            ]);
        }
    }

    orange_complete_order_fulfillment($pdo, $orderId);

    $pdo->commit();

    json_response([
        'success' => true,
        'message' => 'تم تسجيل فاتورة الشركة',
        'order_id' => $orderId,
        'order_number' => $orderNumber,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
