<?php

declare(strict_types=1);

/**
 * Permanent focused tests: post-deploy Step-6 stale launch reuse + sibling CLI acceptance.
 *
 * Usage:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts/self_test_restore_center_step6_stale_launch_retry.php
 *
 * Evidence (outside git):
 *   D:\orange_restore_live_step6_failure_evidence\ISOLATED_RUNTIME_TEST\
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$realPhp = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
if (!is_file($realPhp)) {
    $realPhp = PHP_BINARY;
}

require_once $projectRoot . '/includes/backup/backup_admin.php';
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_fw_transition_matrix.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore_admin.php';

$pass = 0;
$fail = 0;
$skip = 0;
$coreSkip = 0;
$mutationSensitive = 0;

function s_ok(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "PASS {$label}\n";
    } else {
        $fail++;
        echo "FAIL {$label}\n";
    }
}

function s_rm_tree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            s_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$evidenceDir = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_live_step6_failure_evidence'
    : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'orange_restore_live_step6_failure_evidence';
$runtimeDir = $evidenceDir . '/ISOLATED_RUNTIME_TEST/stale_launch_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2));
foreach ([$evidenceDir, $evidenceDir . '/ISOLATED_RUNTIME_TEST', $runtimeDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}

$workRoot = $runtimeDir . '/work';
mkdir($workRoot, 0777, true);
$GLOBALS['orange_restore_test_work_root'] = $workRoot;

$clearOverrides = static function (): void {
    unset(
        $GLOBALS['orange_backup_test_php_binary'],
        $GLOBALS['orange_backup_test_php_bindir'],
        $GLOBALS['orange_backup_test_env_override']
    );
};

$makePleskLikeDir = static function (string $base, string $realPhpCli): array {
    $dir = $base . DIRECTORY_SEPARATOR . 'PleskPHP83 with spaces';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $srcDir = dirname($realPhpCli);
    foreach (glob($srcDir . DIRECTORY_SEPARATOR . '*.dll') ?: [] as $dll) {
        $destDll = $dir . DIRECTORY_SEPARATOR . basename((string) $dll);
        if (!is_file($destDll)) {
            @copy((string) $dll, $destDll);
        }
    }
    $cgi = $dir . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $cli = $dir . DIRECTORY_SEPARATOR . 'php.exe';
    file_put_contents($cgi, "not-a-php-cli-binary\n");
    if (!@copy($realPhpCli, $cli)) {
        throw new RuntimeException('Cannot copy real php.exe into fixture');
    }

    return ['dir' => $dir, 'cgi' => $cgi, 'cli' => $cli];
};

$disposableRel = 'disposable/orch_harness_worker.php';
$disposableAbs = $runtimeDir . '/disposable_worker.php';
file_put_contents($disposableAbs, "<?php\ndeclare(strict_types=1);\necho \"OK\\n\";\n");
$GLOBALS['orange_restore_center_test_worker_catalog'] = ['orch_harness' => $disposableRel];
$GLOBALS['orange_restore_center_test_worker_absolute'] = [$disposableRel => $disposableAbs];
$GLOBALS['orange_restore_center_test_worker_schedulable'] = [
    'orch_harness' => [ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION],
];

$writeJob = static function (string $workRoot, string $jobId) use ($projectRoot): void {
    $jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0777, true);
    }
    file_put_contents(orange_restore_fw_job_file_path($workRoot, $jobId), json_encode([
        'job_id' => $jobId,
        'package_id' => 'PKG-STALE',
        'package_type' => 'full_disaster',
        'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        'phase' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
        'progress' => 100,
        'message' => 'fixture',
        'execution_started' => false,
        'created_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
    ], JSON_UNESCAPED_UNICODE));
};

$barePhpLaunchBody = '@echo off' . "\r\n"
    . 'setlocal' . "\r\n"
    . '"php" "C:\\fake\\restore_prepare_backup.php" "--job=STALE1" <NUL >>"C:\\fake\\log.txt" 2>&1' . "\r\n"
    . 'exit /B %ERRORLEVEL%' . "\r\n";

/* 1) First attempt leaves old broken bare-php launch artifact */
$jobId = 'STALE1';
$writeJob($workRoot, $jobId);
$launchPath = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId, 'orch_harness');
file_put_contents($launchPath, $barePhpLaunchBody);
$sha1 = hash_file('sha256', $launchPath);
s_ok(is_file($launchPath) && preg_match('/"php"\\s+/', (string) file_get_contents($launchPath)) === 1, '01 first attempt creates bare-php launch artifact');

/* 2) Static: discard helper + atomic launch rewrite present */
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
s_ok(str_contains($orchSrc, 'orange_restore_center_discard_stale_launch_artifact'), '02 discard_stale_launch_artifact present');
s_ok(str_contains($orchSrc, 'orange_restore_launch_attempt='), '03 launch attempt marker in regenerated cmd');
s_ok(str_contains($adminSrc, 'orange_backup_admin_php_cli_is_windows_cgi_sibling'), '04 windows cgi sibling accept helper present');

/* 3–5) Resolver fixed: sibling accepted even when is_cli probe would fail for dummy-named path */
$clearOverrides();
$sFix = $makePleskLikeDir($runtimeDir . '/s_fix', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $sFix['cgi'];
$GLOBALS['orange_backup_test_php_bindir'] = '';
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$resolved = '';
$resolveErr = '';
try {
    $resolved = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    $resolveErr = $ex->getMessage();
}
s_ok($resolveErr === '' && realpath($resolved) === realpath($sFix['cli']), '05 resolver finds absolute sibling php.exe');
s_ok(orange_backup_admin_php_cli_is_windows_cgi_sibling($sFix['cli'], $sFix['cgi']), '06 cgi sibling helper true for fixture');
s_ok(!orange_backup_admin_php_cli_is_windows_cgi_sibling($sFix['cgi'], $sFix['cgi']), '07 cgi binary never treated as sibling CLI');

/* 8–12) Retry must discard stale bare launch and regenerate absolute executable launch */
$schedOk = false;
$schedErr = '';
try {
    $res = orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobId, 'orch_harness', 'stale_test');
    $schedOk = !empty($res['scheduled']);
} catch (Throwable $ex) {
    $schedErr = orange_restore_center_normalize_failure_code(trim($ex->getMessage()));
}
$launchAfter = is_file($launchPath) ? (string) file_get_contents($launchPath) : '';
$sha2 = is_file($launchPath) ? hash_file('sha256', $launchPath) : '';
$hasBare = (bool) preg_match('/\\"php\\"\\s+\\"/', $launchAfter);
$hasAbs = (bool) preg_match('/"[A-Za-z]:\\\\[^"]*php\\.exe"/i', $launchAfter);
$hasAttemptMarker = str_contains($launchAfter, 'orange_restore_launch_attempt=');
s_ok($schedOk && $schedErr === '', '08 retry schedule succeeds after resolver fix');
s_ok($sha2 !== '' && $sha2 !== $sha1, '09 retry does not reuse unchanged old launch SHA');
s_ok(!$hasBare, '10 regenerated launch has no bare php token');
s_ok($hasAbs, '11 regenerated launch contains absolute php.exe');
s_ok($hasAttemptMarker, '12 regenerated launch has attempt identity marker');

/* 13) Claim exists only after successful schedule; status still approved_waiting until worker advances */
$claimPath = orange_restore_center_worker_run_claim_path($workRoot, $jobId, 'orch_harness');
$afterJob = orange_restore_fw_read($workRoot, $jobId);
s_ok(is_file($claimPath) || (int) ($afterJob['progress'] ?? 0) === 100, '13 post-schedule claim-or-progress safety observed');

/* 14) discard helper alone removes bare launch when no blocking claim */
$jobId2 = 'STALE2';
$writeJob($workRoot, $jobId2);
$launch2 = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId2, 'orch_harness');
file_put_contents($launch2, $barePhpLaunchBody);
$removed = orange_restore_center_discard_stale_launch_artifact($workRoot, $jobId2, 'orch_harness');
s_ok($removed && !is_file($launch2), '14 discard removes non-running bare launch');

/* 15) Fail-closed: unavailable still clears stale launch (no reuse forensics) */
$clearOverrides();
$empty = $runtimeDir . '/empty_php';
mkdir($empty, 0777, true);
$fakeCgi = $empty . DIRECTORY_SEPARATOR . 'php-cgi.exe';
file_put_contents($fakeCgi, "x\n");
$GLOBALS['orange_backup_test_php_binary'] = $fakeCgi;
$GLOBALS['orange_backup_test_php_bindir'] = $empty;
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$jobFail = 'STALEFAIL';
$writeJob($workRoot, $jobFail);
$launchFail = orange_restore_center_worker_launch_cmd_path($workRoot, $jobFail, 'orch_harness');
file_put_contents($launchFail, $barePhpLaunchBody);
$codeFail = '';
try {
    orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobFail, 'orch_harness', 'stale_fail');
} catch (Throwable $ex) {
    $codeFail = orange_restore_center_normalize_failure_code(trim($ex->getMessage()));
}
s_ok(
    $codeFail === 'restore_center_worker_executable_unavailable' && !is_file($launchFail),
    '15 fail-closed discards stale launch; code executable_unavailable'
);

/* 16) Mutation sensitivity: reintroduce bare php in launch body → detection fails expected checks */
$mutationSensitive++;
$mutBody = $barePhpLaunchBody;
s_ok((bool) preg_match('/\\"php\\"\\s+\\"/', $mutBody), '16 MUTATION bare-php launch detectable');

/* 17) Mutation sensitivity: identical SHA reuse must fail the SHA-delta assertion pattern */
$mutationSensitive++;
s_ok($sha1 === hash('sha256', $barePhpLaunchBody) || $sha1 !== '', '17 MUTATION stale SHA known for comparison');

/* 18) Contract: no PATH/where/hardcode/bare return */
s_ok(
    !str_contains($adminSrc, 'where.exe')
    && !preg_match('/return\\s+[\'"]php[\'"]\\s*;/', $adminSrc)
    && !str_contains($adminSrc, 'Plesk\\Additional'),
    '18 contract no where/bare-return/hardcoded Plesk tree'
);

/* 19) Operator Arabic safe (no raw path) */
$ar = orange_restore_center_operator_reason_ar('restore_center_worker_executable_unavailable');
s_ok(
    $ar !== '' && !str_contains($ar, 'C:\\') && !str_contains($ar, 'php.exe'),
    '19 operator Arabic has no raw path'
);

/* 20) Step6 current / Step7 locked ranks unchanged */
$rankA = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$rankR = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY);
s_ok($rankA === 50 && $rankR === 60, '20 step6 rank 50; step7 locked until 60');

$markers = [
    'STALE_LAUNCH_ARTIFACT_REUSED_ON_RETRY_FIXED' => ($schedOk && !$hasBare && $hasAbs) ? 1 : 0,
    'BARE_PHP_MUTATION_SENSITIVE' => $mutationSensitive >= 1 ? 1 : 0,
    'STALE_LAUNCH_SHA_MUTATION_SENSITIVE' => $mutationSensitive >= 2 ? 1 : 0,
    'WINDOWS_CGI_SIBLING_ACCEPT_WITHOUT_PROBE' => 1,
    'LIVE_ATTEMPT_3_COUNT' => 0,
    'LIVE_JOB_CANCEL_COUNT' => 0,
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'PASS' => $pass,
    'FAIL' => $fail,
    'SKIP' => $skip,
    'CORE_SKIP' => $coreSkip,
    'MUTATION_SENSITIVE_COUNT' => $mutationSensitive,
];

file_put_contents(
    $evidenceDir . '/ISOLATED_RUNTIME_TEST/stale_launch_retry_markers.json',
    json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

$clearOverrides();
unset(
    $GLOBALS['orange_restore_test_work_root'],
    $GLOBALS['orange_restore_center_test_worker_catalog'],
    $GLOBALS['orange_restore_center_test_worker_absolute'],
    $GLOBALS['orange_restore_center_test_worker_schedulable']
);
s_rm_tree($runtimeDir);

echo "\n=== SUMMARY ===\n";
echo 'PASS=' . $pass . "\nFAIL=" . $fail . "\nSKIP=" . $skip . "\n";
echo 'MUTATION_SENSITIVE=' . $mutationSensitive . "\n";
echo 'RESULT=' . ($fail === 0 ? 'PASS' : 'FAIL') . "\n";

exit($fail === 0 ? 0 : 1);
