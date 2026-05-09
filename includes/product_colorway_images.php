<?php

declare(strict_types=1);

require_once __DIR__ . '/product_variants_write.php';

/**
 * خريطة مفتاح colorway (p:s:pp:sp) → معرف product_colorways.id من قاعدة البيانات.
 *
 * @return array<string,int>
 */
function orange_product_colorway_key_map_from_db(PDO $pdo, int $productId): array
{
    if (!orange_table_exists($pdo, 'product_colorways')) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT id, primary_color_id, secondary_color_id, primary_pattern_id, secondary_pattern_id
         FROM product_colorways WHERE product_id = ?'
    );
    $st->execute([$productId]);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $vk = [
            'primary_color_id' => isset($r['primary_color_id']) ? (int) $r['primary_color_id'] : 0,
            'secondary_color_id' => isset($r['secondary_color_id']) ? (int) $r['secondary_color_id'] : 0,
            'primary_pattern_id' => isset($r['primary_pattern_id']) ? (int) $r['primary_pattern_id'] : 0,
            'secondary_pattern_id' => isset($r['secondary_pattern_id']) ? (int) $r['secondary_pattern_id'] : 0,
        ];
        $k = orange_product_variant_cw_row_key($vk, true);
        $out[$k] = (int) ($r['id'] ?? 0);
    }

    return $out;
}

/**
 * قائمة للأدمن: [{ cw_key, images: [basename,...] }, ...]
 *
 * @return list<array{cw_key: string, images: list<string>}>
 */
function orange_product_colorway_images_groups_for_admin(PDO $pdo, int $productId): array
{
    if (!orange_table_exists($pdo, 'product_colorway_images') || !orange_table_exists($pdo, 'product_colorways')) {
        return [];
    }
    $st = $pdo->prepare(
        'SELECT cw.primary_color_id, cw.secondary_color_id, cw.primary_pattern_id, cw.secondary_pattern_id,
                pci.image_path
         FROM product_colorway_images pci
         INNER JOIN product_colorways cw ON cw.id = pci.product_colorway_id
         WHERE cw.product_id = ?
         ORDER BY pci.product_colorway_id ASC, pci.sort_order ASC, pci.id ASC'
    );
    $st->execute([$productId]);
    $byKey = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($r)) {
            continue;
        }
        $vk = [
            'primary_color_id' => isset($r['primary_color_id']) ? (int) $r['primary_color_id'] : 0,
            'secondary_color_id' => isset($r['secondary_color_id']) ? (int) $r['secondary_color_id'] : 0,
            'primary_pattern_id' => isset($r['primary_pattern_id']) ? (int) $r['primary_pattern_id'] : 0,
            'secondary_pattern_id' => isset($r['secondary_pattern_id']) ? (int) $r['secondary_pattern_id'] : 0,
        ];
        $k = orange_product_variant_cw_row_key($vk, true);
        if (!isset($byKey[$k])) {
            $byKey[$k] = [];
        }
        $fn = isset($r['image_path']) ? trim((string) $r['image_path']) : '';
        if ($fn !== '') {
            $byKey[$k][] = $fn;
        }
    }
    $out = [];
    foreach ($byKey as $cwKey => $images) {
        $out[] = ['cw_key' => $cwKey, 'images' => array_values($images)];
    }

    return $out;
}

/**
 * @param list<array{cw_key?: string, images?: mixed}>|null $groups
 */
function orange_product_sync_colorway_images_from_payload(PDO $pdo, int $productId, ?array $groups, bool $hasColors): void
{
    if (!$hasColors || !orange_table_exists($pdo, 'product_colorway_images')) {
        return;
    }
    $pdo->prepare(
        'DELETE pci FROM product_colorway_images pci
         INNER JOIN product_colorways cw ON cw.id = pci.product_colorway_id
         WHERE cw.product_id = ?'
    )->execute([$productId]);

    if (!is_array($groups) || $groups === []) {
        return;
    }

    $keyToId = orange_product_colorway_key_map_from_db($pdo, $productId);
    $ins = $pdo->prepare(
        'INSERT INTO product_colorway_images (product_colorway_id, image_path, sort_order) VALUES (?,?,?)'
    );

    foreach ($groups as $g) {
        if (!is_array($g)) {
            continue;
        }
        $key = trim((string) ($g['cw_key'] ?? ''));
        $images = $g['images'] ?? null;
        if ($key === '' || !is_array($images)) {
            continue;
        }
        $cwId = $keyToId[$key] ?? 0;
        if ($cwId <= 0) {
            continue;
        }
        $so = 0;
        foreach ($images as $imgRaw) {
            $fn = basename((string) $imgRaw);
            $fn = preg_replace('/[^a-zA-Z0-9._-]/', '', $fn);
            if ($fn === '' || $fn === '.' || $fn === '..') {
                continue;
            }
            $ins->execute([$cwId, $fn, $so++]);
        }
    }
}
