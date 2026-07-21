<?php

declare(strict_types=1);

/**
 * CPR P6 Control Plane registry (WP-P6-01).
 *
 * Inventory / hard-rule helpers only — no post-verify / rollback / closeout engines.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §18 §19 roadmap P6
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_P6_CONTROL_SCHEMA = 'cpr_p6_control_plane/1';
const ORANGE_CPR_P6_CONTROL_VERSION = 'P6-01-1.0';

/**
 * Official P6 Work Package IDs (discovered from Architecture — not invented).
 *
 * @return list<string>
 */
function orange_cpr_p6_work_package_ids(): array
{
    return [
        'WP-P6-01',
        'WP-P6-02',
        'WP-P6-03',
        'WP-P6-04',
        'WP-P6-05',
        'WP-P6-06',
    ];
}

/**
 * Primary artifact filenames for each P6 WP.
 *
 * @return array<string, string>
 */
function orange_cpr_p6_work_package_artifacts(): array
{
    return [
        'WP-P6-01' => 'COUNTRY_PRODUCTION_RESTORE_P6_ARTIFACT_INDEX.md',
        'WP-P6-02' => 'COUNTRY_PRODUCTION_RESTORE_P6_02_POST_VERIFY.md',
        'WP-P6-03' => 'COUNTRY_PRODUCTION_RESTORE_P6_03_SUCCESS_FINALIZE.md',
        'WP-P6-04' => 'COUNTRY_PRODUCTION_RESTORE_P6_04_ROLLBACK_INTEGRATION.md',
        'WP-P6-05' => 'COUNTRY_PRODUCTION_RESTORE_P6_05_MAINT_RELEASE.md',
        'WP-P6-06' => 'COUNTRY_PRODUCTION_RESTORE_P6_06_INTEGRATION_BASELINE.md',
    ];
}

/**
 * Architecture roadmap P6 / §18 post-apply stage order (P6 engines only).
 *
 * @return list<string>
 */
function orange_cpr_p6_verify_rollback_stage_order(): array
{
    return [
        'post_verify',
        'success_finalize',
        'session_full_anchor_rollback',
        'maintenance_release',
    ];
}

/**
 * Architecture §18 checkpoints owned by P6 (CP6–CP9 remain P5).
 *
 * @return list<string>
 */
function orange_cpr_p6_checkpoint_ids(): array
{
    return ['CP10', 'CP11', 'CP12'];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p6_control_plane_snapshot(): array
{
    return [
        'schema_version' => ORANGE_CPR_P6_CONTROL_SCHEMA,
        'engine_version' => ORANGE_CPR_P6_CONTROL_VERSION,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'phase' => 'P6',
        'phase_name' => 'Verify + rollback integration',
        'roadmap_output' => 'Post-verify + session Full-anchor rollback',
        'work_packages' => orange_cpr_p6_work_package_ids(),
        'artifacts' => orange_cpr_p6_work_package_artifacts(),
        'verify_rollback_stage_order' => orange_cpr_p6_verify_rollback_stage_order(),
        'checkpoint_ids' => orange_cpr_p6_checkpoint_ids(),
        'wp_p6_01_complete' => true,
        'post_verify_engine_implemented' => true,
        'success_finalize_engine_implemented' => false,
        'rollback_integration_engine_implemented' => false,
        'maint_release_engine_implemented' => false,
        'p6_integration_baseline_complete' => false,
        'enablement_flag_observed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'p7_started' => false,
        'architecture_modified' => false,
        'owner_approved_modified' => false,
        'p0_p5_contracts_preserved' => true,
    ];
}

/**
 * Assert WP-P6-01 hard rules for control-plane operations (fail-closed).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_p6_control_plane_assert(array $env): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'code' => 'p6_enablement_forbidden',
            'message' => $e->getMessage(),
            'fail_closed' => true,
        ];
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return [
            'ok' => false,
            'code' => 'p6_enablement_forbidden',
            'message' => 'Ops enablement must remain FALSE during P6 (Architecture roadmap).',
            'fail_closed' => true,
        ];
    }

    $delete = orange_cpr_mutation_refuse_delete();
    $import = orange_cpr_mutation_refuse_import();
    $ponr = orange_cpr_mutation_refuse_ponr_execution();
    if (!empty($delete['ok']) || !empty($import['ok']) || !empty($ponr['ok'])) {
        return [
            'ok' => false,
            'code' => 'p6_mutation_refuse_broken',
            'message' => 'Mutation refuse helpers must remain fail-closed in WP-P6-01.',
            'fail_closed' => true,
        ];
    }

    $snap = orange_cpr_p6_control_plane_snapshot();
    if (empty($snap['post_verify_engine_implemented'])) {
        return [
            'ok' => false,
            'code' => 'p6_post_verify_missing',
            'message' => 'WP-P6-02 post-verify engine must be marked implemented.',
            'fail_closed' => true,
        ];
    }
    if (!empty($snap['success_finalize_engine_implemented'])
        || !empty($snap['rollback_integration_engine_implemented'])
        || !empty($snap['maint_release_engine_implemented'])
    ) {
        return [
            'ok' => false,
            'code' => 'p6_engines_premature',
            'message' => 'WP-P6-02 must not mark finalize/rollback/maint-release engines implemented.',
            'fail_closed' => true,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'P6 control plane hard rules hold; post-verify implemented; later engines deferred.',
        'snapshot' => $snap,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'ponr_mutation_executed' => false,
    ];
}
