<?php
declare(strict_types=1);

/**
 * Product name, description, SEO title, and SEO description rows.
 * entity_kind is product. Legacy columns commit with the matching row.
 * A stored row wins even when empty. Languages outside ar/en/fil/hi are rows only.
 * Does not create tables and does not load config.php.
 */

require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_content_locale_field.php';

const ORANGE_PRODUCT_CONTENT_KIND = 'product';

const ORANGE_PRODUCT_CONTENT_FIELDS = [
    'name' => [
        'ar' => 'name',
        'en' => 'name_en',
        'fil' => 'name_fil',
        'hi' => 'name_hi',
    ],
    'description' => [
        'ar' => 'description',
        'en' => 'description_en',
        'fil' => 'description_fil',
        'hi' => 'description_hi',
    ],
    'seo_meta_title' => [
        'ar' => 'seo_meta_title_ar',
        'en' => 'seo_meta_title_en',
        'fil' => 'seo_meta_title_fil',
        'hi' => 'seo_meta_title_hi',
    ],
    'seo_meta_description' => [
        'ar' => 'seo_meta_description_ar',
        'en' => 'seo_meta_description_en',
        'fil' => 'seo_meta_description_fil',
        'hi' => 'seo_meta_description_hi',
    ],
];

function orange_product_content_locale_use(PDO $pdo, int $countryId, array $data): bool
{
    return $countryId > 0
        && isset($data['content_locale'])
        && is_array($data['content_locale'])
        && orange_content_locale_screen_ready($pdo, $countryId);
}

function orange_product_content_locale_cap(string $field, string $text): string
{
    if ($field === 'seo_meta_title') {
        return function_exists('mb_substr') ? mb_substr($text, 0, 191, 'UTF-8') : substr($text, 0, 191);
    }
    if ($field === 'seo_meta_description') {
        return function_exists('mb_substr') ? mb_substr($text, 0, 500, 'UTF-8') : substr($text, 0, 500);
    }

    return $text;
}

/**
 * @return array<int, array<string, array<string, array<string, mixed>>>>
 */
function &orange_product_content_locale_cache(): array
{
    static $cache = [];

    return $cache;
}

/**
 * @param list<int> $ids
 * @return array<int, array<string, array<string, array<string, mixed>>>>
 */
function orange_product_content_locale_rows_for(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $fields = array_keys(ORANGE_PRODUCT_CONTENT_FIELDS);
    $fieldPlaceholders = implode(',', array_fill(0, count($fields), '?'));
    $st = $pdo->prepare(
        'SELECT entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash'
        . ' FROM orange_content_locale_text'
        . ' WHERE entity_kind = ? AND entity_id IN (' . $placeholders . ') AND field_key IN (' . $fieldPlaceholders . ')'
    );
    $st->execute(array_merge([ORANGE_PRODUCT_CONTENT_KIND], $ids, $fields));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entityId = (int) ($row['entity_id'] ?? 0);
        $field = (string) ($row['field_key'] ?? '');
        $locale = (string) ($row['locale_code'] ?? '');
        if ($entityId <= 0 || $field === '' || $locale === '' || !isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field])) {
            continue;
        }
        $out[$entityId][$field][$locale] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $products
 */
function orange_product_content_locale_prefetch(PDO $pdo, array $products): void
{
    $cache = &orange_product_content_locale_cache();
    $missing = [];
    foreach ($products as $product) {
        if (!is_array($product)) {
            continue;
        }
        $id = (int) ($product['id'] ?? 0);
        if ($id > 0 && !array_key_exists($id, $cache)) {
            $missing[$id] = $id;
        }
    }
    if ($missing === []) {
        return;
    }
    if (!orange_content_locale_table_ready($pdo)) {
        foreach ($missing as $id) {
            $cache[$id] = [];
        }

        return;
    }
    $loaded = orange_product_content_locale_rows_for($pdo, array_values($missing));
    foreach ($missing as $id) {
        $cache[$id] = $loaded[$id] ?? [];
    }
}

function orange_product_content_locale_ensure_loaded(array $product): void
{
    $id = (int) ($product['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $cache = &orange_product_content_locale_cache();
    if (array_key_exists($id, $cache) || !function_exists('db')) {
        return;
    }
    try {
        orange_product_content_locale_prefetch(db(), [$product]);
    } catch (Throwable $e) {
        $cache[$id] = [];
    }
}

/**
 * @return array<string, array<string, mixed>>
 */
function orange_product_content_locale_cached_field(array $product, string $field): array
{
    orange_product_content_locale_ensure_loaded($product);
    $id = (int) ($product['id'] ?? 0);
    $cache = &orange_product_content_locale_cache();
    $bag = $cache[$id][$field] ?? [];

    return is_array($bag) ? $bag : [];
}

function orange_product_content_locale_has_row(array $product, string $field, string $code): bool
{
    $bag = orange_product_content_locale_cached_field($product, $field);

    return array_key_exists($code, $bag) && is_array($bag[$code]);
}

function orange_product_content_locale_preferred(array $product, string $field, string $code): string
{
    $bag = orange_product_content_locale_cached_field($product, $field);
    if (array_key_exists($code, $bag) && is_array($bag[$code])) {
        return trim((string) ($bag[$code]['text_value'] ?? ''));
    }
    $column = ORANGE_PRODUCT_CONTENT_FIELDS[$field][$code] ?? '';
    if ($column === '') {
        return '';
    }

    return trim((string) ($product[$column] ?? ''));
}

function orange_product_content_locale_display_name(array $product): string
{
    $lang = function_exists('current_lang') ? (string) current_lang() : 'ar';
    $code = orange_content_locale_code($lang) ?? ($lang === 'ar' ? 'ar' : '');
    orange_product_content_locale_ensure_loaded($product);
    if ($code !== '' && orange_product_content_locale_has_row($product, 'name', $code)) {
        $direct = orange_product_content_locale_preferred($product, 'name', $code);
        if ($direct !== '') {
            return $direct;
        }
    }
    if ($lang === 'ar' || $code === 'ar') {
        if (!orange_product_content_locale_has_row($product, 'name', 'ar')) {
            $arabic = trim((string) ($product['name'] ?? ''));
            if ($arabic !== '') {
                return $arabic;
            }
        }
        foreach (['en', 'fil', 'hi'] as $fallback) {
            if ($fallback === $code && orange_product_content_locale_has_row($product, 'name', $code)) {
                continue;
            }
            $value = orange_product_content_locale_preferred($product, 'name', $fallback);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
    $try = ['en', 'ar', 'fil', 'hi'];
    if ($code !== '' && !in_array($code, $try, true) && !orange_product_content_locale_has_row($product, 'name', $code)) {
        $try = ['en', 'ar', 'fil', 'hi'];
    }
    foreach ($try as $fallback) {
        if ($fallback === $code && orange_product_content_locale_has_row($product, 'name', $code)) {
            continue;
        }
        $value = orange_product_content_locale_preferred($product, 'name', $fallback);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function orange_product_content_locale_display_description(array $product): string
{
    $lang = function_exists('current_lang') ? (string) current_lang() : 'ar';
    $code = orange_content_locale_code($lang) ?? ($lang === 'ar' ? 'ar' : '');
    orange_product_content_locale_ensure_loaded($product);
    if ($code !== '' && orange_product_content_locale_has_row($product, 'description', $code)) {
        $direct = orange_product_content_locale_preferred($product, 'description', $code);
        if ($direct !== '') {
            return $direct;
        }
    }
    if ($lang === 'ar' || $code === 'ar') {
        if (!orange_product_content_locale_has_row($product, 'description', 'ar')) {
            $arabic = trim((string) ($product['description'] ?? ''));
            if ($arabic !== '') {
                return $arabic;
            }
        }
        foreach (['en', 'fil', 'hi'] as $fallback) {
            $value = orange_product_content_locale_preferred($product, 'description', $fallback);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
    $try = match ($code) {
        'en' => ['en', 'ar'],
        'fil' => ['fil', 'ar'],
        'hi' => ['hi', 'ar'],
        default => ['ar'],
    };
    foreach ($try as $fallback) {
        if ($fallback === $code && orange_product_content_locale_has_row($product, 'description', $code)) {
            continue;
        }
        $value = orange_product_content_locale_preferred($product, 'description', $fallback);
        if ($value !== '') {
            return $value;
        }
    }

    return orange_product_content_locale_has_row($product, 'description', 'ar')
        ? ''
        : trim((string) ($product['description'] ?? ''));
}

function orange_product_content_locale_saved_seo(array $product, string $field): string
{
    $lang = function_exists('current_lang') ? (string) current_lang() : 'ar';
    $code = match ($lang) {
        'ar' => 'ar',
        'fil' => 'fil',
        'hi' => 'hi',
        default => 'en',
    };
    $parsed = orange_content_locale_code($lang);
    if ($parsed !== null && !isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field][$parsed])) {
        $code = $parsed;
    } elseif ($parsed !== null && isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field][$parsed])) {
        $code = $parsed;
    }
    orange_product_content_locale_ensure_loaded($product);
    if ($code !== '' && orange_product_content_locale_has_row($product, $field, $code)) {
        return orange_product_content_locale_preferred($product, $field, $code);
    }
    $column = ORANGE_PRODUCT_CONTENT_FIELDS[$field][$code] ?? '';
    if ($column !== '') {
        return trim((string) ($product[$column] ?? ''));
    }
    $fallback = ORANGE_PRODUCT_CONTENT_FIELDS[$field]['en'] ?? '';

    return $fallback === '' ? '' : trim((string) ($product[$fallback] ?? ''));
}

function orange_product_content_locale_display_seo_title(array $product): string
{
    $saved = orange_product_content_locale_saved_seo($product, 'seo_meta_title');
    if ($saved !== '') {
        return $saved;
    }

    return orange_product_content_locale_display_name($product);
}

function orange_product_content_locale_display_seo_description(array $product): string
{
    $saved = orange_product_content_locale_saved_seo($product, 'seo_meta_description');
    if ($saved !== '') {
        return $saved;
    }
    $plain = orange_product_content_locale_display_description($product);
    $plain = preg_replace('/\s+/u', ' ', strip_tags($plain));
    $plain = trim((string) $plain);
    if ($plain === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($plain, 'UTF-8') > 160 ? mb_substr($plain, 0, 157, 'UTF-8') . '...' : $plain;
    }

    return strlen($plain) > 160 ? substr($plain, 0, 157) . '...' : $plain;
}

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function orange_product_content_locale_rows(PDO $pdo, int $entityId): array
{
    if ($entityId <= 0) {
        return [];
    }
    $loaded = orange_product_content_locale_rows_for($pdo, [$entityId]);

    return $loaded[$entityId] ?? [];
}

/**
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array<string, array<string, mixed>>
 */
function orange_product_content_locale_effective(string $field, array $record, array $stored): array
{
    $effective = $stored;
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS[$field] as $code => $col) {
        if (isset($effective[$code])) {
            continue;
        }
        $columnText = (string) ($record[$col] ?? '');
        if ($columnText !== '') {
            $effective[$code] = [
                'locale_code' => $code,
                'text_value' => $columnText,
                'origin' => 'unknown',
                'source_locale' => null,
                'source_hash' => null,
                'virtual' => true,
            ];
        }
    }

    return $effective;
}

function orange_product_content_locale_base_text(string $field, string $base, array $record, array $stored): string
{
    if ($base !== '' && array_key_exists($base, $stored) && is_array($stored[$base])) {
        return (string) ($stored[$base]['text_value'] ?? '');
    }
    $column = ORANGE_PRODUCT_CONTENT_FIELDS[$field][$base] ?? '';
    if ($column !== '') {
        return (string) ($record[$column] ?? '');
    }

    return '';
}

/**
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array{id:int,base_text:string,locales:array<string,array<string,mixed>>}
 */
function orange_product_content_locale_panel_state(string $field, array $record, array $stored, string $base, int $entityId): array
{
    $effective = orange_product_content_locale_effective($field, $record, $stored);
    $locales = [];
    foreach ($effective as $code => $row) {
        if ($code === $base || !is_array($row)) {
            continue;
        }
        $locales[(string) $code] = $row;
    }

    return [
        'id' => $entityId,
        'base_text' => orange_product_content_locale_base_text($field, $base, $record, $stored),
        'locales' => $locales,
    ];
}

/**
 * @param array<string, mixed> $product
 * @return array<string, mixed>
 */
function orange_product_content_locale_attach(PDO $pdo, int $countryId, array $product): array
{
    $id = (int) ($product['id'] ?? 0);
    $storedAll = orange_product_content_locale_rows($pdo, $id);
    $cache = &orange_product_content_locale_cache();
    $cache[$id] = $storedAll;
    $roles = $countryId > 0 ? orange_country_locale_roles_read($pdo, $countryId) : ['mode' => 'legacy_unconfigured', 'base' => null];
    $base = (string) (($roles['mode'] ?? '') === 'configured' ? ($roles['base'] ?? '') : '');
    $view = [];
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
        $bag = $storedAll[$field] ?? [];
        foreach ($columns as $code => $col) {
            if (array_key_exists($code, $bag)) {
                $product[$col] = (string) ($bag[$code]['text_value'] ?? '');
            }
        }
        $view[$field] = orange_product_content_locale_panel_state($field, $product, $bag, $base, $id);
    }
    $product['content_locale'] = $view;

    return $product;
}

/**
 * @param list<array<string, mixed>> $products
 * @return list<array<string, mixed>>
 */
function orange_product_content_locale_overlay_list(PDO $pdo, array $products): array
{
    orange_product_content_locale_prefetch($pdo, $products);
    $cache = &orange_product_content_locale_cache();
    foreach ($products as $index => $product) {
        if (!is_array($product)) {
            continue;
        }
        $id = (int) ($product['id'] ?? 0);
        $storedAll = $cache[$id] ?? [];
        if (!is_array($storedAll)) {
            continue;
        }
        foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
            $bag = $storedAll[$field] ?? [];
            if (!is_array($bag)) {
                continue;
            }
            foreach ($columns as $code => $col) {
                if (array_key_exists($code, $bag) && is_array($bag[$code])) {
                    $product[$col] = (string) ($bag[$code]['text_value'] ?? '');
                }
            }
        }
        $products[$index] = $product;
    }

    return $products;
}

function orange_product_content_locale_guard_field(string $field, string $base, string $baseText, string $englishText, bool $allowExplicitEmpty): ?string
{
    $optional = $field !== 'name';
    if ($base === '') {
        return 'لغة أساسية غير مضبوطة.';
    }
    if (trim($baseText) === '') {
        if ($optional || $allowExplicitEmpty) {
            return null;
        }

        return 'اسم اللغة الأساسية مطلوب.';
    }
    if ($base !== 'en' && trim($englishText) === '') {
        return match ($field) {
            'description' => 'الوصف الإنجليزي المرجعي مطلوب.',
            'seo_meta_title' => 'عنوان SEO الإنجليزي المرجعي مطلوب.',
            'seo_meta_description' => 'وصف SEO الإنجليزي المرجعي مطلوب.',
            default => 'الاسم الإنجليزي المرجعي مطلوب.',
        };
    }

    return null;
}

/**
 * @param array<string, mixed> $block
 */
function orange_product_content_locale_english_text(string $base, string $baseText, array $block, array $effective): string
{
    if ($base === 'en') {
        return $baseText;
    }
    $incoming = $block['locales']['en'] ?? null;
    if (is_array($incoming) && !empty($incoming['present']) && empty($incoming['clear'])) {
        return orange_product_content_locale_cap('name', (string) ($incoming['text'] ?? ''));
    }

    return (string) ($effective['en']['text_value'] ?? '');
}

function orange_product_content_locale_name_conflict(PDO $pdo, int $productTypeId, int $excludeId, string $base, string $candidate): bool
{
    $candidate = trim($candidate);
    if ($candidate === '' || $productTypeId <= 0 || $base === '') {
        return false;
    }
    if (function_exists('orange_catalog_products_rows_for_arabic_name_scope')) {
        $unified = function_exists('orange_catalog_nav_use_unified') && orange_catalog_nav_use_unified($pdo);
        $siblings = orange_catalog_products_rows_for_arabic_name_scope($pdo, null, $productTypeId, $unified);
    } else {
        $st = $pdo->prepare('SELECT id, name, name_en, name_fil, name_hi FROM products WHERE product_type_id = ?');
        $st->execute([$productTypeId]);
        $siblings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    if (!is_array($siblings)) {
        return false;
    }
    $ids = [];
    foreach ($siblings as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }
    $stored = orange_product_content_locale_rows_for($pdo, $ids);
    if ($base === 'ar') {
        if (!function_exists('orange_rows_normalized_arabic_conflict')) {
            require_once __DIR__ . '/arabic_name_duplicate.php';
        }
        $comparable = [];
        foreach ($siblings as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $comparable[] = [
                'id' => $id,
                'name' => orange_product_content_locale_base_text('name', 'ar', $row, $stored[$id]['name'] ?? []),
            ];
        }

        return orange_rows_normalized_arabic_conflict($comparable, 'id', 'name', $candidate, $excludeId > 0 ? $excludeId : null);
    }
    foreach ($siblings as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $id === $excludeId) {
            continue;
        }
        if (trim(orange_product_content_locale_base_text('name', $base, $row, $stored[$id]['name'] ?? [])) === $candidate) {
            return true;
        }
    }

    return false;
}

function orange_product_content_locale_upsert(PDO $pdo, int $entityId, string $field, string $locale, string $text, string $origin, ?string $sourceLocale, ?string $sourceHash): void
{
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $existing->execute([ORANGE_PRODUCT_CONTENT_KIND, $entityId, $field, $locale]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([ORANGE_PRODUCT_CONTENT_KIND, $entityId, $field, $locale, $text, $origin, $sourceLocale, $sourceHash]);

        return;
    }
    $upd = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    $upd->execute([$text, $origin, $sourceLocale, $sourceHash, (int) $id]);
}

function orange_product_content_locale_write_column(PDO $pdo, int $entityId, string $field, string $locale, string $text): void
{
    $col = ORANGE_PRODUCT_CONTENT_FIELDS[$field][$locale] ?? '';
    if ($col === '' || $entityId <= 0) {
        return;
    }
    $st = $pdo->prepare('UPDATE products SET ' . $col . ' = ? WHERE id = ?');
    $st->execute([$text, $entityId]);
}

/**
 * @param array<string, mixed> $incoming
 * @param array<string, mixed>|null $stored
 * @param array<string, bool> $written
 */
function orange_product_content_locale_apply_one(
    PDO $pdo,
    int $entityId,
    string $field,
    string $locale,
    array $incoming,
    ?array $stored,
    string $englishText,
    string $baseLocale,
    string $baseText,
    array &$written,
    bool $write
): string {
    if ($locale === $baseLocale) {
        return $englishText;
    }
    $storedOrigin = is_array($stored) ? (string) ($stored['origin'] ?? '') : '';
    $storedText = is_array($stored) ? (string) ($stored['text_value'] ?? '') : ($locale === 'en' ? $englishText : '');
    if (!empty($incoming['clear'])) {
        if ($write) {
            orange_product_content_locale_write_column($pdo, $entityId, $field, $locale, '');
            orange_product_content_locale_upsert($pdo, $entityId, $field, $locale, '', 'cleared', null, null);
        }
        $written[$locale] = true;

        return $locale === 'en' ? '' : $englishText;
    }
    $text = orange_product_content_locale_cap($field, (string) ($incoming['text'] ?? ''));
    if (!empty($incoming['preserve'])) {
        $origin = (string) ($incoming['origin'] ?? 'unknown');
        if (!in_array($origin, ['manual', 'unknown', 'machine', 'cleared'], true)) {
            $origin = 'unknown';
        }
        $sourceLocale = trim((string) ($incoming['source_locale'] ?? ''));
        $sourceHash = trim((string) ($incoming['source_hash'] ?? ''));
        if ($write) {
            orange_product_content_locale_write_column($pdo, $entityId, $field, $locale, $origin === 'cleared' ? '' : $text);
            orange_product_content_locale_upsert(
                $pdo,
                $entityId,
                $field,
                $locale,
                $origin === 'cleared' ? '' : $text,
                $origin,
                $sourceLocale !== '' ? $sourceLocale : null,
                $sourceHash !== '' ? $sourceHash : null
            );
        }
        $written[$locale] = true;

        return $locale === 'en' ? ($origin === 'cleared' ? '' : $text) : $englishText;
    }
    $intent = (string) ($incoming['intent'] ?? 'manual');
    if ($intent === 'machine') {
        $explicit = !empty($incoming['explicit_replace']);
        $protected = in_array($storedOrigin, ['manual', 'unknown', 'cleared'], true);
        if ($protected && !$explicit) {
            return $locale === 'en' ? $storedText : $englishText;
        }
        if (trim($text) === '') {
            return $locale === 'en' ? $storedText : $englishText;
        }
        $claimedHash = trim((string) ($incoming['source_hash'] ?? ''));
        $claimedLocale = trim((string) ($incoming['source_locale'] ?? ''));
        if ($claimedHash === '' || $claimedLocale === '') {
            return $locale === 'en' ? $storedText : $englishText;
        }
        if ($locale === 'en') {
            $expectedLocale = $baseLocale;
            $expectedHash = orange_content_text_hash($baseText);
        } else {
            $expectedLocale = 'en';
            $expectedHash = orange_content_text_hash($englishText);
        }
        if ($claimedLocale !== $expectedLocale || $claimedHash !== $expectedHash) {
            return $locale === 'en' ? $storedText : $englishText;
        }
        if ($write) {
            orange_product_content_locale_write_column($pdo, $entityId, $field, $locale, $text);
            orange_product_content_locale_upsert($pdo, $entityId, $field, $locale, $text, 'machine', $expectedLocale, $expectedHash);
        }
        $written[$locale] = true;

        return $locale === 'en' ? $text : $englishText;
    }
    if ($intent !== 'manual') {
        throw new InvalidArgumentException('intent_invalid');
    }
    if ($write) {
        orange_product_content_locale_write_column($pdo, $entityId, $field, $locale, $text);
        orange_product_content_locale_upsert($pdo, $entityId, $field, $locale, $text, 'manual', null, null);
    }
    $written[$locale] = true;

    return $locale === 'en' ? $text : $englishText;
}

/**
 * @param array<string, mixed> $block
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array<string, string>
 */
function orange_product_content_locale_apply_field(
    PDO $pdo,
    int $entityId,
    string $field,
    string $base,
    array $block,
    array $record,
    array $stored,
    bool $write
): array {
    $columns = [];
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS[$field] as $code => $col) {
        if (array_key_exists($code, $stored)) {
            $columns[$code] = (string) ($stored[$code]['text_value'] ?? '');
        } else {
            $columns[$code] = (string) ($record[$col] ?? '');
        }
    }
    $effective = orange_product_content_locale_effective($field, $record, $stored);
    $baseText = orange_product_content_locale_cap($field, (string) ($block['base_text'] ?? ''));
    $baseOrigin = 'manual';
    $previousBase = isset($effective[$base]) ? (string) ($effective[$base]['text_value'] ?? '') : '';
    if (isset($effective[$base]) && $baseText === $previousBase && $previousBase !== '') {
        $baseOrigin = (string) ($effective[$base]['origin'] ?? 'unknown');
        if (!in_array($baseOrigin, ['manual', 'unknown', 'machine', 'cleared'], true)) {
            $baseOrigin = 'unknown';
        }
    }
    if (isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field][$base])) {
        $columns[$base] = $baseText;
    }
    if ($write) {
        orange_product_content_locale_write_column($pdo, $entityId, $field, $base, $baseText);
        orange_product_content_locale_upsert($pdo, $entityId, $field, $base, $baseText, $baseOrigin, null, null);
    }
    $written = [$base => true];
    $locales = is_array($block['locales'] ?? null) ? $block['locales'] : [];
    $englishText = $base === 'en' ? $baseText : (string) ($effective['en']['text_value'] ?? '');
    if (isset($locales['en']) && is_array($locales['en']) && !empty($locales['en']['present'])) {
        $englishText = orange_product_content_locale_apply_one(
            $pdo,
            $entityId,
            $field,
            'en',
            $locales['en'],
            $effective['en'] ?? null,
            $englishText,
            $base,
            $baseText,
            $written,
            $write
        );
        if (isset($written['en']) && isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field]['en'])) {
            $columns['en'] = $englishText;
        }
    }
    foreach ($locales as $code => $incoming) {
        if ($code === 'en' || $code === $base || !is_array($incoming) || empty($incoming['present'])) {
            continue;
        }
        $locale = orange_content_locale_code((string) $code);
        if ($locale === null) {
            throw new InvalidArgumentException('locale_invalid');
        }
        $nextEnglish = orange_product_content_locale_apply_one(
            $pdo,
            $entityId,
            $field,
            $locale,
            $incoming,
            $effective[$locale] ?? null,
            $englishText,
            $base,
            $baseText,
            $written,
            $write
        );
        if ($locale !== 'en') {
            $englishText = $nextEnglish;
        }
        if (isset($written[$locale]) && isset(ORANGE_PRODUCT_CONTENT_FIELDS[$field][$locale])) {
            if (!empty($incoming['clear'])) {
                $columns[$locale] = '';
            } elseif ((string) ($incoming['intent'] ?? 'manual') === 'manual') {
                $columns[$locale] = orange_product_content_locale_cap($field, (string) ($incoming['text'] ?? ''));
            } elseif ((string) ($incoming['intent'] ?? '') === 'machine') {
                $protected = in_array((string) (($effective[$locale]['origin'] ?? '')), ['manual', 'unknown', 'cleared'], true);
                if ($protected && empty($incoming['explicit_replace'])) {
                    $columns[$locale] = (string) ($effective[$locale]['text_value'] ?? $columns[$locale]);
                } else {
                    $columns[$locale] = orange_product_content_locale_cap($field, (string) ($incoming['text'] ?? ''));
                }
            }
        }
    }
    if ($write) {
        foreach (ORANGE_PRODUCT_CONTENT_FIELDS[$field] as $code => $col) {
            if ($code === $base || isset($written[$code])) {
                continue;
            }
            if (isset($stored[$code]) && is_array($stored[$code])) {
                orange_product_content_locale_write_column(
                    $pdo,
                    $entityId,
                    $field,
                    $code,
                    (string) ($stored[$code]['text_value'] ?? '')
                );
                continue;
            }
            $columnText = (string) ($record[$col] ?? '');
            if ($columnText !== '') {
                orange_product_content_locale_upsert($pdo, $entityId, $field, $code, $columnText, 'unknown', null, null);
                orange_product_content_locale_write_column($pdo, $entityId, $field, $code, $columnText);
                continue;
            }
            orange_product_content_locale_write_column($pdo, $entityId, $field, $code, '');
        }
    }

    return $columns;
}

/**
 * @param array<string, mixed> $pack
 * @return array<string, string>
 */
function orange_product_content_locale_preview_columns(PDO $pdo, int $countryId, int $entityId, array $pack): array
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    $record = [];
    $storedAll = $entityId > 0 ? orange_product_content_locale_rows($pdo, $entityId) : [];
    if ($entityId > 0) {
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$entityId]);
        $loaded = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($loaded)) {
            $record = $loaded;
        }
    }
    $out = [];
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
        foreach ($columns as $col) {
            $out[$col] = (string) ($record[$col] ?? '');
        }
        if (!isset($pack[$field]) || !is_array($pack[$field])) {
            continue;
        }
        $applied = orange_product_content_locale_apply_field(
            $pdo,
            $entityId,
            $field,
            $base,
            $pack[$field],
            $record,
            $storedAll[$field] ?? [],
            false
        );
        foreach ($columns as $code => $col) {
            if (array_key_exists($code, $applied)) {
                $out[$col] = $applied[$code];
            }
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $pack
 */
function orange_product_content_locale_assert_pack(PDO $pdo, int $countryId, int $entityId, array $pack): void
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    if (($roles['mode'] ?? '') !== 'configured') {
        throw new RuntimeException('locale_settings_' . (string) ($roles['mode'] ?? 'missing'));
    }
    $base = (string) $roles['base'];
    $preview = orange_product_content_locale_preview_columns($pdo, $countryId, $entityId, $pack);
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
        if (!isset($pack[$field]) || !is_array($pack[$field])) {
            continue;
        }
        $block = $pack[$field];
        $baseText = (string) ($block['base_text'] ?? '');
        $allowEmpty = !empty($block['base_explicit_empty']);
        $english = $base === 'en' ? $baseText : (string) ($preview[$columns['en']] ?? '');
        $guard = orange_product_content_locale_guard_field($field, $base, $baseText, $english, $allowEmpty);
        if ($guard !== null) {
            throw new InvalidArgumentException($guard);
        }
    }
}

/**
 * @return array<string, string>
 */
function orange_product_content_locale_blank_columns(): array
{
    $out = [];
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $columns) {
        foreach ($columns as $col) {
            $out[$col] = '';
        }
    }

    return $out;
}

/**
 * @param array<string, mixed> $record
 * @return array<string, string>
 */
function orange_product_content_locale_column_snapshot(array $record): array
{
    $out = orange_product_content_locale_blank_columns();
    foreach ($out as $col => $value) {
        if (array_key_exists($col, $record)) {
            $out[$col] = (string) $record[$col];
        }
    }

    return $out;
}

/**
 * Draft preview keeps omitted source rows, including a language with no column.
 * Posted present locales replace that copy. The source product is not written.
 *
 * @param array<string, mixed> $posted
 * @return array<string, mixed>
 */
function orange_product_content_locale_preview_pack(PDO $pdo, int $countryId, int $sourceId, array $posted): array
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) (($roles['mode'] ?? '') === 'configured' ? ($roles['base'] ?? '') : '');
    $source = [];
    if ($sourceId > 0) {
        $st = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $st->execute([$sourceId]);
        $loaded = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($loaded) && (int) ($loaded['is_preview_draft'] ?? 0) !== 1) {
            $source = $loaded;
        } else {
            $sourceId = 0;
        }
    }
    $storedAll = $sourceId > 0 ? orange_product_content_locale_rows($pdo, $sourceId) : [];
    $pack = [];
    foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
        $postedBlock = (isset($posted[$field]) && is_array($posted[$field])) ? $posted[$field] : null;
        $stored = is_array($storedAll[$field] ?? null) ? $storedAll[$field] : [];
        $baseText = $postedBlock !== null
            ? (string) ($postedBlock['base_text'] ?? '')
            : orange_product_content_locale_base_text($field, $base, $source, $stored);
        $locales = [];
        foreach ($stored as $code => $row) {
            if ((string) $code === $base || !is_array($row)) {
                continue;
            }
            $origin = (string) ($row['origin'] ?? 'unknown');
            $locales[(string) $code] = [
                'present' => true,
                'text' => (string) ($row['text_value'] ?? ''),
                'intent' => 'manual',
                'clear' => $origin === 'cleared',
                'explicit_replace' => false,
                'preserve' => true,
                'origin' => $origin,
                'source_hash' => (string) ($row['source_hash'] ?? ''),
                'source_locale' => (string) ($row['source_locale'] ?? ''),
            ];
        }
        foreach ($columns as $code => $col) {
            if ($code === $base || isset($locales[$code]) || isset($stored[$code])) {
                continue;
            }
            $columnText = (string) ($source[$col] ?? '');
            if ($columnText === '') {
                continue;
            }
            $locales[$code] = [
                'present' => true,
                'text' => $columnText,
                'intent' => 'manual',
                'clear' => false,
                'explicit_replace' => false,
                'preserve' => true,
                'origin' => 'unknown',
                'source_hash' => '',
                'source_locale' => '',
            ];
        }
        if ($postedBlock !== null) {
            $postedLocales = is_array($postedBlock['locales'] ?? null) ? $postedBlock['locales'] : [];
            foreach ($postedLocales as $code => $incoming) {
                if (!is_array($incoming) || empty($incoming['present'])) {
                    continue;
                }
                $incoming['preserve'] = false;
                $locales[(string) $code] = $incoming;
            }
        }
        $block = ['base_text' => $baseText, 'locales' => $locales];
        if ($postedBlock !== null && !empty($postedBlock['base_explicit_empty'])) {
            $block['base_explicit_empty'] = true;
        }
        $pack[$field] = $block;
    }

    return $pack;
}

function orange_product_content_locale_save(PDO $pdo, int $countryId, int $entityId, array $pack, bool $ownTransaction = true, ?array $priorColumns = null, bool $strict = true): void
{
    if ($entityId <= 0) {
        throw new InvalidArgumentException('entity_required');
    }
    if ($strict) {
        orange_product_content_locale_assert_pack($pdo, $countryId, $entityId, $pack);
    }
    if ($ownTransaction && $pdo->inTransaction()) {
        throw new RuntimeException('transaction_already_open');
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) $roles['base'];
    $started = false;
    if ($ownTransaction) {
        $pdo->beginTransaction();
        $started = true;
    }
    try {
        $load = $pdo->prepare('SELECT * FROM products WHERE id = ?');
        $load->execute([$entityId]);
        $record = $load->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('entity_missing');
        }
        if (is_array($priorColumns)) {
            foreach ($priorColumns as $col => $value) {
                if (is_string($col)) {
                    $record[$col] = (string) $value;
                }
            }
        }
        $storedAll = orange_product_content_locale_rows($pdo, $entityId);
        foreach (ORANGE_PRODUCT_CONTENT_FIELDS as $field => $columns) {
            if (!isset($pack[$field]) || !is_array($pack[$field])) {
                continue;
            }
            orange_product_content_locale_apply_field(
                $pdo,
                $entityId,
                $field,
                $base,
                $pack[$field],
                $record,
                $storedAll[$field] ?? [],
                true
            );
        }
        $cache = &orange_product_content_locale_cache();
        unset($cache[$entityId]);
        if ($started) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_product_content_locale_delete_entity(PDO $pdo, int $entityId): void
{
    if ($entityId <= 0 || !orange_content_locale_table_ready($pdo)) {
        return;
    }
    $fields = array_keys(ORANGE_PRODUCT_CONTENT_FIELDS);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $st = $pdo->prepare(
        'DELETE FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key IN (' . $placeholders . ')'
    );
    $st->execute(array_merge([ORANGE_PRODUCT_CONTENT_KIND, $entityId], $fields));
    $cache = &orange_product_content_locale_cache();
    unset($cache[$entityId]);
}
