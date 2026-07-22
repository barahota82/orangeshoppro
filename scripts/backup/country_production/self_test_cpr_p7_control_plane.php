<?php

declare(strict_types=1);

/**
 * Self-test: CPR P7 Control Plane (WP-P7-01; flags updated through WP-P7-05).
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
    cpr_p7cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-04-integration-baseline');
    cpr_p7cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p7_work_package_ids();
    cpr_p7cp(
        'official_wp_inventory',
        $ids === ['WP-P7-01', 'WP-P7-02', 'WP-P7-03', 'WP-P7-04', 'WP-P7-05']
    );

    $artifacts = orange_cpr_p7_work_package_artifacts();
    cpr_p7cp(
        'freeze_artifact_name',
        ($artifacts['WP-P7-05'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md'
    );

    $snap = orange_cpr_p7_control_plane_snapshot();
    cpr_p7cp('wp_p7_01_complete_flag', !empty($snap['wp_p7_01_complete']));
    cpr_p7cp('drill_harness_implemented', !empty($snap['drill_harness_implemented']));
    cpr_p7cp('drill_execution_implemented', !empty($snap['drill_execution_engine_implemented']));
    cpr_p7cp('evidence_pack_implemented', !empty($snap['evidence_pack_engine_implemented']));
    cpr_p7cp('p7_integration_complete', !empty($snap['p7_integration_baseline_complete']));
    cpr_p7cp('no_owner_cert_pass', empty($snap['owner_cert_pass_granted']));
    cpr_p7cp('no_p8_started', empty($snap['p8_started']));
    cpr_p7cp('architecture_not_modified_flag', empty($snap['architecture_modified']));
    cpr_p7cp('owner_approved_not_modified_flag', empty($snap['owner_approved_modified']));
    cpr_p7cp('p0_p6_contracts_preserved', !empty($snap['p0_p6_contracts_preserved']));
    cpr_p7cp('no_production_sql', ($snap['production_sql_executed'] ?? true) === false);

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p7_control_plane_assert($env);
    cpr_p7cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p7cp('index_wp_p7_05_complete', str_contains($index, '**WP-P7-05 COMPLETE**'));
    cpr_p7cp('index_lists_integration_module', str_contains($index, 'cpr_p7_integration.php'));
    cpr_p7cp(
        'index_enterprise_audit_passed',
        str_contains($index, 'P7 ENTERPRISE AUDIT COMPLETE') && str_contains($index, 'PASSED')
    );
    cpr_p7cp('index_no_p8', str_contains($index, 'Do **not** begin **P8**'));
    cpr_p7cp('index_no_git_tag', str_contains($index, 'Do **not** create the **Git Tag**') || str_contains($index, 'Git Tag'));

    $freezeDoc = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md';
    cpr_p7cp('freeze_design_doc_exists', is_file($freezeDoc));
} catch (Throwable $e) {
    cpr_p7cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
