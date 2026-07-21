<?php

declare(strict_types=1);

/**
 * CPR checkpoint catalog (WP-P3-04) — CPR-P1-WP04-CHECKPOINT_SCHEMAS.
 */

const ORANGE_CPR_CHECKPOINT_SCHEMA_VERSION = '1.0';

const ORANGE_CPR_CP_ERR_MISSING = 'cpr_checkpoint_missing';
const ORANGE_CPR_CP_ERR_CORRUPT = 'cpr_checkpoint_corrupt';
const ORANGE_CPR_CP_ERR_VERSION = 'cpr_checkpoint_version_mismatch';
const ORANGE_CPR_CP_ERR_PREREQ = 'cpr_checkpoint_prereq_missing';
const ORANGE_CPR_CP_ERR_OD_PIN = 'cpr_checkpoint_od_pin_order';
const ORANGE_CPR_CP_ERR_STATE = 'cpr_checkpoint_state_binding';
const ORANGE_CPR_CP_ERR_IDENTITY = 'cpr_checkpoint_identity';
const ORANGE_CPR_CP_ERR_PONR = 'cpr_checkpoint_ponr_discipline';
const ORANGE_CPR_CP_ERR_DUPLICATE = 'cpr_checkpoint_duplicate_mismatch';
const ORANGE_CPR_CP_ERR_INTEGRITY = 'cpr_checkpoint_integrity_failed';
const ORANGE_CPR_CP_ERR_SCHEMA = 'cpr_checkpoint_schema_invalid';
const ORANGE_CPR_CP_ERR_TORN = 'cpr_checkpoint_torn_tmp_ignored';
const ORANGE_CPR_CP_ERR_REPAIR = 'cpr_checkpoint_silent_repair_forbidden';
const ORANGE_CPR_CP_ERR_ENABLEMENT = 'cpr_enablement_blocks_checkpoint';
const ORANGE_CPR_CP_ERR_CONTRACT = 'cpr_checkpoint_contract_required';
const ORANGE_CPR_CP_ERR_UNKNOWN = 'cpr_checkpoint_unknown_id';

/**
 * Ordered write DAG (OD-PIN: CP4 before CP1).
 *
 * @return list<string>
 */
function orange_cpr_checkpoint_write_order(): array
{
    return [
        'CP0',
        'CP2',
        'CP3',
        'CP4',
        'CP1',
        'runbook_pre_ponr',
        'CP5',
        'CP-A',
        'CP6',
        'CP7',
        'CP8',
        'CP9',
        'CP10',
        'CP11',
        'CP12',
    ];
}

/**
 * @return array<string, array{
 *   name:string,filename:string,class:string,
 *   requires:list<string>,allowed_states:list<string>,
 *   payload_required:list<string>,pre_ponr:bool,post_ponr:bool
 * }>
 */
function orange_cpr_checkpoint_definitions(): array
{
    return [
        'CP0' => [
            'name' => 'gates_passed',
            'filename' => 'CP0_gates_passed.json',
            'class' => 'pre_ponr',
            'requires' => [],
            'allowed_states' => ['cpr_gates_validating'],
            'payload_required' => [
                'c4_overall', 'c5_overall', 'c5_recovery_score', 'c6_status', 'c7_overall',
                'c7_readiness_score', 'c8_overall_result', 'enablement_flag_observed',
                'schema_revision_observed', 'boundary_policy_version', 'report_hashes',
            ],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP2' => [
            'name' => 'approvals_complete',
            'filename' => 'CP2_approvals_complete.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0'],
            'allowed_states' => ['cpr_awaiting_approvals', 'cpr_gates_validating', 'cpr_contract_frozen'],
            'payload_required' => ['workflow', 'approval_fingerprint'],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP3' => [
            'name' => 'contract_frozen',
            'filename' => 'CP3_contract_frozen.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2'],
            'allowed_states' => ['cpr_contract_frozen'],
            'payload_required' => ['contract_revision', 'contract_phase', 'package_fingerprint', 'fingerprint_digest'],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP4' => [
            'name' => 'maintenance_verified',
            'filename' => 'CP4_maintenance_verified.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2', 'CP3'],
            'allowed_states' => ['cpr_maintenance_on'],
            'payload_required' => ['global_maintenance_on', 'write_block_proof', 'maint_entered_at'],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP1' => [
            'name' => 'anchor_pinned',
            'filename' => 'CP1_anchor_pinned.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2', 'CP3', 'CP4'],
            'allowed_states' => ['cpr_anchor_pinning'],
            'payload_required' => [
                'session_full_backup_id', 'session_full_backup_fingerprint', 'verified', 'pinned',
                'created_under_maintenance', 'reused_existing_backup', 'cp4_reference',
            ],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'runbook_pre_ponr' => [
            'name' => 'runbook_pre_ponr',
            'filename' => 'runbook_pre_ponr.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2', 'CP3', 'CP4', 'CP1'],
            'allowed_states' => ['cpr_pre_ponr'],
            'payload_required' => [
                'restore_package_id', 'target_country_id', 'target_country_code', 'c8_overall_result',
                'certified_inventory_snapshot_id', 'session_full_backup_id', 'global_maintenance_active',
                'completed_by_admin_id', 'completed_at', 'audit_record_id',
            ],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP5' => [
            'name' => 'pre_ponr_witnesses',
            'filename' => 'CP5_pre_ponr_witnesses.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr'],
            'allowed_states' => ['cpr_pre_ponr'],
            'payload_required' => [
                'survivor_baseline_hash', 'global_baseline_hash', 'target_inventory_hash',
                'inventory_snapshot_id', 'captured_at',
            ],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP-A' => [
            'name' => 'last_reversible',
            'filename' => 'CPA_last_reversible.json',
            'class' => 'pre_ponr',
            'requires' => ['CP0', 'CP2', 'CP3', 'CP4', 'CP1', 'runbook_pre_ponr', 'CP5'],
            'allowed_states' => ['cpr_pre_ponr'],
            'payload_required' => [
                'runbook_evidence_ref', 'one_time_authorization_id', 'phrase_challenge_id',
                'contract_phase', 'cp1_session_full_backup_id', 'reversible', 'ponr_not_entered',
            ],
            'pre_ponr' => true,
            'post_ponr' => false,
        ],
        'CP6' => [
            'name' => 'delete_complete',
            'filename' => 'CP6_delete_complete.json',
            'class' => 'post_ponr',
            'requires' => ['CP-A'],
            'allowed_states' => ['cpr_deleting'],
            'payload_required' => ['tables_completed', 'delete_order_version', 'ponr_entered_at'],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP7' => [
            'name' => 'import_complete',
            'filename' => 'CP7_import_complete.json',
            'class' => 'post_ponr',
            'requires' => ['CP6'],
            'allowed_states' => ['cpr_importing'],
            'payload_required' => ['batches_completed', 'rows_imported'],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP8' => [
            'name' => 'special_handlers_complete',
            'filename' => 'CP8_special_handlers_complete.json',
            'class' => 'post_ponr',
            'requires' => ['CP7'],
            'allowed_states' => ['cpr_importing'],
            'payload_required' => ['handlers', 'counters_not_lowered_ack'],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP9' => [
            'name' => 'uploads_complete',
            'filename' => 'CP9_uploads_complete.json',
            'class' => 'post_ponr',
            'requires' => ['CP8'],
            'allowed_states' => ['cpr_uploads_applying'],
            'payload_required' => ['scoped_only', 'pre_image_manifest_id', 'files_applied_count'],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP10' => [
            'name' => 'post_verify_pass',
            'filename' => 'CP10_post_verify_pass.json',
            'class' => 'post_ponr',
            'requires' => ['CP9'],
            'allowed_states' => ['cpr_post_verifying'],
            'payload_required' => [
                'verify_suite_result', 'survivor_hash_match_cp5', 'global_hash_match_cp5', 'integrity_waiver',
            ],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP11' => [
            'name' => 'success_finalized',
            'filename' => 'CP11_success_finalized.json',
            'class' => 'post_ponr',
            'requires' => ['CP10'],
            'allowed_states' => ['cpr_succeeded'],
            'payload_required' => ['reports_sealed', 'report_ids'],
            'pre_ponr' => false,
            'post_ponr' => true,
        ],
        'CP12' => [
            'name' => 'maint_released',
            'filename' => 'CP12_maint_released.json',
            'class' => 'closeout',
            'requires' => [], // special: CP11 OR rollback_completed path
            'allowed_states' => [
                'cpr_succeeded',
                'cpr_rollback_completed',
                'cpr_failed_pre_ponr',
                'cpr_cancelled_pre_ponr',
                'cpr_maintenance_released',
            ],
            'payload_required' => [
                'released_by_admin_id', 'runbook_completed', 'prior_terminal', 'writers_restored',
            ],
            'pre_ponr' => false,
            'post_ponr' => false,
        ],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_checkpoint_definition(string $checkpointId): ?array
{
    $all = orange_cpr_checkpoint_definitions();

    return $all[$checkpointId] ?? null;
}
