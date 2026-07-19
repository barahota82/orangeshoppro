<?php

declare(strict_types=1);

/**
 * Phase C7 — Country Shadow Verification self-tests (fixtures / inject only).
 * Never touches production. Does not re-import C6.
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c7_shadow_verify.php
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

$failures = 0;
$passes = 0;

function c7_assert(bool $ok, string $label): void
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

function c7_has(array $result, string $code): bool
{
    return in_array($code, $result['blocking_reason_codes'] ?? [], true)
        || in_array($code, $result['report']['blocking_reason_codes'] ?? [], true);
}

/**
 * @param array<string, mixed> $opts
 */
function c7_build_crp(string $projectRoot, string $dir, array $opts = []): void
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

function c7_install_c6_mocks(): void
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
    // Live probe matching baselines + EA-03 pillar oks (fixture override).
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        return c7_probe_ok();
    };
}

/** @return array<string, mixed> */
function c7_probe_ok(): array
{
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
        'dependency_ok' => true,
        'commercial_ok' => true,
        'catalog_ok' => true,
        'sequences_ok' => true,
        'uploads_ok' => true,
        'id_preservation_ok' => true,
        'schema_ok' => true,
        'documents_ok' => true,
        'accounting_codes' => [],
        'stock_fifo_codes' => [],
        'composite_codes' => [],
        'dependency_codes' => [],
        'commercial_codes' => [],
        'catalog_codes' => [],
        'sequences_codes' => [],
        'uploads_codes' => [],
        'id_preservation_codes' => [],
        'schema_codes' => [],
        'documents_codes' => [],
    ];
}

function c7_clear_mocks(): void
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
        $GLOBALS['orange_country_shadow_lock_override']
    );
}

/**
 * @param array<string, mixed> $inject
 * @return array<string, mixed>
 */
function c7_run_verify(
    string $projectRoot,
    string $backupRoot,
    string $workRoot,
    string $jobId,
    array $inject = []
): array {
    return orange_country_shadow_verify_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'job_id' => $jobId,
        'inject' => $inject,
    ]);
}

$base = sys_get_temp_dir() . '/orange_c7_shadow_' . getmypid();
$backupRoot = $base . '/backup';
$workRoot = $base . '/work';
$pkgId = '2026-07-19_191500';
$pkgDir = $backupRoot . '/country_packages/kw/' . $pkgId;
mkdir($pkgDir, 0777, true);
mkdir($workRoot, 0777, true);

try {
    c7_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country production restore remains disabled');

    c7_build_crp($projectRoot, $pkgDir);
    c7_install_c6_mocks();

    $c6 = orange_country_shadow_run([
        'project_root' => $projectRoot,
        'backup_root' => $backupRoot,
        'work_root' => $workRoot,
        'package_id' => $pkgId,
        'country_code' => 'kw',
    ]);
    c7_assert(!empty($c6['ok']), 'C6 fixture restore ready for C7');
    $jobId = (string) ($c6['run_id'] ?? '');
    c7_assert($jobId !== '', 'C6 run_id present');
    $runDir = orange_country_shadow_run_dir($workRoot, $jobId);
    c7_assert(is_file($runDir . '/survivor_baseline.json'), 'C6 wrote survivor baseline');
    c7_assert(is_file($runDir . '/global_baseline.json'), 'C6 wrote global baseline');

    // Clean shadow → READY
    $ready = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId);
    c7_assert(($ready['overall_result'] ?? '') === 'READY', 'clean shadow → READY');
    c7_assert((int) ($ready['readiness_score'] ?? 0) >= 90, 'READY score >= 90');
    c7_assert(($ready['status'] ?? '') === ORANGE_COUNTRY_SHADOW_STATUS_VERIFIED, 'status country_shadow_verified');
    $rep = $ready['report'] ?? [];
    c7_assert(($rep['target_country_integrity'] ?? '') === 'PASS', 'target_country_integrity PASS');
    c7_assert(($rep['survivor_country_integrity'] ?? '') === 'PASS', 'survivor_country_integrity PASS');
    c7_assert(($rep['global_state_integrity'] ?? '') === 'PASS', 'global_state_integrity PASS');
    c7_assert(($rep['production_db_writes'] ?? 1) === 0, 'production_db_writes always 0');
    c7_assert(($rep['execution_performed'] ?? true) === false, 'execution_performed always false');
    c7_assert(($rep['country_production_restore_enabled'] ?? true) === false, 'production restore disabled in report');
    c7_assert(is_file($runDir . '/' . ORANGE_COUNTRY_SHADOW_VERIFY_REPORT_FILE), 'writes verification report');

    // Wrong shadow DB identity
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['wrong_shadow_db' => true]);
    c7_assert(c7_has($r, 'wrong_shadow_db_identity'), 'wrong shadow DB identity');

    // Production DB identity rejected
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['production_identity' => true]);
    c7_assert(c7_has($r, 'production_db_identity_rejected'), 'production DB identity rejected');

    // Missing C6 report
    $badJob = 'kw_2099-01-01_000001';
    $badDir = orange_country_shadow_run_dir($workRoot, $badJob);
    mkdir($badDir, 0777, true);
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $badJob);
    c7_assert(c7_has($r, 'c6_report_missing'), 'missing C6 report');

    // Package fingerprint drift
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['fingerprint_drift' => true]);
    c7_assert(c7_has($r, 'package_fingerprint_changed'), 'package fingerprint drift');

    // Target-country row missing
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['target_row_missing' => true]);
    c7_assert(c7_has($r, 'target_country_row_missing'), 'target-country row missing');

    // Cross-country row inserted
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['cross_country_row' => true]);
    c7_assert(c7_has($r, 'cross_country_row_inserted'), 'cross-country row inserted');

    // Survivor-country row deleted
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['survivor_deleted' => true]);
    c7_assert(c7_has($r, 'survivor_country_row_deleted'), 'survivor-country row deleted');

    // Survivor-country row modified (inject current ≠ baseline; probe still supplies global)
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, [
        'survivor_current' => [
            'orders' => ['count' => 2, 'hash' => 'tampered'],
        ],
        'global_current' => [
            'journal_entries' => ['count' => 0, 'hash' => hash('sha256', 'je0')],
            'product_taxonomy_nodes' => ['count' => 3, 'hash' => hash('sha256', 'tax3')],
        ],
    ]);
    c7_assert(c7_has($r, 'survivor_country_row_modified'), 'survivor-country row modified');

    // F-01: missing live currents → refuse tautological PASS
    $prevProbe = $GLOBALS['orange_country_shadow_c7_probe_override'] ?? null;
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        return ['probe_mode' => 'override']; // no survivor_current / global_current
    };
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId);
    c7_assert(
        c7_has($r, 'survivor_probe_unavailable') || c7_has($r, 'global_probe_unavailable'),
        'F-01 refuses PASS without live probe currents'
    );
    $GLOBALS['orange_country_shadow_c7_probe_override'] = $prevProbe;

    // F-03 / EA-02: live SQL accounting failure via probe codes
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        $p = c7_probe_ok();
        $p['accounting_ok'] = false;
        $p['accounting_codes'] = ['gl_graph_unbalanced'];

        return $p;
    };
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId);
    c7_assert(c7_has($r, 'gl_graph_unbalanced'), 'F-03 live SQL accounting code fails C7');
    $GLOBALS['orange_country_shadow_c7_probe_override'] = $prevProbe;

    // EA-03: dependency live failure
    $GLOBALS['orange_country_shadow_c7_probe_override'] = static function () {
        $p = c7_probe_ok();
        $p['dependency_ok'] = false;
        $p['dependency_codes'] = ['missing_dependency_parent'];

        return $p;
    };
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId);
    c7_assert(c7_has($r, 'missing_dependency_parent'), 'EA-03 live dependency failure');
    $GLOBALS['orange_country_shadow_c7_probe_override'] = $prevProbe;

    // Global table changed
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['global_changed' => true]);
    c7_assert(c7_has($r, 'global_table_changed'), 'Global table changed');

    // journal_entries changed
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['journal_entries_changed' => true]);
    c7_assert(c7_has($r, 'journal_entries_changed'), 'journal_entries changed');

    // Missing dependency parent
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['missing_parent' => true]);
    c7_assert(c7_has($r, 'missing_dependency_parent'), 'missing dependency parent');

    // Cross-country FK
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['cross_country_fk' => true]);
    c7_assert(c7_has($r, 'cross_country_fk'), 'cross-country FK');

    // Incomplete admin composite
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['incomplete_admin_composite' => true]);
    c7_assert(c7_has($r, 'incomplete_admin_composite'), 'incomplete admin composite');

    // Global admin changed
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['global_admin_changed' => true]);
    c7_assert(c7_has($r, 'global_admin_changed'), 'global admin changed');

    // Incomplete GL composite
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['incomplete_gl_composite' => true]);
    c7_assert(c7_has($r, 'incomplete_gl_composite'), 'incomplete GL composite');

    // GL imbalance
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['gl_imbalance' => true]);
    c7_assert(c7_has($r, 'gl_graph_unbalanced'), 'GL imbalance');

    // Missing account
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['missing_account' => true]);
    c7_assert(c7_has($r, 'missing_account'), 'missing account');

    // Warehouse ownership mismatch
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['warehouse_ownership_mismatch' => true]);
    c7_assert(c7_has($r, 'stock_warehouse_ownership_mismatch'), 'warehouse ownership mismatch');

    // Stock movement leakage
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['stock_movement_leakage' => true]);
    c7_assert(c7_has($r, 'stock_movement_leakage'), 'stock movement leakage');

    // Incomplete FIFO graph
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['incomplete_fifo' => true]);
    c7_assert(c7_has($r, 'incomplete_fifo_graph'), 'incomplete FIFO graph');

    // FIFO over-consumption
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['fifo_overconsumption' => true]);
    c7_assert(c7_has($r, 'fifo_layer_overconsumed'), 'FIFO over-consumption');

    // Missing order item
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['missing_order_item' => true]);
    c7_assert(c7_has($r, 'missing_order_item'), 'missing order item');

    // Payment orphan
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['payment_orphan' => true]);
    c7_assert(c7_has($r, 'payment_orphan'), 'payment orphan');

    // Global taxonomy mutation
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['taxonomy_mutation' => true]);
    c7_assert(c7_has($r, 'global_taxonomy_mutation'), 'Global taxonomy mutation');

    // Product collision
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['product_collision' => true]);
    c7_assert(c7_has($r, 'product_collision'), 'product collision');

    // Unknown polymorphic document owner
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['unknown_document_owner' => true]);
    c7_assert(c7_has($r, 'unknown_polymorphic_document_owner'), 'unknown polymorphic document owner');

    // Document owned by another country
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['document_other_country' => true]);
    c7_assert(c7_has($r, 'document_owned_by_another_country'), 'document owned by another country');

    // Sequence lowered
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['sequence_lowered' => true]);
    c7_assert(c7_has($r, 'sequence_lowered'), 'sequence lowered');

    // Sequence namespace collision
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['sequence_namespace_collision' => true]);
    c7_assert(c7_has($r, 'sequence_namespace_collision'), 'sequence namespace collision');

    // Missing upload reference
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['missing_upload_reference' => true]);
    c7_assert(c7_has($r, 'missing_upload_reference'), 'missing upload reference');

    // Upload owner mismatch
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['upload_owner_mismatch' => true]);
    c7_assert(c7_has($r, 'upload_owner_mismatch'), 'upload owner mismatch');

    // PK collision
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['pk_collision' => true]);
    c7_assert(c7_has($r, 'pk_collision'), 'PK collision');

    // AUTO_INCREMENT too low
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['auto_increment_too_low' => true]);
    c7_assert(c7_has($r, 'auto_increment_too_low'), 'AUTO_INCREMENT too low');

    // Schema mismatch
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['schema_mismatch' => true]);
    c7_assert(c7_has($r, 'schema_mismatch'), 'schema mismatch');

    // WARNING-only result
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, ['warning_only' => true]);
    c7_assert(($r['overall_result'] ?? '') === 'WARNING', 'WARNING-only result');
    c7_assert((int) ($r['readiness_score'] ?? 0) >= 75 && (int) ($r['readiness_score'] ?? 0) < 90, 'WARNING score 75–89');
    c7_assert(($r['status'] ?? '') === ORANGE_COUNTRY_SHADOW_STATUS_WARNING, 'status country_shadow_warning');

    // Report redaction
    $r = c7_run_verify($projectRoot, $backupRoot, $workRoot, $jobId, [
        'warning_only' => true,
    ]);
    $json = json_encode($r['report'] ?? [], JSON_UNESCAPED_UNICODE);
    c7_assert($json !== false && !str_contains($json, 'password='), 'report redaction (no credentials)');
    c7_assert(!isset($r['report']['package_path']) && !isset($r['report']['absolute_paths']), 'report omits absolute paths');

    // Status helper
    $st = orange_country_shadow_verify_status($workRoot, $jobId);
    c7_assert(!empty($st['verify_available']), 'verify status available');
    c7_assert(!empty($st['read_only']), 'verify status read_only');
    c7_assert(($st['execution_performed'] ?? true) === false, 'status execution_performed false');
    c7_assert(($st['production_db_writes'] ?? 1) === 0, 'status production_db_writes 0');

    // CLI / API guards
    $cli = (string) file_get_contents($projectRoot . '/scripts/backup/verify_country_shadow.php');
    c7_assert(str_contains($cli, "PHP_SAPI !== 'cli'"), 'CLI guard present');
    c7_assert(str_contains($cli, '--job='), 'CLI job-only');
    $api = (string) file_get_contents($projectRoot . '/admin/api/restore/country-shadow-verify-status.php');
    c7_assert(str_contains($api, 'restore_admin_api_require_get'), 'HTTP GET-only verify status');
    c7_assert(!str_contains($api, 'orange_country_shadow_verify_run('), 'HTTP does not execute verify');
    $ui = (string) file_get_contents($projectRoot . '/admin/pages/restore_center.php');
    c7_assert(str_contains($ui, 'Country Shadow Verification'), 'Restore Center shows C7 panel');
    c7_assert(
        !str_contains($ui, 'Enable Country Production Restore')
        && !str_contains($ui, 'country_production_restore_run('),
        'UI has no production enablement control'
    );

    // Exit code helper
    c7_assert(orange_country_shadow_verify_exit_code($ready) === 0, 'READY exit code 0');
    c7_assert(orange_country_shadow_verify_exit_code(['overall_result' => 'FAIL', 'readiness_score' => 10]) === 1, 'FAIL exit code 1');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    c7_clear_mocks();
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "C7 totals: pass={$passes} fail={$failures}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: C7 Country Shadow Verification self-tests passed.\n";
exit(0);
