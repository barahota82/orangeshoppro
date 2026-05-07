<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/catalog_schema.php';
require_once __DIR__ . '/../../../includes/catalog_unified_product_helpers.php';
require_admin_api('GET');

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    $catJoinPart = orange_catalog_admin_sql_join_product_category_display($pdo, 'p', 'pt');

    if ($productId > 0) {
        $stmt = $pdo->prepare("
            SELECT p.*,
                   c.name_ar AS category_name_ar, c.name_en AS category_name_en,
                   pt.name_ar AS product_type_name_ar,
                   pt.name_en AS product_type_name_en,
                   pt.slug AS product_type_slug,
                   pt.expected_commercial_kind_key AS product_type_expected_commercial_kind_key,
                   pt.expected_sizing_category_key AS product_type_expected_sizing_category_key
            FROM products p
            LEFT JOIN product_types pt ON pt.id = p.product_type_id
            {$catJoinPart}
            WHERE p.id = ?
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            json_response(['success' => false, 'message' => 'المنتج غير موجود'], 404);
        }

        $variantStmt = $pdo->prepare("
            SELECT v.id AS variant_id,
                   v.product_colorway_id,
                   v.size_family_size_id,
                   v.stock_quantity,
                   COALESCE(cw.primary_color_id, 0) AS primary_color_id,
                   COALESCE(cw.secondary_color_id, 0) AS secondary_color_id,
                   COALESCE(cw.primary_pattern_id, 0) AS primary_pattern_id,
                   COALESCE(cw.secondary_pattern_id, 0) AS secondary_pattern_id
            FROM product_variants v
            LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
            WHERE v.product_id = ?
            ORDER BY primary_color_id ASC, secondary_color_id ASC, primary_pattern_id ASC, secondary_pattern_id ASC,
                     v.size_family_size_id ASC, v.id ASC
        ");
        $variantStmt->execute([$productId]);
        $product['variants'] = $variantStmt->fetchAll();
        /** @var list<array<string,mixed>> */
        $product['variant_matrix_rows'] = $product['variants'];

        $imgStmt = $pdo->prepare(
            'SELECT image_path FROM product_images WHERE product_id = ? ORDER BY id ASC'
        );
        $imgStmt->execute([$productId]);
        $imgRows = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
        $product['extra_images'] = [];
        foreach (is_array($imgRows) ? $imgRows : [] as $row) {
            $p = isset($row['image_path']) ? trim((string)$row['image_path']) : '';
            if ($p !== '') {
                $product['extra_images'][] = $p;
            }
        }

        $product['catalog_attribute_values'] = [];
        if (orange_table_exists($pdo, 'product_attribute_values') && orange_table_exists($pdo, 'catalog_attributes')) {
            try {
                $attrStmt = $pdo->prepare(
                    'SELECT pav.catalog_attribute_id, pav.value_raw, ca.attribute_key, ca.label_ar, ca.input_kind
                     FROM product_attribute_values pav
                     INNER JOIN catalog_attributes ca ON ca.id = pav.catalog_attribute_id AND ca.is_active = 1
                     WHERE pav.product_id = ?
                     ORDER BY ca.sort_order ASC, ca.id ASC'
                );
                $attrStmt->execute([$productId]);
                $product['catalog_attribute_values'] = $attrStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $product['catalog_attribute_values'] = [];
            }
        }

        json_response(['success' => true, 'product' => $product]);
    }

    $rows = $pdo->query("
        SELECT p.*,
               c.name_ar AS category_name_ar, c.name_en AS category_name_en,
               pt.name_ar AS product_type_name_ar,
               pt.name_en AS product_type_name_en,
               pt.slug AS product_type_slug,
               pt.expected_commercial_kind_key AS product_type_expected_commercial_kind_key,
               pt.expected_sizing_category_key AS product_type_expected_sizing_category_key
        FROM products p
        LEFT JOIN product_types pt ON pt.id = p.product_type_id
        {$catJoinPart}
        ORDER BY p.sort_order ASC, p.id ASC
    ")->fetchAll();

    json_response(['success' => true, 'products' => $rows]);
} catch (Throwable $e) {
    orange_admin_api_catch($e, 'تعذر تحميل المنتجات');
}
