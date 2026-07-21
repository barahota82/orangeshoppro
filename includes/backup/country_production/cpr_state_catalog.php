<?php

declare(strict_types=1);

/**
 * CPR state catalog + legal transition matrix (WP-P3-03).
 * Source of truth: CPR-P1-WP03-STATE_TRANSITION_MATRIX (§3, §5, §10 CSV).
 *
 * Scaffolding only — no DELETE/IMPORT/PONR mutation engines.
 */

/** @var list<string> */
const ORANGE_CPR_ALL_STATES = [
    'cpr_pending',
    'cpr_gates_validating',
    'cpr_awaiting_approvals',
    'cpr_contract_frozen',
    'cpr_maintenance_on',
    'cpr_anchor_pinning',
    'cpr_pre_ponr',
    'cpr_deleting',
    'cpr_importing',
    'cpr_uploads_applying',
    'cpr_post_verifying',
    'cpr_succeeded',
    'cpr_paused_delete_failed',
    'cpr_paused_import_failed',
    'cpr_paused_uploads_failed',
    'cpr_paused_verify_failed',
    'cpr_paused_rollback_failed',
    'cpr_rolling_back',
    'cpr_rollback_completed',
    'cpr_failed_pre_ponr',
    'cpr_cancelled_pre_ponr',
    'cpr_failed_post_ponr',
    'cpr_maintenance_released',
];

const ORANGE_CPR_ACTOR_SYSTEM = 'system';
const ORANGE_CPR_ACTOR_SUPER_ADMIN = 'super_admin';
const ORANGE_CPR_ACTOR_COUNTRY_ADMIN = 'country_admin';

const ORANGE_CPR_ERR_ILLEGAL_TRANSITION = 'illegal_cpr_status_transition';
const ORANGE_CPR_ERR_ACTOR_FORBIDDEN = 'cpr_actor_forbidden';
const ORANGE_CPR_ERR_COUNTRY_ADMIN_FORBIDDEN = 'cpr_country_admin_forbidden';
const ORANGE_CPR_ERR_ENABLEMENT = 'cpr_enablement_blocks_transition';
const ORANGE_CPR_ERR_CONTRACT = 'cpr_contract_guard_failed';
const ORANGE_CPR_ERR_IDENTITY = 'cpr_identity_mismatch';
const ORANGE_CPR_ERR_PONR = 'cpr_ponr_invariant_violation';
const ORANGE_CPR_ERR_TERMINAL = 'cpr_terminal_job';
const ORANGE_CPR_ERR_WORKFLOW = 'cpr_workflow_guard_failed';
const ORANGE_CPR_ERR_RUNBOOK = 'cpr_runbook_required';
const ORANGE_CPR_ERR_RESUME = 'cpr_resume_not_eligible';
const ORANGE_CPR_ERR_ROLLBACK = 'cpr_rollback_not_eligible';
const ORANGE_CPR_ERR_TIMEOUT_ALONE = 'cpr_timeout_alone_forbidden';
const ORANGE_CPR_ERR_AUTO_ROLLBACK = 'cpr_auto_rollback_forbidden';
const ORANGE_CPR_ERR_AUTO_UNLOCK = 'cpr_post_ponr_auto_unlock_forbidden';
const ORANGE_CPR_ERR_MUTATION = 'cpr_mutation_engine_forbidden';
const ORANGE_CPR_ERR_CHECKPOINT = 'cpr_checkpoint_binding_failed';
const ORANGE_CPR_ERR_UNKNOWN_STATE = 'cpr_unknown_state';
const ORANGE_CPR_ERR_JOB_NOT_FOUND = 'cpr_job_not_found';

/**
 * State flags: T terminal, P pause, A active, M maint expected, N pre-PONR, X post-PONR.
 *
 * @return array<string, array{terminal:bool,pause:bool,pre_ponr:bool,post_ponr:bool,maint_expected:bool}>
 */
function orange_cpr_state_meta_map(): array
{
    $mk = static function (
        bool $terminal,
        bool $pause,
        bool $prePonr,
        bool $postPonr,
        bool $maint
    ): array {
        return [
            'terminal' => $terminal,
            'pause' => $pause,
            'pre_ponr' => $prePonr,
            'post_ponr' => $postPonr,
            'maint_expected' => $maint,
        ];
    };

    return [
        'cpr_pending' => $mk(false, false, true, false, false),
        'cpr_gates_validating' => $mk(false, false, true, false, false),
        'cpr_awaiting_approvals' => $mk(false, false, true, false, false),
        'cpr_contract_frozen' => $mk(false, false, true, false, false),
        'cpr_maintenance_on' => $mk(false, false, true, false, true),
        'cpr_anchor_pinning' => $mk(false, false, true, false, true),
        'cpr_pre_ponr' => $mk(false, false, true, false, true),
        'cpr_deleting' => $mk(false, false, false, true, true),
        'cpr_importing' => $mk(false, false, false, true, true),
        'cpr_uploads_applying' => $mk(false, false, false, true, true),
        'cpr_post_verifying' => $mk(false, false, false, true, true),
        'cpr_succeeded' => $mk(true, false, false, true, true),
        'cpr_paused_delete_failed' => $mk(false, true, false, true, true),
        'cpr_paused_import_failed' => $mk(false, true, false, true, true),
        'cpr_paused_uploads_failed' => $mk(false, true, false, true, true),
        'cpr_paused_verify_failed' => $mk(false, true, false, true, true),
        'cpr_paused_rollback_failed' => $mk(false, true, false, true, true),
        'cpr_rolling_back' => $mk(false, false, false, true, true),
        'cpr_rollback_completed' => $mk(true, false, false, true, true),
        'cpr_failed_pre_ponr' => $mk(true, false, true, false, false),
        'cpr_cancelled_pre_ponr' => $mk(true, false, true, false, false),
        'cpr_failed_post_ponr' => $mk(true, false, false, true, true),
        'cpr_maintenance_released' => $mk(true, false, false, false, false),
    ];
}

/**
 * Legal transitions from P1-03 §10 CSV (+ T34 expansions + T60 expansions + T24E early cancel).
 *
 * T24E*: pre-PONR cancel family for states before maint (Architecture §28 / WP-P3-02 cancel).
 *
 * @return list<array{
 *   id:string,from:string,to:string,trigger:string,
 *   actors:list<string>,class:string,enters_ponr:bool,requires_runbook:bool,
 *   requires_phrase:bool,requires_safe_resume:bool,requires_contract_frozen:bool,
 *   requires_pin:bool,workflow:?string
 * }>
 */
function orange_cpr_legal_transitions(): array
{
    $t = static function (
        string $id,
        string $from,
        string $to,
        string $trigger,
        array $actors,
        string $class,
        array $extra = []
    ): array {
        return array_merge([
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'trigger' => $trigger,
            'actors' => $actors,
            'class' => $class,
            'enters_ponr' => false,
            'requires_runbook' => false,
            'requires_phrase' => false,
            'requires_safe_resume' => false,
            'requires_contract_frozen' => false,
            'requires_pin' => false,
            'workflow' => null,
        ], $extra);
    };

    $sys = [ORANGE_CPR_ACTOR_SYSTEM];
    $sa = [ORANGE_CPR_ACTOR_SUPER_ADMIN];
    $sysSa = [ORANGE_CPR_ACTOR_SYSTEM, ORANGE_CPR_ACTOR_SUPER_ADMIN];

    $rows = [
        $t('T01', 'cpr_pending', 'cpr_gates_validating', 'start_validation', $sysSa, 'pre_ponr'),
        $t('T02', 'cpr_gates_validating', 'cpr_awaiting_approvals', 'gates_ok_wfb', $sys, 'pre_ponr', [
            'workflow' => 'B',
        ]),
        $t('T03', 'cpr_gates_validating', 'cpr_contract_frozen', 'gates_ok_wfa_freeze', $sysSa, 'pre_ponr', [
            'workflow' => 'A',
            'requires_contract_frozen' => false,
        ]),
        $t('T04', 'cpr_awaiting_approvals', 'cpr_contract_frozen', 'super_admin_approve', $sa, 'pre_ponr', [
            'workflow' => 'B',
        ]),
        $t('T05', 'cpr_awaiting_approvals', 'cpr_failed_pre_ponr', 'reject_or_cancel', $sysSa, 'pre_ponr_fail'),
        $t('T06', 'cpr_contract_frozen', 'cpr_maintenance_on', 'enter_global_maint', $sysSa, 'pre_ponr', [
            'requires_contract_frozen' => true,
        ]),
        $t('T07', 'cpr_maintenance_on', 'cpr_anchor_pinning', 'start_od_pin_backup', $sys, 'pre_ponr'),
        $t('T08', 'cpr_anchor_pinning', 'cpr_pre_ponr', 'pin_verified', $sys, 'pre_ponr', [
            'requires_pin' => true,
        ]),
        $t('T09', 'cpr_pre_ponr', 'cpr_deleting', 'authorize_ponr_start_delete', $sa, 'ponr_edge', [
            'enters_ponr' => true,
            'requires_phrase' => true,
            'requires_runbook' => true,
        ]),
        $t('T10', 'cpr_deleting', 'cpr_importing', 'delete_complete', $sys, 'post_ponr'),
        $t('T11', 'cpr_importing', 'cpr_uploads_applying', 'import_complete', $sys, 'post_ponr'),
        $t('T12', 'cpr_uploads_applying', 'cpr_post_verifying', 'uploads_complete', $sys, 'post_ponr'),
        $t('T13', 'cpr_post_verifying', 'cpr_succeeded', 'verify_pass', $sys, 'post_ponr'),
        $t('T14', 'cpr_succeeded', 'cpr_maintenance_released', 'super_admin_release_maint', $sa, 'maint_release', [
            'requires_runbook' => true,
        ]),
        $t('T20', 'cpr_gates_validating', 'cpr_failed_pre_ponr', 'gate_fail', $sys, 'pre_ponr_fail'),
        $t('T21', 'cpr_contract_frozen', 'cpr_failed_pre_ponr', 'pre_ponr_fail', $sys, 'pre_ponr_fail'),
        $t('T22', 'cpr_maintenance_on', 'cpr_cancelled_pre_ponr', 'cancel_or_estop', $sa, 'pre_ponr_cancel'),
        $t('T23', 'cpr_anchor_pinning', 'cpr_failed_pre_ponr', 'pin_fail', $sys, 'pre_ponr_fail'),
        $t('T24', 'cpr_pre_ponr', 'cpr_cancelled_pre_ponr', 'cancel_or_estop', $sysSa, 'pre_ponr_cancel'),
        // Early pre-PONR cancel (Architecture §28 / WP-P3-02) — before maint ON.
        $t('T24E_PENDING', 'cpr_pending', 'cpr_cancelled_pre_ponr', 'cancel_pre_ponr', $sysSa, 'pre_ponr_cancel'),
        $t('T24E_GATES', 'cpr_gates_validating', 'cpr_cancelled_pre_ponr', 'cancel_pre_ponr', $sysSa, 'pre_ponr_cancel'),
        $t('T24E_APPROVALS', 'cpr_awaiting_approvals', 'cpr_cancelled_pre_ponr', 'cancel_pre_ponr', $sysSa, 'pre_ponr_cancel'),
        $t('T24E_FROZEN', 'cpr_contract_frozen', 'cpr_cancelled_pre_ponr', 'cancel_pre_ponr', $sysSa, 'pre_ponr_cancel'),
        $t('T25', 'cpr_failed_pre_ponr', 'cpr_maintenance_released', 'release_maint', $sa, 'maint_release', [
            'requires_runbook' => true,
        ]),
        $t('T26', 'cpr_cancelled_pre_ponr', 'cpr_maintenance_released', 'release_maint', $sa, 'maint_release', [
            'requires_runbook' => true,
        ]),
        $t('T30', 'cpr_deleting', 'cpr_paused_delete_failed', 'delete_fail', $sys, 'fail_pause'),
        $t('T31', 'cpr_importing', 'cpr_paused_import_failed', 'import_fail', $sys, 'fail_pause'),
        $t('T32', 'cpr_uploads_applying', 'cpr_paused_uploads_failed', 'uploads_fail', $sys, 'fail_pause'),
        $t('T33', 'cpr_post_verifying', 'cpr_paused_verify_failed', 'verify_fail', $sys, 'fail_pause'),
        // T34 emergency stop → stage pause
        $t('T34_DELETE', 'cpr_deleting', 'cpr_paused_delete_failed', 'emergency_stop_post_ponr', $sysSa, 'fail_pause'),
        $t('T34_IMPORT', 'cpr_importing', 'cpr_paused_import_failed', 'emergency_stop_post_ponr', $sysSa, 'fail_pause'),
        $t('T34_UPLOADS', 'cpr_uploads_applying', 'cpr_paused_uploads_failed', 'emergency_stop_post_ponr', $sysSa, 'fail_pause'),
        $t('T34_VERIFY', 'cpr_post_verifying', 'cpr_paused_verify_failed', 'emergency_stop_post_ponr', $sysSa, 'fail_pause'),
        $t('T40', 'cpr_paused_delete_failed', 'cpr_deleting', 'resume', $sa, 'resume', [
            'requires_safe_resume' => true,
        ]),
        $t('T41', 'cpr_paused_import_failed', 'cpr_importing', 'resume', $sa, 'resume', [
            'requires_safe_resume' => true,
        ]),
        $t('T42', 'cpr_paused_uploads_failed', 'cpr_uploads_applying', 'resume', $sa, 'resume', [
            'requires_safe_resume' => true,
        ]),
        $t('T43', 'cpr_paused_verify_failed', 'cpr_post_verifying', 'resume', $sa, 'resume', [
            'requires_safe_resume' => true,
        ]),
        $t('T50', 'cpr_paused_delete_failed', 'cpr_rolling_back', 'rollback_action', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T51', 'cpr_paused_import_failed', 'cpr_rolling_back', 'rollback_action', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T52', 'cpr_paused_uploads_failed', 'cpr_rolling_back', 'rollback_action', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T53', 'cpr_paused_verify_failed', 'cpr_rolling_back', 'rollback_action', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T54', 'cpr_rolling_back', 'cpr_rollback_completed', 'rollback_ok', $sys, 'rollback'),
        $t('T55', 'cpr_rolling_back', 'cpr_paused_rollback_failed', 'rollback_fail', $sys, 'fail_pause'),
        $t('T56', 'cpr_paused_rollback_failed', 'cpr_rolling_back', 'retry_rollback', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T57', 'cpr_rollback_completed', 'cpr_maintenance_released', 'release_maint', $sa, 'maint_release', [
            'requires_runbook' => true,
        ]),
        $t('T61', 'cpr_failed_post_ponr', 'cpr_rolling_back', 'rollback_action', $sa, 'rollback', [
            'requires_phrase' => true,
        ]),
        $t('T62', 'cpr_failed_post_ponr', 'cpr_maintenance_released', 'release_maint_exceptional', $sa, 'maint_release', [
            'requires_runbook' => true,
        ]),
    ];

    foreach ([
        'cpr_paused_delete_failed',
        'cpr_paused_import_failed',
        'cpr_paused_uploads_failed',
        'cpr_paused_verify_failed',
        'cpr_paused_rollback_failed',
    ] as $pause) {
        $suffix = str_replace('cpr_paused_', '', $pause);
        $rows[] = $t(
            'T60_' . strtoupper($suffix),
            $pause,
            'cpr_failed_post_ponr',
            'incident_close',
            $sa,
            'incident'
        );
    }

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_find_transition(string $from, string $to, ?string $trigger = null): ?array
{
    foreach (orange_cpr_legal_transitions() as $row) {
        if ($row['from'] !== $from || $row['to'] !== $to) {
            continue;
        }
        if ($trigger !== null && $trigger !== '' && $row['trigger'] !== $trigger) {
            continue;
        }

        return $row;
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function orange_cpr_transitions_from(string $from): array
{
    $out = [];
    foreach (orange_cpr_legal_transitions() as $row) {
        if ($row['from'] === $from) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @return list<string>
 */
function orange_cpr_fail_pause_states(): array
{
    return [
        'cpr_paused_delete_failed',
        'cpr_paused_import_failed',
        'cpr_paused_uploads_failed',
        'cpr_paused_verify_failed',
        'cpr_paused_rollback_failed',
    ];
}

/**
 * @return list<string>
 */
function orange_cpr_terminal_states(): array
{
    $out = [];
    foreach (orange_cpr_state_meta_map() as $state => $meta) {
        if ($meta['terminal']) {
            $out[] = $state;
        }
    }

    return $out;
}
