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

function orange_colorway_display_label(PDO $pdo, ?int $primaryId, ?int $secondaryId): string
{
    $p = orange_dict_color($pdo, $primaryId);
    $s = orange_dict_color($pdo, $secondaryId);
    $parts = [];
    if ($p) {
        $parts[] = trim((string)($p['name_ar'] !== '' ? $p['name_ar'] : $p['name_en']));
    }
    if ($s) {
        $parts[] = trim((string)($s['name_ar'] !== '' ? $s['name_ar'] : $s['name_en']));
    }
    return implode(' / ', array_filter($parts, static fn ($x) => $x !== ''));
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
