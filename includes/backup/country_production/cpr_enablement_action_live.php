<?php

declare(strict_types=1);

/**
 * CPR Live Super Admin Enable/Disable & Schema Force-Disable (WP-P9-03).
 *
 * ONLY module authorized to change the operational enablement flag (sealed ops state).
 * Enable only from sealed E5 preconditions. Disable from E6. Schema invalidation → E8.
 * No production SQL. No production upload mutation. No auto-enable / no auto re-enable.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_03_ENABLEMENT_ACTIONS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md §7–§8
 */

require_once __DIR__ . '/cpr_enablement_preconditions_live.php';
require_once __DIR__ . '/cpr_p9_control_plane.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_enablement.php';

const ORANGE_CPR_EACT_SCHEMA = 'cpr_enablement_action_live/1';
const ORANGE_CPR_EACT_VERSION = 'P9-03-1.0';
const ORANGE_CPR_EACT_MANIFEST_SCHEMA = 'cpr_enablement_action_manifest/1';
const ORANGE_CPR_EACT_ACTION_SCHEMA = 'cpr_enablement_action/1';
const ORANGE_CPR_EACT_INVALIDATION_SCHEMA = 'cpr_schema_invalidation_event/1';
const ORANGE_CPR_EACT_STATE_E5 = 'E5_preconditions_satisfied';
const ORANGE_CPR_EACT_STATE_E6 = 'E6_enabled';
const ORANGE_CPR_EACT_STATE_E7 = 'E7_disabled_operational';
const ORANGE_CPR_EACT_STATE_E8 = 'E8_schema_invalidated';

const ORANGE_CPR_EACT_ERR_ACTOR = 'eact_actor_not_super_admin';
const ORANGE_CPR_EACT_ERR_MISSING = 'eact_prerequisite_missing';
const ORANGE_CPR_EACT_ERR_CORRUPT = 'eact_prerequisite_corrupt';
const ORANGE_CPR_EACT_ERR_OWNER = 'eact_owner_approval_missing';
const ORANGE_CPR_EACT_ERR_STATE = 'eact_state_forbidden';
const ORANGE_CPR_EACT_ERR_ACTION = 'eact_action_invalid';
const ORANGE_CPR_EACT_ERR_SCHEMA = 'eact_schema_mismatch';
const ORANGE_CPR_EACT_ERR_PERMISSION = 'eact_permission_mismatch';
const ORANGE_CPR_EACT_ERR_CONTRACT = 'eact_contract_mismatch';
const ORANGE_CPR_EACT_ERR_COUNTRY = 'eact_country_mismatch';
const ORANGE_CPR_EACT_ERR_FINGERPRINT = 'eact_fingerprint_mismatch';
const ORANGE_CPR_EACT_ERR_REPLAY = 'eact_replay_forbidden';
const ORANGE_CPR_EACT_ERR_BYPASS = 'eact_bypass_forbidden';
const ORANGE_CPR_EACT_ERR_AUTO = 'eact_automatic_forbidden';
const ORANGE_CPR_EACT_ERR_PERSIST = 'eact_persist_failed';
const ORANGE_CPR_EACT_ERR_JOB = 'eact_job_invalid';
const ORANGE_CPR_EACT_ERR_CROSS_COUNTRY = 'eact_cross_country_forbidden';
const ORANGE_CPR_EACT_ERR_CERT = 'eact_certification_mismatch';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_eact_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'automatic' => false,
        'auto_reenable' => false,
        'written_by_wp' => 'WP-P9-03',
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_eact_fail(string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => false,
        'code' => $code,
        'message' => $message,
        'fail_closed' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'automatic' => false,
        'auto_reenable' => false,
        'written_by_wp' => 'WP-P9-03',
    ], $extra);
}

function orange_cpr_enablement_action_live_root(string $cprRoot, string $jobId): string
{
    return orange_cpr_enablement_directory($cprRoot, $jobId);
}

function orange_cpr_enablement_action_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_enablement_action_live_root($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_enablement_action_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_enablement_action_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, $kind);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !orange_cpr_auth_verify_seal($data)) {
        return null;
    }

    return $data;
}

/**
 * @param array<string, mixed> $payload
 */
function orange_cpr_enablement_action_live_write_latest(
    string $cprRoot,
    string $jobId,
    string $kind,
    array $payload
): void {
    $latest = orange_cpr_enablement_action_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR enablement action directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Enablement action latest write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_action_live_refuse_unsafe(array $request): array
{
    $bad = [
        'bypass', 'super_admin_bypass', 'skip_prerequisite', 'skip_e5', 'skip_owner_order',
        'auto_enable', 'automatic', 'automatic_enablement', 'auto_reenable',
        'country_admin_enable', 'engineering_enable', 'force_replay',
        'use_production_db', 'mutate_production', 'execute_production_sql', 'mutate_uploads',
        'cross_country', 'begin_p9_04', 'partial_enablement',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k]) || (isset($request[$k]) && $request[$k] === true)) {
            if (in_array($k, ['auto_enable', 'automatic', 'automatic_enablement', 'auto_reenable'], true)) {
                return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_AUTO, 'Automatic enable/re-enable forbidden: ' . $k);
            }
            if ($k === 'force_replay') {
                return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_REPLAY, 'Replay forbidden.');
            }

            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_BYPASS, 'Unsafe enablement action knob forbidden: ' . $k);
        }
    }
    if (array_key_exists('automatic', $request) && $request['automatic'] !== false && $request['automatic'] !== 0 && $request['automatic'] !== '0') {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_AUTO, 'automatic must be false (P1-13 §7.3).');
    }

    return orange_cpr_eact_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_action_live_validate_actor(array $request): array
{
    $role = strtolower(trim((string) ($request['actor_role'] ?? '')));
    $isSa = !empty($request['actor_is_super_admin']) || $role === 'super_admin';
    $isCountry = !empty($request['actor_is_country_admin']) || $role === 'country_admin';
    $isOwner = !empty($request['actor_is_owner']) || $role === 'owner';
    $isEngineering = !empty($request['actor_is_engineering']) || $role === 'engineering';

    if ($isCountry || $isEngineering || ($isOwner && !$isSa)) {
        return orange_cpr_eact_fail(
            ORANGE_CPR_EACT_ERR_PERMISSION,
            'Only Super Admin may Enable/Disable / schema force-disable (OD-PERM).'
        );
    }
    if (!$isSa) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_ACTOR, 'actor_role must be super_admin.');
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_ACTOR, 'actor_admin_id required.');
    }

    return orange_cpr_eact_ok(['actor_id' => $actorId]);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_action_live_normalize_action(array $request): array
{
    $action = strtolower(trim((string) ($request['action'] ?? '')));
    if (!in_array($action, ['enable', 'disable', 'schema_force_disable'], true)) {
        return orange_cpr_eact_fail(
            ORANGE_CPR_EACT_ERR_ACTION,
            'action must be enable | disable | schema_force_disable.'
        );
    }

    return orange_cpr_eact_ok(['action' => $action]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_action_live_assert_preconditions(array $env, array $request): array
{
    $unsafe = orange_cpr_enablement_action_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }
    $actor = orange_cpr_enablement_action_live_validate_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }
    $norm = orange_cpr_enablement_action_live_normalize_action($request);
    if (empty($norm['ok'])) {
        return $norm;
    }
    $action = (string) $norm['action'];

    if (!empty($request['cross_country_id']) || !empty($request['secondary_country_id'])) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_CROSS_COUNTRY, 'Cross-country enablement forbidden.');
    }

    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_JOB, 'job_id required.');
    }
    $cprRoot = orange_cpr_resolve_work_root($env);

    try {
        orange_cpr_assert_job_id($jobId);
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_JOB, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $reqCountry = (int) ($request['country_id'] ?? 0);
    if ($reqCountry > 0 && $reqCountry !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_COUNTRY, 'Country mismatch vs job.');
    }
    $reqFp = trim((string) ($request['package_fingerprint'] ?? ''));
    if ($reqFp !== '' && !hash_equals((string) ($job['package_fingerprint'] ?? ''), $reqFp)) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_FINGERPRINT, 'Package fingerprint mismatch vs job.');
    }

    $pre = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'preconditions');
    $order = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'order');
    $cert = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result');

    if ($action === 'enable') {
        if (!is_array($pre) || empty($pre['sealed']) || empty($pre['all_preconditions_met'])) {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_MISSING,
                'Sealed E5 enablement preconditions required before Enable.'
            );
        }
        if (($pre['enablement_state'] ?? '') !== ORANGE_CPR_EACT_STATE_E5) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_STATE, 'Enable allowed only from E5_preconditions_satisfied.');
        }
        if (!is_array($order) || empty($order['sealed']) || ($order['issued_by'] ?? '') !== 'owner') {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_OWNER,
                'Owner enablement order missing/invalid (OD-ENABLE).'
            );
        }
        if (!is_array($cert) || empty($cert['sealed']) || ($cert['result'] ?? '') !== 'PASS') {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_CERT, 'Owner Certification PASS required.');
        }
        $ops = orange_cpr_enablement_ops_state_load($cprRoot);
        if (is_array($ops) && ($ops['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E8) {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_STATE,
                'Schema invalidated (E8); auto re-enable forbidden — full OD-SCHEMA re-authorization required.'
            );
        }
        if (orange_cpr_enablement_flag_read($env)) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_STATE, 'Already enabled (E6); no replay Enable.');
        }
        if (is_array($ops) && ($ops['enablement_state'] ?? '') === ORANGE_CPR_EACT_STATE_E6) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_REPLAY, 'Enable decision already sealed at E6.');
        }
        $schema = (int) ($contract['schema_revision_expected'] ?? 0);
        if ((int) ($pre['schema_revision_bound'] ?? 0) !== $schema
            || (int) ($cert['schema_revision_bound'] ?? 0) !== $schema
            || (int) ($order['schema_revision_bound'] ?? 0) !== $schema
        ) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_SCHEMA, 'Schema binding mismatch across E5/cert/order/contract.');
        }
        $reqSchema = (int) ($request['schema_revision'] ?? 0);
        if ($reqSchema > 0 && $reqSchema !== $schema) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_SCHEMA, 'Request schema_revision mismatch.');
        }
    }

    if ($action === 'disable') {
        if (!orange_cpr_enablement_flag_read($env)) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_STATE, 'Disable allowed only from E6_enabled.');
        }
    }

    if ($action === 'schema_force_disable') {
        $schemaCurrent = (int) ($request['schema_revision_current'] ?? 0);
        $schemaBound = 0;
        if (is_array($pre)) {
            $schemaBound = (int) ($pre['schema_revision_bound'] ?? 0);
        } elseif (is_array($cert)) {
            $schemaBound = (int) ($cert['schema_revision_bound'] ?? 0);
        } else {
            $schemaBound = (int) ($contract['schema_revision_expected'] ?? 0);
        }
        if ($schemaCurrent <= 0) {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_MISSING,
                'schema_revision_current required for schema_force_disable.'
            );
        }
        if ($schemaBound <= 0 || $schemaCurrent === $schemaBound) {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_SCHEMA,
                'schema_force_disable requires schema_revision_current ≠ bound revision (OD-SCHEMA).'
            );
        }
        if (!is_array($cert) || empty($cert['sealed'])) {
            return orange_cpr_eact_fail(
                ORANGE_CPR_EACT_ERR_MISSING,
                'Prior sealed certification required for schema invalidation event.'
            );
        }
    }

    return [
        'ok' => true,
        'action' => $action,
        'actor' => $actor,
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'preconditions' => $pre,
        'order' => $order,
        'cert' => $cert,
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_action_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_enablement_action_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $actor */
    $actor = $pre['actor'];
    $action = (string) $pre['action'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    $actorId = (int) $actor['actor_id'];
    $now = gmdate('c');
    $actionId = orange_cpr_generate_job_id();
    $auditRecordId = 'audit-' . $actionId;

    $fp = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $countryCode = (string) ($job['country_code'] ?? '');
    $schema = (int) ($contract['schema_revision_expected'] ?? 0);

    /** @var array<string, mixed>|null $preconditions */
    $preconditions = is_array($pre['preconditions'] ?? null) ? $pre['preconditions'] : null;
    /** @var array<string, mixed>|null $order */
    $order = is_array($pre['order'] ?? null) ? $pre['order'] : null;
    /** @var array<string, mixed>|null $cert */
    $cert = is_array($pre['cert'] ?? null) ? $pre['cert'] : null;

    $flagBefore = orange_cpr_enablement_flag_read($env);
    $checkpointsObserved = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);

    $certificationId = is_array($cert) ? (string) ($cert['certification_id'] ?? '') : '';
    $orderId = is_array($order) ? (string) ($order['order_id'] ?? '') : '';
    $preconditionsId = is_array($preconditions) ? (string) ($preconditions['preconditions_id'] ?? '') : '';

    $flagAfter = $flagBefore;
    $enablementState = ORANGE_CPR_EACT_STATE_E5;
    $invalidation = null;

    if ($action === 'enable') {
        $flagAfter = true;
        $enablementState = ORANGE_CPR_EACT_STATE_E6;
    } elseif ($action === 'disable') {
        $flagAfter = false;
        $enablementState = ORANGE_CPR_EACT_STATE_E7;
    } else {
        // schema_force_disable
        $flagAfter = false;
        $enablementState = ORANGE_CPR_EACT_STATE_E8;
        $schemaCurrent = (int) ($request['schema_revision_current'] ?? 0);
        $schemaPrevious = is_array($preconditions)
            ? (int) ($preconditions['schema_revision_bound'] ?? $schema)
            : (is_array($cert) ? (int) ($cert['schema_revision_bound'] ?? $schema) : $schema);
        $invalidation = [
            'schema_version' => ORANGE_CPR_EACT_INVALIDATION_SCHEMA,
            'event_id' => orange_cpr_generate_job_id(),
            'detected_at' => $now,
            'schema_revision_previous' => $schemaPrevious,
            'schema_revision_current' => $schemaCurrent,
            'prior_certification_id' => $certificationId,
            'prior_certification_invalidated' => true,
            'flag_forced_false' => true,
            'auto_reenable' => false,
            'checklist' => [
                'package_rebuild' => ['done' => false],
                'new_certification' => ['done' => false],
                'new_c8_safe' => ['done' => false],
                'owner_review' => ['done' => false],
                'owner_pass' => ['done' => false],
                'owner_enable_again' => ['done' => false],
                'super_admin_enable' => ['done' => false],
            ],
            'enablement_state' => ORANGE_CPR_EACT_STATE_E8,
            'audit_record_id' => $auditRecordId,
            'job_id' => $jobId,
            'sealed' => true,
        ];
    }

    $decision = [
        'schema_version' => ORANGE_CPR_EACT_ACTION_SCHEMA,
        'action_id' => $actionId,
        'action' => $action === 'schema_force_disable' ? 'disable' : $action,
        'action_kind' => $action,
        'actor_admin_id' => $actorId,
        'actor_role' => 'super_admin',
        'at' => $now,
        'preconditions_snapshot_ref' => $preconditionsId !== '' ? $preconditionsId : null,
        'all_preconditions_met' => $action === 'enable' ? true : (is_array($preconditions) ? !empty($preconditions['all_preconditions_met']) : null),
        'owner_enablement_order_id' => $orderId !== '' ? $orderId : null,
        'certification_id' => $certificationId !== '' ? $certificationId : null,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'automatic' => false,
        'auto_reenable' => false,
        'audit_record_id' => $auditRecordId,
        'enablement_state' => $enablementState,
        'decision_complete' => true,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'written_by_wp' => 'WP-P9-03',
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'sealed' => true,
    ];

    if ($action === 'enable') {
        if ($decision['all_preconditions_met'] !== true
            || $decision['owner_enablement_order_id'] === null
            || $decision['certification_id'] === null
        ) {
            return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_MISSING, 'Enable decision missing required OD-ENABLE fields.');
        }
    }

    $decisionFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'action_id' => $actionId,
        'action_kind' => $action,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'enablement_state' => $enablementState,
        'actor_admin_id' => $actorId,
        'automatic' => false,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
    ]));

    $root = orange_cpr_enablement_action_live_root($cprRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_PERSIST, 'Cannot create enablement action root.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.enablement_action_live_start',
        'job_id' => $jobId,
        'action_id' => $actionId,
        'action_kind' => $action,
        'flag_before' => $flagBefore,
        'actor_admin_id' => $actorId,
        'automatic' => false,
        'production_resources_accessed' => false,
    ]);

    try {
        orange_cpr_enablement_ops_state_write($cprRoot, $flagAfter, [
            'enablement_state' => $enablementState,
            'action_id' => $actionId,
            'action_kind' => $action,
            'job_id' => $jobId,
            'package_fingerprint' => $fp,
            'country_id' => $countryId,
            'certification_id' => $certificationId,
            'owner_enablement_order_id' => $orderId,
            'schema_revision_bound' => $schema,
            'flag_forced_false' => $action === 'schema_force_disable',
            'prior_certification_invalidated' => $action === 'schema_force_disable',
        ]);
    } catch (RuntimeException $e) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_PERSIST, $e->getMessage());
    }

    // Prove flag changed only via this WP's ops-state writer.
    $flagObserved = orange_cpr_enablement_flag_read($env);
    if ($flagObserved !== $flagAfter) {
        return orange_cpr_eact_fail(
            ORANGE_CPR_EACT_ERR_PERSIST,
            'Ops enablement flag did not match sealed decision after write.'
        );
    }

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'action_id' => $actionId,
        'action_kind' => $action,
        'enablement_state' => $enablementState,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'ops_flag_observed' => $flagObserved,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'preconditions_id' => $preconditionsId,
        'decision_fingerprint' => $decisionFingerprint,
        'completed_phase' => 'p9_enablement_action_sealed',
        'execution_status' => $enablementState,
        'automatic' => false,
        'auto_reenable' => false,
        'written_by_wp' => 'WP-P9-03',
        'p9_04_not_started' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'checkpoints_committed_observed' => $checkpointsObserved,
    ];

    $manifest = [
        'schema_version' => ORANGE_CPR_EACT_MANIFEST_SCHEMA,
        'action_id' => $actionId,
        'action_kind' => $action,
        'enablement_state' => $enablementState,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'automatic' => false,
        'auto_reenable' => false,
        'decision_fingerprint' => $decisionFingerprint,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'written_by_wp' => 'WP-P9-03',
        'artifact_refs' => [
            'decision' => 'cpr_enablement_action_' . ($action === 'schema_force_disable' ? 'force_disable' : $action) . '_latest.json',
            'manifest' => 'cpr_enablement_action_manifest_latest.json',
            'report' => 'cpr_enablement_action_report_latest.json',
            'ops_state' => ORANGE_CPR_ENABLEMENT_OPS_STATE_FILENAME,
        ],
        'sealed' => true,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_EACT_SCHEMA,
        'engine_version' => ORANGE_CPR_EACT_VERSION,
        'record_type' => 'enablement_action_report',
        'action_id' => $actionId,
        'action_kind' => $action,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'decision_complete' => true,
        'exactly_once' => true,
        'sealed' => true,
        'enablement_state' => $enablementState,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'ops_flag_observed' => $flagObserved,
        'automatic' => false,
        'auto_reenable' => false,
        'decision_fingerprint' => $decisionFingerprint,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'written_by_wp' => 'WP-P9-03',
        'only_wp_may_change_flag' => true,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'recovery_metadata' => $recovery,
        'schema_invalidation' => $invalidation,
        'created_at' => $now,
    ];

    $kind = $action === 'schema_force_disable' ? 'force_disable' : $action;

    try {
        orange_cpr_enablement_action_live_write_latest($cprRoot, $jobId, $kind, $decision);
        orange_cpr_enablement_action_live_write_latest($cprRoot, $jobId, 'manifest', $manifest);
        orange_cpr_enablement_action_live_write_latest($cprRoot, $jobId, 'report', $report);
        if (is_array($invalidation)) {
            orange_cpr_enablement_action_live_write_latest($cprRoot, $jobId, 'invalidation', $invalidation);
        }
    } catch (RuntimeException $e) {
        return orange_cpr_eact_fail(ORANGE_CPR_EACT_ERR_PERSIST, $e->getMessage());
    }

    $eventComplete = $action === 'enable' ? 'cpr.enable' : ($action === 'disable' ? 'cpr.disable' : 'cpr.schema_force_disable');
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.enablement_action_live_complete',
        'audit_event' => $eventComplete,
        'job_id' => $jobId,
        'action_id' => $actionId,
        'action_kind' => $action,
        'enablement_state' => $enablementState,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'ops_flag_observed' => $flagObserved,
        'decision_fingerprint' => $decisionFingerprint,
        'automatic' => false,
        'auto_reenable' => false,
        'written_by_wp' => 'WP-P9-03',
        'production_resources_accessed' => false,
        'actor_admin_id' => $actorId,
        'audit_record_id' => $auditRecordId,
    ]);

    return orange_cpr_eact_ok([
        'message' => 'Enablement action sealed (' . $action . ') → ' . $enablementState . '.',
        'job_id' => $jobId,
        'action_id' => $actionId,
        'action' => $action === 'schema_force_disable' ? 'disable' : $action,
        'action_kind' => $action,
        'enablement_state' => $enablementState,
        'flag_before' => $flagBefore,
        'flag_after' => $flagAfter,
        'ops_flag_observed' => $flagObserved,
        'decision_complete' => true,
        'sealed' => true,
        'exactly_once' => true,
        'automatic' => false,
        'auto_reenable' => false,
        'decision_fingerprint' => $decisionFingerprint,
        'written_by_wp' => 'WP-P9-03',
        'only_wp_may_change_flag' => true,
        'decision' => orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, $kind),
        'manifest' => orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'manifest'),
        'report' => orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'report'),
        'schema_invalidation' => is_array($invalidation)
            ? orange_cpr_enablement_action_live_load_latest($cprRoot, $jobId, 'invalidation')
            : null,
        'ops_state' => orange_cpr_enablement_ops_state_load($cprRoot),
        'recovery_metadata' => $recovery,
        'production_resources_accessed' => false,
    ]);
}
