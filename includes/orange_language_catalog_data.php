<?php
declare(strict_types=1);

/**
 * Operational language map for orange_language_reference.
 *
 * Owner decision 2026-10-26: these 41 codes are the only operational list.
 * import_missing inserts a code only when it is in this map and absent from
 * the table. A restored row whose code is absent from this map is not offered,
 * is not a new role, and is not deleted by this file. fil is stored as fil
 * and sent as tl. This file does not run on page open, does not assign
 * country roles, and does not change a stored base language.
 *
 * @return list<array{code:string,provider:string,dir:string,label:string,native:string,aliases:list<string>}>
 */
function orange_language_catalog_data(): array
{
    $rows = [
        ['ar', 'ar', 'rtl', 'Arabic', 'العربية', []],
        ['be', 'be', 'ltr', 'Belarusian', 'Беларуская', []],
        ['bg', 'bg', 'ltr', 'Bulgarian', 'Български', []],
        ['bn', 'bn', 'ltr', 'Bengali', 'বাংলা', []],
        ['cs', 'cs', 'ltr', 'Czech', 'Čeština', []],
        ['da', 'da', 'ltr', 'Danish', 'Dansk', []],
        ['de', 'de', 'ltr', 'German', 'Deutsch', []],
        ['el', 'el', 'ltr', 'Greek', 'Ελληνικά', []],
        ['en', 'en', 'ltr', 'English', 'English', []],
        ['es', 'es', 'ltr', 'Spanish', 'Español', []],
        ['fa', 'fa', 'rtl', 'Persian', 'فارسی', []],
        ['fil', 'tl', 'ltr', 'Filipino', 'Filipino', ['tl']],
        ['fr', 'fr', 'ltr', 'French', 'Français', []],
        ['ga', 'ga', 'ltr', 'Irish', 'Gaeilge', []],
        ['he', 'he', 'rtl', 'Hebrew', 'עברית', ['iw']],
        ['hi', 'hi', 'ltr', 'Hindi', 'हिन्दी', []],
        ['hr', 'hr', 'ltr', 'Croatian', 'Hrvatski', []],
        ['hy', 'hy', 'ltr', 'Armenian', 'Հայերեն', []],
        ['id', 'id', 'ltr', 'Indonesian', 'Bahasa Indonesia', []],
        ['is', 'is', 'ltr', 'Icelandic', 'Íslenska', []],
        ['it', 'it', 'ltr', 'Italian', 'Italiano', []],
        ['ja', 'ja', 'ltr', 'Japanese', '日本語', []],
        ['ka', 'ka', 'ltr', 'Georgian', 'ქართული', []],
        ['kn', 'kn', 'ltr', 'Kannada', 'ಕನ್ನಡ', []],
        ['ko', 'ko', 'ltr', 'Korean', '한국어', []],
        ['ne', 'ne', 'ltr', 'Nepali', 'नेपाली', []],
        ['nl', 'nl', 'ltr', 'Dutch', 'Nederlands', []],
        ['no', 'no', 'ltr', 'Norwegian', 'Norsk', []],
        ['pl', 'pl', 'ltr', 'Polish', 'Polski', []],
        ['pt', 'pt', 'ltr', 'Portuguese', 'Português', []],
        ['ro', 'ro', 'ltr', 'Romanian', 'Română', []],
        ['ru', 'ru', 'ltr', 'Russian', 'Русский', []],
        ['si', 'si', 'ltr', 'Sinhala', 'සිංහල', []],
        ['so', 'so', 'ltr', 'Somali', 'Soomaali', []],
        ['sv', 'sv', 'ltr', 'Swedish', 'Svenska', []],
        ['th', 'th', 'ltr', 'Thai', 'ไทย', []],
        ['tr', 'tr', 'ltr', 'Turkish', 'Türkçe', []],
        ['uk', 'uk', 'ltr', 'Ukrainian', 'Українська', []],
        ['ur', 'ur', 'rtl', 'Urdu', 'اردو', []],
        ['vi', 'vi', 'ltr', 'Vietnamese', 'Tiếng Việt', []],
        ['zh', 'zh-CN', 'ltr', 'Chinese', '中文', []],
    ];
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'code' => $row[0],
            'provider' => $row[1],
            'dir' => $row[2],
            'label' => $row[3],
            'native' => $row[4],
            'aliases' => $row[5],
        ];
    }

    return $out;
}

/**
 * Provider codes for the current gtx endpoint. Aliases map to the same target.
 * A code absent from this map is rejected locally, before any connection.
 *
 * @return array<string,string>
 */
function orange_language_catalog_provider_map(): array
{
    $map = [];
    foreach (orange_language_catalog_data() as $row) {
        $map[$row['code']] = $row['provider'];
        foreach ($row['aliases'] as $alias) {
            $map[$alias] = $row['provider'];
        }
    }

    return $map;
}

/**
 * @return list<string>
 */

/**
 * Operational codes. Membership is this map, not a restored database row.
 *
 * @return list<string>
 */
function orange_language_catalog_operational_codes(): array
{
    $codes = [];
    foreach (orange_language_catalog_data() as $row) {
        $codes[] = $row['code'];
    }

    return $codes;
}

function orange_language_catalog_code_operational(string $code): bool
{
    static $set = null;
    if ($set === null) {
        $set = array_fill_keys(orange_language_catalog_operational_codes(), true);
    }

    return isset($set[strtolower(trim($code))]);
}
function orange_language_catalog_reference_columns(PDO $pdo): array
{
    try {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $st = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'orange_language_reference'");
            if (!$st || !$st->fetchColumn()) {
                return [];
            }
            $info = $pdo->query('PRAGMA table_info(orange_language_reference)');
            $names = [];
            foreach ($info ? $info->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
                $names[] = (string) ($row['name'] ?? '');
            }

            return $names;
        }
        $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute(['orange_language_reference']);
        $names = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $names[] = (string) ($row[0] ?? '');
        }

        return $names;
    } catch (Throwable $e) {
        unset($e);

        return [];
    }
}

/**
 * Insert catalog codes that are not already stored.
 * Does not create the table, does not add columns, does not update existing
 * rows, and does not write country roles.
 *
 * @return array{applied:bool,reason:string,inserted:list<string>,preserved:list<string>}
 */
function orange_language_catalog_import_missing(PDO $pdo): array
{
    $columns = orange_language_catalog_reference_columns($pdo);
    if ($columns === []) {
        return ['applied' => false, 'reason' => 'table_absent', 'inserted' => [], 'preserved' => []];
    }
    foreach (['locale_code', 'label_en', 'label_native', 'dir', 'is_active'] as $need) {
        if (!in_array($need, $columns, true)) {
            return ['applied' => false, 'reason' => 'names_not_stored', 'inserted' => [], 'preserved' => []];
        }
    }
    $hasAliases = in_array('aliases_json', $columns, true);
    $hasSort = in_array('sort_order', $columns, true);
    $hasCreated = in_array('created_at', $columns, true);
    $hasUpdated = in_array('updated_at', $columns, true);
    $existing = [];
    $st = $pdo->query('SELECT locale_code FROM orange_language_reference');
    foreach ($st ? $st->fetchAll(PDO::FETCH_COLUMN) : [] as $code) {
        $existing[(string) $code] = true;
    }
    $fields = ['locale_code', 'label_en', 'label_native', 'dir', 'is_active'];
    if ($hasAliases) {
        $fields[] = 'aliases_json';
    }
    if ($hasSort) {
        $fields[] = 'sort_order';
    }
    if ($hasCreated) {
        $fields[] = 'created_at';
    }
    if ($hasUpdated) {
        $fields[] = 'updated_at';
    }
    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $ins = $pdo->prepare(
        'INSERT INTO orange_language_reference (' . implode(', ', $fields) . ') VALUES (' . $placeholders . ')'
    );
    $inserted = [];
    $preserved = [];
    $sort = 10;
    foreach (orange_language_catalog_data() as $row) {
        if (isset($existing[$row['code']])) {
            $preserved[] = $row['code'];
            $sort += 10;
            continue;
        }
        $values = [$row['code'], $row['label'], $row['native'], $row['dir'], 1];
        if ($hasAliases) {
            $values[] = json_encode($row['aliases'], JSON_UNESCAPED_UNICODE);
        }
        if ($hasSort) {
            $values[] = $sort;
        }
        if ($hasCreated) {
            $values[] = date('Y-m-d H:i:s');
        }
        if ($hasUpdated) {
            $values[] = date('Y-m-d H:i:s');
        }
        $ins->execute($values);
        $inserted[] = $row['code'];
        $sort += 10;
    }

    return [
        'applied' => true,
        'reason' => 'inserted_missing',
        'inserted' => $inserted,
        'preserved' => $preserved,
    ];
}

function orange_language_catalog_sql_literal(string $value): string
{
    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Unexecuted MariaDB/MySQL data update for the proven rich reference table.
 * It does not ALTER, does not set id, and does not write country roles.
 */
function orange_language_catalog_unexecuted_sql(): string
{
    $lines = [];
    $lines[] = '-- Unexecuted data fill for the existing orange_language_reference table.';
    $lines[] = '-- Target: the application database that already has this table and the columns';
    $lines[] = '-- named in the INSERT below. Confirm DATABASE() and those columns with a';
    $lines[] = '-- targeted read before any later run. scripts/orange_db.sql does not contain';
    $lines[] = '-- this table; that absence is not proof the live columns are missing.';
    $lines[] = '-- Source: the 41-code operational map in includes/orange_language_catalog_data.php.';
    $lines[] = '-- This statement does not delete codes that are outside the map.';
    $lines[] = '-- Structure check before any later run: locale_code, label_en, label_native,';
    $lines[] = '-- aliases_json, dir, is_active, sort_order, created_at, updated_at.';
    $lines[] = '-- If one of those columns is absent, stop. This file does not add columns.';
    $lines[] = '-- It inserts missing locale_code values only. Existing ids and rows stay as they are.';
    $lines[] = '-- It does not assign base, content, admin_ui, or customer roles.';
    $lines[] = '-- After a later run: the row count grows only by codes that were not stored.';
    $lines[] = '-- New rows are active reference rows. They do not enable visitor languages.';
    $lines[] = '-- Backup: orange_language_reference is already a global full-table backup.';
    $lines[] = '-- Restoring an older backup removes rows this statement added.';
    $lines[] = '-- Country recovery does not include this table. Schema revision stays unchanged.';
    $lines[] = '-- A slogan screen that lists active reference rows would list the new codes.';
    $lines[] = '-- Slogan text is not changed here.';
    $sort = 10;
    foreach (orange_language_catalog_data() as $row) {
        $aliases = json_encode($row['aliases'], JSON_UNESCAPED_UNICODE);
        $lines[] = 'INSERT INTO orange_language_reference (locale_code, label_en, label_native, aliases_json, dir, is_active, sort_order, created_at, updated_at)';
        $lines[] = 'SELECT ' . implode(', ', [
            orange_language_catalog_sql_literal($row['code']),
            orange_language_catalog_sql_literal($row['label']),
            orange_language_catalog_sql_literal($row['native']),
            orange_language_catalog_sql_literal((string) $aliases),
            orange_language_catalog_sql_literal($row['dir']),
            '1',
            (string) $sort,
            'CURRENT_TIMESTAMP',
            'CURRENT_TIMESTAMP',
        ]);
        $lines[] = 'FROM DUAL';
        $lines[] = 'WHERE NOT EXISTS (SELECT 1 FROM orange_language_reference WHERE locale_code = ' . orange_language_catalog_sql_literal($row['code']) . ');';
        $sort += 10;
    }

    return implode("\n", $lines) . "\n";
}
