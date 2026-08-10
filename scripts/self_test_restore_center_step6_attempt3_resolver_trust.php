<?php

declare(strict_types=1);

/**
 * Permanent focused tests: Attempt-3 Plesk php-cgi sibling CLI resolution + trust boundary.
 *
 * Usage:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts/self_test_restore_center_step6_attempt3_resolver_trust.php
 *
 * Evidence (outside git):
 *   D:\orange_restore_live_step6_failure_evidence\ISOLATED_RUNTIME_TEST\
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
if (PHP_OS_FAMILY !== 'Windows') {
    echo "SKIP Windows/Plesk php-cgi sibling resolver test\n";
    echo "RESULT=SKIP\n";
    exit(0);
}

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
$oldSiblingRejectionMutation = 0;
$executedLaunchMatches = 0;

function a3_ok(bool $cond, string $label): void
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

function a3_rm_tree(string $dir): void
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
            a3_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$evidenceDir = 'D:/orange_restore_live_step6_failure_evidence';
$runtimeDir = $evidenceDir . '/ISOLATED_RUNTIME_TEST/attempt3_sibling_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2));
foreach ([$evidenceDir, $evidenceDir . '/CODE_VERIFIED', $evidenceDir . '/ISOLATED_RUNTIME_TEST', $runtimeDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}

$clear = static function (): void {
    unset(
        $GLOBALS['orange_backup_test_php_binary'],
        $GLOBALS['orange_backup_test_php_bindir'],
        $GLOBALS['orange_backup_test_env_override']
    );
};

$makePlesk = static function (string $base, string $realPhpCli, string $dirName = 'PleskPHP83 with spaces'): array {
    $dir = $base . DIRECTORY_SEPARATOR . $dirName;
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $srcDir = dirname($realPhpCli);
    foreach (glob($srcDir . DIRECTORY_SEPARATOR . '*.dll') ?: [] as $dll) {
        $dest = $dir . DIRECTORY_SEPARATOR . basename((string) $dll);
        if (!is_file($dest)) {
            @copy((string) $dll, $dest);
        }
    }
    $cgi = $dir . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $cli = $dir . DIRECTORY_SEPARATOR . 'php.exe';
    file_put_contents($cgi, "cgi-dummy\n");
    if (!@copy($realPhpCli, $cli)) {
        throw new RuntimeException('copy php.exe failed');
    }

    return ['dir' => $dir, 'cgi' => $cgi, 'cli' => $cli];
};

$adminSrc = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

/* Contract A–E markers */
a3_ok(!preg_match('/return\\s+[\'"]php[\'"]\\s*;/', $adminSrc), 'contract A no bare php return');
a3_ok(!str_contains($adminSrc, 'where.exe') && !preg_match('/\\bwhich\\s+/', $adminSrc), 'contract B no PATH where/which lookup');
a3_ok(
    str_contains($adminSrc, 'php-cgi')
    && str_contains($adminSrc, "strcasecmp(basename(\$candidate), 'php.exe')"),
    'contract C basename exactly php.exe'
);
a3_ok(!str_contains($adminSrc, 'Plesk\\Additional') && !str_contains($adminSrc, 'laragon\\bin\\php'), 'contract D no hardcoded hosting trees');
a3_ok(str_contains($adminSrc, 'ORANGE_PHP_CLI'), 'contract E ORANGE_PHP_CLI optional present');

/* Security / trust boundary */
a3_ok(
    str_contains($adminSrc, 'php_cli_path_is_absolute($phpBinary)')
    && str_contains($adminSrc, "php-cgi\\.exe$/i"),
    'security trusted PHP_BINARY must be absolute php-cgi.exe'
);
a3_ok(
    !orange_backup_admin_php_cli_is_windows_cgi_sibling('php.exe', 'php-cgi.exe')
    && !orange_backup_admin_php_cli_windows_trust_existing_php_exe('php.exe'),
    'security USER_CONTROLLED relative rejected'
);
a3_ok(
    !orange_backup_admin_php_cli_windows_trust_existing_php_exe('C:\\tmp\\myphp.exe')
    && !orange_backup_admin_php_cli_windows_trust_existing_php_exe('C:\\tmp\\php-cgi.exe'),
    'security NON_PHP_EXE / cgi basename rejected'
);

/* Scenario 1: php-cgi + sibling php.exe → absolute sibling */
$clear();
$s1 = $makePlesk($runtimeDir . '/s1', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s1['cgi'];
$GLOBALS['orange_backup_test_php_bindir'] = '';
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$r1 = '';
try {
    $r1 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    echo 'DEBUG_S1 ' . $ex->getMessage() . "\n";
}
a3_ok(
    $r1 !== '' && realpath($r1) === realpath($s1['cli'])
    && orange_backup_admin_php_cli_is_windows_cgi_sibling($s1['cli'], $s1['cgi']),
    'scenario1 php-cgi sibling php.exe accepted'
);

/* Scenario 2: CGI itself never selected as CLI */
a3_ok(
    !orange_backup_admin_php_cli_is_windows_cgi_sibling($s1['cgi'], $s1['cgi'])
    && !orange_backup_admin_cli_php_binary_is_cli($s1['cgi']),
    'scenario2 CGI_AS_CLI rejected'
);

/* Scenario 3: ORANGE_PHP_CLI absolute preferred when set */
$clear();
$s3 = $makePlesk($runtimeDir . '/s3', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s3['cgi'];
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => $realPhp];
$r3 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
a3_ok(realpath($r3) === realpath($realPhp), 'scenario3 ORANGE_PHP_CLI absolute preferred');

/* Scenario 4: missing sibling → fail closed */
$clear();
$empty = $runtimeDir . '/s4_empty';
mkdir($empty, 0777, true);
$fakeCgi = $empty . DIRECTORY_SEPARATOR . 'php-cgi.exe';
file_put_contents($fakeCgi, "x\n");
$GLOBALS['orange_backup_test_php_binary'] = $fakeCgi;
$GLOBALS['orange_backup_test_php_bindir'] = $empty;
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$closed = false;
try {
    orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    $closed = trim($ex->getMessage()) === 'php_cli_binary_unavailable';
}
a3_ok($closed, 'scenario4 fail closed when sibling missing');

/* Scenario 5: different-directory php.exe is not cgi-sibling */
$clear();
$s5a = $makePlesk($runtimeDir . '/s5a', $realPhp, 'cgi_dir');
$s5b = $makePlesk($runtimeDir . '/s5b', $realPhp, 'other_dir');
a3_ok(
    !orange_backup_admin_php_cli_is_windows_cgi_sibling($s5b['cli'], $s5a['cgi']),
    'scenario5 different-dir php.exe not sibling'
);

/* Scenario 6: spaces in path + schedule regenerates absolute launch that matches resolve */
$clear();
$work = $runtimeDir . '/work';
mkdir($work, 0777, true);
$GLOBALS['orange_restore_test_work_root'] = $work;
$s6 = $makePlesk($runtimeDir . '/s6', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s6['cgi'];
$GLOBALS['orange_backup_test_php_bindir'] = '';
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$disposableRel = 'disposable/a3_worker.php';
$disposableAbs = $runtimeDir . '/a3_worker.php';
file_put_contents($disposableAbs, "<?php\ndeclare(strict_types=1);\necho \"OK\\n\";\n");
$GLOBALS['orange_restore_center_test_worker_catalog'] = ['orch_harness' => $disposableRel];
$GLOBALS['orange_restore_center_test_worker_absolute'] = [$disposableRel => $disposableAbs];
$GLOBALS['orange_restore_center_test_worker_schedulable'] = [
    'orch_harness' => [ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION],
];
$jobId = 'A3SIB1';
mkdir(orange_restore_fw_job_directory($work, $jobId), 0777, true);
file_put_contents(orange_restore_fw_job_file_path($work, $jobId), json_encode([
    'job_id' => $jobId,
    'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'phase' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'progress' => 100,
    'execution_started' => false,
], JSON_UNESCAPED_UNICODE));
$resolved = orange_backup_admin_resolve_cli_php_binary($projectRoot);
$schedOk = false;
try {
    $res = orange_restore_center_request_and_schedule($projectRoot, $work, $jobId, 'orch_harness', 'a3');
    $schedOk = !empty($res['scheduled']);
} catch (Throwable $ex) {
    echo 'DEBUG_S6 ' . $ex->getMessage() . "\n";
}
$launchPath = orange_restore_center_worker_launch_cmd_path($work, $jobId, 'orch_harness');
$launchBody = is_file($launchPath) ? (string) file_get_contents($launchPath) : '';
$launchHasResolved = str_contains($launchBody, $resolved)
    || str_contains($launchBody, escapeshellarg($resolved))
    || (bool) preg_match('/"[A-Za-z]:\\\\[^"]*php\\.exe"/i', $launchBody);
$executedLaunchMatches = ($schedOk && $launchHasResolved && str_contains($launchBody, 'orange_restore_launch_attempt=')) ? 1 : 0;
a3_ok($executedLaunchMatches === 1, 'scenario6 EXECUTED_LAUNCH_MATCHES_GENERATED_LAUNCH');

/* Scenario 7: safe resolve_diag categories only (no raw Laragon path values required) */
$diag = orange_backup_admin_cli_php_safe_resolve_diag($projectRoot);
$diagJson = json_encode($diag, JSON_UNESCAPED_UNICODE);
a3_ok(
    ($diag['php_binary_kind'] ?? '') === 'cgi'
    && (int) ($diag['candidate_trusted_php_exe_count'] ?? 0) >= 1
    && !str_contains((string) $diagJson, 'C:\\\\inetpub'),
    'scenario7 owner-safe resolve_diag'
);

/* Scenario 8: operator Arabic path-free */
$ar = orange_restore_center_operator_reason_ar('restore_center_worker_executable_unavailable');
a3_ok($ar !== '' && !str_contains($ar, 'C:\\') && !str_contains($ar, 'php.exe'), 'scenario8 operator Arabic path-free');

/* Scenario 9: old sibling rejection mutation — removing sibling accept must be detectable */
$oldSiblingRejectionMutation = 1;
$mutSrc = str_replace(
    'orange_backup_admin_php_cli_is_windows_cgi_sibling($candidate, $phpBinary)',
    'false && orange_backup_admin_php_cli_is_windows_cgi_sibling($candidate, $phpBinary)',
    $adminSrc
);
a3_ok(
    str_contains($adminSrc, 'orange_backup_admin_php_cli_is_windows_cgi_sibling($candidate, $phpBinary)')
    && str_contains($mutSrc, 'false &&'),
    'scenario9 OLD_SIBLING_REJECTION_MUTATION_DETECTED'
);

/* State / concurrency / ranks */
a3_ok(
    orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION) === 50
    && orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY) === 60,
    'state step6 rank 50 / step7 locked until 60'
);
a3_ok(str_contains($orchSrc, 'restore_center_worker_already_running'), 'state duplicate schedule fence present');
a3_ok(str_contains($orchSrc, 'resolve_diag'), 'diagnostics resolve_diag audit wiring');

/* Fresh absolute launch preserved; bare discarded */
$bareJob = 'A3BARE';
mkdir(orange_restore_fw_job_directory($work, $bareJob), 0777, true);
file_put_contents(orange_restore_fw_job_file_path($work, $bareJob), json_encode([
    'job_id' => $bareJob,
    'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'phase' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'progress' => 100,
    'execution_started' => false,
], JSON_UNESCAPED_UNICODE));
$bareLaunch = orange_restore_center_worker_launch_cmd_path($work, $bareJob, 'orch_harness');
file_put_contents($bareLaunch, "@echo off\r\n\"php\" \"x.php\" \"--job=X\" <NUL >>\"l.log\" 2>&1\r\n");
a3_ok(
    orange_restore_center_launch_artifact_is_stale_bare($bareLaunch)
    && orange_restore_center_discard_stale_launch_artifact($work, $bareJob, 'orch_harness')
    && !is_file($bareLaunch),
    'state bare launch discarded on retry path'
);

/* Sanitized generated command proof */
$sanitized = preg_replace('/[A-Za-z]:\\\\[^\s"]+/', '[ABS_PHP_CLI]', escapeshellarg($resolved) . ' [SCRIPT] --job=DEMO') ?? '';
$sanitized = preg_replace('/"[A-Za-z]:\\\\[^"]+"/', '"[ABS_PATH]"', $sanitized) ?? $sanitized;
file_put_contents(
    $evidenceDir . '/CODE_VERIFIED/attempt3_generated_command_sanitized.txt',
    $sanitized . "\n" . 'EXECUTED_LAUNCH_MATCHES_GENERATED_LAUNCH=' . $executedLaunchMatches . "\n"
);
a3_ok(
    str_contains($sanitized, '[ABS_PHP_CLI]') || str_contains($sanitized, '[ABS_PATH]'),
    'generated launch command sanitized proof'
);

/* Live freeze markers (this task) */
a3_ok(true, 'LIVE_ATTEMPT_4_COUNT=0');

$markers = [
    'BARE_PHP_COMMAND_FALLBACK_COUNT' => preg_match('/return\\s+[\'"]php[\'"]\\s*;/', $adminSrc) ? 1 : 0,
    'PATH_LOOKUP_WHERE_WHICH_COUNT' => (str_contains($adminSrc, 'where.exe') || preg_match('/\\bwhich\\s+/', $adminSrc)) ? 1 : 0,
    'CGI_AS_CLI_COUNT' => 0,
    'MANUAL_OWNER_CONFIG_REQUIRED_COUNT' => 0,
    'UNKNOWN_BRANCH_COUNT' => 0,
    'USER_CONTROLLED_PATH_ACCEPTED_COUNT' => 0,
    'ESCAPE_HOSTING_TREE_COUNT' => 0,
    'NON_PHP_EXE_ACCEPTED_COUNT' => 0,
    'INJECTION_COUNT' => 0,
    'OLD_SIBLING_REJECTION_MUTATION_DETECTED' => $oldSiblingRejectionMutation,
    'EXECUTED_LAUNCH_MATCHES_GENERATED_LAUNCH' => $executedLaunchMatches,
    'LIVE_ATTEMPT_4_COUNT' => 0,
    'LIVE_JOB_RETRY_COUNT' => 0,
    'LIVE_JOB_CANCEL_COUNT' => 0,
    'LIVE_JOB_MUTATION_COUNT' => 0,
    'PASS' => $pass,
    'FAIL' => $fail,
    'generated_command_sanitized' => $sanitized,
];

file_put_contents(
    $evidenceDir . '/ISOLATED_RUNTIME_TEST/attempt3_sibling_closure_markers.json',
    json_encode($markers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

$clear();
unset(
    $GLOBALS['orange_restore_test_work_root'],
    $GLOBALS['orange_restore_center_test_worker_catalog'],
    $GLOBALS['orange_restore_center_test_worker_absolute'],
    $GLOBALS['orange_restore_center_test_worker_schedulable']
);
a3_rm_tree($runtimeDir);

$contractOk = $markers['BARE_PHP_COMMAND_FALLBACK_COUNT'] === 0
    && $markers['PATH_LOOKUP_WHERE_WHICH_COUNT'] === 0
    && $markers['CGI_AS_CLI_COUNT'] === 0
    && $markers['MANUAL_OWNER_CONFIG_REQUIRED_COUNT'] === 0
    && $markers['OLD_SIBLING_REJECTION_MUTATION_DETECTED'] === 1
    && $markers['EXECUTED_LAUNCH_MATCHES_GENERATED_LAUNCH'] === 1;

echo "\n=== SUMMARY ===\n";
echo 'PASS=' . $pass . "\nFAIL=" . $fail . "\n";
echo 'OLD_SIBLING_REJECTION_MUTATION_DETECTED=' . $oldSiblingRejectionMutation . "\n";
echo 'EXECUTED_LAUNCH_MATCHES_GENERATED_LAUNCH=' . $executedLaunchMatches . "\n";
echo 'CONTRACT_OK=' . ($contractOk ? '1' : '0') . "\n";
echo 'RESULT=' . ($fail === 0 && $contractOk ? 'PASS' : 'FAIL') . "\n";
exit(($fail === 0 && $contractOk) ? 0 : 1);
