<?php

declare(strict_types=1);

/**
 * Phase C5 — Country DRV self-tests (fixtures only; no restore).
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c5_drv.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/backup/backup_paths.php';
require_once $projectRoot . '/includes/backup/backup_manifest.php';
require_once $projectRoot . '/includes/backup/backup_table_registry_lib.php';
require_once $projectRoot . '/includes/backup/country_boundary_matrix_lib.php';
require_once $projectRoot . '/includes/backup/country_export.php';
require_once $projectRoot . '/includes/backup/uploads_collector.php';
require_once $projectRoot . '/includes/backup/country_crp_verify.php';
require_once $projectRoot . '/includes/backup/country_crp_drv.php';

$failures = 0;
$passCount = 0;

function c5_assert(bool $ok, string $label): void
{
    global $failures, $passCount;
    if ($ok) {
        echo "PASS: {$label}\n";
        $passCount++;
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function c5_has_blocker(array $result, string $code): bool
{
    return in_array($code, $result['blocking_reason_codes'] ?? [], true)
        || in_array($code, $result['report']['errors'] ?? [], true);
}

/**
 * @param array<string, mixed> $opts
 */
function c5_build_package(string $projectRoot, string $dir, array $opts = []): void
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
    if (!empty($opts['sql_extra'])) {
        $sqlBody .= (string) $opts['sql_extra'];
    }
    file_put_contents($dir . '/sql/001_orders.sql', $sqlBody);
    $gz = gzopen($dir . '/country.sql.gz', 'wb9');
    gzwrite($gz, $sqlBody);
    gzclose($gz);

    $zipPath = $dir . '/files/uploads_country.zip';
    if (!empty($opts['upload_entries']) && is_array($opts['upload_entries'])) {
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($opts['upload_entries'] as $entry) {
            $zip->addFromString((string) $entry, 'x');
        }
        $zip->close();
    } else {
        orange_country_uploads_write_empty_zip($zipPath);
    }

    $inventoryTables = $opts['inventory_tables'] ?? ['orders' => 1];
    orange_backup_write_json($dir . '/table_inventory.json', [
        'country_id' => $countryId,
        'tables' => $inventoryTables,
        'other_country_markers' => $opts['other_country_markers'] ?? [],
    ]);
    orange_backup_write_json($dir . '/id_snapshot.json', [
        'country_id' => $countryId,
        'tables' => $opts['id_tables'] ?? ['orders' => [1]],
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
        'schema_revision' => (int) ($opts['schema_revision'] ?? 121),
        'boundary_policy_version' => $opts['boundary_policy_version'] ?? ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => $opts['dependency_graph_version'] ?? ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
        'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
        'export_backend' => $opts['export_backend'] ?? ORANGE_COUNTRY_EXPORT_BACKEND,
        'registry_version' => '1.0',
        'restore_batches' => $batches,
        'package_fingerprint' => '',
    ];
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    $manifest['package_fingerprint'] = orange_crp_export_package_fingerprint($dir, $manifest);
    orange_backup_write_json($dir . '/manifest.json', $manifest);
    orange_backup_write_checksums($dir, orange_backup_collect_package_files($dir));

    if (!empty($opts['skip_verify'])) {
        return;
    }
    if (!empty($opts['verify_fail_report'])) {
        orange_backup_write_json($dir . '/country_verify_report.json', [
            'report_type' => 'country_recovery_verify',
            'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
            'overall' => 'FAIL',
            'ok' => false,
            'codes' => ['checksum_mismatch'],
            'country_id' => $countryId,
            'schema_revision' => 121,
            'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
            'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
            'package_fingerprint' => $manifest['package_fingerprint'],
        ]);
        return;
    }
    orange_crp_verify_run($dir, ['write_report' => true, 'project_root' => $projectRoot]);
}

$base = sys_get_temp_dir() . '/orange_c5_drv_' . getmypid();
mkdir($base, 0777, true);
$pkgId = '2026-07-19_160000';

try {
    // valid CRP passes
    $passDir = $base . '/' . $pkgId;
    c5_build_package($projectRoot, $passDir);
    $r = orange_country_drv_run($passDir, ['project_root' => $projectRoot, 'package_id' => $pkgId, 'write_report' => true]);
    c5_assert(($r['overall_result'] ?? '') === 'pass', 'valid CRP passes');
    c5_assert(($r['report']['execution_performed'] ?? true) === false, 'execution_performed always false');
    c5_assert(ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED === false, 'Country restore remains disabled');
    c5_assert(is_file($base . '/' . $pkgId . '.country_recovery_validation.json'), 'writes sibling Country DRV report');
    $reportJson = (string) file_get_contents($base . '/' . $pkgId . '.country_recovery_validation.json');
    c5_assert(!str_contains($reportJson, 'D:\\') && !str_contains($reportJson, 'INSERT INTO'), 'report redaction');

    // missing Verify
    $d = $base . '/2026-07-19_160001';
    c5_build_package($projectRoot, $d, ['skip_verify' => true]);
    $r = orange_country_drv_run($d, ['project_root' => $projectRoot, 'package_id' => '2026-07-19_160001', 'write_report' => false]);
    c5_assert(c5_has_blocker($r, 'verify_report_missing'), 'missing Verify rejected');

    // Verify FAIL
    $d = $base . '/2026-07-19_160002';
    c5_build_package($projectRoot, $d, ['verify_fail_report' => true]);
    $r = orange_country_drv_run($d, ['project_root' => $projectRoot, 'package_id' => '2026-07-19_160002', 'write_report' => false]);
    c5_assert(c5_has_blocker($r, 'verify_overall_fail'), 'Verify FAIL rejected');

    // fingerprint changed after Verify (fingerprint covers country.sql.gz)
    $d = $base . '/2026-07-19_160003';
    c5_build_package($projectRoot, $d);
    $gz = gzopen($d . '/country.sql.gz', 'wb9');
    gzwrite($gz, "-- tampered after verify\n");
    gzclose($gz);
    $r = orange_country_drv_run($d, ['project_root' => $projectRoot, 'package_id' => '2026-07-19_160003', 'write_report' => false]);
    c5_assert(c5_has_blocker($r, 'package_fingerprint_changed_after_verify'), 'fingerprint changed after Verify');

    // cross-country row leakage (force Verify PASS so DRV isolation check owns the blocker)
    $d = $base . '/2026-07-19_160004';
    c5_build_package($projectRoot, $d, [
        'sql_extra' => "-- Orange CRP export table=customers country_id=9\nINSERT INTO `customers` (`id`,`country_id`) VALUES (1,9);\n",
        'skip_verify' => true,
    ]);
    $mani = json_decode((string) file_get_contents($d . '/manifest.json'), true);
    orange_backup_write_json($d . '/country_verify_report.json', [
        'report_type' => 'country_recovery_verify',
        'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
        'overall' => 'PASS',
        'ok' => true,
        'codes' => [],
        'country_id' => 1,
        'schema_revision' => 121,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'package_fingerprint' => $mani['package_fingerprint'] ?? '',
    ]);
    $r = orange_country_drv_run($d, ['project_root' => $projectRoot, 'package_id' => '2026-07-19_160004', 'write_report' => false]);
    c5_assert(c5_has_blocker($r, 'cross_country_row_leakage'), 'cross-country row leakage');

    // NULL ownership
    $d = $base . '/2026-07-19_160005';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160005',
        'write_report' => false,
        'inject' => ['null_ownership' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'null_ownership_leakage'), 'NULL ownership leakage');

    // Global table inclusion
    $d = $base . '/2026-07-19_160006';
    c5_build_package($projectRoot, $d, ['inventory_tables' => ['orders' => 1, 'countries' => 1]]);
    // countries may not be in matrix as exportable — inventory_unknown / global
    orange_crp_verify_run($d, ['write_report' => true, 'project_root' => $projectRoot]); // may fail verify
    // Force verify pass report for DRV entry then inject global via inventory already present
    $mani = json_decode((string) file_get_contents($d . '/manifest.json'), true);
    orange_backup_write_json($d . '/country_verify_report.json', [
        'report_type' => 'country_recovery_verify',
        'verify_engine_version' => ORANGE_CRP_VERIFY_ENGINE_VERSION,
        'overall' => 'PASS',
        'ok' => true,
        'codes' => [],
        'country_id' => 1,
        'schema_revision' => 121,
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'package_fingerprint' => $mani['package_fingerprint'] ?? '',
    ]);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160006',
        'write_report' => false,
        'inject' => ['inventory_tables' => ['orders' => 1, 'countries' => 1]],
    ]);
    c5_assert(c5_has_blocker($r, 'global_table_included'), 'Global table inclusion');

    // Full-only table inclusion
    $d = $base . '/2026-07-19_160007';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160007',
        'write_report' => false,
        'inject' => ['inventory_tables' => ['orders' => 1, 'journal_entries' => 2]],
    ]);
    c5_assert(
        c5_has_blocker($r, 'full_only_or_global_table_included') || c5_has_blocker($r, 'accounting_boundary_not_proven'),
        'Full-only table inclusion'
    );

    // missing parent dependency
    $d = $base . '/2026-07-19_160008';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160008',
        'write_report' => false,
        'inject' => ['missing_parent' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'missing_parent_dependency'), 'missing parent dependency');

    // missing composite member
    $d = $base . '/2026-07-19_160009';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160009',
        'write_report' => false,
        'inject' => ['missing_composite' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'missing_composite_member'), 'missing composite member');

    // PK collision
    $d = $base . '/2026-07-19_160010';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160010',
        'write_report' => false,
        'survivor_index' => ['orders' => [1]],
    ]);
    c5_assert(c5_has_blocker($r, 'pk_collision_unresolved'), 'PK collision');

    // unique collision
    $d = $base . '/2026-07-19_160011';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160011',
        'write_report' => false,
        'inject' => ['unique_collision' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'unique_collision_unresolved'), 'unique collision');

    // admin collision
    $d = $base . '/2026-07-19_160012';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160012',
        'write_report' => false,
        'inject' => ['admin_collision' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'global_admin_collision'), 'admin collision');

    // sequence collision
    $d = $base . '/2026-07-19_160013';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160013',
        'write_report' => false,
        'inject' => ['sequence_collision' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'sequence_collision'), 'sequence collision');

    // accounting boundary failure
    $d = $base . '/2026-07-19_160014';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160014',
        'write_report' => false,
        'inject' => ['accounting_failure' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'accounting_boundary_not_proven'), 'accounting boundary failure');

    // unbalanced GL
    $d = $base . '/2026-07-19_160015';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160015',
        'write_report' => false,
        'inject' => ['unbalanced_gl' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'gl_graph_unbalanced'), 'unbalanced GL graph');

    // stock warehouse ownership mismatch
    $d = $base . '/2026-07-19_160016';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160016',
        'write_report' => false,
        'inject' => ['stock_warehouse_mismatch' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'stock_warehouse_ownership_mismatch'), 'stock warehouse ownership mismatch');

    // incomplete FIFO
    $d = $base . '/2026-07-19_160017';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160017',
        'write_report' => false,
        'inject' => ['incomplete_fifo' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'incomplete_fifo_graph'), 'incomplete FIFO graph');

    // over-consumed FIFO
    $d = $base . '/2026-07-19_160018';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160018',
        'write_report' => false,
        'inject' => ['overconsumed_fifo' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'fifo_layer_overconsumed'), 'over-consumed FIFO layer');

    // uploads owner mismatch
    $d = $base . '/2026-07-19_160019';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160019',
        'write_report' => false,
        'inject' => ['uploads_owner_mismatch' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'uploads_owner_mismatch'), 'uploads owner mismatch');

    // uploads path traversal
    $d = $base . '/2026-07-19_160020';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160020',
        'write_report' => false,
        'inject' => ['uploads_path_traversal' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'uploads_path_traversal'), 'uploads path traversal');

    // missing sequence metadata
    $d = $base . '/2026-07-19_160021';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160021',
        'write_report' => false,
        'inject' => ['missing_sequence_metadata' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'sequence_metadata_incomplete'), 'missing sequence metadata');

    // incompatible schema/version/backend
    $d = $base . '/2026-07-19_160022';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160022',
        'write_report' => false,
        'inject' => ['env_incompatible' => true],
    ]);
    c5_assert(c5_has_blocker($r, 'environment_incompatible'), 'incompatible environment');

    // warning-only package
    $d = $base . '/2026-07-19_160023';
    c5_build_package($projectRoot, $d);
    $r = orange_country_drv_run($d, [
        'project_root' => $projectRoot,
        'package_id' => '2026-07-19_160023',
        'write_report' => false,
        'inject' => ['legacy_mirror_diff' => true],
    ]);
    c5_assert(($r['overall_result'] ?? '') === 'warning', 'warning-only package');
    c5_assert((int) ($r['recovery_score'] ?? 0) >= 70 && (int) ($r['recovery_score'] ?? 0) < 85, 'warning score band');

    // package id allowlist
    $threw = false;
    try {
        orange_country_drv_assert_package_id('../evil');
    } catch (Throwable $e) {
        $threw = true;
    }
    c5_assert($threw, 'strict package-ID allowlist');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

echo "C5 totals: pass={$passCount} fail={$failures}\n";
if ($failures > 0) {
    exit(1);
}
echo "OK: C5 Country DRV self-tests passed.\n";
exit(0);
