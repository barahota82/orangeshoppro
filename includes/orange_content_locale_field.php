<?php
declare(strict_types=1);

/**
 * Shared locale text rows for one field on an existing entity.
 * Four legacy columns and their rows commit together.
 * Other locales are rows only. Does not create tables and does not load config.php.
 */

require_once __DIR__ . '/orange_content_languages.php';

const ORANGE_CONTENT_TEXT_COLUMNS = [
    'ar' => 'text_ar',
    'en' => 'text_en',
    'fil' => 'text_fil',
    'hi' => 'text_hi',
];

function orange_content_locale_table_ready(PDO $pdo): bool
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute(['orange_content_locale_text']);
            return (bool) $st->fetchColumn();
        }
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute(['orange_content_locale_text']);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function orange_content_locale_screen_ready(PDO $pdo, int $countryId): bool
{
    if ($countryId <= 0 || !orange_content_locale_table_ready($pdo)) {
        return false;
    }
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
            $st->execute(['orange_country_locale_role']);
            if (!(bool) $st->fetchColumn()) {
                return false;
            }
        } else {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $st->execute(['orange_country_locale_role']);
            if (!(bool) $st->fetchColumn()) {
                return false;
            }
        }
    } catch (Throwable $e) {
        return false;
    }
    $roles = orange_country_locale_roles_read($pdo, $countryId);

    return ($roles['mode'] ?? '') === 'configured';
}

/**
 * @param array<string, array<string, mixed>>|null $localeRows
 */
function orange_content_locale_pick(string $lang, ?array $localeRows, array $columnValues, bool $arabicFallback): string
{
    $code = orange_content_locale_code($lang) ?? 'en';
    if (is_array($localeRows) && array_key_exists($code, $localeRows)) {
        $row = $localeRows[$code];
        return trim((string) (is_array($row) ? ($row['text_value'] ?? '') : ''));
    }
    if ($code === 'ar' || $code === 'en' || $code === 'fil' || $code === 'hi') {
        $text = trim((string) ($columnValues[$code] ?? ''));
        if ($text !== '' || $code === 'ar' || !$arabicFallback) {
            return $text;
        }

        return trim((string) ($columnValues['ar'] ?? ''));
    }

    return $arabicFallback
        ? trim((string) ($columnValues['ar'] ?? ''))
        : trim((string) ($columnValues['en'] ?? ''));
}

/**
 * @param list<int> $ids
 * @return array<int, array<string, array<string, mixed>>>
 */
function orange_content_locale_rows_for_entities(PDO $pdo, string $entityKind, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
    if ($ids === [] || !orange_content_locale_table_ready($pdo)) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare(
        'SELECT entity_id, locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND field_key = ? AND entity_id IN (' . $placeholders . ')'
    );
    $st->execute(array_merge([$entityKind, 'text'], $ids));
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
 * @return array<string, array<string, mixed>>
 */
function orange_content_locale_field_rows(PDO $pdo, string $entityKind, int $entityId): array
{
    $st = $pdo->prepare('SELECT locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute([$entityKind, $entityId, 'text']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) $row['locale_code']] = $row;
    }

    return $out;
}

/**
 * @param array<string, mixed> $record
 * @param array<string, array<string, mixed>> $stored
 * @return array<string, array<string, mixed>>
 */
function orange_content_locale_field_effective(array $record, array $stored): array
{
    $effective = $stored;
    foreach (ORANGE_CONTENT_TEXT_COLUMNS as $code => $col) {
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

function orange_content_locale_field_upsert(PDO $pdo, string $entityKind, int $entityId, string $locale, string $text, string $origin, ?string $sourceLocale, ?string $sourceHash): void
{
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $existing->execute([$entityKind, $entityId, 'text', $locale]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$entityKind, $entityId, 'text', $locale, $text, $origin, $sourceLocale, $sourceHash]);
        return;
    }
    $upd = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    $upd->execute([$text, $origin, $sourceLocale, $sourceHash, (int) $id]);
}

function orange_content_locale_field_write_column(PDO $pdo, string $table, int $entityId, string $locale, string $text): void
{
    if (!isset(ORANGE_CONTENT_TEXT_COLUMNS[$locale])) {
        return;
    }
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('table_invalid');
    }
    $col = ORANGE_CONTENT_TEXT_COLUMNS[$locale];
    $st = $pdo->prepare('UPDATE ' . $table . ' SET ' . $col . ' = ? WHERE id = ?');
    $st->execute([$text, $entityId]);
}

/**
 * @param array<string, mixed> $record
 * @return array{base_text:string,locales:array<string,array<string,mixed>>,roles:array<string,mixed>}
 */
function orange_content_locale_field_read(PDO $pdo, int $countryId, string $entityKind, int $entityId, array $record): array
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $stored = orange_content_locale_field_rows($pdo, $entityKind, $entityId);
    $effective = orange_content_locale_field_effective($record, $stored);
    $base = (string) ($roles['base'] ?? '');
    $baseText = '';
    if ($base !== '' && isset($effective[$base]) && is_array($effective[$base]) && empty($effective[$base]['virtual'])) {
        $baseText = (string) ($effective[$base]['text_value'] ?? '');
    } elseif ($base !== '' && isset(ORANGE_CONTENT_TEXT_COLUMNS[$base])) {
        $baseText = (string) ($record[ORANGE_CONTENT_TEXT_COLUMNS[$base]] ?? '');
    }

    return [
        'base_text' => $baseText,
        'locales' => $effective,
        'roles' => $roles,
    ];
}

function orange_content_locale_field_delete(PDO $pdo, string $entityKind, int $entityId): void
{
    if ($entityId <= 0 || !orange_content_locale_table_ready($pdo)) {
        return;
    }
    $st = $pdo->prepare('DELETE FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute([$entityKind, $entityId, 'text']);
}

/**
 * @param array<string, array<string, mixed>> $locales
 */
function orange_content_locale_field_save(
    PDO $pdo,
    int $countryId,
    string $table,
    string $entityKind,
    int $entityId,
    string $baseText,
    array $locales,
    bool $ownTransaction = true
): void {
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
    if (!preg_match('/^[a-z_]+$/', $table)) {
        throw new InvalidArgumentException('table_invalid');
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
        $stored = orange_content_locale_field_rows($pdo, $entityKind, $entityId);
        $effective = orange_content_locale_field_effective($record, $stored);
        $baseOrigin = 'manual';
        $previousBase = isset($effective[$base]) ? (string) ($effective[$base]['text_value'] ?? '') : '';
        if (isset($effective[$base]) && $baseText === $previousBase && $previousBase !== '') {
            $baseOrigin = (string) ($effective[$base]['origin'] ?? 'unknown');
        }
        orange_content_locale_field_write_column($pdo, $table, $entityId, $base, $baseText);
        orange_content_locale_field_upsert($pdo, $entityKind, $entityId, $base, $baseText, $baseOrigin, null, null);
        $written = [$base => true];
        $englishText = $base === 'en'
            ? $baseText
            : (string) ($effective['en']['text_value'] ?? '');
        if (isset($locales['en']) && !empty($locales['en']['present'])) {
            $englishText = orange_content_locale_field_apply_one($pdo, $table, $entityKind, $entityId, 'en', $locales['en'], $effective['en'] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach ($locales as $code => $incoming) {
            if ($code === 'en' || $code === $base || !is_array($incoming) || empty($incoming['present'])) {
                continue;
            }
            $locale = orange_content_locale_code((string) $code);
            if ($locale === null) {
                throw new InvalidArgumentException('locale_invalid');
            }
            orange_content_locale_field_apply_one($pdo, $table, $entityKind, $entityId, $locale, $incoming, $effective[$locale] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach (ORANGE_CONTENT_TEXT_COLUMNS as $code => $col) {
            if (isset($written[$code]) || isset($stored[$code])) {
                continue;
            }
            $columnText = (string) ($record[$col] ?? '');
            if ($columnText !== '') {
                orange_content_locale_field_upsert($pdo, $entityKind, $entityId, $code, $columnText, 'unknown', null, null);
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
 * @param array<string, mixed> $incoming
 * @param array<string, mixed>|null $stored
 * @param array<string, bool> $written
 */
function orange_content_locale_field_apply_one(
    PDO $pdo,
    string $table,
    string $entityKind,
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
        orange_content_locale_field_write_column($pdo, $table, $entityId, $locale, '');
        orange_content_locale_field_upsert($pdo, $entityKind, $entityId, $locale, '', 'cleared', null, null);
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
        orange_content_locale_field_write_column($pdo, $table, $entityId, $locale, $text);
        orange_content_locale_field_upsert($pdo, $entityKind, $entityId, $locale, $text, 'machine', $expectedLocale, $expectedHash);
        $written[$locale] = true;
        return $locale === 'en' ? $text : $englishText;
    }
    if ($intent !== 'manual') {
        throw new InvalidArgumentException('intent_invalid');
    }
    orange_content_locale_field_write_column($pdo, $table, $entityId, $locale, $text);
    orange_content_locale_field_upsert($pdo, $entityKind, $entityId, $locale, $text, 'manual', null, null);
    $written[$locale] = true;

    return $locale === 'en' ? $text : $englishText;
}
