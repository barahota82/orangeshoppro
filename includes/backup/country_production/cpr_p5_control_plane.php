<?php

declare(strict_types=1);

/**
 * CPR P5 Control Plane registry (WP-P5-01).
 *
 * Inventory / hard-rule helpers only — no production DELETE/IMPORT/uploads engines.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md §6 §18 roadmap P5
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_P5_CONTROL_SCHEMA = 'cpr_p5_control_plane/1';
const ORANGE_CPR_P5_CONTROL_VERSION = 'P5-01-1.0';

/**
 * Official P5 Work Package IDs (discovered from Architecture — not invented).
 *
 * @return list<string>
 */
function orange_cpr_p5_work_package_ids(): array
{
    return [
        'WP-P5-01',
        'WP-P5-02',
        'WP-P5-03',
        'WP-P5-04',
        'WP-P5-05',
        'WP-P5-06',
    ];
}

/**
 * Primary artifact filenames for each P5 WP.
 *
 * @return array<string, string>
 */
function orange_cpr_p5_work_package_artifacts(): array
{
    return [
        'WP-P5-01' => 'COUNTRY_PRODUCTION_RESTORE_P5_ARTIFACT_INDEX.md',
        'WP-P5-02' => 'COUNTRY_PRODUCTION_RESTORE_P5_02_TARGET_SLICE_DELETE.md',
        'WP-P5-03' => 'COUNTRY_PRODUCTION_RESTORE_P5_03_TARGET_SLICE_IMPORT.md',
        'WP-P5-04' => 'COUNTRY_PRODUCTION_RESTORE_P5_04_SPECIAL_HANDLERS.md',
        'WP-P5-05' => 'COUNTRY_PRODUCTION_RESTORE_P5_05_UPLOADS_APPLY.md',
        'WP-P5-06' => 'COUNTRY_PRODUCTION_RESTORE_P5_06_INTEGRATION_BASELINE.md',
    ];
}

/**
 * Architecture §6 / §18 Production Apply stage order (P5 engines only).
 *
 * @return list<string>
 */
function orange_cpr_p5_production_apply_stage_order(): array
{
    return [
        'ponr_target_slice_delete',
        'target_slice_import',
        'special_handlers',
        'country_uploads_apply',
    ];
}

/**
 * Architecture §18 checkpoints owned by P5 (CP10+ are P6).
 *
 * @return list<string>
 */
function orange_cpr_p5_checkpoint_ids(): array
{
    return ['CP6', 'CP7', 'CP8', 'CP9'];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p5_control_plane_snapshot(): array
{
    return [
        'schema_version' => ORANGE_CPR_P5_CONTROL_SCHEMA,
        'engine_version' => ORANGE_CPR_P5_CONTROL_VERSION,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'phase' => 'P5',
        'phase_name' => 'Production apply',
        'roadmap_output' => 'Delete/import/uploads under flags',
        'work_packages' => orange_cpr_p5_work_package_ids(),
        'artifacts' => orange_cpr_p5_work_package_artifacts(),
        'production_apply_stage_order' => orange_cpr_p5_production_apply_stage_order(),
        'checkpoint_ids' => orange_cpr_p5_checkpoint_ids(),
        'wp_p5_01_complete' => true,
        'production_delete_engine_implemented' => true,
        'production_import_engine_implemented' => true,
        'special_handlers_engine_implemented' => true,
        'uploads_apply_engine_implemented' => false,
        'enablement_flag_observed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'p6_started' => false,
        'architecture_modified' => false,
        'owner_approved_modified' => false,
    ];
}

/**
 * Assert WP-P5-01 hard rules for control-plane operations (fail-closed).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_p5_control_plane_assert(array $env): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'code' => 'p5_enablement_forbidden',
            'message' => $e->getMessage(),
            'fail_closed' => true,
        ];
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return [
            'ok' => false,
            'code' => 'p5_enablement_forbidden',
            'message' => 'Ops enablement must remain FALSE during P5 (Architecture roadmap).',
            'fail_closed' => true,
        ];
    }

    $delete = orange_cpr_mutation_refuse_delete();
    $import = orange_cpr_mutation_refuse_import();
    $ponr = orange_cpr_mutation_refuse_ponr_execution();
    if (!empty($delete['ok']) || !empty($import['ok']) || !empty($ponr['ok'])) {
        return [
            'ok' => false,
            'code' => 'p5_mutation_refuse_broken',
            'message' => 'Mutation refuse helpers must remain fail-closed in WP-P5-01.',
            'fail_closed' => true,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'P5 control plane hard rules hold; no production apply engines in WP-P5-01.',
        'snapshot' => orange_cpr_p5_control_plane_snapshot(),
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'ponr_mutation_executed' => false,
    ];
}
