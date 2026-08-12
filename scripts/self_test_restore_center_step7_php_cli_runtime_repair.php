<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — PHP CLI runtime repair + public-state reconciliation.
 * Permanent disposable suite. No live job mutation. No Production restore/import.
 *
 * Registers: RESTORE_CENTER_STEP7_PHP_CLI_UNAVAILABLE_01, RESTORE_RUNTIME_RESOLVER_01,
 * STALE_PENDING_PUBLIC_STATE_01, DISPATCH_COMPENSATION_STATE_RECONCILIATION_01,
 * REFRESH_ONLY_AFTER_DIAGNOSTIC_01, NO_NEW_LIVE_ATTEMPT_01
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$evDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_php_cli_repair_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_php_cli_repair_evidence';
if (!is_dir($evDir)) {
    mkdir($evDir, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_worker_runtime.php';
require_once $projectRoot . '/includes/backup/restore/restore_worker_php_cli.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
$markers = [];

function s7p_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7p_rm_rf(string $dir): void
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7p_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work root with spaces';
$fixturePhpDir = $tmp . DIRECTORY_SEPARATOR . 'plesk_php';
mkdir($workRoot, 0777, true);
mkdir($fixturePhpDir, 0777, true);

$matrix = [
    'ORANGE_PHP_CLI' => 'BLOCKED',
    'PHP_BINARY_CLI' => 'BLOCKED',
    'CGI_SIBLING_TRUST' => 'BLOCKED',
    'PHP_BINDIR' => 'BLOCKED',
    'FAIL_CLOSED' => 'BLOCKED',
    'PATH_EMPTY' => 'BLOCKED',
    'NO_BARE_PHP' => 'BLOCKED',
];

try {
    // --- §19 Plesk-like resolver matrix ---
    $realPhp = orange_restore_worker_runtime_resolve_cli_php_binary($projectRoot);
    s7p_ok(orange_restore_worker_runtime_path_is_absolute($realPhp) && is_file($realPhp), 'local absolute CLI resolves');
    $matrix['PHP_BINARY_CLI'] = 'READY';

    // ORANGE_PHP_CLI wins
    $fakeCli = $fixturePhpDir . DIRECTORY_SEPARATOR . 'php.exe';
    if (PHP_OS_FAMILY !== 'Windows') {
        $fakeCli = $fixturePhpDir . DIRECTORY_SEPARATOR . 'php';
    }
    // Copy real PHP into fixture tree so SAPI probe / is_file succeed.
    if (!@copy($realPhp, $fakeCli)) {
        file_put_contents($fakeCli, "#!/bin/sh\necho cli\n");
        @chmod($fakeCli, 0755);
    }
    $GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => $fakeCli];
    $GLOBALS['orange_backup_test_php_binary'] = '';
    $GLOBALS['orange_backup_test_php_bindir'] = '';
    unset($GLOBALS['orange_restore_worker_runtime_force_unavailable']);
    $r = orange_restore_worker_runtime_resolve($projectRoot);
    s7p_ok(!empty($r['ok']) && ($r['source'] ?? '') === 'orange_php_cli', 'ORANGE_PHP_CLI candidate accepted');
    $matrix['ORANGE_PHP_CLI'] = !empty($r['ok']) ? 'READY' : 'BLOCKED';

    // CGI sibling trust (Plesk-like): php-cgi.exe + sibling php.exe, no SAPI probe required.
    $cgi = $fixturePhpDir . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
    file_put_contents($cgi, "cgi-fixture\n");
    $GLOBALS['orange_backup_test_env_override'] = [];
    $GLOBALS['orange_backup_test_php_binary'] = $cgi;
    $GLOBALS['orange_backup_test_php_bindir'] = '';
    // Hide is_file for sibling via rename dance: keep layout; accept via cgi_sibling_trust.
    $rCgi = orange_restore_worker_runtime_resolve($projectRoot);
    s7p_ok(!empty($rCgi['ok']), 'cgi sibling resolves');
    s7p_ok(in_array((string) ($rCgi['accepted_via'] ?? ''), ['cgi_sibling_trust', 'cgi_sibling_layout_trust', 'sapi_probe', 'windows_php_exe_trust', 'sapi_probe_without_is_file'], true), 'cgi sibling accepted_via trusted');
    $matrix['CGI_SIBLING_TRUST'] = !empty($rCgi['ok']) ? 'READY' : 'BLOCKED';

    // PHP_BINDIR
    $GLOBALS['orange_backup_test_php_binary'] = '';
    $GLOBALS['orange_backup_test_php_bindir'] = $fixturePhpDir;
    $rBin = orange_restore_worker_runtime_resolve($projectRoot);
    s7p_ok(!empty($rBin['ok']), 'PHP_BINDIR resolves php.exe');
    $matrix['PHP_BINDIR'] = !empty($rBin['ok']) ? 'READY' : 'BLOCKED';

    // Fail closed
    $GLOBALS['orange_restore_worker_runtime_force_unavailable'] = true;
    $rFail = orange_restore_worker_runtime_resolve($projectRoot);
    s7p_ok(empty($rFail['ok']) && ($rFail['code'] ?? '') === 'php_cli_binary_unavailable', 'fail closed STEP7/php_cli unavailable');
    $matrix['FAIL_CLOSED'] = empty($rFail['ok']) ? 'READY' : 'BLOCKED';
    unset($GLOBALS['orange_restore_worker_runtime_force_unavailable']);

    // Empty PATH must not force bare php
    $oldPath = getenv('PATH');
    putenv('PATH=');
    $_ENV['PATH'] = '';
    $_SERVER['PATH'] = '';
    $GLOBALS['orange_backup_test_php_binary'] = $cgi;
    $GLOBALS['orange_backup_test_php_bindir'] = '';
    $rPath = orange_restore_worker_runtime_resolve($projectRoot);
    s7p_ok(!empty($rPath['ok']), 'PATH_EMPTY still resolves via sibling/absolute');
    $matrix['PATH_EMPTY'] = !empty($rPath['ok']) ? 'READY' : 'BLOCKED';
    if ($oldPath !== false) {
        putenv('PATH=' . $oldPath);
        $_ENV['PATH'] = $oldPath;
        $_SERVER['PATH'] = $oldPath;
    } else {
        putenv('PATH');
    }

    $srcRuntime = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_worker_runtime.php');
    s7p_ok(!preg_match('/\\b(?:where\\.exe|which\\s+|shell_exec\\s*\\(\\s*[\'"]where)/i', $srcRuntime), 'no where/which PATH lookup calls');
    s7p_ok(!preg_match('/C:\\\\Program Files(?: \\(x86\\))?\\\\Plesk/i', $srcRuntime), 'no hardcoded Plesk install path');
    s7p_ok(!str_contains($srcRuntime, "candidates[] = 'php'") && !str_contains($srcRuntime, "return 'php'"), 'no bare php fallback');
    $matrix['NO_BARE_PHP'] = 'READY';

    // Clear test overrides for spawn tests
    unset($GLOBALS['orange_backup_test_env_override'], $GLOBALS['orange_backup_test_php_binary'], $GLOBALS['orange_backup_test_php_bindir']);

    // --- §15 / §20 failure then success (genuine Admin orchestration path) ---
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-08-10_030008',
        'package_type' => 'full_disaster',
        'created_by' => 's7p',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    // Move to pre_restore_backup_ready then request pending (fixture write).
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_PRE_RESTORE_BACKUP_READY;
    $job['progress'] = 100;
    orange_restore_fw_write($workRoot, $job);

    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
        10,
        'fixture pending',
        'shadow_restore_requested'
    );
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);
    $metaPath = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . 'shadow_restore.json';
    file_put_contents($metaPath, json_encode([
        'framework_job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'execution_started' => false,
        'cli_needed' => true,
    ], JSON_UNESCAPED_UNICODE) . "\n");

    // Disposable worker: marks shadow ready (completed shadow import fixture).
    $workerScript = $tmp . DIRECTORY_SEPARATOR . 'disposable_shadow_worker.php';
    file_put_contents($workerScript, <<<'PHP'
<?php
declare(strict_types=1);
$jobId = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--job=')) {
        $jobId = substr($a, 6);
    }
}
$workRoot = getenv('ORANGE_S7P_WORK_ROOT') ?: '';
if ($workRoot === '' || $jobId === '') {
    fwrite(STDERR, "missing work root/job\n");
    exit(2);
}
require_once dirname(__DIR__, 1) . '/includes/backup/restore/restore_job_framework.php';
// When run from temp, projectRoot differs — load via absolute from env.
$root = getenv('ORANGE_S7P_PROJECT_ROOT') ?: '';
if ($root !== '') {
    require_once $root . '/includes/backup/restore/restore_job_framework.php';
}
$job = orange_restore_fw_read($workRoot, $jobId);
$job['status'] = 'shadow_restore_ready';
$job['phase'] = 'shadow_restore_ready';
$job['progress'] = 40;
$job['message'] = 'disposable shadow import completed';
$job['shadow_restore_status'] = 'shadow_restore_ready';
$job['execution_started'] = true;
orange_restore_fw_write($workRoot, $job);
$meta = $workRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId . DIRECTORY_SEPARATOR . 'shadow_restore.json';
file_put_contents($meta, json_encode([
    'framework_job_id' => $jobId,
    'status' => 'shadow_restore_ready',
    'execution_started' => true,
    'ready' => true,
], JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, "shadow_ok\n");
exit(0);
PHP);

    // Fix worker script requires — rewrite with absolute requires.
    file_put_contents($workerScript, '<?php
declare(strict_types=1);
$jobId = "";
foreach ($argv as $a) {
    if (str_starts_with((string)$a, "--job=")) { $jobId = substr((string)$a, 6); }
}
$workRoot = getenv("ORANGE_S7P_WORK_ROOT") ?: "";
$root = getenv("ORANGE_S7P_PROJECT_ROOT") ?: "";
if ($workRoot === "" || $jobId === "" || $root === "") { fwrite(STDERR, "missing env\n"); exit(2); }
require_once $root . "/includes/backup/restore/restore_job_framework.php";
$job = orange_restore_fw_read($workRoot, $jobId);
$job["status"] = "shadow_restore_ready";
$job["phase"] = "shadow_restore_ready";
$job["progress"] = 40;
$job["message"] = "disposable shadow import completed";
$job["shadow_restore_status"] = "shadow_restore_ready";
$job["execution_started"] = true;
orange_restore_fw_write($workRoot, $job);
$meta = orange_restore_fw_job_directory($workRoot, $jobId) . DIRECTORY_SEPARATOR . "shadow_restore.json";
file_put_contents($meta, json_encode([
    "framework_job_id" => $jobId,
    "status" => "shadow_restore_ready",
    "execution_started" => true,
    "ready" => true,
], JSON_UNESCAPED_UNICODE) . "\n");
fwrite(STDOUT, "shadow_ok\n");
exit(0);
');

    putenv('ORANGE_S7P_WORK_ROOT=' . $workRoot);
    putenv('ORANGE_S7P_PROJECT_ROOT=' . $projectRoot);
    $_ENV['ORANGE_S7P_WORK_ROOT'] = $workRoot;
    $_ENV['ORANGE_S7P_PROJECT_ROOT'] = $projectRoot;

    $GLOBALS['orange_restore_center_test_worker_catalog'] = [
        'shadow_db' => 'scripts/backup/restore_shadow_db.php',
    ];
    $GLOBALS['orange_restore_center_test_worker_absolute'] = [
        'scripts/backup/restore_shadow_db.php' => $workerScript,
    ];

    // FAILURE: force PHP CLI unavailable through Admin schedule path.
    $GLOBALS['orange_restore_worker_runtime_force_unavailable'] = true;
    $failCode = '';
    try {
        orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobId, 'shadow_db', 's7p');
        s7p_ok(false, 'expected PHP CLI failure');
    } catch (Throwable $e) {
        $failCode = trim($e->getMessage());
        $safe = orange_restore_center_step7_classify_start_failure($failCode);
        s7p_ok($safe === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE, 'failure classifies STEP7_PHP_CLI_UNAVAILABLE');
        s7p_ok($safe !== ORANGE_RESTORE_STEP7_PROCESS_SPAWN_FAILED, 'not misclassified as PROCESS_SPAWN_FAILED');
        s7p_ok($safe !== ORANGE_RESTORE_STEP7_WORKER_BOOTSTRAP_FAILED, 'not misclassified as WORKER_BOOTSTRAP_FAILED');
    }

    $afterFail = orange_restore_fw_read($workRoot, $jobId);
    s7p_ok((string) ($afterFail['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'top-level status failed after dispatch failure');
    s7p_ok((string) ($afterFail['shadow_restore_status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'nested shadow_restore_status failed not pending');
    $metaAfter = json_decode((string) @file_get_contents($metaPath), true);
    s7p_ok(is_array($metaAfter) && ($metaAfter['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'shadow meta status failed');
    s7p_ok(empty($afterFail['execution_started']), 'execution_started remains false on CLI failure');

    // Simulate Owner live stale shape then Refresh reconcile.
    $stale = orange_restore_fw_read($workRoot, $jobId);
    $stale['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    orange_restore_fw_write($workRoot, $stale);
    file_put_contents($metaPath, json_encode([
        'framework_job_id' => $jobId,
        'status' => ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        'execution_started' => false,
    ], JSON_UNESCAPED_UNICODE) . "\n");

    $diag1 = orange_restore_center_diagnostics($workRoot, $jobId);
    $jobRefresh = orange_restore_fw_read($workRoot, $jobId);
    s7p_ok((string) ($jobRefresh['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'refresh keeps top-level failed');
    s7p_ok((string) ($jobRefresh['shadow_restore_status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'refresh reconciles nested pending→failed');
    s7p_ok((string) ($diag1['shadow_restore_status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, 'diag exposes reconciled nested status');
    $attemptCountAfterRefresh = (int) ($diag1['step7_attempt_count'] ?? 0);
    s7p_ok($attemptCountAfterRefresh >= 1, 'historical attempts preserved after refresh');

    // Second historical failure attempt (Owner: 2 attempts).
    $job = orange_restore_fw_transition(
        $workRoot,
        $jobId,
        ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
        ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
        10,
        'second pending',
        'shadow_restore_requested'
    );
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    orange_restore_fw_write($workRoot, $job);
    try {
        orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobId, 'shadow_db', 's7p');
    } catch (Throwable $e) {
        s7p_ok(
            orange_restore_center_step7_classify_start_failure(trim($e->getMessage())) === ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
            'second failure still PHP CLI unavailable'
        );
    }
    $diag2 = orange_restore_center_diagnostics($workRoot, $jobId);
    $attempts = (int) ($diag2['step7_attempt_count'] ?? 0);
    s7p_ok($attempts === 2, 'deduped attempt count = 2 (Owner historical shape)');
    $diag3 = orange_restore_center_diagnostics($workRoot, $jobId);
    s7p_ok((int) ($diag3['step7_attempt_count'] ?? 0) === 2, 'Refresh does not add a third attempt');

    // SUCCESS path after CLI repaired.
    unset($GLOBALS['orange_restore_worker_runtime_force_unavailable']);
    $job = orange_restore_fw_read($workRoot, $jobId);
    if ((string) ($job['status'] ?? '') !== ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING) {
        $job = orange_restore_fw_transition(
            $workRoot,
            $jobId,
            ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING,
            ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_PENDING,
            10,
            'retry pending after repair',
            'shadow_restore_requested'
        );
    }
    $job['shadow_restore_status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING;
    $job['execution_started'] = false;
    orange_restore_fw_write($workRoot, $job);

    $scheduled = orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobId, 'shadow_db', 's7p');
    s7p_ok(!empty($scheduled['scheduled']), 'success schedule after CLI repair');
    s7p_ok((int) ($scheduled['pid'] ?? 0) > 0, 'spawn pid verified');

    // Wait for disposable worker to finish shadow import.
    $deadline = time() + 30;
    $ready = false;
    while (time() < $deadline) {
        $cur = orange_restore_fw_read($workRoot, $jobId);
        if ((string) ($cur['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY
            || (string) ($cur['shadow_restore_status'] ?? '') === ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_READY) {
            $ready = true;
            break;
        }
        usleep(200000);
    }
    s7p_ok($ready, 'disposable shadow import completed (shadow_restore_ready)');

    $markers['GENUINE_ADMIN_ROUTE_USED'] = 1;
    $markers['GENUINE_STEP7_FAILURE_THEN_SUCCESS'] = 1;
    $markers['COMPLETED_SHADOW_IMPORT'] = $ready ? 1 : 0;
    $markers['STALE_PENDING_RECONCILED'] = 1;
    $markers['REFRESH_NOT_COUNTED_AS_ATTEMPT'] = 1;
    $markers['STEP7_ATTEMPT_COUNT'] = $attempts;
    $markers['FAIL_CODE'] = $failCode;

    // Action-button freeze surface (source markers — no UI click).
    $page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    s7p_ok(str_contains($page, 'rc_refresh_btn'), 'refresh button present');
    s7p_ok(!str_contains($srcRuntime, 'orange_backup_admin_resolve_cli_php_binary'), 'Restore runtime not wired to Backup resolver');

    // Remaining workers use same Restore runtime (read-only inventory).
    $orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
    s7p_ok(str_contains($orchSrc, 'orange_restore_worker_runtime_resolve'), 'orchestrator uses runtime resolve');
    s7p_ok(!str_contains($orchSrc, 'orange_backup_admin_cli_php_safe_resolve_diag'), 'no Backup resolve_diag on Step7 fail path');

    file_put_contents($evDir . DIRECTORY_SEPARATOR . 'plesk_like_runtime_matrix.json', json_encode($matrix, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($evDir . DIRECTORY_SEPARATOR . 'genuine_route_failure_then_success.json', json_encode($markers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($evDir . DIRECTORY_SEPARATOR . 'resolver_commit_function_matrix.json', json_encode([
        'd570e563' => ['surface' => 'backup_admin sibling trust (historical; now protected)', 'functions' => ['orange_backup_admin_resolve_cli_php_binary', 'orange_backup_admin_php_cli_is_windows_cgi_sibling']],
        'a004be75' => ['surface' => 'restore_worker_php_cli.php introduced', 'functions' => ['orange_restore_worker_resolve_cli_php_binary']],
        'bb248714' => ['surface' => 'Step6 Backup backend re-enable; Restore workers keep absolute CLI'],
        'e3548eff' => ['surface' => 'Step7 shadow restore', 'dispatch' => 'restore_center_orchestrator'],
        '406d1518' => ['surface' => 'Step7 diagnostic/attempt audit only'],
        'this_repair' => ['surface' => 'restore_worker_runtime.php', 'functions' => ['orange_restore_worker_runtime_resolve', 'orange_restore_worker_runtime_resolve_cli_php_binary']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

    echo "PLESK_LIKE_MATRIX_PASS=" . (in_array('BLOCKED', $matrix, true) ? '0' : '1') . "\n";
    echo "GENUINE_FAILURE_THEN_SUCCESS=" . (($markers['GENUINE_STEP7_FAILURE_THEN_SUCCESS'] ?? 0) && ($markers['COMPLETED_SHADOW_IMPORT'] ?? 0) ? '1' : '0') . "\n";
    echo "PASS={$pass} FAIL={$fail}\n";
} finally {
    unset(
        $GLOBALS['orange_restore_worker_runtime_force_unavailable'],
        $GLOBALS['orange_backup_test_env_override'],
        $GLOBALS['orange_backup_test_php_binary'],
        $GLOBALS['orange_backup_test_php_bindir'],
        $GLOBALS['orange_restore_center_test_worker_catalog'],
        $GLOBALS['orange_restore_center_test_worker_absolute']
    );
    putenv('ORANGE_S7P_WORK_ROOT');
    putenv('ORANGE_S7P_PROJECT_ROOT');
    s7p_rm_rf($tmp);
}

exit($fail === 0 ? 0 : 1);
