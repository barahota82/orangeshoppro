<?php

declare(strict_types=1);

/**
 * Restore Center Step 7 — shared-helper optional diagnostic probe (A2).
 * Instrumentation-only contract: probe=null ≡ legacy path; worker never passes probe.
 * Disposable fixtures only. No live job / worker / Step-7 execution.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/includes/backup/restore/restore_job_framework.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';

$pass = 0;
$fail = 0;

function s7probe_ok(bool $c, string $l): void
{
    global $pass, $fail;
    echo ($c ? 'PASS ' : 'FAIL ') . $l . "\n";
    $c ? $pass++ : $fail++;
}

function s7probe_rm_rf(string $dir): void
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

/**
 * Normalize diagnostics for equivalence (drop volatile timing-only surfaces).
 *
 * @param array<string, mixed> $diag
 * @return array<string, mixed>
 */
function s7probe_normalize(array $diag): array
{
    unset($diag['log_tails']);
    if (isset($diag['private_engine_live_trace']) && is_array($diag['private_engine_live_trace'])) {
        // Trace may include wall-clock stamps; keep classification + counters only for parity.
        $t = $diag['private_engine_live_trace'];
        $diag['private_engine_live_trace'] = [
            'classification' => (string) ($t['classification'] ?? ''),
            'read_only' => !empty($t['read_only']),
            'immutable_snapshot' => !empty($t['immutable_snapshot']),
            'mutation_counters' => is_array($t['mutation_counters'] ?? null) ? $t['mutation_counters'] : [],
        ];
    }

    return $diag;
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_s7probe_a2_' . bin2hex(random_bytes(4));
$workRoot = $tmp . DIRECTORY_SEPARATOR . 'work';
mkdir($workRoot, 0777, true);

$evidenceRoot = 'D:\\orange_restore_step7_shared_helper_probe_a2_evidence';
if (!is_dir($evidenceRoot)) {
    @mkdir($evidenceRoot, 0777, true);
}

try {
    $job = orange_restore_fw_create($workRoot, [
        'package_id' => '2026-08-15_120000',
        'package_type' => 'full_disaster',
        'created_by' => 's7probe_a2',
        'created_by_admin_id' => 1,
    ]);
    $jobId = (string) $job['job_id'];
    $job = orange_restore_fw_read($workRoot, $jobId);
    $job['status'] = ORANGE_RESTORE_FW_STATUS_SHADOW_RESTORE_FAILED;
    $job['phase'] = ORANGE_RESTORE_FW_PHASE_SHADOW_RESTORE_FAILED;
    $job['source_package_id'] = '2026-08-15_120000';
    $job['package_id'] = '2026-08-15_120000';
    orange_restore_fw_write($workRoot, $job);

    // A) Signature accepts optional trailing probe (default null).
    $rf = new ReflectionFunction('orange_restore_center_diagnostics');
    s7probe_ok($rf->getNumberOfParameters() === 3, 'signature arity=3');
    $params = $rf->getParameters();
    s7probe_ok(($params[2]->getName() ?? '') === 'diagnosticProbe', 'param3=diagnosticProbe');
    s7probe_ok($params[2]->allowsNull() && $params[2]->isDefaultValueAvailable(), 'probe optional nullable');

    // B) Backward compat / worker equivalence: no-arg ≡ explicit null.
    $diagLegacy = orange_restore_center_diagnostics($workRoot, $jobId);
    $diagNull = orange_restore_center_diagnostics($workRoot, $jobId, null);
    $normLegacy = s7probe_normalize($diagLegacy);
    $normNull = s7probe_normalize($diagNull);
    $jsonLegacy = json_encode($normLegacy, JSON_UNESCAPED_UNICODE);
    $jsonNull = json_encode($normNull, JSON_UNESCAPED_UNICODE);
    s7probe_ok($jsonLegacy !== false && $jsonLegacy === $jsonNull, 'legacy≡null normalized serialize');
    s7probe_ok(
        (string) ($diagLegacy['ready_token'] ?? '') === (string) ($diagNull['ready_token'] ?? ''),
        'ready_token unchanged with null probe'
    );
    s7probe_ok(
        !empty($diagLegacy['step7_action_enabled']) === !empty($diagNull['step7_action_enabled']),
        'step7_action_enabled unchanged with null probe'
    );
    $legacyReady = (string) (($diagLegacy['step7_shadow_target_readiness']['final_readiness'] ?? null)
        ?? ($diagLegacy['step7_shadow_target_readiness']['code'] ?? '')
        ?? '');
    $nullReady = (string) (($diagNull['step7_shadow_target_readiness']['final_readiness'] ?? null)
        ?? ($diagNull['step7_shadow_target_readiness']['code'] ?? '')
        ?? '');
    s7probe_ok($legacyReady === $nullReady, 'readiness surface unchanged with null probe');

    // C) Diagnostic probe emits genuine checkpoints only (no invented package sub-stages).
    $seen = [];
    $exceptions = [];
    $probe = static function (array $event) use (&$seen, &$exceptions): void {
        $ev = (string) ($event['event'] ?? '');
        if ($ev === 'checkpoint') {
            $stage = (string) ($event['stage'] ?? '');
            if ($stage !== '') {
                $seen[] = $stage;
            }

            return;
        }
        if ($ev === 'exception') {
            $exceptions[] = (string) ($event['class'] ?? '');
        }
    };
    $diagProbed = orange_restore_center_diagnostics($workRoot, $jobId, $probe);
    $normProbed = s7probe_normalize($diagProbed);
    $jsonProbed = json_encode($normProbed, JSON_UNESCAPED_UNICODE);
    s7probe_ok($jsonProbed !== false && $jsonProbed === $jsonLegacy, 'probed return ≡ legacy (normalized)');
    s7probe_ok(in_array('diagnostics_entry', $seen, true), 'checkpoint diagnostics_entry');
    s7probe_ok(in_array('job_read', $seen, true), 'checkpoint job_read');
    s7probe_ok(in_array('private_engine_trace_build', $seen, true), 'checkpoint private_engine_trace_build');
    s7probe_ok(in_array('result_aggregation', $seen, true), 'checkpoint result_aggregation');
    s7probe_ok(in_array('diagnostics_complete', $seen, true), 'checkpoint diagnostics_complete');
    // Shadow-failed job is Step-7 guided → restore_record_read + sql cert checkpoints expected.
    s7probe_ok(in_array('restore_record_read', $seen, true), 'checkpoint restore_record_read');
    s7probe_ok(
        in_array('sql_compatibility_certificate_build', $seen, true),
        'checkpoint sql_compatibility_certificate_build'
    );
    // Must NOT invent sub-stages that are not direct helper ops.
    $forbidden = [
        'source_package_binding',
        'package_path_resolution',
        'package_identity_validation',
        'manifest_read',
        'checksums_read',
        'checksum_validation',
        'sql_dump_discovery',
        'sql_dump_open',
        'sql_parser',
        'cross_database_reference_scan',
    ];
    $invented = array_values(array_intersect($forbidden, $seen));
    s7probe_ok($invented === [], 'no invented package sub-stage checkpoints');

    // D) Throwing probe must not alter helper result.
    $throwing = static function (array $event): void {
        throw new RuntimeException('probe_must_not_escape');
    };
    $diagThrowProbe = orange_restore_center_diagnostics($workRoot, $jobId, $throwing);
    s7probe_ok(
        json_encode(s7probe_normalize($diagThrowProbe), JSON_UNESCAPED_UNICODE) === $jsonLegacy,
        'throwing probe does not alter normalized result'
    );

    // E) Endpoint wiring: probe marker + deploy sentinel intact; worker call site unchanged.
    $apiSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/orchestrator-diagnostics.php');
    $workerSrc = (string) file_get_contents($projectRoot . '/admin/api/restore/job/run-worker.php');
    s7probe_ok(str_contains($apiSrc, 'STEP7_SHARED_HELPER_PROBE_A2'), 'endpoint probe marker A2');
    s7probe_ok(str_contains($apiSrc, 'step7_diagnostic_stage_failure'), 'endpoint safe_code stage failure');
    s7probe_ok(str_contains($apiSrc, 'ORANGE_STEP7_DIAG_SENTINEL_94D_CHAIN_A1'), 'deploy sentinel intact');
    s7probe_ok(
        str_contains($apiSrc, 'orange_restore_center_diagnostics($workRoot, $jobId, $diagnosticProbe)'),
        'endpoint passes probe once'
    );
    s7probe_ok(
        substr_count($apiSrc, 'orange_restore_center_diagnostics(') === 1,
        'DIAGNOSTIC_HELPER_CALL_COUNT=1 in endpoint'
    );
    s7probe_ok(
        str_contains($workerSrc, 'orange_restore_center_diagnostics($workRoot, $jobId)'),
        'worker still 2-arg call (no probe)'
    );
    s7probe_ok(!str_contains($workerSrc, 'diagnosticProbe'), 'WORKER_CALL_SITE_CHANGE_COUNT=0');

    // F) Security: no absolute path / env leakage in probe event stages.
    foreach ($seen as $stage) {
        s7probe_ok(
            preg_match('/^[a-z0-9_]+$/', $stage) === 1,
            'stage token safe: ' . $stage
        );
    }

    $evidence = [
        'marker' => 'STEP7_SHARED_HELPER_PROBE_A2',
        'checkpoints' => $seen,
        'legacy_ready_token' => (string) ($diagLegacy['ready_token'] ?? ''),
        'probed_ready_token' => (string) ($diagProbed['ready_token'] ?? ''),
        'worker_passes_probe' => false,
        'invented_checkpoints' => $invented,
        'pass' => $pass,
        'fail' => $fail,
    ];
    @file_put_contents(
        $evidenceRoot . DIRECTORY_SEPARATOR . 'probe_a2_self_test.json',
        json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    echo "RESULT pass={$pass} fail={$fail}\n";
    exit($fail > 0 ? 1 : 0);
} finally {
    s7probe_rm_rf($tmp);
}
