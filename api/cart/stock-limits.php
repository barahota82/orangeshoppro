<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/catalog_schema.php';
require_once __DIR__ . '/../../includes/catalog_unified_product_helpers.php';
require_once __DIR__ . '/../../includes/warehouses.php';
require_once __DIR__ . '/../../includes/countries.php';

try {
    $pdo = db();
    orange_catalog_ensure_schema($pdo);
    $data = get_json_input();
    orange_storefront_apply_lang_from_payload($data);
    if (!isset($data['items']) || !is_array($data['items'])) {
        json_response(['success' => false, 'code' => 'cart_items_required', 'message' => t('checkout_cart_items_required')], 422);
    }

    $stockCountryId = orange_storefront_current_country_id($pdo);
    $limits = [];
    foreach ($data['items'] as $item) {
        $pid = (int)($item['id'] ?? 0);
        if ($pid <= 0) {
            $limits[] = 0;
            continue;
        }

        $pStmt = $pdo->prepare('SELECT id FROM products WHERE id = ? AND is_active = 1 LIMIT 1');
        $pStmt->execute([$pid]);
        if (!$pStmt->fetch()) {
            $limits[] = 0;
            continue;
        }
        if (!orange_storefront_product_in_active_unified_chain($pdo, $pid)) {
            $limits[] = 0;
            continue;
        }

        $vid = (int)($item['variant_id'] ?? 0);
        $stock = 0;

        if ($vid > 0) {
            $stmt = $pdo->prepare(
                'SELECT id FROM product_variants WHERE id = ? AND product_id = ? LIMIT 1'
            );
            $stmt->execute([$vid, $pid]);
            if ($stmt->fetch()) {
                $stock = orange_warehouse_effective_variant_stock($pdo, $vid, $stockCountryId);
            }
        } else {
            $color = isset($item['color']) ? trim((string)$item['color']) : '';
            $size = isset($item['size']) ? trim((string)$item['size']) : '';
            $stmt = $pdo->prepare(
                'SELECT id FROM product_variants
                 WHERE product_id = ? AND color = ? AND size = ?
                 LIMIT 1'
            );
            $stmt->execute([$pid, $color, $size]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $stock = orange_warehouse_effective_variant_stock($pdo, (int) $row['id'], $stockCountryId);
            } else {
                $one = $pdo->prepare(
                    'SELECT id FROM product_variants WHERE product_id = ? ORDER BY id ASC LIMIT 1'
                );
                $one->execute([$pid]);
                $r2 = $one->fetch(PDO::FETCH_ASSOC);
                $stock = $r2
                    ? orange_warehouse_effective_variant_stock($pdo, (int) $r2['id'], $stockCountryId)
                    : 0;
            }
        }

        $limits[] = max(0, $stock);
    }

    json_response(['success' => true, 'limits' => $limits]);
} catch (Throwable $e) {
    api_error($e, t('api_request_failed'));
}
