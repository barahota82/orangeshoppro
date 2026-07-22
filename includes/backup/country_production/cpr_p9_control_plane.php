<?php

declare(strict_types=1);

/**
 * CPR P9 Control Plane registry (WP-P9-01; flags updated through WP-P9-03).
 *
 * Enablement action engine marked complete after WP-P9-03.
 * Still no integration freeze, Enterprise Audit, Git Tag, or project closure.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_P9_CONTROL_SCHEMA = 'cpr_p9_control_plane/1';
const ORANGE_CPR_P9_CONTROL_VERSION = 'P9-03-1.0';

/**
 * @return list<string>
 */
function orange_cpr_p9_work_package_ids(): array
{
    return [
        'WP-P9-01',
        'WP-P9-02',
        'WP-P9-03',
        'WP-P9-04',
    ];
}

/**
 * @return array<string, string>
 */
function orange_cpr_p9_work_package_artifacts(): array
{
    return [
        'WP-P9-01' => 'COUNTRY_PRODUCTION_RESTORE_P9_ARTIFACT_INDEX.md',
        'WP-P9-02' => 'COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md',
        'WP-P9-03' => 'COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md',
        'WP-P9-04' => 'COUNTRY_PRODUCTION_RESTORE_P9_04_INTEGRATION_BASELINE.md',
    ];
}

/**
 * @return list<string>
 */
function orange_cpr_p9_enablement_stage_order(): array
{
    return [
        'enablement_preconditions_and_owner_order',
        'super_admin_enable_disable_and_schema_force_disable',
        'enablement_baseline_freeze',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p9_control_plane_snapshot(): array
{
    return [
        'schema_version' => ORANGE_CPR_P9_CONTROL_SCHEMA,
        'engine_version' => ORANGE_CPR_P9_CONTROL_VERSION,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'phase' => 'P9',
        'phase_name' => 'Enablement',
        'roadmap_output' => 'Flag true under OD-ENABLE path',
        'work_packages' => orange_cpr_p9_work_package_ids(),
        'artifacts' => orange_cpr_p9_work_package_artifacts(),
        'enablement_stage_order' => orange_cpr_p9_enablement_stage_order(),
        'wp_p9_01_complete' => true,
        'enablement_preconditions_engine_implemented' => true,
        'enablement_action_engine_implemented' => true,
        'p9_integration_baseline_complete' => false,
        'enablement_flag_observed' => false,
        'enablement_flag_write_authorized' => true,
        'ops_flag_flipped_true' => false,
        'only_wp_p9_03_may_change_flag' => true,
        'auto_enable_forbidden' => true,
        'auto_reenable_forbidden' => true,
        'cert_pass_does_not_enable' => true,
        'four_preconditions_required' => true,
        'super_admin_only_enable_disable' => true,
        'country_admin_cannot_enable' => true,
        'engineering_cannot_enable' => true,
        'e5_does_not_enable' => true,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'phase_sign_off_started' => false,
        'project_closed' => false,
        'architecture_modified' => false,
        'owner_approved_modified' => false,
        'p0_p8_contracts_preserved' => true,
    ];
}

/**
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_p9_control_plane_assert(array $env): array
{
    // Control-plane assert uses env-only view when no CPR work root/ops state.
    $envOnly = $env;
    // Prefer explicit false for registry assert unless caller tests refuse-true.
    if (!array_key_exists('ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED', $envOnly)
        && !array_key_exists('country_production_restore_enabled', $envOnly)
    ) {
        $envOnly['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'] = false;
    }

    $delete = orange_cpr_mutation_refuse_delete();
    $import = orange_cpr_mutation_refuse_import();
    $ponr = orange_cpr_mutation_refuse_ponr_execution();
    if (!empty($delete['ok']) || !empty($import['ok']) || !empty($ponr['ok'])) {
        return [
            'ok' => false,
            'code' => 'p9_mutation_refuse_broken',
            'message' => 'Mutation refuse helpers must remain fail-closed in P9.',
            'fail_closed' => true,
        ];
    }

    $snap = orange_cpr_p9_control_plane_snapshot();
    if (empty($snap['wp_p9_01_complete'])
        || empty($snap['enablement_preconditions_engine_implemented'])
        || empty($snap['enablement_action_engine_implemented'])
        || empty($snap['enablement_flag_write_authorized'])
        || empty($snap['only_wp_p9_03_may_change_flag'])
    ) {
        return [
            'ok' => false,
            'code' => 'p9_control_incomplete',
            'message' => 'WP-P9-01…03 control plane + preconditions + action engines must be complete.',
            'fail_closed' => true,
        ];
    }
    if (!empty($snap['p9_integration_baseline_complete'])
        || !empty($snap['enterprise_audit_started'])
        || !empty($snap['git_tag_created'])
        || !empty($snap['phase_sign_off_started'])
        || !empty($snap['project_closed'])
    ) {
        return [
            'ok' => false,
            'code' => 'p9_boundary_violation',
            'message' => 'WP-P9-03 must not claim integration freeze, Audit, Tag, Sign-Off, or project closure.',
            'fail_closed' => true,
        ];
    }
    if (empty($snap['auto_enable_forbidden'])
        || empty($snap['auto_reenable_forbidden'])
        || empty($snap['cert_pass_does_not_enable'])
        || empty($snap['four_preconditions_required'])
        || empty($snap['super_admin_only_enable_disable'])
        || empty($snap['country_admin_cannot_enable'])
        || empty($snap['engineering_cannot_enable'])
        || empty($snap['e5_does_not_enable'])
        || empty($snap['p0_p8_contracts_preserved'])
    ) {
        return [
            'ok' => false,
            'code' => 'p9_od_enable_invariants_broken',
            'message' => 'OD-ENABLE / OD-PERM / OD-SCHEMA / P0–P8 preservation constants must remain true.',
            'fail_closed' => true,
        ];
    }

    // Refuse when caller forces ops-true without going through sealed action path artifacts.
    if (!empty($env['ORANGE_COUNTRY_RESTORE_PRODUCTION_ENABLED'])
        || !empty($env['country_production_restore_enabled'])
    ) {
        // Only when no sealed ops state authorizes it under a resolvable CPR root.
        $authorized = false;
        try {
            require_once __DIR__ . '/cpr_paths.php';
            $root = orange_cpr_resolve_work_root($env);
            $ops = is_dir($root) ? orange_cpr_enablement_ops_state_load($root) : null;
            $authorized = is_array($ops) && !empty($ops['enabled']) && ($ops['written_by_wp'] ?? '') === 'WP-P9-03';
        } catch (Throwable $e) {
            $authorized = false;
        }
        if (!$authorized) {
            return [
                'ok' => false,
                'code' => 'p9_enablement_forbidden',
                'message' => 'Ops enablement true without sealed WP-P9-03 ops state is forbidden for control-plane assert.',
                'fail_closed' => true,
            ];
        }
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'P9 control plane hard rules hold; WP-P9-03 complete; integration freeze withheld.',
        'snapshot' => $snap,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'ponr_mutation_executed' => false,
        'enterprise_audit_started' => false,
        'git_tag_created' => false,
        'project_closed' => false,
        'env_probe' => $envOnly,
    ];
}
