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

/**
 * مفتاح option_key فريد ضمن سمة واحدة (جدول catalog_attribute_options).
 */
function orange_catalog_attribute_option_key_allocate_unique(
    PDO $pdo,
    int $catalogAttributeId,
    string $labelEn,
    string $labelAr,
    int $excludeOptionId
): string {
    if ($catalogAttributeId <= 0) {
        return 'opt_' . bin2hex(random_bytes(4));
    }
    $base = orange_catalog_attribute_key_suggest_from_labels($labelEn, $labelAr);
    $dup = $pdo->prepare(
        'SELECT id FROM catalog_attribute_options WHERE catalog_attribute_id = ? AND option_key = ? AND id <> ? LIMIT 1'
    );
    for ($i = 0; $i < 500; $i++) {
        $candidate = $base;
        if ($i > 0) {
            $suf = '_' . (string) $i;
            $maxLen = 80 - strlen($suf);
            $candidate = substr($base, 0, max(2, $maxLen)) . $suf;
        }
        if (!preg_match('/^[a-z][a-z0-9_-]{1,79}$/', $candidate)) {
            $candidate = 'opt_' . bin2hex(random_bytes(4));
            if (strlen($candidate) > 80) {
                $candidate = substr($candidate, 0, 80);
            }
        }
        $dup->execute([$catalogAttributeId, $candidate, max(0, $excludeOptionId)]);
        if (!$dup->fetchColumn()) {
            return $candidate;
        }
    }

    return 'opt_' . bin2hex(random_bytes(8));
}

/**
 * استبدال كل خيارات سمة كتالوج (حذف ثم إدراج). القيم الفارغة تُتخطى.
 * القيمة المحفوظة على المنتج (value_raw) تُطابق label_ar للخيار.
 *
 * @param list<array<string,mixed>>|mixed $options
 */
function orange_catalog_attribute_options_replace(PDO $pdo, int $catalogAttributeId, mixed $options): void
{
    if ($catalogAttributeId <= 0 || !orange_table_exists($pdo, 'catalog_attribute_options')) {
        return;
    }
    if (!is_array($options)) {
        return;
    }

    $seenAr = [];
    $normalized = [];
    foreach ($options as $row) {
        if (!is_array($row)) {
            continue;
        }
        $labelAr = trim((string) ($row['label_ar'] ?? ''));
        if ($labelAr === '') {
            continue;
        }
        $nk = function_exists('mb_strtolower') ? mb_strtolower($labelAr, 'UTF-8') : strtolower($labelAr);
        if (isset($seenAr[$nk])) {
            throw new \InvalidArgumentException('قيمة عربية مكررة في قائمة الخيارات لنفس السمة.');
        }
        $seenAr[$nk] = true;
        $normalized[] = [
            'label_ar' => $labelAr,
            'label_en' => trim((string) ($row['label_en'] ?? '')),
            'label_fil' => trim((string) ($row['label_fil'] ?? '')),
            'label_hi' => trim((string) ($row['label_hi'] ?? '')),
        ];
    }
    if (count($normalized) > 120) {
        throw new \InvalidArgumentException('عدد خيارات السمة يتجاوز الحد المسموح (120).');
    }

    $pdo->prepare('DELETE FROM catalog_attribute_options WHERE catalog_attribute_id = ?')->execute([$catalogAttributeId]);

    if ($normalized === []) {
        return;
    }

    $ins = $pdo->prepare(
        'INSERT INTO catalog_attribute_options (
            catalog_attribute_id, option_key, label_ar, label_en, label_fil, label_hi, sort_order, is_active
        ) VALUES (?,?,?,?,?,?,?,1)'
    );
    $sort = 0;
    foreach ($normalized as $one) {
        $sort++;
        $key = orange_catalog_attribute_option_key_allocate_unique(
            $pdo,
            $catalogAttributeId,
            $one['label_en'],
            $one['label_ar'],
            0
        );
        $ins->execute([
            $catalogAttributeId,
            $key,
            $one['label_ar'],
            $one['label_en'],
            $one['label_fil'],
            $one['label_hi'],
            $sort,
        ]);
    }
}
