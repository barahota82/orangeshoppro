<?php

declare(strict_types=1);

/**
 * تطبيع نص ليصبح مرشحاً لـ attribute_key (حرف صغير أولاً، ثم a-z0-9_-).
 */
function orange_catalog_attribute_key_normalize_latin(string $raw): ?string
{
    $s = strtolower(trim($raw));
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/[^a-z0-9_-]+/', '_', $s);
    if ($s === null) {
        return null;
    }
    $s = preg_replace('/_+/', '_', $s);
    $s = trim($s, '_');
    if ($s === '') {
        return null;
    }
    if (strlen($s) > 80) {
        $s = substr($s, 0, 80);
    }
    $s = rtrim($s, '_');
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^[a-z]/', $s)) {
        $s = 'k_' . ltrim($s, '_-');
    }
    if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $s)) {
        return null;
    }

    return $s;
}

/**
 * اقتراح مفتاح من العناوين؛ بدون لاتيني كافٍ يُستخدم بادئة ca_ من تجزئة العربي.
 */
function orange_catalog_attribute_key_suggest_from_labels(string $labelEn, string $labelAr): string
{
    $fromEn = orange_catalog_attribute_key_normalize_latin($labelEn);
    if ($fromEn !== null && $fromEn !== '') {
        return $fromEn;
    }
    $ar = trim($labelAr);
    if ($ar !== '') {
        $bin = hash('sha256', $ar, true);
        if ($bin !== false && $bin !== '') {
            return 'ca_' . bin2hex(substr($bin, 0, 8));
        }
    }

    return 'ca_' . bin2hex(random_bytes(5));
}

/**
 * مفتاح فريد في catalog_attributes (يُقصّ الأساس ليتسع لاحقة _N عند التكرار).
 */
function orange_catalog_attribute_key_allocate_unique(PDO $pdo, string $labelEn, string $labelAr, int $excludeId): string
{
    $base = orange_catalog_attribute_key_suggest_from_labels($labelEn, $labelAr);
    $dup = $pdo->prepare(
        'SELECT id FROM catalog_attributes WHERE attribute_key = ? AND id <> ? LIMIT 1'
    );
    for ($i = 0; $i < 500; $i++) {
        $candidate = $base;
        if ($i > 0) {
            $suf = '_' . (string) $i;
            $maxLen = 80 - strlen($suf);
            $candidate = substr($base, 0, max(2, $maxLen)) . $suf;
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $candidate)) {
            $candidate = 'ca_' . bin2hex(random_bytes(4));
            if (strlen($candidate) > 80) {
                $candidate = substr($candidate, 0, 80);
            }
        }
        $dup->execute([$candidate, max(0, $excludeId)]);
        if (!$dup->fetchColumn()) {
            return $candidate;
        }
    }

    return 'ca_' . bin2hex(random_bytes(8));
}
