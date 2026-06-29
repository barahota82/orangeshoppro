<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/purchase_doc_product_pick.php';
require_once __DIR__ . '/variant_pricing.php';

/**
 * صفوف اختيار الأصناف لفاتورة مبيعات شركة (INV-C) — سعر + مخزون.
 *
 * @return list<array{code:string,barcode:string,product_id:int,variant_id:int,name:string,color:string,size:string,cost:float,price:float,stock_available:int,stock_reserved:int,stock_total:int}>
 */
function orange_sales_doc_product_pick_rows(PDO $pdo, int $countryId): array
{
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);

    $prodCols = 'p.id, p.name, p.price, p.cost, p.has_colors, p.has_sizes';
    if (orange_table_has_column($pdo, 'products', 'item_code')) {
        $prodCols .= ', p.item_code';
    }
    if (orange_table_has_column($pdo, 'products', 'barcode')) {
        $prodCols .= ', p.barcode';
    }

    $products = $pdo->query(
        "SELECT $prodCols FROM products p WHERE p.is_active = 1" . $countrySql . ' ORDER BY p.name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $vCols = 'pv.id, pv.product_id, pv.color, pv.size';
    if (orange_table_has_column($pdo, 'product_variants', 'item_code')) {
        $vCols .= ', pv.item_code';
    }
    if (orange_table_has_column($pdo, 'product_variants', 'barcode')) {
        $vCols .= ', pv.barcode';
    }
    if (orange_table_has_column($pdo, 'product_variants', 'price')) {
        $vCols .= ', pv.price';
    }
    if (orange_table_has_column($pdo, 'product_variants', 'cost')) {
        $vCols .= ', pv.cost';
    }

    $variants = $pdo->query(
        'SELECT ' . $vCols . ' FROM product_variants pv
         INNER JOIN products p ON p.id = pv.product_id
         WHERE p.is_active = 1' . $countrySql . '
         ORDER BY pv.product_id ASC, pv.id ASC'
    )->fetchAll(PDO::FETCH_ASSOC);

    $variantsByProduct = [];
    foreach ($variants as $v) {
        $pid = (int) ($v['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($variantsByProduct[$pid])) {
            $variantsByProduct[$pid] = [];
        }
        $variantsByProduct[$pid][] = $v;
    }

    $pendingReserved = [];
    if (orange_table_exists($pdo, 'stock_movements') && orange_table_has_column($pdo, 'stock_movements', 'variant_id')) {
        $vidsAll = [];
        foreach ($variants as $v) {
            $vidsAll[] = (int) ($v['id'] ?? 0);
        }
        $vidsAll = array_values(array_unique(array_filter($vidsAll)));
        foreach (array_chunk($vidsAll, 400) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $q = $pdo->prepare(
                "SELECT variant_id, COALESCE(SUM(qty), 0) AS q FROM stock_movements
                 WHERE type = 'pending_order' AND variant_id IN ($ph) GROUP BY variant_id"
            );
            $q->execute($chunk);
            while ($row = $q->fetch(PDO::FETCH_ASSOC)) {
                $pendingReserved[(int) ($row['variant_id'] ?? 0)] = (int) ($row['q'] ?? 0);
            }
        }
    }

    $codesLower = [];
    $pickRows = [];

    foreach ($products as $p) {
        $pid = (int) ($p['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $pcode = trim((string) ($p['item_code'] ?? ''));
        $pbarcode = trim((string) ($p['barcode'] ?? ''));
        $price = (float) ($p['price'] ?? 0);
        $pcost = (float) ($p['cost'] ?? 0);
        $vlist = $variantsByProduct[$pid] ?? [];

        if ($vlist === []) {
            $base = $pcode !== '' ? $pcode : ('P' . $pid);
            $code = orange_purchase_doc_alloc_pick_code($base, $codesLower);
            $pickRows[] = [
                'code' => $code,
                'barcode' => $pbarcode,
                'product_id' => $pid,
                'variant_id' => 0,
                'name' => (string) ($p['name'] ?? ''),
                'color' => '',
                'size' => '',
                'cost' => $pcost,
                'price' => $price,
                'stock_available' => 0,
                'stock_reserved' => 0,
                'stock_total' => 0,
            ];
            continue;
        }

        foreach ($vlist as $v) {
            $vid = (int) ($v['id'] ?? 0);
            $vcode = trim((string) ($v['item_code'] ?? ''));
            $vbarcode = trim((string) ($v['barcode'] ?? ''));
            $rowBarcode = $vbarcode !== '' ? $vbarcode : $pbarcode;
            if ($vcode !== '') {
                $base = $vcode;
            } elseif ($pcode !== '') {
                $base = $pcode . '-' . $vid;
            } else {
                $base = 'P' . $pid . '-V' . $vid;
            }
            $code = orange_purchase_doc_alloc_pick_code($base, $codesLower);
            $avail = orange_warehouse_effective_variant_stock($pdo, $vid, $countryId);
            $res = (int) ($pendingReserved[$vid] ?? 0);
            $pickRows[] = [
                'code' => $code,
                'barcode' => $rowBarcode,
                'product_id' => $pid,
                'variant_id' => $vid,
                'name' => (string) ($p['name'] ?? ''),
                'color' => trim((string) ($v['color'] ?? '')),
                'size' => trim((string) ($v['size'] ?? '')),
                'cost' => orange_variant_effective_cost($p, $v),
                'price' => orange_variant_effective_price($p, $v),
                'stock_available' => $avail,
                'stock_reserved' => $res,
                'stock_total' => $avail + $res,
            ];
        }
    }

    return $pickRows;
}
