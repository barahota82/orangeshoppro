<?php

declare(strict_types=1);

/**
 * CPR Job Framework Scaffolding (WP-P3-02).
 *
 * Create / read / list / cancel + execution-contract freeze (pre_pin).
 * No PONR, no DELETE/IMPORT, no uploads apply, no C3–C8 mutation.
 *
 * @see docs/backup/COUNTRY_PRODUCTION_RESTORE_P3_02_JOB_FRAMEWORK.md
 * @see CPR-P1-WP02-EXECUTION_CONTRACT
 * @see CPR-P1-WP03-STATE_TRANSITION_MATRIX (pre-PONR subset)
 */

require_once __DIR__ . '/cpr_paths.php';
require_once __DIR__ . '/cpr_enablement.php';

/** Pre-PONR states this scaffold may create or cancel from (P1-03). */
const ORANGE_CPR_STATE_PENDING = 'cpr_pending';
const ORANGE_CPR_STATE_GATES_VALIDATING = 'cpr_gates_validating';
const ORANGE_CPR_STATE_AWAITING_APPROVALS = 'cpr_awaiting_approvals';
const ORANGE_CPR_STATE_CONTRACT_FROZEN = 'cpr_contract_frozen';
const ORANGE_CPR_STATE_CANCELLED_PRE_PONR = 'cpr_cancelled_pre_ponr';
const ORANGE_CPR_STATE_FAILED_PRE_PONR = 'cpr_failed_pre_ponr';

/** States forbidden for this scaffold to enter (PONR / apply). */
const ORANGE_CPR_FORBIDDEN_STATES = [
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
    'cpr_rolling_back',
    'cpr_rollback_completed',
    'cpr_failed_post_ponr',
    'cpr_maintenance_on',
    'cpr_anchor_pinning',
    'cpr_maintenance_released',
];

/**
 * @return list<string>
 */
function orange_cpr_scaffold_cancellable_states(): array
{
    return [
        ORANGE_CPR_STATE_PENDING,
        ORANGE_CPR_STATE_GATES_VALIDATING,
        ORANGE_CPR_STATE_AWAITING_APPROVALS,
        ORANGE_CPR_STATE_CONTRACT_FROZEN,
    ];
}

/**
 * @param array<string, mixed> $event
 */
function orange_cpr_audit_append(string $cprRoot, string $jobId, array $event): void
{
    $path = orange_cpr_audit_file_path($cprRoot, $jobId);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR audit directory.');
    }

    $blocked = ['password', 'passwd', 'token', 'secret', 'credential', 'api_key'];
    $safe = [];
    foreach ($event as $key => $value) {
        $keyLower = strtolower((string) $key);
        $skip = false;
        foreach ($blocked as $fragment) {
            if (str_contains($keyLower, $fragment)) {
                $skip = true;
                break;
            }
        }
        if (!$skip) {
            $safe[(string) $key] = $value;
        }
    }
    $safe['at'] = $safe['at'] ?? gmdate('c');
    $line = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        throw new RuntimeException('CPR audit encode failed.');
    }
    if (@file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('CPR audit write failed.');
    }
}

/**
 * @return array<string, mixed>
 */
function orange_cpr_job_read(string $cprRoot, string $jobId): array
{
    $path = orange_cpr_job_file_path($cprRoot, $jobId);
    if (!is_file($path)) {
        throw new RuntimeException('CPR job not found: ' . $jobId);
    }
    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('CPR job file unreadable.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('CPR job JSON invalid.');
    }

    return $data;
}

/**
 * @param array<string, mixed> $job
 */
function orange_cpr_job_write(string $cprRoot, string $jobId, array $job): void
{
    $dir = orange_cpr_job_directory($cprRoot, $jobId);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR job directory.');
    }
    $path = orange_cpr_job_file_path($cprRoot, $jobId);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('CPR job encode failed.');
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('CPR job temp write failed.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('CPR job atomic rename failed.');
    }
}

/**
 * @return array<string, mixed>|null
 */
function orange_cpr_contract_read(string $cprRoot, string $jobId): ?array
{
    $path = orange_cpr_contract_file_path($cprRoot, $jobId);
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

/**
 * @param array<string, mixed> $contract
 */
function orange_cpr_contract_write(string $cprRoot, string $jobId, array $contract): void
{
    $path = orange_cpr_contract_file_path($cprRoot, $jobId);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create CPR contract directory.');
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    $json = json_encode($contract, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        throw new RuntimeException('CPR contract encode failed.');
    }
    if (@file_put_contents($tmp, $json) === false) {
        throw new RuntimeException('CPR contract temp write failed.');
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('CPR contract atomic rename failed.');
    }
}

function orange_cpr_assert_not_forbidden_state(string $state): void
{
    if (in_array($state, ORANGE_CPR_FORBIDDEN_STATES, true)) {
        throw new RuntimeException('CPR scaffold forbids PONR/apply state: ' . $state);
    }
}

/**
 * Build idempotency key per P1-02 (session backup may be empty pre-pin).
 */
function orange_cpr_build_idempotency_key(
    string $packageFingerprint,
    int $countryId,
    ?string $sessionFullBackupId
): string {
    $pinPart = $sessionFullBackupId !== null && $sessionFullBackupId !== ''
        ? $sessionFullBackupId
        : 'pre_pin';

    return hash('sha256', $packageFingerprint . '|' . (string) $countryId . '|' . $pinPart);
}

/**
 * Create a CPR job in cpr_pending. Does not mutate production data.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $input Required: package_id, package_fingerprint, country_id, country_code, workflow (A|B)
 * @return array<string, mixed>
 */
function orange_cpr_job_create(array $env, array $input, ?int $actorAdminId = null): array
{
    orange_cpr_assert_enablement_false_for_scaffold($env);
    if (orange_cpr_enablement_flag_read($env)) {
        throw new RuntimeException('CPR job create blocked: enablement must be false (OD-ENABLE).');
    }

    $packageId = trim((string) ($input['package_id'] ?? ''));
    $packageFingerprint = trim((string) ($input['package_fingerprint'] ?? ''));
    $countryId = (int) ($input['country_id'] ?? 0);
    $countryCode = strtoupper(trim((string) ($input['country_code'] ?? '')));
    $workflow = strtoupper(trim((string) ($input['workflow'] ?? '')));

    if ($packageId === '' || strlen($packageFingerprint) < 16 || $countryId < 1 || strlen($countryCode) < 2) {
        throw new RuntimeException('CPR job create: package_id, package_fingerprint, country_id, country_code required.');
    }
    if ($workflow !== 'A' && $workflow !== 'B') {
        throw new RuntimeException('CPR job create: workflow must be A or B (OD-DUAL).');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $jobId = orange_cpr_generate_job_id();
    $createdAt = gmdate('c');
    $idempotencyKey = orange_cpr_build_idempotency_key($packageFingerprint, $countryId, null);

    $job = [
        'schema_version' => 'cpr_job/1',
        'scaffold_version' => ORANGE_CPR_SCAFFOLD_VERSION,
        'job_id' => $jobId,
        'idempotency_key' => $idempotencyKey,
        'package_id' => $packageId,
        'package_fingerprint' => $packageFingerprint,
        'country_id' => $countryId,
        'country_code' => $countryCode,
        'workflow' => $workflow,
        'state' => ORANGE_CPR_STATE_PENDING,
        'ponr_crossed' => false,
        'http_mutation_forbidden' => true,
        'enablement_flag_observed' => false,
        'created_at' => $createdAt,
        'created_by_admin_id' => $actorAdminId,
        'updated_at' => $createdAt,
        'cancelled_at' => null,
        'contract_frozen' => false,
        'contract_revision' => null,
        // Reserved for later P3 WPs (locks / checkpoints / gates) — unused here.
        'lock_held' => false,
        'last_checkpoint_id' => null,
        'last_gate_eval_ref' => null,
        'mutation_engines' => [
            'delete' => false,
            'import' => false,
            'uploads' => false,
            'ponr' => false,
        ],
    ];

    orange_cpr_assert_not_forbidden_state($job['state']);
    orange_cpr_job_write($cprRoot, $jobId, $job);
    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.job_create',
        'job_id' => $jobId,
        'state' => $job['state'],
        'workflow' => $workflow,
        'country_id' => $countryId,
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
    ]);

    return $job;
}

/**
 * @param array<string, mixed> $env
 * @return list<array<string, mixed>>
 */
function orange_cpr_job_list(array $env, ?int $countryIdFilter = null): array
{
    $cprRoot = orange_cpr_resolve_work_root($env);
    $out = [];
    $entries = @scandir($cprRoot);
    if (!is_array($entries)) {
        return [];
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
            continue;
        }
        $jobFile = $cprRoot . DIRECTORY_SEPARATOR . $entry . DIRECTORY_SEPARATOR . ORANGE_CPR_JOB_FILENAME;
        if (!is_file($jobFile)) {
            continue;
        }
        try {
            $job = orange_cpr_job_read($cprRoot, $entry);
        } catch (Throwable) {
            continue;
        }
        if ($countryIdFilter !== null && (int) ($job['country_id'] ?? 0) !== $countryIdFilter) {
            continue;
        }
        $out[] = $job;
    }
    usort($out, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    return $out;
}

/**
 * Cancel a pre-PONR job → cpr_cancelled_pre_ponr (P1-03 T22/T24/T24E family via state engine).
 *
 * @param array<string, mixed> $env
 * @return array<string, mixed>
 */
function orange_cpr_job_cancel(array $env, string $jobId, ?int $actorAdminId = null, string $reason = 'cancelled'): array
{
    require_once __DIR__ . '/cpr_state_engine.php';

    orange_cpr_assert_enablement_false_for_scaffold($env);
    $cprRoot = orange_cpr_resolve_work_root($env);
    $job = orange_cpr_job_read($cprRoot, $jobId);
    if (!empty($job['ponr_crossed'])) {
        throw new RuntimeException('CPR scaffold cannot cancel post-PONR jobs via pre-PONR cancel.');
    }

    $trigger = 'cancel_pre_ponr';
    $state = (string) ($job['state'] ?? '');
    if ($state === 'cpr_maintenance_on' || $state === 'cpr_pre_ponr') {
        $trigger = 'cancel_or_estop';
    }

    $result = orange_cpr_transition_apply($env, $jobId, ORANGE_CPR_STATE_CANCELLED_PRE_PONR, [
        'actor' => ORANGE_CPR_ACTOR_SUPER_ADMIN,
        'actor_admin_id' => $actorAdminId,
        'trigger' => $trigger,
        'reason' => $reason,
    ]);
    if (empty($result['ok'])) {
        throw new RuntimeException(
            'CPR job cancel rejected: ' . (string) ($result['code'] ?? 'error')
            . ' — ' . (string) ($result['message'] ?? '')
        );
    }

    /** @var array<string, mixed> $cancelled */
    $cancelled = $result['job'];

    return $cancelled;
}

/**
 * Freeze execution contract at pre_pin phase (P1-02). Does not attach session pin (P4).
 * Does not authorize PONR.
 *
 * @param array<string, mixed> $env
 * @param array<string, mixed> $fingerprints Keys aligned to P1-02 freeze_initial profile
 * @return array<string, mixed> contract
 */
function orange_cpr_contract_freeze_initial(
    array $env,
    string $jobId,
    array $fingerprints,
    ?int $actorAdminId = null
): array {
    orange_cpr_assert_enablement_false_for_scaffold($env);
    if (orange_cpr_enablement_flag_read($env)) {
        throw new RuntimeException('Contract freeze blocked: enablement must be false.');
    }

    $cprRoot = orange_cpr_resolve_work_root($env);
    $job = orange_cpr_job_read($cprRoot, $jobId);
    $state = (string) ($job['state'] ?? '');
    if (!in_array($state, [ORANGE_CPR_STATE_PENDING, ORANGE_CPR_STATE_GATES_VALIDATING, ORANGE_CPR_STATE_AWAITING_APPROVALS], true)
        && $state !== ORANGE_CPR_STATE_CONTRACT_FROZEN) {
        // Allow freeze from pending/gates/approvals; re-freeze rejected if already frozen with different package.
        if ($state !== ORANGE_CPR_STATE_CONTRACT_FROZEN) {
            throw new RuntimeException('Contract freeze not allowed from state: ' . $state);
        }
    }
    if (!empty($job['ponr_crossed'])) {
        throw new RuntimeException('Contract freeze forbidden after PONR.');
    }

    $required = [
        'schema_revision_expected',
        'boundary_policy_version',
        'dependency_graph_version',
        'registry_revision',
        'c4_report_hash',
        'c5_report_hash',
        'c6_report_hash',
        'c7_report_hash',
        'c8_report_hash',
        'inventory_snapshot_id',
        'inventory_snapshot_hash',
        'production_db_identity_hash',
    ];
    foreach ($required as $key) {
        if (!isset($fingerprints[$key]) || $fingerprints[$key] === '' || $fingerprints[$key] === null) {
            throw new RuntimeException('Contract freeze missing field: ' . $key);
        }
    }
    if ((string) ($fingerprints['c8_overall_result'] ?? '') !== 'SAFE') {
        throw new RuntimeException('Contract freeze requires c8_overall_result=SAFE (OD-C8).');
    }

    // Reject illegal package/country swap vs job identity.
    if (isset($fingerprints['package_fingerprint'])
        && (string) $fingerprints['package_fingerprint'] !== (string) $job['package_fingerprint']) {
        throw new RuntimeException('Contract package_fingerprint drift vs job identity.');
    }
    if (isset($fingerprints['country_id']) && (int) $fingerprints['country_id'] !== (int) $job['country_id']) {
        throw new RuntimeException('Contract country_id drift vs job identity.');
    }

    $existing = orange_cpr_contract_read($cprRoot, $jobId);
    if (is_array($existing) && !empty($existing['contract_frozen'])) {
        if ((string) ($existing['package_fingerprint'] ?? '') !== (string) $job['package_fingerprint']
            || (int) ($existing['country_id'] ?? 0) !== (int) $job['country_id']) {
            throw new RuntimeException('Illegal contract change after freeze; create a new job (P1-02).');
        }
        throw new RuntimeException('Contract already frozen; pin amend is P4 (OD-PIN), not P3-02.');
    }

    $now = gmdate('c');
    $contract = [
        'schema_version' => 'cpr_execution_contract/1',
        'job_id' => $jobId,
        'idempotency_key' => (string) $job['idempotency_key'],
        'package_id' => (string) $job['package_id'],
        'package_fingerprint' => (string) $job['package_fingerprint'],
        'country_id' => (int) $job['country_id'],
        'country_code' => (string) $job['country_code'],
        'workflow' => (string) $job['workflow'],
        'schema_revision_expected' => $fingerprints['schema_revision_expected'],
        'boundary_policy_version' => (string) $fingerprints['boundary_policy_version'],
        'dependency_graph_version' => (string) $fingerprints['dependency_graph_version'],
        'registry_revision' => $fingerprints['registry_revision'],
        'c4_report_hash' => (string) $fingerprints['c4_report_hash'],
        'c5_report_hash' => (string) $fingerprints['c5_report_hash'],
        'c6_report_hash' => (string) $fingerprints['c6_report_hash'],
        'c7_report_hash' => (string) $fingerprints['c7_report_hash'],
        'c8_report_hash' => (string) $fingerprints['c8_report_hash'],
        'c8_overall_result' => 'SAFE',
        'inventory_snapshot_id' => (string) $fingerprints['inventory_snapshot_id'],
        'inventory_snapshot_hash' => (string) $fingerprints['inventory_snapshot_hash'],
        'production_db_identity_hash' => (string) $fingerprints['production_db_identity_hash'],
        'session_full_backup_id' => null,
        'session_full_backup_fingerprint' => null,
        'session_full_backup_pinned' => null,
        'contract_frozen' => true,
        'contract_revision' => 1,
        'contract_phase' => 'pre_pin',
        'frozen_at' => $now,
        'frozen_by_admin_id' => $actorAdminId,
        'one_time_authorization_id' => null,
        'enablement_flag_observed' => false,
        'http_mutation_forbidden' => true,
        'ponr_authorized' => false,
    ];

    orange_cpr_contract_write($cprRoot, $jobId, $contract);

    $job['state'] = ORANGE_CPR_STATE_CONTRACT_FROZEN;
    $job['contract_frozen'] = true;
    $job['contract_revision'] = 1;
    $job['updated_at'] = $now;
    $job['enablement_flag_observed'] = false;
    orange_cpr_job_write($cprRoot, $jobId, $job);

    orange_cpr_audit_append($cprRoot, $jobId, [
        'event_type' => 'cpr.contract_freeze',
        'job_id' => $jobId,
        'contract_phase' => 'pre_pin',
        'contract_revision' => 1,
        'actor_admin_id' => $actorAdminId,
        'enablement_flag_observed' => false,
        'ponr_authorized' => false,
    ]);

    return $contract;
}

/**
 * Hard stubs — mutation engines are not implemented in P3-02.
 */
function orange_cpr_forbidden_ponr(): void
{
    throw new RuntimeException('PONR is out of scope for P3 (WP-P3-02). Deferred to P4+.');
}

function orange_cpr_forbidden_delete_engine(): void
{
    throw new RuntimeException('DELETE mutation engine is out of scope for P3 (WP-P3-02).');
}

function orange_cpr_forbidden_import_engine(): void
{
    throw new RuntimeException('IMPORT mutation engine is out of scope for P3 (WP-P3-02).');
}
