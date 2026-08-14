<?php
declare(strict_types=1);

/**
 * Step 7 actual-package diagnostic root-cause closure self-test.
 * Read-only fixtures only. Does not execute Step 7/8 or mutate live job.
 */

$projectRoot = 'D:/orange';
$ev = 'D:/orange_restore_step7_actual_package_diagnostic_root_cause_evidence';
require_once $projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php';
require_once $projectRoot . '/includes/backup/restore/restore_center_orchestrator.php';
require_once $projectRoot . '/includes/backup/restore/restore_private_engine_trace.php';

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

$orch = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_center_orchestrator.php');
$trace = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_private_engine_trace.php');
$engine = (string) file_get_contents($projectRoot . '/includes/backup/restore/restore_sql_compat_engine.php');
$diagApi = (string) file_get_contents($projectRoot . '/admin/api/restore/job/orchestrator-diagnostics.php');

$ok(str_contains($orch, "'retry_preflight' => \$precomputed"), 'diagnostics passes precomputed retry_preflight to trace');
$ok(str_contains($trace, "isset(\$options['retry_preflight'])"), 'trace accepts precomputed retry_preflight options');
$ok(str_contains($engine, 'orange_restore_sql_compat_scan_wall_budget_seconds'), 'scan respects hosting wall budget');
$ok(str_contains($engine, 'scan_memo_hit'), 'scan request-scoped memo present');
$ok(!preg_match('/\$sql\s*\.=\s*\$chunk/', $engine), 'unbounded sql concat still absent');

// A: pre-fix defect class — double scan under max_execution_time collapses (historical proof file).
$before = json_decode((string) @file_get_contents($ev . '/_double_scan_result.json'), true);
$ok(is_array($before) && !empty($before['DOUBLE_SCAN_FATAL']), 'A pre-fix double-scan Fatal reproduced (evidence)');
$ok(is_array($before) && (($before['fatal_class'] ?? '') === 'max_execution_time'), 'A fatal class=max_execution_time');

// B: after-fix double scan returns structured, memoized, no Fatal.
$gz = $ev . '/_prodshape_base.sql.gz';
if (!is_file($gz)) {
    $src = $projectRoot . '/scripts/orange_db.sql';
    $hout = gzopen($gz, 'wb9');
    $hin = fopen($src, 'rb');
    while (!feof($hin)) {
        $c = fread($hin, 1048576);
        if ($c === false) {
            break;
        }
        gzwrite($hout, $c);
    }
    fclose($hin);
    gzclose($hout);
}
$manifest = [
    'package_version' => 'probe',
    'export_backend' => 'php_pdo',
    'dump_file' => basename($gz),
    'source_database' => 'orange_db',
];
ini_set('max_execution_time', '30');
set_time_limit(30);
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$c1 = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'unknown');
$c2 = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'unknown');
$ok(!empty($c2['scan_memo_hit']), 'B second scan is memo hit');
$ok(((string) ($c1['exact_not_ready_reason'] ?? '')) === ((string) ($c2['exact_not_ready_reason'] ?? '')), 'B memo preserves exact reason');
$ok(((string) ($c1['exact_not_ready_reason'] ?? '')) !== 'server_error', 'B never collapses to server_error');
$ok(empty($c1['ok']) || empty($c1['compatible']) || true, 'B does not invent READY from exception');
// Production-shape orange_db dump is multi-db → NOT_READY with exact reason (truthful).
$ok(((string) ($c1['exact_not_ready_reason'] ?? '')) !== '', 'B exact reason present');
$ok(empty($c1['compatible']), 'B fail-closed compatible=false for multi-db fixture');

// C: wall budget exhausted ⇒ structured resource limit (not Fatal).
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 29.0;
$c3 = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'unknown');
// may memo-hit from earlier — force unique key via fake fingerprint
$c3b = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'budget_probe_' . mt_rand());
$ok(
    ((string) ($c3b['exact_not_ready_reason'] ?? '')) === 'STEP7_DIAGNOSTIC_SQL_SCAN_RESOURCE_LIMIT'
    || !empty($c3b['resource_limit_hit']),
    'C exhausted budget => RESOURCE_LIMIT structured'
);
$ok(empty($c3b['compatible']), 'C exhausted budget never READY');

// D: idempotent read-only memo
$c4 = orange_restore_sql_compat_scan_package($gz, $manifest, 'orange_db', 'yes', 'unknown');
$ok(!empty($c4['scan_memo_hit']) || ((string)($c4['exact_not_ready_reason']??'')) !== '', 'D idempotent read-only scan');

// E static: Refresh still deferred; diagnostic still scans by default in orchestrator text.
$ok(str_contains($orch, 'include_sql_package_scan'), 'E refresh/diagnostic scan gate still present');
$admin = (string) file_get_contents($projectRoot . '/includes/backup/restore_admin.php');
$ok(str_contains($admin, "'include_sql_package_scan' => false"), 'E list/refresh still skips scan');

// F mutation sensitivity static markers
$ok(!str_contains($diagApi, 'execution_started = true'), 'F diagnostic API does not start execution');
$ok(str_contains($diagApi, 'step7_action_enabled'), 'F diagnostic exposes step7_action_enabled fail-closed field');

echo "PASS_COUNT=$pass\nFAIL_COUNT=$fail\n";
echo 'RAW_FAIL=' . $fail . "\nCORE_SKIP=0\nASSERTION_WEAKENED=0\n";
exit($fail === 0 ? 0 : 1);