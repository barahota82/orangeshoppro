<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_labels.php';
require_admin_api();

/**
 * @param array<int,array<string,mixed>> $variantRows
 * @return array<string,int>
 */
function orange_product_create_colorway_map(PDO $pdo, int $productId, array $variantRows, bool $hasColors): array
{
    $map = [];
    $sort = 0;
    $ins = $pdo->prepare(
        'INSERT INTO product_colorways (product_id, primary_color_id, secondary_color_id, sort_order, is_active)
         VALUES (?,?,?,?,1)'
    );

    foreach ($variantRows as $row) {
        $p = isset($row['primary_color_id']) ? (int)$row['primary_color_id'] : 0;
        $s = isset($row['secondary_color_id']) ? (int)$row['secondary_color_id'] : 0;
        if (!$hasColors) {
            $key = '-';
            if (!isset($map[$key])) {
                $ins->execute([$productId, null, null, $sort++]);
                $map[$key] = (int)$pdo->lastInsertId();
            }
            continue;
        }
        $p = $p > 0 ? $p : null;
        $s = $s > 0 ? $s : null;
        $key = ($p ?? 0) . ':' . ($s ?? 0);
        if (!isset($map[$key])) {
            $ins->execute([$productId, $p, $s, $sort++]);
            $map[$key] = (int)$pdo->lastInsertId();
        }
    }

    if (!$hasColors && !isset($map['-'])) {
        $ins->execute([$productId, null, null, 0]);
        $map['-'] = (int)$pdo->lastInsertId();
    }

    return $map;
}

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();

    if (empty($data['name']) || empty($data['category_id']) || !isset($data['price']) || !isset($data['cost'])) {
        json_response(['success' => false, 'message' => 'البيانات الأساسية مطلوبة'], 422);
    }

    $nameEn = trim((string)($data['name_en'] ?? ''));
    $nameFil = trim((string)($data['name_fil'] ?? ''));
    $nameHi = trim((string)($data['name_hi'] ?? ''));
    if ($nameEn === '' || $nameFil === '' || $nameHi === '') {
        json_response(['success' => false, 'message' => 'أسماء المنتج بلغات English / Filipino / Hindi مطلوبة'], 422);
    }

    $hasSizes = (int)($data['has_sizes'] ?? 0) === 1;
    $hasColors = (int)($data['has_colors'] ?? 0) === 1;

    $sizeFamilyId = isset($data['size_family_id']) ? (int)$data['size_family_id'] : 0;
    if ($sizeFamilyId <= 0) {
        $sizeFamilyId = null;
    }

    $scope = trim((string)($data['sizing_guide_scope'] ?? 'none'));
    $allowedScopes = ['none', 'upper', 'lower', 'both'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'none';
    }

    if ($hasSizes && $sizeFamilyId === null) {
        json_response(['success' => false, 'message' => 'يجب اختيار عائلة مقاسات عند تفعيل المقاسات'], 422);
    }

    $variantsIn = $data['variants'] ?? null;
    if (!is_array($variantsIn) || count($variantsIn) === 0) {
        json_response(['success' => false, 'message' => 'يجب توليد صفوف المتغيرات والمخزون'], 422);
    }

    if ($hasColors) {
        foreach ($variantsIn as $rv) {
            $rp = isset($rv['primary_color_id']) ? (int)$rv['primary_color_id'] : 0;
            if ($rp <= 0) {
                json_response(['success' => false, 'message' => 'كل متغير ملون يجب أن يحدد لوناً أساسياً من القاموس'], 422);
            }
        }
    }

    if ($hasSizes) {
        foreach ($variantsIn as $rv) {
            $z = isset($rv['size_family_size_id']) ? (int)$rv['size_family_size_id'] : 0;
            if ($z <= 0) {
                json_response(['success' => false, 'message' => 'كل متغير يجب أن يرتبط بمقاس من عائلة المقاسات'], 422);
            }
        }
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'INSERT INTO products (
            name, name_en, name_fil, name_hi, description, category_id, size_family_id, sizing_guide_scope, price, cost, main_image, has_sizes, has_colors, is_active, created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW()
        )'
    );

    $stmt->execute([
        trim((string)$data['name']),
        $nameEn,
        $nameFil,
        $nameHi,
        trim((string)($data['description'] ?? '')),
        (int)$data['category_id'],
        $sizeFamilyId,
        $scope,
        (float)$data['price'],
        (float)$data['cost'],
        trim((string)($data['main_image'] ?? '')),
        $hasSizes ? 1 : 0,
        $hasColors ? 1 : 0,
    ]);

    $productId = (int)$pdo->lastInsertId();

    $cwMap = orange_product_create_colorway_map($pdo, $productId, $variantsIn, $hasColors);

    $variantStmt = $pdo->prepare(
        'INSERT INTO product_variants (
            product_id, product_colorway_id, size_family_size_id, size, color, stock_quantity
        ) VALUES (?,?,?,?,?,?)'
    );
    $moveStmt = $pdo->prepare(
        "INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at
        ) VALUES (
            ?, ?, 'initial_stock', ?, 0, ?, 'Initial stock', NOW()
        )"
    );

    foreach ($variantsIn as $variant) {
        $p = isset($variant['primary_color_id']) ? (int)$variant['primary_color_id'] : 0;
        $s = isset($variant['secondary_color_id']) ? (int)$variant['secondary_color_id'] : 0;
        $szId = isset($variant['size_family_size_id']) ? (int)$variant['size_family_size_id'] : 0;
        $stock = (int)($variant['stock_quantity'] ?? 0);

        if (!$hasColors) {
            $cwKey = '-';
        } else {
            $p = $p > 0 ? $p : null;
            $s = $s > 0 ? $s : null;
            $cwKey = ($p ?? 0) . ':' . ($s ?? 0);
        }

        $colorwayId = $cwMap[$cwKey] ?? null;
        if ($colorwayId === null) {
            throw new RuntimeException('Missing colorway mapping');
        }

        $sizeFamilySizeId = $hasSizes && $szId > 0 ? $szId : null;

        $sizeRow = null;
        if ($sizeFamilySizeId !== null) {
            $szStmt = $pdo->prepare(
                'SELECT * FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1'
            );
            $szStmt->execute([$sizeFamilySizeId, $sizeFamilyId]);
            $sizeRow = $szStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$sizeRow) {
                throw new RuntimeException('Invalid size for selected family');
            }
        }

        $colorLabel = orange_colorway_display_label($pdo, $hasColors ? $p : null, $hasColors ? $s : null);
        $sizeLabel = orange_size_display_label($sizeRow);

        $variantStmt->execute([
            $productId,
            $colorwayId,
            $sizeFamilySizeId,
            $sizeLabel,
            $colorLabel,
            $stock,
        ]);

        $variantId = (int)$pdo->lastInsertId();
        $moveStmt->execute([$productId, $variantId, $stock, $stock]);
    }

    $pdo->commit();

    json_response(['success' => true, 'message' => 'تم حفظ المنتج بنجاح', 'product_id' => $productId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
