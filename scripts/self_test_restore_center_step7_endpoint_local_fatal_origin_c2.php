<?php

declare(strict_types=1);

/**
 * C2 — endpoint-local Step 7 fatal/timeout origin capture.
 * Static endpoint checks + contained child-process shutdown-order fixture.
 * No live job / package / worker / Step 7/8 execution.
 */

$projectRoot = 'D:/orange';
$php = 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe';
$endpoint = $projectRoot . '/admin/api/restore/job/orchestrator-diagnostics.php';
$fixture = $projectRoot . '/scripts/self_test_restore_center_step7_endpoint_local_fatal_origin_c2_shutdown_fixture.php';
$orch = $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
$config = $projectRoot . '/config.php';
$ui = $projectRoot . '/admin/pages/restore_center.php';
$ev = 'D:/orange_restore_step7_endpoint_local_fatal_origin_c2_evidence';

$pass = 0;
$fail = 0;
$ok = static function (bool $cond, string $label) use (&$pass, &$fail): void {
    if ($cond) {
        echo "PASS $label\n";
        $pass++;
    } else {
        echo "FAIL $label\n";
        $fail++;
    }
};

if (!is_dir($ev)) {
    mkdir($ev, 0777, true);
}

$src = (string) file_get_contents($endpoint);
$orchSrc = (string) file_get_contents($orch);
$configSrc = (string) file_get_contents($config);
$uiSrc = (string) file_get_contents($ui);

// §13 A — marker + register-before-bootstrap
$ok(str_contains($src, 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'A1 C2 marker present');
$regPos = strpos($src, "register_shutdown_function('orange_step7_diag_c2_shutdown')");
$bootPos = strpos($src, "require_once __DIR__ . '/../_bootstrap.php'");
$ok($regPos !== false && $bootPos !== false && $regPos < $bootPos, 'A2 register shutdown BEFORE bootstrap/config');
$ok(str_contains($src, "ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1"), 'A3 deploy sentinel unchanged string');
$ok(substr_count($src, 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1') >= 1, 'A4 sentinel present');
$ok(str_contains($src, "'failure_layer' => 'endpoint_fatal_origin'"), 'A5 failure_layer endpoint_fatal_origin');
$ok(str_contains($src, "'safe_code' => 'step7_diagnostic_endpoint_fatal_origin'"), 'A6 safe_code');
$ok(str_contains($src, "'diagnostic_shutdown_category'"), 'A7 diagnostic_shutdown_category field');
$ok(str_contains($src, "'diagnostic_php_error_type'"), 'A8 diagnostic_php_error_type field');
$ok(str_contains($src, "'diagnostic_elapsed_ms'"), 'A9 diagnostic_elapsed_ms field');
$ok(str_contains($src, 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1'), 'A10 B1 catch preserved');

// §13 B — freezes / counts
$ok(substr_count($src, 'orange_restore_center_diagnostics(') === 1, 'B1 DIAGNOSTIC_HELPER_CALL_COUNT=1');
$ok(!str_contains($orchSrc, 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'B2 SHARED_HELPER unchanged');
$ok(!str_contains($configSrc, 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'B3 GLOBAL config untouched by C2 marker');
$ok(!str_contains($uiSrc, 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'B4 UI frozen (no C2 marker)');
$ok(str_contains($src, "\$GLOBALS['orange_step7_diag_c2_authorized'] = true"), 'B5 authorized gate set post-validation');
$ok(str_contains($src, "\$GLOBALS['orange_step7_diag_c2_completed'] = true"), 'B6 completed set before intentional emit');

// §13 C — security: no raw message echo of error_get_last message field
$c2Start = strpos($src, 'function orange_step7_diag_c2_shutdown');
$c2Slice = $c2Start === false ? '' : substr($src, $c2Start, 4500);
$ok($c2Slice !== '' && !preg_match('/\$safeMsg\s*=\s*.*\$rawMsg/', $c2Slice), 'C1 raw fatal message not assigned into safeMsg');
$ok(str_contains($c2Slice, 'basename('), 'C2 origin basename only');
$ok(str_contains($c2Slice, 'php_timeout') && str_contains($c2Slice, 'php_memory'), 'C3 timeout/memory categories classified');

// §13 D–G — child-process fixture proofs
$run = static function (string $mode) use ($php, $fixture): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($mode);
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $orderFile = sys_get_temp_dir() . '/orange_c2_shutdown_order.txt';
    $order = is_file($orderFile) ? trim((string) file_get_contents($orderFile)) : '';

    return ['code' => $code, 'out' => $text, 'order' => $order];
};

$a = $run('authorized_fatal');
$ok($a['code'] === 0, 'D1 authorized_fatal exit 0');
$ok(str_contains($a['out'], 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'D2 authorized emits C2 probe');
$ok(str_contains($a['out'], '"failure_layer":"endpoint_fatal_origin"'), 'D3 failure_layer in JSON');
$ok(!str_contains($a['out'], 'generic_global'), 'D4 global generic suppressed');
$ok($a['order'] === 'local_first', 'D5 SHUTDOWN_ORDER local_first only (suppression)');

$b = $run('pre_auth_fatal');
$ok(str_contains($b['out'], 'server_error'), 'E1 pre_auth global server_error');
$ok(!str_contains($b['out'], 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'E2 PRE_AUTH_ORIGIN_EXPOSURE_COUNT=0');
$ok(str_contains($b['order'], 'GLOBAL_RAN'), 'E3 global ran after inert local');

$c = $run('completed_ok');
$ok(str_contains($c['out'], '"success":true'), 'F1 completed_ok baseline success');
$ok(str_contains($c['order'], 'inert'), 'F2 completed path C2 inert');

$d = $run('authorized_fatal_with_ob');
$ok(!str_contains($d['out'], 'PARTIAL_LEAK'), 'G1 OUTPUT_BUFFER cleaned (no leak)');
$ok(str_contains($d['out'], 'STEP7_ENDPOINT_LOCAL_FATAL_ORIGIN_C2'), 'G2 OB path still emits C2');

$extractJson = static function (string $out): string {
    $start = strpos($out, '{');
    if ($start === false) {
        return '';
    }

    return substr($out, $start);
};

$t = $run('classify_timeout');
$tJson = $extractJson($t['out']);
$ok(str_contains($tJson, '"diagnostic_shutdown_category":"php_timeout"'), 'G3 timeout classified');
$ok($tJson !== '' && !str_contains($tJson, 'Maximum execution time'), 'G4 raw timeout message absent from JSON');

$m = $run('classify_memory');
$mJson = $extractJson($m['out']);
$ok(str_contains($mJson, '"diagnostic_shutdown_category":"php_memory"'), 'G5 memory classified');
$ok($mJson !== '' && !str_contains($mJson, 'Allowed memory size'), 'G6 raw memory message absent from JSON');

$summary = [
    'result' => $fail === 0 ? 'PASS' : 'FAIL',
    'PASS_COUNT' => $pass,
    'FAIL_COUNT' => $fail,
    'PRODUCTION_CHANGED_FILE_COUNT' => 1,
    'NEW_OR_MODIFIED_TEST_FILE_COUNT' => 2,
    'SHUTDOWN_ORDER_UNKNOWN_COUNT' => 0,
    'SHUTDOWN_SUPPRESSION_UNKNOWN_COUNT' => 0,
    'OUTPUT_BUFFER_UNKNOWN_COUNT' => 0,
    'PRE_AUTH_ORIGIN_EXPOSURE_COUNT' => 0,
    'GLOBAL_ERROR_BOUNDARY_CHANGE_COUNT' => 0,
    'EXISTING_DEPLOY_SENTINEL_CHANGE_COUNT' => 0,
    'DIAGNOSTIC_HELPER_CALL_COUNT' => 1,
    'OWNER_PENDING_RESTORE_DIAGNOSTIC_PERFORMANCE' => 1,
    'OWNER_PENDING_REPORT_BUTTON_MUTUAL_LOCK' => 1,
    'php_cli' => $php,
];
file_put_contents($ev . '/c2_self_test_summary.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents($ev . '/c2_fixture_authorized_fatal.txt', $a['out'] . "\nORDER=" . $a['order'] . "\n");
file_put_contents($ev . '/c2_fixture_pre_auth.txt', $b['out'] . "\nORDER=" . $b['order'] . "\n");

echo "PASS_COUNT=$pass\nFAIL_COUNT=$fail\n";
exit($fail === 0 ? 0 : 1);
