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
const ORANGE_RESTORE_PRIVATE_ENGINE_INIT_LEDGER_FILE = 'engine_init_ledger.json';
const ORANGE_RESTORE_PRIVATE_ENGINE_SECRET_FILE = '.engine_runtime.opt';
const ORANGE_RESTORE_PRIVATE_ENGINE_BOOTSTRAP_OPT = '.engine_bootstrap.opt';
const ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG = 'mysqld_private.err';
const ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE = 'mysqld_private.pid';
const ORANGE_RESTORE_PRIVATE_ENGINE_RECORD_VERSION = 'step7-private-engine-v1';
const ORANGE_RESTORE_PRIVATE_ENGINE_INIT_LEDGER_VERSION = 'step7-private-init-ledger-v1';

/** Owner-safe Step-7 private engine codes (no path/port/db/password exposure). */
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE = 'STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED = 'STEP7_PRIVATE_ENGINE_INIT_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED = 'STEP7_PRIVATE_ENGINE_MKDIR_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL = 'STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED = 'STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE = 'STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED = 'STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED = 'STEP7_PRIVATE_ENGINE_START_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED = 'STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED = 'STEP7_PRIVATE_ENGINE_PROVISION_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY = 'STEP7_PRIVATE_ENGINE_NOT_READY';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED = 'STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED = 'STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE = 'STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE = 'STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_ARTIFACT_UNREACHABLE = 'STEP7_PRIVATE_RUNTIME_ARTIFACT_UNREACHABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_CHECKSUM_FAILED = 'STEP7_PRIVATE_RUNTIME_CHECKSUM_FAILED';
const ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE = 'STEP7_PRIVATE_RUNTIME_INCOMPATIBLE';
const ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY = 'STEP7_PRIVATE_TOOLS_ROOT_NOT_READY';
const ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE = 'STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE';
const ORANGE_RESTORE_STEP7_PRIVATE_OWNERSHIP_CONFLICT = 'STEP7_PRIVATE_OWNERSHIP_CONFLICT';
const ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH = 'STEP7_PARENT_WORKER_IDENTITY_MISMATCH';
const ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN = 'STEP7_PRIVATE_READINESS_UNKNOWN';
const ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED = 'STEP7_NOT_READY_MUTATION_REJECTED';
const ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE = 'STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE';
const ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE = 'STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE';

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
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED => 'تعذر إنشاء مجلدات محرك قاعدة الظل الخاص. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL => 'مجلد بيانات محرك الظل الخاص جزئي وغير مكتمل. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED => 'مجلد بيانات محرك الظل الخاص غير مملوك لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE => 'سجل تهيئة محرك الظل الخاص غير متاح للتصنيف. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED => 'فشلت تهيئة بيانات محرك قاعدة الظل الخاص. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED => 'تعذر تشغيل محرك قاعدة الظل الخاص. لم يبدأ الاستيراد.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_SECRET_BOUNDARY_FAILED => 'تعذر تأمين أسرار محرك قاعدة الظل الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED => 'تعذر تجهيز محرك قاعدة الظل الخاص لهذه المهمة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY => 'محرك قاعدة الظل الخاص غير جاهز بعد. حدّث الحالة ثم أعد المحاولة من نفس الخطوة.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NETWORK_POLICY_FAILED => 'تعذر تطبيق سياسة الشبكة المحلية لمحرك قاعدة الظل الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED => 'تعذر تجهيز مستخدم التشغيل المقيّد لقاعدة الظل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_SUPPLY_FAILED => 'تعذر توريد محرك قاعدة الظل المحمول الموثوق. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHECKSUM_FAILED => 'فشل التحقق من سلامة حزمة محرك قاعدة الظل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE => 'لا تتوفر قناة آمنة لتوريد محرك قاعدة الظل على هذا الخادم. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE => 'مصدر محرك قاعدة الظل الخاص غير متاح حالياً. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_ARTIFACT_UNREACHABLE => 'تعذر الوصول إلى حزمة محرك قاعدة الظل المحمولة الموثوقة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_CHECKSUM_FAILED => 'فشل التحقق من سلامة حزمة محرك قاعدة الظل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE => 'حزمة محرك قاعدة الظل غير متوافقة مع هذا الخادم. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY => 'مجلد أدوات محرك الظل الخاص غير جاهز أو غير قابل للكتابة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE => 'تعذر تشغيل عملية محرك قاعدة الظل الخاص على الخادم. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_OWNERSHIP_CONFLICT => 'تعارض ملكية لمحرك قاعدة الظل الخاص. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PARENT_WORKER_IDENTITY_MISMATCH => 'عدم تطابق هوية الهدف/المحرك بين الأب والعامل. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_PRIVATE_READINESS_UNKNOWN => 'جاهزية محرك قاعدة الظل الخاص غير مؤكدة. حدّث الحالة ثم أعد المحاولة.',
        ORANGE_RESTORE_STEP7_NOT_READY_MUTATION_REJECTED => 'خطوة استعادة قاعدة الظل غير جاهزة للتنفيذ. حدّث الجاهزية ولا تُنشأ محاولة جديدة.',
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE => 'فشل تهيئة سابق لمحرك الظل الخاص ما زال غير محلول. حدّث الحالة قبل إعادة المحاولة.',
        ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE => 'مصدر محرك الظل الخاص غير قابل للتثبيت في سجل المحاولة. لم يبدأ التنفيذ.',
        ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING => 'الجاهزية: يمكن تجهيز محرك قاعدة الظل الخاص عند الضغط على خطوة استعادة قاعدة الظل.',
    ];

    return $map[$safeCode] ?? 'تعذر تجهيز محرك قاعدة الظل الخاص. لم يبدأ التنفيذ.';
}

/**
 * Job-private engine root path (no mkdir — safe for Refresh/readiness).
 */
function orange_restore_private_engine_root_path(string $workRoot, string $jobId): string
{
    $root = orange_restore_fw_job_directory($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME;
    orange_restore_assert_inside_work_root($workRoot, $root);

    return $root;
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
    foreach ($daemonNames as $name) {
        $candidate = $bin . DIRECTORY_SEPARATOR . $name;
        if (is_file($candidate)) {
            $mysqld = $candidate;
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

    // Family must not be inferred from mysqld.exe alone — MariaDB ships mysqld.exe too.
    $family = orange_restore_private_engine_detect_family($basedir, $bin, $mysqld);

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
 * Detect mysql vs mariadb from basedir/bin layout (never guess from mysqld.exe name alone).
 */
function orange_restore_private_engine_detect_family(string $basedir, string $bin, string $mysqldPath = ''): string
{
    $norm = strtolower(str_replace('\\', '/', $basedir . ' ' . $mysqldPath));
    if (str_contains($norm, 'mariadb') || str_contains($norm, 'maria')) {
        return 'mariadb';
    }
    $isWin = PHP_OS_FAMILY === 'Windows';
    $mariaMarkers = $isWin
        ? ['mariadbd.exe', 'mariadb-install-db.exe', 'mysql_install_db.exe', 'mariadb.exe']
        : ['mariadbd', 'mariadb-install-db', 'mysql_install_db', 'mariadb'];
    foreach ($mariaMarkers as $marker) {
        if (is_file($bin . DIRECTORY_SEPARATOR . $marker)) {
            // mysql_install_db alone is ambiguous on some MySQL builds — require another maria marker
            // unless basedir already hinted. Prefer explicit maria binaries.
            if ($marker === 'mysql_install_db.exe' || $marker === 'mysql_install_db') {
                continue;
            }

            return 'mariadb';
        }
    }
    if (is_file($bin . DIRECTORY_SEPARATOR . ($isWin ? 'mysql_install_db.exe' : 'mysql_install_db'))
        && !is_file($bin . DIRECTORY_SEPARATOR . ($isWin ? 'mysqld.exe' : 'mysqld'))) {
        return 'mariadb';
    }

    return 'mysql';
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
            'tools_root_ready' => !empty($probe['tools_root_ready']),
            'https_pinned' => !empty($probe['https_pinned']),
        ]);
    }

    $failCode = (string) ($probe['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE);
    if ($failCode === '' || $failCode === 'ok') {
        $failCode = ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE;
    }
    if (!empty($probe['https_pinned']) && empty($probe['tools_root_ready'])) {
        $failCode = ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY;
    } elseif (empty($probe['https_pinned']) && empty($probe['ok'])) {
        $failCode = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_CHANNEL_UNAVAILABLE;
    }

    return array_merge($base, [
        'materializable' => false,
        'channel' => (string) ($probe['channel'] ?? 'none'),
        'code' => $failCode,
        'tools_root_ready' => !empty($probe['tools_root_ready']),
        'https_pinned' => !empty($probe['https_pinned']),
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
    $path = orange_restore_private_engine_root_path($workRoot, $jobId)
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
 * Atomic init-ledger read (safe metadata only; Refresh-safe).
 *
 * @return array<string, mixed>|null
 */
function orange_restore_private_engine_init_ledger_read(string $workRoot, string $jobId): ?array
{
    $path = orange_restore_private_engine_root_path($workRoot, $jobId)
        . DIRECTORY_SEPARATOR
        . ORANGE_RESTORE_PRIVATE_ENGINE_INIT_LEDGER_FILE;
    if (!is_file($path)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * Atomic init-ledger write (temp + rename; never stores secrets/paths/PIDs/raw logs).
 *
 * @param array<string, mixed> $ledger
 */
function orange_restore_private_engine_init_ledger_write(string $workRoot, string $jobId, array $ledger): void
{
    $root = orange_restore_private_engine_root($workRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
    unset(
        $ledger['password'],
        $ledger['admin_password'],
        $ledger['runtime_password'],
        $ledger['mysqld'],
        $ledger['mysql'],
        $ledger['basedir'],
        $ledger['datadir'],
        $ledger['socket'],
        $ledger['option_file'],
        $ledger['absolute_paths'],
        $ledger['raw_log'],
        $ledger['error_log_raw'],
        $ledger['pid'],
        $ledger['engine_pid'],
        $ledger['port']
    );
    $ledger['record_version'] = ORANGE_RESTORE_PRIVATE_ENGINE_INIT_LEDGER_VERSION;
    $ledger['job_id'] = $jobId;
    $ledger['updated_at'] = gmdate('c');
    $json = json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
    $target = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_INIT_LEDGER_FILE;
    $tmp = $target . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
    }
    if (!@rename($tmp, $target)) {
        @unlink($target);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED);
        }
    }
}

/**
 * Classify job-private datadir ownership/completeness (no path exposure).
 *
 * @return array{state:string,owned:bool,writable:bool,has_mysql_system:bool,entry_count:int}
 */
function orange_restore_private_engine_classify_datadir(string $engineRoot, string $jobId, ?array $state = null): array
{
    $dataDir = $engineRoot . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($engineRoot) || !is_dir($dataDir)) {
        return [
            'state' => 'ABSENT',
            'owned' => false,
            'writable' => is_dir($engineRoot) ? @is_writable($engineRoot) : false,
            'has_mysql_system' => false,
            'entry_count' => 0,
        ];
    }
    $entries = @scandir($dataDir);
    $names = is_array($entries) ? array_values(array_diff($entries, ['.', '..'])) : [];
    $hasMysql = is_dir($dataDir . DIRECTORY_SEPARATOR . 'mysql');
    $ownedMeta = is_array($state) && array_key_exists('datadir_job_owned', $state)
        ? !empty($state['datadir_job_owned'])
        : null;
    // Under job private_shadow_engine root ⇒ owned by construction when jobId valid.
    $ownedByPath = $jobId !== '' && str_contains(
        strtolower(str_replace('\\', '/', $engineRoot)),
        '/' . strtolower($jobId) . '/' . strtolower(ORANGE_RESTORE_PRIVATE_ENGINE_DIRNAME)
    );
    $owned = $ownedMeta === true || ($ownedMeta === null && $ownedByPath);
    $writable = @is_writable($dataDir);

    if ($names === []) {
        return [
            'state' => $owned ? 'EMPTY_OWNED' : 'ABSENT',
            'owned' => $owned,
            'writable' => $writable,
            'has_mysql_system' => false,
            'entry_count' => 0,
        ];
    }
    if ($ownedMeta === false) {
        return [
            'state' => 'UNOWNED',
            'owned' => false,
            'writable' => $writable,
            'has_mysql_system' => $hasMysql,
            'entry_count' => count($names),
        ];
    }
    if (!$owned && !$ownedByPath) {
        return [
            'state' => 'UNKNOWN',
            'owned' => false,
            'writable' => $writable,
            'has_mysql_system' => $hasMysql,
            'entry_count' => count($names),
        ];
    }
    if (!$hasMysql) {
        return [
            'state' => 'PARTIAL_OWNED_CURRENT_ATTEMPT',
            'owned' => true,
            'writable' => $writable,
            'has_mysql_system' => false,
            'entry_count' => count($names),
        ];
    }
    $ready = is_array($state) && !empty($state['ready']);

    return [
        'state' => $ready ? 'READY_OWNED' : 'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
        'owned' => true,
        'writable' => $writable,
        'has_mysql_system' => true,
        'entry_count' => count($names),
    ];
}

/**
 * Quarantine terminal owned partial datadir under proven preconditions only.
 *
 * @return array{ok:bool,code:string,quarantined:bool}
 */
function orange_restore_private_engine_quarantine_partial_datadir(
    string $workRoot,
    string $jobId,
    array $classification
): array {
    $state = (string) ($classification['state'] ?? 'UNKNOWN');
    if (!in_array($state, [
        'PARTIAL_OWNED_CURRENT_ATTEMPT',
        'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
    ], true)) {
        if ($state === 'UNOWNED' || $state === 'UNKNOWN') {
            return [
                'ok' => false,
                'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED,
                'quarantined' => false,
            ];
        }

        return ['ok' => true, 'code' => 'ok', 'quarantined' => false];
    }
    if (empty($classification['owned']) || empty($classification['writable'])) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED,
            'quarantined' => false,
        ];
    }
    if (orange_restore_private_engine_runtime_healthy($workRoot, $jobId)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_OWNERSHIP_CONFLICT,
            'quarantined' => false,
        ];
    }

    $root = orange_restore_private_engine_root($workRoot, $jobId);
    $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        return ['ok' => true, 'code' => 'ok', 'quarantined' => false];
    }
    $stamp = gmdate('Ymd\THis');
    $dest = $root . DIRECTORY_SEPARATOR . 'data.quarantine.' . $stamp;
    if (is_dir($dest)) {
        $dest .= '_' . bin2hex(random_bytes(2));
    }
    if (!@rename($dataDir, $dest)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL,
            'quarantined' => false,
        ];
    }
    if (!@mkdir($dataDir, 0775, true) && !is_dir($dataDir)) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED,
            'quarantined' => false,
        ];
    }

    return ['ok' => true, 'code' => 'ok', 'quarantined' => true];
}

/**
 * Map error-log classification to contract result A/B/C/D (Owner-sanitized).
 *
 * A = present + classified, B = present + unclassified, C = empty, D = absent
 *
 * @return array{result:string,category:string}
 */
function orange_restore_private_engine_init_log_contract(?string $errorLogPath): array
{
    if ($errorLogPath === null || $errorLogPath === '' || !is_file($errorLogPath)) {
        return ['result' => 'D', 'category' => 'error_log_absent'];
    }
    $size = @filesize($errorLogPath);
    if ($size === false) {
        return ['result' => 'D', 'category' => 'error_log_absent'];
    }
    if ((int) $size === 0) {
        return ['result' => 'C', 'category' => 'error_log_empty'];
    }
    if (!is_readable($errorLogPath)) {
        return ['result' => 'B', 'category' => 'error_log_unreadable'];
    }
    if (function_exists('orange_restore_private_engine_trace_classify_error_log')) {
        $cls = orange_restore_private_engine_trace_classify_error_log($errorLogPath);
        $cat = (string) ($cls['category'] ?? 'error_log_present_unclassified');
        if ($cat === 'error_log_present_unclassified' || $cat === 'error_log_unreadable') {
            return ['result' => 'B', 'category' => $cat];
        }
        if ($cat === 'error_log_empty') {
            return ['result' => 'C', 'category' => $cat];
        }
        if ($cat === 'error_log_absent') {
            return ['result' => 'D', 'category' => $cat];
        }

        return ['result' => 'A', 'category' => $cat];
    }
    $raw = strtolower((string) @file_get_contents($errorLogPath));
    if ($raw === '') {
        return ['result' => 'C', 'category' => 'error_log_empty'];
    }
    if (str_contains($raw, 'not empty') || str_contains($raw, 'already exists')) {
        return ['result' => 'A', 'category' => 'datadir_not_empty'];
    }
    if (str_contains($raw, 'permission') || str_contains($raw, 'access is denied')) {
        return ['result' => 'A', 'category' => 'datadir_permission'];
    }
    if (str_contains($raw, 'error')) {
        return ['result' => 'A', 'category' => 'mysqld_error_generic'];
    }

    return ['result' => 'B', 'category' => 'error_log_present_unclassified'];
}

/**
 * Resolve MariaDB bootstrap helper under basedir/bin (install-db).
 */
function orange_restore_private_engine_resolve_install_db(string $basedir): string
{
    $bin = rtrim($basedir, "\\/") . DIRECTORY_SEPARATOR . 'bin';
    $isWin = PHP_OS_FAMILY === 'Windows';
    $names = $isWin
        ? ['mariadb-install-db.exe', 'mysql_install_db.exe']
        : ['mariadb-install-db', 'mysql_install_db'];
    foreach ($names as $name) {
        $p = $bin . DIRECTORY_SEPARATOR . $name;
        if (is_file($p)) {
            return $p;
        }
    }

    return '';
}

/**
 * Family-aware private datadir initialization with mandatory private error log.
 *
 * MySQL: mysqld --initialize-insecure --log-error=…
 * MariaDB: mariadb-install-db / mysql_install_db (mysqld --initialize-insecure is unsupported
 *          on many MariaDB Windows builds and leaves PARTIAL datadir).
 *
 * @return array{exit_code:int,mysql_system:bool,init_log_result:string,init_log_category:string,method:string}
 */
function orange_restore_private_engine_init_with_log(
    string $mysqld,
    string $basedir,
    string $dataDir,
    string $errorLog,
    string $family = ''
): array {
    $logDir = dirname($errorLog);
    if (!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return [
            'exit_code' => 1,
            'mysql_system' => false,
            'init_log_result' => 'D',
            'init_log_category' => 'error_log_absent',
            'method' => 'none',
        ];
    }
    // Ensure log file exists before invoke so ABSENT cannot hide a failed init.
    if (@file_put_contents($errorLog, '', LOCK_EX) === false) {
        return [
            'exit_code' => 1,
            'mysql_system' => false,
            'init_log_result' => 'D',
            'init_log_category' => 'error_log_absent',
            'method' => 'none',
        ];
    }

    $bin = rtrim($basedir, "\\/") . DIRECTORY_SEPARATOR . 'bin';
    if ($family === '') {
        $family = orange_restore_private_engine_detect_family($basedir, $bin, $mysqld);
    }

    $method = 'mysql_initialize_insecure';
    $init = ['exit_code' => 1, 'output' => ''];
    if ($family === 'mariadb') {
        $installDb = orange_restore_private_engine_resolve_install_db($basedir);
        if ($installDb === '') {
            @file_put_contents(
                $errorLog,
                "init_helper_absent family=mariadb\n",
                FILE_APPEND | LOCK_EX
            );
            $contract = orange_restore_private_engine_init_log_contract($errorLog);

            return [
                'exit_code' => 1,
                'mysql_system' => false,
                'init_log_result' => (string) $contract['result'],
                'init_log_category' => (string) $contract['category'],
                'method' => 'mariadb_install_db_absent',
            ];
        }
        $method = 'mariadb_install_db';
        // Windows mariadb-install-db accepts --datadir (not --basedir); basedir is implied by exe location.
        // It writes my.ini into CWD — run with cwd=parent(datadir). Omit --password for empty root.
        $args = [
            '--datadir=' . $dataDir,
            '--verbose-bootstrap',
        ];
        if (PHP_OS_FAMILY !== 'Windows') {
            $args[] = '--basedir=' . $basedir;
        }
        $installCwd = dirname($dataDir);
        // Windows install-db writes my.ini into CWD; prefer chdir+exec (avoids proc_open pipe deadlocks).
        $prevCwd = getcwd();
        $init = ['exit_code' => 1, 'output' => ''];
        try {
            if ($installCwd !== '' && is_dir($installCwd)) {
                @chdir($installCwd);
            }
            $init = orange_restore_private_engine_run_capture($installDb, $args);
        } finally {
            if (is_string($prevCwd) && $prevCwd !== '') {
                @chdir($prevCwd);
            }
        }
    } else {
        $args = [
            '--initialize-insecure',
            '--basedir=' . $basedir,
            '--datadir=' . $dataDir,
            '--log-error=' . $errorLog,
        ];
        $init = orange_restore_private_engine_run_capture($mysqld, $args);
    }

    // Append captured stdout/stderr into the private log when the process wrote only to pipe.
    $pipeOut = trim((string) ($init['output'] ?? ''));
    if ($pipeOut !== '') {
        @file_put_contents($errorLog, $pipeOut . "\n", FILE_APPEND | LOCK_EX);
    }
    // Classify unknown-option / wrong-family clearly for Owner-safe category.
    $rawLower = strtolower($pipeOut . ' ' . (string) @file_get_contents($errorLog));
    if (str_contains($rawLower, 'unknown option') && str_contains($rawLower, 'initialize-insecure')) {
        @file_put_contents(
            $errorLog,
            "classified=initialize_insecure_unsupported_for_family\n",
            FILE_APPEND | LOCK_EX
        );
    }
    $contract = orange_restore_private_engine_init_log_contract($errorLog);
    if (str_contains($rawLower, 'unknown option') && str_contains($rawLower, 'initialize-insecure')) {
        $contract['category'] = 'initialize_insecure_unsupported';
        $contract['result'] = 'A';
    }
    $mysqlSystem = is_dir($dataDir . DIRECTORY_SEPARATOR . 'mysql');

    return [
        'exit_code' => (int) ($init['exit_code'] ?? 1),
        'mysql_system' => $mysqlSystem,
        'init_log_result' => (string) $contract['result'],
        'init_log_category' => (string) $contract['category'],
        'method' => $method,
    ];
}

/**
 * Persistable runtime_source contract for parent/worker parity.
 *
 * @param array<string, mixed> $discovered
 */
function orange_restore_private_engine_persistable_runtime_source(array $discovered): array
{
    $ok = !empty($discovered['ok']);
    $src = (string) ($discovered['source'] ?? '');
    $channel = (string) ($discovered['channel'] ?? 'none');
    $runtimeSource = 'unavailable';
    if ($ok) {
        if (str_contains($src, 'portable') || $channel === 'verified_portable_cached') {
            $runtimeSource = 'verified_portable_artifact';
        } elseif (str_contains($src, 'service') || $channel === 'local_service' || str_contains($src, 'basedir')) {
            $runtimeSource = 'verified_local_service_binary';
        } else {
            $runtimeSource = 'verified_local_service_binary';
        }
    } elseif (!empty($discovered['materializable'])) {
        $runtimeSource = 'materializable_portable';
    }
    $persistable = in_array($runtimeSource, [
        'verified_portable_artifact',
        'verified_local_service_binary',
    ], true);

    return [
        'runtime_source' => $runtimeSource,
        'channel' => $channel,
        'family' => (string) ($discovered['family'] ?? ''),
        'persistable' => $persistable,
    ];
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
/**
 * Windows mysqld often mis-parses --basedir/--datadir when the path contains spaces.
 * Prefer 8.3 short path, else a space-free junction under the private tools root.
 */
function orange_restore_private_engine_space_safe_basedir(string $projectRoot, string $basedir): string
{
    $basedir = rtrim(str_replace('/', DIRECTORY_SEPARATOR, $basedir), "\\/");
    if ($basedir === '' || !is_dir($basedir)) {
        return $basedir;
    }
    $real = realpath($basedir);
    $source = is_string($real) && $real !== '' ? $real : $basedir;
    if (PHP_OS_FAMILY !== 'Windows' || !str_contains($source, ' ')) {
        return $source;
    }
    $out = [];
    $code = 1;
    @exec('cmd /c for %I in (' . escapeshellarg($source) . ') do @echo %~sI', $out, $code);
    $short = isset($out[0]) ? trim((string) $out[0]) : '';
    $mysqldName = 'mysqld.exe';
    if ($short !== ''
        && !str_contains($short, ' ')
        && is_dir($short)
        && is_file($short . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $mysqldName)
    ) {
        return $short;
    }
    if (!function_exists('orange_restore_private_engine_tools_root')) {
        return $source;
    }
    try {
        $tools = orange_restore_private_engine_tools_root($projectRoot);
    } catch (Throwable) {
        return $source;
    }
    if ($tools === '' || !is_dir($tools)) {
        return $source;
    }
    $link = rtrim($tools, "\\/")
        . DIRECTORY_SEPARATOR
        . 'basedir_ns_'
        . substr(hash('sha256', strtolower($source)), 0, 16);
    if (!is_dir($link)) {
        @exec(
            'cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($source),
            $linkOut,
            $linkCode
        );
    }
    if (is_dir($link) && is_file($link . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $mysqldName)) {
        return $link;
    }

    return $source;
}

/**
 * Quote one CLI token for exec()/shell without corrupting --key=value paths.
 * Windows escapeshellarg() can break MariaDB option parsers (--basedir="X" → unknown variable).
 */
function orange_restore_private_engine_shell_quote(string $token): string
{
    if ($token === '') {
        return '""';
    }
    // Already a --flag=value with no whitespace → leave intact (paths without spaces).
    if (preg_match('/^--[A-Za-z0-9_-]+=\S+$/', $token) === 1) {
        return $token;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        // Escape embedded double-quotes; wrap when spaces/specials present.
        if (preg_match('/[\s&<>|^()%]/', $token) === 1) {
            return '"' . str_replace('"', '""', $token) . '"';
        }

        return $token;
    }

    return escapeshellarg($token);
}

/**
 * @param list<string> $args
 * @return array{exit_code:int,output:string}
 */
function orange_restore_private_engine_run_capture(
    string $binary,
    array $args,
    ?string $defaultsFile = null,
    ?string $cwd = null
): array {
    $cmd = [orange_restore_private_engine_shell_quote($binary)];
    if ($defaultsFile !== null && $defaultsFile !== '') {
        $cmd[] = '--defaults-extra-file=' . orange_restore_private_engine_shell_quote($defaultsFile);
    }
    foreach ($args as $arg) {
        $arg = (string) $arg;
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            $eq = strpos($arg, '=');
            $key = substr($arg, 0, $eq + 1);
            $val = substr($arg, $eq + 1);
            // Strip a single layer of shell quotes accidentally applied by callers.
            if (strlen($val) >= 2) {
                $q0 = $val[0];
                $q1 = $val[strlen($val) - 1];
                if (($q0 === '"' && $q1 === '"') || ($q0 === "'" && $q1 === "'")) {
                    $val = substr($val, 1, -1);
                }
            }
            $cmd[] = $key . (preg_match('/[\s&<>|^()%]/', $val) === 1
                ? orange_restore_private_engine_shell_quote($val)
                : $val);
            continue;
        }
        $cmd[] = orange_restore_private_engine_shell_quote($arg);
    }
    $command = implode(' ', $cmd);
    $out = [];
    $code = 1;
    if ($cwd !== null && $cwd !== '' && is_dir($cwd)) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            $command,
            $descriptors,
            $pipes,
            $cwd,
            null,
            PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => false] : []
        );
        if (is_resource($proc)) {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
            $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            $code = (int) proc_close($proc);
            $merged = trim($stdout . ((trim($stderr) !== '') ? ("\n" . $stderr) : ''));
            if ($merged === '') {
                $out = [];
            } else {
                $split = preg_split('/\r\n|\n|\r/', $merged);
                $out = is_array($split) ? $split : [];
            }
        } else {
            @exec($command . ' 2>&1', $out, $code);
        }
    } else {
        @exec($command . ' 2>&1', $out, $code);
    }

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
    $normalizedArgs = [];
    foreach ($args as $arg) {
        $arg = (string) $arg;
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            $eq = strpos($arg, '=');
            $key = substr($arg, 0, $eq + 1);
            $val = substr($arg, $eq + 1);
            if (strlen($val) >= 2) {
                $q0 = $val[0];
                $q1 = $val[strlen($val) - 1];
                if (($q0 === '"' && $q1 === '"') || ($q0 === "'" && $q1 === "'")) {
                    $val = substr($val, 1, -1);
                }
            }
            $normalizedArgs[] = $key . $val;
            continue;
        }
        $normalizedArgs[] = $arg;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        // True detach via a temporary PowerShell script (avoids nested quoting breakage on spaced paths).
        $psArgItems = [];
        foreach ($normalizedArgs as $a) {
            $psArgItems[] = "'" . str_replace("'", "''", $a) . "'";
        }
        $psBody = '$p = Start-Process -FilePath '
            . "'" . str_replace("'", "''", $mysqld) . "'"
            . ' -ArgumentList @(' . implode(',', $psArgItems) . ')'
            . ' -WindowStyle Hidden -PassThru' . "\r\n"
            . 'if ($null -eq $p) { exit 1 }' . "\r\n"
            . 'Write-Output $p.Id' . "\r\n"
            . 'exit 0' . "\r\n";
        $psFile = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR
            . 'orange_pse_spawn_' . bin2hex(random_bytes(4)) . '.ps1';
        if (@file_put_contents($psFile, $psBody) === false) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED);
        }
        $launchOut = [];
        $launchCode = 1;
        try {
            @exec(
                'powershell -NoProfile -ExecutionPolicy Bypass -File ' . escapeshellarg($psFile),
                $launchOut,
                $launchCode
            );
        } finally {
            @unlink($psFile);
        }
        if ($launchCode !== 0) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED);
        }
        $pid = isset($launchOut[0]) ? (int) trim((string) $launchOut[0]) : 0;

        return $pid > 0 ? $pid : 0;
    }

    $cmdParts = [orange_restore_private_engine_shell_quote($mysqld)];
    foreach ($normalizedArgs as $arg) {
        if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
            $eq = strpos($arg, '=');
            $key = substr($arg, 0, $eq + 1);
            $val = substr($arg, $eq + 1);
            $cmdParts[] = $key . (preg_match('/[\s&<>|^()%]/', $val) === 1
                ? orange_restore_private_engine_shell_quote($val)
                : $val);
            continue;
        }
        $cmdParts[] = orange_restore_private_engine_shell_quote($arg);
    }
    $command = implode(' ', $cmdParts);
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
 * Whether this PHP SAPI can spawn a private engine child process (zero-mutation check).
 */
function orange_restore_private_engine_process_execution_available(): bool
{
    if (isset($GLOBALS['orange_restore_private_engine_process_execution_override'])) {
        return (bool) $GLOBALS['orange_restore_private_engine_process_execution_override'];
    }
    if (!function_exists('proc_open')) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        return true;
    }

    return is_executable('/bin/sh') || is_executable('/usr/bin/env');
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
 *   tools_root_ready:bool,
 *   process_execution_available:bool,
 *   private_capability:string,
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
    $toolsProbe = orange_restore_private_engine_tools_root_probe($projectRoot);
    $toolsReady = !empty($toolsProbe['ok']) || !empty($discovered['tools_root_ready']);
    $procOk = orange_restore_private_engine_process_execution_available();
    $runtimeCompatible = !empty($manifest['sha256_pinned']) || $binaryOk;
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

    $baseFail = [
        'ok' => false,
        'ready_token' => '',
        'binary_available' => false,
        'engine_ready' => false,
        'family' => (string) ($discovered['family'] ?? ($manifest['family'] ?? '')),
        'shadow_db_identity_hash' => '',
        'runtime_source' => 'unavailable',
        'materializable' => false,
        'channel' => (string) ($discovered['channel'] ?? 'none'),
        'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
        'runtime_compatible' => $runtimeCompatible,
        'tools_root_ready' => $toolsReady,
        'process_execution_available' => $procOk,
        'private_capability' => 'unavailable',
        'manifest' => $manifest,
    ];

    if (!$runtimeCompatible) {
        return array_merge($baseFail, [
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_INCOMPATIBLE,
        ]);
    }
    if (!$procOk) {
        return array_merge($baseFail, [
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_PROCESS_EXECUTION_UNAVAILABLE,
            'runtime_source' => $runtimeSource !== 'unavailable' ? $runtimeSource : 'unavailable',
        ]);
    }
    if (!$sourceReady) {
        $code = (string) ($discovered['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE);
        if ($code === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_UNAVAILABLE;
        }

        return array_merge($baseFail, [
            'code' => $code,
            'channel' => (string) ($discovered['channel'] ?? 'none'),
            'tools_root_ready' => $toolsReady,
        ]);
    }
    if ($materializable && !$binaryOk && !$toolsReady) {
        return array_merge($baseFail, [
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_TOOLS_ROOT_NOT_READY,
            'runtime_source' => 'materializable_portable',
            'materializable' => false,
            'channel' => (string) ($discovered['channel'] ?? 'pinned_https_first_use'),
        ]);
    }

    $state = orange_restore_private_engine_load_state($workRoot, $jobId);
    $ledger = orange_restore_private_engine_init_ledger_read($workRoot, $jobId);
    $engineReady = is_array($state)
        && !empty($state['ready'])
        && orange_restore_private_engine_runtime_healthy($workRoot, $jobId);

    if ($engineReady) {
        $persistedSource = is_array($state)
            ? (string) ($state['runtime_source'] ?? $runtimeSource)
            : $runtimeSource;

        return [
            'ok' => true,
            'code' => 'ok',
            'ready_token' => 'READY_FOR_CONTROLLED_STEP7_ATTEMPT',
            'binary_available' => true,
            'engine_ready' => true,
            'family' => (string) ($discovered['family'] ?? ($state['family'] ?? '')),
            'shadow_db_identity_hash' => (string) ($state['shadow_db_identity_hash'] ?? ''),
            'runtime_source' => $persistedSource,
            'materializable' => false,
            'channel' => (string) ($discovered['channel'] ?? ($state['runtime_channel'] ?? '')),
            'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
            'runtime_compatible' => true,
            'tools_root_ready' => $toolsReady,
            'process_execution_available' => true,
            'private_capability' => 'available',
            'manifest' => $manifest,
            'init_ledger_phase' => is_array($ledger) ? (string) ($ledger['phase'] ?? 'READY') : 'READY',
        ];
    }

    // Authoritative readiness: never false-green on UNOWNED/UNKNOWN or non-persistable source.
    // Owned partial datadir may be green only when quarantine preconditions are proven (recovery path).
    $engineRoot = orange_restore_private_engine_root_path($workRoot, $jobId);
    $datadirClass = orange_restore_private_engine_classify_datadir($engineRoot, $jobId, $state);
    $datadirState = (string) ($datadirClass['state'] ?? 'ABSENT');
    $unresolvedInit = is_array($ledger)
        && !empty($ledger['terminal_failure'])
        && empty($ledger['resolved']);
    $persistContract = orange_restore_private_engine_persistable_runtime_source(
        $binaryOk ? $discovered : array_merge($discovered, ['ok' => false, 'materializable' => $materializable])
    );
    $sourceOkForGreen = $persistContract['persistable']
        || ($runtimeSource === 'materializable_portable' && $materializable && $toolsReady);

    $ownedPartial = in_array($datadirState, [
        'PARTIAL_OWNED_CURRENT_ATTEMPT',
        'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
    ], true);
    $quarantineReady = $ownedPartial
        && !empty($datadirClass['owned'])
        && !empty($datadirClass['writable'])
        && !orange_restore_private_engine_runtime_healthy($workRoot, $jobId);
    $hardBlock = in_array($datadirState, ['UNOWNED', 'UNKNOWN'], true)
        || !$sourceOkForGreen
        || ($unresolvedInit && $ownedPartial && !$quarantineReady)
        || ($unresolvedInit && !$ownedPartial && !in_array($datadirState, ['ABSENT', 'EMPTY_OWNED'], true));

    if ($hardBlock) {
        $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_NOT_READY;
        if ($datadirState === 'UNOWNED' || $datadirState === 'UNKNOWN') {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED;
        } elseif ($ownedPartial && !$quarantineReady) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL;
        } elseif ($unresolvedInit) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_UNRESOLVED_INIT_FAILURE;
        } elseif (!$sourceOkForGreen) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE;
        }

        return array_merge($baseFail, [
            'ok' => false,
            'code' => $code,
            'ready_token' => '',
            'binary_available' => $binaryOk || $materializable,
            'runtime_source' => $runtimeSource,
            'materializable' => $materializable,
            'channel' => (string) ($discovered['channel'] ?? ''),
            'db_host_category' => (string) ($discovered['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
            'runtime_compatible' => true,
            'tools_root_ready' => $toolsReady || $binaryOk,
            'process_execution_available' => true,
            'private_capability' => $materializable && !$binaryOk ? 'materializable' : 'runtime_present',
            'datadir_state' => $datadirState,
            'datadir_recovery_required' => $ownedPartial ? true : false,
            'init_ledger_phase' => is_array($ledger) ? (string) ($ledger['phase'] ?? '') : '',
        ]);
    }

    // Pre-existing false READY: unresolved init + partial WITHOUT quarantine path → blocked above.
    // Owned recoverable partial ⇒ green with recovery_required (provision will quarantine first).
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
        'runtime_compatible' => true,
        'tools_root_ready' => $toolsReady || $binaryOk,
        'process_execution_available' => true,
        'private_capability' => $materializable && !$binaryOk ? 'materializable' : 'runtime_present',
        'manifest' => $manifest,
        'datadir_state' => $datadirState,
        'datadir_recovery_required' => $quarantineReady,
        'init_ledger_phase' => is_array($ledger) ? (string) ($ledger['phase'] ?? 'PREFLIGHT') : 'PREFLIGHT',
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
            $state['datadir_job_owned'] = true;
            $state['loopback_only'] = true;
            $state['shadow_db_identity_hash'] = function_exists('orange_restore_shadow_target_identity_hash')
                ? orange_restore_shadow_target_identity_hash($shadowDb, $jobId)
                : hash('sha256', strtolower($shadowDb) . '|' . $jobId);
            if (empty($state['runtime_source'])) {
                $resolvedQuick = orange_restore_private_engine_resolve_runtime($projectRoot, false);
                $contractQuick = orange_restore_private_engine_persistable_runtime_source($resolvedQuick);
                $state['runtime_source'] = (string) $contractQuick['runtime_source'];
                $state['runtime_channel'] = (string) $contractQuick['channel'];
            }
            orange_restore_private_engine_write_state($workRoot, $jobId, $state);
            orange_restore_private_engine_init_ledger_write($workRoot, $jobId, [
                'phase' => 'READY',
                'terminal_failure' => false,
                'resolved' => true,
                'safe_code' => 'ok',
                'runtime_source' => (string) ($state['runtime_source'] ?? ''),
                'runtime_channel' => (string) ($state['runtime_channel'] ?? ''),
                'datadir_state' => 'READY_OWNED',
            ]);
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
        if (!str_starts_with($code, 'STEP7_PRIVATE_ENGINE_') && !str_starts_with($code, 'STEP7_PRIVATE_RUNTIME_')) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_BINARY_UNAVAILABLE;
        }
        try {
            orange_restore_private_engine_init_ledger_write($workRoot, $jobId, [
                'phase' => 'FAILED',
                'terminal_failure' => true,
                'resolved' => false,
                'safe_code' => $code,
                'runtime_source' => 'unavailable',
            ]);
        } catch (Throwable) {
            // fail closed on provision result even if ledger write fails
        }

        return [
            'ok' => false,
            'code' => $code,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    $runtimeContract = orange_restore_private_engine_persistable_runtime_source($discovered);
    if (empty($runtimeContract['persistable'])) {
        try {
            orange_restore_private_engine_init_ledger_write($workRoot, $jobId, [
                'phase' => 'FAILED',
                'terminal_failure' => true,
                'resolved' => false,
                'safe_code' => ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE,
                'runtime_source' => (string) $runtimeContract['runtime_source'],
                'runtime_channel' => (string) $runtimeContract['channel'],
            ]);
        } catch (Throwable) {
        }

        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_RUNTIME_SOURCE_NOT_PERSISTABLE,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    $attemptToken = 'init_' . bin2hex(random_bytes(6));
    $ledgerBase = [
        'phase' => 'PREFLIGHT',
        'attempt_token' => $attemptToken,
        'terminal_failure' => false,
        'resolved' => false,
        'runtime_source' => (string) $runtimeContract['runtime_source'],
        'runtime_channel' => (string) $runtimeContract['channel'],
        'family' => (string) $runtimeContract['family'],
        'quarantine_performed' => false,
        'init_log_result' => '',
        'init_log_category' => '',
        'safe_code' => '',
        'datadir_state' => '',
    ];
    try {
        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    } catch (Throwable) {
        return [
            'ok' => false,
            'code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED,
            'engine_pid' => 0,
            'ready' => false,
        ];
    }

    $failProvision = static function (
        string $workRoot,
        string $jobId,
        array $ledgerBase,
        string $phase,
        string $code,
        array $extra = []
    ): array {
        $ledger = array_merge($ledgerBase, $extra, [
            'phase' => $phase,
            'terminal_failure' => true,
            'resolved' => false,
            'safe_code' => $code,
        ]);
        try {
            orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledger);
            orange_restore_private_engine_write_state($workRoot, $jobId, [
                'ready' => false,
                'datadir_job_owned' => true,
                'loopback_only' => true,
                'runtime_source' => (string) ($ledger['runtime_source'] ?? ''),
                'runtime_channel' => (string) ($ledger['runtime_channel'] ?? ''),
                'family' => (string) ($ledger['family'] ?? ''),
                'last_safe_code' => $code,
                'init_phase' => $phase,
            ]);
        } catch (Throwable) {
        }

        return [
            'ok' => false,
            'code' => $code,
            'engine_pid' => 0,
            'ready' => false,
        ];
    };

    $root = orange_restore_private_engine_root($workRoot, $jobId);
    $ledgerBase['phase'] = 'MKDIR';
    orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    foreach (['data', 'tmp', 'run'] as $sub) {
        $dir = $root . DIRECTORY_SEPARATOR . $sub;
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $failProvision(
                $workRoot,
                $jobId,
                $ledgerBase,
                'FAILED',
                ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED,
                ['datadir_state' => 'ABSENT']
            );
        }
    }

    // Assert private root is outside web project tree.
    try {
        $projectReal = realpath($projectRoot) ?: $projectRoot;
        $rootReal = realpath($root) ?: $root;
        $pn = strtolower(str_replace('\\', '/', $projectReal));
        $rn = strtolower(str_replace('\\', '/', $rootReal));
        if ($rn === $pn || str_starts_with($rn, $pn . '/')) {
            return $failProvision(
                $workRoot,
                $jobId,
                $ledgerBase,
                'FAILED',
                ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_PROVISION_FAILED
            );
        }
    } catch (Throwable) {
        // continue with work-root assert already applied
    }

    $dataDir = $root . DIRECTORY_SEPARATOR . 'data';
    $tmpDir = $root . DIRECTORY_SEPARATOR . 'tmp';
    $errorLog = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG;
    $pidFile = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_PID_FILE;
    $basedir = orange_restore_private_engine_space_safe_basedir(
        $projectRoot,
        (string) $discovered['basedir']
    );
    $binDir = rtrim($basedir, "\\/") . DIRECTORY_SEPARATOR . 'bin';
    $isWin = PHP_OS_FAMILY === 'Windows';
    $mysqldCand = $binDir . DIRECTORY_SEPARATOR . ($isWin ? 'mysqld.exe' : 'mysqld');
    $mysqlCand = $binDir . DIRECTORY_SEPARATOR . ($isWin ? 'mysql.exe' : 'mysql');
    $mysqld = is_file($mysqldCand) ? $mysqldCand : (string) $discovered['mysqld'];
    $mysql = is_file($mysqlCand) ? $mysqlCand : (string) $discovered['mysql'];
    $family = (string) $discovered['family'];

    $ledgerBase['phase'] = 'CLASSIFY_DATADIR';
    $stateProbe = orange_restore_private_engine_load_state($workRoot, $jobId);
    $datadirClass = orange_restore_private_engine_classify_datadir($root, $jobId, $stateProbe);
    $ledgerBase['datadir_state'] = (string) ($datadirClass['state'] ?? 'UNKNOWN');
    orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);

    if (in_array((string) $datadirClass['state'], ['UNOWNED', 'UNKNOWN'], true)) {
        return $failProvision(
            $workRoot,
            $jobId,
            $ledgerBase,
            'FAILED',
            ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED,
            ['datadir_state' => (string) $datadirClass['state']]
        );
    }

    $mysqlSystem = $dataDir . DIRECTORY_SEPARATOR . 'mysql';
    if (!is_dir($mysqlSystem)
        && in_array((string) $datadirClass['state'], [
            'PARTIAL_OWNED_CURRENT_ATTEMPT',
            'PARTIAL_OWNED_OLDER_TERMINAL_ATTEMPT',
        ], true)
    ) {
        $ledgerBase['phase'] = 'QUARANTINE';
        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
        $q = orange_restore_private_engine_quarantine_partial_datadir($workRoot, $jobId, $datadirClass);
        if (empty($q['ok'])) {
            return $failProvision(
                $workRoot,
                $jobId,
                $ledgerBase,
                'FAILED',
                (string) ($q['code'] ?? ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL),
                ['datadir_state' => (string) $datadirClass['state']]
            );
        }
        $ledgerBase['quarantine_performed'] = !empty($q['quarantined']);
        $datadirClass = orange_restore_private_engine_classify_datadir($root, $jobId, $stateProbe);
        $ledgerBase['datadir_state'] = (string) ($datadirClass['state'] ?? 'EMPTY_OWNED');
        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    }

    if (!is_dir($mysqlSystem)) {
        $ledgerBase['phase'] = 'INITIALIZE';
        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
        // Re-detect family at init time (portable MariaDB often exposes mysqld.exe).
        $family = orange_restore_private_engine_detect_family($basedir, $basedir . DIRECTORY_SEPARATOR . 'bin', $mysqld);
        $init = orange_restore_private_engine_init_with_log($mysqld, $basedir, $dataDir, $errorLog, $family);
        $ledgerBase['init_log_result'] = (string) ($init['init_log_result'] ?? 'D');
        $ledgerBase['init_log_category'] = (string) ($init['init_log_category'] ?? 'error_log_absent');
        $ledgerBase['init_method'] = (string) ($init['method'] ?? '');
        $ledgerBase['family'] = $family;
        if ((int) ($init['exit_code'] ?? 1) !== 0 || empty($init['mysql_system'])) {
            $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED;
            if (($init['init_log_result'] ?? '') === 'D') {
                $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE;
            } elseif (($init['init_log_category'] ?? '') === 'initialize_insecure_unsupported') {
                $code = ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED;
            }

            return $failProvision(
                $workRoot,
                $jobId,
                $ledgerBase,
                'FAILED',
                $code,
                [
                    'init_log_result' => (string) ($init['init_log_result'] ?? 'D'),
                    'init_log_category' => (string) ($init['init_log_category'] ?? 'error_log_absent'),
                    'init_method' => (string) ($init['method'] ?? ''),
                    'datadir_state' => 'PARTIAL_OWNED_CURRENT_ATTEMPT',
                ]
            );
        }
        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    }

    $port = orange_restore_private_engine_allocate_loopback_port();
    $adminUser = 'pse_admin';
    $adminPass = orange_restore_private_engine_random_secret(32);
    $runtimeUser = 'pse_shadow';
    $runtimePass = orange_restore_private_engine_random_secret(32);
    $family = orange_restore_private_engine_detect_family(
        $basedir,
        $basedir . DIRECTORY_SEPARATOR . 'bin',
        $mysqld
    );
    $ledgerBase['family'] = $family;

    // Bootstrap with networking disabled where supported, then open loopback-only.
    $ledgerBase['phase'] = 'START';
    orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    // Keep bootstrap flags conservative: unknown options abort MariaDB/MySQL startup.
    $bootstrapArgs = [
        '--basedir=' . $basedir,
        '--datadir=' . $dataDir,
        '--tmpdir=' . $tmpDir,
        '--pid-file=' . $pidFile,
        '--log-error=' . $errorLog,
        '--port=' . (string) $port,
        '--bind-address=127.0.0.1',
    ];
    if ($family === 'mysql') {
        $bootstrapArgs[] = '--mysqlx=0';
        $bootstrapArgs[] = '--skip-log-bin';
        $bootstrapArgs[] = '--skip-replica-start';
        $bootstrapArgs[] = '--symbolic-links=0';
    } else {
        // MariaDB portable: avoid MySQL-only flags; optional binlog skip when accepted.
        $bootstrapArgs[] = '--skip-log-bin';
    }

    try {
        $enginePid = orange_restore_private_engine_spawn_daemon($mysqld, $bootstrapArgs, $errorLog);
    } catch (Throwable) {
        return $failProvision(
            $workRoot,
            $jobId,
            $ledgerBase,
            'FAILED',
            ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED
        );
    }

    $deadline = time() + 60;
    $up = false;
    $portStable = 0;
    $startedAt = time();
    while (time() < $deadline) {
        $portUp = orange_restore_private_engine_port_open('127.0.0.1', $port);
        $logReady = false;
        if (is_file($errorLog)) {
            $tail = strtolower((string) @file_get_contents($errorLog));
            $logReady = str_contains($tail, 'ready for connections')
                || str_contains($tail, 'server socket created on ip');
        }
        if ($portUp) {
            $portStable++;
        } else {
            $portStable = 0;
        }
        // Prefer log-ready; accept stable port after a few polls (log flush lag).
        if (($portUp && $logReady) || $portStable >= 3) {
            $up = true;
            break;
        }
        // If the daemon exited before becoming ready, fail as start (not bootstrap user).
        if ($enginePid > 0 && PHP_OS_FAMILY === 'Windows' && (time() - $startedAt) >= 5) {
            $probe = [];
            @exec('tasklist /FI "PID eq ' . (string) $enginePid . '" /NH', $probe);
            $alive = false;
            foreach ($probe as $line) {
                if (str_contains((string) $line, (string) $enginePid)) {
                    $alive = true;
                    break;
                }
            }
            if (!$alive) {
                break;
            }
        }
        usleep(300000);
    }
    if (!$up) {
        return $failProvision(
            $workRoot,
            $jobId,
            $ledgerBase,
            'FAILED',
            ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_START_FAILED
        );
    }
    if ($enginePid <= 0) {
        $enginePid = orange_restore_private_engine_read_pid_file($pidFile);
    }

    $ledgerBase['phase'] = 'BOOTSTRAP';
    orange_restore_private_engine_init_ledger_write($workRoot, $jobId, $ledgerBase);
    $bootOpt = $root . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_BOOTSTRAP_OPT;
    try {
        // Bootstrap as insecure root via localhost/127.0.0.1 (initialize-insecure / install-db).
        // MySQL 8.x Windows often rejects empty-root PDO/TCP while the mysql client succeeds —
        // prefer CLI when available; keep PDO as secondary path.
        $bootstrapped = false;
        $bootDeadline = time() + 45;
        while (time() < $bootDeadline && !$bootstrapped) {
            if (is_file($mysql)) {
                $cliBoot = orange_restore_private_engine_bootstrap_users_cli(
                    $mysql,
                    $port,
                    $adminUser,
                    $adminPass,
                    $runtimeUser,
                    $runtimePass,
                    $shadowDb,
                    $tmpDir
                );
                if (!empty($cliBoot['ok'])) {
                    $bootstrapped = true;
                    $ledgerBase['bootstrap_cli_exit'] = 0;
                    break;
                }
                $ledgerBase['bootstrap_cli_exit'] = (int) ($cliBoot['exit_code'] ?? 1);
                $cliOut = strtolower((string) ($cliBoot['output'] ?? ''));
                $cliOut = preg_replace('/password[^\s]*/i', '[secret]', $cliOut) ?? $cliOut;
                $cliOut = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $cliOut) ?? $cliOut;
                $ledgerBase['bootstrap_cli_hint'] = substr(trim($cliOut), 0, 240);
            }
            $pdoBoot = null;
            foreach (['127.0.0.1', 'localhost'] as $bootHost) {
                try {
                    $pdoBoot = new PDO(
                        'mysql:host=' . $bootHost . ';port=' . (string) $port . ';charset=utf8mb4',
                        'root',
                        '',
                        [
                            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_TIMEOUT => 5,
                        ]
                    );
                    $pdoBoot->query('SELECT 1');
                    orange_restore_private_engine_bootstrap_users_pdo(
                        $pdoBoot,
                        $adminUser,
                        $adminPass,
                        $runtimeUser,
                        $runtimePass,
                        $shadowDb
                    );
                    $bootstrapped = true;
                    break 2;
                } catch (Throwable) {
                    $pdoBoot = null;
                }
            }
            usleep(400000);
        }
        if (!$bootstrapped) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED);
        }
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

        // Verify runtime user can use shadow DB only on loopback (retry briefly after grants).
        $pdoRt = null;
        $rtDeadline = time() + 20;
        while (time() < $rtDeadline && !$pdoRt instanceof PDO) {
            try {
                $pdoRt = new PDO(
                    'mysql:host=127.0.0.1;port=' . (string) $port . ';dbname=' . $shadowDb . ';charset=utf8mb4',
                    $runtimeUser,
                    $runtimePass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                $pdoRt->query('SELECT 1');
            } catch (Throwable) {
                $pdoRt = null;
                usleep(300000);
            }
        }
        if (!$pdoRt instanceof PDO) {
            throw new RuntimeException(ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_RUNTIME_USER_FAILED);
        }

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
            'runtime_source' => (string) $runtimeContract['runtime_source'],
            'runtime_channel' => (string) $runtimeContract['channel'],
            'provisioned_at' => gmdate('c'),
            // Internal-only port retained in side channel file, not state JSON for browser.
        ]);
        // Persist port for worker reconnect without exposing via public state.
        $portFile = $root . DIRECTORY_SEPARATOR . '.engine_port';
        file_put_contents($portFile, (string) $port . "\n", LOCK_EX);
        orange_restore_private_engine_harden_secret_file($portFile);

        orange_restore_private_engine_init_ledger_write($workRoot, $jobId, array_merge($ledgerBase, [
            'phase' => 'READY',
            'terminal_failure' => false,
            'resolved' => true,
            'safe_code' => 'ok',
            'datadir_state' => 'READY_OWNED',
        ]));

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
        try {
            orange_restore_private_engine_init_ledger_write($workRoot, $jobId, array_merge($ledgerBase, [
                'phase' => 'FAILED',
                'terminal_failure' => true,
                'resolved' => false,
                'safe_code' => $code,
            ]));
            orange_restore_private_engine_write_state($workRoot, $jobId, [
                'ready' => false,
                'datadir_job_owned' => true,
                'loopback_only' => true,
                'runtime_source' => (string) $runtimeContract['runtime_source'],
                'runtime_channel' => (string) $runtimeContract['channel'],
                'family' => $family,
                'last_safe_code' => $code,
                'init_phase' => 'FAILED',
            ]);
        } catch (Throwable) {
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
 * Escape a string literal for MySQL/MariaDB single-quoted SQL.
 */
function orange_restore_private_engine_sql_quote_literal(string $value): string
{
    return str_replace(["\\", "'", "\0"], ["\\\\", "''", ''], $value);
}

/**
 * Build CREATE DATABASE / USER / GRANT SQL for private-engine bootstrap.
 */
function orange_restore_private_engine_bootstrap_users_sql(
    string $adminUser,
    string $adminPass,
    string $runtimeUser,
    string $runtimePass,
    string $shadowDb
): string {
    $quotedShadow = '`' . str_replace('`', '``', $shadowDb) . '`';
    $sql = 'CREATE DATABASE IF NOT EXISTS ' . $quotedShadow
        . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
    $hosts = ['127.0.0.1', 'localhost'];
    foreach ($hosts as $host) {
        foreach (
            [
                [$adminUser, $adminPass],
                [$runtimeUser, $runtimePass],
            ] as [$u, $p]
        ) {
            $eu = orange_restore_private_engine_sql_quote_literal((string) $u);
            $ep = orange_restore_private_engine_sql_quote_literal((string) $p);
            $eh = orange_restore_private_engine_sql_quote_literal((string) $host);
            $sql .= "CREATE USER IF NOT EXISTS '{$eu}'@'{$eh}' IDENTIFIED BY '{$ep}';\n";
            $sql .= "ALTER USER '{$eu}'@'{$eh}' IDENTIFIED BY '{$ep}';\n";
        }
        $ea = orange_restore_private_engine_sql_quote_literal($adminUser);
        $er = orange_restore_private_engine_sql_quote_literal($runtimeUser);
        $eh = orange_restore_private_engine_sql_quote_literal($host);
        $sql .= "GRANT ALL PRIVILEGES ON *.* TO '{$ea}'@'{$eh}' WITH GRANT OPTION;\n";
        $sql .= "GRANT ALL PRIVILEGES ON {$quotedShadow}.* TO '{$er}'@'{$eh}';\n";
    }
    $sql .= "FLUSH PRIVILEGES;\n";

    return $sql;
}

/**
 * Bootstrap users via mysql/mariadb client (preferred on MySQL 8.x Windows when PDO root TCP fails).
 *
 * @return array{ok:bool,exit_code:int,output:string}
 */
function orange_restore_private_engine_bootstrap_users_cli(
    string $mysqlBin,
    int $port,
    string $adminUser,
    string $adminPass,
    string $runtimeUser,
    string $runtimePass,
    string $shadowDb,
    string $tmpDir
): array {
    if ($mysqlBin === '' || !is_file($mysqlBin) || $port < 1024) {
        return ['ok' => false, 'exit_code' => 1, 'output' => 'mysql_cli_unavailable'];
    }
    if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
        return ['ok' => false, 'exit_code' => 1, 'output' => 'tmpdir_unavailable'];
    }
    $opt = $tmpDir . DIRECTORY_SEPARATOR . '.root_bootstrap.opt';
    $sqlFile = $tmpDir . DIRECTORY_SEPARATOR . '.root_bootstrap.sql';
    // Empty root password must be written explicitly (write_option_file skips empty values).
    $optBody = "[client]\nuser=root\npassword=\nhost=127.0.0.1\nport="
        . (string) $port
        . "\nprotocol=TCP\n";
    if (@file_put_contents($opt, $optBody, LOCK_EX) === false) {
        return ['ok' => false, 'exit_code' => 1, 'output' => 'opt_write_failed'];
    }
    orange_restore_private_engine_harden_secret_file($opt);
    $sql = orange_restore_private_engine_bootstrap_users_sql(
        $adminUser,
        $adminPass,
        $runtimeUser,
        $runtimePass,
        $shadowDb
    );
    if (@file_put_contents($sqlFile, $sql, LOCK_EX) === false) {
        @unlink($opt);

        return ['ok' => false, 'exit_code' => 1, 'output' => 'sql_write_failed'];
    }
    try {
        // Prefer proc_open argv form so Windows paths-with-spaces do not break cmd.exe redirects.
        $cmd = [
            $mysqlBin,
            '--defaults-extra-file=' . $opt,
            '--batch',
            '--force',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            null,
            null,
            PHP_OS_FAMILY === 'Windows' ? ['bypass_shell' => true] : []
        );
        if (!is_resource($proc)) {
            // Fallback: shell redirect (may fail on spaced basedir paths).
            $shell = orange_restore_private_engine_shell_quote($mysqlBin)
                . ' --defaults-extra-file=' . orange_restore_private_engine_shell_quote($opt)
                . ' --batch --force < '
                . orange_restore_private_engine_shell_quote($sqlFile);
            $out = [];
            $code = 1;
            @exec($shell . ' 2>&1', $out, $code);

            return [
                'ok' => $code === 0,
                'exit_code' => (int) $code,
                'output' => implode("\n", $out),
            ];
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fwrite($pipes[0], $sql);
            fclose($pipes[0]);
        }
        $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? (string) stream_get_contents($pipes[1]) : '';
        $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? (string) stream_get_contents($pipes[2]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $code = (int) proc_close($proc);
        $merged = trim($stdout . ((trim($stderr) !== '') ? ("\n" . $stderr) : ''));

        return [
            'ok' => $code === 0,
            'exit_code' => $code,
            'output' => $merged,
        ];
    } finally {
        @unlink($opt);
        @unlink($sqlFile);
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

    $privateCap = (string) ($pre['private_capability'] ?? 'unavailable');
    if ($privateCap === '' || ($privateCap === 'unavailable' && !empty($pre['ok']))) {
        $privateCap = !empty($pre['engine_ready'])
            ? 'available'
            : (!empty($pre['materializable']) ? 'materializable' : (!empty($pre['binary_available']) ? 'runtime_present' : 'unavailable'));
    }

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
            'materializable_portable',
        ], true) || !empty($pre['engine_ready']),
        'runtime_compatible' => !empty($pre['runtime_compatible']),
        'materializable' => !empty($pre['materializable']),
        'channel' => (string) ($pre['channel'] ?? 'none'),
        'db_host_category' => (string) ($pre['db_host_category'] ?? ORANGE_RESTORE_DB_HOST_UNKNOWN),
        'tools_root_ready' => !empty($pre['tools_root_ready']),
        'process_execution_available' => !empty($pre['process_execution_available']),
        'private_capability' => $privateCap,
        'runtime_vendor' => (string) ($manifest['vendor'] ?? ''),
        'runtime_version' => (string) ($manifest['version'] ?? ''),
        'sha256_pinned' => !empty($manifest['sha256_pinned']),
        'datadir_state' => (string) ($pre['datadir_state'] ?? ''),
        'datadir_recovery_required' => !empty($pre['datadir_recovery_required']),
        'init_ledger_phase' => (string) ($pre['init_ledger_phase'] ?? ''),
    ];
}
