<?php

declare(strict_types=1);

function orange_dict_color(PDO $pdo, $id): ?array
{
    if ($id === null || $id === '') {
        return null;
    }
    $id = (int)$id;
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $stmt = $pdo->prepare('SELECT * FROM color_dictionary WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$id] = $row ?: null;
    return $cache[$id];
}

function orange_dict_pattern(PDO $pdo, $id): ?array
{
    if ($id === null || $id === '') {
        return null;
    }
    $id = (int) $id;
    if ($id <= 0) {
        return null;
    }
    static $cache = [];
    if (! orange_table_exists($pdo, 'pattern_dictionary')) {
        return null;
    }
    if (isset($cache[$id])) {
        return $cache[$id];
    }
    $stmt = $pdo->prepare('SELECT * FROM pattern_dictionary WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$id] = $row ?: null;

    return $cache[$id];
}

function orange_pattern_dictionary_id_is_active_posting(PDO $pdo, int $id): bool
{
    if ($id <= 0 || ! orange_table_exists($pdo, 'pattern_dictionary')) {
        return false;
    }
    $stmt = $pdo->prepare('SELECT 1 FROM pattern_dictionary WHERE id = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$id]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return array{color: string, pattern: string}
 */
function orange_colorway_display_segments(PDO $pdo, ?int $primaryId, ?int $secondaryId, ?int $primaryPatternId = null, ?int $secondaryPatternId = null): array
{
    $p = orange_dict_color($pdo, $primaryId);
    $s = orange_dict_color($pdo, $secondaryId);
    $parts = [];
    if ($p) {
        $parts[] = trim((string) ($p['name_ar'] !== '' ? $p['name_ar'] : $p['name_en']));
    }
    if ($s) {
        $parts[] = trim((string) ($s['name_ar'] !== '' ? $s['name_ar'] : $s['name_en']));
    }
    $colorSeg = implode(' / ', array_filter($parts, static fn ($x) => $x !== ''));

    $pp = orange_dict_pattern($pdo, $primaryPatternId);
    $sp = orange_dict_pattern($pdo, $secondaryPatternId);
    $patBits = [];
    if ($pp) {
        $patBits[] = trim((string) ($pp['name_ar'] !== '' ? $pp['name_ar'] : $pp['name_en']));
    }
    if ($sp) {
        $patBits[] = trim((string) ($sp['name_ar'] !== '' ? $sp['name_ar'] : $sp['name_en']));
    }
    $patSeg = implode(' · ', array_filter($patBits, static fn ($x) => $x !== ''));

    return ['color' => $colorSeg, 'pattern' => $patSeg];
}

function orange_colorway_display_label(PDO $pdo, ?int $primaryId, ?int $secondaryId, ?int $primaryPatternId = null, ?int $secondaryPatternId = null): string
{
    $segs = orange_colorway_display_segments($pdo, $primaryId, $secondaryId, $primaryPatternId, $secondaryPatternId);
    $colorSeg = $segs['color'];
    $patSeg = $segs['pattern'];

    if ($colorSeg !== '' && $patSeg !== '') {
        return $colorSeg . ' — ' . $patSeg;
    }
    if ($colorSeg !== '') {
        return $colorSeg;
    }

    return $patSeg;
}

/**
 * Split denormalized product_variants.color (as built by orange_colorway_display_label) for storefront cards.
 *
 * @return array{color: string, pattern: string}
 */
function orange_storefront_split_variant_color_field(string $stored): array
{
    $t = trim($stored);
    if ($t === '') {
        return ['color' => '', 'pattern' => ''];
    }
    $pieces = preg_split('/\s+—\s+/u', $t, 2);
    if ($pieces === false || count($pieces) < 2) {
        return ['color' => $t, 'pattern' => ''];
    }
    $c = trim((string) $pieces[0]);
    $pat = trim((string) $pieces[1]);

    return ['color' => $c, 'pattern' => $pat];
}

/**
 * Distinct color/pattern lines per product for grid cards (has_colors products only).
 *
 * @param list<int> $productIds
 * @return array<int, list<array{color: string, pattern: string}>>
 */
function orange_storefront_product_card_variant_line_map(PDO $pdo, array $productIds): array
{
    $productIds = array_values(array_unique(array_map(static fn ($x): int => (int) $x, $productIds)));
    if ($productIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT v.product_id, v.color
         FROM product_variants v
         INNER JOIN products p ON p.id = v.product_id AND p.has_colors = 1
         WHERE v.product_id IN ($ph)
           AND TRIM(IFNULL(v.color, '')) <> ''
         ORDER BY v.product_id ASC, v.color ASC"
    );
    $stmt->execute($productIds);
    $out = [];
    $seen = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $pid = (int) $row['product_id'];
        $parts = orange_storefront_split_variant_color_field((string) ($row['color'] ?? ''));
        if ($parts['color'] === '' && $parts['pattern'] === '') {
            continue;
        }
        $k = $parts['color'] . "\x1e" . $parts['pattern'];
        if (isset($seen[$pid][$k])) {
            continue;
        }
        $seen[$pid][$k] = true;
        if (!isset($out[$pid])) {
            $out[$pid] = [];
        }
        $out[$pid][] = $parts;
    }

    return $out;
}

function orange_size_display_label(?array $sizeRow): string
{
    if (!$sizeRow) {
        return '';
    }
    $a = trim((string)($sizeRow['label_ar'] ?? ''));
    $e = trim((string)($sizeRow['label_en'] ?? ''));
    return $a !== '' ? $a : $e;
}
