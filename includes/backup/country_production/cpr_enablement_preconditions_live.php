<?php

declare(strict_types=1);

/**
 * CPR Live Enablement Preconditions & Owner Enablement Order (WP-P9-02 / OD-ENABLE / P1-13 §6).
 *
 * Seals cpr_enablement_preconditions + cpr_owner_enablement_order after validating
 * all four OD-ENABLE preconditions against sealed Owner Certification PASS.
 * Reaches E5_preconditions_satisfied with ops flag still FALSE.
 * Does NOT flip enablement (WP-P9-03).
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P9_02_ENABLEMENT_PRECONDITIONS.md
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P1_13_ENABLEMENT_CERT_HOOKS.md
 */

require_once __DIR__ . '/cpr_owner_cert_decision_live.php';
require_once __DIR__ . '/cpr_p9_control_plane.php';
require_once __DIR__ . '/cpr_state_engine.php';
require_once __DIR__ . '/cpr_checkpoint_engine.php';
require_once __DIR__ . '/cpr_enablement.php';

const ORANGE_CPR_EPRE_SCHEMA = 'cpr_enablement_preconditions_live/1';
const ORANGE_CPR_EPRE_VERSION = 'P9-02-1.0';
const ORANGE_CPR_EPRE_MANIFEST_SCHEMA = 'cpr_enablement_manifest/1';
const ORANGE_CPR_EPRE_PRECONDITIONS_SCHEMA = 'cpr_enablement_preconditions/1';
const ORANGE_CPR_EPRE_ORDER_SCHEMA = 'cpr_owner_enablement_order/1';
const ORANGE_CPR_EPRE_DIRECTIVE = 'ENABLE_COUNTRY_PRODUCTION_RESTORE';
const ORANGE_CPR_EPRE_STATE_E5 = 'E5_preconditions_satisfied';

const ORANGE_CPR_EPRE_ERR_ENABLEMENT = 'epre_enablement_forbidden';
const ORANGE_CPR_EPRE_ERR_ACTOR = 'epre_actor_forbidden';
const ORANGE_CPR_EPRE_ERR_MISSING = 'epre_prerequisite_missing';
const ORANGE_CPR_EPRE_ERR_CORRUPT = 'epre_prerequisite_corrupt';
const ORANGE_CPR_EPRE_ERR_CERT = 'epre_certification_mismatch';
const ORANGE_CPR_EPRE_ERR_SCHEMA = 'epre_schema_mismatch';
const ORANGE_CPR_EPRE_ERR_PERMISSION = 'epre_permission_mismatch';
const ORANGE_CPR_EPRE_ERR_ORDER = 'epre_owner_order_invalid';
const ORANGE_CPR_EPRE_ERR_CONTRACT = 'epre_contract_mismatch';
const ORANGE_CPR_EPRE_ERR_COUNTRY = 'epre_country_mismatch';
const ORANGE_CPR_EPRE_ERR_FINGERPRINT = 'epre_fingerprint_mismatch';
const ORANGE_CPR_EPRE_ERR_REPLAY = 'epre_replay_forbidden';
const ORANGE_CPR_EPRE_ERR_DUPLICATE = 'epre_duplicate_forbidden';
const ORANGE_CPR_EPRE_ERR_BYPASS = 'epre_bypass_forbidden';
const ORANGE_CPR_EPRE_ERR_PARTIAL = 'epre_partial_enablement_forbidden';
const ORANGE_CPR_EPRE_ERR_AUTO = 'epre_automatic_enablement_forbidden';
const ORANGE_CPR_EPRE_ERR_PERSIST = 'epre_persist_failed';
const ORANGE_CPR_EPRE_ERR_JOB = 'epre_job_invalid';
const ORANGE_CPR_EPRE_ERR_CROSS_COUNTRY = 'epre_cross_country_forbidden';
const ORANGE_CPR_EPRE_ERR_FLAG_WRITE = 'epre_flag_write_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_epre_ok(array $extra = []): array
{
    return array_merge([
        'ok' => true,
        'code' => 'ok',
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'auto_enable_forbidden' => true,
        'automatic_enablement' => false,
        'enablement_action_executed' => false,
    ], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_epre_fail(string $code, string $message, array $extra = []): array
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
        'enablement_flag_observed' => false,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'auto_enable_forbidden' => true,
        'automatic_enablement' => false,
        'enablement_action_executed' => false,
        'all_preconditions_met' => false,
    ], $extra);
}

function orange_cpr_enablement_preconditions_live_root(string $cprRoot, string $jobId): string
{
    return orange_cpr_enablement_directory($cprRoot, $jobId);
}

function orange_cpr_enablement_preconditions_live_latest_path(string $cprRoot, string $jobId, string $kind): string
{
    return orange_cpr_enablement_preconditions_live_root($cprRoot, $jobId)
        . DIRECTORY_SEPARATOR . 'cpr_enablement_' . $kind . '_latest.json';
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_enablement_preconditions_live_load_latest(string $cprRoot, string $jobId, string $kind): ?array
{
    $path = orange_cpr_enablement_preconditions_live_latest_path($cprRoot, $jobId, $kind);
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
function orange_cpr_enablement_preconditions_live_write_latest(
    string $cprRoot,
    string $jobId,
    string $kind,
    array $payload
): void {
    $latest = orange_cpr_enablement_preconditions_live_latest_path($cprRoot, $jobId, $kind);
    $dir = dirname($latest);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR enablement directory.');
    }
    $tmp = $latest . '.tmp.' . bin2hex(random_bytes(4));
    $sealed = orange_cpr_auth_seal($payload);
    $json = json_encode($sealed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('Enablement latest write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $latest);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_preconditions_live_refuse_unsafe(array $request): array
{
    $bad = [
        'force_enable', 'bypass', 'super_admin_bypass', 'skip_prerequisite', 'skip_cert',
        'skip_owner_order', 'skip_enterprise_approval', 'skip_implementation',
        'enablement_true', 'flip_enablement', 'write_enablement_flag', 'set_flag_true',
        'auto_enable', 'automatic_enablement', 'partial_enablement', 'accept_fail_cert',
        'accept_corrupt', 'accept_missing', 'cross_country', 'begin_p9_03',
        'sa_enable', 'enable_action', 'use_production_db', 'mutate_production',
        'execute_production_sql', 'mutate_uploads', 'force_replay',
    ];
    foreach ($bad as $k) {
        if (!empty($request[$k])) {
            if (in_array($k, [
                'force_enable', 'enablement_true', 'flip_enablement', 'write_enablement_flag',
                'set_flag_true', 'sa_enable', 'enable_action', 'begin_p9_03',
            ], true)) {
                return orange_cpr_epre_fail(
                    ORANGE_CPR_EPRE_ERR_FLAG_WRITE,
                    'Enablement flag write / SA Enable forbidden in WP-P9-02: ' . $k
                );
            }
            if (in_array($k, ['auto_enable', 'automatic_enablement'], true)) {
                return orange_cpr_epre_fail(
                    ORANGE_CPR_EPRE_ERR_AUTO,
                    'Automatic enablement forbidden: ' . $k
                );
            }
            if ($k === 'partial_enablement') {
                return orange_cpr_epre_fail(
                    ORANGE_CPR_EPRE_ERR_PARTIAL,
                    'Partial enablement forbidden.'
                );
            }
            if ($k === 'force_replay') {
                return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_REPLAY, 'Replay forbidden.');
            }

            return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_BYPASS, 'Unsafe enablement knob forbidden: ' . $k);
        }
    }

    return orange_cpr_epre_ok(['message' => 'unsafe knobs absent']);
}

/**
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_preconditions_live_validate_actor(array $request): array
{
    $role = strtolower(trim((string) ($request['actor_role'] ?? '')));
    $isOwner = !empty($request['actor_is_owner']) || $role === 'owner';
    $isSa = !empty($request['actor_is_super_admin']) || $role === 'super_admin';
    $isCountry = !empty($request['actor_is_country_admin']) || $role === 'country_admin';
    $isEngineering = !empty($request['actor_is_engineering']) || $role === 'engineering';

    if ($isCountry) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_PERMISSION,
            'Country Admin cannot assemble or authorize enablement preconditions (OD-PERM).'
        );
    }
    if ($isEngineering && !$isOwner && !$isSa) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_PERMISSION,
            'Engineering alone cannot assemble enablement preconditions / Owner order (OD-PERM / OD-ENABLE).'
        );
    }
    if (!$isOwner && !$isSa) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_ACTOR,
            'Actor must be Owner or Super Admin to seal enablement preconditions (assembly only; flag stays false).'
        );
    }
    $actorId = (int) ($request['actor_admin_id'] ?? 0);
    if ($actorId <= 0) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ACTOR, 'actor_admin_id required.');
    }

    return orange_cpr_epre_ok([
        'actor_id' => $actorId,
        'actor_is_owner' => $isOwner,
        'actor_is_super_admin' => $isSa,
    ]);
}

/**
 * @param array<string, mixed> $request
 * @param array<string, mixed> $cert
 * @return array<string, mixed>
 */
function orange_cpr_enablement_preconditions_live_validate_owner_order(
    array $request,
    array $cert
): array {
    $orderIn = $request['owner_enablement_order'] ?? null;
    if (!is_array($orderIn)) {
        // Flat request fields permitted.
        $orderIn = [
            'order_id' => $request['owner_enablement_order_id'] ?? ($request['order_id'] ?? ''),
            'issued_by' => $request['order_issued_by'] ?? ($request['issued_by'] ?? ''),
            'issued_by_actor_id' => $request['order_issued_by_actor_id'] ?? ($request['issued_by_actor_id'] ?? 0),
            'issued_at' => $request['order_issued_at'] ?? ($request['issued_at'] ?? ''),
            'schema_revision_bound' => $request['order_schema_revision_bound'] ?? ($request['schema_revision'] ?? 0),
            'certification_id' => $request['order_certification_id'] ?? ($request['certification_id'] ?? ''),
            'directive' => $request['order_directive'] ?? ($request['directive'] ?? ''),
            'sealed' => $request['order_sealed'] ?? true,
        ];
    }

    $orderId = trim((string) ($orderIn['order_id'] ?? ''));
    $issuedBy = strtolower(trim((string) ($orderIn['issued_by'] ?? '')));
    $issuedAt = trim((string) ($orderIn['issued_at'] ?? ''));
    $directive = trim((string) ($orderIn['directive'] ?? ''));
    $certId = trim((string) ($orderIn['certification_id'] ?? ''));
    $schemaBound = (int) ($orderIn['schema_revision_bound'] ?? 0);
    $issuerActor = (int) ($orderIn['issued_by_actor_id'] ?? ($orderIn['decided_by_actor_id'] ?? 0));
    $sealed = !empty($orderIn['sealed']);

    if ($orderId === '' || !preg_match('/^[0-9a-fA-F-]{8,64}$/', $orderId)) {
        // Allow UUID-like or generate-reject empty; require non-empty UUID format-ish
        if ($orderId === '') {
            return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ORDER, 'Owner enablement order_id required.');
        }
    }
    if ($issuedBy !== 'owner') {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_ORDER,
            'Owner enablement order issued_by must be owner (P1-13 §6.4).'
        );
    }
    if ($issuerActor <= 0) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ORDER, 'Owner enablement order issuer actor id required.');
    }
    if ($issuedAt === '') {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ORDER, 'Owner enablement order issued_at required.');
    }
    if ($directive !== ORANGE_CPR_EPRE_DIRECTIVE) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_ORDER,
            'Owner enablement order directive must be ENABLE_COUNTRY_PRODUCTION_RESTORE.'
        );
    }
    if (!$sealed) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ORDER, 'Owner enablement order must be sealed=true.');
    }
    if ($certId === '' || $certId !== (string) ($cert['certification_id'] ?? '')) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_CERT,
            'Owner enablement order certification_id must match sealed Cert PASS.'
        );
    }
    if ($schemaBound <= 0 || $schemaBound !== (int) ($cert['schema_revision_bound'] ?? 0)) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_SCHEMA,
            'Owner enablement order schema_revision_bound mismatch vs certification.'
        );
    }

    $order = [
        'schema_version' => ORANGE_CPR_EPRE_ORDER_SCHEMA,
        'order_id' => $orderId,
        'issued_by' => 'owner',
        'issued_by_actor_id' => $issuerActor,
        'issued_at' => $issuedAt,
        'schema_revision_bound' => $schemaBound,
        'certification_id' => $certId,
        'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
        'sealed' => true,
        'enablement_flag_after_order' => false,
        'auto_enable_forbidden' => true,
    ];

    return orange_cpr_epre_ok(['order' => $order]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_preconditions_live_assert_preconditions(array $env, array $request): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ENABLEMENT, $e->getMessage());
    }
    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_ENABLEMENT,
            'Ops enablement must remain FALSE during WP-P9-02 (OD-ENABLE; flag flip is WP-P9-03).'
        );
    }

    $unsafe = orange_cpr_enablement_preconditions_live_refuse_unsafe($request);
    if (empty($unsafe['ok'])) {
        return $unsafe;
    }

    $actor = orange_cpr_enablement_preconditions_live_validate_actor($request);
    if (empty($actor['ok'])) {
        return $actor;
    }

    if (!empty($request['cross_country_id']) || !empty($request['secondary_country_id'])) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_CROSS_COUNTRY,
            'Cross-country enablement forbidden.'
        );
    }

    $jobId = trim((string) ($request['job_id'] ?? ''));
    if ($jobId === '') {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_JOB, 'job_id required (must hold sealed Cert PASS).');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);

    $existing = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'preconditions');
    if (is_array($existing) && !empty($existing['sealed']) && !empty($existing['all_preconditions_met'])) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_DUPLICATE,
            'Enablement preconditions already sealed at E5 (exactly once; no replay).'
        );
    }
    $existingReport = orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'report');
    if (is_array($existingReport) && !empty($existingReport['sealed'])
        && !empty($existingReport['preconditions_complete'])
    ) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_REPLAY,
            'Enablement preconditions report already sealed (no replay).'
        );
    }

    try {
        orange_cpr_assert_job_id($jobId);
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_JOB, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    if (!is_array($contract) || empty($contract['contract_frozen'])) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_CONTRACT, 'Frozen execution contract required.');
    }

    $reqCountry = (int) ($request['country_id'] ?? 0);
    if ($reqCountry > 0 && $reqCountry !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_COUNTRY, 'Country mismatch vs job.');
    }
    $reqFp = trim((string) ($request['package_fingerprint'] ?? ''));
    if ($reqFp !== '' && !hash_equals((string) ($job['package_fingerprint'] ?? ''), $reqFp)) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_FINGERPRINT, 'Package fingerprint mismatch vs job.');
    }
    if ((string) ($contract['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')
        || (int) ($contract['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)
    ) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_CONTRACT, 'Contract/job identity drift.');
    }

    $cert = orange_cpr_owner_cert_decision_live_load_latest($cprRoot, $jobId, 'result');
    if (!is_array($cert)) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_MISSING,
            'Sealed cpr_certification_result missing (Owner Cert PASS required).'
        );
    }
    if (empty($cert['sealed']) || !orange_cpr_auth_verify_seal($cert)) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_CORRUPT,
            'Sealed cpr_certification_result corrupt / seal invalid.'
        );
    }
    if (($cert['schema_version'] ?? '') !== 'cpr_certification_result/1') {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_CORRUPT, 'Certification result schema invalid.');
    }
    if (($cert['result'] ?? '') !== 'PASS') {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_CERT,
            'Owner Certification must be PASS before enablement preconditions (OD-ENABLE / OD-CERT).'
        );
    }
    if (($cert['decided_by'] ?? '') !== 'owner') {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_CERT,
            'Certification PASS decided_by must be owner.'
        );
    }
    if ((string) ($cert['package_fingerprint'] ?? '') !== (string) ($job['package_fingerprint'] ?? '')) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_FINGERPRINT, 'Certification fingerprint mismatch vs job.');
    }
    if ((int) ($cert['country_id'] ?? 0) !== (int) ($job['country_id'] ?? 0)) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_COUNTRY, 'Certification country mismatch vs job.');
    }

    $schema = (int) ($contract['schema_revision_expected'] ?? 0);
    if ($schema <= 0 || (int) ($cert['schema_revision_bound'] ?? 0) !== $schema) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_SCHEMA,
            'Certification schema_revision_bound mismatch vs contract/live.'
        );
    }
    $reqSchema = (int) ($request['schema_revision'] ?? 0);
    if ($reqSchema > 0 && $reqSchema !== $schema) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_SCHEMA, 'Request schema_revision mismatch vs live/contract.');
    }
    if ((string) ($cert['certification_id'] ?? '') === '') {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_CORRUPT, 'Certification id missing.');
    }

    $implComplete = !empty($request['implementation_completed']);
    $enterprise = !empty($request['final_enterprise_approval']);
    $enterpriseId = trim((string) ($request['final_enterprise_approval_id'] ?? ''));
    if (!$implComplete) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_MISSING,
            'implementation_completed prerequisite missing/false (OD-ENABLE).'
        );
    }
    if (!$enterprise || $enterpriseId === '') {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_MISSING,
            'final_enterprise_approval / final_enterprise_approval_id prerequisite missing (OD-ENABLE).'
        );
    }

    $orderVal = orange_cpr_enablement_preconditions_live_validate_owner_order($request, $cert);
    if (empty($orderVal['ok'])) {
        return $orderVal;
    }
    /** @var array<string, mixed> $order */
    $order = $orderVal['order'];

    // Four OD-ENABLE preconditions — all must be true; no partial seal.
    $four = [
        'certification_pass' => true,
        'owner_enablement_order' => true,
        'implementation_completed' => true,
        'final_enterprise_approval' => true,
    ];
    foreach ($four as $k => $v) {
        if ($v !== true) {
            return orange_cpr_epre_fail(
                ORANGE_CPR_EPRE_ERR_PARTIAL,
                'Partial enablement forbidden; prerequisite failed: ' . $k
            );
        }
    }

    return [
        'ok' => true,
        'actor' => $actor,
        'job' => $job,
        'contract' => $contract,
        'job_id' => $jobId,
        'cpr_root' => $cprRoot,
        'cert' => $cert,
        'order' => $order,
        'schema_revision' => $schema,
        'final_enterprise_approval_id' => $enterpriseId,
    ];
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $request
 * @return array<string, mixed>
 */
function orange_cpr_enablement_preconditions_live_run(array $env, array $request = []): array
{
    $pre = orange_cpr_enablement_preconditions_live_assert_preconditions($env, $request);
    if (empty($pre['ok'])) {
        return $pre;
    }

    /** @var array<string, mixed> $job */
    $job = $pre['job'];
    /** @var array<string, mixed> $contract */
    $contract = $pre['contract'];
    /** @var array<string, mixed> $cert */
    $cert = $pre['cert'];
    /** @var array<string, mixed> $order */
    $order = $pre['order'];
    /** @var array<string, mixed> $actor */
    $actor = $pre['actor'];
    $jobId = (string) $pre['job_id'];
    $cprRoot = (string) $pre['cpr_root'];
    $schema = (int) $pre['schema_revision'];
    $enterpriseId = (string) $pre['final_enterprise_approval_id'];
    $actorId = (int) $actor['actor_id'];
    $now = gmdate('c');

    $preconditionsId = orange_cpr_generate_job_id();
    $certificationId = (string) ($cert['certification_id'] ?? '');
    $orderId = (string) ($order['order_id'] ?? '');
    $fp = (string) ($job['package_fingerprint'] ?? '');
    $countryId = (int) ($job['country_id'] ?? 0);
    $countryCode = (string) ($job['country_code'] ?? '');
    $packageCycleId = (string) ($cert['package_cycle_id'] ?? '');
    $decisionId = (string) ($cert['decision_id'] ?? '');

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_ENABLEMENT, 'Enablement flipped unexpectedly before seal.');
    }

    $checkpointsObserved = orange_cpr_checkpoint_list_committed($cprRoot, $jobId);

    $order['job_id'] = $jobId;
    $order['package_fingerprint'] = $fp;
    $order['country_id'] = $countryId;
    $order['country_code'] = $countryCode;
    $order['package_cycle_id'] = $packageCycleId;
    $order['preconditions_id'] = $preconditionsId;

    $preconditions = [
        'schema_version' => ORANGE_CPR_EPRE_PRECONDITIONS_SCHEMA,
        'preconditions_id' => $preconditionsId,
        'certification_pass' => true,
        'certification_id' => $certificationId,
        'owner_enablement_order' => true,
        'owner_enablement_order_id' => $orderId,
        'implementation_completed' => true,
        'final_enterprise_approval' => true,
        'final_enterprise_approval_id' => $enterpriseId,
        'schema_revision_bound' => $schema,
        'all_preconditions_met' => true,
        'auto_enable_forbidden' => true,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'automatic_enablement' => false,
        'partial_enablement' => false,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'package_cycle_id' => $packageCycleId,
        'decision_id' => $decisionId,
        'assembled_by_actor_id' => $actorId,
        'assembled_at' => $now,
        'sealed' => true,
    ];

    $preconditionsFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'schema_revision_bound' => $schema,
        'all_preconditions_met' => true,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'enablement_flag_after_preconditions' => false,
    ]));

    $orderFingerprint = hash('sha256', orange_cpr_auth_canonical_json([
        'order_id' => $orderId,
        'issued_by' => 'owner',
        'issued_by_actor_id' => (int) ($order['issued_by_actor_id'] ?? 0),
        'directive' => ORANGE_CPR_EPRE_DIRECTIVE,
        'certification_id' => $certificationId,
        'schema_revision_bound' => $schema,
    ]));

    $root = orange_cpr_enablement_preconditions_live_root($cprRoot, $jobId);
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_PERSIST, 'Cannot create enablement root.');
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.enablement_preconditions_live_start',
        'job_id' => $jobId,
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'enablement_flag_observed' => false,
        'auto_enable_forbidden' => true,
        'actor_admin_id' => $actorId,
        'production_resources_accessed' => false,
    ]);

    $recovery = [
        'job_id' => $jobId,
        'job_state' => (string) ($job['state'] ?? ''),
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_expected' => $schema,
        'certification_id' => $certificationId,
        'decision_id' => $decisionId,
        'package_cycle_id' => $packageCycleId,
        'preconditions_id' => $preconditionsId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'preconditions_fingerprint' => $preconditionsFingerprint,
        'order_fingerprint' => $orderFingerprint,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'all_preconditions_met' => true,
        'completed_phase' => 'p9_enablement_preconditions_sealed',
        'execution_status' => ORANGE_CPR_EPRE_STATE_E5,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'automatic_enablement' => false,
        'auto_enable_forbidden' => true,
        'enablement_action_not_started' => true,
        'p9_03_not_started' => true,
        'production_sql_executed' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'checkpoints_committed_observed' => $checkpointsObserved,
    ];

    $manifest = [
        'schema_version' => ORANGE_CPR_EPRE_MANIFEST_SCHEMA,
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'package_cycle_id' => $packageCycleId,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'all_preconditions_met' => true,
        'auto_enable_forbidden' => true,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'automatic_enablement' => false,
        'preconditions_fingerprint' => $preconditionsFingerprint,
        'order_fingerprint' => $orderFingerprint,
        'job_id' => $jobId,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'artifact_refs' => [
            'preconditions' => 'cpr_enablement_preconditions_latest.json',
            'order' => 'cpr_enablement_order_latest.json',
            'manifest' => 'cpr_enablement_manifest_latest.json',
            'report' => 'cpr_enablement_report_latest.json',
        ],
        'sealed' => true,
    ];

    $report = [
        'schema_version' => ORANGE_CPR_EPRE_SCHEMA,
        'engine_version' => ORANGE_CPR_EPRE_VERSION,
        'record_type' => 'enablement_preconditions_report',
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'package_cycle_id' => $packageCycleId,
        'job_id' => $jobId,
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'preconditions_complete' => true,
        'exactly_once' => true,
        'sealed' => true,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'all_preconditions_met' => true,
        'certification_pass' => true,
        'owner_enablement_order' => true,
        'implementation_completed' => true,
        'final_enterprise_approval' => true,
        'preconditions_fingerprint' => $preconditionsFingerprint,
        'order_fingerprint' => $orderFingerprint,
        'package_fingerprint' => $fp,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'schema_revision_bound' => $schema,
        'ops_enablement_flag' => false,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'auto_enable_forbidden' => true,
        'automatic_enablement' => false,
        'enablement_action_executed' => false,
        'production_sql_executed' => false,
        'production_mutation' => false,
        'production_uploads_mutated' => false,
        'production_resources_accessed' => false,
        'enablement_flag_observed' => false,
        'state_engine_integrated' => true,
        'checkpoint_engine_integrated' => true,
        'certification_consumed' => true,
        'recovery_metadata' => $recovery,
        'created_at' => $now,
    ];

    try {
        orange_cpr_enablement_preconditions_live_write_latest($cprRoot, $jobId, 'preconditions', $preconditions);
        orange_cpr_enablement_preconditions_live_write_latest($cprRoot, $jobId, 'order', $order);
        orange_cpr_enablement_preconditions_live_write_latest($cprRoot, $jobId, 'manifest', $manifest);
        orange_cpr_enablement_preconditions_live_write_latest($cprRoot, $jobId, 'report', $report);
    } catch (RuntimeException $e) {
        return orange_cpr_epre_fail(ORANGE_CPR_EPRE_ERR_PERSIST, $e->getMessage());
    }

    if (orange_cpr_enablement_flag_read($env)) {
        return orange_cpr_epre_fail(
            ORANGE_CPR_EPRE_ERR_ENABLEMENT,
            'E5 must not enable production; enablement observed true after preconditions seal.'
        );
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.enablement_preconditions_live_complete',
        'job_id' => $jobId,
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'all_preconditions_met' => true,
        'preconditions_fingerprint' => $preconditionsFingerprint,
        'order_fingerprint' => $orderFingerprint,
        'enablement_flag_after_preconditions' => false,
        'enablement_flag_observed' => false,
        'ops_flag_flipped_true' => false,
        'auto_enable_forbidden' => true,
        'automatic_enablement' => false,
        'production_resources_accessed' => false,
        'actor_admin_id' => $actorId,
    ]);

    return orange_cpr_epre_ok([
        'message' => 'Enablement preconditions sealed at E5; ops flag remains FALSE.',
        'job_id' => $jobId,
        'preconditions_id' => $preconditionsId,
        'certification_id' => $certificationId,
        'owner_enablement_order_id' => $orderId,
        'final_enterprise_approval_id' => $enterpriseId,
        'package_cycle_id' => $packageCycleId,
        'enablement_state' => ORANGE_CPR_EPRE_STATE_E5,
        'all_preconditions_met' => true,
        'preconditions_complete' => true,
        'sealed' => true,
        'exactly_once' => true,
        'preconditions_fingerprint' => $preconditionsFingerprint,
        'order_fingerprint' => $orderFingerprint,
        'enablement_flag_after_preconditions' => false,
        'ops_flag_flipped_true' => false,
        'auto_enable_forbidden' => true,
        'automatic_enablement' => false,
        'enablement_action_executed' => false,
        'preconditions' => orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'preconditions'),
        'owner_enablement_order' => orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'order'),
        'manifest' => orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'manifest'),
        'report' => orange_cpr_enablement_preconditions_live_load_latest($cprRoot, $jobId, 'report'),
        'recovery_metadata' => $recovery,
        'production_resources_accessed' => false,
    ]);
}
