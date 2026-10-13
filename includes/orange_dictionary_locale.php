<?php
declare(strict_types=1);

/**
 * Locale rows for global dictionaries: colors, patterns, attributes, and options.
 * These parents have no country_id. Hex, attribute_key, option_key, and input_kind stay untranslated.
 * Does not load config.php and does not create tables.
 */

require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_content_locale_field.php';

const ORANGE_DICTIONARY_LOCALE_SPECS = [
    'color' => [
        'table' => 'color_dictionary',
        'field' => 'name',
        'columns' => ['ar' => 'name_ar', 'en' => 'name_en', 'fil' => 'name_fil', 'hi' => 'name_hi'],
    ],
    'pattern' => [
        'table' => 'pattern_dictionary',
        'field' => 'name',
        'columns' => ['ar' => 'name_ar', 'en' => 'name_en', 'fil' => 'name_fil', 'hi' => 'name_hi'],
    ],
    'catalog_attribute' => [
        'table' => 'catalog_attributes',
        'field' => 'label',
        'columns' => ['ar' => 'label_ar', 'en' => 'label_en', 'fil' => 'label_fil', 'hi' => 'label_hi'],
    ],
    'catalog_attribute_option' => [
        'table' => 'catalog_attribute_options',
        'field' => 'label',
        'columns' => ['ar' => 'label_ar', 'en' => 'label_en', 'fil' => 'label_fil', 'hi' => 'label_hi'],
        'value_column' => 'label_ar',
    ],
];

function orange_dictionary_locale_spec(string $kind): array
{
    $spec = ORANGE_DICTIONARY_LOCALE_SPECS[$kind] ?? null;
    if (!is_array($spec)) {
        throw new InvalidArgumentException('dictionary_kind_invalid');
    }

    return $spec;
}

function orange_dictionary_locale_use_payload(PDO $pdo, int $countryId, array $data): bool
{
    return $countryId > 0
        && array_key_exists('base_text', $data)
        && orange_content_locale_screen_ready($pdo, $countryId);
}

/**
 * Public save input must not carry internal preview-copy flags.
 *
 * @param array<string, mixed> $locales
 * @return array<string, mixed>
 */
function orange_dictionary_locale_strip_public(array $locales): array
{
    foreach ($locales as &$entry) {
        if (is_array($entry)) {
            unset($entry['preserve'], $entry['origin']);
        }
    }
    unset($entry);

    return $locales;
}

/**
 * @return array<string, array<string, mixed>>
 */
function orange_dictionary_locale_rows(PDO $pdo, string $kind, int $entityId): array
{
    $spec = orange_dictionary_locale_spec($kind);
    if ($entityId <= 0 || !orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $st = $pdo->prepare('SELECT locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute([$kind, $entityId, $spec['field']]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (is_array($row)) {
            $out[(string) ($row['locale_code'] ?? '')] = $row;
        }
    }

    return $out;
}

/**
 * @param list<int> $ids
 * @return array<int, array<string, array<string, mixed>>>
 */
function orange_dictionary_locale_rows_for(PDO $pdo, string $kind, array $ids): array
{
    $spec = orange_dictionary_locale_spec($kind);
    $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT entity_id, locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND field_key = ? AND entity_id IN (' . $placeholders . ')'
    );
    $st->execute(array_merge([$kind, $spec['field']], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $entityId = (int) ($row['entity_id'] ?? 0);
        $locale = (string) ($row['locale_code'] ?? '');
        if ($entityId > 0 && $locale !== '') {
            $out[$entityId][$locale] = $row;
        }
    }

    return $out;
}

function orange_dictionary_locale_effective(string $kind, array $record, array $stored): array
{
    $columns = orange_dictionary_locale_spec($kind)['columns'];
    $effective = $stored;
    foreach ($columns as $code => $col) {
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

function orange_dictionary_locale_base_text(string $kind, string $base, array $record, array $stored): string
{
    if ($base !== '' && array_key_exists($base, $stored) && is_array($stored[$base])) {
        return (string) ($stored[$base]['text_value'] ?? '');
    }
    $columns = orange_dictionary_locale_spec($kind)['columns'];
    if ($base !== '' && isset($columns[$base])) {
        return (string) ($record[$columns[$base]] ?? '');
    }

    return '';
}

function orange_dictionary_locale_panel_state(string $kind, array $record, array $stored, string $base): array
{
    $effective = orange_dictionary_locale_effective($kind, $record, $stored);
    $locales = [];
    foreach ($effective as $code => $row) {
        if ($code === $base || !is_array($row)) {
            continue;
        }
        $locales[(string) $code] = $row;
    }

    return [
        'id' => (int) ($record['id'] ?? 0),
        'base_text' => orange_dictionary_locale_base_text($kind, $base, $record, $stored),
        'locales' => $locales,
    ];
}

/**
 * @return array<int, string>
 */
function orange_dictionary_locale_label_map(PDO $pdo, int $countryId, string $kind): array
{
    $spec = orange_dictionary_locale_spec($kind);
    if (function_exists('orange_table_exists') && !orange_table_exists($pdo, $spec['table'])) {
        return [];
    }
    $roles = $countryId > 0 ? orange_country_locale_roles_read($pdo, $countryId) : ['mode' => 'legacy_unconfigured', 'base' => null];
    $base = (string) (($roles['mode'] ?? '') === 'configured' ? ($roles['base'] ?? '') : 'ar');
    $rows = $pdo->query('SELECT * FROM ' . $spec['table'])->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }
    $stored = orange_dictionary_locale_rows_for($pdo, $kind, $ids);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = orange_dictionary_locale_base_text($kind, $base, $row, $stored[$id] ?? []);
    }

    return $out;
}

function orange_dictionary_locale_guard(PDO $pdo, int $countryId, bool $localeMode, string $baseText, string $englishText, string $legacyAr, string $legacyEn, string $legacyMessage): ?string
{
    if (!$localeMode) {
        if (trim($legacyAr) === '' || trim($legacyEn) === '') {
            return $legacyMessage;
        }

        return null;
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    if ($base === '' || trim($baseText) === '') {
        return 'نص اللغة الأساسية مطلوب.';
    }
    if ($base !== 'en' && trim($englishText) === '') {
        return 'النص الإنجليزي المرجعي مطلوب.';
    }

    return null;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, string>
 */
function orange_dictionary_locale_preview(PDO $pdo, int $countryId, string $kind, int $entityId, array $data): array
{
    $spec = orange_dictionary_locale_spec($kind);
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    $record = [];
    foreach ($spec['columns'] as $col) {
        $record[$col] = '';
    }
    if ($entityId > 0) {
        $st = $pdo->prepare('SELECT * FROM ' . $spec['table'] . ' WHERE id = ?');
        $st->execute([$entityId]);
        $loaded = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($loaded)) {
            $record = $loaded;
        }
    }
    $stored = $entityId > 0 ? orange_dictionary_locale_rows($pdo, $kind, $entityId) : [];
    $effective = orange_dictionary_locale_effective($kind, $record, $stored);
    $locales = is_array($data['locales'] ?? null) ? orange_dictionary_locale_strip_public($data['locales']) : [];
    $baseText = trim((string) ($data['base_text'] ?? ''));
    $names = [];
    foreach ($spec['columns'] as $code => $col) {
        $current = isset($effective[$code]) ? (string) ($effective[$code]['text_value'] ?? '') : (string) ($record[$col] ?? '');
        if ($code === $base) {
            $names[$col] = $baseText;
            continue;
        }
        $incoming = $locales[$code] ?? null;
        if (!is_array($incoming) || empty($incoming['present'])) {
            $names[$col] = $current;
            continue;
        }
        if (!empty($incoming['clear'])) {
            $names[$col] = '';
            continue;
        }
        $names[$col] = trim((string) ($incoming['text'] ?? ''));
    }

    return $names;
}

function orange_dictionary_locale_upsert(PDO $pdo, string $kind, int $entityId, string $locale, string $text, string $origin, ?string $sourceLocale, ?string $sourceHash): void
{
    $field = orange_dictionary_locale_spec($kind)['field'];
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $existing->execute([$kind, $entityId, $field, $locale]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$kind, $entityId, $field, $locale, $text, $origin, $sourceLocale, $sourceHash]);
        return;
    }
    $upd = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    $upd->execute([$text, $origin, $sourceLocale, $sourceHash, (int) $id]);
}

function orange_dictionary_locale_write_column(PDO $pdo, string $kind, int $entityId, string $locale, string $text): void
{
    $spec = orange_dictionary_locale_spec($kind);
    if (!isset($spec['columns'][$locale])) {
        return;
    }
    // Older product_attribute_values rows store the option's label_ar; the translated title stays in the locale row.
    if (($spec['value_column'] ?? '') === $spec['columns'][$locale]) {
        return;
    }
    $st = $pdo->prepare('UPDATE ' . $spec['table'] . ' SET ' . $spec['columns'][$locale] . ' = ? WHERE id = ?');
    $st->execute([$text, $entityId]);
}

function orange_dictionary_locale_delete_entity(PDO $pdo, string $kind, int $entityId): void
{
    if ($entityId <= 0 || !orange_content_locale_table_ready($pdo)) {
        return;
    }
    $field = orange_dictionary_locale_spec($kind)['field'];
    $st = $pdo->prepare('DELETE FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute([$kind, $entityId, $field]);
}

function orange_dictionary_locale_apply_one(
    PDO $pdo,
    string $kind,
    int $entityId,
    string $locale,
    array $incoming,
    ?array $stored,
    string $englishText,
    string $baseLocale,
    string $baseText,
    array &$written
): string {
    if ($locale === $baseLocale) {
        return $englishText;
    }
    $storedOrigin = is_array($stored) ? (string) ($stored['origin'] ?? '') : '';
    $storedText = is_array($stored) ? (string) ($stored['text_value'] ?? '') : ($locale === 'en' ? $englishText : '');
    if (!empty($incoming['clear'])) {
        orange_dictionary_locale_write_column($pdo, $kind, $entityId, $locale, '');
        orange_dictionary_locale_upsert($pdo, $kind, $entityId, $locale, '', 'cleared', null, null);
        $written[$locale] = true;

        return $locale === 'en' ? '' : $englishText;
    }
    if (!empty($incoming['preserve']) || array_key_exists('origin', $incoming)) {
        throw new InvalidArgumentException('public_locale_flag');
    }
    $text = (string) ($incoming['text'] ?? '');
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
        orange_dictionary_locale_write_column($pdo, $kind, $entityId, $locale, $text);
        orange_dictionary_locale_upsert($pdo, $kind, $entityId, $locale, $text, 'machine', $expectedLocale, $expectedHash);
        $written[$locale] = true;

        return $locale === 'en' ? $text : $englishText;
    }
    if ($intent !== 'manual') {
        throw new InvalidArgumentException('intent_invalid');
    }
    orange_dictionary_locale_write_column($pdo, $kind, $entityId, $locale, $text);
    orange_dictionary_locale_upsert($pdo, $kind, $entityId, $locale, $text, 'manual', null, null);
    $written[$locale] = true;

    return $locale === 'en' ? $text : $englishText;
}

function orange_dictionary_locale_save(
    PDO $pdo,
    int $countryId,
    string $kind,
    int $entityId,
    string $baseText,
    array $locales,
    bool $ownTransaction = true
): void {
    $spec = orange_dictionary_locale_spec($kind);
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    if (($roles['mode'] ?? '') !== 'configured') {
        throw new RuntimeException('locale_settings_' . (string) ($roles['mode'] ?? 'missing'));
    }
    if ($entityId <= 0) {
        throw new InvalidArgumentException('entity_required');
    }
    if ($ownTransaction && $pdo->inTransaction()) {
        throw new RuntimeException('transaction_already_open');
    }
    $locales = orange_dictionary_locale_strip_public($locales);
    $base = (string) $roles['base'];
    $started = false;
    if ($ownTransaction) {
        $pdo->beginTransaction();
        $started = true;
    }
    try {
        $load = $pdo->prepare('SELECT * FROM ' . $spec['table'] . ' WHERE id = ?');
        $load->execute([$entityId]);
        $record = $load->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('entity_missing');
        }
        $stored = orange_dictionary_locale_rows($pdo, $kind, $entityId);
        $effective = orange_dictionary_locale_effective($kind, $record, $stored);
        $baseOrigin = 'manual';
        $previousBase = isset($effective[$base]) ? (string) ($effective[$base]['text_value'] ?? '') : '';
        if (isset($effective[$base]) && $baseText === $previousBase && $previousBase !== '') {
            $baseOrigin = (string) ($effective[$base]['origin'] ?? 'unknown');
        }
        orange_dictionary_locale_write_column($pdo, $kind, $entityId, $base, $baseText);
        orange_dictionary_locale_upsert($pdo, $kind, $entityId, $base, $baseText, $baseOrigin, null, null);
        $written = [$base => true];
        $englishText = $base === 'en' ? $baseText : (string) ($effective['en']['text_value'] ?? '');
        if (isset($locales['en']) && !empty($locales['en']['present'])) {
            $englishText = orange_dictionary_locale_apply_one($pdo, $kind, $entityId, 'en', $locales['en'], $effective['en'] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach ($locales as $code => $incoming) {
            if ($code === 'en' || $code === $base || !is_array($incoming) || empty($incoming['present'])) {
                continue;
            }
            $locale = orange_content_locale_code((string) $code);
            if ($locale === null) {
                throw new InvalidArgumentException('locale_invalid');
            }
            orange_dictionary_locale_apply_one($pdo, $kind, $entityId, $locale, $incoming, $effective[$locale] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach ($spec['columns'] as $code => $col) {
            if (isset($written[$code]) || isset($stored[$code])) {
                continue;
            }
            $columnText = (string) ($record[$col] ?? '');
            if ($columnText !== '') {
                orange_dictionary_locale_upsert($pdo, $kind, $entityId, $code, $columnText, 'unknown', null, null);
            }
        }
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

function orange_dictionary_locale_text(string $kind, string $lang, array $record, array $stored): string
{
    $code = orange_content_locale_code($lang) ?? $lang;
    if (array_key_exists($code, $stored) && is_array($stored[$code])) {
        return (string) ($stored[$code]['text_value'] ?? '');
    }
    $columns = orange_dictionary_locale_spec($kind)['columns'];
    if (isset($columns[$code])) {
        return (string) ($record[$columns[$code]] ?? '');
    }

    return '';
}
