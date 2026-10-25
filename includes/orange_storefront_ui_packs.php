<?php
declare(strict_types=1);

/**
 * Extra storefront packs merged into get_translations().
 * Activation is the manifest, not a directory glob.
 * A file sitting in the pack directory and absent from the manifest is not loaded.
 * The fingerprint is SHA-256 of the LF-normalized file bytes: the payload Git
 * stores when core.autocrlf cleans CRLF to LF. Raw CRLF and LF of the same
 * text share one fingerprint. This file does not change core.autocrlf.
 * Readiness lists a code only after its fingerprint matches and its body covers
 * the current English contract. The acceptance seal covers the live contract,
 * each manifest row hash, and the manifest's declared contract_sha256 and
 * required_count. A cache hit is returned only after the current manifest
 * contract matches. The warm path does not re-read every pack body.
 * get_translations() loads the active extra language only.
 */

function orange_storefront_ui_pack_directory(): string
{
    return __DIR__ . '/storefront_ui_packs';
}

function orange_storefront_ui_pack_manifest_path(): string
{
    if (defined('ORANGE_UI_PACK_MANIFEST_PATH')) {
        $path = (string) constant('ORANGE_UI_PACK_MANIFEST_PATH');
        if ($path !== '' && is_file($path)) {
            return $path;
        }
    }

    return __DIR__ . '/storefront_ui_pack_manifest.php';
}

function orange_storefront_ui_lf_normalize(string $raw): string
{
    return str_replace("\r\n", "\n", $raw);
}

function orange_storefront_ui_content_sha256(string $raw): string
{
    return hash('sha256', orange_storefront_ui_lf_normalize($raw));
}

/**
 * @param list<string> $keys
 */
function orange_storefront_ui_contract_sha256(array $keys): string
{
    $names = [];
    foreach ($keys as $key) {
        if (is_string($key) && $key !== '') {
            $names[] = $key;
        }
    }
    sort($names, SORT_STRING);

    return hash('sha256', implode("\n", $names));
}

/**
 * @return array{memo:?array,stamp:?string,files:array<string,array>}
 */
function &orange_storefront_ui_runtime_slot(): array
{
    static $slot = [
        'memo' => null,
        'stamp' => null,
        'files' => [],
        'read_bodies' => 0,
        'load_bodies' => 0,
    ];

    return $slot;
}

function orange_storefront_ui_reset_pack_runtime(): void
{
    $slot = &orange_storefront_ui_runtime_slot();
    $slot['memo'] = null;
    $slot['stamp'] = null;
    $slot['files'] = [];
    $slot['read_bodies'] = 0;
    $slot['load_bodies'] = 0;
}

function orange_storefront_ui_note_io(string $kind): void
{
    $slot = &orange_storefront_ui_runtime_slot();
    if ($kind === 'read') {
        $slot['read_bodies']++;
        return;
    }
    if ($kind === 'load') {
        $slot['load_bodies']++;
    }
}

/**
 * @return array{read_bodies:int,load_bodies:int}
 */
function orange_storefront_ui_io_counts(): array
{
    $slot = orange_storefront_ui_runtime_slot();

    return [
        'read_bodies' => (int) ($slot['read_bodies'] ?? 0),
        'load_bodies' => (int) ($slot['load_bodies'] ?? 0),
    ];
}

/**
 * Live contract, declared manifest contract, required count, and row hashes.
 * Size and mtime are not this seal.
 *
 * @return array{contract:string,declared_contract:string,required_count:int,rows:array<string,string>}
 */
function orange_storefront_ui_acceptance_seal(array $manifest): array
{
    $keys = function_exists('orange_storefront_ui_required_keys')
        ? orange_storefront_ui_required_keys()
        : [];
    $rows = [];
    $packs = is_array($manifest['packs'] ?? null) ? $manifest['packs'] : [];
    ksort($packs);
    foreach ($packs as $code => $row) {
        if (!is_string($code) || !is_array($row)) {
            continue;
        }
        $rows[$code] = strtolower((string) ($row['sha256'] ?? ''));
    }

    return [
        'contract' => orange_storefront_ui_contract_sha256($keys),
        'declared_contract' => strtolower((string) ($manifest['contract_sha256'] ?? '')),
        'required_count' => (int) ($manifest['required_count'] ?? 0),
        'rows' => $rows,
    ];
}

/**
 * @param mixed $stored
 * @param array{contract:string,declared_contract:string,required_count:int,rows:array<string,string>} $live
 */
function orange_storefront_ui_seal_matches($stored, array $live): bool
{
    if (!is_array($stored)) {
        return false;
    }
    $contract = strtolower((string) ($stored['contract'] ?? ''));
    if ($contract === '' || !hash_equals($live['contract'], $contract)) {
        return false;
    }
    $declared = strtolower((string) ($stored['declared_contract'] ?? ''));
    $liveDeclared = strtolower((string) ($live['declared_contract'] ?? ''));
    if ($declared === '' || $liveDeclared === '' || !hash_equals($liveDeclared, $declared)) {
        return false;
    }
    if ((int) ($stored['required_count'] ?? -1) !== (int) ($live['required_count'] ?? -2)) {
        return false;
    }
    $rows = $stored['rows'] ?? null;
    if (!is_array($rows) || count($rows) !== count($live['rows'])) {
        return false;
    }
    foreach ($live['rows'] as $code => $hash) {
        $got = strtolower((string) ($rows[$code] ?? ''));
        if ($got === '' || !hash_equals($hash, $got)) {
            return false;
        }
    }

    return true;
}

/**
 * @return array{fingerprint:string,contract_sha256:string,required_count:int,packs:array<string,array<string,mixed>>}
 */
function orange_storefront_ui_read_manifest(): array
{
    $empty = [
        'fingerprint' => 'lf-sha256',
        'contract_sha256' => '',
        'required_count' => 0,
        'packs' => [],
    ];
    $path = orange_storefront_ui_pack_manifest_path();
    if (!is_file($path)) {
        return $empty;
    }
    $stamp = (string) @filesize($path) . ':' . (string) @filemtime($path);
    $slot = &orange_storefront_ui_runtime_slot();
    if (isset($slot['files'][$path]['stamp']) && $slot['files'][$path]['stamp'] === $stamp && is_array($slot['files'][$path]['data'])) {
        return $slot['files'][$path]['data'];
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    $data = require $path;
    if (!is_array($data)) {
        $slot['files'][$path] = ['stamp' => $stamp, 'data' => $empty];

        return $empty;
    }
    $slot['files'][$path] = ['stamp' => $stamp, 'data' => $data];

    return $data;
}

function orange_storefront_ui_manifest_contract_ok(array $manifest): bool
{
    $expect = strtolower((string) ($manifest['contract_sha256'] ?? ''));
    $count = (int) ($manifest['required_count'] ?? 0);
    if (!preg_match('/^[a-f0-9]{64}$/', $expect) || $count < 1) {
        return false;
    }
    if (!function_exists('orange_storefront_ui_required_keys')) {
        return false;
    }
    $keys = orange_storefront_ui_required_keys();
    if (count($keys) !== $count) {
        return false;
    }

    return hash_equals($expect, orange_storefront_ui_contract_sha256($keys));
}

function orange_storefront_ui_pack_abs_path(array $row): ?string
{
    $rel = str_replace('\\', '/', (string) ($row['file'] ?? ''));
    if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
        return null;
    }
    $base = dirname(orange_storefront_ui_pack_manifest_path());
    $abs = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

    return is_file($abs) ? $abs : null;
}

function orange_storefront_ui_manifest_stamp(array $manifest): string
{
    $parts = [orange_storefront_ui_pack_manifest_path()];
    $manifestFile = orange_storefront_ui_pack_manifest_path();
    $parts[] = is_file($manifestFile) ? (string) filesize($manifestFile) : '0';
    $parts[] = is_file($manifestFile) ? (string) filemtime($manifestFile) : '0';
    $parts[] = json_encode(orange_storefront_ui_acceptance_seal($manifest), JSON_UNESCAPED_UNICODE);
    $packs = is_array($manifest['packs'] ?? null) ? $manifest['packs'] : [];
    ksort($packs);
    foreach ($packs as $code => $row) {
        if (!is_string($code) || !is_array($row)) {
            continue;
        }
        $abs = orange_storefront_ui_pack_abs_path($row);
        $parts[] = $code;
        $parts[] = $abs ?? 'missing';
        $parts[] = $abs !== null ? (string) filesize($abs) : '0';
        $parts[] = $abs !== null ? (string) filemtime($abs) : '0';
    }

    return hash('sha256', implode('|', $parts));
}

function orange_storefront_ui_audit_cache_path(string $stamp): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_ui_pack_audit_' . $stamp . '.json';
}

function orange_storefront_ui_pack_fingerprint_ok(array $row): bool
{
    $expect = strtolower((string) ($row['sha256'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $expect)) {
        return false;
    }
    $abs = orange_storefront_ui_pack_abs_path($row);
    if ($abs === null) {
        return false;
    }
    $raw = file_get_contents($abs);
    if (!is_string($raw)) {
        return false;
    }
    orange_storefront_ui_note_io('read');

    return hash_equals($expect, orange_storefront_ui_content_sha256($raw));
}

/**
 * @return list<string>
 */
function orange_storefront_ui_audit_manifest(array $manifest): array
{
    if (!orange_storefront_ui_manifest_contract_ok($manifest)) {
        return [];
    }
    $ready = [];
    $packs = is_array($manifest['packs'] ?? null) ? $manifest['packs'] : [];
    foreach ($packs as $code => $row) {
        if (!is_string($code) || !preg_match('/^[a-z]{2,3}$/', $code) || !is_array($row)) {
            continue;
        }
        if (orange_storefront_ui_pack_fingerprint_ok($row)) {
            $abs = orange_storefront_ui_pack_abs_path($row);
            if ($abs !== null && orange_storefront_ui_require_covered_pack($abs) !== null) {
                $ready[] = $code;
            }
        }
    }
    sort($ready, SORT_STRING);

    return $ready;
}

/**
 * @return list<string>
 */
function orange_storefront_ui_manifest_ready_codes(): array
{
    $manifest = orange_storefront_ui_read_manifest();
    $now = orange_storefront_ui_manifest_stamp($manifest);
    $slot = &orange_storefront_ui_runtime_slot();
    if (!orange_storefront_ui_manifest_contract_ok($manifest)) {
        $slot['memo'] = [];
        $slot['stamp'] = $now;

        return [];
    }
    if (is_array($slot['memo']) && $slot['stamp'] === $now) {
        return $slot['memo'];
    }
    $seal = orange_storefront_ui_acceptance_seal($manifest);
    $skip = defined('ORANGE_UI_PACK_SKIP_AUDIT_CACHE') && constant('ORANGE_UI_PACK_SKIP_AUDIT_CACHE');
    $cachePath = orange_storefront_ui_audit_cache_path($now);
    if (!$skip && is_file($cachePath)) {
        $json = json_decode((string) file_get_contents($cachePath), true);
        if (is_array($json) && orange_storefront_ui_seal_matches($json['seal'] ?? null, $seal) && isset($json['codes']) && is_array($json['codes'])) {
            $codes = [];
            foreach ($json['codes'] as $code) {
                if (is_string($code) && preg_match('/^[a-z]{2,3}$/', $code)) {
                    $codes[] = $code;
                }
            }
            $slot['memo'] = $codes;
            $slot['stamp'] = $now;

            return $codes;
        }
    }
    $codes = orange_storefront_ui_audit_manifest($manifest);
    $slot['memo'] = $codes;
    $slot['stamp'] = $now;
    if (!$skip) {
        @file_put_contents($cachePath, json_encode(['codes' => $codes, 'seal' => $seal], JSON_UNESCAPED_UNICODE));
    }

    return $codes;
}

/**
 * @return array<string,mixed>|null
 */
function orange_storefront_ui_verified_pack(string $code): ?array
{
    $code = strtolower(trim($code));
    if (!preg_match('/^[a-z]{2,3}$/', $code)) {
        return null;
    }
    $manifest = orange_storefront_ui_read_manifest();
    if (!orange_storefront_ui_manifest_contract_ok($manifest)) {
        return null;
    }
    $row = $manifest['packs'][$code] ?? null;
    if (!is_array($row) || !orange_storefront_ui_pack_fingerprint_ok($row)) {
        return null;
    }
    $abs = orange_storefront_ui_pack_abs_path($row);
    if ($abs === null) {
        return null;
    }
    if (!function_exists('orange_storefront_ui_pack_covers')) {
        $readyFile = __DIR__ . '/orange_storefront_ui_readiness.php';
        if (is_file($readyFile)) {
            require_once $readyFile;
        }
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($abs, true);
    }
    return orange_storefront_ui_require_covered_pack($abs);
}

/**
 * @return array<string,mixed>|null
 */
function orange_storefront_ui_require_covered_pack(string $abs): ?array
{
    orange_storefront_ui_note_io('load');
    $pack = require $abs;
    if (!is_array($pack) || !function_exists('orange_storefront_ui_pack_covers') || !function_exists('orange_storefront_ui_required_keys')) {
        return null;
    }
    if (!orange_storefront_ui_pack_covers($pack, orange_storefront_ui_required_keys())) {
        return null;
    }

    return $pack;
}

function orange_storefront_ui_active_extra_locale(): ?string
{
    if (!function_exists('current_lang')) {
        return null;
    }
    static $guard = 0;
    if ($guard > 0) {
        return null;
    }
    $guard++;
    try {
        $lang = strtolower(trim((string) current_lang()));
    } finally {
        $guard--;
    }
    if (!preg_match('/^[a-z]{2,3}$/', $lang)) {
        return null;
    }
    if (isset(['ar' => true, 'en' => true, 'fil' => true, 'hi' => true][$lang])) {
        return null;
    }

    return $lang;
}

/**
 * @return array<string,array<string,mixed>>
 */
function orange_storefront_ui_load_pack_files(): array
{
    $out = [];
    foreach (orange_storefront_ui_manifest_ready_codes() as $code) {
        $pack = orange_storefront_ui_verified_pack($code);
        if (is_array($pack)) {
            $out[$code] = $pack;
        }
    }

    return $out;
}

/**
 * @param array<string,array<string,mixed>> $builtin
 * @return array<string,array<string,mixed>>
 */
function orange_storefront_ui_merge_packs(array $builtin): array
{
    $code = orange_storefront_ui_active_extra_locale();
    if ($code !== null && !isset($builtin[$code])) {
        $pack = orange_storefront_ui_verified_pack($code);
        if (is_array($pack)) {
            $builtin[$code] = $pack;
        }
    }

    return $builtin;
}
