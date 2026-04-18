<?php

declare(strict_types=1);

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

    $multi = (int) $p['has_colors'] === 1 || (int) $p['has_sizes'] === 1;
    if ($multi && count($ids) > 1) {
        throw new RuntimeException('اختر المتغير (لون/مقاس) لهذا المنتج في سطر الشراء');
    }

    return (int) $ids[0];
}
