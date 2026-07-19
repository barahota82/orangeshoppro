<?php

declare(strict_types=1);

/**
 * Phase C4 — Country Recovery Package verify self-tests (fixtures; no restore).
 *
 * Usage:
 *   php scripts/backup/self_test_country_crp_c4_verify.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_boundary_matrix_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_crp_verify.php';

$failures = 0;

function c4_assert(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

function c4_assert_has_code(array $result, string $code, string $label): void
{
    c4_assert(in_array($code, $result['codes'], true), $label . ' (code=' . $code . ')');
}

/**
 * @param array{
 *   country_id?:int,
 *   sql_extra?:string,
 *   inventory_tables?:array<string,int>,
 *   id_tables?:array<string,list<int>>,
 *   health_country_id?:int,
 *   inventory_country_id?:int,
 *   fingerprint_override?:string,
 *   omit_restore_batches?:bool,
 *   wrong_batches?:bool,
 *   upload_entries?:list<string>,
 *   other_country_markers?:list<string>,
 *   break_manifest_json?:bool,
 *   omit_fingerprint?:bool,
 *   node_batch_override?:array{table:string,restore_batch:int}
 * } $opts
 */
function c4_build_fixture(string $projectRoot, string $dir, array $opts = []): void
{
    if (is_dir($dir)) {
        orange_backup_remove_dir($dir);
    }
    if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create fixture dir');
    }
    mkdir($dir . DIRECTORY_SEPARATOR . 'sql', 0777, true);
    mkdir($dir . DIRECTORY_SEPARATOR . 'files', 0777, true);

    $countryId = (int) ($opts['country_id'] ?? 1);
    $matrix = orange_country_boundary_matrix_load($projectRoot);
    $registry = orange_backup_registry_load($projectRoot);
    $batches = orange_country_boundary_matrix_restore_batches($matrix);
    if (!empty($opts['wrong_batches'])) {
        $batches = [1 => ['admins'], 2 => ['orders']];
    }
    if (!empty($opts['omit_restore_batches'])) {
        $batchesForManifest = null;
    } else {
        $batchesForManifest = $batches;
    }

    $dep = orange_country_export_build_dependency_graph_c3($matrix, $registry, $batches);
    if (isset($opts['node_batch_override']) && is_array($opts['node_batch_override'])) {
        $t = (string) $opts['node_batch_override']['table'];
        $b = (int) $opts['node_batch_override']['restore_batch'];
        foreach ($dep['nodes'] as $i => $node) {
            if (($node['table'] ?? '') === $t) {
                $dep['nodes'][$i]['restore_batch'] = $b;
            }
        }
    }
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'dependency_graph.json', $dep);

    $sqlBody = "-- Orange CRP export table=orders country_id={$countryId}\n"
        . "INSERT INTO `orders` (`id`,`country_id`) VALUES (1,{$countryId});\n";
    if (isset($opts['sql_extra'])) {
        $sqlBody .= (string) $opts['sql_extra'];
    }
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '001_orders.sql', $sqlBody);

    if (!function_exists('gzopen')) {
        throw new RuntimeException('gzopen required for C4 fixtures');
    }
    $gz = gzopen($dir . DIRECTORY_SEPARATOR . 'country.sql.gz', 'wb9');
    if ($gz === false) {
        throw new RuntimeException('Cannot write country.sql.gz');
    }
    gzwrite($gz, $sqlBody);
    gzclose($gz);

    $zipPath = $dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip';
    $entries = $opts['upload_entries'] ?? [];
    if ($entries === []) {
        orange_country_uploads_write_empty_zip($zipPath);
    } else {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create uploads zip');
        }
        foreach ($entries as $entry) {
            $zip->addFromString($entry, 'x');
        }
        $zip->close();
    }

    $inventoryTables = $opts['inventory_tables'] ?? ['orders' => 1];
    $inventory = [
        'country_id' => (int) ($opts['inventory_country_id'] ?? $countryId),
        'tables' => $inventoryTables,
        'other_country_markers' => $opts['other_country_markers'] ?? [],
        'ownership_summary' => [],
        'classification_summary' => [],
    ];
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'table_inventory.json', $inventory);

    $idSnapshot = [
        'country_id' => $countryId,
        'tables' => $opts['id_tables'] ?? ['orders' => [1]],
    ];
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'id_snapshot.json', $idSnapshot);

    $health = [
        'package_status' => 'healthy',
        'country_id' => (int) ($opts['health_country_id'] ?? $countryId),
        'schema_revision' => (int) ($matrix['schema_revision'] ?? 121),
    ];
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', $health);

    $exportTime = gmdate('c');
    $manifest = [
        'package_type' => 'country_recovery',
        'package_version' => ORANGE_COUNTRY_EXPORT_PACKAGE_VERSION,
        'generated_at' => $exportTime,
        'export_time' => $exportTime,
        'country_id' => $countryId,
        'country_code' => 'kw',
        'schema_revision' => (int) ($matrix['schema_revision'] ?? 121),
        'boundary_policy_version' => ORANGE_COUNTRY_BOUNDARY_POLICY_VERSION,
        'dependency_graph_version' => ORANGE_COUNTRY_DEPENDENCY_GRAPH_VERSION,
        'drv_version' => ORANGE_COUNTRY_EXPORT_DRV_VERSION,
        'verify_version' => ORANGE_COUNTRY_EXPORT_VERIFY_VERSION,
        'restore_batches' => $batchesForManifest,
        'package_fingerprint' => '',
    ];
    if (!empty($opts['omit_fingerprint'])) {
        unset($manifest['package_fingerprint']);
    }
    if ($batchesForManifest === null) {
        unset($manifest['restore_batches']);
    }

    if (!empty($opts['break_manifest_json'])) {
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'manifest.json', '{not-json');
        orange_backup_write_checksums($dir, orange_backup_collect_package_files($dir));
        return;
    }

    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    $fp = orange_crp_export_package_fingerprint($dir, $manifest);
    if (isset($opts['fingerprint_override'])) {
        $fp = (string) $opts['fingerprint_override'];
    }
    $manifest['package_fingerprint'] = $fp;
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    orange_backup_write_checksums($dir, orange_backup_collect_package_files($dir));
}

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_c4_verify_' . getmypid();
if (!is_dir($base) && !mkdir($base, 0777, true) && !is_dir($base)) {
    fwrite(STDERR, "Cannot create temp base\n");
    exit(1);
}

try {
    // --- PASS baseline ---
    $passDir = $base . DIRECTORY_SEPARATOR . 'pass';
    c4_build_fixture($projectRoot, $passDir);
    $pass = orange_crp_verify_run($passDir, ['write_report' => true, 'project_root' => $projectRoot]);
    c4_assert($pass['overall'] === 'PASS', 'baseline overall PASS');
    c4_assert(is_file($passDir . DIRECTORY_SEPARATOR . 'country_verify_report.json'), 'writes country_verify_report.json');
    $report = json_decode((string) file_get_contents($passDir . DIRECTORY_SEPARATOR . 'country_verify_report.json'), true);
    c4_assert(is_array($report) && ($report['report_type'] ?? '') === 'country_recovery_verify', 'report schema report_type');
    c4_assert(($report['verify_engine_version'] ?? '') === ORANGE_CRP_VERIFY_ENGINE_VERSION, 'report verify_engine_version');

    // --- Broken manifest ---
    $d = $base . DIRECTORY_SEPARATOR . 'broken_manifest';
    c4_build_fixture($projectRoot, $d, ['break_manifest_json' => true]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert($r['overall'] === 'FAIL', 'broken manifest overall FAIL');
    c4_assert_has_code($r, 'manifest_invalid_json', 'broken manifest');

    // --- Wrong country (health) ---
    $d = $base . DIRECTORY_SEPARATOR . 'wrong_country';
    c4_build_fixture($projectRoot, $d, ['health_country_id' => 99]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'country_id_mismatch_health', 'wrong country health');

    // --- Cross-country leakage ---
    $d = $base . DIRECTORY_SEPARATOR . 'leakage';
    c4_build_fixture($projectRoot, $d, [
        'country_id' => 1,
        'sql_extra' => "-- Orange CRP export table=customers country_id=9\nINSERT INTO `customers` (`id`,`country_id`) VALUES (1,9);\n",
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'cross_country_leakage_in_sql', 'cross-country leakage');

    // --- Missing dependency batches ---
    $d = $base . DIRECTORY_SEPARATOR . 'missing_dep';
    c4_build_fixture($projectRoot, $d, ['omit_restore_batches' => true]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'dependency_batch_missing', 'missing dependency batches');
    c4_assert_has_code($r, 'manifest_field_missing_restore_batches', 'missing restore_batches field');

    // --- Wrong fingerprint ---
    $d = $base . DIRECTORY_SEPARATOR . 'wrong_fp';
    c4_build_fixture($projectRoot, $d, [
        'fingerprint_override' => str_repeat('a', 64),
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'package_fingerprint_mismatch', 'wrong fingerprint');

    // --- Forbidden table ---
    $d = $base . DIRECTORY_SEPARATOR . 'forbidden';
    c4_build_fixture($projectRoot, $d, [
        'sql_extra' => "INSERT INTO `journal_entries` (`id`) VALUES (1);\n",
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'forbidden_table_in_sql', 'forbidden table');

    // --- NULL leakage ---
    $d = $base . DIRECTORY_SEPARATOR . 'null_rows';
    c4_build_fixture($projectRoot, $d, [
        'sql_extra' => "SELECT * FROM orders WHERE country_id IS NULL;\n",
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'null_leakage_in_sql', 'NULL rows');

    // --- Composite mismatch ---
    $d = $base . DIRECTORY_SEPARATOR . 'composite';
    c4_build_fixture($projectRoot, $d, [
        'inventory_tables' => ['orders' => 1, 'admin_permissions' => 3],
        'id_tables' => ['orders' => [1], 'admins' => []],
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'composite_admins_permissions_mismatch', 'composite mismatch');

    // --- Sequence violation ---
    $d = $base . DIRECTORY_SEPARATOR . 'sequence';
    c4_build_fixture($projectRoot, $d, [
        'sql_extra' => "INSERT INTO `document_sequences` (`scope`,`value`) VALUES ('invoice',1);\n",
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'sequence_namespace_violation', 'sequence violation');

    // --- Uploads mismatch ---
    $d = $base . DIRECTORY_SEPARATOR . 'uploads';
    c4_build_fixture($projectRoot, $d, [
        'upload_entries' => ['uploads/not_allowed/secret.bin'],
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'uploads_allowlist_violation', 'uploads mismatch');

    // --- Dependency batch mismatch / sequence violation of batch order ---
    $d = $base . DIRECTORY_SEPARATOR . 'batch_mismatch';
    c4_build_fixture($projectRoot, $d, ['wrong_batches' => true]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'dependency_batch_mismatch', 'dependency batch mismatch');

    // --- Dependency order violation (node batch vs restore_batches) ---
    $d = $base . DIRECTORY_SEPARATOR . 'order_violation';
    c4_build_fixture($projectRoot, $d, [
        'node_batch_override' => ['table' => 'orders', 'restore_batch' => 99],
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'dependency_order_violation', 'sequence/order violation on nodes');

    // --- Inventory forbidden table ---
    $d = $base . DIRECTORY_SEPARATOR . 'inv_forbidden';
    c4_build_fixture($projectRoot, $d, [
        'inventory_tables' => ['orders' => 1, 'journal_entries' => 2],
    ]);
    $r = orange_crp_verify_run($d, ['write_report' => false, 'project_root' => $projectRoot]);
    c4_assert_has_code($r, 'inventory_forbidden_table_present', 'inventory forbidden table');

} catch (Throwable $e) {
    echo 'FAIL: exception ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if (is_dir($base)) {
        orange_backup_remove_dir($base);
    }
}

if ($failures > 0) {
    echo "FAIL count={$failures}\n";
    exit(1);
}

echo "OK: C4 verify self-tests passed.\n";
exit(0);
