<?php

declare(strict_types=1);

/**
 * Self-test: CPR P5 Control Plane (WP-P5-01).
 * Run: php scripts/backup/country_production/self_test_cpr_p5_control_plane.php
 */

require_once dirname(__DIR__, 3) . '/includes/backup/country_production/cpr_p5_control_plane.php';

$pass = 0;
$fail = 0;

function cpr_p5cp(string $name, bool $ok, string $detail = ''): void
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
$indexPath = $docsRoot . DIRECTORY_SEPARATOR . 'COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md';

try {
    cpr_p5cp('scaffold_version', ORANGE_CPR_SCAFFOLD_VERSION === 'P5-06-integration-baseline');
    cpr_p5cp('artifact_index_exists', is_file($indexPath));

    $ids = orange_cpr_p5_work_package_ids();
    cpr_p5cp(
        'official_wp_inventory',
        $ids === ['WP-P5-01', 'WP-P5-02', 'WP-P5-03', 'WP-P5-04', 'WP-P5-05', 'WP-P5-06']
    );

    $artifacts = orange_cpr_p5_work_package_artifacts();
    cpr_p5cp('artifact_map_complete', count($artifacts) === 6 && isset($artifacts['WP-P5-01']));
    cpr_p5cp(
        'index_artifact_name',
        ($artifacts['WP-P5-01'] ?? '') === 'COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md'
    );

    cpr_p5cp(
        'apply_stage_order',
        orange_cpr_p5_production_apply_stage_order() === [
            'ponr_target_slice_delete',
            'target_slice_import',
            'special_handlers',
            'country_uploads_apply',
        ]
    );
    cpr_p5cp(
        'p5_checkpoints',
        orange_cpr_p5_checkpoint_ids() === ['CP6', 'CP7', 'CP8', 'CP9']
    );

    $snap = orange_cpr_p5_control_plane_snapshot();
    cpr_p5cp('delete_engine_implemented', !empty($snap['production_delete_engine_implemented']));
    cpr_p5cp('import_engine_implemented', !empty($snap['production_import_engine_implemented']));
    cpr_p5cp('special_handlers_implemented', !empty($snap['special_handlers_engine_implemented']));
    cpr_p5cp('uploads_engine_implemented', !empty($snap['uploads_apply_engine_implemented']));
    cpr_p5cp('p5_baseline_ready', !empty($snap['p5_baseline_ready']));
    cpr_p5cp('no_p6_started', empty($snap['p6_started']));
    cpr_p5cp('no_enterprise_audit', empty($snap['enterprise_audit_started']));
    cpr_p5cp('no_git_tag', empty($snap['git_tag_created']));
    cpr_p5cp('architecture_not_modified_flag', empty($snap['architecture_modified']));
    cpr_p5cp('owner_approved_not_modified_flag', empty($snap['owner_approved_modified']));

    $env = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => false];
    $assert = orange_cpr_p5_control_plane_assert($env);
    cpr_p5cp('assert_ok', !empty($assert['ok']), (string) ($assert['code'] ?? ''));
    cpr_p5cp('assert_enablement_false', ($assert['enablement_flag_observed'] ?? null) === false);

    $envTrue = ['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED' => true];
    $denied = orange_cpr_p5_control_plane_assert($envTrue);
    cpr_p5cp(
        'assert_denies_enablement_true',
        empty($denied['ok']) && ($denied['code'] ?? '') === 'p5_enablement_forbidden',
        (string) ($denied['code'] ?? '')
    );

    // Index content smoke checks
    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    cpr_p5cp('index_has_artifact_id', str_contains($index, 'CPR-P5-WP01-ARTIFACT_INDEX'));
    cpr_p5cp('index_lists_wp_p5_06', str_contains($index, 'WP-P5-06'));
    cpr_p5cp('index_wp_p5_06_complete', str_contains($index, '**WP-P5-06 COMPLETE**'));
    cpr_p5cp('index_stop_blocks_p6', str_contains($index, 'Do **not** begin **P6**'));
    cpr_p5cp('index_enablement_false', str_contains($index, 'hard false'));
} catch (Throwable $e) {
    cpr_p5cp('exception', false, $e->getMessage());
}

echo "\nResult: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
