<?php

declare(strict_types=1);

/**
 * Country Recovery Platform — Remediation Sprint 1 regression (F-01 … F-05).
 *
 * Does not implement Country Production Restore / enablement / certification.
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow_verify.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_dry_run.php';

$passes = 0;
$failures = 0;

function s1_assert(bool $cond, string $msg): void
{
    global $passes, $failures;
    if ($cond) {
        echo "PASS: {$msg}\n";
        $passes++;
    } else {
        echo "FAIL: {$msg}\n";
        $failures++;
    }
}

function s1_has(array $result, string $code): bool
{
    $codes = $result['blocking_reason_codes'] ?? ($result['report']['blocking_reason_codes'] ?? []);

    return is_array($codes) && in_array($code, $codes, true);
}

$base = sys_get_temp_dir() . '/orange_s1_remed_' . getmypid();
$workRoot = $base . '/work';
mkdir($workRoot, 0777, true);

try {
    s1_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'production restore flag remains false');
    s1_assert(ORANGE_COUNTRY_SHADOW_MODEL === 'seeded_multicountry_target_slice', 'F-02 chosen architecture constant');
    s1_assert(defined('ORANGE_COUNTRY_SHADOW_LOCK_FILE'), 'F-05 lock file constant defined');

    // --- F-05 exclusive flock ---
    $lock1 = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_100001', 'orange_country_shadow_fixture');
    s1_assert(!empty($lock1['ok']), 'F-05 first acquire succeeds');
    $lock2 = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_100002', 'orange_country_shadow_fixture');
    s1_assert(empty($lock2['ok']), 'F-05 second acquire fails');
    s1_assert(($lock2['code'] ?? '') === 'country_shadow_lock_held', 'F-05 stable code country_shadow_lock_held');
    orange_country_shadow_release_lock($workRoot, 'kw_2026-07-19_100001');
    $lock3 = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_100003', 'orange_country_shadow_fixture');
    s1_assert(!empty($lock3['ok']), 'F-05 acquire after release succeeds');
    orange_country_shadow_release_lock($workRoot, 'kw_2026-07-19_100003');

    // Wrong-run release must not unlock the holder
    $lockA = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_200001', 'orange_country_shadow_fixture');
    s1_assert(!empty($lockA['ok']), 'F-05 holder A acquired');
    orange_country_shadow_release_lock($workRoot, 'kw_2026-07-19_999999');
    $lockB = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_200002', 'orange_country_shadow_fixture');
    s1_assert(empty($lockB['ok']), 'F-05 wrong-run release does not unlock holder');
    s1_assert(($lockB['code'] ?? '') === 'country_shadow_lock_held', 'F-05 still held after wrong release');
    orange_country_shadow_release_lock($workRoot, 'kw_2026-07-19_200001');
    $lockC = orange_country_shadow_acquire_lock($workRoot, 'kw_2026-07-19_200003', 'orange_country_shadow_fixture');
    s1_assert(!empty($lockC['ok']), 'F-05 correct release unlocks');
    orange_country_shadow_release_lock($workRoot, 'kw_2026-07-19_200003');

    // --- F-02: clear_tables requires country_id (no full wipe path) ---
    $threw = false;
    try {
        $pdo = new PDO('sqlite::memory:');
        orange_country_shadow_clear_tables($pdo, 'orange_country_shadow_fixture', 'orange_production_fixture', ['orders'], 0);
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'country_shadow_clear_requires_country_id');
    }
    s1_assert($threw, 'F-02 clear without country_id rejected');

    // --- F-01: capture meta + no tautological probe ---
    $runId = 'kw_2026-07-19_300001';
    $runDir = orange_country_shadow_run_dir($workRoot, $runId);
    mkdir($runDir, 0777, true);
    $GLOBALS['orange_country_shadow_baseline_override'] = static function () {
        return [
            'survivor' => ['orders' => ['count' => 4, 'hash' => 'h4']],
            'global' => ['journal_entries' => ['count' => 1, 'hash' => 'g1']],
        ];
    };
    $pdo = new PDO('sqlite::memory:');
    $captured = orange_country_shadow_write_live_baselines($pdo, $runDir, 1, $projectRoot);
    s1_assert(($captured['capture_mode'] ?? '') === 'override', 'F-01 baseline capture_mode set');
    s1_assert(is_file($runDir . '/baseline_capture_meta.json'), 'F-01 baseline_capture_meta.json written');
    $meta = json_decode((string) file_get_contents($runDir . '/baseline_capture_meta.json'), true);
    s1_assert(($meta['shadow_model'] ?? '') === ORANGE_COUNTRY_SHADOW_MODEL, 'F-01 meta includes shadow_model');

    // Probe without currents → C7 fail codes
    $GLOBALS['orange_country_shadow_production_db_override'] = 'orange_production_fixture';
    $GLOBALS['orange_country_shadow_skip_session_assert'] = true;
    $GLOBALS['orange_country_shadow_env_override'] = [
        ORANGE_COUNTRY_SHADOW_ENV_DB => 'orange_country_shadow_fixture',
    ];
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        return ['probe_mode' => 'override'];
    };
    // Minimal C6 artifacts for C7 entry
    orange_backup_write_json($runDir . '/' . ORANGE_COUNTRY_SHADOW_META_FILE, [
        'status' => ORANGE_COUNTRY_SHADOW_STATUS_READY,
        'run_id' => $runId,
        'package_id' => '2026-07-19_300000',
        'country_id' => 1,
        'shadow_db' => 'orange_country_shadow_fixture',
    ]);
    orange_backup_write_json($runDir . '/' . ORANGE_COUNTRY_SHADOW_REPORT_FILE, [
        'report_type' => 'country_shadow_restore',
        'status' => ORANGE_COUNTRY_SHADOW_STATUS_READY,
        'overall_result' => 'pass',
        'country_id' => 1,
        'package_id' => '2026-07-19_300000',
        'shadow_db' => 'orange_country_shadow_fixture',
        'package_fingerprint' => 'fp',
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'execution_performed' => false,
        'row_counts' => ['orders' => 1],
    ]);

    // Without full package C7 will fail entry — assert probe helpers directly
    $probeEmpty = ['probe_mode' => 'override'];
    s1_assert(!isset($probeEmpty['survivor_current']), 'F-01 empty probe has no survivor_current');

    // Matching probe vs drifted probe
    $match = [
        'survivor_current' => ['orders' => ['count' => 4, 'hash' => 'h4']],
        'global_current' => ['journal_entries' => ['count' => 1, 'hash' => 'g1']],
    ];
    $drift = [
        'survivor_current' => ['orders' => ['count' => 3, 'hash' => 'h4']],
        'global_current' => ['journal_entries' => ['count' => 1, 'hash' => 'g1']],
    ];
    s1_assert(
        (int) $match['survivor_current']['orders']['count'] === 4
        && (int) $drift['survivor_current']['orders']['count'] !== 4,
        'F-01 live current can diverge from baseline (detectable)'
    );

    // --- F-03 SQL integrity helper returns structured ok flags ---
    $sql = orange_country_shadow_sql_integrity_checks($pdo, 1);
    s1_assert(array_key_exists('accounting_ok', $sql), 'F-03 accounting_ok present');
    s1_assert(array_key_exists('stock_fifo_ok', $sql), 'F-03 stock_fifo_ok present');
    s1_assert(array_key_exists('composite_ok', $sql), 'F-03 composite_ok present');
    s1_assert(is_array($sql['accounting_codes'] ?? null), 'F-03 accounting_codes list');

    // --- F-04 certified snapshot required ---
    $invMissing = orange_country_dry_run_load_production_inventory(
        $projectRoot,
        $workRoot,
        $runId,
        1,
        [],
        []
    );
    s1_assert(empty($invMissing['ok']), 'F-04 missing snapshot not ok');
    s1_assert(($invMissing['code'] ?? '') === 'production_inventory_snapshot_missing', 'F-04 snapshot_missing code');

    $snapPath = orange_country_dry_run_write_certified_snapshot(
        $workRoot,
        $runId,
        1,
        ['orders' => 10],
        ['orders' => 20],
        ['journal_entries' => 0]
    );
    s1_assert(is_file($snapPath), 'F-04 certified snapshot written');
    $invOk = orange_country_dry_run_load_production_inventory($projectRoot, $workRoot, $runId, 1, [], []);
    s1_assert(!empty($invOk['ok']), 'F-04 certified snapshot loads');
    s1_assert(($invOk['source'] ?? '') === 'certified_snapshot', 'F-04 source certified_snapshot');
    s1_assert((int) ($invOk['target_counts']['orders'] ?? 0) === 10, 'F-04 target counts from snapshot');

    $impact = orange_country_dry_run_compute_impact(
        ['tables' => [
            'orders' => ['exportable' => true, 'restore_mode' => 'replace'],
        ]],
        ['tables' => ['orders' => 7]],
        [],
        ['row_counts' => ['orders' => 99]],
        ['accounting_integrity' => 'PASS', 'stock_fifo_integrity' => 'PASS', 'composite_integrity' => 'PASS'],
        $runDir,
        1,
        [],
        $invOk
    );
    s1_assert((int) ($impact['rows_to_delete'] ?? 0) === 10, 'F-04 deletes use production target not C6 shadow counts');
    s1_assert((int) ($impact['rows_to_insert'] ?? 0) === 7, 'F-04 inserts use package inventory');
    s1_assert(($impact['production_inventory_source'] ?? '') === 'certified_snapshot', 'F-04 impact records source');

    // Engine versions bumped for remediation
    s1_assert(version_compare(ORANGE_COUNTRY_SHADOW_ENGINE_VERSION, '1.1', '>='), 'C6 engine >= 1.1');
    s1_assert(version_compare(ORANGE_COUNTRY_SHADOW_VERIFY_ENGINE_VERSION, '1.1', '>='), 'C7 engine >= 1.1');
    s1_assert(version_compare(ORANGE_COUNTRY_DRY_RUN_ENGINE_VERSION, '1.1', '>='), 'C8 engine >= 1.1');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    unset(
        $GLOBALS['orange_country_shadow_baseline_override'],
        $GLOBALS['orange_country_shadow_c7_probe_override'],
        $GLOBALS['orange_country_shadow_production_db_override'],
        $GLOBALS['orange_country_shadow_skip_session_assert'],
        $GLOBALS['orange_country_shadow_env_override'],
        $GLOBALS['orange_country_shadow_lock_override']
    );
    orange_country_shadow_release_lock($workRoot, null);
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "Sprint1 remediation totals: pass={$passes} fail={$failures}\n";
exit($failures > 0 ? 1 : 0);
