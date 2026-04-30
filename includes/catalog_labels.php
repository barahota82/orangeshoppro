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

function orange_colorway_display_label(PDO $pdo, ?int $primaryId, ?int $secondaryId, ?int $primaryPatternId = null, ?int $secondaryPatternId = null): string
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

    if ($colorSeg !== '' && $patSeg !== '') {
        return $colorSeg . ' — ' . $patSeg;
    }
    if ($colorSeg !== '') {
        return $colorSeg;
    }

    return $patSeg;
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
