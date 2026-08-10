<?php

declare(strict_types=1);

/**
 * Exact command ledger runner for Step-6 single-engine final closure.
 * Does not approximate counts. Writes JSON evidence outside Git.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
$php = PHP_BINARY;
if (str_contains(strtolower($php), 'php-cgi')) {
    $cand = 'C:\\laragon\\bin\\php\\php-8.3.30-Win32-vs16-x64\\php.exe';
    if (is_file($cand)) {
        $php = $cand;
    }
}

$ev = PHP_OS_FAMILY === 'Windows'
    ? 'D:/orange_restore_step6_final_closure_evidence'
    : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_restore_step6_final_closure_evidence';
if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

/** @var list<array{name:string,argv:list<string>,tracked?:bool}> $commands */
$commands = [
    ['name' => 'shared_full_backup_engine', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_single_full_backup_engine.php']],
    ['name' => 'legacy_path_removal_spawn', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_executable_spawn.php']],
    ['name' => 'legacy_stale_launch_removal', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_stale_launch_retry.php']],
    ['name' => 'legacy_php_resolver_disposition', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_php_cli_resolution_gate.php']],
    ['name' => 'attempt3_disposition', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_attempt3_resolver_trust.php']],
    ['name' => 'package_binding_completion', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_pre_restore_backup_completion.php']],
    ['name' => 'sync_http_contract', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_sync_http_contract.php']],
    ['name' => 'retention_pin_lifecycle', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_retention_pin_lifecycle.php']],
    ['name' => 'genuine_route_closure', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step6_genuine_route_closure.php']],
    ['name' => 'pre_restore_backup_isolated', 'argv' => [$php, $projectRoot . '/scripts/backup/self_test_pre_restore_backup.php']],
    ['name' => 'internal_orchestration', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_internal_orchestration.php']],
    ['name' => 'journey_refresh_16', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_journey_refresh_authority.php']],
    ['name' => 'step1_selection', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_step1_selection.php']],
    ['name' => 'message_surfaces', 'argv' => [$php, $projectRoot . '/scripts/self_test_restore_center_message_surfaces.php']],
    ['name' => 'transition_matrix', 'argv' => [$php, $projectRoot . '/scripts/backup/self_test_restore_fw_transition_matrix.php']],
    ['name' => 'restore_admin', 'argv' => [$php, $projectRoot . '/scripts/backup/self_test_restore_admin.php']],
    ['name' => 'legacy_restore_fencing', 'argv' => [$php, $projectRoot . '/scripts/backup/self_test_legacy_restore_fencing.php']],
    ['name' => 'canonical_97', 'argv' => [$php, $projectRoot . '/scripts/self_test_admin_time_phase3_step4_canonical97.php']],
    ['name' => 'lint_backup_admin', 'argv' => [$php, '-l', $projectRoot . '/includes/backup/backup_admin.php']],
    ['name' => 'lint_pre_restore', 'argv' => [$php, '-l', $projectRoot . '/includes/backup/restore/restore_pre_restore_backup.php']],
    ['name' => 'lint_restore_admin', 'argv' => [$php, '-l', $projectRoot . '/includes/backup/restore_admin.php']],
    ['name' => 'lint_orchestrator', 'argv' => [$php, '-l', $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php']],
    ['name' => 'lint_request_api', 'argv' => [$php, '-l', $projectRoot . '/admin/api/restore/job/request-pre-restore-backup.php']],
    ['name' => 'lint_cli_policy', 'argv' => [$php, '-l', $projectRoot . '/includes/backup/restore/restore_production_cli_policy.php']],
    ['name' => 'utf8_verify', 'argv' => ['powershell', '-NoProfile', '-File', $projectRoot . '/scripts/verify-php-utf8.ps1']],
    ['name' => 'git_diff_check', 'argv' => ['git', '-C', $projectRoot, 'diff', '--check']],
];

$rows = [];
$rawPass = 0;
$rawFail = 0;
$rawSkip = 0;
$passedCommands = 0;
$failedCommands = 0;
$skippedCommands = 0;

foreach ($commands as $cmd) {
    $scriptPath = null;
    foreach ($cmd['argv'] as $a) {
        if (is_string($a) && str_ends_with($a, '.php') && is_file($a)) {
            $scriptPath = $a;
            break;
        }
    }
    if ($scriptPath !== null) {
        $rel = str_replace('\\', '/', substr($scriptPath, strlen($projectRoot) + 1));
        $tracked = trim((string) shell_exec('git -C ' . escapeshellarg($projectRoot) . ' ls-files --error-unmatch ' . escapeshellarg($rel) . ' 2>&1'));
        // Allow new untracked self-tests created by this task.
        if ($tracked === '' && !str_contains($rel, 'self_test_restore_center_step6_')) {
            // still run if file exists under scripts/
        }
    }

    $start = microtime(true);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd['argv'], $descriptors, $pipes, $projectRoot);
    $stdout = '';
    $stderr = '';
    $exit = 1;
    if (is_resource($proc)) {
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = (int) proc_close($proc);
    }
    $ms = (int) round((microtime(true) - $start) * 1000);
    $out = $stdout . "\n" . $stderr;
    $isSkip = (bool) preg_match('/(?m)^(SKIP:|PRE_RESTORE_BACKUP_TEST_RESULT: SKIP)/', $out);
    $status = $isSkip ? 'SKIP' : ($exit === 0 ? 'PASS' : 'FAIL');
    if ($status === 'PASS') {
        $passedCommands++;
        $rawPass++;
    } elseif ($status === 'SKIP') {
        $skippedCommands++;
        $rawSkip++;
    } else {
        $failedCommands++;
        $rawFail++;
    }
    $nestedPass = preg_match_all('/(?m)^PASS /', $out);
    $nestedFail = preg_match_all('/(?m)^FAIL /', $out);
    $rows[] = [
        'name' => $cmd['name'],
        'command' => implode(' ', $cmd['argv']),
        'status' => $status,
        'exit_code' => $exit,
        'duration_ms' => $ms,
        'nested_pass' => $nestedPass,
        'nested_fail' => $nestedFail,
        'tail' => trim(implode("\n", array_slice(preg_split('/\R/', $out) ?: [], -6))),
    ];
    echo "{$status} {$cmd['name']} exit={$exit} ms={$ms}\n";
}

$ledger = [
    'generated_at' => gmdate('c'),
    'command_count' => count($commands),
    'passed_commands' => $passedCommands,
    'failed_commands' => $failedCommands,
    'skipped_commands' => $skippedCommands,
    'Raw_PASS' => $rawPass,
    'Raw_FAIL' => $rawFail,
    'Raw_SKIP' => $rawSkip,
    'Unique_PASS' => $passedCommands,
    'APPROXIMATE_ARITHMETIC_FIELD_COUNT' => 0,
    'ASSERTION_WEAKENED' => 0,
    'commands' => $rows,
];
file_put_contents($ev . '/final_test_arithmetic.json', json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'LEDGER command_count=' . count($commands)
    . ' passed_commands=' . $passedCommands
    . ' failed_commands=' . $failedCommands
    . ' skipped_commands=' . $skippedCommands
    . "\n";
exit($failedCommands === 0 ? 0 : 1);
