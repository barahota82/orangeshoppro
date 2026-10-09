<?php
declare(strict_types=1);

/**
 * Name rows for the global catalogue tree and product types.
 * Parents have no country_id. field_key is name. Legacy name_* columns
 * commit with the matching row. Does not load config.php.
 */

require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_content_locale_field.php';

const ORANGE_CATALOG_NAME_COLUMNS = [
    'ar' => 'name_ar',
    'en' => 'name_en',
    'fil' => 'name_fil',
    'hi' => 'name_hi',
];

const ORANGE_CATALOG_NAME_LOCALE_KINDS = [
    'catalog_section' => 'catalog_sections',
    'catalog_category' => 'catalog_categories',
    'catalog_subcategory' => 'catalog_subcategories',
    'product_type' => 'product_types',
];

function orange_catalog_name_locale_table(string $kind): string
{
    $table = ORANGE_CATALOG_NAME_LOCALE_KINDS[$kind] ?? '';
    if ($table === '' || !preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('catalog_kind_invalid');
    }

    return $table;
}

function orange_catalog_name_locale_use_payload(PDO $pdo, int $countryId, array $data): bool
{
    return $countryId > 0
        && array_key_exists('base_text', $data)
        && orange_content_locale_screen_ready($pdo, $countryId);
}

/**
 * @return array<string, array<string, mixed>>
 */
function orange_catalog_name_locale_rows(PDO $pdo, string $kind, int $entityId): array
{
    orange_catalog_name_locale_table($kind);
    $st = $pdo->prepare('SELECT locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute([$kind, $entityId, 'name']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) $row['locale_code']] = $row;
    }

    return $out;
}

/**
 * @param list<int> $ids
 * @return array<int, array<string, array<string, mixed>>>
 */
function orange_catalog_name_locale_rows_for(PDO $pdo, string $kind, array $ids): array
{
    orange_catalog_name_locale_table($kind);
    $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT entity_id, locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND field_key = ? AND entity_id IN (' . $placeholders . ')'
    );
    $st->execute(array_merge([$kind, 'name'], $ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $entityId = (int) ($row['entity_id'] ?? 0);
        $locale = (string) ($row['locale_code'] ?? '');
        if ($entityId <= 0 || $locale === '') {
            continue;
        }
        $out[$entityId][$locale] = $row;
    }

    return $out;
}

/**
 * An existing locale row wins, including an empty value.
 * A missing row leaves the legacy column.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function orange_catalog_name_locale_overlay(PDO $pdo, string $kind, array $rows): array
{
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }
    $stored = orange_catalog_name_locale_rows_for($pdo, $kind, $ids);
    foreach ($rows as $index => $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        $bag = $stored[$id] ?? null;
        if (!is_array($bag)) {
            continue;
        }
        $localeNames = [];
        foreach ($bag as $code => $item) {
            if (!is_array($item)) {
                continue;
            }
            $localeNames[(string) $code] = (string) ($item['text_value'] ?? '');
        }
        if ($localeNames !== []) {
            $row['locale_names'] = $localeNames;
        }
        foreach (ORANGE_CATALOG_NAME_COLUMNS as $code => $col) {
            if (array_key_exists($code, $bag)) {
                $row[$col] = (string) ($bag[$code]['text_value'] ?? '');
            }
        }
        $rows[$index] = $row;
    }

    return $rows;
}

/**
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array<string, array<string, mixed>>
 */
function orange_catalog_name_locale_effective(array $record, array $stored): array
{
    $effective = $stored;
    foreach (ORANGE_CATALOG_NAME_COLUMNS as $code => $col) {
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

function orange_catalog_name_locale_base_text(string $base, array $record, array $stored): string
{
    if ($base !== '' && array_key_exists($base, $stored) && is_array($stored[$base])) {
        return (string) ($stored[$base]['text_value'] ?? '');
    }
    if ($base !== '' && isset(ORANGE_CATALOG_NAME_COLUMNS[$base])) {
        return (string) ($record[ORANGE_CATALOG_NAME_COLUMNS[$base]] ?? '');
    }

    return '';
}

/**
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array{id:int,base_text:string,locales:array<string,array<string,mixed>>,slug:string}
 */
function orange_catalog_name_locale_panel_state(array $record, array $stored, string $base): array
{
    $effective = orange_catalog_name_locale_effective($record, $stored);
    $locales = [];
    foreach ($effective as $code => $row) {
        if ($code === $base || !is_array($row)) {
            continue;
        }
        $locales[(string) $code] = $row;
    }

    return [
        'id' => (int) ($record['id'] ?? 0),
        'base_text' => orange_catalog_name_locale_base_text($base, $record, $stored),
        'locales' => $locales,
        'slug' => (string) ($record['slug'] ?? ''),
    ];
}

/**
 * @return array<int, string>
 */
function orange_catalog_name_locale_label_map(PDO $pdo, int $countryId, string $kind): array
{
    $table = orange_catalog_name_locale_table($kind);
    if (function_exists('orange_table_exists') && !orange_table_exists($pdo, $table)) {
        return [];
    }
    $roles = $countryId > 0 ? orange_country_locale_roles_read($pdo, $countryId) : ['mode' => 'legacy_unconfigured', 'base' => null];
    $base = (string) (($roles['mode'] ?? '') === 'configured' ? ($roles['base'] ?? '') : 'ar');
    $rows = $pdo->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }
    $stored = orange_catalog_name_locale_rows_for($pdo, $kind, $ids);
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = orange_catalog_name_locale_base_text($base, $row, $stored[$id] ?? []);
    }

    return $out;
}

function orange_catalog_name_locale_guard(PDO $pdo, int $countryId, bool $localeMode, string $baseText, string $englishText, string $legacyAr, string $legacyEn, string $legacyMessage, bool $allowExplicitEmpty = false): ?string
{
    if (!$localeMode) {
        if (trim($legacyAr) === '' || trim($legacyEn) === '') {
            return $legacyMessage;
        }

        return null;
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    if ($base === '' || (trim($baseText) === '' && !$allowExplicitEmpty)) {
        return 'اسم اللغة الأساسية مطلوب.';
    }
    if ($base !== 'en' && trim($englishText) === '') {
        return 'الاسم الإنجليزي المرجعي مطلوب.';
    }

    return null;
}

function orange_catalog_name_locale_names_conflict(PDO $pdo, string $kind, string $parentColumn, int $parentId, int $excludeId, string $base, string $candidate): bool
{
    $candidate = trim($candidate);
    if ($candidate === '' || $parentId <= 0 || $base === '') {
        return false;
    }
    $allowed = ['department_id', 'catalog_section_id', 'catalog_category_id', 'catalog_subcategory_id'];
    if (!in_array($parentColumn, $allowed, true)) {
        throw new InvalidArgumentException('parent_column_invalid');
    }
    $table = orange_catalog_name_locale_table($kind);
    $st = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE ' . $parentColumn . ' = ?');
    $st->execute([$parentId]);
    $siblings = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = [];
    foreach ($siblings as $row) {
        if (is_array($row)) {
            $ids[] = (int) ($row['id'] ?? 0);
        }
    }
    $stored = orange_catalog_name_locale_rows_for($pdo, $kind, $ids);
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
                'name_ar' => orange_catalog_name_locale_base_text('ar', $row, $stored[$id] ?? []),
            ];
        }

        return orange_rows_normalized_arabic_conflict($comparable, 'id', 'name_ar', $candidate, $excludeId > 0 ? $excludeId : null);
    }
    foreach ($siblings as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || $id === $excludeId) {
            continue;
        }
        if (trim(orange_catalog_name_locale_base_text($base, $row, $stored[$id] ?? [])) === $candidate) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{base_text:string,locales:array<string,array<string,mixed>>,roles:array<string,mixed>,slug:string,sort_order:int}
 */
function orange_catalog_name_locale_read(PDO $pdo, int $countryId, string $kind, int $entityId): array
{
    $table = orange_catalog_name_locale_table($kind);
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $st = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = ?');
    $st->execute([$entityId]);
    $record = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($record)) {
        throw new RuntimeException('entity_missing');
    }
    $stored = orange_catalog_name_locale_rows($pdo, $kind, $entityId);
    $effective = orange_catalog_name_locale_effective($record, $stored);
    $base = (string) ($roles['base'] ?? '');

    return [
        'base_text' => orange_catalog_name_locale_base_text($base, $record, $stored),
        'locales' => $effective,
        'roles' => $roles,
        'slug' => (string) ($record['slug'] ?? ''),
        'sort_order' => (int) ($record['sort_order'] ?? 0),
    ];
}

function orange_catalog_name_locale_upsert(PDO $pdo, string $kind, int $entityId, string $locale, string $text, string $origin, ?string $sourceLocale, ?string $sourceHash): void
{
    orange_catalog_name_locale_table($kind);
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $existing->execute([$kind, $entityId, 'name', $locale]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$kind, $entityId, 'name', $locale, $text, $origin, $sourceLocale, $sourceHash]);
        return;
    }
    $upd = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    $upd->execute([$text, $origin, $sourceLocale, $sourceHash, (int) $id]);
}

function orange_catalog_name_locale_write_column(PDO $pdo, string $table, int $entityId, string $locale, string $text): void
{
    if (!isset(ORANGE_CATALOG_NAME_COLUMNS[$locale])) {
        return;
    }
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('table_invalid');
    }
    $col = ORANGE_CATALOG_NAME_COLUMNS[$locale];
    $st = $pdo->prepare('UPDATE ' . $table . ' SET ' . $col . ' = ? WHERE id = ?');
    $st->execute([$text, $entityId]);
}

/**
 * @param array<string, mixed> $incoming
 * @param array<string, mixed>|null $stored
 * @param array<string, bool> $written
 */
function orange_catalog_name_locale_apply_one(
    PDO $pdo,
    string $table,
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
        orange_catalog_name_locale_write_column($pdo, $table, $entityId, $locale, '');
        orange_catalog_name_locale_upsert($pdo, $kind, $entityId, $locale, '', 'cleared', null, null);
        $written[$locale] = true;

        return $locale === 'en' ? '' : $englishText;
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
        orange_catalog_name_locale_write_column($pdo, $table, $entityId, $locale, $text);
        orange_catalog_name_locale_upsert($pdo, $kind, $entityId, $locale, $text, 'machine', $expectedLocale, $expectedHash);
        $written[$locale] = true;

        return $locale === 'en' ? $text : $englishText;
    }
    if ($intent !== 'manual') {
        throw new InvalidArgumentException('intent_invalid');
    }
    orange_catalog_name_locale_write_column($pdo, $table, $entityId, $locale, $text);
    orange_catalog_name_locale_upsert($pdo, $kind, $entityId, $locale, $text, 'manual', null, null);
    $written[$locale] = true;

    return $locale === 'en' ? $text : $englishText;
}

/**
 * @param array<string, array<string, mixed>> $locales
 */
function orange_catalog_name_locale_save(
    PDO $pdo,
    int $countryId,
    string $kind,
    int $entityId,
    string $baseText,
    array $locales,
    bool $ownTransaction = true
): void {
    $table = orange_catalog_name_locale_table($kind);
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
    $base = (string) $roles['base'];
    $started = false;
    if ($ownTransaction) {
        $pdo->beginTransaction();
        $started = true;
    }
    try {
        $load = $pdo->prepare('SELECT * FROM ' . $table . ' WHERE id = ?');
        $load->execute([$entityId]);
        $record = $load->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record)) {
            throw new RuntimeException('entity_missing');
        }
        $stored = orange_catalog_name_locale_rows($pdo, $kind, $entityId);
        $effective = orange_catalog_name_locale_effective($record, $stored);
        $baseOrigin = 'manual';
        $previousBase = isset($effective[$base]) ? (string) ($effective[$base]['text_value'] ?? '') : '';
        if (isset($effective[$base]) && $baseText === $previousBase && $previousBase !== '') {
            $baseOrigin = (string) ($effective[$base]['origin'] ?? 'unknown');
        }
        orange_catalog_name_locale_write_column($pdo, $table, $entityId, $base, $baseText);
        orange_catalog_name_locale_upsert($pdo, $kind, $entityId, $base, $baseText, $baseOrigin, null, null);
        $written = [$base => true];
        $englishText = $base === 'en'
            ? $baseText
            : (string) ($effective['en']['text_value'] ?? '');
        if (isset($locales['en']) && !empty($locales['en']['present'])) {
            $englishText = orange_catalog_name_locale_apply_one($pdo, $table, $kind, $entityId, 'en', $locales['en'], $effective['en'] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach ($locales as $code => $incoming) {
            if ($code === 'en' || $code === $base || !is_array($incoming) || empty($incoming['present'])) {
                continue;
            }
            $locale = orange_content_locale_code((string) $code);
            if ($locale === null) {
                throw new InvalidArgumentException('locale_invalid');
            }
            orange_catalog_name_locale_apply_one($pdo, $table, $kind, $entityId, $locale, $incoming, $effective[$locale] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach (ORANGE_CATALOG_NAME_COLUMNS as $code => $col) {
            if (isset($written[$code]) || isset($stored[$code])) {
                continue;
            }
            $columnText = (string) ($record[$col] ?? '');
            if ($columnText !== '') {
                orange_catalog_name_locale_upsert($pdo, $kind, $entityId, $code, $columnText, 'unknown', null, null);
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

/**
 * Names used by duplicate and slug checks. Omission keeps the stored column.
 *
 * @param array<string, mixed> $data
 * @return array{name_ar:string,name_en:string,name_fil:string,name_hi:string}
 */
function orange_catalog_name_locale_preview(PDO $pdo, int $countryId, string $kind, int $entityId, array $data): array
{
    $table = orange_catalog_name_locale_table($kind);
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $base = (string) ($roles['base'] ?? '');
    $record = [
        'name_ar' => '',
        'name_en' => '',
        'name_fil' => '',
        'name_hi' => '',
    ];
    if ($entityId > 0) {
        $st = $pdo->prepare('SELECT name_ar, name_en, name_fil, name_hi FROM ' . $table . ' WHERE id = ?');
        $st->execute([$entityId]);
        $loaded = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($loaded)) {
            $record = $loaded;
        }
    }
    $stored = $entityId > 0 ? orange_catalog_name_locale_rows($pdo, $kind, $entityId) : [];
    $effective = orange_catalog_name_locale_effective($record, $stored);
    $locales = is_array($data['locales'] ?? null) ? $data['locales'] : [];
    $baseText = trim((string) ($data['base_text'] ?? ''));
    $names = [];
    foreach (ORANGE_CATALOG_NAME_COLUMNS as $code => $col) {
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
        $names[$col] = (string) ($incoming['text'] ?? '');
    }

    return [
        'name_ar' => trim((string) ($names['name_ar'] ?? '')),
        'name_en' => trim((string) ($names['name_en'] ?? '')),
        'name_fil' => trim((string) ($names['name_fil'] ?? '')),
        'name_hi' => trim((string) ($names['name_hi'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $data
 */
function orange_catalog_name_locale_kept_slug(PDO $pdo, string $table, int $entityId, string $posted): string
{
    if ($entityId <= 0 || trim($posted) !== '') {
        return $posted;
    }
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('table_invalid');
    }
    $st = $pdo->prepare('SELECT slug FROM ' . $table . ' WHERE id = ?');
    $st->execute([$entityId]);
    $slug = $st->fetchColumn();

    return is_string($slug) ? $slug : $posted;
}

function orange_catalog_name_locale_commit_payload(PDO $pdo, int $countryId, string $kind, int $entityId, array $data): void
{
    $locales = is_array($data['locales'] ?? null) ? $data['locales'] : [];
    orange_catalog_name_locale_save(
        $pdo,
        $countryId,
        $kind,
        $entityId,
        trim((string) ($data['base_text'] ?? '')),
        $locales,
        false
    );
}
