<?php
declare(strict_types=1);

/**
 * Local curated map for orange_language_reference.
 *
 * The rows are a maintained local selection of codes this project is willing
 * to store and to send as tl on the current gtx endpoint. Membership in this
 * map is not proof that the external service accepts the code, and it is not
 * a dated export of every language that service offers. fil is stored as fil
 * and sent as tl. The country screen reads the table. This file does not run
 * on page open and does not assign country roles.
 *
 * @return list<array{code:string,provider:string,dir:string,label:string,native:string,aliases:list<string>}>
 */
function orange_language_catalog_data(): array
{
    $rows = [
        ['af', 'af', 'ltr', 'Afrikaans', 'Afrikaans', []],
        ['am', 'am', 'ltr', 'Amharic', 'አማርኛ', []],
        ['ar', 'ar', 'rtl', 'Arabic', 'العربية', []],
        ['az', 'az', 'ltr', 'Azerbaijani', 'Azərbaycan', []],
        ['be', 'be', 'ltr', 'Belarusian', 'Беларуская', []],
        ['bg', 'bg', 'ltr', 'Bulgarian', 'Български', []],
        ['bn', 'bn', 'ltr', 'Bengali', 'বাংলা', []],
        ['bs', 'bs', 'ltr', 'Bosnian', 'Bosanski', []],
        ['ca', 'ca', 'ltr', 'Catalan', 'Català', []],
        ['ceb', 'ceb', 'ltr', 'Cebuano', 'Cebuano', []],
        ['co', 'co', 'ltr', 'Corsican', 'Corsu', []],
        ['cs', 'cs', 'ltr', 'Czech', 'Čeština', []],
        ['cy', 'cy', 'ltr', 'Welsh', 'Cymraeg', []],
        ['da', 'da', 'ltr', 'Danish', 'Dansk', []],
        ['de', 'de', 'ltr', 'German', 'Deutsch', []],
        ['el', 'el', 'ltr', 'Greek', 'Ελληνικά', []],
        ['en', 'en', 'ltr', 'English', 'English', []],
        ['eo', 'eo', 'ltr', 'Esperanto', 'Esperanto', []],
        ['es', 'es', 'ltr', 'Spanish', 'Español', []],
        ['et', 'et', 'ltr', 'Estonian', 'Eesti', []],
        ['eu', 'eu', 'ltr', 'Basque', 'Euskara', []],
        ['fa', 'fa', 'rtl', 'Persian', 'فارسی', []],
        ['fi', 'fi', 'ltr', 'Finnish', 'Suomi', []],
        ['fil', 'tl', 'ltr', 'Filipino', 'Filipino', ['tl']],
        ['fr', 'fr', 'ltr', 'French', 'Français', []],
        ['fy', 'fy', 'ltr', 'Frisian', 'Frysk', []],
        ['ga', 'ga', 'ltr', 'Irish', 'Gaeilge', []],
        ['gd', 'gd', 'ltr', 'Scottish Gaelic', 'Gàidhlig', []],
        ['gl', 'gl', 'ltr', 'Galician', 'Galego', []],
        ['gu', 'gu', 'ltr', 'Gujarati', 'ગુજરાતી', []],
        ['ha', 'ha', 'ltr', 'Hausa', 'Hausa', []],
        ['haw', 'haw', 'ltr', 'Hawaiian', 'Ōlelo Hawaiʻi', []],
        ['he', 'he', 'rtl', 'Hebrew', 'עברית', ['iw']],
        ['hi', 'hi', 'ltr', 'Hindi', 'हिन्दी', []],
        ['hmn', 'hmn', 'ltr', 'Hmong', 'Hmoob', []],
        ['hr', 'hr', 'ltr', 'Croatian', 'Hrvatski', []],
        ['ht', 'ht', 'ltr', 'Haitian Creole', 'Kreyòl Ayisyen', []],
        ['hu', 'hu', 'ltr', 'Hungarian', 'Magyar', []],
        ['hy', 'hy', 'ltr', 'Armenian', 'Հայերեն', []],
        ['id', 'id', 'ltr', 'Indonesian', 'Bahasa Indonesia', []],
        ['ig', 'ig', 'ltr', 'Igbo', 'Igbo', []],
        ['is', 'is', 'ltr', 'Icelandic', 'Íslenska', []],
        ['it', 'it', 'ltr', 'Italian', 'Italiano', []],
        ['ja', 'ja', 'ltr', 'Japanese', '日本語', []],
        ['jv', 'jv', 'ltr', 'Javanese', 'Basa Jawa', []],
        ['ka', 'ka', 'ltr', 'Georgian', 'ქართული', []],
        ['kk', 'kk', 'ltr', 'Kazakh', 'Қазақ тілі', []],
        ['km', 'km', 'ltr', 'Khmer', 'ខ្មែរ', []],
        ['kn', 'kn', 'ltr', 'Kannada', 'ಕನ್ನಡ', []],
        ['ko', 'ko', 'ltr', 'Korean', '한국어', []],
        ['ku', 'ku', 'ltr', 'Kurdish', 'Kurdî', []],
        ['ky', 'ky', 'ltr', 'Kyrgyz', 'Кыргызча', []],
        ['lb', 'lb', 'ltr', 'Luxembourgish', 'Lëtzebuergesch', []],
        ['lo', 'lo', 'ltr', 'Lao', 'ລາວ', []],
        ['lt', 'lt', 'ltr', 'Lithuanian', 'Lietuvių', []],
        ['lv', 'lv', 'ltr', 'Latvian', 'Latviešu', []],
        ['mg', 'mg', 'ltr', 'Malagasy', 'Malagasy', []],
        ['mi', 'mi', 'ltr', 'Maori', 'Māori', []],
        ['mk', 'mk', 'ltr', 'Macedonian', 'Македонски', []],
        ['ml', 'ml', 'ltr', 'Malayalam', 'മലയാളം', []],
        ['mn', 'mn', 'ltr', 'Mongolian', 'Монгол', []],
        ['mr', 'mr', 'ltr', 'Marathi', 'मराठी', []],
        ['ms', 'ms', 'ltr', 'Malay', 'Bahasa Melayu', []],
        ['mt', 'mt', 'ltr', 'Maltese', 'Malti', []],
        ['my', 'my', 'ltr', 'Myanmar', 'မြန်မာ', []],
        ['ne', 'ne', 'ltr', 'Nepali', 'नेपाली', []],
        ['nl', 'nl', 'ltr', 'Dutch', 'Nederlands', []],
        ['no', 'no', 'ltr', 'Norwegian', 'Norsk', []],
        ['ny', 'ny', 'ltr', 'Nyanja', 'Chichewa', []],
        ['or', 'or', 'ltr', 'Odia', 'ଓଡ଼ିଆ', []],
        ['pa', 'pa', 'ltr', 'Punjabi', 'ਪੰਜਾਬੀ', []],
        ['pl', 'pl', 'ltr', 'Polish', 'Polski', []],
        ['ps', 'ps', 'rtl', 'Pashto', 'پښتو', []],
        ['pt', 'pt', 'ltr', 'Portuguese', 'Português', []],
        ['ro', 'ro', 'ltr', 'Romanian', 'Română', []],
        ['ru', 'ru', 'ltr', 'Russian', 'Русский', []],
        ['rw', 'rw', 'ltr', 'Kinyarwanda', 'Kinyarwanda', []],
        ['sd', 'sd', 'rtl', 'Sindhi', 'سنڌي', []],
        ['si', 'si', 'ltr', 'Sinhala', 'සිංහල', []],
        ['sk', 'sk', 'ltr', 'Slovak', 'Slovenčina', []],
        ['sl', 'sl', 'ltr', 'Slovenian', 'Slovenščina', []],
        ['sm', 'sm', 'ltr', 'Samoan', 'Gagana Samoa', []],
        ['sn', 'sn', 'ltr', 'Shona', 'chiShona', []],
        ['so', 'so', 'ltr', 'Somali', 'Soomaali', []],
        ['sq', 'sq', 'ltr', 'Albanian', 'Shqip', []],
        ['sr', 'sr', 'ltr', 'Serbian', 'Српски', []],
        ['st', 'st', 'ltr', 'Sesotho', 'Sesotho', []],
        ['su', 'su', 'ltr', 'Sundanese', 'Basa Sunda', []],
        ['sv', 'sv', 'ltr', 'Swedish', 'Svenska', []],
        ['sw', 'sw', 'ltr', 'Swahili', 'Kiswahili', []],
        ['ta', 'ta', 'ltr', 'Tamil', 'தமிழ்', []],
        ['te', 'te', 'ltr', 'Telugu', 'తెలుగు', []],
        ['tg', 'tg', 'ltr', 'Tajik', 'Тоҷикӣ', []],
        ['th', 'th', 'ltr', 'Thai', 'ไทย', []],
        ['tr', 'tr', 'ltr', 'Turkish', 'Türkçe', []],
        ['uk', 'uk', 'ltr', 'Ukrainian', 'Українська', []],
        ['ur', 'ur', 'rtl', 'Urdu', 'اردو', []],
        ['uz', 'uz', 'ltr', 'Uzbek', 'Oʻzbek', []],
        ['vi', 'vi', 'ltr', 'Vietnamese', 'Tiếng Việt', []],
        ['xh', 'xh', 'ltr', 'Xhosa', 'isiXhosa', []],
        ['yi', 'yi', 'rtl', 'Yiddish', 'ייִדיש', []],
        ['yo', 'yo', 'ltr', 'Yoruba', 'Yorùbá', []],
        ['zh', 'zh-CN', 'ltr', 'Chinese', '中文', []],
        ['zu', 'zu', 'ltr', 'Zulu', 'isiZulu', []],
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
    $lines[] = '-- Source: the local curated map in includes/orange_language_catalog_data.php.';
    $lines[] = '-- Map membership is not a dated export of every language the service offers.';
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
