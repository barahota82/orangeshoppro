<?php

declare(strict_types=1);

/**
 * Brand Identity registry library.
 * Normal runtime authority is application MySQL after the seven tables exist.
 * Disposable SQLite is allowed only for library schema-verify self-tests.
 */

require_once __DIR__ . '/brand_identity_schema.php';

const ORANGE_BRAND_IDENTITY_STATES = ['DRAFT', 'PREVIEW_READY', 'ACTIVE', 'ARCHIVED'];
const ORANGE_BRAND_IDENTITY_MAX_UPLOAD_BYTES = 4194304;

/**
 * @return list<string>
 */
function orange_brand_identity_slot_codes(): array
{
    return [
        'STOREFRONT_BRAND_MARK',
        'STOREFRONT_COMPANY_WORDMARK',
        'ADMIN_BRAND_MARK',
        'ADMIN_COMPANY_WORDMARK',
    ];
}

/**
 * @return list<string>
 */
function orange_brand_identity_text_keys(): array
{
    return [
        'PUBLIC_BRAND_NAME',
        'STOREFRONT_SITE_NAME',
        'ADMIN_DISPLAY_NAME',
        'APP_DISPLAY_NAME',
        'APP_SHORT_NAME',
        'STOREFRONT_SLOGAN',
    ];
}

function orange_brand_identity_require_control_pdo(PDO $controlPdo): PDO
{
    $controlPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $controlPdo;
}

function orange_brand_identity_driver(PDO $controlPdo): string
{
    return (string) $controlPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
}

function orange_brand_identity_normalize_locale_code(string $raw): ?string
{
    if (function_exists('orange_ctrl_locale_normalize_code')) {
        return orange_ctrl_locale_normalize_code($raw);
    }
    $c = strtolower(trim($raw));
    if ($c === '') {
        return null;
    }
    if ($c === 'tl') {
        $c = 'fil';
    }
    if (!preg_match('/^[a-z]{2,3}$/', $c)) {
        return null;
    }

    return $c;
}

/**
 * Production locale universe: the same storefront helper as the live shop.
 * Authority is array_keys(storefront_lang_options()) — not Control ctrl_locales,
 * not a Brand-Identity-only hard-coded list, and not a Country locale table.
 * Fail closed when the helper is missing or yields no valid codes.
 *
 * @return list<string>
 */
function orange_brand_identity_approved_locales(PDO $controlPdo): array
{
    orange_brand_identity_require_control_pdo($controlPdo);
    if (!function_exists('storefront_lang_options')) {
        throw new RuntimeException('BRAND_IDENTITY_LOCALE_AUTHORITY_UNAVAILABLE');
    }
    try {
        $opts = storefront_lang_options();
    } catch (Throwable $e) {
        throw new RuntimeException('BRAND_IDENTITY_LOCALE_AUTHORITY_UNAVAILABLE');
    }
    if (!is_array($opts) || $opts === []) {
        throw new RuntimeException('BRAND_IDENTITY_LOCALE_AUTHORITY_UNAVAILABLE');
    }
    $codes = [];
    foreach (array_keys($opts) as $raw) {
        $norm = orange_brand_identity_normalize_locale_code((string) $raw);
        if ($norm !== null) {
            $codes[] = $norm;
        }
    }
    $codes = array_values(array_unique($codes));
    if ($codes === []) {
        throw new RuntimeException('BRAND_IDENTITY_LOCALE_AUTHORITY_UNAVAILABLE');
    }

    return $codes;
}

/**
 * Proposed initial seed only — not a world catalogue and not Storefront enablement.
 * Used when orange_language_reference is absent. Do not treat this as completeness.
 *
 * @return list<array{code:string,label:string,native:string,aliases:list<string>,dir:string}>
 */
function orange_brand_identity_language_reference_seed_entries(): array
{
    return [
        ['code' => 'ar', 'label' => 'Arabic', 'native' => 'العربية', 'aliases' => [], 'dir' => 'rtl'],
        ['code' => 'en', 'label' => 'English', 'native' => 'English', 'aliases' => [], 'dir' => 'ltr'],
        ['code' => 'fil', 'label' => 'Filipino', 'native' => 'Filipino', 'aliases' => ['tl'], 'dir' => 'ltr'],
        ['code' => 'hi', 'label' => 'Hindi', 'native' => 'हिन्दी', 'aliases' => [], 'dir' => 'ltr'],
    ];
}

/**
 * Isolated tests may bind extra labeled reference rows. Ordinary runtime must not.
 *
 * @param list<array<string, mixed>>|null $entries
 */
function orange_brand_identity_bind_language_reference(?array $entries): void
{
    if ($entries === null) {
        unset($GLOBALS['ORANGE_BRAND_IDENTITY_LANGUAGE_REFERENCE']);

        return;
    }
    $GLOBALS['ORANGE_BRAND_IDENTITY_LANGUAGE_REFERENCE'] = $entries;
}

/**
 * Expected list of supported alias strings. Wrong JSON shape, non-strings, or invalid codes are malformed.
 *
 * @return list<string>|null
 */
function orange_brand_identity_decode_aliases_json(string $json): ?array
{
    $decoded = json_decode($json);
    if (!is_array($decoded)) {
        return null;
    }
    $out = [];
    foreach ($decoded as $item) {
        if (!is_string($item) || orange_brand_identity_normalize_locale_code($item) === null) {
            return null;
        }
        $out[] = $item;
    }

    return $out;
}

/**
 * @param array<string, mixed> $raw
 * @return array{code:string,label:string,native:string,aliases:list<string>,dir:string}|null
 */
function orange_brand_identity_normalize_language_reference_entry(array $raw): ?array
{
    $code = orange_brand_identity_normalize_locale_code((string) ($raw['code'] ?? $raw['locale'] ?? $raw['locale_code'] ?? ''));
    if ($code === null) {
        return null;
    }
    if (array_key_exists('aliases', $raw)) {
        $rawAliases = $raw['aliases'];
    } elseif (array_key_exists('aliases_json', $raw)) {
        $rawAliases = orange_brand_identity_decode_aliases_json((string) $raw['aliases_json']);
        if ($rawAliases === null) {
            return null;
        }
    } else {
        $rawAliases = [];
    }
    if (!is_array($rawAliases) || !array_is_list($rawAliases)) {
        return null;
    }
    $aliases = [];
    foreach ($rawAliases as $alias) {
        if (!is_string($alias)) {
            return null;
        }
        $n = orange_brand_identity_normalize_locale_code($alias);
        if ($n === null) {
            return null;
        }
        if ($n !== $code) {
            $aliases[] = $n;
        }
    }
    $label = trim((string) ($raw['label'] ?? $raw['display_name_en'] ?? $raw['display_name_native'] ?? $code));
    $native = trim((string) ($raw['native'] ?? $raw['display_name_native'] ?? $label));

    return [
        'code' => $code,
        'label' => $label !== '' ? $label : $code,
        'native' => $native !== '' ? $native : $code,
        'aliases' => array_values(array_unique($aliases)),
        'dir' => ((string) ($raw['dir'] ?? '')) === 'rtl' ? 'rtl' : 'ltr',
    ];
}

/**
 * @return array{source:string,pending:bool,write_blocked:bool,state:string,entries:list<array{code:string,label:string,native:string,aliases:list<string>,dir:string}>}
 */
function orange_brand_identity_language_reference_pack(
    string $source,
    bool $pending,
    bool $writeBlocked,
    string $state,
    array $entries
): array {
    return [
        'source' => $source,
        'pending' => $pending,
        'write_blocked' => $writeBlocked,
        'state' => $state,
        'entries' => $entries,
    ];
}

/**
 * @return array{sqlstate:string,driver_code:int}
 */
function orange_brand_identity_language_reference_direct_error_identity(PDOException $e): array
{
    $info = is_array($e->errorInfo) ? $e->errorInfo : [];

    return [
        'sqlstate' => (string) ($info[0] ?? ''),
        'driver_code' => (int) ($info[1] ?? 0),
    ];
}

function orange_brand_identity_language_reference_is_missing_table(PDO $pdo, PDOException $e): bool
{
    $id = orange_brand_identity_language_reference_direct_error_identity($e);
    $driver = orange_brand_identity_driver($pdo);
    if ($driver === 'mysql') {
        return $id['driver_code'] === 1146 || $id['sqlstate'] === '42S02';
    }
    if ($driver === 'sqlite') {
        $msg = $e->getMessage();

        return $id['driver_code'] === 1
            && $id['sqlstate'] === 'HY000'
            && (
                str_contains($msg, 'no such table: orange_language_reference')
                || str_contains($msg, "no such table: 'orange_language_reference'")
            );
    }

    return false;
}

function orange_brand_identity_language_reference_metadata_count(PDO $pdo): ?int
{
    try {
        if (orange_brand_identity_driver($pdo) === 'sqlite') {
            $st = $pdo->query(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'orange_language_reference'"
            );

            return $st !== false ? (int) $st->fetchColumn() : null;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute(['orange_language_reference']);

        return (int) $st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Direct read of the intended table. Metadata COUNT is recorded only.
 * Absence is MariaDB 1146 / 42S02 (or the SQLite missing-table identity for that table).
 *
 * @return array{presence:string,reason:string,metadata_count:?int,sqlstate:string,driver_code:int}
 */
function orange_brand_identity_language_reference_table_probe(PDO $controlPdo): array
{
    orange_brand_identity_require_control_pdo($controlPdo);
    $metadataCount = orange_brand_identity_language_reference_metadata_count($controlPdo);
    try {
        $st = $controlPdo->query('SELECT 1 FROM orange_language_reference LIMIT 1');
        if ($st === false) {
            return [
                'presence' => 'unreadable',
                'reason' => 'query_false',
                'metadata_count' => $metadataCount,
                'sqlstate' => '',
                'driver_code' => 0,
            ];
        }

        return [
            'presence' => 'present',
            'reason' => 'direct_read_ok',
            'metadata_count' => $metadataCount,
            'sqlstate' => '00000',
            'driver_code' => 0,
        ];
    } catch (PDOException $e) {
        $id = orange_brand_identity_language_reference_direct_error_identity($e);
        if (orange_brand_identity_language_reference_is_missing_table($controlPdo, $e)) {
            return [
                'presence' => 'absent',
                'reason' => 'missing_table',
                'metadata_count' => $metadataCount,
                'sqlstate' => $id['sqlstate'],
                'driver_code' => $id['driver_code'],
            ];
        }
        $reason = $id['driver_code'] === 1142 ? 'access_denied_1142' : 'other_error';

        return [
            'presence' => 'unreadable',
            'reason' => $reason,
            'metadata_count' => $metadataCount,
            'sqlstate' => $id['sqlstate'],
            'driver_code' => $id['driver_code'],
        ];
    } catch (Throwable $e) {
        return [
            'presence' => 'unreadable',
            'reason' => 'other_error',
            'metadata_count' => $metadataCount,
            'sqlstate' => '',
            'driver_code' => 0,
        ];
    }
}

/**
 * present | absent | unreadable. Absence is never inferred from metadata COUNT=0.
 */
function orange_brand_identity_language_reference_table_presence(PDO $controlPdo): string
{
    return orange_brand_identity_language_reference_table_probe($controlPdo)['presence'];
}

/**
 * A readable view or other non-base object is not the approved application table.
 * Unknown metadata after a successful direct read is not treated as absence.
 */
function orange_brand_identity_language_reference_object_kind(PDO $controlPdo): string
{
    orange_brand_identity_require_control_pdo($controlPdo);
    try {
        if (orange_brand_identity_driver($controlPdo) === 'sqlite') {
            $st = $controlPdo->query(
                "SELECT type FROM sqlite_master WHERE name = 'orange_language_reference' LIMIT 1"
            );
            $type = $st !== false ? strtolower((string) $st->fetchColumn()) : '';
            if ($type === 'table') {
                return 'base_table';
            }
            if ($type === 'view') {
                return 'view';
            }

            return $type !== '' ? 'other' : 'unknown';
        }
        $st = $controlPdo->query(
            "SELECT TABLE_TYPE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orange_language_reference' LIMIT 1"
        );
        if ($st === false) {
            return 'unknown';
        }
        $type = strtoupper((string) $st->fetchColumn());
        if ($type === 'BASE TABLE') {
            return 'base_table';
        }
        if ($type === 'VIEW' || $type === 'SYSTEM VIEW') {
            return 'view';
        }

        return $type !== '' ? 'other' : 'unknown';
    } catch (Throwable $e) {
        return 'unknown';
    }
}

/**
 * Dropdown / slogan-row authority. Distinct from storefront_lang_options().
 * Test bind is isolated and labeled. An absent table may use the pending seed.
 * An installed empty/inactive/invalid/unreadable table never falls back to seed.
 * Does not read Control ctrl_locales and does not create the table on reads.
 *
 * @return array{source:string,pending:bool,write_blocked:bool,state:string,entries:list<array{code:string,label:string,native:string,aliases:list<string>,dir:string}>}
 */
function orange_brand_identity_language_reference(PDO $controlPdo): array
{
    orange_brand_identity_require_control_pdo($controlPdo);
    $out = [];
    $seen = [];
    $add = static function (array $raw) use (&$out, &$seen): void {
        $entry = orange_brand_identity_normalize_language_reference_entry($raw);
        if ($entry === null || isset($seen[$entry['code']])) {
            return;
        }
        $seen[$entry['code']] = true;
        $out[] = $entry;
    };
    if (isset($GLOBALS['ORANGE_BRAND_IDENTITY_LANGUAGE_REFERENCE'])
        && is_array($GLOBALS['ORANGE_BRAND_IDENTITY_LANGUAGE_REFERENCE'])) {
        foreach ($GLOBALS['ORANGE_BRAND_IDENTITY_LANGUAGE_REFERENCE'] as $raw) {
            if (is_array($raw)) {
                $add($raw);
            }
        }
        if ($out !== []) {
            return orange_brand_identity_language_reference_pack('test_bind', true, false, 'test_bind', $out);
        }
    }

    $presence = orange_brand_identity_language_reference_table_presence($controlPdo);
    if ($presence === 'unreadable') {
        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_unreadable',
            false,
            true,
            'unreadable',
            []
        );
    }
    if ($presence === 'absent') {
        foreach (orange_brand_identity_language_reference_seed_entries() as $raw) {
            $add($raw);
        }

        return orange_brand_identity_language_reference_pack(
            'proposal_seed_uninstalled',
            true,
            false,
            'absent',
            $out
        );
    }

    $objectKind = orange_brand_identity_language_reference_object_kind($controlPdo);
    if ($objectKind !== 'base_table') {
        if ($objectKind === 'view' || $objectKind === 'other') {
            return orange_brand_identity_language_reference_pack(
                'orange_language_reference_incompatible',
                false,
                true,
                'incompatible_object',
                []
            );
        }

        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_unreadable',
            false,
            true,
            'unknown_object',
            []
        );
    }

    try {
        $st = $controlPdo->query(
            'SELECT locale_code AS code, label_en AS label, label_native AS native, aliases_json, dir, is_active
             FROM orange_language_reference
             ORDER BY sort_order ASC, id ASC'
        );
        if ($st === false) {
            throw new RuntimeException('BRAND_IDENTITY_LANGUAGE_REFERENCE_UNAVAILABLE');
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_unreadable',
            false,
            true,
            'unreadable',
            []
        );
    }

    if ($rows === []) {
        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_empty',
            false,
            true,
            'empty',
            []
        );
    }

    $activeRaw = [];
    foreach ($rows as $row) {
        if ((int) ($row['is_active'] ?? 0) !== 1) {
            continue;
        }
        $decoded = orange_brand_identity_decode_aliases_json((string) ($row['aliases_json'] ?? ''));
        if ($decoded === null) {
            return orange_brand_identity_language_reference_pack(
                'orange_language_reference_invalid',
                false,
                true,
                'invalid',
                []
            );
        }
        $row['aliases'] = $decoded;
        $activeRaw[] = $row;
    }
    if ($activeRaw === []) {
        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_inactive',
            false,
            true,
            'inactive',
            []
        );
    }
    $installed = [];
    $seenCodes = [];
    $seenAliases = [];
    $mixedInvalid = false;
    foreach ($activeRaw as $row) {
        $entry = orange_brand_identity_normalize_language_reference_entry($row);
        if ($entry === null) {
            $mixedInvalid = true;
            continue;
        }
        if (isset($seenCodes[$entry['code']]) || isset($seenAliases[$entry['code']])) {
            $mixedInvalid = true;
            continue;
        }
        foreach ($entry['aliases'] as $alias) {
            if (isset($seenCodes[$alias]) || isset($seenAliases[$alias])) {
                $mixedInvalid = true;
            }
            $seenAliases[$alias] = true;
        }
        $seenCodes[$entry['code']] = true;
        $installed[] = $entry;
    }
    if ($mixedInvalid || $installed === []) {
        return orange_brand_identity_language_reference_pack(
            'orange_language_reference_invalid',
            false,
            true,
            'invalid',
            []
        );
    }

    return orange_brand_identity_language_reference_pack(
        'orange_language_reference',
        false,
        false,
        'ready',
        $installed
    );
}

function orange_brand_identity_language_reference_notice_ar(array $reference): string
{
    $source = (string) ($reference['source'] ?? '');
    if ($source === 'proposal_seed_uninstalled' || !empty($reference['pending'])) {
        if ($source === 'test_bind') {
            return 'مرجع اللغات هنا للتجربة المحلية فقط، وليس المرجع المثبّت.';
        }

        return 'مرجع اللغات غير مثبت بعد. تظهر حالياً اللغات الأربع الأولى فقط: العربية والإنجليزية والفلبينية والهندية، بانتظار تثبيت المرجع. هذا لا يفعّل لغات الزائر.';
    }
    if (!empty($reference['write_blocked'])) {
        return 'مرجع اللغات مثبت لكن غير قابل للاستخدام حالياً. لا يمكن حفظ شعار جديد. النصوص المحفوظة سابقاً تبقى كما هي.';
    }

    return '';
}

function orange_brand_identity_assert_language_reference_writable(PDO $controlPdo): void
{
    $ref = orange_brand_identity_language_reference($controlPdo);
    if (!empty($ref['write_blocked'])) {
        throw new RuntimeException('BRAND_IDENTITY_LANGUAGE_REFERENCE_UNAVAILABLE');
    }
}

/**
 * @return list<string>
 */
function orange_brand_identity_reference_locale_codes(PDO $controlPdo): array
{
    $codes = [];
    foreach (orange_brand_identity_language_reference($controlPdo)['entries'] as $entry) {
        $codes[] = $entry['code'];
        foreach ($entry['aliases'] as $alias) {
            $codes[] = $alias;
        }
    }

    return array_values(array_unique($codes));
}

function orange_brand_identity_assert_reference_locale(PDO $controlPdo, string $locale): void
{
    orange_brand_identity_assert_language_reference_writable($controlPdo);
    $loc = orange_brand_identity_normalize_locale_code($locale);
    if ($loc === null) {
        throw new RuntimeException('BRAND_IDENTITY_UNSUPPORTED_LOCALE');
    }
    $codes = orange_brand_identity_reference_locale_codes($controlPdo);
    if (!in_array($loc, $codes, true)) {
        throw new RuntimeException('BRAND_IDENTITY_UNSUPPORTED_LOCALE');
    }
}

function orange_brand_identity_assert_slot_code(string $slotCode): void
{
    if (!in_array($slotCode, orange_brand_identity_slot_codes(), true)) {
        throw new RuntimeException('BRAND_IDENTITY_UNKNOWN_SLOT');
    }
}

function orange_brand_identity_assert_text_key(string $textKey): void
{
    if (!in_array($textKey, orange_brand_identity_text_keys(), true)) {
        throw new RuntimeException('BRAND_IDENTITY_UNKNOWN_TEXT_KEY');
    }
}

function orange_brand_identity_assert_locale(PDO $controlPdo, string $locale): void
{
    $loc = orange_brand_identity_normalize_locale_code($locale);
    if ($loc === null) {
        throw new RuntimeException('BRAND_IDENTITY_UNSUPPORTED_LOCALE');
    }
    $approved = orange_brand_identity_approved_locales($controlPdo);
    if (!in_array($loc, $approved, true)) {
        throw new RuntimeException('BRAND_IDENTITY_UNSUPPORTED_LOCALE');
    }
}

function orange_brand_identity_assert_state(string $state): void
{
    if (!in_array($state, ORANGE_BRAND_IDENTITY_STATES, true)) {
        throw new RuntimeException('BRAND_IDENTITY_UNKNOWN_STATE');
    }
}

/**
 * Public transition wrappers are approval/preview helpers only.
 * ACTIVE/ARCHIVED publication remains reserved to activate_release().
 */
function orange_brand_identity_assert_public_preview_only(string $toState): void
{
    if ($toState !== 'PREVIEW_READY') {
        throw new RuntimeException('BRAND_IDENTITY_RELEASE_ACTIVATION_REQUIRED');
    }
}

function orange_brand_identity_project_root(): string
{
    if (function_exists('orange_project_root_path')) {
        return orange_project_root_path();
    }

    return dirname(__DIR__);
}

function orange_brand_identity_objects_dir(): string
{
    return orange_brand_identity_project_root() . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'brand_identity' . DIRECTORY_SEPARATOR . 'objects';
}

function orange_brand_identity_drafts_dir(): string
{
    return orange_brand_identity_project_root() . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'brand_identity' . DIRECTORY_SEPARATOR . 'drafts';
}

function orange_brand_identity_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/**
 * @return list<string>
 */
function orange_brand_identity_allowed_mimes(): array
{
    return ['image/png', 'image/webp', 'image/jpeg'];
}

function orange_brand_identity_detect_mime(string $absolutePath, string $originalFilename): string
{
    $base = strtolower(basename($originalFilename));
    $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === 'svg' || $ext === 'svgz') {
        throw new RuntimeException('BRAND_IDENTITY_SVG_REJECTED');
    }
    $head = (string) @file_get_contents($absolutePath, false, null, 0, 256);
    if ($head !== '' && (preg_match('/<svg[\s>]/i', $head) === 1 || str_contains(strtolower($head), '<svg'))) {
        throw new RuntimeException('BRAND_IDENTITY_SVG_REJECTED');
    }
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f !== false) {
            $mime = (string) finfo_file($f, $absolutePath);
            finfo_close($f);
            if ($mime === 'image/svg+xml' || $mime === 'image/svg') {
                throw new RuntimeException('BRAND_IDENTITY_SVG_REJECTED');
            }
            if (in_array($mime, orange_brand_identity_allowed_mimes(), true)) {
                return $mime;
            }
            if ($mime !== '' && $mime !== 'application/octet-stream') {
                throw new RuntimeException('BRAND_IDENTITY_INVALID_MIME');
            }
        }
    }
    if (str_starts_with($head, "\x89PNG")) {
        return 'image/png';
    }
    if (str_starts_with($head, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    throw new RuntimeException('BRAND_IDENTITY_INVALID_MIME');
}

/**
 * @return array{width:int,height:int,alpha:bool,alpha_note:string}
 */
function orange_brand_identity_read_image_meta(string $absolutePath, string $mime): array
{
    $bytes = (string) file_get_contents($absolutePath);
    if ($mime === 'image/png') {
        if (strlen($bytes) < 29 || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new RuntimeException('BRAND_IDENTITY_INVALID_IMAGE');
        }
        $w = unpack('N', substr($bytes, 16, 4));
        $h = unpack('N', substr($bytes, 20, 4));
        $color = ord($bytes[25]);
        $alpha = ($color === 4 || $color === 6);

        return [
            'width' => (int) ($w[1] ?? 0),
            'height' => (int) ($h[1] ?? 0),
            'alpha' => $alpha,
            'alpha_note' => $alpha ? 'PNG_COLOR_TYPE_ALPHA' : 'PNG_NO_ALPHA_COLOR_TYPE',
        ];
    }
    if ($mime === 'image/jpeg') {
        $info = @getimagesize($absolutePath);
        if (!is_array($info) || (int) ($info[0] ?? 0) <= 0) {
            throw new RuntimeException('BRAND_IDENTITY_INVALID_IMAGE');
        }

        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'alpha' => false,
            'alpha_note' => 'JPEG_NO_ALPHA',
        ];
    }
    if ($mime === 'image/webp') {
        return orange_brand_identity_parse_webp_meta($bytes);
    }
    throw new RuntimeException('BRAND_IDENTITY_INVALID_MIME');
}

/**
 * @return array{width:int,height:int,alpha:bool,alpha_note:string}
 */
function orange_brand_identity_parse_webp_meta(string $bytes): array
{
    if (strlen($bytes) < 20 || substr($bytes, 0, 4) !== 'RIFF' || substr($bytes, 8, 4) !== 'WEBP') {
        throw new RuntimeException('BRAND_IDENTITY_INVALID_IMAGE');
    }
    $pos = 12;
    $len = strlen($bytes);
    while ($pos + 8 <= $len) {
        $four = substr($bytes, $pos, 4);
        $size = unpack('V', substr($bytes, $pos + 4, 4));
        $chunk = (int) ($size[1] ?? 0);
        $data = $pos + 8;
        if ($four === 'VP8X' && $data + 10 <= $len) {
            $flags = ord($bytes[$data]);
            $w = 1 + ord($bytes[$data + 4]) + (ord($bytes[$data + 5]) << 8) + (ord($bytes[$data + 6]) << 16);
            $h = 1 + ord($bytes[$data + 7]) + (ord($bytes[$data + 8]) << 8) + (ord($bytes[$data + 9]) << 16);
            $alpha = ($flags & 0x10) !== 0;

            return [
                'width' => $w,
                'height' => $h,
                'alpha' => $alpha,
                'alpha_note' => $alpha ? 'WEBP_VP8X_ALPHA' : 'WEBP_VP8X_NO_ALPHA',
            ];
        }
        if ($four === 'VP8L' && $data + 5 <= $len && ord($bytes[$data]) === 0x2f) {
            $b1 = ord($bytes[$data + 1]);
            $b2 = ord($bytes[$data + 2]);
            $b3 = ord($bytes[$data + 3]);
            $b4 = ord($bytes[$data + 4]);
            $w = 1 + ((($b2 & 0x3F) << 8) | $b1);
            $h = 1 + ((($b4 & 0x0F) << 10) | ($b3 << 2) | (($b2 & 0xC0) >> 6));
            $alpha = ($b4 & 0x10) !== 0;

            return [
                'width' => $w,
                'height' => $h,
                'alpha' => $alpha,
                'alpha_note' => $alpha ? 'WEBP_VP8L_ALPHA' : 'WEBP_VP8L_NO_ALPHA',
            ];
        }
        if ($four === 'VP8 ' && $data + 10 <= $len) {
            $w = unpack('v', substr($bytes, $data + 6, 2));
            $h = unpack('v', substr($bytes, $data + 8, 2));

            return [
                'width' => (int) ($w[1] ?? 0) & 0x3FFF,
                'height' => (int) ($h[1] ?? 0) & 0x3FFF,
                'alpha' => false,
                'alpha_note' => 'WEBP_VP8_NO_ALPHA',
            ];
        }
        $pos = $data + $chunk + ($chunk % 2);
    }
    throw new RuntimeException('BRAND_IDENTITY_INVALID_IMAGE');
}

function orange_brand_identity_ensure_dir(string $dir): void
{
    if (is_dir($dir)) {
        return;
    }
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('BRAND_IDENTITY_STORAGE_UNAVAILABLE');
    }
}

/**
 * @param array<string, mixed> $fields
 */
function orange_brand_identity_audit(
    PDO $controlPdo,
    string $eventType,
    int $actorId,
    array $fields = []
): void {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    if (!in_array($eventType, orange_brand_identity_audit_event_types(), true)) {
        throw new RuntimeException('BRAND_IDENTITY_AUDIT_TYPE_FORBIDDEN');
    }
    $note = isset($fields['change_note']) ? (string) $fields['change_note'] : null;
    if ($note !== null && (str_contains($note, '\\') || str_contains($note, '/uploads/') || str_contains(strtolower($note), 'password'))) {
        $note = 'redacted';
    }
    $st = $pdo->prepare(
        'INSERT INTO orange_brand_identity_audit_events (
            event_type, actor_id, created_at, entity_table, entity_id,
            release_id, slot_code, identity_version_id, slot_version_id,
            before_id, after_id, change_note
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $st->execute([
        $eventType,
        $actorId,
        orange_brand_identity_now(),
        (string) ($fields['entity_table'] ?? ''),
        (string) ($fields['entity_id'] ?? ''),
        $fields['release_id'] ?? null,
        $fields['slot_code'] ?? null,
        $fields['identity_version_id'] ?? null,
        $fields['slot_version_id'] ?? null,
        isset($fields['before_id']) ? (string) $fields['before_id'] : null,
        isset($fields['after_id']) ? (string) $fields['after_id'] : null,
        $note,
    ]);
}

/**
 * @return array<string, mixed>
 */
function orange_brand_identity_ingest_object(
    PDO $controlPdo,
    string $absolutePath,
    string $originalFilename,
    int $createdBy
): array {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        throw new RuntimeException('BRAND_IDENTITY_FILE_MISSING');
    }
    $size = (int) filesize($absolutePath);
    if ($size <= 0 || $size > ORANGE_BRAND_IDENTITY_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('BRAND_IDENTITY_FILE_SIZE');
    }
    $mime = orange_brand_identity_detect_mime($absolutePath, $originalFilename);
    $meta = orange_brand_identity_read_image_meta($absolutePath, $mime);
    if ($meta['width'] <= 0 || $meta['height'] <= 0) {
        throw new RuntimeException('BRAND_IDENTITY_INVALID_IMAGE');
    }
    $sha = hash_file('sha256', $absolutePath);
    if (!is_string($sha) || strlen($sha) !== 64) {
        throw new RuntimeException('BRAND_IDENTITY_HASH_FAILED');
    }
    $existing = orange_brand_identity_object($pdo, $sha);
    if ($existing !== null) {
        $abs = orange_brand_identity_object_abs_path($pdo, $sha);
        if ($abs === null) {
            throw new RuntimeException('BRAND_IDENTITY_OBJECT_FILE_MISSING');
        }
        $ownReuse = !$pdo->inTransaction();
        if ($ownReuse) {
            $pdo->beginTransaction();
        }
        try {
            orange_brand_identity_audit($pdo, 'brand_identity.object.ingest', $createdBy, [
                'entity_table' => 'orange_brand_asset_objects',
                'entity_id' => $sha,
                'change_note' => 'reuse',
            ]);
            if ($ownReuse) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownReuse && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e instanceof RuntimeException ? $e : new RuntimeException('BRAND_IDENTITY_METADATA_FAILED');
        }

        return $existing;
    }
    $ext = match ($mime) {
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/jpeg' => 'jpg',
        default => throw new RuntimeException('BRAND_IDENTITY_INVALID_MIME'),
    };
    $objectsDir = orange_brand_identity_objects_dir();
    orange_brand_identity_ensure_dir($objectsDir);
    $dest = $objectsDir . DIRECTORY_SEPARATOR . $sha . '.' . $ext;
    if (is_file($dest)) {
        $existingSha = hash_file('sha256', $dest);
        if ($existingSha !== $sha) {
            throw new RuntimeException('BRAND_IDENTITY_OBJECT_COLLISION');
        }
    } else {
        $raw = (string) file_get_contents($absolutePath);
        if (@file_put_contents($dest, $raw, LOCK_EX) === false) {
            throw new RuntimeException('BRAND_IDENTITY_STORAGE_UNAVAILABLE');
        }
        @chmod($dest, 0644);
        if (!is_file($dest)) {
            throw new RuntimeException('BRAND_IDENTITY_STORAGE_UNAVAILABLE');
        }
    }
    $relpath = 'uploads/brand_identity/objects/' . $sha . '.' . $ext;
    $row = [
        'sha256' => $sha,
        'mime' => $mime,
        'byte_size' => $size,
        'width' => $meta['width'],
        'height' => $meta['height'],
        'aspect_ratio' => round($meta['width'] / $meta['height'], 4),
        'alpha_flag' => $meta['alpha'] ? 1 : 0,
        'alpha_note' => $meta['alpha_note'],
        'relpath' => $relpath,
        'original_filename' => basename($originalFilename),
        'created_by' => $createdBy,
        'created_at' => orange_brand_identity_now(),
    ];
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO orange_brand_asset_objects (
                sha256, mime, byte_size, width, height, aspect_ratio,
                alpha_flag, alpha_note, relpath, original_filename, created_by, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $row['sha256'],
            $row['mime'],
            $row['byte_size'],
            $row['width'],
            $row['height'],
            $row['aspect_ratio'],
            $row['alpha_flag'],
            $row['alpha_note'],
            $row['relpath'],
            $row['original_filename'],
            $row['created_by'],
            $row['created_at'],
        ]);
        $written = orange_brand_identity_object($pdo, $sha);
        if ($written === null) {
            throw new RuntimeException('BRAND_IDENTITY_METADATA_FAILED');
        }
        if (orange_brand_identity_object_abs_path($pdo, $sha) === null) {
            throw new RuntimeException('BRAND_IDENTITY_OBJECT_FILE_MISSING');
        }
        orange_brand_identity_audit($pdo, 'brand_identity.object.ingest', $createdBy, [
            'entity_table' => 'orange_brand_asset_objects',
            'entity_id' => $sha,
            'change_note' => 'ingest',
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof RuntimeException
            && in_array($e->getMessage(), ['BRAND_IDENTITY_METADATA_FAILED', 'BRAND_IDENTITY_OBJECT_FILE_MISSING', 'BRAND_IDENTITY_AUDIT_TYPE_FORBIDDEN'], true)) {
            throw $e;
        }
        throw new RuntimeException('BRAND_IDENTITY_METADATA_FAILED');
    }

    return orange_brand_identity_object($pdo, $sha) ?? $row;
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_object(PDO $controlPdo, string $sha256): ?array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare('SELECT * FROM orange_brand_asset_objects WHERE sha256 = ?');
    $st->execute([$sha256]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function orange_brand_identity_object_abs_path(PDO $controlPdo, string $sha256): ?string
{
    $row = orange_brand_identity_object($controlPdo, $sha256);
    if ($row === null) {
        return null;
    }
    $abs = orange_brand_identity_project_root() . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['relpath']);

    return is_file($abs) ? $abs : null;
}

function orange_brand_identity_assert_mutable_draft(string $state, string $code): void
{
    if ($state !== 'DRAFT') {
        throw new RuntimeException($code);
    }
}

function orange_brand_identity_assert_not_terminal_overwrite(string $state): void
{
    if ($state === 'ACTIVE' || $state === 'ARCHIVED') {
        throw new RuntimeException('BRAND_IDENTITY_IMMUTABLE_RECORD');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_brand_identity_identity_version(PDO $controlPdo, int $id): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare('SELECT * FROM orange_brand_identity_versions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('BRAND_IDENTITY_IDENTITY_NOT_FOUND');
    }

    return $row;
}

/**
 * @return array<string, mixed>
 */
function orange_brand_identity_slot_version(PDO $controlPdo, int $id): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare('SELECT * FROM orange_brand_slot_versions WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('BRAND_IDENTITY_SLOT_NOT_FOUND');
    }

    return $row;
}

/**
 * @return array<string, mixed>
 */
function orange_brand_identity_release(PDO $controlPdo, int $id): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare('SELECT * FROM orange_brand_releases WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_FOUND');
    }

    return $row;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function orange_brand_identity_apply_transition(array $row, string $toState, int $approvedBy): array
{
    orange_brand_identity_assert_state($toState);
    $from = (string) $row['state'];
    $ok = ($from === 'DRAFT' && $toState === 'PREVIEW_READY')
        || ($from === 'PREVIEW_READY' && $toState === 'ACTIVE')
        || ($from === 'ACTIVE' && $toState === 'ARCHIVED')
        || ($from === 'ARCHIVED' && $toState === 'ACTIVE');
    if (!$ok) {
        if ($from === 'ACTIVE' || $from === 'ARCHIVED') {
            throw new RuntimeException('BRAND_IDENTITY_IMMUTABLE_RECORD');
        }
        throw new RuntimeException('BRAND_IDENTITY_ILLEGAL_TRANSITION');
    }
    $row['state'] = $toState;
    $now = orange_brand_identity_now();
    if ($toState === 'PREVIEW_READY') {
        $row['previewed_at'] = $now;
        $row['approved_by'] = $approvedBy;
    } elseif ($toState === 'ACTIVE') {
        $row['activated_at'] = $now;
        if ($row['approved_by'] === null || $row['approved_by'] === '') {
            $row['approved_by'] = $approvedBy;
        }
    } elseif ($toState === 'ARCHIVED') {
        $row['archived_at'] = $now;
    }

    return $row;
}

function orange_brand_identity_member_is_publishable(string $state): bool
{
    return $state === 'PREVIEW_READY' || $state === 'ACTIVE' || $state === 'ARCHIVED';
}

/**
 * @param list<array{locale:string,text_key:string,text_value:string}> $translations
 */
function orange_brand_identity_create_identity_version(
    PDO $controlPdo,
    string $defaultLocale,
    array $translations,
    int $createdBy,
    ?string $changeNote = null
): int {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_locale($pdo, $defaultLocale);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO orange_brand_identity_versions (
                state, default_locale, change_note, created_by, approved_by, created_at,
                previewed_at, activated_at, archived_at
             ) VALUES (?, ?, ?, ?, NULL, ?, NULL, NULL, NULL)'
        );
        $st->execute(['DRAFT', orange_brand_identity_normalize_locale_code($defaultLocale), $changeNote, $createdBy, orange_brand_identity_now()]);
        $id = (int) $pdo->lastInsertId();
        foreach ($translations as $row) {
            orange_brand_identity_put_translation(
                $pdo,
                $id,
                (string) ($row['locale'] ?? ''),
                (string) ($row['text_key'] ?? ''),
                (string) ($row['text_value'] ?? '')
            );
        }
        orange_brand_identity_audit($pdo, 'brand_identity.identity.create', $createdBy, [
            'entity_table' => 'orange_brand_identity_versions',
            'entity_id' => (string) $id,
            'identity_version_id' => $id,
            'change_note' => $changeNote,
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_brand_identity_put_translation(
    PDO $controlPdo,
    int $identityVersionId,
    string $locale,
    string $textKey,
    string $textValue
): void {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $ver = orange_brand_identity_identity_version($pdo, $identityVersionId);
    orange_brand_identity_assert_not_terminal_overwrite((string) $ver['state']);
    orange_brand_identity_assert_mutable_draft((string) $ver['state'], 'BRAND_IDENTITY_IMMUTABLE_RECORD');
    orange_brand_identity_assert_reference_locale($pdo, $locale);
    orange_brand_identity_assert_text_key($textKey);
    $locale = (string) orange_brand_identity_normalize_locale_code($locale);
    $find = $pdo->prepare(
        'SELECT id FROM orange_brand_identity_translations
         WHERE identity_version_id = ? AND locale = ? AND text_key = ?'
    );
    $find->execute([$identityVersionId, $locale, $textKey]);
    $existingId = $find->fetchColumn();
    if ($existingId !== false) {
        $upd = $pdo->prepare('UPDATE orange_brand_identity_translations SET text_value = ? WHERE id = ?');
        $upd->execute([$textValue, (int) $existingId]);

        return;
    }
    $ins = $pdo->prepare(
        'INSERT INTO orange_brand_identity_translations (identity_version_id, locale, text_key, text_value)
         VALUES (?, ?, ?, ?)'
    );
    $ins->execute([$identityVersionId, $locale, $textKey, $textValue]);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_brand_identity_identity_translations(PDO $controlPdo, int $identityVersionId): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'SELECT * FROM orange_brand_identity_translations WHERE identity_version_id = ? ORDER BY id'
    );
    $st->execute([$identityVersionId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Merge a partial slogan/text request onto the displayed identity version.
 * Incoming rows overwrite the same locale+key, including an intentional empty
 * clear. Omitted eligible locales keep the displayed-base wording. Historical
 * locales that are no longer eligible are omitted from the new payload and
 * remain on the old version. Newly submitted unsupported locales fail closed.
 *
 * @param list<array{locale?:string,text_key?:string,text_value?:string}> $incoming
 * @return list<array{locale:string,text_key:string,text_value:string}>
 */
function orange_brand_identity_merge_identity_translations(
    PDO $controlPdo,
    array $incoming,
    ?int $previousIdentityVersionId
): array
{
    orange_brand_identity_assert_language_reference_writable($controlPdo);
    $reference = orange_brand_identity_reference_locale_codes($controlPdo);
    $merged = [];
    $incomingCodes = [];
    if ($previousIdentityVersionId !== null && $previousIdentityVersionId > 0) {
        foreach (orange_brand_identity_identity_translations($controlPdo, $previousIdentityVersionId) as $row) {
            $loc = orange_brand_identity_normalize_locale_code((string) ($row['locale'] ?? ''));
            $key = (string) ($row['text_key'] ?? '');
            if ($loc === null || $key === '') {
                continue;
            }
            if (!in_array($loc, $reference, true)) {
                continue;
            }
            $merged[$loc . "\0" . $key] = [
                'locale' => $loc,
                'text_key' => $key,
                'text_value' => (string) ($row['text_value'] ?? ''),
            ];
        }
    }
    foreach ($incoming as $row) {
        if (!is_array($row)) {
            continue;
        }
        $raw = trim((string) ($row['locale'] ?? ''));
        $text = (string) ($row['text_value'] ?? '');
        if ($raw === '') {
            if (trim($text) !== '') {
                throw new RuntimeException('BRAND_IDENTITY_SLOGAN_LOCALE_REQUIRED');
            }
            continue;
        }
        $loc = orange_brand_identity_normalize_locale_code($raw);
        $key = (string) ($row['text_key'] ?? 'STOREFRONT_SLOGAN');
        if ($loc === null || $key === '' || !in_array($loc, $reference, true)) {
            throw new RuntimeException('BRAND_IDENTITY_UNSUPPORTED_LOCALE');
        }
        if (isset($incomingCodes[$loc . "\0" . $key])) {
            throw new RuntimeException('BRAND_IDENTITY_DUPLICATE_LOCALE');
        }
        $incomingCodes[$loc . "\0" . $key] = true;
        $merged[$loc . "\0" . $key] = [
            'locale' => $loc,
            'text_key' => $key,
            'text_value' => $text,
        ];
    }

    return array_values($merged);
}

/**
 * Accept only a nonnegative integer ID in canonical form.
 * Missing/empty returns null. Decimal, exponent, boolean, negative and junk throw.
 */
function orange_brand_identity_parse_nonnegative_int_id(mixed $raw): ?int
{
    if ($raw === null) {
        return null;
    }
    if (is_bool($raw) || is_float($raw) || is_array($raw) || is_object($raw)) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    if (is_int($raw)) {
        if ($raw < 0) {
            throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
        }

        return $raw;
    }
    if (!is_string($raw)) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^(0|[1-9][0-9]*)$/', $s)) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    if (strlen($s) > 10) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    $n = (int) $s;
    if ((string) $n !== $s && $s !== '0') {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }

    return $n;
}

function orange_brand_identity_latest_identity_version_id(PDO $controlPdo): int
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->query('SELECT id FROM orange_brand_identity_versions ORDER BY id DESC LIMIT 1');

    return (int) ($st !== false ? $st->fetchColumn() : 0);
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_latest_preview_ready_release(PDO $controlPdo): ?array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->query("SELECT * FROM orange_brand_releases WHERE state = 'PREVIEW_READY' ORDER BY id DESC LIMIT 1");
    $row = $st !== false ? $st->fetch(PDO::FETCH_ASSOC) : false;

    return is_array($row) ? $row : null;
}

/**
 * Identity/release whose slogan text the editor must display and merge onto.
 * Active current release wins over a newer abandoned preview. With no active
 * release, the latest PREVIEW_READY release is the editable draft.
 *
 * @return array{
 *   kind:string,
 *   identity_version_id:int,
 *   release_id:int,
 *   identity:?array<string, mixed>,
 *   translations:list<array<string, mixed>>
 * }
 */
function orange_brand_identity_editor_text_source(PDO $controlPdo, ?int $focusReleaseId = null): array
{
    $empty = [
        'kind' => 'none',
        'identity_version_id' => 0,
        'release_id' => 0,
        'identity' => null,
        'translations' => [],
    ];
    if ($focusReleaseId !== null && $focusReleaseId > 0) {
        try {
            $focusRel = orange_brand_identity_release($controlPdo, $focusReleaseId);
            $focusState = (string) ($focusRel['state'] ?? '');
            $iid = (int) ($focusRel['identity_version_id'] ?? 0);
            if ($iid > 0 && ($focusState === 'PREVIEW_READY' || $focusState === 'DRAFT')) {
                $ident = orange_brand_identity_identity_version($controlPdo, $iid);
                $idState = (string) ($ident['state'] ?? '');
                if ($idState === 'PREVIEW_READY' || $idState === 'DRAFT') {
                    return [
                        'kind' => 'focused_preview',
                        'identity_version_id' => $iid,
                        'release_id' => (int) ($focusRel['id'] ?? 0),
                        'identity' => $ident,
                        'translations' => orange_brand_identity_identity_translations($controlPdo, $iid),
                    ];
                }
            }
        } catch (Throwable $e) {
            /* fall through to default displayed source */
        }
    }
    $activeRel = orange_brand_identity_current_release($controlPdo);
    if (is_array($activeRel)) {
        $iid = (int) ($activeRel['identity_version_id'] ?? 0);
        if ($iid > 0) {
            $ident = orange_brand_identity_identity_version($controlPdo, $iid);

            return [
                'kind' => 'active',
                'identity_version_id' => $iid,
                'release_id' => (int) ($activeRel['id'] ?? 0),
                'identity' => $ident,
                'translations' => orange_brand_identity_identity_translations($controlPdo, $iid),
            ];
        }
    }
    $previewRel = orange_brand_identity_latest_preview_ready_release($controlPdo);
    if (!is_array($previewRel)) {
        return $empty;
    }
    $iid = (int) ($previewRel['identity_version_id'] ?? 0);
    if ($iid <= 0) {
        return $empty;
    }
    $ident = orange_brand_identity_identity_version($controlPdo, $iid);
    $state = (string) ($ident['state'] ?? '');
    if ($state !== 'PREVIEW_READY' && $state !== 'DRAFT') {
        return $empty;
    }

    return [
        'kind' => 'preview_draft',
        'identity_version_id' => $iid,
        'release_id' => (int) ($previewRel['id'] ?? 0),
        'identity' => $ident,
        'translations' => orange_brand_identity_identity_translations($controlPdo, $iid),
    ];
}

function orange_brand_identity_preview_release_for_identity(PDO $controlPdo, int $identityVersionId): ?array
{
    if ($identityVersionId <= 0) {
        return null;
    }
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        "SELECT * FROM orange_brand_releases
         WHERE identity_version_id = ? AND state = 'PREVIEW_READY'
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$identityVersionId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function orange_brand_identity_resolve_displayed_merge_base(PDO $controlPdo, mixed $clientBaseId): ?int
{
    $src = orange_brand_identity_editor_text_source($controlPdo);
    $serverId = (int) $src['identity_version_id'];
    if ($clientBaseId === null || (is_string($clientBaseId) && trim($clientBaseId) === '')) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    $client = orange_brand_identity_parse_nonnegative_int_id($clientBaseId);
    if ($client === null) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    if ($client === 0) {
        if ($serverId > 0) {
            throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
        }

        return null;
    }
    try {
        $ver = orange_brand_identity_identity_version($controlPdo, $client);
    } catch (RuntimeException $e) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    $state = (string) ($ver['state'] ?? '');
    if (!in_array($state, ['ACTIVE', 'PREVIEW_READY', 'DRAFT'], true)) {
        throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
    }
    if ($client === $serverId) {
        return $client;
    }
    if (($state === 'PREVIEW_READY' || $state === 'DRAFT')
        && orange_brand_identity_preview_release_for_identity($controlPdo, $client) !== null) {
        return $client;
    }

    throw new RuntimeException('BRAND_IDENTITY_DISPLAYED_SOURCE_MISMATCH');
}

function orange_brand_identity_persist_identity_row(PDO $controlPdo, array $row): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'UPDATE orange_brand_identity_versions
         SET state = ?, approved_by = ?, previewed_at = ?, activated_at = ?, archived_at = ?
         WHERE id = ?'
    );
    $st->execute([
        $row['state'],
        $row['approved_by'],
        $row['previewed_at'],
        $row['activated_at'],
        $row['archived_at'],
        (int) $row['id'],
    ]);
}

function orange_brand_identity_persist_slot_row(PDO $controlPdo, array $row): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'UPDATE orange_brand_slot_versions
         SET state = ?, approved_by = ?, previewed_at = ?, activated_at = ?, archived_at = ?
         WHERE id = ?'
    );
    $st->execute([
        $row['state'],
        $row['approved_by'],
        $row['previewed_at'],
        $row['activated_at'],
        $row['archived_at'],
        (int) $row['id'],
    ]);
}

function orange_brand_identity_persist_release_row(PDO $controlPdo, array $row): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $current = array_key_exists('current_flag', $row) ? $row['current_flag'] : null;
    $st = $pdo->prepare(
        'UPDATE orange_brand_releases
         SET state = ?, approved_by = ?, activated_at = ?, archived_at = ?, current_flag = ?
         WHERE id = ?'
    );
    $st->execute([
        $row['state'],
        $row['approved_by'],
        $row['activated_at'],
        $row['archived_at'],
        $current,
        (int) $row['id'],
    ]);
}

function orange_brand_identity_transition_identity(PDO $controlPdo, int $id, string $toState, int $approvedBy): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_public_preview_only($toState);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $before = orange_brand_identity_identity_version($pdo, $id);
        $row = orange_brand_identity_apply_transition($before, $toState, $approvedBy);
        orange_brand_identity_persist_identity_row($pdo, $row);
        orange_brand_identity_audit($pdo, 'brand_identity.identity.transition', $approvedBy, [
            'entity_table' => 'orange_brand_identity_versions',
            'entity_id' => (string) $id,
            'identity_version_id' => $id,
            'before_id' => (string) $before['state'],
            'after_id' => $toState,
            'change_note' => (string) $before['state'] . '->' . $toState,
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Build a display WebP from an already-accepted PNG, JPEG or WebP.
 * Lossless when GD supports it so logo pixels and alpha are kept.
 * Failure is explicit; the caller must not treat the original as the display file.
 *
 * @return array<string, mixed>
 */
function orange_brand_identity_prepare_display_derivative(PDO $controlPdo, string $absolutePath, int $createdBy): array
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) {
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_UNAVAILABLE');
    }
    $mime = orange_brand_identity_detect_mime($absolutePath, basename($absolutePath));
    $meta = orange_brand_identity_read_image_meta($absolutePath, $mime);
    if ($meta['width'] <= 0 || $meta['height'] <= 0) {
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
    }
    $raw = file_get_contents($absolutePath);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
    }
    $im = @imagecreatefromstring($raw);
    if ($im === false) {
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
    }
    if (function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($im);
    }
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $tmp = tempnam(sys_get_temp_dir(), 'obw');
    if (!is_string($tmp) || $tmp === '') {
        imagedestroy($im);
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
    }
    $webpPath = $tmp . '.webp';
    @unlink($tmp);
    $quality = defined('IMG_WEBP_LOSSLESS') ? (int) IMG_WEBP_LOSSLESS : 100;
    $wrote = @imagewebp($im, $webpPath, $quality);
    imagedestroy($im);
    if ($wrote !== true || !is_file($webpPath)) {
        @unlink($webpPath);
        throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
    }
    try {
        $outMeta = orange_brand_identity_read_image_meta($webpPath, 'image/webp');
        if ((int) $outMeta['width'] !== (int) $meta['width'] || (int) $outMeta['height'] !== (int) $meta['height']) {
            throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
        }
        $row = orange_brand_identity_ingest_object($controlPdo, $webpPath, 'display.webp', $createdBy);
        if ((string) ($row['mime'] ?? '') !== 'image/webp') {
            throw new RuntimeException('BRAND_IDENTITY_DERIVATIVE_FAILED');
        }

        return $row;
    } finally {
        @unlink($webpPath);
    }
}

/**
 * Same sequence as the admin upload action: keep the original, store a WebP display file, link both.
 *
 * @return array{slot_version_id:int,object:array<string,mixed>,derivative:array<string,mixed>}
 */
function orange_brand_identity_upload_slot_with_derivative(
    PDO $controlPdo,
    string $slotCode,
    string $absolutePath,
    string $originalFilename,
    int $actorId
): array {
    $obj = orange_brand_identity_ingest_object($controlPdo, $absolutePath, $originalFilename, $actorId);
    $der = orange_brand_identity_prepare_display_derivative($controlPdo, $absolutePath, $actorId);
    $sid = orange_brand_identity_create_slot_version(
        $controlPdo,
        $slotCode,
        (string) $obj['sha256'],
        $actorId,
        'upload',
        (string) $der['sha256']
    );

    return [
        'slot_version_id' => $sid,
        'object' => $obj,
        'derivative' => $der,
    ];
}

function orange_brand_identity_create_slot_version(
    PDO $controlPdo,
    string $slotCode,
    string $originalSha,
    int $uploadedBy,
    ?string $changeNote = null,
    ?string $derivativeSha = null
): int {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_slot_code($slotCode);
    if (orange_brand_identity_object($pdo, $originalSha) === null) {
        throw new RuntimeException('BRAND_IDENTITY_OBJECT_NOT_FOUND');
    }
    if ($derivativeSha !== null && $derivativeSha !== '' && orange_brand_identity_object($pdo, $derivativeSha) === null) {
        throw new RuntimeException('BRAND_IDENTITY_OBJECT_NOT_FOUND');
    }
    $der = ($derivativeSha !== null && $derivativeSha !== '') ? $derivativeSha : null;
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO orange_brand_slot_versions (
                slot_code, original_object_id, derivative_object_id, state, change_note,
                uploaded_by, approved_by, created_at, previewed_at, activated_at, archived_at
             ) VALUES (?, ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, NULL)'
        );
        $st->execute([$slotCode, $originalSha, $der, 'DRAFT', $changeNote, $uploadedBy, orange_brand_identity_now()]);
        $id = (int) $pdo->lastInsertId();
        orange_brand_identity_audit($pdo, 'brand_identity.slot.create', $uploadedBy, [
            'entity_table' => 'orange_brand_slot_versions',
            'entity_id' => (string) $id,
            'slot_version_id' => $id,
            'slot_code' => $slotCode,
            'change_note' => $changeNote,
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_brand_identity_transition_slot(PDO $controlPdo, int $id, string $toState, int $approvedBy): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_public_preview_only($toState);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $before = orange_brand_identity_slot_version($pdo, $id);
        $row = orange_brand_identity_apply_transition($before, $toState, $approvedBy);
        orange_brand_identity_persist_slot_row($pdo, $row);
        orange_brand_identity_audit($pdo, 'brand_identity.slot.transition', $approvedBy, [
            'entity_table' => 'orange_brand_slot_versions',
            'entity_id' => (string) $id,
            'slot_version_id' => $id,
            'before_id' => (string) $before['state'],
            'after_id' => $toState,
            'change_note' => (string) $before['state'] . '->' . $toState,
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param array<string, int> $slotVersionIdsByCode
 */
function orange_brand_identity_create_release(
    PDO $controlPdo,
    int $identityVersionId,
    array $slotVersionIdsByCode,
    int $createdBy,
    ?string $note = null
): int {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_identity_version($pdo, $identityVersionId);
    $required = orange_brand_identity_slot_codes();
    if (count($slotVersionIdsByCode) !== 4) {
        throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
    }
    foreach ($required as $code) {
        if (!isset($slotVersionIdsByCode[$code])) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
        }
    }
    if (count(array_unique(array_keys($slotVersionIdsByCode))) !== 4) {
        throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
    }
    foreach ($slotVersionIdsByCode as $code => $sid) {
        orange_brand_identity_assert_slot_code((string) $code);
        $sv = orange_brand_identity_slot_version($pdo, (int) $sid);
        if ((string) $sv['slot_code'] !== (string) $code) {
            throw new RuntimeException('BRAND_IDENTITY_SLOT_MISMATCH');
        }
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $ins = $pdo->prepare(
            'INSERT INTO orange_brand_releases (
                identity_version_id, state, note, current_flag, created_by, approved_by,
                created_at, activated_at, archived_at
             ) VALUES (?, ?, ?, NULL, ?, NULL, ?, NULL, NULL)'
        );
        $ins->execute([$identityVersionId, 'DRAFT', $note, $createdBy, orange_brand_identity_now()]);
        $id = (int) $pdo->lastInsertId();
        $rs = $pdo->prepare(
            'INSERT INTO orange_brand_release_slots (release_id, slot_code, slot_version_id) VALUES (?, ?, ?)'
        );
        foreach ($slotVersionIdsByCode as $code => $sid) {
            $rs->execute([$id, (string) $code, (int) $sid]);
        }
        orange_brand_identity_audit($pdo, 'brand_identity.release.create', $createdBy, [
            'entity_table' => 'orange_brand_releases',
            'entity_id' => (string) $id,
            'release_id' => $id,
            'identity_version_id' => $identityVersionId,
            'change_note' => $note,
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $id;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_brand_identity_transition_release(PDO $controlPdo, int $id, string $toState, int $approvedBy): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_public_preview_only($toState);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $before = orange_brand_identity_release($pdo, $id);
        $row = orange_brand_identity_apply_transition($before, $toState, $approvedBy);
        orange_brand_identity_persist_release_row($pdo, $row);
        orange_brand_identity_audit($pdo, 'brand_identity.release.transition', $approvedBy, [
            'entity_table' => 'orange_brand_releases',
            'entity_id' => (string) $id,
            'release_id' => $id,
            'before_id' => (string) $before['state'],
            'after_id' => $toState,
            'change_note' => (string) $before['state'] . '->' . $toState,
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return list<array<string, mixed>>
 */
function orange_brand_identity_release_slots(PDO $controlPdo, int $releaseId): array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'SELECT * FROM orange_brand_release_slots WHERE release_id = ? ORDER BY slot_code'
    );
    $st->execute([$releaseId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function orange_brand_identity_lock_current_release_rows(PDO $controlPdo): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $sql = 'SELECT id FROM orange_brand_releases WHERE current_flag = 1';
    if (orange_brand_identity_driver($pdo) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $pdo->query($sql);
}

function orange_brand_identity_audit_member_transition(
    PDO $controlPdo,
    string $kind,
    int $id,
    string $fromState,
    string $toState,
    int $actorId,
    array $extra = []
): void {
    if ($fromState === $toState) {
        return;
    }
    $type = match ($kind) {
        'identity' => 'brand_identity.identity.transition',
        'slot' => 'brand_identity.slot.transition',
        'release' => 'brand_identity.release.transition',
        default => throw new RuntimeException('BRAND_IDENTITY_AUDIT_TYPE_FORBIDDEN'),
    };
    $table = match ($kind) {
        'identity' => 'orange_brand_identity_versions',
        'slot' => 'orange_brand_slot_versions',
        default => 'orange_brand_releases',
    };
    $fields = array_merge($extra, [
        'entity_table' => $table,
        'entity_id' => (string) $id,
        'before_id' => $fromState,
        'after_id' => $toState,
        'change_note' => $fromState . '->' . $toState,
    ]);
    if ($kind === 'identity') {
        $fields['identity_version_id'] = $id;
    } elseif ($kind === 'slot') {
        $fields['slot_version_id'] = $id;
    } else {
        $fields['release_id'] = $id;
    }
    orange_brand_identity_audit($controlPdo, $type, $actorId, $fields);
}

function orange_brand_identity_activate_release(PDO $controlPdo, int $releaseId, int $approvedBy): void
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        orange_brand_identity_lock_current_release_rows($pdo);
        $release = orange_brand_identity_release($pdo, $releaseId);
        $slots = orange_brand_identity_release_slots($pdo, $releaseId);
        if (count($slots) !== 4) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
        }
        $seen = [];
        foreach ($slots as $slot) {
            $code = (string) $slot['slot_code'];
            orange_brand_identity_assert_slot_code($code);
            if (isset($seen[$code])) {
                throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
            }
            $seen[$code] = true;
            $sid = (int) $slot['slot_version_id'];
            $sv = orange_brand_identity_slot_version($pdo, $sid);
            if (!orange_brand_identity_member_is_publishable((string) $sv['state'])) {
                throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_APPROVED');
            }
        }
        foreach (orange_brand_identity_slot_codes() as $need) {
            if (!isset($seen[$need])) {
                throw new RuntimeException('BRAND_IDENTITY_RELEASE_INCOMPLETE');
            }
        }
        $iid = (int) $release['identity_version_id'];
        $identity = orange_brand_identity_identity_version($pdo, $iid);
        if (!orange_brand_identity_member_is_publishable((string) $identity['state'])) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_APPROVED');
        }
        $rstate = (string) $release['state'];
        if ($rstate === 'DRAFT') {
            $release = orange_brand_identity_apply_transition($release, 'PREVIEW_READY', $approvedBy);
            orange_brand_identity_persist_release_row($pdo, $release);
            orange_brand_identity_audit_member_transition($pdo, 'release', $releaseId, $rstate, 'PREVIEW_READY', $approvedBy);
            $release = orange_brand_identity_release($pdo, $releaseId);
        } elseif ($rstate !== 'PREVIEW_READY') {
            throw new RuntimeException('BRAND_IDENTITY_ILLEGAL_TRANSITION');
        }
        $current = orange_brand_identity_current_release($pdo);
        $beforeId = $current !== null ? (int) $current['id'] : null;
        if ($current !== null && (int) $current['id'] !== $releaseId) {
            $oldId = (int) $current['id'];
            $oldSlots = orange_brand_identity_release_slots($pdo, $oldId);
            $newSlotIds = [];
            foreach ($slots as $slot) {
                $newSlotIds[(string) $slot['slot_code']] = (int) $slot['slot_version_id'];
            }
            foreach ($oldSlots as $oldSlot) {
                $code = (string) $oldSlot['slot_code'];
                $oldSid = (int) $oldSlot['slot_version_id'];
                if (!isset($newSlotIds[$code]) || $newSlotIds[$code] !== $oldSid) {
                    $oldSv = orange_brand_identity_slot_version($pdo, $oldSid);
                    if ((string) $oldSv['state'] === 'ACTIVE') {
                        orange_brand_identity_persist_slot_row(
                            $pdo,
                            orange_brand_identity_apply_transition($oldSv, 'ARCHIVED', $approvedBy)
                        );
                        orange_brand_identity_audit_member_transition(
                            $pdo,
                            'slot',
                            $oldSid,
                            'ACTIVE',
                            'ARCHIVED',
                            $approvedBy,
                            ['slot_code' => $code]
                        );
                    }
                }
            }
            $oldIid = (int) $current['identity_version_id'];
            if ($oldIid !== $iid) {
                $oldIdent = orange_brand_identity_identity_version($pdo, $oldIid);
                if ((string) $oldIdent['state'] === 'ACTIVE') {
                    orange_brand_identity_persist_identity_row(
                        $pdo,
                        orange_brand_identity_apply_transition($oldIdent, 'ARCHIVED', $approvedBy)
                    );
                    orange_brand_identity_audit_member_transition($pdo, 'identity', $oldIid, 'ACTIVE', 'ARCHIVED', $approvedBy);
                }
            }
            $oldRel = orange_brand_identity_release($pdo, $oldId);
            $oldFrom = (string) $oldRel['state'];
            $oldRel = orange_brand_identity_apply_transition($oldRel, 'ARCHIVED', $approvedBy);
            $oldRel['current_flag'] = null;
            orange_brand_identity_persist_release_row($pdo, $oldRel);
            orange_brand_identity_audit_member_transition($pdo, 'release', $oldId, $oldFrom, 'ARCHIVED', $approvedBy);
        }
        $identity = orange_brand_identity_identity_version($pdo, $iid);
        $idState = (string) $identity['state'];
        if ($idState === 'PREVIEW_READY' || $idState === 'ARCHIVED') {
            orange_brand_identity_persist_identity_row(
                $pdo,
                orange_brand_identity_apply_transition($identity, 'ACTIVE', $approvedBy)
            );
            orange_brand_identity_audit_member_transition($pdo, 'identity', $iid, $idState, 'ACTIVE', $approvedBy);
        }
        foreach ($slots as $slot) {
            $sid = (int) $slot['slot_version_id'];
            $sv = orange_brand_identity_slot_version($pdo, $sid);
            $sState = (string) $sv['state'];
            if ($sState === 'PREVIEW_READY' || $sState === 'ARCHIVED') {
                orange_brand_identity_persist_slot_row(
                    $pdo,
                    orange_brand_identity_apply_transition($sv, 'ACTIVE', $approvedBy)
                );
                orange_brand_identity_audit_member_transition(
                    $pdo,
                    'slot',
                    $sid,
                    $sState,
                    'ACTIVE',
                    $approvedBy,
                    ['slot_code' => (string) $slot['slot_code']]
                );
            }
        }
        $release = orange_brand_identity_release($pdo, $releaseId);
        $relFrom = (string) $release['state'];
        $release = orange_brand_identity_apply_transition($release, 'ACTIVE', $approvedBy);
        $release['current_flag'] = 1;
        orange_brand_identity_persist_release_row($pdo, $release);
        orange_brand_identity_audit_member_transition($pdo, 'release', $releaseId, $relFrom, 'ACTIVE', $approvedBy);
        orange_brand_identity_audit($pdo, 'brand_identity.release.activate', $approvedBy, [
            'entity_table' => 'orange_brand_releases',
            'entity_id' => (string) $releaseId,
            'release_id' => $releaseId,
            'identity_version_id' => $iid,
            'before_id' => $beforeId !== null ? (string) $beforeId : null,
            'after_id' => (string) $releaseId,
            'change_note' => 'activate',
        ]);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_current_release(PDO $controlPdo): ?array
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->query('SELECT * FROM orange_brand_releases WHERE current_flag = 1 LIMIT 1');
    $row = $st !== false ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (is_array($row)) {
        return $row;
    }

    return null;
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_current_identity_version(PDO $controlPdo): ?array
{
    $cur = orange_brand_identity_current_release($controlPdo);
    if ($cur === null) {
        return null;
    }

    return orange_brand_identity_identity_version($controlPdo, (int) $cur['identity_version_id']);
}

/**
 * @return array<string, mixed>|null
 */
function orange_brand_identity_current_slot_version(PDO $controlPdo, string $slotCode): ?array
{
    orange_brand_identity_assert_slot_code($slotCode);
    $cur = orange_brand_identity_current_release($controlPdo);
    if ($cur === null) {
        return null;
    }
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $st = $pdo->prepare(
        'SELECT sv.* FROM orange_brand_release_slots rs
         INNER JOIN orange_brand_slot_versions sv ON sv.id = rs.slot_version_id
         WHERE rs.release_id = ? AND rs.slot_code = ?'
    );
    $st->execute([(int) $cur['id'], $slotCode]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function orange_brand_identity_rollback_full(PDO $controlPdo, int $historicReleaseId, int $actorId): int
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        orange_brand_identity_lock_current_release_rows($pdo);
        $hist = orange_brand_identity_release($pdo, $historicReleaseId);
        if (!in_array((string) $hist['state'], ['ACTIVE', 'ARCHIVED'], true) && (int) ($hist['current_flag'] ?? 0) !== 1) {
            throw new RuntimeException('BRAND_IDENTITY_RELEASE_NOT_PUBLISHED');
        }
        $slots = orange_brand_identity_release_slots($pdo, $historicReleaseId);
        $map = [];
        foreach ($slots as $slot) {
            $map[(string) $slot['slot_code']] = (int) $slot['slot_version_id'];
        }
        $newId = orange_brand_identity_create_release(
            $pdo,
            (int) $hist['identity_version_id'],
            $map,
            $actorId,
            'full rollback of release ' . $historicReleaseId
        );
        orange_brand_identity_activate_release($pdo, $newId, $actorId);
        orange_brand_identity_audit($pdo, 'brand_identity.release.rollback_full', $actorId, [
            'entity_table' => 'orange_brand_releases',
            'entity_id' => (string) $newId,
            'release_id' => $newId,
            'identity_version_id' => (int) $hist['identity_version_id'],
            'before_id' => (string) $historicReleaseId,
            'after_id' => (string) $newId,
            'change_note' => 'full rollback',
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $newId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_brand_identity_rollback_slot(
    PDO $controlPdo,
    string $slotCode,
    int $historicSlotVersionId,
    int $actorId
): int {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    orange_brand_identity_assert_slot_code($slotCode);
    $hist = orange_brand_identity_slot_version($pdo, $historicSlotVersionId);
    if ((string) $hist['slot_code'] !== $slotCode) {
        throw new RuntimeException('BRAND_IDENTITY_SLOT_MISMATCH');
    }
    if (!in_array((string) $hist['state'], ['ACTIVE', 'ARCHIVED'], true)) {
        throw new RuntimeException('BRAND_IDENTITY_SLOT_NOT_PUBLISHED');
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        orange_brand_identity_lock_current_release_rows($pdo);
        $cur = orange_brand_identity_current_release($pdo);
        if ($cur === null) {
            throw new RuntimeException('BRAND_IDENTITY_NO_ACTIVE_RELEASE');
        }
        $map = [];
        foreach (orange_brand_identity_release_slots($pdo, (int) $cur['id']) as $slot) {
            $map[(string) $slot['slot_code']] = (int) $slot['slot_version_id'];
        }
        $map[$slotCode] = $historicSlotVersionId;
        $newId = orange_brand_identity_create_release(
            $pdo,
            (int) $cur['identity_version_id'],
            $map,
            $actorId,
            'single-slot rollback ' . $slotCode
        );
        orange_brand_identity_activate_release($pdo, $newId, $actorId);
        orange_brand_identity_audit($pdo, 'brand_identity.release.rollback_slot', $actorId, [
            'entity_table' => 'orange_brand_releases',
            'entity_id' => (string) $newId,
            'release_id' => $newId,
            'slot_code' => $slotCode,
            'identity_version_id' => (int) $cur['identity_version_id'],
            'slot_version_id' => $historicSlotVersionId,
            'before_id' => (string) $cur['id'],
            'after_id' => (string) $newId,
            'change_note' => 'single-slot rollback',
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $newId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @param list<array{locale:string,text_key:string,text_value:string}> $translations
 */
function orange_brand_identity_change_identity_text(
    PDO $controlPdo,
    string $defaultLocale,
    array $translations,
    int $actorId,
    ?string $changeNote = null
): int {
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        orange_brand_identity_lock_current_release_rows($pdo);
        $cur = orange_brand_identity_current_release($pdo);
        if ($cur === null) {
            throw new RuntimeException('BRAND_IDENTITY_NO_ACTIVE_RELEASE');
        }
        $newIid = orange_brand_identity_create_identity_version($pdo, $defaultLocale, $translations, $actorId, $changeNote);
        orange_brand_identity_transition_identity($pdo, $newIid, 'PREVIEW_READY', $actorId);
        $map = [];
        foreach (orange_brand_identity_release_slots($pdo, (int) $cur['id']) as $slot) {
            $map[(string) $slot['slot_code']] = (int) $slot['slot_version_id'];
        }
        $newRid = orange_brand_identity_create_release($pdo, $newIid, $map, $actorId, $changeNote ?? 'identity text change');
        orange_brand_identity_activate_release($pdo, $newRid, $actorId);
        orange_brand_identity_audit($pdo, 'brand_identity.identity.change', $actorId, [
            'entity_table' => 'orange_brand_identity_versions',
            'entity_id' => (string) $newIid,
            'release_id' => $newRid,
            'identity_version_id' => $newIid,
            'before_id' => (string) $cur['identity_version_id'],
            'after_id' => (string) $newIid,
            'change_note' => $changeNote,
        ]);
        if ($own) {
            $pdo->commit();
        }

        return $newRid;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function orange_brand_identity_historical_object_count(PDO $controlPdo): int
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $n = $pdo->query('SELECT COUNT(*) FROM orange_brand_asset_objects');

    return (int) ($n !== false ? $n->fetchColumn() : 0);
}

function orange_brand_identity_deleted_object_files(): int
{
    return 0;
}

function orange_brand_identity_audit_count(PDO $controlPdo): int
{
    $pdo = orange_brand_identity_require_control_pdo($controlPdo);
    $n = $pdo->query('SELECT COUNT(*) FROM orange_brand_identity_audit_events');

    return (int) ($n !== false ? $n->fetchColumn() : 0);
}
