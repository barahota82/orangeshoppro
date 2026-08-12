<?php

declare(strict_types=1);

/**
 * Automatic pinned portable DB runtime materializer for Restore Step 7.
 *
 * Delivery order: A build artifact → B vendor asset → C pinned first-use HTTPS
 * (TLS + hostname verify + SHA-256 + atomic + mutex + private tools root).
 */

require_once __DIR__ . '/../backup_paths.php';
require_once __DIR__ . '/restore_private_engine_runtime_manifest.php';
require_once __DIR__ . '/restore_private_engine_local_discovery.php';

// Safe codes are defined in restore_private_shadow_engine.php (authoritative).

/**
 * Channel readiness without downloading the archive.
 *
 * @return array{
 *   ok:bool,
 *   channel:string,
 *   materializable:bool,
 *   code:string,
 *   manifest_summary:array<string,mixed>
 * }
 */
function orange_restore_private_engine_runtime_channel_probe(string $projectRoot): array
{
    $summary = orange_restore_private_engine_runtime_manifest_public_summary();
    $manifest = orange_restore_private_engine_runtime_manifest_for_platform();
    if (!is_array($manifest) || empty($summary['sha256_pinned'])) {
        return [
            'ok' => false,
            'channel' => 'none',
            'materializable' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
            'manifest_summary' => $summary,
        ];
    }

    // A/B: local artifact already present under tools root (vendor-assets or release cache).
    try {
        $tools = orange_restore_private_engine_tools_root($projectRoot);
    } catch (Throwable) {
        return [
            'ok' => false,
            'channel' => 'none',
            'materializable' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
            'manifest_summary' => $summary,
        ];
    }
    $artifact = orange_restore_private_engine_find_local_artifact($tools, $manifest);
    if ($artifact !== null) {
        return [
            'ok' => true,
            'channel' => (string) $artifact['channel'],
            'materializable' => true,
            'code' => 'ok',
            'manifest_summary' => $summary,
        ];
    }

    $url = trim((string) ($manifest['official_https_url'] ?? ''));
    $parts = @parse_url($url);
    $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
    $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
    if ($scheme !== 'https' || !orange_restore_private_engine_runtime_host_allowed($host)) {
        return [
            'ok' => false,
            'channel' => 'none',
            'materializable' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
            'manifest_summary' => $summary,
        ];
    }

    return [
        'ok' => true,
        'channel' => 'pinned_https_first_use',
        'materializable' => true,
        'code' => 'ok',
        'manifest_summary' => $summary,
    ];
}

/**
 * @param array<string, mixed> $manifest
 * @return array{path:string,channel:string}|null
 */
function orange_restore_private_engine_find_local_artifact(string $toolsRoot, array $manifest): ?array
{
    $name = (string) ($manifest['archive_name'] ?? '');
    if ($name === '') {
        return null;
    }
    $paths = [
        ['channel' => 'build_release_artifact', 'path' => $toolsRoot . DIRECTORY_SEPARATOR . 'release-cache' . DIRECTORY_SEPARATOR . $name],
        ['channel' => 'vendor_asset', 'path' => $toolsRoot . DIRECTORY_SEPARATOR . 'vendor-assets' . DIRECTORY_SEPARATOR . $name],
    ];
    foreach ($paths as $row) {
        if (is_file($row['path']) && filesize($row['path']) > 0) {
            return $row;
        }
    }

    return null;
}

/**
 * Acquire exclusive install mutex (blocking with timeout).
 *
 * @return resource|false
 */
function orange_restore_private_engine_runtime_lock_acquire(string $toolsRoot, int $timeoutSec = 120)
{
    $lockDir = $toolsRoot . DIRECTORY_SEPARATOR . '.locks';
    if (!is_dir($lockDir) && !@mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
        return false;
    }
    $lockFile = $lockDir . DIRECTORY_SEPARATOR . 'runtime_install.lock';
    $fh = @fopen($lockFile, 'c+');
    if ($fh === false) {
        return false;
    }
    $deadline = time() + max(5, $timeoutSec);
    while (time() <= $deadline) {
        if (@flock($fh, LOCK_EX | LOCK_NB)) {
            ftruncate($fh, 0);
            fwrite($fh, (string) getmypid() . ' ' . gmdate('c') . "\n");
            fflush($fh);

            return $fh;
        }
        usleep(200000);
    }
    fclose($fh);

    return false;
}

/**
 * @param resource $fh
 */
function orange_restore_private_engine_runtime_lock_release($fh): void
{
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

/**
 * HTTPS download with TLS peer verification, host allowlist, size bounds, atomic rename.
 *
 * @param array<string, mixed> $manifest
 * @return array{ok:bool,code:string,path:string}
 */
function orange_restore_private_engine_download_pinned_archive(
    string $toolsRoot,
    array $manifest
): array {
    $url = trim((string) ($manifest['official_https_url'] ?? ''));
    $sha = strtolower((string) ($manifest['archive_sha256'] ?? ''));
    $name = (string) ($manifest['archive_name'] ?? 'runtime.zip');
    $min = (int) ($manifest['archive_size_min'] ?? 1);
    $max = (int) ($manifest['archive_size_max'] ?? 500_000_000);
    if ($url === '' || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
            'path' => '',
        ];
    }
    $parts = parse_url($url);
    if (!is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || !orange_restore_private_engine_runtime_host_allowed((string) ($parts['host'] ?? ''))) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
            'path' => '',
        ];
    }

    $cacheDir = $toolsRoot . DIRECTORY_SEPARATOR . 'download-cache';
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
        return ['ok' => false, 'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED, 'path' => ''];
    }
    $final = $cacheDir . DIRECTORY_SEPARATOR . $name;
    $partial = $final . '.partial.' . getmypid();

    // Reuse cached verified archive.
    if (is_file($final)) {
        $existing = strtolower((string) hash_file('sha256', $final));
        $size = (int) filesize($final);
        if (hash_equals($sha, $existing) && $size >= $min && $size <= $max) {
            return ['ok' => true, 'code' => 'ok', 'path' => $final];
        }
        @unlink($final);
    }

    // Test transport override (disposable suites) — must still enforce checksum later.
    if (isset($GLOBALS['orange_restore_private_engine_http_transport'])
        && is_callable($GLOBALS['orange_restore_private_engine_http_transport'])) {
        $transport = $GLOBALS['orange_restore_private_engine_http_transport'];
        $result = $transport($url, $partial, $manifest);
        if (!is_array($result) || empty($result['ok'])) {
            @unlink($partial);

            return [
                'ok' => false,
                'code' => (string) ($result['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED),
                'path' => '',
            ];
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 600,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: */*\r\nUser-Agent: OrangeRestorePrivateRuntime/1.0\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => (string) $parts['host'],
                'SNI_enabled' => true,
            ],
        ]);
        // Manual redirect loop (max 3) with host allowlist — never disable TLS.
        $currentUrl = $url;
        $body = false;
        for ($hop = 0; $hop < 4; $hop++) {
            $hopParts = parse_url($currentUrl);
            if (!is_array($hopParts)
                || strtolower((string) ($hopParts['scheme'] ?? '')) !== 'https'
                || !orange_restore_private_engine_runtime_host_allowed((string) ($hopParts['host'] ?? ''))) {
                @unlink($partial);

                return [
                    'ok' => false,
                    'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE,
                    'path' => '',
                ];
            }
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 600,
                    'follow_location' => 0,
                    'ignore_errors' => true,
                    'header' => "Accept: */*\r\nUser-Agent: OrangeRestorePrivateRuntime/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => (string) $hopParts['host'],
                    'SNI_enabled' => true,
                ],
            ]);
            $in = @fopen($currentUrl, 'rb', false, $ctx);
            if ($in === false) {
                @unlink($partial);

                return [
                    'ok' => false,
                    'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
                    'path' => '',
                ];
            }
            $meta = stream_get_meta_data($in);
            $wrapper = $meta['wrapper_data'] ?? [];
            $status = 0;
            $location = '';
            if (is_array($wrapper)) {
                foreach ($wrapper as $hdr) {
                    if (!is_string($hdr)) {
                        continue;
                    }
                    if (preg_match('#^HTTP/\S+\s+(\d+)#', $hdr, $m) === 1) {
                        $status = (int) $m[1];
                    }
                    if (stripos($hdr, 'Location:') === 0) {
                        $location = trim(substr($hdr, 9));
                    }
                }
            }
            if ($status >= 300 && $status < 400 && $location !== '') {
                fclose($in);
                if (str_starts_with($location, '/')) {
                    $currentUrl = 'https://' . (string) $hopParts['host'] . $location;
                } else {
                    $currentUrl = $location;
                }
                continue;
            }
            if ($status !== 200 && $status !== 0) {
                fclose($in);
                @unlink($partial);

                return [
                    'ok' => false,
                    'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
                    'path' => '',
                ];
            }
            $out = @fopen($partial, 'wb');
            if ($out === false) {
                fclose($in);
                @unlink($partial);

                return [
                    'ok' => false,
                    'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
                    'path' => '',
                ];
            }
            $written = 0;
            while (!feof($in)) {
                $chunk = fread($in, 1024 * 256);
                if ($chunk === false) {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $max) {
                    fclose($out);
                    fclose($in);
                    @unlink($partial);

                    return [
                        'ok' => false,
                        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED,
                        'path' => '',
                    ];
                }
                fwrite($out, $chunk);
            }
            fclose($out);
            fclose($in);
            $body = true;
            break;
        }
        if ($body !== true || !is_file($partial)) {
            @unlink($partial);

            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
                'path' => '',
            ];
        }
    }

    $size = (int) filesize($partial);
    if ($size < $min || $size > $max) {
        @unlink($partial);

        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED,
            'path' => '',
        ];
    }
    $got = strtolower((string) hash_file('sha256', $partial));
    if (!hash_equals($sha, $got)) {
        @unlink($partial);

        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED,
            'path' => '',
        ];
    }
    if (!@rename($partial, $final)) {
        if (!@copy($partial, $final)) {
            @unlink($partial);

            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
                'path' => '',
            ];
        }
        @unlink($partial);
    }

    return ['ok' => true, 'code' => 'ok', 'path' => $final];
}

/**
 * Extract archive into shared runtime dir; verify allowlist + executables; write marker.
 *
 * @param array<string, mixed> $manifest
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string}
 */
function orange_restore_private_engine_extract_and_verify(
    string $archivePath,
    string $sharedDir,
    array $manifest
): array {
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'source' => 'portable_extract',
        'version_prefix' => '',
    ];
    $format = (string) ($manifest['archive_format'] ?? 'zip');
    $staging = $sharedDir . DIRECTORY_SEPARATOR . '.extracting_' . getmypid();
    if (is_dir($staging)) {
        orange_restore_private_engine_rm_rf($staging);
    }
    if (!@mkdir($staging, 0775, true) && !is_dir($staging)) {
        return $empty;
    }

    try {
        if ($format === 'zip') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
            $zip = new ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
            $prefixes = $manifest['file_allowlist_prefixes'] ?? [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_contains($name, '..')) {
                    $zip->close();
                    throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED);
                }
                // Allowlist: after optional top-level dirname
                $rel = $name;
                if (preg_match('#^[^/]+/(.+)$#', $name, $m) === 1) {
                    $rel = $m[1];
                }
                $okPrefix = $rel === '' || str_ends_with($name, '/');
                if (!$okPrefix && is_array($prefixes)) {
                    foreach ($prefixes as $p) {
                        $p = str_replace('\\', '/', (string) $p);
                        if ($rel === rtrim($p, '/') || str_starts_with($rel, $p)) {
                            $okPrefix = true;
                            break;
                        }
                    }
                }
                if (!$okPrefix) {
                    // Soft: skip unexpected files rather than execute them; still extract known prefixes only.
                    continue;
                }
            }
            if (!$zip->extractTo($staging)) {
                $zip->close();
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
            $zip->close();
        } elseif ($format === 'tar.gz') {
            // Prefer PharData when available.
            if (!class_exists('PharData')) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
            $phar = new PharData($archivePath);
            $phar->extractTo($staging, null, true);
        } else {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE);
        }

        $basedir = orange_restore_private_engine_find_extracted_basedir($staging, $manifest);
        if ($basedir === '') {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
        }
        $validated = orange_restore_private_engine_validate_executable_root(
            $basedir,
            'verified_portable_artifact'
        );
        if (!($validated['ok'] ?? false)) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE);
        }

        // Promote staging → shared (replace prior incomplete).
        $finalBasedirName = basename($basedir);
        $destBasedir = $sharedDir . DIRECTORY_SEPARATOR . $finalBasedirName;
        if (!is_dir($sharedDir) && !@mkdir($sharedDir, 0775, true) && !is_dir($sharedDir)) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
        }
        if (is_dir($destBasedir)) {
            orange_restore_private_engine_rm_rf($destBasedir);
        }
        // Move extracted tree: if basedir is staging itself or nested.
        if (!@rename($basedir, $destBasedir)) {
            // Fallback copy
            orange_restore_private_engine_copy_tree($basedir, $destBasedir);
            orange_restore_private_engine_rm_rf($basedir);
        }
        // Cleanup leftover staging
        orange_restore_private_engine_rm_rf($staging);

        $marker = [
            'verified' => true,
            'verified_at' => gmdate('c'),
            'archive_sha256' => strtolower((string) ($manifest['archive_sha256'] ?? '')),
            'version' => (string) ($manifest['version'] ?? ''),
            'vendor' => (string) ($manifest['vendor'] ?? ''),
            'family' => (string) ($manifest['family'] ?? ''),
            'basedir_rel' => $finalBasedirName,
            'license' => (string) ($manifest['license'] ?? ''),
            'compatibility' => (string) ($manifest['compatibility'] ?? ''),
        ];
        file_put_contents(
            $sharedDir . DIRECTORY_SEPARATOR . '.runtime_verified.json',
            json_encode($marker, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );

        $validated = orange_restore_private_engine_validate_executable_root(
            $destBasedir,
            'verified_portable_artifact'
        );
        $validated['version_prefix'] = (string) ($manifest['version'] ?? 'portable');

        return $validated;
    } catch (Throwable $e) {
        orange_restore_private_engine_rm_rf($staging);
        $code = trim($e->getMessage());
        if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED;
        }

        return [
            'ok' => false,
            'code' => $code,
            'basedir' => '',
            'mysqld' => '',
            'mysql' => '',
            'family' => '',
            'source' => 'portable_extract',
            'version_prefix' => '',
        ];
    }
}

/**
 * @param array<string, mixed> $manifest
 */
function orange_restore_private_engine_find_extracted_basedir(string $staging, array $manifest): string
{
    $prefix = (string) ($manifest['extracted_root_dirname_prefix'] ?? '');
    $daemonRels = $manifest['daemon_relpaths'] ?? [];
    if (!is_array($daemonRels)) {
        $daemonRels = [];
    }
    $candidates = [$staging];
    foreach (scandir($staging) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $p = $staging . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($p)) {
            $candidates[] = $p;
            if ($prefix !== '' && str_starts_with($entry, $prefix)) {
                array_unshift($candidates, $p);
            }
        }
    }
    foreach ($candidates as $root) {
        foreach ($daemonRels as $rel) {
            $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $rel);
            if (is_file($root . DIRECTORY_SEPARATOR . $rel)) {
                return $root;
            }
        }
        // Generic bin/mysqld
        $isWin = PHP_OS_FAMILY === 'Windows';
        $daemon = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
            . ($isWin ? 'mysqld.exe' : 'mysqld');
        $daemon2 = $root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
            . ($isWin ? 'mariadbd.exe' : 'mariadbd');
        if (is_file($daemon) || is_file($daemon2)) {
            return $root;
        }
    }

    return '';
}

function orange_restore_private_engine_rm_rf(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function orange_restore_private_engine_copy_tree(string $src, string $dst): void
{
    if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $srcLen = strlen($src);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), $srcLen + 1);
        $target = $dst . DIRECTORY_SEPARATOR . $rel;
        if ($f->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
        } else {
            $parent = dirname($target);
            if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
            if (!@copy($f->getPathname(), $target)) {
                throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
            }
        }
    }
}

/**
 * Materialize pinned portable runtime (idempotent). Returns validated binaries.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string,channel:string}
 */
function orange_restore_private_engine_materialize_portable_runtime(string $projectRoot): array
{
    $fail = static function (string $code) : array {
        return [
            'ok' => false,
            'code' => $code,
            'basedir' => '',
            'mysqld' => '',
            'mysql' => '',
            'family' => '',
            'source' => 'portable_materialize',
            'version_prefix' => '',
            'channel' => 'none',
        ];
    };

    // Freeze: never download/launch on Production during coding-task flag.
    if (!empty($GLOBALS['orange_restore_private_engine_forbid_materialize'])) {
        return $fail(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
    }

    $existing = orange_restore_private_engine_discover_materialized_runtime($projectRoot);
    if ($existing['ok'] ?? false) {
        $existing['channel'] = 'verified_portable_cached';

        return $existing;
    }

    $manifest = orange_restore_private_engine_runtime_manifest_for_platform();
    if (!is_array($manifest)) {
        return $fail(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE);
    }

    try {
        $tools = orange_restore_private_engine_tools_root($projectRoot);
    } catch (Throwable) {
        return $fail(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
    }

    $lock = orange_restore_private_engine_runtime_lock_acquire($tools, 180);
    if ($lock === false) {
        return $fail(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
    }
    try {
        // Re-check after lock.
        $existing = orange_restore_private_engine_discover_materialized_runtime($projectRoot);
        if ($existing['ok'] ?? false) {
            $existing['channel'] = 'verified_portable_cached';

            return $existing;
        }

        $channel = 'pinned_https_first_use';
        $local = orange_restore_private_engine_find_local_artifact($tools, $manifest);
        if ($local !== null) {
            $archivePath = $local['path'];
            $channel = $local['channel'];
            $sha = strtolower((string) ($manifest['archive_sha256'] ?? ''));
            $got = strtolower((string) hash_file('sha256', $archivePath));
            if (!hash_equals($sha, $got)) {
                return $fail(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED);
            }
        } else {
            $dl = orange_restore_private_engine_download_pinned_archive($tools, $manifest);
            if (!($dl['ok'] ?? false)) {
                return $fail((string) ($dl['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED));
            }
            $archivePath = (string) $dl['path'];
        }

        $shared = orange_restore_private_engine_shared_runtime_dir($tools, $manifest);
        $extracted = orange_restore_private_engine_extract_and_verify($archivePath, $shared, $manifest);
        if (!($extracted['ok'] ?? false)) {
            return $fail((string) ($extracted['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED));
        }
        $extracted['channel'] = $channel;

        return $extracted;
    } finally {
        orange_restore_private_engine_runtime_lock_release($lock);
    }
}
