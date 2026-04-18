<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/order_helpers.php';
require_once __DIR__ . '/../../includes/order_stock.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    require_fields($data, ['name', 'phone', 'area', 'address', 'channel_id', 'items']);

    if (!is_array($data['items']) || count($data['items']) === 0) {
        json_response(['success' => false, 'message' => 'Cart items are required'], 422);
    }

    $channelStmt = $pdo->prepare("SELECT * FROM channels WHERE id = ? AND is_active = 1 LIMIT 1");
    $channelStmt->execute([(int)$data['channel_id']]);
    $channel = $channelStmt->fetch();

    if (!$channel) {
        json_response(['success' => false, 'message' => 'Invalid channel'], 422);
    }

    $pdo->beginTransaction();

    $orderNumber = generate_order_number();
    $total = 0.0;

    /** @var array<int,int> */
    $variantQtyAccumulated = [];

    $validatedItems = [];
    foreach ($data['items'] as $item) {
        require_fields($item, ['id', 'qty']);

        $productStmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1");
        $productStmt->execute([(int)$item['id']]);
        $product = $productStmt->fetch();

        if (!$product) {
            throw new RuntimeException('Product not found: ' . (int)$item['id']);
        }

        $qty = max(1, (int)$item['qty']);
        $color = isset($item['color']) ? trim((string)$item['color']) : '';
        $size = isset($item['size']) ? trim((string)$item['size']) : '';
        $variantIdIn = isset($item['variant_id']) ? (int)$item['variant_id'] : 0;

        if ((int)$product['has_colors'] === 1 || (int)$product['has_sizes'] === 1) {
            $variant = null;
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1 FOR UPDATE'
                );
                $vStmt->execute([$variantIdIn, (int)$product['id']]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants
                    WHERE product_id = ? AND color = ? AND size = ?
                    LIMIT 1 FOR UPDATE'
                );
                $variantStmt->execute([(int)$product['id'], $color, $size]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$variant) {
                throw new RuntimeException('Variant not found for product: ' . $product['name']);
            }

            $vId = (int)$variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            if ((int)$variant['stock_quantity'] < $alreadyRequested + $qty) {
                throw new RuntimeException('Insufficient stock for product: ' . $product['name']);
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        } else {
            $vStmt = $pdo->prepare(
                'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1 FOR UPDATE'
            );
            $vStmt->execute([(int)$product['id']]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant) {
                throw new RuntimeException('Variant not found for product: ' . $product['name']);
            }
            $vId = (int)$variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            if ((int)$variant['stock_quantity'] < $alreadyRequested + $qty) {
                throw new RuntimeException('Insufficient stock for product: ' . $product['name']);
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        }

        $price = (float)$product['price'];
        $cost = (float)$product['cost'];
        $lineTotal = $price * $qty;
        $total += $lineTotal;

        $validatedItems[] = [
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string)$variant['color'] : $color,
            'size' => $variant ? (string)$variant['size'] : $size,
            'variant_id' => $variant ? (int)$variant['id'] : 0,
            'price' => $price,
            'cost' => $cost
        ];
    }

    // طلبات المتجر الإلكتروني تُسجَّل دائمًا كبيع نقدي (سياسة المشروع).
    $paymentTerms = 'cash';
    $hasSource = orange_table_has_column($pdo, 'orders', 'order_source');
    $hasPay = orange_table_has_column($pdo, 'orders', 'payment_terms');

    $cols = 'order_number, customer_name, phone, area, address, notes, channel_id, status, total';
    $ph = '?, ?, ?, ?, ?, ?, ?, \'pending\', ?';
    $params = [
        $orderNumber,
        trim((string)$data['name']),
        trim((string)$data['phone']),
        trim((string)$data['area']),
        trim((string)$data['address']),
        isset($data['notes']) ? trim((string)$data['notes']) : '',
        (int)$data['channel_id'],
        $total,
    ];
    if ($hasSource) {
        $cols .= ', order_source';
        $ph .= ', ?';
        $params[] = 'website';
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

    orange_order_apply_pending_stock_reservation($pdo, $orderNumber, $validatedItems);

    $pdo->commit();

    $messageLines = [];
    $messageLines[] = "Order Number: {$orderNumber}";
    $messageLines[] = "Customer: " . trim((string)$data['name']);
    $messageLines[] = "Phone: " . trim((string)$data['phone']);
    $messageLines[] = "Area: " . trim((string)$data['area']);
    $messageLines[] = "Address: " . trim((string)$data['address']);
    if (!empty($data['notes'])) {
        $messageLines[] = "Notes: " . trim((string)$data['notes']);
    }
    $messageLines[] = "";
    $messageLines[] = "Items:";
    foreach ($validatedItems as $idx => $row) {
        $messageLines[] = ($idx + 1) . ") " . $row['product']['name'];
        if ($row['color'] !== '') {
            $messageLines[] = "   Color: " . $row['color'];
        }
        if ($row['size'] !== '') {
            $messageLines[] = "   Size: " . $row['size'];
        }
        $messageLines[] = "   Qty: " . $row['qty'];
        $messageLines[] = "   Price: " . number_format($row['price'], 2) . " KD";
    }
    $messageLines[] = "";
    $messageLines[] = 'Payment: ' . ($paymentTerms === 'credit' ? 'Credit / آجل' : 'Cash / نقدي');
    $messageLines[] = "Total: " . number_format($total, 2) . " KD";

    $whatsAppNumber = clean_whatsapp_number((string)$channel['whatsapp_number']);
    $whatsAppUrl = "https://wa.me/{$whatsAppNumber}?text=" . rawurlencode(implode("\n", $messageLines));

    json_response([
        'success' => true,
        'order_id' => $orderId,
        'order_number' => $orderNumber,
        'total' => $total,
        'whatsapp_number' => $whatsAppNumber,
        'whatsapp_url' => $whatsAppUrl
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    json_response([
        'success' => false,
        'message' => $e->getMessage()
    ], 500);
}
