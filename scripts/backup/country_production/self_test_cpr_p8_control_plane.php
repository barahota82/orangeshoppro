<?php

declare(strict_types=1);

/**
 * Self-test: CPR P8 Control Plane (WP-P8-01; flags updated through WP-P8-03).
 * Run: php scripts/backup/country_production/self_test_cpr_p8_control_plane.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p8_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_p8cp(string $name, bool $ok, string $detail = ''): void
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
$indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md';

try {
    cpr_p8cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P8-03-owner-cert-decision');
    cpr_p8cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p8_work_package_ids();
    cpr_p8cp(
        'official_wp_inventory',
        $ids === ['WP-P8-01', 'WP-P8-02', 'WP-P8-03', 'WP-P8-04']
    );

    $artifacts = orange_cpr_p8_work_package_artifacts();
    cpr_p8cp(
        'cert_decision_artifact_name',
        ($artifacts['WP-P8-03'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md'
    );

    $snap = orange_cpr_p8_control_plane_snapshot();
    cpr_p8cp('wp_p8_01_complete_flag', !empty($snap['wp_p8_01_complete']));
    cpr_p8cp('owner_submission_implemented', !empty($snap['owner_submission_engine_implemented']));
    cpr_p8cp('owner_cert_decision_implemented', !empty($snap['owner_cert_decision_engine_implemented']));
    cpr_p8cp('no_p8_integration_yet', empty($snap['p8_integration_baseline_complete']));
    cpr_p8cp('no_global_owner_cert_pass_claim', empty($snap['owner_cert_pass_granted']));
    cpr_p8cp('no_p9_started', empty($snap['p9_started']));
    cpr_p8cp('engineering_cannot_grant_pass', !empty($snap['engineering_cannot_grant_pass']));
    cpr_p8cp('cert_pass_does_not_enable', !empty($snap['cert_pass_does_not_enable']));
    cpr_p8cp('fail_does_not_auto_rollback', !empty($snap['fail_does_not_auto_rollback']));
    cpr_p8cp('p0_p7_contracts_preserved', !empty($snap['p0_p7_contracts_preserved']));
    cpr_p8cp('no_production_sql', ($snap['production_sql_executed'] ?? true) === false);

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p8_control_plane_assert($env);
    cpr_p8cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p8cp('index_wp_p8_03_complete', str_contains($index, '**WP-P8-03 COMPLETE**'));
    cpr_p8cp('index_lists_cert_module', str_contains($index, 'cpr_owner_cert_decision_live.php'));
    cpr_p8cp('index_stop_blocks_p8_04', str_contains($index, 'Do **not** begin **WP-P8-04**'));
    cpr_p8cp('index_stop_blocks_p9', str_contains($index, 'Do **not** begin **P9**'));
    cpr_p8cp(
        'design_doc_exists',
        is_file($docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md')
    );
} catch (Throwable $e) {
    cpr_p8cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
