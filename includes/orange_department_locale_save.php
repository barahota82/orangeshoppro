<?php
declare(strict_types=1);

/**
 * Isolated department name save. Columns stay bound to ar/en/fil/hi.
 * A locale row and its matching column commit together.
 * The caller that already opened a transaction passes $ownTransaction false.
 * Suggestions from orange_department_suggest() are not written here.
 */

function orange_department_rows(PDO $pdo, int $departmentId): array
{
    $st = $pdo->prepare('SELECT locale_code, text_value, origin, source_locale, source_hash FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ?');
    $st->execute(['department', $departmentId, 'name']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string) $row['locale_code']] = $row;
    }
    return $out;
}

function orange_department_load(PDO $pdo, int $departmentId): array
{
    $st = $pdo->prepare('SELECT * FROM departments WHERE id = ?');
    $st->execute([$departmentId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('department_missing');
    }
    return $row;
}

function orange_department_effective_locales(array $dept, array $stored): array
{
    $effective = $stored;
    foreach (ORANGE_DEPT_NAME_COLUMNS as $code => $col) {
        if (isset($effective[$code])) {
            continue;
        }
        $columnText = (string) ($dept[$col] ?? '');
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

function orange_department_upsert_locale(PDO $pdo, int $departmentId, string $locale, string $text, string $origin, ?string $sourceLocale, ?string $sourceHash): void
{
    $existing = $pdo->prepare('SELECT id FROM orange_content_locale_text WHERE entity_kind = ? AND entity_id = ? AND field_key = ? AND locale_code = ?');
    $existing->execute(['department', $departmentId, 'name', $locale]);
    $id = $existing->fetchColumn();
    if ($id === false) {
        $ins = $pdo->prepare('INSERT INTO orange_content_locale_text (entity_kind, entity_id, field_key, locale_code, text_value, origin, source_locale, source_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute(['department', $departmentId, 'name', $locale, $text, $origin, $sourceLocale, $sourceHash]);
        return;
    }
    $upd = $pdo->prepare('UPDATE orange_content_locale_text SET text_value = ?, origin = ?, source_locale = ?, source_hash = ? WHERE id = ?');
    $upd->execute([$text, $origin, $sourceLocale, $sourceHash, (int) $id]);
}

function orange_department_write_column(PDO $pdo, int $departmentId, string $locale, string $text): void
{
    if (!isset(ORANGE_DEPT_NAME_COLUMNS[$locale])) {
        return;
    }
    $col = ORANGE_DEPT_NAME_COLUMNS[$locale];
    $sql = 'UPDATE departments SET ' . $col . ' = ? WHERE id = ?';
    $st = $pdo->prepare($sql);
    $st->execute([$text, $departmentId]);
}

function orange_department_locale_read(PDO $pdo, int $countryId, int $departmentId): array
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    $dept = orange_department_load($pdo, $departmentId);
    $rows = orange_department_rows($pdo, $departmentId);
    $effective = orange_department_effective_locales($dept, $rows);
    $active = orange_language_reference_active_codes($pdo);
    $view = [
        'roles' => $roles,
        'panel' => orange_content_panel_locales($roles, $active),
        'department' => $dept,
        'locales' => $effective,
    ];
    $view['base_text'] = orange_department_base_text_from_read($view);
    return $view;
}

function orange_department_base_text_from_read(array $view): string
{
    $roles = is_array($view['roles'] ?? null) ? $view['roles'] : [];
    $base = (string) ($roles['base'] ?? '');
    $locales = is_array($view['locales'] ?? null) ? $view['locales'] : [];
    if ($base !== '' && array_key_exists($base, $locales) && is_array($locales[$base]) && empty($locales[$base]['virtual'])) {
        return (string) ($locales[$base]['text_value'] ?? $locales[$base]['text'] ?? '');
    }
    $dept = is_array($view['department'] ?? null) ? $view['department'] : [];
    $column = ORANGE_DEPT_NAME_COLUMNS[$base] ?? '';
    if ($column !== '' && array_key_exists($column, $dept)) {
        return (string) $dept[$column];
    }
    return '';
}

function orange_department_locale_save(PDO $pdo, int $countryId, int $departmentId, string $baseText, array $locales, ?callable $afterColumns = null, bool $ownTransaction = true): void
{
    $roles = orange_country_locale_roles_read($pdo, $countryId);
    if ($roles['mode'] !== 'configured') {
        throw new RuntimeException('locale_settings_' . $roles['mode']);
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
        $dept = orange_department_load($pdo, $departmentId);
        $stored = orange_department_rows($pdo, $departmentId);
        $effective = orange_department_effective_locales($dept, $stored);
        $baseOrigin = 'manual';
        $previousBase = isset($effective[$base]) ? (string) ($effective[$base]['text_value'] ?? '') : '';
        if (isset($effective[$base]) && $baseText === $previousBase && $previousBase !== '') {
            $baseOrigin = (string) ($effective[$base]['origin'] ?? 'unknown');
        }
        orange_department_write_column($pdo, $departmentId, $base, $baseText);
        orange_department_upsert_locale($pdo, $departmentId, $base, $baseText, $baseOrigin, null, null);
        $written = [$base => true];
        if ($afterColumns !== null) {
            $afterColumns();
        }
        $englishText = $base === 'en'
            ? $baseText
            : (string) ($effective['en']['text_value'] ?? '');
        if (isset($locales['en']) && !empty($locales['en']['present'])) {
            $englishText = orange_department_apply_one($pdo, $departmentId, 'en', $locales['en'], $effective['en'] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach ($locales as $code => $incoming) {
            if ($code === 'en' || $code === $base || !is_array($incoming) || empty($incoming['present'])) {
                continue;
            }
            $locale = orange_content_locale_code((string) $code);
            if ($locale === null) {
                throw new InvalidArgumentException('locale_invalid');
            }
            orange_department_apply_one($pdo, $departmentId, $locale, $incoming, $effective[$locale] ?? null, $englishText, $base, $baseText, $written);
        }
        foreach (ORANGE_DEPT_NAME_COLUMNS as $code => $col) {
            if (isset($written[$code]) || isset($stored[$code])) {
                continue;
            }
            $columnText = (string) ($dept[$col] ?? '');
            if ($columnText !== '') {
                orange_department_upsert_locale($pdo, $departmentId, $code, $columnText, 'unknown', null, null);
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

function orange_department_apply_one(PDO $pdo, int $departmentId, string $locale, array $incoming, ?array $stored, string $englishText, string $baseLocale, string $baseText, array &$written): string
{
    if ($locale === $baseLocale) {
        return $englishText;
    }
    $storedOrigin = is_array($stored) ? (string) ($stored['origin'] ?? '') : '';
    $storedText = is_array($stored) ? (string) ($stored['text_value'] ?? '') : ($locale === 'en' ? $englishText : '');
    if (!empty($incoming['clear'])) {
        orange_department_write_column($pdo, $departmentId, $locale, '');
        orange_department_upsert_locale($pdo, $departmentId, $locale, '', 'cleared', null, null);
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
        orange_department_write_column($pdo, $departmentId, $locale, $text);
        orange_department_upsert_locale($pdo, $departmentId, $locale, $text, 'machine', $expectedLocale, $expectedHash);
        $written[$locale] = true;
        return $locale === 'en' ? $text : $englishText;
    }
    if ($intent !== 'manual') {
        throw new InvalidArgumentException('intent_invalid');
    }
    orange_department_write_column($pdo, $departmentId, $locale, $text);
    orange_department_upsert_locale($pdo, $departmentId, $locale, $text, 'manual', null, null);
    $written[$locale] = true;
    return $locale === 'en' ? $text : $englishText;
}

/**
 * Preview only. Returns suggestions and the source ticket fields.
 * Does not insert or update a department.
 *
 * @param array<string,array{origin?:string,text?:string}> $known
 * @param callable(string,string,string):string|null $fetch
 * @return array{source_locale:string,source_hash:string,suggestions:array<string,array<string,mixed>>}
 */
function orange_department_suggest(string $baseLocale, string $baseText, array $panelLocales, array $known, array $explicitReplace, ?callable $fetch, int $chunkLen = 1000, ?string $correctedEnglish = null, ?string $previousEnglish = null): array
{
    $baseLocale = orange_content_locale_code($baseLocale) ?? '';
    $report = [
        'source_locale' => $baseLocale,
        'source_hash' => orange_content_text_hash($baseText),
        'suggestions' => [],
    ];
    if ($baseLocale === '' || trim($baseText) === '') {
        $report['suggestions']['base'] = ['ok' => false, 'reason' => 'base_empty', 'apply' => false];
        return $report;
    }
    $englishText = '';
    $englishReady = false;
    if ($correctedEnglish !== null) {
        $englishText = $correctedEnglish;
        $englishReady = trim($englishText) !== '';
        $report['suggestions']['en'] = ['ok' => true, 'reason' => 'kept_manual', 'apply' => false];
        if (!$englishReady) {
            return $report;
        }
    } elseif ($baseLocale === 'en') {
        $englishText = $baseText;
        $englishReady = true;
        $report['suggestions']['en'] = ['ok' => true, 'reason' => 'base_is_english', 'apply' => false];
    } else {
        $en = $known['en'] ?? null;
        $enOrigin = is_array($en) ? (string) ($en['origin'] ?? '') : '';
        $explicitEn = in_array('en', $explicitReplace, true);
        if (in_array($enOrigin, ['manual', 'unknown', 'cleared'], true) && !$explicitEn) {
            $englishText = (string) ($en['text'] ?? $en['text_value'] ?? '');
            $englishReady = trim($englishText) !== '';
            $report['suggestions']['en'] = ['ok' => true, 'reason' => 'kept_' . $enOrigin, 'apply' => false];
        } else {
            $translated = orange_translate_text($baseText, $baseLocale, 'en', $fetch, $chunkLen);
            if (!$translated['ok']) {
                $report['suggestions']['en'] = ['ok' => false, 'reason' => $translated['reason'], 'apply' => false];
                return $report;
            }
            $englishText = $translated['text'];
            $englishReady = true;
            $report['suggestions']['en'] = [
                'ok' => true,
                'reason' => 'machine_from_base',
                'apply' => true,
                'text' => $englishText,
                'intent' => 'machine',
                'source_locale' => $baseLocale,
                'source_hash' => orange_content_text_hash($baseText),
                'explicit_replace' => $explicitEn,
            ];
        }
    }
    if (!$englishReady) {
        return $report;
    }
    foreach ($panelLocales as $code) {
        $locale = orange_content_locale_code((string) $code);
        if ($locale === null || $locale === $baseLocale || $locale === 'en') {
            continue;
        }
        $current = $known[$locale] ?? null;
        $origin = is_array($current) ? (string) ($current['origin'] ?? '') : '';
        $explicit = in_array($locale, $explicitReplace, true);
        if ($origin === 'machine' && !$explicit) {
            $linked = (string) ($current['source_locale'] ?? '');
            $stored = (string) ($current['source_hash'] ?? '');
            $rowPrevious = array_key_exists('previous_english', $current)
                ? (string) $current['previous_english']
                : $previousEnglish;
            $previousHash = $rowPrevious === null || $rowPrevious === '' ? '' : orange_content_text_hash((string) $rowPrevious);
            $linkedToPrevious = $linked === 'en' && $stored !== '' && $previousHash !== '' && hash_equals($previousHash, $stored);
            if (!$linkedToPrevious) {
                $report['suggestions'][$locale] = ['ok' => true, 'reason' => 'kept_unlinked', 'apply' => false];
                continue;
            }
        }
        if (in_array($origin, ['manual', 'unknown', 'cleared'], true) && !$explicit) {
            $report['suggestions'][$locale] = ['ok' => true, 'reason' => 'kept_' . $origin, 'apply' => false];
            continue;
        }
        $translated = orange_translate_text($englishText, 'en', $locale, $fetch, $chunkLen);
        if (!$translated['ok']) {
            $report['suggestions'][$locale] = ['ok' => false, 'reason' => $translated['reason'], 'apply' => false];
            continue;
        }
        $report['suggestions'][$locale] = [
            'ok' => true,
            'reason' => 'machine_from_en',
            'apply' => true,
            'text' => $translated['text'],
            'intent' => 'machine',
            'source_locale' => 'en',
            'source_hash' => orange_content_text_hash($englishText),
            'explicit_replace' => $explicit,
        ];
    }
    return $report;
}
