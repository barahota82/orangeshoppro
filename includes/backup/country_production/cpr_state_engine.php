<?php

declare(strict_types=1);

/**
 * CPR State Engine & Transition Enforcement (WP-P3-03).
 *
 * Validates/applies job status transitions per CPR-P1-WP03-STATE_TRANSITION_MATRIX.
 * Fail-closed. No automatic rollback. No post-PONR auto-unlock.
 * Scaffold apply updates job/audit records only — never DELETE/IMPORT engines.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_03_STATE_SCAFFOLD.md
 * @see CPR-P1-WP03-STATE_TRANSITION_MATRIX
 * @see CPR-P1-WP09-FAIL_RESUME_ROLLBACK
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_state_catalog.php';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_transition_result_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'message' => 'Transition allowed.',
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_transition_result_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
    ], $extra);
}

/**
 * Hard refuse: post-PONR automatic unlock is never a legal transition effect.
 *
 * @return array<string, mixed>
 */
function orange_cpr_refuse_post_ponr_auto_unlock(): array
{
    return orange_cpr_transition_result_fail(
        ORANGE_CPR_ERR_AUTO_UNLOCK,
        'Post-PONR automatic unlock is forbidden (OD-LOCK-TTL; P1-03 R2).'
    );
}

/**
 * Hard refuse: automatic rollback is never legal.
 *
 * @return array<string, mixed>
 */
function orange_cpr_refuse_auto_rollback(): array
{
    return orange_cpr_transition_result_fail(
        ORANGE_CPR_ERR_AUTO_ROLLBACK,
        'Automatic rollback is forbidden (OD-ROLLBACK; P1-03 R1).'
    );
}

/**
 * Resume eligibility (Super Admin + fail-pause + safe continuation flag).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_resume_eligibility(array $job, array $context = []): array
{
    $actor = (string) ($context['actor'] ?? '');
    $state = (string) ($job['state'] ?? '');
    $base = [
        'eligible' => false,
        'from_state' => $state,
        'to_state' => null,
        'transition_id' => null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
    ];

    if ($actor === ORANGE_CPR_ACTOR_COUNTRY_ADMIN) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_COUNTRY_ADMIN_FORBIDDEN,
            'message' => 'Country Admin cannot Resume (OD-PERM; P1-03 R6).',
        ]);
    }
    if ($actor !== ORANGE_CPR_ACTOR_SUPER_ADMIN) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_ACTOR_FORBIDDEN,
            'message' => 'Resume requires Super Admin.',
        ]);
    }
    if (!in_array($state, orange_cpr_fail_pause_states(), true) || $state === 'cpr_paused_rollback_failed') {
        // Resume maps T40–T43 only (not rollback-failed → that is T56 retry rollback).
        if ($state === 'cpr_paused_rollback_failed') {
            return array_merge($base, [
                'code' => ORANGE_CPR_ERR_RESUME,
                'message' => 'Rollback-failed pause uses Retry Rollback (T56), not Resume.',
            ]);
        }

        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_RESUME,
            'message' => 'Resume only from post-PONR fail-pause states (OD-FAIL-*).',
        ]);
    }
    if (empty($job['ponr_crossed'])) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_PONR,
            'message' => 'Resume requires ponr_crossed=true.',
        ]);
    }
    if (empty($context['safe_resume'])) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_RESUME,
            'message' => 'Resume not eligible: stage does not safely support continuation (P1-09).',
        ]);
    }

    $map = [
        'cpr_paused_delete_failed' => ['cpr_deleting', 'T40'],
        'cpr_paused_import_failed' => ['cpr_importing', 'T41'],
        'cpr_paused_uploads_failed' => ['cpr_uploads_applying', 'T42'],
        'cpr_paused_verify_failed' => ['cpr_post_verifying', 'T43'],
    ];
    [$to, $tid] = $map[$state];

    return array_merge($base, [
        'eligible' => true,
        'code' => 'ok',
        'message' => 'Resume eligible for Super Admin safe continuation.',
        'to_state' => $to,
        'transition_id' => $tid,
    ]);
}

/**
 * Rollback eligibility (Super Admin + fail-pause or failed_post_ponr; never Country Admin).
 *
 * @param array<string, mixed> $job
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_rollback_eligibility(array $job, array $context = []): array
{
    $actor = (string) ($context['actor'] ?? '');
    $state = (string) ($job['state'] ?? '');
    $base = [
        'eligible' => false,
        'from_state' => $state,
        'to_state' => 'cpr_rolling_back',
        'transition_id' => null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
    ];

    if ($actor === ORANGE_CPR_ACTOR_COUNTRY_ADMIN) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_COUNTRY_ADMIN_FORBIDDEN,
            'message' => 'Country Admin cannot Rollback (OD-PERM; OD-ROLLBACK).',
        ]);
    }
    if ($actor !== ORANGE_CPR_ACTOR_SUPER_ADMIN) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_ACTOR_FORBIDDEN,
            'message' => 'Rollback requires Super Admin dashboard action.',
        ]);
    }
    if (!empty($context['auto_rollback'])) {
        return array_merge($base, orange_cpr_refuse_auto_rollback(), ['eligible' => false]);
    }

    $pauseToTid = [
        'cpr_paused_delete_failed' => 'T50',
        'cpr_paused_import_failed' => 'T51',
        'cpr_paused_uploads_failed' => 'T52',
        'cpr_paused_verify_failed' => 'T53',
        'cpr_paused_rollback_failed' => 'T56',
        'cpr_failed_post_ponr' => 'T61',
    ];
    if (!isset($pauseToTid[$state])) {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_ROLLBACK,
            'message' => 'Rollback only when paused on failure (or restricted post-PONR incident) — OD-ROLLBACK.',
        ]);
    }
    if (empty($job['ponr_crossed']) && $state !== 'cpr_failed_post_ponr') {
        return array_merge($base, [
            'code' => ORANGE_CPR_ERR_PONR,
            'message' => 'Rollback requires post-PONR context.',
        ]);
    }

    return array_merge($base, [
        'eligible' => true,
        'code' => 'ok',
        'message' => 'Rollback eligible for Super Admin (never automatic).',
        'transition_id' => $pauseToTid[$state],
    ]);
}

/**
 * Validate a proposed transition (fail-closed). Does not mutate.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_transition_validate(
    array $env,
    string $jobId,
    string $toState,
    array $context = []
): array {
    if (!empty($context['auto_rollback'])) {
        return orange_cpr_refuse_auto_rollback();
    }
    if (!empty($context['post_ponr_auto_unlock'])) {
        return orange_cpr_refuse_post_ponr_auto_unlock();
    }
    if (!empty($context['timeout_alone'])) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_TIMEOUT_ALONE,
            'Timeout alone must not transition to failure or Rollback (OD-TIMEOUT; P1-03 R5).'
        );
    }

    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_ENABLEMENT, $e->getMessage());
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_JOB_NOT_FOUND, $e->getMessage());
    }

    $from = (string) ($job['state'] ?? '');
    if (!in_array($from, ORANGE_CPR_ALL_STATES, true)) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_UNKNOWN_STATE, 'Unknown from state: ' . $from);
    }
    if (!in_array($toState, ORANGE_CPR_ALL_STATES, true)) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_UNKNOWN_STATE, 'Unknown to state: ' . $toState);
    }

    $meta = orange_cpr_state_meta_map();
    if (!empty($meta[$from]['terminal']) && $toState !== 'cpr_maintenance_released') {
        // Terminals may only leave via maint-release edges (T25/T26/T14/T57/T62).
        $edge = orange_cpr_find_transition($from, $toState, $context['trigger'] ?? null);
        if ($edge === null) {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_TERMINAL,
                'Terminal job cannot re-enter active states; create a new job (P1-02).',
                ['from_state' => $from, 'to_state' => $toState]
            );
        }
    }

    $trigger = isset($context['trigger']) ? (string) $context['trigger'] : null;
    $transition = orange_cpr_find_transition($from, $toState, $trigger);
    if ($transition === null && $trigger !== null && $trigger !== '') {
        // Retry without trigger filter if specific trigger mismatched but edge exists.
        $transition = orange_cpr_find_transition($from, $toState, null);
        if ($transition !== null && $transition['trigger'] !== $trigger) {
            $transition = null;
        }
    }
    if ($transition === null) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_ILLEGAL_TRANSITION,
            'Illegal CPR status transition.',
            [
                'from_state' => $from,
                'to_state' => $toState,
                'trigger' => $trigger,
                'job_id' => $jobId,
            ]
        );
    }

    $actor = (string) ($context['actor'] ?? '');
    if ($actor === ORANGE_CPR_ACTOR_COUNTRY_ADMIN) {
        $forbiddenClasses = ['resume', 'rollback', 'maint_release', 'ponr_edge', 'incident'];
        if (in_array($transition['class'], $forbiddenClasses, true)
            || in_array($transition['id'], ['T09', 'T14', 'T22', 'T25', 'T26', 'T57', 'T62'], true)
            || str_starts_with($transition['id'], 'T40')
            || str_starts_with($transition['id'], 'T5')
            || str_starts_with($transition['id'], 'T60')
        ) {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_COUNTRY_ADMIN_FORBIDDEN,
                'Country Admin cannot execute / Resume / Rollback / maint-release (OD-PERM).',
                [
                    'from_state' => $from,
                    'to_state' => $toState,
                    'transition_id' => $transition['id'],
                ]
            );
        }
    }
    if (!in_array($actor, $transition['actors'], true)) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_ACTOR_FORBIDDEN,
            'Actor not permitted for transition ' . $transition['id'] . '.',
            [
                'from_state' => $from,
                'to_state' => $toState,
                'transition_id' => $transition['id'],
                'actor' => $actor,
            ]
        );
    }

    // Identity binding
    if (isset($context['job_id']) && (string) $context['job_id'] !== $jobId) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'job_id mismatch.');
    }
    if (isset($context['package_fingerprint'])
        && (string) $context['package_fingerprint'] !== (string) ($job['package_fingerprint'] ?? '')
    ) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'package_fingerprint mismatch vs job.');
    }
    if (isset($context['country_id']) && (int) $context['country_id'] !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'country_id mismatch vs job.');
    }

    $workflow = (string) ($job['workflow'] ?? '');
    if ($transition['workflow'] !== null && $transition['workflow'] !== $workflow) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_WORKFLOW,
            'Workflow guard failed for ' . $transition['id'] . ' (expected ' . $transition['workflow'] . ').',
            ['transition_id' => $transition['id'], 'workflow' => $workflow]
        );
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if ($transition['requires_contract_frozen'] || in_array($transition['id'], ['T06', 'T03', 'T04'], true)) {
        if ($transition['id'] === 'T06') {
            if (!is_array($contract) || empty($contract['contract_frozen'])) {
                return orange_cpr_transition_result_fail(
                    ORANGE_CPR_ERR_CONTRACT,
                    'T06 requires frozen execution contract.',
                    ['transition_id' => 'T06']
                );
            }
        }
        if (in_array($transition['id'], ['T03', 'T04'], true)) {
            // Contract may be frozen by this transition via separate freeze API; require fingerprints intent.
            if (empty($context['contract_fingerprints_ok']) && !(is_array($contract) && !empty($contract['contract_frozen']))) {
                return orange_cpr_transition_result_fail(
                    ORANGE_CPR_ERR_CONTRACT,
                    $transition['id'] . ' requires contract fingerprints OK or already frozen (P1-02).',
                    ['transition_id' => $transition['id']]
                );
            }
        }
        if (is_array($contract)) {
            if ((string) ($contract['job_id'] ?? '') !== $jobId) {
                return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'Contract job_id mismatch.');
            }
            if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
                return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'Contract package drift.');
            }
            if ((int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
                return orange_cpr_transition_result_fail(ORANGE_CPR_ERR_IDENTITY, 'Contract country drift.');
            }
        }
    }

    if ($transition['requires_pin']) {
        $pinned = !empty($context['session_full_backup_pinned'])
            || (is_array($contract) && !empty($contract['session_full_backup_pinned']));
        if (!$pinned) {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_CONTRACT,
                'T08 requires session_full_backup_pinned=true (OD-PIN).',
                ['transition_id' => 'T08']
            );
        }
    }

    if ($transition['requires_runbook'] && empty($context['runbook_completed'])) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_RUNBOOK,
            'Runbook completion required (OD-RUNBOOK).',
            ['transition_id' => $transition['id']]
        );
    }
    if ($transition['requires_phrase']) {
        $phraseOk = !empty($context['phrase_ok']) && !empty($context['reauth_ok']);
        if (!$phraseOk) {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_ACTOR_FORBIDDEN,
                'Phrase RESTORE + re-auth required (OD-PHRASE).',
                ['transition_id' => $transition['id']]
            );
        }
    }
    if ($transition['requires_safe_resume'] && empty($context['safe_resume'])) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_RESUME,
            'Resume requires safe stage continuation (P1-09).',
            ['transition_id' => $transition['id']]
        );
    }

    // PONR invariants
    $ponr = !empty($job['ponr_crossed']);
    if ($transition['enters_ponr'] && $ponr) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_PONR,
            'PONR already crossed; use Resume/Rollback paths, not T09.',
            ['transition_id' => $transition['id']]
        );
    }
    if ($transition['class'] === 'ponr_edge') {
        $c8 = (string) ($context['c8_overall_result'] ?? (is_array($contract) ? ($contract['c8_overall_result'] ?? '') : ''));
        if ($c8 !== 'SAFE') {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_CONTRACT,
                'T09 requires C8 SAFE (OD-C8).',
                ['transition_id' => 'T09']
            );
        }
    }
    $needsPonr = in_array($transition['class'], ['post_ponr', 'fail_pause', 'resume', 'rollback', 'incident'], true);
    if ($needsPonr && !$ponr && empty($transition['enters_ponr'])) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_PONR,
            'Post-PONR transition requires ponr_crossed=true.',
            ['transition_id' => $transition['id']]
        );
    }

    // Checkpoint binding (scaffold): when caller supplies expected checkpoint, it must match job.
    if (array_key_exists('expected_checkpoint_id', $context)) {
        $expected = $context['expected_checkpoint_id'];
        $actual = $job['last_checkpoint_id'] ?? null;
        if ($expected !== $actual) {
            return orange_cpr_transition_result_fail(
                ORANGE_CPR_ERR_CHECKPOINT,
                'Checkpoint binding mismatch.',
                [
                    'expected_checkpoint_id' => $expected,
                    'last_checkpoint_id' => $actual,
                    'transition_id' => $transition['id'],
                ]
            );
        }
    }

    // Mutation engines must not be invoked by this scaffold.
    if (!empty($context['invoke_delete_engine']) || !empty($context['invoke_import_engine'])
        || !empty($context['invoke_ponr_mutation'])
    ) {
        return orange_cpr_transition_result_fail(
            ORANGE_CPR_ERR_MUTATION,
            'DELETE/IMPORT/PONR mutation engines are forbidden in P3 scaffolding.',
            ['transition_id' => $transition['id']]
        );
    }

    return orange_cpr_transition_result_ok([
        'from_state' => $from,
        'to_state' => $toState,
        'transition_id' => $transition['id'],
        'trigger' => $transition['trigger'],
        'class' => $transition['class'],
        'actor' => $actor,
        'job_id' => $jobId,
        'ponr_crossed' => $ponr,
        'enters_ponr' => $transition['enters_ponr'],
        'auto_rollback' => false,
        'post_ponr_auto_unlock' => false,
        'scaffold_record_only' => true,
    ]);
}

/**
 * Apply a validated transition to job record + audit (scaffold only).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_transition_apply(
    array $env,
    string $jobId,
    string $toState,
    array $context = []
): array {
    $validation = orange_cpr_transition_validate($env, $jobId, $toState, $context);
    if (empty($validation['ok'])) {
        return $validation;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $job = orange_cpr_job_read($cprRoot, $jobId);
    $from = (string) $job['state'];
    $transitionId = (string) $validation['transition_id'];
    $now = gmdate('c');

    $job['state'] = $toState;
    $job['updated_at'] = $now;
    $job['last_transition_id'] = $transitionId;
    $job['last_transition_at'] = $now;

    if (!empty($validation['enters_ponr'])) {
        $job['ponr_crossed'] = true;
        // Scaffold marks intent only — engines remain off.
        $job['mutation_engines'] = [
            'delete' => false,
            'import' => false,
            'uploads' => false,
            'ponr' => false,
        ];
        $job['ponr_scaffold_recorded'] = true;
        $job['ponr_mutation_executed'] = false;
    }

    $meta = orange_cpr_state_meta_map()[$toState] ?? null;
    if (is_array($meta) && !empty($meta['post_ponr'])) {
        $job['ponr_crossed'] = true;
    }
    if ($toState === ORANGE_CPR_STATE_CANCELLED_PRE_PONR) {
        $job['cancelled_at'] = $now;
        $job['cancel_reason'] = (string) ($context['reason'] ?? 'cancelled');
        $job['cancelled_by_admin_id'] = $context['actor_admin_id'] ?? null;
    }
    if (array_key_exists('last_checkpoint_id', $context)) {
        $job['last_checkpoint_id'] = $context['last_checkpoint_id'];
    }

    // Never auto-unlock.
    $job['post_ponr_lock_auto_unlocked'] = false;
    $job['auto_rollback_executed'] = false;

    orange_cpr_job_write($cprRoot, $jobId, $job);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.state_transition',
        'job_id' => $jobId,
        'transition_id' => $transitionId,
        'from_state' => $from,
        'to_state' => $toState,
        'actor' => (string) ($context['actor'] ?? ''),
        'actor_admin_id' => $context['actor_admin_id'] ?? null,
        'ponr_crossed' => !empty($job['ponr_crossed']),
        'scaffold_record_only' => true,
        'auto_rollback' => false,
        'post_ponr_auto_unlock' => false,
        'enablement_flag_observed' => false,
        'last_checkpoint_id' => $job['last_checkpoint_id'] ?? null,
    ]);

    return orange_cpr_transition_result_ok([
        'from_state' => $from,
        'to_state' => $toState,
        'transition_id' => $transitionId,
        'job' => $job,
        'scaffold_record_only' => true,
        'ponr_crossed' => !empty($job['ponr_crossed']),
        'ponr_mutation_executed' => false,
        'auto_rollback' => false,
        'post_ponr_auto_unlock' => false,
    ]);
}
