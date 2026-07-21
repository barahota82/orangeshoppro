<?php

declare(strict_types=1);

/**
 * CPR Mutation Engine Skeleton (WP-P3-08).
 *
 * Execution pipeline framework + orchestration + stage dispatch + worker
 * interfaces + execution context + cancellation + fail-closed propagation +
 * audit/checkpoint hooks + integrations with state/lock/gate/authority engines.
 *
 * STRICT: No DELETE/IMPORT/PONR/production business-data writes. Enablement FALSE.
 * All mutation stages return "Not Implemented Yet".
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_08_MUTATION_SKELETON.md
 * @see Architecture §6 Execution pipeline
 */

require_once __DIR__ . '/cpr_mutation_catalog.php';
require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_lock_engine.php';
require_once __DIR__ . '/cpr_gate_evaluator.php';
require_once __DIR__ . '/cpr_authority_engine.php';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_mut_ok(array $extra = []): array
{
    return array_merge(['ok' => true, 'code' => 'ok'], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_mut_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'production_mutation' => false,
        'ponr_executed' => false,
    ], $extra);
}

/**
 * Explicit stub result for every mutation stage.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_mut_not_implemented(string $stageId, array $extra = []): array
{
    return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_NIY, ORANGE_CPR_MUT_MSG_NIY, array_merge([
        'stage_id' => $stageId,
        'stub' => true,
        'fail_closed' => true,
    ], $extra));
}

/**
 * Default dependency injection bundle (engine callables).
 *
 * @param array<string, mixed> $env
 * @return array<string, callable>
 */
function orange_cpr_mutation_default_dependencies(array $env): array
{
    return [
        'enablement_read' => static function () use ($env): bool {
            return orange_cpr_enablement_flag_read($env);
        },
        'job_read' => static function (string $jobId) use ($env): array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_job_read($cprRoot, $jobId);
        },
        'contract_read' => static function (string $jobId) use ($env): ?array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_contract_read($cprRoot, $jobId);
        },
        'lock_read' => static function () use ($env): ?array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_lock_read($cprRoot);
        },
        'gate_load_latest' => static function (string $jobId) use ($env): ?array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_gate_evaluation_load_latest($cprRoot, $jobId, 'pre_ponr_full');
        },
        'authority_load_latest' => static function (string $jobId) use ($env): ?array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_ponr_authorization_load_latest($cprRoot, $jobId);
        },
        'checkpoint_list' => static function (string $jobId) use ($env): array {
            $cprRoot = orange_cpr_resolve_work_root($env);

            return orange_cpr_checkpoint_list_committed($cprRoot, $jobId);
        },
        'audit_append' => static function (string $jobId, array $event) use ($env): void {
            $cprRoot = orange_cpr_resolve_work_root($env);
            orange_cpr_audit_append($cprRoot, $jobId, $event);
        },
        'state_snapshot' => static function (string $jobId) use ($env): array {
            $cprRoot = orange_cpr_resolve_work_root($env);
            $job = orange_cpr_job_read($cprRoot, $jobId);

            return [
                'job_id' => $jobId,
                'state' => (string) ($job['state'] ?? ''),
                'ponr_crossed' => !empty($job['ponr_crossed']),
                'mutation_engines' => $job['mutation_engines'] ?? [
                    'delete' => false,
                    'import' => false,
                    'uploads' => false,
                    'ponr' => false,
                ],
            ];
        },
    ];
}

/**
 * Build execution context for a pipeline run.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_cpr_mutation_context_create(array $env, string $jobId, array $options = []): array
{
    $deps = orange_cpr_mutation_default_dependencies($env);
    if (isset($options['dependencies']) && is_array($options['dependencies'])) {
        foreach ($options['dependencies'] as $key => $value) {
            // Allow callables and structured DI maps (e.g. workers => [name => callable]).
            if (is_callable($value) || is_array($value)) {
                $deps[(string) $key] = $value;
            }
        }
    }

    $hooks = [
        'on_audit' => $options['on_audit'] ?? null,
        'on_checkpoint' => $options['on_checkpoint'] ?? null,
        'on_cancel_check' => $options['on_cancel_check'] ?? null,
        'on_stage_begin' => $options['on_stage_begin'] ?? null,
        'on_stage_end' => $options['on_stage_end'] ?? null,
    ];

    return [
        'schema_version' => ORANGE_CPR_MUT_PIPELINE_SCHEMA,
        'engine_version' => ORANGE_CPR_MUT_ENGINE_VERSION,
        'env' => $env,
        'job_id' => $jobId,
        'pipeline_id' => (string) ($options['pipeline_id'] ?? orange_cpr_generate_job_id()),
        'cancelled' => false,
        'cancel_requested' => !empty($options['cancel_requested']),
        'stop_on_mutation_stub' => array_key_exists('stop_on_mutation_stub', $options)
            ? (bool) $options['stop_on_mutation_stub']
            : true,
        'require_authority' => !empty($options['require_authority']),
        'require_gate_pass' => !empty($options['require_gate_pass']),
        'lease_token' => (string) ($options['lease_token'] ?? ''),
        'worker_id' => (string) ($options['worker_id'] ?? ''),
        'dependencies' => $deps,
        'hooks' => $hooks,
        'stage_results' => [],
        'dispatched_stages' => [],
        'error' => null,
        'production_mutation' => false,
        'ponr_executed' => false,
        'enablement_flag_observed' => false,
        'created_at' => gmdate('c'),
    ];
}

/**
 * Create (and optionally persist) a pipeline definition for a job.
 *
 * @param array<string, mixed> $env
 * @param list<string>|null $stages
 * @return array<string, mixed>
 */
function orange_cpr_mutation_pipeline_create(
    array $env,
    string $jobId,
    ?array $stages = null,
    array $options = []
): array {
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_mut_fail(
            ORANGE_CPR_MUT_ERR_ENABLEMENT,
            'Enablement must remain FALSE; mutation skeleton refuses enablement.'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_CONTEXT, $e->getMessage());
    }

    $stageIds = $stages ?? orange_cpr_mutation_default_stage_order();
    $resolved = [];
    foreach ($stageIds as $sid) {
        $def = orange_cpr_mutation_stage_definition($sid);
        if ($def === null) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_DISPATCH, 'Unknown stage id: ' . $sid);
        }
        $resolved[] = [
            'stage_id' => $sid,
            'kind' => $def['kind'],
            'worker' => $def['worker'],
            'is_mutation' => $def['is_mutation'],
            'description' => $def['description'],
            'status' => 'pending',
        ];
    }

    $pipeline = [
        'schema_version' => ORANGE_CPR_MUT_PIPELINE_SCHEMA,
        'engine_version' => ORANGE_CPR_MUT_ENGINE_VERSION,
        'pipeline_id' => (string) ($options['pipeline_id'] ?? orange_cpr_generate_job_id()),
        'job_id' => $jobId,
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'stages' => $resolved,
        'created_at' => gmdate('c'),
        'production_mutation_allowed' => false,
        'ponr_execution_allowed' => false,
        'enablement_flag_observed' => false,
        'scaffold_only' => true,
    ];

    if (!empty($options['persist'])) {
        $dir = orange_cpr_pipeline_directory($cprRoot, $jobId);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_CONTEXT, 'Cannot create pipeline directory.');
        }
        $path = $dir . DIRECTORY_SEPARATOR . 'cpr_mutation_pipeline_' . $pipeline['pipeline_id'] . '.json';
        $json = json_encode($pipeline, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || @file_put_contents($path, $json) === false) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_CONTEXT, 'Pipeline persist failed.');
        }
        $pipeline['path'] = $path;
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.mutation_pipeline_create',
        'job_id' => $jobId,
        'pipeline_id' => $pipeline['pipeline_id'],
        'stage_count' => count($resolved),
        'production_mutation_allowed' => false,
        'enablement_flag_observed' => false,
        'scaffold_only' => true,
    ]);

    return orange_cpr_mut_ok([
        'pipeline' => $pipeline,
        'message' => 'Mutation pipeline skeleton created (no production mutation).',
    ]);
}

/**
 * Request cancellation on a live execution context.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_cancel(array &$ctx, string $reason = 'cancelled'): array
{
    $ctx['cancelled'] = true;
    $ctx['cancel_requested'] = true;
    $ctx['cancel_reason'] = $reason;
    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_pipeline_cancel',
        'reason' => $reason,
    ]);

    return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_CANCELLED, 'Pipeline cancelled: ' . $reason, [
        'cancelled' => true,
    ]);
}

/**
 * Cancellation point — fail-closed if cancel requested.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_cancellation_point(array &$ctx, string $at): array
{
    $hook = $ctx['hooks']['on_cancel_check'] ?? null;
    if (is_callable($hook)) {
        $hookResult = $hook($ctx, $at);
        if (is_array($hookResult) && !empty($hookResult['cancel'])) {
            return orange_cpr_mutation_cancel($ctx, (string) ($hookResult['reason'] ?? 'hook_cancel'));
        }
    }
    if (!empty($ctx['cancel_requested']) || !empty($ctx['cancelled'])) {
        $ctx['cancelled'] = true;

        return orange_cpr_mut_fail(
            ORANGE_CPR_MUT_ERR_CANCELLED,
            'Cancellation point hit at ' . $at,
            ['cancelled' => true, 'at' => $at]
        );
    }

    return orange_cpr_mut_ok(['at' => $at]);
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $event
 */
function orange_cpr_mutation_emit_audit(array &$ctx, array $event): void
{
    $event = array_merge([
        'job_id' => (string) ($ctx['job_id'] ?? ''),
        'pipeline_id' => (string) ($ctx['pipeline_id'] ?? ''),
        'production_mutation' => false,
        'ponr_executed' => false,
        'enablement_flag_observed' => (bool) ($ctx['enablement_flag_observed'] ?? false),
        'scaffold_only' => true,
    ], $event);

    $hook = $ctx['hooks']['on_audit'] ?? null;
    if (is_callable($hook)) {
        $hook($ctx, $event);
    }

    $deps = $ctx['dependencies'] ?? [];
    if (isset($deps['audit_append']) && is_callable($deps['audit_append'])) {
        try {
            $deps['audit_append']((string) $ctx['job_id'], $event);
        } catch (Throwable) {
            // Fail-closed for audit write is recorded on context; do not mutate production.
            $ctx['audit_emit_error'] = true;
        }
    }
}

/**
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $payload
 */
function orange_cpr_mutation_emit_checkpoint_hook(array &$ctx, array $payload): void
{
    $hook = $ctx['hooks']['on_checkpoint'] ?? null;
    if (is_callable($hook)) {
        $hook($ctx, $payload);
    }
}

/**
 * Resolve worker callable for a stage (DI overridable).
 *
 * @param array<string, mixed> $ctx
 */
function orange_cpr_mutation_resolve_worker(array $ctx, string $stageId): ?callable
{
    $def = orange_cpr_mutation_stage_definition($stageId);
    if ($def === null) {
        return null;
    }
    $workerName = (string) $def['worker'];
    $workers = $ctx['dependencies']['workers'] ?? null;
    if (is_array($workers) && isset($workers[$workerName]) && is_callable($workers[$workerName])) {
        return $workers[$workerName];
    }
    if (is_array($workers) && isset($workers[$stageId]) && is_callable($workers[$stageId])) {
        return $workers[$stageId];
    }

    return static function (array &$context) use ($stageId, $def): array {
        return orange_cpr_mutation_builtin_worker($context, $stageId, $def);
    };
}

/**
 * Built-in workers: integration binds + mutation stubs.
 *
 * @param array<string, mixed> $ctx
 * @param array<string, mixed> $def
 * @return array<string, mixed>
 */
function orange_cpr_mutation_builtin_worker(array &$ctx, string $stageId, array $def): array
{
    if (!empty($def['is_mutation'])) {
        return orange_cpr_mut_not_implemented($stageId, [
            'worker' => (string) ($def['worker'] ?? ''),
            'kind' => (string) ($def['kind'] ?? ''),
        ]);
    }

    return match ($stageId) {
        ORANGE_CPR_MUT_STAGE_PREFLIGHT => orange_cpr_mutation_worker_preflight($ctx),
        ORANGE_CPR_MUT_STAGE_GATE_BIND => orange_cpr_mutation_worker_gate_bind($ctx),
        ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND => orange_cpr_mutation_worker_authority_bind($ctx),
        ORANGE_CPR_MUT_STAGE_LOCK_BIND => orange_cpr_mutation_worker_lock_bind($ctx),
        ORANGE_CPR_MUT_STAGE_STATE_BIND => orange_cpr_mutation_worker_state_bind($ctx),
        ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK => orange_cpr_mutation_worker_checkpoint_hook($ctx),
        ORANGE_CPR_MUT_STAGE_AUDIT_HOOK => orange_cpr_mutation_worker_audit_hook($ctx),
        ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE => orange_cpr_mutation_worker_audit_close($ctx),
        default => orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_DISPATCH, 'No builtin worker for stage: ' . $stageId),
    };
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_preflight(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    if (!isset($deps['enablement_read']) || !is_callable($deps['enablement_read'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'enablement_read dependency missing.');
    }
    $enabled = (bool) $deps['enablement_read']();
    $ctx['enablement_flag_observed'] = $enabled;
    if ($enabled) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_ENABLEMENT, 'Enablement true is forbidden in P3 mutation skeleton.');
    }
    if (!isset($deps['job_read']) || !is_callable($deps['job_read'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'job_read dependency missing.');
    }
    try {
        $job = $deps['job_read']((string) $ctx['job_id']);
    } catch (Throwable $e) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, $e->getMessage());
    }
    if (!empty($job['ponr_crossed'])) {
        return orange_cpr_mut_fail(
            ORANGE_CPR_MUT_ERR_FAIL_CLOSED,
            'Job already ponr_crossed; skeleton will not execute mutation.'
        );
    }
    $ctx['job_snapshot'] = [
        'state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'country_id' => (int) ($job['country_id'] ?? 0),
        'ponr_crossed' => false,
    ];

    return orange_cpr_mut_ok(['stage_id' => ORANGE_CPR_MUT_STAGE_PREFLIGHT]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_gate_bind(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    if (!isset($deps['gate_load_latest']) || !is_callable($deps['gate_load_latest'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'gate_load_latest dependency missing.');
    }
    $gate = $deps['gate_load_latest']((string) $ctx['job_id']);
    $ctx['integrations']['gate'] = [
        'present' => is_array($gate),
        'all_gates_pass' => is_array($gate) ? !empty($gate['all_gates_pass']) : false,
        'seal_ok' => is_array($gate) ? orange_cpr_gate_evaluation_verify_seal($gate) : false,
    ];
    if (!empty($ctx['require_gate_pass'])) {
        if (!is_array($gate) || empty($gate['all_gates_pass']) || empty($gate['ponr_authorized'])) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'Required sealed PASS gate report missing.');
        }
        if (!orange_cpr_gate_evaluation_verify_seal($gate)) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'Gate report seal verification failed.');
        }
    }

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_GATE_BIND,
        'gate_bound' => is_array($gate),
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_authority_bind(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    if (!isset($deps['authority_load_latest']) || !is_callable($deps['authority_load_latest'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'authority_load_latest dependency missing.');
    }
    $auth = $deps['authority_load_latest']((string) $ctx['job_id']);
    $ctx['integrations']['authority'] = [
        'present' => is_array($auth),
        'seal_ok' => is_array($auth) ? orange_cpr_auth_verify_seal($auth) : false,
        'ponr_authorized' => is_array($auth) ? !empty($auth['ponr_authorized']) : false,
        'ponr_crossed' => is_array($auth) ? !empty($auth['ponr_crossed']) : false,
    ];
    if (!empty($ctx['require_authority'])) {
        if (!is_array($auth) || !orange_cpr_auth_verify_seal($auth) || empty($auth['ponr_authorized'])) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'Required sealed PONR authorization missing.');
        }
        if (!empty($auth['consumed_at'])) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'Authorization already consumed; refuse.');
        }
    }

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_AUTHORITY_BIND,
        'authority_bound' => is_array($auth),
        'ponr_executed' => false,
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_lock_bind(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    if (!isset($deps['lock_read']) || !is_callable($deps['lock_read'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'lock_read dependency missing.');
    }
    $lock = $deps['lock_read']();
    $jobId = (string) $ctx['job_id'];
    $held = is_array($lock) && (string) ($lock['job_id'] ?? '') === $jobId;
    $ctx['integrations']['lock'] = [
        'present' => is_array($lock),
        'held_by_job' => $held,
    ];
    if ($held && $ctx['lease_token'] !== '') {
        $own = (string) ($lock['ownership']['lease_token'] ?? '');
        if ($own === '' || !hash_equals($own, $ctx['lease_token'])) {
            return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'Lock lease_token ownership mismatch.');
        }
    }

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_LOCK_BIND,
        'lock_bound' => $held,
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_state_bind(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    if (!isset($deps['state_snapshot']) || !is_callable($deps['state_snapshot'])) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_INTEGRATION, 'state_snapshot dependency missing.');
    }
    $snap = $deps['state_snapshot']((string) $ctx['job_id']);
    $ctx['integrations']['state'] = $snap;
    $engines = is_array($snap['mutation_engines'] ?? null) ? $snap['mutation_engines'] : [];
    foreach (['delete', 'import', 'uploads', 'ponr'] as $eng) {
        if (!empty($engines[$eng])) {
            return orange_cpr_mut_fail(
                ORANGE_CPR_MUT_ERR_FAIL_CLOSED,
                'mutation_engines.' . $eng . ' must remain false in P3 skeleton.'
            );
        }
    }

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_STATE_BIND,
        'state' => (string) ($snap['state'] ?? ''),
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_checkpoint_hook(array &$ctx): array
{
    $deps = $ctx['dependencies'];
    $list = [];
    if (isset($deps['checkpoint_list']) && is_callable($deps['checkpoint_list'])) {
        $list = $deps['checkpoint_list']((string) $ctx['job_id']);
    }
    $payload = [
        'hook' => 'checkpoint',
        'stage_id' => ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK,
        'committed_checkpoints' => $list,
        'write_checkpoint' => false,
        'message' => 'Checkpoint hook only — no CP-A/mutation checkpoint write in P3-08',
    ];
    orange_cpr_mutation_emit_checkpoint_hook($ctx, $payload);
    $ctx['integrations']['checkpoint_hook_fired'] = true;

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_CHECKPOINT_HOOK,
        'checkpoint_hook_fired' => true,
        'committed_count' => count($list),
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_audit_hook(array &$ctx): array
{
    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_audit_hook',
        'stage_id' => ORANGE_CPR_MUT_STAGE_AUDIT_HOOK,
    ]);
    $ctx['integrations']['audit_hook_fired'] = true;

    return orange_cpr_mut_ok([
        'stage_id' => ORANGE_CPR_MUT_STAGE_AUDIT_HOOK,
        'audit_hook_fired' => true,
    ]);
}

/**
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_worker_audit_close(array &$ctx): array
{
    // Only reachable if mutation stubs were not stop-on; still no production closeout.
    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_audit_close_skeleton',
        'stage_id' => ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE,
        'note' => 'Skeleton audit close — no production closeout',
    ]);

    return orange_cpr_mut_ok(['stage_id' => ORANGE_CPR_MUT_STAGE_AUDIT_CLOSE]);
}

/**
 * Dispatch a single stage worker.
 *
 * @param array<string, mixed> $ctx
 * @return array<string, mixed>
 */
function orange_cpr_mutation_stage_dispatch(array &$ctx, string $stageId): array
{
    $cancel = orange_cpr_mutation_cancellation_point($ctx, 'before:' . $stageId);
    if (empty($cancel['ok'])) {
        return $cancel;
    }

    $def = orange_cpr_mutation_stage_definition($stageId);
    if ($def === null) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_DISPATCH, 'Unknown stage: ' . $stageId);
    }

    $begin = $ctx['hooks']['on_stage_begin'] ?? null;
    if (is_callable($begin)) {
        $begin($ctx, $stageId, $def);
    }

    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_stage_begin',
        'stage_id' => $stageId,
        'kind' => $def['kind'],
        'is_mutation' => $def['is_mutation'],
    ]);

    $worker = orange_cpr_mutation_resolve_worker($ctx, $stageId);
    if ($worker === null) {
        return orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_DISPATCH, 'Worker unresolved for ' . $stageId);
    }

    try {
        $result = $worker($ctx);
    } catch (Throwable $e) {
        $result = orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_FAIL_CLOSED, $e->getMessage(), [
            'stage_id' => $stageId,
            'fail_closed' => true,
        ]);
    }

    if (!is_array($result)) {
        $result = orange_cpr_mut_fail(ORANGE_CPR_MUT_ERR_FAIL_CLOSED, 'Worker returned non-array.', [
            'stage_id' => $stageId,
        ]);
    }

    $result['stage_id'] = $result['stage_id'] ?? $stageId;
    $ctx['dispatched_stages'][] = $stageId;
    $ctx['stage_results'][$stageId] = $result;

    $end = $ctx['hooks']['on_stage_end'] ?? null;
    if (is_callable($end)) {
        $end($ctx, $stageId, $result);
    }

    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_stage_end',
        'stage_id' => $stageId,
        'ok' => !empty($result['ok']),
        'code' => (string) ($result['code'] ?? ''),
        'stub' => !empty($result['stub']),
    ]);

    $cancelAfter = orange_cpr_mutation_cancellation_point($ctx, 'after:' . $stageId);
    if (empty($cancelAfter['ok'])) {
        return $cancelAfter;
    }

    return $result;
}

/**
 * Orchestrate pipeline: create context → dispatch stages → fail-closed stop.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function orange_cpr_mutation_orchestrate(array $env, string $jobId, array $options = []): array
{
    $created = orange_cpr_mutation_pipeline_create(
        $env,
        $jobId,
        $options['stages'] ?? null,
        [
            'pipeline_id' => $options['pipeline_id'] ?? null,
            'persist' => !empty($options['persist']),
        ]
    );
    if (empty($created['ok'])) {
        return $created;
    }
    /** @var array<string, mixed> $pipeline */
    $pipeline = $created['pipeline'];

    $ctx = orange_cpr_mutation_context_create($env, $jobId, array_merge($options, [
        'pipeline_id' => $pipeline['pipeline_id'],
    ]));
    $ctx['pipeline'] = $pipeline;

    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_orchestrate_begin',
        'stage_count' => count($pipeline['stages']),
    ]);

    foreach ($pipeline['stages'] as $stageRow) {
        $stageId = (string) ($stageRow['stage_id'] ?? '');
        $result = orange_cpr_mutation_stage_dispatch($ctx, $stageId);
        if (empty($result['ok'])) {
            $ctx['error'] = $result;
            // Fail-closed: do not dispatch remaining stages.
            orange_cpr_mutation_emit_audit($ctx, [
                'event_type' => 'cpr.mutation_orchestrate_fail_closed',
                'failed_stage_id' => $stageId,
                'code' => (string) ($result['code'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
            ]);

            return orange_cpr_mut_fail(
                (string) ($result['code'] ?? ORANGE_CPR_MUT_ERR_FAIL_CLOSED),
                (string) ($result['message'] ?? 'Pipeline fail-closed.'),
                [
                    'pipeline_id' => $pipeline['pipeline_id'],
                    'failed_stage_id' => $stageId,
                    'dispatched_stages' => $ctx['dispatched_stages'],
                    'stage_results' => $ctx['stage_results'],
                    'context' => [
                        'integrations' => $ctx['integrations'] ?? [],
                        'cancelled' => !empty($ctx['cancelled']),
                        'production_mutation' => false,
                        'ponr_executed' => false,
                        'enablement_flag_observed' => (bool) ($ctx['enablement_flag_observed'] ?? false),
                    ],
                    'fail_closed' => true,
                ]
            );
        }
    }

    orange_cpr_mutation_emit_audit($ctx, [
        'event_type' => 'cpr.mutation_orchestrate_complete_skeleton',
        'note' => 'All non-mutation stages completed; no production mutation performed.',
    ]);

    return orange_cpr_mut_ok([
        'pipeline_id' => $pipeline['pipeline_id'],
        'dispatched_stages' => $ctx['dispatched_stages'],
        'stage_results' => $ctx['stage_results'],
        'production_mutation' => false,
        'ponr_executed' => false,
        'enablement_flag_observed' => (bool) ($ctx['enablement_flag_observed'] ?? false),
        'message' => 'Orchestration completed without production mutation.',
    ]);
}

/**
 * Hard refuse helpers — keep parity with P3-02 stubs.
 */
function orange_cpr_mutation_refuse_delete(): array
{
    return orange_cpr_mut_not_implemented(ORANGE_CPR_MUT_STAGE_PONR_DELETE);
}

function orange_cpr_mutation_refuse_import(): array
{
    return orange_cpr_mut_not_implemented(ORANGE_CPR_MUT_STAGE_IMPORT);
}

function orange_cpr_mutation_refuse_ponr_execution(): array
{
    return orange_cpr_mut_fail(
        'ponr_execution_forbidden',
        ORANGE_CPR_MUT_MSG_NIY,
        ['ponr_executed' => false, 'stub' => true]
    );
}
