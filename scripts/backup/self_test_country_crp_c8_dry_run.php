<?php

declare(strict_types=1);

/**
 * Phase C8 — Country Dry Run self-tests (fixtures / inject only).
 * Simulation only — never touches production or shadow DBs.
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c8_dry_run.php
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
require_once $projectRoot . '/includes/backup/restore/restore_country_shadow_verify.php';
require_once $projectRoot . '/includes/backup/restore/restore_country_dry_run.php';

$failures = 0;
$passes = 0;

function c8_assert(bool $ok, string $label): void
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

function c8_has(array $result, string $code): bool
{
    return in_array($code, $result['blocking_reason_codes'] ?? [], true)
        || in_array($code, $result['report']['blocking_reason_codes'] ?? [], true);
}

/**
 * @param array<string, mixed> $opts
 */
function c8_build_crp(string $projectRoot, string $dir, array $opts = []): void
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

    orange_crp_verify_run($dir, ['write_report' => true, 'project_root' => $projectRoot]);
    orange_country_drv_run($dir, [
        'write_report' => true,
        'project_root' => $projectRoot,
        'package_id' => basename($dir),
    ]);
}

function c8_install_mocks(): void
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
    $GLOBALS['orange_country_shadow_baseline_override'] = static function () {
        return [
            'survivor' => [
                'orders' => ['count' => 2, 'hash' => hash('sha256', 'survivor_orders')],
            ],
            'global' => [
                'journal_entries' => ['count' => 0, 'hash' => hash('sha256', 'je0')],
                'product_taxonomy_nodes' => ['count' => 3, 'hash' => hash('sha256', 'tax3')],
            ],
        ];
    };
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        return [
            'probe_mode' => 'override',
            'survivor_current' => [
                'orders' => ['count' => 2, 'hash' => hash('sha256', 'survivor_orders')],
            ],
            'global_current' => [
                'journal_entries' => ['count' => 0, 'hash' => hash('sha256', 'je0')],
                'product_taxonomy_nodes' => ['count' => 3, 'hash' => hash('sha256', 'tax3')],
            ],
            'boundary_violations' => [],
            'accounting_ok' => true,
            'stock_fifo_ok' => true,
            'composite_ok' => true,
            'accounting_codes' => [],
            'stock_fifo_codes' => [],
            'composite_codes' => [],
        ];
    };
}

function c8_clear_mocks(): void
{
    unset(
        $GLOBALS['orange_country_shadow_production_db_override'],
        $GLOBALS['orange_country_shadow_skip_session_assert'],
        $GLOBALS['orange_country_shadow_env_override'],
        $GLOBALS['orange_country_shadow_connect_override'],
        $GLOBALS['orange_country_shadow_wipe_override'],
        $GLOBALS['orange_country_shadow_import_override'],
        $GLOBALS['orange_country_shadow_verify_override'],
        $GLOBALS['orange_country_shadow_baseline_override'],
        $GLOBALS['orange_country_shadow_c7_probe_override'],
        $GLOBALS['orange_country_shadow_lock_override'],
        $GLOBALS['orange_country_dry_run_production_inventory_override']
    );
}

/**
 * @param array<string, mixed> $inject
 * @return array<string, mixed>
 */
function c8_run(
    string $projectRoot,
    string $backupRoot,
    string $workRoot,
    string $jobId,
    array $inject = []
): array {
    return orange_country_dry_run_execute([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'job_id' => $jobId,
        'inject' => $inject,
    ]);
}

$base = sys_get_temp_dir() . '/orange_c8_dry_' . getmypid();
$backupRoot = $base . '/backup';
$workRoot = $base . '/work';
$pkgId = '2026-07-19_192000';
$pkgDir = $backupRoot . '/country_packages/kw/' . $pkgId;
mkdir($pkgDir, 0777, true);
mkdir($workRoot, 0777, true);

try {
    c8_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country production restore remains disabled');

    c8_build_crp($projectRoot, $pkgDir);
    c8_install_mocks();

    $c6 = orange_country_shadow_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $pkgId,
        'country_code' => 'kw',
    ]);
    c8_assert(!empty($c6['ok']), 'C6 fixture ready');
    $jobId = (string) ($c6['run_id'] ?? '');

    $c7 = orange_country_shadow_verify_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'job_id' => $jobId,
    ]);
    c8_assert(($c7['overall_result'] ?? '') === 'READY', 'C7 READY for dry-run entry');

    // F-04: missing certified production inventory → FAIL
    $missingInv = c8_run($projectRoot, $backupRoot, $workRoot, $jobId);
    c8_assert(c8_has($missingInv, 'production_inventory_snapshot_missing'), 'F-04 missing production inventory fails');

    // F-04: write certified read-only snapshot then SAFE
    orange_country_dry_run_write_certified_snapshot(
        $workRoot,
        $jobId,
        1,
        ['orders' => 5, 'accounts' => 2],
        ['orders' => 9],
        ['journal_entries' => 0, 'product_taxonomy_nodes' => 3]
    );

    // SAFE
    $safe = c8_run($projectRoot, $backupRoot, $workRoot, $jobId);
    c8_assert(($safe['overall_result'] ?? '') === 'SAFE', 'SAFE dry-run');
    c8_assert(($safe['status'] ?? '') === ORANGE_COUNTRY_DRY_RUN_STATUS_SAFE, 'status country_dry_run_safe');
    $rep = $safe['report'] ?? [];
    c8_assert(($rep['report_type'] ?? '') === 'country_dry_run', 'report_type country_dry_run');
    c8_assert((int) ($rep['survivor_country_impact'] ?? -1) === 0, 'survivor impact zero');
    c8_assert((int) ($rep['global_impact'] ?? -1) === 0, 'global impact zero');
    c8_assert(($rep['production_db_writes'] ?? 1) === 0, 'production_db_writes 0');
    c8_assert(($rep['shadow_db_writes'] ?? 1) === 0, 'shadow_db_writes 0');
    c8_assert(($rep['execution_performed'] ?? true) === false, 'execution_performed false');
    c8_assert(($rep['simulation_only'] ?? false) === true, 'simulation_only true');
    c8_assert((int) ($rep['rows_to_insert'] ?? 0) >= 1, 'rows_to_insert calculated');
    c8_assert(($rep['production_inventory_source'] ?? '') === 'certified_snapshot', 'F-04 impact uses certified snapshot');
    c8_assert((int) ($rep['rows_to_delete'] ?? 0) >= 5, 'F-04 rows_to_delete from production target counts');
    c8_assert(is_array($rep['composite_units'] ?? null) && count($rep['composite_units']) >= 1, 'composite_units present');
    c8_assert(is_array($rep['special_handlers'] ?? null), 'special_handlers present');
    c8_assert(isset($rep['estimated_duration']), 'estimated_duration present');
    $runDir = orange_country_shadow_run_dir($workRoot, $jobId);
    c8_assert(is_file($runDir . '/' . ORANGE_COUNTRY_DRY_RUN_REPORT_FILE), 'writes country_dry_run_report.json');

    // WARNING
    $warn = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['warning_only' => true]);
    c8_assert(($warn['overall_result'] ?? '') === 'WARNING', 'WARNING dry-run');
    c8_assert(($warn['status'] ?? '') === ORANGE_COUNTRY_DRY_RUN_STATUS_WARNING, 'status country_dry_run_warning');

    // FAIL: missing C7
    $badJob = 'kw_2099-01-01_000002';
    mkdir(orange_country_shadow_run_dir($workRoot, $badJob), 0777, true);
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $badJob);
    c8_assert(c8_has($r, 'c7_report_missing'), 'FAIL missing C7');

    // FAIL: C7 not READY — rewrite report temporarily
    $c7Path = $runDir . '/' . ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE;
    $c7Orig = (string) file_get_contents($c7Path);
    $c7Bad = json_decode($c7Orig, true);
    $c7Bad['overall_result'] = 'WARNING';
    $c7Bad['readiness_score'] = 80;
    orange_backup_write_json($c7Path, $c7Bad);
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId);
    c8_assert(c8_has($r, 'c7_not_ready') || c8_has($r, 'c7_score_below_threshold'), 'FAIL when C7 not READY');
    file_put_contents($c7Path, $c7Orig);

    // leakage
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['leakage' => true]);
    c8_assert(c8_has($r, 'cross_country_leakage_predicted'), 'FAIL leakage');

    // Global mutation
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['global_mutation' => true]);
    c8_assert(c8_has($r, 'global_mutation_predicted'), 'FAIL Global mutation');
    c8_assert(($r['overall_result'] ?? '') === 'FAIL', 'Global mutation overall FAIL');

    // Survivor mutation
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['survivor_mutation' => true]);
    c8_assert(c8_has($r, 'survivor_country_mutation_predicted'), 'FAIL survivor mutation');

    // journal_entries
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['journal_entries_mutation' => true]);
    c8_assert(c8_has($r, 'journal_entries_mutation_predicted'), 'FAIL journal_entries mutation');

    // Full-only
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['full_only_mutation' => true]);
    c8_assert(c8_has($r, 'full_only_table_mutation_predicted'), 'FAIL Full-only mutation');

    // Sequence collision
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['sequence_collision' => true]);
    c8_assert(c8_has($r, 'sequence_collision'), 'FAIL sequence collision');

    // Accounting violation
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['accounting_violation' => true]);
    c8_assert(c8_has($r, 'accounting_boundary_violation'), 'FAIL accounting violation');

    // FIFO violation
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['fifo_violation' => true]);
    c8_assert(c8_has($r, 'stock_fifo_corruption_predicted'), 'FAIL FIFO violation');

    // Composite failure
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['composite_failure' => true]);
    c8_assert(c8_has($r, 'unresolved_composite'), 'FAIL composite failure');

    // Unresolved ownership
    $r = c8_run($projectRoot, $backupRoot, $workRoot, $jobId, ['unresolved_ownership' => true]);
    c8_assert(c8_has($r, 'unresolved_ownership'), 'FAIL unresolved ownership');

    // Status helper
    $st = orange_country_dry_run_status($workRoot, $jobId);
    c8_assert(!empty($st['dry_run_available']), 'dry_run status available');
    c8_assert(!empty($st['read_only']), 'status read_only');
    c8_assert(($st['execution_performed'] ?? true) === false, 'status execution_performed false');

    // Guards
    $cli = (string) file_get_contents($projectRoot . '/scripts/backup/country_dry_run.php');
    c8_assert(str_contains($cli, "PHP_SAPI !== 'cli'"), 'CLI guard present');
    c8_assert(str_contains($cli, '--job='), 'CLI job-only');
    $api = (string) file_get_contents($projectRoot . '/admin/api/restore/country-dry-run-status.php');
    c8_assert(str_contains($api, 'restore_admin_api_require_get'), 'HTTP GET-only');
    c8_assert(!str_contains($api, 'orange_country_dry_run_execute('), 'HTTP does not execute dry-run');
    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    c8_assert(str_contains($ui, 'Country Dry Run (C8)'), 'Restore Center shows C8 panel');

    c8_assert(orange_country_dry_run_exit_code($safe) === 0, 'SAFE exit 0');
    c8_assert(orange_country_dry_run_exit_code(['overall_result' => 'FAIL']) === 1, 'FAIL exit 1');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    c8_clear_mocks();
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "C8 totals: pass={$passes} fail={$failures}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: C8 Country Dry Run self-tests passed.\n";
exit(0);
