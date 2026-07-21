<?php

declare(strict_types=1);

/**
 * CPR Lock Engine & Concurrency Enforcement (WP-P3-05).
 *
 * OD-LOCK-CROSS / OD-LOCK-SHADOW / OD-LOCK-TTL scaffolding.
 * Observes peer locks; does not modify Full DR / C6 / Backup Runner writers.
 * No DELETE/IMPORT/production mutation. No post-PONR automatic unlock.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_05_LOCK_SCAFFOLD.md
 * @see CPR-P1-WP05-LOCK_FORMATS
 */

require_once __DIR__ . '/cpr_job_framework.php';
require_once __DIR__ . '/cpr_state_catalog.php';

const ORANGE_CPR_LOCK_SCHEMA = 'cpr_lock/1';
const ORANGE_CPR_LOCK_KIND = 'country_production_restore';
const ORANGE_CPR_HEARTBEAT_INTERVAL_SEC = 30;
const ORANGE_CPR_STALE_OBSERVE_SEC = 90; // 3× interval (engineering default)

const ORANGE_CPR_LOCK_ERR_FULL_DR = 'cpr_blocked_full_dr_active';
const ORANGE_CPR_LOCK_ERR_C6 = 'cpr_blocked_c6_active';
const ORANGE_CPR_LOCK_ERR_BACKUP = 'cpr_blocked_backup_runner_active';
const ORANGE_CPR_LOCK_ERR_HELD = 'country_production_lock_held';
const ORANGE_CPR_LOCK_ERR_ENABLEMENT = 'cpr_enablement_blocks_lock';
const ORANGE_CPR_LOCK_ERR_IDENTITY = 'cpr_lock_identity_mismatch';
const ORANGE_CPR_LOCK_ERR_LEASE = 'cpr_lock_lease_mismatch';
const ORANGE_CPR_LOCK_ERR_ACTOR = 'cpr_lock_actor_forbidden';
const ORANGE_CPR_LOCK_ERR_NOT_STALE = 'cpr_lock_not_stale';
const ORANGE_CPR_LOCK_ERR_POST_PONR = 'cpr_post_ponr_auto_unlock_forbidden';
const ORANGE_CPR_LOCK_ERR_POST_PONR_CLEAR = 'cpr_post_ponr_manual_stale_clear_forbidden';
const ORANGE_CPR_LOCK_ERR_AUDIT = 'cpr_lock_manual_clear_audit_required';
const ORANGE_CPR_LOCK_ERR_SCHEMA = 'cpr_lock_schema_invalid';
const ORANGE_CPR_LOCK_ERR_MISSING = 'cpr_lock_not_held';
const ORANGE_CPR_LOCK_ERR_CLOSEOUT = 'cpr_lock_release_closeout_required';
const ORANGE_CPR_LOCK_ERR_CPR_ACTIVE = 'cpr_lock_blocks_peer';
const ORANGE_CPR_LOCK_ERR_BYPASS = 'cpr_lock_exclusion_bypass_forbidden';

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_lock_ok(array $extra = []): array
{
    return array_merge(['ok' => true, 'code' => 'ok', 'message' => 'OK'], $extra);
}

/**
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function orange_cpr_lock_fail(string $code, string $message, array $extra = []): array
{
    return array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra);
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_lock_read(string $cprRoot): ?array
{
    $path = orange_cpr_lock_file_path($cprRoot);
    if (!is_file($path)) {
        return null;
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? $data : null;
}

function orange_cpr_lock_file_held(string $path): bool
{
    return is_file($path) && filesize($path) !== 0;
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_peer_full_dr_active(array $env, ?string $cprRoot = null): bool
{
    foreach (orange_cpr_full_dr_lock_paths($env, $cprRoot) as $path) {
        if (orange_cpr_lock_file_held($path)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_peer_c6_active(array $env, ?string $cprRoot = null): bool
{
    return orange_cpr_lock_file_held(orange_cpr_c6_lock_path($env, $cprRoot));
}

/**
 * @param array<string, mixed> $env
 */
function orange_cpr_peer_backup_runner_active(array $env): bool
{
    try {
        return orange_cpr_lock_file_held(orange_cpr_backup_runner_lock_path($env));
    } catch (Throwable) {
        return false;
    }
}

/**
 * Symmetric peer gate: Full DR / C6 / Backup Runner must refuse when CPR held.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_exclusion_check_for_peer(array $env, string $peerKind): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null) {
        return orange_cpr_lock_ok(['cpr_held' => false, 'peer_kind' => $peerKind]);
    }

    return orange_cpr_lock_fail(
        ORANGE_CPR_LOCK_ERR_CPR_ACTIVE,
        'CPR lock held; peer feature refused (OD-LOCK-CROSS / OD-LOCK-SHADOW / §16).',
        [
            'cpr_held' => true,
            'peer_kind' => $peerKind,
            'job_id' => $lock['job_id'] ?? null,
            'ponr_crossed' => !empty($lock['ponr_crossed']),
        ]
    );
}

/**
 * @param array<string, mixed> $lock
 * @return array<string, mixed>
 */
function orange_cpr_lock_stale_classify(array $lock, ?int $nowTs = null): array
{
    $nowTs ??= time();
    $hb = (string) ($lock['heartbeat_at'] ?? '');
    $hbTs = $hb !== '' ? strtotime($hb) : false;
    $age = ($hbTs === false) ? PHP_INT_MAX : max(0, $nowTs - $hbTs);
    $pid = (int) ($lock['pid'] ?? 0);
    $pidDead = $pid > 0 ? !orange_cpr_lock_process_alive($pid) : true;
    $stale = ($age > ORANGE_CPR_STALE_OBSERVE_SEC) || $pidDead;

    return [
        'is_heartbeat_stale' => $stale,
        'heartbeat_age_sec' => $age,
        'stale_observe_after_sec' => ORANGE_CPR_STALE_OBSERVE_SEC,
        'pid' => $pid,
        'pid_dead' => $pidDead,
        'ponr_crossed' => !empty($lock['ponr_crossed']),
        'auto_unlock_authorized' => false,
    ];
}

function orange_cpr_lock_process_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'tasklist /FI "PID eq ' . $pid . '" /NH';
        $out = [];
        @exec($cmd, $out);
        $joined = strtolower(implode(' ', $out));

        return str_contains($joined, (string) $pid);
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }

    return file_exists('/proc/' . $pid);
}

/**
 * Complete concurrency validation before CPR acquire (P1-05 §7.1).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_concurrency_validate(array $env, string $jobId, array $context = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_ENABLEMENT, $e->getMessage());
    }
    if (!empty($context['force']) || !empty($context['bypass_exclusion'])) {
        return orange_cpr_lock_fail(
            ORANGE_CPR_LOCK_ERR_BYPASS,
            'Exclusion bypass is forbidden (OD-LOCK-CROSS / OD-LOCK-SHADOW; H6).'
        );
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    if (orange_cpr_peer_full_dr_active($env, $cprRoot)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_FULL_DR, 'Full DR lock family active (OD-LOCK-CROSS).');
    }
    if (orange_cpr_peer_c6_active($env, $cprRoot)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_C6, 'C6 Country Shadow lock active (OD-LOCK-SHADOW).');
    }
    if (orange_cpr_peer_backup_runner_active($env)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_BACKUP, 'Backup Runner lock active.');
    }

    $existing = orange_cpr_lock_read($cprRoot);
    if ($existing !== null) {
        $holder = (string) ($existing['job_id'] ?? '');
        if ($holder !== '' && $holder !== $jobId) {
            return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_HELD, 'CPR lock held by another job.', [
                'holder_job_id' => $holder,
                'stale' => orange_cpr_lock_stale_classify($existing),
            ]);
        }
    }

    return orange_cpr_lock_ok([
        'job_id' => $jobId,
        'full_dr_active' => false,
        'c6_active' => false,
        'backup_runner_active' => false,
        'cpr_lock_same_job' => $existing !== null,
    ]);
}

/**
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context worker_id, owner_class, actor_admin_id, lease_token?
 * @return array<string, mixed>
 */
function orange_cpr_lock_acquire(array $env, string $jobId, array $context = []): array
{
    $gate = orange_cpr_concurrency_validate($env, $jobId, $context);
    if (empty($gate['ok'])) {
        return $gate;
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_IDENTITY, $e->getMessage());
    }

    $contract = orange_cpr_contract_read($cprRoot, $jobId);
    $existing = orange_cpr_lock_read($cprRoot);
    if ($existing !== null && (string) ($existing['job_id'] ?? '') === $jobId) {
        return orange_cpr_lock_heartbeat($env, $jobId, [
            'lease_token' => (string) ($context['lease_token'] ?? ($existing['ownership']['lease_token'] ?? '')),
            'worker_id' => (string) ($context['worker_id'] ?? ($existing['ownership']['worker_id'] ?? '')),
        ]);
    }

    $now = gmdate('c');
    $lease = bin2hex(random_bytes(16));
    $workerId = (string) ($context['worker_id'] ?? orange_cpr_generate_job_id());
    $ownerClass = (string) ($context['owner_class'] ?? 'system_worker');
    if (!in_array($ownerClass, ['system_worker', 'super_admin_procedure'], true)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Invalid owner_class.');
    }

    $state = (string) ($job['state'] ?? 'cpr_pending');
    $maintRequired = in_array($state, [
        'cpr_maintenance_on', 'cpr_anchor_pinning', 'cpr_pre_ponr',
    ], true) || !empty($job['ponr_crossed']);

    $payload = [
        'schema_version' => ORANGE_CPR_LOCK_SCHEMA,
        'lock_kind' => ORANGE_CPR_LOCK_KIND,
        'job_id' => $jobId,
        'country_id' => (int) ($job['country_id'] ?? 0),
        'package_id' => (string) ($job['package_id'] ?? ''),
        'package_fingerprint' => (string) ($job['package_fingerprint'] ?? ''),
        'phase' => $state,
        'ponr_crossed' => !empty($job['ponr_crossed']),
        'heartbeat_at' => $now,
        'acquired_at' => $now,
        'pid' => getmypid() ?: 1,
        'hostname' => gethostname() ?: 'unknown',
        'maint_global_required' => $maintRequired,
        'idempotency_key' => (string) ($job['idempotency_key'] ?? ''),
        'contract_revision' => is_array($contract)
            ? (int) ($contract['contract_revision'] ?? 0)
            : (int) ($job['contract_revision'] ?? 0),
        'last_checkpoint_id' => $job['last_checkpoint_id'] ?? null,
        'ownership' => [
            'owner_class' => $ownerClass,
            'worker_id' => $workerId,
            'acquired_by_admin_id' => $context['actor_admin_id'] ?? null,
            'acquired_by_username' => $context['actor_username'] ?? null,
            'lease_token' => $lease,
            'deployment_id' => $context['deployment_id'] ?? null,
        ],
    ];

    $schema = orange_cpr_lock_validate_payload($payload);
    if (empty($schema['ok'])) {
        return $schema;
    }

    $path = orange_cpr_lock_file_path($cprRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Cannot create CPR lock directory.');
    }

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Lock encode failed.');
    }

    $fh = @fopen($path, 'x');
    if ($fh === false) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_HELD, 'CPR lock exclusive create failed (concurrent).');
    }
    fwrite($fh, $json);
    fflush($fh);
    fclose($fh);

    $job['lock_held'] = true;
    $job['updated_at'] = $now;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_acquire',
        'job_id' => $jobId,
        'phase' => $state,
        'ponr_crossed' => !empty($payload['ponr_crossed']),
        'lease_token' => $lease,
        'last_checkpoint_id' => $payload['last_checkpoint_id'],
        'enablement_flag_observed' => false,
    ]);

    return orange_cpr_lock_ok([
        'lock' => $payload,
        'path' => $path,
        'lease_token' => $lease,
    ]);
}

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function orange_cpr_lock_validate_payload(array $payload): array
{
    $required = [
        'schema_version', 'lock_kind', 'job_id', 'country_id', 'package_id',
        'phase', 'ponr_crossed', 'heartbeat_at', 'acquired_at', 'pid',
        'ownership', 'hostname', 'maint_global_required',
    ];
    foreach ($required as $key) {
        if (!array_key_exists($key, $payload)) {
            return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Missing lock field: ' . $key);
        }
    }
    if (($payload['schema_version'] ?? '') !== ORANGE_CPR_LOCK_SCHEMA
        || ($payload['lock_kind'] ?? '') !== ORANGE_CPR_LOCK_KIND
    ) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Invalid CPR lock schema/kind.');
    }
    $own = $payload['ownership'] ?? null;
    if (!is_array($own)
        || empty($own['owner_class'])
        || empty($own['worker_id'])
        || empty($own['lease_token'])
        || !array_key_exists('acquired_by_admin_id', $own)
    ) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Invalid ownership object.');
    }
    foreach (array_keys($payload) as $k) {
        $kl = strtolower((string) $k);
        if (str_contains($kl, 'password') || str_contains($kl, 'secret') || $kl === 'api_key') {
            return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Secrets forbidden in lock payload.');
        }
    }

    return orange_cpr_lock_ok();
}

/**
 * Heartbeat refresh — must not drop ponr_crossed / job_id / lease_token.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_lock_heartbeat(array $env, string $jobId, array $context = []): array
{
    try {
        orange_cpr_assert_enablement_false_for_scaffold($env);
    } catch (RuntimeException $e) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_ENABLEMENT, $e->getMessage());
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_MISSING, 'CPR lock not held.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_IDENTITY, 'Heartbeat job_id mismatch.');
    }
    $lease = (string) ($context['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_LEASE, 'Heartbeat lease_token mismatch.');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
    } catch (RuntimeException $e) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_IDENTITY, $e->getMessage());
    }

    $lock['heartbeat_at'] = gmdate('c');
    $lock['pid'] = getmypid() ?: (int) ($lock['pid'] ?? 1);
    $lock['phase'] = (string) ($job['state'] ?? $lock['phase']);
    $lock['ponr_crossed'] = !empty($job['ponr_crossed']) || !empty($lock['ponr_crossed']);
    $lock['last_checkpoint_id'] = $job['last_checkpoint_id'] ?? ($lock['last_checkpoint_id'] ?? null);
    // Preserve ownership identity
    $lock['ownership']['lease_token'] = $heldLease;
    $lock['ownership']['worker_id'] = (string) ($context['worker_id'] ?? $lock['ownership']['worker_id']);

    try {
        orange_cpr_lock_atomic_write($cprRoot, $lock);
    } catch (RuntimeException $e) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, $e->getMessage());
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_heartbeat',
        'job_id' => $jobId,
        'heartbeat_at' => $lock['heartbeat_at'],
        'phase' => $lock['phase'],
        'ponr_crossed' => !empty($lock['ponr_crossed']),
        'last_checkpoint_id' => $lock['last_checkpoint_id'],
    ]);

    return orange_cpr_lock_ok(['lock' => $lock]);
}

/**
 * @param array<string, mixed> $lock
 */
function orange_cpr_lock_atomic_write(string $cprRoot, array $lock): void
{
    $path = orange_cpr_lock_file_path($cprRoot);
    $tmp = $path . '.hb.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($lock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false || @file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('CPR lock tmp write failed.');
    }
    orange_cpr_atomic_rename_replace($tmp, $path);
}

/**
 * Mark ponr_crossed on lock (scaffold; no mutation engine).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_lock_mark_ponr(array $env, string $jobId, array $context = []): array
{
    $hb = orange_cpr_lock_heartbeat($env, $jobId, $context);
    if (empty($hb['ok'])) {
        return $hb;
    }
    /** @var array<string, mixed> $lock */
    $lock = $hb['lock'];
    $lock['ponr_crossed'] = true;
    $cprRoot = orange_cpr_resolve_work_root($env);
    orange_cpr_lock_atomic_write($cprRoot, $lock);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_ponr_marked',
        'job_id' => $jobId,
        'ponr_crossed' => true,
        'scaffold_record_only' => true,
    ]);

    return orange_cpr_lock_ok(['lock' => $lock]);
}

/**
 * Normal release — never post-PONR auto/TTL; post-PONR requires authorized_closeout.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_lock_release(array $env, string $jobId, array $context = []): array
{
    if (!empty($context['auto_unlock']) || !empty($context['ttl_unlock']) || !empty($context['watchdog_unlock'])) {
        return orange_cpr_lock_refuse_post_ponr_auto_unlock();
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_MISSING, 'CPR lock not held.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_IDENTITY, 'Release job_id mismatch.');
    }
    $lease = (string) ($context['lease_token'] ?? '');
    $heldLease = (string) ($lock['ownership']['lease_token'] ?? '');
    if ($lease === '' || !hash_equals($heldLease, $lease)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_LEASE, 'Release lease_token mismatch.');
    }

    $ponr = !empty($lock['ponr_crossed']);
    if ($ponr && empty($context['authorized_closeout'])) {
        return orange_cpr_lock_fail(
            ORANGE_CPR_LOCK_ERR_CLOSEOUT,
            'Post-PONR lock release requires authorized closeout (never TTL/auto).'
        );
    }

    $path = orange_cpr_lock_file_path($cprRoot);
    if (!@unlink($path) && is_file($path)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Failed to remove CPR lock file.');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $job['lock_held'] = false;
        $job['updated_at'] = gmdate('c');
        orange_cpr_job_write($cprRoot, $jobId, $job);
    } catch (RuntimeException) {
        // lock removed; job update best-effort
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_release',
        'job_id' => $jobId,
        'ponr_crossed' => $ponr,
        'authorized_closeout' => !empty($context['authorized_closeout']),
    ]);

    return orange_cpr_lock_ok(['released' => true, 'ponr_crossed' => $ponr]);
}

/**
 * Pre-PONR stale manual clear — Super Admin only + durable audit (P1-05 §8.3 / §9).
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $context
 * @return array<string, mixed>
 */
function orange_cpr_lock_manual_clear_pre_ponr(array $env, string $jobId, array $context = []): array
{
    $actor = (string) ($context['actor'] ?? '');
    if ($actor === ORANGE_CPR_ACTOR_COUNTRY_ADMIN) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_ACTOR, 'Country Admin cannot clear CPR locks.');
    }
    if ($actor !== ORANGE_CPR_ACTOR_SUPER_ADMIN) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_ACTOR, 'Pre-PONR manual clear requires Super Admin.');
    }
    $adminId = (int) ($context['actor_admin_id'] ?? 0);
    if ($adminId < 1) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_ACTOR, 'cleared_by_admin_id required.');
    }
    $reason = trim((string) ($context['reason'] ?? ''));
    if (strlen($reason) < 8) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_AUDIT, 'Manual clear reason min length 8.');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_MISSING, 'CPR lock not held.');
    }
    if ((string) ($lock['job_id'] ?? '') !== $jobId) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_IDENTITY, 'Manual clear job_id mismatch.');
    }
    if (!empty($lock['ponr_crossed'])) {
        return orange_cpr_lock_fail(
            ORANGE_CPR_LOCK_ERR_POST_PONR_CLEAR,
            'Post-PONR stale clear-as-unlock forbidden (OD-LOCK-TTL).'
        );
    }

    $meta = orange_cpr_state_meta_map();
    $phase = (string) ($lock['phase'] ?? '');
    if (isset($meta[$phase]) && !empty($meta[$phase]['post_ponr'])) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_POST_PONR_CLEAR, 'Phase is post-PONR; manual stale clear forbidden.');
    }

    $stale = orange_cpr_lock_stale_classify($lock);
    if (empty($stale['is_heartbeat_stale']) && empty($context['operator_confirms_stale'])) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_NOT_STALE, 'Lock not stale; Super Admin must confirm stale evidence.');
    }
    if (empty($stale['is_heartbeat_stale']) && !empty($context['operator_confirms_stale'])) {
        // still require explicit confirm only when not observably stale — reject without evidence
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_NOT_STALE, 'Cannot clear non-stale lock even with confirm.');
    }

    $path = orange_cpr_lock_file_path($cprRoot);
    $raw = (string) file_get_contents($path);
    $priorHash = hash('sha256', $raw);
    $clearedAt = gmdate('c');
    $auditDir = orange_cpr_lock_manual_clear_audit_directory($cprRoot, $jobId);
    if (!is_dir($auditDir) && !@mkdir($auditDir, 0775, true) && !is_dir($auditDir)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_AUDIT, 'Cannot create manual clear audit directory.');
    }
    $auditName = 'lock_manual_clear_' . gmdate('Ymd\THis') . '_' . bin2hex(random_bytes(4)) . '.json';
    $auditPath = $auditDir . DIRECTORY_SEPARATOR . $auditName;
    $audit = [
        'event_type' => 'cpr_lock_manual_clear',
        'job_id' => $jobId,
        'country_id' => (int) ($lock['country_id'] ?? 0),
        'package_id' => (string) ($lock['package_id'] ?? ''),
        'cleared_at' => $clearedAt,
        'cleared_by_admin_id' => $adminId,
        'cleared_by_username' => $context['actor_username'] ?? null,
        'reason' => $reason,
        'ponr_crossed_observed' => false,
        'phase_observed' => $phase,
        'prior_lock_sha256' => $priorHash,
        'prior_heartbeat_at' => (string) ($lock['heartbeat_at'] ?? ''),
        'prior_pid' => (int) ($lock['pid'] ?? 0),
        'stale_evidence' => $stale,
        'lease_token_observed' => (string) ($lock['ownership']['lease_token'] ?? ''),
        'last_checkpoint_id' => $lock['last_checkpoint_id'] ?? null,
    ];
    $auditJson = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($auditJson === false || @file_put_contents($auditPath, $auditJson) === false) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_AUDIT, 'Manual clear audit write failed; clear aborted.');
    }

    if (!@unlink($path) && is_file($path)) {
        return orange_cpr_lock_fail(ORANGE_CPR_LOCK_ERR_SCHEMA, 'Audit written but lock unlink failed.');
    }

    try {
        $job = orange_cpr_job_read($cprRoot, $jobId);
        $job['lock_held'] = false;
        $job['updated_at'] = $clearedAt;
        orange_cpr_job_write($cprRoot, $jobId, $job);
    } catch (RuntimeException) {
    }

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.lock_manual_clear',
        'job_id' => $jobId,
        'audit_path' => $auditPath,
        'cleared_by_admin_id' => $adminId,
        'ponr_crossed_observed' => false,
    ]);

    return orange_cpr_lock_ok([
        'cleared' => true,
        'audit_path' => $auditPath,
        'audit' => $audit,
    ]);
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_lock_refuse_post_ponr_auto_unlock(): array
{
    return orange_cpr_lock_fail(
        ORANGE_CPR_LOCK_ERR_POST_PONR,
        'Post-PONR automatic unlock is permanently forbidden (OD-LOCK-TTL; H5).'
    );
}

/**
 * Attempt TTL/watchdog auto-unlock — always fail-closed.
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_lock_auto_unlock_attempt(array $env): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $lock = orange_cpr_lock_read($cprRoot);
    if ($lock === null) {
        return orange_cpr_lock_ok(['held' => false, 'unlocked' => false]);
    }
    // Whether pre or post PONR: automatic unlock is never performed by this scaffold.
    if (!empty($lock['ponr_crossed'])) {
        return orange_cpr_lock_refuse_post_ponr_auto_unlock();
    }
    $stale = orange_cpr_lock_stale_classify($lock);

    return orange_cpr_lock_fail(
        ORANGE_CPR_LOCK_ERR_ACTOR,
        'Automatic pre-PONR unlock forbidden; Super Admin manual clear + audit required.',
        ['stale' => $stale, 'unlocked' => false]
    );
}
