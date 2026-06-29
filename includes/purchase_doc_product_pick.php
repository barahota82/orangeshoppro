<?php

declare(strict_types=1);

require_once __DIR__ . '/variant_pricing.php';

/**
 * صفوف اختيار الأصناف لفاتورة الشراء ومردود المشتريات — سطر لكل (صنف + لون + مقاس).
 *
 * @return list<array{code:string,barcode:string,product_id:int,variant_id:int,name:string,color:string,size:string,cost:float}>
 */
function orange_purchase_doc_product_pick_rows(PDO $pdo, int $countryId): array
{
    $countrySql = orange_sql_country_and_fragment($pdo, 'products', 'p', $countryId);

    $prodCols = 'p.id, p.name, p.cost, p.has_colors, p.has_sizes';
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

    $codesLower = [];
    $pickRows = [];

    foreach ($products as $p) {
        $pid = (int) ($p['id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $pcode = trim((string) ($p['item_code'] ?? ''));
        $pbarcode = trim((string) ($p['barcode'] ?? ''));
        $cost = (float) ($p['cost'] ?? 0);
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
                'cost' => $cost,
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
            $pickRows[] = [
                'code' => $code,
                'barcode' => $rowBarcode,
                'product_id' => $pid,
                'variant_id' => $vid,
                'name' => (string) ($p['name'] ?? ''),
                'color' => trim((string) ($v['color'] ?? '')),
                'size' => trim((string) ($v['size'] ?? '')),
                'cost' => orange_variant_effective_cost($p, $v),
            ];
        }
    }

    return $pickRows;
}

/** @param array<string,true> $usedLower */
function orange_purchase_doc_alloc_pick_code(string $base, array &$usedLower): string
{
    $code = trim($base);
    if ($code === '') {
        $code = 'X';
    }
    $trial = $code;
    $n = 0;
    for (;;) {
        $k = function_exists('mb_strtolower') ? mb_strtolower($trial, 'UTF-8') : strtolower($trial);
        if (!isset($usedLower[$k])) {
            $usedLower[$k] = true;

            return $trial;
        }
        $n++;
        $trial = $code . '-' . $n;
    }
}
