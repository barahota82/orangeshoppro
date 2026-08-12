<?php

declare(strict_types=1);

/**
 * Application-owned private MySQL/MariaDB shadow engine for Restore Step 7.
 *
 * Registers:
 *   PRIVATE_SHADOW_ENGINE_01
 *   NO_PRODUCTION_MYSQL_PROVISIONING_01
 *   JOB_OWNED_PRIVATE_DATADIR_01
 *   LOOPBACK_ONLY_01
 *   NO_OWNER_ACTION_01
 *   PROTECTED_WORKING_BASELINE_01
 *   AUTOMATED_RUNTIME_SUPPLY_REQUIRED_01
 *
 * Authoritative resolver order (§14):
 *   1) verified materialized portable runtime
 *   2) trusted local service/registry executable
 *   3) pinned portable materialization (A/B/C)
 *   4) @@basedir only when DB host is LOCAL_SAME_HOST / LOCAL_LOOPBACK
 *   5) fail closed
 *
 * Never uses PATH/where/which/scan/hardcoded Plesk paths.
 * Never CREATE DATABASE on Production MySQL.
 */

require_once __DIR__ . '/../backup_environment.php';
require_once __DIR__ . '/restore_paths.php';
require_once __DIR__ . '/restore_job_framework.php';

const ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME = 'private_shadow_engine';
const ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE = 'engine_state.json';
const ORANGE_RESTORE_PRIVATE_ENGINE_SECRET_FILE = '.engine_runtime.opt';
const ORANGE_RESTORE_PRIVATE_ENGINE_BOOTSTRAP_OPT = '.engine_bootstrap.opt';
const ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG = 'mysqld_private.err';
const ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE = 'mysqld_private.pid';
const ORANGE_RESTORE_PRIVATE_ENGINE_RECORD_VERSION = 'step7-private-engine-v1';

/** Owner-safe Step-7 private engine codes (no path/port/db/password exposure). */
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE = 'STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED = 'STEP7_PRIVATE_ENGINE_INIT_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED = 'STEP7_PRIVATE_ENGINE_START_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED = 'STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED = 'STEP7_PRIVATE_ENGINE_PROVISION_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY = 'STEP7_PRIVATE_ENGINE_NOT_READY';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED = 'STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE = 'STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE';

// Supply-chain helpers (after constants — avoid circular redefinition).
require_once __DIR__ . '/restore_private_engine_runtime_manifest.php';
require_once __DIR__ . '/restore_private_engine_local_discovery.php';
require_once __DIR__ . '/restore_private_engine_materializer.php';

/** Readiness: binary discoverable, engine not yet provisioned (zero-mutation diagnostic). */
const ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING = 'READY_FOR_PRIVATE_SHADOW_PROVISIONING';

/**
 * Owner Arabic for private-engine safe codes.
 */
function orange_restore_private_engine_operator_reason_ar(string $safeCode): string
{
    $map = [
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE => 'تعذر اكتشاف محرك قاعدة الظل الخاص على الخادم. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED => 'تعذر تهيئة محرك قاعدة الظل الخاص. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED => 'تعذر تشغيل محرك قاعدة الظل الخاص. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED => 'تعذر تأمين أسرار محرك قاعدة الظل الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED => 'تعذر تجهيز محرك قاعدة الظل الخاص لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY => 'محرك قاعدة الظل الخاص غير جاهز بعد. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED => 'تعذر تطبيق سياسة الشبكة المحلية لمحرك قاعدة الظل الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED => 'تعذر تجهيز مستخدم التشغيل المقيّد لقاعدة الظل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED => 'تعذر توريد محرك قاعدة الظل المحمول الموثوق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED => 'فشل التحقق من سلامة حزمة محرك قاعدة الظل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE => 'لا تتوفر قناة آمنة لتوريد محرك قاعدة الظل على هذا الخادم. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING => 'الجاهزية: يمكن تجهيز محرك قاعدة الظل الخاص عند الضغط على خطوة استعادة قاعدة الظل.',
    ];

    return $map[$safeCode] ?? 'تعذر تجهيز محرك قاعدة الظل الخاص. لم يبدأ التنفيذ.';
}

function orange_restore_private_engine_root(string $workRoot, string $jobId): string
{
    $jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
    if (!is_dir($jobDir) && !@mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
    $root = $jobDir . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME;
    orange_restore_assert_inside_work_root($workRoot, $root);

    return $root;
}

/**
 * Normalize basedir from @@basedir (may use forward slashes on Windows builds).
 */
function orange_restore_private_engine_normalize_basedir(string $basedir): string
{
    $basedir = trim($basedir);
    if ($basedir === '') {
        return '';
    }
    $basedir = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basedir);
    $basedir = rtrim($basedir, DIRECTORY_SEPARATOR);
    // Some servers append trailing "\".
    return $basedir;
}

/**
 * Resolve daemon + client binaries strictly under a trusted basedir/bin.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string}
 */
function orange_restore_private_engine_resolve_binaries_under_basedir(string $basedir): array
{
    $basedir = orange_restore_private_engine_normalize_basedir($basedir);
    $fail = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
    ];
    if ($basedir === '' || !is_dir($basedir)) {
        return $fail;
    }
    $bin = $basedir . DIRECTORY_SEPARATOR . 'bin';
    if (!is_dir($bin)) {
        return $fail;
    }
    $isWin = PHP_OS_FAMILY === 'Windows';
    $daemonNames = $isWin
        ? ['mysqld.exe', 'mariadbd.exe']
        : ['mysqld', 'mariadbd'];
    $clientNames = $isWin
        ? ['mysql.exe', 'mariadb.exe']
        : ['mysql', 'mariadb'];

    $mysqld = '';
    $family = '';
    foreach ($daemonNames as $name) {
        $candidate = $bin . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            $mysqld = $candidate;
            $family = str_starts_with(strtolower($name), 'maria') ? 'mariadb' : 'mysql';
            break;
        }
    }
    if ($mysqld === '') {
        return $fail;
    }
    $mysql = '';
    foreach ($clientNames as $name) {
        $candidate = $bin . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            $mysql = $candidate;
            break;
        }
    }
    if ($mysql === '') {
        return $fail;
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'basedir' => $basedir,
        'mysqld' => $mysqld,
        'mysql' => $mysql,
        'family' => $family,
    ];
}

/**
 * Legacy @@basedir-only discovery (read-only Production connection).
 * Used only when host is local, or as an internal helper. Not the authoritative resolver.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,version_prefix:string,source:string}
 */
function orange_restore_private_engine_discover_binaries_legacy_basedir(string $projectRoot): array
{
    $empty = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'version_prefix' => '',
        'source' => 'production_basedir',
    ];

    if (isset($GLOBALS['orange_restore_private_engine_basedir_override'])
        && is_string($GLOBALS['orange_restore_private_engine_basedir_override'])
        && trim($GLOBALS['orange_restore_private_engine_basedir_override']) !== '') {
        $resolved = orange_restore_private_engine_resolve_binaries_under_basedir(
            trim($GLOBALS['orange_restore_private_engine_basedir_override'])
        );
        if (!($resolved['ok'] ?? false)) {
            return $empty;
        }
        $resolved['version_prefix'] = 'override';
        $resolved['source'] = 'test_basedir_override';

        return $resolved;
    }

    try {
        $settings = orange_backup_load_db_settings($projectRoot);
        $host = (string) ($settings['host'] ?? '');
        $user = (string) ($settings['user'] ?? '');
        $pass = (string) ($settings['pass'] ?? '');
        if ($host === '' || $user === '') {
            return $empty;
        }
        $dsn = 'mysql:host=' . $host . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $row = $pdo->query('SELECT @@basedir AS basedir, @@version AS version')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return $empty;
        }
        $basedir = orange_restore_private_engine_normalize_basedir((string) ($row['basedir'] ?? ''));
        $version = (string) ($row['version'] ?? '');
        $resolved = orange_restore_private_engine_resolve_binaries_under_basedir($basedir);
        if (!($resolved['ok'] ?? false)) {
            return $empty;
        }
        $resolved['version_prefix'] = substr($version, 0, 12);
        $resolved['source'] = 'production_basedir';

        return $resolved;
    } catch (Throwable) {
        return $empty;
    }
}

/**
 * Authoritative Restore-only private-engine executable resolver (§14).
 * When $allowMaterialize is false (readiness diagnostic): never download/extract.
 *
 * @return array{
 *   ok:bool,
 *   code:string,
 *   basedir:string,
 *   mysqld:string,
 *   mysql:string,
 *   family:string,
 *   version_prefix:string,
 *   source:string,
 *   materializable:bool,
 *   channel:string,
 *   db_host_category:string
 * }
 */
function orange_restore_private_engine_resolve_runtime(
    string $projectRoot,
    bool $allowMaterialize = false
): array {
    $hostInfo = orange_restore_private_engine_classify_db_host($projectRoot);
    $base = [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
        'basedir' => '',
        'mysqld' => '',
        'mysql' => '',
        'family' => '',
        'version_prefix' => '',
        'source' => 'unavailable',
        'materializable' => false,
        'channel' => 'none',
        'db_host_category' => (string) ($hostInfo['category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
    ];

    // Test basedir override remains highest for disposable suites.
    if (isset($GLOBALS['orange_restore_private_engine_basedir_override'])
        && is_string($GLOBALS['orange_restore_private_engine_basedir_override'])
        && trim($GLOBALS['orange_restore_private_engine_basedir_override']) !== '') {
        $legacy = orange_restore_private_engine_discover_binaries_legacy_basedir($projectRoot);
        if ($legacy['ok'] ?? false) {
            return array_merge($base, $legacy, [
                'ok' => true,
                'code' => 'ok',
                'materializable' => false,
                'channel' => 'test_override',
                'db_host_category' => $base['db_host_category'],
            ]);
        }
    }

    // 1) Verified previously materialized portable runtime.
    $mat = orange_restore_private_engine_discover_materialized_runtime($projectRoot);
    if ($mat['ok'] ?? false) {
        return array_merge($base, $mat, [
            'ok' => true,
            'code' => 'ok',
            'materializable' => false,
            'channel' => 'verified_portable_cached',
            'db_host_category' => $base['db_host_category'],
        ]);
    }

    // 2) Trusted local service/registry executable (read-only; never alter service).
    $svc = orange_restore_private_engine_discover_local_service_binaries();
    if ($svc['ok'] ?? false) {
        return array_merge($base, $svc, [
            'ok' => true,
            'code' => 'ok',
            'materializable' => false,
            'channel' => 'local_service',
            'db_host_category' => $base['db_host_category'],
        ]);
    }

    // Channel probe (no download).
    $probe = orange_restore_private_engine_runtime_channel_probe($projectRoot);
    $materializable = !empty($probe['ok']) && !empty($probe['materializable']);

    // 3) Materialize pinned portable runtime when allowed (provision path only).
    if ($allowMaterialize && $materializable) {
        $built = orange_restore_private_engine_materialize_portable_runtime($projectRoot);
        if ($built['ok'] ?? false) {
            return array_merge($base, $built, [
                'ok' => true,
                'code' => 'ok',
                'materializable' => false,
                'channel' => (string) ($built['channel'] ?? 'pinned_https_first_use'),
                'db_host_category' => $base['db_host_category'],
            ]);
        }
        $code = (string) ($built['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED);
        if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED;
        }

        return array_merge($base, [
            'code' => $code,
            'materializable' => false,
            'channel' => (string) ($probe['channel'] ?? 'none'),
        ]);
    }

    // 4) @@basedir only when DB host is local.
    if (!empty($hostInfo['is_local'])) {
        $basedirHit = orange_restore_private_engine_discover_binaries_legacy_basedir($projectRoot);
        if (($basedirHit['ok'] ?? false)
            && (($basedirHit['source'] ?? '') !== 'test_basedir_override'
                || isset($GLOBALS['orange_restore_private_engine_basedir_override']))) {
            // Accept local basedir when binaries resolve on this machine.
            if (is_file((string) ($basedirHit['mysqld'] ?? ''))) {
                return array_merge($base, $basedirHit, [
                    'ok' => true,
                    'code' => 'ok',
                    'source' => 'verified_local_basedir',
                    'materializable' => $materializable,
                    'channel' => $materializable ? (string) ($probe['channel'] ?? 'none') : 'local_basedir',
                    'db_host_category' => $base['db_host_category'],
                ]);
            }
        }
    }

    // Fail closed — but advertise materializable for readiness when channel is safe.
    if ($materializable && !$allowMaterialize) {
        return array_merge($base, [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE,
            'materializable' => true,
            'channel' => (string) ($probe['channel'] ?? 'pinned_https_first_use'),
            'source' => 'materializable_portable',
            'family' => (string) (($probe['manifest_summary']['family'] ?? '') ?: ''),
            'version_prefix' => (string) (($probe['manifest_summary']['version'] ?? '') ?: ''),
        ]);
    }

    return array_merge($base, [
        'materializable' => false,
        'channel' => (string) ($probe['channel'] ?? 'none'),
        'code' => !empty($probe['ok'])
            ? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE
            : (string) ($probe['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE),
    ]);
}

/**
 * Backward-compatible discover entry — authoritative resolver without materialize.
 *
 * @return array{ok:bool,code:string,basedir:string,mysqld:string,mysql:string,family:string,version_prefix:string,source?:string,materializable?:bool,channel?:string,db_host_category?:string}
 */
function orange_restore_private_engine_discover_binaries(string $projectRoot): array
{
    if (!empty($GLOBALS['orange_restore_private_engine_skip_authoritative_resolver'])) {
        return orange_restore_private_engine_discover_binaries_legacy_basedir($projectRoot);
    }

    return orange_restore_private_engine_resolve_runtime($projectRoot, false);
}

/**
 * @return array<string, mixed>|null
 */
function orange_restore_private_engine_load_state(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_private_engine_root($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE;
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Persist non-secret engine state only.
 *
 * @param array<string, mixed> $state
 */
function orange_restore_private_engine_write_state(string $workRoot, string $jobId, array $state): void
{
    $root = orange_restore_private_engine_root($workRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
    unset(
        $state['password'],
        $state['admin_password'],
        $state['runtime_password'],
        $state['absolute_paths'],
        $state['mysqld'],
        $state['mysql'],
        $state['basedir'],
        $state['datadir'],
        $state['socket'],
        $state['option_file']
    );
    // Keep port out of browser-facing payloads; store hashed presence only when needed.
    if (isset($state['port'])) {
        $state['port_bound'] = ((int) $state['port']) > 0;
        unset($state['port']);
    }
    $state['record_version'] = ORANGE_RESTORE_PRIVATE_ENGINE_RECORD_VERSION;
    $state['updated_at'] = gmdate('c');
    $json = json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false
        || file_put_contents(
            $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_STATE_FILE,
            $json . "\n",
            LOCK_EX
        ) === false
    ) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
}

/**
 * High-entropy secret (never logged).
 */
function orange_restore_private_engine_random_secret(int $bytes = 24): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '._'), '=');
}

/**
 * Restrict ACL on a secret file (Windows icacls / Unix chmod). Fail → secret boundary.
 */
function orange_restore_private_engine_harden_secret_file(string $path): void
{
    if (!is_file($path)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED);
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $user = get_current_user();
        if ($user === false || trim((string) $user) === '') {
            $user = getenv('USERNAME') ?: getenv('USER') ?: '';
        }
        $user = trim((string) $user);
        if ($user === '') {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED);
        }
        $cmd = 'icacls ' . escapeshellarg($path)
            . ' /inheritance:r /grant:r '
            . escapeshellarg($user) . ':F';
        $out = [];
        $code = 1;
        @exec($cmd . ' 2>&1', $out, $code);
        if ($code !== 0) {
            // Fallback: still refuse to leave world-readable secrets when hardening fails.
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED);
        }

        return;
    }
    if (!@chmod($path, 0600)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED);
    }
}

/**
 * Write MySQL option file with credentials (never argv). Hardens ACL immediately.
 *
 * @param array<string, string> $client
 */
function orange_restore_private_engine_write_option_file(string $path, array $client): void
{
    $lines = ["[client]\n"];
    foreach (['user', 'password', 'host', 'port', 'socket'] as $key) {
        if (!isset($client[$key]) || (string) $client[$key] === '') {
            continue;
        }
        $lines[] = $key . '=' . (string) $client[$key] . "\n";
    }
    if (file_put_contents($path, implode('', $lines), LOCK_EX) === false) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED);
    }
    orange_restore_private_engine_harden_secret_file($path);
}

/**
 * @return array{user:string,password:string,host:string,port:int,socket:string}|null
 */
function orange_restore_private_engine_read_runtime_secrets(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_private_engine_root($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_PRIVATE_ENGINE_SECRET_FILE;
    if (!is_file($path)) {
        return null;
    }
    $raw = (string) file_get_contents($path);
    $user = '';
    $password = '';
    $host = '127.0.0.1';
    $port = 0;
    $socket = '';
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '[' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($k === 'user') {
            $user = $v;
        } elseif ($k === 'password') {
            $password = $v;
        } elseif ($k === 'host') {
            $host = $v;
        } elseif ($k === 'port') {
            $port = (int) $v;
        } elseif ($k === 'socket') {
            $socket = $v;
        }
    }
    if ($user === '' || $password === '' || ($port <= 0 && $socket === '')) {
        return null;
    }

    return [
        'user' => $user,
        'password' => $password,
        'host' => $host,
        'port' => $port,
        'socket' => $socket,
    ];
}

/**
 * Pick an unused high loopback TCP port.
 */
function orange_restore_private_engine_allocate_loopback_port(): int
{
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if (!is_resource($sock)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED);
    }
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED);
    }
    $port = (int) $m[1];
    if ($port < 1024 || $port > 65535) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED);
    }

    return $port;
}

function orange_restore_private_engine_port_open(string $host, int $port): bool
{
    if ($host !== '127.0.0.1' && $host !== '::1') {
        return false;
    }
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 0.8);
    if (is_resource($fp)) {
        fclose($fp);

        return true;
    }

    return false;
}

/**
 * @param list<string> $args
 * @return array{exit_code:int,output:string}
 */
function orange_restore_private_engine_run_capture(string $binary, array $args, ?string $defaultsFile = null): array
{
    $cmd = [escapeshellarg($binary)];
    if ($defaultsFile !== null && $defaultsFile !== '') {
        $cmd[] = '--defaults-extra-file=' . escapeshellarg($defaultsFile);
    }
    foreach ($args as $arg) {
        $cmd[] = $arg;
    }
    $out = [];
    $code = 1;
    @exec(implode(' ', $cmd) . ' 2>&1', $out, $code);

    return [
        'exit_code' => $code,
        'output' => implode("\n", $out),
    ];
}

/**
 * Spawn mysqld detached; returns OS PID when available (0 if unknown).
 *
 * @param list<string> $args
 */
function orange_restore_private_engine_spawn_daemon(string $mysqld, array $args, string $errorLog): int
{
    $cmdParts = [escapeshellarg($mysqld)];
    foreach ($args as $arg) {
        // Args are pre-built with values; avoid double-escaping path-like tokens.
        $cmdParts[] = $arg;
    }
    $command = implode(' ', $cmdParts);
    if (PHP_OS_FAMILY === 'Windows') {
        $proc = @proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $errorLog, 'a'],
                2 => ['file', $errorLog, 'a'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => false]
        );
        if (!is_resource($proc)) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED);
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $status = proc_get_status($proc);
        $pid = (int) ($status['pid'] ?? 0);
        // Detach — do not proc_close wait.

        return $pid > 0 ? $pid : 0;
    }

    @exec('nohup ' . $command . ' >/dev/null 2>&1 & echo $!', $out, $code);
    $pid = isset($out[0]) ? (int) trim((string) $out[0]) : 0;

    return $pid > 0 ? $pid : 0;
}

function orange_restore_private_engine_read_pid_file(string $pidFile): int
{
    if (!is_file($pidFile)) {
        return 0;
    }
    $raw = trim((string) file_get_contents($pidFile));

    return ctype_digit($raw) ? (int) $raw : 0;
}

/**
 * Zero-mutation preflight: resolve runtime source (no download/datadir/credentials).
 *
 * @return array{
 *   ok:bool,
 *   code:string,
 *   ready_token:string,
 *   binary_available:bool,
 *   engine_ready:bool,
 *   family:string,
 *   shadow_db_identity_hash:string,
 *   runtime_source:string,
 *   materializable:bool,
 *   channel:string,
 *   db_host_category:string,
 *   runtime_compatible:bool,
 *   manifest:array<string,mixed>
 * }
 */
function orange_restore_private_engine_preflight(
    string $projectRoot,
    string $workRoot,
    string $jobId
): array {
    $discovered = orange_restore_private_engine_resolve_runtime($projectRoot, false);
    $materializable = !empty($discovered['materializable']);
    $binaryOk = !empty($discovered['ok']);
    $sourceReady = $binaryOk || $materializable;
    $manifest = orange_restore_private_engine_runtime_manifest_public_summary();
    $runtimeSource = 'unavailable';
    if ($binaryOk) {
        $src = (string) ($discovered['source'] ?? '');
        if (str_contains($src, 'portable') || ($discovered['channel'] ?? '') === 'verified_portable_cached') {
            $runtimeSource = 'verified_portable_artifact';
        } elseif (str_contains($src, 'service') || ($discovered['channel'] ?? '') === 'local_service') {
            $runtimeSource = 'verified_local_service_binary';
        } elseif (str_contains($src, 'basedir')) {
            $runtimeSource = 'verified_local_service_binary';
        } else {
            $runtimeSource = 'verified_local_service_binary';
        }
    } elseif ($materializable) {
        $runtimeSource = 'materializable_portable';
    }

    if (!$sourceReady) {
        return [
            'ok' => false,
            'code' => (string) ($discovered['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE),
            'ready_token' => '',
            'binary_available' => false,
            'engine_ready' => false,
            'family' => (string) ($discovered['family'] ?? ($manifest['family'] ?? '')),
            'shadow_db_identity_hash' => '',
            'runtime_source' => 'unavailable',
            'materializable' => false,
            'channel' => (string) ($discovered['channel'] ?? 'none'),
            'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
            'runtime_compatible' => !empty($manifest['sha256_pinned']),
            'manifest' => $manifest,
        ];
    }

    $state = orange_restore_private_engine_load_state($workRoot, $jobId);
    $engineReady = is_array($state)
        && !empty($state['ready'])
        && orange_restore_private_engine_runtime_healthy($workRoot, $jobId);

    if ($engineReady) {
        return [
            'ok' => true,
            'code' => 'ok',
            'ready_token' => 'READY_FOR_CONTROLLED_STEP7_ATTEMPT',
            'binary_available' => true,
            'engine_ready' => true,
            'family' => (string) ($discovered['family'] ?? ($state['family'] ?? '')),
            'shadow_db_identity_hash' => (string) ($state['shadow_db_identity_hash'] ?? ''),
            'runtime_source' => $runtimeSource,
            'materializable' => false,
            'channel' => (string) ($discovered['channel'] ?? ''),
            'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
            'runtime_compatible' => true,
            'manifest' => $manifest,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'ready_token' => ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING,
        'binary_available' => $binaryOk || $materializable,
        'engine_ready' => false,
        'family' => (string) ($discovered['family'] ?? ($manifest['family'] ?? '')),
        'shadow_db_identity_hash' => is_array($state)
            ? (string) ($state['shadow_db_identity_hash'] ?? '')
            : '',
        'runtime_source' => $runtimeSource,
        'materializable' => $materializable,
        'channel' => (string) ($discovered['channel'] ?? ''),
        'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
        'runtime_compatible' => !empty($manifest['sha256_pinned']) || $binaryOk,
        'manifest' => $manifest,
    ];
}

function orange_restore_private_engine_runtime_healthy(string $workRoot, string $jobId): bool
{
    $secrets = orange_restore_private_engine_read_runtime_secrets($workRoot, $jobId);
    if ($secrets === null) {
        return false;
    }
    $host = (string) $secrets['host'];
    if ($host !== '127.0.0.1' && $host !== '::1') {
        return false;
    }
    $port = (int) $secrets['port'];
    if ($port <= 0 || !orange_restore_private_engine_port_open($host, $port)) {
        return false;
    }
    try {
        $dsn = 'mysql:host=' . $host . ';port=' . (string) $port . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $secrets['user'], $secrets['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 3,
        ]);
        $pdo->query('SELECT 1');

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Provision job-owned private engine + restricted runtime user + shadow DB.
 *
 * @return array{ok:bool,code:string,engine_pid:int,ready:bool}
 */
function orange_restore_private_engine_provision(
    string $projectRoot,
    string $workRoot,
    string $jobId,
    string $shadowDb
): array {
    if ($shadowDb === '' || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $shadowDb)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    // Freeze guard for this coding task: refuse accidental production-host launch when flagged.
    if (!empty($GLOBALS['orange_restore_private_engine_forbid_launch'])) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    if (orange_restore_private_engine_runtime_healthy($workRoot, $jobId)) {
        $state = orange_restore_private_engine_load_state($workRoot, $jobId) ?? [];
        try {
            orange_restore_private_engine_ensure_shadow_schema($workRoot, $jobId, $shadowDb);
            $state['ready'] = true;
            $state['shadow_db_identity_hash'] = function_exists('orange_restore_shadow_target_identity_hash')
                ? orange_restore_shadow_target_identity_hash($shadowDb, $jobId)
                : hash('sha256', strtolower($shadowDb) . '|' . $jobId);
            orange_restore_private_engine_write_state($workRoot, $jobId, $state);
        } catch (Throwable $e) {
            $code = trim($e->getMessage());
            if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
                $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED;
            }

            return ['ok' => false, 'code' => $code, 'engine_pid' => 0, 'ready' => false];
        }

        return [
            'ok' => true,
            'code' => 'ok',
            'engine_pid' => (int) ($state['engine_pid'] ?? 0),
            'ready' => true,
        ];
    }

    // Authoritative resolve WITH materialize when needed (never Production MySQL).
    $discovered = orange_restore_private_engine_resolve_runtime($projectRoot, true);
    if (!($discovered['ok'] ?? false)) {
        $code = (string) ($discovered['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE);
        if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE;
        }

        return [
            'ok' => false,
            'code' => $code,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    $root = orange_restore_private_engine_root($workRoot, $jobId);
    foreach (['data', 'tmp', 'run'] as $sub) {
        $dir = $root . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
                'engine_pid' => 0,
                'ready' => false,
            ];
        }
    }

    // Assert private root is outside web project tree.
    try {
        $projectReal = realpath($projectRoot) ?: $projectRoot;
        $rootReal = realpath($root) ?: $root;
        $pn = strtolower(str_replace('\\', '/', $projectReal));
        $rn = strtolower(str_replace('\\', '/', $rootReal));
        if ($rn === $pn || str_starts_with($rn, $pn . '/')) {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED,
                'engine_pid' => 0,
                'ready' => false,
            ];
        }
    } catch (Throwable) {
        // continue with work-root assert already applied
    }

    $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
    $tmpDir = $root . DIRECTORY_SEPARATOR . 'tmp';
    $errorLog = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG;
    $pidFile = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE;
    $mysqld = (string) $discovered['mysqld'];
    $mysql = (string) $discovered['mysql'];
    $basedir = (string) $discovered['basedir'];
    $family = (string) $discovered['family'];

    $mysqlSystem = $dataDir . DIRECTORY_SEPARATOR . 'mysql';
    if (!is_dir($mysqlSystem)) {
        $init = orange_restore_private_engine_run_capture($mysqld, [
            '--initialize-insecure',
            '--basedir=' . escapeshellarg($basedir),
            '--datadir=' . escapeshellarg($dataDir),
        ]);
        if ($init['exit_code'] !== 0 || !is_dir($mysqlSystem)) {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
                'engine_pid' => 0,
                'ready' => false,
            ];
        }
    }

    $port = orange_restore_private_engine_allocate_loopback_port();
    $adminUser = 'pse_admin';
    $adminPass = orange_restore_private_engine_random_secret(32);
    $runtimeUser = 'pse_shadow';
    $runtimePass = orange_restore_private_engine_random_secret(32);

    // Bootstrap with networking disabled where supported, then open loopback-only.
    $bootstrapArgs = [
        '--basedir=' . escapeshellarg($basedir),
        '--datadir=' . escapeshellarg($dataDir),
        '--tmpdir=' . escapeshellarg($tmpDir),
        '--pid-file=' . escapeshellarg($pidFile),
        '--log-error=' . escapeshellarg($errorLog),
        '--port=' . (string) $port,
        '--bind-address=127.0.0.1',
        '--mysqlx=0',
        '--skip-log-bin',
        '--skip-replica-start',
        '--symbolic-links=0',
    ];
    // skip-networking conflicts with TCP bootstrap on some builds; keep bind-address loopback-only.

    try {
        $enginePid = orange_restore_private_engine_spawn_daemon($mysqld, $bootstrapArgs, $errorLog);
    } catch (Throwable) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    $deadline = time() + 60;
    $up = false;
    while (time() < $deadline) {
        if (orange_restore_private_engine_port_open('127.0.0.1', $port)) {
            $up = true;
            break;
        }
        usleep(300000);
    }
    if (!$up) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED,
            'engine_pid' => $enginePid,
            'ready' => false,
        ];
    }

    $bootOpt = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_BOOTSTRAP_OPT;
    try {
        // Bootstrap as insecure root via localhost/127.0.0.1 (initialize-insecure).
        $pdoBoot = null;
        $bootHosts = ['127.0.0.1', 'localhost'];
        $lastBootErr = null;
        foreach ($bootHosts as $bootHost) {
            try {
                $pdoBoot = new PDO(
                    'mysql:host=' . $bootHost . ';port=' . (string) $port . ';charset=utf8mb4',
                    'root',
                    '',
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                break;
            } catch (Throwable $e) {
                $lastBootErr = $e;
                $pdoBoot = null;
            }
        }
        if (!$pdoBoot instanceof PDO) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED);
        }
        unset($lastBootErr);
        orange_restore_private_engine_bootstrap_users_pdo(
            $pdoBoot,
            $adminUser,
            $adminPass,
            $runtimeUser,
            $runtimePass,
            $shadowDb
        );
        // Optional: also persist a hardened admin option file for recovery (ACL, never browser).
        orange_restore_private_engine_write_option_file($bootOpt, [
            'user' => $adminUser,
            'password' => $adminPass,
            'host' => '127.0.0.1',
            'port' => (string) $port,
        ]);
        @unlink($bootOpt);

        $secretPath = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_SECRET_FILE;
        orange_restore_private_engine_write_option_file($secretPath, [
            'user' => $runtimeUser,
            'password' => $runtimePass,
            'host' => '127.0.0.1',
            'port' => (string) $port,
        ]);

        // Verify runtime user can use shadow DB only on loopback.
        $pdoRt = new PDO(
            'mysql:host=127.0.0.1;port=' . (string) $port . ';dbname=' . $shadowDb . ';charset=utf8mb4',
            $runtimeUser,
            $runtimePass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdoRt->query('SELECT 1');

        $pidFromFile = orange_restore_private_engine_read_pid_file($pidFile);
        $identity = function_exists('orange_restore_shadow_target_identity_hash')
            ? orange_restore_shadow_target_identity_hash($shadowDb, $jobId)
            : hash('sha256', strtolower($shadowDb) . '|' . $jobId);

        orange_restore_private_engine_write_state($workRoot, $jobId, [
            'ready' => true,
            'engine_pid' => $pidFromFile > 0 ? $pidFromFile : $enginePid,
            'loopback_only' => true,
            'port_bound' => true,
            'datadir_job_owned' => true,
            'family' => $family,
            'shadow_db_identity_hash' => $identity,
            'runtime_user_restricted' => true,
            'provisioned_at' => gmdate('c'),
            // Internal-only port retained in side channel file, not state JSON for browser.
        ]);
        // Persist port for worker reconnect without exposing via public state.
        $portFile = $root . DIRECTORY_SEPARATOR . '.engine_port';
        file_put_contents($portFile, (string) $port . "\n", LOCK_EX);
        orange_restore_private_engine_harden_secret_file($portFile);

        return [
            'ok' => true,
            'code' => 'ok',
            'engine_pid' => $pidFromFile > 0 ? $pidFromFile : $enginePid,
            'ready' => true,
        ];
    } catch (Throwable $e) {
        @unlink($bootOpt);
        $code = trim($e->getMessage());
        if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_')) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED;
        }

        return [
            'ok' => false,
            'code' => $code,
            'engine_pid' => $enginePid,
            'ready' => false,
        ];
    }
}

/**
 * @param PDO $pdo root/admin connection on private engine
 */
function orange_restore_private_engine_bootstrap_users_pdo(
    PDO $pdo,
    string $adminUser,
    string $adminPass,
    string $runtimeUser,
    string $runtimePass,
    string $shadowDb
): void {
    $quotedShadow = '`' . str_replace('`', '``', $shadowDb) . '`';
    $pdo->exec(
        'CREATE DATABASE IF NOT EXISTS ' . $quotedShadow
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $hosts = ['127.0.0.1', 'localhost'];
    foreach ($hosts as $host) {
        foreach (
            [
                [$adminUser, $adminPass],
                [$runtimeUser, $runtimePass],
            ] as [$u, $p]
        ) {
            try {
                $pdo->exec("CREATE USER '{$u}'@'{$host}' IDENTIFIED BY '{$p}'");
            } catch (Throwable) {
                try {
                    $pdo->exec("ALTER USER '{$u}'@'{$host}' IDENTIFIED BY '{$p}'");
                } catch (Throwable) {
                    // continue — other host may still succeed
                }
            }
        }
        try {
            $pdo->exec("GRANT ALL PRIVILEGES ON *.* TO '{$adminUser}'@'{$host}' WITH GRANT OPTION");
        } catch (Throwable) {
            // ignore host-specific grant miss
        }
        try {
            $pdo->exec("GRANT ALL PRIVILEGES ON {$quotedShadow}.* TO '{$runtimeUser}'@'{$host}'");
        } catch (Throwable) {
            // ignore host-specific grant miss
        }
    }
    $pdo->exec('FLUSH PRIVILEGES');
}

function orange_restore_private_engine_ensure_shadow_schema(
    string $workRoot,
    string $jobId,
    string $shadowDb
): void {
    $secrets = orange_restore_private_engine_read_runtime_secrets($workRoot, $jobId);
    if ($secrets === null) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY);
    }
    // Prefer admin via separate bootstrap if needed; runtime user may already have schema.
    $dsn = 'mysql:host=127.0.0.1;port=' . (string) $secrets['port'] . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, $secrets['user'], $secrets['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $st = $pdo->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ? LIMIT 1');
        $st->execute([$shadowDb]);
        if ((string) ($st->fetchColumn() ?: '') !== '') {
            return;
        }
    } catch (Throwable) {
        // fall through
    }
    throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY);
}

/**
 * Connection credentials for Step-7 import when private engine is ready.
 *
 * @return array{ok:bool,code:string,host:string,port:int,user:string,pass:string,mode:string}
 */
function orange_restore_private_engine_connection_credentials(string $workRoot, string $jobId): array
{
    if (!orange_restore_private_engine_runtime_healthy($workRoot, $jobId)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
            'host' => '',
            'port' => 0,
            'user' => '',
            'pass' => '',
            'mode' => '',
        ];
    }
    $secrets = orange_restore_private_engine_read_runtime_secrets($workRoot, $jobId);
    if ($secrets === null) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY,
            'host' => '',
            'port' => 0,
            'user' => '',
            'pass' => '',
            'mode' => '',
        ];
    }
    $host = (string) $secrets['host'];
    if ($host !== '127.0.0.1' && $host !== '::1') {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED,
            'host' => '',
            'port' => 0,
            'user' => '',
            'pass' => '',
            'mode' => '',
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'host' => $host,
        'port' => (int) $secrets['port'],
        'user' => (string) $secrets['user'],
        'pass' => (string) $secrets['password'],
        'mode' => 'private_shadow_engine',
    ];
}

/**
 * Public-safe readiness snapshot (no secrets/paths/ports).
 *
 * @return array<string, mixed>
 */
function orange_restore_private_engine_public_readiness(
    string $projectRoot,
    string $workRoot,
    string $jobId
): array {
    $pre = orange_restore_private_engine_preflight($projectRoot, $workRoot, $jobId);
    $manifest = is_array($pre['manifest'] ?? null) ? $pre['manifest'] : [];

    return [
        'binary_available' => !empty($pre['binary_available']),
        'engine_ready' => !empty($pre['engine_ready']),
        'ready_token' => (string) ($pre['ready_token'] ?? ''),
        'code' => (string) ($pre['code'] ?? ''),
        'family' => (string) ($pre['family'] ?? ''),
        'loopback_only' => true,
        'datadir_job_owned' => true,
        'no_production_mysql_provisioning' => true,
        'shadow_db_identity_hash' => (string) ($pre['shadow_db_identity_hash'] ?? ''),
        'read_only_diagnostic' => true,
        'runtime_source' => (string) ($pre['runtime_source'] ?? 'unavailable'),
        'runtime_verified' => in_array((string) ($pre['runtime_source'] ?? ''), [
            'verified_portable_artifact',
            'verified_local_service_binary',
        ], true) || !empty($pre['engine_ready']),
        'runtime_compatible' => !empty($pre['runtime_compatible']),
        'materializable' => !empty($pre['materializable']),
        'channel' => (string) ($pre['channel'] ?? 'none'),
        'db_host_category' => (string) ($pre['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
        'runtime_vendor' => (string) ($manifest['vendor'] ?? ''),
        'runtime_version' => (string) ($manifest['version'] ?? ''),
        'sha256_pinned' => !empty($manifest['sha256_pinned']),
    ];
}
