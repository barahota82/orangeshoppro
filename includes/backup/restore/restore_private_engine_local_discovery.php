<?php

declare(strict_types=1);

/**
 * Trusted local DB-server executable discovery for Restore Step 7 private engine.
 *
 * Exhausts: materialized portable runtime → Windows SCM → registry ImagePath →
 * @@basedir only when DB host is LOCAL_SAME_HOST / LOCAL_LOOPBACK.
 *
 * Forbidden: PATH/where/which, recursive disk scan, hardcoded Plesk paths,
 * browser-supplied paths.
 */

require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_private_engine_runtime_manifest.php';

const ORANGE_RESTORE_DB_HOST_LOCAL_SAME_HOST = 'LOCAL_SAME_HOST';
const ORANGE_RESTORE_DB_HOST_LOCAL_LOOPBACK = 'LOCAL_LOOPBACK';
const ORANGE_RESTORE_DB_HOST_REMOTE = 'REMOTE_HOST';
const ORANGE_RESTORE_DB_HOST_UNKNOWN = 'UNKNOWN';

/**
 * Classify Production DB host without leaking hostname to Owner UI.
 *
 * @return array{category:string,is_local:bool}
 */
function orange_restore_private_engine_classify_db_host(string $projectRoot): array
{
    if (isset($GLOBALS['orange_restore_private_engine_db_host_category_override'])
        && is_string($GLOBALS['orange_restore_private_engine_db_host_category_override'])) {
        $cat = trim($GLOBALS['orange_restore_private_engine_db_host_category_override']);
        $local = in_array($cat, [
            ORANGE_RESTORE_DB_HOST_LOCAL_SAME_HOST,
            ORANGE_RESTORE_DB_HOST_LOCAL_LOOPBACK,
        ], true);

        return ['category' => $cat !== '' ? $cat : ORANGE_RESTORE_DB_HOST_UNKNOWN, 'is_local' => $local];
    }

    try {
        $settings = orange_backup_load_db_settings($projectRoot);
        $host = strtolower(trim((string) ($settings['host'] ?? '')));
    } catch (Throwable) {
        return ['category' => ORANGE_RESTORE_DB_HOST_UNKNOWN, 'is_local' => false];
    }
    if ($host === '') {
        return ['category' => ORANGE_RESTORE_DB_HOST_UNKNOWN, 'is_local' => false];
    }
    if ($host === '127.0.0.1' || $host === '::1' || $host === 'localhost') {
        return ['category' => ORANGE_RESTORE_DB_HOST_LOCAL_LOOPBACK, 'is_local' => true];
    }
    // Strip port if host:port
    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = explode(':', $host, 2)[0];
    }
    $localNames = [];
    $hn = strtolower((string) gethostname());
    if ($hn !== '') {
        $localNames[] = $hn;
        $localNames[] = explode('.', $hn, 2)[0];
    }
    foreach (['SERVER_NAME', 'COMPUTERNAME', 'HOSTNAME'] as $envKey) {
        $v = strtolower(trim((string) (getenv($envKey) ?: '')));
        if ($v !== '') {
            $localNames[] = $v;
        }
    }
    $localNames = array_values(array_unique(array_filter($localNames)));
    if (in_array($host, $localNames, true)) {
        return ['category' => ORANGE_RESTORE_DB_HOST_LOCAL_SAME_HOST, 'is_local' => true];
    }
    // Private / link-local IPv4 of this machine
    $serverIps = @gethostbynamel($hn !== '' ? $hn : 'localhost') ?: [];
    if (in_array($host, array_map('strtolower', $serverIps), true)) {
        return ['category' => ORANGE_RESTORE_DB_HOST_LOCAL_SAME_HOST, 'is_local' => true];
    }

    return ['category' => ORANGE_RESTORE_DB_HOST_REMOTE, 'is_local' => false];
}

/**
 * Probe-create a candidate tools root (outside webroot, writable). Null on failure.
 */
function orange_restore_private_engine_try_prepare_tools_root(string $root): ?string
{
    $root = orange_backup_normalize_directory_path(trim($root));
    if ($root === '') {
        return null;
    }
    try {
        orange_backup_assert_outside_web_root($root);
    } catch (Throwable) {
        return null;
    }
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return null;
    }
    if (!is_writable($root)) {
        return null;
    }
    $probe = $root . DIRECTORY_SEPARATOR . '.orange_runtime_write_probe';
    if (@file_put_contents($probe, 'ok', LOCK_EX) === false) {
        return null;
    }
    @unlink($probe);

    return realpath($root) ?: $root;
}

/**
 * Ordered tools-root candidates (env → backup-root sibling → work-root sibling → drive/project parent).
 *
 * @return list<string>
 */
function orange_restore_private_engine_tools_root_candidates(string $projectRoot, ?array $env = null): array
{
    $env = $env ?? orange_backup_load_env_array($projectRoot);
    $out = [];
    $configured = trim((string) ($env['ORANGE_RESTORE_PRIVATE_TOOLS_DIR'] ?? ''));
    if ($configured !== '') {
        $out[] = orange_backup_normalize_directory_path($configured);
    }
    try {
        $backupRoot = orange_backup_resolve_root($env);
        $out[] = dirname($backupRoot) . DIRECTORY_SEPARATOR . 'orange_restore_private_tools';
    } catch (Throwable) {
        // continue
    }
    try {
        if (!function_exists('orange_restore_resolve_work_root')) {
            require_once __DIR__ . '/restore_paths.php';
        }
        $workRoot = orange_restore_resolve_work_root($env);
        $out[] = dirname($workRoot) . DIRECTORY_SEPARATOR . 'orange_restore_private_tools';
    } catch (Throwable) {
        // continue
    }
    $projectReal = realpath($projectRoot) ?: $projectRoot;
    $drive = preg_match('/^([A-Za-z]:)/', $projectReal, $m) ? $m[1] : '';
    if ($drive !== '') {
        $out[] = $drive . DIRECTORY_SEPARATOR . 'orange_restore_private_tools';
    }
    $out[] = dirname($projectReal) . DIRECTORY_SEPARATOR . 'orange_restore_private_tools';

    $unique = [];
    foreach ($out as $cand) {
        $norm = strtolower(str_replace('\\', '/', orange_backup_normalize_directory_path($cand)));
        if ($norm === '' || isset($unique[$norm])) {
            continue;
        }
        $unique[$norm] = orange_backup_normalize_directory_path($cand);
    }

    return array_values($unique);
}

/**
 * Zero-mutation tools-root readiness (no download). Does not throw.
 *
 * @return array{ok:bool,code:string,writable:bool}
 */
function orange_restore_private_engine_tools_root_probe(string $projectRoot, ?array $env = null): array
{
    if (isset($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && is_string($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && trim($GLOBALS['orange_restore_private_engine_tools_root_override']) !== '') {
        $got = orange_restore_private_engine_try_prepare_tools_root(
            trim($GLOBALS['orange_restore_private_engine_tools_root_override'])
        );
        if ($got !== null) {
            return ['ok' => true, 'code' => 'ok', 'writable' => true];
        }

        return [
            'ok' => false,
            'code' => defined('ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY')
                ? ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY
                : 'STEP7_PRIVATE_TOOLS_ROOT_NOT_READY',
            'writable' => false,
        ];
    }

    foreach (orange_restore_private_engine_tools_root_candidates($projectRoot, $env) as $cand) {
        if (orange_restore_private_engine_try_prepare_tools_root($cand) !== null) {
            return ['ok' => true, 'code' => 'ok', 'writable' => true];
        }
    }

    return [
        'ok' => false,
        'code' => defined('ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY')
            ? ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY
            : 'STEP7_PRIVATE_TOOLS_ROOT_NOT_READY',
        'writable' => false,
    ];
}

/**
 * Resolve shared private tools root (outside webroot / backups snapshots).
 * Prefers a writable sibling of BackupRoot/restore_work (Plesk-safe) over bare drive root.
 */
function orange_restore_private_engine_tools_root(string $projectRoot, ?array $env = null): string
{
    if (isset($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && is_string($GLOBALS['orange_restore_private_engine_tools_root_override'])
        && trim($GLOBALS['orange_restore_private_engine_tools_root_override']) !== '') {
        $got = orange_restore_private_engine_try_prepare_tools_root(
            trim($GLOBALS['orange_restore_private_engine_tools_root_override'])
        );
        if ($got === null) {
            throw new RuntimeException(
                defined('ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY')
                    ? ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY
                    : ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED
            );
        }

        return $got;
    }

    foreach (orange_restore_private_engine_tools_root_candidates($projectRoot, $env) as $cand) {
        $got = orange_restore_private_engine_try_prepare_tools_root($cand);
        if ($got !== null) {
            return $got;
        }
    }

    throw new RuntimeException(
        defined('ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY')
            ? ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY
            : ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED
    );
}

/**
 * Path to shared verified runtime install for a manifest version.
 *
 * @param array<string, mixed> $manifest
 */
function orange_restore_private_engine_shared_runtime_dir(string $toolsRoot, array $manifest): string
{
    $vendor = preg_replace('/[^a-z0-9._-]+/i', '', (string) ($manifest['vendor'] ?? 'vendor')) ?: 'vendor';
    $version = preg_replace('/[^a-z0-9._-]+/i', '', (string) ($manifest['version'] ?? '0')) ?: '0';
    $dir = rtrim($toolsRoot, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $vendor
        . DIRECTORY_SEPARATOR . $version;

    return $dir;
}

/**
 * Validate candidate daemon/client under a basedir-like root.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string}
 */
function orange_restore_private_engine_validate_executable_root(
    string $basedir,
    string $source
): array {
    if (!function_exists('orange_restore_private_engine_resolve_binaries_under_basedir')) {
        require_once __DIR__ . '/restore_private_shadow_engine.php';
    }
    $resolved = orange_restore_private_engine_resolve_binaries_under_basedir($basedir);
    if (!($resolved['ok'] ?? false)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
            'basedir' => '',
            'mysqld' => '',
            'mysql' => '',
            'family' => '',
            'source' => $source,
        ];
    }
    $mysqld = (string) $resolved['mysqld'];
    if (!is_file($mysqld) || !is_readable($mysqld)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
            'basedir' => '',
            'mysqld' => '',
            'mysql' => '',
            'family' => '',
            'source' => $source,
        ];
    }
    // Reject obvious non-regular (directory) — is_file already.
    $resolved['source'] = $source;
    $resolved['version_prefix'] = (string) ($resolved['version_prefix'] ?? $source);

    return $resolved;
}

/**
 * Discover already-materialized portable runtime under tools root.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string}
 */
function orange_restore_private_engine_discover_materialized_runtime(string $projectRoot): array
{
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'source' => 'materialized_portable',
        'version_prefix' => '',
    ];
    $manifest = orange_restore_private_engine_runtime_manifest_for_platform();
    if (!is_array($manifest)) {
        return $empty;
    }
    try {
        $tools = orange_restore_private_engine_tools_root($projectRoot);
    } catch (Throwable) {
        return $empty;
    }
    $shared = orange_restore_private_engine_shared_runtime_dir($tools, $manifest);
    $marker = $shared . DIRECTORY_SEPARATOR . '.runtime_verified.json';
    if (!is_file($marker) || !is_dir($shared)) {
        return $empty;
    }
    $meta = json_decode((string) file_get_contents($marker), true);
    if (!is_array($meta) || empty($meta['verified']) || empty($meta['basedir_rel'])) {
        return $empty;
    }
    $expectedSha = strtolower((string) ($manifest['archive_sha256'] ?? ''));
    $gotSha = strtolower((string) ($meta['archive_sha256'] ?? ''));
    if ($expectedSha === '' || !hash_equals($expectedSha, $gotSha)) {
        return $empty;
    }
    $basedir = $shared . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $meta['basedir_rel']);
    $validated = orange_restore_private_engine_validate_executable_root($basedir, 'verified_portable_artifact');
    if (!($validated['ok'] ?? false)) {
        return $empty;
    }
    $validated['version_prefix'] = (string) ($manifest['version'] ?? 'portable');

    return $validated;
}

/**
 * Extract executable path from Windows service ImagePath / binPath.
 */
function orange_restore_private_engine_parse_windows_image_path(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if ($raw[0] === '"') {
        if (preg_match('/^"([^"]+)"/', $raw, $m) === 1) {
            return $m[1];
        }
    }
    // Unquoted: path until .exe then stop
    if (preg_match('/^(.+?\.(?:exe|EXE))(?:\s|$)/', $raw, $m) === 1) {
        return $m[1];
    }
    $parts = preg_split('/\s+/', $raw) ?: [];

    return (string) ($parts[0] ?? '');
}

/**
 * @return list<string> absolute mysqld/mariadbd candidate paths (no PATH scan)
 */
function orange_restore_private_engine_windows_service_executable_candidates(): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return [];
    }
    if (isset($GLOBALS['orange_restore_private_engine_windows_service_candidates_override'])
        && is_array($GLOBALS['orange_restore_private_engine_windows_service_candidates_override'])) {
        return array_values(array_filter(array_map(
            static fn ($v): string => is_string($v) ? trim($v) : '',
            $GLOBALS['orange_restore_private_engine_windows_service_candidates_override']
        )));
    }

    $candidates = [];
    $out = [];
    $code = 1;
    // Fixed server-controlled query — service names commonly used by MySQL/MariaDB.
    @exec('sc query state= all 2>&1', $out, $code);
    if ($code !== 0 && $out === []) {
        return [];
    }
    $serviceNames = [];
    foreach ($out as $line) {
        if (preg_match('/^\s*SERVICE_NAME:\s*(.+)\s*$/i', $line, $m) === 1) {
            $name = trim($m[1]);
            $lname = strtolower($name);
            if (str_contains($lname, 'mysql') || str_contains($lname, 'maria')) {
                $serviceNames[] = $name;
            }
        }
    }
    foreach (array_slice(array_values(array_unique($serviceNames)), 0, 24) as $svc) {
        if (!preg_match('/^[A-Za-z0-9 _.-]+$/', $svc)) {
            continue;
        }
        $qc = [];
        $qcCode = 1;
        @exec('sc qc ' . escapeshellarg($svc) . ' 2>&1', $qc, $qcCode);
        $blob = implode("\n", $qc);
        if (preg_match('/BINARY_PATH_NAME\s*:\s*(.+)$/mi', $blob, $m) !== 1) {
            continue;
        }
        $exe = orange_restore_private_engine_parse_windows_image_path(trim($m[1]));
        $lexe = strtolower($exe);
        if ($exe === '' || (!str_contains($lexe, 'mysqld') && !str_contains($lexe, 'mariadbd'))) {
            continue;
        }
        if (is_file($exe)) {
            $candidates[] = $exe;
        }
    }

    // Registry ImagePath under Services (trusted machine hive only).
    $regOut = [];
    $regCode = 1;
    @exec(
        'reg query "HKLM\\SYSTEM\\CurrentControlSet\\Services" /s /v ImagePath 2>&1',
        $regOut,
        $regCode
    );
    $pendingSvc = '';
    foreach ($regOut as $line) {
        if (preg_match('/Services\\\\([^\\\\]+)\\\\?$/i', $line, $m) === 1) {
            $pendingSvc = strtolower($m[1]);
            continue;
        }
        if (stripos($line, 'ImagePath') === false) {
            continue;
        }
        if ($pendingSvc !== ''
            && !str_contains($pendingSvc, 'mysql')
            && !str_contains($pendingSvc, 'maria')) {
            continue;
        }
        if (preg_match('/ImagePath\s+REG_\w+\s+(.+)$/i', $line, $m) !== 1) {
            continue;
        }
        $exe = orange_restore_private_engine_parse_windows_image_path(trim($m[1]));
        $lexe = strtolower($exe);
        if ($exe === '' || (!str_contains($lexe, 'mysqld') && !str_contains($lexe, 'mariadbd'))) {
            continue;
        }
        // Expand %SystemRoot% etc.
        $exe = preg_replace_callback('/%([^%]+)%/', static function (array $mm): string {
            $v = getenv($mm[1]);

            return is_string($v) && $v !== '' ? $v : $mm[0];
        }, $exe) ?? $exe;
        if (is_file($exe)) {
            $candidates[] = $exe;
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * From a mysqld.exe path, resolve basedir (parent of bin).
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string}
 */
function orange_restore_private_engine_from_daemon_path(string $daemonPath, string $source): array
{
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'source' => $source,
        'version_prefix' => '',
    ];
    $daemonPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($daemonPath));
    if ($daemonPath === '' || !is_file($daemonPath)) {
        return $empty;
    }
    $binDir = dirname($daemonPath);
    $basedir = dirname($binDir);
    $validated = orange_restore_private_engine_validate_executable_root($basedir, $source);
    if (!($validated['ok'] ?? false)) {
        return $empty;
    }
    $validated['version_prefix'] = $source;

    return $validated;
}

/**
 * Trusted local service/registry discovery (Windows). Never alters services.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string}
 */
function orange_restore_private_engine_discover_local_service_binaries(): array
{
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'source' => 'local_service',
        'version_prefix' => '',
    ];
    foreach (orange_restore_private_engine_windows_service_executable_candidates() as $exe) {
        $got = orange_restore_private_engine_from_daemon_path($exe, 'verified_local_service_binary');
        if ($got['ok'] ?? false) {
            return $got;
        }
    }

    return $empty;
}

/**
 * @@basedir discovery only when DB host is proven local.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,source:string,version_prefix:string}
 */
function orange_restore_private_engine_discover_basedir_when_local(string $projectRoot): array
{
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'source' => 'production_basedir',
        'version_prefix' => '',
    ];
    $host = orange_restore_private_engine_classify_db_host($projectRoot);
    if (empty($host['is_local'])) {
        return $empty;
    }
    // Reuse legacy PDO @@basedir helper (still no CREATE/GRANT).
    if (!function_exists('orange_restore_private_engine_discover_binaries')) {
        require_once __DIR__ . '/restore_private_shadow_engine.php';
    }
    // Call inner basedir read without going through the new authoritative resolver (avoid recursion).
    $prev = $GLOBALS['orange_restore_private_engine_skip_authoritative_resolver'] ?? null;
    $GLOBALS['orange_restore_private_engine_skip_authoritative_resolver'] = true;
    try {
        $got = orange_restore_private_engine_discover_binaries_legacy_basedir($projectRoot);
    } finally {
        if ($prev === null) {
            unset($GLOBALS['orange_restore_private_engine_skip_authoritative_resolver']);
        } else {
            $GLOBALS['orange_restore_private_engine_skip_authoritative_resolver'] = $prev;
        }
    }
    if (!($got['ok'] ?? false)) {
        return $empty;
    }
    $got['source'] = 'verified_local_basedir';

    return $got;
}
