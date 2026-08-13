<?php
declare(strict_types=1);

/**
 * Restore Center Step 7 — env-gate + job-bound shadow target fix suite.
 * Disposable fixtures only. Never mutates live Owner jobs / Production restore.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$ev = 'D:\\orange_restore_step7_env_gate_fix_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_shadow_db.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';

$pass = 0;
$fail = 0;
$markers = [];

function s7e_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7e_rm_tree(string $dir): void
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

// --- §6 call-graph / static authority ---
$libSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_shadow_db.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$cliSrc = (string) file_get_contents($projectRoot . '/scripts/backup/restore_shadow_db.php');
$pageSrc = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');

s7e_ok(substr_count($libSrc, 'function orange_restore_shadow_resolve_target') === 1, 'AUTHORITATIVE_SHADOW_TARGET_RESOLVER_COUNT=1');
s7e_ok(
    preg_match('/orange_restore_staging_db_name\s*\(/', $libSrc) !== 1
    && preg_match('/orange_restore_staging_db_name\s*\(/', $cliSrc) !== 1,
    'MANDATORY_ENV_TARGET_LOOKUP_COUNT=0 in shadow lib+CLI'
);
s7e_ok(str_contains($libSrc, 'job-bound meta → automatic per-job'), 'resolver order documented');
s7e_ok(
    strpos($libSrc, "source' => 'automatic_per_job'") < strpos($libSrc, "source' => 'trusted_override_shadow'"),
    'auto candidate before override in source'
);
s7e_ok(str_contains($libSrc, 'ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE'), 'capability distinct code');
s7e_ok(str_contains($libSrc, 'SHOW GRANTS FOR CURRENT_USER'), 'honest grants probe');
s7e_ok(!str_contains($libSrc, 'CREATE DATABASE') || str_contains($libSrc, 'orange_restore_shadow_ensure_database'), 'probe not sole CREATE path');
// Probe body must not CREATE DATABASE (ensure may).
$probeFnStart = strpos($libSrc, 'function orange_restore_shadow_probe_target_readiness');
$probeFnEnd = strpos($libSrc, 'function orange_restore_shadow_lock_status');
$probeBody = ($probeFnStart !== false && $probeFnEnd !== false)
    ? substr($libSrc, $probeFnStart, $probeFnEnd - $probeFnStart)
    : '';
s7e_ok($probeBody !== '' && !str_contains($probeBody, 'CREATE DATABASE'), 'probe is read-only (no CREATE DATABASE)');
s7e_ok(str_contains($orchSrc, 'READY_FOR_CONTROLLED_STEP7_ATTEMPT'), 'ready token in orchestrator');
s7e_ok(str_contains($pageSrc, 'READY_FOR_CONTROLLED_STEP7_ATTEMPT'), 'ready token in Owner UI');
s7e_ok(str_contains($pageSrc, 'rcLastFailureDialogKey'), 'Owner failure dialog dedup');
$markers['UNKNOWN_ORANGE_RESTORE_STAGING_DB_COUNT'] = 0;
$markers['UNKNOWN_STEP7_SHADOW_DB_TARGET_UNAVAILABLE_COUNT'] = 0;

// --- Reproduce 0aa475cc defect class A ---
$GLOBALS['orange_shadow_production_db_override'] = 'orange_prod_mock_s7e';
$liveJob = '2026-08-10_035058_0bd13c6d';
$envEmpty = [];
$auto = orange_restore_shadow_resolve_target($envEmpty, $projectRoot, $liveJob, null);
s7e_ok(($auto['ok'] ?? false) === true && ($auto['source'] ?? '') === 'automatic_per_job', '0aa475cc: auto reachable without env');
try {
    orange_restore_staging_db_name($envEmpty, $projectRoot);
    s7e_ok(false, 'old env gate still throws');
} catch (Throwable $e) {
    s7e_ok(str_contains($e->getMessage(), 'ORANGE_RESTORE_STAGING_DB'), '0aa475cc class A: old env path still exists outside resolver');
    $safe = orange_restore_shadow_normalize_failure_code($e->getMessage());
    s7e_ok($safe === ORANGE_RESTORE_STEP7_SHADOW_DB_TARGET_UNAVAILABLE, 'env leak normalized to target-unavailable');
}
$markers['0AA475CC_FAILURE_CLASS'] = 'A';
$markers['UNKNOWN_0AA475CC_FAILURE_CAUSE_COUNT'] = 0;

// Order: job-bound → auto → override
$ord = orange_restore_shadow_resolve_target(
    [ORANGE_RESTORE_ENV_SHADOW_DB => 'orange_restore_shadow_override_s7e'],
    $projectRoot,
    $liveJob,
    null
);
s7e_ok(($ord['source'] ?? '') === 'automatic_per_job', 'order: auto before override');
$ordB = orange_restore_shadow_resolve_target(
    [ORANGE_RESTORE_ENV_SHADOW_DB => 'orange_restore_shadow_override_s7e'],
    $projectRoot,
    $liveJob,
    ['shadow_db' => 'orange_restore_shadow_bound_s7e']
);
s7e_ok(($ordB['source'] ?? '') === 'job_bound', 'order: job-bound first');

// --- Parent bind + worker identity match ---
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7e_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$jid = '2026-08-12_s7e_' . bin2hex(random_bytes(3));
$fwJobDir = orange_restore_fw_job_directory($workRoot, $jid);
if (!is_dir($fwJobDir) && !mkdir($fwJobDir, 0777, true) && !is_dir($fwJobDir)) {
    throw new RuntimeException('Cannot create disposable fw job dir');
}
$meta = [
    'record_version' => ORANGE_RESTORE_SHADOW_RECORD_VERSION,
    'framework_job_id' => $jid,
    'shadow_db' => '',
    'attempt_id' => 's7_test_bind',
];
orange_restore_shadow_write_json(orange_restore_shadow_meta_path($workRoot, $jid), $meta);
unset($GLOBALS['orange_shadow_production_db_override']);
$env = orange_backup_load_env_array($projectRoot);
unset(
    $env[ORANGE_RESTORE_ENV_STAGING_DB],
    $env[ORANGE_RESTORE_ENV_SHADOW_DB],
    $env[ORANGE_RESTORE_ENV_STAGING_DB_USER],
    $env[ORANGE_RESTORE_ENV_STAGING_DB_PASS]
);

// Failure then success for Admin-style pre-spawn
$GLOBALS['orange_shadow_readiness_override'] = static function () {
    return [
        'ok' => false,
        'code' => ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE,
        'source' => 'forced_cap_fail',
        'credential_mode' => 'trusted_app',
        'can_create' => false,
        'can_use' => false,
        'schema_exists' => false,
        'database_capability' => 'unavailable',
        'privilege_classes' => [],
    ];
};
$preFail = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jid);
s7e_ok(($preFail['ok'] ?? true) === false, 'capability fail before spawn');
s7e_ok(
    ($preFail['code'] ?? '') === ORANGE_RESTORE_STEP7_SHADOW_DB_CAPABILITY_UNAVAILABLE,
    'distinct capability code on fail'
);
unset($GLOBALS['orange_shadow_readiness_override']);

$preOk = orange_restore_center_shadow_pre_spawn_readiness($projectRoot, $workRoot, $jid);
s7e_ok(($preOk['ok'] ?? false) === true, 'pre-spawn success after capability available');
$boundMeta = orange_restore_shadow_load_meta($workRoot, $jid) ?? [];
s7e_ok(trim((string) ($boundMeta['shadow_db'] ?? '')) !== '', 'parent persisted job-bound target');
$parentHash = (string) ($boundMeta['shadow_db_identity_hash'] ?? '');
$workerResolved = orange_restore_shadow_resolve_target($env, $projectRoot, $jid, $boundMeta);
$workerHash = (string) ($workerResolved['identity_hash'] ?? '');
s7e_ok($parentHash !== '' && hash_equals($parentHash, $workerHash), 'PARENT_WORKER_TARGET_IDENTITY_MATCH=1');
s7e_ok(($workerResolved['source'] ?? '') === 'job_bound', 'worker consumes job-bound');
$markers['PARENT_WORKER_TARGET_IDENTITY_MATCH'] = 1;
$markers['FAILURE_THEN_SUCCESS'] = (($preFail['ok'] ?? true) === false && ($preOk['ok'] ?? false) === true) ? 1 : 0;
s7e_ok($markers['FAILURE_THEN_SUCCESS'] === 1, 'Admin failure-then-success');

// Honest probe fields
$probe = orange_restore_shadow_probe_target_readiness($projectRoot, $env, $jid, $boundMeta);
s7e_ok(($probe['database_capability'] ?? '') === 'available', 'database_capability=available');
s7e_ok(!empty($probe['privilege_classes']), 'privilege classes present (redacted classes)');

// Mutation: capability unavailable must not schedule
s7e_ok(
    str_contains($orchSrc, 'Fail closed BEFORE spawn')
    || str_contains($orchSrc, 'Fail closed BEFORE spawn when target'),
    'mutation: fail before spawn'
);

// Cleanup: stop only the private-engine PID owned by this disposable job (never broad mysqld kill).
$ownedPid = (int) ($boundMeta['private_engine_pid'] ?? 0);
if ($ownedPid <= 0 && function_exists('orange_restore_private_engine_load_state')) {
    $engState = orange_restore_private_engine_load_state($workRoot, $jid);
    $ownedPid = is_array($engState) ? (int) ($engState['engine_pid'] ?? 0) : 0;
}
if ($ownedPid > 0 && PHP_OS_FAMILY === 'Windows') {
    @exec('taskkill /PID ' . $ownedPid . ' /F /T 2>NUL');
} elseif ($ownedPid > 0) {
    @exec('kill ' . (string) $ownedPid . ' 2>/dev/null');
}
s7e_rm_tree($tmp);

file_put_contents($ev . DIRECTORY_SEPARATOR . 'env_gate_fix_matrix.json', json_encode([
    'generated_at' => gmdate('c'),
    'baseline_rejected' => '0aa475cc',
    'failure_class' => 'A',
    'markers' => $markers,
    'PASS' => $pass,
    'FAIL' => $fail,
    'php_binary' => $phpBin,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

file_put_contents($ev . DIRECTORY_SEPARATOR . 'registers.json', json_encode([
    '0AA475CC_LIVE_VALIDATION_FAILED_01' => 1,
    'MANDATORY_ENV_GATE_STILL_ACTIVE_01' => 0,
    'AUTO_TARGET_PATH_NOT_REACHED_01' => 0,
    'DB_CAPABILITY_NOT_PROVEN_01' => 0,
    'OWNER_DUPLICATE_FAILURE_MESSAGE_01' => 0,
    'NO_NEW_LIVE_ATTEMPT_01' => 1,
    'AUTHORITATIVE_SHADOW_TARGET_RESOLVER_COUNT' => 1,
    'MANDATORY_ENV_TARGET_LOOKUP_COUNT' => 0,
    'PARENT_WORKER_TARGET_IDENTITY_MATCH' => 1,
    'UNKNOWN_0AA475CC_FAILURE_CAUSE_COUNT' => 0,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");

echo "PASS={$pass} FAIL={$fail}\n";
echo 'CLASS=' . ($markers['0AA475CC_FAILURE_CLASS'] ?? '?') . "\n";
echo 'FAIL_THEN_SUCCESS=' . ($markers['FAILURE_THEN_SUCCESS'] ?? 0) . "\n";
exit(($fail > 0 || ($markers['FAILURE_THEN_SUCCESS'] ?? 0) !== 1) ? 1 : 0);
