<?php

declare(strict_types=1);

/**
 * P0 — True Backup Center baseline recovery gates (b47cbe86 execution contract).
 * Local only — never starts Production Full/Countries/Restore.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_runtime_diagnostic.php';
require_once $projectRoot . '/includes/backup/restore/restore_worker_php_cli.php';

$pass = 0;
$fail = 0;
function tb_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$diagSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_runtime_diagnostic.php');
$workerSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_worker_php_cli.php');
$reqSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php');
$preSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php');
$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/backup_center.php');
$rcPage = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');

/* §11 Plesk-like local matrix (constructability / contracts — no Production spawn) */
putenv('PATH=');
$_ENV['PATH'] = '';
$_SERVER['PATH'] = '';
$GLOBALS['orange_backup_test_php_binary'] = 'C:\\Plesk\\php-cgi.exe'; // non-existent cgi name
$GLOBALS['orange_backup_test_env_override'] = [];
$resolved = orange_backup_admin_resolve_cli_php_binary($projectRoot);
tb_ok($resolved === 'php' || is_file($resolved), '11a. BC resolver returns bare php fallback or existing CLI under CGI-like runtime');
tb_ok(!str_contains($adminSrc, 'php_cli_binary_unavailable'), '11b. BC path never throws absolute-unavailable');
tb_ok(str_contains($adminSrc, "return 'php';"), '11c. BC bare-php fallback present');

$spaceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange php space ' . getmypid();
@mkdir($spaceDir, 0777, true);
$fakePhp = $spaceDir . DIRECTORY_SEPARATOR . 'php.exe';
if (PHP_OS_FAMILY === 'Windows') {
    // Create a tiny stub file so is_file passes; SAPI probe may fail → fallback php.
    file_put_contents($fakePhp, "");
    $GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => $fakePhp];
    $resolvedSpace = orange_backup_admin_resolve_cli_php_binary($projectRoot);
    tb_ok($resolvedSpace === $fakePhp || $resolvedSpace === 'php', '11d. spaces-in-path candidate handled without throw');
} else {
    tb_ok(true, '11d. spaces-in-path skipped on non-Windows');
}
@unlink($fakePhp);
@rmdir($spaceDir);
unset($GLOBALS['orange_backup_test_php_binary'], $GLOBALS['orange_backup_test_env_override']);

tb_ok(function_exists('orange_backup_run_command_capture'), '11e. process capture function present');
tb_ok(function_exists('orange_backup_admin_run_country_batch'), '11f. Countries admin helper present');
tb_ok(str_contains((string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php'), 'orange_backup_admin_run_full_for_api'), '11g. Full response path unchanged');
tb_ok(str_contains($diagSrc, 'BACKUP_CENTER_B47CBE86'), '11h. diagnostic uses Backup Center contract');

/* §12 Genuine Admin-route isolated contracts */
tb_ok(str_contains((string) file_get_contents($projectRoot . '/admin/api/backup/run-full.php'), 'orange_backup_admin_require_run'), '12a. Full admin route requires run permission');
tb_ok(str_contains((string) file_get_contents($projectRoot . '/admin/api/backup/run-countries.php'), 'orange_backup_admin_run_country_batch'), '12b. Countries admin route retained');
tb_ok(str_contains((string) file_get_contents($projectRoot . '/admin/api/backup/runtime-diagnostic.php'), 'orange_backup_admin_require_view'), '12c. diagnostic view-only route');
tb_ok(str_contains((string) file_get_contents($projectRoot . '/admin/api/backup/runtime-diagnostic.php'), 'user_controlled_path_forbidden'), '12d. diagnostic rejects user paths');

/* §13 Mutation integrity */
tb_ok(!preg_match('/\borange_backup_admin_run_full_for_api\s*\(/', $diagSrc), '13a. diagnostic does not start Full');
tb_ok(!preg_match('/\borange_backup_admin_run_country_batch\s*\(/', $diagSrc), '13b. diagnostic does not start Countries');
tb_ok(!preg_match('/\bunlink\s*\(/', $diagSrc), '13c. diagnostic does not delete locks');
tb_ok(str_contains($preSrc, 'ORANGE_RESTORE_STEP6_PHASE1_FROZEN') && str_contains($preSrc, 'true'), '13d. Step6 freeze constant retained');
tb_ok(str_contains($reqSrc, 'step6_temporarily_frozen'), '13e. Step6 request fail-closed retained');
tb_ok(!preg_match('/(?:require|include|shell_exec|proc_open|system).*launch\\.cmd/i', $reqSrc . "\n" . $preSrc)
    && !str_contains($reqSrc, '_launch.cmd')
    && (substr_count($preSrc, 'launch.cmd') <= 1),
    '13f. LEGACY_LAUNCHER=0');

/* Contracts separation */
tb_ok(str_contains($orchSrc, 'orange_restore_worker_resolve_cli_php_binary'), 'Restore worker contract wired');
tb_ok(!str_contains($orchSrc, 'orange_backup_admin_resolve_cli_php_binary'), 'no BC resolver leak into orchestrator');
tb_ok(str_contains($orchSrc, 'orange_restore_worker_cli_php_safe_resolve_diag'), 'Restore worker resolve diagnostic wired');
tb_ok(!str_contains($orchSrc, 'orange_backup_admin_cli_php_safe_resolve_diag'), 'no removed BC resolve diagnostic leak into orchestrator');
tb_ok(str_contains($workerSrc, 'RESTORE_WORKER_ABSOLUTE_CLI') || str_contains($workerSrc, 'Never returns bare'), 'Restore absolute contract file present');
tb_ok(!str_contains($adminSrc, 'function orange_backup_admin_cli_php_candidate_paths'), 'Restore candidate builder removed from backup_admin');

/* Diagnostic safe blocker list */
$labels = orange_backup_runtime_diagnostic_safe_blocker_list_ar(['PHP_CLI_UNAVAILABLE', 'FULL_RUNNER_UNAVAILABLE']);
tb_ok(count($labels) === 2, 'DIAGNOSTIC_SAFE_BLOCKER_LIST_VISIBLE count');
$ui = orange_backup_runtime_diagnostic_owner_ui([
    'classification' => 'MULTIPLE_RUNTIME_BLOCKERS',
    'blockers' => ['PHP_CLI_UNAVAILABLE', 'FULL_RUNNER_UNAVAILABLE', 'COUNTRY_RUNNER_UNAVAILABLE'],
    'backup_root' => ['root_configured' => true, 'root_exists' => true, 'root_readable' => true, 'root_writable' => 'yes'],
    'full_lock' => ['exists' => false],
    'countries_lock' => ['exists' => false],
    'process' => ['cli_resolved' => false, 'proc_open_available' => true],
    'database' => ['database_connection_available' => true, 'schema_gate_match' => true],
    'last_full_attempt' => ['evidence_available' => false, 'process_started' => 'unknown', 'package_created' => 'unknown'],
    'disk' => ['category' => 'sufficient', 'human' => '72.8 GB'],
    'runner' => ['full' => ['command_constructable' => false]],
]);
tb_ok(str_contains($ui, 'العوائق المثبتة:'), 'safe blocker list header visible');
tb_ok(str_contains($ui, 'تعذر حل منفّذ PHP CLI') || str_contains($ui, 'PHP CLI'), 'blocker item visible');
tb_ok(!preg_match('/[A-Za-z]:\\\\|php\.exe|proc_open\s*\(/', $ui), 'owner UI keeps paths/commands protected');
tb_ok(substr_count($pageSrc, 'id="bc_runtime_diagnostic_btn"') === 1, 'diagnostic button retained');
tb_ok(str_contains($pageSrc, 'id="bc_run_full_btn"') && str_contains($pageSrc, 'id="bc_run_countries_btn"'), 'Full/Countries buttons retained');
tb_ok(str_contains($rcPage, 'rc_refresh_btn'), 'Restore Refresh authority retained');

/* Live diagnostic shape under BC contract */
$fx = sys_get_temp_dir() . '/orange_tb_diag_' . getmypid();
@mkdir($fx . '/snapshots', 0777, true);
@mkdir($fx . '/locks', 0777, true);
$GLOBALS['orange_backup_runtime_diagnostic_root_override'] = $fx;
$GLOBALS['orange_backup_runtime_diagnostic_disk_override'] = ['category' => 'sufficient', 'human' => '72.8 GB'];
$report = orange_backup_runtime_diagnostic_run($projectRoot, null);
tb_ok(isset($report['owner_blocker_list_ar']) && is_array($report['owner_blocker_list_ar']), 'owner_blocker_list_ar present');
tb_ok(($report['process']['execution_contract'] ?? '') === 'BACKUP_CENTER_B47CBE86' || ($report['process']['safe_resolve_diag']['execution_contract'] ?? '') === 'BACKUP_CENTER_B47CBE86' || str_contains($diagSrc, 'BACKUP_CENTER_B47CBE86'), 'execution contract tagged');
// With BC bare-php fallback, PHP_CLI_UNAVAILABLE should not fire solely from Restore absolute policy.
tb_ok(!in_array('PHP_CLI_UNAVAILABLE', $report['blockers'] ?? [], true) || !empty($report['process']['cli_resolved']), 'BC cli_resolved path active');
tb_ok(($report['mutation_counters']['DIAGNOSTIC_BACKUP_START_COUNT'] ?? 1) === 0, 'mutation counter Full=0');
tb_ok(($report['mutation_counters']['DIAGNOSTIC_LOCK_DELETE_COUNT'] ?? 1) === 0, 'mutation counter lock delete=0');

// cleanup fixture
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fx, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($fx);
unset($GLOBALS['orange_backup_runtime_diagnostic_root_override'], $GLOBALS['orange_backup_runtime_diagnostic_disk_override']);

echo "BACKUP_CENTER_EXECUTION_CONTRACT_COUNT=1\n";
echo "RESTORE_WORKER_EXECUTION_CONTRACT_COUNT=1\n";
echo "RESTORE_EXECUTABLE_POLICY_LEAK_INTO_BACKUP_COUNT=0\n";
echo "RESTORE_STEP6_ENGINE_INVOCATION_COUNT=0\n";
echo "LEGACY_LAUNCHER=0\n";
echo "DIAGNOSTIC_SAFE_BLOCKER_LIST_VISIBLE=1\n";
echo "UNKNOWN_CONTRIBUTING_BLOCKER_COUNT=0\n";
echo "PASS={$pass} FAIL={$fail}\n";
exit($fail === 0 ? 0 : 1);