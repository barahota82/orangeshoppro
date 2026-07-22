<?php

declare(strict_types=1);

/**
 * Self-test: CPR P9 Control Plane (WP-P9-01; flags updated through WP-P9-04).
 * Run: php scripts/backup/country_production/self_test_cpr_p9_control_plane.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p9_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_p9cp(string $name, bool $ok, string $detail = ''): void
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
$indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md';

try {
    cpr_p9cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-04-integration-baseline');
    cpr_p9cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p9_work_package_ids();
    cpr_p9cp(
        'official_wp_inventory',
        $ids === ['WP-P9-01', 'WP-P9-02', 'WP-P9-03', 'WP-P9-04']
    );

    $snap = orange_cpr_p9_control_plane_snapshot();
    cpr_p9cp('wp_p9_01_complete_flag', !empty($snap['wp_p9_01_complete']));
    cpr_p9cp('preconditions_engine_implemented', !empty($snap['enablement_preconditions_engine_implemented']));
    cpr_p9cp('action_engine_implemented', !empty($snap['enablement_action_engine_implemented']));
    cpr_p9cp('integration_baseline_complete', !empty($snap['p9_integration_baseline_complete']));
    cpr_p9cp('flag_write_authorized', !empty($snap['enablement_flag_write_authorized']));
    cpr_p9cp('only_wp_p9_03_may_change_flag', !empty($snap['only_wp_p9_03_may_change_flag']));
    cpr_p9cp('auto_enable_forbidden', !empty($snap['auto_enable_forbidden']));
    cpr_p9cp('auto_reenable_forbidden', !empty($snap['auto_reenable_forbidden']));
    cpr_p9cp('e5_does_not_enable', !empty($snap['e5_does_not_enable']));
    cpr_p9cp('no_enterprise_audit', empty($snap['enterprise_audit_started']));
    cpr_p9cp('no_git_tag', empty($snap['git_tag_created']));
    cpr_p9cp('no_phase_sign_off', empty($snap['phase_sign_off_started']));
    cpr_p9cp('project_not_closed', empty($snap['project_closed']));

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p9_control_plane_assert($env);
    cpr_p9cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $envTrue = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => true];
    $assertTrue = orange_cpr_p9_control_plane_assert($envTrue);
    cpr_p9cp('assert_refuses_ops_true_without_ops_state', empty($assertTrue['ok']));

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p9cp('index_wp_p9_01_complete', str_contains($index, '**WP-P9-01 COMPLETE**'));
    cpr_p9cp('index_wp_p9_02_complete', str_contains($index, '**WP-P9-02 COMPLETE**'));
    cpr_p9cp('index_wp_p9_03_complete', str_contains($index, '**WP-P9-03 COMPLETE**'));
    cpr_p9cp('index_wp_p9_04_complete', str_contains($index, '**WP-P9-04 COMPLETE**'));
    cpr_p9cp('index_lists_integration_module', str_contains($index, 'cpr_p9_integration.php'));
    cpr_p9cp('index_stop_blocks_enterprise_audit', str_contains($index, 'Do **not** start the Enterprise Audit'));
    cpr_p9cp('index_stop_blocks_git_tag', str_contains($index, 'Do **not** create the Git Tag'));
    cpr_p9cp('index_stop_blocks_project_complete', str_contains($index, 'Do **not** declare the project complete'));
    cpr_p9cp(
        'design_doc_exists',
        is_file($docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md')
    );
} catch (Throwable $e) {
    cpr_p9cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
