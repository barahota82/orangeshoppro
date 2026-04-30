<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_labels.php';

/**
 * @param array<int,array<string,mixed>> $variantRows
 * @return array<string,int> colorway fingerprint => product_colorways.id (find-or-insert)
 */
function orange_product_ensure_colorways(PDO $pdo, int $productId, array $variantRows, bool $hasColors): array
{
    $map = [];
    foreach ($variantRows as $row) {
        $p = isset($row['primary_color_id']) ? (int) $row['primary_color_id'] : 0;
        $s = isset($row['secondary_color_id']) ? (int) $row['secondary_color_id'] : 0;
        $pp = isset($row['primary_pattern_id']) ? (int) $row['primary_pattern_id'] : 0;
        $sp = isset($row['secondary_pattern_id']) ? (int) $row['secondary_pattern_id'] : 0;
        if (!$hasColors) {
            continue;
        }
        $pk = (($p > 0 ? $p : 0)) . ':' . (($s > 0 ? $s : 0)) . ':' . (($pp > 0 ? $pp : 0)) . ':' . (($sp > 0 ? $sp : 0));
        $map[$pk] = true;
    }

    $out = [];
    $stMax = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_colorways WHERE product_id = ?');
    $stMax->execute([$productId]);
    $sort = ((int) $stMax->fetchColumn()) + 1;

    if (!$hasColors) {
        $sel = $pdo->prepare(
            'SELECT id FROM product_colorways WHERE product_id = ? AND primary_color_id IS NULL AND secondary_color_id IS NULL
             AND primary_pattern_id IS NULL AND secondary_pattern_id IS NULL ORDER BY sort_order ASC, id ASC LIMIT 1'
        );
        $sel->execute([$productId]);
        $rid = $sel->fetchColumn();
        if ($rid !== false && $rid !== null) {
            $out['-'] = (int) $rid;
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO product_colorways (product_id, primary_color_id, secondary_color_id, primary_pattern_id, secondary_pattern_id, sort_order, is_active)
                 VALUES (?,?,?,?,?,?,1)'
            );
            $ins->execute([$productId, null, null, null, null, $sort++]);
            $out['-'] = (int) $pdo->lastInsertId();
        }

        return $out;
    }

    $find = $pdo->prepare(
        'SELECT id FROM product_colorways WHERE product_id = ?
         AND primary_color_id <=> ? AND secondary_color_id <=> ?
         AND primary_pattern_id <=> ? AND secondary_pattern_id <=> ?
         ORDER BY sort_order ASC, id ASC LIMIT 1'
    );
    $ins = $pdo->prepare(
        'INSERT INTO product_colorways (product_id, primary_color_id, secondary_color_id, primary_pattern_id, secondary_pattern_id, sort_order, is_active)
         VALUES (?,?,?,?,?,?,1)'
    );

    foreach ($map as $pk => $_) {
        $parts = array_map(static fn ($x): int => (int) $x, explode(':', $pk, 4));
        $p = $parts[0] > 0 ? $parts[0] : null;
        $s = $parts[1] > 0 ? $parts[1] : null;
        $pp = $parts[2] > 0 ? $parts[2] : null;
        $sp = $parts[3] > 0 ? $parts[3] : null;

        $find->execute([$productId, $p, $s, $pp, $sp]);
        $rid = $find->fetchColumn();
        if ($rid !== false && $rid !== null) {
            $out[$pk] = (int) $rid;

            continue;
        }
        $ins->execute([$productId, $p, $s, $pp, $sp, $sort++]);
        $out[$pk] = (int) $pdo->lastInsertId();
    }

    return $out;
}

/**
 * @param array<string,mixed> $variant
 */
function orange_product_variant_cw_row_key(array $variant, bool $hasColors): string
{
    if (!$hasColors) {
        return '-';
    }
    $p = isset($variant['primary_color_id']) ? (int) $variant['primary_color_id'] : 0;
    $s = isset($variant['secondary_color_id']) ? (int) $variant['secondary_color_id'] : 0;
    $pp = isset($variant['primary_pattern_id']) ? (int) $variant['primary_pattern_id'] : 0;
    $sp = isset($variant['secondary_pattern_id']) ? (int) $variant['secondary_pattern_id'] : 0;

    return (($p > 0 ? $p : 0)) . ':' . (($s > 0 ? $s : 0)) . ':' . (($pp > 0 ? $pp : 0)) . ':' . (($sp > 0 ? $sp : 0));
}

/**
 * @param array<int,array<string,mixed>> $variantsIn
 */
function orange_product_sync_variants_matrix(
    PDO $pdo,
    int $productId,
    array $variantsIn,
    bool $hasColors,
    bool $hasSizes,
    ?int $sizeFamilyId
): void {
    $cwMap = orange_product_ensure_colorways($pdo, $productId, $variantsIn, $hasColors);

    $lst = $pdo->prepare(
        'SELECT v.id, v.stock_quantity, v.product_colorway_id, v.size_family_size_id,
                cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id
         FROM product_variants v
         LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
         WHERE v.product_id = ?'
    );
    $lst->execute([$productId]);
    $indexed = [];
    while ($rw = $lst->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($rw)) {
            continue;
        }
        $cwKeyDb = orange_product_db_row_colorway_key($rw, $hasColors);
        $sid = isset($rw['size_family_size_id']) && $rw['size_family_size_id'] !== null ? (int) $rw['size_family_size_id'] : 0;
        $fp = $cwKeyDb . '|' . $sid;
        $indexed[(string) $fp] = [
            'id' => (int) $rw['id'],
            'stock_quantity' => (int) $rw['stock_quantity'],
            'product_colorway_id' => isset($rw['product_colorway_id']) ? (int) $rw['product_colorway_id'] : null,
        ];
    }

    $insVar = $pdo->prepare(
        'INSERT INTO product_variants (
            product_id, product_colorway_id, size_family_size_id, size, color, stock_quantity
        ) VALUES (?,?,?,?,?,?)'
    );
    $movInitial = $pdo->prepare(
        "INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at
        ) VALUES (
            ?, ?, 'initial_stock', ?, 0, ?, 'Initial stock', NOW()
        )"
    );
    $movAdj = $pdo->prepare(
        "INSERT INTO stock_movements (
            product_id, variant_id, type, qty, old_stock, new_stock, reason, created_at
        ) VALUES (
            ?, ?, 'manual_adjustment', ?, ?, ?, ?, NOW()
        )"
    );
    $updVar = $pdo->prepare(
        'UPDATE product_variants SET product_colorway_id = ?, size_family_size_id = ?, size = ?, color = ?, stock_quantity = ? WHERE id = ? LIMIT 1'
    );

    foreach ($variantsIn as $variant) {
        $cwRowKey = orange_product_variant_cw_row_key($variant, $hasColors);
        $cwId = $cwMap[$cwRowKey] ?? null;
        if ($cwId === null) {
            throw new RuntimeException('Missing colorway mapping after ensure');
        }
        $p = isset($variant['primary_color_id']) ? (int) $variant['primary_color_id'] : 0;
        $s = isset($variant['secondary_color_id']) ? (int) $variant['secondary_color_id'] : 0;
        $pp = isset($variant['primary_pattern_id']) ? (int) $variant['primary_pattern_id'] : 0;
        $sp = isset($variant['secondary_pattern_id']) ? (int) $variant['secondary_pattern_id'] : 0;
        $pN = $hasColors && $p > 0 ? $p : null;
        $sN = $hasColors && $s > 0 ? $s : null;
        $ppN = $hasColors && $pp > 0 ? $pp : null;
        $spN = $hasColors && $sp > 0 ? $sp : null;

        $szId = isset($variant['size_family_size_id']) ? (int) $variant['size_family_size_id'] : 0;
        $stock = max(0, (int) ($variant['stock_quantity'] ?? 0));
        $sizeFamilySizeId = $hasSizes && $szId > 0 ? $szId : null;

        $sizeRow = null;
        if ($sizeFamilySizeId !== null && $sizeFamilyId !== null) {
            $szStmt = $pdo->prepare(
                'SELECT * FROM size_family_sizes WHERE id = ? AND size_family_id = ? LIMIT 1'
            );
            $szStmt->execute([$sizeFamilySizeId, $sizeFamilyId]);
            $sizeRow = $szStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$sizeRow) {
                throw new RuntimeException('Invalid size for selected family');
            }
        }
        $szLabel = orange_size_display_label($sizeRow);
        $colorLabel = orange_colorway_display_label(
            $pdo,
            $hasColors ? $pN : null,
            $hasColors ? $sN : null,
            $hasColors ? $ppN : null,
            $hasColors ? $spN : null,
            'ar'
        );

        $fpNew = ($hasColors ? $cwRowKey : '-') . '|' . (($sizeFamilySizeId !== null) ? (string) $sizeFamilySizeId : '0');

        $rowMatch = $indexed[(string) $fpNew] ?? null;
        if ($rowMatch !== null) {
            $vid = (int) $rowMatch['id'];
            $oldStock = (int) $rowMatch['stock_quantity'];
            $updVar->execute([$cwId, $sizeFamilySizeId, $szLabel, $colorLabel, $stock, $vid]);
            $delta = $stock - $oldStock;
            if ($delta !== 0) {
                $movAdj->execute([
                    $productId,
                    $vid,
                    $delta,
                    $oldStock,
                    $stock,
                    'تهيئة المتغيرات من تعديل المنتج في الأدمن',
                ]);
            }
            unset($indexed[(string) $fpNew]);
        } else {
            $insVar->execute([$productId, $cwId, $sizeFamilySizeId, $szLabel, $colorLabel, $stock]);
            $newId = (int) $pdo->lastInsertId();
            if ($stock > 0) {
                $movInitial->execute([$productId, $newId, $stock, $stock]);
            }
        }
    }

    foreach ($indexed as $fp => $remain) {
        $vid = (int) $remain['id'];
        $oldStock = (int) $remain['stock_quantity'];
        $mCntStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM stock_movements WHERE variant_id = ?'
        );
        $mCntStmt->execute([$vid]);
        $mCnt = (int) $mCntStmt->fetchColumn();
        if ($mCnt > 0) {
            if ($oldStock > 0) {
                $movAdj->execute([
                    $productId,
                    $vid,
                    -$oldStock,
                    $oldStock,
                    0,
                    'إزالة مجموعة من مصفوفة المتغيرات (الأدمن) — حفظ السجل التاريخي',
                ]);
            }
            $pdo->prepare('UPDATE product_variants SET stock_quantity = 0 WHERE id = ? LIMIT 1')->execute([$vid]);

            continue;
        }
        try {
            $pdo->prepare('DELETE FROM product_variants WHERE id = ? LIMIT 1')->execute([$vid]);
        } catch (Throwable $e) {
            if ($oldStock > 0) {
                $movAdj->execute([
                    $productId,
                    $vid,
                    -$oldStock,
                    $oldStock,
                    0,
                    'تعذر حذف المتغير — تصفير المخزون',
                ]);
            }
            $pdo->prepare('UPDATE product_variants SET stock_quantity = 0 WHERE id = ? LIMIT 1')->execute([$vid]);
        }
    }

    orange_product_prune_unused_colorways($pdo, $productId);
}

/**
 * @param array<string,mixed> $rw
 */
function orange_product_db_row_colorway_key(array $rw, bool $hasColors): string
{
    if (!$hasColors) {
        return '-';
    }
    $pc = isset($rw['primary_color_id']) ? (int) $rw['primary_color_id'] : 0;
    $sc = isset($rw['secondary_color_id']) ? (int) $rw['secondary_color_id'] : 0;
    $pp = isset($rw['primary_pattern_id']) ? (int) $rw['primary_pattern_id'] : 0;
    $sp = isset($rw['secondary_pattern_id']) ? (int) $rw['secondary_pattern_id'] : 0;

    return (($pc > 0 ? $pc : 0)) . ':' . (($sc > 0 ? $sc : 0)) . ':' . (($pp > 0 ? $pp : 0)) . ':' . (($sp > 0 ? $sp : 0));
}

function orange_product_prune_unused_colorways(PDO $pdo, int $productId): void
{
    if (!orange_table_exists($pdo, 'product_colorways')) {
        return;
    }
    $orph = $pdo->prepare(
        'SELECT pc.id FROM product_colorways pc
         LEFT JOIN product_variants pv ON pv.product_colorway_id = pc.id
         WHERE pc.product_id = ? AND pv.id IS NULL'
    );
    $orph->execute([$productId]);
    $del = $pdo->prepare('DELETE FROM product_colorways WHERE id = ? LIMIT 1');
    while (($oid = $orph->fetchColumn()) !== false) {
        try {
            $del->execute([(int) $oid]);
        } catch (Throwable $e) {
            continue;
        }
    }
}
