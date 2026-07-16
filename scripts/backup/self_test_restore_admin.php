<?php

declare(strict_types=1);

/**
 * Phase 3B.1 — Restore Center read-only admin self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_restore_admin.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

register_shutdown_function(function (): void {
    $error = error_get_last();
    if ($error !== null) {
        echo 'FATAL: ' . $error['type'] . ' @ ' . $error['file'] . ':' . $error['line'] . ' — ' . $error['message'] . PHP_EOL;
    }
});

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'admin_permissions.php';

$failures = 0;

function restore_admin_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
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

/** @var array{calledRealpath:bool,calledIsDir:bool,calledIsWritable:bool} */
$GLOBALS['restore_admin_test_fs_trace'] = [
    'calledRealpath' => false,
    'calledIsDir' => false,
    'calledIsWritable' => false,
];

function restore_admin_test_fs_trace_reset(): void
{
    $GLOBALS['restore_admin_test_fs_trace'] = [
        'calledRealpath' => false,
        'calledIsDir' => false,
        'calledIsWritable' => false,
    ];
}

/** @return array{calledRealpath:bool,calledIsDir:bool,calledIsWritable:bool} */
function restore_admin_test_fs_trace_snapshot(): array
{
    /** @var array{calledRealpath:bool,calledIsDir:bool,calledIsWritable:bool} $trace */
    $trace = $GLOBALS['restore_admin_test_fs_trace'];

    return $trace;
}

function restore_admin_test_traced_realpath(string $path): string|false
{
    $GLOBALS['restore_admin_test_fs_trace']['calledRealpath'] = true;

    return realpath($path);
}

function restore_admin_test_traced_is_dir(string $path): bool
{
    $GLOBALS['restore_admin_test_fs_trace']['calledIsDir'] = true;

    return is_dir($path);
}

function restore_admin_test_traced_is_writable(string $path): bool
{
    $GLOBALS['restore_admin_test_fs_trace']['calledIsWritable'] = true;

    return is_writable($path);
}

function restore_admin_test_trace_candidate_report(
    string $path,
    bool $allowedByOpenBasedir,
    string $openBasedirHelper,
    bool $skippedBeforeFilesystem,
    string $skipHelper,
    bool $selected,
    string $rejectionReason = '',
    string $generatedFixtureRoot = '',
    bool $mkdirResult = false,
    bool $generatedRootWritable = false,
    bool $outsideProjectRoot = false
): void {
    $trace = restore_admin_test_fs_trace_snapshot();
    echo 'Candidate:' . PHP_EOL . $path . PHP_EOL;
    echo 'AllowedByOpenBaseDir:' . PHP_EOL . ($allowedByOpenBasedir ? 'YES' : 'NO') . PHP_EOL;
    echo 'OpenBaseDirHelper:' . PHP_EOL . $openBasedirHelper . PHP_EOL;
    echo 'SkippedBeforeFilesystem:' . PHP_EOL . ($skippedBeforeFilesystem ? 'YES' : 'NO') . PHP_EOL;
    if ($skippedBeforeFilesystem && $skipHelper !== '') {
        echo 'SkipHelper:' . PHP_EOL . $skipHelper . PHP_EOL;
    }
    echo 'CalledRealpath:' . PHP_EOL . ($trace['calledRealpath'] ? 'YES' : 'NO') . PHP_EOL;
    echo 'CalledIsDir:' . PHP_EOL . ($trace['calledIsDir'] ? 'YES' : 'NO') . PHP_EOL;
    echo 'CalledIsWritable:' . PHP_EOL . ($trace['calledIsWritable'] ? 'YES' : 'NO') . PHP_EOL;
    echo 'OutsideProjectRoot:' . PHP_EOL . ($outsideProjectRoot ? 'YES' : 'NO') . PHP_EOL;
    echo 'GeneratedFixtureRoot:' . PHP_EOL . ($generatedFixtureRoot !== '' ? $generatedFixtureRoot : '—') . PHP_EOL;
    echo 'MkdirResult:' . PHP_EOL . ($mkdirResult ? 'YES' : 'NO') . PHP_EOL;
    echo 'GeneratedRootWritable:' . PHP_EOL . ($generatedRootWritable ? 'YES' : 'NO') . PHP_EOL;
    if (!$selected && $rejectionReason !== '') {
        echo 'RejectionReason:' . PHP_EOL . $rejectionReason . PHP_EOL;
    }
    echo 'Selected:' . PHP_EOL . ($selected ? 'YES' : 'NO') . PHP_EOL;
    echo '---' . PHP_EOL;
}

function restore_admin_test_normalize_path_lexical(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '';
    }
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    $path = rtrim($path, '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        return strtolower($path);
    }

    return $path;
}

function restore_admin_test_path_open_basedir_allowed(string $path): bool
{
    $raw = ini_get('open_basedir');
    if (!is_string($raw) || trim($raw) === '') {
        return true;
    }
    $pathNorm = restore_admin_test_normalize_path_lexical($path);
    if ($pathNorm === '') {
        return false;
    }
    foreach (explode(';', $raw) as $allowed) {
        $allowedNorm = restore_admin_test_normalize_path_lexical($allowed);
        if ($allowedNorm === '') {
            continue;
        }
        if ($pathNorm === $allowedNorm || str_starts_with($pathNorm, $allowedNorm . '/')) {
            return true;
        }
    }

    return false;
}

function restore_admin_test_path_writable(string $dir): bool
{
    if ($dir === '' || !restore_admin_test_traced_is_dir($dir) || !restore_admin_test_traced_is_writable($dir)) {
        return false;
    }

    return true;
}

function restore_admin_test_generated_root_writable(string $generatedFixtureRoot): bool
{
    if ($generatedFixtureRoot === '' || !restore_admin_test_traced_is_dir($generatedFixtureRoot)) {
        return false;
    }
    $probe = $generatedFixtureRoot . DIRECTORY_SEPARATOR . 'orange_writable_' . bin2hex(random_bytes(2));
    if (file_put_contents($probe, '1') === false) {
        return false;
    }
    unlink($probe);

    return true;
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

/** @return list<string> */
function restore_admin_test_temp_base_candidates(): array
{
    $candidates = [];
    $seen = [];

    $addCandidate = function (string $path) use (&$candidates, &$seen): void {
        $path = rtrim($path, '\\/');
        if ($path === '') {
            return;
        }
        $key = restore_admin_test_normalize_path_lexical($path);
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $candidates[] = $path;
    };

    if (DIRECTORY_SEPARATOR === '\\') {
        $addCandidate('C:\\Windows\\Temp');
    }

    $rawOpenBasedir = ini_get('open_basedir');
    if (is_string($rawOpenBasedir) && trim($rawOpenBasedir) !== '') {
        foreach (explode(';', $rawOpenBasedir) as $allowed) {
            $allowed = trim($allowed);
            if ($allowed !== '') {
                $addCandidate($allowed);
            }
        }
    }

    $sysTemp = sys_get_temp_dir();
    if ($sysTemp !== '') {
        $rawOpenBasedirForSysTemp = ini_get('open_basedir');
        if (is_string($rawOpenBasedirForSysTemp) && trim($rawOpenBasedirForSysTemp) !== '') {
            if (restore_admin_test_path_open_basedir_allowed($sysTemp)) {
                $addCandidate($sysTemp);
            }
        } elseif (DIRECTORY_SEPARATOR !== '\\') {
            $addCandidate($sysTemp);
        }
    }

    return $candidates;
}

function restore_admin_test_temp_root(string $projectRoot): string
{
    echo 'OpenBaseDirIni:' . PHP_EOL . (string) ini_get('open_basedir') . PHP_EOL;
    echo 'SysGetTempDirString:' . PHP_EOL . sys_get_temp_dir() . PHP_EOL;

    foreach (restore_admin_test_temp_base_candidates() as $baseDir) {
        restore_admin_test_fs_trace_reset();
        $baseDir = rtrim($baseDir, '\\/');
        $generatedFixtureRoot = '';
        $mkdirResult = false;
        $generatedRootWritable = false;
        $outsideProjectRoot = false;

        if ($baseDir === '') {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                false,
                'restore_admin_test_path_open_basedir_allowed',
                true,
                'restore_admin_test_temp_root',
                false,
                'other:empty_candidate'
            );
            continue;
        }

        $allowedByOpenBasedir = restore_admin_test_path_open_basedir_allowed($baseDir);
        if (!$allowedByOpenBasedir) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                false,
                'restore_admin_test_path_open_basedir_allowed',
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'generated_root_not_allowed'
            );
            continue;
        }

        $outsideProjectRoot = restore_admin_test_path_outside_project($baseDir, $projectRoot);
        if (!$outsideProjectRoot) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                true,
                'restore_admin_test_path_outside_project',
                false,
                'inside_project_root',
                '',
                false,
                false,
                false
            );
            continue;
        }

        if (
            !restore_admin_test_traced_is_dir($baseDir)
            && !mkdir($baseDir, 0775, true)
            && !restore_admin_test_traced_is_dir($baseDir)
        ) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'restore_admin_test_temp_root',
                false,
                'mkdir_failed',
                '',
                false,
                false,
                $outsideProjectRoot
            );
            continue;
        }
        if (!restore_admin_test_path_writable($baseDir)) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'restore_admin_test_path_writable',
                false,
                'other:base_dir_not_writable',
                '',
                false,
                false,
                $outsideProjectRoot
            );
            continue;
        }

        $generatedFixtureRoot = $baseDir . DIRECTORY_SEPARATOR . 'orange_restore_admin_' . bin2hex(random_bytes(4));
        $mkdirResult = mkdir($generatedFixtureRoot, 0775, true);
        if (!$mkdirResult && !restore_admin_test_traced_is_dir($generatedFixtureRoot)) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'restore_admin_test_temp_root',
                false,
                'mkdir_failed',
                $generatedFixtureRoot,
                false,
                false,
                $outsideProjectRoot
            );
            continue;
        }
        if (!restore_admin_test_path_open_basedir_allowed($generatedFixtureRoot)) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'generated_root_not_allowed',
                $generatedFixtureRoot,
                true,
                false,
                $outsideProjectRoot
            );
            continue;
        }
        $generatedRootWritable = restore_admin_test_generated_root_writable($generatedFixtureRoot);
        if (!$generatedRootWritable) {
            restore_admin_test_trace_candidate_report(
                $baseDir,
                true,
                'restore_admin_test_path_open_basedir_allowed',
                false,
                'restore_admin_test_generated_root_writable',
                false,
                'generated_root_not_writable',
                $generatedFixtureRoot,
                true,
                false,
                $outsideProjectRoot
            );
            restore_admin_test_rmtree($generatedFixtureRoot);
            continue;
        }

        restore_admin_test_trace_candidate_report(
            $baseDir,
            true,
            'restore_admin_test_path_open_basedir_allowed',
            false,
            '',
            true,
            '',
            $generatedFixtureRoot,
            true,
            true,
            $outsideProjectRoot
        );

        return $generatedFixtureRoot;
    }

    throw new RuntimeException(
        'Cannot resolve writable restore admin self-test temp directory outside ProjectRoot (open_basedir-safe).'
    );
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

    $tmpRoot = restore_admin_test_temp_root($projectRoot);
    $backupRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'backups';
    $workRoot = $tmpRoot . DIRECTORY_SEPARATOR . 'restore_work';
    $fakeProject = $tmpRoot . DIRECTORY_SEPARATOR . 'fake_project';
    mkdir($backupRoot, 0775, true);
    mkdir($workRoot, 0775, true);
    mkdir($fakeProject, 0775, true);
    restore_admin_self_test(
        restore_admin_test_path_outside_project($backupRoot, $projectRoot),
        'fixture: BackupRoot outside ProjectRoot'
    );
    file_put_contents(
    $fakeProject . DIRECTORY_SEPARATOR . '.env.php',
    "<?php\nreturn ['ORANGE_BACKUP_ROOT' => " . var_export($backupRoot, true) . "];\n"
);

$fullPkgId = '2026-07-01_120000';
$fullPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'snapshots' . DIRECTORY_SEPARATOR . $fullPkgId;
mkdir($fullPkgDir, 0775, true);
orange_backup_write_json($fullPkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'full_disaster',
    'generated_at' => gmdate('c'),
    'schema_revision' => 121,
    'export_backend' => 'php_pdo',
    'backup_status' => 'success',
]);
orange_backup_write_json($fullPkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json($fullPkgDir . DIRECTORY_SEPARATOR . 'recovery_validation.json', [
    'overall_result' => 'pass',
    'recovery_score' => 95,
    'generated_at' => gmdate('c'),
]);

$countryPkgId = '2026-07-01_130000';
$countryPkgDir = $backupRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'kw' . DIRECTORY_SEPARATOR . $countryPkgId;
mkdir($countryPkgDir, 0775, true);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'manifest.json', [
    'package_type' => 'country_recovery',
    'generated_at' => gmdate('c'),
    'schema_revision' => 121,
    'registry_version' => '1',
    'backup_status' => 'success',
]);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'health.json', ['package_status' => 'healthy']);
orange_backup_write_json($countryPkgDir . DIRECTORY_SEPARATOR . 'recovery_validation.json', [
    'overall_result' => 'pass',
    'recovery_score' => 88,
]);

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

$fullPackages = orange_backup_admin_list_full_snapshots($backupRoot, 5);
$publicFull = orange_restore_admin_public_package_row($fullPackages[0], 'full_disaster');
restore_admin_self_test(!isset($publicFull['package_path']), 'packages: absolute package_path stripped');
restore_admin_self_test(isset($publicFull['restore_eligibility']), 'packages: restore eligibility attached');

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
restore_admin_self_test(str_contains($pageSource, 'read_only') || str_contains($pageSource, 'للعرض والمتابعة فقط'), 'ui: read-only warning present');
restore_admin_self_test(!str_contains($pageSource, 'بدء الاسترداد'), 'ui: no Start Restore button label');
restore_admin_self_test(!preg_match('/<button[^>]*>[^<]*موافقة/u', $pageSource), 'ui: no approval action button');
restore_admin_self_test(stripos($pageSource, '>Rollback<') === false && stripos($pageSource, 'restore_full_rollback.php') === false, 'ui: no Rollback action control');
restore_admin_self_test(!str_contains($pageSource, 'restore_admin.php'), 'ui: page does not load restore_admin.php at render');

$restoreAdminLib = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'restore_admin.php');
restore_admin_self_test(!str_contains($restoreAdminLib, 'function orange_restore_orchestrator_approve'), 'lib: restore_admin does not define mutating orchestrator wrappers');
restore_admin_self_test(!str_contains($restoreAdminLib, 'orange_restore_e2e_start_full'), 'lib: restore_admin does not expose e2e start');

restore_admin_test_rmtree($tmpRoot);

    echo $failures === 0 ? "All restore admin self-tests passed.\n" : "Restore admin self-tests failed: {$failures}\n";
    exit($failures > 0 ? 1 : 0);
} catch (Throwable $e) {
    echo 'THROWABLE: ' . get_class($e) . ' @ ' . basename(str_replace('\\', '/', $e->getFile())) . ':' . $e->getLine() . ' — ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
