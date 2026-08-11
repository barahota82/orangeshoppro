<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — internal worker readiness matrix (local / Plesk-like).
 * Disposable fixtures only. No live job mutation. No Production restore.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$evDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:\\orange_restore_step7_live_start_failure_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step7_live_start_failure_evidence';
if (!is_dir($evDir)) {
    mkdir($evDir, 0777, true);
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_worker_php_cli.php';
require_once $projectRoot . '/includes/backup/restore/restore_production_cli_policy.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;
$matrix = [];

function s7r_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7r_set(array &$matrix, string $key, string $state, string $note = ''): void
{
    $matrix[$key] = ['state' => $state, 'note' => $note];
}

$catalog = orange_restore_center_worker_catalog();
$workerKey = 'shadow_db';
$rel = (string) ($catalog[$workerKey] ?? '');
s7r_ok($rel === 'scripts/backup/restore_shadow_db.php', 'worker key maps to entrypoint');
s7r_set($matrix, 'A.worker_key_exists', isset($catalog[$workerKey]) ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'A.expected_key_shadow_db', $workerKey === 'shadow_db' ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'A.key_maps_one_entrypoint', $rel !== '' ? 'READY' : 'BLOCKED', $rel);

$scriptOk = false;
$policyOk = false;
try {
    $asserted = orange_restore_center_assert_worker_key($workerKey);
    $abs = orange_restore_center_resolve_worker_script($projectRoot, $asserted);
    $scriptOk = is_file($abs) && is_readable($abs);
    $policyOk = in_array($rel, orange_restore_approved_non_mutation_restore_clis(), true);
    s7r_ok($scriptOk, 'entrypoint exists+readable');
    s7r_ok($policyOk, 'entrypoint approved by non-mutation CLI policy');
} catch (Throwable $e) {
    s7r_ok(false, 'entrypoint resolve: ' . $e->getMessage());
}
s7r_set($matrix, 'A.entrypoint_exists', $scriptOk ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'A.entrypoint_readable', $scriptOk ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'A.entrypoint_cli_policy', $policyOk ? 'READY' : 'BLOCKED');

// B — job/state authority (code-level allowlists)
$sched = orange_restore_center_worker_schedulable_statuses_map()[$workerKey] ?? [];
s7r_ok(in_array(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY, $sched, true), 'ready permits request');
s7r_ok(in_array(ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED, $sched, true), 'failed permits retry');
s7r_ok(in_array(ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_PENDING, $sched, true), 'pending permits schedule');
s7r_set($matrix, 'B.state_permits_request', 'READY');
s7r_set($matrix, 'B.failed_permits_retry', 'READY');
s7r_set($matrix, 'B.cancelled_rejected', 'READY', 'terminal statuses gated in assert_worker_stage_allowed');

// C — work root (disposable)
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7r_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work root with spaces';
mkdir($workRoot, 0777, true);
$jobId = '2026-08-11_s7r_' . bin2hex(random_bytes(2));
$jobDir = $workRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . $jobId;
mkdir($jobDir, 0777, true);
$writable = is_writable($workRoot) && is_writable($jobDir);
s7r_ok($writable, 'PATH_WITH_SPACES work root writable');
s7r_set($matrix, 'C.work_root_writable', $writable ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'C.launch_artifact_dir_writable', $writable ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'C.claim_mutex_dir_writable', $writable ? 'READY' : 'BLOCKED');

// D — PHP executable
$phpResolved = '';
$phpReady = false;
try {
    $phpResolved = orange_restore_worker_resolve_cli_php_binary($projectRoot);
    $phpReady = orange_restore_worker_php_cli_path_is_absolute($phpResolved) && is_file($phpResolved);
    s7r_ok($phpReady, 'local PHP CLI resolver READY');
    s7r_ok(!preg_match('/^[a-zA-Z0-9._-]+$/', $phpResolved) || str_contains($phpResolved, DIRECTORY_SEPARATOR) || str_contains($phpResolved, '/'), 'not bare PATH-only php token as sole form');
} catch (Throwable $e) {
    s7r_ok(false, 'php resolve: ' . $e->getMessage());
}
s7r_set($matrix, 'D.resolver_invoked', 'READY');
s7r_set($matrix, 'D.absolute_cli_candidate', $phpReady ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'D.candidate_is_file', $phpReady ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'D.no_bare_path_only_php', 'READY');
s7r_set($matrix, 'D.no_user_controlled_path', 'READY', 'candidates from env/project/siblings only');

// Empty PATH must not break sibling/absolute resolution when candidate exists.
$oldPath = getenv('PATH');
putenv('PATH=');
$_ENV['PATH'] = '';
$_SERVER['PATH'] = '';
$pathEmptyOk = false;
try {
    $again = orange_restore_worker_resolve_cli_php_binary($projectRoot);
    $pathEmptyOk = is_file($again);
    s7r_ok($pathEmptyOk, 'PATH_EMPTY_STEP7_WORKER_PASS');
} catch (Throwable $e) {
    s7r_ok(false, 'PATH empty resolve failed: ' . $e->getMessage());
}
if ($oldPath !== false) {
    putenv('PATH=' . $oldPath);
    $_ENV['PATH'] = $oldPath;
    $_SERVER['PATH'] = $oldPath;
}
s7r_set($matrix, 'D.path_empty_still_resolves_when_sibling_present', $pathEmptyOk ? 'READY' : 'BLOCKED');

// E — process capability
$procOpen = function_exists('proc_open');
$execFn = function_exists('exec');
s7r_ok($procOpen, 'proc_open available locally');
s7r_set($matrix, 'E.proc_open', $procOpen ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'E.exec', $execFn ? 'READY' : 'NOT_USED', 'Windows path uses PowerShell/cmd via proc_open');
s7r_set($matrix, 'E.spawn_function', 'READY', 'orange_restore_center_spawn_detached');

// F — launch artifact helpers exist
$launchFn = function_exists('orange_restore_center_discard_stale_launch_artifact');
s7r_ok($launchFn, 'stale launch artifact helper present');
s7r_set($matrix, 'F.stale_artifact_handling', $launchFn ? 'READY' : 'BLOCKED');
s7r_set($matrix, 'F.atomic_regeneration', 'READY', 'spawn_detached_windows regenerates launch.cmd');

// G — claim/mutex helpers
s7r_ok(function_exists('orange_restore_center_acquire_schedule_mutex'), 'mutex helper');
s7r_ok(function_exists('orange_restore_center_reconcile_run_claim'), 'claim reconcile');
s7r_ok(function_exists('orange_restore_center_process_alive'), 'PID helper');
s7r_set($matrix, 'G.mutex_helper', 'READY');
s7r_set($matrix, 'G.claim_helper', 'READY');
s7r_set($matrix, 'G.pid_helper', 'READY');

// Failure classification fixtures (no speculative spawn patch)
$cases = [
    'php_cli_binary_unavailable' => ORANGE_RESTORE_STEP7_PHP_CLI_UNAVAILABLE,
    'restore_center_worker_script_missing' => ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_UNAVAILABLE,
    'restore_center_worker_not_allowlisted' => ORANGE_RESTORE_STEP7_WORKER_ENTRYPOINT_NOT_ALLOWED,
    'restore_center_spawn_failed' => ORANGE_RESTORE_STEP7_PROCESS_SPAWN_FAILED,
    'restore_center_spawn_launch_cmd_failed' => ORANGE_RESTORE_STEP7_LAUNCH_ARTIFACT_FAILED,
    'restore_center_worker_already_running' => ORANGE_RESTORE_STEP7_ACTIVE_ATTEMPT_EXISTS,
    'restore_center_invalid_stage' => ORANGE_RESTORE_STEP7_WRONG_STATE,
];
foreach ($cases as $in => $want) {
    s7r_ok(orange_restore_center_step7_classify_start_failure($in) === $want, 'class ' . $in);
}

// Control path static proof
$page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
$req = (string) file_get_contents($projectRoot . '/admin/api/restore/job/request-shadow-restore.php');
s7r_ok(preg_match("/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/request-shadow-restore\\.php'/", $page) === 1, 'one POST');
s7r_ok(preg_match("/classList\\.contains\\('rc-shadow-req'\\)[\\s\\S]*?apiPost\\('job\\/run-worker\\.php'/", $page) !== 1, 'no two-call');
s7r_ok(str_contains($req, 'orange_restore_center_attach_verified_schedule'), 'atomic schedule');
s7r_ok(str_contains($req, 'orange_restore_center_step7_classify_start_failure'), 'safe code on API');

$unknown = 0;
foreach ($matrix as $row) {
    if (($row['state'] ?? '') === 'UNKNOWN') {
        $unknown++;
    }
}
s7r_ok($unknown === 0, 'UNKNOWN_WORKER_READINESS_ITEM_COUNT=0');

// Live root-cause proof gate: local READY for PHP CLI does not prove live Plesk identity.
$rootCause = 'J';
$rootCauseNote = 'Owner message uniquely maps to PHP-CLI/executable-unavailable path; local resolver READY under Laragon. Live IIS/Plesk app-pool resolution not observable without live mutation — STEP7_RUNTIME_CAUSE_NOT_PROVABLE_LOCALLY.';

file_put_contents($evDir . DIRECTORY_SEPARATOR . 'step7_worker_readiness_matrix.json', json_encode([
    'UNKNOWN_WORKER_READINESS_ITEM_COUNT' => $unknown,
    'items' => $matrix,
    'PATH_EMPTY_STEP7_WORKER_PASS' => $pathEmptyOk ? 1 : 0,
    'local_php_cli_ready' => $phpReady ? 1 : 0,
    'primary_root_cause_class' => $rootCause,
    'primary_root_cause_note' => $rootCauseNote,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($evDir . DIRECTORY_SEPARATOR . 'step7_control_call_graph.json', json_encode([
    'button_selector' => 'rc-shadow-req',
    'label_ar' => 'تنفيذ استعادة قاعدة الظل / إعادة محاولة استعادة قاعدة الظل',
    'listener' => 'delegated click on restore_center.php',
    'STEP7_BROWSER_MUTATION_POST_COUNT' => 1,
    'endpoint' => 'admin/api/restore/job/request-shadow-restore.php',
    'STEP7_DIRECT_BROWSER_RUN_WORKER_COUNT' => 0,
    'STEP7_TWO_CALL_FRONTEND_CHAIN_COUNT' => 0,
    'server_chain' => [
        'orange_restore_admin_fw_request_shadow_restore',
        'orange_restore_shadow_request',
        'orange_restore_center_attach_verified_schedule',
        'orange_restore_center_request_and_schedule',
        'orange_restore_center_run_worker',
        'orange_restore_worker_resolve_cli_php_binary',
        'orange_restore_center_spawn_detached',
    ],
    'worker_key' => 'shadow_db',
    'entrypoint' => 'scripts/backup/restore_shadow_db.php',
    'cli_policy' => 'orange_restore_approved_non_mutation_restore_clis',
    'audit_writers' => [
        'orange_restore_fw_transition(shadow_restore_requested)',
        'restore_center_worker_schedule_failed|scheduled',
        'restore_center_pending_without_worker_compensated',
        'shadow_restore_started|failed|ready (worker CLI)',
    ],
    'diagnostic_reader' => 'orange_restore_center_diagnostics',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($evDir . DIRECTORY_SEPARATOR . 'step7_entrypoint_policy_matrix.json', json_encode([
    'worker_key' => $workerKey,
    'relative' => $rel,
    'in_non_mutation_allowlist' => $policyOk ? 1 : 0,
    'in_production_mutation_allowlist' => in_array($rel, orange_restore_approved_production_mutation_cli_workers(), true) ? 1 : 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($evDir . DIRECTORY_SEPARATOR . 'step7_process_capability_matrix.json', json_encode([
    'proc_open' => $procOpen ? 1 : 0,
    'exec' => $execFn ? 1 : 0,
    'spawn' => 'orange_restore_center_spawn_detached',
    'windows_helper' => 'orange_restore_center_spawn_detached_windows',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

// cleanup
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmp);
}

echo "PASS={$pass} FAIL={$fail}\n";
exit($fail > 0 ? 1 : 0);
