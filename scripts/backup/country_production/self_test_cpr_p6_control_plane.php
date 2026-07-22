<?php

declare(strict_types=1);

/**
 * Self-test: CPR P6 Control Plane (WP-P6-01).
 * Run: php scripts/backup/country_production/self_test_cpr_p6_control_plane.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p6_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_p6cp(string $name, bool $ok, string $detail = ''): void
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
$indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md';

try {
    cpr_p6cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P7-04-evidence-pack');
    cpr_p6cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p6_work_package_ids();
    cpr_p6cp(
        'official_wp_inventory',
        $ids === ['WP-P6-01', 'WP-P6-02', 'WP-P6-03', 'WP-P6-04', 'WP-P6-05', 'WP-P6-06']
    );

    $artifacts = orange_cpr_p6_work_package_artifacts();
    cpr_p6cp('artifact_map_complete', count($artifacts) === 6 && isset($artifacts['WP-P6-01']));
    cpr_p6cp(
        'index_artifact_name',
        ($artifacts['WP-P6-01'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md'
    );

    cpr_p6cp(
        'stage_order',
        orange_cpr_p6_verify_rollback_stage_order() === [
            'post_verify',
            'success_finalize',
            'session_full_anchor_rollback',
            'maintenance_release',
        ]
    );
    cpr_p6cp(
        'p6_checkpoints',
        orange_cpr_p6_checkpoint_ids() === ['CP10', 'CP11', 'CP12']
    );

    $snap = orange_cpr_p6_control_plane_snapshot();
    cpr_p6cp('wp_p6_01_complete_flag', !empty($snap['wp_p6_01_complete']));
    cpr_p6cp('post_verify_engine_implemented', !empty($snap['post_verify_engine_implemented']));
    cpr_p6cp('success_finalize_engine_implemented', !empty($snap['success_finalize_engine_implemented']));
    cpr_p6cp('rollback_engine_implemented', !empty($snap['rollback_integration_engine_implemented']));
    cpr_p6cp('maint_release_engine_implemented', !empty($snap['maint_release_engine_implemented']));
    cpr_p6cp('p6_integration_baseline_complete', !empty($snap['p6_integration_baseline_complete']));
    cpr_p6cp('no_p7_started', empty($snap['p7_started']));
    cpr_p6cp('architecture_not_modified_flag', empty($snap['architecture_modified']));
    cpr_p6cp('owner_approved_not_modified_flag', empty($snap['owner_approved_modified']));
    cpr_p6cp('p0_p5_contracts_preserved', !empty($snap['p0_p5_contracts_preserved']));
    cpr_p6cp('no_production_sql', ($snap['production_sql_executed'] ?? true) === false);

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p6_control_plane_assert($env);
    cpr_p6cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
    cpr_p6cp('assert_enablement_false', ($assert['enablement_flag_observed'] ?? null) === false);

    $envTrue = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => true];
    $denied = orange_cpr_p6_control_plane_assert($envTrue);
    cpr_p6cp(
        'assert_denies_enablement_true',
        empty($denied['ok']) && ($denied['code'] ?? '') === 'p6_enablement_forbidden',
        (string) ($denied['code'] ?? '')
    );

    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p6cp('index_has_artifact_id', str_contains($index, 'CPR-P6-WP01-ARTIFACT_INDEX'));
    cpr_p6cp('index_lists_wp_p6_02', str_contains($index, 'WP-P6-02'));
    cpr_p6cp('index_wp_p6_01_complete', str_contains($index, '**WP-P6-01 COMPLETE**'));
    cpr_p6cp('index_wp_p6_02_complete', str_contains($index, 'COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md'));
    cpr_p6cp('index_wp_p6_03_complete', str_contains($index, '**WP-P6-03 COMPLETE**'));
    cpr_p6cp('index_wp_p6_04_complete', str_contains($index, '**WP-P6-04 COMPLETE**'));
    cpr_p6cp('index_wp_p6_05_complete', str_contains($index, '**WP-P6-05 COMPLETE**'));
    cpr_p6cp('index_wp_p6_06_complete', str_contains($index, '**WP-P6-06 COMPLETE**'));
    cpr_p6cp(
        'index_enterprise_audit_passed',
        str_contains($index, 'P6 ENTERPRISE AUDIT COMPLETE') && str_contains($index, 'PASSED')
    );
    cpr_p6cp('index_stop_blocks_git_tag', str_contains($index, 'Do **not** create a **Git Tag**'));
    cpr_p6cp('index_stop_blocks_p7', str_contains($index, 'Do **not** begin **P7**'));
    cpr_p6cp('index_enablement_false', str_contains($index, 'hard false'));
    cpr_p6cp(
        'index_preserves_p5_contracts',
        str_contains($index, 'Preserves all contracts frozen in P0')
        || str_contains($index, 'Preserve P0')
    );
    cpr_p6cp('index_no_invented_wp_note', str_contains($index, 'No additional WPs invented'));

    // Architecture / OD must remain untouched by this WP (file presence + no local dirty expected via content markers).
    $arch = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md';
    $od = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_OWNER_DECISIONS.md';
    cpr_p6cp('architecture_file_present', is_file($arch));
    cpr_p6cp('owner_decisions_file_present', is_file($od));
} catch (Throwable $e) {
    cpr_p6cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
