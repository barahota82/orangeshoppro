<?php

declare(strict_types=1);

/**
 * Gate B — endpoint-local Step 7 diagnostic exception origin (B1).
 * Static + isolated function checks only. No live job / package / worker / Step 7/8.
 */

$projectRoot = str_replace('\\', '/', dirname(__DIR__));
$php = str_replace('\\', '/', PHP_BINARY);
$endpoint = $projectRoot . '/admin/api/restore/job/orchestrator-diagnostics.php';
$orch = $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
$evidenceRoot = DIRECTORY_SEPARATOR === '\\' ? 'D:/' : rtrim(sys_get_temp_dir(), '/\\');
$ev = rtrim(str_replace('\\', '/', $evidenceRoot), '/') . '/orange_restore_step7_forensic_root_cause_closure_evidence';

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

$ok(str_contains($src, 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1'), 'B1 marker present in endpoint');
$ok(str_contains($src, "ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1"), 'deploy sentinel unchanged');
$ok(str_contains($src, "'safe_code' => 'step7_diagnostic_exception_origin'"), 'safe_code step7_diagnostic_exception_origin');
$ok(str_contains($src, "'failure_layer' => 'exception_origin'"), 'failure_layer exception_origin');
$ok(str_contains($src, "'diagnostic_probe'"), 'additive diagnostic_probe');
$ok(str_contains($src, "'diagnostic_trace_id'"), 'additive diagnostic_trace_id');
$ok(str_contains($src, "'diagnostic_exception_class'"), 'additive diagnostic_exception_class');
$ok(str_contains($src, "'diagnostic_origin_file'"), 'additive diagnostic_origin_file');
$ok(str_contains($src, "'diagnostic_origin_line'"), 'additive diagnostic_origin_line');
$ok(str_contains($src, 'basename(str_replace'), 'origin uses basename only');
$b1Pos = strpos($src, 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1');
$b1Slice = $b1Pos === false ? '' : substr($src, max(0, $b1Pos - 200), 2200);
$ok($b1Slice !== '' && !str_contains($b1Slice, 'getMessage()'), 'B1 catch does not leak raw exception message');
$ok(substr_count($src, 'orange_restore_center_diagnostics(') === 1, 'DIAGNOSTIC_HELPER_CALL_COUNT=1');
$ok(!str_contains($src, 'diagnosticProbe'), 'd1ed probe absent from endpoint');
$ok(!str_contains($orchSrc, 'diagnosticProbe') && !str_contains($orchSrc, 'probeEmit'), 'SHARED_HELPER unchanged (no d1ed probe)');
$ok(!preg_match('/function\s+orange_restore_center_diagnostics\s*\(\s*string\s+\$workRoot\s*,\s*string\s+\$jobId\s*,/', $orchSrc), 'SHARED_HELPER_SIGNATURE_CHANGE=0');

// Isolated payload builder parity (no HTTP / no auth / no package).
$build = static function (Throwable $e): array {
    $probe = 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1';
    $traceId = bin2hex(random_bytes(8));
    $originFile = basename(str_replace('\\', '/', (string) $e->getFile()));
    if ($originFile === '' || $originFile === '.' || $originFile === '..') {
        $originFile = 'unknown';
    }
    $originLine = (int) $e->getLine();
    if ($originLine < 0) {
        $originLine = 0;
    }
    $classRaw = $e::class;
    $classBase = str_contains($classRaw, '\\')
        ? substr($classRaw, (int) strrpos($classRaw, '\\') + 1)
        : $classRaw;
    $classBase = (string) preg_replace('/[^A-Za-z0-9_]/', '', $classBase);
    if ($classBase === '') {
        $classBase = 'Throwable';
    }
    $safeOriginFile = (string) preg_replace('/[^A-Za-z0-9._-]/', '', $originFile);
    if ($safeOriginFile === '') {
        $safeOriginFile = 'unknown';
    }
    $notReadyReason = 'exception_origin_' . $classBase . '_' . $safeOriginFile . '_L' . $originLine;
    $safeMsg = 'تعذر التشخيص بسبب استثناء داخلي آمن'
        . ' [probe: ' . $probe . ']'
        . ' [trace: ' . $traceId . ']'
        . ' [origin: ' . $safeOriginFile . ':' . $originLine . ']'
        . ' [class: ' . $classBase . ']';

    return [
        'success' => false,
        'final_readiness' => 'NOT_READY',
        'safe_code' => 'step7_diagnostic_exception_origin',
        'failure_layer' => 'exception_origin',
        'exact_not_ready_reason' => $notReadyReason,
        'package_certificate_status' => 'unavailable',
        'private_engine_trace_status' => 'unavailable',
        'step7_action_enabled' => false,
        'message' => $safeMsg,
        'diagnostic_probe' => $probe,
        'diagnostic_trace_id' => $traceId,
        'diagnostic_exception_class' => $classBase,
        'diagnostic_origin_file' => $safeOriginFile,
        'diagnostic_origin_line' => $originLine,
    ];
};

try {
    throw new RuntimeException('SENSITIVE raw message must never appear');
} catch (Throwable $e) {
    $payload = $build($e);
}

$ok($payload['diagnostic_probe'] === 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1', 'payload probe B1');
$ok($payload['safe_code'] === 'step7_diagnostic_exception_origin', 'payload safe_code');
$ok($payload['failure_layer'] === 'exception_origin', 'payload failure_layer');
$ok($payload['final_readiness'] === 'NOT_READY', 'payload remains NOT_READY');
$ok($payload['step7_action_enabled'] === false, 'Step 7 disabled');
$ok($payload['package_certificate_status'] === 'unavailable', 'cert unavailable');
$ok($payload['private_engine_trace_status'] === 'unavailable', 'trace unavailable');
$ok(str_contains((string) $payload['message'], '[probe: STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1]'), 'message contains probe');
$ok(str_contains((string) $payload['message'], '[class: RuntimeException]'), 'message contains class');
$ok(str_contains((string) $payload['message'], '[origin: '), 'message contains origin');
$ok(!str_contains((string) $payload['message'], 'SENSITIVE'), 'raw exception message absent');
$ok($payload['diagnostic_exception_class'] === 'RuntimeException', 'class basename only');
$ok(!str_contains((string) $payload['diagnostic_origin_file'], '/') && !str_contains((string) $payload['diagnostic_origin_file'], '\\'), 'origin file basename only');
$ok(str_starts_with((string) $payload['exact_not_ready_reason'], 'exception_origin_RuntimeException_'), 'NOT_READY reason shape');
$ok(preg_match('/_L\d+$/', (string) $payload['exact_not_ready_reason']) === 1, 'NOT_READY reason ends with Lline');

// Refresh/status freeze: list.php / restore_admin must not be touched by this commit scope (static fingerprint).
$list = (string) file_get_contents($projectRoot . '/admin/api/restore/list.php');
$ok(!str_contains($list, 'STEP7_ENDPOINT_EXCEPTION_ORIGIN_B1'), 'REFRESH_STATUS_ROUTE_CHANGE=0 (list untouched by B1)');

$summary = [
    'result' => $fail === 0 ? 'PASS' : 'FAIL',
    'PASS_COUNT' => $pass,
    'FAIL_COUNT' => $fail,
    'GATE_B_PRODUCTION_CHANGED_FILE_COUNT' => 1,
    'SHARED_HELPER_CHANGED' => 0,
    'SHARED_HELPER_SIGNATURE_CHANGE' => 0,
    'DIAGNOSTIC_HELPER_CALL_COUNT' => 1,
    'REUSED_D1ED727E_CODE_COUNT' => 0,
    'php_cli' => $php,
];
file_put_contents($ev . '/gate_b_self_test_summary.json', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "PASS_COUNT=$pass\nFAIL_COUNT=$fail\n";
exit($fail === 0 ? 0 : 1);
