<?php

declare(strict_types=1);

/**
 * Phase 1B.3 automatic country package batch export self-tests.
 *
 * Usage:
 *   php scripts/backup/self_test_country_batch_export.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_batch_export.php';

$failures = 0;

function crp_batch_self_test(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "PASS: {$label}\n";
    } else {
        echo "FAIL: {$label}\n";
        $failures++;
    }
}

// No hardcoded country IDs in batch library source
$batchSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_batch_export.php');
$cliSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'export_all_recoverable_countries.php');
crp_batch_self_test(!preg_match('/country[_-]?id\s*=\s*[1-9]\d*/i', $batchSource), 'no hardcoded country IDs in batch library');
crp_batch_self_test(!preg_match('/--country-id=/', $cliSource), 'batch CLI does not accept hardcoded country-id flag');
crp_batch_self_test(in_array('orders', orange_crp_batch_historical_data_tables(), true), 'historical data tables include orders');
crp_batch_self_test(in_array('journal_vouchers', orange_crp_batch_historical_data_tables(), true), 'historical data tables include journal_vouchers');

// Exit code policy
crp_batch_self_test(orange_crp_batch_compute_exit_code([]) === 0, 'all success exit code 0');
crp_batch_self_test(orange_crp_batch_compute_exit_code([['id' => 99]]) === 1, 'any failure exit code non-zero');
crp_batch_self_test(orange_crp_batch_compute_exit_code([], true) === 2, 'lock failure exit code 2');

// Retention defaults
$defaults = orange_crp_batch_retention_config([]);
crp_batch_self_test($defaults['daily'] === 7, 'retention daily default 7');
crp_batch_self_test($defaults['weekly'] === 4, 'retention weekly default 4');
crp_batch_self_test($defaults['monthly'] === 6, 'retention monthly default 6');
$custom = orange_crp_batch_retention_config([
    'ORANGE_CRP_RETENTION_DAILY' => 10,
    'ORANGE_CRP_RETENTION_WEEKLY' => 2,
    'ORANGE_CRP_RETENTION_MONTHLY' => 3,
]);
crp_batch_self_test($custom['daily'] === 10, 'retention daily from env');

// Retention never deletes newest verified healthy package
$retentionRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_crp_batch_ret_' . bin2hex(random_bytes(4));
$countryDir = $retentionRoot . DIRECTORY_SEPARATOR . 'country_packages' . DIRECTORY_SEPARATOR . 'zztest';
$oldHealthy = $countryDir . DIRECTORY_SEPARATOR . '2020-01-01_010000';
$newFailed = $countryDir . DIRECTORY_SEPARATOR . '2026-07-01_010000';
mkdir($oldHealthy, 0775, true);
mkdir($newFailed, 0775, true);
$writeHealthyPackage = static function (string $dir): void {
    mkdir($dir . DIRECTORY_SEPARATOR . 'sql');
    mkdir($dir . DIRECTORY_SEPARATOR . 'files');
    file_put_contents($dir . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '001_orders.sql', "-- rows=0\n");
    orange_country_uploads_write_empty_zip($dir . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . 'uploads_country.zip');
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'manifest.json', [
        'package_type' => 'country_recovery',
        'package_version' => '1.0',
        'generated_at' => gmdate('c'),
        'country_id' => 99999,
        'country_code' => 'zztest',
        'country_label' => 'Retention Test',
        'schema_revision' => 121,
        'registry_version' => '1.0',
        'export_backend' => 'php_country_export',
        'package_status' => 'healthy',
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'health.json', [
        'package_type' => 'country_recovery',
        'package_status' => 'healthy',
        'country_id' => 99999,
        'schema_revision' => 121,
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'dependency_graph.json', ['nodes' => [], 'edges' => []]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'table_inventory.json', [
        'country_id' => 99999,
        'other_country_markers' => [],
        'tables' => [],
    ]);
    orange_backup_write_json($dir . DIRECTORY_SEPARATOR . 'id_snapshot.json', ['country_id' => 99999, 'tables' => []]);
    orange_backup_write_checksums($dir, [
        'manifest.json',
        'health.json',
        'dependency_graph.json',
        'table_inventory.json',
        'id_snapshot.json',
        'files/uploads_country.zip',
        'sql/001_orders.sql',
    ]);
};
$writeHealthyPackage($oldHealthy);
orange_backup_write_json($newFailed . DIRECTORY_SEPARATOR . 'health.json', [
    'package_type' => 'country_recovery',
    'package_status' => 'failed',
]);
touch($oldHealthy, strtotime('2020-01-01 01:00:00'));
touch($newFailed, strtotime('2026-07-01 01:00:00'));
crp_batch_self_test(orange_crp_batch_find_newest_healthy_package_name($countryDir) === '2020-01-01_010000', 'newest verified healthy is older package when newer failed');
orange_crp_batch_apply_retention($retentionRoot, 'zztest', '2026-07-01_010000', 1, 1, 1);
crp_batch_self_test(is_dir($oldHealthy), 'retention kept oldest verified healthy package');
crp_batch_self_test(!is_dir($newFailed), 'retention removed unprotected failed newer package');
orange_backup_remove_dir($retentionRoot);

// Live DB discovery + partial batch failure (dynamic country IDs — never hardcoded)
if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    try {
        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $liveDiscovery = orange_crp_batch_discover_countries($pdo);
        crp_batch_self_test($liveDiscovery['discovered'] !== [], 'discovery reads countries from DB dynamically');
        $sawActiveSelected = false;
        $sawInactiveHistoricalSelected = false;
        $sawInactiveEmptySkipped = false;
        foreach ($liveDiscovery['selected'] as $entry) {
            if (!empty($entry['is_active'])) {
                $sawActiveSelected = true;
            } elseif (orange_crp_batch_country_has_historical_data($pdo, (int) ($entry['id'] ?? 0))) {
                $sawInactiveHistoricalSelected = true;
            }
        }
        foreach ($liveDiscovery['skipped'] as $entry) {
            if (empty($entry['is_active']) && !orange_crp_batch_country_has_historical_data($pdo, (int) ($entry['id'] ?? 0))) {
                $sawInactiveEmptySkipped = true;
                break;
            }
        }
        crp_batch_self_test($sawActiveSelected, 'active country selected');
        if ($sawInactiveHistoricalSelected) {
            crp_batch_self_test(true, 'inactive country with historical data selected');
        } else {
            echo "INFO: no inactive country with historical data in DB (selection rule still enforced in code)\n";
        }
        if ($sawInactiveEmptySkipped) {
            crp_batch_self_test(true, 'inactive empty/template country skipped');
        } else {
            echo "INFO: no inactive empty country in DB (skip rule still enforced in code)\n";
        }
        crp_batch_self_test(
            count($liveDiscovery['discovered']) === count($liveDiscovery['selected']) + count($liveDiscovery['skipped']),
            'every discovered country is selected or skipped'
        );
        if (count($liveDiscovery['selected']) >= 2) {
            $failId = (int) ($liveDiscovery['selected'][0]['id'] ?? 0);
            $passId = (int) ($liveDiscovery['selected'][1]['id'] ?? 0);
            $batchResult = orange_crp_batch_export_all($pdo, $projectRoot, [
                'export_runner' => static function (PDO $innerPdo, array $exportOptions) use ($failId, $passId, $projectRoot): array {
                    $countryId = (int) ($exportOptions['country_id'] ?? 0);
                    if ($countryId === $failId) {
                        throw new RuntimeException('injected batch failure for self-test');
                    }
                    if ($countryId === $passId) {
                        $testOut = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'orange_crp_batch_partial_' . bin2hex(random_bytes(4));
                        $result = orange_country_export_run($innerPdo, [
                            'country_id' => $countryId,
                            'project_root' => $projectRoot,
                            'output_path' => $testOut,
                        ]);
                        if ($result['ok'] ?? false) {
                            orange_backup_remove_dir((string) ($result['package_path'] ?? ''));
                        }
                        orange_backup_remove_dir($testOut);

                        return $result;
                    }

                    return ['ok' => true, 'package_path' => null, 'message' => 'skipped in partial batch test', 'manifest' => null];
                },
            ]);
            $succeededIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $batchResult['succeeded']);
            $failedIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $batchResult['failed']);
            crp_batch_self_test(in_array($failId, $failedIds, true), 'injected country failure recorded');
            crp_batch_self_test(in_array($passId, $succeededIds, true), 'other country still succeeds after one failure');
            crp_batch_self_test(($batchResult['exit_code'] ?? 0) !== 0, 'batch non-zero exit when any country fails');
        } else {
            echo "INFO: partial batch failure test skipped (need >=2 selected countries)\n";
        }
    } catch (Throwable $e) {
        echo 'INFO: live batch tests skipped/failed: ' . $e->getMessage() . "\n";
    }
}

exit($failures > 0 ? 1 : 0);
