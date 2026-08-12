<?php

declare(strict_types=1);

/**
 * Pinned private shadow-engine runtime manifest (Restore Step 7 supply chain).
 *
 * Registers:
 *   AUTOMATED_RUNTIME_SUPPLY_REQUIRED_01
 *   PRIVATE_RUNTIME_MANIFEST_COUNT
 *
 * One authoritative pinned portable runtime per OS/arch. SHA-256 pinned in source.
 * No floating unpinned download URLs. No browser-controlled URL.
 */

const ORANGE_RESTORE_PRIVATE_RUNTIME_MANIFEST_ID = 'orange-restore-private-db-runtime-v1';
const ORANGE_RESTORE_PRIVATE_RUNTIME_VENDOR = 'mariadb';
const ORANGE_RESTORE_PRIVATE_RUNTIME_PRODUCT = 'MariaDB Server';
const ORANGE_RESTORE_PRIVATE_RUNTIME_VERSION = '11.4.5';
const ORANGE_RESTORE_PRIVATE_RUNTIME_LICENSE = 'GPL-2.0-only';

/** Official HTTPS hosts allowed for first-use materialization redirects. */
const ORANGE_RESTORE_PRIVATE_RUNTIME_ALLOWED_HOSTS = [
    'archive.mariadb.org',
    'dlm.mariadb.com',
    'downloads.mariadb.com',
    'mirror.mariadb.org',
    'cdn.mysql.com',
    'dev.mysql.com',
    'downloads.mysql.com',
];

/**
 * Pinned manifests keyed by os/arch. SHA-256 from MariaDB official checksum publication.
 *
 * @return array<string, array<string, mixed>>
 */
function orange_restore_private_engine_runtime_manifest_catalog(): array
{
    // Test override: full catalog replacement (disposable suites only).
    if (isset($GLOBALS['orange_restore_private_runtime_manifest_override'])
        && is_array($GLOBALS['orange_restore_private_runtime_manifest_override'])) {
        return $GLOBALS['orange_restore_private_runtime_manifest_override'];
    }

    return [
        'windows-x86_64' => [
            'manifest_id' => ORANGE_RESTORE_PRIVATE_RUNTIME_MANIFEST_ID,
            'vendor' => ORANGE_RESTORE_PRIVATE_RUNTIME_VENDOR,
            'product' => ORANGE_RESTORE_PRIVATE_RUNTIME_PRODUCT,
            'version' => ORANGE_RESTORE_PRIVATE_RUNTIME_VERSION,
            'family' => 'mariadb',
            'os' => 'windows',
            'arch' => 'x86_64',
            'archive_name' => 'mariadb-11.4.5-winx64.zip',
            'archive_format' => 'zip',
            'official_https_url' => 'https://archive.mariadb.org/mariadb-11.4.5/winx64-packages/mariadb-11.4.5-winx64.zip',
            'archive_sha256' => 'b7c11d38657f16b837e68199d73670510aadb78f42dfa5d5fdea31a7aab342e3',
            'archive_size_min' => 40_000_000,
            'archive_size_max' => 200_000_000,
            'license' => ORANGE_RESTORE_PRIVATE_RUNTIME_LICENSE,
            'compatibility' => 'mysql8_compatible_mariadb_lts',
            'daemon_relpaths' => [
                'bin/mysqld.exe',
                'bin/mariadbd.exe',
            ],
            'client_relpaths' => [
                'bin/mysql.exe',
                'bin/mariadb.exe',
            ],
            'file_allowlist_prefixes' => [
                'bin/',
                'lib/',
                'share/',
                'include/',
                'LICENSE',
                'README',
                'COPYING',
                'THIRDPARTY',
            ],
            'extracted_root_dirname_prefix' => 'mariadb-11.4.5-winx64',
        ],
        'linux-x86_64' => [
            'manifest_id' => ORANGE_RESTORE_PRIVATE_RUNTIME_MANIFEST_ID,
            'vendor' => ORANGE_RESTORE_PRIVATE_RUNTIME_VENDOR,
            'product' => ORANGE_RESTORE_PRIVATE_RUNTIME_PRODUCT,
            'version' => ORANGE_RESTORE_PRIVATE_RUNTIME_VERSION,
            'family' => 'mariadb',
            'os' => 'linux',
            'arch' => 'x86_64',
            'archive_name' => 'mariadb-11.4.5-linux-systemd-x86_64.tar.gz',
            'archive_format' => 'tar.gz',
            'official_https_url' => 'https://archive.mariadb.org/mariadb-11.4.5/bintar-linux-systemd-x86_64/mariadb-11.4.5-linux-systemd-x86_64.tar.gz',
            'archive_sha256' => '2a44cb70a87dba7eb2cab3b5af2c0416a0204d93a8fda387b4b70c9f1bab7bd6',
            'archive_size_min' => 80_000_000,
            'archive_size_max' => 400_000_000,
            'license' => ORANGE_RESTORE_PRIVATE_RUNTIME_LICENSE,
            'compatibility' => 'mysql8_compatible_mariadb_lts',
            'daemon_relpaths' => [
                'bin/mysqld',
                'bin/mariadbd',
            ],
            'client_relpaths' => [
                'bin/mysql',
                'bin/mariadb',
            ],
            'file_allowlist_prefixes' => [
                'bin/',
                'lib/',
                'share/',
                'include/',
                'scripts/',
                'support-files/',
                'LICENSE',
                'README',
                'COPYING',
            ],
            'extracted_root_dirname_prefix' => 'mariadb-11.4.5-linux-systemd-x86_64',
        ],
    ];
}

/**
 * @return array{os:string,arch:string,key:string}
 */
function orange_restore_private_engine_runtime_platform(): array
{
    $os = PHP_OS_FAMILY === 'Windows' ? 'windows' : 'linux';
    $arch = strtolower((string) (php_uname('m') ?: ''));
    if (in_array($arch, ['amd64', 'x64', 'x86_64', 'x86-64'], true)) {
        $arch = 'x86_64';
    } elseif (in_array($arch, ['aarch64', 'arm64'], true)) {
        $arch = 'aarch64';
    } else {
        $arch = $arch !== '' ? $arch : 'unknown';
    }

    return [
        'os' => $os,
        'arch' => $arch,
        'key' => $os . '-' . $arch,
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_private_engine_runtime_manifest_for_platform(): ?array
{
    $platform = orange_restore_private_engine_runtime_platform();
    $catalog = orange_restore_private_engine_runtime_manifest_catalog();
    $row = $catalog[$platform['key']] ?? null;

    return is_array($row) ? $row : null;
}

/**
 * Public-safe manifest summary (no URL/path).
 *
 * @return array<string, mixed>
 */
function orange_restore_private_engine_runtime_manifest_public_summary(?array $manifest = null): array
{
    $m = $manifest ?? orange_restore_private_engine_runtime_manifest_for_platform();
    if (!is_array($m)) {
        return [
            'available' => false,
            'vendor' => '',
            'product' => '',
            'version' => '',
            'family' => '',
            'compatibility' => '',
            'license' => '',
            'sha256_pinned' => false,
        ];
    }

    return [
        'available' => true,
        'vendor' => (string) ($m['vendor'] ?? ''),
        'product' => (string) ($m['product'] ?? ''),
        'version' => (string) ($m['version'] ?? ''),
        'family' => (string) ($m['family'] ?? ''),
        'compatibility' => (string) ($m['compatibility'] ?? ''),
        'license' => (string) ($m['license'] ?? ''),
        'sha256_pinned' => preg_match('/^[a-f0-9]{64}$/', strtolower((string) ($m['archive_sha256'] ?? ''))) === 1,
        'os' => (string) ($m['os'] ?? ''),
        'arch' => (string) ($m['arch'] ?? ''),
    ];
}

function orange_restore_private_engine_runtime_host_allowed(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return false;
    }
    foreach (ORANGE_RESTORE_PRIVATE_RUNTIME_ALLOWED_HOSTS as $allowed) {
        if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
            return true;
        }
    }

    return false;
}
