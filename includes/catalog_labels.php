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
 * Label from color_dictionary / pattern_dictionary rows for storefront locales.
 */
function orange_catalog_dictionary_row_name(?array $row, string $lang): string
{
    if (!$row) {
        return '';
    }
    $lang = strtolower(trim($lang));
    $ar = trim((string) ($row['name_ar'] ?? ''));
    $en = trim((string) ($row['name_en'] ?? ''));
    $fil = trim((string) ($row['name_fil'] ?? ''));
    $hi = trim((string) ($row['name_hi'] ?? ''));

    return match ($lang) {
        'fil' => $fil !== '' ? $fil : ($en !== '' ? $en : $ar),
        'hi' => $hi !== '' ? $hi : ($en !== '' ? $en : $ar),
        'en' => $en !== '' ? $en : $ar,
        default => $ar !== '' ? $ar : $en,
    };
}

/**
 * @return array{color: string, pattern: string}
 */
function orange_colorway_display_segments(
    PDO $pdo,
    ?int $primaryId,
    ?int $secondaryId,
    ?int $primaryPatternId = null,
    ?int $secondaryPatternId = null,
    string $lang = 'ar'
): array {
    $p = orange_dict_color($pdo, $primaryId);
    $s = orange_dict_color($pdo, $secondaryId);
    $parts = [];
    if ($p) {
        $parts[] = orange_catalog_dictionary_row_name($p, $lang);
    }
    if ($s) {
        $parts[] = orange_catalog_dictionary_row_name($s, $lang);
    }
    $colorSeg = implode(' / ', array_filter($parts, static fn ($x) => $x !== ''));

    $pp = orange_dict_pattern($pdo, $primaryPatternId);
    $sp = orange_dict_pattern($pdo, $secondaryPatternId);
    $patBits = [];
    if ($pp) {
        $patBits[] = orange_catalog_dictionary_row_name($pp, $lang);
    }
    if ($sp) {
        $patBits[] = orange_catalog_dictionary_row_name($sp, $lang);
    }
    $patSeg = implode(' · ', array_filter($patBits, static fn ($x) => $x !== ''));

    return ['color' => $colorSeg, 'pattern' => $patSeg];
}

function orange_colorway_display_label(
    PDO $pdo,
    ?int $primaryId,
    ?int $secondaryId,
    ?int $primaryPatternId = null,
    ?int $secondaryPatternId = null,
    string $lang = 'ar'
): string {
    $segs = orange_colorway_display_segments($pdo, $primaryId, $secondaryId, $primaryPatternId, $secondaryPatternId, $lang);
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
function orange_storefront_product_card_variant_line_map(PDO $pdo, array $productIds, string $lang = 'ar'): array
{
    $productIds = array_values(array_unique(array_map(static fn ($x): int => (int) $x, $productIds)));
    if ($productIds === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($productIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT v.product_id AS product_id,
            cw.primary_color_id AS cw_p, cw.secondary_color_id AS cw_s,
            cw.primary_pattern_id AS cw_pp, cw.secondary_pattern_id AS cw_sp,
            v.color AS legacy_color
         FROM product_variants v
         INNER JOIN products p ON p.id = v.product_id AND p.has_colors = 1
         LEFT JOIN product_colorways cw ON cw.id = v.product_colorway_id
         WHERE v.product_id IN ($ph)
         ORDER BY v.product_id ASC, cw_p ASC, cw_s ASC, cw_pp ASC, cw_sp ASC, legacy_color ASC"
    );
    $stmt->execute($productIds);
    $out = [];
    $seen = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!is_array($row)) {
            continue;
        }
        $pid = (int) $row['product_id'];
        $p = isset($row['cw_p']) && $row['cw_p'] !== null ? (int) $row['cw_p'] : 0;
        $s = isset($row['cw_s']) && $row['cw_s'] !== null ? (int) $row['cw_s'] : 0;
        $pp = isset($row['cw_pp']) && $row['cw_pp'] !== null ? (int) $row['cw_pp'] : 0;
        $psp = isset($row['cw_sp']) && $row['cw_sp'] !== null ? (int) $row['cw_sp'] : 0;
        if ($p > 0 || $s > 0 || $pp > 0 || $psp > 0) {
            $parts = orange_colorway_display_segments(
                $pdo,
                $p > 0 ? $p : null,
                $s > 0 ? $s : null,
                $pp > 0 ? $pp : null,
                $psp > 0 ? $psp : null,
                $lang
            );
        } else {
            $parts = orange_storefront_split_variant_color_field((string) ($row['legacy_color'] ?? ''));
        }
        if ($parts['color'] === '' && $parts['pattern'] === '') {
            continue;
        }
        $k =
            (($p ?: '') . '|' . ($s ?: '') . '|' . ($pp ?: '') . '|' . ($psp ?: ''))
            . "\x1e" . ($parts['color']) . "\x1e" . ($parts['pattern']);
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

function orange_size_display_label(?array $sizeRow, string $lang = 'ar'): string
{
    if (!$sizeRow) {
        return '';
    }
    $a = trim((string) ($sizeRow['label_ar'] ?? ''));
    $e = trim((string) ($sizeRow['label_en'] ?? ''));
    $f = trim((string) ($sizeRow['label_fil'] ?? ''));
    $h = trim((string) ($sizeRow['label_hi'] ?? ''));
    $lang = strtolower($lang);
    $pick = static function (string $primary, string $fb1, string $fb2 = '', string $fb3 = ''): string {
        foreach ([$primary, $fb1, $fb2, $fb3] as $x) {
            if ($x !== '') {
                return $x;
            }
        }

        return '';
    };

    return match ($lang) {
        'en' => $pick($e, $a, $f, $h),
        'fil' => $pick($f, $e, $a, $h),
        'hi' => $pick($h, $e, $a, $f),
        default => $pick($a, $e, $f, $h),
    };
}
