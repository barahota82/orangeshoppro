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
    $pdo->exec('INSERT INTO admins VALUES (' . $adminId . ', \'op\', 1, ' . ($superuser ? '1' : '0') . ', \'Op\', \'\')');
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
        'schema_revision' => 121,
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

$countryPkgId = '2026-07-01_130000';
$countryPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryPkgId;
mkdir($countryPkgDir, 0775, true);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'country_recovery',
    'package_version' => '1.0.0',
    'generated_at' => gmdate('c'),
    'schema_revision' => 121,
    'registry_version' => '1.0',
    'country_id' => 1,
    'backup_status' => 'success',
]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
mkdir($countryPkgDir . DIRECTORY_SEPARATOR . 'sql', 0775, true);
mkdir($countryPkgDir . DIRECTORY_SEPARATOR . 'files', 0775, true);
file_put_contents($countryPkgDir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'countries.sql', "INSERT INTO countries VALUES (1);\n");
restore_admin_test_write_zip($countryPkgDir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip', ['x.txt' => 'x']);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'dependency_graph.json', ['tables' => ['countries']]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'table_inventory.json', ['tables' => ['countries' => 1]]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'id_snapshot.json', ['country_id' => 1, 'tables' => []]);
orange_backup_write_json(
    orange_backup_admin_recovery_report_sibling_path($countryPkgDir, $countryPkgId),
    [
        'overall_result' => 'pass',
        'recovery_score' => 88,
        'validated_at' => gmdate('c'),
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
    'schema_revision' => 121,
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
restore_admin_self_test(str_contains($pageSource, 'أهلية الاسترداد'), 'ui: eligibility column arabic label');
restore_admin_self_test(str_contains($pageSource, 'عرض تفاصيل الحزمة'), 'ui: package details button arabic');
restore_admin_self_test(str_contains($pageSource, 'drvCell'), 'ui: DRV cell avoids zero placeholder');
restore_admin_self_test(str_contains($pageSource, 'انتظار التأكيد') || str_contains($pageSource, 'read_only'), 'ui: confirmation-gate warning present');
restore_admin_self_test(!str_contains($pageSource, 'بدء الاسترداد'), 'ui: no Start Restore button label');
restore_admin_self_test(!preg_match('/<button[^>]*>[^<]*موافقة/u', $pageSource), 'ui: no approval action button');
restore_admin_self_test(stripos($pageSource, '>Rollback<') === false && stripos($pageSource, 'restore_full_rollback.php') === false, 'ui: no Rollback action control');
restore_admin_self_test(!str_contains($pageSource, 'restore_admin.php'), 'ui: page does not load restore_admin.php at render');
restore_admin_self_test(str_contains($pageSource, 'rc-create-job') && str_contains($pageSource, 'rc-fw-cancel'), 'ui: framework create/cancel controls present');
restore_admin_self_test(str_contains($pageSource, 'Run Dry Validation') && str_contains($pageSource, 'View Dry Report'), 'ui: dry validation controls present');
restore_admin_self_test(!str_contains($pageSource, 'Execute'), 'ui: no Execute button');

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

    restore_admin_test_run_cleanup();
    restore_admin_test_emit_summary();
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    restore_admin_test_run_cleanup();
    echo 'THROWABLE:' . get_class($e) . '@' . basename(str_replace('\\', '/', $e->getFile())) . ':' . $e->getLine() . ':' . str_replace(["\r", "\n", ';'], ' ', $e->getMessage()) . PHP_EOL;
    restore_admin_test_emit_summary();
    exit(1);
}
