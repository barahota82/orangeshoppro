<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * @throws RuntimeException
 */
function orange_purchase_apply_variant_stock_increase(PDO $pdo, int $variantId, int $qty, ?int $countryId = null): void
{
    orange_purchase_apply_variant_stock_delta($pdo, $variantId, $qty, $countryId);
}

function orange_purchase_apply_variant_stock_decrease(PDO $pdo, int $variantId, int $qty, ?int $countryId = null): void
{
    if ($qty <= 0) {
        return;
    }
    orange_purchase_apply_variant_stock_delta($pdo, $variantId, -$qty, $countryId);
}

function orange_purchase_apply_variant_stock_delta(PDO $pdo, int $variantId, int $delta, ?int $countryId = null): void
{
    if ($variantId <= 0 || $delta === 0) {
        return;
    }
    require_once __DIR__ . '/countries.php';
    require_once __DIR__ . '/warehouses.php';
    if ($countryId === null || $countryId <= 0) {
        $countryId = orange_admin_context_country_id($pdo);
    }
    $warehouseId = orange_warehouse_default_id_for_country($pdo, $countryId);
    orange_warehouse_apply_variant_delta($pdo, $warehouseId, $variantId, $delta, 0);
}

/**
 * Resolve which product_variants row a purchase line updates (one variant only — correct stock).
 *
 * @throws RuntimeException
 */
function orange_purchase_resolve_variant_id(PDO $pdo, int $productId, int $requestedVariantId): int
{
    if ($productId <= 0) {
        throw new RuntimeException('معرّف منتج غير صالح');
    }

    $pStmt = $pdo->prepare('SELECT id, has_colors, has_sizes FROM products WHERE id = ? LIMIT 1');
    $pStmt->execute([$productId]);
    $p = $pStmt->fetch(PDO::FETCH_ASSOC);
    if (!$p) {
        throw new RuntimeException('المنتج غير موجود');
    }

    if ($requestedVariantId > 0) {
        $vStmt = $pdo->prepare(
            'SELECT id FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
        );
        $vStmt->execute([$requestedVariantId, $productId]);
        $vid = (int) $vStmt->fetchColumn();
        if ($vid <= 0) {
            throw new RuntimeException('المتغير (لون/مقاس) لا يتبع هذا المنتج');
        }

        return $vid;
    }

    $listStmt = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ? ORDER BY id ASC');
    $listStmt->execute([$productId]);
    $ids = $listStmt->fetchAll(PDO::FETCH_COLUMN);
    $ids = is_array($ids) ? array_map('intval', $ids) : [];
    if (count($ids) === 0) {
        throw new RuntimeException('لا توجد أصناف مخزون لهذا المنتج — أنشئ متغيرات من «المنتجات» أولًا');
    }

    // الحارس يعتمد على عدد المتغيّرات الفعلي لا على علمَي has_colors/has_sizes
    // (قد يُنشأ منتج بمتغيّرات متعددة وأعلام مطفأة عبر مسارات نسخ/استيراد، فلا نسمح بسطر بلا متغيّر محدّد).
    if (count($ids) > 1) {
        throw new RuntimeException('اختر المتغير (لون/مقاس) لهذا المنتج في سطر الشراء');
    }

    return (int) $ids[0];
}
