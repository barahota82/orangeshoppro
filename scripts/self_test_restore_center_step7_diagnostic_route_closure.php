<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — diagnostic route + bounded package certificate closure.
 * Disposable fixtures only. No live job mutation.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php';

$pass = 0;
$fail = 0;

function s7diag_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7diag_rm_rf(string $dir): void
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

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7diag_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
$pkgRoot = $tmp . DIRECTORY_SEPARATOR . 'pkg';
mkdir($workRoot, 0777, true);
mkdir($pkgRoot, 0777, true);

try {
    s7diag_ok(
        defined('ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT'),
        'resource limit constant defined'
    );

    // 1) Large dump under tight memory must NOT fatal; returns resource limit.
    $gzLarge = $tmp . DIRECTORY_SEPARATOR . 'large.sql.gz';
    $h = gzopen($gzLarge, 'wb6');
    gzwrite($h, "SET NAMES utf8mb4;\nUSE `orange_db`;\n");
    $line = "INSERT INTO `t` (`id`,`c`) VALUES (1,\"xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\");\n";
    $written = 0;
    while ($written < (28 * 1024 * 1024)) {
        gzwrite($h, $line);
        $written += strlen($line);
    }
    gzclose($h);

    $prevMem = ini_get('memory_limit');
    ini_set('memory_limit', '32M');
    $t0 = microtime(true);
    $fatal = false;
    try {
        $certLarge = orange_restore_sql_compat_scan_package(
            $gzLarge,
            ['dump_file' => 'large.sql.gz', 'source_database' => 'orange_db', 'export_backend' => 'php_pdo'],
            'orange_db',
            'yes',
            'unknown'
        );
    } catch (Throwable $e) {
        $fatal = true;
        $certLarge = ['exact_not_ready_reason' => 'EXCEPTION:' . $e->getMessage()];
    }
    ini_set('memory_limit', (string) $prevMem);
    s7diag_ok(!$fatal, 'large dump scan does not throw/fatal');
    $largeReason = (string) ($certLarge['exact_not_ready_reason'] ?? '');
    $largeClass = (string) ($certLarge['final_compatibility_classification'] ?? '');
    $largeOk = !empty($certLarge['ok']) || str_starts_with($largeClass, 'SQL_PACKAGE_COMPATIBLE');
    $largeLimit = !empty($certLarge['resource_limit_hit'])
        || $largeReason === ORANGE_RESTORE_STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT;
    s7diag_ok(
        $largeOk || $largeLimit,
        'large dump yields resource-limit OR completes via streaming without OOM'
    );
    s7diag_ok(
        (string) ($certLarge['scan_mode'] ?? '') === 'bounded_streaming',
        'scan_mode=bounded_streaming'
    );
    echo 'large_elapsed=' . round(microtime(true) - $t0, 3)
        . ' reason=' . (string) ($certLarge['exact_not_ready_reason'] ?? '')
        . ' peak_mb=' . round(memory_get_peak_usage(true) / 1048576, 1) . "\n";

    // 2) Small compatible package certificate.
    $snap = $pkgRoot . DIRECTORY_SEPARATOR . '2026-08-14_diagcert';
    mkdir($snap, 0777, true);
    $dumpRel = 'dump.sql.gz';
    $gz = $snap . DIRECTORY_SEPARATOR . $dumpRel;
    $hg = gzopen($gz, 'wb9');
    gzwrite($hg, "SET NAMES utf8mb4;\nCREATE TABLE `x` (`id` INT);\nINSERT INTO `x` VALUES (1);\n");
    gzclose($hg);
    $manifest = [
        'package_version' => '1',
        'export_backend' => 'php_pdo',
        'dump_file' => $dumpRel,
        'source_database' => 'orange_db',
    ];
    file_put_contents($snap . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE));
    $certOk = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'unknown');
    s7diag_ok(!empty($certOk['ok']) || !empty($certOk['compatible']), 'small package compatible/ok');
    s7diag_ok(
        (string) ($certOk['final_compatibility_classification'] ?? '') === ORANGE_RESTORE_SQL_PKG_COMPATIBLE_UNCHANGED
            || str_starts_with((string) ($certOk['final_compatibility_classification'] ?? ''), 'SQL_PACKAGE_COMPATIBLE'),
        'small package classification compatible'
    );
    s7diag_ok(empty($certOk['resource_limit_hit']), 'small package no resource limit');

    // 3) Diagnostics read-only on disposable job (no soft-bind writes).
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-08-14_120000',
        'package_type' => 'full_disaster',
        'created_by' => 's7diag',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED;
    $job['source_package_id'] = '2026-08-14_120000';
    $job['package_id'] = '2026-08-14_120000';
    orange_restore_fw_write($workRoot, $job);

    $metaPath = $workRoot . DIRECTORY_SEPARATOR . 'framework' . DIRECTORY_SEPARATOR . $jobId
        . DIRECTORY_SEPARATOR . 'shadow_meta.json';
    @mkdir(dirname($metaPath), 0777, true);
    $metaBefore = [
        'shadow_db' => 'orange_shadow_diag',
        'shadow_db_identity_hash' => '',
        'attempt_id' => 'diag-attempt',
    ];
    file_put_contents($metaPath, json_encode($metaBefore, JSON_UNESCAPED_UNICODE));
    $hashBefore = hash_file('sha256', $metaPath) ?: '';

    $diag = orange_restore_center_diagnostics($workRoot, $jobId);
    $hashAfter = is_file($metaPath) ? (hash_file('sha256', $metaPath) ?: '') : '';
    s7diag_ok($hashBefore === $hashAfter, 'diagnostic does not mutate shadow_meta.json');
    s7diag_ok(isset($diag['private_engine_live_trace']), 'diagnostics includes private_engine_live_trace');
    s7diag_ok(isset($diag['job_id']) && $diag['job_id'] === $jobId, 'diagnostics job_id');

    // 4) Frontend helpers exist in restore_center.php
    $page = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    s7diag_ok(str_contains($page, 'rcDiagnosticInFlight'), 'frontend diagnostic in-flight lock');
    s7diag_ok(str_contains($page, 'formatOrchestratorDiagnosticFailure'), 'frontend structured failure renderer');
    s7diag_ok(str_contains($page, 'توافق ملف SQL للحزمة'), 'frontend package certificate section');
    s7diag_ok(str_contains($page, 'STEP7_DIAGNOSTIC_UNKNOWN_SAFE_FAILURE'), 'frontend never generic-only mapping');

    // 5) API route structured fields
    $api = (string) file_get_contents($projectRoot . '/admin/api/restore/job/orchestrator-diagnostics.php');
    s7diag_ok(str_contains($api, 'orange_restore_diagnostic_api_structured_failure'), 'API structured failure mapper');
    s7diag_ok(str_contains($api, 'package_certificate_status'), 'API package_certificate_status');
    s7diag_ok(str_contains($api, 'STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT')
        || str_contains($api, 'resource_limit'), 'API resource limit handling');

    // 6) Unbounded full-string append removed
    $engine = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php');
    s7diag_ok(!str_contains($engine, '$sql .= $chunk'), 'unbounded $sql .= $chunk removed');
    s7diag_ok(str_contains($engine, 'bounded_streaming'), 'bounded_streaming marker present');

    echo "RESULT pass={$pass} fail={$fail}\n";
    exit($fail > 0 ? 1 : 0);
} finally {
    s7diag_rm_rf($tmp);
}
