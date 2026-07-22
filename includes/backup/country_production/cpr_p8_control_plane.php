<?php

declare(strict_types=1);

/**
 * CPR P8 Control Plane registry (WP-P8-01; flags updated through WP-P8-02).
 *
 * Inventory / hard-rule helpers — Owner Submission marked implemented after WP-P8-02.
 * Still no Owner Cert PASS/FAIL writer and no enablement flip.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_ARCHITECTURE.md roadmap P8
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';
require_once __DIR__ . '/cpr_mutation_engine.php';

const ORANGE_CPR_P8_CONTROL_SCHEMA = 'cpr_p8_control_plane/1';
const ORANGE_CPR_P8_CONTROL_VERSION = 'P8-02-1.0';

/**
 * Official P8 Work Package IDs (discovered from Architecture + P2 — not invented).
 *
 * @return list<string>
 */
function orange_cpr_p8_work_package_ids(): array
{
    return [
        'WP-P8-01',
        'WP-P8-02',
        'WP-P8-03',
        'WP-P8-04',
    ];
}

/**
 * Primary artifact filenames for each P8 WP.
 *
 * @return array<string, string>
 */
function orange_cpr_p8_work_package_artifacts(): array
{
    return [
        'WP-P8-01' => 'COUNTRY_PRODUCTION_RESTORE_P8_ARTIFACT_INDEX.md',
        'WP-P8-02' => 'COUNTRY_PRODUCTION_RESTORE_P8_02_OWNER_SUBMISSION.md',
        'WP-P8-03' => 'COUNTRY_PRODUCTION_RESTORE_P8_03_OWNER_CERT_DECISION.md',
        'WP-P8-04' => 'COUNTRY_PRODUCTION_RESTORE_P8_04_INTEGRATION_BASELINE.md',
    ];
}

/**
 * Architecture roadmap P8 / P2 deferred stage order (P8 engines only).
 *
 * @return list<string>
 */
function orange_cpr_p8_certification_stage_order(): array
{
    return [
        'owner_submission_assembly',
        'owner_cert_pass_fail_decision',
        'certification_baseline_freeze',
    ];
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_p8_control_plane_snapshot(): array
{
    return [
        'schema_version' => ORANGE_CPR_P8_CONTROL_SCHEMA,
        'engine_version' => ORANGE_CPR_P8_CONTROL_VERSION,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'phase' => 'P8',
        'phase_name' => 'Country Production certification',
        'roadmap_output' => 'Cert PASS/FAIL (Owner)',
        'work_packages' => orange_cpr_p8_work_package_ids(),
        'artifacts' => orange_cpr_p8_work_package_artifacts(),
        'certification_stage_order' => orange_cpr_p8_certification_stage_order(),
        'wp_p8_01_complete' => true,
        'owner_submission_engine_implemented' => true,
        'owner_cert_decision_engine_implemented' => false,
        'p8_integration_baseline_complete' => false,
        'enablement_flag_observed' => false,
        'ponr_mutation_executed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'owner_cert_pass_granted' => false,
        'engineering_cannot_grant_pass' => true,
        'cert_pass_does_not_enable' => true,
        'p9_started' => false,
        'architecture_modified' => false,
        'owner_approved_modified' => false,
        'p0_p7_contracts_preserved' => true,
    ];
}

/**
 * Assert P8 control-plane hard rules (fail-closed) after WP-P8-02.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_p8_control_plane_assert(array $env): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return [
            'ok' => false,
            'code' => 'p8_enablement_forbidden',
            'message' => $e->getMessage(),
            'fail_closed' => true,
        ];
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return [
            'ok' => false,
            'code' => 'p8_enablement_forbidden',
            'message' => 'Ops enablement must remain FALSE during P8 (Architecture roadmap).',
            'fail_closed' => true,
        ];
    }

    $delete = orange_cpr_mutation_refuse_delete();
    $import = orange_cpr_mutation_refuse_import();
    $ponr = orange_cpr_mutation_refuse_ponr_execution();
    if (!empty($delete['ok']) || !empty($import['ok']) || !empty($ponr['ok'])) {
        return [
            'ok' => false,
            'code' => 'p8_mutation_refuse_broken',
            'message' => 'Mutation refuse helpers must remain fail-closed in P8.',
            'fail_closed' => true,
        ];
    }

    $snap = orange_cpr_p8_control_plane_snapshot();
    if (empty($snap['wp_p8_01_complete']) || empty($snap['owner_submission_engine_implemented'])) {
        return [
            'ok' => false,
            'code' => 'p8_control_incomplete',
            'message' => 'WP-P8-01/02 control plane + Owner Submission engine must be marked complete/implemented.',
            'fail_closed' => true,
        ];
    }
    if (!empty($snap['owner_cert_decision_engine_implemented'])
        || !empty($snap['p8_integration_baseline_complete'])
    ) {
        return [
            'ok' => false,
            'code' => 'p8_engines_premature',
            'message' => 'WP-P8-02 must not mark WP-P8-03/04 engines/freeze implemented.',
            'fail_closed' => true,
        ];
    }
    if (!empty($snap['owner_cert_pass_granted']) || !empty($snap['p9_started'])) {
        return [
            'ok' => false,
            'code' => 'p8_boundary_violation',
            'message' => 'P8 must not grant Owner Cert PASS or start P9.',
            'fail_closed' => true,
        ];
    }
    if (empty($snap['engineering_cannot_grant_pass']) || empty($snap['cert_pass_does_not_enable'])) {
        return [
            'ok' => false,
            'code' => 'p8_od_cert_split_broken',
            'message' => 'OD-CERT / OD-ENABLE split constants must remain true.',
            'fail_closed' => true,
        ];
    }

    return [
        'ok' => true,
        'code' => 'ok',
        'message' => 'P8 control plane hard rules hold; Owner Submission implemented; Cert decision / P9 withheld.',
        'snapshot' => $snap,
        'enablement_flag_observed' => false,
        'production_mutation' => false,
        'production_sql_executed' => false,
        'ponr_mutation_executed' => false,
        'owner_cert_pass_granted' => false,
    ];
}
