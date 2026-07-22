<?php

declare(strict_types=1);

/**
 * Self-test: CPR P9 Control Plane (WP-P9-01).
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
    cpr_p9cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P9-01-control-plane');
    cpr_p9cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p9_work_package_ids();
    cpr_p9cp(
        'official_wp_inventory',
        $ids === ['WP-P9-01', 'WP-P9-02', 'WP-P9-03', 'WP-P9-04']
    );

    $artifacts = orange_cpr_p9_work_package_artifacts();
    cpr_p9cp(
        'preconditions_artifact_name',
        ($artifacts['WP-P9-02'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md'
    );
    cpr_p9cp(
        'actions_artifact_name',
        ($artifacts['WP-P9-03'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md'
    );
    cpr_p9cp(
        'integration_artifact_name',
        ($artifacts['WP-P9-04'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md'
    );

    $stages = orange_cpr_p9_enablement_stage_order();
    cpr_p9cp(
        'stage_order',
        $stages === [
            'enablement_preconditions_and_owner_order',
            'super_admin_enable_disable_and_schema_force_disable',
            'enablement_baseline_freeze',
        ]
    );

    $snap = orange_cpr_p9_control_plane_snapshot();
    cpr_p9cp('wp_p9_01_complete_flag', !empty($snap['wp_p9_01_complete']));
    cpr_p9cp('no_preconditions_engine', empty($snap['enablement_preconditions_engine_implemented']));
    cpr_p9cp('no_action_engine', empty($snap['enablement_action_engine_implemented']));
    cpr_p9cp('no_p9_integration', empty($snap['p9_integration_baseline_complete']));
    cpr_p9cp('no_flag_write', empty($snap['enablement_flag_write_authorized']));
    cpr_p9cp('no_ops_flag_true', empty($snap['ops_flag_flipped_true']));
    cpr_p9cp('auto_enable_forbidden', !empty($snap['auto_enable_forbidden']));
    cpr_p9cp('auto_reenable_forbidden', !empty($snap['auto_reenable_forbidden']));
    cpr_p9cp('cert_pass_does_not_enable', !empty($snap['cert_pass_does_not_enable']));
    cpr_p9cp('four_preconditions_required', !empty($snap['four_preconditions_required']));
    cpr_p9cp('super_admin_only', !empty($snap['super_admin_only_enable_disable']));
    cpr_p9cp('country_admin_cannot_enable', !empty($snap['country_admin_cannot_enable']));
    cpr_p9cp('engineering_cannot_enable', !empty($snap['engineering_cannot_enable']));
    cpr_p9cp('no_enterprise_audit', empty($snap['enterprise_audit_started']));
    cpr_p9cp('no_git_tag', empty($snap['git_tag_created']));
    cpr_p9cp('no_phase_sign_off', empty($snap['phase_sign_off_started']));
    cpr_p9cp('project_not_closed', empty($snap['project_closed']));
    cpr_p9cp('p0_p8_contracts_preserved', !empty($snap['p0_p8_contracts_preserved']));
    cpr_p9cp('no_production_sql', ($snap['production_sql_executed'] ?? true) === false);
    cpr_p9cp('roadmap_output', ($snap['roadmap_output'] ?? '') === 'Flag true under OD-ENABLE path');

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p9_control_plane_assert($env);
    cpr_p9cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));

    $envTrue = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => true];
    $assertTrue = orange_cpr_p9_control_plane_assert($envTrue);
    cpr_p9cp('assert_refuses_ops_true', empty($assertTrue['ok']));

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p9cp('index_wp_p9_01_complete', str_contains($index, '**WP-P9-01 COMPLETE**'));
    cpr_p9cp('index_lists_control_module', str_contains($index, 'cpr_p9_control_plane.php'));
    cpr_p9cp('index_stop_blocks_wp02', str_contains($index, 'Do **not** begin **WP-P9-02**'));
    cpr_p9cp('index_stop_blocks_enablement_flip', str_contains($index, 'Do **not** flip enablement'));
    cpr_p9cp('index_stop_blocks_enterprise_audit', str_contains($index, 'Do **not** start the Enterprise Audit'));
    cpr_p9cp('index_discovery_note', str_contains($index, 'No additional WPs invented'));
    cpr_p9cp('index_artifact_id', str_contains($index, 'CPR-P9-WP01-ARTIFACT_INDEX'));
} catch (Throwable $e) {
    cpr_p9cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
