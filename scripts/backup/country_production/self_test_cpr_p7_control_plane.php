<?php

declare(strict_types=1);

/**
 * Self-test: CPR P7 Control Plane (WP-P7-01; flags updated through WP-P7-03).
 * Run: php scripts/backup/country_production/self_test_cpr_p7_control_plane.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p7_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_p7cp(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        ++$pass;
        echo "PASS  {$name}\n";
    } else {
        ++$fail;
        echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
    }
}

$docsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup';
$indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md';

try {
    cpr_p7cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P7-03-drill-execution');
    cpr_p7cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p7_work_package_ids();
    cpr_p7cp(
        'official_wp_inventory',
        $ids === ['WP-P7-01', 'WP-P7-02', 'WP-P7-03', 'WP-P7-04', 'WP-P7-05']
    );

    $artifacts = orange_cpr_p7_work_package_artifacts();
    cpr_p7cp('artifact_map_complete', count($artifacts) === 5 && isset($artifacts['WP-P7-01']));
    cpr_p7cp(
        'index_artifact_name',
        ($artifacts['WP-P7-01'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md'
    );
    cpr_p7cp(
        'execution_artifact_name',
        ($artifacts['WP-P7-03'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md'
    );

    cpr_p7cp(
        'stage_order',
        orange_cpr_p7_clone_drill_stage_order() === [
            'clone_drill_harness',
            'drill_scenario_execution',
            'evidence_pack_assembly_seal',
        ]
    );
    cpr_p7cp(
        'allowed_drill_contexts',
        orange_cpr_p7_allowed_drill_contexts() === [
            'clone',
            'shadow_lab',
            'non_production_fixture',
        ]
    );

    $snap = orange_cpr_p7_control_plane_snapshot();
    cpr_p7cp('wp_p7_01_complete_flag', !empty($snap['wp_p7_01_complete']));
    cpr_p7cp('drill_harness_implemented', !empty($snap['drill_harness_implemented']));
    cpr_p7cp('drill_execution_implemented', !empty($snap['drill_execution_engine_implemented']));
    cpr_p7cp('no_evidence_pack_yet', empty($snap['evidence_pack_engine_implemented']));
    cpr_p7cp('no_p7_integration_yet', empty($snap['p7_integration_baseline_complete']));
    cpr_p7cp('no_owner_cert_pass', empty($snap['owner_cert_pass_granted']));
    cpr_p7cp('no_p8_started', empty($snap['p8_started']));
    cpr_p7cp('architecture_not_modified_flag', empty($snap['architecture_modified']));
    cpr_p7cp('owner_approved_not_modified_flag', empty($snap['owner_approved_modified']));
    cpr_p7cp('p0_p6_contracts_preserved', !empty($snap['p0_p6_contracts_preserved']));
    cpr_p7cp('no_production_sql', ($snap['production_sql_executed'] ?? true) === false);

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p7_control_plane_assert($env);
    cpr_p7cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
    cpr_p7cp('assert_enablement_false', ($assert['enablement_flag_observed'] ?? null) === false);

    $envTrue = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => true];
    $denied = orange_cpr_p7_control_plane_assert($envTrue);
    cpr_p7cp(
        'assert_denies_enablement_true',
        empty($denied['ok']) && ($denied['code'] ?? '') === 'p7_enablement_forbidden',
        (string) ($denied['code'] ?? '')
    );

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p7cp('index_has_artifact_id', str_contains($index, 'CPR-P7-WP01-ARTIFACT_INDEX'));
    cpr_p7cp('index_lists_wp_p7_03', str_contains($index, 'WP-P7-03'));
    cpr_p7cp('index_wp_p7_03_complete', str_contains($index, '**WP-P7-03 COMPLETE**'));
    cpr_p7cp('index_stop_blocks_p7_04', str_contains($index, 'Do **not** begin **WP-P7-04**'));
    cpr_p7cp('index_lists_execution_module', str_contains($index, 'cpr_drill_execution_live.php'));
    cpr_p7cp('index_lists_catalog_module', str_contains($index, 'cpr_drill_catalog.php'));
    cpr_p7cp('index_enablement_false', str_contains($index, 'hard false'));
    cpr_p7cp(
        'index_preserves_p6_contracts',
        str_contains($index, 'Preserves all contracts frozen in P0')
        || str_contains($index, 'Preserve P0')
    );
    cpr_p7cp('index_no_invented_wp_note', str_contains($index, 'No additional WPs invented'));
    cpr_p7cp('index_defers_owner_cert_p8', str_contains($index, 'P8') && str_contains($index, 'OD-CERT'));
    cpr_p7cp('index_consumes_p2_03', str_contains($index, 'P2-03'));
    cpr_p7cp('index_consumes_p2_04', str_contains($index, 'P2-04'));

    $execDoc = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md';
    cpr_p7cp('execution_design_doc_exists', is_file($execDoc));

    $arch = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md';
    $od = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md';
    cpr_p7cp('architecture_file_present', is_file($arch));
    cpr_p7cp('owner_decisions_file_present', is_file($od));
} catch (Throwable $e) {
    cpr_p7cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
