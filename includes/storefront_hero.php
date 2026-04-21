<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * لاحقة عمود اللغة في الجدول القديم storefront_home_hero (احتياطي).
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
 * جمل الـ hero للصفحة الرئيسية: صفوف نشطة من storefront_copy_lines حسب لغة الزائر.
 *
 * @return list<string>
 */
function orange_storefront_home_hero_lines_resolved(PDO $pdo, string $lang): array
{
    $out = [];
    try {
        if (orange_table_exists($pdo, 'storefront_copy_lines')) {
            $st = $pdo->prepare(
                'SELECT * FROM storefront_copy_lines WHERE scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
            );
            $st->execute(['home_hero']);
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

    if ($out === [] && orange_table_exists($pdo, 'storefront_home_hero')) {
        $suffix = orange_storefront_home_hero_lang_suffix($lang);
        try {
            $st = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1');
            $legacy = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($legacy)) {
                for ($i = 1; $i <= 3; ++$i) {
                    $t = trim((string) ($legacy['line_' . $i . '_' . $suffix] ?? ''));
                    if ($t !== '') {
                        $out[] = $t;
                    }
                }
            }
        } catch (Throwable $e) {
            $out = [];
        }
    }

    return orange_storefront_copy_pad_rotation($out);
}

/**
 * جمل التناوب تحت الشعار: صفوف نشطة header_tagline، النص حسب لغة الواجهة الحالية.
 *
 * @return list<string>
 */
function orange_storefront_header_tagline_cycle_resolved(PDO $pdo, string $lang): array
{
    $out = [];
    try {
        if (orange_table_exists($pdo, 'storefront_copy_lines')) {
            $st = $pdo->prepare(
                'SELECT * FROM storefront_copy_lines WHERE scope = ? AND is_active = 1 ORDER BY sort_order ASC, id ASC'
            );
            $st->execute(['header_tagline']);
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

    if ($out === [] && orange_table_exists($pdo, 'storefront_home_hero')
        && orange_table_has_column($pdo, 'storefront_home_hero', 'header_tagline_ar')) {
        try {
            $st = $pdo->query(
                'SELECT header_tagline_ar, header_tagline_en, header_tagline_fil, header_tagline_hi
                 FROM storefront_home_hero WHERE id = 1 LIMIT 1'
            );
            $legacy = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($legacy)) {
                $col = match ($lang) {
                    'ar' => 'header_tagline_ar',
                    'fil' => 'header_tagline_fil',
                    'hi' => 'header_tagline_hi',
                    default => 'header_tagline_en',
                };
                $t = trim((string) ($legacy[$col] ?? ''));
                if ($t !== '') {
                    $out[] = $t;
                }
            }
        } catch (Throwable $e) {
            $out = [];
        }
    }

    if ($out === []) {
        return ['', ''];
    }

    return orange_storefront_copy_pad_rotation($out);
}
