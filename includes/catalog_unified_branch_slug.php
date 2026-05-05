<?php

declare(strict_types=1);

/**
 * Slug لشجرة الكتالوج الموحّد (sections / categories / subcategories).
 * يُطابق نمط التحقق في admin/api/unified_catalog/save_*.php: حرف أو رقم أولاً، ثم [a-z0-9_-].
 */

function orange_catalog_unified_branch_slug_sanitize(string $raw): string
{
    $t = strtolower(trim($raw));
    if ($t !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,190}$/', $t)) {
        return '';
    }

    return $t;
}

/** مقطع slug واحد (أحرف صغيرة وأرقام وشرطات). */
function orange_catalog_unified_branch_slug_segment(string $raw): string
{
    $t = strtolower(trim($raw));
    if ($t === '') {
        return '';
    }
    $t = (string) preg_replace('/[^a-z0-9]+/', '-', $t);
    $t = (string) preg_replace('/-+/', '-', $t);

    return trim($t, '-');
}

/**
 * يبني slug من مقاطع (مثلاً slug القسم الداخلي ثم الإنجليزي) ثم يعود لـ derive عند الفراغ.
 *
 * @param list<string> $prefixSegments
 */
function orange_catalog_unified_branch_slug_from_prefixes_and_names(array $prefixSegments, string $nameEn, string $nameAr): string
{
    $parts = [];
    foreach ($prefixSegments as $seg) {
        $s = orange_catalog_unified_branch_slug_segment((string) $seg);
        if ($s !== '') {
            $parts[] = $s;
        }
    }
    $en = orange_catalog_unified_branch_slug_segment($nameEn);
    if ($en !== '') {
        $parts[] = $en;
    }
    $base = implode('-', $parts);
    if ($base !== '') {
        return substr($base, 0, 191);
    }

    return orange_catalog_unified_branch_slug_derive($nameEn, $nameAr);
}

/**
 * slug مرسل من الواجهة إن صالحاً؛ وإلا توليد من المقاطع + الإنجليزي/العربي.
 *
 * @param list<string> $prefixSegments
 */
function orange_catalog_unified_branch_slug_resolve_prefixed(string $postedSlug, array $prefixSegments, string $nameEn, string $nameAr): string
{
    $posted = orange_catalog_unified_branch_slug_sanitize($postedSlug);
    if ($posted !== '') {
        return $posted;
    }
    $cand = orange_catalog_unified_branch_slug_from_prefixes_and_names($prefixSegments, $nameEn, $nameAr);
    $san = orange_catalog_unified_branch_slug_sanitize($cand);

    return $san !== '' ? $san : orange_catalog_unified_branch_slug_derive($nameEn, $nameAr);
}

function orange_catalog_unified_branch_slug_derive(string $nameEn, string $nameAr): string
{
    $en = strtolower(trim($nameEn));
    $en = (string) preg_replace('/[^a-z0-9]+/', '-', $en);
    $en = (string) preg_replace('/-+/', '-', $en);
    $en = trim($en, '-');
    if ($en !== '') {
        return substr($en, 0, 191);
    }
    $arTry = strtolower(trim($nameAr));
    $arTry = (string) preg_replace('/[^a-z0-9]+/', '-', $arTry);
    $arTry = (string) preg_replace('/-+/', '-', $arTry);
    $arTry = trim($arTry, '-');
    if ($arTry !== '' && preg_match('/^[a-z0-9]/', $arTry)) {
        return substr($arTry, 0, 191);
    }

    return 'n-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

/**
 * slug المرسل من الواجهة إن كان صالحاً؛ وإلا توليد من الأسماء.
 */
function orange_catalog_unified_branch_slug_resolve(string $postedSlug, string $nameEn, string $nameAr): string
{
    $s = orange_catalog_unified_branch_slug_sanitize($postedSlug);
    if ($s !== '') {
        return $s;
    }
    $d = orange_catalog_unified_branch_slug_derive($nameEn, $nameAr);

    return orange_catalog_unified_branch_slug_sanitize($d) ?: ('n-' . substr(bin2hex(random_bytes(4)), 0, 8));
}

/**
 * يضمن عدم التصادم تحت نفس الأب (يُضاف -2، -3، … عند الحاجة).
 *
 * @param callable(string):bool $isTaken يستدعى بالـ slug المجرّب؛ يرجع true إن كان محجوزاً
 */
function orange_catalog_unified_branch_slug_allocate(string $baseSlug, callable $isTaken): string
{
    $base = orange_catalog_unified_branch_slug_sanitize($baseSlug);
    if ($base === '') {
        $base = 'n-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
    for ($n = 0; $n < 250; $n++) {
        $candidate = $n === 0 ? $base : orange_catalog_unified_branch_slug_with_suffix($base, $n + 1);
        if (! $isTaken($candidate)) {
            return $candidate;
        }
    }

    return $base . '-' . bin2hex(random_bytes(3));
}

function orange_catalog_unified_branch_slug_with_suffix(string $base, int $suffixNum): string
{
    $suffix = '-' . (string) max(2, $suffixNum);
    $maxBase = 191 - strlen($suffix);
    if ($maxBase < 1) {
        return substr('n-' . bin2hex(random_bytes(6)), 0, 191);
    }
    if (strlen($base) > $maxBase) {
        $base = substr($base, 0, $maxBase);
    }
    $base = rtrim($base, '-');

    return $base === '' ? substr($suffix, 1) . bin2hex(random_bytes(2)) : ($base . $suffix);
}
