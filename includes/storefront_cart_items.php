<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/catalog_unified_product_helpers.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/countries.php';

/**
 * التحقق من بنود السلة وحساب المجموع الفرعي. عند checkout استخدم lockVariants=true داخل معاملة.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{0: float, 1: array<int, array<string, mixed>>}
 */
function orange_storefront_validate_cart_items_core(PDO $pdo, array $items, bool $lockVariants): array
{
    if (!is_array($items) || count($items) === 0) {
        throw new RuntimeException('Cart items are required');
    }

    $lockSql = $lockVariants ? ' FOR UPDATE' : '';
    $stockCountryId = orange_storefront_current_country_id($pdo);

    $subtotal = 0.0;
    /** @var array<int, int> $variantQtyAccumulated */
    $variantQtyAccumulated = [];
    $validatedItems = [];

    foreach ($items as $item) {
        require_fields($item, ['id', 'qty']);

        $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $productStmt->execute([(int) $item['id']]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException('Product not found: ' . (int) $item['id']);
        }
        if (
            orange_table_has_column($pdo, 'products', 'country_id')
            && (int) ($product['country_id'] ?? 0) !== $stockCountryId
        ) {
            throw new RuntimeException(function_exists('t') ? t('product_not_found') : 'Product not available');
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, (int) $product['id'])) {
            throw new RuntimeException(function_exists('t') ? t('product_not_found') : 'Product not available');
        }

        $qty = max(1, (int) $item['qty']);
        $color = isset($item['color']) ? trim((string) $item['color']) : '';
        $size = isset($item['size']) ? trim((string) $item['size']) : '';
        $variantIdIn = isset($item['variant_id']) ? (int) $item['variant_id'] : 0;

        if ((int) $product['has_colors'] === 1 || (int) $product['has_sizes'] === 1) {
            $variant = null;
            if ($variantIdIn > 0) {
                $vStmt = $pdo->prepare(
                    'SELECT * FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1' . $lockSql
                );
                $vStmt->execute([$variantIdIn, (int) $product['id']]);
                $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant) {
                $variantStmt = $pdo->prepare(
                    'SELECT * FROM product_variants
                    WHERE product_id = ? AND color = ? AND size = ?
                    LIMIT 1' . $lockSql
                );
                $variantStmt->execute([(int) $product['id'], $color, $size]);
                $variant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            }

            if (!$variant) {
                throw new RuntimeException('Variant not found for product: ' . $product['name']);
            }

            $vId = (int) $variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            $available = orange_warehouse_effective_variant_stock($pdo, $vId, $stockCountryId);
            if ($available < $alreadyRequested + $qty) {
                throw new RuntimeException('Insufficient stock for product: ' . $product['name']);
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        } else {
            $vStmt = $pdo->prepare(
                'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1' . $lockSql
            );
            $vStmt->execute([(int) $product['id']]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant) {
                throw new RuntimeException('Variant not found for product: ' . $product['name']);
            }
            $vId = (int) $variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            $available = orange_warehouse_effective_variant_stock($pdo, $vId, $stockCountryId);
            if ($available < $alreadyRequested + $qty) {
                throw new RuntimeException('Insufficient stock for product: ' . $product['name']);
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        }

        $price = (float) $product['price'];
        $cost = (float) $product['cost'];
        $lineTotal = $price * $qty;
        $subtotal += $lineTotal;

        $validatedItems[] = [
            'product' => $product,
            'qty' => $qty,
            'color' => $variant ? (string) $variant['color'] : $color,
            'size' => $variant ? (string) $variant['size'] : $size,
            'variant_id' => $variant ? (int) $variant['id'] : 0,
            'price' => $price,
            'cost' => $cost,
        ];
    }

    return [$subtotal, $validatedItems];
}
