<?php

declare(strict_types=1);

/**
 * Phase C6 — Country Shadow Restore self-tests (fixture DBs / mocks only).
 * Never touches production.
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c6_shadow.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/backup_manifest.php';
require_once $projectRoot . '/includes/backup/backup_table_registry_lib.php';
require_once $projectRoot . '/includes/backup/backup_environment.php';
require_once $projectRoot . '/includes/backup/country_boundary_matrix_lib.php';
require_once $projectRoot . '/includes/backup/country_export.php';
require_once $projectRoot . '/includes/backup/uploads_collector.php';
require_once $projectRoot . '/includes/backup/country_crp_verify.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow.php';

$failures = 0;
$passes = 0;

function c6_assert(bool $ok, string $label): void
{
    global $failures, $passes;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passes++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

/**
 * @param array<string, mixed> $opts
 */
function c6_build_crp(string $projectRoot, string $dir, array $opts = []): void
{
    if (is_dir($dir)) {
        orange_backup_remove_dir($dir);
    }
    mkdir($dir, 0777, true);
    mkdir($dir . '/sql', 0777, true);
    mkdir($dir . '/files', 0777, true);

    $countryId = (int) ($opts['country_id'] ?? 1);
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    $registry = orange_backup_registry_load($projectRoot);
    $batches = orange_country_boundary_matrix_restore_batches($matrix);
    $dep = orange_country_export_build_dependency_graph_c3($matrix, $registry, $batches);
    orange_backup_write_json($dir . '/dependency_graph.json', $dep);

    $sqlBody = "-- Orange CRP export table=orders country_id={$countryId}\n"
        . "INSERT INTO `orders` (`id`,`country_id`) VALUES (1,{$countryId});\n";
    file_put_contents($dir . '/sql/010_orders.sql', $sqlBody);
    $gz = gzopen($dir . '/country.sql.gz', 'wb9');
    gzwrite($gz, $sqlBody);
    gzclose($gz);
    orange_country_uploads_write_empty_zip($dir . '/files/uploads_country.zip');

    orange_backup_write_json($dir . '/table_inventory.json', [
        'country_id' => $countryId,
        'tables' => ['orders' => 1],
        'other_country_markers' => [],
    ]);
    orange_backup_write_json($dir . '/id_snapshot.json', [
        'country_id' => $countryId,
        'tables' => ['orders' => [1]],
    ]);
    orange_backup_write_json($dir . '/health.json', [
        'package_status' => 'healthy',
        'country_id' => $countryId,
        'schema_revision' => 121,
    ]);

    $manifest = [
        'package_type' => 'country_recovery',
        'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
        'generated_at' => gmdate('c'),
        'export_time' => gmdate('c'),
        'country_id' => $countryId,
        'country_code' => 'kw',
        'schema_revision' => 121,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
        'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
        'export_backend' => ORANGE_COUNTRY_EXPORT_BACKEND,
        'registry_version' => '1.0',
        'restore_batches' => $batches,
        'package_fingerprint' => '',
    ];
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    $manifest['package_fingerprint'] = orange_crp_export_package_fingerprint($dir, $manifest);
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    orange_backup_write_checksums($dir, orange_backup_collect_package_files($dir));

    if (empty($opts['skip_verify_drv'])) {
        orange_crp_verify_run($dir, ['write_report' => true, 'project_root' => $projectRoot]);
        orange_country_drv_run($dir, [
            'write_report' => true,
            'project_root' => $projectRoot,
            'package_id' => basename($dir),
        ]);
    }
}

function c6_install_mocks(): void
{
    $GLOBALS['orange_country_shadow_production_db_override'] = 'orange_production_fixture';
    $GLOBALS['orange_country_shadow_skip_session_assert'] = true;
    $GLOBALS['orange_country_shadow_env_override'] = [
        ORANGE_COUNTRY_SHADOW_ENV_DB => 'orange_country_shadow_fixture',
    ];
    $GLOBALS['orange_country_shadow_connect_override'] = static function () {
        return new PDO('sqlite::memory:');
    };
    $GLOBALS['orange_country_shadow_wipe_override'] = static function (): void {
        // no-op
    };
    $GLOBALS['orange_country_shadow_import_override'] = static function () {
        return ['ok' => true, 'files_imported' => 1, 'statements_executed' => 2, 'error' => null];
    };
    $GLOBALS['orange_country_shadow_verify_override'] = static function () {
        return [
            'ok' => true,
            'codes' => [],
            'checks' => [['id' => 'mock', 'status' => 'PASS', 'code' => null, 'detail' => 'ok']],
            'row_counts' => ['orders' => 1],
        ];
    };
}

function c6_clear_mocks(): void
{
    unset(
        $GLOBALS['orange_country_shadow_production_db_override'],
        $GLOBALS['orange_country_shadow_skip_session_assert'],
        $GLOBALS['orange_country_shadow_env_override'],
        $GLOBALS['orange_country_shadow_connect_override'],
        $GLOBALS['orange_country_shadow_wipe_override'],
        $GLOBALS['orange_country_shadow_import_override'],
        $GLOBALS['orange_country_shadow_verify_override'],
        $GLOBALS['orange_country_shadow_baseline_override']
    );
}

$base = sys_get_temp_dir() . '/orange_c6_shadow_' . getmypid();
$backupRoot = $base . '/backup';
$workRoot = $base . '/work';
$pkgId = '2026-07-19_190000';
$pkgDir = $backupRoot . '/country_packages/kw/' . $pkgId;
mkdir($pkgDir, 0777, true);
mkdir($workRoot, 0777, true);

try {
    c6_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country production restore remains disabled');

    // Entry: missing verify
    c6_build_crp($projectRoot, $pkgDir, ['skip_verify_drv' => true]);
    $entry = orange_country_shadow_entry_check($pkgDir, $pkgId, $projectRoot);
    c6_assert(!$entry['ok'] && in_array('verify_report_missing', $entry['codes'], true), 'rejects missing Verify');

    // Valid package + mocks → ready
    c6_build_crp($projectRoot, $pkgDir);
    c6_assert(is_file($pkgDir . '/country_verify_report.json'), 'C4 verify report present');
    c6_assert(is_file(dirname($pkgDir) . '/' . $pkgId . '.country_recovery_validation.json')
        || is_file(orange_country_drv_report_sibling_path($pkgDir, $pkgId)), 'C5 DRV report present');

    c6_install_mocks();
    $result = orange_country_shadow_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $pkgId,
        'country_code' => 'kw',
    ]);
    c6_assert(!empty($result['ok']), 'valid CRP shadow restore PASS');
    c6_assert(($result['status'] ?? '') === ORANGE_COUNTRY_SHADOW_STATUS_READY, 'status ready');
    c6_assert(($result['production_touched'] ?? true) === false, 'production_touched false');
    c6_assert(is_file((string) ($result['report_path'] ?? '')), 'writes country_shadow_restore_report.json');
    $report = $result['report'] ?? [];
    c6_assert(($report['report_type'] ?? '') === 'country_shadow_restore', 'report type');
    c6_assert(($report['execution_performed'] ?? true) === false, 'execution_performed false');
    c6_assert(($report['country_production_restore_enabled'] ?? true) === false, 'restore disabled in report');
    $c6RunDir = orange_country_shadow_run_dir($workRoot, (string) $result['run_id']);
    c6_assert(is_file($c6RunDir . '/survivor_baseline.json'), 'pre-restore survivor baseline written');
    c6_assert(is_file($c6RunDir . '/global_baseline.json'), 'pre-restore global baseline written');

    // Status GET helper
    $status = orange_country_shadow_status($workRoot, (string) $result['run_id']);
    c6_assert(($status['status'] ?? '') === ORANGE_COUNTRY_SHADOW_STATUS_READY, 'status API payload ready');
    c6_assert(!empty($status['read_only']), 'status read_only');

    // Reject Verify FAIL
    c6_build_crp($projectRoot, $pkgDir, ['skip_verify_drv' => true]);
    $mani = json_decode((string) file_get_contents($pkgDir . '/manifest.json'), true);
    orange_backup_write_json($pkgDir . '/country_verify_report.json', [
        'report_type' => 'country_recovery_verify',
        'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
        'overall' => 'FAIL',
        'ok' => false,
        'codes' => ['x'],
        'country_id' => 1,
        'schema_revision' => 121,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'package_fingerprint' => $mani['package_fingerprint'] ?? '',
    ]);
    orange_backup_write_json(orange_country_drv_report_sibling_path($pkgDir, $pkgId), [
        'package_type' => 'country',
        'overall_result' => 'pass',
        'recovery_score' => 100,
    ]);
    $entry = orange_country_shadow_entry_check($pkgDir, $pkgId, $projectRoot);
    c6_assert(in_array('verify_not_pass', $entry['codes'], true), 'rejects Verify FAIL');

    // Reject DRV not pass
    c6_build_crp($projectRoot, $pkgDir);
    orange_backup_write_json(orange_country_drv_report_sibling_path($pkgDir, $pkgId), [
        'package_type' => 'country',
        'overall_result' => 'fail',
        'recovery_score' => 10,
        'blocking_reason_codes' => ['x'],
    ]);
    $entry = orange_country_shadow_entry_check($pkgDir, $pkgId, $projectRoot);
    c6_assert(in_array('country_drv_not_pass', $entry['codes'], true), 'rejects Country DRV FAIL');

    // Fingerprint changed
    c6_build_crp($projectRoot, $pkgDir);
    $gz = gzopen($pkgDir . '/country.sql.gz', 'wb9');
    gzwrite($gz, "-- tamper\n");
    gzclose($gz);
    $entry = orange_country_shadow_entry_check($pkgDir, $pkgId, $projectRoot);
    c6_assert(in_array('fingerprint_changed', $entry['codes'], true), 'rejects fingerprint change');

    // Shadow DB must not equal production
    $threw = false;
    try {
        orange_country_shadow_db_name([ORANGE_COUNTRY_SHADOW_ENV_DB => 'orange_production_fixture'], $projectRoot);
    } catch (Throwable $e) {
        $threw = $e->getMessage() === 'country_shadow_db_equals_production';
    }
    c6_assert($threw, 'shadow DB != production asserted');

    // Import plan respects batches / rejects never-export
    c6_build_crp($projectRoot, $pkgDir);
    $mani = json_decode((string) file_get_contents($pkgDir . '/manifest.json'), true);
    $plan = orange_country_shadow_build_import_plan($projectRoot, $pkgDir, $mani);
    c6_assert(!empty($plan['ok']), 'import plan OK');
    c6_assert(in_array('orders', $plan['tables'], true), 'orders in restore tables');
    file_put_contents($pkgDir . '/sql/999_journal_entries.sql', "INSERT INTO `journal_entries` (`id`) VALUES (1);\n");
    $planBad = orange_country_shadow_build_import_plan($projectRoot, $pkgDir, $mani);
    c6_assert(empty($planBad['ok']), 'rejects Full-only/never-export SQL chunk');

    // Verify failure path via mock
    c6_build_crp($projectRoot, $pkgDir);
    $GLOBALS['orange_country_shadow_verify_override'] = static function () {
        return [
            'ok' => false,
            'codes' => ['country_leakage_in_shadow'],
            'checks' => [],
            'row_counts' => [],
        ];
    };
    $failRun = orange_country_shadow_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $pkgId,
        'country_code' => 'kw',
    ]);
    c6_assert(empty($failRun['ok']) && ($failRun['status'] ?? '') === ORANGE_COUNTRY_SHADOW_STATUS_FAILED, 'verify failure → failed state');
    c6_assert(($failRun['code'] ?? '') === 'shadow_verify_failed', 'shadow_verify_failed code');

    // Invalid run id
    $threw = false;
    try {
        orange_country_shadow_assert_run_id('../evil');
    } catch (Throwable) {
        $threw = true;
    }
    c6_assert($threw, 'run_id allowlist');

    // CLI is CLI-only (file declares)
    $cli = (string) file_get_contents($projectRoot . '/scripts/backup/restore_country_shadow.php');
    c6_assert(str_contains($cli, "PHP_SAPI !== 'cli'"), 'CLI guard present');
    $api = (string) file_get_contents($projectRoot . '/admin/api/restore/country-shadow-status.php');
    c6_assert(str_contains($api, 'restore_admin_api_require_get'), 'HTTP GET-only status');
    c6_assert(!str_contains($api, 'orange_country_shadow_run('), 'HTTP does not execute shadow restore');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    c6_clear_mocks();
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "C6 totals: pass={$passes} fail={$failures}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: C6 Country Shadow Restore self-tests passed.\n";
exit(0);
