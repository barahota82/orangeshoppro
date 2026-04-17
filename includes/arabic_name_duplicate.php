<?php

declare(strict_types=1);

/**
 * Normalize Arabic names for duplicate detection (admin catalog).
 * Treats visually/orthographically similar spellings as the same key.
 */
if (!function_exists('orange_normalize_arabic_name')) {
    function orange_normalize_arabic_name(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }
        $s = str_replace("\u{0640}", '', $s);
        $s = preg_replace('/[\x{200C}\x{200D}\x{FEFF}]/u', '', $s) ?? $s;
        $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}\x{06E7}\x{06E8}\x{06EA}-\x{06ED}]/u', '', $s) ?? $s;
        $s = str_replace(
            ["\u{0622}", "\u{0623}", "\u{0625}", "\u{0671}", "\u{0672}", "\u{0673}", "\u{0675}"],
            "\u{0627}",
            $s
        );
        $s = str_replace("\u{0649}", "\u{064A}", $s);
        $s = str_replace("\u{0629}", "\u{0647}", $s);
        $s = str_replace("\u{0624}", "\u{0648}", $s);
        $s = str_replace("\u{0626}", "\u{064A}", $s);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

        return $s;
    }
}

if (!function_exists('orange_arabic_duplicate_blocked_message')) {
    function orange_arabic_duplicate_blocked_message(): string
    {
        return 'لا يمكن الحفظ: الاسم العربي مكرر أو يطابق اسماً موجوداً عند اعتبار الحروف المتشابهة '
            . '(أ إ آ ا — ه ة — ي ى، وأيضاً بعد حذف التشكيل وطي المسافات). '
            . 'استخدم اسماً أوضح أو أضف تمييزاً بسيطاً.';
    }
}

if (!function_exists('orange_rows_normalized_arabic_conflict')) {
    /**
     * @param array<int,array<string,mixed>> $rows
     */
    function orange_rows_normalized_arabic_conflict(
        array $rows,
        string $idKey,
        string $nameKey,
        string $candidateRaw,
        ?int $excludeId = null
    ): bool {
        $target = orange_normalize_arabic_name($candidateRaw);
        if ($target === '') {
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $rid = (int)($row[$idKey] ?? 0);
            if ($excludeId !== null && $rid === $excludeId) {
                continue;
            }
            $val = (string)($row[$nameKey] ?? '');
            if (orange_normalize_arabic_name($val) === $target) {
                return true;
            }
        }

        return false;
    }
}
