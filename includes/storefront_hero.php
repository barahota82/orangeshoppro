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

/**
 * جمل التناوب تحت الشعار في الهيدر (عربي → إنجليزي → فلبيني → هندي): من الأدمن إن وُجدت، وإلا من الترجمة.
 *
 * @return list<string>
 */
function orange_storefront_header_tagline_cycle_resolved(PDO $pdo): array
{
    $order = ['ar', 'en', 'fil', 'hi'];
    $fromRow = ['ar' => '', 'en' => '', 'fil' => '', 'hi' => ''];
    try {
        if (orange_table_exists($pdo, 'storefront_home_hero')
            && orange_table_has_column($pdo, 'storefront_home_hero', 'header_tagline_ar')) {
            $st = $pdo->query(
                'SELECT header_tagline_ar, header_tagline_en, header_tagline_fil, header_tagline_hi
                 FROM storefront_home_hero WHERE id = 1 LIMIT 1'
            );
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row)) {
                foreach ($order as $code) {
                    $fromRow[$code] = trim((string) ($row['header_tagline_' . $code] ?? ''));
                }
            }
        }
    } catch (Throwable $e) {
        $fromRow = ['ar' => '', 'en' => '', 'fil' => '', 'hi' => ''];
    }
    $tr = get_translations();
    $out = [];
    foreach ($order as $code) {
        $db = $fromRow[$code] ?? '';
        $out[] = $db !== '' ? $db : (string) ($tr[$code]['storefront_tagline'] ?? '');
    }

    return $out;
}
