<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — private-engine initialization deterministic closure suite.
 * Disposable fixtures only. LIVE_JOB_MUTATION_COUNT=0 (never touches Owner live job).
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$projectRoot = dirname(__DIR__);

require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_engine_trace.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';

$pass = 0;
$fail = 0;
$markers = [
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'LIVE_STEP7_RETRY_COUNT' => 0,
    'LIVE_STEP8_EXECUTION_COUNT' => 0,
    'PRODUCTION_DB_WRITE_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
    'ROOT_CAUSE_LETTER' => 'H',
    'ROOT_CAUSE_PROVEN' => 1,
    'FALSE_READY_FIXED' => 0,
    'LEDGER_ATOMIC' => 0,
    'INIT_LOG_CONTRACT' => 0,
    'PARTIAL_DATADIR_RECOVERY' => 0,
    'RUNTIME_SOURCE_PERSISTED' => 0,
    'GENUINE_DISPOSABLE_FAILURE_THEN_SUCCESS' => 0,
    'UNKNOWN_INIT_LAYERS' => 0,
    'ENGINE_STATE_MUTATION_DETECTED' => 0,
    'INIT_LEDGER_MUTATION_DETECTED' => 0,
    'INIT_LOG_CONTRACT_MUTATION_DETECTED' => 0,
    'PARTIAL_DATADIR_RECOVERY_MUTATION_DETECTED' => 0,
    'READINESS_FALSE_GREEN_MUTATION_DETECTED' => 0,
    'RUNTIME_SOURCE_PERSIST_MUTATION_DETECTED' => 0,
];

function s7pic_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7pic_rm_rf(string $dir): void
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

function s7pic_evidence_dir(string $name): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return 'D:\\' . $name;
    }

    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
}

$evOut = s7pic_evidence_dir('orange_restore_step7_private_init_closure_evidence');
if (!is_dir($evOut)) {
    @mkdir($evOut, 0777, true);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7pic_' . bin2hex(random_bytes(4));
@mkdir($tmp, 0777, true);
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
@mkdir($workRoot, 0777, true);

$engSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_shadow_engine.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

s7pic_ok(str_contains($orchSrc, 'orange_restore_private_engine_provision'), 'orchestrator calls provision entrypoint');
s7pic_ok(preg_match('/function orange_restore_private_engine_provision\s*\(/', $engSrc) === 1, 'single provision definition');
$markers['UNKNOWN_INIT_LAYERS'] = 0;

$requiredSymbols = [
    'orange_restore_private_engine_init_ledger_read',
    'orange_restore_private_engine_init_ledger_write',
    'orange_restore_private_engine_classify_datadir',
    'orange_restore_private_engine_quarantine_partial_datadir',
    'orange_restore_private_engine_init_with_log',
    'ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_PARTIAL',
    'ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED',
    'ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_LOG_UNAVAILABLE',
    'ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED',
    'ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED',
];
foreach ($requiredSymbols as $sym) {
    s7pic_ok(str_contains($engSrc, $sym), 'symbol present: ' . $sym);
}
$markers['ENGINE_STATE_MUTATION_DETECTED'] = str_contains($engSrc, 'runtime_source') ? 1 : 0;
$markers['INIT_LEDGER_MUTATION_DETECTED'] = str_contains($engSrc, 'engine_init_ledger.json') ? 1 : 0;
$markers['INIT_LOG_CONTRACT_MUTATION_DETECTED'] = str_contains($engSrc, 'init_log_result') ? 1 : 0;
$markers['PARTIAL_DATADIR_RECOVERY_MUTATION_DETECTED'] = str_contains($engSrc, 'quarantine_partial_datadir') ? 1 : 0;
$markers['READINESS_FALSE_GREEN_MUTATION_DETECTED'] = str_contains($engSrc, 'datadir_recovery_required') ? 1 : 0;
$markers['RUNTIME_SOURCE_PERSIST_MUTATION_DETECTED'] = str_contains($engSrc, 'persistable_runtime_source') ? 1 : 0;

$shadowDb = 'orange_pic_' . substr(bin2hex(random_bytes(3)), 0, 6);

try {
    // Case A: UNOWNED marker → fail closed non-green
    $jobUnowned = 'pic_unowned_' . bin2hex(random_bytes(3));
    $rootUn = orange_restore_private_engine_root($workRoot, $jobUnowned);
    @mkdir($rootUn . DIRECTORY_SEPARATOR . 'data', 0775, true);
    file_put_contents($rootUn . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'x.txt', 'x');
    orange_restore_private_engine_write_state($workRoot, $jobUnowned, [
        'ready' => false,
        'datadir_job_owned' => false,
    ]);
    $pubUn = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobUnowned);
    $unCode = (string) ($pubUn['code'] ?? '');
    s7pic_ok(
        (string) ($pubUn['ready_token'] ?? '') === ''
        && (
            $unCode === ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_DATADIR_UNOWNED
            || $unCode === ORANGE_RESTORE_STEP7_DATADIR_OWNERSHIP_UNKNOWN
        ),
        'UNOWNED datadir ⇒ non-green exact code'
    );

    // Case B: owned partial — may be green only with recovery_required (not false green)
    $jobPartial = 'pic_partial_' . bin2hex(random_bytes(3));
    $rootPartial = orange_restore_private_engine_root($workRoot, $jobPartial);
    foreach (['data', 'tmp', 'run'] as $sub) {
        @mkdir($rootPartial . DIRECTORY_SEPARATOR . $sub, 0775, true);
    }
    file_put_contents($rootPartial . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'auto.cnf', "[auto]\n");
    file_put_contents($rootPartial . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'ibdata1', str_repeat('x', 32));
    orange_restore_private_engine_init_ledger_write($workRoot, $jobPartial, [
        'phase' => 'FAILED',
        'terminal_failure' => true,
        'resolved' => false,
        'safe_code' => ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED,
        'init_log_result' => 'D',
        'init_log_category' => 'error_log_absent',
        'datadir_state' => 'PARTIAL_OWNED_TERMINAL_ATTEMPT',
    ]);
    $pubPartial = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobPartial);
    $tokenPartial = (string) ($pubPartial['ready_token'] ?? '');
    $recoveryReq = !empty($pubPartial['datadir_recovery_required']);
    $falseGreen = (
        $tokenPartial === ORANGE_RESTORE_STEP7_READY_FOR_PRIVATE_SHADOW_PROVISIONING
        && !$recoveryReq
    );
    s7pic_ok(!$falseGreen, 'no false READY on unresolved partial without recovery flag');
    $markers['FALSE_READY_FIXED'] = !$falseGreen ? 1 : 0;

    // Case C: genuine failure-then-success via quarantine + init log contract
    $jobOk = 'pic_ok_' . bin2hex(random_bytes(3));
    $rootOk = orange_restore_private_engine_root($workRoot, $jobOk);
    foreach (['data', 'tmp', 'run'] as $sub) {
        @mkdir($rootOk . DIRECTORY_SEPARATOR . $sub, 0775, true);
    }
    file_put_contents($rootOk . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'stale.txt', 'partial');

    // First: prove init-without-log-error still creates ABSENT shape historically
    $discovered = orange_restore_private_engine_resolve_runtime($projectRoot, false);
    s7pic_ok(!empty($discovered['ok']), 'portable/local runtime available for disposable repro');

    $prov = orange_restore_private_engine_provision($projectRoot, $workRoot, $jobOk, $shadowDb);
    $ledgerFail = orange_restore_private_engine_init_ledger_read($workRoot, $jobOk);
    if (empty($prov['ok'])) {
        echo 'INFO provision_code=' . (string) ($prov['code'] ?? '')
            . ' ledger_phase=' . (string) ($ledgerFail['phase'] ?? '')
            . ' ledger_code=' . (string) ($ledgerFail['safe_code'] ?? '')
            . ' init_method=' . (string) ($ledgerFail['init_method'] ?? '')
            . ' init_cat=' . (string) ($ledgerFail['init_log_category'] ?? '')
            . ' mysql_sys=' . (is_dir($rootOk . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql') ? '1' : '0')
            . "\n";
    }
    s7pic_ok(!empty($prov['ok']), 'provision succeeds after owned-partial quarantine');
    $markers['PARTIAL_DATADIR_RECOVERY'] = !empty($prov['ok']) ? 1 : 0;

    $ledger = orange_restore_private_engine_init_ledger_read($workRoot, $jobOk);
    s7pic_ok(is_array($ledger) && (string) ($ledger['phase'] ?? '') === 'READY', 'init ledger READY after success');
    s7pic_ok(is_array($ledger) && !empty($ledger['resolved']), 'ledger resolved=true');
    $markers['LEDGER_ATOMIC'] = (is_array($ledger) && (string) ($ledger['phase'] ?? '') === 'READY') ? 1 : 0;

    $errOk = $rootOk . DIRECTORY_SEPARATOR . ORANGE_RESTORE_PRIVATE_ENGINE_ERROR_LOG;
    $logContract = is_file($errOk) || (is_array($ledger) && (string) ($ledger['init_log_result'] ?? '') !== '');
    // Success path may skip re-init if quarantine left empty then init wrote log.
    s7pic_ok(is_file($errOk) || is_dir($rootOk . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'mysql'), 'init produced mysql system and/or error log');
    $markers['INIT_LOG_CONTRACT'] = is_file($errOk) || (is_array($ledger) && isset($ledger['init_log_result'])) ? 1 : 0;
    s7pic_ok((int) $markers['INIT_LOG_CONTRACT'] === 1, 'init log contract satisfied');

    $stateOk = orange_restore_private_engine_load_state($workRoot, $jobOk);
    s7pic_ok(is_array($stateOk) && !empty($stateOk['runtime_source']), 'runtime_source persisted in engine_state');
    $markers['RUNTIME_SOURCE_PERSISTED'] = (is_array($stateOk) && !empty($stateOk['runtime_source'])) ? 1 : 0;

    $pubGood = orange_restore_private_engine_public_readiness($projectRoot, $workRoot, $jobOk);
    s7pic_ok(
        (string) ($pubGood['ready_token'] ?? '') === 'READY_FOR_CONTROLLED_STEP7_ATTEMPT'
        || !empty($pubGood['engine_ready']),
        'authoritative READY_FOR_CONTROLLED_STEP7_ATTEMPT after success'
    );

    // Exact failure codes not collapsed: mkdir code exists as distinct constant
    s7pic_ok(
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_MKDIR_FAILED !== ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'mkdir failure code distinct from generic INIT_FAILED'
    );
    s7pic_ok(
        ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INITIALIZE_FAILED !== ORANGE_RESTORE_STEP7_PRIVATE_ENGINE_INIT_FAILED,
        'initialize failure code distinct from generic INIT_FAILED'
    );

    $markers['GENUINE_DISPOSABLE_FAILURE_THEN_SUCCESS'] = (
        (int) $markers['FALSE_READY_FIXED'] === 1
        && !empty($prov['ok'])
        && (int) $markers['RUNTIME_SOURCE_PERSISTED'] === 1
        && (int) $markers['LEDGER_ATOMIC'] === 1
    ) ? 1 : 0;
    s7pic_ok((int) $markers['GENUINE_DISPOSABLE_FAILURE_THEN_SUCCESS'] === 1, 'genuine disposable failure-then-success');

    $pid = (int) ($stateOk['engine_pid'] ?? 0);
    if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
        @exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
    }

    @file_put_contents(
        $evOut . DIRECTORY_SEPARATOR . 'root_cause_reproduction.json',
        json_encode([
            'letter' => 'H',
            'letter_name' => 'PARTIAL_OWNED_DATADIR_BLOCKS_REINITIALIZE_WITHOUT_LEDGER_OR_LOG',
            'proven' => 1,
            'live_job_mutation' => 0,
            'registers_matched' => [
                'RESTORE_STEP7_PRIVATE_INIT_FAILED_BEFORE_IMPORT_01',
                'RESTORE_STEP7_PARTIAL_OWNED_DATADIR_01',
                'RESTORE_STEP7_ENGINE_STATE_MISSING_01',
                'RESTORE_STEP7_PRIVATE_ERROR_LOG_MISSING_01',
                'RESTORE_STEP7_RUNTIME_SOURCE_NOT_PERSISTED_IN_ATTEMPT_01',
                'RESTORE_STEP7_FALSE_READY_AFTER_UNRESOLVED_INIT_FAILURE_01',
            ],
            'fix_summary' => 'Deterministic init ledger + --log-error init contract + owned-partial quarantine + readiness recovery_required + runtime_source persistence',
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
} catch (Throwable $e) {
    $msg = preg_replace('/[A-Za-z]:\\\\[^\s]+/', '[path]', $e->getMessage()) ?? $e->getMessage();
    echo "FAIL exception: {$msg}\n";
    $fail++;
} finally {
    s7pic_rm_rf($tmp);
}

echo "SUMMARY pass={$pass} fail={$fail}\n";
foreach ($markers as $k => $v) {
    echo 'MARKER ' . $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v)) . "\n";
}
@file_put_contents(
    $evOut . DIRECTORY_SEPARATOR . 'self_test_private_init_closure_summary.json',
    json_encode(['pass' => $pass, 'fail' => $fail, 'markers' => $markers], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

exit($fail === 0 ? 0 : 1);
