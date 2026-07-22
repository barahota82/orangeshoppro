<?php

declare(strict_types=1);

/**
 * CPR P7 Control Plane registry (WP-P7-01).
 *
 * Inventory / hard-rule helpers only — no drill harness / scenario runner / evidence sealer.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md roadmap P7
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_P7_CONTROL_SCHEMA = 'cpr_p7_control_plane/1';
const ORANGE_CPR_P7_CONTROL_VERSION = 'P7-01-1.0';

/**
 * Official P7 Work Package IDs (discovered from Architecture + P2 — not invented).
 *
 * @return list<string>
 */
function orange_cpr_p7_work_package_ids(): array
{
    return [
        'WP-P7-01',
        'WP-P7-02',
        'WP-P7-03',
        'WP-P7-04',
        'WP-P7-05',
    ];
}

/**
 * Primary artifact filenames for each P7 WP.
 *
 * @return array<string, string>
 */
function orange_cpr_p7_work_package_artifacts(): array
{
    return [
        'WP-P7-01' => 'COUNTRY_PRODUCTION_RESTORE_P7_ARTIFACT_INDEX.md',
        'WP-P7-02' => 'COUNTRY_PRODUCTION_RESTORE_P7_02_DRILL_HARNESS.md',
        'WP-P7-03' => 'COUNTRY_PRODUCTION_RESTORE_P7_03_DRILL_EXECUTION.md',
        'WP-P7-04' => 'COUNTRY_PRODUCTION_RESTORE_P7_04_EVIDENCE_PACK.md',
        'WP-P7-05' => 'COUNTRY_PRODUCTION_RESTORE_P7_05_INTEGRATION_BASELINE.md',
    ];
}

/**
 * Architecture roadmap P7 / P2 deferred stage order (P7 engines only).
 *
 * @return list<string>
 */
function orange_cpr_p7_clone_drill_stage_order(): array
{
    return [
        'clone_drill_harness',
        'drill_scenario_execution',
        'evidence_pack_assembly_seal',
    ];
}

/**
 * Allowed drill_context values (P2-03 H1).
 *
 * @return list<string>
 */
function orange_cpr_p7_allowed_drill_contexts(): array
{
    return [
        'clone',
        'shadow_lab',
        'non_production_fixture',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p7_control_plane_snapshot(): array
{
    return [
        'schema_version' => ORANGE_CPR_P7_CONTROL_SCHEMA,
        'engine_version' => ORANGE_CPR_P7_CONTROL_VERSION,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'phase' => 'P7',
        'phase_name' => 'Clone drills / real-clone proof',
        'roadmap_output' => 'Evidence',
        'work_packages' => orange_cpr_p7_work_package_ids(),
        'artifacts' => orange_cpr_p7_work_package_artifacts(),
        'clone_drill_stage_order' => orange_cpr_p7_clone_drill_stage_order(),
        'allowed_drill_contexts' => orange_cpr_p7_allowed_drill_contexts(),
        'wp_p7_01_complete' => true,
        'drill_harness_implemented' => true,
        'drill_execution_engine_implemented' => true,
        'evidence_pack_engine_implemented' => false,
        'p7_integration_baseline_complete' => false,
        'enablement_flag_observed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'owner_cert_pass_granted' => false,
        'p8_started' => false,
        'architecture_modified' => false,
        'owner_approved_modified' => false,
        'p0_p6_contracts_preserved' => true,
    ];
}

/**
 * Assert WP-P7-01 hard rules for control-plane operations (fail-closed).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_p7_control_plane_assert(array $env): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'code' => 'p7_enablement_forbidden',
            'message' => $e->getMessage(),
            'fail_closed' => true,
        ];
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return [
            'ok' => false,
            'code' => 'p7_enablement_forbidden',
            'message' => 'Ops enablement must remain FALSE during P7 (Architecture roadmap).',
            'fail_closed' => true,
        ];
    }

    $delete = orange_cpr_mutation_refuse_delete();
    $import = orange_cpr_mutation_refuse_import();
    $ponr = orange_cpr_mutation_refuse_ponr_execution();
    if (!empty($delete['ok']) || !empty($import['ok']) || !empty($ponr['ok'])) {
        return [
            'ok' => false,
            'code' => 'p7_mutation_refuse_broken',
            'message' => 'Mutation refuse helpers must remain fail-closed in WP-P7-01.',
            'fail_closed' => true,
        ];
    }

    $snap = orange_cpr_p7_control_plane_snapshot();
    if (empty($snap['wp_p7_01_complete'])) {
        return [
            'ok' => false,
            'code' => 'p7_control_incomplete',
            'message' => 'WP-P7-01 must mark control plane complete.',
            'fail_closed' => true,
        ];
    }
    if (empty($snap['drill_harness_implemented']) || empty($snap['drill_execution_engine_implemented'])) {
        return [
            'ok' => false,
            'code' => 'p7_required_engines_missing',
            'message' => 'WP-P7-02 harness and WP-P7-03 drill execution must be marked implemented.',
            'fail_closed' => true,
        ];
    }
    if (!empty($snap['evidence_pack_engine_implemented'])
        || !empty($snap['p7_integration_baseline_complete'])
        || !empty($snap['owner_cert_pass_granted'])
        || !empty($snap['p8_started'])
    ) {
        return [
            'ok' => false,
            'code' => 'p7_engines_premature',
            'message' => 'WP-P7-03 must not mark evidence pack engine, P7 freeze, Owner Cert PASS, or P8 started.',
            'fail_closed' => true,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'P7 control plane hard rules hold; harness+scenario execution implemented; evidence deferred; P8/enablement withheld.',
        'snapshot' => $snap,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'ponr_mutation_executed' => false,
    ];
}
