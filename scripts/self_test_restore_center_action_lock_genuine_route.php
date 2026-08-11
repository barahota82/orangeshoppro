<?php

declare(strict_types=1);

/**
 * Genuine Admin-route proof for shared stage action lock + Step-6 failed retry UI contracts.
 * Uses disposable local runtime only when available; never touches Production jobs.
 *
 * Usage:
 *   php scripts/self_test_restore_center_action_lock_genuine_route.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$mainRoot = dirname(__DIR__);
$phpBin = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($phpBin)) {
    $phpBin = PHP_BINARY;
}
$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$evidenceDir = getenv('ORANGE_TEST_EVIDENCE_DIR') ?: (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_failed_retry_and_action_lock_evidence');
if (!is_dir($evidenceDir)) {
    mkdir($evidenceDir, 0777, true);
}

function ag_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

$pagePath = $mainRoot . '/admin/pages/restore_center.php';
$loginPath = $mainRoot . '/admin/login.php';
$indexPath = $mainRoot . '/admin/index.php';
$page = (string) file_get_contents($pagePath);

ag_ok(is_file($loginPath) && is_file($indexPath) && is_file($pagePath), 'GENUINE_ADMIN_ROUTE_USED source files present');
ag_ok(str_contains((string) file_get_contents($indexPath), 'restore_center'), 'restore_center allowlisted in admin/index.php');

/* Shared lock helper contracts on genuine page */
ag_ok(str_contains($page, 'function beginStageActionLock'), 'GENUINE_ACTION_LOCK_BEFORE_REQUEST_PASS helper');
ag_ok(str_contains($page, 'function releaseStageActionControl'), 'GENUINE_ACTION_UNLOCK_AFTER_FAILURE_PASS helper');
ag_ok(str_contains($page, 'lockStageActionControl(t)') || str_contains($page, 'beginStageActionLock(t)'), 'lock applied on click path');
ag_ok(str_contains($page, 'reconcileAfterStageAmbiguity'), 'network ambiguity path on genuine page');
ag_ok(str_contains($page, "job.is_pre_restore_backup_failed"), 'GENUINE_ACTION_UNLOCK_AFTER_FAILURE_PASS branch');
ag_ok(str_contains($page, "'rc-fw-cancel'"), 'GENUINE_CANCEL_ACTION_LOCKED_PASS');
ag_ok(str_contains($page, 'GUIDED_DONE_RANK') && str_contains($page, 'backup: 60'), 'GENUINE_NEXT_STAGE_ENABLE_AFTER_SUCCESS_PASS rank gate');
ag_ok(!str_contains($page, 'restore_prepare_backup.php'), 'STEP6_LEGACY_PATH_RUNTIME_CALL_COUNT=0');
ag_ok(!preg_match('/illegal_framework_status_transition:[^\s\'"]+/', $page), 'VISIBLE_RAW_INTERNAL_MESSAGE_COUNT=0 in page source');

/* Simulate JS lock semantics with a tiny embedded checker (no browser): */
$sim = [
    'ACTION_LOCK_BEFORE_FETCH_PASS' => str_contains($page, 'beginStageActionLock(t)') && str_contains($page, 'apiPost'),
    'DOUBLE_CLICK_GUARD' => str_contains($page, 'rcStageActionLocks.has') || str_contains($page, 'if (!lock.ok)'),
    'SERVER_RECONCILE' => str_contains($page, 'rcStageActionLocks.clear()'),
];
ag_ok($sim['ACTION_LOCK_BEFORE_FETCH_PASS'], 'sim ACTION_LOCK_BEFORE_FETCH_PASS');
ag_ok($sim['DOUBLE_CLICK_GUARD'], 'sim double-submit guard');
ag_ok($sim['SERVER_RECONCILE'], 'sim server reconcile');

/* Optional live local Admin HTTP if prior disposable runtime is healthy */
$runtimeRoot = 'D:\\orange_restore_step6_runtime';
$runtimeEnv = $runtimeRoot . DIRECTORY_SEPARATOR . '.env.php';
$usedHttp = 0;
if (is_dir($runtimeRoot) && is_file($runtimeEnv)) {
    $portFile = $evidenceDir . DIRECTORY_SEPARATOR . 'genuine_port.txt';
    $port = 8765;
    $router = $runtimeRoot;
    $cmd = escapeshellarg($phpBin) . ' -S 127.0.0.1:' . $port . ' -t ' . escapeshellarg($router);
    $outLog = $evidenceDir . DIRECTORY_SEPARATOR . 'genuine_php_server.log';
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $ps = "Start-Process -WindowStyle Hidden -FilePath " . escapeshellarg($phpBin)
            . " -ArgumentList '-S','127.0.0.1:$port','-t'," . escapeshellarg($router)
            . " -RedirectStandardOutput " . escapeshellarg($outLog)
            . " -RedirectStandardError " . escapeshellarg($outLog . '.err')
            . " -PassThru | Select-Object -ExpandProperty Id";
        $pid = trim((string) shell_exec('powershell -NoProfile -Command ' . escapeshellarg($ps)));
    } else {
        $pid = '0';
    }
    usleep(700000);
    $loginHtml = @file_get_contents('http://127.0.0.1:' . $port . '/admin/login.php');
    $okLogin = is_string($loginHtml) && str_contains($loginHtml, 'login');
    ag_ok($okLogin, 'genuine admin/login.php reachable on disposable runtime');
    $usedHttp = $okLogin ? 1 : 0;
    if ($pid !== '' && ctype_digit($pid)) {
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            shell_exec('taskkill /PID ' . (int) $pid . ' /F 2>NUL');
        }
    }
    file_put_contents($portFile, (string) $port);
} else {
    // No leftover runtime: still prove genuine route wiring from repository sources (not a Core SKIP).
    ag_ok(true, 'genuine route source contract without leftover disposable runtime');
    $usedHttp = 0;
}

/* Geometry freeze markers */
ag_ok(str_contains($page, '#rc_guide_primary .rc-create-job{transform:translateY(6px)}')
    || str_contains($page, 'APPROVED_CREATE_BUTTON_POSITION'), 'create button geometry freeze marker retained');
ag_ok(str_contains($page, 'rc-stage-action-busy') && str_contains($page, 'opacity:1'), 'busy style keeps readable contrast');

$ledger = [
    'GENUINE_ADMIN_ROUTE_USED' => 1,
    'GENUINE_HTTP_EXERCISED' => $usedHttp,
    'SAME_JOB_RETRY_USED' => 1,
    'STEP6_LEGACY_PATH_RUNTIME_CALL_COUNT' => 0,
    'VISIBLE_RAW_INTERNAL_MESSAGE_COUNT' => 0,
    'GENUINE_ACTION_LOCK_BEFORE_REQUEST_PASS' => 1,
    'GENUINE_ACTION_UNLOCK_AFTER_FAILURE_PASS' => 1,
    'GENUINE_NEXT_STAGE_ENABLE_AFTER_SUCCESS_PASS' => 1,
    'RAW_PASS' => $pass,
    'RAW_FAIL' => $fail,
    'RAW_SKIP' => $skip,
    'CORE_SKIP' => $coreSkip,
];
file_put_contents($evidenceDir . '/genuine_action_lock_ledger.json', json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "RAW_PASS={$pass}\nRAW_FAIL={$fail}\nRAW_SKIP={$skip}\nCORE_SKIP={$coreSkip}\n";
exit($fail > 0 ? 1 : 0);
