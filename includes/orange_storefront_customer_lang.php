<?php
declare(strict_types=1);

/**
 * Visitor language list for the storefront country.
 * Does not load config.php and does not create role rows.
 *
 * legacy_unconfigured keeps the four UI-ready languages only after a successful
 * role read that finds no rows for the storefront country.
 * A read error, a missing resolver, or an unresolved storefront country does not
 * use that list. Those states expose no customer language and no database text.
 * configured offers the customer role intersected with UI-ready languages.
 * invalid_multiple_base, no_base, and configured with no qualified language offer nothing.
 * The internal render language is for t() only. It is not a customer choice.
 */

require_once __DIR__ . '/orange_content_languages.php';
require_once __DIR__ . '/orange_storefront_ui_readiness.php';
require_once __DIR__ . '/orange_language_catalog_data.php';

/**
 * Live interface packs. This is not the language catalog.
 * A catalog language stays out of the customer list until its pack is complete.
 * Labels and direction come from the reference row when one is supplied.
 * The four historical labels are only the fallback for those codes.
 *
 * @param array<string,array{native?:string,label?:string,dir?:string}> $catalogEntries
 * @return array<string, array{label:string,dir:string}>
 */
function orange_storefront_customer_ui_ready(array $catalogEntries = []): array
{
    $fallback = [
        'ar' => 'العربية',
        'en' => 'English',
        'fil' => 'Filipino',
        'hi' => 'हिन्दी',
    ];
    $out = [];
    foreach (orange_storefront_ui_live_ready_locales() as $code) {
        if (!orange_language_catalog_code_operational($code)) {
            continue;
        }
        $ref = $catalogEntries[$code] ?? null;
        $native = is_array($ref) ? trim((string) ($ref['native'] ?? '')) : '';
        $catalogDir = is_array($ref) ? (string) ($ref['dir'] ?? '') : null;
        $out[$code] = [
            'label' => $native !== '' ? $native : ($fallback[$code] ?? $code),
            'dir' => orange_storefront_ui_direction($code, $catalogDir !== '' ? $catalogDir : null),
        ];
    }

    return $out;
}

function orange_storefront_customer_switcher_should_render(array $resolution): bool
{
    return count($resolution['codes'] ?? []) >= 2;
}

/**
 * @return array{
 *   mode: string,
 *   customer_wired: bool,
 *   options: array<string, array{label: string}>,
 *   codes: list<string>,
 *   default_lang: string,
 *   render_lang: string,
 *   base_lang: string,
 *   reason: string,
 *   excluded_unready: list<string>,
 *   country_id: int
 * }
 */
function orange_storefront_customer_lang_legacy_resolution(int $countryId = 0): array
{
    $historical = ['ar', 'en', 'fil', 'hi'];
    $ready = orange_storefront_customer_ui_ready();
    $options = [];
    foreach ($historical as $code) {
        if (!isset($ready[$code])) {
            continue;
        }
        $options[$code] = $ready[$code];
    }

    return [
        'mode' => 'legacy_unconfigured',
        'customer_wired' => false,
        'options' => $options,
        'codes' => array_keys($options),
        'default_lang' => 'en',
        'render_lang' => 'en',
        'base_lang' => '',
        'reason' => 'legacy_unconfigured',
        'excluded_unready' => [],
        'country_id' => $countryId,
    ];
}

/**
 * Closed customer list. Not legacy_unconfigured and not a database message.
 *
 * @return array{
 *   mode: string,
 *   customer_wired: bool,
 *   options: array<string, array{label: string}>,
 *   codes: list<string>,
 *   default_lang: string,
 *   render_lang: string,
 *   base_lang: string,
 *   reason: string,
 *   excluded_unready: list<string>,
 *   country_id: int
 * }
 */
function orange_storefront_customer_lang_closed_resolution(string $mode, int $countryId): array
{
    return [
        'mode' => $mode,
        'customer_wired' => true,
        'options' => [],
        'codes' => [],
        'default_lang' => '',
        'render_lang' => 'ar',
        'base_lang' => '',
        'reason' => $mode,
        'excluded_unready' => [],
        'country_id' => $countryId,
    ];
}

function orange_storefront_customer_lang_ensure_country_resolver(): bool
{
    static $loading = false;
    if (function_exists('orange_storefront_current_country_id')) {
        return true;
    }
    if ($loading) {
        return false;
    }
    $loading = true;
    try {
        $path = __DIR__ . '/countries.php';
        if (is_file($path)) {
            require_once $path;
        }
    } finally {
        $loading = false;
    }

    return function_exists('orange_storefront_current_country_id');
}

/**
 * @return array{
 *   mode: string,
 *   customer_wired: bool,
 *   options: array<string, array{label: string}>,
 *   codes: list<string>,
 *   default_lang: string,
 *   render_lang: string,
 *   base_lang: string,
 *   reason: string,
 *   excluded_unready: list<string>,
 *   country_id: int
 * }
 */
function orange_storefront_customer_lang_resolution(PDO $pdo, int $countryId): array
{
    if ($countryId <= 0) {
        return orange_storefront_customer_lang_closed_resolution('country_unresolved', 0);
    }
    try {
        $roles = orange_country_locale_roles_read($pdo, $countryId);
    } catch (Throwable $e) {
        unset($e);

        return orange_storefront_customer_lang_closed_resolution('read_failed', $countryId);
    }
    $mode = (string) ($roles['mode'] ?? 'legacy_unconfigured');
    if ($mode === 'legacy_unconfigured') {
        return orange_storefront_customer_lang_legacy_resolution($countryId);
    }

    $catalogEntries = [];
    $knownWithoutCatalogRead = ['ar' => true, 'en' => true, 'fil' => true, 'hi' => true];
    $needsCatalog = false;
    if ($mode === 'configured') {
        foreach ($roles['customer'] ?? [] as $rawCode) {
            $probe = strtolower(trim((string) $rawCode));
            if ($probe !== '' && !isset($knownWithoutCatalogRead[$probe])) {
                $needsCatalog = true;
                break;
            }
        }
    }
    if ($needsCatalog) {
        foreach (orange_language_catalog($pdo)['entries'] as $entry) {
            if (is_array($entry) && isset($entry['code'])) {
                $catalogEntries[(string) $entry['code']] = $entry;
            }
        }
    }
    $ready = orange_storefront_customer_ui_ready($catalogEntries);
    $base = strtolower(trim((string) ($roles['base'] ?? '')));
    $options = [];
    $excluded = [];
    if ($mode === 'configured') {
        foreach ($roles['customer'] ?? [] as $rawCode) {
            $code = strtolower(trim((string) $rawCode));
            if ($code === '' || isset($options[$code]) || in_array($code, $excluded, true)) {
                continue;
            }
            if (!isset($ready[$code])) {
                $excluded[] = $code;
                continue;
            }
            $options[$code] = $ready[$code];
        }
    }
    $codes = array_keys($options);
    $default = '';
    if ($codes !== []) {
        $default = ($base !== '' && isset($options[$base])) ? $base : $codes[0];
    }
    $reason = $mode;
    if ($mode === 'configured' && $codes === []) {
        $reason = 'configured_no_qualified';
    }

    return [
        'mode' => $mode,
        'customer_wired' => true,
        'options' => $options,
        'codes' => $codes,
        'default_lang' => $default,
        'render_lang' => $default !== '' ? $default : 'ar',
        'base_lang' => $base,
        'reason' => $reason,
        'excluded_unready' => $excluded,
        'country_id' => $countryId,
    ];
}

/**
 * @return array{
 *   mode: string,
 *   customer_wired: bool,
 *   options: array<string, array{label: string}>,
 *   codes: list<string>,
 *   default_lang: string,
 *   render_lang: string,
 *   base_lang: string,
 *   reason: string,
 *   excluded_unready: list<string>,
 *   country_id: int
 * }
 */
function orange_storefront_customer_lang_for_current_request(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $resolverReady = orange_storefront_customer_lang_ensure_country_resolver();
    if (!$resolverReady || !function_exists('db')) {
        return orange_storefront_customer_lang_closed_resolution('resolution_unavailable', 0);
    }
    try {
        $pdo = db();
        $countryId = orange_storefront_current_country_id($pdo);
        $memo = orange_storefront_customer_lang_resolution($pdo, $countryId);
    } catch (Throwable $e) {
        unset($e);
        $memo = orange_storefront_customer_lang_closed_resolution('read_failed', 0);
    }

    return $memo;
}

function orange_storefront_legacy_request_lang(): string
{
    $allowed = ['en', 'ar', 'fil', 'hi'];
    if (isset($_GET['lang'])) {
        $raw = strtolower(trim((string) $_GET['lang']));
        if ($raw !== '' && in_array($raw, $allowed, true)) {
            return $raw;
        }
        if ($raw !== '') {
            return 'en';
        }
    }
    if (function_exists('orange_is_admin_request_context') && orange_is_admin_request_context()) {
        return 'en';
    }
    if (function_exists('orange_storefront_lang_from_short_url_path')) {
        $fromShort = orange_storefront_lang_from_short_url_path();
        if ($fromShort !== null && in_array($fromShort, $allowed, true)) {
            return $fromShort;
        }
    }
    if (function_exists('orange_storefront_read_saved_lang')) {
        $fromCookie = orange_storefront_read_saved_lang();
        if ($fromCookie !== null && in_array($fromCookie, $allowed, true)) {
            return $fromCookie;
        }
    }
    if (function_exists('orange_lang_from_accept_language_header')) {
        return orange_lang_from_accept_language_header($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null);
    }

    return 'en';
}

/**
 * Accept-Language may select only an offered customer language.
 * An unsupported tag does not become English.
 */
function orange_storefront_accept_language_offered(?string $header, array $codes): ?string
{
    if ($header === null || trim($header) === '' || $codes === []) {
        return null;
    }
    $allowed = [];
    foreach ($codes as $code) {
        $allowed[strtolower(trim((string) $code))] = true;
    }
    $parsed = [];
    foreach (preg_split('/\s*,\s*/', $header) ?: [] as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $q = 1.0;
        if (preg_match('/;\s*q\s*=\s*([0-9.]+)/i', $part, $qm)) {
            $q = (float) $qm[1];
        }
        if ($q <= 0) {
            continue;
        }
        $tag = strtolower(trim((string) preg_replace('/;\s*q\s*=\s*[0-9.]+/i', '', $part)));
        if ($tag === '' || $tag === '*') {
            continue;
        }
        $parsed[] = ['tag' => str_replace('_', '-', $tag), 'q' => $q];
    }
    usort($parsed, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);
    foreach ($parsed as $row) {
        $primary = explode('-', $row['tag'])[0];
        $candidates = [$primary];
        if ($primary === 'tl') {
            $candidates[] = 'fil';
        } elseif ($primary === 'fil') {
            $candidates[] = 'tl';
        }
        foreach ($candidates as $mapped) {
            if (isset($allowed[$mapped])) {
                return $mapped;
            }
        }
    }

    return null;
}

/**
 * @param list<string> $candidates
 */
function orange_storefront_pick_customer_lang(array $resolution, array $candidates): string
{
    $codes = $resolution['codes'];
    if ($codes === []) {
        return (string) $resolution['render_lang'];
    }
    $offered = array_fill_keys($codes, true);
    foreach ($candidates as $raw) {
        $code = strtolower(trim((string) $raw));
        if ($code !== '' && isset($offered[$code])) {
            return $code;
        }
    }

    return (string) $resolution['default_lang'];
}

function orange_storefront_customer_lang_candidates(): array
{
    $out = [];
    if (isset($_GET['lang'])) {
        $out[] = strtolower(trim((string) $_GET['lang']));
    }
    if (function_exists('orange_storefront_lang_from_short_url_path')) {
        $short = orange_storefront_lang_from_short_url_path();
        if (is_string($short) && $short !== '') {
            $out[] = strtolower($short);
        }
    }
    if (function_exists('orange_storefront_read_saved_lang')) {
        $cookie = orange_storefront_read_saved_lang();
        if (is_string($cookie) && $cookie !== '') {
            $out[] = strtolower($cookie);
        }
    }

    return $out;
}

function orange_storefront_resolve_request_lang(): string
{
    orange_storefront_customer_lang_ensure_country_resolver();
    static $guard = 0;
    if ($guard > 0) {
        return '';
    }
    $guard++;
    try {
        if (function_exists('orange_is_admin_request_context') && orange_is_admin_request_context()) {
            return orange_storefront_legacy_request_lang();
        }
        $resolution = orange_storefront_customer_lang_for_current_request();
        if (empty($resolution['customer_wired'])) {
            return orange_storefront_legacy_request_lang();
        }
        $candidates = orange_storefront_customer_lang_candidates();
        $accept = orange_storefront_accept_language_offered(
            isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] : null,
            $resolution['codes']
        );
        if ($accept !== null) {
            $candidates[] = $accept;
        }

        return orange_storefront_pick_customer_lang($resolution, $candidates);
    } finally {
        $guard--;
    }
}

function orange_storefront_customer_payload_lang(array $data): ?string
{
    $lang = isset($data['lang']) ? strtolower(trim((string) $data['lang'])) : '';
    if ($lang === '') {
        return null;
    }
    $ready = array_keys(orange_storefront_customer_ui_ready());
    if (function_exists('orange_is_admin_request_context') && orange_is_admin_request_context()) {
        return in_array($lang, $ready, true) ? $lang : null;
    }
    $resolution = orange_storefront_customer_lang_for_current_request();
    if (empty($resolution['customer_wired'])) {
        return in_array($lang, $ready, true) ? $lang : null;
    }

    return in_array($lang, $resolution['codes'], true) ? $lang : null;
}

function orange_storefront_lang_cookie_deferred_for_channel_restore(): bool
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $channel = trim((string) ($_GET['channel'] ?? ''));

    return str_contains($uri, '/pages/') && $channel === '';
}

function orange_storefront_customer_lang_cookie_allowed(string $lang): bool
{
    $lang = strtolower(trim($lang));
    $ready = array_keys(orange_storefront_customer_ui_ready());
    if (function_exists('orange_is_admin_request_context') && orange_is_admin_request_context()) {
        return in_array($lang, $ready, true);
    }
    $resolution = orange_storefront_customer_lang_for_current_request();
    if (empty($resolution['customer_wired'])) {
        return in_array($lang, $ready, true);
    }

    return in_array($lang, $resolution['codes'], true);
}

/**
 * Empty means the language is not a public customer choice.
 * storefront_url then uses the bare channel path and omits lang.
 * That bare path does not select English while customer binding is active
 * unless English itself is an offered customer language.
 */
function orange_storefront_public_url_lang(string $lang): string
{
    $lang = strtolower(trim($lang));
    if (function_exists('orange_is_admin_request_context') && orange_is_admin_request_context()) {
        return $lang;
    }
    $resolution = orange_storefront_customer_lang_for_current_request();
    if (empty($resolution['customer_wired'])) {
        return $lang;
    }

    return in_array($lang, $resolution['codes'], true) ? $lang : '';
}

/**
 * Customer language codes offered for each active channel.
 * One resolution per distinct country. An empty map means the redirect
 * must not invent English.
 *
 * @return array<string, list<string>>
 */
function orange_storefront_customer_lang_codes_by_channel(PDO $pdo): array
{
    try {
        $rows = $pdo->query('SELECT slug, country_id FROM channels WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        unset($e);

        return [];
    }
    $byCountry = [];
    $map = [];
    foreach ($rows as $row) {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        $countryId = (int) ($row['country_id'] ?? 0);
        if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug) || $countryId <= 0) {
            continue;
        }
        if (!isset($byCountry[$countryId])) {
            $resolution = orange_storefront_customer_lang_resolution($pdo, $countryId);
            $codes = [];
            foreach (is_array($resolution['codes'] ?? null) ? $resolution['codes'] : [] as $code) {
                $code = strtolower(trim((string) $code));
                if (preg_match('/^[a-z]{2,8}$/', $code)) {
                    $codes[] = $code;
                }
            }
            $byCountry[$countryId] = $codes;
        }
        $map[$slug] = $byCountry[$countryId];
    }

    return $map;
}
