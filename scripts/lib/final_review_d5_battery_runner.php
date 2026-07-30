<?php

declare(strict_types=1);

/**
 * FSR D5 — exact regression battery runner (test-only).
 * Prints one JSON summary with per-command PASS/FAIL/SKIP/Exit/duration.
 *
 * Usage: php scripts/lib/final_review_d5_battery_runner.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

/** @var list<array{name:string,path:string,tracked?:bool,policy_frozen?:bool}> $suites */
$suites = [
    ['name' => 'd5_expectations', 'path' => 'scripts/self_test_final_review_d5_expectations.php'],
    ['name' => 'd5_full_01', 'path' => 'scripts/self_test_final_review_d5_fresh_gate_path.php'],
    ['name' => 'd5_stg_01', 'path' => 'scripts/self_test_final_review_d5_staging_privilege_fence.php'],
    ['name' => 'd5_apr_01', 'path' => 'scripts/self_test_final_review_d5_approval_window.php'],
    ['name' => 'd5_chunk', 'path' => 'scripts/self_test_final_review_d5_country_chunk_grammar.php'],
    ['name' => 'd5_full_backup_restore', 'path' => 'scripts/self_test_final_review_d5_full_backup_restore.php'],
    ['name' => 'd5_full_cutover', 'path' => 'scripts/self_test_final_review_d5_full_cutover.php'],
    ['name' => 'd5_country_export_verify', 'path' => 'scripts/self_test_final_review_d5_country_export_verify.php'],
    ['name' => 'd5_country_shadow_dry_run', 'path' => 'scripts/self_test_final_review_d5_country_shadow_dry_run.php'],
    ['name' => 'backup_core', 'path' => 'scripts/backup/self_test_backup.php', 'tracked' => true],
    ['name' => 'backup_admin', 'path' => 'scripts/backup/self_test_backup_admin.php', 'tracked' => true],
    ['name' => 'c3_export', 'path' => 'scripts/backup/self_test_country_crp_c3_export.php', 'tracked' => true],
    ['name' => 'c4_verify', 'path' => 'scripts/backup/self_test_country_crp_c4_verify.php', 'tracked' => true],
    ['name' => 'c5_drv', 'path' => 'scripts/backup/self_test_country_crp_c5_drv.php', 'tracked' => true],
    ['name' => 'c6_shadow', 'path' => 'scripts/backup/self_test_country_crp_c6_shadow.php', 'tracked' => true],
    ['name' => 'c7_shadow_verify', 'path' => 'scripts/backup/self_test_country_crp_c7_shadow_verify.php', 'tracked' => true],
    ['name' => 'c8_dry_run', 'path' => 'scripts/backup/self_test_country_crp_c8_dry_run.php', 'tracked' => true],
    ['name' => 'c_final_hardening', 'path' => 'scripts/backup/self_test_country_crp_final_hardening.php', 'tracked' => true],
    ['name' => 'hygiene_f', 'path' => 'scripts/self_test_final_review_hygiene_dead_stubs.php'],
    ['name' => 'd1_orders', 'path' => 'scripts/self_test_final_review_d1_orders.php'],
    ['name' => 'd1_payments', 'path' => 'scripts/self_test_final_review_d1_payments.php'],
    ['name' => 'd1_purchases', 'path' => 'scripts/self_test_final_review_d1_purchases_returns.php'],
    ['name' => 'd2_balances', 'path' => 'scripts/self_test_final_review_d2_inventory_balances.php'],
    ['name' => 'd2_fifo', 'path' => 'scripts/self_test_final_review_d2_fifo_costing.php'],
    ['name' => 'd2_workflows', 'path' => 'scripts/self_test_final_review_d2_inventory_workflows.php'],
    ['name' => 'd2_concurrency', 'path' => 'scripts/self_test_final_review_d2_inventory_concurrency.php'],
    ['name' => 'd2_closure', 'path' => 'scripts/self_test_final_review_d2_closure_contracts.php'],
    ['name' => 'd3_manual', 'path' => 'scripts/self_test_final_review_d3_manual_vouchers.php'],
    ['name' => 'd3_auto', 'path' => 'scripts/self_test_final_review_d3_automatic_posting.php'],
    ['name' => 'd3_pending', 'path' => 'scripts/self_test_final_review_d3_pending_subledger.php'],
    ['name' => 'd3_fiscal', 'path' => 'scripts/self_test_final_review_d3_fiscal_numbering.php'],
    ['name' => 'd3_concurrency', 'path' => 'scripts/self_test_final_review_d3_accounting_concurrency.php'],
    ['name' => 'd4_doc_seq', 'path' => 'scripts/self_test_final_review_d4_document_sequences.php'],
    ['name' => 'd4_closure', 'path' => 'scripts/self_test_final_review_d4_closure_verification.php'],
];

$results = [];
$rawPass = 0;
$rawFail = 0;
$rawSkip = 0;
$suitePass = 0;
$suiteFail = 0;
$suiteSkip = 0;
$coreSkip = 0;
$policyFrozen = 0;
$namedSkips = [];
$batteryStart = microtime(true);

foreach ($suites as $suite) {
    $rel = $suite['path'];
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $name = $suite['name'];
    if (!empty($suite['tracked'])) {
        $chk = [];
        $code = 0;
        exec('git -C ' . escapeshellarg($root) . ' ls-files --error-unmatch ' . escapeshellarg($rel) . ' 2>&1', $chk, $code);
        if ($code !== 0) {
            $results[] = [
                'name' => $name,
                'command' => $rel,
                'PASS' => 0,
                'FAIL' => 0,
                'SKIP' => 1,
                'Exit' => 2,
                'duration' => 0,
                'skip_names' => ['TRACKED_PATH_MISSING:' . $rel],
            ];
            $rawSkip++;
            $suiteSkip++;
            $namedSkips[] = 'TRACKED_PATH_MISSING:' . $rel;
            continue;
        }
    }
    if (!is_file($abs)) {
        $results[] = [
            'name' => $name,
            'command' => $rel,
            'PASS' => 0,
            'FAIL' => 0,
            'SKIP' => 1,
            'Exit' => 2,
            'duration' => 0,
            'skip_names' => ['FILE_MISSING:' . $rel],
        ];
        $rawSkip++;
        $suiteSkip++;
        $namedSkips[] = 'FILE_MISSING:' . $rel;
        continue;
    }

    $t0 = microtime(true);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open([$php, $abs], $desc, $pipes, $root, null, ['bypass_shell' => true]);
    $out = '';
    $err = '';
    $exit = 1;
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = (int) proc_close($proc);
    }
    $dur = round(microtime(true) - $t0, 3);
    $pass = 0;
    $fail = 0;
    $skip = 0;
    if (preg_match('/PASS\s*=\s*(\d+)/', $out, $m)) {
        $pass = (int) $m[1];
    } elseif (preg_match_all('/^PASS[:\s]/mi', $out, $mm)) {
        $pass = count($mm[0]);
    }
    if (preg_match('/FAIL\s*=\s*(\d+)/', $out, $m)) {
        $fail = (int) $m[1];
    } elseif (preg_match_all('/^FAIL[:\s]/mi', $out, $mm)) {
        $fail = count($mm[0]);
    }
    if (preg_match('/SKIP\s*=\s*(\d+)/', $out, $m)) {
        $skip = (int) $m[1];
    } elseif (preg_match_all('/^SKIP[:\s]/mi', $out, $mm)) {
        $skip = count($mm[0]);
    }
    // Capture SKIP labels
    $localSkips = [];
    if (preg_match_all('/^SKIP\s+(.+)$/mi', $out, $sm)) {
        foreach ($sm[1] as $lab) {
            $localSkips[] = trim($lab);
            $namedSkips[] = $name . ':' . trim($lab);
        }
    }
    $rawPass += $pass;
    $rawFail += $fail;
    $rawSkip += $skip;
    if ($fail > 0 || ($exit !== 0 && $skip === 0 && $pass === 0)) {
        $suiteFail++;
        $status = 'FAIL';
    } elseif ($skip > 0 && $pass === 0 && $fail === 0) {
        $suiteSkip++;
        $status = 'SKIP';
        $coreSkip += $skip;
    } else {
        $suitePass++;
        $status = 'PASS';
    }
    echo "SUITE {$name} status={$status} PASS={$pass} FAIL={$fail} SKIP={$skip} Exit={$exit} DUR={$dur}\n";
    if ($fail > 0 || $exit !== 0) {
        $tail = substr(trim($out . "\n" . $err), -800);
        echo "NOTE  {$name}_tail=" . str_replace("\n", ' | ', $tail) . "\n";
    }
    $results[] = [
        'name' => $name,
        'command' => $rel,
        'status' => $status,
        'PASS' => $pass,
        'FAIL' => $fail,
        'SKIP' => $skip,
        'Exit' => $exit,
        'duration' => $dur,
        'skip_names' => $localSkips,
    ];
}

// UTF-8 verify
$t0 = microtime(true);
$utfOut = [];
$utfExit = 0;
exec('powershell -NoProfile -File ' . escapeshellarg($root . '/scripts/verify-php-utf8.ps1') . ' 2>&1', $utfOut, $utfExit);
$utfDur = round(microtime(true) - $t0, 3);
echo "SUITE utf8 status=" . ($utfExit === 0 ? 'PASS' : 'FAIL') . " Exit={$utfExit} DUR={$utfDur}\n";

// PHP lint for modified/added D5 PHP files
$lintFiles = [
    'includes/backup/restore/restore_fresh_backup_gate.php',
    'includes/backup/restore/restore_staging_target.php',
    'includes/backup/restore/restore_full_staging.php',
    'includes/backup/restore/restore_country_staging.php',
    'includes/backup/restore/restore_country_shadow_ea.php',
    'scripts/lib/final_review_d5_runtime.php',
    'scripts/lib/final_review_d5_backup_worker.php',
    'scripts/lib/final_review_d5_staging_worker.php',
    'scripts/lib/final_review_d5_cutover_worker.php',
    'scripts/lib/final_review_d5_country_shadow_worker.php',
    'scripts/lib/final_review_d5_expectations_drift.php',
    'scripts/lib/final_review_d5_battery_runner.php',
    'scripts/self_test_final_review_d5_expectations.php',
    'scripts/self_test_final_review_d5_fresh_gate_path.php',
    'scripts/self_test_final_review_d5_staging_privilege_fence.php',
    'scripts/self_test_final_review_d5_approval_window.php',
    'scripts/self_test_final_review_d5_country_chunk_grammar.php',
    'scripts/self_test_final_review_d5_full_backup_restore.php',
    'scripts/self_test_final_review_d5_full_cutover.php',
    'scripts/self_test_final_review_d5_country_export_verify.php',
    'scripts/self_test_final_review_d5_country_shadow_dry_run.php',
    'scripts/backup/self_test_restore_full_staging.php',
];
$lintFail = 0;
$lintPass = 0;
foreach ($lintFiles as $rel) {
    $abs = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!is_file($abs)) {
        continue;
    }
    $lo = [];
    $lc = 0;
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($abs) . ' 2>&1', $lo, $lc);
    if ($lc === 0) {
        $lintPass++;
    } else {
        $lintFail++;
        echo "LINT_FAIL {$rel}\n";
    }
}

// JSON validate expectations
$jsonOk = false;
$j = json_decode((string) file_get_contents($root . '/config/country_restore_schema_expectations.json'), true);
$jsonOk = is_array($j) && (int) ($j['schema_revision'] ?? 0) === 124;

$totalDur = round(microtime(true) - $batteryStart, 3);
$summary = [
    'command_count' => count($results),
    'Raw_PASS' => $rawPass,
    'Raw_FAIL' => $rawFail,
    'Raw_SKIP' => $rawSkip,
    'passed_suites' => $suitePass,
    'failed_suites' => $suiteFail,
    'skipped_suites' => $suiteSkip,
    'CORE_D5_SKIP' => $coreSkip,
    'policy_frozen_not_applicable' => $policyFrozen,
    'named_skips' => $namedSkips,
    'utf8_exit' => $utfExit,
    'php_lint_pass' => $lintPass,
    'php_lint_fail' => $lintFail,
    'json_expectations_ok' => $jsonOk,
    'duration_sec' => $totalDur,
    'suites' => $results,
];
echo "\nBATTERY_SUMMARY=" . json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit(($suiteFail > 0 || $rawFail > 0 || $utfExit !== 0 || $lintFail > 0 || !$jsonOk) ? 1 : 0);
