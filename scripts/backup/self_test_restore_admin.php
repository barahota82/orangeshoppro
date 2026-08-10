<?php

declare(strict_types=1);

/**
 * Phase 3B.1 — Restore Center read-only admin self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_admin.php
 *   php scripts/backup/self_test_restore_admin.php --verbose
 *
 * Default output is quiet (FAIL/THROWABLE/FATAL + final summary only).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

/** @var array{quiet:bool,passes:int,fail_labels:list<string>} */
$GLOBALS['restore_admin_test_output'] = [
    'quiet' => true,
    'passes' => 0,
    'fail_labels' => [],
];

foreach ($argv ?? [] as $restoreAdminTestArg) {
    if ($restoreAdminTestArg === '--verbose' || $restoreAdminTestArg === '-v') {
        $GLOBALS['restore_admin_test_output']['quiet'] = false;
    }
}

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null) {
        echo 'FATAL: ' . $error['type'] . ' @ ' . $error['file'] . ':' . $error['line'] . ' — ' . $error['message'] . PHP_EOL;
    }
    restore_admin_test_run_cleanup();
    if (!restore_admin_test_is_quiet()) {
        restore_admin_test_emit_diagnostics();
    }
    restore_admin_test_emit_summary();
});

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function restore_admin_test_is_quiet(): bool
{
    return (bool) ($GLOBALS['restore_admin_test_output']['quiet'] ?? true);
}

function restore_admin_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        $GLOBALS['restore_admin_test_output']['passes']++;
        if (!restore_admin_test_is_quiet()) {
            echo "PASS: {$label}\n";
        }
    } else {
        echo "FAIL: {$label}\n";
        $GLOBALS['restore_admin_test_output']['fail_labels'][] = $label;
        $failures++;
    }
}

function restore_admin_test_emit_summary(): void
{
    if (($GLOBALS['restore_admin_test_summary_emitted'] ?? false) === true) {
        return;
    }
    $GLOBALS['restore_admin_test_summary_emitted'] = true;

    global $failures;
    $passes = (int) ($GLOBALS['restore_admin_test_output']['passes'] ?? 0);
    $failCount = (int) $failures;
    $result = $failCount === 0 ? 'PASS' : 'FAIL';

    echo 'RESTORE_ADMIN_TEST_RESULT: ' . $result . PHP_EOL;
    echo 'TOTAL_PASS: ' . $passes . PHP_EOL;
    echo 'TOTAL_FAIL: ' . $failCount . PHP_EOL;
}

function restore_admin_test_pdo(string $permKey, bool $superuser, int $adminId = 2): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY, username TEXT, is_active INTEGER, is_superuser INTEGER, display_name TEXT, password_hash TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER, resource_key TEXT, can_view INTEGER, can_edit INTEGER, can_delete INTEGER)');
    $hash = password_hash('restore-test-password', PASSWORD_DEFAULT);
    $pdo->exec(
        'INSERT INTO admins VALUES (' . $adminId . ', \'op\', 1, ' . ($superuser ? '1' : '0')
        . ', \'Op\', ' . $pdo->quote($hash) . ')'
    );
    if ($permKey !== '') {
        $pdo->exec('INSERT INTO admin_permissions VALUES (' . $adminId . ', ' . $pdo->quote($permKey) . ', 1, 0, 0)');
    }
    $GLOBALS['orange_schema_table_cache'] = ['admins' => true, 'admin_permissions' => true];
    $GLOBALS['orange_schema_column_cache'] = [
        'admin_permissions.can_lock' => false,
        'admin_permissions.can_unlock' => false,
        'admin_permissions.can_print' => false,
        'admin_permissions.can_export' => false,
    ];

    return $pdo;
}

function restore_admin_test_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            restore_admin_test_rmtree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

const RESTORE_ADMIN_SELF_TEST_MARKER = '.orange_restore_admin_self_test';
const RESTORE_ADMIN_SELF_TEST_NAMESPACE = '_self_tests';

/** @var array{test_base:string,root_created:bool,marker_created:bool,cleanup:bool,emitted:bool} */
$GLOBALS['restore_admin_test_diag'] = [
    'test_base' => '',
    'root_created' => false,
    'marker_created' => false,
    'cleanup' => false,
    'emitted' => false,
];

/** @var array{tmpRoot:?string,configured_backup_root:string,cleaned:bool} */
$GLOBALS['restore_admin_test_fixture_state'] = [
    'tmpRoot' => null,
    'configured_backup_root' => '',
    'cleaned' => false,
];

function restore_admin_test_normalize_path_lexical(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    if (DIRECTORY_SEPARATOR === '\\' && preg_match('#^([A-Za-z]):/#', $path, $m) === 1) {
        $path = strtolower($m[1]) . ':/' . substr($path, 3);
    }
    $path = rtrim($path, '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        return strtolower($path);
    }

    return $path;
}

function restore_admin_test_path_outside_project(string $path, string $projectRoot): bool
{
    $normPath = restore_admin_test_normalize_path_lexical($path);
    $normProject = restore_admin_test_normalize_path_lexical($projectRoot);
    if ($normPath === '' || $normProject === '') {
        return false;
    }

    return $normPath !== $normProject && !str_starts_with($normPath, $normProject . '/');
}

function restore_admin_test_emit_diagnostics(): void
{
    if (restore_admin_test_is_quiet()) {
        return;
    }
    if (($GLOBALS['restore_admin_test_diag']['emitted'] ?? false) === true) {
        return;
    }
    $GLOBALS['restore_admin_test_diag']['emitted'] = true;
    /** @var array{test_base:string,root_created:bool,marker_created:bool,cleanup:bool} $diag */
    $diag = $GLOBALS['restore_admin_test_diag'];
    $base = $diag['test_base'] !== '' ? $diag['test_base'] : 'NONE';
    echo 'TEST_BASE=' . $base . PHP_EOL;
    echo 'TEST_ROOT_CREATED=' . ($diag['root_created'] ? 'Y' : 'N') . PHP_EOL;
    echo 'TEST_MARKER_CREATED=' . ($diag['marker_created'] ? 'Y' : 'N') . PHP_EOL;
    echo 'TEST_CLEANUP=' . ($diag['cleanup'] ? 'Y' : 'N') . PHP_EOL;
}

function restore_admin_test_self_tests_base(string $configuredBackupRoot): string
{
    return rtrim($configuredBackupRoot, DIRECTORY_SEPARATOR . '/\\')
        . DIRECTORY_SEPARATOR . RESTORE_ADMIN_SELF_TEST_NAMESPACE;
}

function restore_admin_test_assert_under_self_tests(string $path, string $configuredBackupRoot): void
{
    $base = restore_admin_test_normalize_path_lexical(
        restore_admin_test_self_tests_base($configuredBackupRoot)
    );
    $norm = restore_admin_test_normalize_path_lexical($path);
    if ($norm === '' || !str_starts_with($norm, $base . '/')) {
        throw new RuntimeException('Restore admin self-test path is outside the _self_tests namespace.');
    }
}

function restore_admin_test_resolve_configured_backup_root(string $projectRoot): string
{
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_environment.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_admin.php';

    $env = orange_backup_load_env_array($projectRoot);
    $candidate = orange_backup_backup_root_candidate($env, $projectRoot);
    orange_backup_admin_validate_configured_root_candidate($candidate);

    $candidate = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate), DIRECTORY_SEPARATOR);
    if ($candidate === '') {
        throw new RuntimeException('Configured BackupRoot must not be empty.');
    }

    if (!is_dir($candidate)) {
        if (!@mkdir($candidate, 0775, true) && !is_dir($candidate)) {
            throw new RuntimeException('Configured BackupRoot is not available.');
        }
    }
    if (!is_writable($candidate)) {
        throw new RuntimeException('Configured BackupRoot is not writable.');
    }

    return $candidate;
}

function restore_admin_test_safe_cleanup(?string $testRoot, string $configuredBackupRoot): bool
{
    if ($testRoot === null || $testRoot === '' || $configuredBackupRoot === '') {
        return false;
    }

    try {
        restore_admin_test_assert_under_self_tests($testRoot, $configuredBackupRoot);
    } catch (Throwable) {
        return false;
    }

    $marker = $testRoot . DIRECTORY_SEPARATOR . RESTORE_ADMIN_SELF_TEST_MARKER;
    if (!is_file($marker)) {
        return false;
    }

    @unlink($marker);
    restore_admin_test_rmtree($testRoot);

    return true;
}

function restore_admin_test_run_cleanup(): bool
{
    /** @var array{tmpRoot:?string,configured_backup_root:string,cleaned:bool} $state */
    $state = $GLOBALS['restore_admin_test_fixture_state'];
    if ($state['cleaned']) {
        return (bool) ($GLOBALS['restore_admin_test_diag']['cleanup'] ?? false);
    }

    $cleaned = restore_admin_test_safe_cleanup($state['tmpRoot'], $state['configured_backup_root']);
    $GLOBALS['restore_admin_test_fixture_state']['cleaned'] = $cleaned;
    $GLOBALS['restore_admin_test_diag']['cleanup'] = $cleaned;

    return $cleaned;
}

/**
 * @return array{
 *   configured_backup_root:string,
 *   tmpRoot:string,
 *   backupRoot:string,
 *   workRoot:string,
 *   fakeProject:string
 * }
 */
function restore_admin_test_create_fixture(string $projectRoot): array
{
    $configuredBackupRoot = restore_admin_test_resolve_configured_backup_root($projectRoot);
    $selfTestsBase = restore_admin_test_self_tests_base($configuredBackupRoot);

    if (!is_dir($selfTestsBase) && !@mkdir($selfTestsBase, 0775, true) && !is_dir($selfTestsBase)) {
        throw new RuntimeException('Cannot create _self_tests namespace under Configured BackupRoot.');
    }

    $random = bin2hex(random_bytes(4));
    $testRoot = $selfTestsBase . DIRECTORY_SEPARATOR . 'restore_admin_' . $random;
    restore_admin_test_assert_under_self_tests($testRoot, $configuredBackupRoot);

    if (!@mkdir($testRoot, 0775, true) && !is_dir($testRoot)) {
        throw new RuntimeException('Cannot create restore admin self-test root.');
    }
    $GLOBALS['restore_admin_test_diag']['root_created'] = true;
    $GLOBALS['restore_admin_test_diag']['test_base'] = RESTORE_ADMIN_SELF_TEST_NAMESPACE . '/restore_admin_' . $random;

    $marker = $testRoot . DIRECTORY_SEPARATOR . RESTORE_ADMIN_SELF_TEST_MARKER;
    if (file_put_contents($marker, 'restore_admin_self_test') === false) {
        throw new RuntimeException('Cannot create restore admin self-test marker.');
    }
    $GLOBALS['restore_admin_test_diag']['marker_created'] = true;

    $backupRoot = $testRoot . DIRECTORY_SEPARATOR . 'backups';
    $workRoot = $testRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $fakeProject = $testRoot . DIRECTORY_SEPARATOR . 'fake_project';
    foreach ([$backupRoot, $workRoot, $fakeProject] as $dir) {
        restore_admin_test_assert_under_self_tests($dir, $configuredBackupRoot);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Cannot create restore admin self-test fixture directory.');
        }
    }

    restore_admin_self_test(
        restore_admin_test_path_outside_project($backupRoot, $projectRoot),
        'fixture: BackupRoot outside ProjectRoot'
    );

    return [
        'configured_backup_root' => $configuredBackupRoot,
        'tmpRoot' => $testRoot,
        'backupRoot' => $backupRoot,
        'workRoot' => $workRoot,
        'fakeProject' => $fakeProject,
    ];
}

/** @return list<string> */
function restore_admin_engine_markers(): array
{
    return [
        'restore_orchestrator.php',
        'restore_e2e_orchestrator.php',
        'restore_full_staging.php',
        'restore_country_staging.php',
        'restore_admin.php',
    ];
}

function restore_admin_included_engine_file(): ?string
{
    foreach (get_included_files() as $path) {
        $base = basename(str_replace('\\', '/', $path));
        foreach (restore_admin_engine_markers() as $marker) {
            if ($base === $marker) {
                return $path;
            }
        }
    }

    return null;
}

$superAdmin = ['id' => 1, 'is_superuser' => 1, 'is_active' => 1];
$superPdo = restore_admin_test_pdo('', true, 1);
$visible = orange_admin_nav_visible($superAdmin, $superPdo, 'restore_center');
restore_admin_self_test($visible === true, 'nav: superuser sees restore_center');
restore_admin_self_test(restore_admin_included_engine_file() === null, 'nav: restore_center visibility does not load restore engine');

try {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php';

    $fixture = restore_admin_test_create_fixture($projectRoot);
    $GLOBALS['restore_admin_test_fixture_state']['tmpRoot'] = $fixture['tmpRoot'];
    $GLOBALS['restore_admin_test_fixture_state']['configured_backup_root'] = $fixture['configured_backup_root'];
    $tmpRoot = $fixture['tmpRoot'];
    $backupRoot = $fixture['backupRoot'];
    $workRoot = $fixture['workRoot'];
    $fakeProject = $fixture['fakeProject'];
    file_put_contents(
    $fakeProject . DIRECTORY_SEPARATOR . '.env.php',
    "<?php\nreturn ['ORANGE_BACKUP_ROOT' => " . var_export($backupRoot, true) . "];\n"
);

function restore_admin_test_write_gzip(string $path, string $contents): void
{
    $gz = gzencode($contents, 1);
    if ($gz === false) {
        throw new RuntimeException('gzencode failed');
    }
    file_put_contents($path, $gz);
}

function restore_admin_test_write_zip(string $path, array $files): void
{
    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('zip create failed');
        }
        foreach ($files as $name => $body) {
            $zip->addFromString((string) $name, (string) $body);
        }
        $zip->close();

        return;
    }

    // Minimal stored (no compression) ZIP writer for environments without ZipArchive.
    $local = '';
    $central = '';
    $offset = 0;
    $count = 0;
    foreach ($files as $name => $body) {
        $name = (string) $name;
        $body = (string) $body;
        $nameLen = strlen($name);
        $size = strlen($body);
        $crc = crc32($body);
        if ($crc < 0) {
            $crc = $crc & 0xFFFFFFFF;
        }
        $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0)
            . $name . $body;
        $centralHeader = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $size, $size, $nameLen, 0, 0, 0, 0, 0, $offset)
            . $name;
        $local .= $localHeader;
        $central .= $centralHeader;
        $offset += strlen($localHeader);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);
    file_put_contents($path, $local . $central . $end);
}

function restore_admin_test_seed_full_dry_package(string $pkgDir, string $pkgId, array $manifestExtra = []): void
{
    if (!is_dir($pkgDir)) {
        mkdir($pkgDir, 0775, true);
    }
    $dumpRel = 'database.sql.gz';
    $uploadsRel = 'uploads.zip';
    restore_admin_test_write_gzip($pkgDir . DIRECTORY_SEPARATOR . $dumpRel, "SET NAMES utf8mb4;\nCREATE TABLE t(id INT);\n");
    restore_admin_test_write_zip($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel, ['a.txt' => 'hello']);
    $dumpSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $dumpRel) ?: '';
    $uploadsSha = hash_file('sha256', $pkgDir . DIRECTORY_SEPARATOR . $uploadsRel) ?: '';
    $manifest = array_merge([
        'package_type' => 'full_disaster',
        'package_version' => '1.0.0',
        'generated_at' => gmdate('c'),
        'schema_revision' => 124,
        'export_backend' => 'php_pdo',
        'backup_status' => 'success',
        'dump_file' => $dumpRel,
        'uploads_file' => $uploadsRel,
        'dump_sha256' => $dumpSha,
        'uploads_sha256' => $uploadsSha,
        'dump_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $dumpRel),
        'uploads_size_bytes' => (int) filesize($pkgDir . DIRECTORY_SEPARATOR . $uploadsRel),
        'health_report_file' => 'health.json',
        'checksums_file' => 'checksums.sha256',
        'table_count' => 1,
    ], $manifestExtra);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    orange_backup_write_json($pkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
    $checksumBody = $dumpSha . '  ' . $dumpRel . "\n" . $uploadsSha . '  ' . $uploadsRel . "\n";
    file_put_contents($pkgDir . DIRECTORY_SEPARATOR . 'checksums.sha256', $checksumBody);
    orange_backup_write_json(
        orange_backup_admin_recovery_report_sibling_path($pkgDir, $pkgId),
        [
            'overall_result' => 'pass',
            'recovery_score' => 95,
            'validated_at' => gmdate('c'),
            'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
            'manifest_valid' => true,
            'health_valid' => true,
            'checksums_valid' => true,
            'sql_valid' => true,
            'uploads_valid' => true,
        ]
    );
}

$fullPkgId = '2026-07-01_120000';
$fullPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullPkgId;
restore_admin_test_seed_full_dry_package($fullPkgDir, $fullPkgId);

// Schema-124 CRP package (same contract as C7/C8 fixtures) — not the legacy stub package.
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_boundary_matrix_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_crp_verify.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_crp_drv.php';
$countryPkgId = '2026-07-01_130000';
$countryPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryPkgId;
if (is_dir($countryPkgDir)) {
    orange_backup_remove_dir($countryPkgDir);
}
mkdir($countryPkgDir . DIRECTORY_SEPARATOR . 'sql', 0775, true);
mkdir($countryPkgDir . DIRECTORY_SEPARATOR . 'files', 0775, true);
$countryId = 1;
$matrix = orange_country_boundary_matrix_load($projectRoot);
$registry = orange_backup_registry_load($projectRoot);
$batches = orange_country_boundary_matrix_restore_batches($matrix);
$dep = orange_country_export_build_dependency_graph_c3($matrix, $registry, $batches);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'dependency_graph.json', $dep);
$sqlBody = "-- Orange CRP export table=orders country_id={$countryId}\n"
    . "INSERT INTO `orders` (`id`,`country_id`) VALUES (1,{$countryId});\n";
file_put_contents($countryPkgDir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '010_orders.sql', $sqlBody);
$gz = gzopen($countryPkgDir . DIRECTORY_SEPARATOR . 'country.sql.gz', 'wb9');
if ($gz !== false) {
    gzwrite($gz, $sqlBody);
    gzclose($gz);
}
orange_country_uploads_write_empty_zip($countryPkgDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'table_inventory.json', [
    'country_id' => $countryId,
    'tables' => ['orders' => 1],
    'other_country_markers' => [],
]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'id_snapshot.json', [
    'country_id' => $countryId,
    'tables' => ['orders' => [1]],
]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'health.json', [
    'package_status' => 'healthy',
    'country_id' => $countryId,
    'country_code' => 'kw',
    'schema_revision' => (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION,
]);
$countryManifest = [
    'package_type' => 'country_recovery',
    'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
    'generated_at' => gmdate('c'),
    'export_time' => gmdate('c'),
    'country_id' => $countryId,
    'country_code' => 'kw',
    'schema_revision' => (int) ORANGE_CATALOG_SCHEMA_PHP_REVISION,
    'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
    'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
    'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
    'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
    'export_backend' => ORANGE_COUNTRY_EXPORT_BACKEND,
    'registry_version' => '1.0',
    'restore_batches' => $batches,
    'backup_status' => 'success',
    'package_fingerprint' => '',
];
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'manifest.json', $countryManifest);
$countryManifest['package_fingerprint'] = orange_crp_export_package_fingerprint($countryPkgDir, $countryManifest);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'manifest.json', $countryManifest);
orange_backup_write_checksums($countryPkgDir, orange_backup_collect_package_files($countryPkgDir));
orange_crp_verify_run($countryPkgDir, ['write_report' => true, 'project_root' => $projectRoot]);
orange_country_drv_run($countryPkgDir, [
    'write_report' => true,
    'project_root' => $projectRoot,
    'package_id' => $countryPkgId,
]);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($countryPkgDir, $countryPkgId),
    [
        'overall_result' => 'pass',
        'recovery_score' => 88,
        'validated_at' => gmdate('c'),
        'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
        'registry_valid' => true,
        'dependency_graph_valid' => true,
    ]
);

$fullMissingDrvId = '2026-07-01_110000';
$fullMissingDrvDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullMissingDrvId;
mkdir($fullMissingDrvDir, 0775, true);
orange_backup_write_json($fullMissingDrvDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 3600),
    'schema_revision' => 124,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullMissingDrvDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);

$fullFailedDrvId = '2026-07-01_100000';
$fullFailedDrvDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullFailedDrvId;
mkdir($fullFailedDrvDir, 0775, true);
orange_backup_write_json($fullFailedDrvDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 7200),
    'schema_revision' => 124,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullFailedDrvDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($fullFailedDrvDir, $fullFailedDrvId),
    ['overall_result' => 'fail', 'recovery_score' => 40, 'validated_at' => gmdate('c')]
);

$fullWarningDrvId = '2026-07-01_090000';
$fullWarningDrvDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullWarningDrvId;
mkdir($fullWarningDrvDir, 0775, true);
orange_backup_write_json($fullWarningDrvDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 10800),
    'schema_revision' => 124,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullWarningDrvDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($fullWarningDrvDir, $fullWarningDrvId),
    ['overall_result' => 'warning', 'recovery_score' => 80, 'validated_at' => gmdate('c')]
);

$fullBadBackendId = '2026-07-01_080000';
$fullBadBackendDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullBadBackendId;
mkdir($fullBadBackendDir, 0775, true);
orange_backup_write_json($fullBadBackendDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 14400),
    'schema_revision' => 124,
    'export_backend' => 'mysqldump',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullBadBackendDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($fullBadBackendDir, $fullBadBackendId),
    ['overall_result' => 'pass', 'recovery_score' => 95, 'validated_at' => gmdate('c')]
);

$fullBadSchemaId = '2026-07-01_070000';
$fullBadSchemaDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullBadSchemaId;
mkdir($fullBadSchemaDir, 0775, true);
orange_backup_write_json($fullBadSchemaDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 18000),
    'schema_revision' => 99,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullBadSchemaDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($fullBadSchemaDir, $fullBadSchemaId),
    ['overall_result' => 'pass', 'recovery_score' => 95, 'validated_at' => gmdate('c')]
);

$fullPassNoScoreId = '2026-07-01_060000';
$fullPassNoScoreDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullPassNoScoreId;
mkdir($fullPassNoScoreDir, 0775, true);
orange_backup_write_json($fullPassNoScoreDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c', time() - 21600),
    'schema_revision' => 124,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullPassNoScoreDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($fullPassNoScoreDir, $fullPassNoScoreId),
    ['overall_result' => 'pass', 'validated_at' => gmdate('c')]
);

$countryFailedDrvId = '2026-07-01_124000';
$countryFailedDrvDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryFailedDrvId;
mkdir($countryFailedDrvDir, 0775, true);
orange_backup_write_json($countryFailedDrvDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'country_recovery',
    'generated_at' => gmdate('c', time() - 3600),
    'schema_revision' => 124,
    'registry_version' => '1.0',
    'backup_status' => 'success',
]);
orange_backup_write_json($countryFailedDrvDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($countryFailedDrvDir, $countryFailedDrvId),
    ['overall_result' => 'fail', 'recovery_score' => 50, 'validated_at' => gmdate('c')]
);

$countryWarningDrvId = '2026-07-01_123000';
$countryWarningDrvDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryWarningDrvId;
mkdir($countryWarningDrvDir, 0775, true);
orange_backup_write_json($countryWarningDrvDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'country_recovery',
    'generated_at' => gmdate('c', time() - 7200),
    'schema_revision' => 124,
    'registry_version' => '1.0',
    'backup_status' => 'success',
]);
orange_backup_write_json($countryWarningDrvDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($countryWarningDrvDir, $countryWarningDrvId),
    ['overall_result' => 'warning', 'recovery_score' => 80, 'validated_at' => gmdate('c')]
);

$countryBadRegistryId = '2026-07-01_122000';
$countryBadRegistryDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryBadRegistryId;
mkdir($countryBadRegistryDir, 0775, true);
orange_backup_write_json($countryBadRegistryDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'country_recovery',
    'generated_at' => gmdate('c', time() - 10800),
    'schema_revision' => 124,
    'registry_version' => '9.9',
    'backup_status' => 'success',
]);
orange_backup_write_json($countryBadRegistryDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($countryBadRegistryDir, $countryBadRegistryId),
    ['overall_result' => 'pass', 'recovery_score' => 95, 'validated_at' => gmdate('c')]
);

require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job.php';

$fullJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_FULL,
    'operator_admin_id' => 1,
    'operator_username' => 'superadmin',
    'source_package_path' => $fullPkgDir,
    'source_package_checksum' => str_repeat('a', 64),
    'schema_revision' => 124,
]);
$fullJobId = (string) ($fullJob['job_id'] ?? '');
orange_restore_job_write($workRoot, array_merge($fullJob, [
    'status' => ORANGE_RESTORE_JOB_STATUS_AWAITING_APPROVAL,
    'approval_token' => 'PLAIN-MUST-NOT-LEAK',
    'approval_token_hash' => hash('sha256', 'secret-token'),
    'fresh_backup_path' => $fullPkgDir,
    'fresh_backup_checksum' => str_repeat('b', 64),
]));

$countryJob = orange_restore_job_create($workRoot, [
    'job_type' => ORANGE_RESTORE_JOB_TYPE_COUNTRY,
    'operator_admin_id' => 2,
    'operator_username' => 'countryop',
    'source_package_path' => $countryPkgDir,
    'source_package_checksum' => str_repeat('c', 64),
    'country_code' => 'KW',
    'schema_revision' => 124,
]);
$countryJobId = (string) ($countryJob['job_id'] ?? '');

$fullOnlyPdo = restore_admin_test_pdo('backup_restore_full', false, 2);
$fullOnlyAdmin = ['id' => 2, 'is_superuser' => 0, 'is_active' => 1];
$countryOnlyPdo = restore_admin_test_pdo('backup_restore_country', false, 3);
$countryOnlyAdmin = ['id' => 3, 'is_superuser' => 0, 'is_active' => 1];
$noPermPdo = restore_admin_test_pdo('', false, 4);
$noPermAdmin = ['id' => 4, 'is_superuser' => 0, 'is_active' => 1];

restore_admin_self_test(orange_restore_admin_may_view_full($superAdmin, $superPdo), 'permissions: superuser sees full');
restore_admin_self_test(orange_restore_admin_may_view_country($superAdmin, $superPdo), 'permissions: superuser sees country');
restore_admin_self_test(orange_restore_admin_may_view_full($fullOnlyAdmin, $fullOnlyPdo), 'permissions: full-only sees full');
restore_admin_self_test(!orange_restore_admin_may_view_country($fullOnlyAdmin, $fullOnlyPdo), 'permissions: full-only denied country');
restore_admin_self_test(!orange_restore_admin_may_view_full($countryOnlyAdmin, $countryOnlyPdo), 'permissions: country-only denied full');
restore_admin_self_test(orange_restore_admin_may_view_country($countryOnlyAdmin, $countryOnlyPdo), 'permissions: country-only sees country');
restore_admin_self_test(!orange_admin_may_restore_center_view($noPermAdmin, $noPermPdo), 'permissions: no restore permission denied');

$ctx = orange_restore_admin_context($fakeProject);
restore_admin_self_test($ctx['backup_root'] !== '', 'context: backup root resolved');

$allJobs = orange_restore_admin_list_jobs($workRoot, true, true);
restore_admin_self_test(count($allJobs) >= 2, 'jobs: superuser list includes jobs');

$fullJobsOnly = orange_restore_admin_list_jobs($workRoot, true, false);
restore_admin_self_test(
    count($fullJobsOnly) >= 1 && !in_array($countryJobId, array_column($fullJobsOnly, 'job_id'), true),
    'jobs: full-only permission filters country jobs'
);

$countryJobsOnly = orange_restore_admin_list_jobs($workRoot, false, true);
restore_admin_self_test(
    count($countryJobsOnly) >= 1 && !in_array($fullJobId, array_column($countryJobsOnly, 'job_id'), true),
    'jobs: country-only permission filters full jobs'
);

$overview = orange_restore_admin_collect_overview($workRoot);
restore_admin_self_test(($overview['job_counts']['total_jobs'] ?? 0) >= 2, 'overview: total jobs counted');
restore_admin_self_test(($overview['job_counts']['awaiting_owner_approval'] ?? 0) >= 1, 'overview: awaiting approval counted');

$fullPackages = orange_backup_admin_list_full_snapshots($backupRoot, 20);
$publicFull = orange_restore_admin_public_package_row($fullPackages[0], 'full_disaster');
restore_admin_self_test(!isset($publicFull['package_path']), 'packages: absolute package_path stripped');
restore_admin_self_test(isset($publicFull['eligibility_status']), 'packages: eligibility_status attached');
restore_admin_self_test(
    ($publicFull['eligibility_status'] ?? '') === 'eligible',
    'eligibility: healthy full + DRV pass => eligible'
);
restore_admin_self_test(
    ($publicFull['drv_result'] ?? '') === 'pass' && ($publicFull['drv_score'] ?? null) === 95,
    'eligibility: full DRV pass with score from sibling report'
);
restore_admin_self_test(
    orange_backup_admin_read_recovery_validation_report($fullPkgDir, $fullPkgId) !== null,
    'eligibility: full DRV resolves from sibling report path'
);

$countryPackages = orange_backup_admin_list_country_packages($superPdo, $backupRoot, 20);
$publicCountry = orange_restore_admin_public_package_row($countryPackages[0], 'country_recovery');
restore_admin_self_test(
    ($publicCountry['eligibility_status'] ?? '') === 'eligible',
    'eligibility: healthy country + DRV pass => eligible'
);
restore_admin_self_test(
    orange_backup_admin_read_recovery_validation_report($countryPkgDir, $countryPkgId) !== null,
    'eligibility: country DRV resolves from sibling report path'
);

$missingDrvSummary = orange_backup_admin_summarize_full_package($fullMissingDrvDir, $fullMissingDrvId);
$missingDrvPublic = orange_restore_admin_public_package_row($missingDrvSummary, 'full_disaster');
restore_admin_self_test(
    ($missingDrvPublic['eligibility_status'] ?? '') === 'unknown',
    'eligibility: missing DRV report => unknown'
);
restore_admin_self_test(
    ($missingDrvPublic['eligibility_reason_code'] ?? '') === 'drv_report_missing',
    'eligibility: missing DRV explicit reason code'
);
restore_admin_self_test(
    ($missingDrvPublic['drv_result'] ?? '') === 'missing'
    && array_key_exists('drv_score', $missingDrvPublic)
    && $missingDrvPublic['drv_score'] === null,
    'eligibility: missing DRV does not default score to 0'
);

$failedDrvSummary = orange_backup_admin_summarize_full_package($fullFailedDrvDir, $fullFailedDrvId);
$failedDrvPublic = orange_restore_admin_public_package_row($failedDrvSummary, 'full_disaster');
restore_admin_self_test(
    ($failedDrvPublic['eligibility_status'] ?? '') === 'not_eligible'
    && ($failedDrvPublic['drv_result'] ?? '') === 'fail',
    'eligibility: failed DRV => not eligible'
);

$warningDrvSummary = orange_backup_admin_summarize_full_package($fullWarningDrvDir, $fullWarningDrvId);
$warningDrvPublic = orange_restore_admin_public_package_row($warningDrvSummary, 'full_disaster');
restore_admin_self_test(
    ($warningDrvPublic['eligibility_status'] ?? '') === 'eligible',
    'eligibility: warning full with score>=70 => eligible per adapter policy'
);

$badBackendSummary = orange_backup_admin_summarize_full_package($fullBadBackendDir, $fullBadBackendId);
$badBackendPublic = orange_restore_admin_public_package_row($badBackendSummary, 'full_disaster');
restore_admin_self_test(
    ($badBackendPublic['eligibility_reason_code'] ?? '') === 'export_backend_unsupported',
    'eligibility: unsupported backend => not eligible'
);

$badSchemaSummary = orange_backup_admin_summarize_full_package($fullBadSchemaDir, $fullBadSchemaId);
$badSchemaPublic = orange_restore_admin_public_package_row($badSchemaSummary, 'full_disaster');
restore_admin_self_test(
    ($badSchemaPublic['eligibility_reason_code'] ?? '') === 'schema_incompatible',
    'eligibility: incompatible schema => not eligible'
);

$passNoScoreSummary = orange_backup_admin_summarize_full_package($fullPassNoScoreDir, $fullPassNoScoreId);
$passNoScorePublic = orange_restore_admin_public_package_row($passNoScoreSummary, 'full_disaster');
restore_admin_self_test(
    ($passNoScorePublic['drv_result'] ?? '') === 'pass'
    && array_key_exists('drv_score', $passNoScorePublic)
    && $passNoScorePublic['drv_score'] === null,
    'eligibility: pass without numeric score leaves drv_score null'
);
restore_admin_self_test(
    ($passNoScorePublic['eligibility_status'] ?? '') === 'eligible',
    'eligibility: pass without numeric score uses overall_result pass'
);

$countryFailedPublic = orange_restore_admin_public_package_row(
    orange_backup_admin_summarize_country_package($countryFailedDrvDir, $countryFailedDrvId, 'kw'),
    'country_recovery'
);
restore_admin_self_test(
    ($countryFailedPublic['eligibility_status'] ?? '') === 'not_eligible',
    'eligibility: country failed DRV => not eligible'
);

$countryWarningPublic = orange_restore_admin_public_package_row(
    orange_backup_admin_summarize_country_package($countryWarningDrvDir, $countryWarningDrvId, 'kw'),
    'country_recovery'
);
restore_admin_self_test(
    ($countryWarningPublic['eligibility_reason_code'] ?? '') === 'drv_result_warning_country',
    'eligibility: country warning DRV => not eligible'
);

$countryBadRegistryPublic = orange_restore_admin_public_package_row(
    orange_backup_admin_summarize_country_package($countryBadRegistryDir, $countryBadRegistryId, 'kw'),
    'country_recovery'
);
restore_admin_self_test(
    ($countryBadRegistryPublic['eligibility_reason_code'] ?? '') === 'registry_invalid',
    'eligibility: country invalid registry => not eligible'
);

try {
    orange_restore_admin_assert_job_allowlisted($workRoot, '../../etc/passwd');
    restore_admin_self_test(false, 'security: arbitrary job id rejected');
} catch (Throwable) {
    restore_admin_self_test(true, 'security: arbitrary job id rejected');
}

try {
    orange_backup_admin_resolve_full_package_path($backupRoot, '../../../evil');
    restore_admin_self_test(false, 'security: arbitrary package id rejected');
} catch (Throwable) {
    restore_admin_self_test(true, 'security: arbitrary package id rejected');
}

$redacted = orange_restore_admin_redact_secrets([
    'approval_token' => 'secret-token',
    'password' => 'pw',
    'token_hash' => 'abc',
    'safe' => 'visible',
]);
restore_admin_self_test(!isset($redacted['approval_token']) && !isset($redacted['password']) && !isset($redacted['token_hash']), 'security: token/hash/password redaction');
restore_admin_self_test(($redacted['safe'] ?? '') === 'visible', 'security: non-secret fields preserved');

$detail = orange_restore_admin_job_detail($fakeProject, $workRoot, $fullJobId);
restore_admin_self_test(($detail['read_only'] ?? false) === true, 'job detail: read_only flag');
restore_admin_self_test(!str_contains(json_encode($detail, JSON_UNESCAPED_UNICODE), 'PLAIN-MUST-NOT-LEAK'), 'job detail: no plaintext approval token');
restore_admin_self_test(!str_contains(json_encode($detail, JSON_UNESCAPED_UNICODE), $fullPkgDir), 'job detail: no raw absolute package path');

$auditPayload = orange_restore_admin_sanitize_audit_list([
    ['recorded_at' => gmdate('c'), 'stage' => 'approval_gate', 'approval_token' => 'leak', 'result' => 'pass'],
]);
restore_admin_self_test(!isset($auditPayload[0]['approval_token']), 'security: audit sanitization removes tokens');

$listApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'list.php');
$statusApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'status.php');
restore_admin_self_test(str_contains($listApiSource, 'restore_admin_api_require_get'), 'api: list.php GET-only guard');
restore_admin_self_test(str_contains($statusApiSource, 'restore_admin_api_require_get'), 'api: status.php GET-only guard');
restore_admin_self_test(!str_contains(strtolower($listApiSource), 'orchestrator_approve'), 'api: list.php has no mutating restore calls');
restore_admin_self_test(!str_contains(strtolower($statusApiSource), 'orchestrator_rollback'), 'api: status.php has no rollback calls');
restore_admin_self_test(!str_contains(strtolower($statusApiSource), 'orchestrator_merge'), 'api: status.php has no merge calls');

$pageSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
restore_admin_self_test(str_contains($pageSource, 'orange_admin_render_page_title_with_country'), 'ui: restore_center unified page title');
restore_admin_self_test(
    str_contains($pageSource, 'eligibilityBadge')
    || str_contains($pageSource, 'eligibility_reason_label_ar')
    || str_contains($pageSource, 'مؤهلة')
    || str_contains($pageSource, 'غير مؤهلة'),
    'ui: eligibility column arabic label'
);
restore_admin_self_test(
    str_contains($pageSource, 'rc-pkg-detail')
    && str_contains($pageSource, 'معلومات الحزمة'),
    'ui: package details button arabic'
);
restore_admin_self_test(str_contains($pageSource, 'drvCell'), 'ui: DRV cell avoids zero placeholder');
restore_admin_self_test(str_contains($pageSource, 'انتظار التأكيد') || str_contains($pageSource, 'read_only'), 'ui: confirmation-gate warning present');
restore_admin_self_test(!str_contains($pageSource, 'بدء الاسترداد'), 'ui: no Start Restore button label');
restore_admin_self_test(!preg_match('/<button[^>]*>[^<]*موافقة(?! النهائية)/u', $pageSource), 'ui: no legacy merge approval button');
restore_admin_self_test(stripos($pageSource, '>Rollback<') === false && stripos($pageSource, 'restore_full_rollback.php') === false, 'ui: no Rollback action control');
restore_admin_self_test(!str_contains($pageSource, 'restore_admin.php'), 'ui: page does not load restore_admin.php at render');
restore_admin_self_test(str_contains($pageSource, 'rc-create-job') && str_contains($pageSource, 'rc-fw-cancel'), 'ui: framework create/cancel controls present');
// Intended UI contract (Arabic Restore Center): dry-validation / plan / contract markers — not English stubs.
restore_admin_self_test(
    str_contains($pageSource, 'rc-dry-run')
    && str_contains($pageSource, 'تنفيذ التحقق التشغيلي')
    && str_contains($pageSource, 'rc-dry-report')
    && str_contains($pageSource, 'عرض تقرير التحقق'),
    'ui: dry validation controls present'
);
restore_admin_self_test(str_contains($pageSource, 'إعداد خطة الاسترداد') && str_contains($pageSource, 'عرض خطة الاسترداد') && str_contains($pageSource, 'إلغاء الخطة'), 'ui: execution plan Arabic controls present');
restore_admin_self_test(str_contains($pageSource, 'بانتظار الموافقة النهائية') && str_contains($pageSource, 'لم يتم تنفيذ أي استرداد حتى الآن'), 'ui: awaiting final approval warning present');
restore_admin_self_test(str_contains($pageSource, 'وضع الصيانة') && str_contains($pageSource, 'الموافقة النهائية'), 'ui: maintenance section and final approval control present');
restore_admin_self_test(
    str_contains($pageSource, 'rc-exec-contract')
    && str_contains($pageSource, 'عرض عقد التنفيذ'),
    'ui: View Execution Contract control present'
);
restore_admin_self_test(
    str_contains($pageSource, 'rc-pre-backup-req')
    && str_contains($pageSource, 'تنفيذ النسخة الاحتياطية الإلزامية قبل الاسترداد')
    && str_contains($pageSource, 'لن يبدأ الاسترداد قبل إنشاء نسخة Full احتياطية موثقة ومثبتة ضد الحذف'),
    'ui: pre-restore backup controls and warning present'
);
restore_admin_self_test(!str_contains($pageSource, 'Execute Restore') && !preg_match('/\\bResume\\b/', $pageSource), 'ui: no Execute/Resume restore controls');
restore_admin_self_test(str_contains($pageSource, 'تم اعتماد الخطة، لكن لم يبدأ الاسترداد ولم يتم تفعيل وضع الصيانة'), 'ui: approved-waiting message present');
restore_admin_self_test(!str_contains($pageSource, 'Execute Restore') && !str_contains($pageSource, 'بدء الاسترداد') && !str_contains($pageSource, 'Start Worker') && !str_contains($pageSource, 'Enable Maintenance'), 'ui: no Execute/Enable Maintenance/Start Worker');

$createApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'create.php');
$cancelApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'cancel.php');
$viewApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'view.php');
$jobListApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'list.php');
restore_admin_self_test(str_contains($createApiSource, 'restore_admin_api_require_csrf'), 'api: job create requires CSRF');
restore_admin_self_test(str_contains($cancelApiSource, 'restore_admin_api_require_csrf'), 'api: job cancel requires CSRF');
restore_admin_self_test(str_contains($viewApiSource, 'restore_admin_api_require_get'), 'api: job view GET-only');
restore_admin_self_test(str_contains($jobListApiSource, 'restore_admin_api_require_get'), 'api: job list GET-only');
restore_admin_self_test(!str_contains(strtolower($createApiSource), 'orchestrator'), 'api: job create has no orchestrator calls');
restore_admin_self_test(!str_contains(strtolower($createApiSource), 'extract'), 'api: job create has no extract calls');

$fwJob = orange_restore_admin_fw_create_job(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    'full_disaster',
    $fullPkgId,
    ''
);
restore_admin_self_test(($fwJob['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_WAITING_CONFIRMATION, 'framework: create job stops at waiting_confirmation');
restore_admin_self_test(($fwJob['progress'] ?? -1) === 0, 'framework: default progress is 0');
restore_admin_self_test(($fwJob['message'] ?? '') === 'Waiting confirmation', 'framework: default message waiting confirmation');
restore_admin_self_test(($fwJob['execution_enabled'] ?? true) === false, 'framework: execution disabled');

$duplicateRejected = false;
try {
    orange_restore_admin_fw_create_job(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        'full_disaster',
        $fullPkgId,
        ''
    );
} catch (Throwable $e) {
    $duplicateRejected = trim($e->getMessage()) === 'restore_job_already_active';
}
restore_admin_self_test($duplicateRejected, 'framework: duplicate active job rejected');

$fwView = orange_restore_admin_fw_view_job($workRoot, (string) $fwJob['job_id'], true, true);
restore_admin_self_test(($fwView['job_id'] ?? '') === ($fwJob['job_id'] ?? ''), 'framework: view job returns same id');
$auditEvents = $fwView['audit_events'] ?? [];
$auditStages = array_map(static fn ($e) => (string) ($e['stage'] ?? ''), is_array($auditEvents) ? $auditEvents : []);
restore_admin_self_test(
    in_array('Restore Job Created', $auditStages, true)
    && in_array('Restore Job Locked', $auditStages, true)
    && in_array('Restore Job Waiting Confirmation', $auditStages, true),
    'framework: audit entries recorded for create/lock/wait'
);

$cancelledQueued = orange_restore_admin_fw_cancel_job(
    $workRoot,
    ['id' => 1, 'username' => 'superadmin'],
    true,
    true,
    (string) $fwJob['job_id']
);
restore_admin_self_test(($cancelledQueued['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_CANCELLED, 'framework: cancel waiting_confirmation job');

$prepJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_fw_write($workRoot, array_merge($prepJob, [
    'status' => ORANGE_RESTORE_FW_STATUS_PREPARING,
    'phase' => ORANGE_RESTORE_FW_PHASE_PREPARING,
    'message' => 'Preparing',
]));
$cancelledPreparing = orange_restore_fw_cancel($workRoot, (string) $prepJob['job_id'], 'superadmin');
restore_admin_self_test(($cancelledPreparing['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_CANCELLED, 'framework: cancel preparing job');

$completedJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_fw_release_lock($workRoot, (string) $completedJob['job_id']);
orange_restore_fw_write($workRoot, array_merge($completedJob, [
    'status' => ORANGE_RESTORE_FW_STATUS_COMPLETED,
    'phase' => ORANGE_RESTORE_FW_PHASE_COMPLETED,
    'message' => 'Completed',
]));
$cannotCancelCompleted = false;
try {
    orange_restore_fw_cancel($workRoot, (string) $completedJob['job_id'], 'superadmin');
} catch (Throwable) {
    $cannotCancelCompleted = true;
}
restore_admin_self_test($cannotCancelCompleted, 'framework: cannot cancel completed job');

$permDenied = false;
try {
    orange_restore_admin_fw_create_job(
        $backupRoot,
        $workRoot,
        $noPermAdmin,
        $noPermPdo,
        'full_disaster',
        $fullPkgId,
        ''
    );
} catch (Throwable $e) {
    $permDenied = str_contains($e->getMessage(), 'permission');
}
restore_admin_self_test($permDenied, 'framework: permissions enforced on create');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['orange_backup_admin_csrf'] = 'framework-csrf-token-test';
$csrfRejected = false;
try {
    orange_backup_admin_verify_csrf('wrong-token');
} catch (Throwable $e) {
    $csrfRejected = str_contains($e->getMessage(), 'CSRF');
}
restore_admin_self_test($csrfRejected, 'framework: CSRF rejection works');
orange_backup_admin_verify_csrf('framework-csrf-token-test');
restore_admin_self_test(true, 'framework: CSRF acceptance works');

$activeAfterCancel = orange_restore_fw_find_active_job($workRoot);
restore_admin_self_test($activeAfterCancel === null, 'framework: no single active job after cancel/completed');

$fwLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_job_framework.php');
restore_admin_self_test(!str_contains($fwLib, 'mysqli_query'), 'framework: no SQL execution');
restore_admin_self_test(!str_contains($fwLib, 'restoring'), 'framework: no restoring status');

$dryApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'dry-run.php');
restore_admin_self_test(str_contains($dryApiSource, 'restore_admin_api_require_csrf'), 'api: dry-run requires CSRF');
restore_admin_self_test(!str_contains(strtolower($dryApiSource), 'orchestrator'), 'api: dry-run has no orchestrator');

// --- Dry run engine cases ---
$dryOkJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$dryOk = orange_restore_dry_run_execute($workRoot, (string) $dryOkJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($dryOk['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED, 'dry-run: successful dry run completes');
restore_admin_self_test(($dryOk['report']['overall_result'] ?? '') === 'PASS', 'dry-run: successful overall PASS');
restore_admin_self_test(($dryOk['report']['execution_performed'] ?? true) === false, 'dry-run: execution_performed false');
restore_admin_self_test(is_file(orange_restore_dry_run_report_path($workRoot, (string) $dryOkJob['job_id'])), 'dry-run: report file written');
restore_admin_self_test(($dryOk['job']['progress'] ?? 0) === 100, 'dry-run: progress reaches 100');

$missingPkgId = '2099-01-01_000001';
$missingJob = orange_restore_fw_create($workRoot, [
    'package_id' => $missingPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$missingDry = orange_restore_dry_run_execute($workRoot, (string) $missingJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($missingDry['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_DRY_FAILED, 'dry-run: missing package => dry_failed');
restore_admin_self_test(($missingDry['report']['overall_result'] ?? '') === 'FAIL', 'dry-run: missing package overall FAIL');

$corruptId = '2026-07-01_050000';
$corruptDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $corruptId;
mkdir($corruptDir, 0775, true);
file_put_contents($corruptDir . DIRECTORY_SEPARATOR . 'manifest.json', '{not-json');
orange_backup_write_json($corruptDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
$corruptJob = orange_restore_fw_create($workRoot, [
    'package_id' => $corruptId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$corruptDry = orange_restore_dry_run_execute($workRoot, (string) $corruptJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($corruptDry['report']['overall_result'] ?? '') === 'FAIL', 'dry-run: corrupt manifest => FAIL');

$drvFailJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullFailedDrvId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$drvFailDry = orange_restore_dry_run_execute($workRoot, (string) $drvFailJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($drvFailDry['report']['overall_result'] ?? '') === 'FAIL', 'dry-run: DRV fail => FAIL');

$schemaMismatchId = '2026-07-01_049000';
$schemaMismatchDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $schemaMismatchId;
restore_admin_test_seed_full_dry_package($schemaMismatchDir, $schemaMismatchId, ['schema_revision' => 99]);
$schemaJob = orange_restore_fw_create($workRoot, [
    'package_id' => $schemaMismatchId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$schemaDry = orange_restore_dry_run_execute($workRoot, (string) $schemaJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$schemaCheck = array_values(array_filter($schemaDry['report']['checks'] ?? [], static fn ($c) => ($c['id'] ?? '') === 'schema'));
restore_admin_self_test(($schemaCheck[0]['result'] ?? '') === 'fail', 'dry-run: schema mismatch detected');

$backendMismatchId = '2026-07-01_048000';
$backendMismatchDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $backendMismatchId;
restore_admin_test_seed_full_dry_package($backendMismatchDir, $backendMismatchId, ['export_backend' => 'mysqldump']);
$backendJob = orange_restore_fw_create($workRoot, [
    'package_id' => $backendMismatchId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$backendDry = orange_restore_dry_run_execute($workRoot, (string) $backendJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$backendCheck = array_values(array_filter($backendDry['report']['checks'] ?? [], static fn ($c) => ($c['id'] ?? '') === 'backend'));
restore_admin_self_test(($backendCheck[0]['result'] ?? '') === 'fail', 'dry-run: backend mismatch detected');

$registryJob = orange_restore_fw_create($workRoot, [
    'package_id' => $countryBadRegistryId,
    'package_type' => 'country_recovery',
    'country_code' => 'KW',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$registryDry = orange_restore_dry_run_execute($workRoot, (string) $registryJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$registryCheck = array_values(array_filter($registryDry['report']['checks'] ?? [], static fn ($c) => ($c['id'] ?? '') === 'registry'));
restore_admin_self_test(($registryCheck[0]['result'] ?? '') === 'fail', 'dry-run: registry mismatch detected');

$lowDiskJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$lowDiskDry = orange_restore_dry_run_execute($workRoot, (string) $lowDiskJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
    'disk_free_bytes_override' => 1024,
]);
$diskCheck = array_values(array_filter($lowDiskDry['report']['checks'] ?? [], static fn ($c) => ($c['id'] ?? '') === 'free_disk_space'));
restore_admin_self_test(($diskCheck[0]['result'] ?? '') === 'fail', 'dry-run: low disk detected');

$noUploadsId = '2026-07-01_047000';
$noUploadsDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $noUploadsId;
restore_admin_test_seed_full_dry_package($noUploadsDir, $noUploadsId);
@unlink($noUploadsDir . DIRECTORY_SEPARATOR . 'uploads.zip');
$noUploadsJob = orange_restore_fw_create($workRoot, [
    'package_id' => $noUploadsId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$noUploadsDry = orange_restore_dry_run_execute($workRoot, (string) $noUploadsJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($noUploadsDry['report']['overall_result'] ?? '') === 'FAIL', 'dry-run: missing uploads => FAIL');

$dryPermDenied = false;
try {
    orange_restore_admin_fw_dry_run(
        $backupRoot,
        $workRoot,
        $noPermAdmin,
        $noPermPdo,
        '',
        'full_disaster',
        $fullPkgId,
        ''
    );
} catch (Throwable $e) {
    $dryPermDenied = str_contains($e->getMessage(), 'permission');
}
restore_admin_self_test($dryPermDenied, 'dry-run: permission failure enforced');

$dryLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_dry_run.php');
restore_admin_self_test(!str_contains($dryLib, 'mysqli_query') && !str_contains($dryLib, '->query('), 'dry-run: no SQL execution');
restore_admin_self_test(!str_contains($dryLib, 'extractTo') && !str_contains($dryLib, 'ZipArchive::EXTRACT'), 'dry-run: no archive extraction');

$restoreAdminLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php');
restore_admin_self_test(!str_contains($restoreAdminLib, 'function orange_restore_orchestrator_approve'), 'lib: restore_admin does not define mutating orchestrator wrappers');
restore_admin_self_test(!str_contains($restoreAdminLib, 'orange_restore_e2e_start_full'), 'lib: restore_admin does not expose e2e start');

// --- Phase 3B.3A execution orchestrator (metadata plan only) ---
$prepareApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'prepare-execution.php');
$execPlanApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'execution-plan.php');
$cancelExecApiSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'cancel-execution.php');
$execOrchLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_orchestrator.php');
restore_admin_self_test(str_contains($prepareApiSource, 'restore_admin_api_require_csrf'), 'api: prepare-execution requires CSRF');
restore_admin_self_test(str_contains($prepareApiSource, 'restore_admin_api_require_post'), 'api: prepare-execution POST-only');
restore_admin_self_test(str_contains($execPlanApiSource, 'restore_admin_api_require_get'), 'api: execution-plan GET-only');
restore_admin_self_test(str_contains($cancelExecApiSource, 'restore_admin_api_require_csrf'), 'api: cancel-execution requires CSRF');
restore_admin_self_test(!str_contains($execOrchLib, 'mysqli_query') && !str_contains($execOrchLib, '->query('), 'exec-orch: no SQL execution');
restore_admin_self_test(!str_contains($execOrchLib, 'extractTo') && !str_contains($execOrchLib, 'ZipArchive'), 'exec-orch: no archive extraction');
restore_admin_self_test(!str_contains($execOrchLib, 'restoring_database') && !str_contains($execOrchLib, 'restoring_files'), 'exec-orch: no restoring_* states');
restore_admin_self_test(!str_contains($execOrchLib, 'orange_restore_e2e_') && !str_contains($execOrchLib, 'orange_restore_orchestrator_approve'), 'exec-orch: no production restore functions');

$execPrepare = orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $dryOkJob['job_id']
);
restore_admin_self_test(($execPrepare['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'exec-orch: successful plan stops at awaiting_final_approval');
restore_admin_self_test(($execPrepare['plan']['execution_started'] ?? true) === false, 'exec-orch: execution_started always false');
restore_admin_self_test(($execPrepare['plan']['requires_final_approval'] ?? false) === true, 'exec-orch: final approval always required');
$planPath = orange_restore_exec_plan_path($workRoot, (string) $dryOkJob['job_id']);
restore_admin_self_test(is_file($planPath), 'exec-orch: execution_plan.json written');
$planRaw = orange_restore_exec_read_plan($workRoot, (string) $dryOkJob['job_id']);
$requiredPlanFields = [
    'plan_version', 'job_id', 'package_id', 'package_type', 'country_code', 'created_at', 'created_by',
    'package_fingerprint', 'dry_run_fingerprint', 'preconditions', 'planned_stages', 'safety_gates',
    'rollback_strategy', 'estimated_duration', 'requires_final_approval', 'execution_started',
];
$planFieldsOk = true;
foreach ($requiredPlanFields as $field) {
    if (!array_key_exists($field, $planRaw)) {
        $planFieldsOk = false;
        break;
    }
}
restore_admin_self_test($planFieldsOk, 'exec-orch: execution_plan.json fields complete');
restore_admin_self_test(($planRaw['execution_started'] ?? true) === false, 'exec-orch: plan execution_started false');
restore_admin_self_test(is_array($planRaw['planned_stages'] ?? null) && count($planRaw['planned_stages']) >= 8, 'exec-orch: planned_stages describe future ops');
$lockAfterPrepare = orange_restore_exec_lock_status($workRoot);
restore_admin_self_test(($lockAfterPrepare['held'] ?? false) === true, 'exec-orch: lock held after plan ready');

$publicPlan = orange_restore_admin_fw_execution_plan($workRoot, true, true, (string) $dryOkJob['job_id']);
$planJson = (string) json_encode($publicPlan, JSON_UNESCAPED_UNICODE);
restore_admin_self_test(!str_contains($planJson, $backupRoot) && !str_contains($planJson, $workRoot), 'exec-orch: safe redaction no absolute roots');
restore_admin_self_test(!isset($publicPlan['package_path']) && !isset($publicPlan['secrets']), 'exec-orch: public plan strips sensitive keys');

$dupLockJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$dupDry = orange_restore_dry_run_execute($workRoot, (string) $dupLockJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($dupDry['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED, 'exec-orch: second dry job ready for lock test');
$dupRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $dupLockJob['job_id']
    );
} catch (Throwable $e) {
    $dupRejected = trim($e->getMessage()) === 'execution_orchestration_already_active';
}
restore_admin_self_test($dupRejected, 'exec-orch: duplicate execution orchestration lock rejected');

$cancelledPlan = orange_restore_admin_fw_cancel_execution_plan(
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $dryOkJob['job_id'],
    'self-test cancel'
);
restore_admin_self_test(($cancelledPlan['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED, 'exec-orch: cancel sets execution_cancelled');
restore_admin_self_test(is_file($planPath), 'exec-orch: cancel preserves execution_plan.json');
$lockAfterCancel = orange_restore_exec_lock_status($workRoot);
restore_admin_self_test(($lockAfterCancel['held'] ?? false) === false, 'exec-orch: cancel plan releases lock');

$cancelThenPrepareRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $dryOkJob['job_id']
    );
} catch (Throwable $e) {
    $cancelThenPrepareRejected = trim($e->getMessage()) === 'execution_plan_cancelled_reset_required';
}
restore_admin_self_test($cancelThenPrepareRejected, 'exec-orch: cancelled plan cannot prepare without reset');

// After lock released, prepare the duplicate dry-completed job.
$execPrepare2 = orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $dupLockJob['job_id']
);
restore_admin_self_test(($execPrepare2['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'exec-orch: prepare after lock release works');
orange_restore_admin_fw_cancel_execution_plan(
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $dupLockJob['job_id']
);

// WARNING policy: Full WARNING allowed; Country WARNING rejected.
$warnFullId = '2026-07-01_046000';
$warnFullDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $warnFullId;
restore_admin_test_seed_full_dry_package($warnFullDir, $warnFullId);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($warnFullDir, $warnFullId),
    [
        'overall_result' => 'warning',
        'recovery_score' => 80,
        'validated_at' => gmdate('c'),
        'validation_engine_version' => ORANGE_RECOVERY_VALIDATION_ENGINE_VERSION,
        'manifest_valid' => true,
    ]
);
$warnFullJob = orange_restore_fw_create($workRoot, [
    'package_id' => $warnFullId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$warnFullDry = orange_restore_dry_run_execute($workRoot, (string) $warnFullJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($warnFullDry['report']['overall_result'] ?? '') === 'WARNING', 'exec-orch: full warning dry-run overall WARNING');
$warnFullPrepare = orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $warnFullJob['job_id']
);
restore_admin_self_test(($warnFullPrepare['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'exec-orch: WARNING follows approved Full policy');
orange_restore_admin_fw_cancel_execution_plan($workRoot, ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1], $superPdo, (string) $warnFullJob['job_id']);

$countryWarnJob = orange_restore_fw_create($workRoot, [
    'package_id' => $countryPkgId,
    'package_type' => 'country_recovery',
    'country_code' => 'KW',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$countryWarnDry = orange_restore_dry_run_execute($workRoot, (string) $countryWarnJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
restore_admin_self_test(($countryWarnDry['report']['overall_result'] ?? '') === 'PASS', 'exec-orch: country dry PASS baseline');
$countryReportPath = orange_restore_dry_run_report_path($workRoot, (string) $countryWarnJob['job_id']);
$countryReport = orange_restore_dry_run_read_report($workRoot, (string) $countryWarnJob['job_id']);
$countryReport['overall_result'] = 'WARNING';
file_put_contents($countryReportPath, json_encode($countryReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$countryJobRow = orange_restore_fw_read($workRoot, (string) $countryWarnJob['job_id']);
$countryJobRow['dry_run_overall_result'] = 'WARNING';
$countryJobRow['dry_run_fingerprint'] = hash_file('sha256', $countryReportPath) ?: '';
orange_restore_fw_write($workRoot, $countryJobRow);
$countryWarnRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $countryWarnJob['job_id']
    );
} catch (Throwable $e) {
    $countryWarnRejected = trim($e->getMessage()) === 'dry_run_warning_not_approved_for_package_type';
}
restore_admin_self_test($countryWarnRejected, 'exec-orch: country WARNING rejected by policy');

// FAIL dry run rejected
$failDryRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $missingJob['job_id']
    );
} catch (Throwable $e) {
    $failDryRejected = in_array(trim($e->getMessage()), ['dry_run_failed', 'Execution plan requires dry_completed status.'], true);
}
restore_admin_self_test($failDryRejected, 'exec-orch: FAIL Dry Run rejected');

// Missing Dry Run rejected
$noDryJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_fw_release_lock($workRoot, (string) $noDryJob['job_id']);
orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $noDryJob['job_id']), [
    'status' => ORANGE_RESTORE_FW_STATUS_DRY_COMPLETED,
    'phase' => ORANGE_RESTORE_FW_PHASE_DRY_COMPLETED,
    'dry_run_overall_result' => 'PASS',
]));
$missingDryRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $noDryJob['job_id']
    );
} catch (Throwable $e) {
    $missingDryRejected = trim($e->getMessage()) === 'dry_run_report_missing';
}
restore_admin_self_test($missingDryRejected, 'exec-orch: missing Dry Run rejected');

// execution_performed=true rejected
$perfJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$perfDry = orange_restore_dry_run_execute($workRoot, (string) $perfJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$perfReportPath = orange_restore_dry_run_report_path($workRoot, (string) $perfJob['job_id']);
$perfReport = orange_restore_dry_run_read_report($workRoot, (string) $perfJob['job_id']);
$perfReport['execution_performed'] = true;
file_put_contents($perfReportPath, json_encode($perfReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$perfJobRow = orange_restore_fw_read($workRoot, (string) $perfJob['job_id']);
$perfJobRow['dry_run_fingerprint'] = hash_file('sha256', $perfReportPath) ?: '';
orange_restore_fw_write($workRoot, $perfJobRow);
$perfRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $perfJob['job_id']
    );
} catch (Throwable $e) {
    $perfRejected = trim($e->getMessage()) === 'execution_already_performed';
}
restore_admin_self_test($perfRejected, 'exec-orch: execution_performed=true rejected');

// package changed after Dry Run
$changedJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $changedJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$manifestPath = $fullPkgDir . DIRECTORY_SEPARATOR . 'manifest.json';
$manifest = json_decode((string) file_get_contents($manifestPath), true);
$manifest['table_count'] = 999;
file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$changedRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $changedJob['job_id']
    );
} catch (Throwable $e) {
    $changedRejected = trim($e->getMessage()) === 'package_changed_after_dry_run';
}
restore_admin_self_test($changedRejected, 'exec-orch: package changed after Dry Run rejected');
// restore manifest for later tests
$manifest['table_count'] = 1;
file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

// mismatched package type
$typeJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $typeJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$typeReportPath = orange_restore_dry_run_report_path($workRoot, (string) $typeJob['job_id']);
$typeReport = orange_restore_dry_run_read_report($workRoot, (string) $typeJob['job_id']);
$typeReport['package_type'] = 'country_recovery';
file_put_contents($typeReportPath, json_encode($typeReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$typeJobRow = orange_restore_fw_read($workRoot, (string) $typeJob['job_id']);
$typeJobRow['dry_run_fingerprint'] = hash_file('sha256', $typeReportPath) ?: '';
orange_restore_fw_write($workRoot, $typeJobRow);
$typeRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $typeJob['job_id']
    );
} catch (Throwable $e) {
    $typeRejected = trim($e->getMessage()) === 'package_type_mismatch';
}
restore_admin_self_test($typeRejected, 'exec-orch: mismatched package type rejected');

// mismatched country
$ccJob = orange_restore_fw_create($workRoot, [
    'package_id' => $countryPkgId,
    'package_type' => 'country_recovery',
    'country_code' => 'KW',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $ccJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$ccReportPath = orange_restore_dry_run_report_path($workRoot, (string) $ccJob['job_id']);
$ccReport = orange_restore_dry_run_read_report($workRoot, (string) $ccJob['job_id']);
$ccReport['country_code'] = 'SA';
file_put_contents($ccReportPath, json_encode($ccReport, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$ccJobRow = orange_restore_fw_read($workRoot, (string) $ccJob['job_id']);
$ccJobRow['dry_run_fingerprint'] = hash_file('sha256', $ccReportPath) ?: '';
orange_restore_fw_write($workRoot, $ccJobRow);
$ccRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $ccJob['job_id']
    );
} catch (Throwable $e) {
    $ccRejected = trim($e->getMessage()) === 'country_code_mismatch';
}
restore_admin_self_test($ccRejected, 'exec-orch: mismatched country rejected');

// incompatible schema/backend
$schemaExecId = '2026-07-01_045000';
$schemaExecDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $schemaExecId;
restore_admin_test_seed_full_dry_package($schemaExecDir, $schemaExecId);
$schemaExecJob = orange_restore_fw_create($workRoot, [
    'package_id' => $schemaExecId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $schemaExecJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$schemaManifest = json_decode((string) file_get_contents($schemaExecDir . DIRECTORY_SEPARATOR . 'manifest.json'), true);
$schemaManifest['schema_revision'] = 1;
// Rewrite checksums file to keep package eligible-ish; prepare checks schema_revision vs expected.
file_put_contents(
    $schemaExecDir . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($schemaManifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);
// Clear bound fingerprint so prepare uses live schema check instead of package_changed.
$schemaJobRow = orange_restore_fw_read($workRoot, (string) $schemaExecJob['job_id']);
$schemaJobRow['package_fingerprint'] = '';
orange_restore_fw_write($workRoot, $schemaJobRow);
$schemaRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $schemaExecJob['job_id']
    );
} catch (Throwable $e) {
    $schemaRejected = in_array(trim($e->getMessage()), ['schema_incompatible', 'package_changed_after_dry_run', 'package_not_eligible'], true);
}
restore_admin_self_test($schemaRejected, 'exec-orch: incompatible schema/backend rejected');

$backendExecId = '2026-07-01_044000';
$backendExecDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $backendExecId;
restore_admin_test_seed_full_dry_package($backendExecDir, $backendExecId, ['export_backend' => 'php_pdo']);
$backendExecJob = orange_restore_fw_create($workRoot, [
    'package_id' => $backendExecId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $backendExecJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$backendManifest = json_decode((string) file_get_contents($backendExecDir . DIRECTORY_SEPARATOR . 'manifest.json'), true);
$backendManifest['export_backend'] = 'mysqldump';
file_put_contents(
    $backendExecDir . DIRECTORY_SEPARATOR . 'manifest.json',
    json_encode($backendManifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);
$backendJobRow = orange_restore_fw_read($workRoot, (string) $backendExecJob['job_id']);
$backendJobRow['package_fingerprint'] = '';
orange_restore_fw_write($workRoot, $backendJobRow);
$backendRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $backendExecJob['job_id']
    );
} catch (Throwable $e) {
    $backendRejected = in_array(trim($e->getMessage()), ['backend_incompatible', 'package_changed_after_dry_run', 'package_not_eligible'], true);
}
restore_admin_self_test($backendRejected, 'exec-orch: incompatible backend rejected');

// stale lock handling — clear stale lock and allow prepare
$staleJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $staleJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$staleLockPath = orange_restore_exec_lock_path($workRoot);
file_put_contents($staleLockPath, json_encode([
    'job_id' => 'rstfw_stale_other',
    'pid' => 999999991,
    'started_at' => gmdate('c', time() - 400000),
    'orchestrator_version' => ORANGE_RESTORE_EXEC_ORCH_VERSION,
], JSON_UNESCAPED_UNICODE) . "\n");
$stalePrepare = orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $staleJob['job_id']
);
restore_admin_self_test(($stalePrepare['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'exec-orch: stale lock cleared then prepare succeeds');
orange_restore_admin_fw_cancel_execution_plan($workRoot, ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1], $superPdo, (string) $staleJob['job_id']);

// completed/failed jobs rejected
$termJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_fw_release_lock($workRoot, (string) $termJob['job_id']);
orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $termJob['job_id']), [
    'status' => ORANGE_RESTORE_FW_STATUS_COMPLETED,
    'phase' => ORANGE_RESTORE_FW_PHASE_COMPLETED,
]));
$termRejected = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $termJob['job_id']
    );
} catch (Throwable $e) {
    $termRejected = str_contains($e->getMessage(), 'terminal') || str_contains($e->getMessage(), 'dry_completed');
}
restore_admin_self_test($termRejected, 'exec-orch: completed/failed jobs rejected');

$fullDryForPerm = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $fullDryForPerm['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$execPermDenied = false;
try {
    orange_restore_admin_fw_prepare_execution(
        $backupRoot,
        $workRoot,
        $countryOnlyAdmin,
        $countryOnlyPdo,
        (string) $fullDryForPerm['job_id']
    );
} catch (Throwable $e) {
    $execPermDenied = str_contains($e->getMessage(), 'permission');
}
restore_admin_self_test($execPermDenied, 'exec-orch: permission separation Full/Country');

// --- Phase 3B.3B1 final approval + maintenance framework ---
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['orange_backup_admin_csrf'] = 'approval-csrf-token-test';

$maintDefault = orange_restore_maint_fw_read($workRoot);
restore_admin_self_test(($maintDefault['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_INACTIVE, 'maint: default inactive');

$vOk = orange_restore_version_lock_evaluate($workRoot, (string) $fullDryForPerm['job_id'], $backupRoot);
// fullDryForPerm may be dry_completed without plan — expect plan missing
restore_admin_self_test(($vOk['ok'] ?? true) === false && in_array('version_plan_missing', $vOk['reasons'] ?? [], true), 'version-lock: missing plan incompatible');

$approveJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $approveJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
$approvePrepared = orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob['job_id']
);
restore_admin_self_test(($approvePrepared['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_AWAITING_FINAL_APPROVAL, 'approval: plan ready for challenge');

$vCompat = orange_restore_version_lock_evaluate($workRoot, (string) $approveJob['job_id'], $backupRoot);
restore_admin_self_test(($vCompat['ok'] ?? false) === true, 'version-lock: all compatible for prepared Full job');

$challenge = orange_restore_admin_fw_create_approval_challenge(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob['job_id']
);
restore_admin_self_test(($challenge['nonce'] ?? '') !== '' && str_contains((string) ($challenge['required_confirmation_phrase'] ?? ''), 'RESTORE '), 'approval: valid challenge creation');
$expectedPhrase = orange_restore_final_approval_phrase($fullPkgId, (string) $approveJob['job_id']);
restore_admin_self_test(($challenge['required_confirmation_phrase'] ?? '') === $expectedPhrase, 'approval: deterministic phrase with ids');

$wrongStatusRejected = false;
try {
    orange_restore_admin_fw_create_approval_challenge(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $fullDryForPerm['job_id']
    );
} catch (Throwable $e) {
    $wrongStatusRejected = trim($e->getMessage()) === 'invalid_status' || trim($e->getMessage()) === 'execution_lock_not_held' || trim($e->getMessage()) === 'execution_plan_missing';
}
restore_admin_self_test($wrongStatusRejected, 'approval: wrong status rejected');

$countryApproveJob = orange_restore_fw_create($workRoot, [
    'package_id' => $countryPkgId,
    'package_type' => 'country_recovery',
    'country_code' => 'KW',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $countryApproveJob['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
// Cancel Full approval lock first so country can prepare.
orange_restore_admin_fw_cancel_execution_plan(
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob['job_id']
);
orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $countryApproveJob['job_id']
);
$countryBlocked = false;
try {
    orange_restore_admin_fw_create_approval_challenge(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $countryApproveJob['job_id']
    );
} catch (Throwable $e) {
    $countryBlocked = trim($e->getMessage()) === 'country_production_restore_not_enabled';
}
restore_admin_self_test($countryBlocked, 'approval: Country production approval rejected');
orange_restore_admin_fw_cancel_execution_plan(
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $countryApproveJob['job_id']
);

// Re-prepare Full job for approval success path (re-dry after cancel).
$approveJob2 = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $approveJob2['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob2['job_id']
);
$challenge2 = orange_restore_admin_fw_create_approval_challenge(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob2['job_id']
);

$noPassword = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob2['job_id'],
        $fullPkgId,
        (string) $challenge2['required_confirmation_phrase'],
        (string) $challenge2['nonce'],
        ''
    );
} catch (Throwable $e) {
    $noPassword = trim($e->getMessage()) === 'recent_authentication_not_available';
}
restore_admin_self_test($noPassword, 'approval: recent-auth absence blocks approval');

$wrongPhrase = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob2['job_id'],
        $fullPkgId,
        'WRONG PHRASE',
        (string) $challenge2['nonce'],
        'restore-test-password'
    );
} catch (Throwable $e) {
    $wrongPhrase = trim($e->getMessage()) === 'confirmation_phrase_mismatch';
}
restore_admin_self_test($wrongPhrase, 'approval: wrong phrase rejected');

$granted = orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob2['job_id'],
    $fullPkgId,
    (string) $challenge2['required_confirmation_phrase'],
    (string) $challenge2['nonce'],
    'restore-test-password'
);
restore_admin_self_test(($granted['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'approval: Full reaches approved_waiting_execution');
restore_admin_self_test(($granted['approval']['execution_started'] ?? true) === false, 'approval: execution_started remains false');
restore_admin_self_test(($granted['approval']['maintenance_enabled'] ?? true) === false, 'approval: no maintenance enabled');
restore_admin_self_test(($granted['approval']['cli_invoked'] ?? true) === false, 'approval: no CLI invoked');
restore_admin_self_test(is_file(orange_restore_final_approval_record_path($workRoot, (string) $approveJob2['job_id'])), 'approval: final_approval.json written');
restore_admin_self_test(is_file(orange_restore_exec_contract_path($workRoot, (string) $approveJob2['job_id'])), 'bridge: contract written on final approval');
restore_admin_self_test(($granted['execution_contract']['execution_started'] ?? true) === false, 'bridge: grant payload execution_started false');
$maintAfterApprove = orange_restore_maint_fw_read($workRoot);
restore_admin_self_test(($maintAfterApprove['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_INACTIVE, 'approval: production maintenance remains inactive');

$dupApprove = false;
try {
    $chReplay = orange_restore_admin_fw_create_approval_challenge(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob2['job_id']
    );
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob2['job_id'],
        $fullPkgId,
        (string) ($chReplay['required_confirmation_phrase'] ?? ''),
        (string) ($chReplay['nonce'] ?? ''),
        'restore-test-password'
    );
} catch (Throwable $e) {
    $dupApprove = in_array(trim($e->getMessage()), ['already_approved', 'invalid_status'], true);
}
restore_admin_self_test($dupApprove, 'approval: duplicate approval rejected');

// Nonce replay on a fresh job
$approveJob3 = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
// Must cancel approved job lock first
orange_restore_exec_release_lock($workRoot, (string) $approveJob2['job_id']);
orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $approveJob2['job_id']), [
    'status' => ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
    'phase' => ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED,
]));
orange_restore_dry_run_execute($workRoot, (string) $approveJob3['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob3['job_id']
);
$ch3 = orange_restore_admin_fw_create_approval_challenge(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob3['job_id']
);
orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob3['job_id'],
    $fullPkgId,
    (string) $ch3['required_confirmation_phrase'],
    (string) $ch3['nonce'],
    'restore-test-password'
);
$replay = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob3['job_id'],
        $fullPkgId,
        (string) $ch3['required_confirmation_phrase'],
        (string) $ch3['nonce'],
        'restore-test-password'
    );
} catch (Throwable $e) {
    $replay = in_array(trim($e->getMessage()), ['approval_nonce_used', 'already_approved', 'invalid_status'], true);
}
restore_admin_self_test($replay, 'approval: nonce replay rejected');

// Wrong operator
orange_restore_exec_release_lock($workRoot, (string) $approveJob3['job_id']);
orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $approveJob3['job_id']), [
    'status' => ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
]));
$approveJob4 = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
orange_restore_dry_run_execute($workRoot, (string) $approveJob4['job_id'], [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob4['job_id']
);
$ch4 = orange_restore_admin_fw_create_approval_challenge(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    (string) $approveJob4['job_id']
);
$wrongOp = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 99, 'username' => 'other', 'is_superuser' => 1, 'is_active' => 1],
        restore_admin_test_pdo('', true, 99),
        (string) $approveJob4['job_id'],
        $fullPkgId,
        (string) $ch4['required_confirmation_phrase'],
        (string) $ch4['nonce'],
        'restore-test-password'
    );
} catch (Throwable $e) {
    $wrongOp = in_array(trim($e->getMessage()), ['approval_nonce_wrong_operator', 'recent_authentication_failed'], true);
}
restore_admin_self_test($wrongOp, 'approval: wrong operator rejected');

// Nonce expiry
$chPath = orange_restore_final_approval_challenge_path($workRoot, (string) $approveJob4['job_id']);
$chRaw = json_decode((string) file_get_contents($chPath), true);
$chRaw['expires_at'] = gmdate('c', time() - 10);
file_put_contents($chPath, json_encode($chRaw, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
$expired = false;
try {
    orange_restore_admin_fw_final_approve(
        $backupRoot,
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob4['job_id'],
        $fullPkgId,
        (string) $ch4['required_confirmation_phrase'],
        (string) $ch4['nonce'],
        'restore-test-password'
    );
} catch (Throwable $e) {
    $expired = trim($e->getMessage()) === 'approval_nonce_expired';
}
restore_admin_self_test($expired, 'approval: nonce expiry rejected');

// CSRF source checks
$challengeApiSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'create-approval-challenge.php');
$finalApiSrc = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'final-approve.php');
restore_admin_self_test(str_contains($challengeApiSrc, 'restore_admin_api_require_csrf'), 'api: create-approval-challenge CSRF');
restore_admin_self_test(str_contains($finalApiSrc, 'restore_admin_api_require_csrf'), 'api: final-approve CSRF');
restore_admin_self_test(!str_contains(strtolower($finalApiSrc), 'orange_restore_full_staging') && !str_contains(strtolower($finalApiSrc), 'cutover'), 'api: final-approve has no restore/cutover');

// Maintenance framework fixture transitions
$req = orange_restore_maint_fw_request($workRoot, 'tester', 'job_fixture', 'test');
restore_admin_self_test(($req['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_REQUESTED, 'maint: request transition');
$act = orange_restore_maint_fw_activate($workRoot, 'tester', 'job_fixture');
restore_admin_self_test(($act['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'maint: activate transition');
$clsBlock = orange_restore_maint_fw_classify_request($workRoot, ['scope' => 'order_create', 'method' => 'POST']);
restore_admin_self_test(($clsBlock['allow'] ?? true) === false, 'maint: central policy blocks writes');
$clsRead = orange_restore_maint_fw_classify_request($workRoot, ['is_restore_center_read' => true, 'method' => 'GET']);
restore_admin_self_test(($clsRead['allow'] ?? false) === true, 'maint: safe reads allowed');
$clsBypass = orange_restore_maint_fw_classify_request($workRoot, [
    'scope' => 'order_create',
    'bypass_token' => 'x',
    'method' => 'POST',
]);
restore_admin_self_test(($clsBypass['reason_code'] ?? '') === 'maintenance_bypass_forbidden', 'maint: no query/header/IP bypass');
$cliTok = orange_restore_maint_fw_issue_cli_bypass($workRoot, 'job_fixture', 'order_create', 120);
$clsCli = orange_restore_maint_fw_classify_request($workRoot, [
    'scope' => 'order_create',
    'is_cli' => true,
    'bypass_token' => $cliTok,
    'bypass_job_id' => 'job_fixture',
    'method' => 'POST',
]);
restore_admin_self_test(($clsCli['allow'] ?? false) === true && ($clsCli['action'] ?? '') === 'cli_bypass', 'maint: CLI bypass scoped and job-bound');
$staleState = orange_restore_maint_fw_read($workRoot);
$staleState['heartbeat_at'] = gmdate('c', time() - 400000);
$staleState['activated_at'] = gmdate('c', time() - 400000);
orange_restore_maint_fw_write($workRoot, $staleState);
$staleRead = orange_restore_maint_fw_read($workRoot);
restore_admin_self_test(($staleRead['stale'] ?? false) === true && ($staleRead['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_ACTIVE, 'maint: stale active does not auto-release');
orange_restore_maint_fw_release($workRoot, 'tester');
restore_admin_self_test((orange_restore_maint_fw_read($workRoot)['state'] ?? '') === ORANGE_RESTORE_MAINT_STATE_INACTIVE, 'maint: release returns inactive');

// Version lock reason codes
$planPath = orange_restore_exec_plan_path($workRoot, (string) $approveJob4['job_id']);
if (is_file($planPath)) {
    $planBad = orange_restore_exec_read_plan($workRoot, (string) $approveJob4['job_id']);
    $planBad['plan_version'] = '999';
    orange_restore_exec_write_plan($workRoot, (string) $approveJob4['job_id'], $planBad);
    // Job may not be awaiting — force status for version lock read only
    $vl = orange_restore_version_lock_evaluate($workRoot, (string) $approveJob4['job_id'], $backupRoot);
    restore_admin_self_test(in_array('version_plan_incompatible', $vl['reasons'] ?? [], true), 'version-lock: incompatible restore plan');
}

// --- Phase 3B.3B2 restore bridge / execution contract ---
// Dedicated approved job (prior fixtures may still hold the orchestration lock / active status).
try {
    orange_restore_admin_fw_cancel_execution_plan(
        $workRoot,
        ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
        $superPdo,
        (string) $approveJob4['job_id'],
        'bridge self-test cleanup'
    );
} catch (Throwable) {
    orange_restore_exec_release_lock($workRoot, (string) $approveJob4['job_id']);
    orange_restore_fw_write($workRoot, array_merge(orange_restore_fw_read($workRoot, (string) $approveJob4['job_id']), [
        'status' => ORANGE_RESTORE_FW_STATUS_EXECUTION_CANCELLED,
        'phase' => ORANGE_RESTORE_FW_PHASE_EXECUTION_CANCELLED,
    ]));
}
$bridgeJob = orange_restore_fw_create($workRoot, [
    'package_id' => $fullPkgId,
    'package_type' => 'full_disaster',
    'created_by' => 'superadmin',
    'created_by_admin_id' => 1,
]);
$bridgeJobId = (string) $bridgeJob['job_id'];
orange_restore_dry_run_execute($workRoot, $bridgeJobId, [
    'backup_root' => $backupRoot,
    'operator_username' => 'superadmin',
]);
orange_restore_admin_fw_prepare_execution(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    $bridgeJobId
);
$bridgeChallenge = orange_restore_admin_fw_create_approval_challenge(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    $bridgeJobId
);
$bridgeGranted = orange_restore_admin_fw_final_approve(
    $backupRoot,
    $workRoot,
    ['id' => 1, 'username' => 'superadmin', 'is_superuser' => 1, 'is_active' => 1],
    $superPdo,
    $bridgeJobId,
    $fullPkgId,
    (string) $bridgeChallenge['required_confirmation_phrase'],
    (string) $bridgeChallenge['nonce'],
    'restore-test-password'
);
restore_admin_self_test(($bridgeGranted['job']['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION, 'bridge: dedicated job approved for contract');
restore_admin_self_test(is_file(orange_restore_exec_contract_path($workRoot, $bridgeJobId)), 'bridge: contract file exists after approve');

$contractLoaded = orange_restore_load_execution_contract($workRoot, $bridgeJobId);
restore_admin_self_test(($contractLoaded['contract_version'] ?? '') === ORANGE_RESTORE_EXEC_CONTRACT_VERSION, 'bridge: contract generation version');
restore_admin_self_test(($contractLoaded['execution_started'] ?? true) === false, 'bridge: contract execution_started false');
restore_admin_self_test(($contractLoaded['cli_invoked'] ?? true) === false, 'bridge: contract cli_invoked false');
restore_admin_self_test(($contractLoaded['backend'] ?? '') === 'php_pdo', 'bridge: backend php_pdo');
restore_admin_self_test((int) ($contractLoaded['schema_revision'] ?? 0) === ORANGE_RECOVERY_VALIDATION_EXPECTED_SCHEMA_REVISION, 'bridge: schema_revision locked');
restore_admin_self_test(($contractLoaded['cli_request']['invoked'] ?? true) === false, 'bridge: cli_request.invoked false');

$contractValidate = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot);
restore_admin_self_test(($contractValidate['ok'] ?? false) === true, 'bridge: validation passes for fresh contract');

$adminContract = orange_restore_admin_fw_execution_contract($workRoot, $backupRoot, true, true, $bridgeJobId);
restore_admin_self_test(($adminContract['execution_started'] ?? true) === false && ($adminContract['validation']['ok'] ?? false) === true, 'bridge: admin GET helper read-only ok');

// package fingerprint mismatch
$contractTamper = $contractLoaded;
$contractTamper['package_fingerprint'] = str_repeat('a', 64);
$pkgMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper);
restore_admin_self_test(($pkgMismatch['ok'] ?? true) === false && in_array('package_changed', $pkgMismatch['reasons'] ?? [], true), 'bridge: fingerprint mismatch rejected');

// approval mismatch
$contractTamper2 = $contractLoaded;
$contractTamper2['approval_hash'] = str_repeat('b', 64);
$approvalMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper2);
restore_admin_self_test(($approvalMismatch['ok'] ?? true) === false && in_array('approval_changed', $approvalMismatch['reasons'] ?? [], true), 'bridge: approval mismatch rejected');

// version mismatch
$contractTamper3 = $contractLoaded;
$contractTamper3['contract_version'] = '0-invalid';
$versionMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper3);
restore_admin_self_test(($versionMismatch['ok'] ?? true) === false && in_array('version_mismatch', $versionMismatch['reasons'] ?? [], true), 'bridge: version mismatch rejected');

// schema mismatch
$contractTamper4 = $contractLoaded;
$contractTamper4['schema_revision'] = 1;
$schemaMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper4);
restore_admin_self_test(($schemaMismatch['ok'] ?? true) === false && in_array('schema_mismatch', $schemaMismatch['reasons'] ?? [], true), 'bridge: schema mismatch rejected');

// backend mismatch
$contractTamper5 = $contractLoaded;
$contractTamper5['backend'] = 'mysqldump';
$backendMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper5);
restore_admin_self_test(($backendMismatch['ok'] ?? true) === false && in_array('backend_mismatch', $backendMismatch['reasons'] ?? [], true), 'bridge: backend mismatch rejected');

// plan hash mismatch
$contractTamper6 = $contractLoaded;
$contractTamper6['execution_plan_hash'] = str_repeat('c', 64);
$planMismatch = orange_restore_validate_execution_contract($workRoot, $bridgeJobId, $backupRoot, $contractTamper6);
restore_admin_self_test(($planMismatch['ok'] ?? true) === false && in_array('plan_changed', $planMismatch['reasons'] ?? [], true), 'bridge: plan hash mismatch rejected');

$bridgeLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_execution_bridge.php');
$bridgeApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'execution-contract.php');
restore_admin_self_test(
    !str_contains($bridgeLib, 'shell_exec') && !str_contains($bridgeLib, 'proc_open')
    && !str_contains($bridgeLib, 'orange_restore_e2e_start_full(')
    && !str_contains($bridgeLib, 'orange_restore_full_staging_run(')
    && !str_contains($bridgeLib, 'mysqli_query'),
    'bridge: no CLI/SQL/staging execution calls'
);
restore_admin_self_test(
    str_contains($bridgeLib, 'function orange_restore_prepare_execution_contract')
    && str_contains($bridgeLib, 'function orange_restore_validate_execution_contract')
    && str_contains($bridgeLib, 'function orange_restore_load_execution_contract')
    && !str_contains($bridgeLib, 'function orange_restore_execute')
    && !str_contains($bridgeLib, 'function orange_restore_invoke_cli'),
    'bridge: only prepare/validate/load helpers exposed'
);
restore_admin_self_test(
    str_contains($bridgeApi, 'restore_admin_api_require_get')
    && !str_contains($bridgeApi, 'orange_restore_prepare_execution_contract')
    && !str_contains(strtolower($bridgeApi), 'execute'),
    'api: execution-contract is GET/read-only and does not prepare/execute'
);
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'RESTORE_PHASE2_CLI_ENTRYPOINTS.md'), 'bridge: Phase 2 CLI discovery doc present');
restore_admin_self_test(count(orange_restore_bridge_phase2_cli_entrypoints()) >= 8, 'bridge: Phase 2 CLI entrypoints catalogued');

$finalApprovalLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_final_approval.php');
$maintLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_maintenance_framework.php');
restore_admin_self_test(!str_contains($finalApprovalLib, 'mysqli_query') && !str_contains($finalApprovalLib, 'orange_restore_full_staging_run'), 'approval: no SQL / staging restore');
restore_admin_self_test(!str_contains($maintLib, 'extractTo') && !str_contains($maintLib, 'shell_exec'), 'maint: no extraction/shell');
restore_admin_self_test(!is_dir($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'execute.php'), 'regression: no execute endpoint file');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'run.php'), 'regression: no run endpoint file');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'resume.php'), 'regression: no resume endpoint file');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'unpin.php'), 'regression: no unpin endpoint');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_prepare_backup.php'), 'pre-backup: obsolete CLI worker deleted');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-pre-restore-backup.php'), 'pre-backup: request API present');
$preBackupLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_pre_restore_backup.php');
restore_admin_self_test(
    str_contains($preBackupLib, 'orange_backup_execute_full_authoritative')
    && str_contains($preBackupLib, 'orange_backup_verify_full_package')
    && str_contains($preBackupLib, 'orange_recovery_validate_package')
    && str_contains($preBackupLib, 'orange_backup_retention_pin_package')
    && !str_contains($preBackupLib, 'orange_restore_full_staging_run(')
    && !preg_match('/\$raw\s*=\s*orange_backup_run_full\s*\(/', $preBackupLib),
    'pre-backup: shared Full service + Verify/DRV/pin without staging restore'
);
$reqApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-pre-restore-backup.php');
restore_admin_self_test(
    str_contains($reqApi, 'orange_restore_admin_fw_execute_pre_restore_backup')
    && !str_contains($reqApi, 'attach_verified_schedule')
    && !str_contains($reqApi, 'orange_backup_run_full('),
    'pre-backup: HTTP Step6 adapter uses shared service (no orchestrator)'
);

restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_shadow_db.php'), 'shadow: CLI entry present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-restore.php'), 'shadow: request API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-restore.php'), 'shadow: status API present');
$shadowLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_db.php');
restore_admin_self_test(
    str_contains($shadowLib, 'orange_restore_sql_runner_import_gzip')
    && str_contains($shadowLib, 'shadow_restore_report.json')
    && str_contains($shadowLib, 'ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY')
    && !str_contains($shadowLib, 'orange_restore_orchestrator_database_cutover(')
    && !str_contains($shadowLib, 'orange_restore_full_staging_run('),
    'shadow: reuses SQL import; no cutover / full-staging run'
);
$shadowReqApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-restore.php');
restore_admin_self_test(
    !str_contains($shadowReqApi, 'orange_restore_shadow_run_cli')
    && !str_contains($shadowReqApi, 'orange_restore_sql_runner_import_gzip'),
    'shadow: HTTP request does not run import'
);
$shadowGetApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-restore.php');
restore_admin_self_test(
    str_contains($shadowGetApi, 'restore_admin_api_require_get')
    && !str_contains($shadowGetApi, 'orange_restore_shadow_run_cli'),
    'shadow: status API is GET/read-only'
);
$shadowUi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
restore_admin_self_test(
    str_contains($shadowUi, 'rc-shadow-req')
    && str_contains($shadowUi, 'rc-shadow-view')
    && str_contains($shadowUi, 'shadow_restore_ready'),
    'shadow: Restore Center UI wires request/view + ready status'
);

restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php'), 'shadow-verify: CLI entry present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-verification.php'), 'shadow-verify: GET API present');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-verification.php'), 'shadow-verify: no HTTP request endpoint');
$shadowVerifyLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_verify.php');
restore_admin_self_test(
    str_contains($shadowVerifyLib, 'shadow_verification_report.json')
    && str_contains($shadowVerifyLib, 'readiness_score')
    && str_contains($shadowVerifyLib, 'ORANGE_RESTORE_FW_STATUS_SHADOW_VERIFIED')
    && str_contains($shadowVerifyLib, 'ORANGE_RESTORE_FW_STATUS_SHADOW_NOT_READY')
    && !str_contains($shadowVerifyLib, 'orange_restore_orchestrator_database_cutover(')
    && !str_contains($shadowVerifyLib, 'orange_restore_full_staging_run('),
    'shadow-verify: report + score + statuses; no cutover/staging'
);
$shadowVerifyApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-verification.php');
restore_admin_self_test(
    str_contains($shadowVerifyApi, 'restore_admin_api_require_get')
    && !str_contains($shadowVerifyApi, 'orange_restore_shadow_verify_run_cli'),
    'shadow-verify: HTTP is GET/read-only'
);
restore_admin_self_test(
    str_contains($shadowUi, 'rc-shadow-verify-view')
    && str_contains($shadowUi, 'shadow_verified')
    && str_contains($shadowUi, 'shadow_not_ready'),
    'shadow-verify: Restore Center UI wires verification view + statuses'
);

restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_shadow_files.php'), 'shadow-files: CLI entry present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-files.php'), 'shadow-files: GET API present');
restore_admin_self_test(!is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-files.php'), 'shadow-files: no HTTP request endpoint');
$shadowFilesLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_files.php');
restore_admin_self_test(
    str_contains($shadowFilesLib, 'restore_shadow_workspace')
    && str_contains($shadowFilesLib, 'shadow_files_report.json')
    && str_contains($shadowFilesLib, 'ORANGE_RESTORE_FW_STATUS_SHADOW_FILES_READY')
    && str_contains($shadowFilesLib, 'orange_restore_uploads_applicator_extract')
    && !str_contains($shadowFilesLib, 'orange_restore_orchestrator_uploads_cutover(')
    && !str_contains($shadowFilesLib, 'orange_restore_merge_uploads_cutover('),
    'shadow-files: workspace + report + safe extract; no cutover'
);
$shadowFilesApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-files.php');
restore_admin_self_test(
    str_contains($shadowFilesApi, 'restore_admin_api_require_get')
    && !str_contains($shadowFilesApi, 'orange_restore_shadow_files_run_cli'),
    'shadow-files: HTTP is GET/read-only'
);
restore_admin_self_test(
    str_contains($shadowUi, 'rc-shadow-files-view')
    && str_contains($shadowUi, 'shadow_files_ready')
    && str_contains($shadowUi, 'shadow_files_failed'),
    'shadow-files: Restore Center UI wires files view + statuses'
);

restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_shadow_smoke.php'), 'shadow-smoke: CLI entry present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-smoke.php'), 'shadow-smoke: POST request API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-smoke.php'), 'shadow-smoke: GET status API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'cutover-readiness.php'), 'shadow-smoke: GET cutover-readiness API present');
$shadowSmokeLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_shadow_smoke.php');
restore_admin_self_test(
    str_contains($shadowSmokeLib, 'shadow_smoke_report.json')
    && str_contains($shadowSmokeLib, 'cutover_readiness.json')
    && str_contains($shadowSmokeLib, 'orange_restore_shadow_context_assert_read_only')
    && str_contains($shadowSmokeLib, "'production_cutover_allowed' => false")
    && !str_contains($shadowSmokeLib, 'orange_restore_merge_db_cutover('),
    'shadow-smoke: reports + read-only guard; no DB cutover'
);
$shadowSmokeReq = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-shadow-smoke.php');
restore_admin_self_test(
    str_contains($shadowSmokeReq, 'restore_admin_api_require_post')
    && !str_contains($shadowSmokeReq, 'orange_restore_shadow_smoke_run_cli'),
    'shadow-smoke: HTTP request is metadata-only'
);
$shadowSmokeGet = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'shadow-smoke.php');
restore_admin_self_test(
    str_contains($shadowSmokeGet, 'restore_admin_api_require_get')
    && !str_contains($shadowSmokeGet, 'orange_restore_shadow_smoke_run_cli'),
    'shadow-smoke: HTTP status is GET/read-only'
);
restore_admin_self_test(
    str_contains($shadowUi, 'rc-shadow-smoke-req')
    && str_contains($shadowUi, 'rc-shadow-smoke-view')
    && str_contains($shadowUi, 'shadow_smoke_ready')
    && str_contains($shadowUi, 'cutover_readiness_blocked')
    && str_contains($shadowUi, 'لم يتم تعديل قاعدة الإنتاج أو ملفات الإنتاج'),
    'shadow-smoke: Restore Center UI wires request/view + statuses'
);
restore_admin_self_test(
    !is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'execute-cutover.php')
    && !is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'approve-cutover.php'),
    'shadow-smoke: no cutover execute/approve endpoints'
);

// --- Phase 3B.4B production maintenance activation (surface checks) ---
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php'), 'maint-3B.4B: activation lib present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-maintenance.php'), 'maint-3B.4B: request API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'activate-maintenance.php'), 'maint-3B.4B: activate API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'maintenance-state.php'), 'maint-3B.4B: state API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'self_test_maintenance_framework.php'), 'maint-3B.4B: dedicated self-test present');
$prodMaintLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_maintenance.php');
restore_admin_self_test(
    str_contains($prodMaintLib, 'ORANGE_RESTORE_FW_STATUS_MAINTENANCE_ACTIVE')
    && str_contains($prodMaintLib, 'orange_restore_production_maintenance_decide') === false
    && !str_contains($prodMaintLib, 'orange_restore_merge_db_cutover')
    && !str_contains($prodMaintLib, 'orange_restore_production_wipe'),
    'maint-3B.4B: states present; no cutover/wipe'
);
restore_admin_self_test(
    function_exists('orange_restore_production_maintenance_decide')
    && function_exists('orange_restore_prod_maint_request')
    && function_exists('orange_restore_prod_maint_activate'),
    'maint-3B.4B: decision + request/activate helpers exist'
);
$maintUi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
restore_admin_self_test(
    str_contains($maintUi, 'الصيانة جاهزة')
    && str_contains($maintUi, 'الصيانة مفعّلة')
    && str_contains($maintUi, 'استرداد الإنتاج لم يبدأ بعد')
    && str_contains($maintUi, 'rc-maint-req')
    && str_contains($maintUi, 'rc-maint-activate'),
    'maint-3B.4B: Restore Center UI wires ready/active warning + controls'
);
$actMaintApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'activate-maintenance.php');
restore_admin_self_test(
    str_contains($actMaintApi, 'framework_activation_only')
    && !str_contains($actMaintApi, 'orange_restore_sql_runner_import_gzip')
    && !str_contains($actMaintApi, 'proc_open'),
    'maint-3B.4B: activate API does not run restore worker'
);

// --- Phase 3B.4C production database import engine (surface checks) ---
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php'), 'prod-import-3B.4C: engine lib present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_import_production.php'), 'prod-import-3B.4C: CLI present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-production-import.php'), 'prod-import-3B.4C: request API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'production-import.php'), 'prod-import-3B.4C: status API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'self_test_production_import.php'), 'prod-import-3B.4C: dedicated self-test present');
$prodImportLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_import.php');
restore_admin_self_test(
    str_contains($prodImportLib, 'ORANGE_RESTORE_FW_STATUS_PRODUCTION_IMPORT_READY')
    && str_contains($prodImportLib, 'orange_restore_sql_runner_import_gzip_to_target')
    && str_contains($prodImportLib, 'orange_restore_production_wipe')
    && !str_contains($prodImportLib, 'orange_restore_merge_uploads_cutover')
    && !str_contains($prodImportLib, 'orange_restore_merge_rollback'),
    'prod-import-3B.4C: DB import only; no uploads/rollback'
);
$prodImportReqApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-production-import.php');
restore_admin_self_test(
    str_contains($prodImportReqApi, 'metadata_only')
    && str_contains($prodImportReqApi, 'http_never_imports')
    && !str_contains($prodImportReqApi, 'orange_restore_prod_import_run_cli'),
    'prod-import-3B.4C: request API metadata only'
);
$prodImportUi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
restore_admin_self_test(
    (
        str_contains($prodImportUi, 'استيراد قاعدة الإنتاج')
        || str_contains($prodImportUi, 'production_import_pending')
    )
    && str_contains($prodImportUi, 'rc-prod-import-req')
    && str_contains($prodImportUi, 'استرداد الإنتاج لم يبدأ بعد'),
    'prod-import-3B.4C: Restore Center UI wires import states + files warning'
);

// --- Phase 3B.4D production uploads cutover (surface checks) ---
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php'), 'uploads-cutover-3B.4D: engine lib present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_uploads_cutover.php'), 'uploads-cutover-3B.4D: CLI present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-uploads-cutover.php'), 'uploads-cutover-3B.4D: request API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'uploads-cutover.php'), 'uploads-cutover-3B.4D: status API present');
restore_admin_self_test(is_file($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'self_test_production_uploads_cutover.php'), 'uploads-cutover-3B.4D: dedicated self-test present');
$uploadsCutoverLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'restore_production_uploads_cutover.php');
restore_admin_self_test(
    str_contains($uploadsCutoverLib, 'ORANGE_RESTORE_FW_STATUS_UPLOADS_CUTOVER_READY')
    && str_contains($uploadsCutoverLib, 'orange_restore_merge_uploads_cutover_atomic_rename')
    && !str_contains($uploadsCutoverLib, 'orange_restore_production_wipe')
    && !str_contains($uploadsCutoverLib, 'orange_restore_merge_rollback'),
    'uploads-cutover-3B.4D: rename only; no wipe/rollback'
);
$uploadsReqApi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'restore' . DIRECTORY_SEPARATOR . 'job' . DIRECTORY_SEPARATOR . 'request-uploads-cutover.php');
restore_admin_self_test(
    str_contains($uploadsReqApi, 'metadata_only')
    && str_contains($uploadsReqApi, 'http_never_cutover')
    && !str_contains($uploadsReqApi, 'orange_restore_uploads_cutover_run_cli'),
    'uploads-cutover-3B.4D: request API metadata only'
);
$uploadsUi = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'pages' . DIRECTORY_SEPARATOR . 'restore_center.php');
restore_admin_self_test(
    str_contains($uploadsUi, 'uploads_cutover_pending')
    && str_contains($uploadsUi, 'rc-uploads-cutover-req')
    && str_contains($uploadsUi, 'الاسترداد لم يكتمل')
    && (
        str_contains($uploadsUi, 'تحويل الرفع معلّق')
        || str_contains($uploadsUi, 'تحويل الرفع')
    ),
    'uploads-cutover-3B.4D: Restore Center UI wires cutover states + warning'
);

    restore_admin_test_run_cleanup();
    restore_admin_test_emit_summary();
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    restore_admin_test_run_cleanup();
    echo 'THROWABLE:' . get_class($e) . '@' . basename(str_replace('\\', '/', $e->getFile())) . ':' . $e->getLine() . ':' . str_replace(["\r", "\n", ';'], ' ', $e->getMessage()) . PHP_EOL;
    restore_admin_test_emit_summary();
    exit(1);
}
