<?php

declare(strict_types=1);

/**
 * Product hard-delete policy (ORANGE_STOCK_ORDER_POLICY §16).
 * Hard delete only when there is no business/historical footprint; otherwise block and use toggle (is_active=0).
 */

/**
 * @return list<string> Machine-readable block reasons; empty = hard delete allowed.
 */
function orange_product_delete_history_block_reasons(PDO $pdo, int $productId): array
{
    if ($productId <= 0) {
        return ['invalid_product_id'];
    }

    $checks = [
        'order_items' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'order_items')) {
                return false;
            }
            $st = $pdo->prepare('SELECT 1 FROM order_items WHERE product_id = ? LIMIT 1');

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'purchase_items' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'purchase_items')) {
                return false;
            }
            $st = $pdo->prepare('SELECT 1 FROM purchase_items WHERE product_id = ? LIMIT 1');

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'purchase_return_items' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'purchase_return_items')) {
                return false;
            }
            $st = $pdo->prepare('SELECT 1 FROM purchase_return_items WHERE product_id = ? LIMIT 1');

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'sales_return_items' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'sales_return_items')) {
                return false;
            }
            $st = $pdo->prepare('SELECT 1 FROM sales_return_items WHERE product_id = ? LIMIT 1');

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'stock_movements' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'stock_movements')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM stock_movements sm
                 WHERE sm.product_id = ?
                    OR sm.variant_id IN (SELECT pv.id FROM product_variants pv WHERE pv.product_id = ?)
                 LIMIT 1'
            );

            return $st->execute([$productId, $productId]) && (bool) $st->fetchColumn();
        },
        'warehouse_variant_stock' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'warehouse_variant_stock') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM warehouse_variant_stock wvs
                 INNER JOIN product_variants pv ON pv.id = wvs.variant_id
                 WHERE pv.product_id = ? AND wvs.quantity <> 0
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'inventory_cost_layers' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'inventory_cost_layers') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM inventory_cost_layers icl
                 INNER JOIN product_variants pv ON pv.id = icl.variant_id
                 WHERE pv.product_id = ?
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'inventory_cost_consumptions' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'inventory_cost_consumptions') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM inventory_cost_consumptions icc
                 INNER JOIN product_variants pv ON pv.id = icc.variant_id
                 WHERE pv.product_id = ?
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'inventory_reconciliation_line' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'inventory_reconciliation_line') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM inventory_reconciliation_line irl
                 INNER JOIN product_variants pv ON pv.id = irl.variant_id
                 WHERE pv.product_id = ?
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'stock_adjustment_voucher_line' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'stock_adjustment_voucher_line') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM stock_adjustment_voucher_line sl
                 INNER JOIN product_variants pv ON pv.id = sl.variant_id
                 WHERE pv.product_id = ?
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
        'orders_gift_variants' => static function (PDO $pdo, int $productId): bool {
            if (!orange_table_exists($pdo, 'orders') || !orange_table_exists($pdo, 'product_variants')) {
                return false;
            }
            $hasGift = orange_table_has_column($pdo, 'orders', 'cart_gift_variant_id');
            $hasBogo = orange_table_has_column($pdo, 'orders', 'cart_bogo_gift_variant_id');
            if (!$hasGift && !$hasBogo) {
                return false;
            }
            $conds = [];
            if ($hasGift) {
                $conds[] = 'o.cart_gift_variant_id = pv.id';
            }
            if ($hasBogo) {
                $conds[] = 'o.cart_bogo_gift_variant_id = pv.id';
            }
            $st = $pdo->prepare(
                'SELECT 1 FROM orders o
                 INNER JOIN product_variants pv ON pv.product_id = ?
                 WHERE (' . implode(' OR ', $conds) . ')
                 LIMIT 1'
            );

            return $st->execute([$productId]) && (bool) $st->fetchColumn();
        },
    ];

    $blocked = [];
    foreach ($checks as $reason => $fn) {
        if ($fn($pdo, $productId)) {
            $blocked[] = $reason;
        }
    }

    return $blocked;
}

function orange_product_delete_history_block_message(): string
{
    return 'لا يمكن حذف المنتج لوجود حركات أو سجل تجاري. عطّل المنتج (غير نشط — is_active = 0) من شاشة المنتجات بدلاً من الحذف.';
}

/**
 * Hard-delete catalog-only rows. Caller must verify orange_product_delete_history_block_reasons() is empty first.
 */
function orange_product_delete_catalog_hard(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        throw new RuntimeException('معرف المنتج مطلوب');
    }

    $variantIds = [];
    if (orange_table_exists($pdo, 'product_variants')) {
        $stVar = $pdo->prepare('SELECT id FROM product_variants WHERE product_id = ?');
        $stVar->execute([$productId]);
        while (($vid = $stVar->fetchColumn()) !== false) {
            $variantIds[] = (int) $vid;
        }
    }

    if (orange_table_exists($pdo, 'offers')) {
        $pdo->prepare('DELETE FROM offers WHERE product_id = ?')->execute([$productId]);
    }
    if (orange_table_exists($pdo, 'product_channels')) {
        $pdo->prepare('DELETE FROM product_channels WHERE product_id = ?')->execute([$productId]);
    }
    if (orange_table_exists($pdo, 'product_images')) {
        $pdo->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
    }
    if (orange_table_exists($pdo, 'product_attribute_values')) {
        $pdo->prepare('DELETE FROM product_attribute_values WHERE product_id = ?')->execute([$productId]);
    }
    if (orange_table_exists($pdo, 'product_colorway_images') && orange_table_exists($pdo, 'product_colorways')) {
        $pdo->prepare(
            'DELETE pci FROM product_colorway_images pci
             INNER JOIN product_colorways cw ON cw.id = pci.product_colorway_id
             WHERE cw.product_id = ?'
        )->execute([$productId]);
    }
    if (orange_table_exists($pdo, 'product_colorways')) {
        $pdo->prepare('DELETE FROM product_colorways WHERE product_id = ?')->execute([$productId]);
    }

    if ($variantIds !== []) {
        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        if (orange_table_exists($pdo, 'inventory_cost_consumptions')) {
            $pdo->prepare(
                'DELETE FROM inventory_cost_consumptions WHERE variant_id IN (' . $placeholders . ')'
            )->execute($variantIds);
        }
        if (orange_table_exists($pdo, 'inventory_cost_layers')) {
            $pdo->prepare(
                'DELETE FROM inventory_cost_layers WHERE variant_id IN (' . $placeholders . ')'
            )->execute($variantIds);
        }
        if (orange_table_exists($pdo, 'warehouse_variant_stock')) {
            $pdo->prepare(
                'DELETE FROM warehouse_variant_stock WHERE variant_id IN (' . $placeholders . ')'
            )->execute($variantIds);
        }
    }

    if (orange_table_exists($pdo, 'product_variants')) {
        $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
    }
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
}
