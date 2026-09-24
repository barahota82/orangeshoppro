<?php
declare(strict_types=1);

/**
 * Orange Phase 2.1B.3 — dormant versioned system-text I18N catalog loader.
 * LOCAL DORMANT only — no Admin/Storefront/API wiring in this phase.
 * catalog_revision=1; APCU_ENABLED_REV1=0
 */

const APCU_ENABLED_REV1 = 0;
const EXPECTED_META_SHA256 = '9a94e9794ffba0ee12f3cf916ab2bfeb69ace8b78f19a5d09a2968efb5b33224';
const EXPECTED_AGGREGATE_MANIFEST_SHA256 = 'a99f7d44f4768ca12f76653cead11098710c38547f63f15f26e7ec83108883d1';
const ORANGE_I18N_CATALOG_REVISION = 1;
const ORANGE_I18N_SAFE_GENERIC_KEY = 'storefront.system.safe_generic_unavailable';
const ORANGE_I18N_PLACEHOLDER_REGEX = '/\{([A-Za-z_][A-Za-z0-9_]{0,63})\}/';
const ORANGE_I18N_LEGACY_KEY_REGEX = '/^[A-Za-z0-9_]{1,64}$/';

function orange_system_i18n_catalog_revision(): int
{
    return ORANGE_I18N_CATALOG_REVISION;
}

/**
 * @return array{
 *   ok:bool,text:string,locale_requested:string,locale_used:?string,
 *   key_requested:string,key_used:?string,package:string,mode:string,
 *   source:string,status:?string,value_kind:?string,review_status:?string,
 *   params_expected:list<string>,params_used:array<string,string|int|float>,
 *   params_missing:list<string>,params_extra:list<string>,
 *   is_fallback:bool,is_missing:bool,failure_code:?string,evidence_code:?string,
 *   catalog_revision:int,meta_hash:string,aggregate_manifest_hash:string,locale_file_hash:string
 * }
 */
function orange_system_i18n_lookup_context(
    string $key,
    string $locale,
    array $params = [],
    string $package = 'canonical',
    string $mode = 'strict'
): array {
    $base = orange_system_i18n_empty_result($key, $locale, $package, $mode);
    try {
        $bundle = orange_system_i18n_load_verified_bundle();
    } catch (Throwable $e) {
        $base['ok'] = false;
        $base['failure_code'] = 'I18N_E_META_INTEGRITY';
        $base['evidence_code'] = 'load_failed';
        $base['is_missing'] = true;
        $base['source'] = 'missing';
        return $base;
    }
    $base['meta_hash'] = $bundle['meta_hash'];
    $base['aggregate_manifest_hash'] = $bundle['aggregate_manifest_hash'];

    if ($package !== 'legacy' && $package !== 'canonical') {
        $base['ok'] = false;
        $base['failure_code'] = 'I18N_E_PACKAGE';
        $base['evidence_code'] = 'bad_package';
        $base['is_missing'] = true;
        $base['source'] = 'missing';
        return $base;
    }
    $modes = ['strict', 'fallback_en', 'legacy_crosswalk', 'foundation_probe', 'safe_production'];
    if (!in_array($mode, $modes, true)) {
        $base['ok'] = false;
        $base['failure_code'] = 'I18N_E_MODE';
        $base['evidence_code'] = 'bad_mode';
        $base['is_missing'] = true;
        $base['source'] = 'missing';
        return $base;
    }

    $resolveKey = $key;
    $sourceHint = 'requested';
    if ($mode === 'legacy_crosswalk' && $package === 'canonical' && preg_match(ORANGE_I18N_LEGACY_KEY_REGEX, $key)) {
        $map = $bundle['meta']['legacy_to_canonical'] ?? [];
        if (isset($map[$key])) {
            $resolveKey = (string)$map[$key];
            $sourceHint = 'legacy_crosswalk';
        }
    }

    $tryLocales = [$locale];
    if (in_array($mode, ['fallback_en', 'legacy_crosswalk', 'safe_production'], true) && $locale !== 'en') {
        $tryLocales[] = 'en';
    }

    $hit = null;
    $usedLocale = null;
    $isFallback = false;
    $found = false;
    foreach ($tryLocales as $idx => $tryLoc) {
        $pack = orange_system_i18n_package_map($bundle, $tryLoc, $package);
        if ($pack !== null && array_key_exists($resolveKey, $pack)) {
            $hit = (string)$pack[$resolveKey];
            $usedLocale = $tryLoc;
            $isFallback = ($idx > 0);
            $found = true;
            if ($idx > 0) {
                $sourceHint = 'english_fallback';
            } elseif ($sourceHint !== 'legacy_crosswalk') {
                $sourceHint = 'requested';
            }
            break;
        }
    }

    if ($mode === 'foundation_probe' && $locale === 'fr' && $package === 'canonical') {
        $pack = orange_system_i18n_package_map($bundle, 'fr', 'canonical');
        if ($pack !== null && array_key_exists($resolveKey, $pack) && (string)$pack[$resolveKey] === '') {
            $metaInfo = orange_system_i18n_key_meta($bundle, $package, $resolveKey, 'fr');
            $base['ok'] = true;
            $base['text'] = '';
            $base['locale_used'] = 'fr';
            $base['key_used'] = $resolveKey;
            $base['source'] = 'foundation_probe';
            $base['status'] = 'FOUNDATION_INCOMPLETE';
            $base['value_kind'] = $metaInfo['value_kind'] ?? 'EMPTY_FOUNDATION';
            $base['review_status'] = $metaInfo['review_status'] ?? 'foundation_incomplete';
            $base['is_fallback'] = false;
            $base['is_missing'] = false;
            $base['evidence_code'] = 'foundation_probe_empty';
            $base['locale_file_hash'] = $bundle['locale_hashes']['fr.php'] ?? '';
            $base['params_expected'] = [];
            $base['params_used'] = [];
            $base['params_missing'] = [];
            $base['params_extra'] = [];
            $base['failure_code'] = null;
            return $base;
        }
    }

    if (!$found && $mode === 'safe_production') {
        $enPack = orange_system_i18n_package_map($bundle, 'en', 'canonical');
        if ($enPack !== null && isset($enPack[ORANGE_I18N_SAFE_GENERIC_KEY])) {
            $hit = (string)$enPack[ORANGE_I18N_SAFE_GENERIC_KEY];
            $usedLocale = 'en';
            $resolveKey = ORANGE_I18N_SAFE_GENERIC_KEY;
            $sourceHint = 'safe_generic';
            $isFallback = true;
            $found = true;
        }
    }

    if (!$found) {
        $fail = ($locale !== 'en' && in_array($mode, ['fallback_en', 'legacy_crosswalk'], true))
            ? 'I18N_E_ENGLISH_REQUIRED_KEY_MISSING'
            : 'I18N_E_MISSING';
        $base['ok'] = false;
        $base['text'] = '';
        $base['failure_code'] = $fail;
        $base['evidence_code'] = 'missing';
        $base['is_missing'] = true;
        $base['source'] = 'missing';
        $base['key_used'] = null;
        $base['locale_used'] = null;
        return $base;
    }

    $paramCheck = orange_system_i18n_apply_params((string)$hit, $params);
    if ($paramCheck['failure_code'] !== null) {
        $base['ok'] = false;
        $base['text'] = '';
        $base['failure_code'] = $paramCheck['failure_code'];
        $base['evidence_code'] = 'param_fail';
        $base['params_expected'] = $paramCheck['params_expected'];
        $base['params_used'] = $paramCheck['params_used'];
        $base['params_missing'] = $paramCheck['params_missing'];
        $base['params_extra'] = $paramCheck['params_extra'];
        $base['locale_used'] = $usedLocale;
        $base['key_used'] = $resolveKey;
        $base['source'] = $sourceHint;
        $base['is_fallback'] = $isFallback;
        $base['is_missing'] = false;
        $base['locale_file_hash'] = $bundle['locale_hashes'][($usedLocale ?? '') . '.php'] ?? '';
        return $base;
    }

    $metaInfo = orange_system_i18n_key_meta($bundle, $package, $resolveKey, (string)$usedLocale);
    $status = $metaInfo['coverage'] ?? 'COVERED';
    if ($sourceHint === 'english_fallback') {
        $status = 'FALLBACK_EN';
    } elseif ($sourceHint === 'safe_generic') {
        $status = 'NEW_NOT_LEGACY';
    }

    $base['ok'] = true;
    $base['text'] = $paramCheck['text'];
    $base['locale_used'] = $usedLocale;
    $base['key_used'] = $resolveKey;
    $base['source'] = $sourceHint;
    $base['status'] = $status;
    $base['value_kind'] = $metaInfo['value_kind'] ?? 'PLAIN';
    $base['review_status'] = $metaInfo['review_status'] ?? null;
    $base['params_expected'] = $paramCheck['params_expected'];
    $base['params_used'] = $paramCheck['params_used'];
    $base['params_missing'] = [];
    $base['params_extra'] = [];
    $base['is_fallback'] = ($sourceHint === 'english_fallback' || $sourceHint === 'safe_generic');
    $base['is_missing'] = false;
    $base['failure_code'] = null;
    $base['evidence_code'] = 'hit';
    $base['locale_file_hash'] = $bundle['locale_hashes'][$usedLocale . '.php'] ?? '';
    $base['catalog_revision'] = ORANGE_I18N_CATALOG_REVISION;
    return $base;
}

function orange_system_i18n_empty_result(string $key, string $locale, string $package, string $mode): array
{
    return [
        'ok' => false,
        'text' => '',
        'locale_requested' => $locale,
        'locale_used' => null,
        'key_requested' => $key,
        'key_used' => null,
        'package' => $package,
        'mode' => $mode,
        'source' => 'missing',
        'status' => null,
        'value_kind' => null,
        'review_status' => null,
        'params_expected' => [],
        'params_used' => [],
        'params_missing' => [],
        'params_extra' => [],
        'is_fallback' => false,
        'is_missing' => true,
        'failure_code' => null,
        'evidence_code' => null,
        'catalog_revision' => ORANGE_I18N_CATALOG_REVISION,
        'meta_hash' => '',
        'aggregate_manifest_hash' => '',
        'locale_file_hash' => '',
    ];
}

function orange_system_i18n_roots(): array
{
    $root = dirname(__DIR__, 2);
    return [
        'root' => $root,
        'meta' => $root . '/includes/i18n/catalogs/v1/meta.php',
        'locales' => [
            'ar.php' => $root . '/includes/i18n/catalogs/v1/ar.php',
            'en.php' => $root . '/includes/i18n/catalogs/v1/en.php',
            'fil.php' => $root . '/includes/i18n/catalogs/v1/fil.php',
            'fr.php' => $root . '/includes/i18n/catalogs/v1/fr.php',
            'hi.php' => $root . '/includes/i18n/catalogs/v1/hi.php',
        ],
        'admin_shell' => $root . '/includes/i18n/admin_shell_keys_v1.php',
        'app_t' => $root . '/includes/i18n/app_t_allowlist_v1.php',
    ];
}

function orange_system_i18n_validate_file_bytes(string $bytes): void
{
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
    if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
    if (!mb_check_encoding($bytes, 'UTF-8')) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
    if (str_contains($bytes, "\r")) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
    if ($bytes === '' || !str_ends_with($bytes, "\n") || str_ends_with($bytes, "\n\n")) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
}

function orange_system_i18n_hash_file(string $path): string
{
    $bytes = file_get_contents($path);
    if ($bytes === false) {
        throw new RuntimeException('I18N_E_ENCODING');
    }
    orange_system_i18n_validate_file_bytes($bytes);
    return hash('sha256', $bytes);
}

/**
 * Verify order B5 steps 1–8; memoize request-lifetime only (no APCu).
 *
 * @return array<string,mixed>
 */
function orange_system_i18n_load_verified_bundle(): array
{
    static $memo = null;
    static $requestPackageMemo = [];
    if (is_array($memo)) {
        return $memo;
    }
    if (APCU_ENABLED_REV1 !== 0) {
        throw new RuntimeException('I18N_E_META_INTEGRITY');
    }
    $paths = orange_system_i18n_roots();

    // 1) Validate and hash meta.php
    $metaHash = orange_system_i18n_hash_file($paths['meta']);
    // 2) Compare with EXPECTED_META_SHA256
    if (!hash_equals(EXPECTED_META_SHA256, $metaHash)) {
        throw new RuntimeException('I18N_E_META_INTEGRITY');
    }
    // 3) Load only expected hash metadata
    /** @var array<string,mixed> $meta */
    $meta = require $paths['meta'];
    if (!is_array($meta) || (int)($meta['catalog_revision'] ?? 0) !== 1) {
        throw new RuntimeException('I18N_E_META_INTEGRITY');
    }
    $expected = $meta['expected_content_sha256'] ?? null;
    if (!is_array($expected)) {
        throw new RuntimeException('I18N_E_META_INTEGRITY');
    }

    // 4–5) Validate/hash each payload; compare to meta
    $localeHashes = [];
    foreach ($paths['locales'] as $base => $path) {
        $h = orange_system_i18n_hash_file($path);
        if (!isset($expected[$base]) || !hash_equals((string)$expected[$base], $h)) {
            throw new RuntimeException('I18N_E_HASH_MISMATCH');
        }
        $localeHashes[$base] = $h;
    }
    $adminHash = orange_system_i18n_hash_file($paths['admin_shell']);
    if (!isset($expected['admin_shell_keys_v1.php']) || !hash_equals((string)$expected['admin_shell_keys_v1.php'], $adminHash)) {
        throw new RuntimeException('I18N_E_HASH_MISMATCH');
    }
    $appTHash = orange_system_i18n_hash_file($paths['app_t']);
    if (!isset($expected['app_t_allowlist_v1.php']) || !hash_equals((string)$expected['app_t_allowlist_v1.php'], $appTHash)) {
        throw new RuntimeException('I18N_E_HASH_MISMATCH');
    }

    // 6) Build Aggregate Manifest input (B5 exact basenames/order)
    $agg = "catalog_revision=1\n"
        . "meta.php={$metaHash}\n"
        . "ar.php={$localeHashes['ar.php']}\n"
        . "en.php={$localeHashes['en.php']}\n"
        . "fil.php={$localeHashes['fil.php']}\n"
        . "fr.php={$localeHashes['fr.php']}\n"
        . "hi.php={$localeHashes['hi.php']}\n"
        . "admin_shell_keys_v1.php={$adminHash}\n"
        . "app_t_allowlist_v1.php={$appTHash}\n";
    // 7) Verify aggregate_manifest_hash
    $aggHash = hash('sha256', $agg);
    if (!hash_equals(EXPECTED_AGGREGATE_MANIFEST_SHA256, $aggHash)) {
        throw new RuntimeException('I18N_E_AGGREGATE_MISMATCH');
    }

    // 8) Accept / memoize packages only after verification
    $packages = [];
    foreach (['en', 'ar', 'fil', 'hi', 'fr'] as $loc) {
        $data = require $paths['locales'][$loc . '.php'];
        if (!is_array($data)) {
            throw new RuntimeException('I18N_E_META_INTEGRITY');
        }
        $packages[$loc] = [
            'legacy' => is_array($data['legacy'] ?? null) ? $data['legacy'] : [],
            'canonical' => is_array($data['canonical'] ?? null) ? $data['canonical'] : [],
        ];
        foreach (['legacy', 'canonical'] as $pkg) {
            $mk = orange_system_i18n_memo_key(ORANGE_I18N_CATALOG_REVISION, $aggHash, $loc, $pkg);
            $requestPackageMemo[$mk] = $packages[$loc][$pkg];
        }
    }
    $admin = require $paths['admin_shell'];
    $appT = require $paths['app_t'];

    $memo = [
        'meta' => $meta,
        'meta_hash' => $metaHash,
        'aggregate_manifest_hash' => $aggHash,
        'aggregate_manifest' => $agg,
        'locale_hashes' => $localeHashes + [
            'admin_shell_keys_v1.php' => $adminHash,
            'app_t_allowlist_v1.php' => $appTHash,
        ],
        'packages' => $packages,
        'admin_shell' => $admin,
        'app_t' => $appT,
        'request_package_memo' => $requestPackageMemo,
    ];
    return $memo;
}

function orange_system_i18n_get_meta(): array
{
    $b = orange_system_i18n_load_verified_bundle();
    return $b['meta'];
}

function orange_system_i18n_get_package(string $locale, string $package): array
{
    $b = orange_system_i18n_load_verified_bundle();
    $map = orange_system_i18n_package_map($b, $locale, $package);
    return $map ?? [];
}

function orange_system_i18n_package_map(array $bundle, string $locale, string $package): ?array
{
    if (!isset($bundle['packages'][$locale][$package]) || !is_array($bundle['packages'][$locale][$package])) {
        return null;
    }
    return $bundle['packages'][$locale][$package];
}

function orange_system_i18n_key_meta(array $bundle, string $package, string $key, string $locale): array
{
    $km = $bundle['meta']['key_meta'][$package][$key]['locale_meta'][$locale] ?? null;
    return is_array($km) ? $km : [];
}

/**
 * @param array<string,mixed> $params
 * @return array{text:string,failure_code:?string,params_expected:list<string>,params_used:array<string,string|int|float>,params_missing:list<string>,params_extra:list<string>}
 */
function orange_system_i18n_apply_params(string $text, array $params): array
{
    $expected = [];
    if (preg_match_all(ORANGE_I18N_PLACEHOLDER_REGEX, $text, $m)) {
        foreach ($m[1] as $name) {
            $expected[$name] = true;
        }
    }
    if (str_contains($text, '{') || str_contains($text, '}')) {
        $reconstruct = preg_replace(ORANGE_I18N_PLACEHOLDER_REGEX, '', $text);
        if (is_string($reconstruct) && (str_contains($reconstruct, '{') || str_contains($reconstruct, '}'))) {
            return [
                'text' => '',
                'failure_code' => 'I18N_E_PLACEHOLDER_GRAMMAR',
                'params_expected' => array_keys($expected),
                'params_used' => [],
                'params_missing' => [],
                'params_extra' => [],
            ];
        }
    }
    $expectedList = array_keys($expected);
    sort($expectedList, SORT_STRING);

    $used = [];
    $missing = [];
    $extra = [];
    foreach ($params as $pk => $pv) {
        if (!is_string($pk)) {
            return [
                'text' => '',
                'failure_code' => 'I18N_E_PARAM_TYPE',
                'params_expected' => $expectedList,
                'params_used' => [],
                'params_missing' => [],
                'params_extra' => [],
            ];
        }
        if (!(is_string($pv) || is_int($pv) || is_float($pv))) {
            return [
                'text' => '',
                'failure_code' => 'I18N_E_PARAM_TYPE',
                'params_expected' => $expectedList,
                'params_used' => [],
                'params_missing' => [],
                'params_extra' => [],
            ];
        }
        if (!isset($expected[$pk])) {
            $extra[] = $pk;
        } else {
            $used[$pk] = $pv;
        }
    }
    foreach ($expectedList as $name) {
        if (!array_key_exists($name, $params)) {
            $missing[] = $name;
        }
    }
    sort($extra, SORT_STRING);
    sort($missing, SORT_STRING);
    if ($missing !== []) {
        return [
            'text' => '',
            'failure_code' => 'I18N_E_PARAM_MISSING',
            'params_expected' => $expectedList,
            'params_used' => $used,
            'params_missing' => $missing,
            'params_extra' => $extra,
        ];
    }
    if ($extra !== []) {
        return [
            'text' => '',
            'failure_code' => 'I18N_E_PARAM_EXTRA',
            'params_expected' => $expectedList,
            'params_used' => $used,
            'params_missing' => $missing,
            'params_extra' => $extra,
        ];
    }
    $out = $text;
    foreach ($used as $name => $val) {
        $out = str_replace('{' . $name . '}', (string)$val, $out);
    }
    return [
        'text' => $out,
        'failure_code' => null,
        'params_expected' => $expectedList,
        'params_used' => $used,
        'params_missing' => [],
        'params_extra' => [],
    ];
}

/**
 * Reject NEW canonical PLAIN values with forbidden controls (B4).
 */
function orange_system_i18n_reject_new_plain_value(string $value): ?string
{
    if (!mb_check_encoding($value, 'UTF-8')) {
        return 'I18N_E_ENCODING';
    }
    if (str_contains($value, "\xEF\xBB\xBF") || str_contains($value, "\xFE\xFF") || str_contains($value, "\xFF\xFE")) {
        return 'I18N_E_ENCODING';
    }
    if (preg_match('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
        return 'I18N_E_CONTROL_CHAR';
    }
    if (preg_match('/[\x{0080}-\x{009F}]/u', $value)) {
        return 'I18N_E_CONTROL_CHAR';
    }
    if (preg_match('/[\x{061C}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', $value)) {
        return 'I18N_E_BIDI_ZW';
    }
    if (preg_match('/<[a-zA-Z\/!]/', $value)) {
        return 'I18N_E_HTML_IN_CANONICAL';
    }
    return null;
}

function orange_system_i18n_memo_key(int $catalogRevision, string $aggregateHash, string $locale, string $package): string
{
    return 'orange_i18n_v' . $catalogRevision . '_' . $aggregateHash . '_' . $locale . '_' . $package;
}
