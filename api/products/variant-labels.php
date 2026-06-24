<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/catalog_labels.php';
require_once __DIR__ . '/../../includes/upload_paths.php';

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $raw = (string) file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids = $data['variant_ids'] ?? $data['ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $pidsRaw = $data['product_ids'] ?? [];
    if (!is_array($pidsRaw)) {
        $pidsRaw = [];
    }
    $langRaw = isset($data['lang']) ? (string) $data['lang'] : 'ar';
    $allowed = ['ar', 'en', 'fil', 'hi'];
    $lang = in_array(strtolower($langRaw), $allowed, true) ? strtolower($langRaw) : 'ar';

    // اسم المنتج في السلة: العربية → الاسم العربي؛ باقي اللغات → الاسم الإنجليزي (مع احتياط).
    $pickName = static function (array $r) use ($lang): string {
        $cols = $lang === 'ar'
            ? ['name', 'name_en', 'name_fil', 'name_hi']
            : ['name_en', 'name', 'name_fil', 'name_hi'];
        foreach ($cols as $c) {
            $v = trim((string) ($r[$c] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    };

    $pdo = db();
    orange_catalog_ensure_schema($pdo);

    $intIds = array_values(array_unique(array_filter(array_map(static fn ($x): int => (int) $x, $ids), static fn ($x): bool => $x > 0)));
    $prodIds = array_values(array_unique(array_filter(array_map(static fn ($x): int => (int) $x, $pidsRaw), static fn ($x): bool => $x > 0)));

    // خريطة أسماء المنتجات المترجَمة (تغطي كل بنود السلة، بمتغيّر أو بدونه).
    $products = [];
    if ($prodIds !== []) {
        $nameCols = ['name'];
        foreach (['name_en', 'name_fil', 'name_hi'] as $nc) {
            if (orange_table_has_column($pdo, 'products', $nc)) {
                $nameCols[] = $nc;
            }
        }
        $phP = implode(',', array_fill(0, count($prodIds), '?'));
        $pst = $pdo->prepare('SELECT id, ' . implode(', ', $nameCols) . " FROM products WHERE id IN ($phP)");
        $pst->execute($prodIds);
        while ($pr = $pst->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($pr)) {
                continue;
            }
            $pid = (int) ($pr['id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $products[(string) $pid] = $pickName($pr);
        }
    }

    if ($intIds === []) {
        echo json_encode(['success' => true, 'labels' => [], 'products' => $products, 'lang' => $lang], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ph = implode(',', array_fill(0, count($intIds), '?'));
    // صورة اللون المختار (colorway): أوّل صورة لـ product_colorway المرتبط بالمتغيّر، واحتياطاً صورة المنتج الرئيسية.
    $hasCwImages = orange_table_exists($pdo, 'product_colorway_images');
    $cwImgSel = $hasCwImages
        ? "(SELECT pci.image_path FROM product_colorway_images pci
             WHERE pci.product_colorway_id = v.product_colorway_id
             ORDER BY pci.sort_order ASC, pci.id ASC LIMIT 1)"
        : 'NULL';
    $hasMainImage = orange_table_has_column($pdo, 'products', 'main_image');
    $mainImgSel = $hasMainImage ? 'pr.main_image' : 'NULL';
    $stmt = $pdo->prepare(
        "SELECT v.id, v.product_id, v.size, v.size_family_size_id, v.stock_quantity, v.color,
                cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id,
                sfs.label_ar AS sfs_la, sfs.label_en AS sfs_le,
                sfs.label_fil AS sfs_lf, sfs.label_hi AS sfs_lh,
                $cwImgSel AS cw_image, $mainImgSel AS main_img
         FROM product_variants v
         LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
         LEFT JOIN size_family_sizes sfs ON sfs.id = v.size_family_size_id
         LEFT JOIN products pr ON pr.id = v.product_id
         WHERE v.id IN ($ph)"
    );
    $stmt->execute($intIds);
    $labels = [];
    while ($rw = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($rw)) {
            continue;
        }
        $vid = (int) ($rw['id'] ?? 0);
        $productId = (int) ($rw['product_id'] ?? 0);
        if ($productId > 0 && !orange_storefront_product_in_active_unified_chain($pdo, $productId)) {
            continue;
        }
        $p = isset($rw['primary_color_id']) ? (int) $rw['primary_color_id'] : 0;
        $s = isset($rw['secondary_color_id']) ? (int) $rw['secondary_color_id'] : 0;
        $pp = isset($rw['primary_pattern_id']) ? (int) $rw['primary_pattern_id'] : 0;
        $sp = isset($rw['secondary_pattern_id']) ? (int) $rw['secondary_pattern_id'] : 0;
        if ($p > 0 || $s > 0 || $pp > 0 || $sp > 0) {
            $segs = orange_colorway_display_segments(
                $pdo,
                $p > 0 ? $p : null,
                $s > 0 ? $s : null,
                $pp > 0 ? $pp : null,
                $sp > 0 ? $sp : null,
                $lang
            );
        } else {
            $leg = orange_storefront_split_variant_color_field((string) ($rw['color'] ?? ''));
            if ($lang === 'ar') {
                $segs = $leg;
            } else {
                $full = trim((string) ($rw['color'] ?? ''));
                $segs = ['color' => $full, 'pattern' => ''];
            }
        }
        $szLbl = orange_size_display_label(
            isset($rw['sfs_la']) || isset($rw['sfs_le']) || isset($rw['sfs_lf']) || isset($rw['sfs_lh'])
                ? [
                    'label_ar' => (string) ($rw['sfs_la'] ?? ''),
                    'label_en' => (string) ($rw['sfs_le'] ?? ''),
                    'label_fil' => (string) ($rw['sfs_lf'] ?? ''),
                    'label_hi' => (string) ($rw['sfs_lh'] ?? ''),
                ]
                : null,
            $lang
        );
        if ($szLbl === '') {
            $szLbl = trim((string) ($rw['size'] ?? ''));
        }

        $colorFull =
            ($segs['color'] ?? '') !== '' && ($segs['pattern'] ?? '') !== ''
                ? $segs['color'] . ' — ' . $segs['pattern']
                : (($segs['color'] ?? '') !== '' ? $segs['color'] : ($segs['pattern'] ?? ''));
        if ($lang !== 'ar' && ($colorFull === '')) {
            $colorFull = trim((string) ($rw['color'] ?? ''));
        }

        $imgRaw = trim((string) ($rw['cw_image'] ?? ''));
        if ($imgRaw === '') {
            $imgRaw = trim((string) ($rw['main_img'] ?? ''));
        }
        $imgWeb = $imgRaw !== '' ? storefront_product_image_web_path($imgRaw) : '';
        $imgBase = $imgWeb !== '' ? basename($imgWeb) : '';

        $labels[(string) $vid] = [
            'variant_id' => $vid,
            'color_part' => (string) ($segs['color'] ?? ''),
            'pattern_part' => (string) ($segs['pattern'] ?? ''),
            'color' => $colorFull !== '' ? $colorFull : trim((string) ($rw['color'] ?? '')),
            'size' => $szLbl,
            'image' => $imgBase,
        ];
    }

    echo json_encode(['success' => true, 'labels' => $labels, 'products' => $products, 'lang' => $lang], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
