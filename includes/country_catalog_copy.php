<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/countries.php';
require_once __DIR__ . '/warehouses.php';

/**
 * نسخ كتalog منتجات من دولة مصدر إلى دولة هدف (idempotent — يتخطى إن وُجدت منتجات في الهدف).
 *
 * @return array{skipped:bool, reason:string, products_copied:int, source_country_id:int, target_country_id:int}
 */
function orange_country_copy_catalog_from_source(PDO $pdo, int $targetCountryId, ?int $sourceCountryId = null): array
{
    $out = [
        'skipped' => true,
        'reason' => '',
        'products_copied' => 0,
        'source_country_id' => 0,
        'target_country_id' => $targetCountryId,
    ];
    if ($targetCountryId <= 0 || !orange_table_exists($pdo, 'products')) {
        $out['reason'] = 'no_products_table';

        return $out;
    }
    if (!orange_table_has_country_id($pdo, 'products')) {
        $out['reason'] = 'no_country_id_column';

        return $out;
    }

    $sourceCountryId = $sourceCountryId !== null && $sourceCountryId > 0
        ? $sourceCountryId
        : orange_countries_default_id($pdo);
    $out['source_country_id'] = $sourceCountryId;
    if ($sourceCountryId <= 0) {
        $out['reason'] = 'no_source_country';

        return $out;
    }
    if ($sourceCountryId === $targetCountryId) {
        $out['reason'] = 'same_country';

        return $out;
    }

    $stCnt = $pdo->prepare('SELECT COUNT(*) FROM products WHERE country_id = ?');
    $stCnt->execute([$targetCountryId]);
    if ((int) $stCnt->fetchColumn() > 0) {
        $out['reason'] = 'target_has_products';

        return $out;
    }

    $stSrc = $pdo->prepare('SELECT * FROM products WHERE country_id = ? ORDER BY id ASC');
    $stSrc->execute([$sourceCountryId]);
    $srcProducts = $stSrc->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($srcProducts === []) {
        $out['reason'] = 'source_empty';

        return $out;
    }

    $targetWhId = orange_warehouse_default_id_for_country($pdo, $targetCountryId);
    $targetChannelIds = [];
    if (orange_table_exists($pdo, 'channels') && orange_channels_has_country_column($pdo)) {
        $stCh = $pdo->prepare('SELECT id FROM channels WHERE is_active = 1 AND country_id = ?');
        $stCh->execute([$targetCountryId]);
        $targetChannelIds = array_map('intval', $stCh->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    require_once __DIR__ . '/catalog_unified_product_helpers.php';

    foreach ($srcProducts as $srcProduct) {
        if (!is_array($srcProduct)) {
            continue;
        }
        $oldProductId = (int) ($srcProduct['id'] ?? 0);
        if ($oldProductId <= 0) {
            continue;
        }

        $pdo->beginTransaction();
        try {
            $newProductId = orange_country_catalog_insert_product_clone($pdo, $srcProduct, $targetCountryId);
            orange_country_catalog_copy_colorways_and_variants(
                $pdo,
                $oldProductId,
                $newProductId,
                $targetWhId
            );
            orange_country_catalog_copy_product_images($pdo, $oldProductId, $newProductId);
            orange_country_catalog_copy_attribute_values($pdo, $oldProductId, $newProductId);
            orange_country_catalog_link_target_channels($pdo, $newProductId, $targetChannelIds);

            if (function_exists('orange_catalog_refresh_product_barcodes')) {
                try {
                    orange_catalog_refresh_product_barcodes($pdo, $newProductId);
                } catch (Throwable $e) {
                    if (function_exists('error_log')) {
                        error_log('[orange] catalog copy barcode refresh #' . $newProductId . ': ' . $e->getMessage());
                    }
                }
            }

            $pdo->commit();
            $out['products_copied']++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (function_exists('error_log')) {
                error_log('[orange] catalog copy product #' . $oldProductId . ': ' . $e->getMessage());
            }
        }
    }

    $out['skipped'] = $out['products_copied'] <= 0;
    if ($out['products_copied'] > 0) {
        $out['reason'] = 'copied';
    } elseif ($out['reason'] === '') {
        $out['reason'] = 'copy_failed';
    }

    return $out;
}

/**
 * @param array<string, mixed> $srcRow
 */
function orange_country_catalog_insert_product_clone(PDO $pdo, array $srcRow, int $targetCountryId): int
{
    unset($srcRow['id']);
    $srcRow['country_id'] = $targetCountryId;
    if (array_key_exists('item_code', $srcRow)) {
        $srcRow['item_code'] = null;
    }
    if (array_key_exists('barcode', $srcRow)) {
        $srcRow['barcode'] = null;
    }
    unset($srcRow['created_at'], $srcRow['updated_at']);

    $cols = array_keys($srcRow);
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO products (`' . implode('`, `', $cols) . '`) VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute(array_values($srcRow));

    return (int) $pdo->lastInsertId();
}

function orange_country_catalog_copy_colorways_and_variants(
    PDO $pdo,
    int $oldProductId,
    int $newProductId,
    int $targetWhId
): void {
    $colorwayMap = [];
    if (orange_table_exists($pdo, 'product_colorways')) {
        $stCw = $pdo->prepare('SELECT * FROM product_colorways WHERE product_id = ? ORDER BY id ASC');
        $stCw->execute([$oldProductId]);
        foreach ($stCw->fetchAll(PDO::FETCH_ASSOC) ?: [] as $cw) {
            if (!is_array($cw)) {
                continue;
            }
            $oldCwId = (int) ($cw['id'] ?? 0);
            unset($cw['id'], $cw['created_at']);
            $cw['product_id'] = $newProductId;
            $cols = array_keys($cw);
            $pdo->prepare(
                'INSERT INTO product_colorways (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
            )->execute(array_values($cw));
            $newCwId = (int) $pdo->lastInsertId();
            if ($oldCwId > 0 && $newCwId > 0) {
                $colorwayMap[$oldCwId] = $newCwId;
            }

            if (orange_table_exists($pdo, 'product_colorway_images')) {
                $stImg = $pdo->prepare(
                    'SELECT image_path, sort_order FROM product_colorway_images WHERE product_colorway_id = ? ORDER BY sort_order ASC, id ASC'
                );
                $stImg->execute([$oldCwId]);
                $insPci = $pdo->prepare(
                    'INSERT INTO product_colorway_images (product_colorway_id, image_path, sort_order) VALUES (?, ?, ?)'
                );
                foreach ($stImg->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pci) {
                    $insPci->execute([$newCwId, (string) ($pci['image_path'] ?? ''), (int) ($pci['sort_order'] ?? 0)]);
                }
            }
        }
    }

    if (!orange_table_exists($pdo, 'product_variants')) {
        return;
    }

    $stV = $pdo->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC');
    $stV->execute([$oldProductId]);
    $insWvs = null;
    if ($targetWhId > 0 && orange_table_exists($pdo, 'warehouse_variant_stock')) {
        $insWvs = $pdo->prepare(
            'INSERT INTO warehouse_variant_stock (warehouse_id, variant_id, quantity) VALUES (?, ?, 0)'
        );
    }

    foreach ($stV->fetchAll(PDO::FETCH_ASSOC) ?: [] as $vr) {
        if (!is_array($vr)) {
            continue;
        }
        unset($vr['id']);
        $vr['product_id'] = $newProductId;
        $vr['stock_quantity'] = 0;
        if (array_key_exists('item_code', $vr)) {
            $vr['item_code'] = null;
        }
        if (array_key_exists('barcode', $vr)) {
            $vr['barcode'] = null;
        }
        $oldCwRef = isset($vr['product_colorway_id']) ? (int) $vr['product_colorway_id'] : 0;
        if ($oldCwRef > 0 && isset($colorwayMap[$oldCwRef])) {
            $vr['product_colorway_id'] = $colorwayMap[$oldCwRef];
        }

        $vCols = array_keys($vr);
        $pdo->prepare(
            'INSERT INTO product_variants (`' . implode('`, `', $vCols) . '`) VALUES (' . implode(', ', array_fill(0, count($vCols), '?')) . ')'
        )->execute(array_values($vr));
        $newVid = (int) $pdo->lastInsertId();
        if ($insWvs !== null && $newVid > 0) {
            $insWvs->execute([$targetWhId, $newVid]);
        }
    }
}

function orange_country_catalog_copy_product_images(PDO $pdo, int $oldProductId, int $newProductId): void
{
    if (!orange_table_exists($pdo, 'product_images')) {
        return;
    }
    $st = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id = ? ORDER BY id ASC');
    $st->execute([$oldProductId]);
    $ins = $pdo->prepare('INSERT INTO product_images (product_id, image_path) VALUES (?, ?)');
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $path) {
        $path = trim((string) $path);
        if ($path === '') {
            continue;
        }
        $ins->execute([$newProductId, $path]);
    }
}

function orange_country_catalog_copy_attribute_values(PDO $pdo, int $oldProductId, int $newProductId): void
{
    if (!orange_table_exists($pdo, 'product_attribute_values')) {
        return;
    }
    $st = $pdo->prepare(
        'SELECT catalog_attribute_id, value_raw FROM product_attribute_values WHERE product_id = ? ORDER BY catalog_attribute_id ASC'
    );
    $st->execute([$oldProductId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === []) {
        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO product_attribute_values (product_id, catalog_attribute_id, value_raw) VALUES (?, ?, ?)'
    );
    foreach ($rows as $r) {
        $ins->execute([$newProductId, (int) ($r['catalog_attribute_id'] ?? 0), (string) ($r['value_raw'] ?? '')]);
    }
}

/**
 * @param list<int> $targetChannelIds
 */
function orange_country_catalog_link_target_channels(PDO $pdo, int $productId, array $targetChannelIds): void
{
    if ($productId <= 0 || $targetChannelIds === [] || !orange_table_exists($pdo, 'product_channels')) {
        return;
    }
    $ins = $pdo->prepare('INSERT IGNORE INTO product_channels (product_id, channel_id) VALUES (?, ?)');
    foreach ($targetChannelIds as $cid) {
        $cid = (int) $cid;
        if ($cid > 0) {
            $ins->execute([$productId, $cid]);
        }
    }
}
