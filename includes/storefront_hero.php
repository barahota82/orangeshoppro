<?php

declare(strict_types=1);

require_once __DIR__ . '/catalog_schema.php';

/**
 * لاحقة عمود اللغة في جدول storefront_home_hero.
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
 * ثلاث جمل الـ hero للصفحة الرئيسية: من قاعدة البيانات لكل لغة، أو من الترجمة الافتراضية إن كانت الثلاث فارغة لتلك اللغة.
 *
 * @return list<string>
 */
function orange_storefront_home_hero_lines_resolved(PDO $pdo, string $lang): array
{
    $suffix = orange_storefront_home_hero_lang_suffix($lang);
    $fromDb = ['', '', ''];

    try {
        if (orange_table_exists($pdo, 'storefront_home_hero')) {
            $st = $pdo->query('SELECT * FROM storefront_home_hero WHERE id = 1 LIMIT 1');
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row)) {
                for ($i = 1; $i <= 3; $i++) {
                    $col = 'line_' . $i . '_' . $suffix;
                    $fromDb[$i - 1] = trim((string) ($row[$col] ?? ''));
                }
            }
        }
    } catch (Throwable $e) {
        $fromDb = ['', '', ''];
    }

    if ($fromDb[0] === '' && $fromDb[1] === '' && $fromDb[2] === '') {
        $translations = get_translations();
        $b = $translations[$lang] ?? $translations['en'];

        return [
            (string) ($b['home_hero_line_1'] ?? ''),
            (string) ($b['home_hero_line_2'] ?? ''),
            (string) ($b['home_hero_line_3'] ?? ''),
        ];
    }

    return $fromDb;
}
