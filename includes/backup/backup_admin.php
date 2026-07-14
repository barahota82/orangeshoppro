<?php

declare(strict_types=1);

require_once __DIR__ . '/backup_paths.php';
require_once __DIR__ . '/backup_environment.php';
require_once __DIR__ . '/backup_runner.php';
require_once __DIR__ . '/backup_full.php';
require_once __DIR__ . '/backup_retention.php';
require_once __DIR__ . '/backup_validate.php';
require_once __DIR__ . '/recovery_validation.php';
require_once __DIR__ . '/country_batch_export.php';
require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'admin_permissions.php';

const ORANGE_BACKUP_ADMIN_PACKAGE_ID_PATTERN = '/^\d{4}-\d{2}-\d{2}_\d{6}$/';
const ORANGE_BACKUP_ADMIN_COUNTRY_CODE_PATTERN = '/^[A-Za-z]{2}$/';

/** @var list<string> */
const ORANGE_BACKUP_ADMIN_VIEWABLE_FILES = [
    'manifest.json',
    'health.json',
    'recovery_validation.json',
    'dependency_graph.json',
    'table_inventory.json',
    'checksums.sha256',
];

/** @var list<string> */
const ORANGE_BACKUP_ADMIN_SECRET_KEY_FRAGMENTS = [
    'password',
    'passwd',
    'secret',
    'token',
    'credential',
    'db_pass',
    'db_user',
    'api_key',
    '.env',
];

/** @return list<string> */
function orange_admin_backup_permission_keys(): array
{
    return ['backup_view', 'backup_run', 'backup_verify'];
}

function orange_backup_admin_may_view(array $admin, PDO $pdo): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }
    $matrix = orange_admin_permissions_matrix($pdo, (int) ($admin['id'] ?? 0));
    $row = $matrix['backup_view'] ?? null;

    return is_array($row) && !empty($row['can_view']);
}

function orange_backup_admin_may_run(array $admin, PDO $pdo): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }
    $matrix = orange_admin_permissions_matrix($pdo, (int) ($admin['id'] ?? 0));
    $row = $matrix['backup_run'] ?? null;

    return is_array($row) && !empty($row['can_edit']);
}

function orange_backup_admin_may_verify(array $admin, PDO $pdo): bool
{
    if (orange_admin_is_superuser($admin)) {
        return true;
    }
    $matrix = orange_admin_permissions_matrix($pdo, (int) ($admin['id'] ?? 0));
    $row = $matrix['backup_verify'] ?? null;

    return is_array($row) && !empty($row['can_edit']);
}

function orange_backup_admin_require_view(array $admin, PDO $pdo): void
{
    if (!orange_backup_admin_may_view($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_view permission.');
    }
}

function orange_backup_admin_require_run(array $admin, PDO $pdo): void
{
    if (!orange_backup_admin_may_run($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_run permission.');
    }
}

function orange_backup_admin_require_verify(array $admin, PDO $pdo): void
{
    if (!orange_backup_admin_may_verify($admin, $pdo)) {
        throw new RuntimeException('Operator lacks backup_verify permission.');
    }
}

function orange_backup_admin_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['orange_backup_admin_csrf']) || !is_string($_SESSION['orange_backup_admin_csrf'])) {
        $_SESSION['orange_backup_admin_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['orange_backup_admin_csrf'];
}

function orange_backup_admin_verify_csrf(?string $token): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $expected = (string) ($_SESSION['orange_backup_admin_csrf'] ?? '');
    if ($expected === '' || !is_string($token) || $token === '' || !hash_equals($expected, $token)) {
        throw new RuntimeException('CSRF token validation failed.');
    }
}

function orange_backup_admin_assert_package_id(string $packageId): void
{
    if (!preg_match(ORANGE_BACKUP_ADMIN_PACKAGE_ID_PATTERN, $packageId)) {
        throw new RuntimeException('Invalid package identifier.');
    }
}

function orange_backup_admin_assert_country_code(string $countryCode): void
{
    if (!preg_match(ORANGE_BACKUP_ADMIN_COUNTRY_CODE_PATTERN, $countryCode)) {
        throw new RuntimeException('Invalid country code.');
    }
}

function orange_backup_admin_resolve_backup_root(string $projectRoot): string
{
    $env = orange_backup_load_env_array($projectRoot);

    return orange_backup_resolve_root($env);
}

/**
 * @return array{project_root:string,backup_root:string,env:array<string,mixed>}
 */
function orange_backup_admin_context(string $projectRoot): array
{
    $projectRoot = realpath($projectRoot) ?: $projectRoot;
    $env = orange_backup_load_env_array($projectRoot);
    $backupRoot = orange_backup_resolve_root($env);

    return [
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'env' => $env,
    ];
}

function orange_backup_admin_resolve_full_package_path(string $backupRoot, string $packageId): string
{
    orange_backup_admin_assert_package_id($packageId);
    $path = orange_backup_path_inside_root($backupRoot, 'snapshots' . DIRECTORY_SEPARATOR . $packageId);
    if (!is_dir($path)) {
        throw new RuntimeException('Full backup package not found.');
    }

    return $path;
}

function orange_backup_admin_resolve_country_package_path(string $backupRoot, string $countryCode, string $packageId): string
{
    orange_backup_admin_assert_country_code($countryCode);
    orange_backup_admin_assert_package_id($packageId);
    $countryCode = strtolower($countryCode);
    $path = orange_backup_path_inside_root(
        $backupRoot,
        'country_packages' . DIRECTORY_SEPARATOR . $countryCode . DIRECTORY_SEPARATOR . $packageId
    );
    if (!is_dir($path)) {
        throw new RuntimeException('Country backup package not found.');
    }

    return $path;
}

/**
 * @param array<string, mixed> $data
 * @return array<string, mixed>
 */
function orange_backup_admin_redact_secrets(array $data): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $keyLower = strtolower((string) $key);
        $blocked = false;
        foreach (ORANGE_BACKUP_ADMIN_SECRET_KEY_FRAGMENTS as $fragment) {
            if (str_contains($keyLower, $fragment)) {
                $blocked = true;
                break;
            }
        }
        if ($blocked) {
            continue;
        }
        if (is_array($value)) {
            $out[$key] = orange_backup_admin_redact_secrets($value);
            continue;
        }
        $out[$key] = $value;
    }

    return $out;
}

/**
 * @return array{ok:bool,data:?array<string,mixed>,raw_text:?string,errors:list<string>}
 */
function orange_backup_admin_read_package_file(string $packagePath, string $fileName): array
{
    if (!in_array($fileName, ORANGE_BACKUP_ADMIN_VIEWABLE_FILES, true)) {
        throw new RuntimeException('File type is not allowlisted.');
    }
    $resolvedPackage = realpath($packagePath);
    if ($resolvedPackage === false || !is_dir($resolvedPackage)) {
        throw new RuntimeException('Package path is invalid.');
    }
    $fullPath = $resolvedPackage . DIRECTORY_SEPARATOR . $fileName;
    $resolvedFile = realpath($fullPath);
    if ($resolvedFile === false || !is_file($resolvedFile)) {
        return ['ok' => false, 'data' => null, 'raw_text' => null, 'errors' => ['File not found: ' . $fileName]];
    }
    if (!str_starts_with(str_replace('\\', '/', $resolvedFile), str_replace('\\', '/', $resolvedPackage))) {
        throw new RuntimeException('Path traversal blocked.');
    }
    $raw = file_get_contents($resolvedFile);
    if ($raw === false) {
        return ['ok' => false, 'data' => null, 'raw_text' => null, 'errors' => ['Cannot read file.']];
    }
    if (str_ends_with($fileName, '.json')) {
        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return [
                'ok' => true,
                'data' => orange_backup_admin_redact_secrets($decoded),
                'raw_text' => null,
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'data' => null, 'raw_text' => null, 'errors' => ['Invalid JSON: ' . $e->getMessage()]];
        }
    }

    return ['ok' => true, 'data' => null, 'raw_text' => $raw, 'errors' => []];
}

function orange_backup_admin_dir_size_bytes(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }
    $total = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile()) {
            $total += (int) $fileInfo->getSize();
        }
    }

    return $total;
}

function orange_backup_admin_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
}

/**
 * @return array{held:bool,path:string,meta:?array<string,mixed>}
 */
function orange_backup_admin_full_lock_status(string $backupRoot): array
{
    $path = orange_backup_lock_path($backupRoot);
    if (!is_file($path)) {
        return ['held' => false, 'path' => $path, 'meta' => null];
    }
    $raw = file_get_contents($path);
    $meta = is_string($raw) ? json_decode($raw, true) : null;

    return ['held' => true, 'path' => $path, 'meta' => is_array($meta) ? $meta : null];
}

/**
 * @return array{held:bool,path:string,meta:?array<string,mixed>}
 */
function orange_backup_admin_country_lock_status(string $backupRoot): array
{
    $path = orange_crp_batch_lock_path($backupRoot);
    if (!is_file($path)) {
        return ['held' => false, 'path' => $path, 'meta' => null];
    }
    $raw = file_get_contents($path);
    $meta = is_string($raw) ? json_decode($raw, true) : null;

    return ['held' => true, 'path' => $path, 'meta' => is_array($meta) ? $meta : null];
}

/**
 * @return array<string, mixed>|null
 */
function orange_backup_admin_read_json_if_exists(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : null;
}

/**
 * @return array<string, mixed>
 */
function orange_backup_admin_summarize_full_package(string $packagePath, string $packageId): array
{
    $manifest = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . ORANGE_BACKUP_MANIFEST_FILE);
    $health = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . ORANGE_BACKUP_HEALTH_FILE);
    $recovery = orange_backup_admin_read_json_if_exists(
        $packagePath . DIRECTORY_SEPARATOR . ORANGE_RECOVERY_VALIDATION_REPORT_FILE
    );

    $packageStatus = (string) ($health['package_status'] ?? $manifest['backup_status'] ?? 'unknown');
    $verification = null;
    if (is_array($recovery)) {
        $verification = [
            'overall_result' => (string) ($recovery['overall_result'] ?? ''),
            'recovery_score' => (int) ($recovery['recovery_score'] ?? 0),
            'validated_at' => (string) ($recovery['validated_at'] ?? $recovery['generated_at'] ?? ''),
        ];
    }

    return orange_backup_admin_redact_secrets([
        'package_id' => $packageId,
        'package_type' => 'full_disaster',
        'generated_at' => (string) ($manifest['generated_at'] ?? ''),
        'package_status' => $packageStatus,
        'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'backend' => (string) ($manifest['export_backend'] ?? ''),
        'dump_size_bytes' => (int) ($manifest['dump_size_bytes'] ?? 0),
        'uploads_size_bytes' => (int) ($manifest['uploads_size_bytes'] ?? 0),
        'table_count' => (int) ($manifest['table_count'] ?? 0),
        'approx_total_rows' => (int) ($manifest['approx_total_rows'] ?? 0),
        'recovery_score' => (int) ($recovery['recovery_score'] ?? 0),
        'verification' => $verification,
        'package_path' => $packagePath,
        'healthy' => ($health['package_status'] ?? '') === 'healthy',
    ]);
}

/**
 * @return array<string, mixed>
 */
function orange_backup_admin_summarize_country_package(
    string $packagePath,
    string $packageId,
    string $countryCode,
    ?array $countryMeta = null
): array {
    $manifest = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'manifest.json');
    $health = orange_backup_admin_read_json_if_exists($packagePath . DIRECTORY_SEPARATOR . 'health.json');
    $recovery = orange_backup_admin_read_json_if_exists(
        $packagePath . DIRECTORY_SEPARATOR . ORANGE_RECOVERY_VALIDATION_REPORT_FILE
    );

    $verification = null;
    if (is_array($recovery)) {
        $verification = [
            'overall_result' => (string) ($recovery['overall_result'] ?? ''),
            'recovery_score' => (int) ($recovery['recovery_score'] ?? 0),
            'validated_at' => (string) ($recovery['validated_at'] ?? $recovery['generated_at'] ?? ''),
        ];
    }

    return orange_backup_admin_redact_secrets([
        'package_id' => $packageId,
        'package_type' => 'country_recovery',
        'country_code' => strtoupper($countryCode),
        'country_id' => (int) ($manifest['country_id'] ?? ($countryMeta['id'] ?? 0)),
        'country_name' => (string) ($countryMeta['name_ar'] ?? $countryMeta['name_en'] ?? ''),
        'generated_at' => (string) ($manifest['generated_at'] ?? ''),
        'package_status' => (string) ($health['package_status'] ?? $manifest['backup_status'] ?? 'unknown'),
        'schema_revision' => (int) ($manifest['schema_revision'] ?? 0),
        'registry_version' => (string) ($manifest['registry_version'] ?? ''),
        'row_counts_summary' => $manifest['row_counts_summary'] ?? ($manifest['row_counts'] ?? null),
        'recovery_score' => (int) ($recovery['recovery_score'] ?? 0),
        'verification' => $verification,
        'package_path' => $packagePath,
        'healthy' => ($health['package_status'] ?? '') === 'healthy',
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function orange_backup_admin_list_full_snapshots(string $backupRoot, int $limit = 20): array
{
    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    $dirs = orange_backup_retention_list_finalized_dirs($snapshotsDir);
    $out = [];
    foreach (array_slice($dirs, 0, max(1, $limit)) as $dir) {
        $out[] = orange_backup_admin_summarize_full_package($dir['path'], $dir['name']);
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_backup_admin_list_country_packages(PDO $pdo, string $backupRoot, int $perCountryLimit = 5): array
{
    require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'countries.php';

    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    $codes = orange_backup_retention_list_country_codes($backupRoot);
    $out = [];

    foreach ($codes as $countryCode) {
        $countryMeta = null;
        if (function_exists('orange_country_row_by_code')) {
            $countryMeta = orange_country_row_by_code($pdo, strtoupper($countryCode), false);
        }
        $container = $countryRoot . DIRECTORY_SEPARATOR . $countryCode;
        $dirs = orange_backup_retention_list_finalized_dirs($container);
        $latest = array_slice($dirs, 0, max(1, $perCountryLimit));
        if ($latest === []) {
            continue;
        }
        foreach ($latest as $dir) {
            $out[] = orange_backup_admin_summarize_country_package(
                $dir['path'],
                $dir['name'],
                $countryCode,
                is_array($countryMeta) ? $countryMeta : null
            );
        }
    }

    usort($out, static fn (array $a, array $b): int => strcmp((string) ($b['generated_at'] ?? ''), (string) ($a['generated_at'] ?? '')));

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_backup_admin_list_logs(string $backupRoot, int $limit = 30): array
{
    $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');
    if (!is_dir($logsDir)) {
        return [];
    }
    $files = [];
    foreach (scandir($logsDir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $logsDir . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($full)) {
            continue;
        }
        $files[] = [
            'name' => $entry,
            'path' => $full,
            'mtime' => filemtime($full) ?: 0,
            'size_bytes' => filesize($full) ?: 0,
            'category' => orange_backup_admin_log_category($entry),
        ];
    }
    usort($files, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

    return array_slice($files, 0, max(1, $limit));
}

function orange_backup_admin_log_category(string $fileName): string
{
    $lower = strtolower($fileName);
    if (str_contains($lower, 'run_full_backup')) {
        return 'full_backup';
    }
    if (str_contains($lower, 'export_all_countries') || str_contains($lower, 'country')) {
        return 'country_batch';
    }
    if (str_contains($lower, 'recovery') || str_contains($lower, 'validation')) {
        return 'validation';
    }

    return 'other';
}

/**
 * @return array<string, mixed>
 */
function orange_backup_admin_collect_overview(PDO $pdo, string $projectRoot): array
{
    $ctx = orange_backup_admin_context($projectRoot);
    $backupRoot = $ctx['backup_root'];
    $env = $ctx['env'];
    $retentionDays = orange_backup_retention_days($env);

    $snapshotsDir = orange_backup_path_inside_root($backupRoot, 'snapshots');
    $countryRoot = orange_backup_path_inside_root($backupRoot, 'country_packages');
    $logsDir = orange_backup_path_inside_root($backupRoot, 'logs');

    $fullSnapshots = orange_backup_admin_list_full_snapshots($backupRoot, 50);
    $latestFull = $fullSnapshots[0] ?? null;
    $lastSuccessfulFull = null;
    foreach ($fullSnapshots as $snap) {
        if (!empty($snap['healthy'])) {
            $lastSuccessfulFull = $snap;
            break;
        }
    }

    $latestRecoveryScore = 0;
    foreach ($fullSnapshots as $snap) {
        $score = (int) ($snap['recovery_score'] ?? 0);
        if ($score > $latestRecoveryScore) {
            $latestRecoveryScore = $score;
        }
    }

    $discovery = orange_crp_batch_discover_countries($pdo);
    $recoverableCount = count($discovery['selected'] ?? []);

    $report = orange_backup_collect_environment_report($projectRoot);
    $backend = (string) ($report['selected_backend'] ?? '');

    $countryPackages = orange_backup_admin_list_country_packages($pdo, $backupRoot, 1);
    $latestCountry = $countryPackages[0] ?? null;

    $fullLock = orange_backup_admin_full_lock_status($backupRoot);
    $countryLock = orange_backup_admin_country_lock_status($backupRoot);

    $storage = [
        'snapshots_bytes' => orange_backup_admin_dir_size_bytes($snapshotsDir),
        'country_packages_bytes' => orange_backup_admin_dir_size_bytes($countryRoot),
        'logs_bytes' => orange_backup_admin_dir_size_bytes($logsDir),
        'total_bytes' => 0,
    ];
    $storage['total_bytes'] = $storage['snapshots_bytes'] + $storage['country_packages_bytes'] + $storage['logs_bytes'];
    $storage['snapshots_human'] = orange_backup_admin_format_bytes($storage['snapshots_bytes']);
    $storage['country_packages_human'] = orange_backup_admin_format_bytes($storage['country_packages_bytes']);
    $storage['logs_human'] = orange_backup_admin_format_bytes($storage['logs_bytes']);
    $storage['total_human'] = orange_backup_admin_format_bytes($storage['total_bytes']);

    return [
        'backup_root' => $backupRoot,
        'backup_root_status' => is_writable($backupRoot) ? 'writable' : 'not_writable',
        'retention_days' => $retentionDays,
        'selected_backend' => $backend,
        'recoverable_countries' => $recoverableCount,
        'latest_recovery_score' => $latestRecoveryScore,
        'last_successful_full' => $lastSuccessfulFull,
        'latest_full' => $latestFull,
        'latest_country_batch' => $latestCountry,
        'full_lock' => ['held' => $fullLock['held']],
        'country_lock' => ['held' => $countryLock['held']],
        'storage' => $storage,
        'scheduled_tasks' => orange_backup_admin_scheduled_tasks_readonly($retentionDays),
    ];
}

/**
 * @return list<array<string, string>>
 */
function orange_backup_admin_scheduled_tasks_readonly(int $retentionDays): array
{
    return [
        [
            'task' => 'Full Disaster Backup',
            'schedule' => 'Daily 03:00 UTC',
            'script' => 'scripts/backup/run_full_backup.php',
            'editable' => 'no',
        ],
        [
            'task' => 'Country Batch Backup',
            'schedule' => 'Daily after Full Backup (e.g. 03:30 UTC)',
            'script' => 'scripts/backup/export_all_recoverable_countries.php',
            'editable' => 'no',
        ],
        [
            'task' => 'Retention policy',
            'schedule' => 'Applied after each backup run',
            'script' => 'includes/backup/backup_retention.php',
            'editable' => 'no',
            'retention_days' => (string) $retentionDays,
        ],
    ];
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_backup_admin_run_full(string $projectRoot, array $options = []): array
{
    $startedAt = gmdate('c');
    if (isset($options['run_full_override']) && is_callable($options['run_full_override'])) {
        /** @var array<string, mixed> $result */
        $result = ($options['run_full_override'])($projectRoot);
    } else {
        $result = orange_backup_run_full($projectRoot);
    }
    $finishedAt = gmdate('c');

    return array_merge($result, [
        'started_at' => $startedAt,
        'finished_at' => $finishedAt,
        'action' => 'run_full_backup',
    ]);
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_backup_admin_run_country_batch(string $projectRoot, array $options = []): array
{
    $startedAt = gmdate('c');
    $projectRoot = realpath($projectRoot) ?: $projectRoot;

    if (isset($options['batch_override']) && is_callable($options['batch_override'])) {
        /** @var array<string, mixed> $cliResult */
        $cliResult = ($options['batch_override'])();
    } else {
        $script = $projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup'
            . DIRECTORY_SEPARATOR . 'export_all_recoverable_countries.php';
        if (!is_file($script)) {
            throw new RuntimeException('Country batch script not found.');
        }
        $phpBinary = (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $capture = orange_backup_run_command_capture([$phpBinary, $script], 7200);
        $cliResult = [
            'ok' => ((int) ($capture['exit_code'] ?? 1)) === 0,
            'exit_code' => (int) ($capture['exit_code'] ?? 1),
            'stdout' => (string) ($capture['stdout'] ?? ''),
            'stderr' => (string) ($capture['stderr'] ?? ''),
            'message' => ((int) ($capture['exit_code'] ?? 1)) === 0
                ? 'Country batch export completed.'
                : 'Country batch export failed.',
        ];
    }

    return array_merge($cliResult, [
        'started_at' => $startedAt,
        'finished_at' => gmdate('c'),
        'action' => 'run_country_batch',
    ]);
}

/**
 * @return array<string, mixed>
 */
function orange_backup_admin_verify_package(string $packageType, string $packagePath): array
{
    if ($packageType === 'full_disaster') {
        $result = orange_backup_verify_full_package($packagePath);

        return orange_backup_admin_redact_secrets([
            'ok' => (bool) ($result['ok'] ?? false),
            'package_type' => 'full_disaster',
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'manifest' => is_array($result['manifest'] ?? null) ? orange_backup_admin_redact_secrets($result['manifest']) : null,
            'health' => is_array($result['health'] ?? null) ? orange_backup_admin_redact_secrets($result['health']) : null,
        ]);
    }
    if ($packageType === 'country_recovery') {
        $result = orange_country_export_verify_package($packagePath);

        return orange_backup_admin_redact_secrets([
            'ok' => (bool) ($result['ok'] ?? false),
            'package_type' => 'country_recovery',
            'errors' => $result['errors'] ?? [],
            'warnings' => $result['warnings'] ?? [],
            'manifest' => is_array($result['manifest'] ?? null) ? orange_backup_admin_redact_secrets($result['manifest']) : null,
            'health' => is_array($result['health'] ?? null) ? orange_backup_admin_redact_secrets($result['health']) : null,
        ]);
    }

    throw new RuntimeException('Unsupported package type.');
}

/**
 * @return array<string, mixed>
 */
function orange_backup_admin_recovery_validate(string $packagePath): array
{
    $report = orange_recovery_validate_package($packagePath);
    $reportPath = orange_recovery_write_report_file($report);

    return orange_backup_admin_redact_secrets([
        'ok' => (string) ($report['overall_result'] ?? 'fail') === 'pass',
        'overall_result' => (string) ($report['overall_result'] ?? 'fail'),
        'recovery_score' => (int) ($report['recovery_score'] ?? 0),
        'package_type' => (string) ($report['package_type'] ?? ''),
        'errors' => $report['errors'] ?? [],
        'warnings' => $report['warnings'] ?? [],
        'report_path' => $reportPath,
        'report' => $report,
    ]);
}

function orange_backup_admin_audit(
    string $action,
    string $packageType,
    string $packageIdentifier,
    string $startedAt,
    string $finishedAt,
    bool $ok,
    string $errorSummary = ''
): void {
    $message = sprintf(
        'backup_admin %s type=%s id=%s started=%s finished=%s result=%s%s',
        $action,
        $packageType,
        $packageIdentifier,
        $startedAt,
        $finishedAt,
        $ok ? 'pass' : 'fail',
        $errorSummary !== '' ? ' error=' . $errorSummary : ''
    );
    audit_log('backup_admin_' . $action, $message, 'backup_package', $packageIdentifier);
}
