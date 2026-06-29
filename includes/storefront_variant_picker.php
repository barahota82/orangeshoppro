<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_labels.php';
require_once __DIR__ . '/warehouses.php';
require_once __DIR__ . '/upload_paths.php';
require_once __DIR__ . '/variant_pricing.php';

/**
 * عرض متغيّرات منتج للواجهة (ألوان/مقاسات/مخزون فعّال حسب الدولة) — مستخرَج من منطق
 * pages/product.php لإعادة استخدامه في صفحة العرض pages/offer.php حيث نحتاج منتقياً
 * مستقلاً لكل مكوّن من مكوّنات العرض. لا يلمس product.php (تفادياً لمخاطرة إعادة هيكلة
 * المسار الساخن)؛ يحاكي نفس المنطق بدقّة.
 *
 * @return array{
 *   product_id:int, name:string, price:float, main_image:string,
 *   has_colors:int, has_sizes:int, total_stock:int,
 *   price_min:float, price_max:float,
 *   colors: list<array{key:string,color:string,pattern:string}>,
 *   sizes: list<array{key:string,label:string}>,
 *   variants: list<array{id:int,color:string,size:string,stock_quantity:int,price:float}>
 * }
 */
function orange_storefront_product_variant_view(PDO $pdo, int $productId, int $countryId, string $lang): array
{
    $empty = [
        'product_id' => $productId,
        'name' => '',
        'price' => 0.0,
        'main_image' => '',
        'has_colors' => 0,
        'has_sizes' => 0,
        'total_stock' => 0,
        'colors' => [],
        'sizes' => [],
        'variants' => [],
    ];
    if ($productId <= 0 || !orange_table_exists($pdo, 'products')) {
        return $empty;
    }
    $st = $pdo->prepare('SELECT * FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
    $st->execute([$productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return $empty;
    }
    $hasColors = (int) ($product['has_colors'] ?? 0) === 1 ? 1 : 0;
    $hasSizes = (int) ($product['has_sizes'] ?? 0) === 1 ? 1 : 0;

    $mainFile = trim((string) ($product['main_image'] ?? ''));
    if ($mainFile === '' && function_exists('orange_product_first_colorway_image_map')) {
        $fb = orange_product_first_colorway_image_map($pdo, [$productId]);
        if (!empty($fb[$productId])) {
            $mainFile = (string) $fb[$productId];
        }
    }
    $mainImageHref = $mainFile !== '' ? storefront_product_image_href($mainFile) : '';

    $variantsStmt = $pdo->prepare(
        "SELECT v.*,
            cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id,
            sfs.label_ar AS sfs_la, sfs.label_en AS sfs_le,
            sfs.label_fil AS sfs_lf, sfs.label_hi AS sfs_lh,
            sfs.sort_order AS sfs_so
         FROM product_variants v
         LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
         LEFT JOIN size_family_sizes sfs ON sfs.id = v.size_family_size_id
         WHERE v.product_id = ?
         ORDER BY v.id ASC"
    );
    $variantsStmt->execute([$productId]);
    $rows = $variantsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $colorOrder = [];
    $colorMeta = [];
    $sizeOrder = [];
    $sizeLabel = [];
    $sizeSort = [];
    $totalStock = 0;
    $variants = [];
    $priceMin = null;
    $priceMax = null;

    foreach ($rows as $v) {
        $vid = (int) ($v['id'] ?? 0);
        $stock = $vid > 0 ? orange_warehouse_effective_variant_stock($pdo, $vid, $countryId) : 0;
        $color = trim((string) ($v['color'] ?? ''));
        $size = trim((string) ($v['size'] ?? ''));
        $vPrice = orange_variant_effective_price($product, $v);
        $variants[] = [
            'id' => $vid,
            'color' => $color,
            'size' => $size,
            'stock_quantity' => $stock,
            'price' => $vPrice,
        ];
        if ($priceMin === null || $vPrice < $priceMin) {
            $priceMin = $vPrice;
        }
        if ($priceMax === null || $vPrice > $priceMax) {
            $priceMax = $vPrice;
        }
        $totalStock += $stock;

        if ($hasColors === 1 && $color !== '' && !isset($colorMeta[$color])) {
            $pc = (int) ($v['primary_color_id'] ?? 0);
            $sc = (int) ($v['secondary_color_id'] ?? 0);
            $pp = (int) ($v['primary_pattern_id'] ?? 0);
            $psp = (int) ($v['secondary_pattern_id'] ?? 0);
            if ($pc > 0 || $sc > 0 || $pp > 0 || $psp > 0) {
                $segs = orange_colorway_display_segments(
                    $pdo,
                    $pc > 0 ? $pc : null,
                    $sc > 0 ? $sc : null,
                    $pp > 0 ? $pp : null,
                    $psp > 0 ? $psp : null,
                    $lang
                );
            } else {
                $segs = orange_storefront_split_variant_color_field($color);
            }
            $colorMeta[$color] = [
                'key' => $color,
                'color' => (string) ($segs['color'] ?? ''),
                'pattern' => (string) ($segs['pattern'] ?? ''),
            ];
            $colorOrder[] = $color;
        }

        if ($hasSizes === 1 && $size !== '' && !isset($sizeLabel[$size])) {
            $szRow = null;
            if (
                isset($v['sfs_la'])
                || isset($v['sfs_le'])
                || (isset($v['sfs_lf']) && trim((string) $v['sfs_lf']) !== '')
                || (isset($v['sfs_lh']) && trim((string) $v['sfs_lh']) !== '')
            ) {
                $szRow = [
                    'label_ar' => (string) ($v['sfs_la'] ?? ''),
                    'label_en' => (string) ($v['sfs_le'] ?? ''),
                    'label_fil' => (string) ($v['sfs_lf'] ?? ''),
                    'label_hi' => (string) ($v['sfs_lh'] ?? ''),
                ];
            }
            $sizeLabel[$size] = $szRow ? orange_size_display_label($szRow, $lang) : $size;
            $sizeOrder[] = $size;
            $sizeSort[$size] = isset($v['sfs_so']) && $v['sfs_so'] !== null
                ? (int) $v['sfs_so']
                : (1000000 + count($sizeSort));
        }
    }

    if ($sizeOrder !== [] && $sizeSort !== []) {
        $stable = array_flip($sizeOrder);
        usort($sizeOrder, static function ($a, $b) use ($sizeSort, $stable) {
            $sa = $sizeSort[$a] ?? PHP_INT_MAX;
            $sb = $sizeSort[$b] ?? PHP_INT_MAX;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return ($stable[$a] ?? 0) <=> ($stable[$b] ?? 0);
        });
    }

    $colors = [];
    foreach ($colorOrder as $ck) {
        $colors[] = $colorMeta[$ck];
    }
    $sizes = [];
    foreach ($sizeOrder as $sk) {
        $sizes[] = ['key' => $sk, 'label' => $sizeLabel[$sk]];
    }

    $basePrice = (float) ($product['price'] ?? 0);

    return [
        'product_id' => $productId,
        'name' => storefront_product_display_name($product),
        'price' => $basePrice,
        'price_min' => $priceMin !== null ? (float) $priceMin : $basePrice,
        'price_max' => $priceMax !== null ? (float) $priceMax : $basePrice,
        'main_image' => $mainImageHref,
        'has_colors' => $hasColors,
        'has_sizes' => $hasSizes,
        'total_stock' => $totalStock,
        'colors' => $colors,
        'sizes' => $sizes,
        'variants' => $variants,
    ];
}
