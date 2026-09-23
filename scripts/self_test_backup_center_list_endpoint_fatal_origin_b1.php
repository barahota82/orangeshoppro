<?php

declare(strict_types=1);

/**
 * B1 — Backup list.php endpoint-local fatal/timeout origin capture.
 * Static endpoint checks + contained child-process shutdown-order fixture.
 * No Backup execution / Verify / DRV / Restore / live mutation.
 */

$projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$projectRoot = str_replace('\\', '/', $projectRoot);
$php = PHP_BINARY;
$endpoint = $projectRoot . '/admin/api/backup/list.php';
$fixture = $projectRoot . '/scripts/self_test_backup_center_list_endpoint_fatal_origin_b1_shutdown_fixture.php';
$backupAdmin = $projectRoot . '/includes/backup/backup_admin.php';
$bootstrap = $projectRoot . '/admin/api/backup/_bootstrap.php';
$config = $projectRoot . '/config.php';
$ui = $projectRoot . '/admin/pages/backup_center.php';
$ev = DIRECTORY_SEPARATOR === '\\'
    ? 'D:/orange_backup_list_endpoint_fatal_origin_b1_evidence'
    : sys_get_temp_dir() . '/orange_backup_list_endpoint_fatal_origin_b1_evidence';

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
$adminSrc = (string) file_get_contents($backupAdmin);
$bootSrc = (string) file_get_contents($bootstrap);
$configSrc = (string) file_get_contents($config);
$uiSrc = (string) file_get_contents($ui);

// A — marker + register-before-bootstrap
$ok(str_contains($src, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'A1 B1 marker present');
$regPos = strpos($src, "register_shutdown_function('orange_backup_list_b1_shutdown')");
$bootPos = strpos($src, "require_once __DIR__ . '/_bootstrap.php'");
$ok($regPos !== false && $bootPos !== false && $regPos < $bootPos, 'A2 register shutdown BEFORE bootstrap');
$ok(str_contains($src, "'code' => 'backup_list_endpoint_fatal_origin'"), 'A3 safe code');
$ok(str_contains($src, 'diagnostic_failure_stage'), 'A4 diagnostic_failure_stage');
$ok(str_contains($src, 'diagnostic_shutdown_category'), 'A5 diagnostic_shutdown_category');
$ok(str_contains($src, 'diagnostic_php_error_type'), 'A6 diagnostic_php_error_type');
$ok(str_contains($src, 'diagnostic_elapsed_ms'), 'A7 diagnostic_elapsed_ms');
$ok(str_contains($src, "\$GLOBALS['orange_backup_list_b1_authorized'] = true"), 'A8 authorized after permission');
$ok(str_contains($src, "\$GLOBALS['orange_backup_list_b1_completed'] = true"), 'A9 completed before intentional emit');

// Stages present
foreach ([
    'route_entry',
    'bootstrap_complete',
    'authentication_complete',
    'permission_complete',
    'pdo_schema_context',
    'backup_view_context',
    'list_full_snapshots',
    'list_country_packages',
    'package_inventory_counts_first',
    'package_inventory_counts_second',
    'collect_storage_totals',
    'collect_overview',
    'list_logs',
    'response_aggregation',
    'response_serialization',
    'response_complete',
] as $st) {
    $ok(str_contains($src, $st), 'A10 stage ' . $st);
}

// B — freezes
$ok(!str_contains($adminSrc, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'B1 backup_admin unchanged by B1 marker');
$ok(!str_contains($bootSrc, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'B2 _bootstrap unchanged by B1 marker');
$ok(!str_contains($configSrc, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'B3 config untouched');
$ok(!str_contains($uiSrc, 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'B4 UI frozen');
$ok(substr_count($src, 'orange_backup_admin_list_full_snapshots(') === 1, 'B5 list_full_snapshots once');
$ok(substr_count($src, 'orange_backup_admin_collect_storage_totals(') === 1, 'B6 collect_storage_totals once');
$ok(substr_count($src, 'orange_backup_admin_dir_size_bytes(') === 0, 'B7 list.php does not call dir_size directly');

// C — security slice
$b1Start = strpos($src, 'function orange_backup_list_b1_shutdown');
$b1Slice = $b1Start === false ? '' : substr($src, $b1Start, 5500);
$ok($b1Slice !== '' && str_contains($b1Slice, 'basename('), 'C1 origin basename only');
$ok(!preg_match('/\$safeMsg\s*=\s*.*\$rawMsg/', $b1Slice), 'C2 raw message not embedded in safeMsg assignment');
$ok(str_contains($b1Slice, 'php_timeout') && str_contains($b1Slice, 'memory_exhausted'), 'C3 timeout/memory categories');
$ok(str_contains($b1Slice, 'ORANGE_JSON_RESPONSE_EMITTED') && str_contains($b1Slice, 'ORANGE_ERROR_BOUNDARY_CLIENT_SENT'), 'C4 suppress later handlers');

// D — child-process fixture
$run = static function (string $mode) use ($php, $fixture): array {
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($fixture) . ' ' . escapeshellarg($mode);
    $orderFile = sys_get_temp_dir() . '/orange_backup_list_b1_shutdown_order.txt';
    if (is_file($orderFile)) {
        @unlink($orderFile);
    }
    $out = [];
    $code = 0;
    exec($cmd . ' 2>&1', $out, $code);
    $text = implode("\n", $out);
    $order = is_file($orderFile) ? trim((string) file_get_contents($orderFile)) : '';

    return ['code' => $code, 'out' => $text, 'order' => $order];
};

$a = $run('authorized_fatal');
$ok($a['code'] === 0, 'D1 authorized_fatal exit 0');
$ok(str_contains($a['order'], 'local_first') && !str_contains($a['order'], 'GLOBAL_RAN'), 'D2 local runs first / global suppressed');
$aj = json_decode($a['out'], true);
$ok(is_array($aj) && ($aj['diagnostic_probe'] ?? '') === 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1', 'D3 B1 probe in JSON');
$ok(is_array($aj) && ($aj['code'] ?? '') === 'backup_list_endpoint_fatal_origin', 'D4 safe code');
$ok(is_array($aj) && ($aj['diagnostic_failure_stage'] ?? '') === 'collect_storage_totals', 'D5 stage preserved');
$ok(!str_contains($a['out'], 'Simulated authorized fatal'), 'D6 raw fatal text absent');

$p = $run('pre_auth_fatal');
$ok(str_contains($p['order'], 'GLOBAL_RAN') || str_contains($p['out'], 'server_error'), 'D7 pre-auth falls to generic');
$ok(!str_contains($p['out'], 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'D8 pre-auth no B1 probe');

$c = $run('completed_ok');
$ok($c['code'] === 0 && str_contains($c['out'], '"success":true'), 'D9 completed_ok success');
$ok(str_contains($c['order'], 'inert'), 'D10 completed inert');

$ob = $run('authorized_fatal_with_ob');
$ok(!str_contains($ob['out'], 'PARTIAL_LEAK'), 'D11 OB cleaned');
$ok(str_contains($ob['out'], 'BACKUP_LIST_ENDPOINT_FATAL_ORIGIN_B1'), 'D12 OB fatal still B1');

$t = $run('classify_timeout');
$tj = json_decode($t['out'], true);
$ok(is_array($tj) && ($tj['diagnostic_shutdown_category'] ?? '') === 'php_timeout', 'D13 timeout category');

$m = $run('classify_memory');
$mj = json_decode($m['out'], true);
$ok(is_array($mj) && ($mj['diagnostic_shutdown_category'] ?? '') === 'memory_exhausted', 'D14 memory category');

// E — no Backup mutation markers in endpoint
$ok(!str_contains($src, 'orange_backup_admin_run_full'), 'E1 no Full run');
$ok(!str_contains($src, 'orange_backup_admin_run_country'), 'E2 no Country run');

file_put_contents(
    $ev . '/b1_self_test_summary.json',
    json_encode(['pass' => $pass, 'fail' => $fail, 'at' => gmdate('c')], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
);

echo "SUMMARY pass={$pass} fail={$fail}\n";
exit($fail > 0 ? 1 : 0);
