<?php

declare(strict_types=1);

require_once __DIR__ . '/order_helpers.php';
require_once __DIR__ . '/catalog_unified_product_helpers.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/product_preview.php';
require_once __DIR__ . '/variant_pricing.php';
require_once __DIR__ . '/storefront_api_errors.php';

/**
 * التحقق من بنود السلة وحساب المجموع الفرعي. عند checkout استخدم lockVariants=true داخل معاملة.
 *
 * @param array<int, array<string, mixed>> $items
 * @return array{0: float, 1: array<int, array<string, mixed>>}
 */
function orange_storefront_validate_cart_items_core(PDO $pdo, array $items, bool $lockVariants): array
{
    if (!is_array($items) || count($items) === 0) {
        orange_storefront_throw_customer('checkout_cart_items_required', 'Cart items are required');
    }

    $lockSql = $lockVariants ? ' FOR UPDATE' : '';
    $stockCountryId = orange_storefront_current_country_id($pdo);

    /* معاينة المنتج قبل النشر: نسمح بمسودّة المعاينة (is_active=0) في السلة لجلسة الأدمن صاحبها فقط،
       ونعاملها كمتوفّرة المخزون — الطلب نفسه محاكاة لا يُسجَّل (api/orders/create-order.php).
       محصور بصرامة: يتطلّب جلسة معاينة فعّالة ومطابقة مُعرّف المسودّة؛ بلا جلسة ⇒ السلوك الطبيعي تماماً. */
    $previewCtx = function_exists('orange_preview_active_context') ? orange_preview_active_context($pdo) : null;
    $previewDraftId = is_array($previewCtx) ? (int) ($previewCtx['draft_id'] ?? 0) : 0;

    $subtotal = 0.0;
    /** @var array<int, int> $variantQtyAccumulated */
    $variantQtyAccumulated = [];
    $validatedItems = [];

    foreach ($items as $item) {
        require_fields($item, ['id', 'qty']);

        $isPreviewDraftItem = ($previewDraftId > 0 && (int) $item['id'] === $previewDraftId);

        if ($isPreviewDraftItem) {
            $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_preview_draft = 1 LIMIT 1');
        } else {
            $productStmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        }
        $productStmt->execute([(int) $item['id']]);
        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            orange_storefront_throw_customer(
                'product_not_found',
                'Product not found: ' . (int) $item['id']
            );
        }
        if (!$isPreviewDraftItem) {
            if (
                orange_table_has_column($pdo, 'products', 'country_id')
                && (int) ($product['country_id'] ?? 0) !== $stockCountryId
            ) {
                orange_storefront_throw_customer(
                    'product_not_found',
                    'Product country mismatch id=' . (int) $product['id']
                );
            }
            if (!orange_storefront_product_in_active_unified_chain($pdo, (int) $product['id'])) {
                orange_storefront_throw_customer(
                    'product_not_found',
                    'Product inactive in unified chain id=' . (int) $product['id']
                );
            }
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
                orange_storefront_throw_customer(
                    'product_not_found',
                    'Variant not found product_id=' . (int) $product['id'] . ' variant_id=' . $variantIdIn
                );
            }

            $vId = (int) $variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            $available = $isPreviewDraftItem
                ? PHP_INT_MAX
                : orange_warehouse_effective_variant_stock($pdo, $vId, $stockCountryId);
            if ($available < $alreadyRequested + $qty) {
                orange_storefront_throw_customer(
                    'out_of_stock',
                    'Insufficient stock variant_id=' . $vId . ' available=' . $available
                );
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        } else {
            $vStmt = $pdo->prepare(
                'SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1' . $lockSql
            );
            $vStmt->execute([(int) $product['id']]);
            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
            if (!$variant) {
                orange_storefront_throw_customer(
                    'product_not_found',
                    'Default variant missing product_id=' . (int) $product['id']
                );
            }
            $vId = (int) $variant['id'];
            $alreadyRequested = $variantQtyAccumulated[$vId] ?? 0;
            $available = $isPreviewDraftItem
                ? PHP_INT_MAX
                : orange_warehouse_effective_variant_stock($pdo, $vId, $stockCountryId);
            if ($available < $alreadyRequested + $qty) {
                orange_storefront_throw_customer(
                    'out_of_stock',
                    'Insufficient stock variant_id=' . $vId . ' available=' . $available
                );
            }
            $variantQtyAccumulated[$vId] = $alreadyRequested + $qty;
        }

        $price = orange_variant_effective_price($product, $variant);
        $cost = orange_variant_effective_cost($product, $variant);
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
