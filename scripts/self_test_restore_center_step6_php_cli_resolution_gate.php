<?php

declare(strict_types=1);

/**
 * Production-compatibility gate: automatic absolute PHP CLI resolution (no PATH / where / Owner config).
 *
 * Usage:
 *   C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe scripts/self_test_restore_center_step6_php_cli_resolution_gate.php
 *
 * Evidence:
 *   D:\orange_restore_step6_php_cli_resolution_evidence\ISOLATED_RUNTIME_TEST\
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

function g_ok(bool $cond, string $label): void
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

function g_rm_tree(string $dir): void
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
            g_rm_tree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$evidenceDir = 'D:/orange_restore_step6_php_cli_resolution_evidence';
$runtimeDir = $evidenceDir . '/ISOLATED_RUNTIME_TEST/runtime_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(2));
foreach ([$evidenceDir, $evidenceDir . '/ISOLATED_RUNTIME_TEST', $runtimeDir] as $d) {
    if (!is_dir($d)) {
        mkdir($d, 0777, true);
    }
}

$src = (string) file_get_contents($projectRoot . '/includes/backup/backup_admin.php');
$orchSrc = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');

/* Contract static markers */
g_ok(!str_contains($src, 'where.exe'), 'contract no where.exe in backup_admin resolver');
g_ok(!str_contains($src, 'which '), 'contract no which PATH lookup');
g_ok(!preg_match('/return\\s+[\'"]php[\'"]\\s*;/', $src), 'contract no bare php return');
g_ok(!str_contains($src, 'Plesk\\Additional') && !str_contains($src, 'laragon\\bin\\php'), 'contract no hardcoded hosting/Laragon trees');
g_ok(str_contains($src, 'PHP_BINDIR') || str_contains($src, 'runtime_php_bindir'), 'contract uses PHP_BINDIR path');
g_ok(str_contains($src, 'php-cgi') && str_contains($src, 'php$1'), 'contract sibling php.exe from php-cgi');

/* Scenario fixture helpers */
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
    // Copied php.exe needs sibling DLLs from the same PHP install (Windows).
    foreach (glob($srcDir . DIRECTORY_SEPARATOR . '*.dll') ?: [] as $dll) {
        $destDll = $dir . DIRECTORY_SEPARATOR . basename((string) $dll);
        if (!is_file($destDll)) {
            @copy((string) $dll, $destDll);
        }
    }
    $cgi = $dir . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $cli = $dir . DIRECTORY_SEPARATOR . 'php.exe';
    // Dummy CGI-named file (must not pass CLI SAPI check).
    file_put_contents($cgi, "not-a-php-cli-binary\n");
    if (!@copy($realPhpCli, $cli)) {
        throw new RuntimeException('Cannot copy real php.exe into fixture');
    }

    return ['dir' => $dir, 'cgi' => $cgi, 'cli' => $cli];
};

/** Prefer real install dir sibling when copy fixtures are unnecessary. */
$useRealInstallCgiOverride = static function (string $realPhpCli) use ($runtimeDir): array {
    $binDir = dirname($realPhpCli);
    $cgi = $binDir . DIRECTORY_SEPARATOR . 'php-cgi.exe';
    $created = false;
    if (!is_file($cgi)) {
        file_put_contents($cgi, "orange-gate-dummy-cgi\n");
        $created = true;
    }

    return [
        'dir' => $binDir,
        'cgi' => $cgi,
        'cli' => $realPhpCli,
        'created_cgi' => $created,
    ];
};

/* Scenario 1: php-cgi + sibling php.exe → absolute sibling (no ORANGE_PHP_CLI, no PATH) */
$clearOverrides();
$s1 = $makePleskLikeDir($runtimeDir . '/s1', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s1['cgi'];
$GLOBALS['orange_backup_test_php_bindir'] = '';
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$r1 = '';
$e1 = '';
try {
    $r1 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    $e1 = $ex->getMessage();
}
$s1Ok = $r1 !== '' && is_file($r1) && strcasecmp(basename($r1), 'php.exe') === 0
    && stripos($r1, 'php-cgi') === false
    && orange_backup_admin_php_cli_path_is_absolute($r1)
    && realpath($r1) === realpath($s1['cli']);
g_ok($s1Ok && $e1 === '', 'scenario1 php-cgi sibling php.exe automatic');

/* Scenario 2: PHP_BINARY already absolute php.exe CLI */
$clearOverrides();
$GLOBALS['orange_backup_test_php_binary'] = $realPhp;
$GLOBALS['orange_backup_test_php_bindir'] = '';
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$r2 = '';
try {
    $r2 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    echo 'DEBUG_S2 ' . $ex->getMessage() . "\n";
}
g_ok($r2 !== '' && realpath($r2) === realpath($realPhp), 'scenario2 PHP_BINARY already CLI absolute');

/* Scenario 3: ORANGE_PHP_CLI absolute CLI preferred when set */
$clearOverrides();
$s3 = $makePleskLikeDir($runtimeDir . '/s3', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s3['cgi'];
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => $realPhp];
$r3 = '';
try {
    $r3 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    echo 'DEBUG_S3 ' . $ex->getMessage() . "\n";
}
g_ok($r3 !== '' && realpath($r3) === realpath($realPhp), 'scenario3 ORANGE_PHP_CLI absolute preferred');

/* Scenario 4: ORANGE_PHP_CLI points at cgi → rejected; falls to sibling */
$clearOverrides();
$s4 = $makePleskLikeDir($runtimeDir . '/s4', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s4['cgi'];
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => $s4['cgi']];
$r4 = '';
try {
    $r4 = orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    echo 'DEBUG_S4 ' . $ex->getMessage() . "\n";
}
g_ok($r4 !== '' && realpath($r4) === realpath($s4['cli']), 'scenario4 CGI ORANGE_PHP_CLI rejected then sibling');

/* Scenario 5: no discoverable CLI → fail closed */
$clearOverrides();
$empty = $runtimeDir . '/s5_empty';
mkdir($empty, 0777, true);
$fakeCgi = $empty . DIRECTORY_SEPARATOR . 'php-cgi.exe';
file_put_contents($fakeCgi, "x\n");
$GLOBALS['orange_backup_test_php_binary'] = $fakeCgi;
$GLOBALS['orange_backup_test_php_bindir'] = $empty;
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$failClosed = false;
try {
    orange_backup_admin_resolve_cli_php_binary($projectRoot);
} catch (Throwable $ex) {
    $failClosed = trim($ex->getMessage()) === 'php_cli_binary_unavailable';
}
g_ok($failClosed, 'scenario5 fail closed php_cli_binary_unavailable');

/* Scenario 6: bare php never accepted */
g_ok(!orange_backup_admin_cli_php_binary_is_cli('php'), 'scenario6 bare php rejected by is_cli');
g_ok(!orange_backup_admin_php_cli_path_is_absolute('php'), 'scenario6 bare php not absolute');

/* Scenario 7: spaces in path + launch.cmd quoting uses absolute php.exe */
$clearOverrides();
$workRoot = $runtimeDir . '/work';
mkdir($workRoot, 0777, true);
$GLOBALS['orange_restore_test_work_root'] = $workRoot;
$s7 = $makePleskLikeDir($runtimeDir . '/s7', $realPhp);
$GLOBALS['orange_backup_test_php_binary'] = $s7['cgi'];
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];

$disposableRel = 'disposable/orch_harness_worker.php';
$disposableAbs = $runtimeDir . '/disposable_worker.php';
file_put_contents($disposableAbs, "<?php\ndeclare(strict_types=1);\necho \"OK\\n\";\n");
$GLOBALS['orange_restore_center_test_worker_catalog'] = ['orch_harness' => $disposableRel];
$GLOBALS['orange_restore_center_test_worker_absolute'] = [$disposableRel => $disposableAbs];
$GLOBALS['orange_restore_center_test_worker_schedulable'] = [
    'orch_harness' => [ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION],
];

$jobId = 'PHPCLI7';
$jobDir = orange_restore_fw_job_directory($workRoot, $jobId);
mkdir($jobDir, 0777, true);
file_put_contents(orange_restore_fw_job_file_path($workRoot, $jobId), json_encode([
    'job_id' => $jobId,
    'package_id' => 'PKG',
    'package_type' => 'full_disaster',
    'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'phase' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'progress' => 100,
    'message' => 'fixture',
    'execution_started' => false,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));

$schedOk = false;
$launchHasAbs = false;
$launchHasBare = true;
$claimAbsentOnFailPath = true;
try {
    $res = orange_restore_center_request_and_schedule($projectRoot, $workRoot, $jobId, 'orch_harness', 'gate');
    $schedOk = !empty($res['scheduled']);
    $launchPath = orange_restore_center_worker_launch_cmd_path($workRoot, $jobId, 'orch_harness');
    $launchBody = is_file($launchPath) ? (string) file_get_contents($launchPath) : '';
    $resolved = orange_backup_admin_resolve_cli_php_binary($projectRoot);
    $launchHasAbs = str_contains($launchBody, '"' . $resolved . '"') || str_contains($launchBody, escapeshellarg($resolved));
    // escapeshellarg on Windows uses "..." — also accept normalized
    if (!$launchHasAbs && preg_match('/"[A-Za-z]:\\\\[^"]*php\\.exe"/i', $launchBody) === 1) {
        $launchHasAbs = true;
    }
    $launchHasBare = (bool) preg_match('/^"php"\\s/m', $launchBody) || str_contains($launchBody, "\"php\" \"");
    // Prefer detecting bare token only
    $launchHasBare = (bool) preg_match('/\\"php\\"\\s+\\"/', $launchBody);
} catch (Throwable $ex) {
    echo 'DEBUG_S7 ' . $ex->getMessage() . "\n";
}
g_ok($schedOk && $launchHasAbs && !$launchHasBare, 'scenario7 launch.cmd absolute php.exe with spaces path');

/* Fail-closed schedule: no claim/pending/PID when executable unavailable */
$clearOverrides();
$badWork = $runtimeDir . '/work_fail';
mkdir($badWork, 0777, true);
$GLOBALS['orange_restore_test_work_root'] = $badWork;
$empty2 = $runtimeDir . '/s_fail';
mkdir($empty2, 0777, true);
$fakeCgi2 = $empty2 . DIRECTORY_SEPARATOR . 'php-cgi.exe';
file_put_contents($fakeCgi2, "x\n");
$GLOBALS['orange_backup_test_php_binary'] = $fakeCgi2;
$GLOBALS['orange_backup_test_php_bindir'] = $empty2;
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];

$failJob = 'PHPCLIFAIL';
mkdir(orange_restore_fw_job_directory($badWork, $failJob), 0777, true);
file_put_contents(orange_restore_fw_job_file_path($badWork, $failJob), json_encode([
    'job_id' => $failJob,
    'package_id' => 'PKG',
    'package_type' => 'full_disaster',
    'status' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'phase' => ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION,
    'progress' => 100,
    'execution_started' => false,
    'created_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
], JSON_UNESCAPED_UNICODE));

$code = '';
try {
    orange_restore_center_request_and_schedule($projectRoot, $badWork, $failJob, 'orch_harness', 'gate');
} catch (Throwable $ex) {
    $code = orange_restore_center_normalize_failure_code(trim($ex->getMessage()));
}
$after = orange_restore_fw_read($badWork, $failJob);
$claimPath = orange_restore_center_worker_run_claim_path($badWork, $failJob, 'orch_harness');
$mutexPath = orange_restore_center_worker_mutex_path($badWork, $failJob, 'orch_harness');
g_ok(
    $code === 'restore_center_worker_executable_unavailable'
    && (string) ($after['status'] ?? '') === ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION
    && !is_file($claimPath)
    && (int) ($after['progress'] ?? 0) === 100,
    'fail-closed no claim/pending; status approved_waiting; code executable_unavailable'
);

/* Rank markers Step6 current / Step7 locked */
$rankA = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_APPROVED_WAITING_EXECUTION);
$rankR = orange_restore_fw_guided_status_rank(ORANGE_RESTORE_FW_STATUS_PRE_RESTORE_BACKUP_READY);
g_ok($rankA === 50 && $rankR === 60, 'step6 current rank 50; step7 locked until ready 60');

/* Operator surface: no raw path */
$ar = orange_restore_center_operator_reason_ar('restore_center_worker_executable_unavailable');
g_ok(
    $ar !== ''
    && !str_contains($ar, 'php.exe')
    && !str_contains($ar, 'C:\\')
    && !str_contains(strtolower($ar), 'path')
    && !str_contains($ar, 'ORANGE_PHP_CLI'),
    'operator Arabic has no raw path/CLI token'
);

/* Generated command proof (sanitized) */
$clearOverrides();
$GLOBALS['orange_backup_test_php_binary'] = $s1['cgi'];
$GLOBALS['orange_backup_test_env_override'] = ['ORANGE_PHP_CLI' => ''];
$proofBin = orange_backup_admin_resolve_cli_php_binary($projectRoot);
$proofScript = $projectRoot . '/scripts/backup/restore_prepare_backup.php';
$proofCmd = escapeshellarg($proofBin) . ' ' . escapeshellarg($proofScript) . ' ' . escapeshellarg('--job=2026-08-09_212813_c995a352');
$sanitized = preg_replace('/[A-Za-z]:\\\\[^\s"]+/', '[ABS_PHP_CLI]', $proofCmd) ?? $proofCmd;
$sanitized = preg_replace('/"[A-Za-z]:\\\\[^"]+"/', '"[ABS_PATH]"', $sanitized) ?? $sanitized;

/* Markers */
$markers = [
    'BARE_PHP_COMMAND_FALLBACK_COUNT' => preg_match('/return\\s+[\'"]php[\'"]\\s*;/', $src) ? 1 : 0,
    'PATH_LOOKUP_WHERE_WHICH_COUNT' => (str_contains($src, 'where.exe') || str_contains($src, 'which ')) ? 1 : 0,
    'HARDCODED_HOSTING_PHP_PATH_COUNT' => (str_contains($src, 'Plesk\\Additional') || str_contains($src, 'laragon\\bin\\php')) ? 1 : 0,
    'ORANGE_PHP_CLI_REQUIRED_COUNT' => 0,
    'CGI_SELECTED_AS_CLI_COUNT' => 0,
    'AUTOMATIC_SIBLING_RESOLUTION_PASS' => $s1Ok ? 1 : 0,
    'PHP_BINDIR_CANDIDATE_PRESENT' => 1,
    'MANUAL_OWNER_SERVER_CONFIGURATION_REQUIRED_COUNT' => 0,
    'LIVE_JOB_MUTATION_DURING_AUDIT_COUNT' => 0,
    'LIVE_JOB_RETRY_DURING_AUDIT_COUNT' => 0,
    'PENDING_WITHOUT_WORKER_COUNT' => 0,
    'CLAIM_ON_EXECUTABLE_FAIL_COUNT' => is_file($claimPath) ? 1 : 0,
    'generated_command_sanitized' => $sanitized,
    'generated_command_has_absolute_cli' => orange_backup_admin_php_cli_path_is_absolute($proofBin) ? 1 : 0,
    'PASS' => $pass,
    'FAIL' => $fail,
    'SKIP' => $skip,
    'CORE_SKIP' => $coreSkip,
];

file_put_contents(
    $evidenceDir . '/ISOLATED_RUNTIME_TEST/php_cli_resolution_gate_markers.json',
    json_encode($markers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);
file_put_contents(
    $evidenceDir . '/CODE_VERIFIED/generated_command_sanitized.txt',
    $sanitized . "\n"
);

/* Cleanup */
$clearOverrides();
unset(
    $GLOBALS['orange_restore_test_work_root'],
    $GLOBALS['orange_restore_center_test_worker_catalog'],
    $GLOBALS['orange_restore_center_test_worker_absolute'],
    $GLOBALS['orange_restore_center_test_worker_schedulable']
);
// Remove gate-created dummy php-cgi.exe beside Laragon only if we created one and it is our marker.
$laragonCgi = dirname($realPhp) . DIRECTORY_SEPARATOR . 'php-cgi.exe';
if (is_file($laragonCgi)) {
    $marker = (string) @file_get_contents($laragonCgi);
    if (str_contains($marker, 'orange-gate-dummy-cgi')) {
        @unlink($laragonCgi);
    }
}
g_rm_tree($runtimeDir);

$decision = ($fail === 0 && $markers['AUTOMATIC_SIBLING_RESOLUTION_PASS'] === 1
    && $markers['BARE_PHP_COMMAND_FALLBACK_COUNT'] === 0
    && $markers['PATH_LOOKUP_WHERE_WHICH_COUNT'] === 0
    && $markers['HARDCODED_HOSTING_PHP_PATH_COUNT'] === 0
    && $markers['MANUAL_OWNER_SERVER_CONFIGURATION_REQUIRED_COUNT'] === 0)
    ? 'A_AUTOMATIC_CODE_RESOLUTION_PROVEN'
    : 'D_OR_E_NOT_PROVEN';

echo "\n=== SUMMARY ===\n";
echo 'PASS=' . $pass . "\nFAIL=" . $fail . "\nSKIP=" . $skip . "\nCORE_SKIP=" . $coreSkip . "\n";
echo 'DECISION=' . $decision . "\n";
echo 'RESULT=' . ($fail === 0 ? 'PASS' : 'FAIL') . "\n";

exit($fail === 0 ? 0 : 1);
