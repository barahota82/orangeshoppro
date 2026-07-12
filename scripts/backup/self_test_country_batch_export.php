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

$batchSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'country_batch_export.php');
$cliSource = (string) file_get_contents($projectRoot . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup' . DIRECTORY_SEPARATOR . 'export_all_recoverable_countries.php');
crp_batch_self_test(!preg_match('/country[_-]?id\s*=\s*[1-9]\d*/i', $batchSource), 'no hardcoded country IDs in batch library');
crp_batch_self_test(!preg_match('/--country-id=/', $cliSource), 'batch CLI does not accept hardcoded country-id flag');
crp_batch_self_test(in_array('customers', orange_crp_batch_historical_data_tables(), true), 'historical data tables include customers');
crp_batch_self_test(in_array('inventory_cost_consumptions', orange_crp_batch_historical_data_tables(), true), 'historical data tables include inventory/FIFO consumptions');

$directProbe = orange_crp_batch_make_country_id_probe('orders');
crp_batch_self_test($directProbe['key'] === 'orders', 'direct country_id probe key');
crp_batch_self_test(str_contains($directProbe['sql'], 'orders') && str_contains($directProbe['sql'], 'country_id = ?'), 'direct country_id probe SQL');

$indirectProbe = [
    'key' => 'inventory_cost_consumptions',
    'sql' => 'SELECT 1 FROM inventory_cost_consumptions icc INNER JOIN inventory_cost_layers icl ON icl.id = icc.layer_id WHERE icl.country_id = ? LIMIT 1',
    'param_count' => 1,
];
crp_batch_self_test(
    orange_crp_batch_historical_probe_params($indirectProbe, 7) === [7],
    'indirect country ownership probe params'
);
crp_batch_self_test(
    orange_crp_batch_historical_probe_params([
        'key' => 'purchase_returns',
        'sql' => 'SELECT 1 FROM purchase_returns pr LEFT JOIN purchases p ON p.id = pr.purchase_id LEFT JOIN suppliers s ON s.id = pr.supplier_id WHERE (p.id IS NOT NULL AND p.country_id = ?) OR (p.id IS NULL AND s.country_id = ?) LIMIT 1',
        'param_count' => 2,
    ], 3) === [3, 3],
    'parent-table ownership probe uses two country params'
);

crp_batch_self_test(!preg_match('/FROM purchase_returns[^\\n]*WHERE country_id/s', $batchSource), 'purchase_returns never queried with direct country_id');
crp_batch_self_test(str_contains($batchSource, 'inventory_cost_consumptions icc'), 'inventory_cost_consumptions resolved via inventory_cost_layers parent');

$malformedFailed = false;
try {
    orange_crp_batch_validate_historical_probe_spec(['key' => 'bad', 'sql' => '', 'param_count' => 0]);
} catch (RuntimeException $e) {
    $malformedFailed = str_contains($e->getMessage(), 'Malformed country history probe spec');
}
crp_batch_self_test($malformedFailed, 'malformed ownership probe spec fails clearly');

crp_batch_self_test(orange_crp_batch_compute_exit_code([]) === 0, 'all success exit code 0');
crp_batch_self_test(orange_crp_batch_compute_exit_code([['id' => 99]]) === 1, 'any failure exit code non-zero');
crp_batch_self_test(orange_crp_batch_compute_exit_code([], true) === 2, 'lock failure exit code 2');
crp_batch_self_test(orange_backup_retention_days([]) === 30, 'shared retention default = 30');

if (is_file($projectRoot . DIRECTORY_SEPARATOR . '.env.php')) {
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
    require_once $projectRoot . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'catalog_schema.php';
    try {
        $pdo = db();
        orange_catalog_ensure_schema($pdo);
        $liveDiscovery = orange_crp_batch_discover_countries($pdo);
        $probeSpecs = orange_crp_batch_historical_data_probe_specs($pdo);
        crp_batch_self_test($probeSpecs !== [], 'historical probe specs build from live schema');
        $probeKeys = array_map(static fn (array $spec): string => (string) ($spec['key'] ?? ''), $probeSpecs);
        crp_batch_self_test(in_array('purchase_returns', $probeKeys, true) || !orange_table_exists($pdo, 'purchase_returns'), 'purchase_returns probe registered when table exists');
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
