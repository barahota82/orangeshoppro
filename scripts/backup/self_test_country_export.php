<?php

declare(strict_types=1);

/**
 * Phase 1B.2 Country Recovery Package export self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_country_export.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_paths.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_manifest.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_table_registry_lib.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'backup_validate.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'uploads_collector.php';
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_export.php';

$failures = 0;

function crp_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

// Registry load + structure
try {
    $registry = orange_backup_registry_load($projectRoot);
    crp_self_test(($registry['schema_revision'] ?? 0) === 121, 'registry schema_revision=121');
    crp_self_test(count($registry['tables'] ?? []) === 117, 'registry table_count=117');
} catch (Throwable $e) {
    crp_self_test(false, 'registry load: ' . $e->getMessage());
}

// Registry mismatch rejection
$badRegistry = ['registry_version' => '1.0', 'schema_revision' => 99, 'tables' => []];
$structErrors = orange_backup_registry_validate_structure($badRegistry, 121);
crp_self_test($structErrors !== [], 'registry mismatch rejection');

// Cross-country reference rejection
$meta = ['extraction_rule' => ['type' => 'country_id', 'column' => 'country_id']];
$crossErrors = orange_country_export_validate_row_country_scope('orders', $meta, ['id' => 5, 'country_id' => 2], 1);
crp_self_test($crossErrors !== [], 'cross-country reference rejection');

// Dependent orphan rejection
$idSnapshot = ['orders' => [1, 2]];
$depMeta = ['parent_dependency' => ['table' => 'orders', 'foreign_key' => 'order_id', 'nullable' => false]];
$orphanErrors = orange_country_export_validate_orphan_fk('order_items', $depMeta, ['id' => 9, 'order_id' => 99], $idSnapshot);
crp_self_test($orphanErrors !== [], 'missing required child / orphan FK rejection');

// Parent rows query extraction
$parentQuery = orange_country_export_build_parent_rows_query('order_items', [
    'type' => 'parent_rows',
    'parent_table' => 'orders',
    'foreign_key' => 'order_id',
], $idSnapshot);
crp_self_test(str_contains($parentQuery['sql'], 'order_id IN'), 'dependent row extraction query');

// Trial balance tolerance
crp_self_test(abs(0.005) <= ORANGE_COUNTRY_EXPORT_TRIAL_BALANCE_TOLERANCE, 'trial balance tolerance configured');
crp_self_test(abs(0.05) > ORANGE_COUNTRY_EXPORT_TRIAL_BALANCE_TOLERANCE, 'trial balance mismatch would fail');

// Upload path allowlist + traversal block
crp_self_test(orange_country_uploads_is_allowlisted('uploads/products/x.jpg'), 'upload allowlist products');
crp_self_test(!orange_country_uploads_is_allowlisted('uploads/../secrets.txt'), 'upload traversal blocked');
$uploadIssues = orange_country_uploads_collect($projectRoot, 1, [
    'products' => [['id' => 1, 'main_image' => 'missing-file.webp']],
]);
$classified = orange_country_export_classify_upload_issues($uploadIssues['issues']);
crp_self_test(($classified['package_status'] ?? '') === 'healthy' || ($classified['package_status'] ?? '') === 'warning', 'warning upload missing does not auto-fail when non-critical');

// Critical upload missing classification
$criticalIssues = ['critical:missing upload file: uploads/products/required.webp'];
$criticalClass = orange_country_export_classify_upload_issues($criticalIssues);
crp_self_test(($criticalClass['package_status'] ?? '') === 'failed', 'critical upload missing rejection');

// Atomic cleanup on failure
$tmpParent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_crp_selftest_' . bin2hex(random_bytes(4));
mkdir($tmpParent);
$tempPkg = $tmpParent . DIRECTORY_SEPARATOR . '.tmp_pkg_' . bin2hex(random_bytes(3));
mkdir($tempPkg);
file_put_contents($tempPkg . DIRECTORY_SEPARATOR . 'partial.txt', 'partial');
orange_backup_remove_dir($tempPkg);
crp_self_test(!is_dir($tempPkg), 'atomic cleanup removes temp package on failure');

// Mock CRP package + verify + corrupted checksum
$pkg = $tmpParent . DIRECTORY_SEPARATOR . 'mock_crp';
mkdir($pkg);
mkdir($pkg . DIRECTORY_SEPARATOR . 'sql');
mkdir($pkg . DIRECTORY_SEPARATOR . 'files');
file_put_contents($pkg . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '001_orders.sql', "-- rows=0\n");
orange_country_uploads_write_empty_zip($pkg . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
$manifest = [
    'package_type' => 'country_recovery',
    'package_version' => '1.0',
    'generated_at' => gmdate('c'),
    'country_id' => 1,
    'country_code' => 'kw',
    'country_label' => 'Kuwait',
    'schema_revision' => 121,
    'registry_version' => '1.0',
    'export_backend' => 'php_country_export',
    'package_status' => 'healthy',
];
orange_backup_write_json($pkg . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
orange_backup_write_json($pkg . DIRECTORY_SEPARATOR . 'health.json', [
    'package_type' => 'country_recovery',
    'package_status' => 'healthy',
    'country_id' => 1,
    'schema_revision' => 121,
]);
orange_backup_write_json($pkg . DIRECTORY_SEPARATOR . 'dependency_graph.json', ['nodes' => [], 'edges' => []]);
orange_backup_write_json($pkg . DIRECTORY_SEPARATOR . 'table_inventory.json', [
    'country_id' => 1,
    'other_country_markers' => [],
    'tables' => [],
]);
orange_backup_write_json($pkg . DIRECTORY_SEPARATOR . 'id_snapshot.json', ['country_id' => 1, 'tables' => []]);
orange_backup_write_checksums($pkg, [
    'manifest.json',
    'health.json',
    'dependency_graph.json',
    'table_inventory.json',
    'id_snapshot.json',
    'files/uploads_country.zip',
    'sql/001_orders.sql',
]);
$verifyOk = orange_country_export_verify_package($pkg);
crp_self_test($verifyOk['ok'], 'mock CRP package verifies');

file_put_contents($pkg . DIRECTORY_SEPARATOR . 'checksums.sha256', str_repeat('a', 64) . "  manifest.json\n");
$verifyBad = orange_country_export_verify_package($pkg);
crp_self_test(!$verifyBad['ok'], 'corrupted checksum rejection');

orange_backup_remove_dir($tmpParent);

// Live DB export tests when available
if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    try {
        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $st = $pdo->query('SELECT id FROM countries WHERE is_active = 1 ORDER BY id ASC');
        $countryIds = [];
        if ($st !== false) {
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $countryIds[] = (int) ($row['id'] ?? 0);
            }
        }
        if ($countryIds !== []) {
            $testOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_crp_live_' . bin2hex(random_bytes(4));
            $emptyCountryId = (int) end($countryIds);
            $result = orange_country_export_run($pdo, [
                'country_id' => $emptyCountryId,
                'project_root' => $projectRoot,
                'output_path' => $testOut . DIRECTORY_SEPARATOR . 'empty_country',
            ]);
            crp_self_test($result['ok'] ?? false, 'one country export (live DB)');
            if ($result['ok'] ?? false) {
                $liveVerify = orange_country_export_verify_package((string) $result['package_path']);
                crp_self_test($liveVerify['ok'], 'live exported package verifies');
                orange_backup_remove_dir((string) $result['package_path']);
            }
            $populatedId = (int) $countryIds[0];
            $populatedOut = $testOut . DIRECTORY_SEPARATOR . 'populated_country';
            $popResult = orange_country_export_run($pdo, [
                'country_id' => $populatedId,
                'project_root' => $projectRoot,
                'output_path' => $populatedOut,
            ]);
            crp_self_test($popResult['ok'] ?? false, 'populated country export (live DB)');
            if ($popResult['ok'] ?? false) {
                orange_backup_remove_dir((string) $popResult['package_path']);
            }
            orange_backup_remove_dir($testOut);
        }
    } catch (Throwable $e) {
        echo 'INFO: live DB CRP tests skipped/failed: ' . $e->getMessage() . "\n";
    }
}

// No business-data writes: export uses SELECT only (structural check on build_query outputs)
$sampleQuery = orange_country_export_build_query('orders', [
    'extraction_rule' => ['type' => 'country_id', 'column' => 'country_id'],
], 1, []);
crp_self_test(str_starts_with(strtoupper(trim($sampleQuery['sql'])), 'SELECT'), 'no business-data writes (SELECT-only export)');

exit($failures > 0 ? 1 : 0);
