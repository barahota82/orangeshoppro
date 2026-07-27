<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/admin_settings_country.php';

function orange_storefront_copy_has_country_column(PDO $pdo): bool
{
    return orange_table_exists($pdo, 'storefront_copy_lines')
        && orange_table_has_column($pdo, 'storefront_copy_lines', 'country_id');
}

/**
 * لاحقة عمود اللغة في الجدول القديم storefront_home_hero.
 * معزول: لا يُستخدَم للقراءة العامة (قرار مالك 2026-07-27 — لا fallback عالمي).
 * الجدول/الصف يبقيان للتوافق التاريخي فقط؛ لا حذف ولا تعديل في هذا الإصلاح.
 */
function orange_storefront_home_hero_lang_suffix(string $lang): string
{
    return match ($lang) {
        'ar' => 'ar',
        'fil' => 'fil',
        'hi' => 'hi',
        default => 'en',
    };
}

/**
 * @param array<string, mixed> $row
 */
function orange_storefront_copy_text_for_lang(array $row, string $lang): string
{
    $col = match ($lang) {
        'ar' => 'text_ar',
        'fil' => 'text_fil',
        'hi' => 'text_hi',
        default => 'text_en',
    };

    return trim((string) ($row[$col] ?? ''));
}

/**
 * مقتطف قصير لعرض الجملة في جدول الأدمن.
 *
 * @param array<string, mixed> $row
 */
function orange_storefront_copy_preview_snippet(array $row): string
{
    foreach (['text_ar', 'text_en', 'text_fil', 'text_hi'] as $col) {
        $t = trim((string) ($row[$col] ?? ''));
        if ($t !== '') {
            if (mb_strlen($t, 'UTF-8') > 80) {
                return mb_substr($t, 0, 80, 'UTF-8') . '…';
            }

            return $t;
        }
    }

    return '—';
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function orange_storefront_copy_pad_rotation(array $lines): array
{
    if (count($lines) === 0) {
        return [];
    }
    if (count($lines) === 1) {
        return [$lines[0], $lines[0]];
    }

    return $lines;
}

/**
 * جمل الـ hero للصفحة الرئيسية: صفوف نشطة من storefront_copy_lines حسب لغة الزائر ودولة المتجر فقط.
 * لا قراءة عامة من storefront_home_hero (جدول عالمي قديم — معزول).
 *
 * @return list<string>
 */
function orange_storefront_home_hero_lines_resolved(PDO $pdo, string $lang, ?int $countryId = null): array
{
    $out = [];
    $forStorefront = $countryId === null;
    $cid = $forStorefront
        ? orange_storefront_settings_country_id($pdo)
        : orange_admin_settings_effective_country_id($pdo, $countryId);
    try {
        if (orange_table_exists($pdo, 'storefront_copy_lines')) {
            if (orange_storefront_copy_has_country_column($pdo) && $cid > 0) {
                $st = $pdo->prepare(
                    'SELECT * FROM storefront_copy_lines WHERE country_id = ? AND scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
                );
                $st->execute([$cid, 'home_hero']);
            } else {
                $st = $pdo->prepare(
                    'SELECT * FROM storefront_copy_lines WHERE scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
                );
                $st->execute(['home_hero']);
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    break;
                }
                $t = orange_storefront_copy_text_for_lang($row, $lang);
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        }
    } catch (Throwable $e) {
        $out = [];
    }

    /* فارغ صالح: لا fallback عالمي ولا زرع صفوف. قيمتان فارغتان لتفادي أخطاء التناوب. */
    if ($out === []) {
        return ['', ''];
    }

    return orange_storefront_copy_pad_rotation($out);
}

/**
 * استخراج نصوص تناوب الهيدر من صف واحد بترتيب ثابت: عربي → إنجليزي → فلبيني → هندي (تخطي الفارغ).
 *
 * @param array<string, mixed> $row
 *
 * @return list<string>
 */
function orange_storefront_header_tagline_row_lang_cycle(array $row): array
{
    if (array_key_exists('text_ar', $row)) {
        $cols = ['text_ar', 'text_en', 'text_fil', 'text_hi'];
    } elseif (array_key_exists('header_tagline_ar', $row)) {
        $cols = ['header_tagline_ar', 'header_tagline_en', 'header_tagline_fil', 'header_tagline_hi'];
    } else {
        return [];
    }
    $out = [];
    foreach ($cols as $col) {
        $t = trim((string) ($row[$col] ?? ''));
        if ($t !== '') {
            $out[] = $t;
        }
    }

    return $out;
}

/**
 * جمل التناوب تحت الشعار: لكل صف نشط header_tagline يُعرض التناوب بكل اللغات غير الفارغة.
 *
 * @return list<string>
 */
function orange_storefront_header_tagline_cycle_resolved(PDO $pdo, ?int $countryId = null): array
{
    static $cached = [];
    $forStorefront = $countryId === null;
    $cid = $forStorefront
        ? orange_storefront_settings_country_id($pdo)
        : orange_admin_settings_effective_country_id($pdo, $countryId);
    $cacheKey = ($forStorefront ? 'sf:' : 'adm:') . (string) $cid;
    if (isset($cached[$cacheKey])) {
        return $cached[$cacheKey];
    }
    $out = [];
    try {
        if (orange_table_exists($pdo, 'storefront_copy_lines')) {
            if (orange_storefront_copy_has_country_column($pdo) && $cid > 0) {
                $st = $pdo->prepare(
                    'SELECT * FROM storefront_copy_lines WHERE country_id = ? AND scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
                );
                $st->execute([$cid, 'header_tagline']);
            } else {
                $st = $pdo->prepare(
                    'SELECT * FROM storefront_copy_lines WHERE scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
                );
                $st->execute(['header_tagline']);
            }
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                if (!is_array($row)) {
                    break;
                }
                foreach (orange_storefront_header_tagline_row_lang_cycle($row) as $t) {
                    $out[] = $t;
                }
            }
        }
    } catch (Throwable $e) {
        $out = [];
    }

    /* لا قراءة عامة من storefront_home_hero — محتوى الهيدر من storefront_copy_lines للدولة فقط. */
    if ($out === []) {
        $cached[$cacheKey] = ['', ''];

        return $cached[$cacheKey];
    }

    $cached[$cacheKey] = orange_storefront_copy_pad_rotation($out);

    return $cached[$cacheKey];
}
